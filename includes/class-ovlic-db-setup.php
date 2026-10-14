<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom table for license records, replacing the ov_license CPT as the
 * operational source of truth for issuing/validating/managing licenses.
 *
 * The migration is deliberately non-destructive: it COPIES every existing
 * ov_license post + its meta into wp_ovlic_licenses and never deletes or
 * modifies the original posts. class-ovlic-post-type.php is still
 * required and still registers the CPT, purely so that historical data
 * stays browsable in wp-admin as a fallback/audit trail — nothing else
 * reads from it anymore once this has run.
 *
 * maybe_upgrade() runs on 'init' (after the post type is registered) on
 * every request, comparing a stored version option, so it also completes
 * automatically for a site owner who just overwrites the plugin files
 * without deactivating/reactivating — register_activation_hook alone
 * would miss that deployment path.
 */
class OVLIC_DB_Setup {

	const DB_VERSION_OPTION    = 'ovlic_db_version';
	const MIGRATION_LOG_OPTION = 'ovlic_migration_log';

	public static function table_licenses() {
		global $wpdb;
		return $wpdb->prefix . 'ovlic_licenses';
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === OVLIC_VERSION ) {
			return;
		}

		self::create_table();
		self::migrate_from_cpt();
		OVLIC_License_Domains::create_table();
		OVLIC_License_Domains::migrate_from_bound_domain_column();

		update_option( self::DB_VERSION_OPTION, OVLIC_VERSION );
	}

	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table            = self::table_licenses();

		$sql = "CREATE TABLE {$table} (
			license_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			license_key VARCHAR(60) NOT NULL DEFAULT '',
			customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			plan VARCHAR(20) NOT NULL DEFAULT 'starter',
			bound_domain VARCHAR(190) NOT NULL DEFAULT '',
			manual_reason VARCHAR(255) NOT NULL DEFAULT '',
			activated_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00',
			legacy_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (license_id),
			UNIQUE KEY license_key (license_key),
			KEY customer_id (customer_id),
			KEY product_id (product_id),
			KEY status (status),
			KEY legacy_post_id (legacy_post_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Copies every ov_license post + its meta into the new table. Never
	 * touches the source posts. Safe to call more than once — a row
	 * already migrated (matched by legacy_post_id) is skipped, so a
	 * partial or repeated run can never create duplicates.
	 */
	public static function migrate_from_cpt() {
		global $wpdb;
		$table = self::table_licenses();

		$posts = get_posts(
			array(
				'post_type'      => 'ov_license',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$migrated = 0;
		$skipped  = 0;

		foreach ( $posts as $post_id ) {
			$already_migrated = (int) $wpdb->get_var( $wpdb->prepare( "SELECT license_id FROM {$table} WHERE legacy_post_id = %d", $post_id ) );

			if ( $already_migrated ) {
				++$skipped;
				continue;
			}

			$key = get_post_meta( $post_id, '_ovlic_key', true );

			if ( '' === $key ) {
				// Nothing usable to migrate — an incomplete/corrupt record.
				continue;
			}

			$activated_at = get_post_meta( $post_id, '_ovlic_activated_at', true );

			$wpdb->insert(
				$table,
				array(
					'license_key'    => $key,
					'customer_id'    => (int) get_post_meta( $post_id, '_ovlic_customer_id', true ),
					'product_id'     => (int) get_post_meta( $post_id, '_ovlic_product_id', true ),
					'order_id'       => (int) get_post_meta( $post_id, '_ovlic_order_id', true ),
					'status'         => get_post_meta( $post_id, '_ovlic_status', true ) ?: 'active',
					'bound_domain'   => (string) get_post_meta( $post_id, '_ovlic_bound_domain', true ),
					'manual_reason'  => (string) get_post_meta( $post_id, '_ovlic_manual_reason', true ),
					'activated_at'   => $activated_at ? $activated_at : null,
					'created_at'     => get_post_field( 'post_date_gmt', $post_id ) ?: current_time( 'mysql', true ),
					'legacy_post_id' => $post_id,
				),
				array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			++$migrated;
		}

		update_option(
			self::MIGRATION_LOG_OPTION,
			array(
				'ran_at'   => current_time( 'mysql' ),
				'migrated' => $migrated,
				'skipped'  => $skipped,
				'total'    => count( $posts ),
			)
		);
	}

	public static function generate_key( $product_id ) {
		$prefixes = array(
			1001 => 'PRODA',
			1002 => 'PRODB',
			1003 => 'PRODC',
			1004 => 'PRODC',
		);
		$prefix = isset( $prefixes[ $product_id ] ) ? $prefixes[ $product_id ] : 'OUTVIEW';

		$chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L to avoid transcription errors

		do {
			$segments = array();
			for ( $s = 0; $s < 3; $s++ ) {
				$segment = '';
				for ( $i = 0; $i < 4; $i++ ) {
					$segment .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
				}
				$segments[] = $segment;
			}
			$key = $prefix . '-' . implode( '-', $segments );
		} while ( self::find_by_key( $key ) );

		return $key;
	}

	public static function find_by_key( $key ) {
		global $wpdb;
		$table = self::table_licenses();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_key = %s", $key ) );

		return $row ?: null;
	}

	public static function find_by_id( $license_id ) {
		global $wpdb;
		$table = self::table_licenses();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_id = %d", $license_id ) );

		return $row ?: null;
	}
}
