<?php

namespace App\Service;

class Validator
{
    public static function email($email)
    {
        $pattern = '/^([a-z0-9_-]+\.)*[a-z0-9_-]+@[a-z0-9_-]+(\.[a-z0-9_-]+)*\.[a-z]{2,6}$/';

        if (preg_match($pattern, $email)) {
            return true;
        }

        return false;
    }

    public static function phone($phone)
    {
        if (preg_match("/^\+7[0-9]{10}$/", $phone)) {
            return true;
        }

        return false;
    }
}