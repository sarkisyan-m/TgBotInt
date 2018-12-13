<?php

namespace App\Service;

class Helper
{
    public static function curl($url, $args = null, $assoc = false)
    {

        $args = "?" . http_build_query($args);
        $url = $url . $args;

        $ch = curl_init();
        $parameter = [
            CURLOPT_URL => $url,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10
        ];
        curl_setopt_array($ch, $parameter);
        $data = curl_exec($ch);
        curl_close($ch);

        return json_decode($data, $assoc);
    }

    public static function getDateStr($dateStr) {
        if (!$dateStr)
            return null;
        return date("d.m.Y", strtotime($dateStr));
    }

    public static function getTimeStr($timeStr) {
        if (!$timeStr)
            return null;
        return date("H:i", strtotime($timeStr));
    }
}