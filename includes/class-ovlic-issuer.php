<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVLIC_Issuer {

	private static $instance = null;

	/** Set right before a false return from create_license_record(), so a
	 *  caller can surface the real reason instead of a bare "failed". */
	private $last_error = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function last_error() {
		return $this->last_error;
	}

	private function __construct() {
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_issue_license' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_issue_license' ) );
	}

	public function maybe_issue_license( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( '_ovlic_processed' ) ) {
			return;
		}

		$issued_any = false;

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( ! in_array( $product_id, OVLIC_LICENSED_PRODUCT_IDS, true ) ) {
				continue;
			}

			$plan = $this->resolve_plan_from_item( $item );

			$key = $this->create_license_record( $order->get_customer_id(), $product_id, $order->get_id(), '', $plan );

			if ( false === $key ) {
				$order->add_order_note( 'Outview Licensing: FAILED to issue a license — see PHP error log.' );
				continue;
			}

			$order->add_order_note( sprintf( 'Outview Licensing: issued license %s.', $key ) );
			$this->email_key_to_user( $order->get_customer_id(), $order->get_billing_email(), $key, $product_id, $plan );

			$issued_any = true;
		}

		if ( $issued_any ) {
			$order->update_meta_data( '_ovlic_processed', 'yes' );
			$order->save();
		}
	}

	/**
	 * Reads the purchased variation's "License Plan" attribute off an order
	 * item (added in outview-licensing 1.6.0 alongside the Professional/
	 * Agency draft variations on product 1002 — see class-ovlic-issuer.php's
	 * changelog note in the plugin header) and normalizes it to one of the
	 * three plan slugs create_license_record() accepts.
	 *
	 * WooCommerce stores a variation's attribute selections as order item
	 * meta keyed by the attribute's label ("License Plan") with the chosen
	 * term as the value ("Professional"). A simple product, a variation with
	 * no such attribute (Example Plugin C, Example Plugin A), or an unrecognized value all
	 * fall back to 'starter' — exactly the pre-1.6.0 behavior — so this is
	 * additive and can't change what any existing product/order issues.
	 *
	 * @param WC_Order_Item_Product $item
	 * @return string One of 'starter' | 'professional' | 'agency'.
	 */
	private function resolve_plan_from_item( $item ) {
		$raw = $item->get_meta( 'License Plan' );

		if ( '' === $raw ) {
			// Some themes/gateways store variation attribute meta with a
			// lowercase, slugified key instead of the display label —
			// covers that without requiring a second lookup at call sites.
			$raw = $item->get_meta( 'license-plan' );
		}

		$plan = strtolower( trim( (string) $raw ) );

		$valid_plans = array( 'starter', 'professional', 'agency' );

		return in_array( $plan, $valid_plans, true ) ? $plan : 'starter';
	}

	/**
	 * Called from the "Issue Manually" admin screen — same key generation and
	 * emailing as a real purchase, just without a WooCommerce order behind it.
	 *
	 * $plan is only meaningful for products whose client plugin actually
	 * checks tier (currently just Example Plugin B, product 1002 — see
	 * the client plugin's capability matrix). Since 1.6.0, a real
	 * WooCommerce purchase of product 1002 also resolves a real plan — see
	 * resolve_plan_from_item() — by reading the "License Plan" variation
	 * attribute off the order item. This manual screen remains the only way
	 * to issue a tiered key for a product that has no such per-tier
	 * variation set up (Example Plugin C, Example Plugin A), or to hand-correct a mistake.
	 *
	 * @return string|false The generated key, or false if the DB insert
	 *                       failed — callers must check for this rather
	 *                       than assuming the returned-looking key means
	 *                       the license actually got persisted. Call
	 *                       last_error() after a false return to see why.
	 */
	public function issue_manual_license( $customer_id, $product_id, $reason = '', $plan = 'starter' ) {
		$key = $this->create_license_record( $customer_id, $product_id, null, $reason, $plan );

		if ( false === $key ) {
			return false;
		}

		$user = get_userdata( $customer_id );
		if ( $user ) {
			$this->email_key_to_user( $customer_id, $user->user_email, $key, $product_id, $plan );
		}

		return $key;
	}

	/**
	 * @return string|false The generated key, or false on a failed insert
	 *                       (e.g. schema drift) — $wpdb->insert()'s result
	 *                       is checked explicitly rather than assumed, since
	 *                       the key itself is generated up-front regardless
	 *                       of whether the row actually gets persisted.
	 */
	private function create_license_record( $customer_id, $product_id, $order_id = null, $note = '', $plan = 'starter' ) {
		global $wpdb;

		$key = OVLIC_DB_Setup::generate_key( $product_id );

		$valid_plans = array( 'starter', 'professional', 'agency' );
		if ( ! in_array( $plan, $valid_plans, true ) ) {
			$plan = 'starter';
		}

		$inserted = $wpdb->insert(
			OVLIC_DB_Setup::table_licenses(),
			array(
				'license_key'   => $key,
				'customer_id'   => $customer_id,
				'product_id'    => $product_id,
				'order_id'      => $order_id ? $order_id : 0,
				'status'        => 'active',
				'plan'          => $plan,
				'bound_domain'  => '',
				'manual_reason' => $order_id ? '' : $note,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$this->last_error = $wpdb->last_error;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: only path to see why a license failed to persist.
			error_log( 'Outview Licensing: license insert failed — ' . $wpdb->last_error );
			return false;
		}

		return $key;
	}

	/**
	 * Prefix reflects which product was purchased so the key itself is
	 * self-describing (e.g. a support ticket mentioning "PRODB-..." is
	 * immediately recognizable as an Example Plugin B license). Both Example Plugin C
	 * SKUs (annual 1003, monthly 1004) share one PRODC- prefix — the
	 * billing interval isn't tracked by this plugin, so there's nothing to
	 * distinguish in the key itself.
	 *
	 * $plan is only mentioned in the email body when $product_id is in
	 * OVLIC_TIERED_PRODUCT_IDS — every license row has a 'plan' column
	 * (defaulting to 'starter'), but stating a tier on a product that has
	 * no actual tier distinction (Example Plugin C, Example Plugin A) would just read as
	 * confusing noise ("your Starter Example Plugin C license" when there's no
	 * such thing as a non-Starter one).
	 */
	private function email_key_to_user( $customer_id, $email, $key, $product_id, $plan = 'starter' ) {
		if ( ! $email ) {
			return;
		}

		$product_names = array(
			1001 => 'Example Plugin A',
			1002 => 'Example Plugin B',
			1003 => 'Example Plugin C',
			1004 => 'Example Plugin C',
		);
		$product_name = isset( $product_names[ $product_id ] ) ? $product_names[ $product_id ] : 'your Outview product';

		$plan_names = array(
			'starter'      => 'Starter',
			'professional' => 'Professional',
			'agency'       => 'Agency',
		);
		$is_tiered_product = in_array( $product_id, OVLIC_TIERED_PRODUCT_IDS, true );
		$plan_label         = isset( $plan_names[ $plan ] ) ? $plan_names[ $plan ] : ucfirst( $plan );
		$plan_line           = $is_tiered_product ? "Plan: {$plan_label}\n" : '';

		$subject = $is_tiered_product
			? "Your {$product_name} ({$plan_label}) license key"
			: "Your {$product_name} license key";

		$message = "Hi,\n\n"
			. "Your license key: {$key}\n"
			. $plan_line . "\n"
			. "You'll need this when you install {$product_name} on your website — enter it in the plugin's License screen in wp-admin once it's active.\n\n"
			. "This key activates on the FIRST domain you enter it on. If you need to move it to a different site later, just reply to this email and we'll reset it for you.\n\n"
			. "Thanks,\nOutview";

		wp_mail( $email, $subject, $message );
	}
}
