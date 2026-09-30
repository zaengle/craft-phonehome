# Site verification runner

A proof of concept for the inspection half of security-patch automation. The plugin describes what
to check, this runner executes those checks before and after a change, and an operator reads the
result. Nothing here opens a pull request, promotes a release, or decides anything.

It is deliberately self-contained so it can be lifted out of this repository once it has earned a
permanent home.

## How a run works

A run has two halves that share one frozen bundle under `runs/<run-id>/`.

`capture` reads the manifest from the site's plugin API, freezes it alongside a baseline screenshot
of every page it names, and stops. `compare` reloads that exact bundle, confirms the site is still
answering, re-runs the frozen definitions, and writes `result.json`.

The frozen bundle is what makes the pair meaningful. A compare run never adopts the site's current
definitions, so a page deleted between the two halves fails rather than quietly leaving the suite.

## Running it

The runner belongs inside the pilot site's DDEV project, in a container pinned to the same
Playwright version as the package. That keeps the browser, the operating system that renders the
screenshots, and the packages that drive them identical between one run and the next — and
identical to CI, once CI runs the same image. A baseline captured on a Mac will not compare cleanly
against one captured in a container, so the environment is part of the contract.

Copy the files in `ddev/` into the pilot site (see `ddev/README.md`), then:

```bash
ddev verify setup                  # install dependencies in the container, once
ddev verify capture trial-001
# change something, or deploy
ddev verify compare trial-001
```

The `verify` command reads the site's own `PHONEHOME_TOKEN`, so both halves of a pair cannot be run
against different credentials by accident.

### Addressing the site

The container reaches the site at `http://ddev-<project>-web`, its full container name, over plain
HTTP inside the project network. It deliberately does **not** use DDEV's `web` service alias: every
running DDEV project publishes that alias on the shared `ddev_default` network, so it resolves to
whichever projects happen to be up and can point the runner at a different site entirely.

### Running on the host instead

Possible, but only for a quick look. Screenshots captured on macOS will not match ones captured in
a container.

```bash
npm install
npm run install-browser

export PHV_ORIGIN=https://example.ddev.site
export PHV_TOKEN=<the site's PHONEHOME_TOKEN>
export PHV_RUN_ID=trial-001
export PHV_INSECURE_TLS=1   # local DDEV certificates only

npm run capture
npm run compare
```

`PHV_RUN_ID` is optional for `capture` and required for `compare`, because a compare run has to be
told which baseline it is comparing against. Generating one would compare a run against itself.

| Variable | Purpose |
|---|---|
| `PHV_ORIGIN` | Origin the manifest's relative paths resolve against |
| `PHV_TOKEN` | The site's plugin API token. In CI a job can obtain it from Phone Home instead; see below |
| `PHV_RUN_ID` | Identifies the frozen bundle |
| `PHV_API_ORIGIN` | Plugin API origin, when it differs from the site origin |
| `PHV_INSECURE_TLS` | Accepts a self-signed certificate, for one request, for local work only |

## Running it in CI

A baseline lives in Phone Home rather than on disk, so the two halves of a pair need not share a
machine — which in CI they cannot, since each job gets a fresh one. Set `PHV_DASHBOARD_ORIGIN` and
a capture pushes its bundle; a comparison with nothing local pulls it back before anything reads it.

`.github/workflows/site-verification.yml` in this repository is a reusable workflow. A site calls it
around its existing deploy; see `examples/verify-on-deploy.yml` for the whole file to copy.

The workflow publishes two outputs, `overall` and `summary`, carrying what the runner concluded. A
caller that quotes the result anywhere should read those rather than the job's status: the job
fails on purpose when a comparison finds something, and a caller that tolerates that failure sees
it as a success. `.github/workflows/remediate.yml` is the worked example, running a capture and a
compare under one run id around a deploy and writing the outcome into a draft pull request.

