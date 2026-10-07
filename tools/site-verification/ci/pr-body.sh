#!/usr/bin/env bash
#
# Composes the body of the draft pull request a remediation opens, from what the workflow's jobs
# reported. Called by .github/workflows/remediate.yml; kept here so it can be run and read without
# a GitHub runner.
#
# Every input is an environment variable, and every one is read for what it says rather than for
# whether a job exited zero: a comparison that found something exits non-zero on purpose, and a
# tolerated job failure reads as "success" to the workflow that tolerated it.
#
#   PATCH_ID               The Phone Home patch.
#   DASHBOARD_ORIGIN       Where Phone Home is, so the patch can be linked when the context below
#                          could not be fetched.
#   ORIGIN                 The environment the change was verified against. Empty means the site
#                          has none and the pull request was opened without verification.
#   MOVED, COUNT           What Composer moved, one "name before -> after" per line, and how many.
#   SCOPE, SCOPE_REASON    How far the update had to widen (exact, dependencies or all) and the
#                          conflict that made it widen. Empty when this run did not resolve the change.
#   CONTEXT                The remediation-context JSON from Phone Home, or empty if unreachable.
#   BASELINE               The capture's outcome as the runner reported it.
#   BASELINE_SUMMARY       One line on what the capture found.
#   DEPLOYED               1 when the site's deploy workflow succeeded, otherwise anything else.
#   DEPLOY_JOB             The deploy job's status, to tell "failed" from "never named".
#   DEPLOY_RUN_URL         The deploy run, when one started.
#   DEPLOY_CONFIRMED_BY    How the environment confirmed it was running this branch before the
#                          comparison: commit, lock_hash or versions. Empty when it did not.
#   DEPLOY_CONFIRMATION    The same as a phrase naming what it matched.
#   VERIFY_JOB             The compare job's status, to tell "skipped" from "produced nothing".
#   VERIFICATION           The compare's outcome as the runner reported it.
#   VERIFICATION_SUMMARY   One line on what the compare found.
#   ENVIRONMENT            One line on what the site reports moved since the baseline.

set -euo pipefail

: "${PATCH_ID:=?}" "${DASHBOARD_ORIGIN:=}" "${ORIGIN:=}" "${MOVED:=}" "${COUNT:=0}" "${CONTEXT:=}" "${SCOPE:=}" "${SCOPE_REASON:=}"
: "${BASELINE:=}" "${BASELINE_SUMMARY:=}" "${DEPLOYED:=}" "${DEPLOY_JOB:=}" "${DEPLOY_RUN_URL:=}"
: "${VERIFY_JOB:=}" "${VERIFICATION:=}" "${VERIFICATION_SUMMARY:=}" "${ENVIRONMENT:=}"
: "${DEPLOY_CONFIRMED_BY:=}" "${DEPLOY_CONFIRMATION:=}"

# The patch is named as a link to its page in Phone Home, never as `#<number>`. GitHub turns a bare
# `#90` into a link to issue or pull request 90 of whichever repository the body lands in, which
# has nothing to do with Phone Home's patch 90. The context carries the page's own URL; without it
# the URL is built from the dashboard's origin, and without that the number is written plainly.
patch_url="$(printf '%s' "$CONTEXT" | jq -r '.patch.url // empty' 2>/dev/null || true)"
if [ -z "$patch_url" ] && [ -n "$DASHBOARD_ORIGIN" ]; then
    patch_url="${DASHBOARD_ORIGIN%/}/patches/${PATCH_ID}"
fi
if [ -n "$patch_url" ]; then
    patch_ref="[patch ${PATCH_ID}](${patch_url})"
else
    patch_ref="patch ${PATCH_ID}"
fi

# The judgement behind the change. The person merging should see why it exists and who already
# agreed, not re-derive both from a lock file diff. A dashboard that could not be reached is said
# so rather than left as a silent omission.
if [ -n "$CONTEXT" ] && printf '%s' "$CONTEXT" | jq -e '.patch' >/dev/null 2>&1; then
    why="$(printf '%s' "$CONTEXT" | jq -r '
        "**Why** — [" + .patch.title + "](" + .patch.url + ")"
            + (if .patch.severity then ", severity " + .patch.severity else "" end) + "."
            + (if (.releases | length) > 0
                then "\n\nFixes " + (.releases | map(.craft_handle + " " + .version + (if .ghsa_id then " (" + .ghsa_id + ")" else "" end)) | join(", ")) + "."
                else "" end)
            + (if .patch.severity_rationale then "\n\n> " + (.patch.severity_rationale | gsub("\r"; "") | gsub("\n"; "\n> ")) else "" end)
            + "\n\nAssessed by " + (.patch.assessed_by // "nobody recorded")
            + "; signed off by " + (if (.reviews | length) > 0 then (.reviews | map(.name) | join(", ")) else "nobody yet" end)
            + "; dispatched by " + (.dispatched_by // "nobody recorded") + "."
    ')"
else
    why="**Why** — Phone Home could not be reached for the assessment behind ${patch_ref}; it is on the patch page there."
fi

# A security patch that moves three packages and one that moves fifty are different things to
# review, and the second should not arrive looking like the first.
scale=""
if [ "${COUNT:-0}" -gt 10 ] 2>/dev/null; then
    scale="> [!WARNING]
> This moves ${COUNT} packages. That is a dependency bump which happens to contain a security fix, not a security patch — review it as one."
fi

# How far the update had to reach, so a reviewer can tell what the release required from what moved
# along with it. Each step is tried only when the narrower one could not be resolved.
because="${SCOPE_REASON:+ Composer reported: ${SCOPE_REASON}}"
case "$SCOPE" in
    exact) resolved="Only the requested package was allowed to move." ;;
    dependencies) resolved="Moving only the requested package could not be resolved, so its own dependencies were allowed to move as well, with the fewest changes Composer could make. No root requirement, such as Craft, was moved.${because}" ;;
    all) resolved="Neither the requested package alone nor with its own dependencies could be resolved, so root requirements such as Craft were allowed to move too, with the fewest changes Composer could make.${because}" ;;
    *) resolved="" ;;
