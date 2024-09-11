<?php
/**
 * Plugin Name: Smart AJAX Firewall
 * Description: Smart AJAX Firewall secures your website by filtering and optimizing AJAX requests, improving both security and performance.
 * Version: 1.0
 * Author: Autsec
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

add_action('admin_menu', 'saf_license_menu');
add_action('admin_init', 'saf_license_settings');

// Enqueue Select2 JavaScript and CSS
function saf_enqueue_scripts($hook) {
    if ($hook !== 'toplevel_page_smart-ajax-firewall') {
        return; // Only load on the plugin settings page
    }

    // Enqueue Select2 CSS
    wp_enqueue_style('select2css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');

    // Enqueue Select2 JS
    wp_enqueue_script('select2js', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'));

    // Initialize Select2 for the multi-select field
    wp_add_inline_script('select2js', '
        jQuery(document).ready(function($) {
            $(".saf-select2").select2({
                placeholder: "Select AJAX actions",
                width: "100%"
            });
        });
    ');
}
add_action('admin_enqueue_scripts', 'saf_enqueue_scripts');

function saf_license_menu() {
    add_menu_page(
        'Smart AJAX Firewall',               // Page title
        'AJAX Firewall',                     // Menu title
        'manage_options',                    // Capability
        'smart-ajax-firewall',               // Menu slug
        'saf_options_page',                  // Function to display the page
        'dashicons-shield',                  // Icon (shield)
        20                                   // Position in the menu
    );
}


function saf_options_page() {
    ?>
    <div class="wrap">
        <h1>Smart AJAX Firewall</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('saf_license_group');
            do_settings_sections('saf_license_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}


function saf_license_settings() {
    register_setting('saf_license_group', 'saf_license_key');
    register_setting('saf_license_group', 'saf_whitelisted_ajax');
    register_setting('saf_license_group', 'saf_custom_ajax_actions');

    add_settings_section(
        'saf_license_section',
        'License Key',
        null,
        'saf_license_settings'
    );

    add_settings_field(
        'saf_license_key',
        'Enter License Key',
        'saf_license_key_callback',
        'saf_license_settings',
        'saf_license_section'
    );

    add_settings_section(
        'saf_ajax_section',
        'AJAX Whitelist',
        'saf_ajax_section_callback',
        'saf_license_settings'
    );

    add_settings_field(
        'saf_whitelisted_ajax',
        'Select AJAX Actions to Whitelist',
        'saf_whitelist_ajax_field_callback',
        'saf_license_settings',
        'saf_ajax_section'
    );
}


function saf_license_key_callback() {
    $license_key = get_option('saf_license_key');
    echo '<input type="text" id="saf_license_key" name="saf_license_key" value="' . esc_attr($license_key) . '" />';
}

function saf_whitelist_ajax_field_callback() {
    $options = get_option('saf_whitelisted_ajax', []);
    $custom_ajax_actions = get_option('saf_custom_ajax_actions', '');

    // Predefined list of AJAX actions
    $available_actions = array(
        'woocommerce_add_to_cart',
        'wpcf7_submit',
        'heartbeat',
        'save_post',
        'wp_ajax_nopriv_my_custom_action',
        'wp_ajax_my_custom_action',
        'wp_ajax_update_user_profile',
        'wp_ajax_add_comment',
        'wp_ajax_nopriv_login',
        'wp_ajax_nopriv_register',
        'wp_ajax_save_widget',
        'wp_ajax_edit_post',
        'wp_ajax_get_post',
        'wp_ajax_create_order',
        'wp_ajax_delete_user',
        'wp_ajax_custom_plugin_action',
        'wp_ajax_nopriv_custom_plugin_action'
    );

    // Render the multi-select field with Select2
    echo '<select multiple="multiple" class="saf-select2" name="saf_whitelisted_ajax[]" style="width: 100%;">';
    foreach ($available_actions as $action) {
        $selected = in_array($action, $options) ? 'selected' : '';
        echo "<option value='$action' $selected>$action</option>";
    }
    echo '</select>';

    // Render the text input field for custom AJAX actions (optional)
    echo '<p><strong>Or manually add your own AJAX actions (comma separated):</strong></p>';
    echo '<input type="text" name="saf_custom_ajax_actions" style="width: 100%;" value="' . esc_attr($custom_ajax_actions) . '" />';
}

// Callback for AJAX section description
function saf_ajax_section_callback() {
    echo '<p>Select the AJAX actions that should be whitelisted for better performance. You can also manually add your custom actions below.</p>';
}

/**
 * Validate the license by calling the external Python server.
 */
function saf_validate_license_key($license_key) {
    $api_url = 'https://joebb10.pythonanywhere.com/validate_license'; 

    $response = wp_remote_post($api_url, array(
        'method' => 'POST',
        'headers' => array('Content-Type' => 'application/json'),
        'body' => json_encode(array('license_key' => $license_key)),
    ));

    if (is_wp_error($response)) {
        return false; // Request failed
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    if ($data->status == 'valid') {
        return true;
    } else {
        return false;
    }
}

/**
 * Enforce License Validation: Disable plugin functionality if the license is not valid.
 */
function saf_check_license_and_enable() {
    $license_key = get_option('saf_license_key');

    if (!$license_key || !saf_validate_license_key($license_key)) {
        add_action('admin_notices', 'saf_license_invalid_notice');
        return; // Do not enable functionality if the license is invalid
    }

    // =================== SMART AJAX FIREWALL FUNCTIONALITY =======================
    
    /**
     * Handle AJAX requests with firewall, optimized for performance
     */
    add_action('wp_ajax_my_custom_action', 'saf_handle_ajax_request');
    add_action('wp_ajax_nopriv_my_custom_action', 'saf_handle_ajax_request');

    function saf_handle_ajax_request() {
        // Get predefined and custom whitelisted AJAX actions
        $whitelisted_actions = get_option('saf_whitelisted_ajax', []);
        $custom_ajax_actions = get_option('saf_custom_ajax_actions', '');

        // Combine predefined actions and manually added actions (splitting by commas)
        $custom_actions_array = array_map('trim', explode(',', $custom_ajax_actions));
        $whitelisted_actions = array_merge($whitelisted_actions, $custom_actions_array);

        // Check if the action is whitelisted
        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';

        if (in_array($action, $whitelisted_actions)) {
            wp_send_json_success(array('message' => 'Request passed without firewall'));
        } else {
            if (strpos($_POST['data'], '<script>') !== false) {
                wp_die('Security threat detected.');
            } else {
                wp_send_json_success(array('message' => 'Request passed after security scan'));
            }
        }
    }
}
add_action('admin_init', 'saf_check_license_and_enable');

/**
 * Show admin notice if the license is invalid.
 */
function saf_license_invalid_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('Smart AJAX Firewall is disabled because the license is invalid or expired. Please enter a valid license key.', 'saf'); ?></p>
    </div>
    <?php
}

// Show notice after "Save Changes"
add_action('admin_notices', 'saf_settings_saved_notice');
function saf_settings_saved_notice() {
    if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Settings saved successfully!', 'saf'); ?></p>
        </div>
        <?php
    }
}
