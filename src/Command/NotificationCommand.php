<?php

namespace App\Command;

use App\Service\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class NotificationCommand extends Command
{
    protected static $defaultName = 'cron_notification';
    protected $router;

    public function __construct(UrlGeneratorInterface $router)
    {
        parent::__construct();

        $this->router = $router;
    }

    protected function configure()
    {
        $this
            ->setDescription('Проверка на уведомления')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $url = 'https://';
        $url .= $this->getApplication()->getKernel()->getContainer()->getParameter('base_url');
        $url .= $this->router->generate('telegram', ['cron' => 'notification']);

        Helper::curl($url);

        $io->success('Проверка на уведомления завершена');
    }
}