No Phone Home secret has to live in the site's repository. A job granted `id-token: write` holds
an OIDC token GitHub signed for it, naming the repository and run; `ci/site-token.sh` presents
that to Phone Home, which checks the signature against GitHub's published keys and hands back the
token of the site linked to that repository. Every grant is recorded there. A repository Phone
Home has not linked passes a `phonehome_token` secret instead, and that always wins when set.

A site that opts in to remediation copies two more files: `examples/remediate.yml`, the thin
workflow Phone Home dispatches, and `examples/deploy-staging.yml`, the deploy contract. The second
is the site's own deploy behind a `workflow_dispatch` trigger, and its one obligation is to not
exit until the ref it was started on is live on staging, failing if it is not. The remediation
workflow starts it, waits on it, and only compares when it succeeded; Phone Home refuses to
dispatch to a site that has not named one.

Three things decide whether a pair is comparable, and all three are enforced rather than assumed:

- **The browser and platform.** A baseline records its Playwright version, Chromium version,
  Chromium revision and platform, and a comparison against a different one is refused. The workflow
  pins the same image the local DDEV service uses for exactly this reason.
- **The origin.** A baseline captured against one origin is not comparable against another, so a
  local bundle can never be used against staging even by accident.
- **The run id.** Both halves must share one, and it must be new. Re-capturing over a sealed
  baseline is refused, because otherwise a comparison that found a regression could be made to pass
  by running capture again.

Note that **CI captures its own baselines**. A baseline taken on an Apple Silicon Mac records
`linux-arm64` and will never match a GitHub runner's `linux-x64`; the origins differ too. Local
bundles are for local work.

If the environment sits behind HTTP basic auth — staging commonly does — set `PHV_BASIC_AUTH_USER`
and `PHV_BASIC_AUTH_PASS`. Without them the runner photographs the browser's own auth prompt and
reports a missing required element on every page, which is a `failed` that says nothing about the
deploy.

## Outcomes

Results are four states rather than pass and fail, because the difference between them is the thing
the report exists to communicate.

- `passed` — the page matched its baseline and its required element was visible.
- `changes_detected` — the page looks different. This needs a person to interpret it and does not by
  itself mean the site is broken.
- `failed` — a required element was missing, or the page returned an error status.
- `inconclusive` — the run could not establish anything. A missing baseline, an unreachable site, a
  manifest the site has not enabled, and a manifest with a typo all land here. So does a comparison
  that was told to expect a change (`PHV_EXPECT_CHANGE=1`, which `ddev verify run` and the
  remediation workflow set) when the site reports the same Craft and plugin versions as at baseline:
  the change never reached the environment, and a clean result about the old code is not evidence.

Every comparison records what the site says moved since the baseline as `environment_delta`, by
version, for Craft and each plugin. That, not the lock file, is what says the change arrived. A
baseline captured before versions were recorded reads as unknown, never as unchanged.

A required failure or an inconclusive result takes precedence in the overall summary, but every
individual outcome stays visible in `result.json`.

## Properties worth preserving

These are the behaviours the proof of concept was built to demonstrate, and the ones most likely to
be lost in a later refactor.

- **A missing baseline never becomes a new reference.** Playwright is configured with
  `updateSnapshots: 'none'` for comparison, so an absent snapshot fails the check instead of being
  written and reported as a pass.
- **A run gets one attempt.** Retries are off, because a visual check retried until it passes is not
  evidence.
- **The site is confirmed to be answering before any page result is believed.** A host that has gone
  away can still serve a router's 404 for every path, which reads as a page-by-page failure when it
  is really an inability to verify anything.
- **Manifest drift is recorded, never acted on.** The frozen definitions run either way.
- **The manifest carries no credentials and no absolute URLs.** The origin and the token are given
  to the runner separately, and the plugin rejects any path that would leave the site.

## Known limits

Screenshot baselines are specific to the environment that captured them. Running both halves in the
pinned container is what makes them portable; capturing on the host and comparing in the container,
or the reverse, will not work. Recalibrate whenever the image version changes.

A matching pair of screenshots cannot detect a defect that was already present when the baseline was
captured, and it cannot prove a form submits.
