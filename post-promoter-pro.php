<?php
/*
Plugin Name: Post Promoter Pro
Plugin URI: http://postpromoterpro.com
Description: Schedule the promotion of blog posts for the next 6 days, with no further work.
Version: 0.2.2
Author: Post Promoter Pro
Author URI: http://postpromoterpro.com
License: GPLv2
*/

define( 'PPP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PPP_VERSION', '0.2.2' );
define( 'PPP_FILE', plugin_basename( __FILE__ ) );
define( 'PPP_URL', plugins_url( '/', PPP_FILE ) );

define( 'PPP_STORE_URL', 'https://postpromoterpro.com' );
define( 'PPP_PLUGIN_NAME', 'Post Promoter Pro' );
if( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
	// load our custom updater
	include( PPP_PATH . '/includes/EDD_SL_Plugin_Updater.php' );
}

class PostPromoterPro {
	private static $ppp_instance;

	private function __construct() {
		register_activation_hook( PPP_FILE, array( $this, 'activation_setup' ) );

		global $ppp_options, $ppp_social_settings, $ppp_share_settings;
		$ppp_options         = get_option( 'ppp_options' );
		$ppp_social_settings = get_option( 'ppp_social_settings' );
		$ppp_share_settings  = get_option( 'ppp_share_settings' );

		include PPP_PATH . '/includes/general-functions.php';
		include PPP_PATH . '/includes/share-functions.php';
		include PPP_PATH . '/includes/cron-functions.php';
		include PPP_PATH . '/includes/libs/social-loader.php';

		if ( is_admin() ) {
			include PPP_PATH . '/includes/admin/admin-pages.php';
			include PPP_PATH . '/includes/admin/meta-boxes.php';

			add_action( 'admin_init', array( $this, 'ppp_register_settings' ) );

			// Handle licenses
			add_action( 'admin_init', array( $this, 'plugin_updater' ) );
			add_action( 'admin_init', array( $this, 'activate_license' ) );
			add_action( 'admin_init', array( $this, 'deactivate_license' ) );

			add_action( 'admin_menu', array( $this, 'ppp_setup_admin_menu' ), 1000, 0 );
			add_filter( 'plugin_action_links', array( $this, 'plugin_settings_links' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'load_custom_scripts' ), 99 );
			add_action( 'admin_enqueue_scripts', array( $this, 'load_styles' ) );
			add_action( 'trash_post', 'ppp_remove_scheduled_shares', 10, 1 );
		}

		add_action( 'save_post', 'ppp_schedule_share', 10, 2);
	}

	/**
	 * Get the singleton instance of our plugin
	 * @return class The Instance
	 * @access public
	 */
	public static function getInstance() {
		if ( !self::$ppp_instance ) {
			self::$ppp_instance = new PostPromoterPro();
		}

		return self::$ppp_instance;
	}

	public function activation_setup() {
		$default_settings['post_types']['post'] = '1';
		$default_settings['times']['day1']      = '08:00';
		$default_settings['times']['day2']      = '10:00';
		$default_settings['times']['day3']      = '12:00';
		$default_settings['times']['day4']      = '16:00';
		$default_settings['times']['day5']      = '10:30';
		$default_settings['times']['day6']      = '20:00';


		update_option( 'ppp_options', $default_settings );
	}

	/**
	 * Queue up the JavaScript file for the admin page, only on our admin page
	 * @param  string $hook The current page in the admin
	 * @return void
	 * @access public
	 */
	public function load_custom_scripts( $hook ) {
		if ( 'toplevel_page_ppp-options' != $hook
			  && 'post-promoter_page_ppp-social-settings' != $hook
			  && 'post-new.php' != $hook
			  && 'post.php' != $hook )
			return;

		wp_enqueue_script( 'jquery-ui-core' );
		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_script( 'jquery-ui-slider' );
		wp_enqueue_script( 'ppp_timepicker_js', PPP_URL . '/includes/scripts/libs/jquery-ui-timepicker-addon.js', array( 'jquery', 'jquery-ui-core' ), PPP_VERSION, true );
		wp_enqueue_script( 'ppp_core_custom_js', PPP_URL.'/includes/scripts/js/ppp_custom.js', 'jquery', PPP_VERSION, true );
	}

	public function load_styles() {
		wp_register_style( 'ppp_admin_css', PPP_URL . '/includes/scripts/css/admin-style.css', false, PPP_VERSION );
		wp_enqueue_style( 'ppp_admin_css' );
	}

