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

            if ('ee1956c052582e534573d95c67493d21780a982368253de9e78503da67372253' != $hash) {
                return new Response('', Response::HTTP_FORBIDDEN);
            }
        }

        return $this->render('analytics/analytics.html.twig');
    }

    public function sortPositionDistance($x, $y)
    {
        return $x['TOTAL_QUANTITY_LACKS'] > $y['TOTAL_QUANTITY_LACKS'] ||
        $x['TOTAL_QUANTITY_LACKS'] == $y['TOTAL_QUANTITY_LACKS'] && $x['DISTANCE_DIFF'] > $y['DISTANCE_DIFF'] ? 1 :
            ($x['TOTAL_QUANTITY_LACKS'] < $y['TOTAL_QUANTITY_LACKS'] ? -1 : 0);
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
        if ($request->isXmlHttpRequest() || true) {
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
                    } elseif (isset($row['message']['callback_query']['message']['text'])) {
                        $text = $row['message']['callback_query']['message']['text'];
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

//            $data = json_encode($data);

            return new JsonResponse($data);
        }

        return new Response('', Response::HTTP_FORBIDDEN);
    }

    public function getPaths()
    {
        $extension = 'log';
        $path = $this->getParameter('kernel.project_dir').'/var/log';

        $exclude = [
            'dump',
        ];

        /**
         * @param \SplFileInfo                     $file
         * @param mixed                            $key
         * @param \RecursiveCallbackFilterIterator $iterator
         *
         * @return bool True if you need to recurse or if the item is acceptable
         */
        $filter = function ($file, $key, $iterator) use ($exclude) {
            if ($iterator->hasChildren() && !in_array($file->getFilename(), $exclude)) {
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
         * @var $file \SplFileInfo
         */
        foreach ($rii as $file) {
            if (!$file->isDir()) {
                if ($file->getExtension() == $extension) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
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
