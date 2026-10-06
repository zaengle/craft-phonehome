#!/usr/bin/env bash
#
# Opens the draft pull request for a remediation branch, or updates the one already open.
#
# Called by the `pull_request` job of .github/workflows/remediate.yml. A re-run of that job finds the
# pull request the first attempt opened, and GitHub refuses a second one for the same branch, so
# creating unconditionally failed the job on the pilot and left the open pull request carrying the
# first attempt's verdict. Kept here, like deploy-watch.sh, so the gh calls run against a stand-in.
#
#   BRANCH          The remediation branch.
#   BASE            The branch the pull request targets.
#   TITLE           The title for a new pull request. An open one keeps its own.
#   BODY_FILE       The body, which replaces an open pull request's body so it carries this verdict.
#   GITHUB_OUTPUT   Where `url` is written, the same either way.

set -euo pipefail

: "${BRANCH:?BRANCH is required}" "${BASE:?BASE is required}" "${TITLE:?TITLE is required}" "${BODY_FILE:?BODY_FILE is required}"
out="${GITHUB_OUTPUT:-/dev/stdout}"

existing="$(gh pr list --head "$BRANCH" --state open --json url --jq '.[0].url // empty')"

if [ -n "$existing" ]; then
    gh pr edit "$existing" --body-file "$BODY_FILE" >/dev/null
    echo "Updated ${existing} with this run's verification."
    url="$existing"
else
    url="$(gh pr create --draft --base "$BASE" --head "$BRANCH" --title "$TITLE" --body-file "$BODY_FILE")"
    echo "Opened ${url}."
fi

echo "url=${url}" >> "$out"
