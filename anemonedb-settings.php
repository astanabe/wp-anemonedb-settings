<?php
/**
 * Plugin Name:       ANEMONE DB Settings
 * Plugin URI:        https://github.com/astanabe/anemonedb-settings
 * Description:       ANEMONE DB Settings Plugin for WordPress
 * Author:            Akifumi S. Tanabe
 * Author URI:        https://github.com/astanabe
 * License:           GNU General Public License v2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       anemonedb-settings
 * Domain Path:       /languages
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires Plugins:  buddypress, bp-classic, two-factor, tinymce-advanced, leaflet-map, extensions-leaflet-map, page-list
 *
 * @package           Anemonedb_Settings
 */

// Security check
if (!defined('ABSPATH')) {
	exit;
}

// Activation hook
function anemonedb_settings_activate() {
	anemonedb_check_login_failure_log();
	anemonedb_change_frontpage_to_home();
	anemonedb_create_dd_users_table();
	anemonedb_create_email_tables();
	anemonedb_post_types_init();
	anemonedb_taxonomies_init();
	flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'anemonedb_settings_activate');

// Deactivation hook
function anemonedb_settings_deactivate() {
	anemonedb_delete_dd_users_table();
	anemonedb_delete_email_tables();
	flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'anemonedb_settings_deactivate');

// Function to display admin notice
function anemonedb_display_admin_notice($message, $type = 'success') {
    wp_admin_notice(
        $message,
        array(
            'type'    => $type, // 'success', 'error', 'warning', 'info'
            'dismiss' => true,
        )
    );
}

// Check login failure log file
function anemonedb_check_login_failure_log() {
	$authlog = "/var/log/wp_auth_failure.log";
	if (!file_exists($authlog)) {
		if (!touch($authlog)) {
			anemonedb_display_admin_notice("Failed to create log file {$authlog}. Please create this file and ensure the correct permissions are set.", 'error');
			return;
		}
		chmod($authlog, 0644);
	}
	if (!is_writable($authlog)) {
		anemonedb_display_admin_notice("Log file {$authlog} is not writable. Please set the correct permissions (e.g., 644).", 'error');
		return;
	}
}

// Change frontpage to "home"
function anemonedb_change_frontpage_to_home() {
	$page_id = anemonedb_create_page_if_not_exists('Home', 'home');
	if ($page_id) {
		update_option('page_on_front', $page_id);
		update_option('show_on_front', 'page');
	}
	anemonedb_create_page_if_not_exists('Loggedin Home', 'loggedin-home');
}

// Create empty page if not exists and return page ID
function anemonedb_create_page_if_not_exists($page_title, $page_slug) {
	$existing_page = get_page_by_path($page_slug);
	if ($existing_page) {
		return $existing_page->ID;
	}
	else {
		$page_id = wp_insert_post([
			'post_title'   => $page_title,
			'post_name'    => $page_slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
		]);
		return $page_id;
	}
}

// Redirection after user activation
function anemonedb_redirect_after_user_activation($user_id) {
	wp_safe_redirect(wp_login_url()); // redirect to login page
	exit;
}
add_action('register_new_user', 'anemonedb_redirect_after_user_activation');

// Redirection after login
function anemonedb_redirect_after_login($redirect_to, $requested_redirect_to, $user) {
	if (isset($user->roles) && is_array($user->roles)) {
		return home_url(); // redirect to frontpage
	}
	return $redirect_to;
}
add_filter('login_redirect', 'anemonedb_redirect_after_login', 10, 3);

// Redirection after logout
function anemonedb_redirect_after_logout() {
	wp_safe_redirect(home_url()); // redirect to home_url
	exit;
}
add_action('wp_logout', 'anemonedb_redirect_after_logout');

// Override frontpage for loggedin users
function anemonedb_override_frontpage_for_loggedin_users($content) {
	if (is_front_page() && !is_admin() && is_user_logged_in()) {
		$loggedin_home = get_page_by_path('loggedin-home');
		if ($loggedin_home) {
			remove_filter('the_content', 'anemonedb_override_frontpage_for_loggedin_users');
			$newcontent = apply_filters('the_content', $loggedin_home->post_content);
			add_filter('the_content', 'anemonedb_override_frontpage_for_loggedin_users');
			return $newcontent;
		}
	}
	return $content;
}
add_filter('the_content', 'anemonedb_override_frontpage_for_loggedin_users');

// Check user's Name field length in registration
function anemonedb_namelength_validation() {
	if ( strlen( $_POST['signup_username'] ) > 50 ) {
		global $bp;
		$bp->signup->errors['signup_username'] = __( 'ERROR!: Your Name is too long.', 'anemonedb-settings' );
	}
}
add_action( 'bp_signup_validate', 'anemonedb_namelength_validation' );

// Set user default role to subscriber
function anemonedb_set_default_role( $user_id ) {
	$user = new WP_User( $user_id );
	$user->add_role( 'subscriber' );
}
add_action( 'bp_core_activated_user', 'anemonedb_set_default_role' );

// Disable send private message button
function anemonedb_remove_send_message_button() {
	return false;
}
add_filter( 'bp_get_send_message_button_args', 'anemonedb_remove_send_message_button' );

// Disable adminbar except for admins and editors
function anemonedb_remove_admin_bar() {
	if (!current_user_can('edit_posts')) {
		show_admin_bar(false);
	}
}
add_action( 'after_setup_theme' , 'anemonedb_remove_admin_bar' );

// Disable dashboard except for admins and editors
function anemonedb_restrict_dashboard_access() {
	if (is_admin() && !current_user_can('edit_posts') && !(defined('DOING_AJAX') && DOING_AJAX)) {
		wp_safe_redirect(home_url());
		exit;
	}
}
add_action('admin_init', 'anemonedb_restrict_dashboard_access');

// Disable login language menu
function anemonedb_remove_login_language_menu() {
	return false;
}
add_filter( 'login_display_language_dropdown', 'anemonedb_remove_login_language_menu' );

// Disable emoji
function anemonedb_disable_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
}
add_action( 'init', 'anemonedb_disable_emoji' );

// Add shortcode of login state
function anemonedb_login_state_shortcode( $atts, $content = null ) {
	if ( is_user_logged_in() ) {
		$content = do_shortcode( shortcode_unautop( $content ) );
		return $content;
	} else {
		return '';
	}
}
add_shortcode( 'if-login', 'anemonedb_login_state_shortcode' );
function anemonedb_logout_state_shortcode( $atts, $content = null ) {
	if ( !is_user_logged_in() ) {
		$content = do_shortcode( shortcode_unautop( $content ) );
		return $content;
	} else {
		return '';
	}
}
add_shortcode( 'if-logout', 'anemonedb_logout_state_shortcode' );

// Add shortcode of user login ID
function anemonedb_login_user_shortcode( $atts, $content = null ) {
	$atts = shortcode_atts( array( 'is' => 1 ), $atts, 'if-user' );
	if ( get_current_user_id() == $atts['is'] ) {
		$content = do_shortcode( shortcode_unautop( $content ) );
		return $content;
	} else {
		return '';
	}
}
add_shortcode( 'if-user', 'anemonedb_login_user_shortcode' );

// Add shortcode of inserting search form
function anemonedb_search_form_shortcode( ) {
	ob_start();
	get_search_form( );
	return ob_get_clean();
}
add_shortcode( 'search-form', 'anemonedb_search_form_shortcode' );

// Function to obtain client IP
function anemonedb_get_client_ip() {
	if (!empty($_SERVER['CF-Connecting-IP']) && filter_var($_SERVER['CF-Connecting-IP'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
		return sanitize_text_field($_SERVER['CF-Connecting-IP']);
	}
	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
		foreach ($ip_list as $ip) {
			$ip = trim($ip);
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				return sanitize_text_field($ip);
			}
		}
	}
	if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
		return sanitize_text_field($_SERVER['REMOTE_ADDR']);
	}
	return 'UNKNOWN';
}

// Login failure logging
function anemonedb_login_failure_log($intruder) {
	$authlog = "/var/log/wp_auth_failure.log";
	$msg = date('[Y-m-d H:i:s T]') . " login failure from " . anemonedb_get_client_ip() . " for $intruder\n";
	$log_append = fopen($authlog, "a");
	if ($log_append) {
		flock($log_append, LOCK_EX);
		fwrite($log_append, $msg);
		fflush($log_append);
		flock($log_append, LOCK_UN);
		fclose($log_append);
	}
}
add_action('wp_login_failed', 'anemonedb_login_failure_log');

// Enforce two-factor authentication
function anemonedb_enforce_two_factor( $enabled, $user_id ) {
	if ( count( $enabled ) ) {
		return $enabled;
	}
	return [ 'Two_Factor_Email' ];
}
add_filter( 'two_factor_enabled_providers_for_user', 'anemonedb_enforce_two_factor', 10, 2 );

// Enforce plain text email
function anemonedb_plain_text_email() {
	return 'text/plain';
}
add_filter( 'wp_mail_content_type', 'anemonedb_plain_text_email' );

// Disable "Export Data" page
function anemonedb_remove_export_data() {
	return false;
}
add_filter( 'bp_settings_show_user_data_page', 'anemonedb_remove_export_data' );

// Disable "Profile Visibility" and "Email" pages
function anemonedb_remove_subnav_item() {
	bp_core_remove_subnav_item( 'settings', 'profile' );
	bp_core_remove_subnav_item( 'settings', 'notifications' );
}
add_action( 'bp_setup_nav', 'anemonedb_remove_subnav_item', 999 );

// Disable adminbar submenu of "Profile Visibility" and "Email"
function anemonedb_remove_submenu_from_adminbar_settings() {
	if (is_admin_bar_showing() && function_exists('buddypress')) {
		global $wp_admin_bar;
		$wp_admin_bar->remove_menu('my-account-settings-profile');
		$wp_admin_bar->remove_menu('my-account-settings-notifications');
	}
}
add_action('admin_bar_menu', 'anemonedb_remove_submenu_from_adminbar_settings', 999);

