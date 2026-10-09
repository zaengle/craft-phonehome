# Release Notes for Craft Phone Home Plugin

## Unreleased

This release changes what a remediation commits. The plugin itself and its API are unchanged, so the API schema stays at 1.6.0.

- A remediation now keeps project config in step with the schema versions it installs. When an update raises a Craft plugin's schema version, or Craft's, the remediation rewrites the matching `schemaVersion` in the site's project config and commits it with the lock file, and the pull request lists each change. Before this, such an update migrated the database on deploy and then stopped at Craft's project config check, and every later deploy failed until the YAML was edited by hand. When the update brings a new migration that writes project config, which a version number cannot capture, the pull request still opens but leads with a warning to apply the update locally before merging.
- The `verify-on-deploy.yml` example now mints `<commit SHA>-<GitHub run id>-<attempt>` as the run id, and recommends `<deployment SHA>-deploy-<deployment id>` for the `deployment_status` shape. A run id must begin with the deployed commit's SHA, or Phone Home records the comparison but never links it to a patch.
- The remediation's branch name, pull request title and commit message are now documented as a contract that sites may rely on, and are pinned by tests. Their formats are unchanged.
- The site verification runner moves to Playwright 1.63.0, in the matching `mcr.microsoft.com/playwright:v1.63.0-noble` image, and a test now fails if the image and the runner name different Playwright releases.
- The API documentation now says that a request with no token is answered with 404 outside dev mode and 401 in dev mode, and that a wrong token is answered with 401.

## [1.8.3] - 2026-10-07

This release lets the environment say exactly what it is running, so a deployment is confirmed by the site itself rather than by whoever deployed it. The API schema moves to 1.6.0. The change is additive, so nothing existing is removed or renamed.

- The report has a new `revision` object.
  - `lock_hash` is a SHA-256 of the exact package versions the site's composer.lock pins. It is not Composer's `content-hash`, which does not change when an update moves locked versions.
  - `commit` is the deployed commit's SHA, read from the first source that holds one: an environment variable or file the site names, the `PHONE_HOME_REVISION` environment variable, a `REVISION` file at the project root (which Envoyer and Capistrano-style deploys write), and then `.git/HEAD` when the release is a git clone (which Forge produces). Git is read as files and never run.
  - `commit_source` says which source answered.

  Every field is null when it cannot be established, and reading it never fails the report.
- A site can name its own source with `revisionEnv` (an environment variable name) or `revisionFile` (a path relative to the project root) in its `verification` config. A malformed value makes the manifest invalid with an error naming the setting.
- Before a comparison captures the after side, the runner now waits for the environment to report the commit it was given. When the environment reports no commit, it waits for the lock file's hash. A reported commit that differs keeps the runner waiting, whatever the package versions say. A site on a plugin older than 1.8.3 reports neither, and keeps the version-based wait.
- The remediation pull request no longer presents the deploy workflow finishing as proof of the deploy. It says that the workflow finished, and how the environment confirmed the change before the comparison: by its commit, its lock file hash or its package versions. The deploy contract in the examples now says the same. A deploy workflow must still fail when it knows the deploy failed, but a hook that answers when the deploy is queued makes the comparison slower, not wrong.
- The pull request body names the Phone Home patch as a link to its page instead of as `#90`. GitHub turned the bare number into a link to an unrelated issue or pull request with the same number in the site's repository. The remediation commit says "Phone Home patch 90" for the same reason.
- The example callers now reference the `1.8.3` tag.

## [1.8.2] - 2026-10-06

This release changes how the shared remediation workflow updates Composer. The plugin itself and its API are unchanged, so the API schema stays at 1.5.0.

- A remediation now moves as little as the security release requires. The workflow tries the requested package alone first, then lets its own dependencies move, and lets root requirements such as Craft move only when neither narrower update can be resolved, each time with Composer's `--minimal-changes`. Before this, every update moved every dependency, which turned one plugin release into a 48-package pull request on the first real site.
- The remediation pull request says how far the update had to reach and quotes the conflict that made it widen, so a reviewer can tell what the release required from what moved alongside it.
- A Composer failure that is not a dependency conflict, such as a missing credential, now stops the update at once rather than being retried more broadly.
- The example callers now reference the `1.8.2` tag. A site keeps the old behaviour until its own `remediate.yml` names this tag or a later one.

## [1.8.1] - 2026-10-06

This release changes the shared remediation workflows and the example files a site copies. The plugin itself and its API are unchanged, so the API schema stays at 1.5.0.

