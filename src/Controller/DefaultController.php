<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;

use App\Service\Calendar;

use App\Service\TelegramAPI;

class DefaultController extends Controller
{

    protected $calendar;
    public function __construct(Container $container)
    {
        $this->calendar = new Calendar($container);
    }

    /**
     * @Route("/", name="index")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index()
    {


        $data["d"] = 0;
        $data["m"] = 0;
        $data["y"] = 0;

        $filter = json_encode([
            "startDateTime" => $this->calendar->getDateTime($data["d"], $data["m"], $data["y"]),
            "calendarName" => "Первая переговорка"
        ]);

        $eventListCurDay = json_decode(file_get_contents("https://tgbot.skillum.ru/google/service/calendar/list?filter={$filter}"), true);
        $eventListCurDay = $eventListCurDay[0];

        dump($eventListCurDay);

        $text = "{$eventListCurDay["calendarName"]} - {$this->calendar->getDateTime($data["d"], $data["m"], $data["y"])}<br>";

        dump($text);

        if ($eventListCurDay["listEvents"]) {
            foreach ($eventListCurDay["listEvents"] as $event) {
                $event["dateStart"] = date("H:i", strtotime($event["dateStart"]));
                $event["dateEnd"] = date("H:i", strtotime($event["dateEnd"]));
                $text .= "{$event["dateStart"]}-{$event["dateEnd"]} - {$event["calendarEventName"]}, {$event["organizerName"]}<br>";
            }
        } else {
            $text .= "Список событий пуст!";
        }

        print $text;

        return $this->render('index.html.twig');
    }
}