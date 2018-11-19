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

        define("RESPONSE_MESSAGE", "0");
        define("RESPONSE_CALLBACK", "1");

        $this->calendar = new Calendar;
    }

    /**
     * @Route("/tgWebhook", name="tg_webhook")
     * @return Response
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function tgWebhook()
    {
        $filename = $this->getParameter('kernel.project_dir') . "/public/debug.txt";
        file_put_contents($filename, print_r($this->tgResponse, true), FILE_APPEND);


        if (isset($this->tgResponse["message"]["text"])) {
            $this->negotiation(RESPONSE_MESSAGE);

        } elseif (isset($this->tgResponse["callback_query"]["data"])) {
            $this->negotiation(RESPONSE_CALLBACK);
        }

//        dump($this->tgBot->editMessageText(
//            "Тестыч",
//            338308809,
//            934,
//            null,
//            null,
//            false,
//            null
//        ));

//        dump($this->calendar->getMonth());
//
//                for ($i = 0; $i < 4; $i++) {
//                    $keyboard[] = [
//                        ["text" => 1 + $i * 7, "callback_data" => 123],
//                        ["text" => 2 + $i * 7, "callback_data" => 123],
//                        ["text" => 3 + $i * 7, "callback_data" => 123],
//                        ["text" => 4 + $i * 7, "callback_data" => 123],
//                        ["text" => 5 + $i * 7, "callback_data" => 123],
//                        ["text" => 6 + $i * 7, "callback_data" => 123],
//                        ["text" => 7 + $i * 7, "callback_data" => 123],
//                    ];
//                }
//        dump($keyboard);

        $keyboard = [];
        $j = 0;
        $keyboard[$j][] = [$this->calendar->getMonth()];
        $j++;
        for ($i = 0; $i < $this->calendar->getDays(); $i ++) {
            if ($i % 7 == 0 && $i != 0) {
                $j++;
            }
            $keyboard[$j][] = ["text" => $i + 1, "callback_data" => 123];

            if ($i + 1 == $this->calendar->getDays() && $this->calendar->getDays() != 28) {
                $days = 35 - $this->calendar->getDays();
                for ($k = 0; $k < $days; $k++) {
                    $keyboard[$j][] = ["text" => " ", "callback_data" => "none"];
                }
            }

        }



        dump($keyboard);


        dump($this->calendar->getMonth());

        dump("Конец файла");
        return new Response();
    }

    /**
     * @param $responseType
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function negotiation($responseType)
    {
        if ($responseType == RESPONSE_MESSAGE):
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

            if ($this->tgResponse["message"]["text"] == "1"):

                if (!$this->cache->get($this->cacheContainer["negotiation"])) {
                    $repository = $this->getDoctrine()->getRepository(Negotiation::class);
                    $negotiation = $repository->findBy([]);
                    $this->cache->set($this->cacheContainer["negotiation"], $negotiation, $this->cacheTime);
                }
                $negotiation = $this->cache->get($this->cacheContainer["negotiation"]);

                /**
                 * @var $item Negotiation
                 */
                $commands = [];
                foreach ($negotiation as $item) {
                    $commands[] = [["text" => $item->getName(), "callback_data" => $item->getTgCommand()]];
                }
                $keyboardInline = $commands;
                $keyboard = ["inline_keyboard" => $keyboardInline];
                $replyMarkup = $this->tgBot->jsonEncode($keyboard);

                $this->tgBot->sendMessage(
                    $this->tgResponse["message"]["from"]["id"],
                    "*Вы*_бери_[те](http://www.example.com/)(эти две буквы - ссылка) `желаемую` ```переговорку!``` ([прямая ссылка tg://](tg://user?id={$this->tgResponse["message"]["from"]["id"]}) в тг на админа)",
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
//                $this->tgBot->sendMessage(
//                    $this->tgResponse["message"]["from"]["id"],
//                    "Циферки на клаве",
//                    null,
//                    false,
//                    false,
//                    null,
//                    $replyMarkup
//                );
            endif;
        endif;

        if ($responseType == RESPONSE_CALLBACK):

            $data = $this->tgBot->jsonDecode($this->tgResponse["callback_query"]["data"], true);

            if (isset($data["event"])):
                if ($data["event"]["calendar"]) {

                    $keyboard = [];

                    if ($data["event"]["calendar"] == "previous") {
                        $keyboard = $this->calendar->keyboard($data["day"], ++$data["month"], $data["year"]);
                    }

                    if ($data["event"]["calendar"] == "following") {
                        $keyboard = $this->calendar->keyboard($data["day"], --$data["month"], $data["year"]);
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
                        "Markdown",
                        false,
                        $replyMarkup
                    );
                }

            endif;

            if ($data == "/n1"):
//                $this->tgBot->sendMessage(
//                    $this->tgResponse["callback_query"]["from"]["id"],
//                    "Вы хотите занять первую переговорку?"
//                );
//                $this->tgBot->answerCallbackQuery($this->tgResponse["callback_query"]["id"], 'Изи переговорка',true);


//                for ($i = 0; $i < 4; $i++) {
//                    $keyboard[] = [
//                        ["text" => 1 + $i * 7, "callback_data" => 123],
//                        ["text" => 2 + $i * 7, "callback_data" => 123],
//                        ["text" => 3 + $i * 7, "callback_data" => 123],
//                        ["text" => 4 + $i * 7, "callback_data" => 123],
//                        ["text" => 5 + $i * 7, "callback_data" => 123],
//                        ["text" => 6 + $i * 7, "callback_data" => 123],
//                        ["text" => 7 + $i * 7, "callback_data" => 123],
//                    ];
//                }


//                $keyboard[] = [["text" => 1, "callback_data" => 123]];
//                $keyboard[] = [["text" => 1, "callback_data" => 123]];


//                $keyboard = [];
//                $ln = 0;
//
//                $keyboard[$ln][] = ["text" => $this->calendar->getMonthYear(), "callback_data" => "none"];
//                $ln++;
//                for ($i = 0; $i < $this->calendar->getDays(); $i ++) {
//                    // в одной строке 7 кнопок
//                    if ($i % 7 == 0 && $i != 0)
//                        $ln++;
//
//                    $keyboard[$ln][] = ["text" => $i + 1, "callback_data" => "none"];
//
//                    // создаем пустые ячейки
//                    if ($i + 1 == $this->calendar->getDays() && $this->calendar->getDays() != 28) {
//                        $days = 35 - $this->calendar->getDays();
//                        for ($k = 0; $k < $days; $k++) {
//                            $keyboard[$ln][] = ["text" => $k + 1, "callback_data" => "none"];
//                        }
//                    }
//                }
//                $ln++;
//                $keyboard[$ln][] = ["text" => "Пред.", "callback_data" => "none"];
//                $keyboard[$ln][] = ["text" => "След.", "callback_data" => "none"];

                $keyboard = $this->calendar->keyboard();

                $replyMarkup = $this->tgBot->jsonEncode([
                    'inline_keyboard' => $keyboard,
                ]);

                $this->tgBot->editMessageText(
                    "Выберите желаемую дату",
                    $this->tgResponse["callback_query"]["message"]["chat"]["id"],
                    $this->tgResponse["callback_query"]["message"]["message_id"],
                    null,
                    "Markdown",
                    false,
                    $replyMarkup
                );
            endif;
        endif;
    }
}