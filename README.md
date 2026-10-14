# Outview Licensing

A self-hosted **WooCommerce license-key server** for WordPress plugins. It issues keys when a product is purchased (or manually), validates them over a public REST API with per-domain activation limits, and rate-limits the public endpoints.

Built by [Outview](https://outview.co.za). Current version: **1.8.2**.

> **This repository is a view-only showcase.** The product IDs, key prefixes and product names are generic examples, and the private modules that ship in the production build have been removed. It is published to show how the server is designed, not to be reused. See [LICENSE.md](LICENSE.md).
>
> **Selling WordPress plugins and need licensing?** [Talk to Outview](https://outview.co.za).

## What it does

- **Issues keys on purchase:** hooks WooCommerce order completion, generates a key per licensed product, stores it, and emails it to the customer.
- **Manual issuing:** a "Issue a License Manually" admin screen for your own sites, comped licenses and EFT sales.
- **Validates over REST:** `POST /wp-json/outview-licensing/v1/validate`. The first domain a key is used on is activated; later checks from the same domain always pass.
- **Per-domain activation limits by plan:** Starter 1, Professional 3, Agency 50. Unknown plans fall back to the Starter limit (fail closed).
- **Product cross-check:** a client plugin can send its product slug, and the key must belong to that product. A right key for the wrong product is answered exactly like "not found".
- **Revocation:** revoked licenses fail validation.
- **Agency site list:** `POST /wp-json/outview-licensing/v1/agency/sites` returns every domain on an Agency license, but only to a site already registered on that license.
- **Rate limiting:** per-IP limits on both public endpoints (see below).

## Keys

Format `PREFIX-XXXX-XXXX-XXXX`, with 12 random characters drawn from a 31-character alphabet that omits `0`, `O`, `1`, `I` and `L` to avoid transcription errors. That is roughly 7.9 x 10^17 combinations per prefix, generated with `random_int()`.

## REST API

The endpoints are public by design: the license key is the secret.

### `POST /validate`

| Param | Required | Notes |
|---|---|---|
| `license_key` | yes | |
| `site_url` | yes | the domain is derived from this (lower-cased, `www.` stripped) |
| `product` | no | product slug; if present, the key must belong to it |

Responses are HTTP 200 with JSON such as `{"valid": true, "reason": "ok" | "activated", "plan": "...", "sites_limit": 3, "sites_count": 1}` or `{"valid": false, "reason": "not_found" | "revoked" | "site_limit_reached"}`.

### `POST /agency/sites`

Takes `license_key` and `site_url`. Returns the domain list for an Agency-plan license, or `{"ok": false, "reason": "not_found" | "revoked" | "not_agency" | "domain_not_registered"}`.

## Rate limiting (v1.8.1, extended in v1.8.2)

The endpoints above are unauthenticated, so without a limit anyone could hammer them to guess keys or burn activation slots with a leaked key.

- **Failed lookups:** 10 per hour per IP (unknown key or wrong product).
- **Total requests:** 120 per hour per IP.
- Over the limit, the caller gets **HTTP 429** with a `Retry-After` header. Client plugins that treat any non-200 reply as "server unreachable" fall back to their grace period instead of locking users out.
- Counters are per IP and per endpoint, stored as transients, and expire on their own. No schema change.
- Only `REMOTE_ADDR` is trusted. `X-Forwarded-For` is client-controlled, so honouring it blindly would let an attacker rotate a fake IP and never be limited. Behind a trusted proxy or CDN, return the real client IP from the `ovlic_rate_limit_client_ip` filter.
- Tunable with the filters `ovlic_rate_limit_total`, `ovlic_rate_limit_failures` and `ovlic_rate_limit_window`. Return `0` from a limit filter to disable that counter. Since 1.8.2 the total and failure filters also receive the endpoint name, so you can tune one endpoint without affecting the others.
- **Per-license burst limiter (1.8.2):** `OVLIC_Rate_Limiter::check_license( $endpoint, $license_key )` limits one valid license to 10 requests per 10 minutes (filter `ovlic_rate_limit_license_burst`, `0` disables). It is meant for expensive endpoints, such as ones that make paid third-party API calls: if a monthly allowance is checked before the call and recorded after it, parallel requests can all pass the check while one call is left, and this closes that window. The production build uses it on a private endpoint that is not included in this showcase edition, so nothing in this repo calls it.

The limiter was covered by stubbed behavioural tests during development (11th failure blocked, 121st request blocked, the 11th request for one license within the burst window blocked, other IPs, licenses and endpoints unaffected, limits can be disabled), and the failure limit was confirmed against a live site. It has not been tested against a high-traffic production load.

## Requirements

- WordPress 6.0+, PHP 7.4+
- WooCommerce (the plugin does nothing without it)

## Storage

Two custom tables, `wp_ovlic_licenses` and `wp_ovlic_license_domains`, created and migrated automatically by the plugin.

## Project layout

```
outview-licensing.php                       bootstrap, product constants
includes/class-ovlic-rest-api.php           /validate and /agency/sites
includes/class-ovlic-rate-limiter.php       per-IP rate limiting
includes/class-ovlic-issuer.php             key issuing + email
includes/class-ovlic-license-domains.php    per-domain activations and plan limits
includes/class-ovlic-db-setup.php           tables, key generation, migrations
includes/class-ovlic-admin.php              license list, manual issue screen
includes/class-ovlic-post-type.php          legacy storage (read-only fallback)
```

## Design notes

- Rate limiting by `REMOTE_ADDR` only works correctly if the server sees real client IPs; behind a proxy or CDN, supply the real IP through the `ovlic_rate_limit_client_ip` filter.

## Licence

Copyright (c) 2026 Outview. All rights reserved. Published for viewing and evaluation only; see [LICENSE.md](LICENSE.md).