// Create table
function anemonedb_create_dd_users_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'anemonedb_dd_users';
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		user_login VARCHAR(60) NOT NULL,
		dd_pass VARCHAR(255) NOT NULL,
		dd_pass_expiry BIGINT NOT NULL,
		PRIMARY KEY (user_login)
	) $charset_collate;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

// Delete table
function anemonedb_delete_dd_users_table() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'anemonedb_dd_users';
	$wpdb->query("DROP TABLE IF EXISTS $table_name");
}

// Add Data Download settings tab (subnav) to BuddyPress user settings
function anemonedb_add_data_download() {
	bp_core_new_subnav_item(array(
		'name'            => 'Data Download',
		'slug'            => 'data-download',
		'parent_slug'     => 'settings',
		'parent_url'      => trailingslashit(bp_loggedin_user_domain() . 'settings'),
		'screen_function' => 'anemonedb_data_download_screen',
		'position'        => 50,
		'user_has_access' => bp_is_my_profile(),
	));
}
add_action('bp_setup_nav', 'anemonedb_add_data_download', 10);

// Add Data Download submenu to adminbar "Settings" menu
function anemonedb_add_submenu_to_adminbar_settings() {
	if (is_admin_bar_showing() && function_exists('buddypress')) {
		global $wp_admin_bar;
		$wp_admin_bar->add_menu(array(
			'parent' => 'my-account-settings',
			'id'     => 'my-account-settings-data-download',
			'title'  => 'Data Download',
			'href'   => bp_loggedin_user_domain() . 'settings/data-download/',
		));
	}
}
add_action('wp_before_admin_bar_render', 'anemonedb_add_submenu_to_adminbar_settings', 999);

// Screen function for Data Download settings page
function anemonedb_data_download_screen() {
	add_action('bp_template_content', 'anemonedb_display_dd_pass_section');
	bp_core_load_template('members/single/plugins');
}

// Display data download password section
function anemonedb_display_dd_pass_section() {
	if (!is_user_logged_in()) {
		return;
	}
	global $wpdb;
	$user_id = get_current_user_id();
	$user_info = get_userdata($user_id);
	$table_name = $wpdb->prefix . 'anemonedb_dd_users';
	$data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE user_login = %s", $user_info->user_login));
	echo '<h2 class="screen-heading general-settings-screen">Data Download Password Generator</h2>';
	if ($data && $data->dd_pass_expiry > time()) {
		echo '<p class="info">Your data download password will be expired at ' . date('Y-m-d H:i T', $data->dd_pass_expiry) . '.</p>';
		echo '<div class="info bp-feedback"><span class="bp-icon" aria-hidden="true"></span><p class="text">Click on the &quot;Regenerate Data Download Password&quot; button to regenerate and renew your temporary password for data download. This password is required to login to data file distribution area and is valid for 10 days. After 10 days, this password will be expired. <strong>The regenerated password will be shown only once.</strong> If you lost this password, you can regenerate password again and again.</p></div>';
		echo '<form method="post" class="standard-form" id="your-profile">';
		wp_nonce_field('anemonedb_generate_dd_pass', 'anemonedb_generate_dd_pass_nonce');
		echo '<div class="wp-pwd"><button type="submit" name="generate_dd_pass" class="button">Regenerate Data Download Password</button></div>';
		echo '</form>';
	} else {
		echo '<p class="info">Generate your temporary password for data download if you want to access to the data file distribution area.</p>';
		echo '<div class="info bp-feedback"><span class="bp-icon" aria-hidden="true"></span><p class="text">Click on the &quot;Generate Data Download Password&quot; button to generate your temporary password for data download. This password is required to login to data file distribution area and is valid for 10 days. After 10 days, this password will be expired. <strong>The generated password will be shown only once.</strong> If you lost this password, you can regenerate password.</p></div>';
		echo '<form method="post" class="standard-form" id="your-profile">';
		wp_nonce_field('anemonedb_generate_dd_pass', 'anemonedb_generate_dd_pass_nonce');
		echo '<div class="wp-pwd"><button type="submit" name="generate_dd_pass" class="button">Generate Data Download Password</button></div>';
		echo '</form>';
	}
}

// Generate and save data download password
function anemonedb_save_dd_pass() {
	if (!is_user_logged_in() || !isset($_POST['generate_dd_pass'])) {
		return;
	}
	if (!isset($_POST['anemonedb_generate_dd_pass_nonce']) || !wp_verify_nonce($_POST['anemonedb_generate_dd_pass_nonce'], 'anemonedb_generate_dd_pass')) {
		wp_die('Security check failed.');
	}
	global $wpdb;
	$user_id = get_current_user_id();
	$user_info = get_userdata($user_id);
	$table_name = $wpdb->prefix . 'anemonedb_dd_users';
	if (isset($_POST['generate_dd_pass'])) {
		$password = wp_generate_password(12, false, false);
		$hashed_password = wp_hash_password($password);
		$expiry_time = time() + (10 * 86400);
		$wpdb->replace(
			$table_name,
			[
				'user_login' => $user_info->user_login,
				'dd_pass' => $hashed_password,
				'dd_pass_expiry' => $expiry_time
			],
			['%s', '%s', '%d']
		);
		echo '<h2 class="screen-heading general-settings-screen">Your Data Download Password</h2>';
		echo '<p class="info">Your temporary data download password is the following.</p>';
		echo '<div class="wp-pwd"><input type="text" name="dd_pass" id="dd_pass" size="24" value="' . esc_attr($password) . '" class="settings-input" readonly data-clipboard-target="#dd_pass"><button type="button" id="copy_button" class="button" style="margin-left: 10px;" data-clipboard-target="#dd_pass">Copy</button><span id="copy_tooltip" style="display: none; margin-left: 10px; color: green;">Copied!</span></div>';
		echo '<p class="info">This data download password will be expired at ' . date('Y-m-d H:i T', $expiry_time) . '.</p>';
		echo '<div class="info bp-feedback"><span class="bp-icon" aria-hidden="true"></span><p class="text">Note that this password will not be displayed again. If you lost this password, please regenerate it.</p></div>';
		echo '<script src="' . includes_url('js/clipboard.min.js') . '"></script>';
		echo '<script>
			document.addEventListener("DOMContentLoaded", function() {
				var clipboard = new ClipboardJS("#copy_button, #dd_pass");
				clipboard.on("success", function(e) {
					var tooltip = document.getElementById("copy_tooltip");
					tooltip.style.display = "inline";
					setTimeout(function() {
						tooltip.style.transition = "opacity 1s"; tooltip.style.opacity = "0";
						setTimeout(function() {
							tooltip.style.display = "none";
							tooltip.style.opacity = "1";
						}, 1000);
					}, 3000);
					e.clearSelection();
				});
			});
		</script>';
	}
}
add_action('bp_template_content', 'anemonedb_save_dd_pass', 1);

// Schedule data download password cleanup
function anemonedb_schedule_dd_pass_cleanup() {
	if (!wp_next_scheduled('anemonedb_dd_pass_cleanup')) {
		wp_schedule_event(time(), 'anemonedb_every_ten_minutes', 'anemonedb_dd_pass_cleanup');
	}
}
add_action('wp', 'anemonedb_schedule_dd_pass_cleanup');

// Cleanup expired data download password
function anemonedb_cleanup_expired_dd_pass() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'anemonedb_dd_users';
	$current_time = time();
	$wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE dd_pass_expiry <= %d", $current_time));
}
add_action('anemonedb_dd_pass_cleanup', 'anemonedb_cleanup_expired_dd_pass');

