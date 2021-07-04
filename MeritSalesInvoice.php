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
        $body->DocDate        = $this->order->get_date_created()->date('Ymd');
        $body->InvoiceNo      = "#" . $this->order->get_id();
        $body->InvoiceRow     = $orderRows['rows'];
        $body->TaxAmount      = [$orderRows['TaxAmount']];
        $roundingAmount       = $this->getRoundingAmount($orderRows['rows']);
        $body->RoundingAmount = $roundingAmount;
        $body->TotalAmount    = number_format($this->getRowsTotal($orderRows['rows']), 2, ".", "");


        $orderPaymentMethod      = $this->order->get_payment_method();
        $orderPaymentMethodTitle = $this->order->get_payment_method_title();

        //use method ID and title for fallback
        if (isset($settings->paymentMethodsPaid) && !($settings->paymentMethodsPaid->$orderPaymentMethod || $settings->paymentMethodsPaid->$orderPaymentMethodTitle)) {
            error_log("Payment method $orderPaymentMethod is not allowed to be marked paid");
        } else {
            error_log("Marking order $body->InvoiceNo paid");
            $body->Payment = $this->getPaymentData();
        }


        $salesInvoice = $this->api->sendRequest($body, $endpoint);
        return ["invoice" => $salesInvoice];
    }

    public function getPaymentData()
    {
        $orderPaymentMethod      = $this->order->get_payment_method();
        $orderCurrencyCode       = $this->order->get_currency();
        $paymentMethod           = null;
        $settings                = json_decode(get_option("merit_settings"));
        if (is_array($settings->currencyBanks)) {
            foreach ($settings->currencyBanks as $bank) {
                if ($bank->currency_code == $orderCurrencyCode && $bank->payment_method == $orderPaymentMethod) {
                    $paymentMethod = $bank->currency_bank;
                    break;
                }
            }
        }

        if ($paymentMethod == null) {
            $paymentMethod = $settings->defaultPayment;
        }

        return (object)[
            "PaymentMethod" => $paymentMethod,
            "PaidAmount"    => $this->order->get_total(),
            "PaymDate"      => $this->order->get_date_created()->date("YmdHis"),
        ];
    }

    public function getRowsTotal($rows)
    {
        $rowsTotalCents = 0;
        foreach ($rows as $row) {
            $rowCents       = intval(floatval($row->Price) * 100 * $row->Quantity, 2);
            $rowsTotalCents += $rowCents;
        }

        return number_format($rowsTotalCents / 100, 2, ".", "");
    }

    public function getRoundingAmount($rows)
    {
        $rowsTotalCents = 0;
        foreach ($rows as $row) {
            $rowCents       = intval(floatval($row->Price) * 100 * $row->Quantity, 2);
            $rowsTotalCents += $rowCents;
            $rowsTotalCents += round($rowCents * $this->getVatPc() / 100);
        }
        $roundingAmount = number_format(($this->getOrderTotal() * 100 + $this->getTotalTax() * 100 - $rowsTotalCents) / 100, 2);

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
            $settings              = json_decode(get_option("merit_settings"));
            $shippingRow           = new stdClass();
            $code                  = isset($settings->defaultShipping) ? $settings->defaultShipping : "shipping";
            $shippingRow->Item     = (object)[
                'Code'        => $code,
                'Description' => 'WooCommerce shipping',
                'Type'        => 2,
            ];
            $shippingRow->Quantity = 1;
            $shippingRow->Price    = number_format($this->order->get_shipping_total(), 2, ".", "");
            $shippingRow->TaxId    = $taxId;
            $rows[]                = $shippingRow;
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

    public function getTotalTax()
    {
        return floatval($this->order->get_total_tax());
    }

    public function getOrderTotal()
    {
        return $this->order->get_subtotal() + (float)$this->order->get_shipping_total() - $this->order->get_discount_total();
    }
}
