<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

include_once('MeritClient.php');
include_once('MeritSalesInvoice.php');
include_once('MeritApi.php');
include_once('MeritArticle.php');

class MeritClass
{
    public static function orderStatusProcessing($order_id)
    {
        error_log("Order $order_id changed status. Checking if sending to Merit");
        //try catch makes sure your store will operate even if there are errors
        try {
            $order = wc_get_order($order_id);
            if (strlen(get_post_meta($order_id, 'merit_invoice_id', true)) > 0) {
                error_log("Merit order $order_id already sent, not sending again, SA id="
                    . get_post_meta($order_id, 'merit_invoice_id', true));

                return; //Merit order is already created
            }

            $meritClient       = new MeritClient($order);
            $clientId          = $meritClient->getClient();
            $meritSalesInvoice = new MeritSalesInvoice($order, $clientId);

            $invoice = $meritSalesInvoice->saveInvoice();
            update_post_meta($order_id, 'merit_invoice_id', $invoice['invoice']['InvoiceId']);
            error_log("Merits sales invoice created for order $order_id - " . $invoice['invoice']['InvoiceId']);
            $order->add_order_note("Invoice sent to Merit: " . $invoice['invoice']['InvoiceId']);

            $invoiceIdsString = get_option('merit_failed_orders');
            $invoiceIds       = json_decode($invoiceIdsString);
            if (is_array($invoiceIds) && array_key_exists($order_id, $invoiceIds)) {
                unset($invoiceIds[$order_id]);
                update_option('merit_failed_orders', json_encode($invoiceIds));
                error_log("Removed $order_id from failed orders array");
            }
        } catch (Exception $exception) {
            error_log("Merit error: " . $exception->getMessage() . " " . $exception->getTraceAsString());

            $invoiceIdsString = get_option('merit_failed_orders');
            $invoiceIds       = json_decode($invoiceIdsString);
            if (is_array($invoiceIds)) {
                error_log("Adding $order_id to failed orders array $invoiceIdsString to be retried later");
                $invoiceIds[$order_id] = $order_id;
                update_option('merit_failed_orders', json_encode($invoiceIds));
            } else {
                error_log("Adding $order_id to new failed orders array. previously $invoiceIdsString");
                $invoiceIds = [$order_id => $order_id];
                update_option('merit_failed_orders', json_encode($invoiceIds));
            }

            wp_schedule_single_event(time() + 129600, 'merit_retry_failed_job');
            $order->add_order_note("Invoice sending to Merit failed: " . $exception->getMessage());
        }
    }

    public static function retryFailedOrders()
    {
        $invoiceIdsString = get_option('merit_failed_orders');
        error_log("Retrying orders $invoiceIdsString");

        $retryCount = json_decode(get_option('merit_failed_orders_retries'));
        if (!is_array($retryCount)) {
            $retryCount = [];
        }

        $invoiceIds = json_decode($invoiceIdsString);

        if (is_array($invoiceIds)) {
            update_option('merit_failed_orders', json_encode([]));
            foreach ($invoiceIds as $id) {
                if (array_key_exists($id, $retryCount)) {
                    if ($retryCount[$id] > 3) {
                        error_log("Order $id has sync been retried over 3 times, dropping");
                    } else {
                        $retryCount[$id]++;
                    }
                } else {
                    $retryCount[$id] = 1;
                }
                error_log("Retrying sending order $id");
                MeritClass::orderStatusProcessing($id);
            }
            update_option('merit_failed_orders_retries', json_encode($retryCount));
        } else {
            error_log("Unable to parse failed orders: $invoiceIdsString");
        }
    }