- A site that keeps its Craft application in a subdirectory, such as `src/`, can now be remediated. The shared `remediate.yml` takes a `working_directory` input and runs the Composer update, the lock file comparison and the commit of the lock file inside it, and the comparison waits for the lock file found there. A site that leaves the input at the repository root while its Composer files are elsewhere gets an error naming the input, rather than a lock file created at the root.
- The example `remediate.yml` and `verify-on-deploy.yml` now call the shared workflows at the `1.8.1` tag instead of a placeholder, and show the one line a site in a subdirectory changes in each. A site whose callers still name `feature/verification-manifest-poc` should move them to this tag, because that branch is being retired.
- The three workflow problems found in the 6 October pilot run were fixed in 1.8.0 and are unchanged here. A comparison waits for the environment to be running the deployed commit, a re-run updates the open pull request rather than failing to open a second one, and every attempt of a remediation has its own run id.

## [1.8.0] - 2026-10-06

This release adds deployment verification and the workflows Phone Home uses to prepare and prove a security remediation. The API schema moves to 1.5.0, which adds the `verification` field and a `package_name` on each plugin; nothing existing is removed or renamed.

- A site can declare pages to verify in a `verification` block in `config/phonehome.php`, or let the plugin choose them, and the report publishes the result as a verification manifest with its template coverage.
- The Playwright runner under `tools/site-verification` captures a baseline of those pages, compares a later run against it, and reports one of four outcomes: `passed`, `changes_detected`, `failed` or `inconclusive`. A run that cannot establish anything is inconclusive and is never reported as a pass.
- Baselines and results are stored on Phone Home, so the two halves of a comparison need not run on the same machine, and a run proves its identity to Phone Home with GitHub's OIDC token in place of a stored secret.
- The reusable `site-verification.yml` and `remediate.yml` workflows, with the examples a site copies, let Phone Home dispatch a remediation that updates one package, deploys the branch to a sibling environment, compares it, and opens a draft pull request carrying the verdict.
- A comparison now waits until the environment reports the package versions in the deployed commit's `composer.lock` before it captures the after side, and ends inconclusive when the wait times out. This holds however the workflow was started; before it, a comparison started by a push could measure the environment before the host had deployed the commit.
- A re-run of a remediation now updates the draft pull request the first attempt opened instead of failing to open a second one, and "Re-run all jobs" redoes the verification rather than finding the open pull request and stopping.
- Every attempt of a remediation now has its own run id. A re-run that repeats the comparison without the baseline is inconclusive and says to use "Re-run all jobs", rather than comparing against an earlier attempt's capture.
- The example workflows reference `RELEASE_TAG_TO_FILL_IN`, which a site replaces with this release's tag or a later one.
- Remediation deploys require a baseline stored on Phone Home, and comparisons require a confirmed deploy. Nested workflows follow the same release commit, and caller inputs are passed through environment variables rather than inserted into shell commands.
- Staging basic-auth credentials also authenticate the plugin API on the same origin. Deployment checks tolerate older plugins reported by handle and require Craft's version to be reported.
- Malformed automatic-coverage option types produce an invalid manifest instead of breaking the monitoring response. Expired and future-dated entries do not count toward live template coverage.

## [1.7.0] - 2026-09-23

