<?php
/**
 * Plugin Name:     ANEMONEDB Settings
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
	anemonedb_post_type_init();
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
add_action('admin_notices', 'anemonedb_admin_notice_callback');

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
		$bp->signup->errors['signup_username'] = __( 'ERROR!: Your Name is too long.', 'buddypress' );
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

// Generate and save data download password
function anemonedb_save_dd_pass() {
	if (!is_user_logged_in()) {
		return;
	}
	global $wpdb;
	$user_id = get_current_user_id();
	$user_info = get_userdata($user_id);
	$table_name = $wpdb->prefix . 'anemonedb_dd_users';
	if (isset($_POST['generate_password'])) {
		$password = wp_generate_password(12, false, false);
		$hashed_password = wp_hash_password($password);
		$expiry_time = time() + 86400;
		$wpdb->replace(
			$table_name,
			[
				'user_login' => $user_info->user_login,
				'dd_pass' => $hashed_password,
				'dd_pass_expiry' => $expiry_time
			],
			['%s', '%s', '%d']
		);
		echo '<p>Your new password: <strong>' . esc_html($password) . '</strong></p>';
		echo '<p>Note that this password will not be displayed again.</p>';
		echo '<p>If you lost password, please regenerate.</p>';
	}
}
add_action('bp_core_general_settings_after_save', 'anemonedb_save_dd_pass');

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
	echo '<h2 class="general-settings-screen screen-heading">ANEMONE DB Data Download Password</h2>';
	echo '<form method="post">';
	if ($data && $data->dd_pass_expiry > time()) {
		echo '<p>Expires at: ' . date('Y-m-d H:i:s', $data->dd_pass_expiry) . '</p>';
		echo '<button type="submit" name="generate_password">Regenerate</button>';
	} else {
		echo '<p>Password: <input type="text" value="Undefined yet" readonly></p>';
		echo '<button type="submit" name="generate_password">Generate</button>';
	}
	echo '</form>';
}
add_action('bp_core_general_settings_before_submit', 'anemonedb_display_dd_pass_section', 1);

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
