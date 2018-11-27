<?php

namespace App\Service;

use App\Service\Methods as MethodsService;

class TelegramAPI
{
    protected $tgBotApiUrl = 'https://api.telegram.org/bot';
    protected $tgToken;
    protected $proxy;
    protected $methods;

    /**
     * TelegramAPI constructor.
     * @param $tgToken
     * @param array $proxy
     */
    public function __construct($tgToken, array $proxy)
    {
        $this->tgToken = $tgToken;
        $this->proxy = $proxy;
        $this->tgBotApiUrl .= "{$this->tgToken}/";
        $this->methods = new MethodsService;
    }

    /**
     * @param $method
     * @param null $args
     * @return mixed
     */
    public function curlTgProxy($method, $args = null)
    {
        $args = $this->methods->getRender($args);

        $url = $this->tgBotApiUrl . $method . $args;
        $ch = curl_init();
        $parameter = [
            CURLOPT_PROXY => $this->proxy[0],
            CURLOPT_PROXYPORT => $this->proxy[1],
            CURLOPT_PROXYUSERPWD => $this->proxy[2],
            CURLOPT_URL => $url,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
        ];
        curl_setopt_array($ch, $parameter);
        $data = curl_exec($ch);
        curl_close($ch);

        return $this->methods->jsonDecode($data);
    }
    
    /*
     * __________________________Переменные типа Markup__________________________
     */

    /**
     * @param array $inline_keyboard
     * @return string
     */
    public function InlineKeyboardMarkup(array $inline_keyboard)
    {
        return $this->methods->jsonEncode([
            "inline_keyboard" => $inline_keyboard
        ]);
    }

    /**
     * @param $keyboard
     * @param bool $resize_keyboard
     * @param bool $one_time_keyboard
     * @param bool $selective
     * @return string
     */
    public function ReplyKeyboardMarkup(array $keyboard, bool $resize_keyboard = false, bool $one_time_keyboard = false, bool $selective = false)
    {
        return $this->methods->jsonEncode([
            "keyboard" => $keyboard,
            "resize_keyboard" => $resize_keyboard,
            "one_time_keyboard" => $one_time_keyboard,
            "selective" => $selective
        ]);
    }

    /**
     * @param bool $remove_keyboard
     * @param bool $selective
     * @return string
     */
    public function ReplyKeyboardRemove(bool $remove_keyboard = true, bool $selective = false)
    {
        return $this->methods->jsonEncode([
            "remove_keyboard" => $remove_keyboard,
            "selective" => $selective
        ]);
    }

    /**
     * @param bool $force_reply
     * @param bool $selective
     * @return string
     */
    public function ForceReply(bool $force_reply = true, bool $selective = false)
    {
        return $this->methods->jsonEncode([
            "force_reply" => $force_reply,
            "selective" => $selective
        ]);
    }

    /*
     * __________________________Переменные типа Keyboard__________________________
     */

    /**
     * @param string $text
     * @param null $callback_data
     * @param string|null $url
     * @param string|null $switch_inline_query
     * @param string|null $switch_inline_query_current_chat
     * @param null $callback_game
     * @param bool $pay
     * @return array
     */
    public function InlineKeyboardButton(string $text, $callback_data = null, string $url = null, string $switch_inline_query = null, string $switch_inline_query_current_chat = null, $callback_game = null, bool $pay = false)
    {
        if (is_string($callback_data))
            $callback_data = explode(' ', $callback_data);

        $callback_data = $this->methods->jsonEncode($callback_data);
        $url = urlencode($url);

        return [
            "text" => $text,
            "callback_data" => $callback_data,
            "url" => $url,
            "switch_inline_query" => $switch_inline_query,
            "switch_inline_query_current_chat" => $switch_inline_query_current_chat,
            "callback_game" => $callback_game,
            "pay" => $pay
        ];
    }

    public function keyboardButton(string $text, bool $request_contact = false, bool $request_location = false)
    {
        return [
            "text" => $text,
            "request_contact" => $request_contact,
            "request_location" => $request_location
        ];
    }

    /**
     * @param bool $hide_keyboard
     * @return string
     */
    public function hideKeyboard(bool $hide_keyboard = true)
    {
        return $this->methods->jsonEncode([
            "hide_keyboard" => $hide_keyboard
        ]);
    }

