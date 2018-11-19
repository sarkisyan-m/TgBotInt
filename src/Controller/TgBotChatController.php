<?php

namespace App\Controller;

use App\Utils\Calendar;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use App\Utils\TelegramBotAPI;

use App\Entity\Negotiation;
use Symfony\Component\Cache\Simple\FilesystemCache;

class TgBotChatController extends Controller
{
    protected $tgBot;
    protected $tgToken;
    protected $tgResponse;
    protected $isTg;

    protected $cache;
    protected $cacheTime;
    protected $cacheContainer;

    protected $calendar;

    protected $workTimeStart;
    protected $workTimeEnd;
    protected $dateRange;

    const RESPONSE_MESSAGE = "message";
    const RESPONSE_CALLBACK_QUERY = "callback_query";

    const COMMAND_MESSAGE_NEGOTIATION_LIST = "1";

    const COMMAND_CALLBACK_QUERY_NEGOTIATION_ONE = "n1";

    function __construct(Container $container)
    {
        $this->tgToken = $container->getParameter('tg_token');
        $this->isTg = isset($_GET[$this->tgToken]);

        $proxyName = $container->getParameter('proxy_name');
        $proxyPort = $container->getParameter('proxy_port');
        $proxyLogPass = $container->getParameter('proxy_logpass');

        $this->tgBot = new TelegramBotAPI($this->tgToken, [$proxyName, $proxyPort, $proxyLogPass]);
        $this->tgResponse = $this->tgBot->getResponse();

        $this->cache = new FilesystemCache;
        $this->cacheTime = $container->getParameter('cache_time');
        $this->cacheContainer["negotiation"] = $container->getParameter('cache_container_negotiation');

        $this->calendar = new Calendar;

        $this->workTimeStart = $container->getParameter('work_time_start');
        $this->workTimeEnd = $container->getParameter('work_time_end');
        $this->dateRange = $container->getParameter('date_range');

    }

    public function debugVal($val = null, $flag = FILE_APPEND)
    {
        if (!$val)
            $val = $this->tgResponse;
        $filename = $this->getParameter('kernel.project_dir') . "/public/debug.txt";
        file_put_contents($filename, print_r($val, true), $flag);
    }

    public function debugLen($val)
    {
        return dump(strlen(json_encode($val)));
    }

    /**
     * @Route("/tgWebhook", name="tg_webhook")
     * @return Response
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function tgWebhook()
    {

//        $day = 0;
//        $month = 0;
//        $year = 0;
//        $this->debugLen('{"e":{"calendar":"fol"},"d":0,"m":0,"y":0}');

        $this->debugVal();



        dump($this->calendar->getTimeDiff("13:12", "15:55", $this->workTimeStart, $this->workTimeEnd));

        if (isset($this->tgResponse["message"]))
            $this->negotiation(self::RESPONSE_MESSAGE);
        elseif (isset($this->tgResponse["callback_query"]))
            $this->negotiation(self::RESPONSE_CALLBACK_QUERY);

        return new Response();
    }

    /**
     * @param $responseType
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function negotiation($responseType)
    {
        if ($responseType == self::RESPONSE_MESSAGE):
            if (!isset($this->tgResponse["message"]["reply_to_message"])) {
                if ($this->tgResponse["message"]["text"] == "/reg"):

                    $keyboard[][] = ["text" => "Выслать номер", "request_contact" => true];

                    $replyMarkup = $this->tgBot->jsonEncode([
                        'keyboard' => $keyboard,
                    ]);

                    $this->tgBot->sendMessage(
                        $this->tgResponse["message"]["from"]["id"],
                        "Для продолжения необходимо зарегистрироваться!",
                        null,
                        false,
                        false,
                        null,
                        $replyMarkup
                    );
                endif;

                if ($this->tgResponse["message"]["text"] == self::COMMAND_MESSAGE_NEGOTIATION_LIST):
                    $this->negotiationList();
                endif;

            } else {
                if ($this->calendar->validateDate($this->tgResponse["message"]["reply_to_message"]["text"], $this->dateRange)) {

                    $time = explode(" ", $this->tgResponse["message"]["text"]);
                    if ($this->calendar->validateTime($time[0], $this->workTimeStart, $this->workTimeEnd) &&
                        $this->calendar->validateTime($time[1], $this->workTimeStart, $this->workTimeEnd)) {
                        $this->tgBot->sendMessage(
                            $this->tgResponse["message"]["from"]["id"],
                            "Ок"
                        );
                    } else {
                        $this->tgBot->sendMessage(
                            $this->tgResponse["message"]["from"]["id"],
                            "Время имеет неверный формат! Пожалуйста, выберите еще раз дату выше и введите корректные данные!"
                        );
                    }
                }

            }

        endif;

        if ($responseType == self::RESPONSE_CALLBACK_QUERY):

            $data = $this->tgBot->jsonDecode($this->tgResponse["callback_query"]["data"], true);

            if (isset($data["e"])):

                /*
                 * Callback события Календарь
                 */

