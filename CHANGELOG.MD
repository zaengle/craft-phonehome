# Release Notes for Craft Phone Home Plugin

## [1.2.2] - 2025-08-13

- fixed: changed custom logging to use monolog's logger levels [1c55d65](https://github.
  com/zaengle/craft-phonehome/commit/1c55d652403c4ef27ec8b02b466da20295fa86ce)

## [1.2.1] - 2025-08-13

- fixed: changed logging to use yii's logger levels [5d5f04b](https://github.com/zaengle/craft-phonehome/commit/5d5f04b62a92d0a549ff8e2f77fa0f48a2939273)

## [1.2.0] - 2025-08-01

- build(deps): bump actions/checkout from 2 to 4 [`#8`](https://github.com/zaengle/craft-phonehome/pull/8)
- 1.1.1 release [`cdb37f4`](https://github.com/zaengle/craft-phonehome/commit/cdb37f4815d6072b8b09e2f4bfe12a453b11c52d)
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

[1.2.0]: https://github.com/zaengle/craft-phonehome/compare/1.1.3...1.2.0
[1.1.3]: https://github.com/zaengle/craft-phonehome/compare/1.1.2...1.1.3
[1.1.2]: https://github.com/zaengle/craft-phonehome/compare/1.1.1...1.1.2
[1.1.1]: https://github.com/zaengle/craft-phonehome/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/zaengle/craft-phonehome/compare/v1.0.0...1.1.0
