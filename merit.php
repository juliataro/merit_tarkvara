<?php
/**
 * Plugin Name: Merit_tarkvara
 * Plugin URI: https://github.com/smartman/woocommerce_smartaccounts
 * Description: This plugin creates sales invoices in the smartaccounts.ee Online Accounting Software after Woocommerce order creation
 * Version: 3.4
 * Author: Julia Taro
 * Author URI: https://marguspala.com
 * License: GPLv2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 4.8.0
 * Tested up to: 5.6
 */

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

include_once(ABSPATH . 'wp-admin/includes/plugin.php');

function smartaccounts_missing_wc_admin_notice()
{
    ?>
    <div class="notice notice-error">
        <p>SmartAccounts is WooCommerce plugin and requires WooCommerce plugin to be installed</p>
    </div>
    <?php
}

if (is_plugin_active('woocommerce/woocommerce.php')) {
    require_once('MeritClass.php');
    require_once('MeritArticleAsync.php');

    add_action('admin_enqueue_scripts', 'MeritClass::enqueueScripts');
    add_action('admin_menu', 'MeritClass::optionsPage');

    //if no configured invoice nor offer sending statuses configured then use default
    $settings      = json_decode(get_option('sa_settings'));
    $countStatuses = 0;
    if (isset($settings->statuses) && is_array($settings->statuses)) {
        foreach ($settings->statuses as $status) {
            $countStatuses++;
            add_action("woocommerce_order_status_$status", 'MeritClass::orderStatusProcessing');
        }
    }
    if (isset($settings->offer_statuses) && is_array($settings->offer_statuses)) {
        foreach ($settings->offer_statuses as $status) {
            $countStatuses++;
            add_action("woocommerce_order_status_$status", 'MeritClass::orderOfferStatusProcessing');
        }
    }
    if ($countStatuses === 0) {
        add_action('woocommerce_order_status_processing', 'MeritClass::orderStatusProcessing');
        add_action('woocommerce_order_status_completed', 'MeritClass::orderStatusProcessing');
    }

    add_action('sa_retry_failed_job', 'MeritClass::retryFailedOrders');

    add_action("wp_ajax_sa_save_settings", "MeritClass::saveSettings");
    add_action("wp_ajax_sa_sync_products", "MeritArticleAsync::syncSaProducts");
    add_action("wp_ajax_nopriv_sa_sync_products", "MeritArticleAsync::syncSaProducts");
    add_action('init', 'MeritClass::loadAsyncClass');
} else {
    add_action('admin_notices', 'smartaccounts_missing_wc_admin_notice');
}

function juliaTestib() {
    $order=wc_get_order(26450);

    $merit=new MeritClient($order);
    var_dump($merit->getClient());
}

add_action("wp_ajax_julia_merit", 'juliaTestib');

add_action("wp_ajax_nopriv_julia_merit", [MeritClient::class, 'getClient']);


