<?php

namespace App\API\GoogleCalendar;

use App\Service\Helper;
use Psr\SimpleCache\CacheInterface;
use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_CalendarList;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_CalendarListEntry;
use Google_Service_Calendar_EventAttendee;

class GoogleCalendarAPI
{
    private $notificationGoogle;
    private $notificationTime;

    private $cache;
    private $cacheTime;
    private $cacheContainer;

    private $googleClient;

    private $dateRange;

    private $meetingRoom;
    private $meetingRoomAutoAdd;

    public function __construct(
        $notificationTime,
        CacheInterface $cache,
        $cacheTime,
        $cacheContainer,
        $dateRange,
        $notificationGoogle,
        $meetingRoom,
        $meetingRoomAutoAdd
    ) {
        $this->cache = $cache;
        $this->cacheTime = $cacheTime;
        $this->cacheContainer = $cacheContainer;

        $this->googleClient = new Google_Client();
        $this->googleClient->addScope(Google_Service_Calendar::CALENDAR);

        $this->googleClient->useApplicationDefaultCredentials();

        if ($this->googleClient->isAccessTokenExpired()) {
            $this->googleClient->fetchAccessTokenWithAssertion();
        }

        $this->dateRange = $dateRange;

        $this->meetingRoom = explode(', ', $meetingRoom);
        $this->meetingRoomAutoAdd = 'true' === $meetingRoomAutoAdd ? true : false;
        $this->notificationGoogle = 'true' === $notificationGoogle ? true : false;
        $this->notificationTime = $notificationTime;
    }

    public function getFilters($filter)
    {
        $filtersKey = [
            'calendarName',
            'startDateTime',
            'endDateTime',
            'attendees',
            'attendees_member',
            'get',
            'eventIdShort',
        ];

        $filterAvailableKeys = [];
        foreach ($filtersKey as $getFilter) {
            $filterAvailableKeys[$getFilter] = null;
        }

        $resultFilter = [];
        if ($filterAvailableKeys && $filter) {
            foreach (array_keys($filterAvailableKeys) as $filterAvailableKey) {
                foreach ($filter as $filterKey => $filterValue) {
                    if ($filterKey == $filterAvailableKey) {
                        $resultFilter += [$filterKey => $filterValue];
                    }
                }
            }
        }

        if ($resultFilter) {
            return array_merge($filterAvailableKeys, $resultFilter);
        }

        return $filterAvailableKeys;
    }

    public function deleteData()
    {
        try {
            $this->cache->delete($this->cacheContainer);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            error_log($e->getMessage());
        }
    }

    public function loadData()
    {
        try {
            if ($this->cache->has($this->cacheContainer)) {
                return $this->cache->get($this->cacheContainer);
            }
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            error_log($e->getMessage());

            return null;
        }

        /**
         * @var Google_Service_Calendar
         * @var $service                Google_Service_Calendar
         * @var $calendarList           Google_Service_Calendar_CalendarList
         * @var $calendarListItems      Google_Service_Calendar_CalendarListEntry
         * @var $calendarListItem       Google_Service_Calendar_CalendarListEntry
         */
        $cacheItems = [];

        $cacheItems['service'] = serialize(new Google_Service_Calendar($this->googleClient));
        $service = unserialize($cacheItems['service']);

        $cacheItems['calendar_list'] = serialize($service->calendarList->listCalendarList());
        $calendarList = unserialize($cacheItems['calendar_list']);

        $cacheItems['calendar_list_items'] = serialize($calendarList->getItems());
        $calendarListItems = unserialize($cacheItems['calendar_list_items']);

        foreach ($calendarListItems as $calendarListItem) {
            if (array_search($calendarListItem->getSummary(), $this->meetingRoom) === false) {
                continue;
            }

            $startDateTime = (new \DateTime())->format(\DateTime::RFC3339);
            $endDateTime = (new \DateTime())->modify("+{$this->dateRange} day")->format(\DateTime::RFC3339);

            $cacheItems["event_list_{$calendarListItem->getId()}"] = serialize($service->events->listEvents($calendarListItem->getId(), ['maxResults' => 2500, 'timeMin' => $startDateTime, 'timeMax' => $endDateTime, 'orderBy' => 'startTime', 'singleEvents' => 'true']));
            $eventsList = unserialize($cacheItems["event_list_{$calendarListItem->getId()}"]);

            $cacheItems["event_list_{$calendarListItem->getId()}_items"] = serialize($eventsList->getItems());
        }

        try {
            $this->cache->set($this->cacheContainer, $cacheItems, $this->cacheTime);

            return $this->cache->get($this->cacheContainer);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            error_log($e->getMessage());

            return null;
        }
    }

