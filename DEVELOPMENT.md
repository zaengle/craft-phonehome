# Development guide

## Contributing to Phone Home Plugin

This project welcomes contributions and suggestions that create meaningful value and that adhere to the development standards and code quality practices, for the project. Contributions are accepted via pull requests on GitHub at the sole discretion of the maintainers.

All contributions are subject to the [plugin license](./LICENSE.md).

## Coding standards

This project follows the [Craft CMS coding standards](https://craftcms.com/docs/5.x/extend/coding-guidelines.html) for PHP, Twig, and JavaScript code.

## Code quality

To maintain high code quality, we use the following tools:
- **[PHPStan](https://phpstan.org/)** for static analysis.
- **[PHP Easy Coding Standard](https://github.com/easy-coding-standard/easy-coding-standard)** for coding standards.

Configurations for each, using the Craft CMS coding standards, are included in the `phpstan.yml` and `ecs.php` files respectively.

```bash
composer phpstan
composer check-cs
composer fix-cs
```

## Versioning

This plugin follows [Semantic Versioning](https://semver.org/). The version number is in the format `MAJOR.MINOR.PATCH`, where:

- **MAJOR** version changes indicate incompatible API changes
- **MINOR** version changes add functionality in a backwards-compatible manner
- **PATCH** version changes are for backwards-compatible bug fixes

### API Schema changes

The plugin's public API is defined in the JSON Schema located at `src/schemas/PhonehomeApi.schema.json`. This schema defines the structure of the API response and is used to validate the API output. The version of the API is defined in this schema file under the `version` property.

When making changes to the API, it is important to follow these guidelines:

API versions should shadow or trail the plugin version, i.e. the API version should:
- Always have the same major version as the plugin version.
- Never have a minor or patch version greater than the plugin version.

We are committed to not introducing breaking changes outside of major version releases, so it's important that these steps are followed to ensure compatibility with existing integrations.

- If the change does not affect the API response, you can keep the same API version.
- If you are introducing a breaking change to the API response, ensure that the major version of the API is incremented accordingly.
- If you are adding a new feature or making a non-breaking change to the API response, increment the minor version of the API.
- It is very unlikely that a patch version change will be needed, but if you do need to make a patch change to the API, increment the patch version.

## Plugin Documentation

The plugin docs are written in Markdown and are located in the `docs` directory. The main entry point is `index.md`, which serves as the homepage for the documentation.

The docs site is built using [VitePress](https://vitepress.dev/), a static site generator that uses Vue.js. To build the docs site, you need to have Node.js and npm installed. It is hosted on GitHub Pages, and the build process is automated via a GitHub Action.

### Docs Development

```shell
npm install
npm run docs:dev
```


## Release Process

To release a new version of the plugin, follow these steps:

1. **Run the code quality checks** see above for details
2. **IF** the release changes the API:
  - Ensure that the JSON Schema at `src/schemas/PhonehomeApi.schema.json` is updated to reflect the changes. At a minimum, this means updating the `version` property in the schema file to match the new plugin version.
  - The API version is now automatically pulled from the JSON schema file via the `PhoneHome::getApiVersion()` method
3. **Make any required changes to the docs**. For example, if you add a new feature or change the API, update the documentation in the `docs` directory.
4. **Update the version number in `package.json`.** We do not use the `version` property in `composer.json` as, we use git tags to manage versions.
5. **Run `npm run version:bump`** to generate the changelog and update the version in `CHANGELOG.md`.
6. **Commit Changes**: Commit the changes with a message like `Release X.Y.Z` to match the Changelog version.
6. **Tag the commit** with the version number: `git tag X.Y.Z`.
7. **Push Changes**: Push the changes to the remote repository:
8. **[Confirm the release on packagist.org](https://packagist.org/packages/zaengle/craft-phonehome)**.
