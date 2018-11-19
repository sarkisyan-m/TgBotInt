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

    public function getMonthYear()
    {
        return $this->getMonth() . ", " . $this->getYear();
    }

    public function keyboard(int $day = 0, int $month = 0, int $year = 0)
    {
        $keyboard = [];
        $ln = 0;

        $keyboard[$ln][] = ["text" => $this->getMonth($day, $month, $year) . ", " . $this->getYear($day, $month, $year), "callback_data" => json_encode(["event" => ["calendar" => "current"], "day" => $day, "month" => $month, "year" => $year])];
        $ln++;
        for ($i = 0; $i < $this->getDays($day, $month, $year); $i ++) {
            // в одной строке 7 кнопок
            if ($i % 7 == 0 && $i != 0)
                $ln++;

            $keyboard[$ln][] = ["text" => $i + 1, "callback_data" => "none"];

            // создаем пустые ячейки
            if ($i + 1 == $this->getDays($day, $month, $year) && $this->getDays($day, $month, $year) != 28) {
                $days = 35 - $this->getDays($day, $month, $year);
                for ($k = 0; $k < $days; $k++) {
                    $keyboard[$ln][] = ["text" => $k + 1, "callback_data" => "none"];
                }
            }
        }
        $ln++;
        $keyboard[$ln][] = ["text" => "Пред. мес.", "callback_data" => json_encode(["event" => ["calendar" => "previous"], "day" => $day, "month" => $month, "year" => $year])];
        $keyboard[$ln][] = ["text" => "След. мес.", "callback_data" => json_encode(["event" => ["calendar" => "following"], "day" => $day, "month" => $month, "year" => $year])];
        $ln++;
        $keyboard[$ln][] = ["text" => "Сегодня", "callback_data" => "none"];
        $keyboard[$ln][] = ["text" => "Завтра", "callback_data" => "none"];
        $keyboard[$ln][] = ["text" => "Послезавтра", "callback_data" => "none"];
        return $keyboard;

    }
}