<?php

namespace App\Service;

use App\Service\Helper as MethodsService;
use Symfony\Component\Routing\RouterInterface;

class GoogleCalendarAPI
{
    protected $container;
    protected $methods;
    protected $isGoogle;
    protected $googleToken;
    protected $router;

    function __construct($googleToken, RouterInterface $router)
    {
        $this->googleToken = $googleToken;
        $this->isGoogle = isset($_GET[$this->googleToken]);
        $this->methods = new MethodsService;
        $this->router = $router;
    }

    /**
     * @param array|null $filter
     * @return mixed
     */
    public function getList(array $filter = null)
    {
        $url = $this->router->generate('google_service_calendar_list', [], 0);

        $filterAvailableKeys = Helper::curl($url, ["filter" => "getList"], true);

        $resultFilter = [];

        if ($filterAvailableKeys && $filter) {
            $filterAvailableKeys = array_keys($filterAvailableKeys);
            foreach ($filterAvailableKeys as $filterAvailableKey) {
                foreach ($filter as $filterKey => $filterValue) {
                    if ($filterKey == $filterAvailableKey) {
                        $resultFilter += [$filterKey => $filterValue];
                    }
                }
            }
        }

        $filter = json_encode($resultFilter);


        return Helper::curl($url, ["filter" => $filter], true);
    }

    public function getCalendars($calendarName = null)
    {
        $url = $this->router->generate('google_service_calendar_list', [], 0);

        $filter = ["get" => "calendars"];
        if ($calendarName)
            $filter += ["calendarName" => $calendarName];

        $filter = json_encode($filter);

        return Helper::curl($url, ["filter" => $filter], true);
    }

    public function getCalendarId($calendarName)
    {
        $data = $this->getCalendars($calendarName);

        if (isset($data[0]["calendarId"]))
            return $data[0]["calendarId"];

        return null;
    }

    public function getCalendarNameList()
    {
        $calendarNameList = [];
        $calendars = $this->getCalendars();
        if ($calendars) {
            foreach ($calendars as $calendar)
                $calendarNameList[] = $calendar["calendarName"];
        }
        return $calendarNameList;
    }

    /**
     * @param string $calendarId
     * @param string|null $summary
     * @param string|null $description
     * @param string|null $startDateTime
     * @param string|null $endDateTime
     * @param null $attendees
     * @return mixed
     */
    public function addEvent(string $calendarId, string $summary = null, string $description = null, string $startDateTime = null, string $endDateTime = null, $attendees = null)
    {
        $url = $this->router->generate('google_service_calendar_event_add', [], 0);

        $event = [
            'summary' => $summary,
            'description' => $description,
            'start' => [
                'dateTime' => $startDateTime,
            ],
            'end' => [
                'dateTime' => $endDateTime,
            ],
            'attendees' => $attendees,
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                ],
            ],
        ];

        $event = json_encode($event);


        $args = [
            "calendarId" => $calendarId,
            "event" => $event
        ];

        return Helper::curl($url, $args, true);
    }

    public function editEvent(string $calendarId, string $eventId, string $summary = null, string $description = null, string $startDateTime = null, string $endDateTime = null, $attendees = null)
    {
        $url = $this->router->generate('google_service_calendar_event_edit', [], 0);

        $event = [
            'summary' => $summary,
            'description' => $description,
            'start' => [
                'dateTime' => $startDateTime,
            ],
            'end' => [
                'dateTime' => $endDateTime,
            ],
            'attendees' => $attendees,
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                ],
            ],
        ];

        $event = json_encode($event);


        $args = [
            "calendarId" => $calendarId,
            "eventId" => $eventId,
            "event" => $event
        ];

        return Helper::curl($url, $args, true);
    }

    public function removeEvent($calendarId = null, $eventId = null)
    {
        $url = $this->router->generate('google_service_calendar_event_remove', [], 0);

        $args = [
            "calendarId" => $calendarId,
            "eventId" => $eventId
        ];

        return Helper::curl($url, $args, true);
    }
}