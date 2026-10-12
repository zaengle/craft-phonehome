#!/usr/bin/env bash
#
# Prints the Phone Home token a job should use, obtaining one from Phone Home when the calling
# repository holds none.
#
# A workflow run can prove who it is: GitHub signs it an OIDC token naming the repository, ref and
# run. Presented to Phone Home, that gets the linked site's token back, so a client repository need
# not hold a PHONEHOME_TOKEN secret at all. A repository that does hold one still wins, because a
# site can be verified from a repository Phone Home has not linked.
#
#   PHV_TOKEN            A token the caller already has. Printed as-is when set.
#   PHV_DASHBOARD_ORIGIN Where Phone Home is.
#   PHV_ORIGIN           The site being rendered, so Phone Home picks the right one of a pair.
#   PHV_OIDC_AUDIENCE    The audience Phone Home expects. Default: phone-home.
#
# With `--json`, prints Phone Home's whole answer instead of the token alone: the token, the site,
# and under `cloudflare_access` the Cloudflare Access service token the site holds, or null. A
# site behind Access keeps that token in Phone Home for the same reason it keeps the site token,
# so a run that proves who it is gets both and the client repository holds neither. When the
# caller already holds a token there is no exchange, and the answer carries the token alone.
#
# Needs `id-token: write` on the job, which is what makes ACTIONS_ID_TOKEN_REQUEST_URL exist.

set -euo pipefail

format=token
if [ "${1:-}" = "--json" ]; then
    format=json
fi

if [ -n "${PHV_TOKEN:-}" ]; then
    if [ "$format" = "json" ]; then
        node -e 'process.stdout.write(JSON.stringify({ token: process.argv[1] }))' "$PHV_TOKEN"
    else
        printf '%s' "$PHV_TOKEN"
    fi
    exit 0
fi

if [ -z "${PHV_DASHBOARD_ORIGIN:-}" ]; then
    echo "No PHONEHOME_TOKEN was given and no dashboard origin is set to obtain one from." >&2
    exit 1
fi

if [ -z "${ACTIONS_ID_TOKEN_REQUEST_URL:-}" ] || [ -z "${ACTIONS_ID_TOKEN_REQUEST_TOKEN:-}" ]; then
    echo "No PHONEHOME_TOKEN was given and this job cannot request an identity token; it needs 'id-token: write'." >&2
    exit 1
fi

audience="${PHV_OIDC_AUDIENCE:-phone-home}"

identity="$(curl -sS --fail-with-body -H "Authorization: bearer ${ACTIONS_ID_TOKEN_REQUEST_TOKEN}" \
    "${ACTIONS_ID_TOKEN_REQUEST_URL}&audience=${audience}" \
    | node -e 'let b="";process.stdin.on("data",c=>b+=c).on("end",()=>{const v=JSON.parse(b).value;if(!v)process.exit(1);process.stdout.write(v)})')"

body="$(node -e 'process.stdout.write(JSON.stringify({ origin: process.argv[1] || null }))' "${PHV_ORIGIN:-}")"

response="$(curl -sS --fail-with-body -X POST "${PHV_DASHBOARD_ORIGIN%/}/api/site-tokens" \
    -H "Authorization: Bearer ${identity}" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "$body")" || {
    echo "Phone Home refused to hand this run a site token. Is the repository linked to a site there?" >&2
    exit 1
}

# The answer is checked for a token either way, so a reply that carries none fails here rather
# than reaching a caller as an empty string that only shows up as a 401 later.
printf '%s' "$response" | node -e 'let b="";process.stdin.on("data",c=>b+=c).on("end",()=>{const r=JSON.parse(b);if(!r.token)process.exit(1);process.stdout.write(process.argv[1]==="json"?JSON.stringify(r):r.token)})' "$format"
