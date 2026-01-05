# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Craft CMS plugin that provides a secure API endpoint for remotely monitoring Craft CMS installations. The plugin collects comprehensive system information, plugin status, available updates, and custom status checks.

## Development Commands

### Code Quality
```bash
# Run PHPStan static analysis (level 5)
composer phpstan

# Check coding standards (Craft CMS 4 standards via ECS)
composer check-cs

# Auto-fix coding standards
composer fix-cs
```

### Documentation
```bash
# Install dependencies
npm install

# Start VitePress dev server (docs site)
npm run docs:dev

# Build documentation
npm run docs:build

# Serve built documentation
npm run docs:serve
```

### Release Process
```bash
# Update version in package.json, then:
npm run version:bump  # Generates changelog and updates CHANGELOG.md

# Tag and push
git tag X.Y.Z
git push && git push --tags
```

## Architecture

### Core Components

**Main Plugin Class**: `src/PhoneHome.php`
- Initializes plugin and registers services
- Provides static access to API version from JSON schema
- Handles settings and configuration

**API Controller**: `src/controllers/ApiController.php`
- `POST /actions/phonehome/api/index` - Returns comprehensive system info
  - Requires `X-Auth-Token` header
  - Optional `expandPhpInfo` body parameter
- `GET /actions/phonehome/api/schema` - Returns API JSON schema
  - Requires `X-Auth-Token` header

**Report Service**: `src/services/Report.php`
- Central service that aggregates all system information
- Gathers data about PHP, OS, database, image drivers, Craft version/edition, plugins, modules, updates
- Supports expandable phpinfo output
- Collects custom environment variables configured in settings

**Settings Model**: `src/models/Settings.php`
- `token` - Required authentication token (support env parsing via `App::parseEnv()`)
- `queueFailedCriticalThreshold` - Failed queue jobs threshold for critical status
- `queueFailedWarningThreshold` - Failed queue jobs threshold for warning status
- `additionalEnvKeys` - Array of additional environment variable names to include in meta section

### Status Checks System

**StatusCheckInterface**: `src/statuschecks/StatusCheckInterface.php`
- Defines contract for status checks: `getHandle()` and `check()` methods

**QueueStatusCheck**: `src/statuschecks/QueueStatusCheck.php`
- Monitors Craft queue status (delayed, waiting, failed, reserved jobs)
- Returns status enum (OK, WARNING, CRITICAL) based on failed job thresholds
- Accessed via `Report::getStatusChecks()` in the API response

**StatusCheckResult**: `src/models/StatusCheckResult.php`
- Model for status check results with status enum and meta data

**StatusCheck Enum**: `src/enums/StatusCheck.php`
- Enum values: OK, WARNING, CRITICAL

### Configuration

- Config file: `config/phonehome.php` (auto-created from `config.example.php` on install)
- Settings page available in Craft CP
- Authentication via `X-Auth-Token` header (fails with 404 in production, 401 in dev mode when missing)

## API Schema and Versioning

The plugin uses semantic versioning with a special constraint: the API schema version (defined in `src/schemas/PhonehomeApi.schema.json`) must shadow or trail the plugin version.

- API major version must match plugin major version
- API minor/patch version must never exceed plugin minor/patch version
- API version is automatically retrieved via `PhoneHome::getApiVersion()` which reads the JSON schema
- Breaking changes to API response require major version bump
- New features/non-breaking changes require minor version bump

## Coding Standards

- Follows [Craft CMS coding guidelines](https://craftcms.com/docs/5.x/extend/coding-guidelines.html)
- Uses Craft CMS PHPStan rules (vendor/craftcms/phpstan)
- Uses Craft CMS ECS standards (SetList::CRAFT_CMS_4)
- PHPStan level 5 analysis on `src/` directory
- Namespaced under `zaengle\phonehome`

## Key Patterns

- Service access: `PhoneHome::$plugin->report->getInfo()`
- Settings access: `PhoneHome::$plugin->getSettings()`
- Plugin uses custom log file via `HasOwnLogFile` trait
- Logging: `PhoneHome::info()`, `PhoneHome::error()`
- Supports both Craft 4 and Craft 5 (handles CmsEdition enum properly)
- Uses Laravel collections (`collect()`) for data transformation
- All sensitive values redacted via `Craft::$app->getSecurity()->redactIfSensitive()`