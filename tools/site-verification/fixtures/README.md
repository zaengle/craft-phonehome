# Pilot fixture site

Deterministic pages for exercising the runner against a real Craft install. There are no dates, no
randomness, no third-party embeds and no lazy-loaded images, so any difference between two runs is
something the QA pass deliberately introduced.

Copy `templates/poc/` into the site's `templates/` directory and `web/poc.css` into its `web/`
directory. The pages are then at `/poc/home`, `/poc/about` and `/poc/contact`.

The matching plugin config is in the QA instructions.
