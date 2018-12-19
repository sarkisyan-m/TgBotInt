<?php

namespace App\Service;

use App\Model\BitrixUser;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Serializer\SerializerInterface;

class Bitrix24API
{
    protected $bitrix24Url;

    protected $cache;
    protected $cacheTime;
    protected $cacheContainer;

    /**
     * @var BitrixUser[]
     */
    protected $users;
    protected $data;

    protected $serializer;

    function __construct($bitrix24Url, $bitrix24UserId, $bitrix24Api, CacheInterface $cache, $cacheTime, $cacheContainer, SerializerInterface $serializer)
    {
        $this->bitrix24Url = $bitrix24Url;
        $this->bitrix24Url .= $bitrix24UserId . "/";
        $this->bitrix24Url .= $bitrix24Api . "/";

        $this->cache = $cache;
        $this->cacheTime = $cacheTime;
        $this->cacheContainer = $cacheContainer;

        $this->serializer = $serializer;

        $this->users = $this->loadData();
    }

    public function phoneFix($phone)
    {
        if (!$phone) {
            return null;
        }

        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 11) {
            $phone = substr($phone, 1);
            $phone = "+7" . $phone;
        } elseif (strlen($phone) == 10) {
            $phone = "+7" . $phone;
        }

        return $phone;
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

        $method = "user.get";
        $url = $this->bitrix24Url . $method . "/";
        $pagination = 50;
        $result = [];

//        $total = Helper::curl($url, ["ACTIVE" => true], true);
        $total = Helper::curl($url, [], true);
        $total = ceil($total["total"] / $pagination);

        for ($i = 0; $i < $total; $i++) {
            $page = $i * $pagination;
//            $args = ["start" => $page, "ACTIVE" => true];
            $args = ["start" => $page];
            $result = array_merge($result, Helper::curl($url, $args, true)["result"]);
        }

        foreach ($result as &$user) {
            $user = array_change_key_case($user, CASE_LOWER);

            $email = $user["email"];
            $email = trim($email);
            $email = strtolower($email);

            if (!Validator::email($email)) {
                $email = null;
            }

            $personalPhone = $this->phoneFix($user["personal_phone"]);
            if (!Validator::phone($personalPhone)) {
                $personalPhone = null;
            }

            $personalMobile = $this->phoneFix($user["personal_mobile"]);
            if (!Validator::phone($personalMobile)) {
                $personalMobile = null;
            }

            $workPhone = $this->phoneFix($user["work_phone"]);
            if (!Validator::phone($workPhone)) {
                $workPhone = null;
            }

            $firstPhone = null;
            if ($personalPhone) {
                $firstPhone = $personalPhone;
            } elseif ($personalMobile) {
                $firstPhone = $personalMobile;
            } elseif ($workPhone) {
                $firstPhone = $workPhone;
            }

            $name = "{$user["name"]} {$user["last_name"]}";

//            if ($name == "Михаил Саркисян") {
//                $user["active"] = true;
//            }

            $user = [
                "id" => $user["id"],
                "email" => $email,
                "name" => $name,
                "first_name" => $user["name"],
                "last_name" => $user["last_name"],
                "personal_phone" => $personalPhone,
                "personal_mobile" => $personalMobile,
                "work_phone" => $workPhone,
                "first_phone" => $firstPhone,
                "active" => $user["active"]
            ];

            $user = $this->serializer->deserialize(json_encode($user), BitrixUser::class, 'json');

            unset($user);
        }

