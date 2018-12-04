<?php

namespace App\Service;

class Methods
{
    /**
     * @param $val
     * @param bool $assoc
     * @return mixed
     */
    function jsonDecode($val, bool $assoc = false) {
        $json = json_decode($val, $assoc);

        if (json_last_error() == JSON_ERROR_NONE)
            return $json;

        return $val;
    }

    /**
     * @param $val
     * @param bool $assoc
     * @return string
     */
    function jsonEncode($val, bool $assoc = false) {
        $json = json_encode($val, $assoc);

        if (json_last_error() == JSON_ERROR_NONE)
            return $json;
        else {
            error_clear_last();
            return $val;
        }
    }

    /**
     * @param array|null $args
     * @return bool|string
     */
    public function getRender(array $args = null)
    {
        if (!$args)
            return false;
        $get = "?";
        foreach ($args as $arg) {
            $get .= $arg;
            if (next($args))
                $get .= "&";
        }

        return $get;
    }

    public function curl($url, $args = null, $assoc = false)
    {
        $args = $this->getRender($args);
        $url = $url . $args;

        $ch = curl_init();
        $parameter = [
            CURLOPT_URL => $url,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true
        ];
        curl_setopt_array($ch, $parameter);
        $data = curl_exec($ch);
        curl_close($ch);

        return $this->jsonDecode($data, $assoc);
    }

    public function getDateStr($dateStr) {
        if (!$dateStr)
            return null;
        return date("d.m.Y", strtotime($dateStr));
    }

    public function getTimeStr($timeStr) {
        if (!$timeStr)
            return null;
        return date("H:i", strtotime($timeStr));
    }
}