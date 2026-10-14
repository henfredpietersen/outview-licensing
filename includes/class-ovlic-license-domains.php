<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-site enforcement (Agency "50 sites" and friends).
 *
 * Before this, a license could only ever be bound to ONE domain — a
 * single `bound_domain` VARCHAR column on wp_ovlic_licenses, first-write-
 * wins. That was fine for Starter (which is only ever entitled to 1 site
 * anyway) but meant Agency's advertised "up to 50 sites per license"
 * never actually worked: a second site entering the same key just got
 * `domain_mismatch` and failed, identical to Starter. This class is the
 * new source of truth for "which domains has this license activated on,
 * and how many more can it take" — backed by a proper one-to-many table
 * (wp_ovlic_license_domains) instead of the single column.
 *
 * `bound_domain` on the licenses table is kept (dbDelta only adds columns,
 * it never drops them) and is still written with the FIRST domain a
 * license activates on, purely so anything that reads it directly off the
 * row (e.g. the admin list's search) still shows something sane. It is no
 * longer read for validation decisions — this class is.
 */
class OVLIC_License_Domains {

	/**
	 * Plan => number of distinct domains a license on that plan may
	 * activate. Mirrors the sites.limit_1 / sites.limit_3 / sites.limit_50
	 * capability keys in the client plugin's capability matrix — kept here
	 * too since the server can't
	 * (and shouldn't) depend on a client plugin's PHP classes, and this is
	 * the actual enforcement point regardless of which client is asking.
	 *
	 * Professional raised 2 -> 3 in 1.8.0. Only product 1002 (Example Plugin B)
	 * ever issues a 'professional'-plan
	 * license in the normal WooCommerce purchase flow — see
	 * OVLIC_TIERED_PRODUCT_IDS and Issuer::resolve_plan_from_item(), which
	 * falls every other product (Example Plugin C, Example Plugin A) back to
	 * 'starter' since they have no "License Plan" variation attribute — so
	 * this change is scoped to Example Plugin B in practice even though the
	 * constant itself is shared server-side infrastructure.
	 */
	const PLAN_LIMITS = array(
		'starter'      => 1,
		'professional' => 3,
		'agency'       => 50,
	);

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ovlic_license_domains';
	}

	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table            = self::table();

		$sql = "CREATE TABLE {$table} (
			domain_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			license_id BIGINT UNSIGNED NOT NULL,
			domain VARCHAR(190) NOT NULL DEFAULT '',
			activated_at DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00',
			PRIMARY KEY  (domain_id),
			UNIQUE KEY license_domain (license_id, domain),
			KEY license_id (license_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * One-time, safe-to-repeat: copies each license's existing single
	 * bound_domain (from before this table existed) into a row here, so a
	 * site that was already activated doesn't get treated as "new" and
	 * silently eat a slot, or worse, get rejected if the migration runs
	 * after the limit was already reached by legitimate re-activations.
	 * Skipped per-license once a domains row already exists for it, so
	 * running this more than once is harmless.
	 */
	public static function migrate_from_bound_domain_column() {
		global $wpdb;
		$licenses_table = OVLIC_DB_Setup::table_licenses();
		$domains_table  = self::table();

		$rows = $wpdb->get_results( "SELECT license_id, bound_domain, activated_at FROM {$licenses_table} WHERE bound_domain != ''" );

		$migrated = 0;

		foreach ( $rows as $row ) {
			$already = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$domains_table} WHERE license_id = %d", $row->license_id )
			);

			if ( $already ) {
				continue;
			}

			$wpdb->insert(
				$domains_table,
				array(
					'license_id'   => (int) $row->license_id,
					'domain'       => $row->bound_domain,
					'activated_at' => $row->activated_at ? $row->activated_at : current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s' )
			);

			++$migrated;
		}

		return $migrated;
	}

	/** @return string[] */
	public static function get_domains( $license_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_col(
			$wpdb->prepare( "SELECT domain FROM {$table} WHERE license_id = %d ORDER BY activated_at ASC", $license_id )
		);
	}

	/**
	 * Same as get_domains() but with activation timestamps — used by the
	 * Agency dashboard (client plugin, via the /agency/sites REST route)
	 * to show when each site joined the license, not just which sites
	 * exist. get_domains() stays as-is (domain strings only) since its
	 * other caller, the admin "Manage Sites" screen, builds its own row
	 * markup straight from $wpdb results rather than this method.
	 *
	 * @return array[] Each: ['domain' => string, 'activated_at' => string]
	 */
	public static function get_domains_with_dates( $license_id ) {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT domain, activated_at FROM {$table} WHERE license_id = %d ORDER BY activated_at ASC", $license_id ),
			ARRAY_A
		);

		return $rows ?: array();
	}

	public static function count( $license_id ) {
		global $wpdb;
		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_id = %d", $license_id )
		);
	}

	public static function has_domain( $license_id, $domain ) {
		global $wpdb;
		$table = self::table();

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_id = %d AND domain = %s", $license_id, $domain )
		);
	}

	/**
	 * Registers a new domain for a license. Caller is responsible for
	 * checking limit_for_plan()/count() first — this does not itself
	 * enforce the cap, since the REST endpoint needs to distinguish
	 * "already registered, just let it through" from "new registration,
	 * check the cap" before calling this.
	 */
	/**
	 * Note: does not touch the legacy `bound_domain` column — see class
	 * docblock. Callers that care about keeping it in sync (the REST
	 * endpoint, for its "first domain" display value) do that themselves.
	 */
	public static function add_domain( $license_id, $domain ) {
		global $wpdb;
		$table = self::table();

		return false !== $wpdb->insert(
			$table,
			array(
				'license_id'   => (int) $license_id,
				'domain'       => $domain,
				'activated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	public static function remove_domain( $license_id, $domain ) {
		global $wpdb;
		$table = self::table();

		return false !== $wpdb->delete(
			$table,
			array( 'license_id' => (int) $license_id, 'domain' => $domain ),
			array( '%d', '%s' )
		);
	}

	public static function remove_all( $license_id ) {
		global $wpdb;
		$table = self::table();

		return false !== $wpdb->delete( $table, array( 'license_id' => (int) $license_id ), array( '%d' ) );
	}

	/**
	 * Unknown/legacy plan values fall back to the Starter limit (1) rather
	 * than an unlimited default — a typo'd or future plan string should
	 * fail closed, not accidentally grant unlimited sites.
	 */
	public static function limit_for_plan( $plan ) {
		return self::PLAN_LIMITS[ $plan ] ?? self::PLAN_LIMITS['starter'];
	}
}
