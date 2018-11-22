<?php

namespace App\Service;

class TelegramAPI
{
    protected $tgBotApiUrl = 'https://api.telegram.org/bot';
    protected $tgToken;
    protected $proxy;

    public function __construct($tgToken, array $proxy)
    {
        $this->tgToken = $tgToken;
        $this->proxy = $proxy;
        $this->tgBotApiUrl .= "{$this->tgToken}/";
    }

    public function curl($method, $args = null)
    {
        $args = $this->getRender($args);
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
        return json_decode($data);
    }

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

//    public function hideKeyboard()
//    {
//        return $this->jsonEncode(["hide_keyboard" => true]);
//    }

    function jsonDecode($val, bool $assoc = false) {
        $json = json_decode($val, $assoc);

        if (json_last_error() == JSON_ERROR_NONE)
            return $json;
        else
            return $val;
    }

    function jsonEncode($val, bool $assoc = false) {
        $json = json_encode($val, $assoc);

        if (json_last_error() == JSON_ERROR_NONE)
            return $json;
        else {
            error_clear_last();
            return $val;
        }

    }

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

        return $this->curl(__FUNCTION__ , $args);
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

        return $this->curl(__FUNCTION__ , $args);
    }

    public function deleteWebhook()
    {
        return $this->curl(__FUNCTION__);
    }

    public function getWebhookInfo()
    {
        return $this->curl(__FUNCTION__);
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

        return $this->curl(__FUNCTION__ , $args);
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

        return $this->curl(__FUNCTION__ , $args);
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
        $args = [
            "text={$text}",
            "chat_id={$chat_id}",
            "message_id={$message_id}",
            "inline_message_id={$inline_message_id}",
            "parse_mode={$parse_mode}",
            "disable_web_page_preview={$disable_web_page_preview}",
            "reply_markup={$reply_markup}",
        ];

        return $this->curl(__FUNCTION__ , $args);
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

        return $this->curl(__FUNCTION__ , $args);
    }
}