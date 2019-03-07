<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
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
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index(Request $request)
    {
        if ($request->get('auth')) {
            $hash = hash('sha256', $request->get('auth'));

            if ($hash != 'ee1956c052582e534573d95c67493d21780a982368253de9e78503da67372253') {
                return new Response('', Response::HTTP_FORBIDDEN);
            }
        }

        $paths = $this->getPaths();

        $content = [];
        foreach ($paths as $path) {
            $content[] = $this->getContent($path);
        }

        $content = array_values(array_filter($content));

        dump([$content]);

        return $this->render('analytics/analytics.html.twig', [
            'content' => $content
        ]);
    }

    public function getPaths()
    {
        $extension = 'log';
        $path = $this->getParameter('kernel.project_dir') . '/var/log';

        $exclude = [
            'dump'
        ];

        /**
         * @param \SplFileInfo $file
         * @param mixed $key
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
            if (!$file->isDir()){
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