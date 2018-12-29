<?php

namespace App\Command;

use App\API\Telegram\TelegramAPI;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class TelegramWebhookCommand extends Command
{
    protected static $defaultName = 'telegram_webhook';
    protected $tgBot;

    public function __construct(TelegramAPI $tgBot)
    {
        parent::__construct();
        $this->tgBot = $tgBot;
    }

    protected function configure()
    {
        $this
            ->setDescription('Управление Telegram Webhook')
            ->addArgument('args', InputArgument::OPTIONAL, 'Ссылка с https на сайт')
            ->addOption('set', null, InputOption::VALUE_NONE, 'Установить вебхук')
            ->addOption('del', null, InputOption::VALUE_NONE, 'Удалить вебхук')
            ->addOption('get', null, InputOption::VALUE_NONE, 'Инфомарция о вебхуке')
        ;
    }

    protected function getWebhookInfo()
    {
        $getWebhookInfo = $this->tgBot->getWebhookInfo();
        $getWebhookInfo = (array) $getWebhookInfo;

        if (!$getWebhookInfo['ok']) {
            return null;
        }

        $getWebhookInfo['result'] = (array) $getWebhookInfo['result'];

        return $getWebhookInfo;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $args = $input->getArgument('args');

        if ($input->getOption('set')) {
            if ($args) {
                $setWebhook = $this->tgBot->setWebHook($args);
                $setWebhook = (array) $setWebhook;

                foreach ($setWebhook as $key => $value) {
                    if (true === $value) {
                        $value = 'true';
                    } elseif (false === $value) {
                        $value = 'false';
                    }

                    $io->text("{$key}: {$value}");
                }

                $getWebhookInfo = $this->getWebhookInfo();

                if (!$getWebhookInfo) {
                    $io->error('Не удалось получить информацию о текущем вебхуке!');

                    return;
                }

                $io->success('Вебхук установлен! URL: '.$getWebhookInfo['result']['url']);
                $io->note('В конце добавляется токен, чтобы наверняка определить, что ответ прислал именно бот.');

                return;
            } else {
                $io->error('Аргументом должен быть URL с https!');

                return;
            }
        }

        if ($input->getOption('get')) {
            $getWebhookInfo = $this->getWebhookInfo();

            foreach ($getWebhookInfo['result'] as $key => $value) {
                if (true === $value) {
                    $value = 'true';
                } elseif (false === $value) {
                    $value = 'false';
                }

                $io->text("{$key}: {$value}");
            }

            if ($getWebhookInfo['result']['url']) {
                $io->success('Вебхук существует! URL: '.$getWebhookInfo['result']['url']);
            } else {
                $io->success('Вебхук еще не установлен!');
            }

            return;
        }

        if ($input->getOption('del')) {
            $getWebhookInfo = $this->getWebhookInfo();

            if (!$getWebhookInfo) {
                $io->error('Не удалось получить информацию о текущем вебхуке!');

                return;
            }

            $deleteWebhook = $this->tgBot->deleteWebhook();
            $deleteWebhook = (array) $deleteWebhook;

            foreach ($deleteWebhook as $key => $value) {
                if (true === $value) {
                    $value = 'true';
                } elseif (false === $value) {
                    $value = 'false';
                }

                $io->text("{$key}: {$value}");
            }

            if ($getWebhookInfo['result']['url']) {
                $io->success('Вебхук удален! URL: '.$getWebhookInfo['result']['url']);
            } else {
                $io->success('Вебхук еще не установлен!');
            }

            return;
        }

        $io->error('Ошибка ввода! Для получения дополнительной помощи пропишите --help');
    }
}
