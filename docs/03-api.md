<script setup>
// Access Vite define variables
const apiVersion = __API_VERSION__
</script>


# API Documentation

You can integrate your own monitoring solution that accesses the Phone Home API at `/actions/phonehome/api`. 

## API Versions

The Phone Home API is versioned to ensure compatibility with future changes. The API version in included in an `api_version` key in the response. The current version is `{{ apiVersion }}`.

The API is designed to be stable, but may evolve over time. Always check the version in the response to ensure compatibility with your integration.

The API version uses semantic versioning, which means that:

- **Major version** changes indicate breaking changes that may require updates to your integration.
- **Minor version** changes may introduce new features or improvements without breaking existing functionality.
- **Patch version** changes are for bug fixes and do not affect the API's functionality.

Major/minor/patch versions will always be reflected in the plugin release version. We will commit not to introducing breaking changes outside of major version release.

## Making Requests

- Only `POST` requests are accepted. by the `/actions/phonehome/api` endpoint.
- Only `GET` requests are accepted by the `/actions/phonehome/schema` endpoint.
- Requests must include the correct Bearer token in a `x-auth-token` header.
- Requests must accept JSON responses.
- If the token is missing or invalid, the request will be rejected with a 401 Unauthorized error.

### Request Options

When making a request to the `/actions/phonehome/api` endpoint, you can include the following options in the request body:

- `expandPhpInfo` (boolean): When set to `true`, the response will include detailed PHP information in the `system.php.info` field. This can be useful for debugging or for gathering comprehensive information about the PHP environment. Default is `false`.

## API Schema

A JSON Schema for the API response is available at `/actions/phonehome/schema`. This schema describes the structure of the API response, including the fields and their types. You can use this schema to validate the API response in your integration. To avoid unnecessary leaking information, **requests for the schema still check for a valid token**. You can find a static copy of the schema in the `src/schemas/PhonehomeApi.schema.json` file.