    public function removeAllEvents()
    {
        $calendarList = $this->getList();
        $service = new Google_Service_Calendar($this->googleClient);

        foreach ($calendarList as $calendar) {
            foreach ($calendar['listEvents'] as $event) {
                $service->events->delete($event['calendarId'], $event['eventId']);
            }
        }

        return $calendarList;
    }

    public function getList(array $filter = null)
    {
        /**
         * @var Google_Service_Calendar
         * @var $calendarList           Google_Service_Calendar_CalendarList
         * @var $calendarListItems      Google_Service_Calendar_CalendarListEntry
         * @var $calendarListItem       Google_Service_Calendar_CalendarListEntry
         * @var $eventsListItems        Google_Service_Calendar_Event[]
         * @var $member                 Google_Service_Calendar_EventAttendee
         */
        $data = $this->loadData();

        if (!$data) {
            return null;
        }

        $filter = $this->getFilters($filter);
        $calendarListResult = [];
        $calendarListItems = unserialize($data['calendar_list_items']);

        // Сортировка
        $googleCalendarTemp = [];
        foreach ($this->meetingRoom as $meetingRoom) {
            foreach ($calendarListItems as $calendarListId => $calendarListItem) {
                if ($calendarListItem->getSummary() == $meetingRoom) {
                    $googleCalendarTemp[] = $calendarListItem;
                    unset($calendarListItems[$calendarListId]);
                }
            }
        }

        if ($this->meetingRoomAutoAdd) {
            $calendarListItems = array_merge($googleCalendarTemp, $calendarListItems);
        } else {
            $calendarListItems = $googleCalendarTemp;
        }

        foreach ($calendarListItems as $calendarListItem) {
            // Если нет нужного календаря
            if ($filter['calendarName'] && $filter['calendarName'] != $calendarListItem->getSummary()) {
                continue;
            }

            $calendarEventResult = [];
            $startDateTime = null;
            $endDateTime = null;

            if ('calendars' != $filter['get']) {
                $eventsListItems = unserialize($data["event_list_{$calendarListItem->getId()}_items"]);
                foreach ($eventsListItems as $event) {
                    $dateTimeStart = Helper::getDateStr($event->getStart()->getDateTime());
                    $dateTimeEnd = Helper::getDateStr($event->getEnd()->getDateTime());

                    if ($filter['startDateTime'] && Helper::getDateDiffDays($filter['startDateTime'], $dateTimeStart) < 0) {
                        continue;
                    } elseif ($filter['endDateTime'] && Helper::getDateDiffDays($filter['endDateTime'], $dateTimeEnd) > 0) {
                        break;
                    }

                    if (!$event->getSummary()) {
                        $event->setSummary('<Без названия>');
                    }

                    $attendeesEmail = [];

                    foreach ($event->getAttendees() as $member) {
                        $organizerText = \App\API\Telegram\Module\MeetingRoom::ORGANIZER;

                        if (substr($member->comment, 0, strlen($organizerText)) == $organizerText) {
                            array_unshift($attendeesEmail, $member->getEmail());

                            continue;
                        }
                        $attendeesEmail[] = $member->getEmail();
                    }

                    if ($filter['attendees']) {
                        if (!$attendeesEmail) {
                            continue;
                        }

                        if ($filter['attendees'] != $attendeesEmail[0]) {
                            continue;
                        }
                    }

                    if ($filter['attendees_member']) {
                        if (!$attendeesEmail) {
                            continue;
                        }

                        if (false === array_search($filter['attendees_member'], $attendeesEmail)) {
                            continue;
                        }
                    }

                    $eventArray = [
                        'eventId' => $event->getId(),
                        'calendarEventName' => $event->getSummary(),
                        'calendarId' => $calendarListItem->getId(),
                        'calendarName' => $calendarListItem->getSummary(),
                        'description' => $event->getDescription(),
                        'organizerName' => $event->getCreator()->getDisplayName(),
                        'organizerEmail' => $event->getCreator()->getEmail(),
                        'dateCreated' => $event->getCreated(),
                        'dateTimeStart' => $event->getStart()->getDateTime(),
                        'dateTimeEnd' => $event->getEnd()->getDateTime(),
                        'dateStart' => $event->getStart()->getDate(),
                        'dateEnd' => $event->getEnd()->getDate(),
                        'attendees' => $attendeesEmail,
                    ];

                    if ($filter['eventIdShort']) {
                        if (substr($eventArray['eventId'], 0, strlen($filter['eventIdShort'])) == $filter['eventIdShort']) {
                            return $eventArray;
                        }
                    }

                    $calendarEventResult[] = $eventArray;
                }
            }

            $calendarListResult[] = [
                'calendarName' => $calendarListItem->getSummary(),
                'calendarId' => $calendarListItem->getId(),
                'listEvents' => $calendarEventResult,
            ];
        }

        return $calendarListResult;
    }

