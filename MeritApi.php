<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class MeritApi
{

    function signURL($id, $key, $timestamp, $json)
    {
        $signable = $id . $timestamp . $json;
        // NOTICE:  bool $raw_output = TRUE
        $rawSig = hash_hmac('sha256', $signable, $key, true);
        // key-hashed message authentication code
        $base64Sig = base64_encode($rawSig); // encoding JSON binary data prevent modificcation through tronsporting
        return $base64Sig;
    }

    public function sendRequest(?stdClass $body, $endpoint): array
    {
        error_log("Sending Merit API $endpoint: ".json_encode($body));

        if ($body == null) {
            $body = new stdClass();
        }
        $now = new DateTime();
        $now->setTimezone(new DateTimeZone('Europe/Tallinn'));
        $settings = json_decode(get_option("merit_settings"));

        $pk       = $settings->apiKey;
        $sk       = $settings->apiSecret;

        $timestamp = date("YmdHis");

        $json      = json_encode($body);
        $signature = $this->signURL($pk, $sk, $timestamp, $json);

        $version = "v1";
        if ($endpoint === "sendcustomer") {
            $version = "v2";
        }

        $url = "https://aktiva.merit.ee/api/$version/" . $endpoint . "?ApiId=" . $pk . "&timestamp=" . $timestamp . "&signature=" . $signature;

        $args = [
            'body'    => $json,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'timeout' => 60
        ];

        $response        = wp_remote_post($url, $args);
        $response_code   = wp_remote_retrieve_response_code($response);
        $meritResponse   = wp_remote_retrieve_body($response);
        $decodedResponse = json_decode($meritResponse, true);
        if (is_string($decodedResponse)) {
            $decodedResponse = json_decode($decodedResponse, true);
        }

        if (!in_array($response_code, [200, 201]) || is_wp_error($meritResponse)) {
            throw new Exception("Merit call failed url=$url: $response_code" . print_r($response, true));
        }

        return $decodedResponse;
    }
}
