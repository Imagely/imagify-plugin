<?php
defined( 'ABSPATH' ) || die( 'Cheatin\' uh?' );

/**
 * Class that handles the admin notices.
 *
 * @since  1.6.10
 * @author Grégory Viguier
 */
class Imagify_Notices {

	/**
	 * Class version.
	 *
	 * @var string
	 */
	const VERSION = '1.0';

	/**
	 * Name of the user meta that stores the dismissed notice IDs.
	 *
	 * @var string
	 */
	const DISMISS_META_NAME = '_imagify_ignore_notices';

	/**
	 * Action used in the nonce to dismiss a notice.
	 *
	 * @var string
	 */
	const DISMISS_NONCE_ACTION = 'imagify-dismiss-notice';

	/**
	 * Action used in the nonce to deactivate a plugin.
	 *
	 * @var string
	 */
	const DEACTIVATE_PLUGIN_NONCE_ACTION = 'imagify-deactivate-plugin';

	/**
	 * The path to the folder containing the views.
	 *
	 * @var string
	 */
	protected static $views_folder = IMAGIFY_ADMIN_UI_PATH;

	/**
	 * List of notice IDs.
	 * They correspond to method names and IDs stored in the "dismissed" transient.
	 * Only use "-" character, not "_".
	 *
	 * @var array
	 */
	protected static $notice_ids = array(
		// This warning is displayed when the API key is empty. Dismissible.
		'welcome-steps',
		// This warning is displayed when the API key is wrong. Dismissible.
		'wrong-api-key',
		// This warning is displayed if some plugins are active. NOT dismissible.
		'plugins-to-deactivate',
		// This notice is displayed when external HTTP requests are blocked via the WP_HTTP_BLOCK_EXTERNAL constant. Dismissible.
		'http-block-external',
		// This warning is displayed when the grid view is active on the library. Dismissible.
		'grid-view',
		// This warning is displayed to warn the user that its quota is consumed for the current month. Dismissible.
		'free-over-quota',
		// This warning is displayed if the backup folder is not writable. NOT dismissible.
		'backup-folder-not-writable',
		// This notice is displayed to rate the plugin after 100 optimizations & 7 days after the first installation. Dismissible.
		'rating',
		// Add a message about WP Rocket on the "Bulk Optimization" screen. Dismissible.
		'wp-rocket',
	);

	/**
	 * List of user capabilities to use for each notice.
	 * Default value is not listed.
	 *
	 * @var array
	 */
	protected static $capabilities = array(
		'grid-view'                  => 'upload',
		'backup-folder-not-writable' => 'admin',
		'rating'                     => 'admin',
		'wp-rocket'                  => 'admin',
	);

	/**
	 * List of plugins that conflict with Imagify.
	 *
	 * @var array
	 */
	protected static $conflicting_plugins = array(
		'wp-smush'     => 'wp-smushit/wp-smush.php',                                   // WP Smush.
		'wp-smush-pro' => 'wp-smush-pro/wp-smush.php',                                 // WP Smush Pro.
		'kraken'       => 'kraken-image-optimizer/kraken.php',                         // Kraken.io.
		'tinypng'      => 'tiny-compress-images/tiny-compress-images.php',             // TinyPNG.
		'shortpixel'   => 'shortpixel-image-optimiser/wp-shortpixel.php',              // Shortpixel.
		'ewww'         => 'ewww-image-optimizer/ewww-image-optimizer.php',             // EWWW Image Optimizer.
		'ewww-cloud'   => 'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php', // EWWW Image Optimizer Cloud.
		'imagerecycle' => 'imagerecycle-pdf-image-compression/wp-image-recycle.php',   // ImageRecycle.
	);

	/**
	 * The single instance of the class.
	 *
	 * @var object
	 */
	protected static $_instance;

	/**
	 * The constructor.
	 *
	 * @return void
	 */
	protected function __construct() {}


