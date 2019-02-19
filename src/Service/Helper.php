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
    }

    public static function getDateDiffHoursDateTime(\DateTimeInterface $dateStart, \DateTimeInterface $dateEnd)
    {
        $dateDiff = $dateStart->diff($dateEnd);
        $sing = $dateDiff->format('%R');

        return (int) "{$sing}{$dateDiff->h}";
    }

    public static function getDateDiffMinutesDateTime(\DateTimeInterface $dateStart, \DateTimeInterface $dateEnd)
    {
        $dateDiff = $dateStart->diff($dateEnd);
        $sing = $dateDiff->format('%R');

        return (int) "{$sing}{$dateDiff->i}";
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

    public static function phoneFix($phone)
    {
        if (!$phone) {
            return null;
        }

        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (11 == strlen($phone)) {
            $phone = substr($phone, 1);
            $phone = '+7'.$phone;
        } elseif (10 == strlen($phone)) {
            $phone = '+7'.$phone;
        }

        return $phone;
    }

    public static function rusLetterFix($text)
    {
        return str_replace('Ё', 'Е', str_replace('ё', 'е', $text));
    }

    public static function markDownReplace($text)
    {
        $text = str_replace('`', '"', $text);
        $text = str_replace('*', '"', $text);
        $text = str_replace('_', '-', $text);
        $text = str_replace('\\', '/', $text);
        $text = str_replace('[', '(', $text);
        $text = str_replace(']', ')', $text);
//        $text = preg_replace('/[^\pL\pM[:ascii:]]+/g', '', $text);

        return $text;
    }

    public static function markDownEmailEscapeReplace($text)
    {
        $text = str_replace('_', '\\_', $text);

        return $text;
    }

    public static function markDownEmailEscapeReplaceReverse($text)
    {
        $text = str_replace('\\_', '_', $text);

        return $text;
    }

    public static function timeReplacer($time)
    {
        $time = str_replace('.', ':', $time);

        return $time;
    }

    public static function timeExploder($time, &$timeValidate = null)
    {
        $delimiterList = [' ', '-'];

        foreach ($delimiterList as $delimiter) {
            $timeTemp = explode($delimiter, $time);

            if (count($timeTemp) == 2) {
                $time = $timeTemp;
                break;
            }
        }

        if (count($time) != 2) {
            return [];
        }

        return $time;
    }

    public static function HoursAndMinutesExploder($time)
    {
        $delimiterList = ['.', ':'];

        foreach ($delimiterList as $delimiter) {
            $timeTemp = explode($delimiter, $time);

            if (count($timeTemp) == 2) {
                $time = $timeTemp;
                break;
            }
        }

        if (count($time) != 2 || $time[0] >= 24 || $time[1] >= 60) {
            return [];
        }

        return $time;
    }

    public static function timeCurrentMultipleFive() {
        $x = 5;
        $n = date('i', time());
//        $n = 39;

        if ($n % 5 == 0) {
            $result = $n;
        } else {
            $result = (int) round(($n + $x / 2) / $x) * $x;
        }

        $result = sprintf('%02d', $result);

        if ($result == 60) {
            return date('H', strtotime('+1 hours')) . ':00';
        }

        return date('H', time()) . ":{$result}";
    }

    public static function timeToGoodFormat($time, $timeOldValues)
    {
        if (strpos($time, '+') === 0) {
            $timeHoursAndMinutes = substr($time, strpos($time, '+') + 1);
            if (strlen($timeHoursAndMinutes) <= 2) {
                $timeHoursAndMinutes .= '.00';
            }

            $timeHoursAndMinutes = Helper::HoursAndMinutesExploder($timeHoursAndMinutes);

            if ($timeHoursAndMinutes && $timeOldValues) {
                $timeOldValues = Helper::timeExploder($timeOldValues);

                $isTodayTime = date('d.m.Y', time()) == date('d.m.Y', strtotime("{$timeOldValues[1]} +{$timeHoursAndMinutes[0]} hours +{$timeHoursAndMinutes[1]} minutes"));
                if ($isTodayTime) {
                    $timeOldValues[1] = date('H:i', strtotime("{$timeOldValues[1]} +{$timeHoursAndMinutes[0]} hours +{$timeHoursAndMinutes[1]} minutes"));
                    $time = $timeOldValues;
                }
            } elseif ($timeHoursAndMinutes) {
                $timeCurrent = self::timeCurrentMultipleFive();
                $isTodayTime = date('d.m.Y', time()) == date('d.m.Y', strtotime("{$timeCurrent} +{$timeHoursAndMinutes[0]} hours +{$timeHoursAndMinutes[1]} minutes"));

                if ($isTodayTime) {
                    $time = [];
                    $time[0] = $timeCurrent;
                    $time[1] = date('H:i', strtotime("{$timeCurrent} +{$timeHoursAndMinutes[0]} hours +{$timeHoursAndMinutes[1]} minutes"));
                    return $time;
                }
            }
        }

        if (count($time) != 2) {
            $time = self::timeExploder($time, $timeValidate);
        }

        $time = self::timeReplacer($time);

        if (!$time) {
            return [];
        }

        foreach ($time as $key => $item) {

            if (strlen($item) == 1) {
                $time[$key] = "0{$item}:00";
            }

            if (strlen($item) == 2) {
                $time[$key] = "{$item}:00";
            }

            if (strlen($item) == 4) {
                $time[$key] = '0' . $item;
            }
        }

        return $time;
    }

    public static function objectToJsonEncodeSerialize($value)
    {
        return json_encode(serialize($value));
    }

    public static function jsonEncodeSerializeToObject($value)
    {
        return unserialize(json_decode($value));
    }
}
