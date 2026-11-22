# Findings — Modules: Account, Dashboard, Hotel, Ecommerce, BusinessTurn, DocumentaryProcedure, LevelAccess, Offline, Padron, Services

Audit mode: read-only. Branch `chore/system-audit`. No secret values are quoted; secrets are referred to by config/column name.

## Coverage

| Module | Fully read | Pattern-scanned only | Not reviewed (why) | Routes | Controller actions |
|---|---|---|---|---|---|
| Account | Routes, CompanyAccountController, System/AccountingController, FormatController, SummaryReportController, CompanyAccountRequest/Resource, Models, 1 Export; AccountController lines 1-330 | AccountController 330-790 (Concar/Siscont/Contasis row builders), 7 other Exports (identical 25-line wrappers), Providers | Blade templates (excel_*), Vue (other auditor) | 11 web + 1 api closure | 13 |
| Dashboard | Routes, DashboardController, DashboardData, TotalsTrait, DashboardUtility, DashboardStock + Collection | DashboardInsights (read data/ranges/documentRows/noteRows/receivables/purchasesYear; skipped bucket/weekday/hour helpers), DashboardSalePurchase (read 1-140; rest scanned), DashboardView (read 1-25, 150-230) | Vue | 10 web + 1 api closure | 9 |
| Hotel | Routes, Rent/Room/Reception controllers, all Requests used, HotelRoom/Rent/RentItem/RoomRate models | Rate/Floor/Category controllers (diffed: identical CRUD clones) | Blade views, Vue | 29 | 29 |
| Ecommerce | Routes, EcommerceController, CulqiController, ConfigurationController, ItemBarCollection, 5 view composers, ConfigurationEcommerceResource, middleware chain (LockedTenant, RedirectModule/EnforceModuleAccess, AccessMap), app/Services/Payments/{CartPricing,EcommerceCheckout,CulqiCharger} | EcommerceServiceProvider, ViewServiceProvider registrations | Blade (only cart/detail.blade.php excerpts read), JS assets | 19 web + 1 api closure | 25 |
| BusinessTurn | Routes, Controller, 3 Requests, 3 Models, ViewComposer | Providers | Vue | 7 | 7 |
| DocumentaryProcedure | Routes, DocumentaryFileController, DocumentaryOfficeController, DocumentaryProcedureController, FileRequest, OfficeRequest, DocumentaryFile/FileOffice models | Process/Document/Action controllers (pattern-scanned; same shape as Office), other Requests/Models | Blade, Vue | 25 | 25 (+6 scaffold methods) |
| LevelAccess | Routes, Controller, Models, ViewComposer | Providers | views | 1 | 1 (+6 scaffold) |
| Offline | All PHP (routes, controller, request, model, resource) | Providers | Vue | 3 | 3 |
| Padron | All PHP (routes, controller, trait, models) | Providers | none | 4 | 4 |
| Services | All PHP | Providers | none | 2 (web + api) | 1 |

Out of scope / not done: Vue/Blade UI correctness (other auditor), database access, runtime behaviour. `app/` was followed only to confirm findings (middleware, Payments services, User/Order/Document models, config/permissions.php, ViewServiceProvider, routes/api.php).

Reference to the removed `culqi/culqi-php` package: `app/Services/Payments/CulqiCharger.php` (see MOP-007). `vendor/culqi` is absent in this checkout.

## Findings (ordered by severity)

### MOP-001 — Public self-registration creates a user with the `ecommerce` admin module and a working API token
- Category: security
- Severity: critical
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes who can sign up and what a storefront buyer can reach; needs a decision on the "client" user model)
- Location: modules/Ecommerce/Http/Controllers/EcommerceController.php:148-185 (storeUser); modules/Ecommerce/Routes/web.php:30; config/permissions.php:60-70; config/auth.php:44 (api guard); routes/api.php:9-35
- Problem: `POST /ecommerce/storeUser` is public (only `locked.tenant`), has no validation, no rate limit, and creates a row in the same `users` table used by staff: `type='client'`, `establishment_id=1`, a random `api_token`, and `modules()->sync([10])`. Module id 10 is the `ecommerce` module (migration 2019_09_16_133219 seeds `id 10, value 'ecommerce'`). `config/permissions.php` gates the store back-office (`orders`, `items_ecommerce`, `tags`, `promotions`, `ecommerce/configuration*`, `ecommerce/uploads`, `ecommerce/record`, `statusOrder`) only by that module. `EnforceModuleAccess` never looks at `users.type`. The `api` guard is a plain `token` guard on `users`, with no type check, and `routes/api.php` exposes `documents`, `sale-note`, `items`, `person`, `services/*` to any valid token. The cart page even renders `json_encode(Auth::user())` (including `api_token`) to the browser (cart/detail.blade.php:507).
- Evidence:
```php
$user->type = 'client';
$user->api_token = str_random(50);
$user->save();
$user->modules()->sync([10]);   // 10 = 'ecommerce' back-office module
```
- Failure scenario: an anonymous visitor posts `name`, `email`, `pswd`, gets logged in (`Auth::attempt`), then opens `/ecommerce/configuration` and calls `POST /ecommerce/configuration_culqui` to replace the store's Culqi public/private keys with their own (card payments then settle to the attacker), reads/edits orders, uploads files via `/ecommerce/uploads`, and uses the returned `api_token` against `/api/documents`, `/api/sale-note`, `/api/items/{id}/update`. Also `bcrypt($request->pswd)` with a missing `pswd` creates an account with a hash of an empty string.
- Proposed fix: give storefront buyers no back-office module (sync `[]` or a dedicated non-admin module value with no rule in permissions.php), deny `type === 'client'` in `EnforceModuleAccess`/`auth:api` flow, do not issue an `api_token` to clients (or issue a token the API guard rejects by type), add `validate()` (name, unique email, password min 8) and `throttle:5,1` to `storeUser` and `login`. Run a one-off check for existing `type='client'` users that hold module 10.
- Verification idea: feature test registering a client, then asserting 403 on `/ecommerce/configuration_culqui`, `/orders/records`, and 401/403 on `POST /api/documents` with that token.

