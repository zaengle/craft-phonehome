#!/usr/bin/env node
/**
 * Keeps a site's project config in step with the schema versions a remediation installs.
 *
 * A Craft plugin records its database schema version in project config, at
 * `plugins.<handle>.schemaVersion`, and Craft records its own at `system.schemaVersion`. When an
 * update raises that version, a developer's `craft up` rewrites the YAML and the developer commits
 * it; a remediation commits only composer.lock. On the first onboarded site, Formie 3.1.43 to
 * 3.1.46 raised its schema from 3.4.12 to 3.4.13, production migrated the database on deploy and
 * then refused to finish because project.yaml still said 3.4.12, and Forge never activated the
 * release. Every later deploy failed the same way until the YAML was fixed by hand.
 *
 * Run from the directory that holds composer.json, in two steps around the update:
 *
 *   node schema-sync.mjs snapshot <file>                      before: record each plugin's migrations
 *   node schema-sync.mjs apply <lock.before> <file>           after: update project config, report
 *
 * `apply` writes `schema_changes`, `apply_locally` and `project_config_files` to $GITHUB_OUTPUT.
 * Nothing here boots Craft: versions are read from the installed source, and only the matching
 * `schemaVersion` lines are rewritten, so the rest of the YAML, `dateModified` included, is left
 * exactly as it was. Craft decides whether to re-read project config from the file's modification
 * time, not from that key.
 */
