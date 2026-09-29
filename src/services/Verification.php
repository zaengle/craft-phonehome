<?php

namespace zaengle\phonehome\services;

use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use DateTime;
use Throwable;
use yii\base\Component;
use yii\db\Expression;
use zaengle\phonehome\PhoneHome;

/**
 * Verification service
 *
 * Normalises the site's opt-in `verification` config into the versioned manifest the report
 * exposes. A deployment-verification runner reads that manifest and executes the checks it names;
 * nothing here performs any browser work, and the manifest carries no credentials.
 *
 * The normalisation is deliberately all-or-nothing. A manifest that contains any invalid page is
 * reported as invalid with an empty page list, because the failure mode worth designing against is
 * a typo silently reducing coverage while the run still reports a pass.
 */
class Verification extends Component
{
    /**
     * @var int The manifest contract version. Increment when the shape of a normalised page
     * changes, so a runner built against an older contract can decline rather than guess.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * @var int The most pages a manifest may name. Bounded so a misconfiguration cannot commit a
     * runner to an unbounded amount of browser work.
     */
    public const MAX_PAGES = 10;

    /**
     * @var int Most uncovered template names to report. The counts stay exact; the list is bounded
     * so a site with many templates cannot bloat every report it sends.
     */
    public const MAX_UNCOVERED = 25;

    /**
     * @var string[] The keys a page definition may contain. An unrecognised key is an error rather
     * than something to ignore, so that `assertions` in place of `assert` is caught here instead of
     * silently producing a page with no assertion.
     */
    private const PAGE_KEYS = ['id', 'path', 'assert', 'mask'];

    /**
     * @var int Most mask selectors a page may declare. Bounded because a mask is coverage removed,
     * and an unbounded list of them is a page that is no longer being checked.
     */
    public const MAX_MASKS = 10;

    /**
     * Returns the normalised manifest for this site.
     *
     * @return array{schema_version: int, supported: bool, enabled: bool, valid: bool, pages: list<array{id: string, path: string, assert: array{visible: string}, mask: list<string>}>, errors: list<string>}
     */
    public function getManifest(): array
    {
        $raw = PhoneHome::$plugin->getSettings()->verification;
        $auto = ($raw['autoCoverTemplates'] ?? null) === true;
        $defaultAssert = $raw['defaultAssert'] ?? ['visible' => 'h1'];
        $masks = $raw['masks'] ?? [];

        // Removed before validation so the page contract stays exactly what it was. A mistyped key
        // such as `autoCoverTemplate` therefore still fails loudly as an unknown key.
        unset($raw['autoCoverTemplates'], $raw['defaultAssert'], $raw['masks']);

        if ($auto) {
            $raw['pages'] = $this->withAutoCoverage($raw['pages'] ?? [], $defaultAssert, $masks);
        }

        return $this->normalize($raw);
    }

