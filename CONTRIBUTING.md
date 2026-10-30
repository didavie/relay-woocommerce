# Contributing

Thank you for helping improve Relay Livraison for WooCommerce.

## Before you start

- For a bug, open an [issue](https://github.com/didavie/relay-woocommerce/issues) with the WordPress, WooCommerce and PHP versions, what you expected and what happened. Add the relevant lines of the `relay-delivery` log after removing customer data.
- For a new feature, open an issue first so we can agree on the design.
- Security problems go through [SECURITY.md](SECURITY.md), not public issues.

## Set up

PHP 7.4 or later and Composer are required.

```bash
git clone https://github.com/didavie/relay-woocommerce.git
cd relay-woocommerce
composer install
```

To try changes, link or copy `relay-delivery/` into the `wp-content/plugins/` folder of a local WordPress with WooCommerce, and use a sandbox key from [relaysandbox.didavie.com](https://relaysandbox.didavie.com).

## Checks

Every pull request must pass:

```bash
composer lint     # WordPress coding standards (composer lint:fix to fix)
composer test     # plain PHP unit test
```

The CI also checks PHP syntax on PHP 7.4, 8.1 and 8.3, so do not use syntax newer than PHP 7.4 in `relay-delivery/`.

## Guidelines

- Escape all output, check nonces and capabilities on every admin action, and sanitize every input.
- Never log or print the API key or the webhook secret.
- User-facing strings use the `relay-delivery` text domain. Update `languages/relay-delivery.pot` and the English translations when you add strings.
- Use clear commit messages in the imperative mood, for example `fix: keep the fallback price when the estimate times out`.
- Add a line to `CHANGELOG.md` under an `Unreleased` heading.

By contributing, you agree that your work is released under the [GPL-2.0-or-later](LICENSE) license.
