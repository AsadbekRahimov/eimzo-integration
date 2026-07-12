# Архитектура — как этот пакет устроен под капотом

Этот документ — для **разработчиков, интегрирующих, расширяющих или отлаживающих пакет**. Он описывает каждую движущуюся часть E-IMZO, к которой имеет отношение пакет:

- Три независимых процесса, которые вместе реализуют E-IMZO-подпись.
- За что отвечает desktop-клиент, браузерный SDK, Laravel-модуль и Java-бэкенд.
- Точные форматы данных на каждом «прыжке» (WebSocket-кадры, тела HTTP-запросов, строки БД).
- Инварианты безопасности, на которые полагается пакет (replay, привязка к хосту, цепочка доверия) и где они проверяются.
- Жизненные циклы всех публичных потоков: вход, подписание документа, подписание действия, верификация, mobile (ID-CARD через NFC).

Если вам нужно только **использовать** пакет, читайте [README.md](README.md) и [INTEGRATION.md](INTEGRATION.md). По значениям конфигурации — [CONFIG.md](CONFIG.md). По бизнес-примерам (login, sign, action, mobile) — [EXAMPLES.md](EXAMPLES.md) и [USAGE.md](USAGE.md).

---

## 1. Модель из трёх процессов

E-IMZO — **не** одна библиотека. Рабочая подпись задействует три процесса, разговаривающих друг с другом через две сетевые границы:

```
   ┌─────────────────────────────────────┐    ┌────────────────────────────────────┐    ┌────────────────────────────┐
   │ 1. Браузер (ПК пользователя)        │    │ 2. Laravel-приложение (этот пакет) │    │ 3. E-IMZO-SERVER (Java)    │
   │    - Blade-страницы                 │    │    - выпуск challenge’ей           │    │    - верификатор PKCS#7    │
   │    - vendor/e-imzo.js (CAPIWS)      │ ──►│    - хранение сертов и подписей    │ ──►│    - OCSP / CRL responder  │
   │    - vendor/e-imzo-client.js        │    │    - вызовы E-IMZO-SERVER (HTTP)   │    │    - TSA timestamp service │
   │    - eimzo.js (EimzoBridge)         │    │    - логин пользователя            │    │    - VPN до гос-PKI        │
   │           │                         │    │           │                        │    │                            │
   │           │ wss://127.0.0.1:64443   │    │           │ HTTP (text/plain body) │    │                            │
   │           ▼                         │    │           ▼                        │    │                            │
   │ ┌──────────────────────────────┐    │    │ ┌──────────────────────────────┐   │    │ ┌──────────────────────────┐
   │ │ 1a. E-IMZO desktop-клиент    │    │    │ │ MySQL / Postgres / SQLite    │   │    │ │ vpn.e-imzo.uz:3443       │
   │ │     (E-IMZO.exe)             │    │    │ │  eimzo_challenges            │   │    │ │ (UZ PKI Technical Centre)│
   │ │     - держит приватные ключи │    │    │ │  eimzo_certificates              │    │ │  - выдаёт TSA-токены     │
   │ │     - читает .pfx, ID-card   │    │    │ │  eimzo_signatures            │   │    │ │  - подписывает OCSP      │
   │ │     - запрашивает PIN        │    │    │ └──────────────────────────────┘   │    │ └──────────────────────────┘
   │ └──────────────────────────────┘    │    │                                    │    │                              │
   └─────────────────────────────────────┘    └────────────────────────────────────┘    └──────────────────────────────┘
```

### 1.1 Зачем такое разбиение

**Граница безопасности 1 — приватный ключ никогда не покидает ПК пользователя.** Только desktop-клиент (E-IMZO.exe) видит материал ключа. Браузер просит его подписать, пользователь локально вводит PIN, desktop-клиент возвращает готовый PKCS#7. Ваш сервер ключ не видит.

