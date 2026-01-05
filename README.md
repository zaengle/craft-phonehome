# Phone Home Plugin for Craft CMS

> Monitor the status of your #CraftCMS projects remotely

Phonehome a secure API endpoint that returns comprehensive information about a Craft CMS installation and the server environment it is installed in.

## Requirements

This plugin requires Craft CMS 4.0.0+ or 5.0.0+, and PHP 8.1+.

## Quickstart Installation

```bash
composer require zaengle/craft-phonehome
./craft plugin/install phonehome
```
then in `config/phonehome.php` add:

```php
return [
     'token' => getenv('PHONEHOME_TOKEN'), // Your secure token from Zaengle Phone Home service
];
```

## Documentation

[See the full documentation online](https://craft-phonehome-docs.zaengle.com/) or in the [`docs/`](./docs/index.md) directory of this repository.

## API Schema

The plugin provides a JSON schema that defines the structure of the API response. The schema is located at `src/schemas/PhonehomeApi.schema.json` and follows semantic versioning.

You can retrieve the schema via the API endpoint:

```
GET /actions/phonehome/api/schema
X-Auth-Token: your-token-here
```

**Important for contributors**: When modifying the API response structure in `Report::getInfo()`, you must update the schema file accordingly and bump the schema version following semantic versioning rules. See `CLAUDE.md` for detailed schema maintenance guidelines.

## Configuration

### Additional Environment Variables

You can configure the plugin to include additional environment variables in the API response by setting `additionalEnvKeys` in your `config/phonehome.php`:

```php
return [
    'token' => getenv('PHONEHOME_TOKEN'),
    'additionalEnvKeys' => [
        'MY_CUSTOM_ENV_VAR',
        'DEPLOYMENT_ID',
        'BUILD_NUMBER',
    ],
];
```

These values will be included in the `meta` section of the API response, making it easy to include deployment-specific information alongside the standard system metrics.

## Extensibility

### Custom Status Checks

You can register custom status checks to monitor application-specific health metrics. Status checks are registered via Craft's event system:

```php
use yii\base\Event;
use zaengle\phonehome\services\Report;
use zaengle\phonehome\events\RegisterStatusChecksEvent;

Event::on(
    Report::class,
    Report::EVENT_REGISTER_STATUS_CHECKS,
    function(RegisterStatusChecksEvent $event) {
        $event->checks[] = MyCustomStatusCheck::class;
    }
);
```

Your status check class must implement `StatusCheckInterface`:

```php
use zaengle\phonehome\statuschecks\StatusCheckInterface;
use zaengle\phonehome\models\StatusCheckResult;
use zaengle\phonehome\enums\StatusCheck;

class MyCustomStatusCheck implements StatusCheckInterface
{
    public static function getName(): string
    {
        return 'My Custom Check';
    }

    public static function getDescription(): string
    {
        return 'Monitors custom application metrics';
    }

    public static function check(): StatusCheckResult
    {
        return new StatusCheckResult([
            'name' => self::getName(),
            'status' => StatusCheck::OK,
            'description' => self::getDescription(),
            'meta' => [
                'custom_metric' => 'value',
            ],
        ]);
    }
}
```

## Support

For support, visit the [GitHub repository](https://github.com/zaengle/craft-phonehome).

## Development

See the [development guide](./DEVELOPMENT.md) for details on how to contribute to this project.

