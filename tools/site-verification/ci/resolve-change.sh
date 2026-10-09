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
#   GITHUB_OUTPUT   Where `moved` (one "name before -> after" per line), `count`, `scope` (how far
#                   the update had to widen), `scope_reason` (why it widened), and from
#                   schema-sync.mjs `schema_changes`, `apply_locally` and `project_config_files`
#                   are written.

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
trap 'rm -f composer.lock.before composer.err migrations.before.json' EXIT
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# The site as it is, installed, so each Craft plugin's migrations can be recorded before the update.
# A new migration that writes project config cannot be reproduced from a schema version, and the
# only way to tell a new migration from an old one is to have seen the old set.
#
# Best effort: the security update matters more than the project config check, so a failure here
# is reported, in the run and in the pull request, rather than allowed to stop the remediation.
schema_error=""
if ! composer install --no-interaction --no-scripts --quiet; then
    schema_error="The site could not be installed as it was before the update, so schema versions and new migrations were not checked."
elif ! node "$here/schema-sync.mjs" snapshot migrations.before.json; then
    schema_error="The site's migrations could not be recorded before the update, so schema versions and new migrations were not checked."
fi

# The narrowest update that resolves, widened one step at a time. Moving every dependency at once
# turned a one-plugin security release into a 48-package change on the first real site, most of
# which the release did not need, and a reviewer cannot tell a security fix from the noise around
# it. Composer exits 2 when an update cannot be resolved, and only that widens the next attempt;
# any other failure, such as a missing credential, stops here, because a broader update would fail
# the same way and say less about why.
#
#   exact         Only the requested package moves.
#   dependencies  Its own dependencies may move too, but no root requirement does.
#   all           Root requirements may move as well, which a plugin release that needs a newer
#                 Craft still requires. --minimal-changes keeps even this to what the release needs.
#
# Every step keeps the requested version fixed, and that version still has to fit the root
# constraint; when it does not, this fails before anything is pushed, and the constraint is the
# thing a person has to change.
scope=""
reason=""
for step in exact dependencies all; do
    case "$step" in
        exact) flags=() ;;
        dependencies) flags=(--with-dependencies --minimal-changes) ;;
        all) flags=(--with-all-dependencies --minimal-changes) ;;
    esac

    cp composer.lock.before composer.lock
    # Composer's messages go to a file so the reason can be read back, and then to the log as well.
    # `${flags[@]+...}` because an empty array is unbound under `set -u` in older bash.
    set +e
    composer update "$PACKAGE:$VERSION" ${flags[@]+"${flags[@]}"} --no-interaction --no-scripts 2>composer.err
    status=$?
    set -e
    cat composer.err >&2

    if [ "$status" -eq 0 ]; then
        scope="$step"
        break
    fi

    if [ "$status" -ne 2 ]; then
        echo "::error::composer update failed with exit status ${status}, which is not a dependency conflict, so no broader update was tried."
        exit "$status"
    fi

    # The last line of Composer's first problem is the conflict itself; the lines above it are the
    # chain that led there.
    reason="$(awk '/Problem 1/ { found = 1; next } found && /^ *Problem [0-9]/ { exit } found && /^ *- / { line = $0 } END { sub(/^ *- /, "", line); print line }' composer.err)"
done

if [ -z "$scope" ]; then
    echo "::error::${PACKAGE} could not be moved to ${VERSION} even with every dependency allowed to move: ${reason}"
    exit 2
fi

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
echo "scope=${scope}" >> "$out"
# Composer's advice to list the package as an argument is about widening the update, which is what
# this script has just done, so it is dropped rather than repeated to a reviewer.
reason="${reason% Make sure you list it as an argument for the update command.}"
echo "scope_reason=$(printf '%s' "$reason" | tr -d '\r\n' | cut -c1-300)" >> "$out"
# Project config records each plugin's schema version, and Craft's. When the update raised one, the
# YAML is brought into step here, because a deploy that migrates the database and then finds the
# YAML still naming the old version stops before the release goes live.
if [ -z "$schema_error" ] && ! GITHUB_OUTPUT="$out" node "$here/schema-sync.mjs" apply composer.lock.before migrations.before.json; then
    schema_error="Project config could not be brought into step with the update's schema versions."
fi

if [ -n "$schema_error" ]; then
    echo "::warning::${schema_error} Check project config before merging."
    printf 'schema_changes<<SCHEMA\nSCHEMA\napply_locally<<SCHEMA\nSCHEMA\nschema_unknown<<SCHEMA\nSCHEMA\nproject_config_files=\n' >> "$out"
fi
echo "schema_error=${schema_error}" >> "$out"

{
    echo 'moved<<MOVED'
    printf '%s\n' "$moved"
    echo 'MOVED'
} >> "$out"