**Граница безопасности 2 — VPN до гос-PKI есть только у E-IMZO-SERVER.** Чтобы убедиться, что подписной сертификат действителен прямо сейчас, нужно стучаться к гос-OCSP-respondery, а это требует VPN до UZ PKI Technical Centre. Java-сервис (`e-imzo-server.jar`) — единственный компонент, у которого эти VPN-данные есть. Ваше Laravel-приложение разговаривает с ним по обычному HTTP (loopback или LAN).

**Эксплуатационная граница — держите гос-VPN и CRM на расстоянии вытянутой руки.** Если OCSP оффлайн или VPN падает — ломается только верификация подписей; CRM продолжает работать. Если падает CRM — подписи, уже сохранённые в БД, по-прежнему валидны: их верифицировали в момент подписания.

### 1.2 Роль каждого компонента

| Компонент | Процесс | Файлы в репо | Ответственность |
|---|---|---|---|
| **E-IMZO desktop-клиент** | ПК пользователя | (внешний; не в репо) | Хранит приватные ключи (PFX-файлы, ID-CARD через NFC, BAIK-токен, CKC), перечисляет сертификаты, спрашивает PIN, **производит** PKCS#7-конверт |
| **CAPIWS** | Браузер (в `e-imzo.js`) | `resources/js/vendor/e-imzo.js` | Тонкий WebSocket-мост: открывает `wss://127.0.0.1:64443/service/cryptapi`, шлёт JSON-команду и резолвит ответ desktop-клиента |
| **EIMZOClient** | Браузер (в `e-imzo-client.js`) | `resources/js/vendor/e-imzo-client.js` | Высокоуровневая обёртка: парсит X.500-имена сертификатов, перечисляет ключи пользователя, дёргает `create_pkcs7`, version-gating |
| **EimzoBridge** | Браузер (в `eimzo.js`) | `resources/js/eimzo.js` | Promise-фасад этого пакета: регистрирует API-ключ для текущего хоста, ведёт auth/sign-поток, общается с Laravel |
| **EimzoMobile** | Браузер (в `eimzo-mobile.js`) | `resources/js/eimzo-mobile.js` | Сборщик QR/deeplink + опрос статуса для мобильного ID-CARD-потока |
| **Laravel-модуль** | Ваш сервер | `src/`, `routes/` | Выпускает challenge’и, сохраняет сертификаты, сохраняет подписи, ищет пользователей, верифицирует подписи через E-IMZO-SERVER, выставляет браузер-видимые маршруты |
| **E-IMZO-SERVER** | Ваш сервер (Java JAR) | (внешний; не в репо) | Верифицирует PKCS#7 против гос-цепочки доверия, навешивает TSA-метки, валидирует мобильные сессии |
| **UZ PKI Technical Centre** | Гос. сервис | (внешний) | Выдаёт доменные API-ключи, держит OCSP / TSA, регистрирует SiteID для mobile |

---

## 2. Браузерный слой (CAPIWS, EIMZOClient, EimzoBridge)

### 2.1 `vendor/e-imzo.js` — `CAPIWS`

Этот файл — WebSocket-мост к desktop-клиенту. Каждый криптовызов — один round-trip:

1. Открываем `wss://127.0.0.1:64443/service/cryptapi` (или `ws://127.0.0.1:64646` для plain-HTTP-страниц — см. `e-imzo.js`).
2. Шлём один JSON-кадр, например `{"name":"version"}`.
3. Desktop-клиент отвечает одним JSON-кадром.
4. Закрываем сокет.

Файл выставляет четыре публичных функции: `version`, `apidoc`, `apikey` и обобщённый `callFunction({plugin, name, arguments})`.

**Пример — список всех PFX-сертификатов:**

```js
CAPIWS.callFunction(
    { plugin: "pfx", name: "list_all_certificates" },
    function (event, data) { console.log(data.certificates); },
    function (e) { console.error(e); }
);
```

Пространства имён `plugin` фиксированы desktop-клиентом. Самые востребованные: `pfx` (файловые keystore’ы), `ftjc` (BAIK USB-токены), `idcard` (NFC ID-CARD), `ckc`, `pkcs7` (создание конвертов).