// Register custom post types
function anemonedb_post_types_init() {
	register_post_type(
		'sample',
		[
			'labels' => [
				'name' => esc_html__( 'Samples', 'anemonedb-settings' ),
				'singular_name' => esc_html__( 'Sample', 'anemonedb-settings' ),
				'menu_name' => esc_html__( 'Samples', 'anemonedb-settings' ),
				'all_items' => esc_html__( 'All Samples', 'anemonedb-settings' ),
				'add_new' => esc_html__( 'Add new', 'anemonedb-settings' ),
				'add_new_item' => esc_html__( 'Add new Sample', 'anemonedb-settings' ),
				'edit_item' => esc_html__( 'Edit Sample', 'anemonedb-settings' ),
				'new_item' => esc_html__( 'New Sample', 'anemonedb-settings' ),
				'view_item' => esc_html__( 'View Sample', 'anemonedb-settings' ),
				'view_items' => esc_html__( 'View Samples', 'anemonedb-settings' ),
				'search_items' => esc_html__( 'Search Samples', 'anemonedb-settings' ),
				'not_found' => esc_html__( 'No Samples found', 'anemonedb-settings' ),
				'not_found_in_trash' => esc_html__( 'No Samples found in trash', 'anemonedb-settings' ),
				'parent' => esc_html__( 'Parent Sample:', 'anemonedb-settings' ),
				'featured_image' => esc_html__( 'Featured image for this Sample', 'anemonedb-settings' ),
				'set_featured_image' => esc_html__( 'Set featured image for this Sample', 'anemonedb-settings' ),
				'remove_featured_image' => esc_html__( 'Remove featured image for this Sample', 'anemonedb-settings' ),
				'use_featured_image' => esc_html__( 'Use as featured image for this Sample', 'anemonedb-settings' ),
				'archives' => esc_html__( 'Sample archives', 'anemonedb-settings' ),
				'insert_into_item' => esc_html__( 'Insert into Sample', 'anemonedb-settings' ),
				'uploaded_to_this_item' => esc_html__( 'Upload to this Sample', 'anemonedb-settings' ),
				'filter_items_list' => esc_html__( 'Filter Samples list', 'anemonedb-settings' ),
				'items_list_navigation' => esc_html__( 'Samples list navigation', 'anemonedb-settings' ),
				'items_list' => esc_html__( 'Samples list', 'anemonedb-settings' ),
				'attributes' => esc_html__( 'Samples attributes', 'anemonedb-settings' ),
				'name_admin_bar' => esc_html__( 'Sample', 'anemonedb-settings' ),
				'item_published' => esc_html__( 'Sample published', 'anemonedb-settings' ),
				'item_published_privately' => esc_html__( 'Sample published privately.', 'anemonedb-settings' ),
				'item_reverted_to_draft' => esc_html__( 'Sample reverted to draft.', 'anemonedb-settings' ),
				'item_scheduled' => esc_html__( 'Sample scheduled', 'anemonedb-settings' ),
				'item_updated' => esc_html__( 'Sample updated.', 'anemonedb-settings' ),
				'parent_item_colon' => esc_html__( 'Parent Sample:', 'anemonedb-settings' ),
			],
			'label' => esc_html__( 'Samples', 'anemonedb-settings' ),
			'description' => 'DNA metabarcoding samples',
			'public' => true,
			'publicly_queryable' => true,
			'show_ui' => true,
			'show_in_rest' => true,
			'rest_base' => '',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'rest_namespace' => 'wp/v2',
			'has_archive' => false,
			'show_in_menu' => true,
			'show_in_nav_menus' => true,
			'delete_with_user' => false,
			'exclude_from_search' => false,
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'hierarchical' => false,
			'can_export' => true,
			'rewrite' => [ 'slug' => 'sample', 'with_front' => false ],
			'query_var' => true,
			'supports' => [ 'title', 'editor', 'thumbnail', 'revisions', 'author' ],
			'taxonomies' => [ 'meshcode2', 'project', 'taxon', 'yearmonth' ],
			'show_in_graphql' => false,
		]
	);
	register_post_type(
		'map',
		[
			'labels' => [
				'name' => esc_html__( 'Maps', 'anemonedb-settings' ),
				'singular_name' => esc_html__( 'Map', 'anemonedb-settings' ),
				'menu_name' => esc_html__( 'Maps', 'anemonedb-settings' ),
				'all_items' => esc_html__( 'All Maps', 'anemonedb-settings' ),
				'add_new' => esc_html__( 'Add new', 'anemonedb-settings' ),
				'add_new_item' => esc_html__( 'Add new Map', 'anemonedb-settings' ),
				'edit_item' => esc_html__( 'Edit Map', 'anemonedb-settings' ),
				'new_item' => esc_html__( 'New Map', 'anemonedb-settings' ),
				'view_item' => esc_html__( 'View Map', 'anemonedb-settings' ),
				'view_items' => esc_html__( 'View Maps', 'anemonedb-settings' ),
				'search_items' => esc_html__( 'Search Maps', 'anemonedb-settings' ),
				'not_found' => esc_html__( 'No Maps found', 'anemonedb-settings' ),
				'not_found_in_trash' => esc_html__( 'No Maps found in trash', 'anemonedb-settings' ),
				'parent' => esc_html__( 'Parent Map:', 'anemonedb-settings' ),
				'featured_image' => esc_html__( 'Featured image for this Map', 'anemonedb-settings' ),
				'set_featured_image' => esc_html__( 'Set featured image for this Map', 'anemonedb-settings' ),
				'remove_featured_image' => esc_html__( 'Remove featured image for this Map', 'anemonedb-settings' ),
				'use_featured_image' => esc_html__( 'Use as featured image for this Map', 'anemonedb-settings' ),
				'archives' => esc_html__( 'Map archives', 'anemonedb-settings' ),
				'insert_into_item' => esc_html__( 'Insert into Map', 'anemonedb-settings' ),
				'uploaded_to_this_item' => esc_html__( 'Upload to this Map', 'anemonedb-settings' ),
				'filter_items_list' => esc_html__( 'Filter Maps list', 'anemonedb-settings' ),
				'items_list_navigation' => esc_html__( 'Maps list navigation', 'anemonedb-settings' ),
				'items_list' => esc_html__( 'Maps list', 'anemonedb-settings' ),
				'attributes' => esc_html__( 'Maps attributes', 'anemonedb-settings' ),
				'name_admin_bar' => esc_html__( 'Map', 'anemonedb-settings' ),
				'item_published' => esc_html__( 'Map published', 'anemonedb-settings' ),
				'item_published_privately' => esc_html__( 'Map published privately.', 'anemonedb-settings' ),
				'item_reverted_to_draft' => esc_html__( 'Map reverted to draft.', 'anemonedb-settings' ),
				'item_scheduled' => esc_html__( 'Map scheduled', 'anemonedb-settings' ),
				'item_updated' => esc_html__( 'Map updated.', 'anemonedb-settings' ),
				'parent_item_colon' => esc_html__( 'Parent Map:', 'anemonedb-settings' ),
			],
			'label' => esc_html__( 'Maps', 'anemonedb-settings' ),
			'description' => '',
			'public' => true,
			'publicly_queryable' => true,
			'show_ui' => true,
			'show_in_rest' => true,
			'rest_base' => '',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'rest_namespace' => 'wp/v2',
			'has_archive' => false,
			'show_in_menu' => true,
			'show_in_nav_menus' => true,
			'delete_with_user' => false,
			'exclude_from_search' => false,
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'hierarchical' => true,
			'can_export' => true,
			'rewrite' => [ 'slug' => 'map', 'with_front' => false ],
			'query_var' => true,
			'supports' => [ 'title', 'editor', 'thumbnail', 'page-attributes' ],
			'show_in_graphql' => false,
		]
	);
}
add_action('init', 'anemonedb_post_types_init');

/**
 * Sets the post updated messages for the `sample` post type.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `sample` post type.
 */
