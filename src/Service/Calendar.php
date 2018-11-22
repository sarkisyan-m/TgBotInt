<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class Calendar
{
    protected $container;

    function __construct(Container $container)
    {
        $this->container = $container;
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

    public function getDateTime(int $day = 0, int $month = 0, int $year = 0)
    {
        return date("d.m.Y", strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function keyboard(int $day = 0, int $month = 0, int $year = 0, string $meetingRoom = null)
    {
        $keyboard = [];
        $curDay = null;

        $ln = 0;
        $keyboard[$ln][] = ["text" => $this->getMonthText($day, $month, $year) . ", " . $this->getYear($day, $month, $year), "callback_data" => json_encode(["e" => ["cal" => "cur"], "mr" => $meetingRoom, "day" => $day, "month" => $month, "year" => $year])];

        $ln++;
        $dWeek = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        for ($i = 0; $i < count($dWeek); $i++)
            $keyboard[$ln][] = ["text" => $dWeek[$i], "callback_data" => "none"];

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
                    $keyboard[$ln][] = ["text" => ".", "callback_data" => "none"];
                }
                $dWeekCount += $emptyCell;
            }

            // создаем ячейки из чисел
            $keyboard[$ln][] = ["text" => $curDay, "callback_data" => json_encode(["e" => ["cal" => "sDay"], "mr" => $meetingRoom, "d" => $curDay, "m" => $this->getMonth($day, $month, $year), "y" => $this->getYear($day, $month, $year)])];


            // создаем пустые ячейки в конце
            if ($curDay == $this->getDays($day, $month, $year) /*&& $this->getDays($day, $month, $year) != 28*/) {
                $emptyCell = count($keyboard[$ln]);
                for ($k = 0; $k < 7 - $emptyCell; $k++) {
                    $keyboard[$ln][] = ["text" => ".", "callback_data" => "none"];
                }
            }

            $dWeekCount++;
        }
        $ln++;
        $keyboard[$ln][] = ["text" => "\u{2190}", "callback_data" => json_encode(["e" => ["cal" => "pre"],  "mr" => $meetingRoom, "d" => $day, "m" => $month, "y" => $year])];
        $keyboard[$ln][] = ["text" => "\u{2192}", "callback_data" => json_encode(["e" => ["cal" => "fol"],  "mr" => $meetingRoom, "d" => $day, "m" => $month, "y" => $year])];

        $ln++;
        $keyboard[$ln][] = ["text" => "Сегодня", "callback_data" => json_encode(["e" => ["cal" => "sDay"], "mr" => $meetingRoom, "d" => $this->getDay("0"), "m" => $this->getMonth(), "y" => $this->getYear()])];
        $keyboard[$ln][] = ["text" => "Завтра", "callback_data" => json_encode(["e" => ["cal" => "sDay"], "mr" => $meetingRoom, "d" => $this->getDay("-1"), "m" => $this->getMonth(), "y" => $this->getYear()])];
        $keyboard[$ln][] = ["text" => "Послезавтра", "callback_data" => json_encode(["e" => ["cal" => "sDay"], "mr" => $meetingRoom, "d" => $this->getDay("-2"), "m" => $this->getMonth(), "y" => $this->getYear()])];;

        $ln++;
        $keyboard[$ln][] = ["text" => "Выбрать другую переговорку", "callback_data" => json_encode(["e" => ["mr" => "switch"], "d" => $day, "m" => $month, "y" => $year])];

        return $keyboard;
    }

    public function getTimeDiff($timeStart, $timeEnd, $workTimeStart, $workTimeEnd)
    {
        if (strtotime($timeStart) < strtotime($workTimeStart) ||
            strtotime($timeEnd) > strtotime($workTimeEnd))
            return false;

        $timeDiff = (strtotime($timeEnd) - strtotime($timeStart)) / 60;
        $workTimeDiff = (strtotime($workTimeEnd) - strtotime($workTimeStart)) / 60;

        $hours = (int)floor($timeDiff / 60);
        $hours ? $hoursTemp = $hours : $hoursTemp = 1;
        $minutes = $timeDiff % ($hoursTemp * 60);

        if ($timeDiff > 0 && $timeDiff <= $workTimeDiff)
            return ["h" => $hours, "m" => $minutes];
        return false;
    }

    public function validateDate($date, $dateRange, $format = 'd.m.Y')
    {
        if (date_create_from_format($format, $date) && strtotime($date) > strtotime("yesterday") && strtotime($date) < strtotime("{$dateRange} day"))
            return true;
        return false;
    }
}