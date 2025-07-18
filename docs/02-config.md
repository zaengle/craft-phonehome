# Configuring the plugin

Phonehome exposes a single controller endpoint at `/actions/phonehome/api` with information about your Craft installation and the server environment it is installed in. 

The plugin can be configured either via it's settings page in the Craft control panel or via its config file, a starter version of which will be automatically created at `config/phonehome.php` as part of the plugin's install command.

## Getting started

The config file supports the following keys:

- `token` - a shared, secret, token that will be used to authenticate requests to the Phone Home API. If you are using the [phonehome.zaengle.com](https://phone-home.zaengle.com/) service, this token should match the token provided in your account settings there. If not, this should be set to a secure, random string.
- `additionalEnvKeys` - an array of environment keys to include in the API response. These keys will be added to the `meta` section of the response, allowing you to send custom site-specific or environment-specific data to the Phone Home service.

Example:

```php
return [
  '*' => [
    // Available transformers that should be registered with Craft
    'token' => getenv('PHONEHOME_TOKEN'),
    'additionalEnvKeys' => 'MY_CUSTOM_KEY', 'ANOTHER_KEY'],
  ],
];
```