### MOP-002 — Padron routes are public, mutate state on GET and truncate the table before validating the download
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE for adding `auth` + tenant-domain wrapper + POST/permission; RISKY only if an external cron calls these URLs (no in-repo consumer found: no match in app/, resources/, routes/)
- Location: modules/Padron/Routes/web.php:14-20; modules/Padron/Http/Controllers/PadronController.php:70-147 (charges_data), 158-200 (demo)
- Problem: the whole `padron` prefix is registered in the bare `web` group (no `auth`, no `Route::domain`, no `locked.tenant`). `charges_data` and `demo` are GET, call `DB::table('padrones')->truncate()` and run `LOAD DATA LOCAL INFILE`. In `charges_data` the table is truncated (DDL, implicit commit, no transaction) before the result of `extract()` (stored in an unused `$data`) is checked; the download uses plain `http://`, `CURLOPT_SSL_VERIFYPEER=false`, no `CURLOPT_TIMEOUT`, and the whole zip is held in memory via `RETURNTRANSFER`; HTTP errors (404 HTML page) are written to disk as a "zip". `public/padron_rar` and `public/padron_extract` do not exist in the checkout, so `file_put_contents` fails with a warning. `catch(Exception $e)` refers to `Modules\Padron\Http\Controllers\Exception` (no `use Exception`), so it never matches. Error branches return raw PDO messages.
- Evidence:
```php
Route::prefix('padron')->group(function() {
    Route::get('charges_data', 'PadronController@charges_data');
    Route::get('demo', 'PadronController@demo');
...
DB::connection('tenant')->table('padrones')->truncate();
```
- Failure scenario: any anonymous GET to `/padron/demo` (or a link prefetcher/crawler) wipes the production padron and replaces it with demo rows; GET `/padron/charges_data` repeated in a loop makes the server download ~hundreds of MB each time and leaves `charge_padrones` rows with `state=0`. If the download or unzip fails the table is already empty and `Padron::consulta` returns "RUC inválido" for every RUC.
- Proposed fix: wrap in `Route::domain($hostname->fqdn)` + `['auth','redirect.module','locked.tenant']`, make `charges_data`/`demo` POST, download to a temp file with a timeout and `https`, verify HTTP 200 + zip validity + extract success before truncating, add `use Exception;`, return a generic error and log the detail. Remove `demo` from production.
- Verification idea: unauthenticated `GET /padron/demo` must return 302/401; test with a stubbed failing download asserting `padrones` is untouched.

