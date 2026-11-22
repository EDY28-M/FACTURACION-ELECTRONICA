# Findings — Security

Branch `chore/system-audit`. Static review only. Secret values are never reproduced; locations only.

## Coverage
- Pattern sweeps (app/, modules/, resources/, config/, routes/; vendor/public/storage excluded). Hit counts:
  - `{!! !!}` 3209 (1128 files under CoreFacturalo/Templates; the non-template hits are paginators, config names, tagline fields); `v-html` 1 (changelog from repo CHANGELOG.md, trusted).
  - `eval(` 2 (vendor theme JS only); `unserialize` 0; shell/proc_open/popen 0; `Process::` ~10 (System UpdateController/BackupController/HomeController, all fixed strings or sanitised; ChangelogGen is a console command); `extract($` 0 real (Padron archive helper); `phpinfo` 0; `preg_replace /e` 0.
  - `->all()` into create/update/fill: 55 (sampled Item, Company, Establishment, Order, User); `$guarded = []`: 0.
  - TLS verification disabled: 10 hits (5 real). `md5/sha1`: no password use (GreClient cache key, DemoSeed only).
  - Downloads/readers with path params: PaymentFileController, SaleOpportunityFileController, BackupController, `temp_path` consumers (9 files).
  - Open redirects with request input: 0. CSRF: `VerifyCsrfToken::$except` is empty.
- Files read fully: EcommerceController, CulqiController (already hardened), UpdateController, System/Tenant CertificateController, Tenant CompanyController::uploadFile, Ecommerce ConfigurationController::uploadFile, Tenant ConfigurationController::uploadFile/generateDispatch, DownloadController, SearchController, MobileController::login, Handler, routes/api.php, EnforceModuleAccess/AccessMap::decide, docker/nginx/default.conf.
- Dependency audits: `composer audit --locked` (run in `facturador_fpm`): 5 advisories, all in `laravel/framework` 10.50.4 (1 high, 1 medium, 2 low, 1 unrated); `laravelcollective/html` abandoned. `npm audit --package-lock-only`: 170 vulnerabilities = 20 critical, 73 high, 61 moderate, 16 low (almost all build/dev-time transitive; direct: axios, lodash, moment, chart.js, vue-chartjs, canvas, laravel-mix, watch = high; jquery, xml2js = moderate).
- Not reviewed: the 1,380 PDF/XML templates beyond pattern scan, Hotel/Offline/BusinessTurn modules, all Excel import classes, the 196 `$request->all()` call sites beyond sampling, SQLi/route-structure (other auditors).

## Findings (ordered by severity)

### SEC-001 — Storefront self-registration yields a back-office session and an API token
- Category: idor
- Severity: critical
- Confidence: CONFIRMED (code path); exploitation breadth depends on the ecommerce module being enabled
- Auto-fix: RISKY (touches registration/login/permission flow)
- Location: modules/Ecommerce/Http/Controllers/EcommerceController.php:148 (storeUser), app/Http/Controllers/Tenant/Api/MobileController.php:31 (login), routes/api.php:9 (auth:api group), app/Http/Middleware/EnforceModuleAccess.php + app/Support/Access/AccessMap.php:82-105 (decide)
- Attacker and path: anonymous visitor. `POST /ecommerce/storeUser` creates a row in the same tenant `users` table (type `client`, module 10, random api_token) with no validation. The same credentials then work on (a) the web guard: module gating returns allowed for any path that is unmapped, has module null, or is an AJAX call to a rule without `'ajax' => true` (most rules, e.g. `documents`, `items`, `sale-notes`), so JSON endpoints under `auth` are reachable with `X-Requested-With`; (b) `POST /api/login`, which returns `api_token`, accepted by every `auth:api` route (create documents/sale notes/voided/dispatches/items/persons). No middleware inspects `users.type = 'client'`.
- Evidence:
```
$user->type = 'client'; $user->api_token = str_random(50); $user->password = bcrypt($request->pswd);
...
'token' => $user->api_token,           // MobileController::login, any user
if ($rule['module'] === null) return [... 'reason' => 'open'];
if ($ajax && !$rule['ajax'])  return [... 'reason' => 'ajax-not-enforced'];
```
- Proposed fix: reject `type === 'client'` in `auth:api` routes and in the module gate (deny-by-default for clients except `ecommerce/*`); never issue `api_token` to storefront users (or use a separate table/guard); validate `storeUser` input. Verification: register a customer on a test tenant and assert 403 on `documents/records` (AJAX) and `POST /api/login`.

