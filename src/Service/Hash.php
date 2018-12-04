<?php

namespace App\Service;

class Hash
{
    protected $method = "sha256";

    public function hash($text, $salt)
    {
        return hash($this->method, $text . $salt);
    }
}