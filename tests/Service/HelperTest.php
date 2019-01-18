<?php

namespace App\Tests\Service;

use App\Service\Helper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HelperTest extends WebTestCase
{
    public function testPhoneFix()
    {
        $phones = [
            '+71231231231',
            '71231231231',
            '81231231231',
            '1231231231',
            '+7 123 123 123 1',
            '+7-123-123-12-31',
            '+81231231231',
            '+7(123)123-12-31',
            '+8(123)123-12-31',
            '+8(123)(123)-(12)-(31)',
            '+7(123)(123)-(12)-(31)'
        ];

        $phoneExpected = '+71231231231';

        foreach ($phones as $phone) {
            $phone = Helper::phoneFix($phone);
            $this->assertEquals($phoneExpected, $phone);
        }
    }

    public function testGetArgs()
    {
        $args = Helper::getArgs('/admin_supercommand', $command);
        $this->assertEquals('supercommand', $args);
        $this->assertEquals('/admin', $command);

        $args = Helper::getArgs('/eventlist_1_1', $command);
        $this->assertEquals('1_1', $args);
        $this->assertEquals('/eventlist', $command);
    }

    public function testGetDateDiffDaysDateTime()
    {
        $dayDefault = '2019-01-10 08:00:00';

        $dayEvent = '2019-01-10 08:00:00';
        $dateDiffDaysDateTime = Helper::getDateDiffDaysDateTime(new \DateTime($dayDefault), new \DateTime($dayEvent));
        $this->assertEquals(0, $dateDiffDaysDateTime);

        $dayEvent = '2019-01-11 08:00:00';
        $dateDiffDaysDateTime = Helper::getDateDiffDaysDateTime(new \DateTime($dayDefault), new \DateTime($dayEvent));
        $this->assertEquals(1, $dateDiffDaysDateTime);

        $dayEvent = '2019-01-09 08:00:00';
        $dateDiffDaysDateTime = Helper::getDateDiffDaysDateTime(new \DateTime($dayDefault), new \DateTime($dayEvent));
        $this->assertEquals(-1, $dateDiffDaysDateTime);
    }
}