<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

use App\Entity\Book;
use App\Form\BooksType;
use Symfony\Component\Cache\Simple\FilesystemCache;

/**
 * Class BooksController
 * @package App\Controller
 */
class BooksController extends Controller
{
    /**
     * @var $file UploadedFile
     * @var $book Book
     * @param $type
     * @param $fileCurrent
     */
    protected function uploadFile($file, $book, $type, $fileCurrent = false)
    {
        $rootPath = $this->getParameter('kernel.project_dir') . '/public';

        if ($fileCurrent && $file && $type == 'file') {
            if (file_exists($rootPath . $fileCurrent))
                unlink($rootPath . $fileCurrent);
        } else if ($fileCurrent && !$file && $type == 'file') {
            $book->setFile($fileCurrent);
        }

        if ($fileCurrent && $file && $type == 'image') {
            if (file_exists($rootPath . $fileCurrent))
                unlink($rootPath . $fileCurrent);
        } else if ($fileCurrent && !$file && $type == 'image') {
            $book->setCover($fileCurrent);
        }

        if (!$file)
            return;

        $salt = time() . rand(100, 999) . '_';

        if ($type == 'file') {
            $fileName = $salt . $file->getClientOriginalName();
            $file->move($this->getParameter('files_dir_full'), $fileName);
            $book->setFile($this->getParameter('files_dir_short') . $fileName);
        }

        if ($type == 'image') {
            $fileName = $salt . $file->getClientOriginalName();
            $file->move($this->getParameter('images_dir_full'), $fileName);
            $book->setCover($this->getParameter('images_dir_short') . $fileName);
        }
    }

    /**
     * @var $book Book
     */
    protected function testInfo($book)
    {
        $book->setName('Книга #' . rand(1000, 9999));
        $book->setAuthor('Агент #' . rand(1, 99));
        $book->setAllowDownload(true);
        $book->setReadingDate(new \DateTime);
    }

    /**
     * @Route("/books/{id}/remove_file_{type}", name="books_remove_file")
     * @param Request $request
     * @param $id
     * @param $type
     * @return JsonResponse|RedirectResponse
     */
    public function booksRemoveFile(Request $request, $id, $type)
    {
        if ($request->isXmlHttpRequest()) {
            $rootPath = $this->getParameter('kernel.project_dir') . '/public/';

            $repository = $this->getDoctrine()->getRepository(Book::class);
            $book = $repository->find($id);

            $em = $this->getDoctrine()->getManager();

            if ($book) {
                if ($type == "image") {
                    $path = $book->getCover();
                    if ($path)
                        if (file_exists($rootPath . $path))
                            unlink($rootPath . $path);

                    $book->setCover('');
                    $em->flush();
                }

                if ($type == "file") {

                    $path = $book->getFile();
                    if ($path)
                        if (file_exists($rootPath . $path))
                            unlink($rootPath . $path);
                    $book->setFile('');
                    $em->flush();
                }
                return new JsonResponse(true);
            }
        }
        return new JsonResponse(false);
    }

    /**
     * @Route("/books/cache_clear", name="books_list_cache_clear")
     * @param Request $request
     * @return Response
     */
    public function booksListCacheClear(Request $request)
    {
        if($request->isXmlHttpRequest()) {
            $cache = new FilesystemCache();
            $cache->clear();
            return new JsonResponse(true);
        }
    }

    /**
     * @Route("/books/remove_all_books", name="books_remove_all")
     * @param Request $request
     * @return JsonResponse|RedirectResponse
     */
    public function booksRemoveAll(Request $request)
    {
        if ($request->isXmlHttpRequest()) {
        $repository = $this->getDoctrine()->getRepository(Book::class);

            if ($books = $repository->findAll()) {
                $em = $this->getDoctrine()->getManager();
                foreach ($books as $book)
                    $em->remove($book);

                $em->flush();
                return new JsonResponse(true);
            } else {
                return new JsonResponse(false);
            }
        }
    }