- Promote develop to main for 1.7.0 [`#30`](https://github.com/zaengle/craft-phonehome/pull/30)
- fixed: locate package.json instead of assuming it sits at @root [`#29`](https://github.com/zaengle/craft-phonehome/pull/29)
- fixed: stage the changelog when bumping the version [`0f59563`](https://github.com/zaengle/craft-phonehome/commit/0f595633fb73c4b06d28556c287fb52e5a651978)

## [1.6.0] - 2026-09-23

- Promote develop to main to bring CI onto main [`#28`](https://github.com/zaengle/craft-phonehome/pull/28)
- added: run tests and static analysis on pull requests [`#26`](https://github.com/zaengle/craft-phonehome/pull/26)
- Promote develop to main for 1.6.0 [`#27`](https://github.com/zaengle/craft-phonehome/pull/27)
- added: npm package reporting to API response [`#25`](https://github.com/zaengle/craft-phonehome/pull/25)
- added: unit tests for npm package reporting [`d62ad87`](https://github.com/zaengle/craft-phonehome/commit/d62ad879e5ae37d10cc53f2dc3d56a625941f4d8)
- fixed: report an explicit collection status for the npm section [`61c7f82`](https://github.com/zaengle/craft-phonehome/commit/61c7f824ee2574338901c9285e1391c3f8f4481d)
- fixed: review findings on npm package reporting [`93197f3`](https://github.com/zaengle/craft-phonehome/commit/93197f353eca19923b2973c1ed6dae765a2d2bc3)
- fixed: keep a mapping failure from taking down the whole report [`ca035c9`](https://github.com/zaengle/craft-phonehome/commit/ca035c97f507eef82be209b15ce7b641624e5158)
- added: run tests, static analysis and coding standards on pull requests [`9e4a36d`](https://github.com/zaengle/craft-phonehome/commit/9e4a36dea1ee1e2f2168579eb468785748020a60)
- fixed: do not report a post-manifest failure as a manifest problem [`084cee2`](https://github.com/zaengle/craft-phonehome/commit/084cee266ad0177b030fb46e040775f996354ba2)
- fixed: apply credential strip to resolved versions [`16fd411`](https://github.com/zaengle/craft-phonehome/commit/16fd4117a1eb66a268233cb31be8606d512bfc90)
- Release 1.6.0 [`9bec8d5`](https://github.com/zaengle/craft-phonehome/commit/9bec8d5705f4e991385f8504afd9dd132f7c7209)
- fixed: pin workflow actions to full commit SHAs [`b47c31c`](https://github.com/zaengle/craft-phonehome/commit/b47c31c408d9894e3be06e3a40b49192316c1634)
- fixed: document that a null package_manager can also mean detection failed [`1d6c656`](https://github.com/zaengle/craft-phonehome/commit/1d6c65633b46ad3283585639b4a08de0504a9fdd)
- fixed: drop PHP 8.2 from the test matrix [`e58d63d`](https://github.com/zaengle/craft-phonehome/commit/e58d63d749c066f92b433a273fc00bbce992348a)
- fixed: strip credentials past an @ in an npm URL password [`ed4419a`](https://github.com/zaengle/craft-phonehome/commit/ed4419a73da49d4ce918f314c989e5dd9c6ad0e2)
- fixed: complete the phpstan config fix [`b88c102`](https://github.com/zaengle/craft-phonehome/commit/b88c1020a8be48b76f4ddcef1dceab535267d09f)
- fixed: make the phpstan config load [`8cb8d6b`](https://github.com/zaengle/craft-phonehome/commit/8cb8d6bd274ed2c1abd87dfe7ef63040f9000d01)

## [1.5.0] - 2026-08-20

- Score pending queue jobs against configurable thresholds [`#24`](https://github.com/zaengle/craft-phonehome/pull/24)
- added: score pending queue jobs against configurable thresholds [`6af2e09`](https://github.com/zaengle/craft-phonehome/commit/6af2e096274feaa1f3638f730f5cbe6b7434108e)
- fixed: review findings on queue status check [`b79925c`](https://github.com/zaengle/craft-phonehome/commit/b79925ca1c6dfe8897cf1debdc5da4479cb7aedd)
- Release 1.5.0 [`b9e6eb9`](https://github.com/zaengle/craft-phonehome/commit/b9e6eb9d64dd22f971931fa48d749d0b7f0770ca)
- changed: lower pending job thresholds to 20/50 [`75a31bc`](https://github.com/zaengle/craft-phonehome/commit/75a31bc1a344c9e18a5857d21b7cab495b925fe0)
- Revise security policy and reporting guidelines [`ae8b9c9`](https://github.com/zaengle/craft-phonehome/commit/ae8b9c9abc325f5a2cfb32c63ff9c1bee16dfa6e)
- Add permissions for contents in deploy-docs workflow [`f1b2048`](https://github.com/zaengle/craft-phonehome/commit/f1b204831a25994186b3f75003038183a77204ee)
- Add permissions to create-release workflow [`763e2c0`](https://github.com/zaengle/craft-phonehome/commit/763e2c04709f1dc0cc7849d8702e29cb7386d8da)

## [1.4.0] - 2026-01-05

- added: configurable status checks [`3d53e61`](https://github.com/zaengle/craft-phonehome/commit/3d53e6174688dd5c8b157c12e96c81d471d9f789)
- improved: add check for delayed jobs, add queue threshold settings to plugin settings template [`f410fda`](https://github.com/zaengle/craft-phonehome/commit/f410fda8a94815dd9907576d3e43df830b44f36f)
- added: claude init file [`300d66c`](https://github.com/zaengle/craft-phonehome/commit/300d66ca08b5c00b2d70eb70b7ab82ea8da2864a)
- chore: self-review fixes [`038e2b3`](https://github.com/zaengle/craft-phonehome/commit/038e2b3a95801e7e1059597c54c04304a8037065)
- added: check descriptions + threshold info in meta in queue check [`0c128ec`](https://github.com/zaengle/craft-phonehome/commit/0c128eceb7ab46eedef3a7ae172102aa9941dbfa)
- added: missing events dir [`79c1596`](https://github.com/zaengle/craft-phonehome/commit/79c1596f46bcbc53794864a44474bb81677016f1)
- fixed: error on date formatting of plugin updates [`36714d9`](https://github.com/zaengle/craft-phonehome/commit/36714d9f793527e415fc24dd965721dc7acc093e)

## [1.3.0] - 2026-01-05

- chore: composer update [`42ee211`](https://github.com/zaengle/craft-phonehome/commit/42ee2117aede97a8072bfc04db7bc7c78ecc1c05)
- chore: npm update [`007b84b`](https://github.com/zaengle/craft-phonehome/commit/007b84bc93207f5d525fa87251a378b37bded3a6)
- chore: update GH actions deps [`f67a703`](https://github.com/zaengle/craft-phonehome/commit/f67a70309eb299db71f1d8639ba0e497f1c7c285)

## [1.2.4] - 2025-08-21

- 1.2.4 release [`6857cee`](https://github.com/zaengle/craft-phonehome/commit/6857cee07c3d9e723f9600782764eef0c7b228ec)
- fixed: error on date formatting of plugin updates [`096e9ca`](https://github.com/zaengle/craft-phonehome/commit/096e9ca66e7ade18fc3848ff1f8f067942808a51)

## [1.2.3] - 2025-08-14

- Refactor logging methods and correct trait name casing. [`b3999e7`](https://github.com/zaengle/craft-phonehome/commit/b3999e7f228e607adec8e59faadb85b7c8a0ab99)
- Release 1.2.3 [`0d209d7`](https://github.com/zaengle/craft-phonehome/commit/0d209d7cc9078cbd15c9003ed0a5b7fba2eafbc6)

## [1.2.2] - 2025-08-13

- Update CHANGELOG with release notes for v1.2.2 [`18e8216`](https://github.com/zaengle/craft-phonehome/commit/18e8216b1543fa7543766f84bb851ccfdef86f8c)
- Refactor logging to use `Psr\Log\LogLevel` constant instead of `yii\log\Logger` constant. [`1c55d65`](https://github.com/zaengle/craft-phonehome/commit/1c55d652403c4ef27ec8b02b466da20295fa86ce)

## [1.2.1] - 2025-08-13

- Refactor logging to use `yii\log\Logger` constants instead of `Psr\Log\LogLevel`. [`5d5f04b`](https://github.com/zaengle/craft-phonehome/commit/5d5f04b62a92d0a549ff8e2f77fa0f48a2939273)
- Updated CHANGELOG.MD [`d632626`](https://github.com/zaengle/craft-phonehome/commit/d6326267db04a9017a98c8150b8dca51d3912628)

## [1.2.0] - 2025-08-01

- build(deps): bump actions/checkout from 2 to 4 [`#8`](https://github.com/zaengle/craft-phonehome/pull/8)
- 1.1.1 release [`cdb37f4`](https://github.com/zaengle/craft-phonehome/commit/cdb37f4815d6072b8b09e2f4bfe12a453b11c52d)
- 1.2.0 release [`4c6443f`](https://github.com/zaengle/craft-phonehome/commit/4c6443f5c5c0e0ccfedfc55daffddd99fa220e9a)
- improved: return a 404 for invalid requests if devMode is false [`88586ea`](https://github.com/zaengle/craft-phonehome/commit/88586ea54e39a151358d4a775f59bed1438050a5)
- buildId to output [#10] [`1e4ca1f`](https://github.com/zaengle/craft-phonehome/commit/1e4ca1f86eff43e7552780ae51e98b2419671011)

## [1.1.3] - 2025-07-31

- Update release date formatting to use nullable safe operator in Report service [`#9`](https://github.com/zaengle/craft-phonehome/pull/9)
- Updating version [`1a934e8`](https://github.com/zaengle/craft-phonehome/commit/1a934e834a0ca922ed052982508bcc96230f8ba2)

## [1.1.2] - 2025-07-29

- Update changelog [`8890417`](https://github.com/zaengle/craft-phonehome/commit/8890417ae5baaca23882595de8e3ea0b7948d7c3)
- update changelog [`da78ac7`](https://github.com/zaengle/craft-phonehome/commit/da78ac792ef3870fe4439d301cc17735cbb0d905)
- fixed: ensure modules are objects before calling get_class() on them [`6165f4c`](https://github.com/zaengle/craft-phonehome/commit/6165f4cbe9269d8baee4a0197f52204a2dc6e26f)

## [1.1.1] - 2025-07-29

- fixed: PHP warning from require of config/app.php when mapping installed modules [`db9aea2`](https://github.com/zaengle/craft-phonehome/commit/db9aea239c4fec3398a0535cc09dd358be827930)

## [1.1.0] - 2025-07-18

- Add documentation + JSON schema [`#6`](https://github.com/zaengle/craft-phonehome/pull/6)
- chore: composer cleanup [`4051a17`](https://github.com/zaengle/craft-phonehome/commit/4051a172ad5a54746f4412daa71c910e2f17c0fe)
- added: first pass at docs [`1c81856`](https://github.com/zaengle/craft-phonehome/commit/1c81856e3062a97759168ce9c38f9bbc22d31d69)
- added: JSON schema + docs + endpoint [`76d73a3`](https://github.com/zaengle/craft-phonehome/commit/76d73a3eb8356c73ac6bf95da86ee444dd39dae4)
- added: gh workflows + dependabot config [`7b1c394`](https://github.com/zaengle/craft-phonehome/commit/7b1c39435afef3d0b81cbb6219ef9c8c9f768a08)
- 1.1.0 release [`65097a6`](https://github.com/zaengle/craft-phonehome/commit/65097a6c79dd7d09edbf3246a555c01cc49c067f)
- removed: /phone-home route [`801769e`](https://github.com/zaengle/craft-phonehome/commit/801769e0f48aed2c94f4ad4711550ef093d11819)
- added: api_version to plugin response [`5a461b3`](https://github.com/zaengle/craft-phonehome/commit/5a461b3554d6359d99bd3285125a192d0f40acde)
- build: configure composer.json for plugin store release [`e2dad1d`](https://github.com/zaengle/craft-phonehome/commit/e2dad1db202a49d7815b2989a69f8e95a8a9f51a)
- Update 02-config.md [`95b93bb`](https://github.com/zaengle/craft-phonehome/commit/95b93bb463303085910251b4f55455bbc47438cd)
- Update .github/dependabot.yml [`9536724`](https://github.com/zaengle/craft-phonehome/commit/953672483d81baf4dd6feb80d67792ea9b122f11)
- Update docs/03-api.md [`7140023`](https://github.com/zaengle/craft-phonehome/commit/71400233174721d933c5cfd913e04db46aac4439)
- Update docs/02-config.md [`7908cb1`](https://github.com/zaengle/craft-phonehome/commit/7908cb18008b5c228c6936fc257752e98e9baacb)
- Update src/PhoneHome.php [`fde92cc`](https://github.com/zaengle/craft-phonehome/commit/fde92ccce1205ac5feba790fad55224cedc55151)

## v1.0.0 - 2025-07-16

- Initial commit [`490a6d0`](https://github.com/zaengle/craft-phonehome/commit/490a6d0b08758d148f64d2c90720a057d590316b)

[1.7.0]: https://github.com/zaengle/craft-phonehome/compare/1.6.0...1.7.0
[1.6.0]: https://github.com/zaengle/craft-phonehome/compare/1.5.0...1.6.0
[1.5.0]: https://github.com/zaengle/craft-phonehome/compare/1.4.0...1.5.0
[1.4.0]: https://github.com/zaengle/craft-phonehome/compare/1.3.0...1.4.0
[1.3.0]: https://github.com/zaengle/craft-phonehome/compare/1.2.4...1.3.0
[1.2.4]: https://github.com/zaengle/craft-phonehome/compare/1.2.3...1.2.4
[1.2.3]: https://github.com/zaengle/craft-phonehome/compare/1.2.2...1.2.3
[1.2.2]: https://github.com/zaengle/craft-phonehome/compare/1.2.1...1.2.2
[1.2.1]: https://github.com/zaengle/craft-phonehome/compare/1.2.0...1.2.1
[1.2.0]: https://github.com/zaengle/craft-phonehome/compare/1.1.3...1.2.0
[1.1.3]: https://github.com/zaengle/craft-phonehome/compare/1.1.2...1.1.3
[1.1.2]: https://github.com/zaengle/craft-phonehome/compare/1.1.1...1.1.2
[1.1.1]: https://github.com/zaengle/craft-phonehome/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/zaengle/craft-phonehome/compare/v1.0.0...1.1.0
