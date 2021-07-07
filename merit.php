<?php
/**
 * Plugin Name: Merit accounting software and WooCommerce integration
 * Description: This plugin creates sales invoices in the merit.ee Online Merit after Woocommerce order creation
 * Version: 1.0
 * Author: Julia Taro
 * Author URI: https://marguspala.com
 * License: GPLv2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 4.8.0
 * Tested up to: 1.0
 */

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

include_once(ABSPATH . 'wp-admin/includes/plugin.php');

function merit_missing_wc_admin_notice()
{
    ?>
    <div class="notice notice-error">
        <p>Merit is WooCommerce plugin and requires WooCommerce plugin to be installed</p>
    </div>
    <?php
}

if (is_plugin_active('woocommerce/woocommerce.php')) {
    require_once('MeritClass.php');

    add_action('admin_enqueue_scripts', [MeritClass::class, 'enqueueScripts']);
    add_action('admin_menu', 'MeritClass::optionsPage');

    $settings      = json_decode(get_option('merit_settings'));
    $countStatuses = 0;
    if (isset($settings->statuses) && is_array($settings->statuses)) {
        foreach ($settings->statuses as $status) {
            $countStatuses++;
            add_action("woocommerce_order_status_$status", 'MeritClass::orderStatusProcessing');
        }
    }

    if ($countStatuses === 0) {
        add_action('woocommerce_order_status_processing', 'MeritClass::orderStatusProcessing');
        add_action('woocommerce_order_status_completed', 'MeritClass::orderStatusProcessing');
    }

    add_action('merit_retry_failed_job', 'MeritClass::retryFailedOrders');

    add_action("wp_ajax_merit_save_settings", "MeritClass::saveSettings");
} else {
    add_action('admin_notices', 'merit_missing_wc_admin_notice');
}

