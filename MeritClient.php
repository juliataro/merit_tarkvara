<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class MeritClient
{

    /** @var WC_Order */
    protected $order;
    protected $isAnonymous;
    protected $email;
    protected $name;
    protected $country;
    protected $isCompany;
    protected $regNo;

    /** @var MeritApi */
    protected $api;
    protected $vatNumber;

    protected $generalUserName = "WooCommerce User";

    /* @param $order WC_Order
     */

    public function __construct($order)
    {
        $this->order   = $order;
        $this->country = $order->get_billing_country();
        if ($this->country == null || strlen($this->country) == 0) {
            $this->country = $order->get_shipping_country();
        }
        $this->email     = $order->get_billing_email();
        $this->isCompany = strlen($order->get_billing_company()) > 0;
        $firstName       = trim(strlen($order->get_shipping_first_name()) == 0 ? $order->get_billing_first_name() : $order->get_shipping_first_name());
        $lastName        = trim(strlen($order->get_shipping_last_name()) == 0 ? $order->get_billing_last_name() : $order->get_shipping_last_name());

        $this->isAnonymous = (!$firstName && !$lastName);

        if ($this->isCompany) {
            $this->name = trim($order->get_billing_company());
        } elseif ($this->isAnonymous) {
            $this->name = trim("$this->generalUserName $this->country");
        } else {
            $this->name = "$firstName $lastName";
        }

        $settings        = json_decode(get_option("merit_settings"));
        $this->vatNumber = get_post_meta($order->get_id(), isset($settings->vat_number_meta) ? $settings->vat_number_meta : 'vat_number', true);

        $this->api = new MeritApi();
    }

    /* @return Merit Customer ID
     */
    public function getClient(): ?string
    {
        $endpoint = "getcustomers";

        if ($this->order->meta_exists('_billing_regno')) {
            $regNo     = $this->order->get_meta('_billing_regno', true);
            $requestData = (object)['RegNo' => $regNo];
            $response    = $this->api->sendRequest($requestData, $endpoint);
        } else {
            // Remove all that are not company "main" part of name
            $name = $this->getNormalizedName($this->name);

            $requestData = (object)[
                'Name' => $name
            ];

            // TODO Otsi Meritist kõik firmad selle nimega
            $response = $this->api->sendRequest($requestData, $endpoint);

        }


        if(count($response) > 0) {
            return $response[0]["CustomerId"];
        } else{
            return $this->addNewMeritClient($this->email, $this->name, $this->country);
        }

}
        // TODO Võta $response välja esimene vaste ja kui ühtegi firmat ei leia, siis tee uus firma


//        if ($this->isAnonymous) {
//            return $this->getAnonymousClient($response, $this->country, $this->name);
//        } elseif ($this->regNo !== 0 || $this->name !== 0) {
//            return $this->getLoggedInClient($response, $this->country, $this->name, $this->email);
//        } else {
//            return $this->addNewMeritClient($this->email, $this->name, $this->country);
//        }


    /**
     * Returns Merit general client for this country. Creates new if it does not exist yet.
     */

    private function getAnonymousClient($response, $country, $name)
    {
        foreach ($response as $client) {

            // Here $client is another entry form Merit $response
            if ($this->isGeneralCountryClient($response, $country)) {
                return $client[0]["CustomerId"];
            }
        }

        return $this->addNewMeritClient(null, $name, $country);
    }

    // TODO needs doublechecking
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

    private function addNewMeritClient($email, $name, $country)
    {
        $endpoint = "sendcustomer";
        if ($country !== "EE") {
            $NotTDCustomer = true; // foreign company
        } elseif ($this->isCompany) {
            $NotTDCustomer = false; // Eesti firma
        } else {
            $NotTDCustomer = true; //Eestist ja ei ole firma
        }

        //maybe has PHP 5 and ?? operator is missing
        $city       = $this->order->get_billing_city() ? $this->order->get_billing_city() :
            ($this->order->get_shipping_city() ? $this->order->get_shipping_city() : "");
        $state      = $this->order->get_billing_state() ? $this->order->get_billing_state() :
            ($this->order->get_shipping_state() ? $this->order->get_shipping_state() : "");
        $postalCode = $this->order->get_billing_postcode() ? $this->order->get_billing_postcode() :
            ($this->order->get_shipping_postcode() ? $this->order->get_shipping_postcode() : "");
        $address1   = substr($this->order->get_billing_address_1() ? $this->order->get_billing_address_1() :
            ($this->order->get_shipping_address_1() ? $this->order->get_shipping_address_1() : ""), 0, 64);
        $address2   = substr($this->order->get_billing_address_2() ? $this->order->get_billing_address_2() :
            ($this->order->get_shipping_address_2() ? $this->order->get_shipping_address_2() : ""), 0, 64);

        $requestData                = new stdClass();
        $requestData->Name          = $name;
        $requestData->NotTDCustomer = $NotTDCustomer;
        $requestData->Address       = "$address1" . ($address2 ? " $address2" : "");
        $requestData->CountryCode   = $country;
        $requestData->County        = $state;
        $requestData->City          = $city;
        $requestData->PostalCode    = $postalCode;

        if ($email != null) {
            $requestData->Email = $email;
        }

        $phone = $this->order->get_billing_phone();
        if ($phone) {
            $requestData->PhoneNo = $phone;
        }

        if ($this->vatNumber) {
            $requestData->VatRegNo = $this->vatNumber;
        }

        // Sisestab uue ettevõtte
        $createResponse = $this->api->sendRequest($requestData, $endpoint);
        return $createResponse["Id"];
    }


    /**
     * Returns Merit client for the logged in user in the order. Creates new if it does not exist yet.
     */
    private function getLoggedInClient($clients, $country, $name, $email)
    {
        if (count($clients) > 0) {
            return $this->addNewMeritClient($email, $name, $country);
        }

        foreach ($clients as $client) {
            // TODO Make sure $this->name is correct here!
            var_dump($client, $this->name, $client->name);
            // If name has exact match
            if (strtolower($client->Name) === strtolower($this->name)) {
                return $client[0]["CustomerId"];
            }

            // "OÜ Firnamini" is same as "Firmanimi OÜ"
            if ($this->isCompany && (strtolower($this->getNormalizedName($this->name)) === strtolower($client->Name))) {
                return $client[0]["CustomerId"];
            }
        }


        return $this->addNewMeritClient($email, $name, $country);
    }

    /**
     * @return array|string|string[]|null
     */
    public function getNormalizedName($name)
    {
        $pres = ['oü', 'as', 'fie', 'mtü', 'kü', 'osaühing'];
        foreach ($pres as $pre) {
            $name = preg_replace("/(.*)( " . $pre . "| " . mb_strtoupper($pre) . ")?$/imU", '$1', $name);
            $name = preg_replace("/^(" . $pre . " |" . mb_strtoupper($pre) . " )?(.*)/im", '$2', $name);
        }
        return $name;
    }
}