## Sample API Response

 ```json
{
    "api_version": "1.0.0",
    "timestamp": "2025-07-18T09:01:52-04:00",
    "php_version": "8.3.6",
    "craft_version": "5.8.6",
    "craft_edition": "Pro",
    "ip_address": "127.0.0.1",
    "environment": "development",
    "dev_mode": false,
    "composer_lock_updated": "2025-07-17T11:30:53-04:00",
    "system": {
        "php": {
            "name": "PHP",
            "version": "8.8.6",
            "info": null
        },
        "os": {
            "name": "Linux",
            "version": "5.4.0-150-generic"
        },
        "database": {
            "name": "MySQL",
            "version": "8.0.42"
        },
        "image": {
            "name": "Imagick",
            "version": "3.7.0 (ImageMagick 6.9.10-23)"
        },
        "craft": {
            "version": "5.8.6",
            "edition": "Pro",
            "update_status": {
                "total_available_updates": 1,
                "pending_migrations": [],
                "was_craft_breakpoint_skipped": false,
                "is_update_pending": false,
                "is_craft_update_pending": false,
                "is_plugin_update_pending": false,
                "is_critical_update_available": false
            },
            "requirements": [
                {
                    "name": "PHP 8.2+",
                    "mandatory": true,
                    "condition": true,
                    "memo": "PHP 8.2 or later is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "MySQL 8.0.17+",
                    "mandatory": true,
                    "condition": true,
                    "memo": "MySQL 8.0.17 or higher is required to run Craft CMS.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "MySQL InnoDB support",
                    "mandatory": true,
                    "condition": true,
                    "memo": "Craft CMS requires the MySQL InnoDB storage engine to run.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "MySQL timezone support",
                    "mandatory": false,
                    "condition": false,
                    "memo": "MySQL should be configured with <a rel='noopener' target='_blank' href='https://dev.mysql.com/doc/refman/8.0/en/time-zone-support.html'>full timezone support</a>.",
                    "error": false,
                    "warning": true
                },
                {
                    "name": "Sensitive folders should not be publicly accessible",
                    "mandatory": false,
                    "condition": true,
                    "memo": "All of your Craft folders appear to be above your web root.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "BCMath extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.bc.php\">BCMath</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "ctype extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.ctype.php\">ctype</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "cURL extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.curl.php\">cURL</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "DOM extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.dom.php\">DOM</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "Fileinfo extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.fileinfo.php\">Fileinfo</a> extension required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "GD extension or ImageMagick extension",
                    "mandatory": false,
                    "condition": true,
                    "memo": "When using Craft's default image transformer, the <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.image.php\">GD</a> or <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.imagick.php\">ImageMagick</a> extension is required. ImageMagick is recommended as it adds animated GIF support, and preserves 8-bit and 24-bit PNGs during image transforms.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "iconv extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "<a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.iconv.php\">iconv</a> is required for more robust character set conversion support.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "Intl extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.intl.php\">Intl</a> extension (version 1.0.2+) is recommended.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "JSON extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.json.php\">JSON</a> extension is required for JSON encoding and decoding.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "Multibyte String extension (with Function Overloading disabled)",
                    "mandatory": true,
                    "condition": true,
                    "memo": "Craft CMS requires the <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.mbstring.php\">Multibyte String</a> extension with <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/mbstring.overload.php\">Function Overloading</a> disabled in order to run.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "OPcache extension (with save_comments)",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.save-comments\">opcache.save_comments</a> configuration setting must be enabled.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "OpenSSL extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.openssl.php\">OpenSSL</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "PCRE extension (with UTF-8 support)",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.pcre.php\">PCRE</a> extension is required and it must be compiled to support UTF-8.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "PDO extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.pdo.php\">PDO</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "PDO MySQL extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/ref.pdo-mysql.php\">PDO MySQL</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "Reflection extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/class.reflectionextension.php\">Reflection</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "SPL extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.spl.php\">SPL</a> extension is required.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "Zip extension",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/book.zip.php\">zip</a> extension is required for zip and unzip operations.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "ignore_user_abort()",
                    "mandatory": false,
                    "condition": true,
                    "memo": "<a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/function.ignore-user-abort.php\">ignore_user_abort()</a> must be enabled in your PHP configuration for the native web-based queue runner to work.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "password_hash()",
                    "mandatory": true,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/function.password-hash.php\">password_hash()</a> function is required so Craft can create secure passwords.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "proc_close()",
                    "mandatory": false,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/function.proc-close.php\">proc_close()</a> function is required for Plugin Store operations as well as sending emails.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "proc_get_status()",
                    "mandatory": false,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/function.proc-get-status.php\">proc_get_status()</a> function is required for Plugin Store operations as well as sending emails.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "proc_open()",
                    "mandatory": false,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/function.proc-open.php\">proc_open()</a> function is required for Plugin Store operations as well as sending emails.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "proc_terminate()",
                    "mandatory": false,
                    "condition": true,
                    "memo": "The <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/function.proc-terminate.php\">proc_terminate()</a> function is required for Plugin Store operations as well as sending emails.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "allow_url_fopen",
                    "mandatory": false,
                    "condition": "1",
                    "memo": "<a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen\">allow_url_fopen</a> must be enabled in your PHP configuration for Plugin Store and updating operations.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "ini_set calls",
                    "mandatory": true,
                    "condition": true,
                    "memo": "Calls to <a rel=\"noopener\" target=\"_blank\" href=\"https://php.net/manual/en/function.ini-set.php\">ini_set</a> are working correctly.",
                    "error": false,
                    "warning": false
                },
                {
                    "name": "Memory Limit",
                    "mandatory": false,
                    "condition": true,
                    "memo": "Craft requires a minimum PHP memory limit of 256M. The memory_limit directive in php.ini is currently set to 512M.",
                    "error": false,
                    "warning": false
                }
            ],
            "aliases": {
                "@app": "/home/forge/staging.zaengle.com/releases/20250717153057/src/vendor/craftcms/cms/src",
                "@appicons": "/home/forge/staging.zaengle.com/releases/20250717153057/src/vendor/craftcms/cms/src/icons/solid",
                // ... other aliases
            }
        },
        "Yii": "2.0.52",
        "Twig": "v3.15.0",
        "Guzzle": "7.9.3"
    },
    "plugins": {
        "conventions": {
            "name": "Conventions",
            "handle": "conventions",
            "description": "Craft Conventions",
            "version": "5.1.0",
            "is_installed": true,
            "is_enabled": true,
            "is_upgrade_available": false
        },
        "imageguru": {
            "name": "Image Guru",
            "handle": "imageguru",
            "description": "Streamline your Image Transforms",
            "version": "5.1.1",
            "is_installed": true,
            "is_enabled": true,
            "is_upgrade_available": false
        },
        "phonehome": {
            "name": "Phone Home",
            "handle": "phonehome",
            "description": "Phone Home plugin for Craft CMS",
            "version": "dev-develop",
            "is_installed": true,
            "is_enabled": true,
            "is_upgrade_available": false
        },
        "readtime": {
            "name": "Read Time",
            "handle": "readtime",
            "description": "Craft Read Time",
            "version": "1.0.1",
            "is_installed": true,
            "is_enabled": true,
            "is_upgrade_available": false
        },
        "toolbelt": {
            "name": "Toolbelt",
            "handle": "toolbelt",
            "description": "Utility tools for CraftCMS projects",
            "version": "5.0.7",
            "is_installed": true,
            "is_enabled": true,
            "is_upgrade_available": true
        }
    },
    "modules": {
        "my-module": {
            "class": "modules\\Module"
        }
    },
    "updates": [
        {
            "name": "Toolbelt",
            "abandoned": false,
            "status": "available",
            "version": "5.1.0",
            "package": "zaengle/craft-toolbelt",
            "critical": false,
            "release_date": "2025-07-15T10:00:00-04:00"
        }
    ],
    "meta": []
}
```
