<?php

namespace App\API\Telegram\Module;

use App\API\Bitrix24\Bitrix24API;
use App\API\Telegram\TelegramAPI;
use App\API\Telegram\TelegramDb;
use App\API\Telegram\TelegramRequest;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Translation\TranslatorInterface;

class Admin extends Module
{
    private $tgBot;
    private $tgDb;

    /**
     * @var TelegramRequest
     */
    private $tgRequest;
    private $translator;
    private $bitrix24;
    private $tgAdminList;
    private $cache;

    public function __construct(
        TelegramAPI $tgBot,
        TelegramDb $tgDb,
        Bitrix24API $bitrix24,
        TranslatorInterface $translator,
        $tgAdminList,
        CacheInterface $cache
    ) {
        $this->tgBot = $tgBot;
        $this->tgDb = $tgDb;
        $this->bitrix24 = $bitrix24;
        $this->translator = $translator;
        $tgAdminList = explode(', ', $tgAdminList);
        $this->tgAdminList = $tgAdminList;
        $this->cache = $cache;
    }

    public function request(TelegramRequest $request)
    {
        $this->tgRequest = $request;

        return $this->tgRequest;
    }

    public function translate($key, array $params = [])
    {
        return $this->translator->trans($key, $params, 'telegram', 'ru');
    }

    public function isAdmin()
    {
        $tgUser = $this->tgDb->getTgUser();
        if (array_search($tgUser->getBitrixId(), $this->tgAdminList) !== false) {
            return true;
        }

        return false;
    }

    public function adminList()
    {
        $text = null;
        foreach ($this->tgAdminList as $bitrixIdAdmin) {
            $bitrixUser = $this->bitrix24->getUsers(['id' => $bitrixIdAdmin, 'active' => true]);
            if ($bitrixUser) {
                $bitrixUser = $bitrixUser[0];
                $name = $bitrixUser->getName();
                $tgUser = $this->tgDb->getTgUsers(['bitrix_id' => $bitrixUser->getId()]);
                if ($tgUser) {
                    $tgUser = $tgUser[0];
                    $name = "[#name#](tg://user?id=#id#)";
                    $name = str_replace('#name#', $bitrixUser->getName(), $name);
                    $name = str_replace('#id#', $tgUser->getChatId(), $name);
                }

                $adminContact = array_filter([$bitrixUser->getFirstPhone(), $bitrixUser->getEmail()]);
                if ($adminContact) {
                    $adminContact = implode(', ', $adminContact);
                    $adminContact = "({$adminContact})";
                } else {
                    $adminContact = null;
                }

                $text .= $this->translate('admin.admin_list_user', ['%adminName%' => $name, '%adminContact%' => $adminContact]);
            }
        }

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('command.admin_list') . $text,
            'Markdown'
        );
    }

    public function cacheClear()
    {
        if (!$this->isAdmin()) {
            return false;
        }

        $this->cache->clear();

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('admin.cache_clear_success'),
            'Markdown'
        );

        return true;
    }

    public function commandList()
    {
        if (!$this->isAdmin()) {
            return false;
        }

        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            $this->translate('admin.commands'),
            'Markdown'
        );

        return true;
    }
}