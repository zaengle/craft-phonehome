<?php

namespace zaengle\phonehome\services;

use yii\base\Component;
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
        return $this->normalize(PhoneHome::$plugin->getSettings()->verification);
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