### SEC-002 — `temp_path` from the request is read with file_get_contents (arbitrary local file read)
- Category: file-handling
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (replace with server-side token/registry of temp files; client contract keeps the same field name)
- Location: app/Http/Controllers/Tenant/ItemController.php:179-193 (store), ItemSetController.php:114, PromotionController.php:80, modules/Finance/Traits/FilePaymentTrait.php:19, modules/Sale/.../SaleOpportunityFileController.php:24, app/CoreFacturalo/Requests/Inputs/DocumentInput.php:432, modules/Document/.../DocumentController.php:246, System/ClientController.php:190,279 (pfx read)
- Attacker and path: any tenant user (or SEC-001 customer, or API client via DocumentInput). The upload endpoint returns a server temp path which the client echoes back on save; the server never checks it is the file it issued. Setting `temp_path` to `/var/www/html/.env`, a PEM under storage/app/certificates, or another tenant's file makes the server copy it into `public/uploads/items` (item image) or the payment/sale-opportunity attachment dir, from where it is downloadable.
- Evidence:
```
$temp_path = $request->input('temp_path');
$file_content = file_get_contents($temp_path);
Storage::put($directory.$file_name, $file_content);
```
- Proposed fix: accept only paths whose realpath is inside `sys_get_temp_dir()` and whose basename was issued in this session (store issued basenames in the session), or accept an opaque id. Verification: send `temp_path=/etc/hostname` to items store; expect 422.

### SEC-003 — Client-controlled extension on uploaded images allows `.php` polyglot in a web-served directory
- Category: file-handling
- Severity: high
- Confidence: CONFIRMED (extension control); execution PLAUSIBLE (nginx `location ~ \.php$` passes any `.php` under public/, and `public/storage` symlinks storage/app/public; tenant disk root mapping not verified)
- Auto-fix: SAFE
- Location: ItemController.php:179-189 (extension from `$request->input('image')` split on '.'), Tenant/CompanyController.php:71, Ecommerce/ConfigurationController.php:97, Tenant/EstablishmentController.php:62, Tenant/ConfigurationController.php:362, Tenant/LoginConfigurationController.php:27, FilePaymentTrait.php:23-27 (extension from `filename`)
- Attacker and path: authenticated tenant user. Laravel `mimes:` validates the guessed (content) type, but the stored name uses `getClientOriginalExtension()` / the request string. A valid PNG with appended `<?php ...` uploaded as `x.php` passes validation and is stored as `.php` under `public/uploads/...`, reachable at `/storage/uploads/...php`.
- Evidence:
```
$ext = $file->getClientOriginalExtension();
$name = $type.'_'.$company->number.'.'.$ext;
$file->storeAs('public/uploads/logos', $name);   // after mimes:png,jpg validation
```
- Proposed fix: use `$file->extension()` (content-derived) or a fixed map, and for the item flow derive the extension from the stored temp file's MIME, never from request strings; add `location ~* ^/storage/.*\.php$ { deny all; }` in docker/nginx/default.conf. Verification: upload polyglot named `a.php`; stored name must end `.png`.

