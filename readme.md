Телеграм-бот для бронирования переговорок
======

- Общие сведения
  - [Особенности](#Особенности)
  - [Возможности](#Возможности)
- Установка
  - [Рекомендуемые требования](#Рекомендуемые-требования)
  - [Конфигурация](#Конфигурация)
  - [Telegram Webhook](#Telegram-Webhook)
  - [Bitrix24 Webhook](#Bitrix24-Webhook)
  - [Google API](#Google-API)

- Каркасы для работы с API
  - [\Service\TelegramAPI](#\Service\TelegramAPI)
  - [\Service\GoogleCalendarAPI](#\Service\GoogleCalendarAPI)
  - [\Service\Bitrix24API](#\Service\Bitrix24API)

Общие сведения
======

Особенности
------

- **Google Calendar API v3** и **Google Service Account** для работы с событиями. 
- **Bitrix24 Webhook** для получения данных о сотрудинках.
- **Telegram API** для работы с ботом.
- **Собственные каркасы** для работы с API

Возможности
------

- Регистрация
- Бронирование переговорок
- Управление событиями (добавить / изменить / удалить)
- Поиск участников
- Удаление персональных данных

Подготовка к установке
======

Рекомендуемые требования
------

- php 7.1
- postgresql 9.3
- Symfony 4

Конфигурация
------

**.env**
- Доступ к БД
- Данные для установки вебхука Bitrix24
- Токен для телеграм-бота
- Данные прокси для работы с телеграм-ботом
- Секретный файл json для работы сервис аккаунта Google

**config/services.yaml**
- Диапазон начала и конца рабочего дня
- На сколько дней вперед можно бронировать
- За сколько минут до начала события оповещать участинков
- Время кеширование данных Google и Bitrix
- Анти-флуд: сколько сообщений в минуту можно отправлять одному пользователю

Установка
------

```bash
php7.1 composer.phar install
yarn install
yarn encore dev
php7.1 bin/console doctrine:migrations:diff
php7.1 bin/console doctrine:migrations:migrate
```

Telegram Webhook
------

Сайт обязательно должен иметь ssl-сертификат. Для самоподписанных сертификатов необходимо задать дополнительные
параметры в методе setWebHook.
```php
<?php
// src/Controller/TelegramController.php
...

public function tgWebhook(Request $request)
{
    $this->tgBot->setWebHook("https://example.com");
    $this->tgBot->getWebhookInfo();
    $this->tgBot->deleteWebhook();
}
...
```

Bitrix24 Webhook
------

Получить доступ к вебхуку может любой пользователь, который имеет права на просмотр данных сотрудников.

Google API
------

Создаем сервис-аккаунт, скачиваем json и добавляем в конфиг. Для того, чтобы сервис-аккаунт видел календари, необходимо
добавить его почту и выдать необходимые права (минимум на изменение событий).

Каркасы для работы с API
======

\Service\TelegramAPI
------

```php
<?php
// src/Controller/TelegramController.php
...
public function tgWebhook(Request $request)
{
    if ($this->isTg && $this->tgRequest->getRequestType()) {
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            "*Привет*, _как дела_?",
            "Markdown"
        );
        
        $this->tgBot->editMessageText(
            "*Привет*, _как дела_? Отлично?",
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            "Markdown"
        );
        
        // Отправить клавиатуру
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            "Вот вам клавиатура!",
            null,
            false,
            false,
            null,
            $this->tgBot->replyKeyboardMarkup($keyboard)
        );
    }
}
...
```

\Service\GoogleCalendarAPI
------

```php
<?php
// src/Controller/TelegramController.php
...
public function tgWebhook(Request $request)
{
    // @param array|null $filter
    $filter = ["calendarName" => "Название календаря"];
    $this->googleCalendar->getList($filter);
    
    // Добавить событие
    $this->googleCalendar->addEvent(
        $calendarId,
        $meetingRoomEventName,
        $textMembers,
        $meetingRoomDateTimeStart,
        $meetingRoomDateTimeEnd,
        $attendees
    );
    
    // Изменить событие
    $this->googleCalendar->editEvent(
        $calendarId,
        $event["eventId"],
        $meetingRoomEventName,
        $textMembers,
        $meetingRoomDateTimeStart,
        $meetingRoomDateTimeEnd,
        $attendees
    );
    
    // Удалить событие
    $this->googleCalendar->removeEvent(
        $event["calendarId"], 
        $event["eventId"]
    );
}
...
```

\Service\Bitrix24API
------

```php
<?php
// src/Controller/TelegramController.php
...

public function tgWebhook(Request $request)
{
    // @param array|string|null $filter
    $filter = ["id" => 1];
    $filter = ["name" => ["Иван Иванов", "Иван", "Иванов"]];
    $this->bitrix24->getUsers($filter);
}
...
```