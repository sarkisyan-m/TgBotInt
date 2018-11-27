<?php

namespace App\Controller;

use App\Service\Methods;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;

use Google_Client as GoogleClient;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_CalendarListEntry;

use App\Service\Calendar;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class GoogleAPIController extends Controller
{
    protected $methods;
    protected $googleClient;
    protected $authUrl;
    protected $siteUrl;

    protected $rootPath;

    public function __construct(Container $container)
    {
        $this->rootPath = $container->getParameter('root_path');

        $this->googleClient = new GoogleClient;
        $this->googleClient->addScope(Google_Service_Calendar::CALENDAR);

        putenv("GOOGLE_APPLICATION_CREDENTIALS={$this->rootPath}/IntaroCalendar-1e6e479a537a.json");
        $this->googleClient->useApplicationDefaultCredentials();

        if ($this->googleClient->isAccessTokenExpired()) {
            $this->googleClient->refreshTokenWithAssertion();
        }
        
        $this->methods = new Methods();
    }

    /**
     * @Route("/google/service/calendar/list", name="google_service_calendar_list")
     * @param Request $request
     * @return JsonResponse
     */
    public function googleServiceCalendarList(Request $request)
    {

        $filter = $this->methods->jsonDecode($request->get('filter'), true);

        $service = new Google_Service_Calendar($this->googleClient);
        $calendarList = $service->calendarList->listCalendarList();

        /**
         * @var $calendarListEntry Google_Service_Calendar_CalendarListEntry
         */
        $calendarListResult = [];
        foreach ($calendarList->getItems() as $calendarListEntry) {
            /**
             * @var $event Google_Service_Calendar_Event
             */

            // Если есть фильтр и нет нужного календаря - ищем дальше
            if (isset($filter["calendarName"]))
                if ($filter["calendarName"] != $calendarListEntry->getSummary())
                    continue;

            $calendarEventResult = [];
            foreach ($service->events->listEvents($calendarListEntry->getId(), ["orderBy" => "startTime", "singleEvents" => "true"])->getItems() as $event) {

                // Если есть фильтр и нет нужного дня - ищем дальше
                if (isset($filter["startDateTime"]))
                    if (date("d.m.Y", strtotime($filter["startDateTime"])) != date("d.m.Y", strtotime($event->getStart()->getDateTime())))
                        continue;

                if (!$event->getSummary())
                    $event->setSummary('Нет заголовка');

                $calendarEventResult[] = [
                    "calendarEventName" => $event->getSummary(),
                    "organizerName" =>  $event->getCreator()->getDisplayName(),
                    "organizerEmail" =>  $event->getCreator()->getEmail(),
                    "dateCreated" => $event->getCreated(),
                    "dateStart" => $event->getStart()->getDateTime(),
                    "dateEnd" => $event->getEnd()->getDateTime(),
                ];
            }

            $calendarListResult[] = [
                "calendarName" => $calendarListEntry->getSummary(),
                "calendarId" => $calendarListEntry->getId(),
                "listEvents" => $calendarEventResult
            ];
        }

        return new JsonResponse($calendarListResult);
    }

    /*
     * __________________________TEST__________________________
     */

    /**
     * @Route("/testgoogle", name="google_calendar_test")
     * @param Request $request
     * @return Response
     */
    public function test(Request $request)
    {
        $filter = $this->methods->jsonDecode($request->get('filter'), true);

        dump($filter);

        $service = new Google_Service_Calendar($this->googleClient);
        $calendarList = $service->calendarList->listCalendarList();

        dump($calendarList->getItems());

        /**
         * @var $calendarListEntry Google_Service_Calendar_CalendarListEntry
         */
        $calendarId = null;
        foreach ($calendarList->getItems() as $calendarListEntry) {
            $calendarId = $calendarListEntry->getId();
//            dump($calendarListEntry);
            dump(
                [
                    "Календарь: " . $calendarListEntry->getSummary(),
                    "Календарь ID: " . $calendarListEntry->getId()
                ]
            );
            /**
             * @var $event Google_Service_Calendar_Event
             */
            foreach ($service->events->listEvents($calendarListEntry->getId())->getItems() as $event) {
//                dump($event);

                if (date("d.m.Y", strtotime($filter["startDateTime"])) != date("d.m.Y", strtotime($event->getStart()->getDateTime()))) {
                    continue;
                }
                dump(
                    [
                        "Название: " . $event->getSummary(),
                        "Организатор: " . $event->getCreator()->getDisplayName() . " " . $event->getCreator()->getEmail(),
                        "Дата создания: " . date("d.m.Y H:i:s", strtotime($event->getCreated())),
                        "Начало: " . date("d.m.Y H:i:s", strtotime($event->getStart()->getDateTime())),
                        "Конец: " . date("d.m.Y H:i:s", strtotime($event->getEnd()->getDateTime()))
                    ]
                );

                dump(date("d.m.Y", strtotime($event->getStart()->getDateTime())));
            }
        }

        $event = new Google_Service_Calendar_Event([
            'summary' => 'Название события',
            'location' => 'Адрес события',
            'description' => 'Описание события',
            'start' => [
                'dateTime' => '2018-11-20T11:45:00+03:00',
//                'timeZone' => null,
            ],
            'end' => [
                'dateTime' => '2018-11-20T13:45:00+03:00',
//                'timeZone' => null,
            ],
            'recurrence' => [], // повтор. пример: массив ['RRULE:FREQ=DAILY;COUNT=2']
            'attendees' => [
                ['email' => 'lpage@example.com'],
                ['email' => 'sbrin@example.com'],
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                    ['method' => 'popup', 'minutes' => 10],
                ],
            ],
        ]);

//        $event = $service->events->insert($calendarId, $event);
//        printf('Event created: %s\n', $event->htmlLink);

        return new Response();
    }
}