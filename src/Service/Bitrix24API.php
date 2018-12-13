<?php

namespace App\Service;

use App\Service\Helper as MethodsService;

class Bitrix24API
{
    protected $bitrix24Url;
    protected $methods;

    function __construct($bitrix24Url, $bitrix24UserId, $bitrix24Api)
    {
        $this->methods = new MethodsService;

        $this->bitrix24Url = $bitrix24Url;
        $this->bitrix24Url .= $bitrix24UserId . "/";
        $this->bitrix24Url .= $bitrix24Api . "/";
    }

    /**
     * @return array
     */
    public function getUsers()
    {
        $method = "user.get";
        $url = $this->bitrix24Url . $method . "/";
        $pagination = 50;
        $result = [];
        
        $total = Helper::curl($url, ["ACTIVE" => true], true);
        $total = ceil($total["total"] / $pagination);

        for ($i = 0; $i < $total; $i++) {
            $page = $i * $pagination;
            $args = ["start" => $page, "ACTIVE" => true];
            $result = array_merge($result, Helper::curl($url, $args, true)["result"]);
        }

        foreach ($result as &$user) {
            if ($user["PERSONAL_MOBILE"]) {
                $user["PERSONAL_MOBILE"] = preg_replace('/[^0-9]/', '', $user["PERSONAL_MOBILE"]);
                if (strlen($user["PERSONAL_MOBILE"]) == 11) {
                    $user["PERSONAL_MOBILE"] = substr($user["PERSONAL_MOBILE"], 1);
                    $user["PERSONAL_MOBILE"] = "+7" . $user["PERSONAL_MOBILE"];
                } elseif (strlen($user["PERSONAL_MOBILE"]) == 10) {
                    $user["PERSONAL_MOBILE"] = "+7" . $user["PERSONAL_MOBILE"];
                }

                // для теста
//                if ($user["PERSONAL_MOBILE"] == "НОМЕР") {
//                    $user["PERSONAL_MOBILE"] = "";
//                }
            }
        }

        return $result;
    }

//    /**
//     * @param $args
//     * @return mixed
//     */
//    public function getUser($args)
//    {
//        $method = "user.get";
//        $url = $this->bitrix24Url . $method . "/";
//
//        return Helper::curl($url, $args, true);
//    }
}