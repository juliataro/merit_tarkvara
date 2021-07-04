=== Plugin Name ===
Tags: Merit, merit, WooCommerce
Requires at least: 4.8
Tested up to: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Send sales invoices to api.merit.ee Online Accounting Software and sync products with warehouse quantities.

== Description ==

This plugin:
* Creates Customer to Merit if no existing customer found
* Creates Articles in Merit of Woocommerce product on the order if existing products are not found.
Woocommerce product SKU is compared with Merit article code.
* Creates sales in invoice and connects it with the right customer and adds all the Articles on the invoice.
* Marks the sales invoice as paid if needed.
* Imports Articles from Merit to Woocommerce

== Installation ==

Configuration is needed to make this plugin work. After activating the plugin find its configuration page under Woocommerce menu item and:

* Make sure you have Merit package with API access
* Copy Merit API key and secret from Merit interface.
* Add bank account name you want to be used when making the invoices paid
* You can also change Merit code for shipping
* "Payment methods" section allows configuring which payment methods are marked paid in Merit immediately
* "Bank accounts" section allows configuring which payment method and currency corresponds to which Merit bank account name. If mapping is missing then default is used

== Importing products from Merit ==
* Products must be active sales items and of type Warehouse Item or Product. Services are not imported.
* Product final price is taken from Merit unless sale price is set. If sale price is set manually then only regular price is changed.

== Note about Woo and Merit client matching ==

If order has meta _billing_regcode then plugin looks for Client with this registration code from Merit.

Otherwise customer name is used to match. If Merit Client with this name does not exist then new Merit Client is created.
If user was anonymous then general client is created with the country name.

As people often type their official company name incorrectly then fuzzy matching is performed.
OÜ, AS, MTÜ, KÜ and FIE are removed from the beginning and end of the name and first match with the "main part of name" is used.

== What are the plugin limitations? ==
* Order changes and cancelling is not handled automatically
* All items have one VAT percentage
* Merit article code must be added to the Woocommerce product SKU if existing Merit article must be used
* If product is not found then "Woocommerce product NAME" Article is created to Merit
* Plugin does not handle errors which come from exceeding rate limits or unpaid Merit invoices.
* If there are errors then invoices might be missing and rest of the Woocommerce functionality keeps on working
* Merit API key, API secret and Payment account name must be configured before plugin will start working properly.
* If plugin creates offer and this offer is deleted by the time invoice is created then creating invoice will fail.
* Exact shipping method is not sent to Merit

These shortcomings can be resolved by additional development. If these are problem for you then please get in touch with margus.pala@gmail.com

== Changelog ==

= 1.0 =
Initial version based on SmartAccounts plugin