    public static function saveSettings()
    {
        $unSanitized               = json_decode(file_get_contents('php://input'));
        $settings                  = new stdClass();
        $settings->apiKey          = sanitize_text_field($unSanitized->apiKey);
        $settings->apiSecret       = sanitize_text_field($unSanitized->apiSecret);
        $settings->defaultShipping = sanitize_text_field($unSanitized->defaultShipping);
        $settings->defaultPayment  = sanitize_text_field($unSanitized->defaultPayment);
        $settings->vat_number_meta = sanitize_text_field($unSanitized->vat_number_meta);

        if (!$settings->defaultShipping) {
            $settings->defaultShipping = "shipping";
        }

        $settings->showAdvanced = $unSanitized->showAdvanced == true;

        $settings->paymentMethodsPaid = new stdClass();
        foreach ($unSanitized->paymentMethodsPaid as $key => $method) {
            if (in_array($key, self::getAvailablePaymentMethods())) {
                $settings->paymentMethodsPaid->$key = $unSanitized->paymentMethodsPaid->$key == true;
            }
        }

        $settings->currencyBanks = [];
        foreach ($unSanitized->currencyBanks as $currencyBank) {
            $newCurrencyBank                 = new stdClass();
            $newCurrencyBank->payment_method = sanitize_text_field($currencyBank->payment_method);
            $newCurrencyBank->currency_code  = sanitize_text_field($currencyBank->currency_code);
            $newCurrencyBank->currency_bank  = sanitize_text_field($currencyBank->currency_bank);
            if (!$newCurrencyBank->currency_code || !$newCurrencyBank->currency_bank) {
                continue;
            }
            array_push($settings->currencyBanks, $newCurrencyBank);
        }

        $allowedStatuses = [
            'pending',
            'processing',
            'completed',
            'on-hold'
        ];

        $settings->statuses = [];
        foreach ($unSanitized->statuses as $status) {
            if (in_array($status, $allowedStatuses)) {
                $settings->statuses[] = $status;
            }
        }

        update_option('merit_settings', json_encode($settings));

        wp_send_json(['status' => "OK", 'settings' => $settings]);
    }

    public static function getSettings()
    {
        self::convertOldSettings();

        $currentSettings = json_decode(get_option('merit_settings') ? get_option('merit_settings') : "");

        if (!$currentSettings) {
            $currentSettings = new stdClass();
        }
        if (!isset($currentSettings->vat_number_meta)) {
            $currentSettings->vat_number_meta = "vat_number";
        }
        if (!isset($currentSettings->paymentMethods) || !is_object($currentSettings->paymentMethods)) {
            $currentSettings->paymentMethods = new stdClass();
        }
        if (!isset($currentSettings->paymentMethodsPaid) || !is_object($currentSettings->paymentMethodsPaid)) {
            $currentSettings->paymentMethodsPaid = new stdClass();
        }
        if (!isset($currentSettings->countryObjects) || !is_array($currentSettings->countryObjects)) {
            $currentSettings->countryObjects = [];
        }
        if (!isset($currentSettings->currencyBanks) || !is_array($currentSettings->currencyBanks)) {
            $currentSettings->currencyBanks = [];
        }
        if (!isset($currentSettings->statuses) || !is_array($currentSettings->statuses)) {
            $currentSettings->statuses = [
                'processing',
                'completed'
            ];
        }

        return $currentSettings;
    }

    public static function options_page_html()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <style>
            .form-table th, .form-table td {
                padding-top: 10px;
                padding-bottom: 10px;
            }
        </style>
        <div id="merit-admin" class="wrap">
            <h1><?= esc_html(get_admin_page_title()); ?></h1>
            <hr>

            <button @click="saveSettings" class="button-primary woocommerce-save-button" :disabled="!formValid">
                Save settings
            </button>
            <div v-show="!formValid" class="notice notice-error">
                <small>Please review all settings</small>
            </div>
            <div class="notice notice-error" v-show="!settings.apiKey">
                <small>Missing Merit public key</small>
            </div>
            <div class="notice notice-error" v-show="!settings.apiSecret">
                <small>Merit private key</small>
            </div>
            <div class="notice notice-error" v-show="!settings.defaultPayment">
                <small>Merit payments default bank account name</small>
            </div>

            <div v-if="!formFieldsValidated">
                <div class="notice notice-error" v-for="err in errors.items">{{err.msg}}</div>
            </div>

