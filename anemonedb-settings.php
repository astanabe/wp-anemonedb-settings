<?php
/**
 * Plugin Name:     ANEMONE DB Settings
 * Plugin URI:      https://github.com/astanabe/anemonedb-settings
 * Description:     ANEMONE DB Settings Plugin for WordPress
 * Author:          Akifumi S. Tanabe
 * Author URI:      https://github.com/astanabe
 * License:         GNU General Public License v2
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     anemonedb-settings
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Anemonedb_Settings
 */

// Security check
if (!defined('ABSPATH')) {
	exit;
}

// Activation hook
function anemonedb_settings_activate() {
	anemonedb_check_required_plugins();
	anemonedb_check_login_failure_log();
	anemonedb_create_dd_users_table();
	anemonedb_post_types_init();
	anemonedb_taxonomies_init();
	flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'anemonedb_settings_activate');

// Deactivation hook
function anemonedb_settings_deactivate() {
	anemonedb_delete_dd_users_table();
	flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'anemonedb_settings_deactivate');

// Function to display admin notices
function anemonedb_admin_notices_callback() {
	if (!empty($GLOBALS['anemonedb_admin_notices'])) {
		foreach ($GLOBALS['anemonedb_admin_notices'] as $notice) {
			echo '<div class="notice notice-error"><p><strong>ANEMONE DB Settings Plugin Error:</strong> ' . wp_kses_post($notice) . '</p></div>';
		}
	}
}
add_action('admin_notices', 'anemonedb_admin_notices_callback');

// Function to add admin notices
function anemonedb_add_admin_notices($message) {
	$GLOBALS['anemonedb_admin_notices'][] = $message;
}