    public function getCalendars($calendarName = null)
    {
        $filter = ['get' => 'calendars'];

        if ($calendarName) {
            $filter += ['calendarName' => $calendarName];
        }

        return $this->getList($filter);
    }

    public function getCalendarId($calendarName)
    {
        $data = $this->getCalendars($calendarName);

        if (isset($data[0]['calendarId'])) {
            return $data[0]['calendarId'];
        }

        return null;
    }

    public function getCalendarNameList()
    {
        $calendarNameList = [];
        $calendars = $this->getCalendars();

        if ($calendars) {
            foreach ($calendars as $calendar) {
                $calendarNameList[] = $calendar['calendarName'];
            }
        }

        return $calendarNameList;
    }

    /**
     * @param null $summary
     * @param null $description
     * @param null $startDateTime
     * @param null $endDateTime
     * @param null $attendees
     * @param null $event
     * @param null $params
     */
    public function eventBuilder($summary = null, $description = null, $startDateTime = null, $endDateTime = null, $attendees = null, &$event = null, &$params = null)
    {
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
                    ['method' => 'email', 'minutes' => $this->notificationTime],
                ],
            ],
        ];

        $params = [];

        if ($this->notificationGoogle) {
            $params['sendUpdates'] = 'all';
        }
    }

    /**
     * @param string      $calendarId
     * @param string|null $summary
     * @param string|null $description
     * @param string|null $startDateTime
     * @param string|null $endDateTime
     * @param null        $attendees
     */
    public function addEvent(string $calendarId, string $summary = null, string $description = null, string $startDateTime = null, string $endDateTime = null, $attendees = null)
    {
        $this->eventBuilder($summary, $description, $startDateTime, $endDateTime, $attendees, $event, $params);
        $event = new Google_Service_Calendar_Event($event);
        $service = new Google_Service_Calendar($this->googleClient);
        $service->events->insert($calendarId, $event, $params);

        $this->deleteData();
    }

    /**
     * @param string      $calendarId
     * @param string      $eventId
     * @param string|null $summary
     * @param string|null $description
     * @param string|null $startDateTime
     * @param string|null $endDateTime
     * @param null        $attendees
     */
    public function editEvent(string $calendarId, string $eventId, string $summary = null, string $description = null, string $startDateTime = null, string $endDateTime = null, $attendees = null)
    {
        $this->eventBuilder($summary, $description, $startDateTime, $endDateTime, $attendees, $event, $params);
        $event = new Google_Service_Calendar_Event($event);

        $service = new Google_Service_Calendar($this->googleClient);
        $service->events->update($calendarId, $eventId, $event, $params);

        $this->deleteData();
    }

    /**
     * @param null $calendarId
     * @param null $eventId
     */
    public function removeEvent($calendarId = null, $eventId = null)
    {
        $this->eventBuilder(null, null, null, null, null, $event, $params);
        $service = new Google_Service_Calendar($this->googleClient);
        $service->events->delete($calendarId, $eventId, $params);

        $this->deleteData();
    }
}
