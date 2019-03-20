<?php

namespace App\Analytics\Trigger\MeetingRoom;

class Event
{
    const CREATED = 'triggerMeetingRoomEventCreated';
    const CHANGED = 'triggerMeetingRoomEventChanged';
    const DELETED = 'triggerMeetingRoomEventDeleted';
    const REMINDER = 'triggerMeetingRoomEventReminder';
    const CANCEL_PARTICIPATION = 'triggerMeetingRoomEventCancelParticipation';
}
