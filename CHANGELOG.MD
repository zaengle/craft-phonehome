# Release Notes for Craft Phone Home Plugin

## [1.5.0] - 2026-08-20

- Score pending queue jobs against configurable thresholds [`#24`](https://github.com/zaengle/craft-phonehome/pull/24)
- added: score pending queue jobs against configurable thresholds [`6af2e09`](https://github.com/zaengle/craft-phonehome/commit/6af2e096274feaa1f3638f730f5cbe6b7434108e)
- fixed: review findings on queue status check [`b79925c`](https://github.com/zaengle/craft-phonehome/commit/b79925ca1c6dfe8897cf1debdc5da4479cb7aedd)
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
