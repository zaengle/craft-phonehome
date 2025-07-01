# Phone Home Plugin for Craft CMS

## Requirements

This plugin requires Craft CMS 4.0.0 or later, or Craft CMS 5.0.0 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "Phone Home". Then press "Install".

### With Composer

Open your terminal and run the following commands:

```bash
composer require zaengle/craft-phonehome
php craft plugin/install phonehome
```

## Overview

Phone Home provides a secure API endpoint that returns comprehensive information about your Craft CMS installation. This is useful for monitoring multiple Craft sites, gathering analytics, or performing system audits.

## Configuration

### Required Environment Variables

Add these to your `.env` file:

```env
# Required: Secure token for API authentication
PHONE_HOME_TOKEN=your-secure-token-here

# Optional: Custom metadata keys to include in response
PHONE_HOME_CUSTOM_KEYS=SITE_NAME,SITE_URL,CRAFT_ENVIRONMENT
```

### How PHONE_HOME_CUSTOM_KEYS Works

- `PHONE_HOME_CUSTOM_KEYS` is a comma-separated list of environment variable names.
- For each key listed, the plugin will look up the corresponding environment variable and include its value in the `meta` section of the API response.
- This allows you to send custom site-specific or environment-specific data to the Phone Home service.

For example, if you set:

```env
PHONE_HOME_CUSTOM_KEYS=SITE_NAME,SITE_URL,CRAFT_ENVIRONMENT
SITE_NAME=My Craft Site
SITE_URL=https://example.com
CRAFT_ENVIRONMENT=production
```

The API response will include:

```json
{
  // ...other data...
  "meta": {
    "SITE_NAME": "My Craft Site",
    "SITE_URL": "https://example.com",
    "CRAFT_ENVIRONMENT": "production"
  }
}
```

You can add any environment variable you want to this list, and the plugin will include its value if it exists.

## Usage

1. Register your site with the [Zaengle Phone Home service](https://phone-home.zaengle.com) to obtain your authentication token.
2. Add the `PHONE_HOME_TOKEN` (and any custom keys) to your `.env` file as shown above.
3. The plugin exposes a POST endpoint at `/phone-home`.
4. The Zaengle Phone Home service will make authenticated POST requests to this endpoint, including the token in the `x-auth-token` header:

   ```http
   POST /phone-home
   x-auth-token: your-secure-token-here
   ```

5. The endpoint will return a JSON response with system, plugin, module, and environment information.

   #### Example Response (Craft 4 & Craft 5)

   ```json
   {
     "php_version": "8.2.0",
     "craft_version": "5.1.2",
     "craft_edition": "Pro",
     "ip_address": "203.0.113.10",
     "environment": "production",
     "dev_mode": false,
     "timestamp": "2025-07-01T12:00:00+00:00",
     "composer_lock_updated": "2025-06-30T18:00:00+00:00",
     "system": {
       "php": { "name": "PHP", "version": "8.2.0" },
       "os": { "name": "Darwin", "version": "22.5.0" },
       "database": { "name": "PostgreSQL", "version": "15.3" },
       "image": { "name": "Imagick", "version": "7.1.0" },
       "yii": { "name": "Yii", "version": "3.0.0" },
       "twig": { "name": "Twig", "version": "3.7.0" },
       "guzzle": { "name": "Guzzle", "version": "7.8.0" }
     },
     "plugins": {
       "phonehome": {
         "name": "Phone Home",
         "description": "Phone Home plugin for Craft CMS",
         "version": "1.0.0",
         "is_installed": true,
         "is_enabled": true
       }
       // ...other plugins...
     },
     "modules": {},
     "updates": {
       "craft": false,
       "plugins": []
     },
     "meta": {
       "SITE_NAME": "My Craft Site",
       "SITE_URL": "https://example.com",
       "CRAFT_ENVIRONMENT": "production"
     }
   }
   ```

## Security

- Only POST requests are accepted.
- Requests must include the correct Bearer token in the `Authorization` header or the `x-auth-token` header.
- If the token is missing or invalid, the request will be rejected with a 401 Unauthorized error.

## Support

For documentation and support, visit the [GitHub repository](https://github.com/zaengle/craft-phonehome).
