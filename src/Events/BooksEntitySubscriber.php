<?php

namespace App\Events;

use App\Entity\Book;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;

use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;


class BooksEntitySubscriber implements EventSubscriber
{
    private $entity;
    private $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function getSubscribedEvents()
    {
        /**
         * Список всех событий:
         * https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/events.html
         */
        return array(
            'onFlush',
        );
    }

    /**
     * @param OnFlushEventArgs $args
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function onFlush(OnFlushEventArgs $args)
    {

        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $this->entity) {
        }

        foreach ($uow->getScheduledEntityUpdates() as $this->entity) {
        }

        foreach ($uow->getScheduledEntityDeletions() as $this->entity) {
            $book = $this->entity;

            if (!empty($path = $book->getCover()))
                $path = "." . $path;
                if (file_exists($path) && !is_dir($path))
                    unlink($path);

            if (!empty($path = $book->getFile()))
                $path = "." . $path;
                if (file_exists($path) && !is_dir($path))
                    unlink($path);
        }

        if ($this->entity instanceof Book) {
            $cache = new FilesystemCache();
            $cacheBooksList = $this->container->getParameter('cache_books_list');
            if ($cache->get($cacheBooksList))
                $cache->delete($cacheBooksList);
        }
    }
}