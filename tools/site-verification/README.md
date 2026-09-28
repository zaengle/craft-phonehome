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
| `PHV_TOKEN` | The site's plugin API token |
| `PHV_RUN_ID` | Identifies the frozen bundle |
| `PHV_API_ORIGIN` | Plugin API origin, when it differs from the site origin |
| `PHV_INSECURE_TLS` | Accepts a self-signed certificate, for one request, for local work only |

## Outcomes

Results are four states rather than pass and fail, because the difference between them is the thing
the report exists to communicate.

- `passed` — the page matched its baseline and its required element was visible.
- `changes_detected` — the page looks different. This needs a person to interpret it and does not by
  itself mean the site is broken.
- `failed` — a required element was missing, or the page returned an error status.
- `inconclusive` — the run could not establish anything. A missing baseline, an unreachable site, a
  manifest the site has not enabled, and a manifest with a typo all land here.

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
