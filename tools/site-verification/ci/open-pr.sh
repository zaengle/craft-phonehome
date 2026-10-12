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
    # A re-run or a resumed branch skips the step that detects a migration needing a local apply,
    # so its body would lose the warning. A warning already on the pull request is kept until a
    # run that did the detection says otherwise.
    if [ -z "${APPLY_LOCALLY:-}" ] && [ -z "${MOVED:-}" ] && ! grep -q 'Apply this update locally' "$BODY_FILE"; then
        previous="$(gh pr view "$existing" --json body --jq .body 2>/dev/null || true)"
        caution="$(printf '%s\n' "$previous" | awk '/^> \[!CAUTION\]/ { on = 1 } on && /^$/ { exit } on { print }')"
        if printf '%s' "$caution" | grep -q 'Apply this update locally'; then
            { printf '%s\n\n' "$caution"; cat "$BODY_FILE"; } > "${BODY_FILE}.kept" && mv "${BODY_FILE}.kept" "$BODY_FILE"
        fi
    fi
    gh pr edit "$existing" --body-file "$BODY_FILE" >/dev/null
    echo "Updated ${existing} with this run's verification."
    url="$existing"
else
    url="$(gh pr create --draft --base "$BASE" --head "$BRANCH" --title "$TITLE" --body-file "$BODY_FILE")"
    echo "Opened ${url}."
fi

# Best effort: creating or adding a label can need more than the job's token is granted, and the
# warning at the top of the body already says the same thing.
if [ -n "${APPLY_LOCALLY:-}" ]; then
    gh pr edit "$url" --add-label "phone-home: apply locally" >/dev/null 2>&1 \
        || echo "::warning::Could not add the 'phone-home: apply locally' label to ${url}; the warning at the top of its body still stands."
fi

echo "url=${url}" >> "$out"
