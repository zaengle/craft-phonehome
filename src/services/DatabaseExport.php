<?php

namespace zaengle\phonehome\services;

use Craft;
use craft\db\Connection;
use craft\events\BackupEvent;
use yii\base\Component;
use yii\base\Event;
use zaengle\phonehome\PhoneHome;

/**
 * Database export service
 *
 * Lets a site say which tables Craft should leave out of `craft db/backup`. Craft dumps the schema
 * for every table and then the data for all but the ignored ones, so an excluded table restores
 * empty rather than missing -- the copy is usable, it just has no rows in those tables.
 *
 * This is the only safe point to do it. Excluding at dump time means the data never reaches the
 * file; scrubbing a dump afterwards means it was in the file, on disk, first.
 *
 * ⚠️ Excluding a table that content refers to will break rendering. Emptying `users` on a site whose
 * templates print an entry author produces a 500 on every such page, and the exception names the
 * field layout rather than the missing user, so it does not look like a backup problem at all.
 * Exclude submission and token tables freely; think hard before excluding anything Craft joins to.
 */
class DatabaseExport extends Component
{
    /**
     * Registers the exclusion handler, if the site has configured any.
     *
     * Kept opt-in: with nothing configured no handler is attached at all, so installing the plugin
     * can never quietly change what a site's backups contain.
     */
    public function register(): void
    {
        $settings = PhoneHome::$plugin->getSettings();

        if ($settings->backupExcludeTables === [] && $settings->backupExcludePatterns === []) {
            return;
        }

        Event::on(Connection::class, Connection::EVENT_BEFORE_CREATE_BACKUP, function(BackupEvent $event) use ($settings) {
            $resolution = $this->resolveExcludedTables(
                Craft::$app->getDb()->getSchema()->getTableNames(),
                $settings->backupExcludeTables,
                $settings->backupExcludePatterns,
            );

            // An entry that matches nothing excludes nothing, and the resulting dump is
            // indistinguishable from one that never held the data. Silence is the wrong default
            // when the entire point is keeping personal data out of a file that leaves the server.
            foreach ($resolution['unmatched'] as $entry) {
                PhoneHome::warning("Backup exclusion \"$entry\" matched no table; its data is in this backup.");
            }

            $event->ignoreTables = array_merge($event->ignoreTables, $resolution['matched']);
        });
    }

    /**
     * Resolves configured names and wildcard patterns against the tables that actually exist.
     *
     * Craft's schema returns table names already carrying the table prefix, while its own `Table`
     * constants are `{{%name}}` tokens. Both spellings are accepted and matched against the real
     * names, because the failure mode otherwise is silent: a name that matches nothing excludes
     * nothing, and a backup that still contains the data looks exactly like one that never had it.
     *
     * @param string[] $existing Table names as the schema reports them, prefix included
     * @param string[] $tables Exact names, with or without the prefix, or as `{{%name}}`
     * @param string[] $patterns Wildcard patterns, where `*` matches any run of characters
     * @return array{matched: list<string>, unmatched: list<string>} Matching table names in the
     *     schema's own spelling, and the configured entries that matched nothing
     */
    public function resolveExcludedTables(array $existing, array $tables, array $patterns): array
    {
        $prefix = $this->tablePrefix();
        $wanted = [];

        foreach ($tables as $table) {
            $bare = trim(str_replace(['{{%', '}}', '{{', '%}}'], '', (string)$table));

            if ($bare === '') {
                continue;
            }

            $wanted[] = $bare;
            $wanted[] = $prefix . $bare;
        }

        $matched = [];

        foreach ($existing as $name) {
            if (in_array($name, $wanted, true)) {
                $matched[$name] = true;
                continue;
            }

            foreach ($patterns as $pattern) {
                if ($this->matchesPattern($name, (string)$pattern, $prefix)) {
                    $matched[$name] = true;
                    break;
                }
            }
        }

        $names = array_keys($matched);
        sort($names);

        $unmatched = [];

        foreach ($tables as $table) {
            $bare = trim(str_replace(['{{%', '}}', '{{', '%}}'], '', (string)$table));

            if ($bare !== '' && !in_array($bare, $names, true) && !in_array($prefix . $bare, $names, true)) {
                $unmatched[] = (string)$table;
            }
        }

        foreach ($patterns as $pattern) {
            if (trim((string)$pattern) === '') {
                continue;
            }

            foreach ($names as $name) {
                if ($this->matchesPattern($name, (string)$pattern, $prefix)) {
                    continue 2;
                }
            }

            $unmatched[] = (string)$pattern;
        }

        return ['matched' => $names, 'unmatched' => $unmatched];
    }

    /**
     * Reads the configured table prefix. Separated so the resolution logic can be exercised without
     * a booted Craft application.
     */
    protected function tablePrefix(): string
    {
        return Craft::$app->getDb()->tablePrefix;
    }

    /**
     * Matches a wildcard pattern against a table name, with and without the prefix, so a pattern
     * written either way behaves the same.
     */
    private function matchesPattern(string $name, string $pattern, string $prefix): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
        $bare = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;

        return preg_match($regex, $name) === 1 || preg_match($regex, $bare) === 1;
    }
}