    /*
     * __________________________Методы телеграма__________________________
     */

    public function getResponse()
    {
        return json_decode(file_get_contents('php://input'), true);
    }

    /**
     * @param int|null $offset
     * @param int|null $limit
     * @param int|null $timeout
     * @param array|null $allowed_updates
     * @return mixed
     */
    public function getUpdates(int $offset = null, int $limit = null, int $timeout = null, array $allowed_updates = null)
    {
        $args = [
            "offset={$offset}",
            "limit={$limit}",
            "timeout={$timeout}",
            "allowed_updates={$allowed_updates}"
        ];

        return $this->curlTgProxy(__FUNCTION__ , $args);
    }

    /**
     * @param string $url
     * @param null $certificate
     * @param int|null $max_connections
     * @param array|null $allowed_updates
     * @return mixed
     */
    public function setWebHook(string $url, $certificate = null, int $max_connections = null, array $allowed_updates = null)
    {
        $args = [
            "url={$url}?{$this->tgToken}",
            "certificate={$certificate}",
            "max_connections={$max_connections}",
            "allowed_updates={$allowed_updates}"
        ];

        return $this->curlTgProxy(__FUNCTION__ , $args);
    }

    public function deleteWebhook()
    {
        return $this->curlTgProxy(__FUNCTION__);
    }

    public function getWebhookInfo()
    {
        return $this->curlTgProxy(__FUNCTION__);
    }

    /**
     * @param $chat_id
     * @param string $text
     * @param string|null $parse_mode
     * @param bool $disable_web_page_preview
     * @param bool $disable_notification
     * @param int|null $reply_to_message_id
     * @param null $reply_markup
     * @return mixed
     */
    public function sendMessage($chat_id, string $text, string $parse_mode = null, bool $disable_web_page_preview = false, bool $disable_notification = false, int $reply_to_message_id = null, $reply_markup = null)
    {
        $text = urlencode($text);
        $args = [
            "chat_id={$chat_id}",
            "text={$text}",
            "parse_mode={$parse_mode}",
            "disable_web_page_preview={$disable_web_page_preview}",
            "disable_notification={$disable_notification}",
            "reply_to_message_id={$reply_to_message_id}",
            "reply_markup={$reply_markup}"
        ];

        return $this->curlTgProxy(__FUNCTION__ , $args);
    }

    /**
     * @param string $callback_query_id
     * @param string|null $text
     * @param bool $show_alert
     * @param string|null $url
     * @param int|null $cache_time
     * @return mixed
     */
    public function answerCallbackQuery(string $callback_query_id, string $text = null, bool $show_alert = false, string $url = null, int $cache_time = null)
    {
        $args = [
            "callback_query_id={$callback_query_id}",
            "text={$text}",
            "show_alert={$show_alert}",
            "url={$url}",
            "cache_time={$cache_time}"
        ];

        return $this->curlTgProxy(__FUNCTION__ , $args);
    }

    /**
     * @param string $text
     * @param null $chat_id
     * @param int|null $message_id
     * @param string|null $inline_message_id
     * @param string|null $parse_mode
     * @param bool $disable_web_page_preview
     * @param null $reply_markup
     * @return mixed
     */
    public function editMessageText(string $text, $chat_id = null, int $message_id = null, string $inline_message_id = null, string $parse_mode = null, bool $disable_web_page_preview = false, $reply_markup = null)
    {
        $text = urlencode($text);
        $args = [
            "text={$text}",
            "chat_id={$chat_id}",
            "message_id={$message_id}",
            "inline_message_id={$inline_message_id}",
            "parse_mode={$parse_mode}",
            "disable_web_page_preview={$disable_web_page_preview}",
            "reply_markup={$reply_markup}",
        ];

        return $this->curlTgProxy(__FUNCTION__ , $args);
    }

    /**
     * @param $chat_id
     * @param int $message_id
     * @return mixed
     */
    public function deleteMessage($chat_id, int $message_id)
    {
        $args = [
            "chat_id={$chat_id}",
            "message_id={$message_id}",
        ];

        return $this->curlTgProxy(__FUNCTION__ , $args);
    }
}