function anemonedb_sample_updated_messages( $messages ) {
	global $post;
	$permalink = get_permalink( $post );
	$messages['sample'] = [
		0  => '', // Unused. Messages start at index 1.
		/* translators: %s: post permalink */
		1  => sprintf( __( 'Sample updated. <a target="_blank" href="%s">View Sample</a>', 'anemonedb-settings' ), esc_url( $permalink ) ),
		2  => __( 'Custom field updated.', 'anemonedb-settings' ),
		3  => __( 'Custom field deleted.', 'anemonedb-settings' ),
		4  => __( 'Sample updated.', 'anemonedb-settings' ),
		/* translators: %s: date and time of the revision */
		5  => isset( $_GET['revision'] ) ? sprintf( __( 'Sample restored to revision from %s', 'anemonedb-settings' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		/* translators: %s: post permalink */
		6  => sprintf( __( 'Sample published. <a href="%s">View Samples</a>', 'anemonedb-settings' ), esc_url( $permalink ) ),
		7  => __( 'Sample saved.', 'anemonedb-settings' ),
		/* translators: %s: post permalink */
		8  => sprintf( __( 'Sample submitted. <a target="_blank" href="%s">Preview Sample</a>', 'anemonedb-settings' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
		/* translators: 1: Publish box date format, see https://secure.php.net/date 2: Post permalink */
		9  => sprintf( __( 'Sample scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview Sample</a>', 'anemonedb-settings' ), date_i18n( __( 'M j, Y @ G:i', 'anemonedb-settings' ), strtotime( $post->post_date ) ), esc_url( $permalink ) ),
		/* translators: %s: post permalink */
		10 => sprintf( __( 'Sample draft updated. <a target="_blank" href="%s">Preview Sample</a>', 'anemonedb-settings' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
	];
	return $messages;
}
add_filter( 'post_updated_messages', 'anemonedb_sample_updated_messages' );

/**
 * Sets the bulk post updated messages for the `sample` post type.
 *
 * @param  array $bulk_messages Arrays of messages, each keyed by the corresponding post type. Messages are
 *                              keyed with 'updated', 'locked', 'deleted', 'trashed', and 'untrashed'.
 * @param  int[] $bulk_counts   Array of item counts for each message, used to build internationalized strings.
 * @return array Bulk messages for the `sample` post type.
 */
function anemonedb_sample_bulk_updated_messages( $bulk_messages, $bulk_counts ) {
	global $post;
	$bulk_messages['sample'] = [
		/* translators: %s: Number of Samples. */
		'updated'   => _n( '%s Sample updated.', '%s Samples updated.', $bulk_counts['updated'], 'anemonedb-settings' ),
		'locked'    => ( 1 === $bulk_counts['locked'] ) ? __( '1 Sample not updated, somebody is editing it.', 'anemonedb-settings' ) :
						/* translators: %s: Number of Samples. */
						_n( '%s Sample not updated, somebody is editing it.', '%s Samples not updated, somebody is editing them.', $bulk_counts['locked'], 'anemonedb-settings' ),
		/* translators: %s: Number of Samples. */
		'deleted'   => _n( '%s Sample permanently deleted.', '%s Samples permanently deleted.', $bulk_counts['deleted'], 'anemonedb-settings' ),
		/* translators: %s: Number of Samples. */
		'trashed'   => _n( '%s Sample moved to the Trash.', '%s Samples moved to the Trash.', $bulk_counts['trashed'], 'anemonedb-settings' ),
		/* translators: %s: Number of Samples. */
		'untrashed' => _n( '%s Sample restored from the Trash.', '%s Samples restored from the Trash.', $bulk_counts['untrashed'], 'anemonedb-settings' ),
	];
	return $bulk_messages;
}
add_filter( 'bulk_post_updated_messages', 'anemonedb_sample_bulk_updated_messages', 10, 2 );

/**
 * Sets the post updated messages for the `map` post type.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `map` post type.
 */
function anemonedb_map_updated_messages( $messages ) {
	global $post;
	$permalink = get_permalink( $post );
	$messages['map'] = [
		0  => '', // Unused. Messages start at index 1.
		/* translators: %s: post permalink */
		1  => sprintf( __( 'Map updated. <a target="_blank" href="%s">View Map</a>', 'anemonedb-settings' ), esc_url( $permalink ) ),
		2  => __( 'Custom field updated.', 'anemonedb-settings' ),
		3  => __( 'Custom field deleted.', 'anemonedb-settings' ),
		4  => __( 'Map updated.', 'anemonedb-settings' ),
		/* translators: %s: date and time of the revision */
		5  => isset( $_GET['revision'] ) ? sprintf( __( 'Map restored to revision from %s', 'anemonedb-settings' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		/* translators: %s: post permalink */
		6  => sprintf( __( 'Map published. <a href="%s">View Map</a>', 'anemonedb-settings' ), esc_url( $permalink ) ),
		7  => __( 'Map saved.', 'anemonedb-settings' ),
		/* translators: %s: post permalink */
		8  => sprintf( __( 'Map submitted. <a target="_blank" href="%s">Preview Map</a>', 'anemonedb-settings' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
		/* translators: 1: Publish box date format, see https://secure.php.net/date 2: Post permalink */
		9  => sprintf( __( 'Map scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview Map</a>', 'anemonedb-settings' ), date_i18n( __( 'M j, Y @ G:i', 'anemonedb-settings' ), strtotime( $post->post_date ) ), esc_url( $permalink ) ),
		/* translators: %s: post permalink */
		10 => sprintf( __( 'Map draft updated. <a target="_blank" href="%s">Preview Map</a>', 'anemonedb-settings' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
	];
	return $messages;
}
add_filter( 'post_updated_messages', 'anemonedb_map_updated_messages' );

/**
 * Sets the bulk post updated messages for the `map` post type.
 *
 * @param  array $bulk_messages Arrays of messages, each keyed by the corresponding post type. Messages are
 *                              keyed with 'updated', 'locked', 'deleted', 'trashed', and 'untrashed'.
 * @param  int[] $bulk_counts   Array of item counts for each message, used to build internationalized strings.
 * @return array Bulk messages for the `map` post type.
 */
function anemonedb_map_bulk_updated_messages( $bulk_messages, $bulk_counts ) {
	global $post;
	$bulk_messages['map'] = [
		/* translators: %s: Number of Maps. */
		'updated'   => _n( '%s Map updated.', '%s Maps updated.', $bulk_counts['updated'], 'anemonedb-settings' ),
		'locked'    => ( 1 === $bulk_counts['locked'] ) ? __( '1 Map not updated, somebody is editing it.', 'anemonedb-settings' ) :
						/* translators: %s: Number of Maps. */
						_n( '%s Map not updated, somebody is editing it.', '%s Maps not updated, somebody is editing them.', $bulk_counts['locked'], 'anemonedb-settings' ),
		/* translators: %s: Number of Maps. */
		'deleted'   => _n( '%s Map permanently deleted.', '%s Maps permanently deleted.', $bulk_counts['deleted'], 'anemonedb-settings' ),
		/* translators: %s: Number of Maps. */
		'trashed'   => _n( '%s Map moved to the Trash.', '%s Maps moved to the Trash.', $bulk_counts['trashed'], 'anemonedb-settings' ),
		/* translators: %s: Number of Maps. */
		'untrashed' => _n( '%s Map restored from the Trash.', '%s Maps restored from the Trash.', $bulk_counts['untrashed'], 'anemonedb-settings' ),
	];
	return $bulk_messages;
}
add_filter( 'bulk_post_updated_messages', 'anemonedb_map_bulk_updated_messages', 10, 2 );

// Register custom taxonomies
function anemonedb_taxonomies_init() {
	register_taxonomy( 'meshcode2', [ 'sample' ], [
		'labels' => [
			'name' => esc_html__( 'Meshcode2', 'anemonedb-settings' ),
			'singular_name' => esc_html__( 'Meshcode2', 'anemonedb-settings' ),
			'menu_name' => esc_html__( 'Meshcode2', 'anemonedb-settings' ),
			'all_items' => esc_html__( 'All Meshcode2', 'anemonedb-settings' ),
			'edit_item' => esc_html__( 'Edit Meshcode2', 'anemonedb-settings' ),
			'view_item' => esc_html__( 'View Meshcode2', 'anemonedb-settings' ),
			'update_item' => esc_html__( 'Update Meshcode2 name', 'anemonedb-settings' ),
			'add_new_item' => esc_html__( 'Add new Meshcode2', 'anemonedb-settings' ),
			'new_item_name' => esc_html__( 'New Meshcode2 name', 'anemonedb-settings' ),
			'parent_item' => esc_html__( 'Parent Meshcode2', 'anemonedb-settings' ),
			'parent_item_colon' => esc_html__( 'Parent Meshcode2:', 'anemonedb-settings' ),
			'search_items' => esc_html__( 'Search Meshcode2', 'anemonedb-settings' ),
			'popular_items' => esc_html__( 'Popular Meshcode2', 'anemonedb-settings' ),
			'separate_items_with_commas' => esc_html__( 'Separate Meshcode2 with commas', 'anemonedb-settings' ),
			'add_or_remove_items' => esc_html__( 'Add or remove Meshcode2', 'anemonedb-settings' ),
			'choose_from_most_used' => esc_html__( 'Choose from the most used Meshcode2', 'anemonedb-settings' ),
			'not_found' => esc_html__( 'No Meshcode2 found', 'anemonedb-settings' ),
			'no_terms' => esc_html__( 'No Meshcode2', 'anemonedb-settings' ),
			'items_list_navigation' => esc_html__( 'Meshcode2 list navigation', 'anemonedb-settings' ),
			'items_list' => esc_html__( 'Meshcode2 list', 'anemonedb-settings' ),
			'back_to_items' => esc_html__( 'Back to Meshcode2', 'anemonedb-settings' ),
			'name_field_description' => esc_html__( 'The name is how it appears on your site.', 'anemonedb-settings' ),
			'parent_field_description' => esc_html__( 'Assign a parent term to create a hierarchy. The term Jazz, for example, would be the parent of Bebop and Big Band.', 'anemonedb-settings' ),
			'slug_field_description' => esc_html__( 'The slug is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'anemonedb-settings' ),
			'desc_field_description' => esc_html__( 'The description is not prominent by default; however, some themes may show it.', 'anemonedb-settings' ),
		],
		'label' => esc_html__( 'Meshcode2', 'anemonedb-settings' ),
		'public' => true,
		'publicly_queryable' => true,
		'hierarchical' => false,
		'show_ui' => true,
		'show_in_menu' => true,
		'show_in_nav_menus' => true,
		'query_var' => true,
		'rewrite' => [ 'slug' => 'meshcode2', 'with_front' => false, ],
		'show_admin_column' => false,
		'show_in_rest' => true,
		'show_tagcloud' => false,
		'rest_base' => 'meshcode2',
		'rest_controller_class' => 'WP_REST_Terms_Controller',
		'rest_namespace' => 'wp/v2',
		'show_in_quick_edit' => false,
		'sort' => false,
		'show_in_graphql' => false,
	] );
	register_taxonomy( 'project', [ 'sample' ], [
		'labels' => [
			'name' => esc_html__( 'Projects', 'anemonedb-settings' ),
			'singular_name' => esc_html__( 'Project', 'anemonedb-settings' ),
			'menu_name' => esc_html__( 'Projects', 'anemonedb-settings' ),
			'all_items' => esc_html__( 'All Projects', 'anemonedb-settings' ),
			'edit_item' => esc_html__( 'Edit Project', 'anemonedb-settings' ),
			'view_item' => esc_html__( 'View Project', 'anemonedb-settings' ),
			'update_item' => esc_html__( 'Update Project name', 'anemonedb-settings' ),
			'add_new_item' => esc_html__( 'Add new Project', 'anemonedb-settings' ),
			'new_item_name' => esc_html__( 'New Project name', 'anemonedb-settings' ),
			'parent_item' => esc_html__( 'Parent Project', 'anemonedb-settings' ),
			'parent_item_colon' => esc_html__( 'Parent Project:', 'anemonedb-settings' ),
			'search_items' => esc_html__( 'Search Projects', 'anemonedb-settings' ),
			'popular_items' => esc_html__( 'Popular Projects', 'anemonedb-settings' ),
			'separate_items_with_commas' => esc_html__( 'Separate Projects with commas', 'anemonedb-settings' ),
			'add_or_remove_items' => esc_html__( 'Add or remove Projects', 'anemonedb-settings' ),
			'choose_from_most_used' => esc_html__( 'Choose from the most used Projects', 'anemonedb-settings' ),
			'not_found' => esc_html__( 'No Projects found', 'anemonedb-settings' ),
			'no_terms' => esc_html__( 'No Projects', 'anemonedb-settings' ),
			'items_list_navigation' => esc_html__( 'Projects list navigation', 'anemonedb-settings' ),
			'items_list' => esc_html__( 'Projects list', 'anemonedb-settings' ),
			'back_to_items' => esc_html__( 'Back to Projects', 'anemonedb-settings' ),
			'name_field_description' => esc_html__( 'The name is how it appears on your site.', 'anemonedb-settings' ),
			'parent_field_description' => esc_html__( 'Assign a parent term to create a hierarchy. The term Jazz, for example, would be the parent of Bebop and Big Band.', 'anemonedb-settings' ),
			'slug_field_description' => esc_html__( 'The slug is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'anemonedb-settings' ),
			'desc_field_description' => esc_html__( 'The description is not prominent by default; however, some themes may show it.', 'anemonedb-settings' ),
		],
		'label' => esc_html__( 'Projects', 'anemonedb-settings' ),
		'public' => true,
		'publicly_queryable' => true,
		'hierarchical' => true,
		'show_ui' => true,
		'show_in_menu' => true,
		'show_in_nav_menus' => true,
		'query_var' => true,
		'rewrite' => [ 'slug' => 'project', 'with_front' => false,  'hierarchical' => true, ],
		'show_admin_column' => false,
		'show_in_rest' => true,
		'show_tagcloud' => false,
		'rest_base' => 'project',
		'rest_controller_class' => 'WP_REST_Terms_Controller',
		'rest_namespace' => 'wp/v2',
		'show_in_quick_edit' => false,
		'sort' => false,
		'show_in_graphql' => false,
	] );
	register_taxonomy( 'taxon', [ 'sample' ], [
		'labels' => [
			'name' => esc_html__( 'Taxa', 'anemonedb-settings' ),
			'singular_name' => esc_html__( 'Taxon', 'anemonedb-settings' ),
			'menu_name' => esc_html__( 'Taxa', 'anemonedb-settings' ),
			'all_items' => esc_html__( 'All Taxa', 'anemonedb-settings' ),
			'edit_item' => esc_html__( 'Edit Taxon', 'anemonedb-settings' ),
			'view_item' => esc_html__( 'View Taxon', 'anemonedb-settings' ),
			'update_item' => esc_html__( 'Update Taxon name', 'anemonedb-settings' ),
			'add_new_item' => esc_html__( 'Add new Taxon', 'anemonedb-settings' ),
			'new_item_name' => esc_html__( 'New Taxon name', 'anemonedb-settings' ),
			'parent_item' => esc_html__( 'Parent Taxon', 'anemonedb-settings' ),
			'parent_item_colon' => esc_html__( 'Parent Taxon:', 'anemonedb-settings' ),
			'search_items' => esc_html__( 'Search Taxa', 'anemonedb-settings' ),
			'popular_items' => esc_html__( 'Popular Taxa', 'anemonedb-settings' ),
			'separate_items_with_commas' => esc_html__( 'Separate Taxa with commas', 'anemonedb-settings' ),
			'add_or_remove_items' => esc_html__( 'Add or remove Taxa', 'anemonedb-settings' ),
			'choose_from_most_used' => esc_html__( 'Choose from the most used Taxa', 'anemonedb-settings' ),
			'not_found' => esc_html__( 'No Taxa found', 'anemonedb-settings' ),
			'no_terms' => esc_html__( 'No Taxa', 'anemonedb-settings' ),
			'items_list_navigation' => esc_html__( 'Taxa list navigation', 'anemonedb-settings' ),
			'items_list' => esc_html__( 'Taxa list', 'anemonedb-settings' ),
			'back_to_items' => esc_html__( 'Back to Taxa', 'anemonedb-settings' ),
			'name_field_description' => esc_html__( 'The name is how it appears on your site.', 'anemonedb-settings' ),
			'parent_field_description' => esc_html__( 'Assign a parent term to create a hierarchy. The term Jazz, for example, would be the parent of Bebop and Big Band.', 'anemonedb-settings' ),
			'slug_field_description' => esc_html__( 'The slug is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'anemonedb-settings' ),
			'desc_field_description' => esc_html__( 'The description is not prominent by default; however, some themes may show it.', 'anemonedb-settings' ),
		],
		'label' => esc_html__( 'Taxa', 'anemonedb-settings' ),
		'public' => true,
		'publicly_queryable' => true,
		'hierarchical' => true,
		'show_ui' => true,
		'show_in_menu' => true,
		'show_in_nav_menus' => true,
		'query_var' => true,
		'rewrite' => [ 'slug' => 'taxon', 'with_front' => false,  'hierarchical' => true, ],
		'show_admin_column' => false,
		'show_in_rest' => true,
		'show_tagcloud' => false,
		'rest_base' => 'taxon',
		'rest_controller_class' => 'WP_REST_Terms_Controller',
		'rest_namespace' => 'wp/v2',
		'show_in_quick_edit' => false,
		'sort' => false,
		'show_in_graphql' => false,
	] );
	register_taxonomy( 'yearmonth', [ 'sample' ], [
		'labels' => [
			'name' => esc_html__( 'YearMonths', 'anemonedb-settings' ),
			'singular_name' => esc_html__( 'YearMonth', 'anemonedb-settings' ),
			'menu_name' => esc_html__( 'YearMonths', 'anemonedb-settings' ),
			'all_items' => esc_html__( 'All YearMonths', 'anemonedb-settings' ),
			'edit_item' => esc_html__( 'Edit YearMonth', 'anemonedb-settings' ),
			'view_item' => esc_html__( 'View YearMonth', 'anemonedb-settings' ),
			'update_item' => esc_html__( 'Update YearMonth name', 'anemonedb-settings' ),
			'add_new_item' => esc_html__( 'Add new YearMonth', 'anemonedb-settings' ),
			'new_item_name' => esc_html__( 'New YearMonth name', 'anemonedb-settings' ),
			'parent_item' => esc_html__( 'Parent YearMonth', 'anemonedb-settings' ),
			'parent_item_colon' => esc_html__( 'Parent YearMonth:', 'anemonedb-settings' ),
			'search_items' => esc_html__( 'Search YearMonths', 'anemonedb-settings' ),
			'popular_items' => esc_html__( 'Popular YearMonths', 'anemonedb-settings' ),
			'separate_items_with_commas' => esc_html__( 'Separate YearMonths with commas', 'anemonedb-settings' ),
			'add_or_remove_items' => esc_html__( 'Add or remove YearMonths', 'anemonedb-settings' ),
			'choose_from_most_used' => esc_html__( 'Choose from the most used YearMonths', 'anemonedb-settings' ),
			'not_found' => esc_html__( 'No YearMonths found', 'anemonedb-settings' ),
			'no_terms' => esc_html__( 'No YearMonths', 'anemonedb-settings' ),
			'items_list_navigation' => esc_html__( 'YearMonths list navigation', 'anemonedb-settings' ),
			'items_list' => esc_html__( 'YearMonths list', 'anemonedb-settings' ),
			'back_to_items' => esc_html__( 'Back to YearMonths', 'anemonedb-settings' ),
			'name_field_description' => esc_html__( 'The name is how it appears on your site.', 'anemonedb-settings' ),
			'parent_field_description' => esc_html__( 'Assign a parent term to create a hierarchy. The term Jazz, for example, would be the parent of Bebop and Big Band.', 'anemonedb-settings' ),
			'slug_field_description' => esc_html__( 'The slug is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'anemonedb-settings' ),
			'desc_field_description' => esc_html__( 'The description is not prominent by default; however, some themes may show it.', 'anemonedb-settings' ),
		],
		'label' => esc_html__( 'YearMonths', 'anemonedb-settings' ),
		'public' => true,
		'publicly_queryable' => true,
		'hierarchical' => true,
		'show_ui' => true,
		'show_in_menu' => true,
		'show_in_nav_menus' => true,
		'query_var' => true,
		'rewrite' => [ 'slug' => 'yearmonth', 'with_front' => false,  'hierarchical' => true, ],
		'show_admin_column' => false,
		'show_in_rest' => true,
		'show_tagcloud' => false,
		'rest_base' => 'yearmonth',
		'rest_controller_class' => 'WP_REST_Terms_Controller',
		'rest_namespace' => 'wp/v2',
		'show_in_quick_edit' => false,
		'sort' => false,
		'show_in_graphql' => false,
	] );
}
add_action('init', 'anemonedb_taxonomies_init');

/**
 * Sets the post updated messages for the `meshcode2` taxonomy.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `meshcode2` taxonomy.
 */
function anemonedb_meshcode2_updated_messages( $messages ) {
	$messages['meshcode2'] = [
		0 => '', // Unused. Messages start at index 1.
		1 => __( 'Meshcode2 added.', 'anemonedb-settings' ),
		2 => __( 'Meshcode2 deleted.', 'anemonedb-settings' ),
		3 => __( 'Meshcode2 updated.', 'anemonedb-settings' ),
		4 => __( 'Meshcode2 not added.', 'anemonedb-settings' ),
		5 => __( 'Meshcode2 not updated.', 'anemonedb-settings' ),
		6 => __( 'Meshcode2 deleted.', 'anemonedb-settings' ),
	];
	return $messages;
}
add_filter( 'term_updated_messages', 'anemonedb_meshcode2_updated_messages' );

/**
 * Sets the post updated messages for the `project` taxonomy.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `project` taxonomy.
 */
function anemonedb_project_updated_messages( $messages ) {
	$messages['project'] = [
		0 => '', // Unused. Messages start at index 1.
		1 => __( 'Project added.', 'anemonedb-settings' ),
		2 => __( 'Project deleted.', 'anemonedb-settings' ),
		3 => __( 'Project updated.', 'anemonedb-settings' ),
		4 => __( 'Project not added.', 'anemonedb-settings' ),
		5 => __( 'Project not updated.', 'anemonedb-settings' ),
		6 => __( 'Projects deleted.', 'anemonedb-settings' ),
	];
	return $messages;
}
add_filter( 'term_updated_messages', 'anemonedb_project_updated_messages' );

/**
 * Sets the post updated messages for the `taxon` taxonomy.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `taxon` taxonomy.
 */
function anemonedb_taxon_updated_messages( $messages ) {
	$messages['taxon'] = [
		0 => '', // Unused. Messages start at index 1.
		1 => __( 'Taxon added.', 'anemonedb-settings' ),
		2 => __( 'Taxon deleted.', 'anemonedb-settings' ),
		3 => __( 'Taxon updated.', 'anemonedb-settings' ),
		4 => __( 'Taxon not added.', 'anemonedb-settings' ),
		5 => __( 'Taxon not updated.', 'anemonedb-settings' ),
		6 => __( 'Taxa deleted.', 'anemonedb-settings' ),
	];
	return $messages;
}
add_filter( 'term_updated_messages', 'anemonedb_taxon_updated_messages' );

/**
 * Sets the post updated messages for the `yearmonth` taxonomy.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `yearmonth` taxonomy.
 */
function anemonedb_yearmonth_updated_messages( $messages ) {
	$messages['yearmonth'] = [
		0 => '', // Unused. Messages start at index 1.
		1 => __( 'YearMonth added.', 'anemonedb-settings' ),
		2 => __( 'YearMonth deleted.', 'anemonedb-settings' ),
		3 => __( 'YearMonth updated.', 'anemonedb-settings' ),
		4 => __( 'YearMonth not added.', 'anemonedb-settings' ),
		5 => __( 'YearMonth not updated.', 'anemonedb-settings' ),
		6 => __( 'YearMonths deleted.', 'anemonedb-settings' ),
	];
	return $messages;
}
add_filter( 'term_updated_messages', 'anemonedb_yearmonth_updated_messages' );

// Modify post type link to include taxonomy term
function anemonedb_permalink_structure($post_link, $post) {
	if ($post->post_type === 'sample') {
		$terms = get_the_terms($post->ID, 'project');
		if ($terms && !is_wp_error($terms)) {
			$term = array_shift($terms);
			$term_slug = get_term_parents_list($term->term_id, 'project', array('separator' => '/', 'link' => false, 'inclusive' => true));
			$term_slug = trim(str_replace(' ', '', $term_slug), '/');
			return home_url('/sample/' . $term_slug . '/' . $post->post_name . '/');
		}
	}
	else if ($post->post_type === 'map') {
		$slug = get_post_ancestors($post->ID);
		$slug = array_reverse($slug);
		$parent_slugs = array();
		foreach ($slug as $parent_id) {
			$parent_post = get_post($parent_id);
			if ($parent_post) {
				$parent_slugs[] = $parent_post->post_name;
			}
		}
		$parent_slugs[] = $post->post_name;
		return home_url('/map/' . implode('/', $parent_slugs) . '/');
	}
	return $post_link;
}
add_filter('post_type_link', 'anemonedb_permalink_structure', 10, 2);

// Define custom post type rewrite rules
function anemonedb_custom_post_type_rewrite_rules() {
	add_rewrite_rule(
		'sample/(.+)/([^/]+)/?$',
		'index.php?post_type=sample&name=$matches[2]',
		'top'
	);
	add_rewrite_rule(
		'map/(.+)/?$',
		'index.php?post_type=map&name=$matches[1]',
		'top'
	);
}
add_action('init', 'anemonedb_custom_post_type_rewrite_rules');

// Add "Modify Emails" submenu to "Settings" menu of dashboard
function anemonedb_add_modify_emails_to_dashboard() {
	add_options_page(
		'Modify Emails',
		'Modify Emails',
		'manage_options',
		'anemonedb-modify-emails',
		'anemonedb_modify_emails_page_screen'
	);
}
add_action('admin_menu', 'anemonedb_add_modify_emails_to_dashboard');

// Screen function for "Modify Emails" submenu of "Settings" menu of dashboard
function anemonedb_modify_emails_page_screen() {
	?>
	<div class="wrap">
		<h1>Modify Emails</h1>
		<form method="post" action="options.php">
			<?php
			settings_fields('anemonedb_modify_emails');
			do_settings_sections('anemonedb-modify-emails');
			submit_button();
			?>
		</form>
	</div>
	<?php
}

// Register settings
function anemonedb_register_modify_emails() {
	register_setting('anemonedb_modify_emails', 'anemonedb_welcome_email_subject');
	register_setting('anemonedb_modify_emails', 'anemonedb_welcome_email_body');
	register_setting('anemonedb_modify_emails', 'anemonedb_reset_password_email_subject');
	register_setting('anemonedb_modify_emails', 'anemonedb_reset_password_email_body');
	add_settings_section(
		'anemonedb_email_section',
		'Email Templates',
		function() { echo '<p>Configure the email messages sent to users.</p><p>The following variables can be used in email subjects.</p><ul><li>{user_login}</li><li>{site_title}</li></ul><p>The following variables can be used in welcome email body.</p><ul><li>{user_login}</li><li>{user_email}</li><li>{login_url}</li><li>{home_url}</li><li>{profile_url}</li><li>{site_title}</li></ul><p>The following variables can be used in reset password email body.</p><ul><li>{user_login}</li><li>{user_email}</li><li>{login_url}</li><li>{home_url}</li><li>{profile_url}</li><li>{site_title}</li><li>{resetpass_url}</li><li>{user_ip}</li></ul>'; },
		'anemonedb-modify-emails'
	);
	add_settings_field(
		'anemonedb_welcome_email_subject',
		'Welcome Email Subject',
		'anemonedb_render_text_input',
		'anemonedb-modify-emails',
		'anemonedb_email_section',
		['label_for' => 'anemonedb_welcome_email_subject']
	);
	add_settings_field(
		'anemonedb_welcome_email_body',
		'Welcome Email Body',
		'anemonedb_render_textarea_input',
		'anemonedb-modify-emails',
		'anemonedb_email_section',
		['label_for' => 'anemonedb_welcome_email_body']
	);
	add_settings_field(
		'anemonedb_reset_password_email_subject',
		'Reset Password Email Subject',
		'anemonedb_render_text_input',
		'anemonedb-modify-emails',
		'anemonedb_email_section',
		['label_for' => 'anemonedb_reset_password_email_subject']
	);
	add_settings_field(
		'anemonedb_reset_password_email_body',
		'Reset Password Email Body',
		'anemonedb_render_textarea_input',
		'anemonedb-modify-emails',
		'anemonedb_email_section',
		['label_for' => 'anemonedb_reset_password_email_body']
	);
}
add_action('admin_init', 'anemonedb_register_modify_emails');

// Render function for subject
function anemonedb_render_text_input($args) {
	$option = get_option($args['label_for'], '');
	echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($option) . '" class="regular-text">';
}

// Render function for body
function anemonedb_render_textarea_input($args) {
	$option = get_option($args['label_for'], '');
	echo '<textarea id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" rows="5" class="large-text">' . esc_textarea($option) . '</textarea>';
}

// Replace welcome email
function anemonedb_replace_welcome_email($user_id) {
	$user = get_userdata($user_id);
	$login_url = wp_login_url();
	$home_url = home_url();
	$profile_url = bp_members_get_user_url($user_id);
	$site_title = get_bloginfo('name');
	$subject = get_option('anemonedb_welcome_email_subject', 'Welcome to our site!');
	$subject = str_replace(
		array('{user_login}', '{site_title}'),
		array($user->user_login, $site_title),
		$subject
	);
	$body = get_option('anemonedb_welcome_email_body', "Hi {user_login},\n\nThank you for registering!\n\nRegards,\nSite Team");
	$body = str_replace(
		array('{user_login}', '{user_email}', '{login_url}', '{home_url}', '{profile_url}', '{site_title}'),
		array($user->user_login, $user->user_email, $login_url, $home_url, $profile_url, $site_title),
		$body
	);
	wp_mail($user->user_email, $subject, $body);
}
add_action('user_register', 'anemonedb_replace_welcome_email');

// Replace reset password email body
function anemonedb_replace_reset_password_email_body($message, $key, $user_login, $user_data) {
	$login_url = wp_login_url();
	$home_url = home_url();
	$profile_url = bp_members_get_user_url($user_data->ID);
	$site_title = get_bloginfo('name');
	$resetpass_url = add_query_arg(
		array(
		'action' => 'rp',
		'key'    => $key,
		'login'  => rawurlencode($user_login),
		),
		$login_url
	);
	$user_ip = anemonedb_get_client_ip();
	$body = get_option('anemonedb_reset_password_email_body', "Hi {user_login},\n\nClick the link below to reset your password:\n{resetpass_url}\n\nRegards,\nSite Team");
	$body = str_replace(
		array('{user_login}', '{user_email}', '{login_url}', '{home_url}', '{profile_url}', '{site_title}', '{resetpass_url}', '{user_ip}'),
		array($user_login, $user_data->user_email, $login_url, $home_url, $profile_url, $site_title, $resetpass_url, $user_ip),
		$body
	);
	return $body;
}
add_filter('retrieve_password_message', 'anemonedb_replace_reset_password_email_body', 10, 4);

// Replace reset password email subject
function anemonedb_replace_reset_password_email_subject($title, $user_login, $user_data) {
	$site_title = get_bloginfo('name');
	$subject = get_option('anemonedb_reset_password_email_subject', 'Reset your password');
	$subject = str_replace(
		array('{user_login}', '{site_title}'),
		array($user_login, $site_title),
		$subject
	);
	return get_option('anemonedb_reset_password_email_subject', 'Reset your password');
}
add_filter('retrieve_password_title', 'anemonedb_replace_reset_password_email_subject', 10, 3);

// Create email tables
function anemonedb_create_email_tables() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_content = $wpdb->prefix . 'anemonedb_email_content';
	$sql_content = "CREATE TABLE IF NOT EXISTS $table_content (
		subject TEXT NOT NULL,
		body TEXT NOT NULL,
		status ENUM('active', 'paused', 'completed') NOT NULL DEFAULT 'completed',
		batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 1000
	) $charset_collate;";
	$table_recipients = $wpdb->prefix . 'anemonedb_email_recipients';
	$sql_recipients = "CREATE TABLE IF NOT EXISTS $table_recipients (
		user_id BIGINT(20) NOT NULL PRIMARY KEY
	) $charset_collate;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql_content);
	dbDelta($sql_recipients);
}
register_activation_hook(__FILE__, 'anemonedb_create_email_tables');

// Delete email tables
function anemonedb_delete_email_tables() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'anemonedb_email_content';
	$table_recipients = $wpdb->prefix . 'anemonedb_email_recipients';
	$content_exists = $wpdb->get_var("SELECT COUNT(*) FROM $table_content");
	$recipients_exists = $wpdb->get_var("SELECT COUNT(*) FROM $table_recipients");
	$cron_exists = wp_next_scheduled('anemonedb_email_send');
	if ($content_exists > 0 || $recipients_exists > 0 || $cron_exists) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die('<p>There are pending email jobs. Please cancel them before deactivating the plugin.</p><p><a href="' . esc_url(admin_url('admin.php?page=anemonedb-send-email')) . '">Go to Email Management</a></p>', 'Plugin Deactivation Error', array('back_link' => true));
	}
	wp_clear_scheduled_hook('anemonedb_email_send');
	$wpdb->query("DROP TABLE IF EXISTS $table_content");
	$wpdb->query("DROP TABLE IF EXISTS $table_recipients");
}

// Add "Send Email to Roles" submenu to "Settings" menu of dashboard
function anemonedb_add_send_email_to_dashboard() {
	add_management_page(
		'Send Email to Roles',
		'Send Email to Roles',
		'manage_options',
		'anemonedb-send-email',
		'anemonedb_send_email_page_screen'
	);
}
add_action('admin_menu', 'anemonedb_add_send_email_to_dashboard');

// Screen function for "Send Email" submenu of "Settings" menu of dashboard
function anemonedb_send_email_page_screen() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'anemonedb_email_content';
	$current_job = $wpdb->get_row("SELECT * FROM $table_content LIMIT 1");
	$is_sending = $current_job && ($current_job->status === 'active');
	$is_paused = $current_job && ($current_job->status === 'paused');
	echo '<div class="wrap"><h1>Send Email to Roles</h1>';
	if ($is_sending) {
		echo '<h2>Sending Emails...</h2>';
		echo '<p>Email is currently being sent. You can pause the sending process.</p>';
		echo '<form method="post">';
		wp_nonce_field('anemonedb_pause_send_email', 'anemonedb_pause_send_email_nonce');
		echo '<input type="hidden" name="anemonedb_pause_send_email" value="1">';
		echo '<p><input type="submit" class="button button-warning" value="Pause Sending"></p></form>';
	}
	else if ($is_paused) {
		echo '<h2>Pausing Sending Emails...</h2>';
		echo '<p>Email sending is paused. You can resume or cancel the process.</p>';
		echo '<form method="post">';
		wp_nonce_field('anemonedb_resume_send_email', 'anemonedb_resume_send_email_nonce');
		echo '<input type="hidden" name="anemonedb_resume_send_email" value="1">';
		echo '<p><input type="submit" class="button button-primary" value="Resume Sending"></p></form>';
		echo '<form method="post">';
		wp_nonce_field('anemonedb_cancel_send_email', 'anemonedb_cancel_send_email_nonce');
		echo '<input type="hidden" name="anemonedb_cancel_send_email" value="1">';
		echo '<p><input type="submit" class="button button-danger" value="Cancel Sending"></p></form>';
	}
	else {
		if (isset($_POST['anemonedb_confirm_send_email']) && check_admin_referer('anemonedb_confirm_send_email', 'anemonedb_confirm_send_email_nonce')) {
			$subject = sanitize_text_field($_POST['email_subject']);
			$body = sanitize_textarea_field($_POST['email_body']);
			$roles = array();
			foreach ($_POST['email_recipient_roles'] as $role_key) {
				$role_key = sanitize_text_field($role_key);
				if (isset(wp_roles()->roles[$role_key])) {
					$roles[] = $role_key;
				}
				else {
					anemonedb_display_admin_notice('Recipient role "' . $role_key . '" is invalid.', 'error');
					wp_redirect(wp_get_referer());
					exit;
				}
			}
			$unlogged_only = !empty($_POST['email_unlogged_only']);
			$batch_size = sanitize_text_field($_POST['email_batch_size']);
			if (empty($subject) || empty($body) || empty($roles) || empty($batch_size)) {
				anemonedb_display_admin_notice('Email subject, body, recipient roles and batch size are required.', 'error');
				wp_redirect(wp_get_referer());
				exit;
			}
			if ($batch_size < 10 || $batch_size > 10000) {
				anemonedb_display_admin_notice('Batch size "' . $batch_size . '" is invalid.', 'error');
				wp_redirect(wp_get_referer());
				exit;
			}
			echo '<h2>Confirm Email</h2>';
			echo '<p>Please review the email details before sending.</p>';
			echo '<table class="form-table">
				<tr><th>Email Subject:</th><td><strong>' . esc_html($subject) . '</strong></td></tr>
				<tr><th>Email Body:</th><td><pre>' . esc_html($body) . '</pre></td></tr>';
			$role_names = array();
			foreach ($roles as $role_key) {
				$role_data = wp_roles()->roles[$role_key];
				$role_names[] = esc_html($role_data['name']);
			}
			echo '<tr><th>Recipient Role(s):</th><td><strong>' . implode('</strong><br /><strong>', $role_names) . '</strong></td></tr>';
			echo '<tr><th>Limit to users who never logged in:</th><td><strong>' . ($unlogged_only ? 'Yes' : 'No') . '</strong></td></tr>
				<tr><th>Sending batch size every 10 min:</th><td><strong>' . esc_html($batch_size) . '</strong></td></tr>
				</table>';
			echo '<form method="post">';
			wp_nonce_field('anemonedb_perform_send_email', 'anemonedb_perform_send_email_nonce');
			echo '<input type="hidden" name="email_subject" value="' . esc_attr($subject) . '">';
			echo '<input type="hidden" name="email_body" value="' . esc_attr($body) . '">';
			foreach ($roles as $role_key) {
				echo '<input type="hidden" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '">';
			}
			echo '<input type="hidden" name="email_unlogged_only" value="' . ($unlogged_only ? '1' : '') . '">';
			echo '<input type="hidden" name="email_batch_size" value="' . esc_attr($batch_size) . '">';
			echo '<input type="hidden" name="anemonedb_perform_send_email" value="1">';
			echo '<p><input type="submit" class="button button-primary" value="Send Email"></p></form>';
			echo '<form method="post">';
			wp_nonce_field('anemonedb_edit_send_email', 'anemonedb_edit_send_email_nonce');
			echo '<input type="hidden" name="email_subject" value="' . esc_attr($subject) . '">';
			echo '<input type="hidden" name="email_body" value="' . esc_attr($body) . '">';
			foreach ($roles as $role_key) {
				echo '<input type="hidden" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '">';
			}
			echo '<input type="hidden" name="email_unlogged_only" value="' . ($unlogged_only ? '1' : '') . '">';
			echo '<input type="hidden" name="email_batch_size" value="' . esc_attr($batch_size) . '">';
			echo '<input type="hidden" name="anemonedb_edit_send_email" value="1">';
			echo '<p><input type="submit" class="button" value="Edit"></p></form>';
		}
		else {
			if (isset($_POST['anemonedb_edit_send_email']) && check_admin_referer('anemonedb_edit_send_email', 'anemonedb_edit_send_email_nonce')) {
				$subject = sanitize_text_field($_POST['email_subject']);
				$body = sanitize_textarea_field($_POST['email_body']);
				$roles = array();
				foreach ($_POST['email_recipient_roles'] as $role_key) {
					$role_key = sanitize_text_field($role_key);
					if (isset(wp_roles()->roles[$role_key])) {
						$roles[] = $role_key;
					}
					else {
						anemonedb_display_admin_notice('Recipient role "' . $role_key . '" is invalid.', 'error');
						wp_redirect(wp_get_referer());
						exit;
					}
				}
				$unlogged_only = !empty($_POST['email_unlogged_only']);
				$batch_size = sanitize_text_field($_POST['email_batch_size']);
				if (empty($subject) || empty($body) || empty($roles) || empty($batch_size)) {
					anemonedb_display_admin_notice('Email subject, body, recipient roles and batch size are required.', 'error');
					wp_redirect(wp_get_referer());
					exit;
				}
				if ($batch_size < 10 || $batch_size > 10000) {
					anemonedb_display_admin_notice('Batch size "' . $batch_size . '" is invalid.', 'error');
					wp_redirect(wp_get_referer());
					exit;
				}
				echo '<h2>Edit Email</h2>';
				echo '<p>Input the email message sent to users.</p><p>The following variables can be used in email subject.</p><ul><li>{user_login}</li><li>{site_title}</li></ul><p>The following variables can be used in email body.</p><ul><li>{user_login}</li><li>{user_email}</li><li>{login_url}</li><li>{home_url}</li><li>{profile_url}</li><li>{site_title}</li><li>{resetpass_url}</li></ul>';
				echo '<form method="post">';
				wp_nonce_field('anemonedb_confirm_send_email', 'anemonedb_confirm_send_email_nonce');
				echo '<table class="form-table">
					<tr><th><label for="email_subject">Email Subject</label></th>
					<td><input type="text" id="email_subject" name="email_subject" class="regular-text" value="' . esc_attr($subject) . '"></td></tr>
					<tr><th><label for="email_body">Email Body</label></th>
					<td><textarea id="email_body" name="email_body" rows="5" class="large-text">' . esc_attr($body) . '</textarea></td></tr>
					<tr><th><label for="email_recipient_roles">Recipient Roles</label></th><td>';
				$all_roles = wp_roles()->roles;
				foreach ($all_roles as $role_key => $role_data) {
					if (in_array($role_key, $roles)) {
						echo '<input type="checkbox" id="email_recipient_roles" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '" checked>' . esc_html($role_data['name']) . '<br />';
					}
					else {
						echo '<input type="checkbox" id="email_recipient_roles" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '">' . esc_html($role_data['name']) . '<br />';
					}
				}
				echo '</td></tr><tr><th><label for="email_unlogged_only">Limit to users who never logged in</label></th><td>';
				echo '<input type="checkbox" id="email_unlogged_only" name="email_unlogged_only"' . ($unlogged_only ? ' checked' : '') . '>';
				echo '</td></tr>
					<tr><th><label for="email_batch_size">Sending batch size every 10 min</label></th>
					<td><select id="email_batch_size" name="email_batch_size">';
				$batch_sizes = array(10, 50, 100, 500, 1000, 5000, 10000);
				foreach ($batch_sizes as $value) {
					if ($value == $batch_size) {
						echo '<option value="' . $value . '" selected>' . $value . '</option>';
					}
					else {
						echo '<option value="' . $value . '">' . $value . '</option>';
					}
				}
				echo '</select></td></tr>
					</table>';
				echo '<input type="hidden" name="anemonedb_confirm_send_email" value="1">';
				echo '<p><input type="submit" class="button button-primary" value="Confirm Email"></p></form>';
			}
			else {
				echo '<h2>Input Email</h2>';
				echo '<p>Input the email message sent to users.</p><p>The following variables can be used in email subject.</p><ul><li>{user_login}</li><li>{site_title}</li></ul><p>The following variables can be used in email body.</p><ul><li>{user_login}</li><li>{user_email}</li><li>{login_url}</li><li>{home_url}</li><li>{profile_url}</li><li>{site_title}</li><li>{resetpass_url}</li></ul>';
				echo '<form method="post">';
				wp_nonce_field('anemonedb_confirm_send_email', 'anemonedb_confirm_send_email_nonce');
				echo '<table class="form-table">
					<tr><th><label for="email_subject">Email Subject</label></th>
					<td><input type="text" id="email_subject" name="email_subject" class="regular-text"></td></tr>
					<tr><th><label for="email_body">Email Body</label></th>
					<td><textarea id="email_body" name="email_body" rows="5" class="large-text"></textarea></td></tr>
					<tr><th><label for="email_recipient_roles">Recipient Roles</label></th><td>';
				$roles = wp_roles()->roles;
				foreach ($roles as $role_key => $role_data) {
					echo '<input type="checkbox" id="email_recipient_roles" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '">' . esc_html($role_data['name']) . '<br />';
				}
				echo '</td></tr><tr><th><label for="email_unlogged_only">Limit to users who never logged in</label></th>
					<td><input type="checkbox" id="email_unlogged_only" name="email_unlogged_only"></td></tr>
					<tr><th><label for="email_batch_size">Sending batch size every 10 min</label></th>
					<td><select id="email_batch_size" name="email_batch_size">
						<option value="10">10</option>
						<option value="50">50</option>
						<option value="100">100</option>
						<option value="500">500</option>
						<option value="1000" selected>1000</option>
						<option value="5000">5000</option>
						<option value="10000">10000</option>
					</select></td></tr>
					</table>';
				echo '<input type="hidden" name="anemonedb_confirm_send_email" value="1">';
				echo '<p><input type="submit" class="button button-primary" value="Confirm Email"></p></form>';
			}
		}
	}
	echo '</div>';
}

// Add custom schedules
function anemonedb_add_cron_schedules($schedules) {
    $schedules['anemonedb_every_ten_minutes'] = array(
        'interval' => 600, // = 10 minutes
        'display'  => __('Every 10 Minutes')
    );
    return $schedules;
}
add_filter('cron_schedules', 'anemonedb_add_cron_schedules');

// Button push handling
function anemonedb_handle_email_controls() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'anemonedb_email_content';
	$table_recipients = $wpdb->prefix . 'anemonedb_email_recipients';
	if (isset($_POST['anemonedb_pause_send_email']) && check_admin_referer('anemonedb_pause_send_email', 'anemonedb_pause_send_email_nonce')) {
		$wpdb->query("UPDATE $table_content SET status='paused' WHERE status='active'");
		anemonedb_display_admin_notice('Email sending has been paused.', 'success');
	}
	if (isset($_POST['anemonedb_resume_send_email']) && check_admin_referer('anemonedb_resume_send_email', 'anemonedb_resume_send_email_nonce')) {
		$wpdb->query("UPDATE $table_content SET status='active' WHERE status='paused'");
		anemonedb_display_admin_notice('Email sending has resumed.', 'success');
		if (!wp_next_scheduled('anemonedb_email_send')) {
			wp_schedule_event(time(), 'anemonedb_every_ten_minutes', 'anemonedb_email_send');
		}
	}
	if (isset($_POST['anemonedb_cancel_send_email']) && check_admin_referer('anemonedb_cancel_send_email', 'anemonedb_cancel_send_email_nonce')) {
		$wpdb->query("DELETE FROM $table_content");
		$wpdb->query("DELETE FROM $table_recipients");
		wp_clear_scheduled_hook('anemonedb_email_send');
		anemonedb_display_admin_notice('Email sending has been completely canceled.', 'success');
	}
}
add_action('admin_init', 'anemonedb_handle_email_controls');

// Send email
function anemonedb_perform_send_email() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'anemonedb_email_content';
	$table_recipients = $wpdb->prefix . 'anemonedb_email_recipients';
	if (!isset($_POST['anemonedb_perform_send_email']) || !check_admin_referer('anemonedb_perform_send_email', 'anemonedb_perform_send_email_nonce')) {
		return;
	}
	$subject = sanitize_text_field($_POST['email_subject']);
	$body = sanitize_textarea_field($_POST['email_body']);
	$roles = array();
	foreach ($_POST['email_recipient_roles'] as $role_key) {
		$role_key = sanitize_text_field($role_key);
		if (isset(wp_roles()->roles[$role_key])) {
			$roles[] = $role_key;
		}
		else {
			anemonedb_display_admin_notice('Recipient role "' . $role_key . '" is invalid.', 'error');
			return;
		}
	}
	$unlogged_only = !empty($_POST['email_unlogged_only']);
	$batch_size = sanitize_text_field($_POST['email_batch_size']);
	if (empty($subject) || empty($body) || empty($roles) || empty($batch_size)) {
		anemonedb_display_admin_notice('Email subject, body, recipient roles and batch size are required.', 'error');
		return;
	}
	if ($batch_size < 10 || $batch_size > 10000) {
		anemonedb_display_admin_notice('Batch size "' . $batch_size . '" is invalid.', 'error');
		return;
	}
	$wpdb->query("DELETE FROM $table_content");
	$wpdb->insert(
		$table_content,
		array(
			'subject'    => $subject,
			'body'       => $body,
			'status'     => 'active',
			'batch_size' => $batch_size
		),
		array('%s', '%s', '%s', '%d')
	);
	$page = 1;
	$total_users = 0;
	do {
		$users = anemonedb_get_users_by_roles($roles, $unlogged_only, 1000, $page);
		if (empty($users)) {
			$new_users = anemonedb_get_users_by_roles($roles, $unlogged_only, 1000, $page + 1);
			if (!empty($new_users)) {
				$users = $new_users;
			} else {
				break;
			}
		}
		$values = [];
		$placeholders = [];
		foreach ($users as $user) {
			$values[] = $user->ID;
			$placeholders[] = "(%d)";
		}
		$query = "INSERT INTO $table_recipients (user_id) VALUES " . implode(',', $placeholders);
		$wpdb->query($wpdb->prepare($query, ...$values));
		$total_users += count($users);
		$page++;
	} while (count($users) >= 1000);
	if ($total_users === 0) {
		anemonedb_display_admin_notice('No users found to send email.', 'error');
		return;
	}
	if (!wp_next_scheduled('anemonedb_email_send')) {
		wp_schedule_event(time(), 'anemonedb_every_ten_minutes', 'anemonedb_email_send');
	}
	anemonedb_display_admin_notice('Email sending started: <strong>' . esc_html($subject) . '</strong> to ' . esc_html($total_users) . ' users.', 'success');
}
add_action('admin_init', 'anemonedb_perform_send_email');

// Send email in cron job
function anemonedb_send_email_cron() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'anemonedb_email_content';
	$table_recipients = $wpdb->prefix . 'anemonedb_email_recipients';
	$current_job = $wpdb->get_row("SELECT * FROM $table_content LIMIT 1");
	if (empty($current_job) || $current_job->status === 'paused') {
		return;
	}
	$recipients = $wpdb->get_results("SELECT user_id FROM $table_recipients LIMIT $current_job->batch_size");
	if (empty($recipients)) {
		$wpdb->query("DELETE FROM $table_content");
		wp_clear_scheduled_hook('anemonedb_email_send');
		return;
	}
	$contains_resetpass_url = strpos($current_job->body, '{resetpass_url}') !== false;
	$usleep_time = (int) ((60 / $current_job->batch_size) * 1000000);
	foreach ($recipients as $recipient) {
		$user = get_userdata($recipient->user_id);
		if ($user) {
			$login_url = wp_login_url();
			$home_url = home_url();
			$profile_url = bp_members_get_user_url($recipient->user_id);
			$site_title = get_bloginfo('name');
			$subject = str_replace(
				array('{user_login}', '{site_title}'),
				array($user->user_login, $site_title),
				$current_job->subject
			);
			$body = str_replace(
				array('{user_login}', '{user_email}', '{login_url}', '{home_url}', '{profile_url}', '{site_title}'),
				array($user->user_login, $user->user_email, $login_url, $home_url, $profile_url, $site_title),
				$current_job->body
			);
			if ($contains_resetpass_url) {
				$key = get_password_reset_key($user);
				$resetpass_url = add_query_arg(
					array(
					'action' => 'rp',
					'key'    => $key,
					'login'  => rawurlencode($user->user_login),
					),
					$login_url
				);
				$body = str_replace('{resetpass_url}', $resetpass_url, $body);
			}
			wp_mail($user->user_email, $subject, $body);
			usleep($usleep_time);
		}
	}
	$ids = wp_list_pluck($recipients, 'user_id');
	$wpdb->query("DELETE FROM $table_recipients WHERE user_id IN (" . implode(',', $ids) . ")");
	$remaining_users = $wpdb->get_var("SELECT COUNT(*) FROM $table_recipients");
	if ($remaining_users == 0) {
		$wpdb->query("DELETE FROM $table_content");
		wp_clear_scheduled_hook('anemonedb_email_send');
	}
}
add_action('anemonedb_email_send', 'anemonedb_send_email_cron');

// Get users
function anemonedb_get_users_by_roles($roles, $unlogged_only, $batch_size = 1000, $page = 1) {
	$args = array(
		'role__in'  => empty($roles) ? null : $roles,
		'fields'    => array('ID'),
		'number'    => $batch_size,
		'paged'     => $page,
	);
	if ($unlogged_only) {
		$args['meta_query'] = array(
			array(
				'key'     => 'last_login',
				'compare' => 'NOT EXISTS',
			),
		);
	}
	$query = new WP_User_Query($args);
	return $query->get_results();
}
