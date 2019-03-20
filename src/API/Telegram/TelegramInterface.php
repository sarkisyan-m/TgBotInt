<?php

namespace App\API\Telegram;

interface TelegramInterface
{
    public function translate($key, array $params = []);

    public function request(TelegramRequest $request);
}