> **Важно (регрессия).** В некоторых сборках этот файл уже отгружался пустым. В пакете теперь есть регрессионный тест (`tests/BrowserBridgeSourceTest.php::test_vendor_e_imzo_js_exposes_capiws_websocket_bridge_and_base64`), который роняет тест-набор, как только `e-imzo.js` перестаёт выставлять `Base64`, `CAPIWS`, WebSocket-URL или кадры `version`/`apikey`.

### 2.2 `vendor/e-imzo-client.js` — `EIMZOClient`

Оборачивает `CAPIWS` всеми удобствами, нужными потребителю:

- `installApiKeys(success, fail)` — шлёт кадр `apikey`, чтобы desktop-клиент знал: страница имеет право его дёргать.
- `checkVersion(success, fail)` — выставляет фичефлаги `NEW_API`, `NEW_API2`, `NEW_API3` по версии запущенного desktop-клиента.
- `listAllUserKeys(idGen, uiGen, success, fail)` — мерджит списки сертификатов PFX (`_findPfxs2`) и BAIK-токенов (`_findTokens2`) в один массив объектов с нормализованными X.500-полями (`CN`, `TIN`, `PINFL`, `validFrom`, …).
- `loadKey(item, success, fail)` — открывает хэндл ключа (если нужно — спрашивает PIN) и возвращает `keyId`.
- `createPkcs7(keyId, data, timestamper, success, fail, detached, isB64)` — просит desktop-клиент произвести PKCS#7-конверт.

Вендор-файл ещё inline-парсит X.500 (`_getX500Val`, `splitKeep`); учтите, что он нормализует узбек-специфичные OID:

- `1.2.860.3.16.1.1` → `INN` (налоговый ID юр-лица, часто называют TIN)
- `1.2.860.3.16.1.2` → `PINFL` (идентификатор физ-лица)

### 2.3 `eimzo.js` — `EimzoBridge`

Promise-фасад пакета, с которым на самом деле взаимодействует прикладной код. Это единственная точка входа, на которую ссылаются все Blade-демо и quick-start из README.

```js
const eimzo = new EimzoBridge({ csrfToken: '...' });
await eimzo.install();              // -> CAPIWS.apikey() + проверка версии
const keys = await eimzo.listKeys(); // -> EIMZOClient.listAllUserKeys
const result = await eimzo.login(keys[0]);  // -> challenge + signRaw + verify
```

Внутри:

- Читает `window.EIMZO_API_KEYS`, выставленный Blade-лейаутом (см. § 4), и подсовывает его в `EIMZOClient.API_KEYS` перед вызовом `installApiKeys`.
- Для каждого бизнес-вызова (`login`, `sign`, `verify`) делает WebSocket round-trip ради PKCS#7, а затем `fetch()`-ит соответствующий маршрут Laravel, чтобы сохранить и верифицировать результат.
- Все HTTP-ответы нормализуются к `{ status, message, ... }` — `status: 1` это успех, остальное считается ошибкой.

---

## 3. Слой Laravel

Маршруты монтируются `EimzoServiceProvider`. Префиксы по умолчанию: `eimzo/*` (web) и `api/eimzo/*` (api). Каждый браузер-видимый маршрут смотрит в контроллер, контроллер делегирует в сервис:

