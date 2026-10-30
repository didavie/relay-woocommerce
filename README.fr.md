# Relay Livraison pour WooCommerce

Extension WooCommerce pour les livraisons [Relay](https://relay.didavie.com), par Didavie.

Affichez le prix de livraison Relay en direct au paiement, créez la livraison depuis la commande, envoyez un lien de suivi au client et gardez le statut de la commande à jour.

[English version](README.md)

## Fonctions

- Méthode de livraison **Livraison Relay** au paiement, au prix Relay calculé pour l'adresse du client, avec un prix fixe de secours si Relay ne répond pas.
- Livraison créée automatiquement quand la commande passe « En cours », ou avec un bouton dans la commande.
- Paiement à la livraison : le livreur encaisse le total des commandes « Paiement à la livraison ».
- Lien de suivi Relay ajouté à l'e-mail du client.
- Statuts de commande mis à jour par des webhooks signés (livré : commande « Terminée »).
- Boîte **Livraison Relay** dans chaque commande : code de suivi, statut, livreur, frais, montant à encaisser, suivi, annulation gratuite avant la récupération.
- Mode test : les livraisons sont vérifiées et chiffrées sans être créées.
- Compatible HPOS et blocs panier / paiement.

## Prérequis

WordPress 6.2, WooCommerce 7.6, PHP 7.4, et une boutique approuvée sur Relay avec sa clé API (ou une clé sandbox).

## Installation

1. Récupérez `relay-delivery-<version>.zip` (page des versions du dépôt, ou `./bin/build-zip.sh` depuis un clone).
2. WordPress > **Extensions > Ajouter > Téléverser une extension**, choisissez le zip, installez et activez.

## Réglages

1. Dans Relay Partenaires, **Boutique > Connecter mon site** : créez une clé (`rb_live_...`), affichée une seule fois.
2. WordPress > **WooCommerce > Réglages > Livraison > Relay** : collez la clé, cliquez **Tester la connexion**, enregistrez.
3. **WooCommerce > Réglages > Livraison** : ajoutez **Livraison Relay** à vos zones de livraison.
4. Copiez l'adresse du webhook affichée sur la page de réglages dans Relay Partenaires, générez le secret de signature et collez-le (`whsec_...`) dans les réglages de l'extension.
5. Coupez le **Mode test** quand vous êtes prêt à envoyer de vraies livraisons.

## Tester avec la sandbox

1. Créez un compte gratuit sur [relaysandbox.didavie.com](https://relaysandbox.didavie.com) et une clé boutique (`rb_sbx_...`).
2. Collez-la dans les réglages. Gardez l'adresse de l'API par défaut : les clés sandbox partent seules vers la sandbox.
3. Passez une commande avec **Livraison Relay** : la livraison est fictive (aucun livreur, aucun paiement) et avance seule. Le simulateur de la sandbox force n'importe quel statut.

Limites de la sandbox : 60 requêtes par minute et par clé, 50 livraisons par jour, 30 ouvertes en même temps, comptes supprimés après 30 jours.

## Données envoyées à Relay

Pour livrer une commande : nom, téléphone, e-mail et adresse de livraison du client, liste des articles, total de la commande et note du client. La clé API et le secret du webhook ne sont jamais réaffichés en entier ni écrits dans le journal.

## Problèmes fréquents

| Symptôme | Que faire |
| --- | --- |
| Clé refusée au test de connexion | Clé inconnue ou révoquée : créez-en une nouvelle. Une clé sandbox doit venir de relaysandbox.didavie.com. |
| Relay n'apparaît pas au paiement | Ajoutez **Livraison Relay** à la zone de livraison du client. Hors de la zone desservie, la méthode est masquée. |
| Le statut de la commande ne change pas | Vérifiez l'adresse du webhook dans Relay Partenaires et le secret `whsec_`. Un pare-feu ou une extension de sécurité doit laisser passer `POST /wp-json/relay-delivery/v1/webhook`. |
| `DAILY_LIMIT` ou `PENDING_LIMIT` | Limite de la boutique atteinte : créez la livraison plus tard avec le bouton de la commande. |

Journal : **WooCommerce > État > Journaux**, source `relay-delivery`.

## Assistance

- Référence API : [relaypartner.didavie.com/fr/developers](https://relaypartner.didavie.com/fr/developers)
- Problèmes et demandes : [GitHub issues](https://github.com/didavie/relay-woocommerce/issues)
- E-mail : relay@didavie.com
- Failles de sécurité : voir [SECURITY.md](SECURITY.md)

## Licence

[GPL-2.0-or-later](LICENSE). Relay est un produit de Didavie.