                if (isset($data["e"]["calendar"])) {

                    if ($data["e"]["calendar"] == "pre" ||
                        $data["e"]["calendar"] == "fol" ||
                        $data["e"]["calendar"] == "cur") {

                        $keyboard = [];
                        switch ($data["e"]["calendar"]) {
                            case "pre":
                                $keyboard = $this->calendar->keyboard($data["d"], ++$data["m"], $data["y"]);
                                break;
                            case "fol":
                                $keyboard = $this->calendar->keyboard($data["d"], --$data["m"], $data["y"]);
                                break;
                            case "cur":
                                $keyboard = $this->calendar->keyboard(0, 0, 0);
                                break;
                        }

                        $this->pickDateTime($keyboard);
                    }

                    if ($data["e"]["calendar"] == "sDay") {
                        $date = sprintf("%02d.%s.%s", $data["d"], $data["m"], $data["y"]);

                        if ($this->calendar->validateDate($date, $this->dateRange)) {
                            $replyMarkup = $this->tgBot->jsonEncode([
                                'force_reply' => true,
                            ]);

                            $this->tgBot->sendMessage(
                                $this->tgResponse["callback_query"]["message"]["chat"]["id"],
                                "{$date}",
                                null,
                                false,
                                false,
                                null,
                                $replyMarkup
                            );
                        }
                    }
                }

                /*
                 * Callback события Переговорка
                 */

                if (isset($data["e"]["n"])) {
                    if ($data["e"]["n"] == "switch") {
                        $this->tgBot->deleteMessage(
                            $this->tgResponse["callback_query"]["message"]["chat"]["id"],
                            $this->tgResponse["callback_query"]["message"]["message_id"]
                        );
                        $this->negotiationList(self::RESPONSE_CALLBACK_QUERY);
                    }
                }
            endif;

            if ($data == self::COMMAND_CALLBACK_QUERY_NEGOTIATION_ONE):
                $keyboard = $this->calendar->keyboard(0, 0, 0, self::COMMAND_CALLBACK_QUERY_NEGOTIATION_ONE);
                $this->pickDateTime($keyboard);
            endif;
        endif;
    }

    /*
     * Сообщение Список переговорок
     */
    /**
     * @param string $type
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function negotiationList($type = self::RESPONSE_MESSAGE)
    {
        $this->cache->delete($this->cacheContainer["negotiation"]);
        if (!$this->cache->get($this->cacheContainer["negotiation"])) {
            $repository = $this->getDoctrine()->getRepository(Negotiation::class);
            $negotiation = $repository->findBy([]);
            $this->cache->set($this->cacheContainer["negotiation"], $negotiation, $this->cacheTime);
        }

        $negotiation = $this->cache->get($this->cacheContainer["negotiation"]);

        /**
         * @var $item Negotiation
         */
        $keyboard = [];
        foreach ($negotiation as $item) {
            $keyboard[] = [["text" => $item->getName(), "callback_data" => $item->getTgCommand()]];
        }
        $replyMarkup = $this->tgBot->jsonEncode(["inline_keyboard" => $keyboard]);

        $this->tgBot->sendMessage(
            $this->tgResponse[$type]["from"]["id"],
            "`Выберите переговорку`",
            "Markdown",
            false,
            false,
            null,
            $replyMarkup
        );

        $keyboard = [
            ['7', '8', '9'],
            ['4', '5', '6'],
            ['1', '2', '3'],
            ['0', ':', ' ']
        ];

        $replyMarkup = $this->tgBot->jsonEncode([
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ]);
    }

    /*
     * Событие Выбрать дату и время
     */
    public function pickDateTime($keyboard)
    {
        $replyMarkup = $this->tgBot->jsonEncode([
            'inline_keyboard' => $keyboard,
        ]);

        $this->tgBot->editMessageText(
            urlencode("`Необходимо сначала выбрать дату (минимум {$this->calendar->getDay()} число текущего месяца и до {$this->dateRange} дн.), потом написать время (к примеру, 11:30 13:00).\nКстати, сегодня " . date("d.m.Y")) . "`",
            $this->tgResponse["callback_query"]["message"]["chat"]["id"],
            $this->tgResponse["callback_query"]["message"]["message_id"],
            null,
            "Markdown",
            false,
            $replyMarkup
        );
    }
}