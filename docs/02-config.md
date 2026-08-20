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
| `queuePendingWarningThreshold` | `100` | Pending (waiting) jobs that trigger a `WARNING` status |
| `queuePendingCriticalThreshold` | `250` | Pending (waiting) jobs that trigger a `CRITICAL` status |

"Pending" is the label Craft's control panel uses for waiting jobs; the count is reported as `meta.waiting` in the API response.

Notes:

- Each threshold may be set to an integer or to an env var name (e.g. `'$PHONEHOME_QUEUE_PENDING_CRITICAL'`), the same as `token`.
- Setting a threshold to `0` disables that level. This also means an env var that is missing or empty disables the level rather than pinning the check to that status.
- If a warning threshold is higher than its matching critical threshold, it is clamped down to the critical value.

Example:

```php
return [
  '*' => [
    'token' => getenv('PHONEHOME_TOKEN'),
    // Only alert on pending jobs once the queue is clearly backed up
    'queuePendingWarningThreshold' => 250,
    'queuePendingCriticalThreshold' => 500,
    // Disable the delayed-jobs check entirely
    'queueDelayedWarningThreshold' => 0,
    'queueDelayedCriticalThreshold' => 0,
  ],
];
```