### MOP-003 — Dashboard `globalData` loads every document and sale note (with 9 eager relations) on each page load
- Category: performance
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE for the `without()` mitigation (same numbers); RISKY for rewriting as SQL SUM (rounding parity)
- Location: modules/Dashboard/Helpers/DashboardData.php:303-362 (document_totals_globals), 162-199 (sale_note_totals_global); called from DashboardController.php:70 (buildBootstrap) and :99 (globalData)
- Problem: `Document::query()->get()` and `SaleNote::...->get()` with no date or establishment bound. `Document::$with` eager loads `user, soap_type, state_type, document_type, currency_type, group, items, invoice, note, payments` (app/Models/Tenant/Document.php:14), so every document row drags 9 relations, and everything is summed in PHP. `buildBootstrap()` runs this on every `GET /dashboard`.
- Evidence:
```php
$documents = Document::query()->get();
...
$document_total_payment_pen += collect($document->payments)->sum('payment');
```
- Failure scenario: a tenant with 200k documents hits memory_limit / multi-second TTFB on every dashboard open (the exception is swallowed by `buildBootstrap`'s catch, so the page silently falls back to XHR and calls it again).
- Proposed fix: minimal, same output: `Document::query()->without(['user','soap_type','state_type','document_type','currency_type','group','items','invoice','note'])->whereIn('state_type_id', [...])->get()` (only `payments` is needed; note `$payments` sum is not used by `document_totals_globals`, only `$document_total`, so even `payments` can be dropped there). Better: SUM per currency in SQL, as DashboardUtility already does.
- Verification idea: compare output of old/new on a seeded tenant; assert query count and peak memory.

### MOP-004 — Dashboard period totals hydrate full documents three times per widget refresh
- Category: performance
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (large refactor; figures must match to the cent)
- Location: modules/Dashboard/Helpers/DashboardData.php:207-221 (document_totals), 373-392 (totals), modules/Dashboard/Traits/TotalsTrait.php:17-235 (get_purchase_totals, get_expense_totals, get_sale_note_totals, get_document_totals); getDocumentsByDays 624-690
- Problem: `DashboardData::data()` loads the same establishment/range `Document` set three times (document_totals, totals, balance->get_document_totals) and the `SaleNote` set three times, each with the full `$with` graph, then filters/aggregates with Collections. `getDocumentsByDays` re-scans the whole documents collection 5 times per calendar day (O(days x docs)). `Purchase`/`Expense` payments are lazy loaded per row (`$purchase->purchase_payments`, `$expense->payments`) = N+1. When `period` is unknown (`switch` has no default) `$d_start/$d_end` stay null and the query is unbounded for the establishment.
- Failure scenario: "between_months" over 12 months with 50k documents: 3 x 50k hydrated documents with items and payments plus 365 x 5 collection scans.
- Proposed fix: share one pre-aggregated query (GROUP BY date_of_issue, currency, document_type, state) as DashboardUtility/DashboardSalePurchase/DashboardInsights already do; short term pass the same loaded collection to the three methods and use `->without([...])`.
- Verification idea: golden-master test of `DashboardData::data()` output on a fixture before/after.

### MOP-005 — Ecommerce quote/charge prices USD items at exchange rate 0 when no rate exists
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED (code path); PLAUSIBLE (that a tenant has no exchange-rate rows)
- Auto-fix: RISKY (payment behaviour)
- Location: app/Services/Payments/CartPricing.php:96-102 (forStore); app/Http/Controllers/Tenant/Api/ServiceController.php:93-121 (exchangeRateTest returns `$sale = 0` when there is no rate in DB and the lookup fails)
- Problem: `forStore` does `(float) ($rate['sale'] ?? 1)`; `exchangeRateTest` returns the number `0` (not an array key miss) when nothing is known. The result is `$unit *= 0` for every non-PEN item. The only guard is `round($total,2) <= 0` for the whole cart.
- Evidence:
```php
$rate = app(ServiceController::class)->exchangeRateTest(date('Y-m-d'));
$exchangeRate = (float) ($rate['sale'] ?? 1);
```
(also `exchangeRateTest` returns a scalar here; `$rate['sale']` on an int/array mix yields null -> 1.0 or 0 depending on branch, both wrong)
- Failure scenario: cart with one S/50 PEN item and one USD item; SUNAT rate service down and `exchange_rates` empty -> the USD item contributes 0.00 and the card is charged S/50.
- Proposed fix: in `CartPricing::total()` throw `InvalidArgumentException` when an item is non-PEN and `$exchangeRate <= 0`; make `forStore` read the array shape explicitly.
- Verification idea: unit test `CartPricing::total` with a USD item and rate 0 expecting the exception.

### MOP-006 — Offline/company-accounts/business-turn endpoints bypass module check for AJAX requests
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes permissions behaviour; the `ajax=false` convention exists on purpose for cross-module lookups)
- Location: config/permissions.php:163-166 (`bussiness_turns`, `offline-configurations`, `company_accounts` have no `'ajax' => true`); app/Support/Access/AccessMap.php:96-98; app/Http/Middleware/EnforceModuleAccess.php:47-54; modules/Offline/Http/Controllers/OfflineConfigurationController.php:21-38; modules/Account/Http/Controllers/CompanyAccountController.php:15-32; modules/BusinessTurn/Http/Controllers/BusinessTurnController.php:29-51,99
- Problem: `decide()` returns `allowed ('ajax-not-enforced')` for any rule without `ajax=true` when the request "wants JSON", which is just `X-Requested-With` or `Accept: application/json` supplied by the caller. These three module data endpoints are all JSON/AJAX, so any authenticated tenant user (including MOP-001 clients) without the `configuration` module can GET `offline-configurations/record` (returns `token_server` and `url_server`), POST `offline-configurations` (overwrite them), POST `company_accounts` (change accounting accounts), toggle business turns, and read `bussiness_turns/tables` (which includes `api_service_token`, see MOP-012).
- Evidence:
```php
if ($ajax && !$rule['ajax']) {
    return ['allowed' => true, 'rule' => $rule, 'reason' => 'ajax-not-enforced'];
}
```
- Failure scenario: a cashier with only `pos` sends `curl -H 'X-Requested-With: XMLHttpRequest' .../offline-configurations/record` and receives the sync server token.
- Proposed fix: add `'ajax' => true` to these three rules (they are not "fill-a-form" lookups); keep the exemption only for endpoints that other modules call.
- Verification idea: AccessMap unit test with these paths and `ajax=true`.

### MOP-007 — Code still depends on the removed `Culqi\` package (hard failure at first charge)
- Category: integration
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (user is mid-change on composer.json; decide: restore the package, or replace with a direct HTTPS client)
- Location: app/Services/Payments/CulqiCharger.php:5,23,57,64-66; tests/Unit/PaymentsTest.php:208,216 (references `\Culqi\Error\*`)
- Problem: `use Culqi\Culqi;` + `new Culqi(['api_key' => $secret])` + `instanceof \Culqi\Error\UnhandledError/AuthenticationError/InvalidApiKey/NotFound/MethodNotAllowed/InputValidationError`. `composer.json` no longer requires `culqi/culqi-php` and `vendor/culqi` does not exist, so the default factory throws `Class "Culqi\Culqi" not found` (an `Error`, caught by `\Throwable`, then classified as "outcome unknown"): every card payment would be reported as "unknown" and the order left `culqui:pendiente` although nothing was sent to Culqi. The tests only pass if the package is installed because they reference its exception classes.
- Failure scenario: customer pays by card, gets "No pudimos confirmar el pago... no vuelva a intentar", order stays pending forever, no charge exists. (Inverse risk: unrelated `Error` subclasses are also classified "unknown".)
- Proposed fix: either keep the dependency, or implement `Charges->create` over Guzzle with explicit `timeout`/`connect_timeout`, verify TLS, and map HTTP status codes instead of library exception classes; inject the factory so missing-class errors are not conflated with unknown outcomes.
- Verification idea: with the package removed, `php artisan test --filter PaymentsTest` and an integration test that the default factory resolves.

