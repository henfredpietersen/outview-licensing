<?php
/**
 * Plugin Name:       Outview Licensing
 * Description:       A self-hosted WooCommerce license-key server: issues keys on purchase (or manually), validates them over a public REST API with per-domain activation limits, and rate-limits the public endpoints.
 * Version:           1.8.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Outview
 * License:           All rights reserved (view-only showcase, see LICENSE.md)
 * Text Domain:       ov-licensing
 *
 * SHOWCASE EDITION: product IDs, key prefixes and product names below are
 * generic examples, and private modules that ship in the
 * production build have been removed. This copy is published for viewing
 * only and is not intended to be installed or reused.
 *
 * CHANGELOG (abridged)
 * 1.8.2 — Rate limiter: limit filters now receive the endpoint name so one
 *         endpoint can be tuned without affecting the others, and a new
 *         per-license burst limiter (OVLIC_Rate_Limiter::check_license,
 *         10 requests per 10 minutes by default) for expensive endpoints.
 *         The production build uses it on a private endpoint that is not
 *         part of this showcase edition. /validate and /agency/sites
 *         behave exactly as in 1.8.1.
 * 1.8.1 —Per-IP rate limiting on the public /validate and /agency/sites
 *         endpoints (includes/class-ovlic-rate-limiter.php): 120 requests/
 *         hour and 10 failed key lookups/hour per IP, then HTTP 429 with a
 *         Retry-After header. Client plugins treat non-200 as "unreachable"
 *         and fall back to their grace period. Uses REMOTE_ADDR only;
 *         override via the ovlic_rate_limit_client_ip filter if behind a
 *         trusted proxy. No schema change.
 * 1.8.0 — Professional plan's site-activation cap raised from 2 to 3.
 * 1.7.0 — /agency/sites endpoint: lists every domain activated on an
 *         Agency-plan license (caller must already be a registered site).
 * 1.6.0 — Plan resolved from a "License Plan" variation attribute at
 *         purchase time (starter / professional / agency).
 * 1.5.0 — Multi-site enforcement via a wp_ovlic_license_domains table
 *         (replaces the single bound_domain column as source of truth).
 * 1.4.0 — Per-license "plan" column.
 * 1.2.0 — Licenses moved from a custom post type to a dedicated
 *         wp_ovlic_licenses table (automatic, non-destructive migration).
 * 1.1.0 — Optional "product" cross-check on /validate: if a caller sends a
 *         product slug, the key must belong to that product.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Must track the plugin Version header above. maybe_upgrade() in
// class-ovlic-db-setup.php gates its dbDelta() run on this constant vs a
// stored option, so it must be bumped on every release that changes the
// schema or the migration will be skipped.
define( 'OVLIC_VERSION', '1.8.2' );
define( 'OVLIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// The WooCommerce product ID(s) that, when purchased, should issue a license.
// Example IDs: replace with your own products.
//   1001 = Example Plugin A, 1002 = Example Plugin B (tiered),
//   1003 = Example Plugin C (annual), 1004 = Example Plugin C (monthly).
define( 'OVLIC_LICENSED_PRODUCT_IDS', array( 1001, 1002, 1003, 1004 ) );

// Of the licensed products above, which ones have a Starter/Professional/
// Agency distinction that the client plugin actually checks. Every license
// still stores a 'plan' column (default 'starter'); this constant is only
// consulted so the issuance email doesn't state a tier for a product that
// has none.
define( 'OVLIC_TIERED_PRODUCT_IDS', array( 1002 ) );

/**
 * Product ID -> the "product" slug a client plugin's check-in may send.
 * Only consulted by the /validate endpoint when the caller actually sends
 * a "product" param — see class-ovlic-rest-api.php.
 */
define(
	'OVLIC_PRODUCT_SLUGS',
	array(
		1001 => 'product-a',
		1002 => 'product-b',
		1003 => 'product-c',
		1004 => 'product-c',
	)
);

function ovlic_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-post-type.php';
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-db-setup.php';
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-license-domains.php';
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-issuer.php';
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-rate-limiter.php';
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-rest-api.php';
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-admin.php';

	// Kept registered (but no longer read from) so historical records
	// stay browsable in wp-admin as a fallback/audit trail.
	OVLIC_Post_Type::instance();

	// Runs after 'init' so the CPT above is registered first; the actual
	// upgrade only does anything on the first request after this version
	// is deployed — every request after that is a single option lookup.
	add_action( 'init', array( 'OVLIC_DB_Setup', 'maybe_upgrade' ), 20 );

	OVLIC_Issuer::instance();
	OVLIC_REST_API::instance();
	OVLIC_Admin::instance();
}
add_action( 'plugins_loaded', 'ovlic_boot', 20 );

function ovlic_activate() {
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-post-type.php';
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-db-setup.php';
	require_once OVLIC_PLUGIN_DIR . 'includes/class-ovlic-license-domains.php';

	OVLIC_Post_Type::instance()->register_post_type();
	OVLIC_DB_Setup::maybe_upgrade();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ovlic_activate' );

function ovlic_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ovlic_deactivate' );
