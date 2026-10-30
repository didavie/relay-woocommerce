=== Relay Livraison pour WooCommerce ===
Contributors: didavie
Tags: delivery, shipping, livraison, local delivery, cash on delivery
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.6
WC tested up to: 10.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Relay delivery for WooCommerce: live Relay price at checkout, delivery created from the order, customer tracking link and order statuses kept up to date.

== Description ==

Relay Livraison connects your WooCommerce store to [Relay](https://relay.didavie.com), the delivery platform by Didavie. Relay drivers pick up your orders at your shop and deliver them to your customers.

* "Livraison Relay" shipping method at checkout, at the Relay price for the customer's address, with a fixed fallback price when Relay cannot be reached.
* Delivery created automatically when the order becomes "Processing", or with a button on the order screen.
* Cash on delivery: the driver collects the order total.
* Relay tracking link added to the customer e-mail.
* Order statuses updated by signed webhooks (delivered: the order is completed).
* "Livraison Relay" box on the order: tracking code, status, driver, fee, amount to collect, tracking link, free cancellation before pickup.
* Test mode: deliveries are checked and priced without being created.
* Works with High-Performance Order Storage (HPOS) and the cart and checkout blocks.

You need a shop approved on Relay and its API key (Relay Partners > Shop > Connect my website). To try the plugin first, use a free sandbox key from [relaysandbox.didavie.com](https://relaysandbox.didavie.com).

The plugin interface is in French, with English (en_US, en_GB) translations included.

= Data sent to Relay =

To deliver an order, the plugin sends Relay the customer's name, phone number, e-mail address and delivery address, the list of items, the order total and the customer note. Relay uses them only to carry out and track the delivery. API calls go to `relaypartner.didavie.com` (or `relaysandbox.didavie.com` with a sandbox key).

= Français =

Relay Livraison relie votre boutique WooCommerce à Relay : prix Relay en direct au paiement (prix fixe de secours si Relay ne répond pas), livraison créée automatiquement quand la commande passe « En cours » ou avec un bouton, paiement à la livraison, lien de suivi dans l'e-mail du client, statuts de commande mis à jour par des webhooks signés. Il vous faut une boutique approuvée sur Relay et sa clé API (Relay Partenaires > Boutique > Connecter mon site), ou une clé sandbox gratuite pour essayer.

== Installation ==

1. Upload the `relay-delivery` folder to `wp-content/plugins/` (or upload the zip file in Plugins > Add New) and activate it.
2. Go to WooCommerce > Settings > Shipping > Relay, paste your shop API key, click "Tester la connexion", then save.
3. Add "Livraison Relay" to your shipping zones (WooCommerce > Settings > Shipping).
4. In Relay Partners, set the webhook URL shown on the settings page, then paste the signing secret (`whsec_...`) in the plugin settings.

== Frequently Asked Questions ==

= Can I try it without a Relay account? =

Yes. Create a sandbox key (`rb_sbx_...`) on relaysandbox.didavie.com and paste it in the settings. Keep the default API address: the plugin sends sandbox keys to the sandbox on its own. Deliveries are fake (no driver, no payment) and move through the statuses on their own.

= What happens at checkout if Relay is down? =

The fixed fallback price is offered. The delivery can be created later with the button on the order.

= Can one order create two deliveries? =

No. Each order sends a unique id to Relay: sending it again returns the same delivery.

= Where are the logs? =

WooCommerce > Status > Logs, source `relay-delivery`. The API key and the webhook secret are never written to the logs.

== Changelog ==

= 1.0.0 =
* First release.
