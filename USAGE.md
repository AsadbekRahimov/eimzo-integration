# USAGE — asadbekrahimov/eimzo-integration

Подробное руководство по использованию пакета: установка, конфигурация, поток
авторизации/подписи через десктопный E-IMZO, мобильный API (ID-CARD), а также
интеграция в CRM.

> Документация Е-ИМЗО, на которую опирается пакет: <https://github.com/qo0p/e-imzo-doc>

---

## Оглавление

1. [Архитектура](#архитектура)
2. [Установка и конфигурация](#установка-и-конфигурация)
3. [Маршруты пакета](#маршруты-пакета)
4. [Десктопный E-IMZO: авторизация по сертификату](#десктопный-e-imzo-авторизация-по-сертификату)
5. [Десктопный E-IMZO: подпись документа](#десктопный-e-imzo-подпись-документа)
6. [Подпись CRM-действия (canonical JSON)](#подпись-crm-действия-canonical-json)
7. [Верификация PKCS#7](#верификация-pkcs7)
8. [TSA-таймстампы и операции с PKCS#7](#tsa-таймстампы-и-операции-с-pkcs7)
9. [Мобильный API (ID-CARD)](#мобильный-api-id-card)
10. [Использование сервисов из PHP-кода](#использование-сервисов-из-php-кода)
11. [Модели и таблицы БД](#модели-и-таблицы-бд)
12. [События ошибок и коды статусов](#события-ошибок-и-коды-статусов)
13. [Безопасность](#безопасность)
14. [Тестирование](#тестирование)

---

## Архитектура

```
┌─────────────────┐  ws://127.0.0.1:64443  ┌──────────────────┐
│ Браузер +       │ ─────────────────────▶ │ E-IMZO Desktop   │
│ eimzo.js bridge │                        │ (CAPIWS plugin)  │
└────┬────────────┘                        └──────────────────┘
     │ /eimzo/* (JSON)
     ▼
┌─────────────────┐  text/plain                    ┌──────────────────┐
│ Laravel-пакет   │ ──────────────────────────────▶│ E-IMZO-SERVER    │
│ AsadbekRahimov/ │     /backend/*  /frontend/*    │ (Java + VPN)     │
│ EimzoIntegration│                                │                  │
└─────────────────┘                                └──────────────────┘
                                                            │
                                                            │ vpn.e-imzo.uz
                                                            ▼
                                                    ┌──────────────────┐
                                                    │ УЦ (PKI ГЦР РУз) │
                                                    └──────────────────┘
```

- **Браузер** общается с десктопным E-IMZO через `CAPIWS` (WebSocket
  `wss://127.0.0.1:64443`). Пакет подключает готовые `vendor/e-imzo.js` +
  `vendor/e-imzo-client.js` и оборачивает их в Promise-API (`EimzoBridge`).
- **Laravel** ничего не подписывает сам. Он только: (а) выдаёт challenge,
  (б) проксирует PKCS#7 в E-IMZO-SERVER, (в) хранит результаты в БД.
- **E-IMZO-SERVER** (Java) — единственный компонент с VPN-доступом до УЦ.
  Отвечает за валидацию цепочки, OCSP, TSA-таймстампы, мобильный API.
- **Мобильный API** (`/frontend/mobile/*`, `/backend/mobile/*`) использует
  Redis в E-IMZO-SERVER для отслеживания статуса операций между
  ID-CARD-приложением и веб-сайтом клиента.

---

## Установка и конфигурация

### 1. Composer

```bash
composer require asadbekrahimov/eimzo-integration
php artisan vendor:publish --tag=eimzo-config
php artisan vendor:publish --tag=eimzo-migrations
php artisan vendor:publish --tag=eimzo-assets
php artisan migrate
```

### 2. .env

```env
# Java E-IMZO-SERVER
EIMZO_SERVER_URL=http://127.0.0.1:8080
# Если nginx проксирует /frontend на тот же сервер — оставьте /frontend
EIMZO_FRONTEND_URL=/frontend
EIMZO_SERVER_TIMEOUT=20
EIMZO_SERVER_CONNECT_TIMEOUT=3
# Заголовок Host, который видит Java-сервис. Пусто = берётся из request().
EIMZO_REQUEST_HOST=

# Per-domain API keys (выдаёт ГУП ЦГИБ)
# Формат: domain1,KEY1,domain2,KEY2,...
EIMZO_API_KEYS=localhost,LOCALHOST_KEY,my-crm.uz,DOMAIN_KEY

# Авторизация
EIMZO_CHALLENGE_TTL=120
EIMZO_USER_MODEL=App\Models\User
EIMZO_USER_LOOKUP_COLUMN=tin
EIMZO_AUTO_REGISTER=false
EIMZO_AUTH_GUARD=web
EIMZO_REDIRECT_AFTER_LOGIN=/

# Подпись
EIMZO_ATTACH_TIMESTAMP=true
EIMZO_SIGN_MODE=attached
EIMZO_STORAGE_DISK=local
EIMZO_STORAGE_PATH=eimzo/signatures

# Мобильный API
EIMZO_MOBILE_ENABLED=true
EIMZO_MOBILE_SITE_ID=0001
EIMZO_MOBILE_UPLOAD_URL=https://my-crm.uz/eimzo/mobile/upload
EIMZO_MOBILE_POLL_TIMEOUT=120
EIMZO_MOBILE_POLL_INTERVAL_MS=1500

# Маршруты
EIMZO_ROUTES_ENABLED=true
EIMZO_ROUTE_PREFIX=eimzo
EIMZO_API_PREFIX=api/eimzo
EIMZO_ASSET_ROUTES_ENABLED=true
EIMZO_LOCAL_PARSE=true
```

### 3. Колонки в `users`

Опциональные, но полезны для автоматического апдейта при логине:

```php
$table->string('tin')->nullable()->index();
$table->string('pinfl')->nullable()->index();
$table->string('eimzo_serial_number')->nullable();
$table->string('eimzo_full_name')->nullable();
$table->timestamp('eimzo_authenticated_at')->nullable();
```

См. готовую миграцию `database/migrations/2026_05_02_000004_add_eimzo_columns_to_users_table.php`.

### 4. CSRF / VerifyCsrfToken

Mobile-upload вызывается ID-CARD-приложением, которое не знает про CSRF.
В `App\Http\Middleware\VerifyCsrfToken` добавьте:

```php
protected $except = [
    'eimzo/mobile/upload',
    'frontend/mobile/upload',
];
```

API-маршруты (`/api/eimzo/*`) уже идут через guard `api` без CSRF.

### 5. Конфиг E-IMZO-SERVER (`config.properties`)

Минимум для мобильного API:

```properties
listen.address=0.0.0.0
listen.port=8080
vpn.host=vpn.e-imzo.uz
vpn.port=3443
vpn.key=/etc/eimzo/vpn.pfx
vpn.password=YOUR_VPN_PASSWORD

mobile.siteId=0001
mobile.storage.redis.host=127.0.0.1
mobile.storage.redis.password=secret
mobile.storage.redis.db=1
```

`mobile.siteId` **обязан совпадать** с `EIMZO_MOBILE_SITE_ID`. Иначе мобильное
приложение не сможет загрузить PKCS#7 обратно.

---

## Маршруты пакета

| Метод   | URL                                  | Назначение |
|---------|--------------------------------------|------------|
| GET     | `/eimzo`                             | Демо-страница |
| GET     | `/eimzo/login`                       | Демо-логин |
| GET     | `/eimzo/sign`                        | Демо-подпись |
| GET     | `/eimzo/verify`                      | Демо-верификация |
| GET     | `/eimzo/examples`                    | Список примеров |
| GET     | `/eimzo/auth/challenge`              | Выдать challenge для логина |
| POST    | `/eimzo/auth/verify`                 | Проверить подписанный challenge, залогинить |
| POST    | `/eimzo/auth/logout`                 | Logout |
| POST    | `/eimzo/sign`                        | Сохранить и проверить PKCS#7 |
| GET     | `/eimzo/signatures/{id}`             | Получить подпись |
| POST    | `/eimzo/verify`                      | Stateless-верификация PKCS#7 |
| POST    | `/eimzo/timestamp/pkcs7`             | TSA-таймстамп для PKCS#7 |
| POST    | `/eimzo/timestamp/data`              | TSA-таймстамп для произвольных данных |
| POST    | `/eimzo/pkcs7/make-attached`         | Detached → Attached |
| POST    | `/eimzo/pkcs7/join`                  | Объединить две подписи |
| POST    | `/eimzo/mobile/auth/start`           | Начать мобильный логин |
| POST    | `/eimzo/mobile/auth/status`          | Опросить статус |
| POST    | `/eimzo/mobile/auth/complete`        | Завершить мобильный логин |
| POST    | `/eimzo/mobile/sign/start`           | Начать мобильную подпись |
| POST    | `/eimzo/mobile/sign/status`          | Опросить статус |
| POST    | `/eimzo/mobile/sign/complete`        | Завершить мобильную подпись |
| POST    | `/eimzo/mobile/upload`               | UPLOAD URL для ID-CARD |
| GET/POST| `/eimzo/frontend/mobile/upload`      | Алиас для совместимости |
| GET/POST| `/frontend/mobile/upload`            | Корневой алиас (путь из эталонного демо qo0p) |

Те же эндпоинты доступны под `/api/eimzo/*` (guard `api`, без CSRF, без сессий).

---

## Десктопный E-IMZO: авторизация по сертификату

### Подключение скриптов

```html
<meta name="csrf-token" content="{{ csrf_token() }}">

<script src="/vendor/eimzo/vendor/e-imzo.js"></script>
<script src="/vendor/eimzo/vendor/e-imzo-client.js"></script>
<script src="/vendor/eimzo/eimzo.js"></script>
<script>
  window.EIMZO_API_KEYS = @json(config('eimzo.api_keys'));
</script>
```

### Поток логина

```javascript
const eimzo = new EimzoBridge();
await eimzo.install();           // checkVersion + installApiKeys
const keys = await eimzo.listKeys();
// показать пользователю keys[i].CN, valid_to, ...

const result = await eimzo.login(keys[0]);
// result.status === 1 → сервер залогинил пользователя в Laravel-сессии
// result.user = { id, name }
// result.certificate = { serial_number, cn, tin, pinfl, valid_from, valid_to }
window.location = result.redirect;
```

### Что происходит на бекенде (`EimzoAuthService`)

1. `GET /eimzo/auth/challenge` → проксируется в `GET /frontend/challenge`
   → ответ `{challenge: "..."}` → строка сохраняется в `eimzo_challenges`
   с `purpose=auth`, `expires_at = now + EIMZO_CHALLENGE_TTL`.
2. Браузер вызывает `CAPIWS.callFunction('pkcs7','create_pkcs7',[challenge_b64, keyId, 'no'])`.
3. `POST /eimzo/auth/verify {challenge, pkcs7}`:
   * Ищем строку в `eimzo_challenges` (purpose=auth, не used, не expired).
   * Шлём `POST /backend/auth` с телом `pkcs7Base64` (text/plain) +
     заголовками `Host` и `X-Real-IP`.
   * Если `status === 1`: парсим X.500 (CN, TIN, PINFL, UID, …),
     апдейтим/создаём `eimzo_certificates`, создаём `eimzo_signatures`
     с `document_type='auth-challenge'`, помечаем challenge `used`.
   * Если `auth.user_lookup_column` находит пользователя — `Auth::login()`.
     Иначе `auth.auto_register=true` создаст нового.

### Пример ответа `/eimzo/auth/verify`

```json
{
  "status": 1,
  "redirect": "/",
  "authenticated": true,
  "user": {"id": 17, "name": "ИВАНОВ ИВАН ИВАНОВИЧ"},
  "certificate": {
    "serial_number": "AABB1122",
    "cn": "ИВАНОВ ИВАН ИВАНОВИЧ",
    "tin": "300000000",
    "pinfl": "30000000000000",
    "valid_from": "2024-01-01T00:00:00+05:00",
    "valid_to": "2027-01-01T00:00:00+05:00"
  }
}
```

---

## Десктопный E-IMZO: подпись документа

```javascript
const data = JSON.stringify({ id: 42, total: 1500000 });
const result = await eimzo.sign(keys[0], {
  data,                         // строка → utf8→base64 автоматически
  detached: false,              // attached PKCS#7 (рекомендуется)
  document_type: 'invoice',
  document_name: 'invoice-42.json',
  attach_timestamp: true,       // запросить TSA-таймстамп
  meta: { invoice_id: 42 }
});

console.log(result.signature.id);              // ID в eimzo_signatures
console.log(result.signature.has_timestamp);   // true если TSA сработала
```

### Что происходит на бекенде (`EimzoSignService`)

1. `POST /eimzo/sign` принимает `pkcs7` (base64), `data` (опционально для
   detached), `document_type`, `document_name`, `attach_timestamp`, `meta`.
2. Если `attach_timestamp=true`: вызываем `POST /frontend/timestamp/pkcs7`,
   из ответа достаём `pkcs7b64` (новые билды) или `payload.pkcs7b64`
   (старые) и пишем в `pkcs7_with_timestamp`.
3. Полная верификация: `POST /backend/pkcs7/verify/attached` (или
   `/detached` с телом `<data>|<pkcs7>`).
4. Локальный парсинг сертификата через `openssl_pkcs7_read` (если
   `EIMZO_LOCAL_PARSE=true`), upsert `eimzo_certificates`, запись в
   `eimzo_signatures` со `verification_status=valid|invalid`.
5. Если `EIMZO_STORAGE_DISK` задан — сырой PKCS#7 сохраняется в файл
   `eimzo/signatures/{id}.p7`.

### Detached-подпись

```javascript
await eimzo.sign(keys[0], {
  data: largeFileAsBase64,
  detached: true,
  document_name: 'agreement.pdf'
});
```

---

## Подпись CRM-действия (canonical JSON)

Чтобы подпись имела юридическую силу для конкретного действия, нужно
зафиксировать набор полей. Бекенд сам генерирует canonical-JSON, фронт
получает только готовую строку для подписи.

### Контроллер CRM-действия

```php
public function approve(Request $request, Invoice $invoice)
{
    $payload = [
        'action' => 'approve_invoice',
        'amount' => $invoice->amount,
        'currency' => $invoice->currency,
        'entity_id' => $invoice->id,
        'entity_type' => 'invoice',
        'issued_at' => now()->toIso8601String(),
        'nonce' => Str::random(16),
    ];
    ksort($payload);
    return response()->json(['canonical' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}
```

### Браузер

```javascript
const { canonical } = await fetch('/invoices/42/approve', { method: 'POST' }).then(r => r.json());
const result = await eimzo.sign(keys[0], {
  data: canonical,
  document_type: 'crm-action',
  document_name: 'approve_invoice:42',
  meta: { canonical }
});
// signature.id ↔ привязываем к таблице signed_actions
```

### Привязка к доменной таблице

```php
Schema::create('signed_actions', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id');
    $t->string('action', 64);
    $t->string('entity_type');
    $t->unsignedBigInteger('entity_id');
    $t->json('payload_json');
    $t->string('payload_hash', 64);
    $t->foreignId('signature_id')->references('id')->on('eimzo_signatures');
    $t->foreignId('certificate_id')->nullable()->references('id')->on('eimzo_certificates');
    $t->timestampTz('signed_at');
    $t->ipAddress('ip')->nullable();
    $t->string('user_agent', 512)->nullable();
});
```

---

## Верификация PKCS#7

`EimzoVerifyService` — stateless, ничего в БД не пишет. Полезен для
"проверь подпись, которую мне прислали в письме".

```php
use AsadbekRahimov\EimzoIntegration\Services\EimzoVerifyService;

$result = app(EimzoVerifyService::class)->verify(
    $pkcs7Base64,                 // обязательно
    $dataBase64 ?? null,          // detached → передать оригинал
    request()
);

if ($result['ok']) {
    foreach ($result['signers'] as $signer) {
        // certificate chain, OCSP, политики
    }
    $document = base64_decode($result['document_base64']); // для attached
}
```

---

## TSA-таймстампы и операции с PKCS#7

```php
use AsadbekRahimov\EimzoIntegration\Services\EimzoTimestampService;

$ts = app(EimzoTimestampService::class);

// Запросить таймстамп на готовый PKCS#7
$ts->timestampPkcs7($pkcs7Base64, $request);

// Или на сырые данные (получится отдельный TSA-токен)
$ts->timestampData(base64_encode($document), $request);

// Detached → Attached
$ts->makeAttached($dataBase64, $detachedPkcs7Base64, $request);

// Объединить две подписи (co-sign)
$ts->join($pkcs7A, $pkcs7B, $request);
```

HTTP-обёртки тех же операций: `POST /eimzo/timestamp/pkcs7`,
`/eimzo/timestamp/data`, `/eimzo/pkcs7/make-attached`, `/eimzo/pkcs7/join`.

---

## Мобильный API (ID-CARD)

Используется когда пользователь логинится / подписывает с **смартфона**
через приложение «E-IMZO ID-CARD» (NFC-чтение ID-карты). На сайте
показывается QR (или deeplink), пользователь сканирует его в приложении,
подписывает, приложение POST-ит PKCS#7 на UPLOAD URL сайта.

### Поток авторизации

```
[Site]                      [Backend]                     [E-IMZO-SERVER]                 [ID-CARD app]
   │                            │                                │                              │
   │── POST /mobile/auth/start ─▶                                │                              │
   │                            │── POST /frontend/mobile/auth ─▶│                              │
   │                            ◀──── {documentId, challenge}────│                              │
   ◀── {site_id, document_id, challenge} ──│                                                    │
   │                                                                                            │
   │ render QR(siteId+docId+gostHash(challenge)+CRC32)                                          │
   │ ◀───── scan ────────────────────────────────────────────────────────────────────────────── │
   │                                                                                            │
   │ poll: POST /mobile/auth/status {document_id}        ID-CARD reads NFC, signs               │
   │                            │── POST /frontend/mobile/status ▶                              │
   │                            ◀────────── status=2 ────────────│                              │
   │                                                       ◀── POST /frontend/mobile/upload ────│
   │                            │── POST /frontend/mobile/status ▶                              │
   │                            ◀────────── status=1 ────────────│                              │
   │                                                                                            │
   │── POST /mobile/auth/complete ▶                                                             │
   │                            │── GET /backend/mobile/authenticate/{id} ▶                     │
   │                            ◀──── subjectCertificateInfo ────│                              │
   ◀── {user, certificate, authenticated:true} ──│                                              │
```

### Браузер

```html
<script src="/vendor/eimzo/eimzo-mobile.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
<!-- GostHash НЕ входит в поставляемые vendor-скрипты: подключите gost-hash.js
     отдельно (см. https://test.e-imzo.uz/demo/eimzoidcard) -->
```

```javascript
const m = new EimzoMobile();

// 1. Логин через мобилу
const session = await m.startAuth();
// session = { status, site_id, document_id, challenge, qr, ttl }
QRCode.toCanvas(document.getElementById('qr'), session.qr);

const result = await m.waitAndCompleteAuth(session.document_id);
// result.user, result.certificate, result.authenticated
```

### Подпись через мобилу

```javascript
const documentBase64 = btoa(JSON.stringify(canonical));
const session = await m.startSign(documentBase64);
QRCode.toCanvas(document.getElementById('qr'), session.qr);

const sig = await m.waitAndCompleteSign(session.document_id, documentBase64, {
  document_type: 'invoice',
  document_name: 'invoice-42.json',
  meta: { canonical: JSON.parse(atob(documentBase64)) }
});
// sig.signature.id — ID в eimzo_signatures, pkcs7_with_timestamp заполнен
```

### Структура QR

```
siteId(4 hex) + documentId(8 hex) + gostHash34_11_94(64 hex) + CRC32(8 hex)
```

`makeQrPayload()` собирает строку. Для GOST-хеша используется глобальный
`GostHash` — он **не входит** в поставляемые vendor-скрипты, подключите
`gost-hash.js` отдельно (образец — <https://test.e-imzo.uz/demo/eimzoidcard>).
Если хеша нет — fallback на SHA-256 для отладки (мобильное приложение его
не примет).

### UPLOAD URL

PKI ГЦР должен зарегистрировать ваш UPLOAD URL. По умолчанию пакет принимает
PKCS#7 на:

```
https://my-crm.uz/eimzo/mobile/upload
https://my-crm.uz/frontend/mobile/upload      (алиас, под путь из эталонного демо)
```

Контроллер `EimzoMobileController::upload()` просто проксирует тело и
query-параметры (DocumentID, SerialNumber) в `POST /frontend/mobile/upload`
на E-IMZO-SERVER, чтобы Java-сервис положил PKCS#7 в Redis.

### Коды статусов мобильного API

| Код | Где видно                              | Значение |
|-----|----------------------------------------|----------|
| 1   | status, complete                       | OK       |
| 2   | status                                 | Ожидание загрузки PKCS#7 |
| -1  | status / authenticate / verify         | Ошибка Redis |
| -2  | status / authenticate / verify         | DocumentID не найден / истёк |
| -5  | authenticate / verify                  | Неверная подпись |
| -6  | authenticate / verify                  | Неверный сертификат |
| -7  | authenticate / verify                  | Сертификат был невалиден на момент подписи |
| -8  | authenticate / verify                  | Ошибка проверки статуса сертификата (OCSP) |
| -9  | authenticate / verify                  | Превышено окно времени |
| -10 | status                                 | Ошибка сервера, см. логи |

### Использование сервиса напрямую

```php
use AsadbekRahimov\EimzoIntegration\Services\EimzoMobileService;

$mobile = app(EimzoMobileService::class);

$session = $mobile->issueAuth($request);
// $session['document_id'], $session['challenge']

$status = $mobile->pollStatus($session['document_id'], $request);
if ($status['status'] === 1) {
    $result = $mobile->completeAuth($session['document_id'], $request);
    // $result['user'], $result['certificate']
}

// Подпись
$signSession = $mobile->issueSign($request);
$sig = $mobile->completeSign(
    $signSession['document_id'],
    base64_encode($document),
    $request,
    [
        'document_type' => 'invoice',
        'document_name' => 'invoice-42.json',
        'user_id' => auth()->id(),
        'meta' => ['invoice_id' => 42],
    ]
);
```

---

## Использование сервисов из PHP-кода

| Сервис                       | Назначение |
|------------------------------|-----------|
| `EimzoServerClient`          | Низкоуровневый HTTP-клиент к E-IMZO-SERVER |
| `EimzoAuthService`           | Логин по challenge (десктоп) |
| `EimzoSignService`           | Сохранение подписи + verify + TSA |
| `EimzoVerifyService`         | Stateless-верификация |
| `EimzoTimestampService`      | TSA + make-attached + join |
| `EimzoMobileService`         | Полный поток мобильного API |
| `Pkcs7Parser`                | Локальный парсинг сертификата |

Все они зарегистрированы как singletons. Внедряйте через DI:

```php
public function __construct(EimzoSignService $signer) {
    $this->signer = $signer;
}
```

---

## Модели и таблицы БД

### `eimzo_challenges`

| Колонка       | Описание                                                      |
|---------------|---------------------------------------------------------------|
| `challenge`   | Десктоп: строка от `/frontend/challenge`. Мобайл: DocumentID. |
| `purpose`     | `auth`, `mobile-auth`, `mobile-sign`                          |
| `expires_at`  | TTL                                                           |
| `used_at`     | NULL пока не использовали — защита от replay                  |
| `meta`        | JSON: `site_id`, `server_payload`                             |

### `eimzo_certificates`

Уникален по `serial_number`. Поля: `cn`, `tin`, `pinfl`, `uid`, `o`, `t`,
`country`, `email`, `valid_from`, `valid_to`, `subject_dn`, `issuer_dn`,
`certificate_pem`, `last_verify_payload`, `last_verified_at`, `user_id`.

`EimzoCertificate::upsertFromSigner($info, $userId)` — апсерт по серийнику,
не затирает ранее сохранённые непустые поля.

### `eimzo_signatures`

| Колонка                 | Описание |
|-------------------------|----------|
| `user_id`               | nullable |
| `certificate_id`        | FK → `eimzo_certificates` |
| `document_type`         | `auth-challenge`, `invoice`, `mobile-sign`, … |
| `document_name`         | свободный текст |
| `document_size`/`hash`  | sha256 от исходных данных (если они есть) |
| `pkcs7`                 | base64 PKCS#7 без TSA (десктоп) |
| `pkcs7_with_timestamp`  | base64 PKCS#7 с TSA |
| `pkcs7_path`            | путь в storage если включено |
| `detached`              | bool |
| `signed_at`/`timestamp_at`/`verified_at` | timestamps |
| `verification_status`   | `valid` / `invalid` / `pending` / `error` |
| `verification_payload`  | JSON-ответ E-IMZO-SERVER |
| `meta`                  | JSON, в т.ч. `mobile_document_id`, `policy_identifiers` |
| `signable_type`/`signable_id` | morphTo для привязки к доменной модели |

Привязать подпись к Invoice:

```php
$signature->signable()->associate($invoice)->save();
```

---

## События ошибок и коды статусов

В JSON-ответах пакета:

| status | Значение                                         | HTTP |
|--------|--------------------------------------------------|------|
| 1      | OK                                               | 200  |
| -1     | Бизнес-ошибка (challenge expired, signature bad) | 410 / 422 |
| -2     | Ошибка E-IMZO-SERVER (недоступен / 5xx / VPN)    | 503  |

Исключения:

- `AsadbekRahimov\EimzoIntegration\Exceptions\EimzoServerException` — сетевые
  ошибки и не-JSON ответы. `payload()` содержит `path`, `status`, `body`.
- `VerificationFailedException` — `status !== 1` от E-IMZO-SERVER.
- `ChallengeExpiredException` — challenge просрочен или уже использован.

Логирование рекомендуется на каждое исключение:

```php
try {
    $signer->store($input, $request);
} catch (EimzoServerException $e) {
    logger()->error('EIMZO unreachable', $e->payload());
    throw $e;
}
```

---

## Безопасность

1. **API-ключи доменов** — никогда не коммить в репозиторий, только через
   `.env`. Для каждого домена ключ свой.
2. **`X-Real-IP` и `Host`** — пакет выставляет их автоматически. Если
   стоите за nginx, убедитесь что он передаёт реальный IP в `X-Real-IP`.
3. **CSRF** — все маршруты под `web` защищены, кроме `mobile/upload`
   (см. раздел установки).
4. **One-time challenge** — `eimzo_challenges.used_at` помечается после
   первой успешной верификации. Replay невозможен.
5. **Match-проверка** — `EimzoAuthService` проверяет, что подписанный
   challenge внутри PKCS#7 совпадает с тем, что выдал сервер.
6. **OCSP** — выполняется в E-IMZO-SERVER через VPN. Если VPN недоступен,
   `verify` вернёт `status=-2`, ничего не сохранится.
7. **TSA** — рекомендуется всегда (`EIMZO_ATTACH_TIMESTAMP=true`). Без
   таймстампа нельзя доказать, что подпись была сделана *до* истечения
   сертификата.
8. **Mobile DocumentID** — одноразовый, TTL = `EIMZO_CHALLENGE_TTL`.
   Полным аналогом nonce служит сам DocumentID, который мы храним в
   `eimzo_challenges` со столь же одноразовой семантикой.

---

## Тестирование

```bash
composer install
composer test
```

В пакете тесты на:

- AuthFlow (десктоп логин)
- MobileFlow (мобильный логин/подпись/upload)
- EimzoServerClient (роутинг URL для same-origin proxy)
- Pkcs7Parser
- AssetRoute (отдача vendor/eimzo/*.js)
- BrowserBridgeSource (sanity-check JS-бандла)
- ExamplePages (smoke views)

```bash
php vendor/bin/phpunit
```

---

## Резюме маршрутов мобильного API в одном кадре

```
POST  /eimzo/mobile/auth/start        →  {site_id, document_id, challenge}
POST  /eimzo/mobile/auth/status       ⇄  {status: 1|2|-1|-2|-9|-10}
POST  /eimzo/mobile/auth/complete     →  {user, certificate, authenticated}

POST  /eimzo/mobile/sign/start        →  {site_id, document_id}
POST  /eimzo/mobile/sign/status       ⇄  {status}
POST  /eimzo/mobile/sign/complete     →  {signature: {id, pkcs7Attached, ts}}

POST  /eimzo/mobile/upload            ←  PKCS#7 от ID-CARD app
```
