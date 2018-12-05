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

use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class GoogleAPIController extends Controller
{
    protected $methods;

    protected $googleClient;
    protected $googleToken;
    protected $isGoogle;

    protected $rootPath;

    public function __construct(Container $container)
    {
        $this->googleToken = $container->getParameter('google_token');
//        $this->isGoogle = isset($_GET[$this->googleToken]);
//        if (!$this->isGoogle) exit();

        $this->rootPath = $container->getParameter('root_path');

        $this->googleClient = new GoogleClient;
        $this->googleClient->addScope(Google_Service_Calendar::CALENDAR);

        putenv("GOOGLE_APPLICATION_CREDENTIALS={$this->rootPath}/IntaroCalendar-1e6e479a537a.json");
        $this->googleClient->useApplicationDefaultCredentials();

        if ($this->googleClient->isAccessTokenExpired()) {
            $this->googleClient->refreshTokenWithAssertion();
        }
        
        $this->methods = new Methods;
    }

    public function isGoogleCalendarBotEmail($email)
    {
        $googleBotEmail = "@group.calendar.google.com";
        $email = substr($email, strpos($email, "@"));
        if ($email == $googleBotEmail)
            return true;
        return false;
    }

    /**
     * @Route("/google/service/calendar/list", name="google_service_calendar_list")
     * @param Request $request
     * @return JsonResponse
     */
    public function googleServiceCalendarList(Request $request)
    {
        // Список фильтров
        $filter["calendarName"] = null;
        $filter["startDateTime"] = null;
        $filter["endDateTime"] = null;
        $filter["attendees"] = null;

        // Получение списка фильтров
        if ($request->get('filter') == "getList")
            return new JsonResponse($filter);

        if ($this->methods->jsonDecode($request->get('filter')))
            $filter = array_merge($filter, $this->methods->jsonDecode($request->get('filter'), true));

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

                // Если нет нужного календаря
            if ($filter["calendarName"] && $filter["calendarName"] != $calendarListEntry->getSummary())
                continue;

            $calendarEventResult = [];

            $startDateTime = null;
            $endDateTime = null;

            if ($filter["startDateTime"]) {
                $startDateTime = (new \DateTime("{$filter["startDateTime"]}"))->format(\DateTime::RFC3339);
                if (!$filter["endDateTime"])
                    $endDateTime = (new \DateTime("{$filter["startDateTime"]}"))->modify("+1 day")->format(\DateTime::RFC3339);
                else
                    $endDateTime = (new \DateTime("{$filter["endDateTime"]}"))->format(\DateTime::RFC3339);

            }


            $eventsList = $service->events->listEvents($calendarListEntry->getId(), ["maxResults" => 2500, "timeMin" => $startDateTime, "timeMax" => $endDateTime, "orderBy" => "startTime", "singleEvents" => "true"]);

            foreach ($eventsList->getItems() as $event) {

                // Если нет нужного дня
                // У событий может быть только один ключ с датами:либо date (когда на весь день занимают),
                // либо dateTime на определенный промежуток
//                if ($filter["startDateTime"]) {
//                    $dateTime = $event->getStart()->getDateTime();
//                    $date = $event->getStart()->getDate();
//                    if (isset($dateTime) && $this->methods->getDateStr($filter["startDateTime"]) != $this->methods->getDateStr($dateTime))
//                        continue;
//                    elseif (isset($date) && $this->methods->getDateStr($filter["startDateTime"]) != $this->methods->getDateStr($date))
//                        continue;
//                }

                if (!$event->getSummary())
                    $event->setSummary('<Без названия>');

//                if ($this->isGoogleCalendarBotEmail($event->getCreator()->getEmail()))
//                    dump("OK");

//                if (!$event->getStart()->getDateTime() && !$event->getEnd()->getDateTime()) {
//                    break;
//                }

                $attendeesEmail = [];
                foreach ($event->getAttendees() as $member)
                    $attendeesEmail[] = implode(" ",(array)$member["email"]);

                if ($filter["attendees"] && (!isset($attendeesEmail[0]) || $filter["attendees"] != $attendeesEmail[0]))
                    continue;

                $calendarEventResult[] = [
                    "eventId" => $event->getId(),
                    "calendarEventName" => $event->getSummary(),
                    "description" => $event->getDescription(),
                    "organizerName" => $event->getCreator()->getDisplayName(),
                    "organizerEmail" => $event->getCreator()->getEmail(),
                    "dateCreated" => $event->getCreated(),
                    "dateTimeStart" => $event->getStart()->getDateTime(),
                    "dateTimeEnd" => $event->getEnd()->getDateTime(),
                    "dateStart" => $event->getStart()->getDate(),
                    "dateEnd" => $event->getEnd()->getDate(),
                    "attendees" => $attendeesEmail,
                ];
            }

            $calendarListResult[] = [
                "calendarName" => $calendarListEntry->getSummary(),
                "calendarId" => $calendarListEntry->getId(),
                "listEvents" => $calendarEventResult
            ];
        }
//        dump($calendarListResult);


        return new JsonResponse($calendarListResult);
    }

    /**
     * @Route("/google/service/calendar/event/add", name="google_service_calendar_event_add")
     * @param Request $request
     * @return JsonResponse
     */
    public function googleServiceCalendarEventAdd(Request $request)
    {
        $calendarId = $request->get('calendarId');
        $event = $request->get('event');

        if (!$calendarId)
            return new JsonResponse(json_encode(["error" => "calendarId not found!"]));
        elseif (!$event)
            return new JsonResponse(json_encode(["error" => "data not found!"]));


        $event = $this->methods->jsonDecode($event, true);
        $event = new Google_Service_Calendar_Event($event);
        $service = new Google_Service_Calendar($this->googleClient);
        $event = $service->events->insert($calendarId, $event);

        printf("Создано событие: %s", $event->htmlLink);

        return new JsonResponse();



//        $event = new Google_Service_Calendar_Event([
//            'summary' => 'Название события',
//            'description' => 'Описание события',
//            'start' => [
//                'dateTime' => '2018-11-30T11:45:00+03:00',
//            ],
//            'end' => [
//                'dateTime' => '2018-11-30T13:45:00+03:00',
//            ],
//            'attendees' => [
//                ['email' => 'sarkisyan@intaro.email'],
//                ['email' => 'sbrin@example.com'],
//            ],
//            'reminders' => [
//                'useDefault' => false,
//                'overrides' => [
//                    ['method' => 'email', 'minutes' => 24 * 60],
//                ],
//            ],
//        ]);

//        $calendarId = "iloec6jf1h4rop33877ns95v44@group.calendar.google.com";
//        $service = new Google_Service_Calendar($this->googleClient);
//        $event = $service->events->insert($calendarId, $event);
//        dump(sprintf("Создано событие: %s", $event->htmlLink));



//        return new JsonResponse();
    }

    /**
     * @Route("/google/service/calendar/event/update", name="google_service_calendar_event_add")
     * @param Request $request
     * @return JsonResponse
     */
    public function googleServiceCalendarEventUpdate(Request $request)
    {
        $service = new Google_Service_Calendar($this->googleClient);

        $calendarId = "m3dli34k5foskeiq52ummh839g@group.calendar.google.com";
        $eventId = "2j9b05qf2p116vakkb97ahdhro";

        // First retrieve the event from the API.
        $event = $service->events->get($calendarId, $eventId);

        $event->setSummary("Тест 2");
        $updatedEvent = $service->events->update($calendarId, $event->getId(), $event);

        echo $updatedEvent->getUpdated();
        return new JsonResponse();
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
//        $filter = ["startDateTime" => "28.11.2018", "calendarName" => "Первая переговорка"];

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
//                dump($event->getAttendees());

                // Если есть фильтр и нет нужного дня - ищем дальше
                if (isset($filter["startDateTime"])) {

                    $dateTime = $event->getStart()->getDateTime();
                    if (isset($dateTime)) {
                        if (date("d.m.Y", strtotime($filter["startDateTime"])) != date("d.m.Y", strtotime($event->getStart()->getDateTime())))
                            continue;
                    }

                    $date = $event->getStart()->getDate();
                    if (isset($date)) {
                        if (date("d.m.Y", strtotime($filter["startDateTime"])) != date("d.m.Y", strtotime($event->getStart()->getDate())))
                            continue;
                    }
                }

                if (!$event->getSummary())
                    $event->setSummary('<Без названия>');

                $calendarEventResult[] = [
                    "eventId" => $event->getId(),
                    "calendarEventName" => $event->getSummary(),
                    "description" => $event->getDescription(),
                    "organizerName" =>  $event->getCreator()->getDisplayName(),
                    "organizerEmail" =>  $event->getCreator()->getEmail(),
                    "dateCreated" => $event->getCreated(),
                    "dateTimeStart" => $event->getStart()->getDateTime(),
                    "dateTimeEnd" => $event->getEnd()->getDateTime(),
                    "dateStart" => $event->getStart()->getDate(),
                    "dateEnd" => $event->getEnd()->getDate(),
                    "attendees" => $event->getAttendees(),
                ];
            }

            $calendarListResult[] = [
                "calendarName" => $calendarListEntry->getSummary(),
                "calendarId" => $calendarListEntry->getId(),
                "listEvents" => $calendarEventResult
            ];
        }

        dump($calendarListResult);
        exit();

        return new JsonResponse($calendarListResult);

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