<?php

namespace App\Service;

class Helper
{
    public static function curl($url, $args = null, $assoc = false)
    {
        if ($args) {
            $args = '?'.http_build_query($args);
            $url = $url.$args;
        }

        $ch = curl_init();
        $parameter = [
            CURLOPT_URL => $url,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10,
        ];
        curl_setopt_array($ch, $parameter);
        $data = curl_exec($ch);
        curl_close($ch);

        return json_decode($data, $assoc);
    }


    public static function getTime($time)
    {
        if (!$time) {
            return null;
        }

        return date('H:i', $time);
    }

    public static function getDateStr($dateStr)
    {
        if (!$dateStr) {
            return null;
        }

        return date('d.m.Y', strtotime($dateStr));
    }

    public static function getTimeStr($timeStr)
    {
        if (!$timeStr) {
            return null;
        }

        return date('H:i', strtotime($timeStr));
    }

    public static function getDateDiffDays($dateStart, $dateEnd)
    {
        $dateStart = new \DateTime($dateStart);
        $dateEnd = new \DateTime($dateEnd);
        $dateDiff = $dateStart->diff($dateEnd);
        $sing = $dateDiff->format('%R');

        return (int) "{$sing}{$dateDiff->days}";
    }

    public static function getDateDiffDaysDateTime(\DateTimeInterface $dateStart, \DateTimeInterface $dateEnd)
    {
        $dateDiff = $dateStart->diff($dateEnd);
        $sing = $dateDiff->format('%R');

        return (int) "{$sing}{$dateDiff->d}";
//        return $dateDiff;
    }

    public static function getArgs($text, &$command = null)
    {
        $delimiter = strpos($text, '_');

        if (false !== $delimiter) {
            $args = substr($text, $delimiter + 1);

            if ($args) {
                $command = substr($text, 0, $delimiter);

                return $args;
            }
        }

        return null;
    }
}
