# asadbekrahimov/eimzo-integration

Laravel-пакет для аутентификации через E-IMZO (Узбекистан), подписания документов в формате PKCS#7, подписания CRM-действий, верификации, временных меток (TSA) и набора готовых демо-страниц.

В пакет входит:

- Браузерные мост-скрипты E-IMZO (CAPIWS + EIMZOClient + EimzoBridge).
- Маршруты Laravel и демо-страницы.
- Серверные сервисы для общения с E-IMZO-SERVER.
- Модели и миграции для challenge’ов, сертификатов и подписей.
- Готовые примеры: вход по подписи, подписание документа, подписание CRM-действия.

## Требования

- PHP `^7.4|^8.0`
- Laravel `^8.0|^9.0|^10.0`
- E-IMZO desktop-клиент на ПК пользователя
- Java-сервис E-IMZO-SERVER, доступный вашему Laravel-серверу

`^8.0` включает PHP 8.1, 8.2, 8.3 и 8.4.

## Установка

```bash
composer require asadbekrahimov/eimzo-integration
```

Для локальной разработки до публикации в Packagist:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../asadbekrahimov-eimzo-integration"
    }
  ],
  "require": {
    "asadbekrahimov/eimzo-integration": "*"
  }
}
```

Опубликовать конфиг, миграции, представления и браузерные ассеты:

```bash
php artisan vendor:publish --tag=eimzo-config
php artisan vendor:publish --tag=eimzo-migrations
php artisan vendor:publish --tag=eimzo-assets
php artisan migrate
```

Публикация представлений необязательна:

```bash
php artisan vendor:publish --tag=eimzo-views
```

## Переменные окружения

```env
EIMZO_SERVER_URL=http://185.xxx.xxx.123:8080
EIMZO_FRONTEND_URL=
EIMZO_SERVER_TIMEOUT=20
EIMZO_SERVER_CONNECT_TIMEOUT=3
EIMZO_REQUEST_HOST=

EIMZO_API_KEYS="localhost=96D0C1...;127.0.0.1=A7BCFA5D...;eimzo.test=YOUR_DOMAIN_KEY"

EIMZO_CHALLENGE_TTL=120
EIMZO_USER_MODEL=App\Models\User
EIMZO_USER_LOOKUP_COLUMN=tin
EIMZO_AUTO_REGISTER=false
EIMZO_AUTH_GUARD=web
EIMZO_REDIRECT_AFTER_LOGIN=/

EIMZO_ATTACH_TIMESTAMP=true
EIMZO_SIGN_MODE=attached
EIMZO_STORAGE_DISK=local
EIMZO_STORAGE_PATH=eimzo/signatures

EIMZO_ROUTES_ENABLED=true
EIMZO_ROUTE_PREFIX=eimzo
EIMZO_API_PREFIX=api/eimzo
EIMZO_ASSET_ROUTES_ENABLED=true
EIMZO_ASSET_CACHE_SECONDS=3600
EIMZO_LOCAL_PARSE=true
```

**Доменные API-ключи** выдаёт UZ PKI Technical Centre — демо-значения в `config/eimzo.php` действуют только для `localhost` / `127.0.0.1`. Рекомендуемая форма записи — карта `домен=ключ;домен=ключ`; также принимаются переменные на каждый хост `EIMZO_API_KEY_<HOST>` и устаревшая запятая-парами. Пакет автоматически отдаёт в браузер только ту запись, которая соответствует текущему хосту запроса. Полная справка — в [CONFIG.md](CONFIG.md). Прокси `/frontend` — опционально, см. [INTEGRATION.md § 5.2](INTEGRATION.md). Внутреннее устройство (CAPIWS, EIMZOClient, E-IMZO-SERVER) — в [ARCHITECTURE.md](ARCHITECTURE.md).

Если ваш nginx/OpenServer всё-таки проксирует Java-сервис через `/frontend`, используйте:

```env
EIMZO_SERVER_URL=http://185.xxx.xxx.123:8080
EIMZO_FRONTEND_URL=/frontend
```

Для обычного использования пакета этот nginx-блок не нужен: браузер ходит в Laravel-маршруты `/eimzo/*`, а Laravel сам обращается к Java E-IMZO-SERVER.

## Маршруты

Web-маршруты:

- `GET /eimzo`
- `GET /eimzo/login`
- `GET /eimzo/sign`
- `GET /eimzo/verify`
- `GET /eimzo/examples`
- `GET /eimzo/auth/challenge`
- `POST /eimzo/auth/verify`
- `POST /eimzo/sign`
- `POST /eimzo/verify`

API-маршруты по умолчанию монтируются под `/api/eimzo`.

Браузерные ассеты обслуживаются по адресам:

- `/vendor/eimzo/vendor/e-imzo.js`
- `/vendor/eimzo/vendor/e-imzo-client.js`
- `/vendor/eimzo/eimzo.js`

Для production-подобных локальных серверов опубликуйте ассеты, чтобы nginx/apache мог отдавать их как статический JavaScript.

## Примеры

Откройте:

```text
/eimzo/examples
```

Включённые примеры:

- Вход по подписанному challenge.
- Подписание документа.
- Подписание CRM-действия с каноническим JSON.

Подробности по хранению в БД и потокам данных — в [EXAMPLES.md](EXAMPLES.md).

Пошаговую интеграцию в существующую CRM см. в [INTEGRATION.md](INTEGRATION.md).

Полный справочник возможностей (desktop + mobile API) — в [USAGE.md](USAGE.md).

## Подписание CRM-действий

Для CRM-действий подписываемый документ должен быть каноническим JSON, который сгенерировал бэкенд, например:

```json
{
  "action": "approve_invoice",
  "amount": 1500000,
  "currency": "UZS",
  "entity_id": 1024,
  "entity_type": "invoice",
  "issued_at": "2026-05-03T19:20:00+05:00",
  "nonce": "example-nonce-123"
}
```

Бизнес-смысл храните отдельно и связывайте с `eimzo_signatures.id`:

```text
signed_actions
- user_id
- action
- entity_type
- entity_id
- payload_json
- payload_hash
- signature_id
- certificate_id
- signed_at
- ip
- user_agent
```

## Тестирование

```bash
composer install
composer test
```