        //TEST

//        $user = [
//            "id" => 1000,
//            "email" => "test1@example.com",
//            "name" => "Иван Иванов",
//            "first_name" => "Иван",
//            "last_name" => "Иванов",
//            "personal_phone" => "+71231231231",
//            "personal_mobile" => null,
//            "work_phone" => null,
//            "first_phone" => "+71231231231",
//            "active" => true
//        ];
//        $user = $this->serializer->deserialize(json_encode($user), BitrixUser::class, 'json');
//        array_push($result, $user);
//
//        $user = [
//            "id" => 1001,
//            "email" => "test2@example.com",
//            "name" => "Иван Иванов",
//            "first_name" => "Иван",
//            "last_name" => "Иванов",
//            "personal_phone" => "+72231231231",
//            "personal_mobile" => null,
//            "work_phone" => null,
//            "first_phone" => "+72231231231",
//            "active" => true
//        ];
//        $user = $this->serializer->deserialize(json_encode($user), BitrixUser::class, 'json');
//        array_push($result, $user);
//
//        $user = [
//            "id" => 1002,
//            "email" => "test3@example.com",
//            "name" => "Петр Петров",
//            "first_name" => "Петр",
//            "last_name" => "Петров",
//            "personal_phone" => "+73231231231",
//            "personal_mobile" => null,
//            "work_phone" => null,
//            "first_phone" => "+73231231231",
//            "active" => true
//        ];
//        $user = $this->serializer->deserialize(json_encode($user), BitrixUser::class, 'json');
//        array_push($result, $user);
//
//        $user = [
//            "id" => 1003,
//            "email" => "test4@example.com",
//            "name" => "Петр Петров",
//            "first_name" => "Петр",
//            "last_name" => "Петров",
//            "personal_phone" => "+74231231231",
//            "personal_mobile" => null,
//            "work_phone" => null,
//            "first_phone" => "+74231231231",
//            "active" => true
//        ];
//        $user = $this->serializer->deserialize(json_encode($user), BitrixUser::class, 'json');
//        array_push($result, $user);
//
//        $user = [
//            "id" => 1004,
//            "email" => "test5@example.com",
//            "name" => "Петр Петров",
//            "first_name" => "Петр",
//            "last_name" => "Петров",
//            "personal_phone" => "+75231231231",
//            "personal_mobile" => null,
//            "work_phone" => null,
//            "first_phone" => "+75231231231",
//            "active" => true
//        ];
//        $user = $this->serializer->deserialize(json_encode($user), BitrixUser::class, 'json');
//        array_push($result, $user);

        //TEST

        try {
            $this->cache->set($this->cacheContainer, $result, $this->cacheTime);

            return $this->cache->get($this->cacheContainer);
        } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
            error_log($e->getMessage());

            return null;
        }
    }

    public function getFilters($filter)
    {
        $filtersKey = [
            "id",
            "name",
            "phone",
            "email"
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

    /**
     * @param array $filter
     * @return BitrixUser[]|mixed|null
     */
    public function getUsers($filter = [])
    {
        $filter = $this->getFilters($filter);
        $filter = array_filter($filter);
        $users = [];
        if ($filter) {
            foreach ($this->users as $user) {
                foreach ($filter as $filterKey => $filterValue) {

                    if ($filterKey == "id") {
                        if (is_array($filterValue)) {
                            if (array_search($user->getId(), $filterValue) !== false) {
                                $users[] = $user;
                            }
                        } else {
                            if ($user->getId() == $filterValue) {
                                $users[] = $user;
                            }
                        }

                        break;
                    }

                    if ($filterKey == "name") {
                        $fullName1 = "{$user->getFirstName()} {$user->getLastName()}";
                        $fullName2 = "{$user->getLastName()} {$user->getFirstName()}";
                        if (is_array($filterValue)) {
                            if (array_search($fullName1, $filterValue) !== false ||
                                array_search($fullName2, $filterValue) !== false ) {
                                $users[] = $user;
                            }
                        } else {
                            if ($fullName1 == $filterValue ||
                                $fullName2 == $filterValue) {
                                $users[] = $user;
                            }
                        }

                        break;
                    }

                    if ($filterKey == "phone") {
                        if (is_array($filterValue)) {
                            if (array_search($user->getPersonalMobile(), $filterValue) !== false ||
                                array_search($user->getPersonalPhone(), $filterValue) !== false ||
                                array_search($user->getWorkPhone(), $filterValue) !== false) {
                                $users[] = $user;
                            }
                        } else {
                            if ($user->getPersonalMobile() == $filterValue ||
                                $user->getPersonalPhone() == $filterValue ||
                                $user->getWorkPhone() == $filterValue) {
                                $users[] = $user;
                            }
                        }

                        break;
                    }

                    if ($filterKey == "email") {
                        if (is_array($filterValue)) {
                            if (array_search($user->getEmail(), $filterValue) !== false) {
                                $users[] = $user;
                            }
                        } else {
                            if ($user->getEmail() == $filterValue) {
                                $users[] = $user;
                            }
                        }

                        break;
                    }
                }
            }

            return $users;
        }

        return $this->loadData();
    }
}