	/**
	 * Adds the Settings and Post Promoter Pro Link to the Settings page list
	 * @param  array $links The current list of links
	 * @param  string $file The plugin file
	 * @return array        The new list of links, with our additional ones added
	 * @access public
	 */
	public function plugin_settings_links( $links, $file ) {
		if ( $file != PPP_FILE ) {
			return $links;
		}

		$settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=ppp-options' ), __( 'Settings', 'ppp-txt' ) );

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the Pushover Notifications item to the Settings menu
	 * @return void
	 * @access public
	 */
	public function ppp_setup_admin_menu() {
		add_menu_page( __( 'Post Promoter', 'ppp-txt' ),
		               __( 'Post Promoter', 'ppp-txt' ),
		               'manage_options',
		               'ppp-options',
		               'ppp_admin_page'
		             );

		add_submenu_page( 'ppp-options', __( 'Social Settings', 'ppp-txt' ), __( 'Social Settings', 'ppp-txt' ), 'manage_options', 'ppp-social-settings', 'ppp_display_social' );
		add_submenu_page( 'ppp-options', __( 'Schedule', 'ppp-txt' ), __( 'Schedule', 'ppp-txt' ), 'manage_options', 'ppp-schedule-info', 'ppp_display_schedule' );
		add_submenu_page( 'ppp-options', __( 'System Info', 'ppp-txt' ), __( 'System Info', 'ppp-txt' ), 'manage_options', 'ppp-system-info', 'ppp_display_sysinfo' );
	}

	/**
	 * Register/Whitelist our settings on the settings page, allow extensions and other plugins to hook into this
	 * @return void
	 * @access public
	 */
	public function ppp_register_settings() {
		register_setting( 'ppp-options', 'ppp_options' );
		register_setting( 'ppp-options', '_ppp_license_key', array( $this, 'ppp_sanitize_license' ) );

		register_setting( 'ppp-social-settings', 'ppp_social_settings' );
		register_setting( 'ppp-share-settings', 'ppp_share_settings' );
		do_action( 'ppp_register_additional_settings' );
	}

	/**
	 * Load the Text Domain for i18n
	 * @return void
	 * @access public
	 */
	public function ppp_loaddomain() {
		load_plugin_textdomain( 'ppp-txt', false, '/post-promoter-pro/languages/' );
	}


	/**
	 * Setup the plugin updater
	 */

	public function plugin_updater() {
		$license_key = trim( get_option( '_ppp_license_key' ) );

		if ( empty( $license_key ) ) {
			add_action( 'admin_notices', array( $this, 'no_license_nag' ) );
			return;
		}

		// setup the updater
		$edd_updater = new EDD_SL_Plugin_Updater( PPP_STORE_URL, __FILE__, array(
				'version'   => PPP_VERSION,         // current version number
				'license'   => $license_key,        // license key (used get_option above to retrieve from DB)
				'item_name' => PPP_PLUGIN_NAME,     // name of this plugin
				'author'    => 'Post Promoter Pro'  // author of this plugin
			)
		);
	}

	public function no_license_nag() {
		?>
		<div class="updated">
			<p><?php printf( __( 'Post Promoter Pro requires your license key to work, pelase <a href="%s">enter it now</a>.', 'ppp-text' ), admin_url( 'admin.php?page=ppp-options' ) ); ?></p>
		</div>
		<?php
	}

	public function deactivate_license() {
		// listen for our activate button to be clicked
		if( isset( $_POST['ppp_license_deactivate'] ) ) {

			// run a quick security check
			if( ! check_admin_referer( 'ppp_deactivate_nonce', 'ppp_deactivate_nonce' ) )
				return; // get out if we didn't click the Activate button

			// retrieve the license from the database
			$license = trim( get_option( '_ppp_license_key' ) );


			// data to send in our API request
			$api_params = array(
				'edd_action'=> 'deactivate_license',
				'license' 	=> $license,
				'item_name' => urlencode( PPP_PLUGIN_NAME ) // the name of our product in EDD
			);

			// Call the custom API.
			$response = wp_remote_get( add_query_arg( $api_params, PPP_STORE_URL ), array( 'timeout' => 15, 'sslverify' => false ) );

			// make sure the response came back okay
			if ( is_wp_error( $response ) )
				return false;

			// decode the license data
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			// $license_data->license will be either "deactivated" or "failed"
			if( $license_data->license == 'deactivated' )
				delete_option( '_ppp_license_key_status' );

		}
	}

	public function activate_license() {
		// listen for our activate button to be clicked
		if( isset( $_POST['ppp_license_activate'] ) ) {

			// run a quick security check
		 	if( ! check_admin_referer( 'ppp_activate_nonce', 'ppp_activate_nonce' ) )
				return; // get out if we didn't click the Activate button

			// retrieve the license from the database
			$license = trim( get_option( '_ppp_license_key' ) );


			// data to send in our API request
			$api_params = array(
				'edd_action'=> 'activate_license',
				'license' 	=> $license,
				'item_name' => urlencode( PPP_PLUGIN_NAME ) // the name of our product in EDD
			);

			// Call the custom API.
			$response = wp_remote_get( add_query_arg( $api_params, PPP_STORE_URL ), array( 'timeout' => 15, 'sslverify' => false ) );

			// make sure the response came back okay
			if ( is_wp_error( $response ) )
				return false;

			// decode the license data
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			// $license_data->license will be either "active" or "inactive"

			update_option( '_ppp_license_key_status', $license_data->license );

		}
	}

	public function ppp_sanitize_license( $new ) {
		$old = get_option( '_ppp_license_key' );
		if( $old && $old != $new ) {
			delete_option( '_ppp_license_key_status' ); // new license has been entered, so must reactivate
		}
		return $new;
	}
}

$ppp_loaded = PostPromoterPro::getInstance();