	/** ----------------------------------------------------------------------------------------- */
	/** INIT ==================================================================================== */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Get the main Instance.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return object Main instance.
	 */
	public static function get_instance() {
		if ( ! isset( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Launch the hooks.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 */
	public function init() {
		add_action( 'all_admin_notices',                    array( $this, 'render_notices' ) );
		add_action( 'wp_ajax_imagify_dismiss_notice',       array( $this, 'admin_post_dismiss_notice' ) );
		add_action( 'admin_post_imagify_dismiss_notice',    array( $this, 'admin_post_dismiss_notice' ) );
		add_action( 'imagify_dismiss_notice',               array( $this, 'clear_scheduled_rating' ) );
		add_action( 'admin_post_imagify_deactivate_plugin', array( $this, 'deactivate_plugin' ) );
	}


	/** ----------------------------------------------------------------------------------------- */
	/** HOOKS =================================================================================== */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Maybe display some notices.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 */
	public function render_notices() {
		foreach ( $this->get_notice_ids() as $notice_id ) {
			// Get the name of the method that will tell if this notice should be displayed.
			$callback = 'display_' . str_replace( '-', '_', $notice_id );

			if ( ! method_exists( $this, $callback ) ) {
				continue;
			}

			$data = call_user_func( array( $this, $callback ) );

			if ( $data ) {
				// The notice must be displayed: render the view.
				$this->render_view( str_replace( '_', '-', $notice_id ), $data );
			}
		}
	}

	/**
	 * Process a dismissed notice.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 * @see    _do_admin_post_imagify_dismiss_notice()
	 */
	public function admin_post_dismiss_notice() {
		imagify_check_nonce( self::DISMISS_NONCE_ACTION );

		$notice  = ! empty( $_GET['notice'] ) ? esc_html( $_GET['notice'] ) : false;
		$notices = $this->get_notice_ids();
		$notices = array_flip( $notices );

		if ( ! $notice || ! isset( $notices[ $notice ] ) || ! $this->user_can( $notice ) ) {
			imagify_die();
		}

		self::dismiss_notice( $notice );

		/**
		 * Fires when a notice is dismissed.
		 *
		 * @since 1.4.2
		 *
		 * @param int $notice The notice slug
		*/
		do_action( 'imagify_dismiss_notice', $notice );

		imagify_maybe_redirect();
		wp_send_json_success();
	}

	/**
	 * Stop the rating cron when the notice is dismissed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 * @see    _imagify_clear_scheduled_rating()
	 *
	 * @param string $notice The notice name.
	 */
	public function clear_scheduled_rating( $notice ) {
		if ( 'rating' === $notice ) {
			set_site_transient( 'do_imagify_rating_cron', 'no' );
			wp_clear_scheduled_hook( 'imagify_rating_event' );
		}
	}

	/**
	 * Disable a plugin which can be in conflict with Imagify.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 * @see    _imagify_deactivate_plugin()
	 */
	public function deactivate_plugin() {
		imagify_check_nonce( self::DEACTIVATE_PLUGIN_NONCE_ACTION );

		if ( empty( $_GET['plugin'] ) || ! $this->user_can( 'plugins-to-deactivate' ) ) {
			imagify_die();
		}

		$plugin  = esc_html( $_GET['plugin'] );
		$plugins = $this->get_conflicting_plugins();
		$plugins = array_flip( $plugins );

		if ( empty( $plugins[ $plugin ] ) ) {
			imagify_die();
		}

		deactivate_plugins( $plugin );

		imagify_maybe_redirect();
		wp_send_json_success();
	}


	/** ----------------------------------------------------------------------------------------- */
	/** NOTICES ================================================================================= */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Tell if the 'welcome-steps' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool
	 */
	public function display_welcome_steps() {
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		$display = false;

		if ( ! $this->user_can( 'welcome-steps' ) ) {
			return $display;
		}

		if ( imagify_is_screen( 'imagify-settings' ) ) {
			return $display;
		}

		if ( self::notice_is_dismissed( 'welcome-steps' ) || get_imagify_option( 'api_key' ) ) {
			return $display;
		}

		$display = true;
		return $display;
	}

	/**
	 * Tell if the 'wrong-api-key' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool
	 */
	public function display_wrong_api_key() {
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		$display = false;

		if ( ! $this->user_can( 'wrong-api-key' ) ) {
			return $display;
		}

		if ( ! imagify_is_screen( 'bulk' ) ) {
			return $display;
		}

		if ( self::notice_is_dismissed( 'wrong-api-key' ) || ! get_imagify_option( 'api_key' ) || imagify_valid_key() ) {
			return $display;
		}

		$display = true;
		return $display;
	}

	/**
	 * Tell if the 'plugins-to-deactivate' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return array An array of plugins to deactivate.
	 */
	public function display_plugins_to_deactivate() {
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		if ( ! $this->user_can( 'plugins-to-deactivate' ) ) {
			$display = false;
			return $display;
		}

		$display = $this->get_conflicting_plugins();
		return $display;
	}

	/**
	 * Tell if the 'plugins-to-deactivate' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool
	 */
	public function display_http_block_external() {
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		$display = false;

		if ( ! $this->user_can( 'http-block-external' ) ) {
			return $display;
		}

		if ( imagify_is_screen( 'imagify-settings' ) ) {
			return $display;
		}

		if ( self::notice_is_dismissed( 'http-block-external' ) || ! is_imagify_blocked() ) {
			return $display;
		}

		$display = true;
		return $display;
	}

	/**
	 * Tell if the 'grid-view' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool
	 */
	public function display_grid_view() {
		global $wp_version;
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		$display = false;

		if ( ! $this->user_can( 'grid-view' ) ) {
			return $display;
		}

		if ( ! imagify_is_screen( 'library' ) ) {
			return $display;
		}

		$media_library_mode = get_user_option( 'media_library_mode', get_current_user_id() );

		if ( 'list' === $media_library_mode || self::notice_is_dismissed( 'grid-view' ) || version_compare( $wp_version, '4.0' ) < 0 ) {
			return $display;
		}

		// Don't display the notice if the API key isn't valid.
		if ( ! imagify_valid_key() ) {
			return $display;
		}

		$display = true;
		return $display;
	}

	/**
	 * Tell if the 'over-quota' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool|object An Imagify user object. False otherwise.
	 */
	public function display_free_over_quota() {
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		$display = false;

		if ( ! $this->user_can( 'free-over-quota' ) ) {
			return $display;
		}

		if ( ! imagify_is_screen( 'imagify-settings' ) && ! imagify_is_screen( 'bulk' ) ) {
			return $display;
		}

		if ( self::notice_is_dismissed( 'free-over-quota' ) ) {
			return $display;
		}

		$user = new Imagify_User();

		// Don't display the notice if the user doesn't use all his quota or the API key isn't valid.
		if ( ! $user->is_over_quota() || ! imagify_valid_key() ) {
			return $display;
		}

		$display = $user;
		return $display;
	}

	/**
	 * Tell if the 'backup-folder-not-writable' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool
	 */
	public function display_backup_folder_not_writable() {
		global $post_id;
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		$display = false;

		if ( ! $this->user_can( 'backup-folder-not-writable' ) ) {
			return $display;
		}

		// Every places where images can be optimized, automatically or not (+ the settings page).
		if ( ! imagify_is_screen( 'imagify-settings' ) && ! imagify_is_screen( 'library' ) && ! imagify_is_screen( 'upload' ) && ! imagify_is_screen( 'bulk' ) && ! imagify_is_screen( 'media-modal' ) ) {
			return $display;
		}

		if ( ! get_imagify_option( 'backup' ) ) {
			return $display;
		}

		if ( imagify_backup_dir_is_writable() ) {
			return $display;
		}

		$display = true;
		return $display;
	}

	/**
	 * Tell if the 'rating' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool|int
	 */
	public function display_rating() {
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		$display = false;

		if ( ! $this->user_can( 'rating' ) ) {
			return $display;
		}

		if ( ! imagify_is_screen( 'bulk' ) && ! imagify_is_screen( 'library' ) && ! imagify_is_screen( 'upload' ) ) {
			return $display;
		}

		if ( self::notice_is_dismissed( 'rating' ) ) {
			return $display;
		}

		$user_images_count = (int) get_site_transient( 'imagify_user_images_count' );

		if ( ! $user_images_count || get_site_transient( 'imagify_seen_rating_notice' ) ) {
			return $display;
		}

		$display = $user_images_count;
		return $display;
	}

	/**
	 * Tell if the 'wp-rocket' notice should be displayed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool
	 */
	public function display_wp_rocket() {
		static $display;

		if ( isset( $display ) ) {
			return $display;
		}

		$display = false;

		if ( ! $this->user_can( 'wp-rocket' ) ) {
			return $display;
		}

		if ( ! imagify_is_screen( 'bulk' ) ) {
			return $display;
		}

		if ( defined( 'WP_ROCKET_VERSION' ) || self::notice_is_dismissed( 'wp-rocket' ) ) {
			return $display;
		}

		$display = true;
		return $display;
	}


	/** ----------------------------------------------------------------------------------------- */
	/** PUBLIC TOOLS ============================================================================ */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Renew a dismissed Imagify notice.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 * @see    imagify_renew_notice()
	 *
	 * @param  string $notice  A notice ID.
	 * @param  int    $user_id A user ID.
	 */
	public static function renew_notice( $notice, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$notices = get_user_meta( $user_id, self::DISMISS_META_NAME, true );
		$notices = $notices && is_array( $notices ) ? array_flip( $notices ) : array();

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		unset( $notices[ $notice ] );
		$notices = array_flip( $notices );
		$notices = array_filter( $notices );
		$notices = array_values( $notices );

		update_user_meta( $user_id, self::DISMISS_META_NAME, $notices );
	}

	/**
	 * Dismiss an Imagify notice.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 * @see    imagify_dismiss_notice()
	 *
	 * @param  string $notice  A notice ID.
	 * @param  int    $user_id A user ID.
	 */
	public static function dismiss_notice( $notice, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$notices = get_user_meta( $user_id, self::DISMISS_META_NAME, true );
		$notices = $notices && is_array( $notices ) ? array_flip( $notices ) : array();

		if ( isset( $notices[ $notice ] ) ) {
			return;
		}

		$notices   = array_flip( $notices );
		$notices[] = $notice;
		$notices   = array_filter( $notices );
		$notices   = array_values( $notices );

		update_user_meta( $user_id, self::DISMISS_META_NAME, $notices );
	}

	/**
	 * Tell if an Imagify notice is dismissed.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 * @see    imagify_notice_is_dismissed()
	 *
	 * @param  string $notice  A notice ID.
	 * @param  int    $user_id A user ID.
	 * @return bool
	 */
	public static function notice_is_dismissed( $notice, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$notices = get_user_meta( $user_id, self::DISMISS_META_NAME, true );
		$notices = $notices && is_array( $notices ) ? array_flip( $notices ) : array();

		return isset( $notices[ $notice ] );
	}

	/**
	 * Tell if one or more notices will be displayed later in the page.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return bool
	 */
	public function has_notices() {
		foreach ( self::$notice_ids as $notice_id ) {
			$callback = 'display_' . str_replace( '-', '_', $notice_id );

			if ( method_exists( $this, $callback ) && call_user_func( array( $this, $callback ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Include the view file.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @param string $view The view ID.
	 * @param mixed  $data Some data to pass to the view.
	 */
	public function render_view( $view, $data = array() ) {
		require self::$views_folder . 'notice-' . $view . '.php';
	}


	/** ----------------------------------------------------------------------------------------- */
	/** INTERNAL TOOLS ========================================================================== */
	/** ----------------------------------------------------------------------------------------- */

	/**
	 * Get all notice IDs.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 */
	protected function get_notice_ids() {
		/**
		 * Filter the notices Imagify can display.
		 *
		 * @since  1.6.10
		 * @author Grégory Viguier
		 *
		 * @param array $notice_ids An array of notice "IDs".
		 */
		return apply_filters( 'imagify_notices', self::$notice_ids );
	}

	/**
	 * Tell if the current user can see the notices.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @param  string $notice_id A notice ID.
	 * @return bool
	 */
	protected function user_can( $notice_id ) {
		static $user_can;

		if ( ! isset( $user_can ) ) {
			$user_can = array(
				'network' => current_user_can( imagify_get_capacity() ),
				'admin'   => current_user_can( imagify_get_capacity( true ) ),
				'upload'  => current_user_can( 'upload_files' ),
			);
		}

		$capability = isset( self::$capabilities[ $notice_id ] ) ? self::$capabilities[ $notice_id ] : 'network';

		return isset( $user_can[ $capability ] ) ? $user_can[ $capability ] : $user_can['network'];
	}

	/**
	 * Get a list of plugins that can conflict with Imagify.
	 *
	 * @since  1.6.10
	 * @author Grégory Viguier
	 *
	 * @return array
	 */
	protected function get_conflicting_plugins() {
		/**
		 * Filter the recommended plugins to deactivate to prevent conflicts.
		 *
		 * @since 1.0
		 *
		 * @param string $plugins List of recommended plugins to deactivate.
		*/
		$plugins = apply_filters( 'imagify_plugins_to_deactivate', self::$conflicting_plugins );

		return array_filter( $plugins, 'is_plugin_active' );
	}
}