| Маршрут | Контроллер | Сервис | Что делает |
|---|---|---|---|
| `GET  /eimzo/auth/challenge` | `EimzoAuthController::challenge` | `EimzoAuthService::issueChallenge` | Дёргает `EimzoServerClient::challenge()`, кладёт строку в `eimzo_challenges` с TTL |
| `POST /eimzo/auth/verify` | `EimzoAuthController::verify` | `EimzoAuthService::verifyChallenge` | Проверяет challenge → `Server::authenticate(pkcs7)` → парсит подписанта → upsert серта → логин |
| `POST /eimzo/sign` | `EimzoSignController::store` | `EimzoSignService::store` | Опциональный TSA-timestamp → серверная верификация (attached/detached) → upsert серта → запись `eimzo_signatures` |
| `POST /eimzo/verify` | `EimzoVerifyController::verify` | `EimzoVerifyService::verify` | Идемпотентная серверная верификация произвольного PKCS#7 |
| `POST /eimzo/timestamp/pkcs7` | `EimzoTimestampController::pkcs7` | `EimzoTimestampService::pkcs7` | Обёртка над `Server::timestampPkcs7` для клиент-управляемых TSA-потоков |
| `POST /eimzo/mobile/auth/start` | `EimzoMobileController::authStart` | `EimzoMobileService::authStart` | Выпускает `documentId` + challenge для QR/deeplink |
| `POST /eimzo/mobile/auth/status` | `EimzoMobileController::status` | `EimzoMobileService::pollStatus` | Опрашивает Java-state (Redis-backed) |
| `POST /eimzo/mobile/auth/complete` | `EimzoMobileController::authComplete` | `EimzoMobileService::completeAuth` | Финализирует mobile-auth через `/backend/mobile/authenticate/{id}` |
| `*    /eimzo/mobile/upload` | `EimzoMobileController::upload` | `EimzoMobileService::upload` | Принимает PKCS#7 от ID-CARD-приложения и форвардит в Java |

### 3.1 `EimzoServerClient` — единственное место, которое говорит с Java

Все вызовы к гос-стороне идут через `src/Services/EimzoServerClient.php`. Java-бэкенд использует тела **`text/plain`** (часто с `|` как разделителем) для крипто-эндпоинтов и `application/x-www-form-urlencoded` лишь для горстки служебных мобильных. Клиент уважает обе формы:

```php
$server->authenticate($pkcs7Base64);                         // POST /backend/auth                  (text body)
$server->verifyAttached($pkcs7Base64);                       // POST /backend/pkcs7/verify/attached  (text body)
$server->verifyDetached($dataBase64, $pkcs7Base64);          // POST /backend/pkcs7/verify/detached  (text body, "<data>|<pkcs7>")
$server->timestampPkcs7($pkcs7Base64);                       // POST /frontend/timestamp/pkcs7       (text body)
$server->joinPkcs7($a, $b);                                  // POST /frontend/pkcs7/join            (text body, "<a>|<b>")
$server->mobileAuth();                                       // POST /frontend/mobile/auth           (пустое тело)
$server->mobileStatus($docId);                               // POST /frontend/mobile/status         (form: documentId)
$server->mobileVerify($docId, $documentBase64);              // POST /backend/mobile/verify          (form)
```

В клиенте сосуществуют два HTTP-слоя:

- `server_url` — **канонический** URL Java-сервиса. Всегда достижим из PHP. Все вызовы `/backend/*` идут сюда.
- `frontend_url` — опциональный алиас для frontend-группы Java endpoints (`/frontend/*`). По умолчанию пустой, и PHP вызывает эти endpoints через `server_url`. Если задан относительный путь вроде `/frontend`, клиент перепишет его в `<схема запроса>://<хост>/frontend/...`, и тогда используется nginx/apache proxy. Если задан абсолютный корень Java (`http://127.0.0.1:8080`), клиент сохранит `/frontend/...`; если абсолютный URL уже заканчивается на `/frontend`, префикс не будет продублирован.

### 3.2 `Pkcs7Parser` — локальное извлечение сертификата

Пакет извлекает поля сертификата подписанта **локально** через `openssl_pkcs7_read` до/после серверной верификации. Так вы получаете `CN`, `TIN`, `PINFL`, `serial_number`, `valid_from`, `valid_to` и сырой PEM **без** ожидания Java. Локальный парсинг — **не** замена проверке цепочки доверия: эту проверку всегда делает E-IMZO-SERVER.

Отключите через `EIMZO_LOCAL_PARSE=false`, если у вас нет расширения openssl или вы хотите строго server-only-парсинг.

### 3.3 Три таблицы хранения