### SEC-004 — Path traversal and arbitrary attribute write in Tenant CompanyController::uploadFile / Ecommerce uploadFile via `type`
- Category: file-handling
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (whitelist `type`)
- Location: app/Http/Controllers/Tenant/CompanyController.php:62-98; modules/Ecommerce/Http/Controllers/ConfigurationController.php:92-105; route `companies/uploads` has `module => null` in config/permissions.php:159 (open to every authenticated user)
- Attacker and path: any authenticated user incl. SEC-001 customers. `$type` is concatenated into the file name; for types other than logo/logo_store/favicon/img_firm there is no validation at all and the file lands in `certificates/`. `type=../public/uploads/logos/x` normalises (Flysystem 3 only blocks escaping the root) to the public dir, with client extension (see SEC-003). Then `$company->$type = $name` assigns an attacker-named column (SQL error after the file is already written; with valid column names it overwrites e.g. soap/GRE settings with a filename).
- Evidence:
```
$name = $type.'_'.$company->number.'.'.$ext;
$file->storeAs(($type === 'img_firm') ? 'public/uploads/firms' : 'certificates', $name);
$company->$type = $name;
```
- Proposed fix: `in_array($type, ['logo','logo_store','favicon','img_firm'], true)` (else 422), always validate the file for every branch, build the name from the whitelist only. Verification: `type=../x` returns 422.

### SEC-005 — Unauthenticated customer directory (`api/documents/search/customers`)
- Category: idor
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (move inside the `auth:api` group; confirm the front-end calls it from an authenticated page) — RISKY if an anonymous public page uses it
- Location: routes/api.php:44; app/Http/Controllers/Tenant/DocumentController.php:99-125 (searchCustomers)
- Attacker and path: anonymous visitor, `GET /api/documents/search/customers?input=` (LIKE `%%` returns all) returns name, document number, address, addresses, state. The `where like OR where like AND type...` has no grouping, so `number like` matches suppliers too. Only the generic api throttle (60/min) applies.
- Evidence: `Person::where('number','like',"%{$request->input}%")->orWhere('name','like',...)->whereType('customers')...` outside `auth:api`.
- Proposed fix: add `auth:api`; group the OR; cap results. Verification: anonymous curl returns 401.

### SEC-006 — Unauthenticated `api/sendserver/{id}` pushes any document to the central server
- Category: idor
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (may be called by an external integrator)
- Location: routes/api.php:52; Tenant/DocumentController.php:592 (sendServer)
- Attacker and path: anonymous visitor iterates `document_id`; each call posts the document JSON, signed XML and PDF (base64) to the configured server with the Bearer token and flips `send_server`. A missing id gives an unhandled null dereference (500 with message, see SEC-008).
- Proposed fix: move into `auth:api` (or require a shared secret); `find` -> `findOrFail`.

### SEC-007 — Unauthenticated `api/configurations/generateDispatch` writes a PDF at a request-controlled path
- Category: file-handling
- Severity: medium
- Confidence: CONFIRMED (reachability and traversal); impact limited to `.pdf` files with fixed content
- Auto-fix: SAFE (auth + whitelist against existing template directories)
- Location: routes/api.php:53; Tenant/ConfigurationController.php:29-125
- Attacker and path: anonymous visitor posts `base_pdf_template` with `../` segments that still resolve to an existing `style.css`; `Storage::put('preprintedpdf/'.$request->base_pdf_template.'.pdf', ...)` then writes/overwrites a `.pdf` anywhere under the storage root and the raw input is echoed back as the HTML response (reflected). Also unauthenticated CPU-heavy mPDF rendering.
- Proposed fix: authenticate; `abort_unless(in_array($tpl, scandir(...)))`; return `response()->json`.

### SEC-008 — Global error handler returns exception message, absolute file path and line to every JSON client
- Category: info-leak
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (return generic message unless `config('app.debug')`)
- Location: app/Exceptions/Handler.php:114-127 (errorResponse), used for every non-HTML (ajax/api) request at :70-72
- Attacker and path: anyone triggering a 500 on an AJAX/API route (anonymous on public routes such as `ecommerce/item/999999`, `documents/search/customers`, `sendserver/0`) receives SQL text, class messages and server paths, independent of APP_DEBUG. Many controllers also return `$e->getMessage()` (~hundreds of sites).
- Evidence: `'message' => $message, 'file' => $file, 'line' => $line`.
- Proposed fix: drop `file`/`line`, and when `!config('app.debug')` return a fixed message for 5xx (keep validation/auth messages). Log with `report()`.

