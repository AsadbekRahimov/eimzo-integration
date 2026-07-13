# E-IMZO Module — To'liq Texnik Qo'llanma

Bu hujjat modul **ichidan qanday ishlashi**ni qadamma-qadam tushuntiradi:
foydalanuvchi tugmani bosgandan, server bazaga `eimzo_signatures` qatorini
yozgungacha bo'lgan butun zanjirni.

---

## 1. Umumiy arxitektura

E-IMZO yagona dastur emas — bu **uchta alohida jarayon**ning hamkorligi:

```
   ┌──────────────────────────┐         ┌──────────────────────────┐         ┌────────────────────────┐
   │  Foydalanuvchi brauzeri  │         │   Sizning Laravel app    │         │   E-IMZO-SERVER (Java) │
   │                          │         │   (bu modul)             │         │                        │
   │  - HTML / JS             │         │  - challenge issuer      │         │  - PKCS#7 verifier     │
   │  - vendor/e-imzo.js      │  HTTPS  │  - signature storage     │  HTTP   │  - OCSP responder      │
   │  - EimzoBridge.js        │ ──────► │  - user authentication   │ ──────► │  - TSA timestamp       │
   │  - CAPIWS (WebSocket)    │  JSON   │  - cert metadata         │  text   │  - VPN to gov PKI      │
   │           │              │         │           │              │         │                        │
   └───────────┼──────────────┘         └───────────┼──────────────┘         └────────────┬───────────┘
               │                                    │                                     │
               │ wss://127.0.0.1:64443              │  SQLite/MySQL/Postgres              │  TLS+VPN
               ▼                                    ▼                                     ▼
   ┌──────────────────────────┐         ┌──────────────────────────┐         ┌────────────────────────┐
   │   E-IMZO.exe (desktop)   │         │   eimzo_challenges       │         │   PKI Technical        │
   │   foydalanuvchi PC sida  │         │   eimzo_certificates     │         │   Center (gov)         │
   │                          │         │   eimzo_signatures       │         │   vpn.e-imzo.uz:3443   │
   │   - keystore (.pfx)      │         │   users.tin/pinfl        │         │                        │
   │   - ID-card reader       │         │                          │         │                        │
   │   - private key (PIN)    │         │                          │         │                        │
   └──────────────────────────┘         └──────────────────────────┘         └────────────────────────┘
```

### Har bir komponent nima qiladi