esac

# One of four sentences, chosen from what actually happened. No environment at all comes first,
# because it is a different kind of pull request rather than a verification that went wrong; then
# no baseline, then no comparison, then the comparison's own verdict.
if [ -z "$ORIGIN" ]; then
    verdict="> [!IMPORTANT]
> **Not verified.** This site has no environment to verify on, so this pull request was opened without deploying or comparing anything. Review it as you would any dependency update."
elif [ "$BASELINE" != "passed" ]; then
    verdict="**Verification** — \`not run\`. The baseline of ${ORIGIN} could not be captured, so nothing was compared. ${BASELINE_SUMMARY:-The capture produced no result.}"
elif [ "$VERIFY_JOB" = "skipped" ] || [ -z "$VERIFICATION" ]; then
    verdict="**Verification** — \`not run\`. The baseline was captured but the comparison against ${ORIGIN} never produced a result."
else
    verdict="**Verification** — \`${VERIFICATION}\` against ${ORIGIN}. ${VERIFICATION_SUMMARY}"
fi

# Three states again: deployed, the deploy was attempted and failed, or none was named. With no
# environment the verdict above has already said everything, and a warning here would repeat it.
if [ -z "$ORIGIN" ]; then
    deployed=""
elif [ "$DEPLOYED" = "1" ]; then
    # Two facts, kept apart. The deploy workflow finishing says the deploy was started and did not
    # report a failure; it is not proof the environment is running this branch. The environment's
    # own report is, and the comparison waited for it.
    deployed="The deploy workflow finished${DEPLOY_RUN_URL:+ (${DEPLOY_RUN_URL})}."
    if [ -n "$DEPLOY_CONFIRMATION" ]; then
        deployed="${deployed} Before the comparison, ${DEPLOY_CONFIRMATION}, confirming that ${ORIGIN} was running this branch."
    elif [ -n "$VERIFICATION" ]; then
        deployed="${deployed} The comparison did not record how ${ORIGIN} confirmed it was running this branch."
    fi
elif [ "$DEPLOY_JOB" = "failure" ]; then
    deployed="> [!WARNING]
> The deploy to ${ORIGIN} failed${DEPLOY_RUN_URL:+ (${DEPLOY_RUN_URL})}, so nothing was compared. This change has not been rendered anywhere."
else
    deployed="> [!WARNING]
> ${ORIGIN} was not deployed with this branch, so the comparison above measured the environment as it already was. It says nothing about this change."
fi

echo "Prepared by Phone Home for ${patch_ref}."
echo
echo "$why"
echo
if [ -n "$scale" ]; then
    echo "$scale"
    echo
fi
echo "**What moved**"
echo
# Empty when this run resumed a branch an earlier run pushed, or redid one on a re-run, because the
# step that resolves the change is skipped then. Said so rather than shown as an empty block.
if [ -n "$MOVED" ]; then
    echo '```'
    echo "$MOVED"
    echo '```'
else
    echo "This run used a branch an earlier run had already pushed, so what moved is in that branch's commit rather than repeated here."
fi
echo
if [ -n "$resolved" ]; then
    echo "$resolved"
    echo
fi
echo "$verdict"
echo
# What the site itself reported moving. This, not the lock file, is the evidence that the change
# reached the environment the comparison was made against.
if [ -n "$ENVIRONMENT" ]; then
    echo "**${ENVIRONMENT}**"
    echo
fi
if [ -n "$deployed" ]; then
    echo "$deployed"
    echo
fi
# What was verified, and the limits of that. Promising screenshots of a comparison that never ran
# would send a reviewer looking for evidence that does not exist.
if [ -n "$ORIGIN" ]; then
    echo "The full result, including before and after screenshots of anything that changed,"
    echo "is on the patch in Phone Home."
    echo
    echo "What this verified is the pages the site declared, rendered before and after the change."
    echo "It did not exercise forms, the control panel, queue jobs, console commands, or anything"
    echo "that calls the site's API."
    echo
fi
echo "Opened as a draft on purpose. Nobody has merged anything."
