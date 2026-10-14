<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-IP rate limiting for the public licensing endpoints (/validate and
 * /agency/sites), which are deliberately unauthenticated — the license key
 * itself is the secret. Without a limit, anyone could hammer them to guess
 * keys or burn through activation slots with a leaked key.
 *
 * Two independent counters per client IP, each over a rolling window that
 * starts at the first hit (fixed-window, stored as a transient):
 *
 *  - total requests: generous, since a legitimate site checks in only a
 *    couple of times a day, but an Agency reseller can have up to 50 client
 *    sites sharing one outbound IP.
 *  - failed lookups (key not found / wrong product): tight, since a real
 *    site essentially never sends a key that doesn't exist.
 *
 * Only REMOTE_ADDR is trusted by default. X-Forwarded-For and similar
 * headers are client-controlled, so honoring them blindly would let an
 * attacker rotate a fake IP per request and never be limited. If the site
 * sits behind a trusted proxy/CDN, return the real client IP from the
 * `ovlic_rate_limit_client_ip` filter.
 *
 * Over-limit callers get HTTP 429 with a Retry-After header. Client
 * plugins that treat any non-200 as "server
 * unreachable" and fall back to their grace period, so a throttled site
 * degrades gracefully instead of locking clients out.
 *
 * Tunable via filters: `ovlic_rate_limit_total` (default 120),
 * `ovlic_rate_limit_failures` (default 10), `ovlic_rate_limit_window`
 * (default HOUR_IN_SECONDS). Return 0 from either limit filter to disable
 * that counter.
 */
class OVLIC_Rate_Limiter {

	const DEFAULT_TOTAL    = 120;
	const DEFAULT_FAILURES = 10;

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the client IP used for rate limiting — see class docblock.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'ovlic_rate_limit_client_ip', $ip );

		return '' !== $ip ? $ip : 'unknown';
	}

	private static function window() {
		return max( 60, (int) apply_filters( 'ovlic_rate_limit_window', HOUR_IN_SECONDS ) );
	}

	private static function key( $bucket, $endpoint ) {
		return 'ovlic_rl_' . $bucket . '_' . md5( $endpoint . '|' . self::client_ip() );
	}

	/**
	 * Counts this request against the total bucket and returns a 429
	 * response if the caller is already over either limit; null otherwise.
	 * Call at the top of an endpoint, before any database lookup.
	 *
	 * @return WP_REST_Response|null
	 */
	public static function check( $endpoint, $total_default = self::DEFAULT_TOTAL ) {
		// $endpoint is passed to the filters so a site can tune one endpoint
		// without touching the others.
		$total_limit   = (int) apply_filters( 'ovlic_rate_limit_total', $total_default, $endpoint );
		$failure_limit = (int) apply_filters( 'ovlic_rate_limit_failures', self::DEFAULT_FAILURES, $endpoint );

		if ( $failure_limit > 0 && self::count( 'fail', $endpoint ) >= $failure_limit ) {
			return self::too_many( 'fail', $endpoint );
		}

		if ( $total_limit > 0 ) {
			if ( self::count( 'all', $endpoint ) >= $total_limit ) {
				return self::too_many( 'all', $endpoint );
			}
			self::hit( 'all', $endpoint );
		}

		return null;
	}

	/**
	 * Burst limit for one VALID license on an expensive endpoint (for
	 * example one that makes paid third-party API calls), independent of the
	 * caller's IP. If a monthly allowance is checked before the expensive
	 * call and only recorded after it, N parallel requests can all pass the
	 * check while the license has one call left. This closes that window.
	 * Call after the license has been validated, and before the expensive
	 * call. Counts this request; returns a 429 response when over the limit,
	 * null otherwise. The production build uses this on a private endpoint
	 * that is not part of this showcase edition.
	 *
	 * Default: 10 requests per 10 minutes per license; tune or disable (0)
	 * with the `ovlic_rate_limit_license_burst` filter.
	 *
	 * @return WP_REST_Response|null
	 */
	public static function check_license( $endpoint, $license_key, $limit = 10, $window = 600 ) {
		$limit = (int) apply_filters( 'ovlic_rate_limit_license_burst', $limit, $endpoint );
		if ( $limit <= 0 ) {
			return null;
		}

		$key  = 'ovlic_rl_lic_' . md5( $endpoint . '|' . $license_key );
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['reset'] ) || $data['reset'] <= time() ) {
			$data = array( 'n' => 0, 'reset' => time() + max( 60, (int) $window ) );
		}

		if ( $data['n'] >= $limit ) {
			$retry = max( 1, $data['reset'] - time() );
			return new WP_REST_Response(
				array( 'valid' => false, 'ok' => false, 'reason' => 'rate_limited', 'retry_after' => $retry ),
				429,
				array( 'Retry-After' => (string) $retry )
			);
		}

		++$data['n'];
		set_transient( $key, $data, max( 1, $data['reset'] - time() ) );

		return null;
	}

	/**
	 * Records a failed lookup (unknown key / wrong product) against the
	 * caller. Call just before returning a not_found response.
	 */
	public static function record_failure( $endpoint ) {
		self::hit( 'fail', $endpoint );
	}

	private static function count( $bucket, $endpoint ) {
		$data = get_transient( self::key( $bucket, $endpoint ) );
		return is_array( $data ) ? (int) $data['n'] : 0;
	}

	private static function hit( $bucket, $endpoint ) {
		$key  = self::key( $bucket, $endpoint );
		$data = get_transient( $key );

		if ( ! is_array( $data ) || empty( $data['reset'] ) || $data['reset'] <= time() ) {
			$data = array( 'n' => 0, 'reset' => time() + self::window() );
		}

		++$data['n'];

		// Expire at the window's original end, not "now + window", so
		// repeated hits can't keep sliding the reset time forward.
		set_transient( $key, $data, max( 1, $data['reset'] - time() ) );
	}

	private static function too_many( $bucket, $endpoint ) {
		$data  = get_transient( self::key( $bucket, $endpoint ) );
		$retry = ( is_array( $data ) && ! empty( $data['reset'] ) ) ? max( 1, $data['reset'] - time() ) : self::window();

		return new WP_REST_Response(
			// 'valid' answers /validate callers, 'ok' answers /agency/sites callers.
			array( 'valid' => false, 'ok' => false, 'reason' => 'rate_limited', 'retry_after' => $retry ),
			429,
			array( 'Retry-After' => (string) $retry )
		);
	}
}
