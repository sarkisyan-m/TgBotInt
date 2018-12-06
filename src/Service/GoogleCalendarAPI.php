<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use App\Service\Methods as MethodsService;
use Symfony\Component\Routing\Router;

class GoogleCalendarAPI
{
    protected $container;
    protected $methods;
    protected $googleToken;
    protected $router;

    function __construct($token, Router $router, MethodsService $methods)
    {
        $this->googleToken = $token;
        $this->router = $router;
        $this->methods = $methods;
    }

    /**
     * @param array|null $filter
     * @return mixed
     */
    public function getList(array $filter = null)
    {
        $url = $this->router->generate('google_service_calendar_list', [], 0);

        $filterAvailableKeys = array_keys($this->methods->curl($url, ["filter=getList"], true));
        $resultFilter = [];

        foreach ($filterAvailableKeys as $filterAvailableKey) {
            foreach ($filter as $filterKey => $filterValue) {
                if ($filterKey == $filterAvailableKey) {
                    $resultFilter += [$filterKey => $filterValue];
                }
            }
        }

        $filter = json_encode($resultFilter);

        $args = ["filter={$filter}"];

        return $this->methods->curl($url, $args, true);
    }

    public function getCalendars($calendarName = null)
    {
        $url = $this->router->generate('google_service_calendar_list', [], 0);

        $filter = ["get" => "calendars"];
        if ($calendarName)
            $filter += ["calendarName" => $calendarName];

        $filter = json_encode($filter);
        $args = ["filter={$filter}"];

        return $this->methods->curl($url, $args, true);
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

        $event = urlencode(json_encode($event));


        $args = [
            "calendarId={$calendarId}",
            "event={$event}"
        ];

        return $this->methods->curl($url, $args, true);
    }
}