// Check required plugins
function anemonedb_check_required_plugins() {
	// Ensure the required function is available
	if (!function_exists('is_plugin_active')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$deactivate = false;
	// Check if BuddyPress is active
	if (!is_plugin_active('buddypress/bp-loader.php')) {
		$deactivate = true;
		anemonedb_add_admin_notices('ANEMONE DB Settings Plugin requires <a href="' . esc_url('https://wordpress.org/plugins/buddypress/') . '" target="_blank">BuddyPress</a> plugin to be installed and activated.');
	}
	// Check if Two-Factor is active
	if (!is_plugin_active('two-factor/two-factor.php')) {
		$deactivate = true;
		anemonedb_add_admin_notices('ANEMONE DB Settings Plugin requires <a href="' . esc_url('https://wordpress.org/plugins/two-factor/') . '" target="_blank">Two-Factor</a> plugin to be installed and activated.');
	}
	// Deactivate the plugin if required plugins are missing
	if ($deactivate && current_user_can('activate_plugins')) {
		deactivate_plugins(plugin_basename(__FILE__));
	}
}

// Check login failure log file
function anemonedb_check_login_failure_log() {
	$authlog = "/var/log/wp_auth_failure.log";
	if (!file_exists($authlog)) {
		if (!touch($authlog)) {
			anemonedb_add_admin_notices("Failed to create log file {$authlog}. Please create this file and ensure the correct permissions are set.");
			return;
		}
		chmod($authlog, 0644);
	}
	if (!is_writable($authlog)) {
		anemonedb_add_admin_notices("Log file {$authlog} is not writable. Please set the correct permissions (e.g., 644).");
		return;
	}
}

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

// Hide send private message button
function anemonedb_hide_send_message_button() {
	return false;
}
add_filter( 'bp_get_send_message_button_args', 'anemonedb_hide_send_message_button' );

// Disable adminbar except for admins and editors
function anemonedb_remove_admin_bar( $content ) {
	return ( current_user_can("administrator") || current_user_can("editor") ) ? $content : false;
}
add_filter( 'show_admin_bar' , 'anemonedb_remove_admin_bar');

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

// Login failure logging
function anemonedb_login_failure_log($intruder) {
	$authlog = "/var/log/wp_auth_failure.log";
	$msg = date('[Y-m-d H:i:s T]') . " login failure from " . $_SERVER['REMOTE_ADDR'] . " for $intruder\n";
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

// Disable "Profile Visibility" page
function anemonedb_remove_profile_visibility() {
	bp_core_remove_subnav_item( 'settings', 'profile' );
}
add_action( 'bp_setup_nav', 'anemonedb_remove_profile_visibility', 999 );

// Disable "Email" page
function anemonedb_remove_notifications() {
	bp_core_remove_subnav_item( 'settings', 'notifications' );
}
add_action( 'bp_setup_nav', 'anemonedb_remove_notifications', 999 );

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
		wp_schedule_event(time(), 'hourly', 'anemonedb_dd_pass_cleanup');
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
			'labels'                => [
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
			'labels'                => [
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
function sample_updated_messages( $messages ) {
	global $post;

	$permalink = get_permalink( $post );

	$messages['sample'] = [
		0  => '', // Unused. Messages start at index 1.
		/* translators: %s: post permalink */
		1  => sprintf( __( 'Samples updated. <a target="_blank" href="%s">View Samples</a>', 'anemonedb-settings' ), esc_url( $permalink ) ),
		2  => __( 'Custom field updated.', 'anemonedb-settings' ),
		3  => __( 'Custom field deleted.', 'anemonedb-settings' ),
		4  => __( 'Samples updated.', 'anemonedb-settings' ),
		/* translators: %s: date and time of the revision */
		5  => isset( $_GET['revision'] ) ? sprintf( __( 'Samples restored to revision from %s', 'anemonedb-settings' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		/* translators: %s: post permalink */
		6  => sprintf( __( 'Samples published. <a href="%s">View Samples</a>', 'anemonedb-settings' ), esc_url( $permalink ) ),
		7  => __( 'Samples saved.', 'anemonedb-settings' ),
		/* translators: %s: post permalink */
		8  => sprintf( __( 'Samples submitted. <a target="_blank" href="%s">Preview Samples</a>', 'anemonedb-settings' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
		/* translators: 1: Publish box date format, see https://secure.php.net/date 2: Post permalink */
		9  => sprintf( __( 'Samples scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview Samples</a>', 'anemonedb-settings' ), date_i18n( __( 'M j, Y @ G:i', 'anemonedb-settings' ), strtotime( $post->post_date ) ), esc_url( $permalink ) ),
		/* translators: %s: post permalink */
		10 => sprintf( __( 'Samples draft updated. <a target="_blank" href="%s">Preview Samples</a>', 'anemonedb-settings' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
	];

	return $messages;
}
add_filter( 'post_updated_messages', 'sample_updated_messages' );

/**
 * Sets the bulk post updated messages for the `sample` post type.
 *
 * @param  array $bulk_messages Arrays of messages, each keyed by the corresponding post type. Messages are
 *                              keyed with 'updated', 'locked', 'deleted', 'trashed', and 'untrashed'.
 * @param  int[] $bulk_counts   Array of item counts for each message, used to build internationalized strings.
 * @return array Bulk messages for the `sample` post type.
 */
function sample_bulk_updated_messages( $bulk_messages, $bulk_counts ) {
	global $post;

	$bulk_messages['sample'] = [
		/* translators: %s: Number of Samples. */
		'updated'   => _n( '%s Samples updated.', '%s Samples updated.', $bulk_counts['updated'], 'anemonedb-settings' ),
		'locked'    => ( 1 === $bulk_counts['locked'] ) ? __( '1 Samples not updated, somebody is editing it.', 'anemonedb-settings' ) :
						/* translators: %s: Number of Samples. */
						_n( '%s Samples not updated, somebody is editing it.', '%s Samples not updated, somebody is editing them.', $bulk_counts['locked'], 'anemonedb-settings' ),
		/* translators: %s: Number of Samples. */
		'deleted'   => _n( '%s Samples permanently deleted.', '%s Samples permanently deleted.', $bulk_counts['deleted'], 'anemonedb-settings' ),
		/* translators: %s: Number of Samples. */
		'trashed'   => _n( '%s Samples moved to the Trash.', '%s Samples moved to the Trash.', $bulk_counts['trashed'], 'anemonedb-settings' ),
		/* translators: %s: Number of Samples. */
		'untrashed' => _n( '%s Samples restored from the Trash.', '%s Samples restored from the Trash.', $bulk_counts['untrashed'], 'anemonedb-settings' ),
	];

	return $bulk_messages;
}
add_filter( 'bulk_post_updated_messages', 'sample_bulk_updated_messages', 10, 2 );

/**
 * Sets the post updated messages for the `map` post type.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `map` post type.
 */
function map_updated_messages( $messages ) {
	global $post;

	$permalink = get_permalink( $post );

	$messages['map'] = [
		0  => '', // Unused. Messages start at index 1.
		/* translators: %s: post permalink */
		1  => sprintf( __( 'Maps updated. <a target="_blank" href="%s">View Maps</a>', 'anemonedb-settings' ), esc_url( $permalink ) ),
		2  => __( 'Custom field updated.', 'anemonedb-settings' ),
		3  => __( 'Custom field deleted.', 'anemonedb-settings' ),
		4  => __( 'Maps updated.', 'anemonedb-settings' ),
		/* translators: %s: date and time of the revision */
		5  => isset( $_GET['revision'] ) ? sprintf( __( 'Maps restored to revision from %s', 'anemonedb-settings' ), wp_post_revision_title( (int) $_GET['revision'], false ) ) : false, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		/* translators: %s: post permalink */
		6  => sprintf( __( 'Maps published. <a href="%s">View Maps</a>', 'anemonedb-settings' ), esc_url( $permalink ) ),
		7  => __( 'Maps saved.', 'anemonedb-settings' ),
		/* translators: %s: post permalink */
		8  => sprintf( __( 'Maps submitted. <a target="_blank" href="%s">Preview Maps</a>', 'anemonedb-settings' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
		/* translators: 1: Publish box date format, see https://secure.php.net/date 2: Post permalink */
		9  => sprintf( __( 'Maps scheduled for: <strong>%1$s</strong>. <a target="_blank" href="%2$s">Preview Maps</a>', 'anemonedb-settings' ), date_i18n( __( 'M j, Y @ G:i', 'anemonedb-settings' ), strtotime( $post->post_date ) ), esc_url( $permalink ) ),
		/* translators: %s: post permalink */
		10 => sprintf( __( 'Maps draft updated. <a target="_blank" href="%s">Preview Maps</a>', 'anemonedb-settings' ), esc_url( add_query_arg( 'preview', 'true', $permalink ) ) ),
	];

	return $messages;
}
add_filter( 'post_updated_messages', 'map_updated_messages' );

/**
 * Sets the bulk post updated messages for the `map` post type.
 *
 * @param  array $bulk_messages Arrays of messages, each keyed by the corresponding post type. Messages are
 *                              keyed with 'updated', 'locked', 'deleted', 'trashed', and 'untrashed'.
 * @param  int[] $bulk_counts   Array of item counts for each message, used to build internationalized strings.
 * @return array Bulk messages for the `map` post type.
 */
function map_bulk_updated_messages( $bulk_messages, $bulk_counts ) {
	global $post;

	$bulk_messages['map'] = [
		/* translators: %s: Number of Maps. */
		'updated'   => _n( '%s Maps updated.', '%s Maps updated.', $bulk_counts['updated'], 'anemonedb-settings' ),
		'locked'    => ( 1 === $bulk_counts['locked'] ) ? __( '1 Maps not updated, somebody is editing it.', 'anemonedb-settings' ) :
						/* translators: %s: Number of Maps. */
						_n( '%s Maps not updated, somebody is editing it.', '%s Maps not updated, somebody is editing them.', $bulk_counts['locked'], 'anemonedb-settings' ),
		/* translators: %s: Number of Maps. */
		'deleted'   => _n( '%s Maps permanently deleted.', '%s Maps permanently deleted.', $bulk_counts['deleted'], 'anemonedb-settings' ),
		/* translators: %s: Number of Maps. */
		'trashed'   => _n( '%s Maps moved to the Trash.', '%s Maps moved to the Trash.', $bulk_counts['trashed'], 'anemonedb-settings' ),
		/* translators: %s: Number of Maps. */
		'untrashed' => _n( '%s Maps restored from the Trash.', '%s Maps restored from the Trash.', $bulk_counts['untrashed'], 'anemonedb-settings' ),
	];

	return $bulk_messages;
}
add_filter( 'bulk_post_updated_messages', 'map_bulk_updated_messages', 10, 2 );

// Register custom taxonomies
function anemonedb_taxonomies_init() {
	register_taxonomy( 'meshcode2', [ 'sample' ], [
		'labels'                => [
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
		'labels'                => [
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
		'labels'                => [
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
		'labels'                => [
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
function meshcode2_updated_messages( $messages ) {

	$messages['meshcode2'] = [
		0 => '', // Unused. Messages start at index 1.
		1 => __( 'Meshcode2 added.', 'anemonedb-settings' ),
		2 => __( 'Meshcode2 deleted.', 'anemonedb-settings' ),
		3 => __( 'Meshcode2 updated.', 'anemonedb-settings' ),
		4 => __( 'Meshcode2 not added.', 'anemonedb-settings' ),
		5 => __( 'Meshcode2 not updated.', 'anemonedb-settings' ),
		6 => __( 'Meshcode2s deleted.', 'anemonedb-settings' ),
	];

	return $messages;
}
add_filter( 'term_updated_messages', 'meshcode2_updated_messages' );

/**
 * Sets the post updated messages for the `project` taxonomy.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `project` taxonomy.
 */
function project_updated_messages( $messages ) {

	$messages['project'] = [
		0 => '', // Unused. Messages start at index 1.
		1 => __( 'Projects added.', 'anemonedb-settings' ),
		2 => __( 'Projects deleted.', 'anemonedb-settings' ),
		3 => __( 'Projects updated.', 'anemonedb-settings' ),
		4 => __( 'Projects not added.', 'anemonedb-settings' ),
		5 => __( 'Projects not updated.', 'anemonedb-settings' ),
		6 => __( 'Projects deleted.', 'anemonedb-settings' ),
	];

	return $messages;
}
add_filter( 'term_updated_messages', 'project_updated_messages' );

/**
 * Sets the post updated messages for the `taxon` taxonomy.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `taxon` taxonomy.
 */
function taxon_updated_messages( $messages ) {

	$messages['taxon'] = [
		0 => '', // Unused. Messages start at index 1.
		1 => __( 'Taxa added.', 'anemonedb-settings' ),
		2 => __( 'Taxa deleted.', 'anemonedb-settings' ),
		3 => __( 'Taxa updated.', 'anemonedb-settings' ),
		4 => __( 'Taxa not added.', 'anemonedb-settings' ),
		5 => __( 'Taxa not updated.', 'anemonedb-settings' ),
		6 => __( 'Taxas deleted.', 'anemonedb-settings' ),
	];

	return $messages;
}
add_filter( 'term_updated_messages', 'taxon_updated_messages' );

/**
 * Sets the post updated messages for the `yearmonth` taxonomy.
 *
 * @param  array $messages Post updated messages.
 * @return array Messages for the `yearmonth` taxonomy.
 */
function yearmonth_updated_messages( $messages ) {

	$messages['yearmonth'] = [
		0 => '', // Unused. Messages start at index 1.
		1 => __( 'YearMonths added.', 'anemonedb-settings' ),
		2 => __( 'YearMonths deleted.', 'anemonedb-settings' ),
		3 => __( 'YearMonths updated.', 'anemonedb-settings' ),
		4 => __( 'YearMonths not added.', 'anemonedb-settings' ),
		5 => __( 'YearMonths not updated.', 'anemonedb-settings' ),
		6 => __( 'YearMonths deleted.', 'anemonedb-settings' ),
	];

	return $messages;
}
add_filter( 'term_updated_messages', 'yearmonth_updated_messages' );

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

// 
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
