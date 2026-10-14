<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces class-ovlic-admin-list.php's CPT-list-table screen — the table
 * doesn't get one for free, so this is its own top-level "Licenses" page:
 * list + search + Revoke/Reactivate row actions, a "Manage Sites" screen
 * per license (multi-site domain list — see OVLIC_License_Domains), plus
 * the "Issue Manually" screen (unchanged in spirit, now writing to the
 * table).
 */
class OVLIC_Admin {

	const MENU_SLUG = 'ovlic-licenses';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_row_action' ) );
		add_action( 'admin_init', array( $this, 'handle_manual_issue' ) );
		add_action( 'admin_init', array( $this, 'handle_site_action' ) );
	}

	public function register_menu() {
		add_menu_page(
			'Licenses',
			'Licenses',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_list' ),
			'dashicons-privacy',
			56
		);

		add_submenu_page( self::MENU_SLUG, 'All Licenses', 'All Licenses', 'manage_options', self::MENU_SLUG, array( $this, 'render_list' ) );
		add_submenu_page( self::MENU_SLUG, 'Issue Manually', 'Issue Manually', 'manage_options', 'ovlic-manual-issue', array( $this, 'render_manual_issue_page' ) );
		// Not added as a visible submenu item — reached only via each
		// license row's "Manage Sites" link (needs a license_id in the
		// query string, so it doesn't make sense as a standalone nav
		// entry). Still registered so its capability check + page render
		// work when linked to.
		add_submenu_page( null, 'Manage Sites', 'Manage Sites', 'manage_options', 'ovlic-license-sites', array( $this, 'render_sites_page' ) );
	}

	/** ------------------------------------------------------------------
	 * List screen
	 * -------------------------------------------------------------------*/

	public function render_list() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = OVLIC_DB_Setup::table_licenses();

		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$domains_table = OVLIC_License_Domains::table();

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$licenses = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE license_key LIKE %s OR license_id IN ( SELECT license_id FROM {$domains_table} WHERE domain LIKE %s ) ORDER BY license_id DESC LIMIT 200",
					$like,
					$like
				)
			);
		} else {
			$licenses = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY license_id DESC LIMIT 200" );
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">Licenses</h1>';
		echo '<hr class="wp-header-end" />';

		echo '<form method="get" style="margin:16px 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search key or domain…" /> ';
		submit_button( 'Search', 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>Key</th><th>Product</th><th>Customer</th><th>Sites</th><th>Status</th><th>Issued</th><th></th></tr></thead><tbody>';

		if ( empty( $licenses ) ) {
			echo '<tr><td colspan="7">No licenses yet.</td></tr>';
		} else {
			foreach ( $licenses as $license ) {
				$user  = $license->customer_id ? get_userdata( $license->customer_id ) : false;
				$count = OVLIC_License_Domains::count( $license->license_id );
				$limit = OVLIC_License_Domains::limit_for_plan( $license->plan );

				echo '<tr>';
				echo '<td><code>' . esc_html( $license->license_key ) . '</code></td>';
				echo '<td>' . esc_html( $this->product_label( (int) $license->product_id ) ) . '</td>';
				echo '<td>' . ( $user ? esc_html( $user->display_name . ' (' . $user->user_email . ')' ) : '—' ) . '</td>';
				echo '<td>' . ( $count ? esc_html( $count . ' / ' . $limit ) : '<em>Not activated yet (0 / ' . esc_html( $limit ) . ')</em>' ) . '</td>';
				$color = 'revoked' === $license->status ? '#b91c1c' : '#15803d';
				echo '<td><strong style="color:' . esc_attr( $color ) . ';">' . esc_html( ucfirst( $license->status ) ) . '</strong></td>';
				echo '<td>' . esc_html( mysql2date( 'Y-m-d', $license->created_at ) ) . '</td>';
				echo '<td>' . $this->row_actions( $license ) . '</td>'; // phpcs:ignore -- built from esc_url()/esc_html() below
				echo '</tr>';
			}
		}

		echo '</tbody></table></div>';
	}

	private function product_label( $product_id ) {
		$labels = array(
			1001 => 'Example Plugin A',
			1002 => 'Example Plugin B',
			1003 => 'Example Plugin C — Annual',
			1004 => 'Example Plugin C — Monthly',
		);
		return isset( $labels[ $product_id ] ) ? $labels[ $product_id ] : ( 'Product #' . $product_id );
	}

	private function row_actions( $license ) {
		$base  = admin_url( 'admin.php' );
		$links = array();

		$sites_url = add_query_arg( array( 'page' => 'ovlic-license-sites', 'license_id' => $license->license_id ), $base );
		$links[]   = '<a href="' . esc_url( $sites_url ) . '">Manage Sites</a>';

		if ( 'revoked' !== $license->status ) {
			$url     = wp_nonce_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'ovlic_action' => 'revoke', 'ovlic_id' => $license->license_id ), $base ), 'ovlic_row_action_' . $license->license_id );
			$links[] = '<a href="' . esc_url( $url ) . '" style="color:#b91c1c;" onclick="return confirm(\'Revoke this license? The site using it will lose validation on its next check.\');">Revoke</a>';
		} else {
			$url     = wp_nonce_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'ovlic_action' => 'reactivate', 'ovlic_id' => $license->license_id ), $base ), 'ovlic_row_action_' . $license->license_id );
			$links[] = '<a href="' . esc_url( $url ) . '">Reactivate</a>';
		}

		return implode( ' | ', $links );
	}

	public function handle_row_action() {
		if ( empty( $_GET['ovlic_action'] ) || empty( $_GET['ovlic_id'] ) || empty( $_GET['page'] ) || self::MENU_SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$id = absint( $_GET['ovlic_id'] );
		check_admin_referer( 'ovlic_row_action_' . $id );

		global $wpdb;
		$table  = OVLIC_DB_Setup::table_licenses();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE license_id = %d", $id ) );

		if ( ! $exists ) {
			wp_die( 'License not found.' );
		}

		switch ( sanitize_text_field( $_GET['ovlic_action'] ) ) {
			case 'revoke':
				$wpdb->update( $table, array( 'status' => 'revoked' ), array( 'license_id' => $id ), array( '%s' ), array( '%d' ) );
				break;
			case 'reactivate':
				$wpdb->update( $table, array( 'status' => 'active' ), array( 'license_id' => $id ), array( '%s' ), array( '%d' ) );
				break;
			default:
				return;
		}

		wp_safe_redirect( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** ------------------------------------------------------------------
	 * Manual issuance screen
	 * -------------------------------------------------------------------*/

	public function render_manual_issue_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Issue a License Manually</h1>
			<p>Use this for anything that didn't go through a WooCommerce checkout — your own sites, comped licenses, manual EFT sales, etc.</p>
			<form method="post">
				<?php wp_nonce_field( 'ovlic_manual_issue' ); ?>
				<table class="form-table">
					<tr>
						<th>Customer</th>
						<td>
							<select name="ovlic_customer_id" required>
								<option value="">— Select a user —</option>
								<?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) : ?>
									<option value="<?php echo esc_attr( $user->ID ); ?>"><?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th>Product</th>
						<td>
							<select name="ovlic_product_id" required>
								<option value="1001">Example Plugin A</option>
								<option value="1002">Example Plugin B</option>
								<option value="1003">Example Plugin C — Annual</option>
								<option value="1004">Example Plugin C — Monthly</option>
							</select>
						</td>
					</tr>
					<tr>
						<th>Plan</th>
						<td>
							<select name="ovlic_plan">
								<option value="starter">Starter</option>
								<option value="professional">Professional</option>
								<option value="agency">Agency</option>
							</select>
							<p class="description">Only meaningful for products whose plugin actually checks tier (currently just Example Plugin B). Ignored by everything else.</p>
						</td>
					</tr>
					<tr>
						<th>Reason (internal note, optional)</th>
						<td><input type="text" name="ovlic_reason" class="regular-text" placeholder="e.g. own site, comped for beta tester, manual EFT sale" /></td>
					</tr>
				</table>
				<?php submit_button( 'Generate & Issue' ); ?>
			</form>
			<?php
			if ( ! empty( $_GET['issued'] ) ) {
				printf( '<div class="notice notice-success"><p>License issued: <code>%s</code> — also emailed to the customer.</p></div>', esc_html( sanitize_text_field( $_GET['issued'] ) ) );
			}
			if ( ! empty( $_GET['ovlic_issue_failed'] ) ) {
				printf(
					'<div class="notice notice-error"><p>License could not be saved. Nothing was issued.%s</p></div>',
					! empty( $_GET['ovlic_issue_error'] ) ? ' <code>' . esc_html( sanitize_text_field( wp_unslash( $_GET['ovlic_issue_error'] ) ) ) . '</code>' : ''
				);
			}
			?>
		</div>
		<?php
	}

	public function handle_manual_issue() {
		if ( ! isset( $_POST['ovlic_customer_id'], $_POST['ovlic_product_id'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ovlic_manual_issue' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$customer_id = absint( $_POST['ovlic_customer_id'] );
		$product_id  = absint( $_POST['ovlic_product_id'] );
		$reason      = isset( $_POST['ovlic_reason'] ) ? sanitize_text_field( $_POST['ovlic_reason'] ) : '';
		$plan        = isset( $_POST['ovlic_plan'] ) ? sanitize_key( $_POST['ovlic_plan'] ) : 'starter';

		if ( ! $customer_id || ! in_array( $product_id, OVLIC_LICENSED_PRODUCT_IDS, true ) ) {
			return;
		}

		$issuer = OVLIC_Issuer::instance();
		$key    = $issuer->issue_manual_license( $customer_id, $product_id, $reason, $plan );

		if ( false === $key ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'               => 'ovlic-manual-issue',
						'ovlic_issue_failed' => '1',
						'ovlic_issue_error'  => rawurlencode( $issuer->last_error() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'ovlic-manual-issue', 'issued' => rawurlencode( $key ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** ------------------------------------------------------------------
	 * Manage Sites screen — per-license domain list (multi-site / Agency
	 * "50 sites" enforcement, see OVLIC_License_Domains).
	 * -------------------------------------------------------------------*/

	public function render_sites_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$license_id = isset( $_GET['license_id'] ) ? absint( $_GET['license_id'] ) : 0;
		$license    = $license_id ? OVLIC_DB_Setup::find_by_id( $license_id ) : null;

		if ( ! $license ) {
			echo '<div class="wrap"><h1>Manage Sites</h1><p>License not found.</p></div>';
			return;
		}

		$domains = OVLIC_License_Domains::get_domains( $license_id );
		$limit   = OVLIC_License_Domains::limit_for_plan( $license->plan );
		$base    = admin_url( 'admin.php' );

		echo '<div class="wrap">';
		echo '<h1>Manage Sites — <code>' . esc_html( $license->license_key ) . '</code></h1>';
		echo '<p>' . esc_html( ucfirst( $license->plan ) ) . ' plan — ' . esc_html( count( $domains ) . ' / ' . $limit ) . ' sites activated.</p>';

		if ( ! empty( $_GET['ovlic_sites_error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['ovlic_sites_error'] ) ) ) );
		}

		echo '<table class="widefat striped" style="max-width:700px;"><thead><tr><th>Domain</th><th></th></tr></thead><tbody>';

		if ( empty( $domains ) ) {
			echo '<tr><td colspan="2">No sites activated yet — this license has not been entered on a live site.</td></tr>';
		} else {
			foreach ( $domains as $domain ) {
				$remove_url = wp_nonce_url(
					add_query_arg(
						array( 'page' => 'ovlic-license-sites', 'license_id' => $license_id, 'ovlic_site_action' => 'remove', 'domain' => rawurlencode( $domain ) ),
						$base
					),
					'ovlic_site_action_' . $license_id
				);
				echo '<tr><td>' . esc_html( $domain ) . '</td><td><a href="' . esc_url( $remove_url ) . '" onclick="return confirm(\'Remove this site? It will need to re-enter the license key to reactivate, and will only succeed if the license is under its site limit.\');">Remove (free this slot)</a></td></tr>';
			}
		}

		echo '</tbody></table>';

		// Manual "add a domain" — lets an admin grandfather in a site
		// (e.g. one migrating from another agency, or a staging domain a
		// client wants pre-approved) without it having to burn its own
		// activation attempt first. Still enforces the same plan limit as
		// automatic activation — an admin fat-fingering past the cap
		// would silently defeat the whole feature otherwise.
		echo '<h2>Add a site manually</h2>';
		echo '<form method="post" style="max-width:500px;">';
		wp_nonce_field( 'ovlic_site_add_' . $license_id );
		echo '<input type="hidden" name="ovlic_add_license_id" value="' . esc_attr( $license_id ) . '" />';
		echo '<input type="text" name="ovlic_add_domain" class="regular-text" placeholder="example.com" required /> ';
		submit_button( 'Add', 'secondary', '', false );
		echo '</form>';

		echo '<p style="margin-top:24px;"><a href="' . esc_url( add_query_arg( 'page', self::MENU_SLUG, $base ) ) . '">&larr; Back to All Licenses</a></p>';
		echo '</div>';
	}

	public function handle_site_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Remove (GET, nonce-protected link from the table above).
		if ( ! empty( $_GET['ovlic_site_action'] ) && 'remove' === $_GET['ovlic_site_action'] && ! empty( $_GET['license_id'] ) && isset( $_GET['domain'] ) ) {
			$license_id = absint( $_GET['license_id'] );
			check_admin_referer( 'ovlic_site_action_' . $license_id );

			$domain = sanitize_text_field( rawurldecode( wp_unslash( $_GET['domain'] ) ) );
			OVLIC_License_Domains::remove_domain( $license_id, $domain );

			wp_safe_redirect( add_query_arg( array( 'page' => 'ovlic-license-sites', 'license_id' => $license_id ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Add (POST form above).
		if ( isset( $_POST['ovlic_add_license_id'], $_POST['ovlic_add_domain'] ) ) {
			$license_id = absint( $_POST['ovlic_add_license_id'] );
			check_admin_referer( 'ovlic_site_add_' . $license_id );

			$license = OVLIC_DB_Setup::find_by_id( $license_id );

			if ( ! $license ) {
				return;
			}

			$domain = strtolower( preg_replace( '/^www\./', '', trim( (string) $_POST['ovlic_add_domain'] ) ) );

			if ( '' === $domain ) {
				return;
			}

			if ( OVLIC_License_Domains::has_domain( $license_id, $domain ) ) {
				wp_safe_redirect( add_query_arg( array( 'page' => 'ovlic-license-sites', 'license_id' => $license_id ), admin_url( 'admin.php' ) ) );
				exit;
			}

			$limit = OVLIC_License_Domains::limit_for_plan( $license->plan );
			$count = OVLIC_License_Domains::count( $license_id );

			if ( $count >= $limit ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'               => 'ovlic-license-sites',
							'license_id'         => $license_id,
							'ovlic_sites_error'  => rawurlencode( "This license is already at its {$limit}-site limit for the {$license->plan} plan. Remove a site first, or issue/upgrade to a higher plan." ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			OVLIC_License_Domains::add_domain( $license_id, $domain );

			wp_safe_redirect( add_query_arg( array( 'page' => 'ovlic-license-sites', 'license_id' => $license_id ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}
}
