<?php

namespace App\Controller;

use App\Entity\FOSUser;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

use App\Entity\Book;

use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Encoder\JsonDecode;

use App\Form\BooksType;

class BooksAPIController extends Controller
{
    /**
     * @var $request Request
     * @return bool|string
     */
    function checkApiKey($request)
    {
        if (!empty($apiKey = $request->get('apiKey'))) {
            $repository = $this->getDoctrine()->getRepository(FOSUser::class);
            $apiKey = $repository->findBy(["apiKey" => $apiKey]);

            if (!$apiKey)
                return "API KEY NOT FOUND";
        } else {
            return "ENTER API KEY";
        }
    }

    /**
     * @Route("api/v1/books", name="books_api_list")
     * @param SerializerInterface $serializer
     * @param Request $request
     * @return JsonResponse
     */
    function booksList(SerializerInterface $serializer, Request $request)
    {
        if ($checkApiKey = $this->checkApiKey($request))
            return new JsonResponse($checkApiKey);

        $repository = $this->getDoctrine()->getRepository(Book::class);
        $books = $repository->findBy([], ['reading_date' => 'DESC']);

        $rootPath = "://" . $_SERVER["HTTP_HOST"];

        foreach ($books as &$book) {
            if($book->getFile() && $book->getAllowDownload()) {
                $book->setFile($rootPath . $book->getFile());
            } else {
                $book->setFile('');
            }
            if($book->getCover())
                $book->setCover($rootPath . $book->getCover());
        }

        $jsonContent = $serializer->serialize($books, 'json');
        $jsonDecode = new JsonDecode;

        return new JsonResponse($jsonDecode->decode($jsonContent, 'json'));
    }

    /**
     * @Route("/api/v1/add", name="books_api_add")
     * @param Request $request
     * @return JsonResponse
     */
    function booksAdd(Request $request)
    {
        if ($checkApiKey = $this->checkApiKey($request))
            return new JsonResponse($checkApiKey);

        $book = new Book;

        if (!$request->get("name"))
            return new JsonResponse('BBeguTe Ha3BaHue KHuru');

        if (!$request->get("author"))
            return new JsonResponse('BBeguTe aBTopa KHuru');

        if (!$request->get("reading_date"))
            return new JsonResponse('BBeguTe gaTy IIpo4TeHu9I KHuru');

        $book->setName($request->get('name'));
        $book->setAuthor($request->get('author'));
        $book->setAllowDownload($request->get('allow_download'));
        $book->setReadingDate($request->get('reading_date'));

        $book->setCover('');
        $book->setFile('');

        $em = $this->getDoctrine()->getManager();
        $em->persist($book);
        $em->flush();

        return new JsonResponse(true);
    }

    /**
     * @Route("/api/v1/{id}/edit", name="books_api_edit")
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    function booksEdit(Request $request, $id)
    {
        if ($checkApiKey = $this->checkApiKey($request))
            return new JsonResponse($checkApiKey);

        $repository = $this->getDoctrine()->getRepository(Book::class);
        $book = $repository->find($id);

        if ($book->getFile())
            $fileCurrent = $book->getFile();
        else
            $fileCurrent = null;
        if ($book->getCover())
            $coverCurrent = $book->getCover();
        else
            $coverCurrent = null;

        if (!$book)
            return new JsonResponse('KHura He HaugeHa!');

        if (!$request->get("name"))
            return new JsonResponse('BBeguTe Ha3BaHue KHuru');

        if (!$request->get("author"))
            return new JsonResponse('BBeguTe aBTopa KHuru');

        if (!$request->get("reading_date"))
            return new JsonResponse('BBeguTe gaTy IIpo4TeHu9I KHuru');

        $book->setName($request->get('name'));
        $book->setAuthor($request->get('author'));
        $book->setAllowDownload($request->get('allow_download'));
        $book->setReadingDate($request->get('reading_date'));

        $book->setCover($coverCurrent);
        $book->setFile($fileCurrent);

        $em = $this->getDoctrine()->getManager();
        $em->persist($book);
        $em->flush();

        return new JsonResponse(true);
    }
}