    /**
     * Appends one page per template that the site's explicit pages do not already cover.
     *
     * Coverage becomes a property of the manifest rather than of whoever wrote it, and it heals
     * itself: an entry expiring or a new section appearing changes the generated set on the next
     * report rather than leaving a list that quietly stopped being true.
     *
     * Explicit pages always win. A generated page is only ever added for a template nothing
     * explicit already reaches.
     *
     * @param array<mixed> $explicit
     * @param array<mixed> $defaultAssert
     * @param array<mixed> $masks
     * @return array<mixed>
     */
    protected function withAutoCoverage(array $explicit, array $defaultAssert, array $masks): array
    {
        try {
            $representatives = $this->representativeUris();
        } catch (Throwable $e) {
            $this->logError('Error selecting pages for automatic coverage: ' . $e->getMessage());

            return $explicit;
        }

        $explicitPaths = [];

        foreach ($explicit as $page) {
            if (is_array($page) && is_string($page['path'] ?? null)) {
                $explicitPaths[] = $this->pathToUri($page['path']);
            }
        }

        $covered = array_intersect_key($representatives, array_flip($explicitPaths));

        try {
            $covered = array_merge(
                $covered,
                $this->templatesForUris(Table::SECTIONS_SITES, Table::ENTRIES, 'sectionId', $explicitPaths),
                $this->templatesForUris(Table::CATEGORYGROUPS_SITES, Table::CATEGORIES, 'groupId', $explicitPaths),
            );
        } catch (Throwable $e) {
            $this->logError('Error resolving explicit page templates: ' . $e->getMessage());
        }

        $coveredTemplates = array_flip(array_values($covered));
        $pages = $explicit;
        $usedIds = [];

        foreach ($explicit as $page) {
            if (is_array($page) && is_string($page['id'] ?? null)) {
                $usedIds[$page['id']] = true;
            }
        }

        foreach ($representatives as $template => $uri) {
            if (isset($coveredTemplates[$template])) {
                continue;
            }

            $id = $this->templateId($template, $usedIds);
            $usedIds[$id] = true;

            $page = ['id' => $id, 'path' => '/' . $uri, 'assert' => $defaultAssert];

            if ($masks !== []) {
                $page['mask'] = $masks;
            }

            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * @param array<string, bool> $used
     */
    /**
     * Separated so the failure paths can be exercised without a booted Craft application.
     */
    protected function logError(string $message): void
    {
        PhoneHome::error($message);
    }

    protected function templateId(string $template, array $used): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($template)) ?? 'template';
        $base = trim((string)$base, '-');
        $id = 'auto-' . ($base === '' ? 'template' : $base);
        $candidate = $id;
        $suffix = 2;

        while (isset($used[$candidate])) {
            $candidate = $id . '-' . $suffix++;
        }

        return $candidate;
    }

    /**
     * Normalises and validates a raw `verification` config value.
     *
     * @param array<mixed> $raw
     * @return array{schema_version: int, supported: bool, enabled: bool, valid: bool, pages: list<array{id: string, path: string, assert: array{visible: string}, mask: list<string>}>, errors: list<string>}
     */
    public function normalize(array $raw): array
    {
        // An absent or empty `pages` list means the site has not opted in. That is reported as
        // disabled rather than as an empty passing suite, so a runner can tell the difference
        // between "nothing to check" and "nothing was checked".
        if ($raw === []) {
            return $this->result(enabled: false, valid: true, pages: [], errors: []);
        }

        $errors = [];

        foreach (array_keys($raw) as $key) {
            if ($key !== 'pages') {
                $errors[] = sprintf('Unknown verification key "%s".', (string)$key);
            }
        }

        $pages = $raw['pages'] ?? null;

        if (!is_array($pages) || $pages === [] || !array_is_list($pages)) {
            $errors[] = 'verification.pages must be a non-empty list of page definitions.';

            return $this->result(enabled: true, valid: false, pages: [], errors: $errors);
        }

        if (count($pages) > self::MAX_PAGES) {
            $errors[] = sprintf('verification.pages names %d pages; the maximum is %d.', count($pages), self::MAX_PAGES);
        }

        $normalized = [];
        $seenIds = [];

        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                $errors[] = sprintf('Page %d is not a page definition.', $index);
                continue;
            }

            $id = $this->validateId($page['id'] ?? null, $index, $seenIds, $errors);
            $path = $this->validatePath($page['path'] ?? null, $index, $errors);
            $selector = $this->validateSelector($page['assert'] ?? null, $index, $errors);
            $mask = $this->validateMask($page['mask'] ?? null, $selector, $index, $errors);

            foreach (array_keys($page) as $key) {
                if (!in_array($key, self::PAGE_KEYS, true)) {
                    $errors[] = sprintf('Page %d has an unknown key "%s".', $index, (string)$key);
                }
            }

            if ($id === null || $path === null || $selector === null) {
                continue;
            }

            if ($mask === null) {
                continue;
            }

