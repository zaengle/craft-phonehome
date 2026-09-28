# DDEV integration for the pilot site

Copy these into the pilot site's `.ddev/` directory:

```bash
cp ddev/docker-compose.playwright.yaml <pilot-site>/.ddev/
mkdir -p <pilot-site>/.ddev/commands/host
cp ddev/commands/host/verify <pilot-site>/.ddev/commands/host/
chmod +x <pilot-site>/.ddev/commands/host/verify
ddev restart
```

The compose file expects the plugin repository to be mounted at
`/var/www/html/plugins/craft-phonehome`, which is the same mount the plugin itself is installed
from. Adjust the host path if your checkout lives elsewhere.

Then `ddev verify setup`, `ddev verify capture <run-id>` and `ddev verify compare <run-id>`.