### MOP-008 — Culqi/cash checkout endpoints are public with no rate limit (card testing, order/email spam)
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE for `throttle` middleware; RISKY if tied to payment flow semantics
- Location: modules/Ecommerce/Routes/web.php:26-33 (`culqi`, `cart_quote`, `payment_cash`, `login`, `storeUser`); modules/Ecommerce/Http/Controllers/CulqiController.php:20-87; EcommerceController.php:121-137,238-303
- Problem: no `throttle`, no authentication on `culqi`/`payment_cash`. `payment_cash` creates an `Order` row and sends mail to `customer['correo_electronico']`, which for guests comes from the request body (any address). `culqi` accepts any token/email and charges the real card. `login` calls `Auth::attempt` with no throttling and does not regenerate the session id.
- Failure scenario: script posts thousands of `payment_cash` requests: unbounded orders and emails to arbitrary victims; stolen-card testing through `culqi`; credential stuffing on `ecommerce/login`.
- Proposed fix: `throttle:10,1` on payment routes and `throttle:5,1` on login/storeUser; validate `correo_electronico` for guest cash orders; `$request->session()->regenerate()` after `Auth::attempt`.
- Verification idea: hit the route 11 times, assert 429.

### MOP-009 — Public `items_bar`/`item/{id}`/`item_partial/{id}` expose cost data and non-store items
- Category: security
- Severity: medium
- Confidence: CONFIRMED (items_bar fields); PLAUSIBLE (what the Blade partials render)
- Auto-fix: RISKY (storefront JS may consume these fields)
- Location: modules/Ecommerce/Http/Resources/ItemBarCollection.php:48-67; EcommerceController.php:53-103; Routes/web.php:19-23
- Problem: `items_bar` is unauthenticated and returns `purchase_unit_price` (the cost), `stock`, `stock_min`, per-warehouse stock and `item_code_gs1` for every store item, unpaginated. `item($id)` and `partialItem($id)` use `Item::find($id)` with no `apply_store`/`active` filter, so any item id (including non-store items) renders for anonymous users, and an unknown id produces a null dereference (500).
- Evidence:
```php
'purchase_unit_price' => "{$row->currency_type->symbol} {$row->purchase_unit_price}",
```
- Failure scenario: a competitor scrapes `/ecommerce/items_bar` and learns your margin on every product; iterating `/ecommerce/item/1..N` reveals unpublished products.
- Proposed fix: drop `purchase_unit_price`/`stock_min`/`warehouses` from the public resource (or use a dedicated public resource), `Item::where('apply_store',1)->where('active',1)->findOrFail($id)`.
- Verification idea: request an item with `apply_store=0` -> 404; response keys snapshot test.

### MOP-010 — Unrestricted file upload in DocumentaryFile (no type/size validation, web-accessible path)
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (add `file` rule: mimes/size) once the allowed types are agreed
- Location: modules/DocumentaryProcedure/Http/Controllers/DocumentaryFileController.php:53-63,65-91,111-128; Http/Requests/FileRequest.php:14-27
- Problem: `FileRequest` has no rule for `file`. `storeFile()` keeps the client-supplied extension and original name and stores under `public/uploads/files` (served via `/storage`). Any `.html`, `.svg`, `.php5/.phtml` (depends on web-server config) is stored. Same weakness in `ConfigurationController::uploadFile` (modules/Ecommerce/Http/Controllers/ConfigurationController.php:87-119): `$type` from the request is concatenated into the stored file name and `svg` is allowed; validation runs after the extension is read, and `$config`/`$company` null is not handled.
- Failure scenario: a user with the `documentary-procedure` module uploads `x.html` with a script that runs on the tenant origin when another user opens the attachment (stored XSS), or an SVG logo does the same.
- Proposed fix: add `'file' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png|max:10240'`, generate the name server side (`Str::uuid().'.'.$file->guessExtension()`), and whitelist `$type` in the logo upload.
- Verification idea: upload `a.html` -> 422.

### MOP-011 — `/service/{type}/{number}` proxies arbitrary path segments with TLS verification disabled, no timeout, and a broken fallback
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (whitelist type, enable verify, add timeout); the `=!` typo fix changes which token is used when `token_apiruc` is empty, so RISKY only in that line
- Location: modules/Services/Data/ServiceData.php:14-27; modules/Services/Routes/web.php:7-9; modules/Services/Routes/api.php:7-9
- Problem: (1) `'verify' => false` while sending `Authorization: Bearer <platform token>`. (2) only `connect_timeout` is set, no `timeout`, so a slow upstream pins a PHP worker. (3) `$type` and `$number` go unvalidated into `'/api/'.$type.'/'.$number` (a `..` segment resolves to a different path on the upstream host with the bearer token). (4) The route has no throttle, and only `auth`: any logged-in user (including MOP-001 clients) can burn the paid lookup quota. (5) `$configuration->url_apiruc =! ''` is an assignment of `!''` (true), not a comparison, so the ternary always picks `$configuration->url_apiruc`/`token_apiruc` and the `config('configuration.api_service_*')` fallback never applies; with an empty column the request goes to an empty base URI. (6) `json_decode` of a non-JSON body returns `null` and the endpoint answers 200 with an empty body.
- Evidence:
```php
$token = $configuration->token_apiruc =! '' ? $configuration->token_apiruc : config('configuration.api_service_token');
$client = new Client(['base_uri' => $url, 'verify' => false]);
```
- Failure scenario: tenant leaves `token_apiruc` empty (the intended fallback case) -> upstream gets `Bearer ` -> 401, the lookup silently returns an error body; or a MITM on the path reads the bearer token.
- Proposed fix: `!== ''`/`filled()`, `verify => true`, add `'timeout' => 10`, whitelist `$type` in `['ruc','dni']` and `ctype_digit($number)`, `throttle:30,1`, return a 502 JSON on non-JSON.
- Verification idea: unit test with a MockHandler for empty token; request `/service/..%2fx/1` -> 404/422.

