<?php

namespace App\Controller;

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

class GoogleCalendarAPIController extends Controller
{
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
    }

    public function googleService()
    {
        $service = new Google_Service_Calendar($this->googleClient);
        $service->settings->get('locale')->setValue('ru');

        return $service;
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
        $filter["get"] = null;
        $filter["eventIdShort"] = null;

        // Получение списка фильтров
        if ($request->get('filter') == "getList") {
            return new JsonResponse($filter);
        }

        if (json_decode($request->get('filter'))) {
            $filter = array_merge($filter, json_decode($request->get('filter'), true));
        }

        $service = $this->googleService();
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
            if ($filter["calendarName"] && $filter["calendarName"] != $calendarListEntry->getSummary()) {
                continue;
            }

            $calendarEventResult = [];

            $startDateTime = null;
            $endDateTime = null;

            if ($filter["startDateTime"]) {
                $startDateTime = (new \DateTime("{$filter["startDateTime"]}"))->format(\DateTime::RFC3339);
                if (!$filter["endDateTime"]) {
                    $endDateTime = (new \DateTime("{$filter["startDateTime"]}"))->modify("+1 day")->format(\DateTime::RFC3339);
                } else {
                    $endDateTime = (new \DateTime("{$filter["endDateTime"]}"))->format(\DateTime::RFC3339);
                }
            }

            if ($filter["get"] != "calendars") {
                $eventsList = $service->events->listEvents($calendarListEntry->getId(), ["maxResults" => 2500, "timeMin" => $startDateTime, "timeMax" => $endDateTime, "orderBy" => "startTime", "singleEvents" => "true"]);
                foreach ($eventsList->getItems() as $event) {
                    if (!$event->getSummary()) {
                        $event->setSummary('<Без названия>');
                    }

                    $attendeesEmail = [];
                    foreach ($event->getAttendees() as $member) {
                        $attendeesEmail[] = implode(" ", (array)$member["email"]);
                    }

                    if ($filter["attendees"] && (!isset($attendeesEmail[0]) || $filter["attendees"] != $attendeesEmail[0])) {
                        continue;
                    }

                    $eventArray = [
                        "eventId" => $event->getId(),
                        "calendarEventName" => $event->getSummary(),
                        "calendarId" => $calendarListEntry->getId(),
                        "calendarName" => $calendarListEntry->getSummary(),
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

                    if ($filter["eventIdShort"]) {
                        if (substr($eventArray["eventId"], 0, strlen($filter["eventIdShort"])) == $filter["eventIdShort"])
                            return new JsonResponse($eventArray);
                    }

                    $calendarEventResult[] = $eventArray;
                }
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
        $params = $request->get('params');

        if (!$calendarId) {
            return new JsonResponse(["error" => "calendarId not found!"]);
        } elseif (!$event) {
            return new JsonResponse(["error" => "event not found!"]);
        } elseif (!$params) {
            return new JsonResponse(["error" => "params not found!"]);
        }

        $event = json_decode($event, true);
        $event = new Google_Service_Calendar_Event($event);
        $params = json_decode($params, true);

        $service = $this->googleService();

        $event = $service->events->insert($calendarId, $event, $params);

        return new JsonResponse(["success" => "Event created!", "url" => $event->htmlLink]);
    }

    /**
     * @Route("/google/service/calendar/event/edit", name="google_service_calendar_event_edit")
     * @param Request $request
     * @return JsonResponse
     */
    public function googleServiceCalendarEventEdit(Request $request)
    {
        $calendarId = $request->get('calendarId');
        $eventId = $request->get('eventId');
        $event = $request->get('event');
        $params = $request->get('params');

        if (!$calendarId) {
            return new JsonResponse(["error" => "calendarId not found!"]);
        } elseif (!$eventId) {
            return new JsonResponse(["error" => "eventId not found!"]);
        }
        elseif (!$event) {
            return new JsonResponse(["error" => "event not found!"]);
        } elseif (!$params) {
            return new JsonResponse(["error" => "params not found!"]);
        }

        $event = json_decode($event, true);
        $event = new Google_Service_Calendar_Event($event);
        $params = json_decode($params, true);

        $service = $this->googleService();
        $service->settings->get('locale')->setValue('ru');
        $service->events->update($calendarId, $eventId, $event, $params);

        return new JsonResponse();
    }

    /**
     * @Route("/google/service/calendar/event/remove", name="google_service_calendar_event_remove")
     * @param Request $request
     * @return JsonResponse
     */
    public function googleServiceCalendarEventRemove(Request $request)
    {
        $calendarId = $request->get('calendarId');
        $eventId = $request->get('eventId');
        $params = $request->get('params');

        if (!$calendarId) {
            return new JsonResponse(["error" => "calendarId not found!"]);
        } elseif (!$eventId) {
            return new JsonResponse(["error" => "eventId not found!"]);
        } elseif (!$params) {
            return new JsonResponse(["error" => "params not found!"]);
        }

        $params = json_decode($params, true);

        $service = $this->googleService();
        $service->settings->get('locale')->setValue('ru');
        $service->events->delete($calendarId, $eventId, $params);

        return new JsonResponse(["success" => "Event deleted!"]);
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
        // Список фильтров
        $filter["calendarName"] = null;
        $filter["startDateTime"] = null;
        $filter["endDateTime"] = null;
        $filter["attendees"] = null;
        $filter["get"] = null;
        $filter["eventIdShort"] = null;

        // Получение списка фильтров
        if ($request->get('filter') == "getList")
            return new JsonResponse($filter);

        if (json_decode($request->get('filter')))
            $filter = array_merge($filter, json_decode($request->get('filter'), true));


        $service = $this->googleService();
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

            if ($filter["get"] != "calendars") {
                $eventsList = $service->events->listEvents($calendarListEntry->getId(), ["maxResults" => 2500, "timeMin" => $startDateTime, "timeMax" => $endDateTime, "orderBy" => "startTime", "singleEvents" => "true"]);
                foreach ($eventsList->getItems() as $event) {

                    if (!$event->getSummary()) {
                        $event->setSummary('<Без названия>');
                    }

                    $attendeesEmail = [];
                    foreach ($event->getAttendees() as $member)
                        $attendeesEmail[] = implode(" ", (array)$member["email"]);

                    if ($filter["attendees"] && (!isset($attendeesEmail[0]) || $filter["attendees"] != $attendeesEmail[0]))
                        continue;


                    $eventArray = [
                        "eventId" => $event->getId(),
                        "calendarEventName" => $event->getSummary(),
                        "calendarId" => $calendarListEntry->getId(),
                        "calendarName" => $calendarListEntry->getSummary(),
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

                    if ($filter["eventIdShort"]) {
                        if (substr($eventArray["eventId"], 0, strlen($filter["eventIdShort"])) == $filter["eventIdShort"])
                            return new JsonResponse($eventArray);
                    }

                    $calendarEventResult[] = $eventArray;
                }
            }

            $calendarListResult[] = [
                "calendarName" => $calendarListEntry->getSummary(),
                "calendarId" => $calendarListEntry->getId(),
                "listEvents" => $calendarEventResult
            ];
        }
        dump($calendarListResult);


        return new JsonResponse($calendarListResult);
    }
}