| Komponent | Qayerda ishlaydi | Vazifasi |
|-----------|------------------|----------|
| **E-IMZO.exe** | Foydalanuvchi PC-sida | Maxfiy kalitni ushlab turadi, browser bilan WebSocket orqali (wss://127.0.0.1:64443) gaplashadi, PIN/parol so'raydi, **PKCS#7 imzo yaratadi** |
| **e-imzo.js + e-imzo-client.js** | Brauzerda | E-IMZO.exe ga WebSocket orqali qo'ng'iroq qiladigan SDK. `CAPIWS` global obyektini eksport qiladi. PKI Tech Center tomonidan ta'minlanadi |
| **EimzoBridge** (eimzo.js) | Brauzerda | Yuqorida turuvchi (high-level) Promise-based wrapper. Browserdan kelgan signaturani Laravel-ga POST qiladi |
| **Bu Laravel modul** | Sizning serveringizda | Challenge generatsiya qiladi, PKCS#7 ni saqlaydi, login qildiradi. **Maxfiy kalitni ko'rmaydi** — faqat imzolangan natijani qabul qiladi |
| **E-IMZO-SERVER** | Sizning serveringizda | Java JAR. Imzoni davlat PKI ga qarshi tekshiradi (OCSP), TSA timestamp qo'shadi. **VPN orqali davlatga ulanadi** |
| **Davlat PKI** | `vpn.e-imzo.uz` | Sertifikat haqiqiyligini tasdiqlash, blok ro'yxati (CRL/OCSP), TSA |

### Nima uchun shunday murakkab?

Asosiy g'oya: **maxfiy kalit hech qachon foydalanuvchi PC-sini tark etmasligi kerak**. Server faqat tayyor imzoni oladi va uni davlat PKI ga qarshi tekshiradi. Davlatga ulanish VPN talab qilgani uchun, oraliqda Java `e-imzo-server.jar` turadi — sizning Laravel app esa unga oddiy HTTP orqali murojaat qiladi.

---

## 2. Auth flow — login by signature

E-IMZO orqali foydalanuvchini tizimga kirish — bu **challenge-response** sxemasi. Parol o'rniga, foydalanuvchi serverga "men kalitni egallayman" degan dalil yuboradi.

### Sequence diagrammasi

```
Browser                  Laravel                   E-IMZO desktop          E-IMZO-SERVER
   │                        │                            │                       │
   │ 1. GET /eimzo/login    │                            │                       │
   │ ─────────────────────► │                            │                       │
   │ ◄───────────────────── │                            │                       │
   │   login.blade.php      │                            │                       │
   │                        │                            │                       │
   │ 2. eimzo.install()     │                            │                       │
   │ ─────────────────────────────────────────────────► │                       │
   │   wss://127.0.0.1:64443                             │                       │
   │   CAPIWS.apikey('localhost', '...')                 │                       │
   │ ◄───────────────────────────────────────────────── │                       │
   │   {success: true}                                   │                       │
   │                                                     │                       │
   │ 3. eimzo.listKeys()                                 │                       │
   │ ─────────────────────────────────────────────────► │                       │
   │   list_all_certificates                             │                       │
   │ ◄───────────────────────────────────────────────── │                       │
   │   [{CN, TIN, PINFL, ...}, ...]                      │                       │
   │                                                     │                       │
   │ 4. GET /eimzo/auth/challenge                        │                       │
   │ ─────────────────────► │                            │                       │
   │                        │  GET /frontend/challenge   │                       │
   │                        │ ─────────────────────────────────────────────────► │
   │                        │ ◄───────────────────────────────────────────────── │
   │                        │  {challenge: "<random>"}   │                       │
   │                        │  INSERT INTO               │                       │
   │                        │    eimzo_challenges        │                       │
   │                        │  (ttl = 120s)              │                       │
   │ ◄───────────────────── │                            │                       │
   │   {challenge}          │                            │                       │
   │                                                     │                       │
   │ 5. eimzo.signRaw(key, challenge, false)             │                       │
   │ ─────────────────────────────────────────────────► │                       │
   │   plugin: "pkcs7"                                   │                       │
   │   name: "create_pkcs7"                              │                       │
   │   args: [base64(challenge), keyId, "no"]            │                       │
   │   ◄── PIN dialog ──◄                                │                       │
   │ ◄───────────────────────────────────────────────── │                       │
   │   {success: true, pkcs7_64: "MIID..."}              │                       │
   │                                                     │                       │
   │ 6. POST /eimzo/auth/verify                          │                       │
   │   {challenge, pkcs7}   │                            │                       │
   │ ─────────────────────► │                            │                       │
   │                        │  EimzoAuthService          │                       │
   │                        │  ::verifyChallenge()       │                       │
   │                        │                            │                       │
   │                        │  1. Challenge tekshirish   │                       │
   │                        │     (mavjudmi, expirsdi?)  │                       │
   │                        │                            │                       │
   │                        │  2. Server::authenticate() │                       │
   │                        │ ────────────────────────────────────────────────► │
   │                        │   POST /backend/auth       │                       │
   │                        │   body: pkcs7              │                       │
   │                        │ ◄──────────────────────────────────────────────── │
   │                        │   {status:1, payload:{...}}│                       │
   │                        │                            │                       │
   │                        │  3. Pkcs7Parser::parse()   │                       │
   │                        │     openssl_pkcs7_read()   │                       │
   │                        │     extract X.509 subject  │                       │
   │                        │                            │                       │
   │                        │  4. resolveUser($info)     │                       │
   │                        │     SELECT * FROM users    │                       │
   │                        │     WHERE tin = ...        │                       │
   │                        │                            │                       │
   │                        │  5. UPDATE challenge       │                       │
   │                        │       SET used_at = NOW()  │                       │
   │                        │       WHERE used_at IS NULL│                       │
   │                        │     (atomik, 1 marta)      │                       │
   │                        │     UPSERT certificate     │                       │
   │                        │     INSERT signature       │                       │
   │                        │                            │                       │
   │                        │  6. Auth::login($user)     │                       │
   │ ◄───────────────────── │                            │                       │
   │  {status:1,            │                            │                       │
   │   redirect:"/dash",    │                            │                       │
   │   user:{id, name},     │                            │                       │
   │   certificate:{...}}   │                            │                       │
   │                                                     │                       │
   │ 7. window.location = redirect                       │                       │
```

### Kod-darajada aynan nima sodir bo'ladi

**1-qadam — frontendda challenge so'rash:**

```js
// asadbekrahimov/eimzo-integration/resources/js/eimzo.js -> EimzoBridge::login()
login(key) {
    return this.fetch(this.routes.challenge, { method: 'GET' })
        .then((res) => {
            // res.challenge = E-IMZO-SERVER bergan tasodifiy qator
            return this.signRaw(key, res.challenge, false);
        });
    // ...
}
```

`fetch('/eimzo/auth/challenge')` borib `EimzoAuthController::challenge()` ga keladi:

```php
// asadbekrahimov/eimzo-integration/src/Http/Controllers/EimzoAuthController.php
public function challenge(Request $request): JsonResponse
{
    $row = $this->auth->issueChallenge($request);
    return response()->json([
        'status' => 1,
        'challenge' => $row->challenge,           // E-IMZO-SERVER bergan qiymat
        'expires_at' => $row->expires_at->toIso8601String(),
        'ttl' => $row->meta['ttl'] ?? 120,
    ]);
}
```

`EimzoAuthService::issueChallenge()` avval E-IMZO-SERVER dan challenge oladi, keyin uni bazaga yozadi:

```php
// asadbekrahimov/eimzo-integration/src/Services/EimzoAuthService.php
public function issueChallenge(Request $request): EimzoChallenge
{
    // 1. E-IMZO-SERVER dan tasodifiy challenge so'raymiz (GET /frontend/challenge)
    $payload = $this->server->challenge($request->ip());
    $challenge = $payload['challenge'] ?? null;
    if (! is_string($challenge) || $challenge === '') {
        throw new EimzoServerException('E-IMZO-SERVER did not return a challenge', $payload);
    }

    // 2. Bir martalik ishlatish + TTL nazorati uchun lokal bazaga yozamiz
    return EimzoChallenge::issue(
        'auth',
        $request->ip(),
        substr((string) $request->userAgent(), 0, 512),
        ['server_payload' => $payload],
        $challenge
    );
}
```

Challenge server tomonida ham, lokal bazada ham esda saqlanadi — bu raqibga qarshi himoya. Hujumchi imzolangan boshqa hech qaysi narsani qayta yuborolmaydi, chunki har bir login uchun yangi challenge kerak va u bir marta ishlatilgach `used_at` bilan yopiladi.

**2-qadam — challenge ni imzolash:**

```js
// EimzoBridge::signRaw() -> CAPIWS WebSocket call
signRaw(key, data, detached) {
    const data64 = utf8ToBase64(data);   // challenge → base64
    return this.loadKey(key).then((keyId) => new Promise((resolve, reject) => {
        global.CAPIWS.callFunction(
            { plugin: 'pkcs7', name: 'create_pkcs7', arguments: [data64, keyId, 'no'] },
            (event, data) => {
                if (data && data.success === true) {
                    resolve(data.pkcs7_64);    // base64-encoded PKCS#7
                }
            }
        );
    }));
}
```

Bu yerda muhim: `CAPIWS` (e-imzo.js dan) **WebSocket orqali** foydalanuvchining **lokal mashinasidagi** E-IMZO.exe ga ulanadi. Sizning serveringiz bu jarayonda ishtirok etmaydi. Foydalanuvchi PIN/parolni o'z PC-sida kiritadi va imzolangan PKCS#7 (base64) qaytadi.

**3-qadam — verify:**

```js
// Browser yana bizning serverga keladi
this.fetch('/eimzo/auth/verify', {
    method: 'POST',
    body: { challenge: "550e8400-...", pkcs7: "MIID..." }
});
```

Server tomonidan eng muhim metod — `EimzoAuthService::verifyChallenge()`:

```php
public function verifyChallenge(string $challenge, string $pkcs7Base64, Request $request): array
{
    // 1. Challenge ma'lumotlar bazasidan topiladi
    $row = EimzoChallenge::where('challenge', $challenge)
        ->where('purpose', 'auth')->first();

    if (! $row)            throw new VerificationFailedException('Unknown challenge');
    if ($row->isUsed())    throw new ChallengeExpiredException('Challenge already used');
    if ($row->isExpired()) throw new ChallengeExpiredException('Challenge expired');

    // 2. PKCS#7 ni E-IMZO-SERVER ga jo'natamiz tekshirish uchun
    $payload = $this->server->authenticate($pkcs7Base64, $request->ip());
    if (($payload['status'] ?? null) !== 1) {
        throw new VerificationFailedException(/* ... */);
    }

    // 3. Challenge ni atomik ravishda "ishlatilgan" deb belgilaymiz.
    //    Shartli UPDATE (WHERE used_at IS NULL) tufayli parallel replay
    //    so'rovlardan faqat bittasi yutadi (replay attack himoyasi).
    if (! $row->markUsed()) {
        throw new ChallengeExpiredException('Challenge already used');
    }

    // 4. Lokalda openssl yordamida certdan ma'lumot ajratamiz
    $info = $this->parser->parseSigner($pkcs7Base64);
    // $info = ['cn' => 'Asadbek Rahimov', 'tin' => '200000000', 'pinfl' => '...', ...]

    // 5. users jadvalidan tegishli foydalanuvchini topamiz
    $user = $this->resolveUser($info);    // WHERE tin = $info['tin']

    // 6. Sertifikat va imzoni saqlaymiz
    $certificate = EimzoCertificate::upsertFromSigner($info, $user?->id);
    $signature = EimzoSignature::create([
        'certificate_id' => $certificate->id,
        'document_type' => 'auth-challenge',
        'pkcs7' => $pkcs7Base64,
        'verification_status' => 'valid',
        // ...
    ]);

    // 7. Foydalanuvchini tizimga kiritamiz
    if ($user) {
        $this->auth->guard('web')->login($user, true);
    }

    return [/* ... */];
}
```

Eng muhimi — `$this->server->authenticate()` chaqiruvi. Bu `EimzoServerClient` ichida:

```php
// asadbekrahimov/eimzo-integration/src/Services/EimzoServerClient.php
public function authenticate(string $pkcs7Base64, ?string $clientIp = null): array
{
    // E-IMZO-SERVER ga POST: text body, headers: X-Real-IP, Host
    return $this->postRaw('/backend/auth', $pkcs7Base64, $clientIp);
}
```

Java backend (`e-imzo-server.jar`) PKCS#7 ni ochadi, sertifikatni davlat PKI ga (`vpn.e-imzo.uz`) jo'natadi, OCSP javobini oladi va `{status: 1, payload: {CN, TIN, PINFL, ...}}` qaytaradi.

### Replay attack-dan himoya

Diqqat qiling: har bir challenge **bir martagina** ishlatish mumkin (`used_at` ustun, shartli UPDATE orqali atomik band qilinadi) va **120 soniya** ichida (`expires_at`). Agar hujumchi imzolangan PKCS#7 ni ushlasa ham, qayta yuborolmaydi — hatto ikkita parallel so'rovdan ham faqat bittasi muvaffaqiyatli bo'ladi.

---

## 3. Sign flow — hujjatni imzolash

Hujjat imzolash — auth-ga juda o'xshash, lekin imzolangan ma'lumot challenge emas, sizning hujjatingiz (PDF, JSON, matn) bo'ladi va biz odatda **TSA timestamp** ham qo'shamiz.

### Sequence

```
Browser                       Laravel                  E-IMZO desktop      E-IMZO-SERVER
   │                             │                            │                  │
   │ 1. eimzo.sign(key, {                                      │                  │
   │      data: 'contract text',                               │                  │
   │      document_type: 'contract',                           │                  │
   │      attach_timestamp: true                               │                  │
   │    })                                                     │                  │
   │                                                           │                  │
   │ 2. signRaw -> CAPIWS create_pkcs7                         │                  │
   │ ─────────────────────────────────────────────────────────►│                  │
   │ ◄────────────────────────────────────────────────────────│                  │
   │   pkcs7_64                                                │                  │
   │                                                           │                  │
   │ 3. POST /eimzo/sign       │                                                  │
   │   {pkcs7, document_type,..}│                                                 │
   │ ─────────────────────────►│                                                  │
   │                            │  EimzoSignService::store()                      │
   │                            │                                                  │
   │                            │  3a. TSA timestamp                               │
   │                            │ ──────────────────────────────────────────────►│
   │                            │  POST /frontend/timestamp/pkcs7                 │
   │                            │ ◄──────────────────────────────────────────────│
   │                            │  pkcs7_with_timestamp                            │
   │                            │                                                  │
   │                            │  3b. PKCS#7 verify                              │
   │                            │ ──────────────────────────────────────────────►│
   │                            │  POST /backend/pkcs7/verify/attached            │
   │                            │ ◄──────────────────────────────────────────────│
   │                            │  {status: 1, payload: {...}}                     │
   │                            │                                                  │
   │                            │  3c. Pkcs7Parser - signer info                  │
   │                            │  3d. UPSERT certificate                          │
   │                            │  3e. INSERT signature (status=valid)             │
   │                            │  3f. Storage::put(/eimzo/signatures/N.p7)        │
   │ ◄─────────────────────────│                                                  │
   │  {status:1, signature:{id,│                                                  │
   │     verification_status,  │                                                  │
   │     certificate, ...}}    │                                                  │
```

### Kod

```js
// EimzoBridge::sign()
sign(key, options) {
    const detached = !!options.detached;
    return this.signRaw(key, options.data, detached).then((pkcs7) => 
        this.fetch('/eimzo/sign', {
            method: 'POST',
            body: {
                pkcs7,
                detached,
                data: detached ? base64(options.data) : undefined,
                document_type: options.document_type,
                document_name: options.document_name,
                attach_timestamp: options.attach_timestamp,
                meta: options.meta
            }
        })
    );
}
```

```php
// EimzoSignService::store()
public function store(array $input, Request $request): EimzoSignature
{
    $pkcs7 = $input['pkcs7'];
    $detached = (bool) ($input['detached'] ?? false);

    // 1. TSA timestamp (ixtiyoriy, lekin tavsiya qilinadi).
    //    TSA ishlamasa ham so'rov yiqilmaydi - xato meta.timestamp_error ga yoziladi.
    $pkcs7WithTs = null;
    if ($input['attach_timestamp'] ?? config('eimzo.sign.attach_timestamp')) {
        try {
            $tsResp = $this->server->timestampPkcs7($pkcs7, $request->ip());
        } catch (EimzoServerException $e) {
            $tsResp = ['status' => -2, 'message' => $e->getMessage()];
        }
        if (($tsResp['status'] ?? null) === 1) {
            $pkcs7WithTs = $tsResp['pkcs7b64'] ?? ($tsResp['payload']['pkcs7b64'] ?? null);
        }
    }

    // 2. To'liq tekshirish
    $verifyResp = $detached
        ? $this->server->verifyDetached($input['data'], $pkcs7WithTs ?? $pkcs7, $request->ip())
        : $this->server->verifyAttached($pkcs7WithTs ?? $pkcs7, $request->ip());

    $status = ($verifyResp['status'] ?? null) === 1 ? 'valid' : 'invalid';

    // 3. Sertifikat ma'lumotini ajratish + saqlash
    $info = $this->parser->parseSigner($pkcs7);
    $certificate = EimzoCertificate::upsertFromSigner($info, $input['user_id']);

    // 4. Imzoni bazaga yozish
    $sig = EimzoSignature::create([
        'user_id' => $input['user_id'],
        'certificate_id' => $certificate?->id,
        'document_type' => $input['document_type'],
        'document_name' => $input['document_name'],
        'document_hash' => hash('sha256', $rawData),
        'pkcs7' => $pkcs7,
        'pkcs7_with_timestamp' => $pkcs7WithTs,
        'verification_status' => $status,
        'verification_payload' => $verifyResp,
        // signable_type/signable_id - polymorphic relation
    ]);

    // 5. (ixtiyoriy) Faylga ham saqlash
    if (config('eimzo.sign.storage_disk')) {
        Storage::disk(...)->put('/eimzo/signatures/' . $sig->id . '.p7', $rawPkcs7);
    }

    return $sig;
}
```

### Polymorphic signable

`eimzo_signatures.signable_type` + `signable_id` — har qanday Eloquent modelga ulanishi mumkin:

```php
$contract = Contract::find(42);
$signature = $signService->store([
    'pkcs7' => $pkcs7,
    'document_type' => 'contract',
    'signable' => $contract,    // <-- bog'lash
], $request);

// keyinchalik:
$contract->signatures;          // hasMany morphTo
```

Buning uchun `Contract` modelida:
```php
public function signatures()
{
    return $this->morphMany(\AsadbekRahimov\EimzoIntegration\Models\EimzoSignature::class, 'signable');
}
```

---

## 4. Verify flow — mavjud imzoni tekshirish

Eng oddiy oqim — siz allaqachon bor bo'lgan PKCS#7 (boshqa joyda imzolangan, fayldan, email-dan, ...) ni tekshirmoqchisiz.

```js
const result = await eimzo.verify({ 
    pkcs7: 'MIID...',           // base64
    data: 'aGVsbG8='            // base64, faqat detached uchun
});
```

```php
// EimzoVerifyController -> EimzoVerifyService::verify()
public function verify(string $pkcs7Base64, ?string $dataBase64, Request $request): array
{
    $detached = is_string($dataBase64) && $dataBase64 !== '';

    // E-IMZO-SERVER ga jo'natish
    $payload = $detached
        ? $this->server->verifyDetached($dataBase64, $pkcs7Base64, $request->ip())
        : $this->server->verifyAttached($pkcs7Base64, $request->ip());

    // Lokal parser - server javob bermasa ham bizda signer info bo'ladi
    $signer = $this->parser->parseSigner($pkcs7Base64);

    return [
        'ok' => ($payload['status'] ?? null) === 1,
        'signer' => $signer,        // [cn, tin, pinfl, valid_from, valid_to, ...]
        'payload' => $payload,
    ];
}
```

`verify` `eimzo_signatures` ga **yozmaydi** — agar saqlash kerak bo'lsa `EimzoSignService::store()` ishlatiladi.

---

## 5. Pkcs7Parser — lokal sertifikat parser

`EimzoServerClient` davlat PKI ga ulanmasdan bizga PKCS#7 dan signer info ajratish kerak. Buning uchun `Pkcs7Parser` xizmati:

```php
// asadbekrahimov/eimzo-integration/src/Services/Pkcs7Parser.php
public function parseSigner(string $pkcs7Base64): array
{
    $der = base64_decode($pkcs7Base64);
    $pem = "-----BEGIN PKCS7-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PKCS7-----\n";

    $certs = [];
    if (! @openssl_pkcs7_read($pem, $certs)) {
        return $this->emptyInfo();
    }

    // PKCS#7 odatda bir nechta sertifikat saqlaydi: [signer, intermediate, root]
    foreach ($certs as $certPem) {
        $info = $this->buildInfo($certPem);
        if ($info['serial_number'] !== null) {
            return $info;     // birinchi imzolovchi
        }
    }
    return $this->emptyInfo();
}
```

`buildInfo()` har bir sertifikat ichidan quyidagilarni ajratadi:
- `serial_number` — uniq identifikator
- `cn` — Common Name (to'liq ism)
- `tin` — INN (tashkilot uchun)
- `pinfl` — jismoniy shaxs raqami
- `uid` — sertifikat UID
- `o` — tashkilot nomi
- `t` — lavozim
- `valid_from`, `valid_to` — amal qilish muddati
- `subject_dn` — to'liq subject string
- `certificate_pem` — to'liq PEM (auditing uchun)

Buning uchun openssl quyidagi funksiyalarni ishlatadi:
- `openssl_pkcs7_read` — PKCS#7 ichidan sertifikatlarni chiqarish (trust chainsiz)
- `openssl_x509_parse` — X.509 sertifikatdan ma'lumot olish
- `X500NameParser` — DN string `"C=UZ,CN=...,UID=..."` ni array ga aylantirish

> **Muhim**: bu parser **kriptografik tekshiruvni qilmaydi**. U faqat metadata-ni o'qiydi. Haqiqiy verify E-IMZO-SERVER javobiga qaraydi (status=1).

---

## 6. Database schema

### `eimzo_challenges`

| Ustun | Tip | Tushuntirish |
|-------|-----|--------------|
| id | bigint | PK |
| challenge | string(255) (unique) | Foydalanuvchi imzolaydigan tasodifiy qator (desktop: E-IMZO-SERVER challenge, mobil: DocumentID) |
| purpose | string(32) | `auth`, `mobile-auth`, `mobile-sign` |
| ip | string(45) | Challenge so'ralgan IP |
| user_agent | string(512) | Audit uchun |
| meta | json | Qo'shimcha ma'lumotlar |
| expires_at | timestamp | TTL (default 120s) |
| used_at | timestamp | Ishlatilgan vaqt (replay-attack himoyasi) |
| created_at, updated_at | timestamps | |

**Hayot sikli**: yangi qator `issue()` da yaratiladi → `verifyChallenge()` da o'qiladi va `used_at` belgilanadi → keyin tozalanadi (cron orqali yoki qoldiriladi audit uchun).

### `eimzo_certificates`

Sertifikatlar **serial_number** bo'yicha unikal. Bir foydalanuvchi har xil cert bilan kirsa, har biri uchun yangi qator.

| Ustun | Tip | |
|-------|-----|---|
| id | bigint | |
| user_id | bigint nullable | Sizning `users` jadvaliga best-effort bog'lanish |
| serial_number | string(64) unique | X.509 serial (HEX) |
| cn | string | Common Name (egasi to'liq ismi) |
| tin | string indexed | INN — tashkilot raqami |
| pinfl | string indexed | jismoniy shaxs raqami |
| uid | string | sertifikat UID |
| o, t | string | tashkilot, lavozim |
| country, email | string | qo'shimcha |
| valid_from, valid_to | timestamp | amal qilish muddati |
| subject_dn, issuer_dn | text | to'liq DN |
| certificate_pem | longtext | PEM (private kalit YO'Q!) |
| last_verify_payload | json | E-IMZO-SERVER javobi |
| last_verified_at | timestamp | oxirgi tekshirish vaqti |

**`upsertFromSigner()`** — agar shu serial bilan qator bor bo'lsa yangilash, yo'q bo'lsa yaratish. Bu bir foydalanuvchi har 100-marta login qilganda 100 ta qator yaratmasligi uchun.

### `eimzo_signatures`

Har bir imzo (login challenge ham, hujjat imzosi ham) bu yerda yashaydi.

| Ustun | Tip | |
|-------|-----|---|
| id | bigint | |
| signable_type, signable_id | morphs | Polymorphic — istalgan modelga (Contract, Order, ...) |
| user_id | bigint nullable | imzolovchi |
| certificate_id | bigint nullable | eimzo_certificates yozuviga best-effort bog'lanish |
| document_type | string(64) | `auth-challenge`, `contract`, ... |
| document_name | string | foydalanuvchiga ko'rinadigan nom |
| document_size | unsignedBigInt | bayt |
| document_hash | string indexed | SHA-256 hex |
| pkcs7 | longtext | base64 PKCS#7 |
| pkcs7_path | string | agar `storage_disk` ishlatilsa, yo'l |
| detached | boolean | attached/detached |
| pkcs7_with_timestamp | longtext | TSA bilan |
| signed_at | timestamp | imzolanganda |
| timestamp_at | timestamp | TSA timestamp vaqti |
| verification_status | string(32) | pending / valid / invalid / error |
| verification_payload | json | server javobi (audit) |
| verified_at | timestamp | |
| meta | json | qo'shimcha |

### `users` jadvalini kengaytirish

Agar siz E-IMZO orqali foydalanuvchi login qilishini xohlasangiz, `users` jadvalida `tin` (yoki `pinfl`) ustun bo'lishi kerak. Migration buni avtomat qo'shadi:

```sql
ALTER TABLE users
    ADD tin VARCHAR(32) NULL UNIQUE,
    ADD pinfl VARCHAR(32) NULL UNIQUE,
    ADD eimzo_serial_number VARCHAR(64) NULL,
    ADD eimzo_full_name VARCHAR(255) NULL,
    ADD eimzo_authenticated_at TIMESTAMP NULL;
```

`config/eimzo.php` da `auth.user_lookup_column` orqali qaysi ustun bo'yicha qidirish kerakligini sozlash mumkin (`tin`, `pinfl`, `uid`, va h.k.).

---

## 7. EimzoBridge — frontend SDK

`asadbekrahimov/eimzo-integration/resources/js/eimzo.js` da `EimzoBridge` class — eng yuqori darajadagi wrapper. U callback-jahannami orniga oddiy Promise-larni beradi.

### Asosiy metodlar

```js
const eimzo = new EimzoBridge({
    csrfToken: 'optional-override',     // odatda <meta name="csrf-token"> dan olinadi
    apiKeys: 'optional-override',       // domain authorization key
    routes: {                           // server endpointlari
        challenge: '/eimzo/auth/challenge',
        verify: '/eimzo/auth/verify',
        // ...
    }
});

// E-IMZO desktop client bilan ulanish + API key joylash
await eimzo.install();

// Mavjud sertifikatlarni ko'rsatish
const keys = await eimzo.listKeys();
// => [{name, CN, TIN, PINFL, validFrom, validTo, ...}, ...]

// Login qilish (challenge sign + verify + login)
const session = await eimzo.login(keys[0]);
// => {status:1, redirect:'/dash', user:{...}, certificate:{...}}

// Hujjat imzolash
const sig = await eimzo.sign(keys[0], {
    data: 'matn yoki PDF base64',
    document_type: 'contract',
    document_name: 'shartnoma.pdf',
    detached: false,
    attach_timestamp: true
});

// Existing PKCS#7 ni tekshirish
const result = await eimzo.verify({ pkcs7: '...', data: '...' });

// Logout
await eimzo.logout();

// Past darajadagi: faqat imzolash, serverga yubormasdan
const pkcs7Base64 = await eimzo.signRaw(keys[0], 'arbitrary text', false);
```

### CSRF tokens

Agar siz Laravel-ning `web` middleware-sini ishlatsangiz (default), POST requestlar uchun CSRF token kerak. EimzoBridge avtomatik:

1. `<meta name="csrf-token" content="...">` ni qidiradi
2. Topgan tokenni `X-CSRF-TOKEN` header-da yuboradi
3. Yo'q bo'lsa — `csrfToken` parametri orqali qo'lda berish mumkin

`layouts/app.blade.php` da bu meta tag avtomatik:
```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
```

### CAPIWS qatlami

`EimzoBridge` aslida **boshqa ikkita JS** ustida ishlaydi:

- `vendor/e-imzo.js` — Base64 utilities + `CAPIWS` (WebSocket low-level)
- `vendor/e-imzo-client.js` — `EIMZOClient` (orta darajadagi API)

Bu fayllarni o'zgartirmang — ular **PKI Tech Center**dan keladi va vaqti-vaqti bilan yangilanishi mumkin. Yangilash usuli: `https://github.com/qo0p/e-imzo-doc/tree/master/example.uz/php/demo` dan yangi versiyalarni olib `asadbekrahimov/eimzo-integration/resources/js/vendor/` ga ko'chirish.

---

## 8. Service Provider — barcha narsalarni bog'lash

`EimzoServiceProvider` Laravel-ga package mavjudligini ma'lum qiladi:

```php
public function register(): void
{
    // config/eimzo.php ni Laravel config bilan birlashtirish
    $this->mergeConfigFrom($this->path('config/eimzo.php'), 'eimzo');

    // Service singleton-larni IoC ga ro'yxatdan o'tkazish
    $this->app->singleton(Pkcs7Parser::class);
    $this->app->singleton(EimzoServerClient::class);
    $this->app->singleton(EimzoAuthService::class);
    $this->app->singleton(EimzoSignService::class);
    $this->app->singleton(EimzoVerifyService::class);
    $this->app->singleton(EimzoTimestampService::class);
}

public function boot(): void
{
    // Migration-larni avtomatik o'qish
    $this->loadMigrationsFrom($this->path('database/migrations'));

    // View-larni 'eimzo::' namespace bilan ro'yxatdan o'tkazish
    $this->loadViewsFrom($this->path('resources/views'), 'eimzo');

    // Routelar (web va api)
    $this->registerRoutes();

    // vendor:publish targetlari
    $this->registerPublishing();
}
```

Package `composer.json` ichidagi Laravel auto-discovery orqali bu provider odatda avtomatik ulanadi. Agar CRM loyihada package discovery o'chirilgan bo'lsa, Laravel 8/9/10 uchun `config/app.php` ichidagi `providers` ro'yxatiga `AsadbekRahimov\EimzoIntegration\Providers\EimzoServiceProvider::class` qo'shing. Laravel 11+ skeletonlarda esa provider `bootstrap/providers.php` orqali ro'yxatga olinadi.

---

## 9. Production checklist

### 1. E-IMZO-SERVER (Java backend)

Sizning Laravel-ingizning yonida `e-imzo-server.jar` ishlashi kerak.

```bash
# Test versiyasi (https://github.com/qo0p/e-imzo-doc da)
# Production uchun PKI Tech Center bilan VPN keys uchun gaplashing.

java -Dfile.encoding=UTF-8 -jar e-imzo-server.jar config.properties
```

`config.properties` minimumi:
```properties
listen.ip=127.0.0.1
listen.port=8080
vpn.connect.host=vpn.e-imzo.uz
vpn.connect.port=3443
vpn.key.file.path=/path/to/vpnkey.pkcs12
vpn.key.password=...
vpn.truststore.file.path=/path/to/truststore.jks
tsp.jks.file.path=/path/to/tsp.jks
```

Docker bilan ham mumkin — repodan misol:
```dockerfile
FROM amazoncorretto:8-alpine3.19-jre
COPY e-imzo-server.jar /app/
COPY config.properties /app/
COPY *.pkcs12 *.jks /app/
WORKDIR /app
CMD ["java", "-jar", "e-imzo-server.jar", "config.properties"]
```

### 2. API key

`config/eimzo.php` da:
```php
'api_keys' => \AsadbekRahimov\EimzoIntegration\Support\ApiKeyRegistry::normalize(
    env('EIMZO_API_KEYS', 'localhost=LOCAL_KEY;yourdomain.uz=YOUR_KEY')
),
```

`yourdomain.uz` uchun unikal kalit PKI Tech Center tomonidan beriladi. Localhost va 127.0.0.1 uchun publik test kalitlari ishlaydi.

### 3. HTTPS

Production-da albatta HTTPS, chunki:
- `wss://127.0.0.1:64443` uchun browser sizning sahifangizni `https://` ostida talab qiladi
- Cookie va CSRF token xavfsiz uzatish uchun

### 4. CSRF + CORS

Agar SPA + API ishlatsangiz `api/eimzo/*` route-ga `auth:sanctum` middleware qo'shing yoki CSRF dan ozod qiling — ko'rsatma `config/eimzo.php` `routes.api_middleware`.

### 5. Storage

`config/eimzo.php` `sign.storage_disk` ni production-ga moslang — S3 yoki MinIO qulay (raw PKCS#7 fayllar uchun).

### 6. Rate limiting

`/eimzo/auth/challenge` Throttle bilan o'rab qo'ying:
```php
// bootstrap/app.php yoki RouteServiceProvider
->withMiddleware(function (Middleware $m) {
    $m->throttleApi();   // o'zingizning limit
})
```

### 7. Cron tozalash

Eski challenge-larni tozalash uchun `routes/console.php` ga:
```php
use Illuminate\Support\Facades\Schedule;
use AsadbekRahimov\EimzoIntegration\Models\EimzoChallenge;

Schedule::call(fn () => EimzoChallenge::where('expires_at', '<', now()->subDay())->delete())->daily();
```

---

## 10. Kengaytirish — common scenariylar

### A. Auth lookup column-ni o'zgartirish

Agar siz `users.pinfl` orqali login qilmoqchi bo'lsangiz:
```env
EIMZO_USER_LOOKUP_COLUMN=pinfl
```
yoki `config/eimzo.php` da:
```php
'user_lookup_column' => 'pinfl',
```

### B. Auto-register

Agar serverda foydalanuvchi yo'q bo'lsa, avtomatik yaratish:
```env
EIMZO_AUTO_REGISTER=true
```

`EimzoAuthService::resolveUser()` boshqacha logika bilan o'rab olish uchun, service-ni o'zingizning class bilan almashtiring:

```php
// AppServiceProvider::register
$this->app->singleton(\AsadbekRahimov\EimzoIntegration\Services\EimzoAuthService::class, function ($app) {
    return new \App\Services\MyEimzoAuthService(/* deps */);
});
```

### C. Hujjatga imzo bog'lash (polymorphic)

```php
// app/Models/Contract.php
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;

class Contract extends Model
{
    public function signatures()
    {
        return $this->morphMany(EimzoSignature::class, 'signable');
    }
}
```

```php
// Controller
$contract = Contract::find($id);
$signature = app(\AsadbekRahimov\EimzoIntegration\Services\EimzoSignService::class)->store([
    'pkcs7' => $request->input('pkcs7'),
    'document_type' => 'contract',
    'document_name' => "contract-{$contract->id}.pdf",
    'signable' => $contract,
    'user_id' => $request->user()->id,
], $request);

// Keyinchalik:
$contract->signatures()->where('verification_status', 'valid')->count();
```

### D. Frontend-da xato hodisalarini ushlash

```js
try {
    await eimzo.install();
} catch (e) {
    if (e.message.includes('CAPIWS is not loaded')) {
        // vendor JS yuklanmagan
    } else if (e.message.includes('version too old')) {
        // foydalanuvchi E-IMZO ni yangilashi kerak
    } else {
        // E-IMZO.exe ishga tushmagan
    }
}

try {
    await eimzo.login(key);
} catch (e) {
    if (e.status === 410) {
        // challenge expired - qayta urinib ko'ring
    } else if (e.status === 422) {
        // verification failed
    } else if (e.status === 503) {
        // E-IMZO-SERVER ishlamayapti
    }
}
```

### E. Webhooks / Events

Agar imzolanganda biror narsa qilish kerak bo'lsa, Eloquent observer:

```php
// app/Observers/EimzoSignatureObserver.php
class EimzoSignatureObserver
{
    public function created(\AsadbekRahimov\EimzoIntegration\Models\EimzoSignature $sig): void
    {
        if ($sig->verification_status === 'valid' && $sig->document_type === 'contract') {
            event(new ContractSigned($sig));
        }
    }
}

// AppServiceProvider::boot
\AsadbekRahimov\EimzoIntegration\Models\EimzoSignature::observe(EimzoSignatureObserver::class);
```

---

## 11. Debugging

### `EIMZO not connected` — vendor SDK xatosi

```
CAPIWS is not loaded - include vendor/e-imzo.js before vendor/e-imzo-client.js
```

→ JS asset publish qilinmagan, yoki blade-da yuklanish tartibi noto'g'ri:
```bash
php artisan vendor:publish --tag=eimzo-assets --force
```

### `Challenge expired`

→ Foydalanuvchi PIN-ni 120 soniyadan ortiq vaqtda kiritdi. `config/eimzo.php` da `auth.challenge_ttl` ni oshirish mumkin.

### `E-IMZO-SERVER returned HTTP 503`

→ Java backend (`e-imzo-server.jar`) ishga tushmagan yoki VPN uzilgan. `EIMZO_SERVER_URL` ni tekshiring.

### `pkcs7 is not valid` (server javobi `status: -X`)

→ Sertifikat muddati o'tgan yoki bekor qilingan. `eimzo_certificates.last_verify_payload` da batafsil sabab.

### Lokal test (E-IMZO.exe siz)

`AuthFlowTest.php` da ko'rsatilganidek, `EimzoServerClient` ni mock qilib bo'ladi:
```php
$this->app->singleton(EimzoServerClient::class, fn () => new class extends EimzoServerClient {
    public function __construct() {}
    public function authenticate(string $p, ?string $ip = null): array {
        return ['status' => 1, 'message' => '', 'payload' => []];
    }
});
```

---

## 12. Asosiy URL-lar va manbalar

| URL | Tavsif |
|-----|--------|
| <https://github.com/qo0p/e-imzo-doc> | E-IMZO rasmiy hujjati |
| <https://test.e-imzo.uz/demo/> | Public demo (haqiqiy E-IMZO bilan ishlash misolllari) |
| <https://e-imzo.uz/> | E-IMZO desktop client yuklab olish |
| <https://pki.gov.uz/> | PKI Technical Center — VPN kalitlari va api_keys uchun |

---

## 13. Quick reference — service API

```php
use AsadbekRahimov\EimzoIntegration\Services\EimzoAuthService;
use AsadbekRahimov\EimzoIntegration\Services\EimzoSignService;
use AsadbekRahimov\EimzoIntegration\Services\EimzoVerifyService;
use AsadbekRahimov\EimzoIntegration\Services\EimzoTimestampService;
use AsadbekRahimov\EimzoIntegration\Services\Pkcs7Parser;
use AsadbekRahimov\EimzoIntegration\Services\EimzoServerClient;

// AUTH
$auth = app(EimzoAuthService::class);
$challenge = $auth->issueChallenge($request);                      // EimzoChallenge
$result = $auth->verifyChallenge($challenge->challenge, $pkcs7, $request);
$auth->logout();

// SIGN
$sign = app(EimzoSignService::class);
$signature = $sign->store([
    'pkcs7' => $pkcs7,
    'data' => $base64data,           // detached uchun
    'detached' => false,
    'document_type' => 'contract',
    'document_name' => 'a.pdf',
    'attach_timestamp' => true,
    'signable' => $eloquentModel,    // ixtiyoriy
    'user_id' => $userId,
    'meta' => ['ref' => 'X-123'],
], $request);

// VERIFY
$verify = app(EimzoVerifyService::class);
$check = $verify->verify($pkcs7, $optionalDataBase64, $request);

// TIMESTAMP
$ts = app(EimzoTimestampService::class);
$response = $ts->timestampPkcs7($pkcs7, $request);
$response = $ts->timestampData($base64data, $request);
$response = $ts->makeAttached($base64data, $detachedPkcs7, $request);
$response = $ts->join($pkcs7A, $pkcs7B, $request);

// PARSER (lokal, server-siz)
$parser = app(Pkcs7Parser::class);
$info = $parser->parseSigner($pkcs7);     // [cn, tin, pinfl, valid_to, ...]

// SERVER CLIENT (eng past daraja)
$server = app(EimzoServerClient::class);
$server->authenticate($pkcs7, $clientIp);
$server->verifyAttached($pkcs7, $clientIp);
$server->verifyDetached($data, $pkcs7, $clientIp);
$server->timestampPkcs7($pkcs7, $clientIp);
```

---

## 14. Models — quick reference

```php
use AsadbekRahimov\EimzoIntegration\Models\EimzoChallenge;
use AsadbekRahimov\EimzoIntegration\Models\EimzoCertificate;
use AsadbekRahimov\EimzoIntegration\Models\EimzoSignature;

// Challenge
$ch = EimzoChallenge::issue('auth', $ip, $userAgent, ['extra' => 'meta']);
$ch->isExpired();
$ch->isUsed();
$ch->markUsed();

// Certificate
$cert = EimzoCertificate::upsertFromSigner($parsedInfo, $userId);
$cert->user;          // BelongsTo
$cert->signatures;    // HasMany
$cert->isCurrentlyValid();
$cert->isExpired();

// Signature
$sig = EimzoSignature::find($id);
$sig->signable;       // morphTo - Contract, Order, ...
$sig->user;           // BelongsTo
$sig->certificate;    // BelongsTo
$sig->isValid();      // verification_status === 'valid'
```

---

Savollar yoki muammolar bo'lsa, README.md va source-dagi DocBlock comment-larga qarang. Modul to'liq IDE-friendly: PHPStorm/VSCode da har qaysi metodga `Cmd+Click` qilib ko'rsangiz, hujjat va kengaytirish nuqtalarini topasiz.