### MOP-012 — Platform lookup token sent to the browser by `bussiness_turns/tables`
- Category: security
- Severity: medium
- Confidence: CONFIRMED (the value is returned); the config is named `configuration.api_service_token`
- Auto-fix: RISKY (Vue consumers in BusinessTurn and Order call the third-party API directly with it)
- Location: modules/BusinessTurn/Http/Controllers/BusinessTurnController.php:99-101; consumers: modules/BusinessTurn/Resources/assets/js/views/hotels/{form,guest}.vue, modules/Order (Dispatcher/Driver controllers share the pattern)
- Problem: the shared platform token of the DNI/RUC provider is returned in JSON to every authenticated tenant user and used client-side. Combined with MOP-006 it needs no permission.
- Proposed fix: have the Vue forms call the server-side `/service/{type}/{number}` proxy (after MOP-011) and stop returning the token.
- Verification idea: JSON response of `bussiness_turns/tables` must not contain `api_service_token`.

### MOP-013 — HotelRentController::store leaves a transaction open, returns `success:true` on errors, and double-rents under concurrency
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (rollback + success flags + `lockForUpdate`)
- Location: modules/Hotel/Http/Controllers/HotelRentController.php:37-81
- Problem: `beginTransaction()` then an early `return` (room not DISPONIBLE) without `rollBack()`/`commit()`, so the tenant connection keeps an open transaction (nested level leaks into the rest of the request/worker). Both error responses return `'success' => true` with HTTP 500, so the Vue consumer that tests `success` shows a success message. The catch block returns `$th->getMessage()` (SQL text) to the client. The availability check `$room->status !== 'DISPONIBLE'` is a read followed by a write with no `lockForUpdate()`, so two receptionists can rent the same room.
- Evidence:
```php
if ($room->status !== 'DISPONIBLE') {
    return response()->json(['success' => true, 'message' => 'La habitación seleccionada no esta disponible'], 500);
}
```
- Failure scenario: two simultaneous requests for room 101 both pass the check and create two `OCUPADO` rents; or a rejected rental shows "success" in the UI.
- Proposed fix: `HotelRoom::lockForUpdate()->findOrFail()`, rollback before the early return, `success => false` for both errors, generic message + `report($th)`.
- Verification idea: test renting an occupied room asserts `success=false` and `DB::transactionLevel()==0`.

### MOP-014 — Documentary office/process/document/action list returns `{}` when the search box is empty (AJAX)
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/DocumentaryProcedure/Http/Controllers/DocumentaryOfficeController.php:11-25 (same in DocumentaryProcessController:14-22, DocumentaryDocumentController:15-23, DocumentaryActionController:14-22)
- Problem: `->get()` only runs inside `if ($filter)`; otherwise the Eloquent Builder itself is placed in `response()->json(['data' => $offices])`. A Builder is not JSON-serialisable, so `data` becomes `{}`. The Vue consumer does `this.items = response.data.data` (offices/Offices.vue:140).
- Evidence:
```php
if (request()->ajax()) {
    $filter = request('name');
    if ($filter) { $offices = $offices->where('name', 'like', "%$filter%")->get(); }
    return response()->json(['data' => $offices], 200);
}
```
- Failure scenario: user types a filter then clears it -> table shows no rows (`items = {}`).
- Proposed fix: move `->get()` out of the `if` (apply the `where` conditionally, always `->get()`).
- Verification idea: AJAX GET without `name` returns the list.

### MOP-015 — Hotel room update uses `HotelFloorRequest` (wrong rules)
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Hotel/Http/Controllers/HotelRoomController.php:86-98; Http/Requests/HotelFloorRequest.php:15-21
- Problem: `update(HotelFloorRequest $request, $id)` validates `description` as required, max 50 and `unique:hotel_floors,description` ignoring `$this->id` (a room id used against the floors table), and does not validate `name`, `hotel_category_id`, `hotel_floor_id` at all, while `HotelRoomRequest` (name unique, ids exist) is only used on store. A room with an empty description cannot be edited; a room description equal to a floor description is rejected; `name` can be changed to a duplicate or null.
- Proposed fix: use `HotelRoomRequest` (its `->ignore($this->id)` then works against `hotel_rooms`).
- Verification idea: PUT a room with null description -> 200; duplicate name -> 422.

### MOP-016 — Hotel: room-rate creation ignores the route `{roomId}` and uses body `hotel_room_id`; uniqueness is checked against another room
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Hotel/Http/Controllers/HotelRoomController.php:160-169; Http/Requests/HotelAddRateToRoomRequest.php:15-24
- Problem: the unique rule scopes by `$this->id` (route `{id}`) but `create($request->only('hotel_room_id', 'hotel_rate_id', 'price'))` takes `hotel_room_id` from the body (`'hotel_room_id' => ['required','numeric']`, no `exists`). The controller's `$roomId` is never used.
- Failure scenario: POST `rooms/5/rates/store` with `hotel_room_id=9` attaches the rate to room 9 and the duplicate check was done against room 5.
- Proposed fix: `HotelRoomRate::create(['hotel_room_id' => $roomId] + $request->only('hotel_rate_id','price'))`; drop the body field.
- Verification idea: post with mismatching ids; assert room 5 gets the row.