            <h2>General settings</h2>
            <table class="form-table">
                <tr valign="middle">
                    <th>Merit public key</th>
                    <td>
                        <input size="50"
                               data-vv-name="apiKey"
                               v-validate="'required'"
                               v-bind:class="{'notice notice-error':errors.first('apiKey')}"
                               v-model="settings.apiKey"/>
                    </td>
                </tr>

                <tr valign="middle">
                    <th>Merit private key</th>
                    <td>
                        <input size="50"
                               type="password"
                               data-vv-name="apiSecret"
                               v-validate="'required'"
                               v-bind:class="{'notice notice-error':errors.first('apiSecret')}"
                               v-model="settings.apiSecret"/>
                    </td>
                </tr>

                <tr valign="middle">
                    <th>Merit shipping article code</th>
                    <td>
                        <input size="50"
                               data-vv-name="defaultShipping"
                               v-bind:class="{'notice notice-error':errors.first('defaultShipping')}"
                               v-model="settings.defaultShipping"/>
                    </td>
                </tr>
                <tr valign="middle">
                    <th>Merit payments default bank account name</th>
                    <td>
                        <input size="50"
                               data-vv-name="defaultPayment"
                               v-validate="'required'"
                               v-bind:class="{'notice notice-error':errors.first('defaultPayment')}"
                               v-model="settings.defaultPayment"/>
                    </td>
                </tr>

                <tr valign="middle">
                    <th>Show advanced settings</th>
                    <td>
                        <input type="checkbox" v-model="settings.showAdvanced"/>
                    </td>
                </tr>
            </table>


            <div v-show="settings.showAdvanced">
                <hr>

                <h2>Order statuses to send to Merit as Invoice (Müügiarve)</h2>
                <small>If none selected then default Processing and Completed are used. Use CTRL+click to choose
                    multiple values
                </small>
                <br><br>
                <select v-model="settings.statuses" multiple>
                    <option value="pending">Pending</option>
                    <option value="processing">Processing</option>
                    <option value="on-hold">On hold</option>
                    <option value="completed">Completed</option>
                </select>

                <h2>Vat number meta field</h2>
                <small>Order meta field that contains company VAT number if one exists. Default vat_number. If meta
                    field does not exists then client VAT number will not be sent to the Merit
                    (Optional)</small>
                <br>
                <input size="20" v-model="settings.vat_number_meta"/>
                <br><br>

                <hr>
                <h2>Payment methods</h2>
                <small>Configure which payment methods are paid immediately and invoices can be created with payments
                </small>
                <table class="form-table">
                    <tr valign="top" v-for="(method, title) in paymentMethods">
                        <th>Method: {{title}}</th>
                        <td>
                            <label>Mark paid? </label>
                            <input type="checkbox" v-model="settings.paymentMethodsPaid[method]">
                        </td>
                    </tr>
                </table>

                <br>
                <br>
                <hr>
                <h2>Bank accounts</h2>
                <small>If currency and bank account mapping is set then given bank account name will be used for bank
                    payment
                    entry
                </small>
                <table class="form-table">
                    <thead>
                    <tr>
                        <th>Payment method</th>
                        <th>Currency code</th>
                        <th>Merit bank account name</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tr valign="middle" v-for="(item, index) in settings.currencyBanks">
                        <th>
                            <select v-model="settings.currencyBanks[index].payment_method">
                                <option v-for="(method, title) in paymentMethods" :value="method">{{title}}</option>
                            </select>
                        </th>
                        <td>
                            <a @click="removeCurrency(index)">X</a>
                            <input :data-vv-name="'currency_code_'+index"
                                   v-validate="{regex: /^[A-Z]{3}$/}"
                                   v-bind:class="{'notice notice-error':errors.first('currency_code_'+index)}"
                                   v-model="settings.currencyBanks[index].currency_code"
                                   placeholder="EUR"/>
                        </td>
                        <td>
                            <input size="30"
                                   :data-vv-name="'currency_bank_'+index"
                                   v-validate="{min: 2}"
                                   v-bind:class="{'notice notice-error':errors.first('currency_bank_'+index)}"
                                   v-model="settings.currencyBanks[index].currency_bank"
                                   placeholder="LHV EUR"/>
                        </td>
                        <td></td>
                    </tr>
                </table>
                <button @click="newCurrency" class="button-primary woocommerce-save-button">New mapping
                </button>
            </div>

