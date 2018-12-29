<?php

namespace App\API\Telegram\Modules;

use App\API\Telegram\TelegramRequest;

abstract class Module
{
    abstract public function translate($key, array $params = []);
    abstract public function request(TelegramRequest $request);
}