### MOP-017 — Hotel finalize/add-product skip state checks and transactions; product price comes from the client
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED (checks missing); price trust is a RISKY business-rule suspicion
- Auto-fix: SAFE for status/transaction guards; RISKY for server-side repricing
- Location: modules/Hotel/Http/Controllers/HotelRentController.php:106-127,154-170; HotelRoomController.php:123-134
- Problem: `finalizeRent` never checks the rent is still `OCUPADO`/not already FINALIZADO, then unconditionally sets the room to `LIMPIEZA` (a double click or a re-post after the room was re-rented marks the new guest's room as being cleaned). `arrears` is unvalidated. `addProductsToRoom` writes N rows in a loop with no transaction and no check that `$rentId` exists or is open; each product's whole `item` JSON (price, quantity, total) is stored exactly as the client sent it. `changeRoomStatus` accepts any string for `status` (the model has a whitelist in `HotelRoom::$status` that is not applied) and always answers "cambió su estado a DISPONIBLE".
- Proposed fix: `abort_unless($rent->status !== 'FINALIZADO')`, wrap in a transaction, `Rule::in(HotelRoom::$status)`, return the real new status. For prices, report to the product owner whether prices should be re-read from `items`.
- Verification idea: finalize twice -> second is a no-op/422.

### MOP-018 — Dashboard `month`/`between_dates` graphs mislabel or drop data across month/year boundaries
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes graph labels/series shape consumed by the Vue chart)
- Location: modules/Dashboard/Helpers/DashboardData.php:624-690 (getDocumentsByDays: `$d_start->format('d').'d'`), 692-774 (getDocumentsByMonths: `(int) substr($month_start, 5, 2)` .. `$m_end`)
- Problem: day buckets are keyed only by day-of-month, so `between_dates` 2026-01-15..2026-03-10 overwrites `15d..31d`, `01d..10d` and shows only the last month's values per key. `getDocumentsByMonths` loops from the month number of start to the month number of end and filters by `format('m')` only: Nov-2025..Feb-2026 gives `11..2` (empty loop, empty graph) and ranges spanning two years merge same-numbered months.
- Failure scenario: user picks "entre meses" Nov 2025 - Feb 2026: chart is empty while the totals cards show values.
- Proposed fix: key buckets by full `Y-m-d`/`Y-m` and format labels in the response; keep labels identical for single-month ranges.
- Verification idea: test with a Nov-Feb range asserting 4 buckets.

### MOP-019 — Purchases total double counts perceptions for USD purchases
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED (code), business-rule impact must be confirmed by the owner
- Auto-fix: RISKY (changes reported figures)
- Location: modules/Dashboard/Traits/TotalsTrait.php:35,51
- Problem: PEN purchases add `sum('total_perception')` of PEN rows once, but inside the loop each USD purchase adds `$purchases->sum('total_perception')` over ALL purchases (PEN and USD, N times) and uses an unconverted perception. A stray `;;` marks the line as unreviewed.
- Evidence:
```php
$purchases_total_usd += $purchase->total * $purchase->exchange_rate_sale +  $purchases->sum('total_perception');;
```
- Failure scenario: 3 USD purchases and one PEN purchase with perception 10 -> the balance counts that 10 three more times.
- Proposed fix (to be validated by owner): `+ $purchase->total_perception * $purchase->exchange_rate_sale` per row.
- Verification idea: fixture with mixed currencies.

### MOP-020 — Dashboard utilities ignore USD sales (documented parity bug)
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: RISKY (business rule)
- Location: modules/Dashboard/Helpers/DashboardUtility.php:142-152
- Problem: `where('d.currency_type_id', 'PEN')` for the sale total while the cost query has no currency filter, so USD documents add cost but zero income. The comment states this reproduces the legacy behaviour ("USD documents add 0").
- Failure scenario: USD invoices lower the reported utility.
- Proposed fix: report to owner; convert with `exchange_rate_sale` like `DashboardSalePurchase` does.

### MOP-021 — Ecommerce storefront unbounded queries, N+1 and repeated external rate lookups
- Category: n+1/sql
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (eager load/limit/cache), except caching the rate which is SAFE if TTL is short
- Location: modules/Ecommerce/Http/ViewComposers/FeaturedProductsViewComposer.php:16-50 (registered for 4 views in app/Providers/ViewServiceProvider.php:104-115); ItemBarCollection.php:19-78; EcommerceController.php:33,85-97
- Problem: the composer runs once per composed partial (4 on the home page), each doing `Item::...->get()` (no limit) plus lazy `currency_type`/`tags` per item and `exchangeRateTest()` (DB hit; when no row for today it calls the external rate service and INSERTs on a public GET). `ItemBarCollection` adds `getStockByWarehouse()`, `warehouses->warehouse` per item. The controller `__construct` runs `Item::...->take(2)->get()` for every request including POSTs. `category()` and `index()` dereference `$configuration->stock_control` with no null check.
- Proposed fix: `with('currency_type','tags')`, cache the composed list per request (`once()`), memoize the exchange rate for the request, paginate `items_bar`.
- Verification idea: query-count assertion on the home page.

### MOP-022 — Account export loads full documents with lazy relations and executes unused queries
- Category: n+1/sql
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Account/Http/Controllers/AccountController.php:24-101,195-216; FormatController.php:63-150
- Problem: `download()` always runs `getDocuments()` even for `concar` (which uses `getAllDocuments()`); per row `->customer`, `->invoice`, `->currency_type`, `->note->affected_document`, `->supplier` are lazy loaded (N+1, plus `Document::$with` pulls `items`/`payments` that the exports mostly do not need). `siscont` writes a `tempnam` file and `response()->download($temp, ...)` without `deleteFileAfterSend(true)`: the temp file is leaked on every export. An unknown `type` falls out of the `switch` and returns an empty 200. `$month` is not validated (`Carbon::parse` throws a 500 on bad input). `$company_account->total_pen` is dereferenced with no null check.
- Proposed fix: compute only the needed dataset per type, `with('customer')`, `deleteFileAfterSend(true)`, `default:` branch returning 422, `validate(['type' => 'required|in:...','month' => 'required|date_format:Y-m'])`.
- Verification idea: query count for 100 documents.

