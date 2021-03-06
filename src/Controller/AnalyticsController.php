<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AnalyticsController extends Controller
{
    /**
     * @Route("/analytics", name="analytics")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request)
    {
        if ($request->get('auth')) {
            $hash = hash('sha256', $request->get('auth'));

            if ('ee1956c052582e534573d95c67493d21780a982368253de9e78503da67372253' == $hash) {
                return $this->render('analytics/analytics.html.twig');
            }
        }

        return new Response('', Response::HTTP_FORBIDDEN);
    }

    /**
     * @Route("/analytics/data", name="analytics_data")
     *
     * @param Request $request
     *
     * @return JsonResponse|Response
     */
    public function data(Request $request)
    {
        if ($request->isXmlHttpRequest()) {
            $paths = $this->getPaths();

            $content = [];
            foreach ($paths as $path) {
                $content[] = $this->getContent($path);
            }

            $content = array_values(array_filter($content));

            $data = [];
            foreach ($content as $file) {
                foreach ($file as $row) {
                    /*
                     * Date
                     */
                    $date = new \DateTime($row['datetime']['date']);
                    $date = $date->format('d/m/Y H:i:s.v');

                    /*
                     * User
                     */
                    $user = [
                        'first_name' => null,
                        'last_name' => null,
                        'username' => null,
                        'name' => null,
                        'full_name' => null,
                    ];

                    if (isset($row['message']['result']['chat'])) {
                        if (isset($row['message']['result']['chat']['first_name'])) {
                            $user['first_name'] = $row['message']['result']['chat']['first_name'];
                        }
                        if (isset($row['message']['result']['chat']['last_name'])) {
                            $user['last_name'] = $row['message']['result']['chat']['last_name'];
                        }
                        if (isset($row['message']['result']['chat']['username'])) {
                            $user['username'] = $row['message']['result']['chat']['username'];
                        }
                    } elseif (isset($row['message']['callback_query']['from'])) {
                        if (isset($row['message']['callback_query']['from']['first_name'])) {
                            $user['first_name'] = $row['message']['callback_query']['from']['first_name'];
                        }
                        if (isset($row['message']['callback_query']['from']['last_name'])) {
                            $user['last_name'] = $row['message']['callback_query']['from']['last_name'];
                        }
                        if (isset($row['message']['callback_query']['from']['username'])) {
                            $user['username'] = $row['message']['callback_query']['from']['username'];
                        }
                    } elseif (isset($row['message']['message']['from'])) {
                        if (isset($row['message']['message']['from']['first_name'])) {
                            $user['first_name'] = $row['message']['message']['from']['first_name'];
                        }
                        if (isset($row['message']['message']['from']['last_name'])) {
                            $user['last_name'] = $row['message']['message']['from']['last_name'];
                        }
                        if (isset($row['message']['message']['from']['username'])) {
                            $user['username'] = $row['message']['message']['from']['username'];
                        }
                    } elseif (isset($row['message']['ok']) && !$row['message']['ok']) {
                        $user['first_name'] = 'Error';
                    }

                    $user['name'] = implode(' ', array_filter([$user['first_name'], $user['last_name']]));

                    if ($user['username']) {
                        $user['full_name'] = "{$user['name']} ({$user['username']})";
                    } else {
                        $user['full_name'] = $user['name'];
                    }

                    /*
                     * Type
                     */

                    isset($row['message']['callback_query']) ? $type = 'callback' : $type = 'message';

                    /*
                     * Channel
                     */

                    'telegram_request_in' == $row['channel'] ? $channel = 'in' : $channel = 'out';

                    /*
                     * Text
                     */
                    $text = null;

                    if (isset($row['message']['result']['text'])) {
                        $text = $row['message']['result']['text'];
                    } elseif (isset($row['message']['message']['text'])) {
                        $text = $row['message']['message']['text'];
                    } elseif (isset($row['message']['message']['contact'])) {
                        $firstName = null;
                        $lastName = null;
                        if (isset ($row['message']['message']['contact']['first_name'])) {
                            $firstName = $row['message']['message']['contact']['first_name'];
                        }
                        if (isset ($row['message']['message']['contact']['last_name'])) {
                            $lastName = $row['message']['message']['contact']['last_name'];
                        }
                        $name = array_filter([$lastName, $firstName]);
                        if ($name) {
                            $name = implode(' ', $name);
                            $name = "({$name})";
                        } else {
                            $name = 'Неизвестный';
                        }
                        if ($row['message']['message']['contact']['user_id'] != $row['message']['message']['from']['id']) {
                            $text = 'TelegramID контакта не совпадает с аккаунтом пользователя! ';
                        }
                        $text .= "Попытка регистрации: {$row['message']['message']['contact']['phone_number']} {$name}";
                    } elseif (isset($row['message']['callback_query']['message']['text'])) {
                        $text = $row['message']['callback_query']['message']['text'];
                    } elseif (isset($row['message']['ok']) && !$row['message']['ok']) {
                        $text = "[Error {$row['message']['error_code']}] {$row['message']['description']}";
                    }

                    /*
                     * Result
                     */

                    $data[] = [
                        'date' => $date,
                        'user' => $user,
                        'type' => $type,
                        'channel' => $channel,
                        'text' => $text,
                        'data' => json_encode($row['message'], JSON_UNESCAPED_UNICODE),
                    ];
                }
            }

            return new JsonResponse($data);
        }

        return new Response('', Response::HTTP_FORBIDDEN);
    }

    public function getPaths()
    {
        $extension = 'log';
        $path = $this->getParameter('kernel.project_dir').'/var/log';

        $excludeAlways = [
            'dump',
            'php',
        ];

        $sortFolders = [
            'in',
            'out'
        ];

        /**
         * @param \SplFileInfo                     $file
         * @param mixed                            $key
         * @param \RecursiveCallbackFilterIterator $iterator
         *
         * @return bool True if you need to recurse or if the item is acceptable
         */
        $result = [];
        foreach ($sortFolders as $sortFolder) {
            $sortFolder = array_merge([$sortFolder], $excludeAlways);
            $filter = function ($file, $key, $iterator) use ($sortFolder) {
                if ($iterator->hasChildren() && !in_array($file->getFilename(), $sortFolder)) {
                    return true;
                }

                return $file->isFile();
            };

            $rii = new \RecursiveDirectoryIterator(
                $path,
                \RecursiveDirectoryIterator::SKIP_DOTS
            );

            $rii = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator($rii, $filter)
            );

            $files = [];

            /**
             * @var \SplFileInfo[]
             */
            foreach ($rii as $file) {
                if (!$file->isDir()) {
                    if ($file->getExtension() == $extension) {
                        if (strpos( $file->getPathname(), '-test-') !== false) {
                            continue;
                        }

                        $files[] = $file->getPathname();
                    }
                }
            }

            usort($files, function($a, $b) {
                $a = substr($a, -14, -4);
                $b = substr($b, -14, -4);
                $a = (new \DateTime($a))->getTimestamp();
                $b = (new \DateTime($b))->getTimestamp();

                if ($a == $b) {
                    return 0;
                }

                return ($a < $b) ? -1 : 1;
            });

            $files = array_slice($files, -15);

            $result = array_merge($result, $files);
        }

        return $result;
    }

    public function getContent($path)
    {
        $content = file_get_contents($path);

        $contentArray = array_filter(explode("\n", $content));

        $contentLines = [];
        foreach ($contentArray as $contentLine) {
            $contentLine = json_decode($contentLine, true);

            if (!$contentLine['message']) {
                continue;
            }

            $contentLine['message'] = json_decode($contentLine['message'], true);
            $contentLines[] = $contentLine;
        }

        return $contentLines;
    }
}
