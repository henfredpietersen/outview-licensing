<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ov_license post meta:
 *  _ovlic_key            (string) the license key itself, e.g. PRODA-XXXX-XXXX-XXXX
 *  _ovlic_customer_id    (int)    WP user who purchased it
 *  _ovlic_order_id       (int)    WC order that issued it
 *  _ovlic_product_id     (int)    WooCommerce product ID the license was issued for
 *  _ovlic_status         (string) active|revoked
 *  _ovlic_bound_domain   (string) the site URL it's activated on (empty until first validation)
 *  _ovlic_activated_at   (string) Y-m-d H:i:s of first activation
 *
 * Unchanged from 1.0.2 — no schema changes were needed to add Example Plugin C,
 * since licenses are already keyed by product ID rather than a fixed list
 * of known products.
 */
class OVLIC_Post_Type {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	public function register_post_type() {
		register_post_type(
			'ov_license',
			array(
				'labels'              => array(
					'name'          => 'Licenses',
					'singular_name' => 'License',
				),
				'public'              => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'show_ui'             => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_menu'        => 'woocommerce',
				'supports'            => array( 'title' ),
				'capability_type'     => 'shop_order',
				'map_meta_cap'        => true,
				'has_archive'         => false,
				'exclude_from_search' => true,
			)
		);
	}
}
