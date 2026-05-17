# Интеграция asadbekrahimov/eimzo-integration в существующую Laravel CRM

Этот документ — практический рецепт, как «вкатить» E-IMZO-вход, подписание документов и подписание CRM-действий в существующее Laravel-приложение.

Архитектурные детали — в [GUIDE.md](GUIDE.md) и [ARCHITECTURE.md](ARCHITECTURE.md). Примеры login/документ/действие и рекомендации по хранению — в [EXAMPLES.md](EXAMPLES.md). Полный справочник переменных окружения — в [CONFIG.md](CONFIG.md).

## 1. Чек-лист предполётной проверки

Перед установкой пакета убедитесь в проекте CRM, что:

| Проверка | Команда | Ожидаемое |
|---|---|---|
| Версия PHP | `php -v` | `7.4.x` или новее |
| Версия Laravel | `php artisan --version` | Laravel 8, 9 или 10 |
| Расширение OpenSSL | `php -m` | в списке есть `openssl` |
| База данных | `php artisan tinker` | `Schema::hasTable('users')` равен `true` |
| Доступ на запись | права ОС | `storage/` и `bootstrap/cache/` доступны на запись |
| E-IMZO-SERVER | браузер/curl | `/frontend/challenge` или server URL возвращает challenge |

## 2. Установка пакета

Из Packagist:

```bash
composer require asadbekrahimov/eimzo-integration
```

Для локальной разработки до публикации добавьте path-репозиторий в CRM-приложение:

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

Затем:

```bash
composer update asadbekrahimov/eimzo-integration --with-all-dependencies
```

## 3. Регистрация сервис-провайдера

Laravel должен автообнаружить провайдер из `composer.json` пакета:

```json
"extra": {
  "laravel": {
    "providers": [
      "AsadbekRahimov\\EimzoIntegration\\Providers\\EimzoServiceProvider"
    ]
  }
}
```

Если автообнаружение отключено, зарегистрируйте вручную.

Laravel 8, 9 и 10:

```php
// config/app.php
'providers' => [
    AsadbekRahimov\EimzoIntegration\Providers\EimzoServiceProvider::class,
],
```

Скелеты в стиле Laravel 11+:

```php
// bootstrap/providers.php
return [
    AsadbekRahimov\EimzoIntegration\Providers\EimzoServiceProvider::class,
];
```

## 4. Публикация файлов пакета

```bash
php artisan vendor:publish --tag=eimzo-config
php artisan vendor:publish --tag=eimzo-migrations
php artisan vendor:publish --tag=eimzo-assets
php artisan migrate
```

Опционально:

```bash
php artisan vendor:publish --tag=eimzo-views
```

Публикация ассетов создаёт:

```text
public/vendor/eimzo/vendor/e-imzo.js
public/vendor/eimzo/vendor/e-imzo-client.js
public/vendor/eimzo/eimzo.js
```

Это важно для серверов nginx/apache, которые отдают `/vendor/eimzo/*` как статику до того, как сработают маршруты Laravel.

## 5. Окружение

```env
APP_URL=http://eimzo.test

# Java E-IMZO-SERVER (только server-to-server; браузер сюда не ходит).
EIMZO_SERVER_URL=http://185.xxx.xxx.123:8080
EIMZO_SERVER_TIMEOUT=20
EIMZO_SERVER_CONNECT_TIMEOUT=3
EIMZO_REQUEST_HOST=

# Опциональный same-origin прокси для браузер-видимых эндпоинтов /frontend/*.
# Оставьте пустым, если прокси вам реально не нужен (см. § 5.2 ниже и CONFIG.md).
EIMZO_FRONTEND_URL=

# Доменные API-ключи, выданные UZ PKI Technical Centre (https://pki.gov.uz).
# Рекомендуемая форма-карта:
EIMZO_API_KEYS="localhost=96D0C1...;127.0.0.1=A7BCFA5D...;eimzo.test=YOUR_DOMAIN_KEY"
```

### 5.1 API-ключи — предпочтительные формы

Форма-карта выше — самая простая. Также принимаются две альтернативы:

**Переменные на каждый хост** — удобно, когда ключ каждого домена живёт отдельной записью в secret-менеджере:

```env
EIMZO_API_KEY_LOCALHOST=96D0C1...
EIMZO_API_KEY_127_0_0_1=A7BCFA5D...
EIMZO_API_KEY_EIMZO_TEST=YOUR_DOMAIN_KEY
```

**Inline-ассоциативный массив** в опубликованном `config/eimzo.php`:

```php
'api_keys' => [
    'localhost'   => env('EIMZO_API_KEY_LOCALHOST', '96D0C1...'),
    '127.0.0.1'   => env('EIMZO_API_KEY_LOOPBACK',  'A7BCFA5D...'),
    'eimzo.test'  => env('EIMZO_API_KEY_PROD'),
],
```