### MOP-023 — SummaryReport: select() without keys defeats eager loading; download not validated
- Category: n+1/sql
- Severity: low
- Confidence: PLAUSIBLE
- Auto-fix: SAFE
- Location: modules/Account/Http/Controllers/SummaryReportController.php:102-163,165-203,54-67
- Problem: `with(['documents' => fn => select('series','number',...)])` omits `id`, so each series still triggers the 9 `Document::$with` eager queries with null keys. `download()` has no `validate` (the `records` route has it); empty dates yield `whereBetween` with nulls. `getAcceptedDocuments` uses `whereHas('documents')` without the date range, so series without documents in the period are returned as zero rows.
- Proposed fix: `->without([...])` on the closure query, share the `validate()` rule.

### MOP-024 — Offline configuration: `required_if` is malformed and `store` dereferences null
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Offline/Http/Requests/OfflineConfigurationRequest.php:19-20; OfflineConfigurationController.php:28-38
- Problem: `required_if:is_client, "true"` compares against the literal string ` "true"` (leading space and quotes), so `token_server`/`url_server` are never required when `is_client` is true. `OfflineConfiguration::find($id)` returns null for an unknown/missing `id` -> fatal on `->fill`. `fill($request->all())` relies on `$fillable`.
- Proposed fix: `'required_if:is_client,true'` (or `Rule::requiredIf`), `firstOrFail()`.

### MOP-025 — Company accounts / Offline `store` use `find($id)->fill(...)` with no null check
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Account/Http/Controllers/CompanyAccountController.php:22-32; `record()` passes a possibly null model to the Resource.
- Proposed fix: `CompanyAccount::firstOrFail()`/`findOrFail`.

### MOP-026 — Ecommerce `saveDataUser` has an assignment-in-condition bug and no auth guard
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Ecommerce/Http/Controllers/EcommerceController.php:354-368; Routes/web.php:33 (outside the `auth` group)
- Problem: `if ($user->telephone = $request->telephone) {` assigns first, so an empty `telephone` overwrites the stored phone with null (and the inner assignment is redundant). `auth()->user()` is null for guests, so a guest POST is a fatal error (500) instead of 401. The route is not in the `auth` group.
- Evidence:
```php
if ($user->telephone = $request->telephone) {
    $user->telephone = $request->telephone;
}
```
- Proposed fix: `if ($request->filled('telephone'))`, move the route under `auth`, return 401.

### MOP-027 — `transactionFinally` / `ratingItem` accept unvalidated client data; rating returns success=false when saved
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (rating response flag, validation); `transactionFinally` document ids RISKY (payment/document flow)
- Location: modules/Ecommerce/Http/Controllers/EcommerceController.php:187-225,305-323
- Problem: `document_external_id` and `number_document` are stored from the request without any validation or check that they belong to a real document of this buyer; `User::update` receives `identity_document_type_id`/`number` unvalidated. `ratingItem` stores `value` unvalidated (any string/number, no range) and returns `'success' => false` after saving ("Rating Guardado"); `getRating`/`ratingItem` do not verify `item_id` exists.
- Proposed fix: `exists:documents,external_id`, `integer|between:1,5`, `success => true`.

### MOP-028 — Ecommerce `uploadFile` mixes validation order and accepts arbitrary `type`
- Category: security
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Ecommerce/Http/Controllers/ConfigurationController.php:87-119
- Problem: extension is read before validation; `$type` (request input) is part of the file name (`$type.'_'.$company->number.'.'.$ext`) and any value is stored, but the DB always writes `$config->logo = $name`, so uploading `type=banner` replaces the logo reference. `ConfigurationEcommerceRequest` is reused for section saves but `store_configuration_social`/`tags` accept raw `Request` (any strings, no URL validation on `link_*`).
- Proposed fix: validate first, `Rule::in(['logo_store'])`, `url` rule for links.

### MOP-029 — Dashboard `command/df` runs three shell commands for any authenticated user and has no consumer
- Category: dead-code
- Severity: low
- Confidence: PLAUSIBLE (no reference in resources/js, blade, app or scheduler)
- Auto-fix: SAFE to remove if nobody uses it externally
- Location: modules/Dashboard/Routes/web.php:25; DashboardController.php:150-185
- Problem: `df -m -h --output=...` is GNU-only; the path `command/df` has no rule in `config/permissions.php`, so any authenticated tenant user can run it and see host disk usage (information disclosure, not injection: the command is fixed). Three process spawns where one would do; `throw` after `return` is unreachable.
- Proposed fix: delete route + method or restrict to the owner.

### MOP-030 — Dead code in Dashboard
- Category: dead-code
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Dashboard/Helpers/DashboardView.php:24-226 (`getUnpaid` only referenced by commented-out code in DashboardController.php:109-119; it also runs 2 extra queries per row = N+1 and is unbounded); DashboardController.php unused imports (`AccountsReceivable`, `Excel`, `DB`, `Document`, `Arr`, `Dispatch`-style leftovers) and commented route/method blocks; DashboardStock/DashboardData commented blocks (DashboardData.php:58-66, 74).
- Proposed fix: delete `getUnpaid`, unused imports and commented blocks (keep `getUnpaidFilterUser`, used by Report module; `data_mobile` is used by MobileController).

### MOP-031 — Dead/duplicate classes in Account, Ecommerce, DocumentaryProcedure, LevelAccess
- Category: dead-code
- Severity: low
- Confidence: CONFIRMED (no references found in php/blade/js/json for the classes; checked providers, routes, ViewServiceProvider)
- Auto-fix: SAFE
- Location: modules/Account/Exports/ReportAccountConcarExport.php (+ views `account/templates/excel_concar.blade.php`; `accounting/templates/excel_concar_bk.blade.php` backup); modules/Ecommerce/Http/ViewComposers/TakeProductoViewComposer.php (declares namespace `App\Http\View\Composers`, not registered anywhere; `EcommerceController::__construct` shares `records` instead); modules/DocumentaryProcedure/Http/Controllers/DocumentaryProcedureController.php (no route, views `documentaryprocedure::index/create/show/edit` do not exist); modules/LevelAccess/Http/Controllers/LevelAccessController.php (scaffold; only `index` routed); DocumentaryFileController `$files->load('offices')` (already `with('offices')`); `Padron::index/create` empty methods; `ExtractorTrait` commented gz/rar blocks; `HotelRoomController::store` sets `$room->status` after create and never saves (DB default already 'DISPONIBLE').
- Proposed fix: remove.

