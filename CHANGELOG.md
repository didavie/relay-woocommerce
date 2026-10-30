# Changelog

All notable changes to this plugin are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin uses [Semantic Versioning](https://semver.org/). The WordPress changelog is also kept in `relay-delivery/readme.txt`.

## [1.0.0] - 2026-10-29

### Added

- **Livraison Relay** shipping method with the live Relay price for the customer's address and a fixed fallback price.
- Delivery created when the order becomes *Processing*, or with a button on the order.
- Cash on delivery, tracking link in the customer e-mail, order box with tracking, refresh and free cancellation before pickup.
- Signed webhook receiver (`/wp-json/relay-delivery/v1/webhook`) that updates order statuses.
- Test mode, sandbox keys (`rb_sbx_`) routed to `relaysandbox.didavie.com`, HPOS and checkout blocks compatibility.
- English (en_US, en_GB) translations.

[1.0.0]: https://github.com/didavie/relay-woocommerce/releases/tag/v1.0.0