import { appendFileSync, existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const CRAFT = 'craftcms/cms';

/** Versions by name from a composer.lock's text, packages and dev packages alike. */
export function lockVersions(text) {
    const lock = JSON.parse(text);
    const versions = {};

    for (const key of ['packages', 'packages-dev']) {
        for (const entry of Array.isArray(lock[key]) ? lock[key] : []) {
            if (typeof entry?.name === 'string' && typeof entry.version === 'string') {
                versions[entry.name] = { version: entry.version, type: entry.type ?? '' };
            }
        }
    }

    return versions;
}

/**
 * Sets one `schemaVersion` inside a top-level YAML block, editing only that line.
 *
 * `path` is the keys below the top level, for example `['formie']` under `plugins`, or `[]` for
 * `system`. Returns the new text and the old value, or null when the key is not there, which for a
 * plugin means the site does not have it in project config and it is skipped.
 */
export function setSchemaVersion(text, topKey, path, version) {
    const lines = text.split('\n');
    let depth = 0;
    let inside = false;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        if (/^\S/.test(line)) {
            inside = line.replace(/\s+$/, '') === `${topKey}:`;
            depth = 0;
            continue;
        }

        if (!inside || line.trim() === '' || line.trim().startsWith('#')) {
            continue;
        }

        const indent = line.length - line.trimStart().length;
        const key = line.trim().split(':')[0].replace(/^['"]|['"]$/g, '');

        // Walks down the path one indentation level at a time, two spaces per level as Craft writes it.
        if (depth < path.length) {
            if (indent === 2 * (depth + 1) && key === path[depth]) {
                depth++;
            } else if (indent <= 2 * depth) {
                inside = false;
            }

            continue;
        }

        if (indent < 2 * (depth + 1)) {
            inside = false;
            continue;
        }

        if (indent === 2 * (depth + 1) && key === 'schemaVersion') {
            const match = line.match(/^(\s*schemaVersion:\s*)(['"]?)([^'"\s#]+)\2(.*)$/);

            if (match === null) {
                return null;
            }

            lines[i] = `${match[1]}${match[2]}${version}${match[2]}${match[4]}`;

            return { text: lines.join('\n'), from: match[3] };
        }
    }

    return null;
}

/** Whether a migration's source touches project config, which a version number cannot capture. */
export function writesProjectConfig(source) {
    return /getProjectConfig\s*\(|->projectConfig\b|ProjectConfig::|projectConfig\s*\(\)/.test(source);
}

/** The installed package's composer.json, or null. */
function packageJson(vendor, name) {
    const path = join(vendor, name, 'composer.json');

    return existsSync(path) ? JSON.parse(readFileSync(path, 'utf8')) : null;
}

/** The directory a namespace prefix maps to, from the package's PSR-4 autoload. */
function sourceRoot(vendor, name, pkg, className) {
    const psr4 = pkg?.autoload?.['psr-4'] ?? {};
    const prefix = Object.keys(psr4)
        .filter((candidate) => className.startsWith(candidate))
        .sort((a, b) => b.length - a.length)[0];

    if (prefix === undefined) {
        return null;
    }

    const base = Array.isArray(psr4[prefix]) ? psr4[prefix][0] : psr4[prefix];

    return { dir: join(vendor, name, base), prefix };
}

/**
 * What a moved package declares: its project config path, its schema version, and its migrations
 * directory. Null for a package that is not Craft or a Craft plugin.
 */
export function describePackage(vendor, name) {
    if (name === CRAFT) {
        const app = join(vendor, CRAFT, 'src', 'config', 'app.php');
        const version = existsSync(app) ? readFileSync(app, 'utf8').match(/'schemaVersion'\s*=>\s*'([^']+)'/)?.[1] ?? null : null;

        return { topKey: 'system', path: [], label: 'Craft', version, migrations: join(vendor, CRAFT, 'src', 'migrations') };
    }

    const pkg = packageJson(vendor, name);

    if (pkg?.type !== 'craft-plugin' || typeof pkg.extra?.handle !== 'string' || typeof pkg.extra?.class !== 'string') {
        return null;
    }

    const root = sourceRoot(vendor, name, pkg, pkg.extra.class);

    if (root === null) {
        return null;
    }

    const file = join(root.dir, `${pkg.extra.class.slice(root.prefix.length).replace(/\\/g, '/')}.php`);
    const version = existsSync(file) ? readFileSync(file, 'utf8').match(/\$schemaVersion\s*=\s*['"]([^'"]+)['"]/)?.[1] ?? null : null;

    return { topKey: 'plugins', path: [pkg.extra.handle], label: pkg.extra.handle, version, migrations: join(root.dir, 'migrations') };
}

function migrationFiles(dir) {
    return existsSync(dir) ? readdirSync(dir).filter((file) => file.endsWith('.php')).sort() : [];
}

/** Every YAML file under config/project, where project config may be split across several. */
function projectConfigFiles(root) {
    const dir = join(root, 'config', 'project');
    const found = [];

    const walk = (current) => {
        for (const entry of readdirSync(current)) {
            const full = join(current, entry);

            if (statSync(full).isDirectory()) {
                walk(full);
            } else if (entry.endsWith('.yaml')) {
                found.push(full);
            }
        }
    };

    if (existsSync(dir)) {
        walk(dir);
    }

    return found.sort();
}

function vendorDir(root) {
    const composer = JSON.parse(readFileSync(join(root, 'composer.json'), 'utf8'));

    return join(root, composer?.config?.['vendor-dir'] ?? 'vendor');
}

/** Records each Craft plugin's migration files, and Craft's, before the update. */
export function snapshot(root) {
    const vendor = vendorDir(root);
    const record = {};

    for (const name of Object.keys(lockVersions(readFileSync(join(root, 'composer.lock'), 'utf8')))) {
        const described = describePackage(vendor, name);

        if (described !== null) {
            record[name] = migrationFiles(described.migrations);
        }
    }

    return record;
}

/**
 * Updates project config for every moved Craft plugin, and Craft, and reports what it did.
 *
 * @return {{ changes: string[], applyLocally: string[], files: string[] }}
 */
export function apply(root, lockBefore, before) {
    const vendor = vendorDir(root);
    const was = lockVersions(lockBefore);
    const now = lockVersions(readFileSync(join(root, 'composer.lock'), 'utf8'));
    const files = projectConfigFiles(root);
    const changes = [];
    const applyLocally = [];
    const touched = new Set();

    for (const name of Object.keys(now).sort()) {
        if (was[name]?.version === now[name].version) {
            continue;
        }

        const described = describePackage(vendor, name);

        if (described === null) {
            continue;
        }

        // A migration that writes project config cannot be reproduced from a version number, so
        // the pull request says the update has to be applied locally instead.
        for (const file of migrationFiles(described.migrations).filter((candidate) => !(before[name] ?? []).includes(candidate))) {
            if (writesProjectConfig(readFileSync(join(described.migrations, file), 'utf8'))) {
                applyLocally.push(`${name}: ${file}`);
            }
        }

        if (described.version === null) {
            continue;
        }

        for (const file of files) {
            const result = setSchemaVersion(readFileSync(file, 'utf8'), described.topKey, described.path, described.version);

            if (result === null) {
                continue;
            }

            if (result.from !== described.version) {
                writeFileSync(file, result.text);
                touched.add(relative(root, file));
                changes.push(`${described.label} ${result.from} -> ${described.version}`);
            }

            break;
        }
    }

    return { changes, applyLocally, files: [...touched].sort() };
}

function main() {
    const [command, ...args] = process.argv.slice(2);
    const root = process.cwd();
    const out = process.env.GITHUB_OUTPUT;

    if (command === 'snapshot') {
        writeFileSync(args[0], JSON.stringify(snapshot(root)));

        return;
    }

    if (command === 'apply') {
        const result = apply(root, readFileSync(args[0], 'utf8'), JSON.parse(readFileSync(args[1], 'utf8')));
        const block = (name, lines) => `${name}<<SCHEMA\n${lines.join('\n')}${lines.length > 0 ? '\n' : ''}SCHEMA\n`;
        const text = block('schema_changes', result.changes) + block('apply_locally', result.applyLocally) + `project_config_files=${result.files.join(' ')}\n`;

        if (out) appendFileSync(out, text);
        process.stdout.write(text);

        return;
    }

    process.stderr.write('Usage: schema-sync.mjs snapshot <file> | apply <lock.before> <snapshot>\n');
    process.exit(64);
}

if (process.argv[1] && resolve(process.argv[1]) === new URL(import.meta.url).pathname) {
    main();
}
