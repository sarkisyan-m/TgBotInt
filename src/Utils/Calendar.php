<?php

namespace App\Utils;

class Calendar
{
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
        $monthName = [
            'Январь', 'Февраль', 'Март', 'Апрель',
            'Май', 'Июнь', 'Июль', 'Август',
            'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
        ];
        $month = date('m', strtotime("-{$day} day -{$month} month -{$year} year"));
        return $monthName[$month - 1];
    }

    public function getYear(int $day = 0, int $month = 0, int $year = 0)
    {
        return date("Y", strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function keyboard(int $day = 0, int $month = 0, int $year = 0, string $negotiation = null)
    {
        $keyboard = [];
        $curDay = null;

        $ln = 0;
        $keyboard[$ln][] = ["text" => $this->getMonthText($day, $month, $year) . ", " . $this->getYear($day, $month, $year), "callback_data" => json_encode(["e" => ["calendar" => "cur"], "day" => $day, "month" => $month, "year" => $year])];

        $ln++;
        $dWeek = ['П', 'В', 'С', 'Ч', 'П', 'С', 'В'];
        for ($i = 0; $i < count($dWeek); $i++) {
            $keyboard[$ln][] = ["text" => $dWeek[$i], "callback_data" => "none"];
        }

        $ln++;
        for ($i = 0; $i < $this->getDays($day, $month, $year); $i ++) {
            // в одной строке 7 кнопок (одна неделя)
            if ($i % 7 == 0 && $i != 0)
                $ln++;

            $curDay = $i + 1;

            $keyboard[$ln][] = ["text" => $curDay, "callback_data" => json_encode(["e" => ["calendar" => "sDay"], "n" => $negotiation, "d" => $curDay, "m" => $this->getMonth($day, $month, $year), "y" => $this->getYear($day, $month, $year)])];

            // создаем пустые ячейки
            if ($curDay == $this->getDays($day, $month, $year) /*&& $this->getDays($day, $month, $year) != 28*/) {
                // создаем для 28 февраля еще одну пустую неделю
                if ($this->getDays($day, $month, $year) == 28)
                    $ln++;

                $days = 35 - $this->getDays($day, $month, $year);
                for ($k = 0; $k < $days; $k++) {
                    $keyboard[$ln][] = ["text" => ".", "callback_data" => "none"];
                }
            }
        }
        $ln++;
        $keyboard[$ln][] = ["text" => "\u{2190}", "callback_data" => json_encode(["e" => ["calendar" => "pre"], "d" => $day, "m" => $month, "y" => $year])];
        $keyboard[$ln][] = ["text" => "\u{2192}", "callback_data" => json_encode(["e" => ["calendar" => "fol"], "d" => $day, "m" => $month, "y" => $year])];

        $ln++;
        $keyboard[$ln][] = ["text" => "Сегодня", "callback_data" => json_encode(["e" => ["calendar" => "sDay"], "n" => $negotiation, "d" => $this->getDay("0"), "m" => $this->getMonth(), "y" => $this->getYear()])];
        $keyboard[$ln][] = ["text" => "Завтра", "callback_data" => json_encode(["e" => ["calendar" => "sDay"], "n" => $negotiation, "d" => $this->getDay("-1"), "m" => $this->getMonth(), "y" => $this->getYear()])];
        $keyboard[$ln][] = ["text" => "Послезавтра", "callback_data" => json_encode(["e" => ["calendar" => "sDay"], "n" => $negotiation, "d" => $this->getDay("-2"), "m" => $this->getMonth(), "y" => $this->getYear()])];;

        $ln++;
        $keyboard[$ln][] = ["text" => "Выбрать другую переговорку", "callback_data" => json_encode(["e" => ["n" => "switch"], "d" => $day, "m" => $month, "y" => $year])];

        return $keyboard;
    }


    public function getTimeDiff($timeStart, $timeEnd, $workTimeStart, $workTimeEnd)
    {
        $timeDiff = (strtotime($timeEnd) - strtotime($timeStart)) / 60;
        $workTimeDiff = (strtotime($workTimeEnd) - strtotime($workTimeStart)) / 60;

        $hours = (int)floor($timeDiff / 60);
        $minutes = $timeDiff % ($hours * 60);

        if ($timeDiff > 0 && $timeDiff <= $workTimeDiff)
            return ["h" => $hours, "m" => $minutes];
        else
            return false;
    }

    public function validateDate($date, $dateRange, $format = 'd.m.Y')
    {
        if (date_create_from_format($format, $date) && strtotime($date) > strtotime("yesterday") && strtotime($date) < strtotime("$dateRange day"))
            return true;
        else
            return false;
    }

    public function validateTime($time, $workTimeStart, $workTimeEnd, $format = 'H:i')
    {
        if (date_create_from_format($format, $time))
            return true;
        else
            return false;
    }
}