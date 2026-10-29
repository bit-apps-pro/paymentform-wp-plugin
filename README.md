# paymentform-wp-plugin

## Development

Dev tooling is opt-in per site. Add to `wp-config.php`:

```php
define('PAYMENTFORM_DEV', true);
```

With it set, the plugin honours `frontend/.env` (`APP_API_BASE_URL`, `APP_APP_BASE_URL`) and serves the admin SPA from the Vite dev server while `pnpm dev` is running. Without it, both are ignored and the plugin always uses the production hosts and the built `assets/`.

## Release

`bin/package.sh [output.zip]` builds the WordPress.org zip: built assets, PHP, production `vendor/` and third-party licenses. The readable frontend source stays in this repo, which readme.txt links for WordPress.org review.