### SEC-009 — System admin deployment actions exposed as GET (CSRF) and run shell/Artisan
- Category: csrf
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (front-end calls them by GET; changing verb touches Vue + routes)
- Location: routes/web.php:645-652; app/Http/Controllers/System/UpdateController.php (pull, artisanMigrate, artisanTenancyMigrate, artisanClear, composerInstall)
- Attacker and path: any web page the logged-in system admin visits (a link or top-level navigation; `same_site` is null so browsers fall back to Lax, which permits top-level GET). Triggers `git checkout .` (discarding local changes) plus `git pull origin <branch>`, `migrate`, `config:cache`, `composer install` with `chmod -R 777` on vendor/mpdf. `chown -R ssh/` is also invalid/odd. Branch name is sanitised and argv-based, so no injection.
- Proposed fix: POST + CSRF token for these endpoints; drop `chmod 777`; consider removing in-app updating in production.

### SEC-010 — State-changing GET routes in the tenant app (CSRF)
- Category: csrf
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (Vue callers use GET)
- Location: routes/web.php:168-171 (items disable/enable/images delete), :209-230 (documents send/re_store/send_server/change_to_registered_status), :380-381 (purchases anular/delete), :407-449 (quotations/sale-notes state/anular/changed); modules/*/Routes (anular/voided/changed)
- Attacker and path: external page leads a logged-in tenant user to a top-level GET (SameSite null -> Lax allows it). Effects: delete purchase, void documents, send to SUNAT, change status.
- Proposed fix: convert to POST/DELETE with the existing axios CSRF header; set `same_site => 'lax'` explicitly and `SESSION_SECURE_COOKIE=true` in production.

### SEC-011 — laravel/framework 10.50.4 has open advisories (incl. high: CRLF injection in the email validation rule)
- Category: dependency
- Severity: medium
- Confidence: CONFIRMED (composer audit)
- Auto-fix: RISKY (fixes only exist on 12.x/13.x, i.e. a major framework upgrade)
- Location: composer.lock (laravel/framework v10.50.4)
- Evidence: advisories: CRLF injection in default email rule (high, <12.60/<13.10, also listed for 9-12 ranges, CVE-2026-48019), Temporary Signed URL path confusion (medium), XSS in debug page (low, CVE-2026-102279), Task output injection in Concurrency (low, CVE-2026-128083). Practical exposure: email rule used by storeUser-style forms and mail recipients; signed URLs unused if no `signed` middleware (not checked).
- Proposed fix: plan a Laravel 11/12 migration as its own project; interim, validate emails with a regex/`filter_var` and strip CR/LF before sending mail (`Mail::to`). Also replace abandoned `laravelcollective/html` with `spatie/laravel-html`.

### SEC-012 — npm tree: 20 critical / 73 high advisories (build-time and runtime)
- Category: dependency
- Severity: medium
- Confidence: CONFIRMED (npm audit)
- Auto-fix: RISKY (laravel-mix 1-4 / webpack 4 era toolchain; upgrades are breaking)
- Location: package-lock.json
- Evidence: runtime-relevant direct deps: axios (high), lodash (high), moment (high), chart.js/vue-chartjs (high), jquery (moderate), element-ui/vue (low). Critical entries are mostly dev-server/build transitives: @babel/traverse, elliptic, minimist, loader-utils, form-data, request, url-parse, tar, sockjs-client, webpack-dev-server, etc.
- Proposed fix: bump axios, lodash, moment, jquery within their major lines first (SAFE-ish, run the build + smoke test); treat toolchain upgrade as a separate task. Do not run `npm audit fix --force`.

### SEC-013 — TLS verification disabled on outbound calls carrying credentials and SUNAT traffic
- Category: ssrf
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE for Guzzle ones with a CA bundle present; RISKY for the SUNAT SOAP stream context (OSE/legacy endpoints may rely on it)
- Location: app/CoreFacturalo/WS/Client/WsClient.php:34 (`verify_peer => false`), app/Http/Controllers/Tenant/DocumentController.php:597,635 (Bearer to central server), modules/Services/Data/ServiceData.php:17 (Bearer API token), app/Console/Commands/SummarySendCommand.php:84 and SummaryQueryCommand.php:81, modules/Padron/.../PadronController.php:88
- Attacker and path: network-position attacker (MITM) reads the Bearer tokens and SOAP username/password, or alters responses (SUNAT CDR/ticket). The URLs come from config/DB (tenant admin settings `url_apiruc`), not from unauthenticated input, so SSRF itself is limited to tenant admins.
- Proposed fix: remove `verify => false`; point Guzzle `verify` at the system CA bundle; allow-list host for `url_apiruc`.

### SEC-014 — SVG uploads are served same-origin (stored XSS) and ecommerce user data endpoints take unvalidated rating/user fields
- Category: xss
- Severity: low
- Confidence: PLAUSIBLE
- Auto-fix: SAFE (drop svg from the allowed list or sanitise; set `Content-Disposition`/CSP on /storage)
- Location: Tenant/ItemController.php:406 (`jpg,jpeg,png,gif,svg`), CompanyController.php:76,81, ConfigurationController.php:365, UploadFileHelper default mimes include svg
- Attacker and path: a seller uploads an SVG with `<script>`; a victim who opens the `/storage/uploads/...svg` URL directly runs it in the app origin (session theft). Images inside `<img>` are not executed.
- Proposed fix: add header `Content-Security-Policy: default-src 'none'; sandbox` for `/storage/` in nginx, or disallow svg.

### SEC-015 — Hard-coded credential material in source
- Category: secrets
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/CoreFacturalo/Services/Dni/Jne.php:21 (`$requestToken`, a long third-party bearer-style token), database/seeds/DatabaseSeeder.php:17-18 (default system admin with a trivial password), app/CoreFacturalo/Facturalo.php:970 (SUNAT public beta password, public by SUNAT design, not sensitive)
- Attacker and path: anyone with repo access; the seeder admin account works on any install seeded with `db:seed` (the one-command installer uses ADMIN_PASSWORD from the environment instead).
- Proposed fix: move the JNE token to config/env; make the seeder read the password from env or generate one and print it once.

### SEC-016 — `api_token` is stored in clear, not hidden from serialisation, and embedded in pages
- Category: secrets
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (add to `$hidden`; keep `UserResource` for the admin UI deliberately) / RISKY for hashing tokens (mobile app contract)
- Location: app/Models/Tenant/User.php:33 (`$hidden` lacks api_token); resources/views/tenant/orders/index.blade.php:4 and documents/note.blade.php:5 (`json_encode(auth()->user())`); UserResource/UserCollection expose all tokens to admins
- Attacker and path: XSS or shoulder-surf gets the user's bearer; the token never rotates and never expires.
- Proposed fix: hide the attribute, pass only needed fields to Vue, provide a regenerate action.

### SEC-017 — Storefront auth hygiene: no throttling, no input validation, error text returned
- Category: info-leak
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: EcommerceController.php:121 (login, no `throttle`), :148 (storeUser: no validation on email/name/pswd, returns `$e->getMessage()`), :354 (saveDataUser without `auth`, null deref); `api/login` has only the generic throttle
- Attacker and path: anonymous credential stuffing against customers and back-office users via `ecommerce/login` (shares the users table with staff).
- Proposed fix: `throttle:5,1` on login routes, `Validator` (email, min password), generic error strings, `auth` middleware on saveDataUser.

### SEC-018 — Unauthenticated public routes with side effects or class-from-URL
- Category: dangerous-sink
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (validate date; whitelist model names)
- Location: routes/web.php:19 (`exchange_rate/ecommence/{date}`: unvalidated string creates rows in exchange_rates and triggers an outbound request), DownloadController.php:14,45 (`"App\\Models\\Tenant\\".ucfirst($model)` from the URL; any model class lacking `external_id` returns a 500 with SQL text; `format` triggers PDF regeneration)
- Attacker and path: anonymous visitor; DB growth, outbound traffic, model enumeration, exception leak. Document access itself is gated by a UUID `external_id` (acceptable capability link).
- Proposed fix: `Carbon::createFromFormat` validation; `in_array($model, [...])`.

### SEC-019 — Session/cookie and environment defaults
- Category: secrets
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (config only) but verify behind-proxy HTTPS first
- Location: config/session.php:167,195 (`secure` defaults false, `same_site` null); `.env.example` ships `APP_ENV=local`, `APP_DEBUG=true`, `PASSWORD_CHANGE=true`
- Attacker and path: an operator copying `.env.example` to production exposes debug pages (Laravel XSS-in-debug advisory applies). `http_only` is true.
- Proposed fix: default `same_site=lax`, `SESSION_SECURE_COOKIE=true` documented; use production-safe defaults in `.env.example`.

### SEC-020 — Temp files from uploads/printing are never removed
- Category: file-handling
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: ItemController.php:430, FilePaymentTrait/PaymentFileController.php:76, SaleOpportunityFileController.php:80, DownloadController.php:60 (`tempnam` + never unlink; toPrint reachable anonymously with a valid external_id)
- Attacker and path: a user repeatedly uploads/prints to fill /tmp. Also compounds SEC-002 (temp paths are guessable by prefix).
- Proposed fix: `deleteFileAfterSend(true)`, unlink after store.

## Appendix — PLAUSIBLE / unverified pattern hits
- PDF templates render `{!! $row->item->description !!}`, `$document->term_condition`, `$row->name_product_pdf` unescaped (≈2,000 uses). Output is mPDF (not browser HTML), so no XSS, but attacker-controlled HTML (seller or API client) can trigger mPDF remote fetches (`<img src=http://internal>`) = blind SSRF and layout abuse. mPDF 8.3.1; no `allowedLocalDirectories` config found. Confidence PLAUSIBLE, medium-low.
- `Storage::disk('tenant')->download('payment_files/'.$type.'/'.$filename)` and `sale_opportunity_files/<filename>` (route params; Flysystem blocks escaping the root but `type=..` reaches other top-level files of the same tenant disk). PLAUSIBLE, low; whitelist `type` to `document|sale_note`.
- Mass assignment: `Item::fill($request->all())`, `Company::fill($request->all())`, `Establishment::fill($request->all())` with wide `$fillable` (Company includes user_id, soap settings). Authenticated and mostly admin-scoped; no concrete privilege escalation traced. `User` fillable includes `type`, but `UserController::store` sets fields explicitly and `UserRequest` is used.
- Ecommerce `item($id)` / `partialItem($id)` expose any item (not only `apply_store=1`) and 500 on unknown id; `transactionFinally` lets the buyer set `document_external_id` to any string; `ratingItem` value unvalidated. Low.
- System-side: ClientController `temp_path` pfx read and BackupController::download (`auth:admin` only), `logs` LogViewer route (`auth:admin`) shows secrets that may be logged. Not traced further.
- Culqi/PayPal flow (CulqiController, EcommerceCheckout, CartPricing) re-checked: totals recomputed server side, secret encrypted via EncryptedSecret cast, `transactionFinally` ownership check present. No finding.

## Overflow themes
- Hundreds of `catch (Exception $e) { return ['message' => $e->getMessage()] }` sites (same class as SEC-008).
- The AccessMap default-allow for unmapped paths and `ajax-not-enforced` rules is a systemic gate weakness (see SEC-001); recommended to deny by default for `client` users.
