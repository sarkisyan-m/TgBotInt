<?php

namespace App\API\Telegram;

use App\API\Telegram\Model\Request\CallbackQueryRequest;
use App\API\Telegram\Model\Request\CallbackQuery;
use App\API\Telegram\Model\Request\MessageRequest;
use App\API\Telegram\Model\Request\Message;
use App\API\Telegram\Model\Request\From;
use App\API\Telegram\Model\Request\Chat;
use App\API\Telegram\Model\Request\ReplyToMessage;
use App\API\Telegram\Model\Request\Contact;
use Symfony\Component\Serializer\SerializerInterface;

class Request
{
    const REQUEST_MESSAGE = 'message';
    const REQUEST_CALLBACK_QUERY = 'callback_query';

    private $serializer;
    private $request;

    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
        $this->request = $this->init();
    }

    public function request($request)
    {
        $this->request = $this->init();
        $this->request = array_replace_recursive($this->request, $request);
    }

    public function toObject(array $array, $objectType)
    {
        return $this->serializer->deserialize(json_encode($array), $objectType, 'json');
    }

    public function getRequestType()
    {
        if ($this->request[self::REQUEST_MESSAGE]['message_id']) {
            return self::REQUEST_MESSAGE;
        }

        if ($this->request[self::REQUEST_CALLBACK_QUERY]['id']) {
            return self::REQUEST_CALLBACK_QUERY;
        }

        return null;
    }

    public function getRequestObject()
    {
        if (!$this->request) {
            return null;
        }

        if (self::REQUEST_MESSAGE == $this->getRequestType()) {
            /**
             * @var $from From
             * @var $chat           Chat
             * @var $replyToMessage ReplyToMessage
             * @var $contact        Contact
             */
            $from = $this->toObject($this->request[self::REQUEST_MESSAGE]['from'], From::class);
            $chat = $this->toObject($this->request[self::REQUEST_MESSAGE]['chat'], Chat::class);
            $replyToMessage = $this->toObject($this->request[self::REQUEST_MESSAGE]['reply_to_message'], ReplyToMessage::class);
            $contact = $this->toObject($this->request[self::REQUEST_MESSAGE]['contact'], Contact::class);

            $replyToMessageFrom = $this->toObject($this->request[self::REQUEST_MESSAGE]['reply_to_message']['from'], From::class);
            $replyToMessageChat = $this->toObject($this->request[self::REQUEST_MESSAGE]['reply_to_message']['chat'], Chat::class);
            $replyToMessage->setFrom($replyToMessageFrom);
            $replyToMessage->setChat($replyToMessageChat);

            /**
             * @var $message Message
             */
            $message = $this->toObject($this->request[$this->getRequestType()], Message::class);
            $message->setFrom($from);
            $message->setChat($chat);
            $message->setReplyToMessage($replyToMessage);
            $message->setContact($contact);

            /**
             * @var $messageRequest MessageRequest
             */
            $messageRequest = $this->toObject($this->request, MessageRequest::class);
            $messageRequest->setMessage($message);

            return $messageRequest;
        }

        if (self::REQUEST_CALLBACK_QUERY == $this->getRequestType()) {
            /**
             * @var $from                  From
             * @var $message               Message
             * @var $messageReplyToMessage ReplyToMessage
             */
            $from = $this->toObject($this->request[self::REQUEST_CALLBACK_QUERY]['from'], From::class);
            $message = $this->toObject($this->request[self::REQUEST_CALLBACK_QUERY]['message'], Message::class);
            $messageReplyToMessage = $this->toObject($this->request[self::REQUEST_CALLBACK_QUERY]['message']['reply_to_message'], ReplyToMessage::class);

            $messageFrom = $this->toObject($this->request[self::REQUEST_CALLBACK_QUERY]['message']['from'], From::class);
            $messageChat = $this->toObject($this->request[self::REQUEST_CALLBACK_QUERY]['message']['chat'], Chat::class);
            $message->setFrom($messageFrom);
            $message->setChat($messageChat);

            $messageReplyToMessageFrom = $this->toObject($this->request[self::REQUEST_CALLBACK_QUERY]['message']['reply_to_message']['from'], From::class);
            $messageReplyToMessageChat = $this->toObject($this->request[self::REQUEST_CALLBACK_QUERY]['message']['reply_to_message']['chat'], Chat::class);
            $messageReplyToMessage->setFrom($messageReplyToMessageFrom);
            $messageReplyToMessage->setChat($messageReplyToMessageChat);

            /**
             * @var $callbackQuery CallbackQuery
             */
            $callbackQuery = $this->toObject($this->request[self::REQUEST_CALLBACK_QUERY], CallbackQuery::class);
            $callbackQuery->setFrom($from);
            $callbackQuery->setMessage($message);

            /**
             * @var $callbackQueryRequest CallbackQueryRequest
             */
            $callbackQueryRequest = $this->toObject($this->request, CallbackQueryRequest::class);
            $callbackQueryRequest->setCallbackQuery($callbackQuery);

            return $callbackQueryRequest;
        }

        return null;
    }

    public function init()
    {
        $from = [
            'from' => [
                'id' => null,
                'is_bot' => null,
                'first_name' => null,
                'last_name' => null,
                'language_code' => null,
            ],
        ];

        $chat = [
            'id' => null,
            'first_name' => null,
            'last_name' => null,
            'type' => null,
        ];

        $message = [
            'message_id' => null,
            'from' => $from,
            'chat' => $chat,
            'date' => null,
            'text' => null,
            'reply_to_message' => [
                'message_id' => null,
                'from' => $from,
                'chat' => $chat,
                'date' => null,
                'text' => null,
            ],
            'contact' => [
                'phone_number' => null,
                'first_name' => null,
                'last_name' => null,
                'user_id' => null,
            ],
        ];

        return [
            'update_id' => null,
            'message' => $message,
            'callback_query' => [
                'id' => null,
                'from' => $from,
                'message' => $message,
                'chat_instance' => null,
                'data' => null,
            ],
        ];
    }
}