Пакет автоматически фильтрует этот список до **той единственной записи, что совпадает с текущим хостом запроса**, прежде чем выставить его в `window.EIMZO_API_KEYS` — ключи других доменов в браузер не попадают. Полный справочник — в [CONFIG.md § 1](CONFIG.md#1-доменные-api-ключи).

Устаревшая форма с запятыми (`EIMZO_API_KEYS=localhost,KEY,127.0.0.1,KEY`) ещё распознаётся, но избегайте её: при нечётном числе элементов хвост «тихо» обрезается.

### 5.2 Нужен ли вам nginx-блок `/frontend`?

**Короткий ответ: большинству интеграций — нет.** В режиме по умолчанию пакет держит все вызовы Java на стороне сервера; браузер общается только с маршрутами Laravel. В таком сетапе можно оставить `EIMZO_FRONTEND_URL` пустым и ничего не добавлять в `nginx.conf`.

Прокси-блок **нужен**, когда:

- Мобильное ID-CARD-приложение должно POST’ить подписанный PKCS#7 на ваш домен по `/frontend/mobile/upload`, **и** этот публичный URL зарегистрирован в UZ PKI Technical Centre как target загрузки для вашего SiteID;
- Жёсткий CSP запрещает браузеру соединяться с другим origin, кроме самой страницы, поэтому кросс-origin-вызовы к Java-сервису должны выглядеть как same-origin.

Если применимо хоть что-то одно — добавьте этот блок в ваш `server { ... }` и установите `EIMZO_FRONTEND_URL=/frontend`:

```nginx
location /frontend {
    proxy_set_header   Host             $host;
    proxy_set_header   X-Real-IP        $remote_addr;
    proxy_set_header   X-Forwarded-For  $proxy_add_x_forwarded_for;
    proxy_pass http://127.0.0.1:8080;   # E-IMZO-SERVER на той же машине
}
```

`EIMZO_FRONTEND_URL=/frontend` заставляет браузер-видимые URL использовать same-origin-прокси. Серверная верификация по-прежнему ходит на `EIMZO_SERVER_URL` напрямую. Точные правила диспетчеризации — в [ARCHITECTURE.md § 3.1](ARCHITECTURE.md#31-eimzoserverclient--единственное-место-которое-говорит-с-java).

## 6. Стратегия поиска пользователя

Поток аутентификации извлекает идентичность подписанта из сертификата и находит пользователя по одной настраиваемой колонке:

```env
EIMZO_USER_MODEL=App\Models\User
EIMZO_USER_LOOKUP_COLUMN=tin
EIMZO_AUTO_REGISTER=false
EIMZO_AUTH_GUARD=web
EIMZO_REDIRECT_AFTER_LOGIN=/
```

Типичные стратегии:

- юр-лица как пользователи: `tin`;
- физ-лица как пользователи: `pinfl`;
- смешанные CRM-аккаунты: храните и `tin`, и `pinfl`, а колонку выбирайте под проект.

Миграция пакета может добавить эти опциональные колонки в `users`:

```text
tin
pinfl
eimzo_serial_number
eimzo_full_name
eimzo_authenticated_at
```

## 7. Поток входа (login)

Откройте демо:

```text
/eimzo/login
```

Поток:

1. `GET /eimzo/auth/challenge`
2. Браузер подписывает challenge через E-IMZO.
3. `POST /eimzo/auth/verify`
4. Бэкенд верифицирует через E-IMZO-SERVER.
5. Найденный пользователь логинится.

Затрагиваемые таблицы:

```text
eimzo_challenges
eimzo_certificates
eimzo_signatures
```

## 8. Подписание документа

Откройте:

```text
/eimzo/sign
```

Используйте для договоров, счетов, актов, XML, JSON и любых других бизнес-документов. Подписываемый документ может быть текстом или JSON — PDF не обязателен.

Важные колонки в `eimzo_signatures`:

```text
document_type
document_name
document_size
document_hash
pkcs7
pkcs7_with_timestamp
verification_status
verification_payload
certificate_id
user_id
```

Если подпись принадлежит существующей модели, храните `signature_id` в своей таблице или используйте `signable_type` и `signable_id`.

## 9. Подписание CRM-действий

Откройте:

```text
/eimzo/examples/action-sign
```

Для действий вроде «approve», «reject», «cancel», «publish», «confirm» подписывайте канонический JSON, сгенерированный бэкендом:

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

Рекомендуемая таблица в CRM:

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

Криптографическую «обёртку» хранит пакет в `eimzo_signatures`; ваша CRM хранит бизнес-смысл.

## 10. Чек-лист перед продакшеном

- Заведите реальную запись `EIMZO_API_KEYS` (или `EIMZO_API_KEY_<HOST>`) для каждого домена, на котором живут E-IMZO-страницы. Пакет теперь отдаёт в браузер только совпадающую пару, поэтому отсутствие записи «тихо» отключит подписание на этом домене.
- Опубликуйте E-IMZO-ассеты в `public/vendor/eimzo` (и убедитесь, что `vendor/e-imzo.js` **не пустой** — см. `tests/BrowserBridgeSourceTest.php`).
- Сделайте `EIMZO_SERVER_URL` достижимым из PHP. Браузеру **не нужно** ходить туда напрямую.
- Добавляйте nginx-прокси `/frontend` **только** при `EIMZO_FRONTEND_URL=/frontend` (мобильный upload или жёсткий CSP — см. § 5.2).
- После изменений `.env` запускайте `php artisan optimize:clear`.
- Сохраняйте и сравнивайте `document_hash` / `payload_hash` до того, как применяете бизнес-действие.
- Никогда не доверяйте PKCS#7, созданному в браузере, пока его не верифицировал бэкенд.
