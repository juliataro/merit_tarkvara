<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class SmartAccountsClient
{

    /** @var WC_Order */
    protected $order;
    protected $isAnonymous;
    protected $email;
    protected $name;
    protected $country;
    protected $isCompany;

    /** @var SmartAccountsApi */
    protected $api;
    protected $vatNumber;
    protected $NotTDCustomer;

    protected $generalUserName = "WooCommerce User";


    /**
     * SmartAccountsClient constructor.
     *
     /* @param $order WC_Order
     */



    public function __construct($order)
    {
        $this->order = $order;
        $this->country = $order->get_billing_country();
        if ($this->country == null || strlen($this->country) == 0) {
            $this->country = $order->get_shipping_country();
        }
        $this->email = $order->get_billing_email();
        $this->isCompany = strlen($order->get_billing_company()) > 0;
        $firstName = trim(strlen($order->get_shipping_first_name()) == 0 ? $order->get_billing_first_name() : $order->get_shipping_first_name());
        $lastName = trim(strlen($order->get_shipping_last_name()) == 0 ? $order->get_billing_last_name() : $order->get_shipping_last_name());

        $this->isAnonymous = (!$firstName && !$lastName);

        if ($this->isCompany) {
            $this->name = trim($order->get_billing_company());
        } elseif ($this->isAnonymous) {
            $this->name = trim("$this->generalUserName $this->country");
        } else {
            $this->name = "$firstName $lastName";
        }

        $settings = json_decode(get_option("sa_settings"));
        $this->vatNumber = get_post_meta($order->get_id(), isset($settings->vat_number_meta) ? $settings->vat_number_meta : 'vat_number', true);

        $this->api = new SmartAccountsApi();
    }

    /**
     * This method will look for SmartAccounts clients and if no customers related to
     * current WooCommerce order do not exist then will create new client.
     * Comparison is done with name, country and e-mail
     *
     /* @return SmartAccountsClient
     */
    public function getClient()
    {
        $endpoint = "getcustomers";

        if ($this->order->meta_exists('_billing_regno')) {
            $regCode = $this->order->get_meta('_billing_regno', true);
            $requestData = ['RegNo' => $regCode];
            $response = $this->api->sendRequest($requestData, $endpoint);
        } else {
            // Remove all that are not company "main" part of name
            $pres = ['oü', 'as', 'fie', 'mtü', 'kü'];
            $name = $this->name;
            foreach ($pres as $pre) {
                $name = preg_replace("/(.*)( " . $pre . "| " . mb_strtoupper($pre) . ")?$/imU", '$1', $name);
                $name = preg_replace("/^(" . $pre . " |" . mb_strtoupper($pre) . " )?(.*)/im", '$2', $name);
            }


            $requestData = urlencode($name);

            // TODO Otsi Meritist kõik firmad selle nimega

            $response = $this->api->sendRequest($requestData, $endpoint);

            // wp_send_json($response);
            //echo 'test';die;
        }

        // TODO Võta $response välja esimene vaste ja kui ühtegi firmat ei leia, siis tee uus firma
        if ($this->isAnonymous) {
            return $this->getAnonymousClient($response["Customers"],  $this->name, $this->country);
        } else {
            return $this->addNewSaClient($this->name, $this->NotTDCustomer, $this->country);
        }
    }

    /**
     * Returns SmartAccounts general client for this country. Creates new if it does not exist yet.
     */
    private function getAnonymousClient($response, $country, $name)
    {
        foreach ($response as $client) {
            // Here $client is another entry form Merit $response
            if ($this->isGeneralCountryClient($response, $country)) {
                return $client;
            }
        }

        return $this->addNewSaClient(null, $name, $country);
    }

    private function isGeneralCountryClient($response, $country)
    {
        if (!array_key_exists("address", $response)) {
            if ($response['name'] == $this->generalUserName) {
                return true;
            } else {
                return false;
            }
        }

        foreach ($response["address"] as $key => $value) {
            if ($key == "country" && $value == $country && $this->name == $response["name"]) {
                return true;
            }
        }

        return false;
    }

    private function addNewSaClient($email, $name, $country)
    {
        $endpoint = "sendcustomer";
        $NotTDCustomer = "NotTDCustomer";

        //maybe has PHP 5 and ?? operator is missing
        $city = $this->order->get_billing_city() ? $this->order->get_billing_city() :
            ($this->order->get_shipping_city() ? $this->order->get_shipping_city() : "");
        $state = $this->order->get_billing_state() ? $this->order->get_billing_state() :
            ($this->order->get_shipping_state() ? $this->order->get_shipping_state() : "");
        $postalCode = $this->order->get_billing_postcode() ? $this->order->get_billing_postcode() :
            ($this->order->get_shipping_postcode() ? $this->order->get_shipping_postcode() : "");
        $address1 = substr($this->order->get_billing_address_1() ? $this->order->get_billing_address_1() :
            ($this->order->get_shipping_address_1() ? $this->order->get_shipping_address_1() : ""), 0, 64);
        $address2 = substr($this->order->get_billing_address_2() ? $this->order->get_billing_address_2() :
            ($this->order->get_shipping_address_2() ? $this->order->get_shipping_address_2() : ""), 0, 64);


        $requestData          = new stdClass();
        $requestData->name    = $name;
        $requestData          = $NotTDCustomer;
        $requestData->address = (object)[
            "country"    => $country,
            "city"       => $city,
            "county"     => $state,
            "address1"   => $address1,
            "address2"   => $address2,
            "postalCode" => $postalCode
        ];


        if ($email != null) {
            $requestData->contacts = [
                [
                    "type"  => "EMAIL",
                    "value" => $email
                ]
            ];
        }

        $phone = $this->order->get_billing_phone();
        if ($phone) {
            if (!$requestData->contacts) {
                $requestData->contacts = [];
            }
            $requestData->contacts[] =
                [
                    "type"  => "PHONE",
                    "value" => $phone
                ];
        }


        if ($this->vatNumber) {
            $requestData->vatNumber = $this->vatNumber;
        }


        $createResponse = $this->api->sendRequest($requestData, $endpoint);
        $requestData       = $createResponse["Id"];
        $client         = $this->api->sendRequest($requestData, "getcustomers");

        return $client["customers"][0];


    }


    /**
     * Returns SmartAccounts client for the logged in user in the order. Creates new if it does not exist yet.
     */
    private function getLoggedInClient($clients, $country, $name, $email)
    {
        if (!is_array($clients) || count($clients) == 0) {
            return $this->addNewSaClient($email, $name, $country);
        }

        foreach ($clients as $client) {
            //match client if name matches and is company or email also matches
            if (($this->isCompany || $this->hasEmail($client,
                        $email)) && strtolower($this->name) == strtolower($client["name"])) {
                return $client;
            }
        }

        return $this->addNewSaClient($email, $name, $country);
    }

    private function hasEmail($client, $email)
    {
        if (!array_key_exists("contacts", $client)) {
            return false;
        }
        foreach ($client["contacts"] as $contact) {
            if ($contact["type"] == "EMAIL" && $contact["value"] == $email) {
                return true;
            }
        }

        return false;
    }

}