            <br>
            <hr>
            <button @click="saveSettings" class="button-primary woocommerce-save-button" :disabled="!formValid">
                Save settings
            </button>

        </div>
        <?php
    }

    public static function enqueueScripts()
    {
        wp_register_script('merit_vue_js', plugins_url('js/merit-vue.js', __FILE__));
        wp_register_script('merit_axios_js', plugins_url('js/merit-axios.min.js', __FILE__));
        wp_register_script('merit_app_js', plugins_url('js/merit-app.js', __FILE__));
        wp_register_script('merit_vee_validate', plugins_url('js/merit-vee-validate.js', __FILE__));
        wp_register_script('merit_mini_toastr', plugins_url('js/merit-mini-toastr.js', __FILE__));

        wp_enqueue_script('merit_mini_toastr');
        wp_enqueue_script('merit_vue_js');
        wp_enqueue_script('merit_axios_js');
        wp_enqueue_script('merit_vee_validate', false, ['merit_vue_js'], null, true);
        wp_enqueue_script('merit_app_js', false, ['merit_vue_js', 'merit_axios_js', 'merit_mini_toastr'], null, true);


        wp_localize_script("merit_app_js",
            'merit_settings',
            [
                'ajaxUrl'        => admin_url('admin-ajax.php'),
                'settings'       => self::getSettings(),
                'paymentMethods' => self::getAvailablePaymentMethods()
            ]
        );
    }

    public static function optionsPage()
    {
        add_submenu_page('woocommerce', 'Merit settings', "Merit", 'manage_woocommerce',
            'Merit', 'MeritClass::options_page_html');
    }

    public static function getAvailablePaymentMethods()
    {
        $gateways         = WC()->payment_gateways->payment_gateways();
        $enabled_gateways = [];

        if ($gateways) {
            foreach ($gateways as $gateway) {
                if ($gateway->enabled == 'yes') {
                    $enabled_gateways[$gateway->title] = $gateway->id;
                }
            }
        }

        return $enabled_gateways;
    }

    //not very expensive to run every time when getting Merit settings, better safe than sorry
    public static function convertOldSettings()
    {
        if (get_option('merit_api_pk')) {
            $settings                  = new stdClass();
            $settings->apiKey          = get_option('merit_api_pk');
            $settings->apiSecret       = get_option('merit_api_sk');
            $settings->defaultShipping = get_option('merit_api_shipping_code');
            $settings->defaultPayment  = get_option('merit_api_payment_account');
            update_option('merit_settings', json_encode($settings));
            delete_option('merit_api_pk');
            delete_option('merit_api_sk');
            delete_option('merit_api_shipping_code');
            delete_option('merit_api_payment_account');
        }

        $settings = json_decode(get_option('merit_settings')) ? json_decode(get_option('merit_settings')) : new stdClass();

        $gateways = WC()->payment_gateways->payment_gateways() ? WC()->payment_gateways->payment_gateways() : [];

        foreach ($gateways as $gateway) {
            $title = $gateway->title;
            $id    = $gateway->id;
            //move paid methods over to ID-s from title
            if (property_exists($settings, 'paymentMethodsPaid')) {
                if (property_exists($settings->paymentMethodsPaid, $title)) {
                    $settings->paymentMethodsPaid->$id = $settings->paymentMethodsPaid->$title;
                    unset($settings->paymentMethodsPaid->$title);
                }
            }

            //move currency bank accounts over to ID-s from title
            if (property_exists($settings, 'currencyBanks')) {
                $newCurrencyBanks = [];
                foreach ($settings->currencyBanks as $key => $value) {
                    if ($value->payment_method === $title) {
                        $value->payment_method = $id;
                    }
                    $newCurrencyBanks[] = $value;
                }
                $settings->currencyBanks = $newCurrencyBanks;
            }
        }
        update_option('merit_settings', json_encode($settings));
    }

    public static function loadAsyncClass()
    {
    }
}
