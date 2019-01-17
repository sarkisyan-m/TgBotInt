Телеграм-бот для бронирования переговорок
======

- Общие сведения
  - [Особенности](#Особенности)
  - [Возможности](#Возможности)
- Подготовка к установке
  - [Рекомендуемые требования](#Рекомендуемые-требования)
  - [Конфигурация](#Конфигурация)
  - [Установка](#Установка)
  - [Тесты](#Тесты)
  - [Telegram Webhook](#Telegram-Webhook)
  - [Bitrix24 Webhook](#Bitrix24-Webhook)
  - [Google Calendar API](#Google-Calendar-API)

- Библиотеки для работы с API
  - [TelegramAPI](#TelegramAPI)
  - [GoogleCalendarAPI](#GoogleCalendarAPI)
  - [Bitrix24API](#Bitrix24API)

Общие сведения
======

Особенности
------

- **Google Calendar API v3** и **Google Service Account** для работы с событиями. 
- **Bitrix24 Webhook** для получения данных о сотрудинках.
- **Telegram API** для работы с ботом.
- **Собственные библиотеки** для работы с API

Возможности
------

- Регистрация через данные из Bitrix24, если активен аккаунт
- Бронирование переговорок
- Управление событиями Google calendar (добавить / изменить / удалить)
- Поиск участников в Bitrix24
- Административное меню
- Управление персональными данными

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
- Список админов по BitrixID
- Данные для установки вебхука Telegram
- Секретный файл json для работы сервис-аккаунта Google
- Данные прокси для работы с телеграм-ботом

**config/services.yaml**
- Диапазон начала и конца рабочего дня
- На сколько дней вперед можно бронировать
- За сколько минут до начала события оповещать участников, если почта от Google
- Время кеширование данных Google и Bitrix24
- Анти-флуд: сколько сообщений в минуту можно отправлять одному пользователю
- Добавление и сортировка переговорок (1 переговорка - 1 календарь в Google)
- Возможность автоматически добавлять переговорки
- Фиксация размеров входных данных от пользователей

Установка
------

```bash
php7.1 composer.phar install
yarn install
yarn encore dev
php7.1 bin/console doctrine:migrations:diff
php7.1 bin/console doctrine:migrations:migrate
```

Тесты
------
```bash
php7.1 bin/phpunit tests/
```

Telegram Webhook
------

Вебхук для телеграма можно установить несколькими способами.

    Рекомендуется использовать url вместе с токеном для дополнителньой безопасности:
    https://example.com/?<token>
    
    Стоит учитывать, что через опцию set или через контроллер токен сам пропишется.
    В результате получим: https://example.com/?<token>
    
- Через консоль с помощью готовых команд
```bash
php7.1 bin/console telegram_webhook --set https://example.com
php7.1 bin/console telegram_webhook --get
php7.1 bin/console telegram_webhook --del
```

- Через контроллер сайт обязательно должен иметь ssl-сертификат. Для самоподписанных сертификатов необходимо задать дополнительные
параметры в методе setWebHook.
```php
<?php
// src/Controller/TelegramController.php
...
public function tgWebhook(Request $request)
{
    $this->tgBot->setWebHook('https://example.com');
    $this->tgBot->getWebhookInfo();
    $this->tgBot->deleteWebhook();
}
...
```

- Через адресную строку вручную сформировать запрос
- Через curl в консоле

Bitrix24 Webhook
------

Получить доступ к вебхуку может любой пользователь, который имеет права на просмотр данных сотрудников.

Google Calendar API
------

Создаем сервис-аккаунт, скачиваем json и добавляем в конфиг. Для того, чтобы сервис-аккаунт видел календари, необходимо
добавить его почту и выдать необходимые права (минимум на изменение событий).

Библиотеки для работы с API
======

TelegramAPI
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
            '*Привет*, _как дела_?',
            'Markdown'
        );
        
        $this->tgBot->editMessageText(
            '*Привет*, _как дела_? Отлично?',
            $this->tgRequest->getChatId(),
            $this->tgRequest->getMessageId(),
            null,
            'Markdown'
        );
        
        // Отправить клавиатуру
        $this->tgBot->sendMessage(
            $this->tgRequest->getChatId(),
            'Вот вам клавиатура!',
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

GoogleCalendarAPI
------

```php
<?php
// src/Controller/TelegramController.php
...
public function tgWebhook(Request $request)
{
    $filter = ['calendarName' => 'Название календаря'];
    $this->googleCalendar->getList($filter);
    
    // Добавить событие
    $this->googleCalendar->addEvent(
        $calendarId,
        $eventName,
        $eventDescription,
        $eventTimeStart,
        $eventTimeEnd,
        $eventAttendees
    );
    
    // Изменить событие
    $this->googleCalendar->editEvent(
        $calendarId,
        $eventId,
        $eventName,
        $evnetDescription,
        $eventTimeStart,
        $eventTimeEnd,
        $eventAttendees
    );
    
    // Удалить событие
    $this->googleCalendar->removeEvent(
        $calendarId, 
        $eventId
    );
}
...
```

Bitrix24API
------

```php
<?php
// src/Controller/TelegramController.php
...

public function tgWebhook(Request $request)
{
    // Список фильтров можно посмотреть в методе GoogleCalendarAPI - getFilters()
    $filter = ['id' => 1];
    $filter = ['name' => ['Иван Иванов', 'Иван', 'Иванов']];
    $filter = ['phone' => '+71231231231'];
    $this->bitrix24->getUsers($filter);
}
...
```