<?php

namespace App\Command;

use App\Entity\Verification;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class DatabaseClearOldDataCommand extends Command
{
    // Скорее, я сюда повешу крон. Но это неточно
    protected $doctrine;
    public function __construct(Container $container, ?string $name = null)
    {
        parent::__construct($name);
        $this->doctrine = $container->get('doctrine');
    }

    protected static $defaultName = 'app:database-clear-old-data';

    protected function configure()
    {
        $this
            ->setDescription('Add a short description for your command')
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $repository = $this->doctrine->getRepository(Verification::class);
        $hash = $repository->findBy([]);

        $output->writeln(print_r($hash, true));

        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');
    }
}
