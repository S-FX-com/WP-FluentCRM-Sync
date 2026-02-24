<?php
/**
 * GitHub Releases updater.
 *
 * Hooks into the WordPress plugin update mechanism and checks
 * https://api.github.com/repos/S-FX-com/WP-FluentCRM-Sync/releases/latest
 * for a newer version. When a newer release tag is found, WordPress will
 * display the standard "update available" notice and handle the download.
 *
 * @package FCRM_WP_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class FCRM_WP_Sync_Github_Updater
 */
class FCRM_WP_Sync_Github_Updater {

	/** GitHub repository owner. */
	const GITHUB_USER = 'S-FX-com';

	/** GitHub repository name. */
	const GITHUB_REPO = 'WP-FluentCRM-Sync';

	/** Transient key for caching the latest release data (6 hours). */
	const TRANSIENT_KEY = 'fcrm_wp_sync_github_release';

	/** @var string plugin_basename() of the main plugin file. */
	private $plugin_slug;

	/** @var string Absolute path to the main plugin file. */
	private $plugin_file;

	/** @var string Currently installed version. */
	private $current_version;

	/**
	 * Constructor — registers all WordPress hooks.
	 */
	public function __construct() {
		$this->plugin_file     = FCRM_WP_SYNC_FILE;
		$this->plugin_slug     = plugin_basename( FCRM_WP_SYNC_FILE );
		$this->current_version = FCRM_WP_SYNC_VERSION;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_post_install',                 [ $this, 'post_install' ], 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( FCRM_WP_SYNC_FILE ), [ $this, 'action_links' ] );
		add_action( 'admin_init',                            [ $this, 'handle_manual_check' ] );
		add_action( 'admin_notices',                         [ $this, 'show_check_notice' ] );
	}

	// -------------------------------------------------------------------------
	// GitHub API
	// -------------------------------------------------------------------------

