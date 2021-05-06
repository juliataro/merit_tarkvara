<?php

if ( ! defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class SmartAccountsApi
{

    function signURL($id, $key, $timestamp, $json){
        $signable = $id.$timestamp.$json;
        // NOTICE:  bool $raw_output = TRUE
        $rawSig = hash_hmac('sha256', $signable, $key, true);
        // key-hashed message authentication code
        $base64Sig = base64_encode($rawSig); // encoding JSON binary data prevent modificcation through tronsporting
        return $base64Sig;
    }

    public function sendRequest($requestData, $endpoint) {
        $ch = curl_init();

        // random test company
        $APIID = "eb854b11-db9c-495f-a108-ce5fbcb59ccb"; // E-poe firma isiklik ID
        $APIKEY = "883GM0TSFxJqg/OANR5fgKi5U3FIHeEgICt4M7ZsAds="; // E-poe firma isiklik priivatne võti
        $TIMESTAMP = date("YmdHis");

        $signature = $this->signURL($APIID,$APIKEY, $TIMESTAMP,  json_encode($requestData));
        curl_setopt($ch, CURLOPT_URL, "https://aktiva.merit.ee/api/v1/".$endpoint."?ApiId=".$APIID."&timestamp=".$TIMESTAMP."&signature=".$signature);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_exec($ch);
     //   if(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) != 200) {
      //     print("ERROR ".curl_getinfo($ch, CURLINFO_RESPONSE_CODE)."\r\n");
      //      print_r(curl_getinfo($ch));
      //  }
        curl_close($ch); // closing connection

    }
}

