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
- `queueFailedCriticalThreshold` - Failed queue jobs threshold for critical status (default: 6)
- `queueFailedWarningThreshold` - Failed queue jobs threshold for warning status (default: 3)
- `queueDelayedCriticalThreshold` - Delayed queue jobs threshold for critical status (default: 50)
- `queueDelayedWarningThreshold` - Delayed queue jobs threshold for warning status (default: 20)
- `additionalEnvKeys` - Array of additional environment variable names to include in meta section

### Status Checks System

The plugin uses an event-based architecture for registering status checks, allowing plugins and modules to add their own custom health checks.

**Event-Based Registration Pattern**:
- Status checks are registered via `Report::EVENT_REGISTER_STATUS_CHECKS` event
- The event handler receives `RegisterStatusChecksEvent` with a `$checks` array
- Add status check class names (not instances) to the event's `$checks` array
- Each check class must implement `StatusCheckInterface`
- The Report service loops through registered checks and calls their static `check()` method

**StatusCheckInterface**: `src/statuschecks/StatusCheckInterface.php`
- Defines contract for status checks: `getName()` and `check()` methods
- Both methods are static (no instantiation required)
- `getName()` returns a human-readable name for the check
- `check()` returns a `StatusCheckResult` instance

**QueueStatusCheck**: `src/statuschecks/QueueStatusCheck.php` (Built-in check)
- Monitors Craft queue status (delayed, waiting, failed, reserved jobs)
- Returns status enum (OK, WARNING, CRITICAL) based on both failed and delayed job thresholds
- Checks failed jobs against `queueFailedWarningThreshold` and `queueFailedCriticalThreshold`
- Checks delayed jobs against `queueDelayedWarningThreshold` and `queueDelayedCriticalThreshold`
- Combined status: CRITICAL if either check is critical, WARNING if either is warning, otherwise OK
- Includes current threshold values in meta output for transparency
- Registered automatically in `PhoneHome::attachEventHandlers()`
- Demonstrates the status check implementation pattern

**RegisterStatusChecksEvent**: `src/events/RegisterStatusChecksEvent.php`
- Event class with public `$checks` array property
- Triggered by `Report::getStatusChecks()` before collecting results
- Allows multiple listeners to register checks

**StatusCheckResult**: `src/models/StatusCheckResult.php`
- Model for status check results
- Required properties: `name` (string), `status` (StatusCheck enum)
- Optional properties: `description` (string), `meta` (array) for additional check-specific data
- The `name` should be human-readable (e.g., "Queue Status", "Database Connection")
- The `description` provides additional context about what the check monitors

**StatusCheck Enum**: `src/enums/StatusCheck.php`
- Enum values: `CRITICAL` ('critical'), `WARNING` ('warning'), `OK` ('ok')

**Registration Flow**:
1. `PhoneHome::attachEventHandlers()` registers built-in checks (QueueStatusCheck)
2. Modules/plugins can register their own checks via the same event
3. When API is called, `Report::getStatusChecks()` triggers the event
4. All registered check classes have their `check()` method called
5. Results are collected and returned in the API response under `status_checks`

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

### Schema Maintenance

**CRITICAL**: The JSON schema in `src/schemas/PhonehomeApi.schema.json` must be kept in sync with the actual API response structure returned by `Report::getInfo()`.

When modifying the API response:

1. **Adding a new field** to the response:
   - Add the field definition to the `properties` section in the schema
   - If the field is always present, add it to the `required` array
   - Bump the schema minor version (e.g., 1.2.0 → 1.3.0)

2. **Removing or renaming a field**:
   - This is a breaking change - bump the schema major version
   - Update the schema to remove the old field definition
   - Document the breaking change in the changelog

3. **Changing field structure** (e.g., changing type or adding nested properties):
   - If backward compatible (e.g., making a field nullable), bump minor version
   - If breaking (e.g., changing from string to array), bump major version
   - Update the field definition in the schema accordingly

4. **Verification workflow**:
   - After any changes to `Report::getInfo()` or related methods, review the schema
   - Ensure all returned fields are documented in the schema
   - Verify the schema version has been bumped appropriately
   - Test that the API endpoint returns data matching the schema structure

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

## Implementing Custom Status Checks

When adding new status checks to the plugin:

1. **Create the check class** in `src/statuschecks/`
2. **Implement StatusCheckInterface** with static methods
3. **Define a HANDLE constant** for the check identifier
4. **Register the check** in `PhoneHome::attachEventHandlers()`

Example implementation:

```php
// src/statuschecks/DatabaseStatusCheck.php
namespace zaengle\phonehome\statuschecks;

use Craft;
use zaengle\phonehome\enums\StatusCheck;
use zaengle\phonehome\models\StatusCheckResult;

class DatabaseStatusCheck implements StatusCheckInterface
{
    public static function getName(): string
    {
        return 'Database Connection';
    }

    public static function getDescription(): string
    {
        return 'Monitors the database connection status';
    }

    public static function check(): StatusCheckResult
    {
        $db = Craft::$app->getDb();
        $connectionOk = $db->getIsActive();

        return new StatusCheckResult([
            'name' => self::getName(),
            'status' => $connectionOk ? StatusCheck::OK : StatusCheck::CRITICAL,
            'description' => self::getDescription(),
            'meta' => [
                'driver' => $db->getDriverName(),
                'is_active' => $connectionOk,
            ],
        ]);
    }
}
```

Then register in `PhoneHome::attachEventHandlers()`:

```php
Event::on(
    Report::class,
    Report::EVENT_REGISTER_STATUS_CHECKS,
    function(RegisterStatusChecksEvent $event) {
        $event->checks[] = QueueStatusCheck::class;
        $event->checks[] = DatabaseStatusCheck::class; // Add new check
    }
);
```

**Best Practices**:
- Keep checks lightweight and fast (they run on every API call)
- Use human-readable names (e.g., "Queue Status" not "queue")
- Provide a clear `description` that explains what the check monitors
- Use the `meta` array for detailed diagnostic information (e.g., current values, thresholds)
- Return `CRITICAL` for issues that require immediate attention
- Return `WARNING` for issues that should be monitored
- Make check methods testable by accepting optional dependencies