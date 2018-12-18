<?php

namespace App\Service;

use Psr\SimpleCache\CacheInterface;

class Bitrix24API
{
    protected $bitrix24Url;

    protected $cache;
    protected $cacheTime;
    protected $cacheContainer;

    function __construct($bitrix24Url, $bitrix24UserId, $bitrix24Api, CacheInterface $cache, $cacheTime, $cacheContainer)
    {
        $this->bitrix24Url = $bitrix24Url;
        $this->bitrix24Url .= $bitrix24UserId . "/";
        $this->bitrix24Url .= $bitrix24Api . "/";

        $this->cache = $cache;
        $this->cacheTime = $cacheTime;
        $this->cacheContainer = $cacheContainer;
    }

    public function loadData()
    {
        try {
            if ($this->cache->has($this->cacheContainer)) {

                return $this->cache->get($this->cacheContainer);
            }
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            error_log($e->getMessage());

            return null;
        }

        $method = "user.get";
        $url = $this->bitrix24Url . $method . "/";
        $pagination = 50;
        $result = [];

//        $total = Helper::curl($url, ["ACTIVE" => true], true);
        $total = Helper::curl($url, [], true);
        $total = ceil($total["total"] / $pagination);

        for ($i = 0; $i < $total; $i++) {
            $page = $i * $pagination;
//            $args = ["start" => $page, "ACTIVE" => true];
            $args = ["start" => $page];
            $result = array_merge($result, Helper::curl($url, $args, true)["result"]);
        }

        foreach ($result as &$user) {
            $user = [
                "EMAIL" => $user["EMAIL"],
                "NAME" => $user["NAME"],
                "LAST_NAME" => $user["LAST_NAME"],
                "PERSONAL_MOBILE" => $user["PERSONAL_MOBILE"],
                "ACTIVE" => $user["ACTIVE"]
            ];

            if ($user["PERSONAL_MOBILE"]) {
                $user["PERSONAL_MOBILE"] = preg_replace('/[^0-9]/', '', $user["PERSONAL_MOBILE"]);
                if (strlen($user["PERSONAL_MOBILE"]) == 11) {
                    $user["PERSONAL_MOBILE"] = substr($user["PERSONAL_MOBILE"], 1);
                    $user["PERSONAL_MOBILE"] = "+7" . $user["PERSONAL_MOBILE"];
                } elseif (strlen($user["PERSONAL_MOBILE"]) == 10) {
                    $user["PERSONAL_MOBILE"] = "+7" . $user["PERSONAL_MOBILE"];
                }
            }
        }

        try {
            $this->cache->set($this->cacheContainer, $result, $this->cacheTime);

            return $this->cache->get($this->cacheContainer);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            error_log($e->getMessage());

            return null;
        }
    }

    public function getUsers()
    {
        return $this->loadData();
    }
}