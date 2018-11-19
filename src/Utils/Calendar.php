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
        $monthName = [
            'Январь',
            'Февраль',
            'Март',
            'Апрель',
            'Май',
            'Июнь',
            'Июль',
            'Август',
            'Сентябрь',
            'Октябрь',
            'Ноябрь',
            'Декабрь'
        ];
        $month = date('m', strtotime("-{$day} day -{$month} month -{$year} year"));
        return $monthName[$month - 1];
    }

    public function getYear(int $day = 0, int $month = 0, int $year = 0)
    {
        return date("Y", strtotime("-{$day} day -{$month} month -{$year} year"));
    }

    public function keyboard(int $day = 0, int $month = 0, int $year = 0)
    {
        $keyboard = [];
        $curDay = null;
        $ln = 0;

        $keyboard[$ln][] = ["text" => $this->getMonth($day, $month, $year) . ", " . $this->getYear($day, $month, $year), "callback_data" => json_encode(["event" => ["calendar" => "current"], "day" => $day, "month" => $month, "year" => $year])];
        $ln++;
        for ($i = 0; $i < $this->getDays($day, $month, $year); $i ++) {
            // в одной строке 7 кнопок (одна неделя)
            if ($i % 7 == 0 && $i != 0)
                $ln++;

            $curDay = $i + 1;

            $keyboard[$ln][] = ["text" => $curDay, "callback_data" => "none"];

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
        $keyboard[$ln][] = ["text" => "\u{2190}", "callback_data" => json_encode(["event" => ["calendar" => "previous"], "d" => $day, "m" => $month, "y" => $year])];
        $keyboard[$ln][] = ["text" => "\u{2192}", "callback_data" => json_encode(["event" => ["calendar" => "following"], "d" => $day, "m" => $month, "y" => $year])];
        $ln++;
        $keyboard[$ln][] = ["text" => "Сегодня", "callback_data" => "none"];
        $keyboard[$ln][] = ["text" => "Завтра", "callback_data" => "none"];
        $keyboard[$ln][] = ["text" => "Послезавтра", "callback_data" => "none"];
        $ln++;
        $keyboard[$ln][] = ["text" => "Выбрать другую переговорку", "callback_data" => json_encode(["event" => ["negotiation" => "switch"], "d" => $day, "m" => $month, "y" => $year])];

        return $keyboard;

    }
}