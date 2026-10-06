#!/usr/bin/env bash
#
# Starts the site's own deploy workflow on a branch and waits for it to finish.
#
# Called by the `deploy` job of .github/workflows/remediate.yml. Kept here so the gh invocations
# can be exercised against a stand-in `gh` on PATH: the inline version shipped with a flag gh does
# not accept, which no substring test could have caught and only a real run would have.
#
#   DEPLOY_WORKFLOW   The workflow_dispatch workflow in this repository that deploys a ref. Empty
#                     means no deploy, which is reported rather than treated as a failure.
#   BRANCH            The ref to deploy.
#   GITHUB_OUTPUT     Where `deployed` and `run_url` are written.
#
# Exits non-zero when a deploy was attempted and did not succeed, which is what gates the compare.

set -euo pipefail

: "${DEPLOY_WORKFLOW:=}" "${BRANCH:?BRANCH is required}"
out="${GITHUB_OUTPUT:-/dev/stdout}"
attempts="${DEPLOY_WATCH_ATTEMPTS:-30}"
interval="${DEPLOY_WATCH_INTERVAL:-2}"

if [ -z "$DEPLOY_WORKFLOW" ]; then
    echo "::warning::No deploy workflow was named, so the verification did not render this branch."
    echo "deployed=0" >> "$out"
    exit 0
fi

# The run id is not returned by the dispatch, so the run is found by looking for one on this branch
# created after the dispatch. Timestamps are ISO-8601, which compare as text. The JSON is piped to
# jq rather than filtered by gh's own --jq, which takes no --arg.
started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
gh workflow run "$DEPLOY_WORKFLOW" --ref "$BRANCH"

run_id=""
for _ in $(seq 1 "$attempts"); do
    run_id="$(gh run list --workflow "$DEPLOY_WORKFLOW" --branch "$BRANCH" --event workflow_dispatch --limit 10 --json databaseId,createdAt \
        | jq -r --arg started "$started" '[.[] | select(.createdAt >= $started)] | .[0].databaseId // empty')"
    [ -n "$run_id" ] && break
    sleep "$interval"
done

if [ -z "$run_id" ]; then
    echo "::error::${DEPLOY_WORKFLOW} was dispatched on ${BRANCH} but no run appeared in time."
    echo "deployed=0" >> "$out"
    exit 1
fi

url="$(gh run view "$run_id" --json url --jq .url)"
echo "run_url=${url}" >> "$out"
echo "Waiting on ${url}"

# --exit-status makes this fail when the deploy did.
if gh run watch "$run_id" --exit-status; then
    echo "deployed=1" >> "$out"
else
    echo "::error::The deploy run failed: ${url}"
    echo "deployed=0" >> "$out"
    exit 1
fi