### MOP-032 — LevelAccess web route is reachable without authentication or tenant guard
- Category: security
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/LevelAccess/Routes/web.php:14-16
- Problem: `GET /levelaccess` is in the bare `web` group (no `auth`, no `Route::domain`, not in the permissions map) and renders `levelaccess::index` (scaffold view). Low impact today, but it is an unauthenticated route on both system and tenant hosts.
- Proposed fix: remove the scaffold route/module or wrap it like the other modules.

### MOP-033 — Four near-identical Hotel CRUD controllers and four Documentary CRUD controllers; delete leaks DB errors
- Category: duplication
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE for the error-message leak; duplication refactor optional
- Location: modules/Hotel/Http/Controllers/Hotel{Rate,Category,Floor}Controller.php, HotelRoomController::destroy; modules/DocumentaryProcedure/Http/Controllers/Documentary{Office,Process,Document,Action}Controller.php
- Problem: diffed Floor vs Category vs Rate controllers: they differ only in names. `destroy()` returns `'Detalles: ' . $th->getMessage()` (raw SQL/FK error text) with `success=false`; Documentary controllers answer with the misspelt key `succes` on success and `success` on failure; Hotel floor/category delete has no check for dependent rooms (FK error or orphans). Room delete has no check for an active rent.
- Proposed fix: generic message + `report($th)`, return `success` consistently (check the Vue consumers read the right key before renaming: Files.vue/Offices.vue use `response.data.message` only).

### MOP-034 — Documentary file numbering uses count()+1 (race and gaps)
- Category: functional-bug
- Severity: low
- Confidence: PLAUSIBLE (business rule; report only)
- Auto-fix: RISKY
- Location: modules/DocumentaryProcedure/Http/Controllers/DocumentaryFileController.php:203-233
- Problem: `next_id` and `number` are `count()+1`; deleting a file (soft delete) or two concurrent creations yields repeated numbers; the number comes back from the client on `store` with no uniqueness rule (`FileRequest` `number` only `numeric|min:1`). `index()` has no limit on `get()`.
- Proposed fix: unique index on (document type, year, number) and server-side assignment inside the transaction.

### MOP-035 — Hotel reception queries each occupied room's rent separately
- Category: n+1/sql
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Hotel/Http/Controllers/HotelReceptionController.php:31-56
- Problem: for every `OCUPADO` room a `HotelRent::where('hotel_room_id')->latest->first()` is executed; `rooms` is unpaginated. `showFormChekout` dereferences `$establishment->id` with no null check.
- Proposed fix: one query `HotelRent::whereIn('hotel_room_id', $ids)->orderByDesc('id')->get()->unique('hotel_room_id')`.

### MOP-036 — Public Padron `consulta` has no throttle and a trivial validation
- Category: security
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: modules/Padron/Http/Controllers/PadronController.php:36-68
- Problem: only `strlen($ruc) == 11`, no digit check, unauthenticated and unthrottled; lets anyone enumerate taxpayer data from the tenant's padron table; duplicated `direccion`/`direccion_completa`; `ubigeo => []` placeholder.
- Proposed fix: `ctype_digit`, `throttle`, put behind auth if only used internally (no in-repo consumer found).

### MOP-037 — Misc low-severity items
- Category: maintainability
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location / Problem:
  - modules/Ecommerce/Routes/web.php:20,23 — route name `tenant_ecommerce_login` is registered twice (GET and POST same URI): name collision, the second wins in the route table.
  - EcommerceController.php:295-303 `paymentCashEmail` swallows the exception and returns `true`; callers ignore it (no logging).
  - EcommerceController `login` returns 200 with `success:false` for bad credentials instead of 401/422.
  - modules/Dashboard/Helpers/DashboardStock.php:23 `Establishment::first()->id` null deref when no establishment; DashboardController.php:26 `Company::first()` null.
  - modules/Dashboard/Helpers/DashboardInsights.php:665 `whereYear()` on `date_of_issue` is not index-friendly (use a date range).
  - modules/Account/Http/Controllers/System/AccountingController.php:26 `Client::latest()->get()` unbounded.
  - modules/Dashboard `data/data_aditional/insights/utilities` accept `$request->all()` without validation; `Carbon::parse($month.'-01')` on malformed input throws 500 (DashboardInsights catches it, the others do not).
  - JS-in-Blade: cart/detail.blade.php:507,785 embed `json_encode(Auth::user())` inside a single-quoted JS string: a user name containing `'` or `\` breaks the page (self-XSS vector once MOP-001 is open); use `@json`.

## Overflow themes (not itemised)
- Dashboard: `DashboardInsights` helpers not read (bucket/weekday/hour series, typeCounts, today); `DashboardSalePurchase` lines 140-383 only pattern-scanned (aggregates in SQL, looked sane).
- AccountController `getStructureConcar/Siscont/Contasis` repeat the same row blocks ~6 times with many commented columns (duplication/maintainability); amounts are formatted as strings with mixed `number_format` separators.
- Providers/RouteServiceProvider in all 10 modules are copies of the stock nwidart stubs; Ecommerce/Services providers do not register routes with the tenant domain scoping used elsewhere (each routes file handles it itself, inconsistently: Ecommerce, LevelAccess, Padron do not).
- Vue consumers were not audited here; response-shape claims were checked only against the files cited.
