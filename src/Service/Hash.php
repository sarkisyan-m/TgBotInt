<?php

namespace App\Service;

class Hash
{
    public static function sha256($text, $salt)
    {
        return hash('sha256', $text.$salt);
    }
}