```
eimzo_challenges
└── одна строка на каждый выпущенный challenge (auth или sign)
    challenge text, purpose, ip, ua, expires_at, used_at

eimzo_certificates
└── одна строка на каждый уникальный подписной сертификат
    serial_number (unique), cn, tin, pinfl, uid, o, t, valid_from, valid_to,
    subject_dn, issuer_dn, certificate_pem, last_verify_payload, last_verified_at,
    user_id (best-effort связь)

eimzo_signatures
└── одна строка на каждый подписанный конверт
    user_id, certificate_id, document_type, document_name, document_size,
    document_hash, pkcs7, pkcs7_with_timestamp, detached, signed_at,
    timestamp_at, verification_status, verification_payload, verified_at,
    pkcs7_path, signable_type, signable_id, meta
```

Полиморфные `signable_type` / `signable_id` позволяют привязать подпись к любой Eloquent-модели:

```php
$signature = $signService->store([
    'pkcs7'         => $pkcs7,
    'document_type' => 'contract',
    'document_name' => 'Contract #'.$contract->id,
    'signable'      => $contract, // -> signable_type=Contract, signable_id=42
], $request);
```

---

## 4. Как работает поток доменных API-ключей

Это та область, про которую вы спрашивали раньше.

### 4.1 Что навязывает desktop-клиент

Когда вы вызываете `CAPIWS.apikey([d1, k1, d2, k2, ...])`, desktop-клиент запоминает список **на всё время WebSocket-сессии**. Последующие вызовы (`pkcs7.create_pkcs7`, `pfx.list_all_certificates`, …) отвергаются, если хост вызывающей страницы (`window.location.host`, без порта) отсутствует в списке или его ключ не совпадает с тем, который UZ PKI Technical Centre выдал именно для этого хоста.

Desktop-клиент сравнивает хосты регистронезависимо, но **дословно** — `localhost` и `127.0.0.1` для него разные, поэтому в dev-окружении, где переключаются между ними, нужны оба ключа.

### 4.2 Что делает этот пакет

В каждом Blade-лейауте демо (`resources/views/layouts/app.blade.php`) пакет выставляет ровно один `<script>`:

```html
<script>
  window.EIMZO_API_KEYS = ["crm.example.uz","BBB"];
</script>
```

Список собирает `AsadbekRahimov\EimzoIntegration\Support\ApiKeyRegistry::resolveForHost()`:

1. Читает `config('eimzo.api_keys')` (любая из четырёх входных форм — строка-карта, строка с запятыми, ассоциативный массив, плоские пары).
2. Накладывает сверху переменные на каждый хост (`EIMZO_API_KEY_*`).
3. Фильтрует получившуюся `[domain => key]`-карту до **только** записи для хоста запроса плюс loopback-партнёра, если уместно (`localhost ↔ 127.0.0.1`).
4. Уплощает в `[d, k, d, k]` для SDK.

Это значит: страница на `crm.example.uz` **не получает** ключ для `backoffice.example.uz`, даже если оба сконфигурированы. Это defence-in-depth: утечка доменных ключей не катастрофична (они и так привязаны к конкретному хосту), но ограничить экспозицию дёшево и DevTools остаются опрятнее.

### 4.3 Что нужно сконфигурировать

