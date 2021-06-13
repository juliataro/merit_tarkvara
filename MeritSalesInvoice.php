<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class MeritSalesInvoice
{
    /** @var WC_Order */
    protected $order;
    protected $clientId;

    /** @var MeritApi */
    protected $api;

    public function __construct(WC_Order $order, string $clientId)
    {
        $this->clientId = $clientId;
        $this->order    = $order;
        $this->api      = new MeritApi();
    }

    public function saveInvoice()
    {
        $endpoint = "sendinvoice";

        $orderRows = $this->getOrderRows();

        $body                 = new stdClass();
        $body->Customer       = (object)['Id' => $this->clientId];
        $body->InvoiceNo      = $this->getOrderTotal();
        $body->DocDate        = $this->order->get_date_created()->date('Ymd');
        $body->InvoiceNo      = "#" . $this->order->get_id();
        $body->InvoiceRow     = $orderRows['rows'];
        $body->TaxAmount      = [$orderRows['TaxAmount']];
        $body->RoundingAmount = $this->getRoundingAmount($orderRows['rows']);
        $body->TotalAmount    = $this->getOrderTotal();

//        wp_send_json($body);

        $salesInvoice = $this->api->sendRequest($body, $endpoint);
        wp_send_json($salesInvoice);
        return ["invoice" => $salesInvoice];
    }

    public function getRoundingAmount($rows)
    {
        $rowsTotalCents = 0;
        foreach ($rows as $row) {
            $rowCents       = intval(floatval($row->Price) * 100, 2);
            $rowsTotalCents += $rowCents;
            $rowsTotalCents += intval($rowCents * $this->getVatPc() / 100);
        }

        $roundingAmount = number_format(($this->getOrderTotal() * 100 + $this->getTotalTax() * 100 - $rowsTotalCents) / 100,
            2);

        return $roundingAmount;
    }

    public function getOrderRows(): array
    {
        $taxId = $this->getTaxId();

        $rows = [];
        foreach ($this->order->get_items() as $item) {
            $row             = new stdClass();
            $codeDescription = $this->getCodeDescription($item);
            $row->Item       = (object)[
                'Code'        => $codeDescription['code'],
                'Description' => $codeDescription['description'],
                'Type'        => 3 // needs dynamic calculation
            ];
            $row->Quantity   = $item->get_quantity();
            $rowPrice        = $item->get_total() / $item->get_quantity();
            $row->Price      = number_format($rowPrice, 2, ".", "");
            $row->TaxId      = $taxId;

            $rows[] = $row;
        }

        if ($this->order->get_shipping_total() > 0) {
            $settings                = json_decode(get_option("merit_settings"));
            $itemObject              = new stdClass();
            $itemObject->code        = isset($settings->defaultShipping) ? $settings->defaultShipping : "shipping";
            $itemObject->description = "Woocommerce Shipping";
            $rows[]                  = $itemObject;
        }
        return [
            'rows'      => $rows,
            'TaxAmount' => (object)[
                'TaxId'  => $taxId,
                'Amount' => $this->getTotalTax()
            ]
        ];
    }

    protected function getVatPc()
    {
        $totalTax = $this->getTotalTax();
        $subTotal = $this->getOrderTotal();
        $vatPc    = round($totalTax * 100 / $subTotal);
        return $vatPc;
    }

    protected function getTaxId()
    {
        $vatPc = $this->getVatPc();
        $taxes = get_option('merit_tax_items');
        $taxId = null;
        if (!$taxes) {
            $taxes = $this->api->sendRequest(null, 'gettaxes');
            update_option('merit_tax_items', $taxes);
        }
        foreach ($taxes as $tax) {
            if (trim($tax['Name']) === "$vatPc% käibemaks") {
                $taxId = $tax['Id'];
                break;
            }
        }
        if (!$taxId) {
            $taxes = $this->api->sendRequest(null, 'gettaxes');
            update_option('merit_tax_items', $taxes);
            foreach ($taxes as $tax) {
                if (trim($tax['Name']) === "$vatPc% käibemaks") {
                    $taxId = $tax['Id'];
                    break;
                }
            }
        }

        return $taxId;
    }

    protected function getCodeDescription(WC_Order_Item $item)
    {
        $product     = $item->get_product();
        $description = $item->get_name();
        $code        = "wc_missing_product_" . $item->get_id();

        if ($product == null) {
            error_log("Merit product not found for order item " . $item->get_id());
        } else {
            $code = $product->get_sku();
            if ($code == null || strlen($code) == 0) {
                $code = "wc_product_" . $product->get_id();
            }

            $description = strlen($product->get_name()) == 0 ? $product->get_description() : $product->get_name();
        }

        if (strlen($description) == 0) {
            $description = $code;
        }
        // Remove unsupported UTF-8 multibyte characters.
        $description = preg_replace('/[\xF0-\xF7].../s', '_', $description);

        return [
            'code'        => $code,
            'description' => $description
        ];
    }

    public function getInvoiceRowItem()
    {
        foreach ($this->order->get_items() as $item) {
            $product = $item->get_product();

            $itemObject = new stdClass();
            if ($product == null) {
                error_log("Merit Product not found for order item " . $item->get_id());
                $itemObject->description = $item->get_name();
                $code                    = "wc_missing_product_" . $item->get_id();
            } else {
                $code = $product->get_sku();
                if ($code == null || strlen($code) == 0) {
                    $code = "wc_product_" . $product->get_id();
                }

                //in case
                $itemObject->description = strlen($product->get_name()) == 0 ? $product->get_description() : $product->get_name();
            }

            if (strlen($itemObject->description) == 0) {
                $itemObject->description = $code;
            }
            // Remove unsupported UTF-8 multibyte characters.
            $itemObject->code        = $code;
            $itemObject->description = preg_replace('/[\xF0-\xF7].../s', '_', $itemObject->description);

            $settings = json_decode(get_option("sa_settings"));
            if ($settings && $settings->objectId) {
                $itemObject->objectId = $settings->objectId;

            }
            $rows[] = $itemObject;
        }
        if ($this->order->get_shipping_total() > 0) {
            $settings                = json_decode(get_option("merit_settings"));
            $itemObject              = new stdClass();
            $itemObject->code        = isset($settings->defaultShipping) ? $settings->defaultShipping : "shipping";
            $itemObject->description = "Woocommerce Shipping";

            $settings = json_decode(get_option("merit_settings"));
            if ($settings && $settings->objectId) {
                $itemObject->objectId = $settings->objectId;
            }
            $rows[] = $itemObject;
        }
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
}