    /**
     * @Route("/books/{id}/edit", name="books_edit_id")
     * @param Request $request
     * @param $id
     * @return RedirectResponse|Response
     */
    public function booksEdit(Request $request, $id)
    {
        $repository = $this->getDoctrine()->getRepository(Book::class);
        $book = $repository->find($id);

        $fileCurrent = $book->getFile();
        $coverCurrent = $book->getCover();

        $form = $this->createForm(BooksType::class, $book);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $this->uploadFile($form->get('file')->getData(), $book, 'file', $fileCurrent);
            $this->uploadFile($form->get('cover')->getData(), $book, 'image', $coverCurrent);

            $requestData = $form->getData();

            $em = $this->getDoctrine()->getManager();
            $em->persist($requestData);
            $em->flush();

            $this->addFlash('status_edit_book', $book->getId());

            return $this->redirectToRoute('books_edit_id', array('id' => $book->getId()));
        }

        $status = implode("", $this->get('session')->getFlashBag()->get('status_edit_book'));
        $this->get('session')->remove('status_edit_book');

        return $this->render('books/edit_book.html.twig', [
            'form' => $form->createView(),
            'status' => $status,
            'book' => $book,
        ]);
    }

    /**
     * @Route("/books/{id}/remove", name="books_remove_id")
     * @param Request $request
     * @param $id
     * @return JsonResponse|RedirectResponse
     */
    public function booksRemove(Request $request, $id)
    {
        if ($request->isXmlHttpRequest()) {
            $repository = $this->getDoctrine()->getRepository(Book::class);
            $book = $repository->find($id);

            $em = $this->getDoctrine()->getManager();

            if ($book) {
                $bookName = $book->getName();
                $em->remove($book);
                $em->flush();

                return new JsonResponse($bookName);
            }
        }

        return new JsonResponse(false);
    }

    /**
     * @Route("/books/add", name="books_add")
     * @param Request $request
     * @return RedirectResponse|Response
     */

    public function booksAdd(Request $request)
    {
        $book = new Book;

        $this->testInfo($book);

        $form = $this->createForm(BooksType::class, $book);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $this->uploadFile($form->get('file')->getData(), $book, 'file');
            $this->uploadFile($form->get('cover')->getData(), $book, 'image');

            $requestData = $form->getData();

            $em = $this->getDoctrine()->getManager();
            $em->persist($requestData);
            $em->flush();

            $this->addFlash('status_add_book', $book->getId());

            return $this->redirectToRoute('books_add');
        }

        $status = implode("", $this->get('session')->getFlashBag()->get('status_add_book'));
        $this->get('session')->remove('status_add_book');

        return $this->render('books/add_book.html.twig', array(
            'form' => $form->createView(),
            'status' => $status,
        ));
    }

    /**
     * @Route("/books", name="books_list")
     * @param Request $request
     * @return JsonResponse|Response
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function booksList(Request $request)
    {
        $cacheBooksList = $this->getParameter('cache_books_list');
        $cache = new FilesystemCache();
        $cacheTime = $this->getParameter('cache_time');

        if (!$cache->get($cacheBooksList)) {
            $repository = $this->getDoctrine()->getRepository(Book::class);
            $books = $repository->findBy([], ['reading_date' => 'DESC']);
            $cache->set($cacheBooksList, $books, $cacheTime);

        }
        $books = $cache->get($cacheBooksList);

        if ($request->isXmlHttpRequest() && isset($_REQUEST["refresh"])) {
            $cache->clear();
            return new JsonResponse(count($books));
        }


        return $this->render('books/view_list.html.twig', [
            'books' => $books,
        ]);
    }

    /**
     * @Route("/books/{id}", name="books_details")
     * @param $id
     * @return Response
     */
    public function booksDetails($id)
    {
        $repository = $this->getDoctrine()->getRepository(Book::class);
        $book = $repository->find($id);

        return $this->render('books/view_details.html.twig', [
            'book' => $book,
        ]);
    }
}
