<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVLIC_REST_API {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'outview-licensing/v1',
			'/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_validate' ),
				'permission_callback' => '__return_true', // Public by design — the secret is the license key itself.
				'args'                => array(
					'license_key' => array( 'required' => true, 'type' => 'string' ),
					'site_url'    => array( 'required' => true, 'type' => 'string' ),
					// Optional: a client plugin may send its own product
					// slug (e.g. "product-a"). When
					// present, the key must belong to that product.
					// Omitted entirely, validation behaves exactly as
					// before — existing installs that don't send it are
					// unaffected.
					'product'     => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			'outview-licensing/v1',
			'/agency/sites',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_agency_sites' ),
				'permission_callback' => '__return_true', // Same trust model as /validate -- the license key is the secret.
				'args'                => array(
					'license_key' => array( 'required' => true, 'type' => 'string' ),
					'site_url'    => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Backs the Agency dashboard in the client plugin: lists every domain activated on the
	 * calling site's license, so an Agency reseller can see all the client
	 * sites their one license covers without logging into each one
	 * separately.
	 *
	 * Narrower trust model than /validate in one respect: the calling site
	 * must already be a registered activation on that license (the
	 * has_domain check below), not just a holder of the key. /validate has
	 * to accept a brand-new domain's first check-in, but this endpoint
	 * hands back every OTHER site's domain name too -- requiring the caller
	 * to already be a member keeps a leaked-but-not-yet-activated key from
	 * enumerating a license's whole site list.
	 */
	public function handle_agency_sites( WP_REST_Request $request ) {
		$limited = OVLIC_Rate_Limiter::check( 'agency' );
		if ( $limited ) {
			return $limited;
		}

		$key      = sanitize_text_field( $request->get_param( 'license_key' ) );
		$site_url = esc_url_raw( $request->get_param( 'site_url' ) );
		$domain   = $this->normalize_domain( $site_url );

		$license = OVLIC_DB_Setup::find_by_key( $key );

		if ( ! $license ) {
			OVLIC_Rate_Limiter::record_failure( 'agency' );
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'not_found' ), 200 );
		}

		if ( 'revoked' === $license->status ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'revoked' ), 200 );
		}

		if ( 'agency' !== $license->plan ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'not_agency', 'plan' => $license->plan ), 200 );
		}

		if ( ! OVLIC_License_Domains::has_domain( $license->license_id, $domain ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'domain_not_registered' ), 200 );
		}

		$domains = OVLIC_License_Domains::get_domains_with_dates( $license->license_id );
		$limit   = OVLIC_License_Domains::limit_for_plan( $license->plan );

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'plan'           => $license->plan,
				'limit'          => $limit,
				'count'          => count( $domains ),
				'current_domain' => $domain,
				'domains'        => $domains,
			),
			200
		);
	}

	public function handle_validate( WP_REST_Request $request ) {
		$limited = OVLIC_Rate_Limiter::check( 'validate' );
		if ( $limited ) {
			return $limited;
		}

		$key      = sanitize_text_field( $request->get_param( 'license_key' ) );
		$site_url = esc_url_raw( $request->get_param( 'site_url' ) );
		$domain   = $this->normalize_domain( $site_url );
		$product  = $request->get_param( 'product' ) ? sanitize_text_field( $request->get_param( 'product' ) ) : '';

		$license = OVLIC_DB_Setup::find_by_key( $key );

		if ( ! $license ) {
			OVLIC_Rate_Limiter::record_failure( 'validate' );
			return new WP_REST_Response( array( 'valid' => false, 'reason' => 'not_found' ), 200 );
		}

		if ( '' !== $product ) {
			$license_slug = isset( OVLIC_PRODUCT_SLUGS[ (int) $license->product_id ] ) ? OVLIC_PRODUCT_SLUGS[ (int) $license->product_id ] : '';

			if ( $license_slug !== $product ) {
				// Right key, wrong plugin — treat the same as not found
				// rather than confirming the key exists for a different
				// product.
				OVLIC_Rate_Limiter::record_failure( 'validate' );
				return new WP_REST_Response( array( 'valid' => false, 'reason' => 'not_found' ), 200 );
			}
		}

		if ( 'revoked' === $license->status ) {
			return new WP_REST_Response( array( 'valid' => false, 'reason' => 'revoked' ), 200 );
		}

		// Multi-site (Agency "50 sites") enforcement — see
		// OVLIC_License_Domains's class docblock for why this is a
		// separate table now rather than the single bound_domain column.
		// A domain the license has already activated on always passes,
		// regardless of how many other domains it's using (re-validating
		// from an already-registered site must never fail just because
		// the license happens to be at its cap elsewhere). Only a NEW
		// domain is checked against the plan's limit.
		$limit = OVLIC_License_Domains::limit_for_plan( $license->plan );

		if ( OVLIC_License_Domains::has_domain( $license->license_id, $domain ) ) {
			return new WP_REST_Response(
				array(
					'valid'       => true,
					'reason'      => 'ok',
					'plan'        => $license->plan,
					'sites_limit' => $limit,
					'sites_count' => OVLIC_License_Domains::count( $license->license_id ),
				),
				200
			);
		}

		$current_count = OVLIC_License_Domains::count( $license->license_id );

		if ( $current_count >= $limit ) {
			return new WP_REST_Response(
				array(
					'valid'  => false,
					'reason' => 'site_limit_reached',
					'plan'   => $license->plan,
					'limit'  => $limit,
				),
				200
			);
		}

		OVLIC_License_Domains::add_domain( $license->license_id, $domain );

		global $wpdb;
		$table = OVLIC_DB_Setup::table_licenses();

		// Legacy bound_domain column: keep it populated with the FIRST
		// domain a license ever activates on, purely so anything that
		// still reads it directly off the row (e.g. the admin list's
		// search-by-domain) shows something sane. Not used for validation
		// decisions any more — OVLIC_License_Domains is the source of
		// truth for that.
		if ( '' === $license->bound_domain ) {
			$wpdb->update(
				$table,
				array( 'bound_domain' => $domain, 'activated_at' => current_time( 'mysql', true ) ),
				array( 'license_id' => $license->license_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		return new WP_REST_Response(
			array(
				'valid'       => true,
				'reason'      => 'activated',
				'plan'        => $license->plan,
				'sites_limit' => $limit,
				'sites_count' => $current_count + 1,
			),
			200
		);
	}

	private function normalize_domain( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host ? strtolower( preg_replace( '/^www\./', '', $host ) ) : strtolower( $url );
	}
}
