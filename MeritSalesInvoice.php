<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class MeritSalesInvoice
{
    /** @var WC_Order */
    protected $order;

    public function __construct($order, $client)
    {
        $this->client = $client;
        $this->order  = $order;

        $this->api    = new MeritApi();
    }

    public function saveInvoice()
    {
        $endpoint = "sendinvoice";

        $email = "";
        $name = "";
        $country = "";

        $body              = new stdClass();
        $body->rows        = $this->addNewMeritClient($email, $name, $country);
        $body->inoviceNo   = $this->order->get_id();
        $body->rows        = $this->invoiceRow();

        $salesInvoice = $this->api->sendRequest($body, $endpoint);
        return ["invoice" => $salesInvoice, "rows" => $body->rows];
    }


    public function invoiceRow()
    {
        $rows     = [];
        $totalTax = $this->getTotalTax();
        $subTotal = $this->getOrderTotal();
        $vatPc    = round($totalTax * 100 / $subTotal);
        foreach ($this->order->get_items() as $item) {
            $product = $item->get_product();
                $row     = new stdClass();
                   $itemObject     = new stdClass();

                  $itemObject ->code     = $code;
                  $itemObject->description = $item->get_name();
                     $code = $product->get_sku();
                      if ($code == null || strlen($code) == 0) {
                        $code = $product->get_id();
            }
                  $itemObject->description = strlen($product->get_name()) == 0 ? $product->get_description() : $product->get_name();
            }

            if (strlen($itemObject->description) == 0) {
                $itemObject->description = $code;
            }
            // Remove unsupported UTF-8 multibyte characters.
            $itemObject->description = preg_replace('/[\xF0-\xF7].../s', '_', $itemObject->description);


            $row->quantity = $item->get_quantity();
            $rowPrice = $item->get_total() / $item->get_quantity();
            $row->price      = number_format($rowPrice, 2, ".", "");
            $row->vatPc      = $vatPc;

            $settings = json_decode(get_option("merit_settings"));
            if ($settings && $settings->objectId) {
                $itemObject->objectId = $settings->objectId;
            }

            $rows[] = $row;
         return $rows;


    }

    public function getTotalTax()
    {
        return floatval($this->order->get_total_tax());
    }

    public function getOrderTotal()
    {
        return $this->order->get_subtotal() + (float)$this->order->get_shipping_total() - $this->order->get_discount_total();
    }


    // TODO Add Client Data
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


}