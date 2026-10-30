# Security policy

## Supported versions

Security fixes are made on the latest release of Relay Livraison for WooCommerce. Please update before reporting.

| Version | Supported |
| --- | --- |
| 1.0.0 | Yes |

## Reporting a vulnerability

Please do not open a public issue for a security problem.

- Use GitHub private vulnerability reporting: **Security > Report a vulnerability** on [didavie/relay-woocommerce](https://github.com/didavie/relay-woocommerce/security/advisories/new), or
- e-mail **relay@didavie.com** with the subject `Security: relay-woocommerce`.

Include what you found, the steps to reproduce it, the version you tested and the impact you expect. Never include a real API key, webhook secret or customer data: use sandbox keys from [relaysandbox.didavie.com](https://relaysandbox.didavie.com).

We acknowledge reports within 3 business days and keep you informed until the fix is released. We credit reporters who wish to be named once the fix is public.

## Scope

This policy covers the code in this repository. Problems with the Relay API itself (`relaypartner.didavie.com`, `relaysandbox.didavie.com`) can be reported the same way.

## If a key leaks

If you published a Relay API key or webhook secret by mistake, revoke it right away in Relay Partners (Shop > Connect my website, or the agency Developers page) and create a new one. Revoked keys stop working at once.
