<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class Calendar
{
    protected $container;
    protected $tgBot;

    function __construct(Container $container, $tgBot)
    {
        $this->container = $container;

        /**
         * @var $tgBot TelegramAPI
         */
        $this->tgBot = $tgBot;
    }

    public function getDays(int $day = 0, int $month = 0, int $year = 0)
    {
        return date("t", strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function getDay(int $day = 0, int $month = 0, int $year = 0)
    {
        return date("d", strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function getMonth(int $day = 0, int $month = 0, int $year = 0)
    {

        return date("m", strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function getMonthText(int $day = 0, int $month = 0, int $year = 0)
    {
        $monthName = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
        $month = date('m', strtotime("-{$day} day -{$month} month -{$year} year"));
        return $monthName[$month - 1];
    }

    public function getWeekText(int $day = 0, int $month = 0, int $year = 0, $returnArrayKeys = false)
    {
        $days = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];

        if ($returnArrayKeys)
            return array_search($days[(date('w', strtotime("-{$day} day -{$month} month -{$year} year")))], $days);

        return $days[(date('w', strtotime("-{$day} day -{$month} month -{$year} year"))) - 1];
    }

    public function getYear(int $day = 0, int $month = 0, int $year = 0)
    {
        return date("Y", strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function getDate(int $day = 0, int $month = 0, int $year = 0)
    {
        return date("d.m.Y", strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function keyboard(int $day = 0, int $month = 0, int $year = 0, string $meetingRoom = null)
    {
        $keyboard = [];
        $curDay = null;

        $ln = 0;
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton(
            $this->getMonthText($day, $month, $year) . ", " . $this->getYear($day, $month, $year),
            ["e" => ["cal" => "cur"], "mr" => $meetingRoom, "day" => $day, "month" => $month, "year" => $year]
        );

        $ln++;
        $dWeek = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        for ($i = 0; $i < count($dWeek); $i++)
            $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($dWeek[$i], "none");

        $ln++;
        $dWeekCount = 0;
        for ($i = 0; $i < $this->getDays($day, $month, $year); $i++) {
            // в одной строке 7 кнопок (одна неделя)
            if ($dWeekCount % 7 == 0 && $dWeekCount != 0)
                $ln++;

            $curDay = $i + 1;

            // создаем пустые ячейки в начале
            if ($curDay == 1) {
                $emptyCell = $this->getWeekText($this->getDay($day, $month, $year), $month, $year, true);
                for ($k = 0; $k < $emptyCell; $k++) {
                    $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton(".", "none");
                }
                $dWeekCount += $emptyCell;
            }

            // создаем ячейки из чисел
            $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton($curDay, ["e" => ["cal" => "sDay"], "mr" => $meetingRoom, "d" => $curDay, "m" => $this->getMonth($day, $month, $year), "y" => $this->getYear($day, $month, $year)]);

            // создаем пустые ячейки в конце
            if ($curDay == $this->getDays($day, $month, $year)) {
                $emptyCell = count($keyboard[$ln]);
                for ($k = 0; $k < 7 - $emptyCell; $k++) {
                    $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton(".", "none");
                }
            }

            $dWeekCount++;
        }
        $ln++;
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton("\u{2190}", ["e" => ["cal" => "pre"],  "mr" => $meetingRoom, "d" => $day, "m" => $month, "y" => $year]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton("\u{2192}", ["e" => ["cal" => "fol"],  "mr" => $meetingRoom, "d" => $day, "m" => $month, "y" => $year]);

        $ln++;
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton("Сегодня", ["e" => ["cal" => "sDay"], "mr" => $meetingRoom, "d" => $this->getDay("0"), "m" => $this->getMonth(), "y" => $this->getYear()]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton("Завтра", ["e" => ["cal" => "sDay"], "mr" => $meetingRoom, "d" => $this->getDay("-1"), "m" => $this->getMonth(), "y" => $this->getYear()]);
        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton("Послезавтра", ["e" => ["cal" => "sDay"], "mr" => $meetingRoom, "d" => $this->getDay("-2"), "m" => $this->getMonth(), "y" => $this->getYear()]);

        $ln++;
//        $keyboard[$ln][] = $this->tgBot->InlineKeyboardButton("Выбрать другую переговорку", ["e" => ["mr" => "switch"], "d" => $day, "m" => $month, "y" => $year]);

        return $keyboard;
    }


    public function validateDate($date, $dateRange, $format = 'd.m.Y')
    {
        if (date_create_from_format($format, $date) && strtotime($date) > strtotime("yesterday") && strtotime($date) < strtotime("{$dateRange} day"))
            return true;
        return false;
    }

    public function timeDiff($timeStart, $timeEnd)
    {
        $timeDiff = ($timeEnd - $timeStart) / 60;

        $hours = (int)floor($timeDiff / 60);
        $hours ? $hoursTemp = $hours : $hoursTemp = 1;
        $minutes = $timeDiff % ($hoursTemp * 60);

        if ($timeDiff > 0 ) {
            if (intval($hours) == 0)
                return "{$minutes} мин.";
            elseif (intval($minutes) == 0)
                return "{$hours} ч.";
            else
                return "{$hours} ч. {$minutes} мин.";
        }

        return false;
    }

    public function validateTime($timeStart, $timeEnd, $workTimeStart, $workTimeEnd)
    {
        if (strtotime($timeStart) < strtotime($workTimeStart) ||
            strtotime($timeEnd) > strtotime($workTimeEnd))
            return false;

        $timeDiff = (strtotime($timeEnd) - strtotime($timeStart)) / 60;
        $workTimeDiff = (strtotime($workTimeEnd) - strtotime($workTimeStart)) / 60;

        if ($timeDiff > 0 && $timeDiff <= $workTimeDiff)
            return true;
        return false;
    }

    public function makeAvailableTime($timeStart, $timeEnd)
    {
        $timeText = $this->timeDiff($timeStart, $timeEnd);
        return [
            "timeStart" => date("H:i", $timeStart),
            "timeEnd" => date("H:i", $timeEnd),
            "timeText" => $timeText
        ];
    }

    public function AvailableTimes(array $times, $workTimeStart, $workTimeEnd, $returnString = false)
    {
        $workTimeStart = strtotime($workTimeStart);
        $workTimeEnd = strtotime($workTimeEnd);

        $result = [];
        $tempTime = null;

        if (!$times) {
            $result[] = $this->makeAvailableTime($workTimeStart, $workTimeEnd);
        }

        foreach ($times as $time) {

            $time["timeStart"] = strtotime($time["timeStart"]);
            $time["timeEnd"] = strtotime($time["timeEnd"]);

            if ($time["timeStart"] < $workTimeStart) {
                if ($time["timeEnd"] >= $workTimeStart && $time["timeEnd"] <= $workTimeEnd) {
                    $workTimeStart = $time["timeEnd"];
                    continue;
                }
                continue;
            }

            if ($time["timeStart"] > $workTimeEnd && $time["timeEnd"] > $workTimeEnd) {
                if ($tempTime) {
                    if ($tempTime["timeEnd"] < $workTimeEnd) {
                        $result[] = $this->makeAvailableTime($tempTime["timeEnd"], $tempTime["timeEnd"]);
                    }
                }
                continue;
            } elseif ($time["timeStart"] <= $workTimeEnd && $time["timeEnd"] > $workTimeEnd) {
                if ($tempTime) {
                    if ($tempTime["timeEnd"] < $workTimeEnd) {
                        $result[] = $this->makeAvailableTime($tempTime["timeEnd"], $time["timeStart"]);
                    }
                }
                continue;
            }

            if ($tempTime) {
                if ($time["timeStart"] >= $tempTime["timeEnd"] && $time["timeStart"] <= $workTimeEnd) {
                    $result[] = $this->makeAvailableTime($tempTime["timeEnd"], $time["timeStart"]);
                }

            } else {
                if ($time["timeStart"] >= $workTimeStart && $time["timeEnd"] <= $workTimeEnd) {
                    $result[] = $this->makeAvailableTime($workTimeStart, $time["timeStart"]);
                }
            }

            if ($time["timeStart"] <= $workTimeEnd && $time["timeEnd"] <= $workTimeEnd && !next($times)) {
                if ($time["timeEnd"] < $workTimeEnd) {
                    if ($tempTime["timeEnd"] < $workTimeEnd) {
                        $result[] = $this->makeAvailableTime($time["timeEnd"], $workTimeEnd);
                    }
                }
            }

            $tempTime = $time;

        }


        if ($returnString) {
            $text = null;
            foreach ($result as $time)
                $text .= sprintf("%s-%s (%s)\n", $time["timeStart"], $time["timeEnd"], $time["timeText"]);
            $result = $text;
        }

        return $result;
    }

    public function validateAvailableTimes($times, $timeStart, $timeEnd)
    {
        $timeStart = strtotime($timeStart);
        $timeEnd = strtotime($timeEnd);

        if (!$times)
            return true;
        foreach ($times as $time) {
            $time["timeStart"] = strtotime($time["timeStart"]);
            $time["timeEnd"] = strtotime($time["timeEnd"]);

            if ($timeStart >= $time["timeStart"] && $timeEnd <= $time["timeEnd"]) {
                return true;
            }
        }
        return false;
    }

}