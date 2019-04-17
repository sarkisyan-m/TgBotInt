<?php

namespace App\API\Bitrix24;

use App\API\Bitrix24\Model\BitrixUser;
use App\Service\Helper;
use App\Service\Validator;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Serializer\SerializerInterface;

class Bitrix24API
{
    protected $bitrix24Url;

    protected $cache;
    protected $cacheTime;
    protected $cacheContainer;
    protected $data;
    protected $serializer;

    public function __construct(
        $bitrix24Url,
        $bitrix24UserId,
        $bitrix24Api,
        CacheInterface $cache,
        $cacheTime,
        $cacheContainer,
        SerializerInterface $serializer
    ) {
        $this->bitrix24Url = $bitrix24Url;
        $this->bitrix24Url .= $bitrix24UserId.'/';
        $this->bitrix24Url .= $bitrix24Api.'/';

        $this->cache = $cache;
        $this->cacheTime = $cacheTime;
        $this->cacheContainer = $cacheContainer;

        $this->serializer = $serializer;
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

            return [];
        }

        $method = 'user.get';
        $url = $this->bitrix24Url.$method.'/';

        $multiCurl = [];
        $mh = curl_multi_init();
        $process = 0;

        $pagination = 50;
        $totalPage = 0;

        $result = [];
        do {
            $fetchUrl = $url.'?start='.($pagination * $process);

            $multiCurl[$process] = curl_init();
            $parameter = [
                CURLOPT_URL => $fetchUrl,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 10,
            ];
            curl_setopt_array($multiCurl[$process], $parameter);
            curl_multi_add_handle($mh, $multiCurl[$process]);

            if (0 == $process) {
                $index = 0;
                do {
                    curl_multi_exec($mh, $index);
                } while ($index > 0);

                $result = json_decode(curl_multi_getcontent($multiCurl[$process]), true);
                $totalPage = ceil($result['total'] / $pagination);
                $result = $result['result'];

                curl_multi_remove_handle($mh, $multiCurl[$process]);
                unset($multiCurl[$process]);
            }

            ++$process;
        } while ($totalPage > $process);

        $index = 0;
        do {
            curl_multi_exec($mh, $index);
        } while ($index > 0);

        foreach ($multiCurl as $ch) {
            $result = array_merge($result, json_decode(curl_multi_getcontent($ch), true)['result']);

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        foreach ($result as &$user) {
            $user = array_change_key_case($user, CASE_LOWER);

            $email = $user['email'];
            $email = trim($email);
            $email = strtolower($email);

            if (!Validator::email($email)) {
                $email = null;
            }

            $personalPhone = Helper::phoneFix($user['personal_phone']);
            if (!Validator::phone($personalPhone)) {
                $personalPhone = null;
            }

            $personalMobile = Helper::phoneFix($user['personal_mobile']);
            if (!Validator::phone($personalMobile)) {
                $personalMobile = null;
            }

            $workPhone = Helper::phoneFix($user['work_phone']);
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

            $user['name'] = Helper::rusLetterFix($user['name']);
            $user['last_name'] = Helper::rusLetterFix($user['last_name']);

            $name = "{$user['name']} {$user['last_name']}";

            $user = [
                'id' => $user['id'],
                'email' => $email,
                'name' => $name,
                'first_name' => $user['name'],
                'last_name' => $user['last_name'],
                'personal_phone' => $personalPhone,
                'personal_mobile' => $personalMobile,
                'work_phone' => $workPhone,
                'first_phone' => $firstPhone,
                'active' => $user['active'],
            ];

            $user = $this->serializer->deserialize(json_encode($user), BitrixUser::class, 'json');

            unset($user);
        }

        //______________________________________________________________________
        //TEST
        //______________________________________________________________________

        $user = [
            'id' => 1000,
            'email' => 'test2_test@example.com',
            'name' => 'Иван Иванов',
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'personal_phone' => '+72231231231',
            'personal_mobile' => null,
            'work_phone' => null,
            'first_phone' => '+72231231231',
            'active' => true,
        ];
        $user = $this->serializer->deserialize(json_encode($user), BitrixUser::class, 'json');
//        array_push($result, $user);

        //______________________________________________________________________
        //TEST
        //______________________________________________________________________

        if ($result) {
            try {
                $this->cache->set($this->cacheContainer, $result, $this->cacheTime);
            } catch (\Psr\SimpleCache\InvalidArgumentException $e) {
                error_log($e->getMessage());
            }
        }

        return $result;
    }

    public function getFilters($filter)
    {
        // Указываются фильтры и их приоритет
        $filtersKey = [
            'active',
            'id',
            'name',
            'phone',
            'email',
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

        $result = array_merge($filterAvailableKeys, $resultFilter);

        foreach ($result as $filterKey => $filterValue) {
            if (null === $filterValue) {
                unset($result[$filterKey]);
            }
        }

        return $result;
    }

    /**
     * @param array $filter
     *
     * @return BitrixUser[]|mixed|null
     */
    public function getUsers($filter = [])
    {
        $filter = $this->getFilters($filter);

        /**
         * @var BitrixUser[]
         */
        $bitrixUsers = $this->loadData();

        $users = [];
        if ($filter) {
            foreach ($bitrixUsers as $user) {
                foreach ($filter as $filterKey => $filterValue) {
                    if ('active' == $filterKey) {
                        if (1 == count($filter)) {
                            if ($user->getActive() == $filterValue) {
                                $users[] = $user;
                            }
                        } else {
                            if ($user->getActive() != $filterValue) {
                                break;
                            }
                        }
                    }

                    if ('id' == $filterKey) {
                        if (is_array($filterValue)) {
                            if (false !== ($position = array_search($user->getId(), $filterValue))) {
                                $users[(int) $position] = $user;
                            }
                        } else {
                            if ($user->getId() == $filterValue) {
                                $users[] = $user;
                            }
                        }

                        break;
                    }

                    if ('name' == $filterKey) {
                        $fullName1 = "{$user->getFirstName()} {$user->getLastName()}";
                        $fullName2 = "{$user->getLastName()} {$user->getFirstName()}";
                        if (is_array($filterValue)) {
                            if (false !== ($position = array_search($fullName1, $filterValue)) ||
                                false !== ($position = array_search($fullName2, $filterValue)) ||
                                false !== ($position = array_search($user->getFirstName(), $filterValue)) ||
                                false !== ($position = array_search($user->getLastName(), $filterValue))) {
                                $users[(int) $position] = $user;
                            }
                        } else {
                            if ($fullName1 == $filterValue ||
                                $fullName2 == $filterValue ||
                                $user->getFirstName() == $filterValue ||
                                $user->getLastName() == $filterValue) {
                                $users[] = $user;
                            }
                        }

                        break;
                    }

                    if ('phone' == $filterKey) {
                        if (is_array($filterValue)) {
                            if (false !== ($position = array_search($user->getPersonalMobile(), $filterValue)) ||
                                false !== ($position = array_search($user->getPersonalPhone(), $filterValue)) ||
                                false !== ($position = array_search($user->getWorkPhone(), $filterValue))) {
                                $users[(int) $position] = $user;
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

                    if ('email' == $filterKey) {
                        if (is_array($filterValue)) {
                            if (false !== ($position = array_search($user->getEmail(), $filterValue))) {
                                $users[(int) $position] = $user;
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

            ksort($users);

            return $users;
        }

        return $bitrixUsers;
    }
}
