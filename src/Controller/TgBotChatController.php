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

    const RESPONSE_MESSAGE = "message";
    const RESPONSE_CALLBACK_QUERY = "callback_query";

//    const COMMAND_NEGOTIATION_LIST = "/nlist";
    const COMMAND_NEGOTIATION_LIST = "1";

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

        $this->debugLen(
            [
            "event" => ["calendar" => "previous"],
            "d" => 0, "m" => -100, "y" => 0
        ]
        );


        $this->debugVal();

        if (isset($this->tgResponse["message"])) {
            $this->negotiation(self::RESPONSE_MESSAGE);

        } elseif (isset($this->tgResponse["callback_query"])) {
            $this->negotiation(self::RESPONSE_CALLBACK_QUERY);
        }


        dump("Конец файла");
        return new Response();
    }

    /**
     * @param $responseType
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function negotiation($responseType)
    {
        if ($responseType == self::RESPONSE_MESSAGE):
            if ($this->tgResponse["message"]["text"] == "2"):
                $this->tgBot->sendMessage(
                    $this->tgResponse["message"]["from"]["id"],
                    "Тест",
                    null,
                    false,
                    false,
                    null,
                    $this->tgBot->hideKeyboard()
                );
            endif;

            if ($this->tgResponse["message"]["text"] == self::COMMAND_NEGOTIATION_LIST):
                $this->negotiationList();
            endif;
        endif;

        if ($responseType == self::RESPONSE_CALLBACK_QUERY):

            $data = $this->tgBot->jsonDecode($this->tgResponse["callback_query"]["data"], true);

            if (isset($data["event"])):

                /*
                 * Callback события Календарь
                 */

                if (isset($data["event"]["calendar"])) {

                    $keyboard = [];

                    if ($data["event"]["calendar"] == "previous") {
                        $keyboard = $this->calendar->keyboard($data["d"], ++$data["m"], $data["y"]);
                    }

                    if ($data["event"]["calendar"] == "following") {
                        $keyboard = $this->calendar->keyboard($data["d"], --$data["m"], $data["y"]);
                    }

                    if ($data["event"]["calendar"] == "current") {
                        $keyboard = $this->calendar->keyboard(0, 0, 0);
                    }

                    $replyMarkup = $this->tgBot->jsonEncode([
                        'inline_keyboard' => $keyboard,
                    ]);

                    $this->tgBot->editMessageText(
                        "Выберите желаемую дату",
                        $this->tgResponse["callback_query"]["message"]["chat"]["id"],
                        $this->tgResponse["callback_query"]["message"]["message_id"],
                        null,
                        null,
                        false,
                        $replyMarkup
                    );
                }

                /*
                 * Callback события Переговорка
                 */

                if (isset($data["event"]["negotiation"])) {
                    if ($data["event"]["negotiation"] == "switch") {
                        $this->tgBot->deleteMessage(
                            $this->tgResponse["callback_query"]["message"]["chat"]["id"],
                            $this->tgResponse["callback_query"]["message"]["message_id"]
                        );
                        $this->negotiationList(self::RESPONSE_CALLBACK_QUERY);
                    }
                }
            endif;

            if ($data == "/n1"):
                $keyboard = $this->calendar->keyboard();

                $replyMarkup = $this->tgBot->jsonEncode([
                    'inline_keyboard' => $keyboard,
                ]);

                $this->tgBot->editMessageText(
                    "Выберите желаемую дату",
                    $this->tgResponse["callback_query"]["message"]["chat"]["id"],
                    $this->tgResponse["callback_query"]["message"]["message_id"],
                    null,
                    null,
                    false,
                    $replyMarkup
                );
            endif;
        endif;
    }

    /*
     * Событие Список переговорок
     */
    /**
     * @param string $type
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function negotiationList($type = self::RESPONSE_MESSAGE)
    {
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
            "*Вы*_бери_[те](http://www.example.com/)(эти две буквы - ссылка) `желаемую` ```переговорку!``` ([прямая ссылка tg://](tg://user?id={$this->tgResponse[$type]["from"]["id"]}) в тг на админа)",
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
            ['0']
        ];

        $replyMarkup = $this->tgBot->jsonEncode([
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ]);

        $this->tgBot->sendMessage(
            $this->tgResponse["message"]["from"]["id"],
            "Циферки на клаве",
            null,
            false,
            false,
            null,
            $replyMarkup
        );
    }
}