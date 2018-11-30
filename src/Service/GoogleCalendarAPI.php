<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use App\Service\Methods as MethodsService;

class GoogleCalendarAPI
{
    protected $container;
    protected $methods;
    protected $isGoogle;
    protected $googleToken;

    function __construct(Container $container)
    {
        $this->container = $container;

        $this->googleToken = $container->getParameter('google_token');
        $this->isGoogle = isset($_GET[$this->googleToken]);
        
        $this->methods = new MethodsService;
    }

    /**
     * @param array|null $filter
     * @return mixed
     */
    public function getList(array $filter = null)
    {
        $url = $this->container->get('router')->generate('google_service_calendar_list', [], 0);

        $filterAvailableKeys = array_keys($this->methods->curl($url, ["filter=getList"], true));
        $resultFilter = [];

        foreach ($filterAvailableKeys as $filterAvailableKey) {
            foreach ($filter as $filterKey => $filterValue) {
                if ($filterKey == $filterAvailableKey) {
                    $resultFilter += [$filterKey => $filterValue];
                }
            }
        }

        $filter = $this->methods->jsonEncode($resultFilter);

        $args = ["filter={$filter}"];

        return $this->methods->curl($url, $args, true);
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
        $url = $this->container->get('router')->generate('google_service_calendar_event_add', [], 0);

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

        $event = urlencode($this->methods->jsonEncode($event));


        $args = [
            "calendarId={$calendarId}",
            "event={$event}"
        ];

        return $this->methods->curl($url, $args, true);
    }

}