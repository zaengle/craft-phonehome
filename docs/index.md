<script setup>
// Access Vite define variables
const pluginVersion = __PLUGIN_VERSION__
</script>

# Phonehome {{ pluginVersion }} for Craft CMS

> Monitor the status of your #CraftCMS projects remotely

## What

Phonehome provides a secure API endpoint that returns comprehensive information about a Craft CMS installation and the server environment it is installed in.

## Why?

If like us, you manage a large number of production Craft CMS installations, you know how important it is to keep track of their health and status. Phonehome allows you to monitor your Craft sites from a single dashboard (not included with this plugin), providing insights into their performance, uptime, and configuration.

## How it works

Usage of this plugin requires an external service to monitor your Craft sites and view their status. Use the plugin to create your own custom dashboard or integrate it with your existing monitoring tools. The schema of the API response is documented in the [API documentation](./03-api.md) and also provided in the `src/schemas/PhonehomeApi.schema.json` file.

## Next steps

1. [Installation](./01-installation)
2. [Configuration](./02-config)
3. [API Documentation](./03-api.md)
