<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class MeritArticle
{

    public function __construct()
    {
        $this->api = new MeritApi();
    }

    public function ensureAllArticlesExist($rows)
    {
        $getApiUrl = "getitems";
        foreach ($rows as $row) {
            $body     = new stdClass();
            $articles = $this->api->sendRequest($body, $getApiUrl);

            var_dump($articles);
//            $settings = json_decode(get_option("merit_settings"));
//            if (!(array_key_exists("articles", $articles) && count($articles["articles"]) == 1)) {
//                $body              = new stdClass();
//                $body->code        = $row->code;
//                $body->description = preg_replace('/[\xF0-\xF7].../s', '_', $row->description);
//                $body->type        = $row->code == $settings->defaultShipping ? "SERVICE" : "PRODUCT";
//                $body->activeSales = true;
//                $this->api->sendRequest($body, $addApiUrl);
//            }
        }
    }

}
