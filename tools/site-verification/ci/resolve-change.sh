#!/usr/bin/env bash
#
# Moves one Composer package to the requested version and reports what moved.
#
# Called by the `prepare` job of .github/workflows/remediate.yml, from the directory that holds the
# site's composer.json and composer.lock. That is the repository root for most sites and a
# subdirectory, such as `src/`, for a site that keeps its Craft application there; the job sets it
# from the `working_directory` input. Kept here so it can be run against a stand-in `composer`.
#
#   PACKAGE         The Composer package to move, e.g. craftcms/cms.
#   VERSION         The exact version to move it to.
#   GITHUB_OUTPUT   Where `moved` (one "name before -> after" per line) and `count` are written.

set -euo pipefail

: "${PACKAGE:?PACKAGE is required}" "${VERSION:?VERSION is required}"
out="${GITHUB_OUTPUT:-/dev/stdout}"

# Said plainly rather than left to Composer, which would otherwise create a fresh lock file at the
# repository root of a site that keeps its application in a subdirectory and report a large move.
if [ ! -f composer.json ] || [ ! -f composer.lock ]; then
    echo "::error::$(pwd) has no composer.json and composer.lock. If this site keeps its Craft application in a subdirectory, set working_directory to it in the calling workflow."
    exit 1
fi

cp composer.lock composer.lock.before
trap 'rm -f composer.lock.before' EXIT

# --with-all-dependencies, because a Craft plugin security release frequently cannot move without
# craftcms/cms moving with it, and craftcms/cms is a root requirement, which --with-dependencies
# alone would refuse to touch. The requested version still has to fit the root constraint; when it
# does not, this fails here, before anything is pushed, and the constraint is the thing a person
# has to change. What moves is reported rather than assumed to be small.
composer update "$PACKAGE:$VERSION" --with-all-dependencies --no-interaction --no-scripts

# shellcheck disable=SC2016
moved="$(php -r '
  $read = fn(string $f): array => array_column(json_decode(file_get_contents($f), true)["packages"] ?? [], "version", "name");
  $before = $read("composer.lock.before");
  $after = $read("composer.lock");
  $changed = [];
  foreach ($after as $name => $version) {
    if (($before[$name] ?? null) !== $version) { $changed[] = $name . " " . ($before[$name] ?? "new") . " -> " . $version; }
  }
  echo implode("\n", $changed);
')"

echo "count=$(printf '%s' "$moved" | grep -c . || true)" >> "$out"
{
    echo 'moved<<MOVED'
    printf '%s\n' "$moved"
    echo 'MOVED'
} >> "$out"
