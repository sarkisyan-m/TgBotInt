<?php

namespace App\API\Telegram\Plugins;

use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramInterface;
use App\API\Telegram\TelegramRequest;
use App\Service\Validator;
use Symfony\Component\Translation\TranslatorInterface;

class Calendar implements TelegramInterface
{
    protected $container;
    protected $tgBot;
    protected $tgDb;
    protected $tgRequest;
    protected $translator;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        TelegramRequest $tgRequest,
        TranslatorInterface $translator
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->tgRequest = $tgRequest;
        $this->translator = $translator;
    }

    public function request(TelegramRequest $request)
    {
    }

    public function translate($key, array $params = [])
    {
        return $this->translator->trans($key, $params, 'telegram', 'ru');
    }

    public function getDays(int $month = 0)
    {
        return date('t', strtotime("first day of this month -{$month} month"));
    }

    public function getDay(int $day = 0, int $month = 0, int $year = 0)
    {
        return date('d', strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function getMonth(int $day = 0, int $month = 0, int $year = 0)
    {
        return date('m', strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function getSelectMonth(int $month = 0)
    {
        return date('m', strtotime("first day of this month  -{$month} month"));
    }

    public function getMonthText($month = 0)
    {
        $monthName = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
        $month = date('m', strtotime("first day of this month -{$month} month"));

        return $monthName[$month - 1];
    }

    public function getSelectWeek(int $month = 0)
    {
        $week = date('w', strtotime("first day of this month -{$month} month")) - 1;
        if ($week < 0) {
            $week = 6;
        }

        return $week;
    }

    public function getDateRus($date, $strToLower = false, $format = 'w, d m')
    {
        $weekRus = [
            'Воскресенье',
            'Понедельник',
            'Вторник',
            'Среда',
            'Четверг',
            'Пятница',
            'Суббота',
        ];

        $monthRus = [
            '',
            'января',
            'февраля',
            'марта',
            'апреля',
            'мая',
            'июня',
            'июля',
            'августа',
            'сентября',
            'октября',
            'ноября',
            'декабря',
        ];

        $week = (int) date('w', strtotime($date));
        $day = (int) date('d', strtotime($date));
        $month = (int) date('m', strtotime($date));

        if ($format == 'w, d m') {
            $text = "{$weekRus[$week]}, {$day} {$monthRus[$month]}";
        } elseif ($format == 'd m') {
            $text = "{$day} {$monthRus[$month]}";
        } else {
            return null;
        }

        if ($strToLower) {
            $text = mb_strtolower($text);
        }

        return $text;
    }

    public function getYear(int $day = 0, int $month = 0, int $year = 0)
    {
        return date('Y', strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function getDate(int $day = 0, int $month = 0, int $year = 0)
    {
        return date('d.m.Y', strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function keyboard(int $day = 0, int $month = 0, int $year = 0)
    {
        $keyboard = [];
        $curDay = null;
        $eventName = 'calendar';
        $emptyCallback = $this->tgDb->prepareCallbackQuery(['empty' => true]);

        $ln = 0;
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => [$eventName => 'current'], 'data' => ['day' => $day, 'month' => $month, 'year' => $year]]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton(
            $this->translate('calendar.current_date', ['%monthText%' => $this->getMonthText($month), '%year%' => $this->getYear($day, $month, $year)]),
            $callback
        );

        ++$ln;
        $dWeek = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        for ($i = 0; $i < count($dWeek); ++$i) {
            $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($dWeek[$i], $emptyCallback);
        }

        ++$ln;
        $dWeekCount = 0;
        for ($i = 0; $i < $this->getDays($month); ++$i) {
            // в одной строке 7 кнопок (одна неделя)
            if (0 == $dWeekCount % 7 && 0 != $dWeekCount) {
                ++$ln;
            }

            $curDay = $i + 1;
            $curDay = $this->translate('calendar.day', ['%day%' => $curDay]);

            // создаем пустые ячейки в начале
            if (1 == $curDay) {
                $emptyCell = $this->getSelectWeek($month);
                for ($k = 0; $k < $emptyCell; ++$k) {
                    $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.day_empty'), $emptyCallback);
                }
                $dWeekCount += $emptyCell;
            }

            // создаем ячейки из чисел
            $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => [$eventName => 'selectDay'], 'data' => ['day' => $curDay, 'month' => $this->getSelectMonth($month), 'year' => $this->getYear($day, $month, $year)]]);

            if ($this->getDate($day, $month, $year) == $this->getDate() && $this->getDay() == $curDay) {
                $curDayText = $this->translate('calendar.day_current', ['%day%' => $curDay]);
                $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($curDayText, $callback);
            } else {
                $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($curDay, $callback);
            }

            // создаем пустые ячейки в конце
            if ($curDay == $this->getDays($month)) {
                $emptyCell = count($keyboard[$ln]);
                for ($k = 0; $k < 7 - $emptyCell; ++$k) {
                    $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.day_empty'), $emptyCallback);
                }
            }

            ++$dWeekCount;
        }
        ++$ln;
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => [$eventName => 'previous'], 'data' => ['day' => $day, 'month' => $month, 'year' => $year]]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.back'), $callback);

        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => [$eventName => 'following'], 'data' => ['day' => $day, 'month' => $month, 'year' => $year]]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.forward'), $callback);

        ++$ln;
        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => [$eventName => 'selectDay'], 'data' => ['day' => $this->getDay(0), 'month' => $this->getMonth(0), 'year' => $this->getYear(0)]]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.today'), $callback);

        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => [$eventName => 'selectDay'], 'data' => ['day' => $this->getDay(-1), 'month' => $this->getMonth(-1), 'year' => $this->getYear(-1)]]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.tomorrow'), $callback);

        $callback = $this->tgDb->prepareCallbackQuery(['callback_event' => [$eventName => 'selectDay'], 'data' => ['day' => $this->getDay(-2), 'month' => $this->getMonth(-2), 'year' => $this->getYear(-2)]]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($this->translate('calendar.day_after_tomorrow'), $callback);

        $this->tgDb->setCallbackQuery();

        return $keyboard;
    }

    public function validateDate($date, $dateRange, $format = 'd.m.Y')
    {
        if (date_create_from_format($format, $date) && strtotime($date) > strtotime('yesterday') && strtotime($date) < strtotime("{$dateRange} day")) {
            return true;
        }

        return false;
    }

    public function timeDiff($timeStart, $timeEnd)
    {
        $timeDiff = ($timeEnd - $timeStart) / 60;

        $hours = (int) floor($timeDiff / 60);
        $hours ? $hoursTemp = $hours : $hoursTemp = 1;
        $minutes = $timeDiff % ($hoursTemp * 60);

        if ($timeDiff > 0) {
            if (0 == intval($hours)) {
                return "{$minutes} мин.";
            } elseif (0 == intval($minutes)) {
                return "{$hours} ч.";
            } else {
                return "{$hours} ч. {$minutes} мин.";
            }
        }

        return null;
    }

    public function validateTime($time)
    {
        $time = array_filter($time);

        if (2 != count($time)) {
            return false;
        }

        if (Validator::time($time[0]) && Validator::time($time[1])) {
            return true;
        }

        return false;
    }

    public function validateTimeRelativelyWork($time, $workTimeStart, $workTimeEnd)
    {
        $timeStart = $time[0];
        $timeEnd = $time[1];
        if (strtotime($timeStart) < strtotime($workTimeStart) ||
            strtotime($timeEnd) > strtotime($workTimeEnd)) {
            return false;
        }

        $timeDiff = (strtotime($timeEnd) - strtotime($timeStart)) / 60;
        $workTimeDiff = (strtotime($workTimeEnd) - strtotime($workTimeStart)) / 60;

        if ($timeDiff > 0 && $timeDiff <= $workTimeDiff) {
            return true;
        }

        return false;
    }

    public function makeAvailableTime($timeStart, $timeEnd)
    {
        $timeText = $this->timeDiff($timeStart, $timeEnd);

        return [
            'timeStart' => date('H:i', $timeStart),
            'timeEnd' => date('H:i', $timeEnd),
            'timeText' => $timeText,
        ];
    }

    public function availableTimes($date, array $times, $workTimeStart, $workTimeEnd, $returnString = false, &$count = 0)
    {
        $workTimeStart = strtotime($workTimeStart);
        $workTimeEnd = strtotime($workTimeEnd);
        $pastTime = false;

        if ($returnString) {
            if ($date == $this->getDate()) {
                $workTimeStartNew = strtotime(date('H:i', time()));
                if ($workTimeStartNew > $workTimeStart && $workTimeStartNew < $workTimeEnd) {
                    $workTimeStart = $workTimeStartNew;
                    $pastTime = true;
                }
            }
        }

        $result = [];
        $tempTime = null;

        if (!$times) {
            $result[] = $this->makeAvailableTime($workTimeStart, $workTimeEnd);
        }

        $total = count($times);
        $counter = 0;

        foreach ($times as $timeKey => $time) {
            ++$counter;
            $end = $counter == $total;
            $notEnd = $counter < $total;

            $time['timeStart'] = strtotime($time['timeStart']);
            $time['timeEnd'] = strtotime($time['timeEnd']);

            if ($time['timeStart'] <= $workTimeStart && $time['timeEnd'] <= $workTimeEnd) {
                if ($time['timeStart'] == $workTimeStart && $time['timeEnd'] == $workTimeEnd && $end) {
                    break;
                } elseif ($time['timeEnd'] >= $workTimeStart && $notEnd) {
                    $tempTime = $time;
                } elseif ($time['timeEnd'] >= $workTimeStart && $end && $pastTime) {
                    break;
                } elseif ($time['timeEnd'] >= $workTimeStart && $end) {
                    $result[] = $this->makeAvailableTime($time['timeEnd'], $workTimeEnd);
                }

                continue;
            }

            if ($time['timeStart'] < $workTimeStart) {
                if ($time['timeEnd'] > $workTimeStart && $time['timeEnd'] < $workTimeEnd && $notEnd) {
                    $workTimeStart = $time['timeEnd'];

                    continue;
                } elseif ($time['timeEnd'] >= $workTimeStart && $time['timeEnd'] <= $workTimeEnd && $end) {
                    break;
                }
            }

            if ($time['timeStart'] > $workTimeEnd && $time['timeEnd'] > $workTimeEnd) {
                continue;
            } elseif ($time['timeStart'] <= $workTimeEnd && $time['timeEnd'] > $workTimeEnd) {
                if ($tempTime) {
                    if ($tempTime['timeEnd'] < $workTimeEnd && $time['timeStart'] > $tempTime['timeEnd']) {
                        $result[] = $this->makeAvailableTime($tempTime['timeEnd'], $time['timeStart']);
                    }
                } else {
                    $result[] = $this->makeAvailableTime($workTimeStart, $time['timeStart']);
                }

                continue;
            }

            if ($tempTime) {
                if ($time['timeStart'] > $tempTime['timeEnd'] && $time['timeStart'] <= $workTimeEnd) {
                    $result[] = $this->makeAvailableTime($tempTime['timeEnd'], $time['timeStart']);
                }
            } else {
                if ($time['timeStart'] > $workTimeStart && $time['timeEnd'] <= $workTimeEnd) {
                    $result[] = $this->makeAvailableTime($workTimeStart, $time['timeStart']);
                } elseif ($time['timeStart'] == $workTimeStart && $time['timeEnd'] <= $workTimeEnd) {
                    $result[] = $this->makeAvailableTime($workTimeStart, $time['timeStart']);
                }
            }

            if ($end && $time['timeEnd'] < $workTimeEnd) {
                if ($tempTime['timeEnd'] < $workTimeEnd && $time['timeStart'] > $tempTime['timeEnd']) {
                    $result[] = $this->makeAvailableTime($time['timeEnd'], $workTimeEnd);
                } elseif ($time['timeEnd'] < $workTimeEnd) {
                    $result[] = $this->makeAvailableTime($time['timeEnd'], $workTimeEnd);
                }
            }

            $tempTime = $time;
        }

        // Пустой результат бывает либо когда день полностью занят, либо события,
        // нахохдящиеся не в промежутке workTimeStart - workTimeEnd
        if ($times && !$result) {
            $allDay = false;

            $time['timeStart'] = null;
            $time['timeEnd'] = null;

            foreach ($times as $time) {
                $time['timeStart'] = strtotime($time['timeStart']);
                $time['timeEnd'] = strtotime($time['timeEnd']);

                if ($time['timeStart'] >= $workTimeStart && $time['timeStart'] < $workTimeEnd
                || !$time['timeStart'] && !$time['timeEnd']) {
                    $allDay = true;
                } elseif ($pastTime && $workTimeStart >= $time['timeStart'] && $time['timeEnd'] >= $workTimeEnd
                || !$time['timeStart'] && !$time['timeEnd']) {
                    $allDay = true;
                }
            }

            if (!$allDay && 1 == count($times) && $time['timeEnd']) {
                $result[] = $this->makeAvailableTime($time['timeEnd'], $workTimeEnd);
            } elseif (!$allDay) {
                $result[] = $this->makeAvailableTime($workTimeStart, $workTimeEnd);
            }
        }

        $count = count($result);

        if ($returnString) {
            $text = null;
            foreach ($result as $time) {
                $text .= sprintf("%s-%s (%s)\n", $time['timeStart'], $time['timeEnd'], $time['timeText']);
            }
            $result = $text;
        }

        return $result;
    }

    public function validateAvailableTimes($times, $timeStart, $timeEnd)
    {
        $timeStart = strtotime($timeStart);
        $timeEnd = strtotime($timeEnd);

        foreach ($times as $time) {
            $time['timeStart'] = strtotime($time['timeStart']);
            $time['timeEnd'] = strtotime($time['timeEnd']);

            if ($timeStart >= $time['timeStart'] && $timeEnd <= $time['timeEnd']) {
                return true;
            }
        }

        return false;
    }
}
