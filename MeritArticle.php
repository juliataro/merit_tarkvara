<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class MeritArticle
{

//    protected $code;
//    protected $description;
//    protected $type;
//
//    public function __construct()
//    {
//        $this->code   = $code;
//        $this->description   = $description;
//        $this->type   = $type;
//        $this->api = new MeritApi();
//    }
//
//    public function ensureAllArticlesExist($rows)
//    {
//        $endpoint = "getitems";
//
//
//
//        foreach ($rows as $row) {
//            $body     = new stdClass();
//            $body->code        = $row->code;
//            $body->description = preg_replace('/[\xF0-\xF7].../s', '_', $row->description);
// $body->type        = $row->code == $settings->defaultShipping ? "SERVICE" : "PRODUCT";
//            $articles = $this->api->sendRequest($body, $endpoint);
//
//            $articles;
//            $settings = json_decode(get_option("merit_settings"));
//            if (!(array_key_exists("articles", $articles) && count($articles["articles"]) == 1)) {
//                $body              = new stdClass();
//                $body->code        = $row->code;
//                $body->description = preg_replace('/[\xF0-\xF7].../s', '_', $row->description);
//                $body->type        = $row->code == $settings->defaultShipping ? "SERVICE" : "PRODUCT";
//                $body->activeSales = true;
//                $this->api->sendRequest($body, $addApiUrl);
//            }
//        }
//    }

}