            $seenIds[] = $id;
            $normalized[] = [
                'id' => $id,
                'path' => $path,
                'assert' => ['visible' => $selector],
                'mask' => $mask,
            ];
        }

        if ($errors !== []) {
            return $this->result(enabled: true, valid: false, pages: [], errors: $errors);
        }

        return $this->result(enabled: true, valid: true, pages: $normalized, errors: []);
    }

    /**
     * Reports how much of what the site actually renders the manifest watches.
     *
     * Counting pages is close to meaningless -- five entries out of twenty thousand sounds like
     * nothing, and is fine. What matters is templates: a page list that exercises every template
     * the site renders will catch a broken template, and one that misses a template cannot, however
     * many pages it names.
     *
     * Returns null when the question could not be answered, which a consumer must not read as full
     * coverage.
     *
     * @return array{scope: list<string>, templates_total: int, templates_covered: int, uncovered: list<string>, routable_uris: int, unmatched_paths: list<string>}|null
     */
    public function getCoverage(): ?array
    {
        $manifest = $this->getManifest();

        if (!$manifest['enabled'] || !$manifest['valid']) {
            return null;
        }

        $paths = array_column($manifest['pages'], 'path');
        $uris = array_map(fn(string $path): string => $this->pathToUri($path), $paths);

        try {
            // Aggregated in the database and narrowed to the manifest's own URIs. Reading every
            // routable URI into PHP is fine on a small site and ruinous on a large one, and this
            // runs on every ping.
            // Counted from the same live query that selects pages. Counting enabled-but-expired
            // rows inflates the denominator with templates nothing can reach, which reads as a
            // permanent coverage gap that no page could ever close.
            $templates = array_values(array_unique(array_merge(
                array_keys($this->representativeUris()),
                $this->routableTemplates(Table::CATEGORYGROUPS_SITES, Table::CATEGORIES, 'groupId'),
            )));

            $matched = array_merge(
                $this->templatesForUris(Table::SECTIONS_SITES, Table::ENTRIES, 'sectionId', $uris),
                $this->templatesForUris(Table::CATEGORYGROUPS_SITES, Table::CATEGORIES, 'groupId', $uris),
            );

            $routableUris = $this->countRoutableUris();
        } catch (Throwable $e) {
            $this->logError('Error reading template coverage: ' . $e->getMessage());

            return null;
        }

        return $this->summariseCoverage($templates, $matched, $paths, $routableUris);
    }

    /**
     * Base query over one element type's routable rows, shared by the aggregate lookups below.
     */
    private function routableQuery(string $settingsTable, string $elementTable, string $foreignKey): Query
    {
        return (new Query())
            ->from(['settings' => $settingsTable])
            ->innerJoin(['el_type' => $elementTable], "[[el_type.$foreignKey]] = [[settings.$foreignKey]]")
            ->innerJoin(
                ['elements_sites' => Table::ELEMENTS_SITES],
                '[[elements_sites.elementId]] = [[el_type.id]] AND [[elements_sites.siteId]] = [[settings.siteId]]',
            )
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[el_type.id]]')
            ->where(['settings.hasUrls' => true, 'elements_sites.enabled' => true])
            ->andWhere(['not', ['elements_sites.uri' => null]])
            ->andWhere(['not', ['elements_sites.uri' => '']])
            ->andWhere(['not', ['settings.template' => null]])
            ->andWhere(['not', ['settings.template' => '']])
            ->andWhere(['elements.dateDeleted' => null, 'elements.revisionId' => null, 'elements.draftId' => null])
            ->andWhere(['elements.enabled' => true]);
    }

    /**
     * Narrows a routable query to entries a visitor can actually load.
     *
     * An expired or future-dated entry keeps its enabled URI row, so counting those as coverage
     * names pages that 404. Two of the pages picked by hand for this pilot did exactly that.
     */
    private function liveEntriesQuery(): Query
    {
        $now = Db::prepareDateForDb(new DateTime());

        return $this->routableQuery(Table::SECTIONS_SITES, Table::ENTRIES, 'sectionId')
            ->andWhere(['or', ['el_type.postDate' => null], ['<=', 'el_type.postDate', $now]])
            ->andWhere(['or', ['el_type.expiryDate' => null], ['>', 'el_type.expiryDate', $now]]);
    }

    /**
     * One live URI per template, for templates nothing else covers.
     *
     * @return array<string, string> template => uri
     */
    protected function representativeUris(): array
    {
        $rows = $this->liveEntriesQuery()
            ->select(['settings.template AS template', 'uri' => new Expression('MIN([[elements_sites.uri]])')])
            ->groupBy(['settings.template'])
            ->all();

        $byTemplate = [];

        foreach ($rows as $row) {
            if (is_string($row['template'] ?? null) && is_string($row['uri'] ?? null)) {
                $byTemplate[$row['template']] = $row['uri'];
            }
        }

        return $byTemplate;
    }

    /**
     * @return list<string>
     */
    private function routableTemplates(string $settingsTable, string $elementTable, string $foreignKey): array
    {
        return $this->routableQuery($settingsTable, $elementTable, $foreignKey)
            ->select(['settings.template'])
            ->distinct()
            ->column();
    }

    /**
     * @param list<string> $uris
     * @return array<string, string>
     */
    protected function templatesForUris(string $settingsTable, string $elementTable, string $foreignKey, array $uris): array
    {
        if ($uris === []) {
            return [];
        }

        return $this->routableQuery($settingsTable, $elementTable, $foreignKey)
            ->select(['elements_sites.uri AS uri', 'settings.template AS template'])
            ->andWhere(['elements_sites.uri' => $uris])
            ->pairs();
    }

    private function countRoutableUris(): int
    {
        return (int)$this->routableQuery(Table::SECTIONS_SITES, Table::ENTRIES, 'sectionId')->count('[[elements_sites.id]]')
            + (int)$this->routableQuery(Table::CATEGORYGROUPS_SITES, Table::CATEGORIES, 'groupId')->count('[[elements_sites.id]]');
    }

    /**
     * @param list<string> $templates Every template that renders a routable URL, in scope
     * @param array<string, string> $matched Manifest URI to the template that renders it
     * @param list<string> $paths The manifest's paths, in their original spelling
     * @return array{scope: list<string>, templates_total: int, templates_covered: int, uncovered: list<string>, routable_uris: int, unmatched_paths: list<string>}
     */
    public function summariseCoverage(array $templates, array $matched, array $paths, int $routableUris = 0): array
    {
        $covered = [];
        $unmatched = [];

        foreach ($paths as $path) {
            $uri = $this->pathToUri($path);

            if (isset($matched[$uri])) {
                $covered[$matched[$uri]] = true;
            } else {
                // Not an error. A manifest may legitimately name a custom route or an element type
                // outside the counted scope, which has no template to attribute coverage to -- but
                // it also cannot be credited as covering anything, so it is reported.
                $unmatched[] = $path;
            }
        }

        $all = array_fill_keys(array_filter($templates, static fn(mixed $t): bool => is_string($t) && $t !== ''), true);
        $uncovered = array_keys(array_diff_key($all, $covered));
        sort($uncovered);

        return [
            // Named so a consumer knows what the denominator counted. Element types outside this
            // list render through templates nobody here is measuring, and a percentage that does
            // not say what it is over invites being read as the whole site.
            'scope' => ['entries', 'categories'],
            'templates_total' => count($all),
            'templates_covered' => count($covered),
            'uncovered' => array_slice($uncovered, 0, self::MAX_UNCOVERED),
            'routable_uris' => $routableUris,
            'unmatched_paths' => $unmatched,
        ];
    }

    /**
     * Converts a manifest path to the URI spelling Craft stores, where the homepage is `__home__`
     * and nothing else carries a leading slash.
     */
    protected function pathToUri(string $path): string
    {
        $uri = trim(explode('?', $path)[0], '/');

        return $uri === '' ? '__home__' : $uri;
    }

    /**
     * @param list<string> $seenIds
     * @param list<string> $errors
     */
    private function validateId(mixed $value, int $index, array $seenIds, array &$errors): ?string
    {
        // \z, not $, which in PCRE also matches before a trailing newline -- "home" and "home\n"
        // would both validate and then print identically everywhere they were reported.
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9-]*\z/', $value)) {
            $errors[] = sprintf('Page %d needs an id of lowercase letters, digits and hyphens.', $index);

            return null;
        }

        if (in_array($value, $seenIds, true)) {
            $errors[] = sprintf('Page id "%s" is used more than once.', $value);

            return null;
        }

        return $value;
    }

    /**
     * Accepts only a site-relative path. The runner resolves these against an origin it is given
     * separately, so a manifest must not be able to point it at another host, and `..` must not be
     * able to walk outside the site.
     *
     * @param list<string> $errors
     */
    private function validatePath(mixed $value, int $index, array &$errors): ?string
    {
        if (!is_string($value) || $value === '' || !str_starts_with($value, '/')) {
            $errors[] = sprintf('Page %d needs a path beginning with "/".', $index);

            return null;
        }

        // Control characters are rejected before anything else is checked, because URL parsing
        // strips them and then re-reads what is left. "/\n/example.com" passes every test below --
        // it does not begin with "//" and contains no scheme -- yet a browser resolves it to
        // https://example.com/. Stripping them here instead of rejecting would be worse still,
        // since the path the site declared and the path the runner visits must be the same string.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $errors[] = sprintf('Page %d path must not contain control characters.', $index);

            return null;
        }

        // `//host` is protocol-relative and would navigate off-site; a scheme or a backslash is an
        // absolute URL by another spelling.
        if (str_starts_with($value, '//') || str_contains($value, '://') || str_contains($value, '\\')) {
            $errors[] = sprintf('Page %d path must be site-relative, not a URL.', $index);

            return null;
        }

        if (in_array('..', explode('/', $value), true)) {
            $errors[] = sprintf('Page %d path must not contain "..".', $index);

            return null;
        }

        return $value;
    }

    /**
     * Validates the optional list of selectors whose text and pixels are excluded from comparison.
     *
     * A mask is coverage deliberately given up, so the one thing it must never cover is the element
     * the page is asserting on. Masking that would leave a check that passes because it is no
     * longer looking at anything.
     *
     * @param list<string> $errors
     * @return list<string>|null The selectors, or null when the definition is unusable
     */
    private function validateMask(mixed $value, ?string $assertSelector, int $index, array &$errors): ?array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = sprintf('Page %d mask must be a list of selectors.', $index);

            return null;
        }

        if (count($value) > self::MAX_MASKS) {
            $errors[] = sprintf('Page %d declares %d masks; the maximum is %d.', $index, count($value), self::MAX_MASKS);

            return null;
        }

        $masks = [];

        foreach ($value as $selector) {
            if (!is_string($selector) || trim($selector) === '') {
                $errors[] = sprintf('Page %d has a mask that is not a selector.', $index);

                return null;
            }

            $selector = trim($selector);

            if ($assertSelector !== null && $selector === $assertSelector) {
                $errors[] = sprintf('Page %d masks the selector it asserts on, which would leave nothing to check.', $index);

                return null;
            }

            $masks[] = $selector;
        }

        return $masks;
    }

    /**
     * @param list<string> $errors
     */
    private function validateSelector(mixed $value, int $index, array &$errors): ?string
    {
        $selector = is_array($value) ? ($value['visible'] ?? null) : null;

        if (!is_array($value) || $value === []) {
            $errors[] = sprintf('Page %d needs an assert.visible selector.', $index);

            return null;
        }

        foreach (array_keys($value) as $key) {
            if ($key !== 'visible') {
                $errors[] = sprintf('Page %d has an unknown assert key "%s".', $index, (string)$key);
            }
        }

        if (!is_string($selector) || trim($selector) === '') {
            $errors[] = sprintf('Page %d needs a non-empty assert.visible selector.', $index);

            return null;
        }

        return trim($selector);
    }

    /**
     * @param list<array{id: string, path: string, assert: array{visible: string}, mask: list<string>}> $pages
     * @param list<string> $errors
     * @return array{schema_version: int, supported: bool, enabled: bool, valid: bool, pages: list<array{id: string, path: string, assert: array{visible: string}, mask: list<string>}>, errors: list<string>}
     */
    private function result(bool $enabled, bool $valid, array $pages, array $errors): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'supported' => true,
            'enabled' => $enabled,
            'valid' => $valid,
            'pages' => $pages,
            'errors' => $errors,
        ];
    }
}
