# Relay Livraison for WooCommerce

WooCommerce plugin for [Relay](https://relay.didavie.com) deliveries, by Didavie.

Show the live Relay delivery price at checkout, create the delivery from the order, send the customer a tracking link and keep the order status up to date.

[Version française](README.fr.md)

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Install](#install)
- [Configure](#configure)
- [Try it with the sandbox](#try-it-with-the-sandbox)
- [How it works](#how-it-works)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Support](#support)
- [License](#license)

## Features

- **Livraison Relay** shipping method at checkout, at the Relay price for the customer's address. A fixed fallback price is offered when Relay cannot be reached, so checkout never blocks.
- Delivery created automatically when the order becomes *Processing*, or by hand with a button on the order.
- Cash on delivery: the driver collects the order total for *Cash on delivery* orders.
- Relay tracking link added to the customer e-mail.
- Order statuses updated by signed webhooks: delivered orders become *Completed*.
- **Livraison Relay** box on each order: tracking code, status, driver, fee, amount to collect, tracking link, free cancellation until pickup, refresh.
- Test mode: deliveries are checked and priced without being created.
- Works with High-Performance Order Storage (HPOS) and the cart and checkout blocks.
- French interface, English (en_US, en_GB) translations included.

## Requirements

| | Minimum |
| --- | --- |
| WordPress | 6.2 |
| WooCommerce | 7.6 (tested up to 10.3) |
| PHP | 7.4 |
| Relay | A shop approved on Relay and its API key, or a sandbox key |

## Install

1. Get `relay-delivery-<version>.zip`: download it from the [releases](https://github.com/didavie/relay-woocommerce/releases) page, or build it from a clone with `./bin/build-zip.sh`.
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, choose the zip file, install and activate.

You can also copy the `relay-delivery` folder into `wp-content/plugins/`.

## Configure

1. **Get an API key.** In Relay Partners, open **Shop > Connect my website** and create a key (`rb_live_...`). It is shown once: copy it.
2. **Connect.** In WordPress, go to **WooCommerce > Settings > Shipping > Relay**, paste the key, click **Tester la connexion**, then save. The badge shows the connected shop.
3. **Add the shipping method.** In **WooCommerce > Settings > Shipping**, add **Livraison Relay** to each shipping zone where Relay delivers.
4. **Webhooks.** Copy the webhook URL shown on the settings page (`https://your-site.example/wp-json/relay-delivery/v1/webhook`) into the Connect my website page of Relay Partners, generate the signing secret there, and paste it (`whsec_...`) in the plugin settings.
5. **Go live.** Turn **Mode test** off when you are ready to send real deliveries.

| Setting | Default | What it does |
| --- | --- | --- |
| Clé API de la boutique | empty | Shop key (`rb_live_`, or `rb_sbx_` from the sandbox). Never shown again after saving. |
| Mode test | on | Deliveries are checked and priced, nothing is created and no driver is called. |
| Création de la livraison | automatic | Automatic when the order becomes *Processing*, or manual with the order button. |
| Paiement à la livraison | on | The driver collects the order total for cash on delivery orders. |
| Type de colis par défaut | standard | Package type sent to Relay. |
| Prix au paiement | live, fallback 1500 | Live Relay price for the customer's address; fixed price when Relay does not answer. |
| Qui paie la livraison | customer | Customer at checkout, shop (free delivery) or customer paying the driver on delivery. |
| E-mail au client | on | Adds the tracking link to the order e-mail. |
| Secret du webhook | empty | `whsec_...` secret used to check the webhook signature. |
| Adresse de l'API | `https://relaypartner.didavie.com/api/v1` | Change only if Relay asks you to. |

## Try it with the sandbox

You can try the whole flow without a Relay account.

1. Create a free account on [relaysandbox.didavie.com](https://relaysandbox.didavie.com) and create a shop key (`rb_sbx_...`).
2. Paste it in **WooCommerce > Settings > Shipping > Relay**. Keep the default API address: sandbox keys always go to `https://relaysandbox.didavie.com/api/v1`.
3. Place an order with **Livraison Relay**. The delivery is fake (no driver, no payment) and moves through the statuses on its own. The sandbox simulator can force any status and send the matching webhook.

Sandbox limits: 60 requests per minute per key, 50 deliveries a day, 30 open at once. Sandbox accounts are deleted after 30 days.

## How it works

| Step | API call |
| --- | --- |
| Checkout price | `GET /api/v1/pricing/estimate?delivery_address=...` (cached 10 minutes per address) |
| Create the delivery | `POST /api/v1/packages` with the `quote_id` from checkout and a unique `platform_order_id` |
| Order box refresh | `GET /api/v1/packages/{tracking_code}` |
| Cancel | `PATCH /api/v1/packages/{tracking_code}/cancel` (free until pickup) |
| Status updates | Relay calls `POST /wp-json/relay-delivery/v1/webhook`, signed with `X-Relay-Signature` |

Webhook events are checked (HMAC-SHA256, 5-minute tolerance), deduplicated on their `id`, and applied only to the order that owns the package. *Delivered* completes the order; a problem or return puts it *On hold*; pickup and cancellation add an order note.

**Data sent to Relay.** To deliver an order, the plugin sends the customer's name, phone number, e-mail address and delivery address, the list of items, the order total and the customer note. The API key and webhook secret are stored in the WordPress options table, never printed back in full and never written to logs. Uninstalling the plugin deletes its settings; orders keep their Relay notes.

The API itself is documented in [relay-api](https://github.com/didavie/relay-api).

## Troubleshooting

| Symptom | What to do |
| --- | --- |
| "Tester la connexion" says the key is invalid | The key is unknown or revoked. Create a new one in Relay Partners. A sandbox key must come from relaysandbox.didavie.com. |
| Relay is not offered at checkout | Add **Livraison Relay** to the customer's shipping zone. If the address is outside the area Relay serves, the method is hidden on purpose. |
| The fallback price is always shown | Check that **Prix Relay en direct** is on and that the key is saved. Look at the log for the last estimate call. |
| The order status never changes | Check the webhook URL in Relay Partners and the `whsec_` secret in the plugin. Refused webhooks are logged as `webhook refused: bad or missing signature`. Security plugins or a firewall must let `POST /wp-json/relay-delivery/v1/webhook` through. |
| `QUOTE_EXPIRED` on the order | The checkout price is older than about 15 minutes. The plugin retries once at the current price. |
| `DAILY_LIMIT` or `PENDING_LIMIT` | A Relay shop limit was reached. Create the delivery later with the order button. |

Logs: **WooCommerce > Status > Logs**, source `relay-delivery`. The settings page also shows the last API call and its result.

## Development

```bash
composer install          # PHP_CodeSniffer with the WordPress standards
composer lint             # phpcs
composer lint:fix         # phpcbf
composer test             # plain PHP unit test, no WordPress needed
./bin/build-zip.sh        # build/relay-delivery-<version>.zip
```

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Support

- API reference: [relaypartner.didavie.com/en/developers](https://relaypartner.didavie.com/en/developers)
- Bugs and feature requests: [GitHub issues](https://github.com/didavie/relay-woocommerce/issues)
- E-mail: relay@didavie.com
- Security issues: see [SECURITY.md](SECURITY.md)

## License

[GPL-2.0-or-later](LICENSE), like WordPress and WooCommerce. Relay is a product of Didavie.
