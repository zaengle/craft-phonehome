import { expect, test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { apply, setSchemaVersion, snapshot, writesProjectConfig } from '../../ci/schema-sync.mjs';

/**
 * A remediation that raises a plugin's schema version must bring project config into step, or the
 * site's next deploy migrates the database and then stops on the mismatch. That is what happened on
 * the first onboarded site when Formie 3.1.43 to 3.1.46 raised its schema from 3.4.12 to 3.4.13.
 */
const PROJECT = `dateModified: 1759860000
plugins:
  formie:
    edition: standard
    enabled: true
    schemaVersion: 3.4.12
  seomatic:
    enabled: true
    schemaVersion: '3.0.13'
system:
  edition: pro
  live: true
  schemaVersion: 5.8.0.3
`;

function write(root: string, path: string, text: string): void {
    mkdirSync(dirname(join(root, path)), { recursive: true });
    writeFileSync(join(root, path), text);
}

function lock(versions: Record<string, string>): string {
    return JSON.stringify({
        packages: Object.entries(versions).map(([name, version]) => ({ name, version, type: name === 'craftcms/cms' ? 'library' : 'craft-plugin' })),
    });
}

/** Installs a stand-in Craft plugin into the fixture's vendor directory. */
function plugin(root: string, name: string, handle: string, className: string, schema: string, migrations: Record<string, string> = {}): void {
    const [vendor, pkg] = name.split('/');
    const namespace = `${vendor}\\${pkg}\\`;

    write(root, `vendor/${name}/composer.json`, JSON.stringify({ name, type: 'craft-plugin', autoload: { 'psr-4': { [namespace]: 'src/' } }, extra: { handle, class: `${namespace}${className}` } }));
    write(root, `vendor/${name}/src/${className}.php`, `<?php\nclass ${className} extends Plugin\n{\n    public string $schemaVersion = '${schema}';\n}\n`);

    for (const [file, source] of Object.entries(migrations)) {
        write(root, `vendor/${name}/src/migrations/${file}`, source);
    }
}

const TABLE_ONLY = `<?php class m260927_000000_signature_access extends Migration { public function safeUp(): bool { $this->addColumn('{{%formie_fields}}', 'x', 'text'); return true; } }`;
const WRITES_CONFIG = `<?php class m261001_000000_move_settings extends Migration { public function safeUp(): bool { Craft::$app->getProjectConfig()->set('plugins.formie.settings.x', 1); return true; } }`;

/**
 * A site repository before and after a remediation: Formie moved, its schema went from 3.4.12 to
 * whatever `schema` is, and a migration was added. Returns the root and what apply() reported.
 */
function site(options: { schema?: string; newMigration?: string; craft?: string } = {}) {
    const root = mkdtempSync(join(tmpdir(), 'phv-schema-'));
    const before = { 'craftcms/cms': '5.11.1', 'verbb/formie': '3.1.43', 'nystudio107/craft-seomatic': '5.1.22', 'verbb/hidden': '1.0.0' };

    write(root, 'composer.json', '{}');
    write(root, 'config/project/project.yaml', PROJECT);
    write(root, 'composer.lock', lock(before));
    write(root, 'vendor/craftcms/cms/src/config/app.php', `<?php return ['schemaVersion' => '5.8.0.3'];`);
    plugin(root, 'verbb/formie', 'formie', 'Formie', '3.4.12', { 'm250101_000000_old.php': TABLE_ONLY.replace('m260927_000000_signature_access', 'm250101_000000_old') });
    plugin(root, 'verbb/hidden', 'hidden', 'Hidden', '1.0.0');

    const recorded = snapshot(root);
    const lockBefore = readFileSync(join(root, 'composer.lock'), 'utf8');

    // The update: Formie moves, and so does a plugin the site does not have in project config.
    plugin(root, 'verbb/formie', 'formie', 'Formie', options.schema ?? '3.4.13', options.newMigration ? { 'm260927_000000_new.php': options.newMigration } : {});
    plugin(root, 'verbb/hidden', 'hidden', 'Hidden', '2.0.0');
    if (options.craft) {
        write(root, 'vendor/craftcms/cms/src/config/app.php', `<?php return ['schemaVersion' => '${options.craft}'];`);
    }
    write(root, 'composer.lock', lock({ ...before, 'verbb/formie': '3.1.46', 'verbb/hidden': '2.0.0', ...(options.craft ? { 'craftcms/cms': '5.12.0' } : {}) }));

    return { root, result: apply(root, lockBefore, recorded), yaml: () => readFileSync(join(root, 'config/project/project.yaml'), 'utf8') };
}

test.describe('schema-sync.mjs', () => {
    test('a plugin update that raises its schema version updates only that line of project config', () => {
        const { result, yaml } = site();

        expect(result).toEqual({ changes: ['formie 3.4.12 -> 3.4.13'], applyLocally: [], files: ['config/project/project.yaml'] });
        expect(yaml()).toBe(PROJECT.replace('    schemaVersion: 3.4.12', '    schemaVersion: 3.4.13'));
        // dateModified is never touched; Craft re-reads project config from the file's mtime.
        expect(yaml()).toContain('dateModified: 1759860000');
    });

    test('an update that leaves the schema version alone changes nothing', () => {
        const { result, yaml } = site({ schema: '3.4.12' });

        expect(result).toEqual({ changes: [], applyLocally: [], files: [] });
        expect(yaml()).toBe(PROJECT);
    });

    test('a Craft update brings system.schemaVersion into step', () => {
        const { result, yaml } = site({ craft: '5.9.0.1' });

        expect(result.changes).toEqual(['Craft 5.8.0.3 -> 5.9.0.1', 'formie 3.4.12 -> 3.4.13']);
        expect(yaml()).toContain('  schemaVersion: 5.9.0.1\n');
    });

    test('a plugin the site does not have in project config is skipped', () => {
        const { result } = site();

        expect(result.changes.some((change) => change.startsWith('hidden'))).toBe(false);
    });

    test('a new migration that writes project config is reported, and one that only alters tables is not', () => {
        expect(site({ newMigration: WRITES_CONFIG }).result.applyLocally).toEqual(['verbb/formie: m260927_000000_new.php']);
        expect(site({ newMigration: TABLE_ONLY }).result.applyLocally).toEqual([]);
        expect(writesProjectConfig(WRITES_CONFIG)).toBe(true);
        expect(writesProjectConfig(TABLE_ONLY)).toBe(false);
    });

    test('a quoted schema version keeps its quotes, and a missing key is left alone', () => {
        expect(setSchemaVersion(PROJECT, 'plugins', ['seomatic'], '3.0.14')?.text).toContain("    schemaVersion: '3.0.14'\n");
        expect(setSchemaVersion(PROJECT, 'plugins', ['navigation'], '1.0.0')).toBeNull();
    });

    test('the pull request body lists the changes, and leads with the warning when a migration writes project config', () => {
        const body = (env: Record<string, string>) =>
            spawnSync('bash', [new URL('../../ci/pr-body.sh', import.meta.url).pathname], { env: { PATH: process.env.PATH ?? '', PATCH_ID: '90', ...env }, encoding: 'utf8' }).stdout;

        expect(body({ SCHEMA_CHANGES: 'formie 3.4.12 -> 3.4.13' })).toContain('**Project config.** The update raises these schema versions');
        expect(body({ SCHEMA_CHANGES: 'formie 3.4.12 -> 3.4.13' })).toContain('- formie 3.4.12 -> 3.4.13');

        const warned = body({ APPLY_LOCALLY: 'verbb/formie: m261001_000000_move_settings.php' });

        expect(warned.startsWith('> [!CAUTION]\n> **Apply this update locally before merging.**')).toBe(true);
        expect(warned).toContain('> - verbb/formie: m261001_000000_move_settings.php');
        expect(body({})).not.toContain('Apply this update locally');
    });
});