Для каждого домена, на котором живут Blade-страницы (или работает кастомный JS, использующий `EimzoBridge`), зарегистрируйте соответствующий ключ. Рекомендуемый синтаксис `EIMZO_API_KEYS=...` — в [CONFIG.md § 1](CONFIG.md#1-доменные-api-ключи).

---

## 5. Жизненный цикл: вход по подписи (запрос за запросом)

```
Браузер                 Laravel               Desktop-клиент          E-IMZO-SERVER
  │                        │                        │                       │
  │ GET /eimzo/login       │                        │                       │
  │ ─────────────────────► │                        │                       │
  │ ◄───────────────────── │                        │                       │
  │  HTML + window.EIMZO_API_KEYS = [host,key]      │                       │
  │                        │                        │                       │
  │ new EimzoBridge().install()                     │                       │
  │ ─────────────────────────────────────────────► │                       │
  │   wss + CAPIWS.apikey([host,key])              │                       │
  │ ◄───────────────────────────────────────────── │                       │
  │   {success:true}                               │                       │
  │                                                 │                       │
  │ EIMZOClient.listAllUserKeys()                  │                       │
  │ ─────────────────────────────────────────────► │                       │
  │ ◄───────────────────────────────────────────── │                       │
  │   [{type:pfx, CN, TIN, PINFL, ...}, ...]       │                       │
  │                                                 │                       │
  │ GET /eimzo/auth/challenge                       │                       │
  │ ─────────────────────► │                        │                       │
  │                        │ EimzoServerClient::challenge()                 │
  │                        │ ───────────────────────────────────────────► │
  │                        │ ◄────────────────────────────────────────────│
  │                        │   {challenge:"<random>"}                     │
  │                        │ INSERT eimzo_challenges (..., expires_at=120) │
  │ ◄───────────────────── │                        │                      │
  │  {challenge}           │                        │                      │
  │                                                 │                      │
  │ EIMZOClient.createPkcs7(keyId, challenge, "no") │                      │
  │ ─────────────────────────────────────────────► │                      │
  │   ◄── PIN-диалог ──◄                            │                      │
  │ ◄───────────────────────────────────────────── │                      │
  │   {success:true, pkcs7_64:"MIID..."}           │                      │
  │                                                 │                      │
  │ POST /eimzo/auth/verify {challenge, pkcs7}      │                      │
  │ ─────────────────────► │                        │                      │
  │                        │ EimzoChallenge::find / isExpired / isUsed     │
  │                        │ EimzoServerClient::authenticate(pkcs7)         │
  │                        │ ───────────────────────────────────────────► │
  │                        │  POST /backend/auth (text body = pkcs7)       │
  │                        │ ◄────────────────────────────────────────────│
  │                        │  {status:1, payload:{CN,TIN,PINFL,...}}      │
  │                        │ Pkcs7Parser::parseSigner (openssl)            │
  │                        │ resolveUser($info) -> users.tin = $tin        │
  │                        │ EimzoCertificate::upsertFromSigner            │
  │                        │ EimzoSignature::create(document_type=auth)    │
  │                        │ challenge->markUsed()                          │
  │                        │ Auth::guard()->login($user, true)             │
  │ ◄───────────────────── │                        │                      │
  │  {status:1, redirect, user, certificate}        │                      │
  │ window.location = redirect                      │                      │
```

**Зачем challenge?** Подписываемые байты не должны быть угадываемы атакующим. Иначе повтор старого PKCS#7 из логов залогинил бы его. Challenge одноразовый (`used_at`) и с TTL (`expires_at`), поэтому каждый вход даёт уникальный криптоконверт.

---

## 6. Жизненный цикл: подписание документа (TSA + verify)

```
Браузер                 Laravel               Desktop-клиент          E-IMZO-SERVER
  │ POST /eimzo/sign       │                                                │
  │  {pkcs7, detached?,    │                                                │
  │   data?, doc_type, ...}│                                                │
  │ ─────────────────────► │                                                │
  │                        │ EimzoSignService::store()                      │
  │                        │                                                │
  │                        │ if attach_timestamp:                            │
  │                        │   Server::timestampPkcs7($pkcs7)              │
  │                        │ ───────────────────────────────────────────► │
  │                        │  POST /frontend/timestamp/pkcs7               │
  │                        │ ◄────────────────────────────────────────────│
  │                        │   {status:1, pkcs7b64:..., timestampedSignerList:[{timestamp:...}]}
  │                        │                                                │
  │                        │ Server::verifyAttached($pkcs7WithTs ?? $pkcs7)│
  │                        │ ───────────────────────────────────────────► │
  │                        │ ◄────────────────────────────────────────────│
  │                        │   {status:1, payload:{CN,TIN,PINFL,...}}     │
  │                        │                                                │
  │                        │ Pkcs7Parser::parseSigner($pkcs7)              │
  │                        │ EimzoCertificate::upsertFromSigner             │
  │                        │ EimzoSignature::create(verification_status=valid)
  │                        │ Storage::disk('local')->put('eimzo/signatures/$id.p7')
  │ ◄───────────────────── │                                                │
  │  {status:1, signature:{id, certificate, document_hash, ...}}            │
```

Замечания:

- Падение `attach_timestamp` не отменяет запрос. Неподписанный TSA-меткой PKCS#7 всё равно сохраняется и верифицируется; ошибка TSA пишется в `meta.timestamp_error`, чтобы потом дотекстамповать.
- При detached-подписании канонические байты документа должны прийти в `data` как base64, чтобы сервер мог сверить дайджест с конвертом.

---

## 7. Жизненный цикл: подписание действий

Подписание действий — это **подписание документа, применённое к каноническому JSON**, который произвёл бэкенд. Пакет не вводит для этого специальный эндпоинт: переиспользуется `POST /eimzo/sign`, а потребитель кодирует смысл в `document_type`, `document_name` и `meta`.

Стандартный паттерн (тоже описан в [EXAMPLES.md](EXAMPLES.md)):

```php
// Бэкенд производит канонический JSON (клиент не может его поменять):
$payload = [
    'action'      => 'approve_invoice',
    'amount'      => 1500000,
    'currency'    => 'UZS',
    'entity_id'   => $invoice->id,
    'entity_type' => 'invoice',
    'issued_at'   => now()->toIso8601String(),
    'nonce'       => Str::random(16),
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Шлём в браузер канонические байты, храним хеш для replay-защиты:
SignedAction::create([
    'user_id'      => $user->id,
    'action'       => $payload['action'],
    'entity_type'  => 'invoice',
    'entity_id'    => $invoice->id,
    'payload_json' => $json,
    'payload_hash' => hash('sha256', $json),
]);
```

```js
// Браузер подписывает в точности те канонические байты, что выдал бэкенд:
await eimzo.sign(key, {
    data: window.SIGN_PAYLOAD_JSON,           // отрендерено сервером, неизменяемо
    document_type: 'action:approve_invoice',
    document_name: 'invoice#1024',
});
```

После verify контроллер ищет соответствующую `SignedAction` по `payload_hash`, проставляет `signature_id = $signature->id`, и только после этого выполняет побочное действие (помечает счёт одобренным). Привязка по хешу — то, что мешает атакующему переиспользовать PKCS#7, полученный для одного действия, против другого.

---

## 8. Жизненный цикл: mobile (ID-CARD через NFC)

```
Браузер             Laravel             E-IMZO-SERVER          Mobile ID-CARD app
  │ POST /mobile/auth/start │                                            │
  │ ──────────────────────► │                                            │
  │                          │ Server::mobileAuth() (пустое тело)         │
  │                          │ ─────────────────────────────────►         │
  │                          │ ◄─────────────────────────────────         │
  │                          │   {status:1, siteId, documentId, challange}│
  │ ◄─────────────────────── │                                            │
  │  {site_id, document_id, challenge}                                   │
  │                                                                      │
  │ Сборка QR payload:                                                   │
  │   site_id + document_id + GOST(challenge) + crc32(...)               │
  │                                                                      │
  │ Пользователь сканирует QR → ID-CARD app читает challenge → запрашивает PIN
  │                                              ─────────────►          │
  │                                                                      │
  │                                            ID-CARD app шлёт PKCS#7   │
  │                                            на /frontend/mobile/upload │
  │                          ◄───────────────────────────────────────── │
  │                          │ Server::mobileUpload(rawBody)              │
  │                          │ ─────────────────────────────────►         │
  │                          │  (Java сохраняет pkcs7 в Redis по documentId)
  │                                                                      │
  │ POST /mobile/auth/status (раз в 1.5с)                                │
  │ ──────────────────────► │                                            │
  │                          │ Server::mobileStatus(documentId)           │
  │                          │ ◄────────────                              │
  │                          │   {status: 1|2|<отрицательное>}            │
  │                          │   1 = готово, 2 = ожидание, <0 = ошибка    │
  │ ◄─────────────────────── │                                            │
  │  {status: 2}                                                         │
  │ ...                                                                  │
  │  {status: 1}                                                         │
  │                                                                      │
  │ POST /mobile/auth/complete                                           │
  │ ──────────────────────► │                                            │
  │                          │ Server::mobileAuthenticate(documentId)     │
  │                          │ ◄────────────                              │
  │                          │   {status:1, subjectCertificateInfo:{...}} │
  │                          │ Pkcs7Parser + upsert серта + login         │
  │ ◄─────────────────────── │                                            │
  │  {status:1, redirect, ...}                                           │
```

Формат QR-payload (`site_id + document_id + GOST(text) + crc32(...)`) реализован в `resources/js/eimzo-mobile.js::makeQrPayload()`. Он ожидает глобальный `GostHash` — этот модуль **не входит** в поставляемые vendor-скрипты, подключите `gost-hash.js` отдельно (образец — `test.e-imzo.uz/demo/eimzoidcard`). Без него используется SHA-256-fallback, который реальное ID-CARD-приложение не примет.

---

## 9. Инварианты безопасности, на которые опирается пакет

| # | Инвариант | Где обеспечивается |
|---|---|---|
| 1 | Подписанный challenge можно верифицировать только **один раз** | `eimzo_challenges.used_at`, выставляется в `EimzoAuthService::verifyChallenge()` |
| 2 | Подписанный challenge истекает по TTL | `eimzo_challenges.expires_at`, по умолчанию 120с |
| 3 | PKCS#7 должен встраивать **те же** байты challenge, что были выпущены | `EimzoAuthService::verifyChallenge()` сравнивает `$payload['payload']['challenge']` с записью |
| 4 | Подписной сертификат должен быть валиден на момент проверки против гос-PKI | E-IMZO-SERVER `/backend/auth` и `/backend/pkcs7/verify/*` (локальному парсингу для проверки доверия мы не верим) |
| 5 | `INN` / `TIN` — колонка поиска; `UID` — **не** допустимый fallback | `Pkcs7Parser::buildInfo()` и `EimzoAuthService::resolveUser()` |
| 6 | Браузер может звать desktop-клиент только с хоста, который есть в списке API-ключей | Desktop-клиент (CAPIWS), при этом пакет отдаёт лишь совпадающую пару |
| 7 | PKCS#7, созданному в браузере, не доверяем, пока его не подтвердил E-IMZO-SERVER | `EimzoSignService::store()` — verify-ответ должен быть `status:1`, иначе строка помечается `STATUS_INVALID` и кидается `VerificationFailedException` |
| 8 | Привязка action-payload через `payload_hash` | Таблица `signed_actions` потребителя (рекомендуемый паттерн, пакет не навязывает) |

Нарушение любого из этих инвариантов превращает обычно безопасный поток в подделываемый — поэтому расширяйте пакет, а не обходите его.

---

## 10. Куда дальше

- **Сначала сконфигурировать**: [CONFIG.md](CONFIG.md)
- **Рецепт интеграции в CRM**: [INTEGRATION.md](INTEGRATION.md)
- **Сквозные примеры** (login, document, action, mobile): [EXAMPLES.md](EXAMPLES.md), [USAGE.md](USAGE.md)
- **Оригинальная справка по протоколу**: <https://github.com/qo0p/e-imzo-doc>
- **Карта исходников пакета**:
  - JS-мост: `resources/js/eimzo.js`, `resources/js/eimzo-mobile.js`
  - Vendor-SDK: `resources/js/vendor/e-imzo.js`, `resources/js/vendor/e-imzo-client.js`
  - Серверный клиент: `src/Services/EimzoServerClient.php`
  - Поток auth: `src/Services/EimzoAuthService.php`
  - Поток sign: `src/Services/EimzoSignService.php`
  - Парсер PKCS#7: `src/Services/Pkcs7Parser.php`, `src/Support/X500NameParser.php`
  - Реестр API-ключей: `src/Support/ApiKeyRegistry.php`
