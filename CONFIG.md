# Справочник по конфигурации

Этот документ — единственный источник истины по всем переменным окружения `EIMZO_*` и значениям `config/eimzo.php`, которые читает пакет. Объяснение, **почему** существуют те или иные настройки, см. в [ARCHITECTURE.md](ARCHITECTURE.md).

---

## 1. Доменные API-ключи

Desktop-клиент E-IMZO (`E-IMZO.exe` / `e-imzo` в Linux) отказывается выполнять любую криптографическую операцию, пока вызывающая страница не зарегистрировала себя с помощью API-ключа домена, выданного [UZ PKI Technical Centre](https://pki.gov.uz). Браузерный SDK делает это так:

```js
CAPIWS.apikey([ 'localhost', 'AAA...', 'crm.example.uz', 'BBB...' ], ...)
```

Этот пакет публикует список в браузер как `window.EIMZO_API_KEYS`, но **в страницу попадает только запись, совпадающая с текущим хостом** — см. [ARCHITECTURE.md §4](ARCHITECTURE.md#4-как-работает-поток-доменных-api-ключей).

### 1.1 Рекомендуемая форма карты (одна переменная)

```env
EIMZO_API_KEYS="localhost=96D0C1...;127.0.0.1=A7BCFA5D...;eimzo.test=YOUR_DOMAIN_KEY"
```

| Токен | Значение |
|---|---|
| `;` | Разделитель пар. Также работают `\n` и пробельные символы. |
| `=` | Разделитель домена и ключа внутри одной пары. |
| Домен | Точное `window.location.hostname` страницы (без схемы и порта). |
| Ключ | Hex-ключ, выданный UZ PKI Technical Centre. |

Это **рекомендуемая** форма — она избегает ловушки запятой-парами (нечётное число элементов = «тихая» порча) и читается естественно.

### 1.2 Переменные на каждый хост (лучше для secret-менеджеров)

```env
EIMZO_API_KEY_LOCALHOST=96D0C1...
EIMZO_API_KEY_127_0_0_1=A7BCFA5D...
EIMZO_API_KEY_EIMZO_TEST=YOUR_DOMAIN_KEY
```

Соглашения:

- Префикс `EIMZO_API_KEY_`.
- Любой символ хоста, не входящий в `[A-Z0-9]`, заменяется на `_`.
- Хост восстанавливается из суффикса (`_` → `.`). Для хостов, которые не восстанавливаются однозначно (например, `my-app.local`), задайте явное переопределение:

  ```env
  EIMZO_API_KEY_PROD=YOUR_KEY
  EIMZO_API_KEY_PROD_HOST=my-app.local
  ```

Переменные на каждый хост **переопределяют** одноимённый домен из `EIMZO_API_KEYS`, поэтому в `.env.example` можно держать дефолт для разработки, а production-ключ инжектировать через secret-менеджер / CI без переписывания файла.

### 1.3 Inline-ассоциативный массив (опубликованный конфиг)

После `php artisan vendor:publish --tag=eimzo-config`:

```php
// config/eimzo.php
'api_keys' => [
    'localhost'       => env('EIMZO_API_KEY_LOCALHOST', '96D0C1...'),
    '127.0.0.1'       => env('EIMZO_API_KEY_LOOPBACK',  'A7BCFA5D...'),
    'crm.example.uz'  => env('EIMZO_API_KEY_PROD',      ''),
],
```

Принимаются обе формы — **ассоциативная** (`['домен' => 'ключ']`) и **плоская парами** (`['домен', 'ключ', 'домен', 'ключ']`).

### 1.4 Устаревшая форма с запятыми

```env
EIMZO_API_KEYS="localhost,96D0C1...,127.0.0.1,A7BCFA5D..."
```

По-прежнему поддерживается ради обратной совместимости с upstream-демо qo0p, но форма-карта (§ 1.1) предпочтительнее.

### 1.5 Что в действительности видит браузер

Дано:

```env
EIMZO_API_KEYS="localhost=AAA;crm.example.uz=BBB;backoffice.example.uz=CCC"
```

Запрос на `https://crm.example.uz/eimzo/login` вернёт:

```html
<script>
  window.EIMZO_API_KEYS = ["crm.example.uz","BBB"];
</script>
```

Обратите внимание: `AAA` и `CCC` не попадают в этот ответ — фильтр по хосту их отрезает. Страница на `localhost` получит `["localhost","AAA"]` и так далее.

### 1.6 Wildcard-fallback

Если нужен один общий ключ разработки на любые внутренние имена хостов, зарегистрируйте `*` — реестр вернёт его для любого иначе неизвестного хоста:

```env
EIMZO_API_KEYS="*=DEV-WIDE-KEY"
```

Используйте умеренно — продакшен-домены всегда должны иметь явную запись.

---

## 2. Эндпоинты бэкенда (E-IMZO-SERVER)

```env
EIMZO_SERVER_URL=http://127.0.0.1:8080
EIMZO_FRONTEND_URL=
EIMZO_SERVER_TIMEOUT=20
EIMZO_SERVER_CONNECT_TIMEOUT=3
EIMZO_REQUEST_HOST=
```

| Переменная | Кто использует | Значение |
|---|---|---|
| `EIMZO_SERVER_URL` | PHP (server-to-server вызовы) | Базовый URL Java-сервиса `e-imzo-server.jar`. Должен быть достижим с вашего Laravel-сервера, **не** из браузера. |
| `EIMZO_FRONTEND_URL` | Frontend-группа Java endpoints (`/frontend/*`) | Обычно пусто: PHP вызывает `/frontend/*` прямо через `EIMZO_SERVER_URL`. Укажите `/frontend`, если PHP должен идти в Java через same-origin nginx/apache proxy. Укажите полный URL, если frontend-группа Java endpoints доступна отдельно, например `http://127.0.0.1:8080` или `http://proxy.local/frontend`. |
| `EIMZO_SERVER_TIMEOUT` | Guzzle | Таймаут запроса к бэкенду. |
| `EIMZO_SERVER_CONNECT_TIMEOUT` | Guzzle | Таймаут TCP-соединения. |
| `EIMZO_REQUEST_HOST` | `EimzoServerClient::request()` | Опциональное переопределение заголовка `Host:`, отсылаемого Java-сервису; полезно, когда E-IMZO-SERVER мультиплексирует несколько SiteID по `Host`. |

### 2.1 Нужен ли вам nginx-блок `/frontend`?

В демо qo0p используется такой фрагмент nginx:

```nginx
location /frontend {
    proxy_set_header   Host             $host;
    proxy_set_header   X-Real-IP        $remote_addr;
    proxy_set_header   X-Forwarded-For  $proxy_add_x_forwarded_for;
    proxy_pass http://127.0.0.1:8080;
}
```

**Для этого пакета он не нужен по умолчанию.** Блок требуется только если вы сознательно выводите Java `/frontend/*` наружу:

- Java upload URL для mobile ID-CARD должен быть публичным как `/frontend/mobile/upload`.
- Ваш собственный frontend напрямую вызывает `/frontend/challenge`, `/frontend/timestamp/*` или другие frontend endpoints E-IMZO-SERVER.
- PHP может достучаться до Java только через публичный proxy, а не напрямую по `EIMZO_SERVER_URL`.

Если вы держите все вызовы на стороне сервера (по умолчанию пакет так и работает — PHP сам обращается к `EIMZO_SERVER_URL`), блок прокси **не нужен**. Просто оставьте `EIMZO_FRONTEND_URL` пустым:

```env
EIMZO_SERVER_URL=http://127.0.0.1:8080
EIMZO_FRONTEND_URL=
```

Прокси **нужен**, если:

- Вы выставляете Java upload URL в публичный интернет на собственном домене (`/frontend/mobile/upload`), и UZ PKI Technical Centre регистрирует именно его как target загрузки для вашего SiteID. Если используете Laravel upload route этого пакета (`/eimzo/mobile/upload`), прокси обычно не нужен.
- Вы включаете любой браузерный хелпер, обращающийся напрямую к `/frontend/*` (например, когда CSP запрещает кросс-origin соединения).

При сомнении: режим по умолчанию (только server-to-server) не требует никаких изменений в nginx.

---

## 3. Аутентификация

```env
EIMZO_CHALLENGE_TTL=120
EIMZO_USER_MODEL=App\Models\User
EIMZO_USER_LOOKUP_COLUMN=tin
EIMZO_AUTO_REGISTER=false
EIMZO_AUTH_GUARD=web
EIMZO_REDIRECT_AFTER_LOGIN=/
```

| Переменная | Заметки |
|---|---|
| `EIMZO_CHALLENGE_TTL` | Максимальный TTL в секундах. Если E-IMZO-SERVER вернул меньший положительный `ttl`, пакет использует его; по умолчанию максимум равен 120. |
| `EIMZO_USER_MODEL` | Eloquent-модель, реализующая `Authenticatable`. |
| `EIMZO_USER_LOOKUP_COLUMN` | Предпочтительная колонка в таблице пользователей (`tin`, `inn`, `pinfl`, `eimzo_serial_number` и т. п.). `inn` сопоставляется с TIN сертификата, а `eimzo_serial_number` — с его serial. Если выбранное поле недоступно, сервис пробует колонки `pinfl` → `tin`/`inn` → `eimzo_serial_number`/`serial_number`. `uid` намеренно не используется неявно; включить его можно только явно. |
| `EIMZO_AUTO_REGISTER` | Если `true`, неизвестный подписант создаётся автоматически при первом входе. **Включайте, только если у вас есть процесс валидации автосозданного пользователя.** |
| `EIMZO_AUTH_GUARD` | Гард `auth`, на котором вызывается `login()`. |
| `EIMZO_REDIRECT_AFTER_LOGIN` | Возвращается JS-слою как `result.redirect`. |

---

## 4. Подписание

```env
EIMZO_ATTACH_TIMESTAMP=true
EIMZO_SIGN_MODE=attached
EIMZO_STORAGE_DISK=local
EIMZO_STORAGE_PATH=eimzo/signatures
```

`EIMZO_SIGN_MODE` — режим по умолчанию для `EimzoBridge.sign()` и серверного `EimzoSignService`, когда параметр `detached` не передан. Ставьте `detached`, если CRM хранит документ отдельно от подписи.

Когда `EIMZO_STORAGE_DISK` не пуст, desktop- и mobile-подписи дополнительно сохраняются как бинарные `.p7`-файлы под `EIMZO_STORAGE_PATH`. Чтобы оставить подписи только в таблице `eimzo_signatures`, оставьте диск пустым.

---

## 5. Mobile (ID-CARD через NFC)

```env
EIMZO_MOBILE_ENABLED=true
EIMZO_MOBILE_SITE_ID=ABCD
EIMZO_MOBILE_UPLOAD_URL=https://crm.example.uz/eimzo/mobile/upload
EIMZO_MOBILE_POLL_TIMEOUT=120
EIMZO_MOBILE_POLL_INTERVAL_MS=1500
```

`EIMZO_MOBILE_ENABLED=false` полностью убирает web/API mobile-маршруты и корневой upload-алиас. `EIMZO_MOBILE_SITE_ID` должен совпадать с SiteID, который UZ PKI Technical Centre зарегистрировал для вашего домена. Пакет сверяет его со значением E-IMZO-SERVER и останавливает старт flow при несовпадении. `EIMZO_MOBILE_UPLOAD_URL` — публичный URL, по которому ID-CARD-приложение POST’ит подписанный PKCS#7. Параметры polling возвращаются из `/mobile/*/start` и автоматически применяются `EimzoMobile`.

---

## 6. Маршрутизация

```env
EIMZO_ROUTES_ENABLED=true
EIMZO_ROUTE_PREFIX=eimzo
EIMZO_API_PREFIX=api/eimzo
EIMZO_ASSET_ROUTES_ENABLED=true
EIMZO_ASSET_CACHE_SECONDS=3600
EIMZO_LOCAL_PARSE=true
```

| Переменная | Заметки |
|---|---|
| `EIMZO_ROUTES_ENABLED` | Поставьте `false`, если регистрируете маршруты сами. |
| `EIMZO_ROUTE_PREFIX` | Префикс браузер-видимых маршрутов (по умолчанию `eimzo`). Встроенные страницы передают фактические named-route URL в JS, поэтому нестандартный префикс не требует ручной настройки `EimzoBridge`. |
| `EIMZO_API_PREFIX` | Префикс stateless-маршрутов `api/*`. |
| `EIMZO_ASSET_ROUTES_ENABLED` | Отдаёт встроенный JS из `/vendor/eimzo/...`. Отключите после `php artisan vendor:publish --tag=eimzo-assets`, чтобы nginx обслуживал их как статические файлы. |
| `EIMZO_ASSET_CACHE_SECONDS` | `Cache-Control: max-age` для отдаваемого JS. |
| `EIMZO_LOCAL_PARSE` | Локальное извлечение подписанта из PKCS#7 через `openssl_pkcs7_read`. При `false` сертификат всё равно сохраняется из доверенного payload E-IMZO-SERVER; отключается только локальный разбор. |

---

## 7. Таблицы

```php
'tables' => [
    'challenges'   => 'eimzo_challenges',
    'certificates' => 'eimzo_certificates',
    'signatures'   => 'eimzo_signatures',
],
```

Переопределяйте, только если иначе будет коллизия имён в вашей схеме.