	/**
	 * Fetch the latest release data from the GitHub API.
	 *
	 * Results are cached in a site transient for 6 hours to avoid hammering
	 * the API on every admin page load.
	 *
	 * @return array|null Decoded release object, or null on failure.
	 */
	private function get_release_data(): ?array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$api_url  = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);

		$response = wp_remote_get(
			$api_url,
			[
				'headers' => [
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['tag_name'] ) ) {
			return null;
		}

		set_transient( self::TRANSIENT_KEY, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Strip a leading "v" from a tag name so it can be compared with semver.
	 *
	 * @param string $tag e.g. "v1.2.0" or "1.2.0".
	 * @return string e.g. "1.2.0".
	 */
	private function tag_to_version( string $tag ): string {
		return ltrim( $tag, 'vV' );
	}

	// -------------------------------------------------------------------------
	// WordPress update hooks
	// -------------------------------------------------------------------------

	/**
	 * Inject update data into the WordPress update transient when a newer
	 * release is available on GitHub.
	 *
	 * Hooked to: pre_set_site_transient_update_plugins
	 *
	 * @param object $transient The update_plugins transient value.
	 * @return object Possibly modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release_data();
		if ( null === $release ) {
			return $transient;
		}

		$remote_version = $this->tag_to_version( $release['tag_name'] );

		if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_slug ] = (object) [
				'slug'        => dirname( $this->plugin_slug ),
				'plugin'      => $this->plugin_slug,
				'new_version' => $remote_version,
				'url'         => esc_url( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO ),
				'package'     => $release['zipball_url'],
				'requires'    => '5.8',
				'tested'      => '6.7',
			];
		}

		return $transient;
	}

	/**
	 * Populate the plugin information modal (Plugins → "View version x.x.x details").
	 *
	 * Hooked to: plugins_api
	 *
	 * @param false|object|array $result  Current result (false = not handled yet).
	 * @param string             $action  API action name.
	 * @param object             $args    Request arguments.
	 * @return false|object Modified result or original if not our plugin.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		// $args->slug is the folder name, not the full basename.
		if ( dirname( $this->plugin_slug ) !== $args->slug ) {
			return $result;
		}

		$release = $this->get_release_data();
		if ( null === $release ) {
			return $result;
		}

		$remote_version = $this->tag_to_version( $release['tag_name'] );

		return (object) [
			'name'          => 'FluentCRM WordPress Sync',
			'slug'          => dirname( $this->plugin_slug ),
			'version'       => $remote_version,
			'author'        => '<a href="https://github.com/' . esc_attr( self::GITHUB_USER ) . '">S-FX</a>',
			'homepage'      => esc_url( 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO ),
			'requires'      => '5.8',
			'tested'        => '6.7',
			'last_updated'  => $release['published_at'] ?? '',
			'sections'      => [
				'description' => 'Bidirectional sync between FluentCRM contacts and WordPress users with configurable field mapping, ACF support, and mismatch resolution.',
				'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( $release['body'] ) : 'See GitHub releases for changelog.',
			],
			'download_link' => $release['zipball_url'],
		];
	}

	/**
	 * After WordPress installs the update, rename the unpacked directory from
	 * GitHub's hashed folder name (e.g. "S-FX-com-WP-FluentCRM-Sync-a1b2c3d")
	 * back to the expected plugin folder name.
	 *
	 * Hooked to: upgrader_post_install
	 *
	 * @param bool  $response   Install response.
	 * @param array $hook_extra Extra data about what was installed.
	 * @param array $result     Result data including destination path.
	 * @return array Modified result.
	 */
	public function post_install( $response, array $hook_extra, array $result ): array {
		global $wp_filesystem;

		// Only act on our own plugin.
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $result;
		}

		$proper_destination = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );

		$wp_filesystem->move( $result['destination'], $proper_destination, true );
		$result['destination'] = $proper_destination;

		// Re-activate if it was active before the update.
		if ( is_plugin_active( $this->plugin_slug ) ) {
			activate_plugin( $this->plugin_slug );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Plugins-page "Check for Updates" link
	// -------------------------------------------------------------------------

	/**
	 * Append a "Check for Updates" action link to this plugin's row on
	 * wp-admin/plugins.php.
	 *
	 * Hooked to: plugin_action_links_{slug}
	 *
	 * @param string[] $links Existing action links.
	 * @return string[] Modified links.
	 */
	public function action_links( array $links ): array {
		$url = wp_nonce_url(
			add_query_arg( 'fcrm_check_update', '1', self_admin_url( 'plugins.php' ) ),
			'fcrm_check_update'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">'
			. esc_html__( 'Check for Updates', 'fcrm-wp-sync' )
			. '</a>';

		return $links;
	}

	/**
	 * Process the manual update-check request.
	 *
	 * Flushes the cached release data and the WordPress update_plugins
	 * transient so that WordPress performs a fresh check immediately.
	 * Redirects back to plugins.php with a result query arg so the
	 * admin notice can be shown.
	 *
	 * Hooked to: admin_init
	 */
	public function handle_manual_check(): void {
		if ( empty( $_GET['fcrm_check_update'] ) ) { // phpcs:ignore
			return;
		}

		check_admin_referer( 'fcrm_check_update' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fcrm-wp-sync' ) );
		}

		// Bust the cached release data so get_release_data() hits GitHub fresh.
		self::flush_cache();

		// Also delete WordPress's own update_plugins transient so it re-runs
		// check_for_update() immediately on the next page load.
		delete_site_transient( 'update_plugins' );

		// Fetch the latest release now so the result is ready for the notice.
		$release = $this->get_release_data();
		$result  = 'fail';

		if ( $release ) {
			$remote = $this->tag_to_version( $release['tag_name'] );
			if ( version_compare( $remote, $this->current_version, '>' ) ) {
				$result = 'update_available';
			} else {
				$result = 'up_to_date';
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'fcrm_update_result' => $result ],
				self_admin_url( 'plugins.php' )
			)
		);
		exit;
	}

	/**
	 * Display an admin notice after a manual update check.
	 *
	 * Hooked to: admin_notices
	 */
	public function show_check_notice(): void {
		if ( empty( $_GET['fcrm_update_result'] ) ) { // phpcs:ignore
			return;
		}

		$result = sanitize_key( $_GET['fcrm_update_result'] ); // phpcs:ignore

		switch ( $result ) {
			case 'update_available':
				$release = $this->get_release_data();
				$version = $release ? $this->tag_to_version( $release['tag_name'] ) : '';
				$message = sprintf(
					/* translators: %s: new version number */
					esc_html__( 'FluentCRM WP Sync: version %s is available. Use the "Update now" link to install it.', 'fcrm-wp-sync' ),
					esc_html( $version )
				);
				$class = 'notice-warning';
				break;

			case 'up_to_date':
				$message = sprintf(
					/* translators: %s: current version number */
					esc_html__( 'FluentCRM WP Sync: you are running the latest version (%s).', 'fcrm-wp-sync' ),
					esc_html( $this->current_version )
				);
				$class = 'notice-success';
				break;

			default:
				$message = esc_html__( 'FluentCRM WP Sync: could not reach GitHub to check for updates. Please try again later.', 'fcrm-wp-sync' );
				$class   = 'notice-error';
				break;
		}

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			$message
		);
	}

	// -------------------------------------------------------------------------
	// Cache management
	// -------------------------------------------------------------------------

	/**
	 * Delete the cached release transient so the next update check fetches
	 * fresh data from the GitHub API.
	 */
	public static function flush_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
