# Configuring the plugin

Phonehome exposes a single controller endpoint at `/actions/phonehome/api` with information about your Craft installation and the server environment it is installed in. 

The plugin can be configured either via its settings page in the Craft control panel or via its config file, a starter version of which will be automatically created at `config/phonehome.php` as part of the plugin's install command.

## Getting started

The config file supports the following keys:

- `token` - a shared, secret, token that will be used to authenticate requests to the Phone Home API. This should be set to a secure, random string.
- `additionalEnvKeys` - an array of environment keys to include in the API response. These keys will be added to the `meta` section of the response, allowing you to send custom site-specific or environment-specific data to the Phone Home service.

Example:

```php
return [
  '*' => [
    // Available transformers that should be registered with Craft
    'token' => getenv('PHONEHOME_TOKEN'),
    'additionalEnvKeys' => ['MY_CUSTOM_KEY', 'ANOTHER_KEY'],
  ],
];
```

## Queue status check thresholds

The built-in Queue status check reports the Craft queue's failed, delayed, pending (waiting), and reserved job counts, and scores the first three against the thresholds below. A count that meets or exceeds a threshold reports that status; `CRITICAL` takes precedence over `WARNING`.

| Key | Default | Description |
| --- | --- | --- |
| `queueFailedWarningThreshold` | `3` | Failed jobs that trigger a `WARNING` status |
| `queueFailedCriticalThreshold` | `6` | Failed jobs that trigger a `CRITICAL` status |
| `queueDelayedWarningThreshold` | `20` | Delayed jobs that trigger a `WARNING` status |
| `queueDelayedCriticalThreshold` | `50` | Delayed jobs that trigger a `CRITICAL` status |
| `queuePendingWarningThreshold` | `20` | Pending (waiting) jobs that trigger a `WARNING` status |
| `queuePendingCriticalThreshold` | `50` | Pending (waiting) jobs that trigger a `CRITICAL` status |

"Pending" is the label Craft's control panel uses for waiting jobs; the count is reported as `meta.waiting` in the API response.

Notes:

- Each threshold may be set to an integer or to an env var name (e.g. `'$PHONEHOME_QUEUE_PENDING_CRITICAL'`), the same as `token`.
- Setting a threshold to `0` (or leaving it blank) disables that level. This also means an env var that is missing, empty, or non-numeric disables the level rather than pinning the check to that status.
- Disabling a critical level does **not** disable its warning level. `queuePendingCriticalThreshold => 0` with `queuePendingWarningThreshold => 20` warns at 20 and never escalates.
- If a warning threshold is higher than its matching critical threshold, it is clamped down to the critical value — but only when that critical level is enabled.
- Setting all six to `0` disables queue scoring entirely; the check still reports the counts in `meta`.

The check reads the counts from `craft\queue\Queue`. On a site that has swapped the `queue` component for another `yii\queue` driver those counts aren't available, so the check reports `ok` with an explanatory `meta.error` instead of failing the whole report.

Example:

```php
return [
  '*' => [
    'token' => getenv('PHONEHOME_TOKEN'),
    // Only alert on pending jobs once the queue is clearly backed up
    'queuePendingWarningThreshold' => 100,
    'queuePendingCriticalThreshold' => 250,
    // Disable the delayed-jobs check entirely
    'queueDelayedWarningThreshold' => 0,
    'queueDelayedCriticalThreshold' => 0,
  ],
];
```
