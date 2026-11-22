# Findings — System, Auth & Infrastructure

Audit mode: read-only. Branch `chore/system-audit`. No secret values are reproduced; secrets are referred to by config/column name only.

## Coverage
- Scope (fully read unless noted):
  - `app/Http/Controllers/Controller.php` and `app/Http/Controllers/Auth/*` (all 5: `LoginController`, `RegisterController`, `ForgotPasswordController`, `ResetPasswordController`, `VerificationController`).
  - `app/Http/Controllers/System/*` (all 13 controllers + `Api/ResellerController`: `AccountStatusController`, `BackupController`, `CertificateController`, `ClientController`, `ClientPaymentController`, `CompanyController`, `ConfigurationController`, `HomeController`, `LoginController`, `PlanController`, `ServiceController`, `UpdateController`, `UserController`, `Api/ResellerController`), plus `app/Http/Requests/System/*` (all 5) and `app/Http/Resources/System/*` (`ClientCollection`, `ClientResource`, `CompanyResource`, `UserResource`).
  - `app/Http/Kernel.php` and `app/Http/Middleware/*` (all 14: `Authenticate`, `CheckForMaintenanceMode`, `CheckModule`, `EncryptCookies`, `EnforceModuleAccess`, `InternalModeTenant`, `LockedAdmin`, `LockedTenant`, `RedirectIfAuthenticated`, `RedirectModule`, `RedirectModuleLevel`, `TrimStrings`, `TrustProxies`, `VerifyCsrfToken`).
  - `app/Providers/*` (all 15: `AnulationServiceProvider`, `AppServiceProvider`, `AuthServiceProvider`, `BroadcastServiceProvider`, `CacheServiceProvider`, `CashServiceProvider`, `DocumentPaymentProvider`, `EventServiceProvider`, `InventoryAnulationServiceProvider`, `InventoryKardexServiceProvider`, `InventoryServiceProvider`, `KardexServiceProvider`, `LockedEmissionProvider`, `RouteServiceProvider`, `ViewServiceProvider`).
  - `app/Exceptions/Handler.php`.
  - `app/Console/Kernel.php` and `app/Console/Commands/*` (18 commands fully read; `HotelDemoSeedCommand` read lines 1-486; `DemoSeedCommand` read lines 1-300 and 1900-2058).
  - `routes/web.php`, `routes/api.php`, `routes/channels.php`, `routes/console.php`.
  - `config/app.php`, `config/auth.php`, `config/cache.php`, `config/configuration.php`, `config/cors.php`, `config/database.php`, `config/filesystems.php`, `config/permissions.php`, `config/session.php`, `config/tenancy.php`, `config/tenant.php`.
  - `app/Support/Access/*` (`AccessMap`, `PermissionTree`).
- Supporting files read to confirm behavior: `app/Tenancy/{Environment,Providers/TenancyProvider,Commands/MigrateCommand,Database/Connection,Repositories/WebsiteRepository,Repositories/HostnameRepository,Middleware/EagerIdentification,Middleware/HostnameActions}`, `app/Http/ViewComposers/Tenant/*`, `modules/{LevelAccess,Document,BusinessTurn}/Http/ViewComposers/*`, `app/Models/Tenant/{User,Establishment,Company,Configuration,SaleNote,Task}`, `app/Models/System/{Client,Configuration,Plan,User}`, `app/Observers/DocumentObserver`, `app/Traits/{BackupTrait,CommandTrait,OfflineTrait}`, `app/Http/Controllers/Tenant/{TaskController,UserController}`, `modules/{Account,Inventory}/Routes/*`, `tests/Unit/AccessMapTest.php`, `database/migrations/2019_01_29_170027_create_clients_table.php`, `composer.json`.
- Not reviewed: `CoreFacturalo/*`, non-System `Tenant/*` controllers beyond cross-checks, Blade/Vue consumers.

## Findings (ordered by severity)

### SYS-001 — Unauthenticated `api/reseller/*` endpoints dump every tenant's `api_token` and let anyone lock out the entire platform
- Category: security
- Severity: critical
- Confidence: CONFIRMED
- Auto-fix: RISKY (external reseller billing/provisioning callers may depend on the contract; coordinate shared auth)
- Location: `routes/api.php:56-62`, `app/Http/Controllers/System/Api/ResellerController.php:27-40, 61-88`, `app/Http/Resources/System/ClientCollection.php:17-41`, `app/Http/Controllers/System/ClientController.php:324-328, 435`
- Problem: In the non-tenant branch (`else`), `routes/api.php:59-60` mounts `POST /api/reseller/detail` and `POST /api/reseller/lockedAdmin` with no authentication middleware, and `ResellerController` checks no bearer token or signature. `resellerDetail()` iterates every `Client`, switches tenant DBs, and returns `ClientCollection`, which includes `'token' => $row->token` — the exact plaintext `api_token` seeded onto tenant admin user #1 (`ClientController::store:435`). `lockedAdmin()` lets any caller flip `system.configurations.locked_admin` (checked on every `web` request by `LockedAdmin`) and loop through every client setting `locked_tenant` in both `system.clients` and every tenant's `configurations` table. In addition, `routes/api.php:56` evaluates `env('APP_URL_BASE')` directly; under `config:cache`, `env()` returns `null`, removing the domain restriction as well.
- Evidence:
```php
// routes/api.php:56-61
Route::domain(env('APP_URL_BASE'))->group(function () {
    Route::post('reseller/detail', 'System\Api\ResellerController@resellerDetail');
    Route::post('reseller/lockedAdmin', 'System\Api\ResellerController@lockedAdmin');
});
```
```php
// ClientCollection.php:23 & ResellerController.php:65-80
'token' => $row->token,
...
$configuration->locked_admin = $request->locked_admin;
...
DB::connection('tenant')->table('configurations')->where('id', 1)->update(['locked_tenant' => $client->locked_tenant]);
```
- Failure scenario: Any unauthenticated caller `POST`s to `/api/reseller/detail`, collects every tenant's FQDN, RUC, email, and admin `api_token`, and uses `Authorization: Bearer <token>` against any tenant `/api/*` endpoint (issuing/voiding SUNAT documents, reading customers, creating items/users); or `POST`s `locked_admin=1` to `/api/reseller/lockedAdmin` to lock out the entire platform.
- Proposed fix: Protect both routes with HMAC/signed-request or dedicated bearer-secret middleware (and rate limiting), strip `'token'` from `ClientCollection` (or create a separate reseller resource without credentials), and replace `env('APP_URL_BASE')` with `config('tenant.app_url_base')`.
- Verification idea: Feature test `POST /api/reseller/detail` and `POST /api/reseller/lockedAdmin` without credentials returns `401`/`403`.

### SYS-002 — Route registration reads `env('APP_URL_BASE')` and `CurrentHostname` at boot, breaking `route:cache`/`route:list` and exposing System Admin routes when config is cached
- Category: functional-bug / security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (for replacing `env('APP_URL_BASE')` with `config('tenant.app_url_base')` and removing `config:cache` from `UpdateController::artisanClear`); RISKY (for restructuring `if ($hostname)` route registration)
- Location: `routes/web.php:3-6, 568-570`, `routes/api.php:3-5, 56`, `modules/Account/Routes/web.php:34-36`, `app/Http/Controllers/System/UpdateController.php:81-86`, `app/Tenancy/Environment.php:28-35`, `config/tenancy.php:150`
- Problem:
  1. `routes/web.php:568`, `routes/api.php:56`, and `modules/Account/Routes/web.php:34` call `env('APP_URL_BASE')` directly outside `config/`. Whenever config is cached (`php artisan config:cache`, or clicking Clear Cache in System Update via `UpdateController::artisanClear:83`), `env()` returns `null`. On `routes/web.php:569`, `$groupAttributes` collapses to `[]`, so System Admin routes match any unmatched hostname (`config/tenancy.php:150` also has `'abort-without-identified-hostname' => false`). On `routes/api.php:56`, `Route::domain(null)` drops domain scoping.
  2. `routes/web.php:3-5` and `routes/api.php:3-4` branch on `$hostname = app(Hyn\Tenancy\Contracts\CurrentHostname::class)` at route-loading time. In CLI (`route:cache`, `route:list`), `$hostname` is `null`, so `route:cache` discards all tenant routes and `php artisan route:list` fails (`BASELINE.md:30`).
- Evidence:
```php
// routes/web.php:568-570
$systemDomain = env('APP_URL_BASE');
$groupAttributes = ($systemDomain && !in_array($systemDomain, ['localhost', '127.0.0.1', ''])) ? ['domain' => $systemDomain] : [];
```
- Failure scenario: Running `auto-update/artisan/clear` caches config; on the next request to any unmapped hostname or IP, `env('APP_URL_BASE')` returns `null` and the host serves the System Admin login and routes instead of 404.
- Proposed fix: Replace all `env('APP_URL_BASE')` calls in route files with `config('tenant.app_url_base')`, stop invoking `config:cache` inside `UpdateController::artisanClear()`, and long-term register tenant and system route groups unconditionally using route domain/middleware constraints instead of boot-time `if ($hostname)`.
- Verification idea: Cache config and assert `routes/web.php` still restricts System Admin routes to `config('tenant.app_url_base')`.

### SYS-003 — `Handler::render` converts every `HttpException` (`abort(403)`, `429`, `503`) into HTTP 500 JSON and leaks file/line paths to browsers
- Category: functional-bug / security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Exceptions/Handler.php:55-91, 107-127`, `app/Http/Middleware/LockedAdmin.php:21-23`, `app/Http/Middleware/LockedTenant.php:21-23`, `app/Http/Middleware/InternalModeTenant.php:21-23`, `app/Http/Middleware/Authenticate.php:15-18`
- Problem:
  1. `Handler.php:82-84` handles `HttpException` by calling `$this->errorResponse('', '', $exception)` — passing `''` instead of `$exception->getStatusCode()`. Line 117 coerces any non-integer `$code` to `500`, so every `abort(403)` (`LockedAdmin`, `LockedTenant`, `InternalModeTenant`), `429` (`ThrottleRequests`), and `503` (maintenance mode) returns HTTP **500** JSON (`{"success":false,"message":"","file":...,"line":...}`) — even on normal HTML browser requests (never checks `$this->isFrontend($request)`).
  2. `AuthorizationException` (403), `NotFoundHttpException` (404), and `MethodNotAllowedHttpException` (405) at lines 63-74 also skip `$this->isFrontend($request)`, returning JSON error payloads with server paths instead of HTML error pages.
  3. `errorResponse()` unconditionally outputs `file`, `line`, and `$exception->getMessage()` ignoring `config('app.debug')` (overlaps `SEC-008`).
- Evidence:
```php
// app/Exceptions/Handler.php:82-84, 114-127
if($exception instanceof HttpException) {
    return $this->errorResponse('', '', $exception);
}
...
$code = ($code === '' || !is_int($code) || $code < 100 || $code > 599) ? 500 : $code;
return response()->json(['success' => false, 'message' => $message, 'file' => $file, 'line' => $line], $code);
```
- Failure scenario: Locked tenant or browser hitting a missing URL receives HTTP 500 JSON leaking `/var/www/html/...` paths; throttled requests return 500 instead of 429.
- Proposed fix: Pass `$exception->getStatusCode()` for `HttpException`; delegate to `parent::render($request, $exception)` when `$this->isFrontend($request)` is true for HTTP/404/405/403 exceptions; include `file`/`line`/raw message in JSON only when `config('app.debug')` is true; check `$request->expectsJson()` in `Authenticate::redirectTo`.
- Verification idea: Feature test hitting a missing URL with `Accept: text/html` returns 404 HTML; hitting an `abort(403)` route as JSON returns 403 without `file`/`line` when `app.debug=false`.

### SYS-004 — `tenancy:run` scheduler command is missing from the custom `app/Tenancy` implementation, silently disabling all tenant cron jobs
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (needs implementing multi-tenant command runner or updating `TenantCommand` + restricting allowed task commands)
- Location: `app/Console/Kernel.php:25-27`, `app/Tenancy/Providers/TenancyProvider.php:57-59`, `app/Console/Commands/TenantCommand.php:40-53`, `app/Http/Controllers/Tenant/TaskController.php:46-68`, `config/permissions.php:162`
- Problem:
  1. `app/Console/Kernel.php:26` schedules `$schedule->command('tenancy:run tenant:run')->everyMinute();`. However, `hyn/multi-tenant` was replaced by the in-repo `app/Tenancy/` implementation (`composer.json:49`), and `TenancyProvider::register()` only registers `MigrateCommand` (`tenancy:migrate`). `tenancy:run` does not exist anywhere in `app/` or `vendor/`. Thus `php artisan schedule:run` fails every minute and none of the tenant scheduled tasks (`online:send-all`, `offline:send-all`, `offline:query-all`, `summary:send`, `summary:query`, `recurrency:sale-note`, `consult:voided-documents`, `validate:documents`) ever run.
  2. Even when `tenant:run` runs directly, `TenantCommand` matches `execution_time` strictly to `Carbon::now()->format('H:i').':00'` (skipped if the cron run drifts by a minute), catches only `\Exception` (not `\Throwable`), and runs `Artisan::call($task->class)` with no allowlist — while `TaskController::listsCommand()` exposes every file in `app/Console/Commands/` (including `BackupDatabase`, `BackupFiles`, `SystemInstall`, `DemoSeedCommand`, `ReencryptCulqiSecrets`, `ChangelogGen`), `TaskRequest` accepts any string for `class`, and `config/permissions.php:162` lacks `'ajax' => true` on `tasks`.
- Failure scenario: Scheduled SUNAT submissions, summary queries, offline syncs, and recurring sale notes never execute via cron; if `tenant:run` is enabled without an allowlist, any authenticated tenant user can schedule global system commands (`bk:bd`, `demo:seed`, etc.) via `POST /tasks`.
- Proposed fix: Implement `tenancy:run` (or iterate `Website`s inside `TenantCommand` with per-tenant exception isolation), add an explicit allowlist of tenant-safe commands in both `TaskController`/`TaskRequest` and `TenantCommand`, and mark `'tasks'` `'ajax' => true` in `config/permissions.php`.
- Verification idea: `php artisan schedule:list` / `schedule:run` executes without `Command "tenancy:run" is not defined`; `POST /tasks` with `BackupDatabase` returns 422.

### SYS-005 — Authorization gaps in `EnforceModuleAccess` / `config/permissions.php`: unmapped sensitive routes, API guard bypass, and double middleware runs
- Category: security / performance
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (adding rules/`ajax => true` or API gating requires checking cross-module UI calls; deduplicating `redirect.level` is SAFE)
- Location: `app/Http/Middleware/EnforceModuleAccess.php:16-54`, `app/Http/Middleware/RedirectModule.php`, `app/Http/Middleware/RedirectModuleLevel.php`, `app/Support/Access/AccessMap.php:34-43, 82-107`, `config/permissions.php:28-184`, `routes/web.php:24, 75-559`, `routes/api.php:9-45`, `modules/Inventory/Routes/api.php:8`
- Problem:
  1. Confirming and extending `TC1-005` / `SEC-001`: `AccessMap::decide()` allows all unmapped paths (`reason => 'unmapped'`) and skips checks for XHR requests on rules without `'ajax' => true`. Beyond `configurations/*` mutations (`POST /configurations`, `icbper`, `changeFormat`, `uploads`, `GET addSeeder`), `routes/web.php` exposes entire unmapped resource groups to every authenticated tenant user (including sellers and storefront `type='client'` users): `bank_accounts/*`, `series/*`, `charge_discounts/*`, `card_brands/*`, `document_payments/*`, `sale_note_payments/*`, `unit_types/*`, `detraction_types/*`, `banks/*`, `exchange_rates/*`, `currency_types/*`, `tribute_concept_types/*`, `codes/*`, `payment_method/*`, `templates`.
  2. `routes/api.php:9` applies only `['auth:api', 'locked.tenant']` (no module/level check and no user-type check). Even `modules/Inventory/Routes/api.php:8` (`['auth:api', 'redirect.module', 'locked.tenant']`) is ineffective because `EnforceModuleAccess` passes `$request->path()` (`api/inventory/transaction`) to `AccessMap`, which has no `api/*` rules (`reason => 'unmapped'`).
  3. Thirteen routes in `routes/web.php` (lines 153, 154, 197, 200, 202, 243, 249, 259, 401, 404, 405, 430, 437) attach `redirect.level` inside the `redirect.module` group; since both `RedirectModule` and `RedirectModuleLevel` extend `EnforceModuleAccess`, `handle()` executes twice on those routes.
  4. `AccessMap::fromConfig()` caches `$instance` in a function-level `static` variable, ignoring config overrides in tests or long-lived workers.
- Failure scenario: A restricted seller or storefront buyer calls `GET/POST/DELETE /bank_accounts`, `POST /series`, `POST /configurations`, or uses `api_token` on `/api/documents` and `/api/inventory/transaction`.
- Proposed fix: Map the missing config/bank/series/catalog prefixes to `'configuration'` (or appropriate module) in `config/permissions.php`, deny `type === 'client'` in `EnforceModuleAccess` and `auth:api` routes, enforce module permissions on API routes, remove redundant `->middleware('redirect.level')` calls, and resolve `AccessMap` from the container instead of a `static` local.
- Verification idea: Feature test as a seller and as a `type='client'` user asserting 403 on `/bank_accounts`, `POST /series`, `POST /configurations`, and `/api/inventory/transaction`.

### SYS-006 — `ValidateDocumentsCommand` enters an infinite `goto` loop on any unsuccessful SUNAT CPE lookup
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Console/Commands/ValidateDocumentsCommand.php:70-115`
- Problem: Inside the document loop, line 75 defines label `reValidate:` and lines 112-114 execute `goto reValidate;` whenever `!$response['success']`, with no retry cap, no sleep/backoff, and no error handling. Any SUNAT outage, timeout, bad token, or rejected document traps the CLI process in an infinite loop hammering `ValidateCpe2::search()`. In addition, the state-update block (lines 96-111) is commented out, `$document->company` triggers an N+1 query per document, and `Company::query()->first()->name` (line 70) lacks a null check.
- Evidence:
```php
reValidate:
$validate_cpe = new ValidateCpe2();
$response = $validate_cpe->search(...);
if ($response['success']) {
    ...
} else {
    goto reValidate;
}
```
- Failure scenario: Running `php artisan validate:documents` when SUNAT validation returns `success => false` hangs indefinitely and spams external API requests until killed.
- Proposed fix: Replace `goto reValidate` with a bounded retry loop (e.g., max 3 attempts with backoff), log failed documents and continue, eager-load `with('company')`, and null-check `Company::first()`.
- Verification idea: Unit test mocking `ValidateCpe2::search()` returning `['success' => false]` finishes after bounded retries instead of hanging.

### SYS-007 — `System\ClientController`: arbitrary `temp_path` read, cross-tenant certificate overwrite on `store`, orphaned tenants on failure, and unvalidated `update`
- Category: security / functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (for unique cert filename, `subdomain` regex, `findOrFail`, `establishment_id`, and `array` validation); RISKY (for `temp_path` protocol change, aligned with `SEC-002`)
- Location: `app/Http/Controllers/System/ClientController.php:185-275 (update), 277-476 (store), 417-428, 569-606 (destroy/password), 636-655 (upload_certificate)`, `app/Http/Requests/System/ClientRequest.php:34-36`, `app/Tenancy/Repositories/WebsiteRepository.php:51-55`
- Problem:
  1. `store()` and `update()` feed client-supplied `temp_path` directly into `file_get_contents($temp_path)` (`SEC-002`); `upload_certificate()` calls `tempnam(sys_get_temp_dir(), $type)` (`$type` may be null -> PHP 8 deprecation) and never removes temp files.
  2. `store()` line 290 hardcodes `$name = 'certificate_'.'admin_tenant'.'.pem';`. Provisioning a second tenant with a custom certificate overwrites the first tenant's PEM file on disk.
  3. `store()` starts a `system` transaction around `WebsiteRepository::create` (whose `CREATE DATABASE` and migrations implicitly commit MySQL transactions), then inserts `companies`, `configurations`, `establishments`, `warehouses`, `series`, `users`, `module_user`, `module_level_user` into `tenant` outside any `try/catch` or transaction. Any failure (e.g., `Plan::findOrFail` at line 370) leaves an orphaned database, `Website`, `Hostname`, and `Client`. Additionally, lines 418-427 hardcode `'establishment_id' => 1` instead of `$establishment_id`, line 385 stores system-host `asset('images/login-v2.svg')`, and `ClientRequest` validates `'subdomain' => ['required']` without `alpha_dash`/regex (allowing path characters into `storage_path('app/tenancy/tenants/' . $website->uuid)`).
  4. `update()` accepts raw `Request` (no FormRequest), dereferences `Plan::find($request->plan_id)->limit_documents` without null check (and never updates `limit_users`), deletes `module_user` and `module_level_user` (lines 242-243) *before* iterating `$request->modules` / `$request->levels` (if either is null, PHP 8 `foreach()` throws a TypeError leaving user #1 with zero permissions), uses non-recursive `mkdir()`, and omits a tenant transaction.
  5. `destroy($id)` and `password($id)` use `Client::find($id)` with no null check; `destroy` leaves tenant certificates on disk; `password` resets user #1's password to `bcrypt($client->number)` (public RUC) and leaves `Environment` switched to that tenant.
- Proposed fix: Name store certificates `'certificate_'.$request->input('number').'.pem'`; validate `subdomain` as `required|alpha_dash`; validate `update()` via a `ClientUpdateRequest` and wrap tenant provisioning/updates in transactions with rollback cleanup; use `$establishment_id` in `series`; use server-issued temp tokens.
- Verification idea: Create two clients with distinct PFX files and verify both PEM filenames are distinct; post `update()` with missing `modules` and verify 422 without wiping `module_user`.

### SYS-008 — `System\ClientController::records` crashes every January (month `0`) and throws null dereference on unseeded tenants
- Category: functional-bug / performance
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/System/ClientController.php:75-111, 142-183`
- Problem:
  1. Lines 93-95 compute `$init = Carbon::parse(date('Y').'-'.((int)date('n') - 1).'-'.$day_start_billing)` whenever `$day_now <= $day_start_billing`. In January (`date('n') == 1`), `(int)date('n') - 1` is `0`, forming `'2026-0-15'`, which causes `Carbon::parse()` to throw `InvalidFormatException` and breaks `/clients/records` for the entire month of January for any client with a `start_billing_cycle`. Day `29/30/31` in shorter months also overflows via `Carbon::parse`.
  2. Lines 83-84 dereference `DB::connection('tenant')->table('configurations')->first()->quantity_documents` and `->table('companies')->first()->soap_type_id` without null checks — a single empty/broken tenant DB crashes the entire admin client listing.
  3. Performance: switches tenant DBs for every client synchronously (`records()` runs 4-5 queries per client; `charts()` runs 12 separate `COUNT(*)` queries per client instead of one `GROUP BY MONTH(date_of_issue)` query).
- Evidence:
```php
// ClientController.php:93-95
if( $day_now <= $day_start_billing ) {
    $init = Carbon::parse( date('Y').'-'.((int)date('n') -1).'-'.$day_start_billing );
```
- Failure scenario: Opening `/clients` in January fails with 500 if any client has `start_billing_cycle` set to a day >= today's day-of-month; an unseeded tenant breaks the table for all clients.
- Proposed fix: Use `Carbon::today()->subMonthNoOverflow()` / clamp day with `min($day, $daysInMonth)`; guard `optional(...)` on `configurations` and `companies` queries; aggregate `charts()` with `GROUP BY MONTH(date_of_issue)`.
- Verification idea: Test billing cycle date calculation on `2026-01-05` with `start_billing_cycle = 2025-12-20` -> expects `2025-12-20`..`2026-01-05`.

### SYS-009 — `AnulationServiceProvider` re-credits stock and duplicates Kardex rows on EVERY update of a voided (`state_type_id = 11`) document
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (touches stock/kardex accounting; verify interaction with `Modules\Inventory`)
- Location: `app/Providers/AnulationServiceProvider.php:30-56`
- Problem: `Document::updated` checks `if ($document['state_type_id'] == 11)` without checking `$document->wasChanged('state_type_id')` (unlike `DocumentObserver::updated` at `app/Observers/DocumentObserver.php:41`). Every subsequent save on an already-voided `01`/`03` document (e.g., status/query/shipping/ticket updates) executes `updateStock(..., false)` and inserts another `Kardex` row, repeatedly inflating item stock. Also triggers a lazy load of `$document['items']`.
- Evidence:
```php
Document::updated(function ($document) {
    if($document['document_type_id'] == '01' || $document['document_type_id'] == '03'){
        if($document['state_type_id'] == 11){
            foreach ($document['items'] as $detail) {
                $this->updateStock($detail['item_id'], $detail['quantity'], false);
                $this->saveKardex('sale', $detail['item_id'], $document['id'], -$detail['quantity'],'document');
```
- Failure scenario: Void a factura (stock restored +1), then update `sunat_shipping_status` or `query_status` on that row -> stock increases +1 again and a duplicate Kardex row is recorded.
- Proposed fix: Add `$document->wasChanged('state_type_id')` guard (matching `DocumentObserver::updated`).
- Verification idea: Void a document, then update an unrelated field (`filename`/`query_status`) and assert `items.stock` and `kardex` count stay unchanged.

### SYS-010 — `LockedEmissionProvider`: non-atomic counter increment, post-insert limit check, and null `$configuration->plan` crash on user creation
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (null/type guards and `creating` hook); RISKY (atomic `DB::table('configurations')->increment(...)` if observers expect Eloquent `Configuration` events)
- Location: `app/Providers/LockedEmissionProvider.php:29-87`
- Problem:
  1. Both `locked_emission()` and `update_quantity_documents()` listen to `Document::created` (AFTER insert) and each queries `Configuration::first()` separately (2 extra queries per created document). If `Document::create()` runs without a surrounding transaction and exceeds `limit_documents`, the row is persisted before `locked_emission()` throws `Exception`. `update_quantity_documents()` runs a non-atomic read-modify-write (`$configuration->quantity_documents++; $configuration->save();`), losing increments under concurrent emissions.
  2. `locked_users()` hooks `User::creating` and reads `$configuration->plan->limit_users` (lines 78-80) with no null check on `$configuration` or `$configuration->plan` (which can be null via `AccountController::updatePlan` `TC1-022` or unseeded tenants), crashing user creation with a fatal property read on null; strict `!== 0` also misbehaves if JSON decodes `"0"`.
- Failure scenario: Tenant with `plan = null` and `locked_users = true` tries to create a user -> 500 error; concurrent invoices lose `quantity_documents` increments.
- Proposed fix: Enforce emission limits in `Document::creating`, increment `quantity_documents` atomically via `DB::connection('tenant')->table('configurations')->where('id', ...)->increment('quantity_documents')`, and guard `optional($configuration)->plan` with `(int)` cast in `locked_users()`.
- Verification idea: Test `User::create` with `configuration.plan = null` and `locked_users = true` does not fatal.

### SYS-011 — `CashServiceProvider` fatals when `auth()->user()` is null and `DocumentPaymentProvider` skips `updated` events + loads full payment relations
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Providers/CashServiceProvider.php:47-71`, `app/Providers/DocumentPaymentProvider.php:37-68`
- Problem:
  1. `CashServiceProvider::getCash()` calls `auth()->user()->id` without null-checking `auth()->user()`. Any `ExpensePayment` with `expense_method_type_id === 1` created in CLI/queue/seeders throws `Attempt to read property "id" on null` (and strict `=== 1` skips `"1"`).
  2. `DocumentPaymentProvider` listens only to `DocumentPayment::created` and `deleted`, missing `updated` (`TC1-012`), omits a null guard on `$document_payment->document`, and loads all `DocumentPayment` models via `$document->payments->sum('payment')` instead of querying `$document->payments()->sum('payment')` (which can also read a stale cached relation on delete).
- Proposed fix: Guard `auth()->id()` in `CashServiceProvider`; hook `DocumentPayment::updated` and query `$document->payments()->sum('payment')` with null guard in `DocumentPaymentProvider`.
- Verification idea: Update a `DocumentPayment` amount to settle a document and assert `documents.total_canceled` becomes `true`.

### SYS-012 — `CacheServiceProvider`'s `redis_tenancy` driver reads invalid `config['driver']` in CLI and unguarded `$_SERVER['SERVER_NAME']` on web
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Providers/CacheServiceProvider.php:14-33`, `config/cache.php:71-74`
- Problem: `config/cache.php:71-74` configures the `redis` cache store with `'driver' => 'redis_tenancy'`. In `CacheServiceProvider::boot()`:
  1. Under CLI (`PHP_SAPI === 'cli'`), line 16 evaluates `$uuid = $app['config']['driver'];`, which does not exist (`null`), giving all tenants the same unprefixed Redis keyspace and risking cross-tenant cache collisions in CLI/cron/queues if Redis cache is enabled.
  2. On web, line 20 reads `$_SERVER['SERVER_NAME']` directly (can throw `Undefined array key "SERVER_NAME"` in PHP 8 under certain FastCGI/CLI-server setups or mismatch `HTTP_HOST`) and queries `DB::table('hostnames')` via the default connection rather than the `system` connection.
- Proposed fix: Resolve prefix from `optional(app(\Hyn\Tenancy\Environment::class)->website())->uuid ?: 'central'` (using the already-identified tenant in both web and CLI) and query `system` only if needed.
- Verification idea: Switch `Environment::tenant($website)` in CLI and assert `Cache::store('redis')->getStore()->getPrefix()` uses `$website->uuid`.

### SYS-013 — Heavy per-request middleware, Eloquent `$with`, and duplicate `ViewServiceProvider` query overhead
- Category: performance
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Kernel.php:39`, `app/Http/Middleware/{LockedAdmin,LockedTenant,InternalModeTenant}.php`, `app/Providers/ViewServiceProvider.php:14-158`, `app/Http/ViewComposers/Tenant/{CompanyViewComposer,ConfigurationVisualViewComposer,CompactSidebarViewComposer,ModuleViewComposer}.php`, `app/Models/Tenant/{User,Establishment}.php`, `app/Tenancy/Environment.php:38-87`
- Problem: Every authenticated tenant Blade page incurs ~30+ redundant queries before controller logic runs:
  1. `Environment` runs `hasTable('websites')` + `Hostname::with('website')->where('fqdn', ...)` on every request (`config/tenancy.php`'s `'cache' => 10` is unused by `app/Tenancy/Environment.php`).
  2. `LockedAdmin` runs `System\Configuration::first()` on every `web` request (both system and tenant, uncached and null-unsafe); `LockedTenant` runs `Tenant\Configuration::first()` on every authenticated tenant web/API request (uncached, null-unsafe); `InternalModeTenant` runs `Company::first()` (uncached, null-unsafe).
  3. `User::$with = ['establishment']` + `Establishment::$with = ['country', 'department', 'province', 'district']` triggers 6 queries to hydrate `auth()->user()` on every authenticated request, including JSON API endpoints.
  4. `ViewServiceProvider` registers `CompanyViewComposer` **4 times** per layout (`app`, `header`, and **twice** on `sidebar` at lines 36-39 and 56-59 -> 8 queries for `Company::first()` + `Order::where('status_order_id', 1)->count()`), `ModuleViewComposer` **2 times** (`header` + `sidebar`), `ModuleLevelViewComposer` **2 times** (`header` + `sidebar`), and `ConfigurationVisualViewComposer` **2 times** (`sidebar_styles` + `app`, and `compose()` queries `Configuration::first()` twice at lines 12 & 22 plus `UPDATE`s when `visual` is null). Plus `EnforceModuleAccess` runs twice on the 13 routes with `redirect.level` (`SYS-005`).
- Proposed fix: Remove the duplicate `CompanyViewComposer` registration at `ViewServiceProvider.php:56-59`, share layout data once at `tenant.layouts.app` or request-memoize `Company::first()`/`Configuration::first()`/`System\Configuration::first()`, add null guards in `LockedAdmin`/`LockedTenant`/`InternalModeTenant`/`CompactSidebarViewComposer`/`ModuleViewComposer`, and honor `tenancy.hostname.cache`.
- Verification idea: Assert query count on a standard Blade GET drops after deduplicating `ViewServiceProvider` and memoizing config/company lookups.

### SYS-014 — `System\UserController`: unauthenticated `/phone` endpoint, cross-tenant updates outside tenant transactions, and missing single-admin guard
- Category: security / functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (for eager-loading, null checks, and restoring tenant connection); RISKY (verify no external consumer hits `GET /phone` before gating behind `auth:admin`)
- Location: `routes/web.php:574`, `app/Http/Controllers/System/UserController.php:26-80`
- Problem:
  1. `Route::get('phone', 'System\UserController@getPhone')` is registered outside `auth:admin` (`routes/web.php:574`) and returns the system administrator's phone number anonymously (and crashes if `User::first()` is null); its original tenant caller (`Tenant\ConfigurationController::getSystemPhone` / `CompactSidebarViewComposer`) is commented out.
  2. `store()` uses `User::firstOrNew(['id' => $id])`, allowing creation of additional admin rows when `id` is omitted and `config('tenant.password_change')` is true (or failing on null required columns when false).
  3. `updatePhoneClients()` wraps `Client::get()` in `DB::connection('system')->transaction(...)` while updating `DB::connection('tenant')->table('configurations')` across all tenants: tenant updates are not transactional, `$row->hostname->website` lacks null checks and triggers N+1 queries, and `$tenancy->tenant(...)` stays pointing at the last tenant.
- Proposed fix: Move `phone` inside `auth:admin` (or remove if unused), require an existing `User::firstOrFail()` in `store()`, eager-load `hostname.website` with null guards in `updatePhoneClients()`, and reset tenant connection after the loop.
- Verification idea: Unauthenticated `GET /phone` returns 401/302; `updatePhoneClients` skips clients missing a hostname/website without aborting.

### SYS-015 — `System\ConfigurationController` & `CertificateController`: cleartext secrets in JSON, SVG upload (XSS), arbitrary property assignment into `login` JSON
- Category: security / functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (for `only()` in `storeLoginSettings`, dropping `svg`, unlinking PEM on `destroy`, `File::ensureDirectoryExists`, null guards); RISKY (for masking `token_apiruc`/`soap_password` in JSON responses — check Vue form handling)
- Location: `app/Http/Controllers/System/ConfigurationController.php:14-31, 77-144`, `app/Http/Controllers/System/CertificateController.php:14-87`, `app/Http/Controllers/System/ClientController.php:68-72, 130-131`, `app/Http/Resources/System/{ClientResource,CompanyResource}.php`
- Problem:
  1. `ConfigurationController::record()` masks `token_private_culqui`, yet `ConfigurationController::apiruc()` returns `token_apiruc` in cleartext, `CertificateController::record()` returns `soap_password` in cleartext, and `ClientController::tables()` / `ClientResource` / `CompanyResource` return `soap_password` in cleartext.
  2. `ConfigurationController::storeLoginSettings()` validates 5 keys and then iterates `request()->all()` assigning every key onto `$config->login` (allowing injection of arbitrary fields such as `image`/`logo` URLs or `javascript:` values into the `login` JSON), and crashes if `$config->login` is null.
  3. `ConfigurationController::storeBgLogin()` permits `svg` uploads into `public/uploads/login` (same-origin stored XSS, `SEC-014`), does not validate `type`, and never deletes replaced files.
  4. `System\CertificateController::uploadFile()` performs no file validation before `file_get_contents($file)`, calls non-recursive `mkdir()` (`TC1-030` pattern), returns raw `$e->getMessage()`, and `destroy()` only nulls `certificate` in DB while leaving `certificate_admin_master.pem` (private key) on disk. `saveSoapUser()` has no validation.
- Proposed fix: Assign only `$request->only(['position_form', 'show_logo_in_form', 'position_logo', 'show_socials'])` in `storeLoginSettings()`, drop `svg` from `storeBgLogin()`, unlink the PEM file on `CertificateController::destroy()`, validate upload file/mime/size, use `File::ensureDirectoryExists`, and replace cleartext secrets in `apiruc()`/`record()`/`tables()` with `has_*` flags.
- Verification idea: `POST /configurations/login` with extra `image=javascript:alert(1)` ignores `image`; `DELETE /certificates` removes `certificate_admin_master.pem` from disk.

### SYS-016 — `System\ClientPaymentController` & `AccountStatusController`: duplicate tenant `account_payments` on edit, orphan rows on delete, GET mutation, null dereferences
- Category: functional-bug / security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/System/ClientPaymentController.php:34-102`, `app/Http/Controllers/System/AccountStatusController.php:30-51`, `routes/web.php:612`
- Problem:
  1. `ClientPaymentController::store()` unconditionally calls `DB::connection('tenant')->table('account_payments')->insert(...)` even when `$id` is present (edit), creating duplicate `account_payments` rows in the tenant DB, and writes across `system` and `tenant` without transaction/null guards (`$client->hostname->website`).
  2. `destroy($id)` deletes the `ClientPayment` row in `system` but leaves the corresponding `account_payments` row (`reference_id = $id`) in `tenant`.
  3. `cancel_payment($client_payment_id)` is mounted on `GET` (`routes/web.php:612`, CSRF-able) and uses `ClientPayment::find($client_payment_id)` with no null check.
  4. `AccountStatusController::client()` and `ClientPaymentController::client()` use `Client::find($client_id)` without null check (`$client->payments`, `$client->plan->pricing` fatal when client or plan is null).
- Proposed fix: Use `updateOrInsert(['reference_id' => $record->id], ...)` in `store()`; delete the matching `account_payments` row in `destroy()`; move `cancel_payment` to `POST`; use `findOrFail()` and null-guard `$client->plan`.
- Verification idea: Editing a client payment does not create a second row in `tenant.account_payments`; deleting it removes both rows.

### SYS-017 — `System\BackupController` & `BackupDatabase`/`BackupFiles`: crashes on empty/nested backup dir, unvalidated download/FTP SSRF, hardcoded `mysql` DB name
- Category: functional-bug / security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (for `mostRecent()` initialization/null handling, `basename()` check, recursive `mkdir`, reading `system` DB config, non-zero exit codes); RISKY (for FTP host restrictions / `bk:bd` signature cleanup)
- Location: `app/Http/Controllers/System/BackupController.php:42-163`, `app/Console/Commands/BackupDatabase.php:21, 51-108`, `app/Console/Commands/BackupFiles.php:18, 42-71`
- Problem:
  1. `BackupController::mostRecent()` leaves `$most_recent_path` and `$most_recent_name` uninitialized before the loop; if `backups/zip/` has only nested files (`count(explode('/', $zip)) > 3`), lines 148-150 trigger `Undefined variable` and `Carbon::createFromTimestamp(null)`. When `backups/zip/` is empty, it returns `''`, causing `BackupController::upload()` (`$most_recent['name']` / `$most_recent['path']`) to crash with PHP 8 `TypeError: Cannot access offset of type string on string`.
  2. `BackupController::upload()` does not validate `host`/`port`/`username`/`password` (enabling FTP SSRF/probing) and loads the entire archive into memory via `Storage::get` instead of `readStream`. `download($filename)` passes `$filename` directly to `Storage::download` without `basename()` or `.zip` validation.
  3. `BackupDatabase` line 72 dumps `config('database.connections.mysql.database')` (default `'forge'`) instead of the `system` connection database (`config('tenancy.db.system-connection-name', 'system')`), queries `DB::table('websites')` on the default connection instead of `system`, uses non-recursive `mkdir()` assuming `storage/app/backups` already exists when `type === 'individual'`, declares `'bk:bd --type={type} --database={database?}'` (`Parser` treats `{type}` as positional argument, not `--type` option), and returns an `array` on exception (`Command::execute` casts it to exit code `1`, discarding `message`). `BackupFiles` catches `Throwable` and returns `null` (exit code `0`), hiding backup failures from `BackupController::files()`.
- Proposed fix: Initialize variables in `mostRecent()` and return `null` when empty; check `!$most_recent` and stream uploads in `upload()`; validate `basename($filename)` ending in `.zip` in `download()`; fix `BackupDatabase` DB connection/name lookup and recursive `mkdir`; return `1` on failure in both `BackupDatabase` and `BackupFiles`.
- Verification idea: Call `mostRecent()` and `upload()` with an empty `backups/zip/` directory and verify graceful 422 response instead of `TypeError`; run `bk:bd` with `type=todos` and verify it dumps the `system` DB name rather than `forge`.

### SYS-018 — `QueryAllServerCommand` ungrouped `orWhere`, `SendAllSunatCommand` blind success marking + typo, `ConsultVoidedDocumentsCommand` long HTTP transaction
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Console/Commands/QueryAllServerCommand.php:53-79`, `app/Console/Commands/SendAllSunatCommand.php:80-99`, `app/Console/Commands/SendAllServerCommand.php:80-90`, `app/Console/Commands/ConsultVoidedDocumentsCommand.php:45-60`
- Problem:
  1. `QueryAllServerCommand:53-58` chains `->where('send_server', 1)->where('state_type_id', '!=', '05')->orWhere('success_query_status', false)`: the ungrouped `orWhere` selects every document in the table with `success_query_status = false` regardless of `send_server` or `state_type_id`.
  2. `SendAllSunatCommand:84-86` always sets `$document->success_sunat_shipping_status = true` even when `DocumentController::send()` returns `['success' => false, ...]`, and line 93 misspells `'sucess' => false`. All three commands (`QueryAllServerCommand`, `SendAllServerCommand`, `SendAllSunatCommand`) catch `\Exception` instead of `\Throwable` and write `'payload' => $e` (`json_encode($e)` produces `{}`).
  3. `ConsultVoidedDocumentsCommand:48-57` wraps the entire loop of external SUNAT status queries (`$facturalo->statusSummary`) in one `DB::connection('tenant')->transaction(...)`, holding DB locks across network calls and rolling back already-checked voided documents on any later failure; line 45 logs debug string `"Consulta ssss"`.
- Proposed fix: Group the `orWhere` in `QueryAllServerCommand`; check `$response['success']` and fix `'sucess'` -> `'success'` in `SendAllSunatCommand`; drop `'payload' => $e`; remove the outer HTTP-spanning transaction and debug log in `ConsultVoidedDocumentsCommand`.
- Verification idea: Seed a non-offline document with `success_query_status = 0` and verify `QueryAllServerCommand` ignores it.

### SYS-019 — `SummarySendCommand` & `SummaryQueryCommand`: duplicated self-HTTP calls with TLS `verify => false`, null host/JSON crashes
- Category: security / functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (null/JSON checks and helper extraction); RISKY (replacing HTTP loopback with direct service invocation)
- Location: `app/Console/Commands/SummarySendCommand.php:46-111`, `app/Console/Commands/SummaryQueryCommand.php:46-108`
- Problem: Both commands duplicate the same boilerplate: `Auth::login(User::firstOrFail())`, resolve `$hostname` via `Website::...->first()->hostnames->first()` with no null check before `$hostname->fqdn`, and send a Guzzle POST back to the app's own `/api/summaries*` route with `'verify' => false` (`SEC-013`) and `api_token`. Line 100 (`SummaryQueryCommand`) and line 103 (`SummarySendCommand`) dereference `$res['success']` without checking `is_array($res)` — a 500/HTML/empty response causes a PHP 8 `TypeError: Cannot access offset of type string on null`. `Company::firstOrFail()->active()->soap_type_id` also runs an extra query.
- Proposed fix: Null-check `$hostname` and `is_array($res) && array_key_exists('success', $res)`; extract a shared helper or invoke `SummaryController` / `Facturalo` directly in-process instead of an insecure HTTP loopback.
- Verification idea: Mock non-JSON 500 HTTP response and verify the command handles it without crashing.

### SYS-020 — `RecurrencySaleNoteCommand`: replicated sale notes keep the original `series`/`number`, nested transactions, future-date sign bug
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (affects sale-note numbering and recurrence business rules)
- Location: `app/Console/Commands/RecurrencySaleNoteCommand.php:44-186`
- Problem:
  1. Line 101 calls `$sale_note->replicate()` without allocating a new `number` (or clearing `number` for auto-numbering), so every generated recurring sale note duplicates the original `series` and `number`.
  2. Line 90 computes `$today->diffInMonths($init_d_of_issue)` (absolute difference by default); if `date_of_issue` is in the future while `automatic_date_of_issue <= $today`, `diffInMonths` returns a positive count.
  3. `handle()` wraps all due sale notes in one transaction with `sharedLock()` and calls `createSaleNote()` which opens a nested savepoint transaction, so a single error rolls back every previously processed recurring sale note.
- Proposed fix: Allocate the next sequential `number` per `series` inside per-note transactions and guard `$init_d_of_issue->lte($today)`.
- Verification idea: Run recurrence on a past-due sale note and assert the new `sale_notes` row gets `number = original + 1`.

### SYS-021 — `System\UpdateController`: GET state mutations, broken `chown`, relative `chmod -R 777`, unescaped `base_path`, `config:cache` pitfall
- Category: security / functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (for removing `chown`/`chmod`/`keygen`/`config:cache` and using array args in `Process`); RISKY (for changing GET to POST with frontend updates)
- Location: `app/Http/Controllers/System/UpdateController.php:43-135`, `routes/web.php:645-654`
- Problem: Beyond the CSRF-able GET deployment endpoints (`SEC-009`):
  1. `pull()` line 50 executes `Process::fromShellCommandline('chown -R ssh/');` (invalid 1-argument `chown`, always fails silently).
  2. `composerInstall()` line 90 interpolates `base_path()` unescaped into `Process::fromShellCommandline`, and line 94 runs `chmod -R 777 ../vendor/mpdf/mpdf` (relative to CWD and makes vendor files world-writable).
  3. `artisanClear()` line 83 invokes `Artisan::call('config:cache')`, which breaks every `env('APP_URL_BASE')` check in route files (`SYS-002`).
  4. `keygen()` (lines 100-135) is an empty commented stub still bound to `routes/web.php:652`.
- Proposed fix: Remove the broken `chown`, `chmod -R 777`, `keygen`, and `config:cache` calls; use `new Process(['composer', 'install', '-d', base_path()])`; change mutating routes to `POST` alongside Vue callers.
- Verification idea: Confirm `auto-update/artisan/clear` only clears caches (`cache:clear`/`view:clear`) without calling `config:cache`.

### SYS-022 — `System\HomeController` & `BackupController` run blocking `du -sh` / `df` shell processes per page load and load all `Client` rows for `count()`
- Category: performance / functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/System/HomeController.php:15-49`, `app/Http/Controllers/System/BackupController.php:23-40`
- Problem: `HomeController::index()` loads every `Client` row (`Client::get()`) with eager `$with = ['hostname', 'plan']` just to call `count($clients)` (`Client::count()`). Both `HomeController::index()` and `BackupController::index()` spawn synchronous `du -sh` (on `storage_path()`, unescaped) and GNU-specific `df -m -h --output=...` shell processes on every page load (`substr(..., 0, -1)` instead of `trim()`).
- Proposed fix: Use `Client::count()`, `disk_free_space('/')`, `trim()`, and short-lived caching for storage directory sizing.
- Verification idea: Assert `HomeController::index()` issues `COUNT(*)` instead of `SELECT * FROM clients`.

### SYS-023 — `App\Models\Tenant\User::$hidden` omits `password` and `api_token`
- Category: security
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: RISKY (verify no Blade/Vue or API caller serializes `Auth::user()` / `$user` expecting `api_token`, e.g. `SEC-016`)
- Location: `app/Models/Tenant/User.php:33-35`
- Problem: `Tenant\User::$hidden` only hides `['remember_token']` (compare `System\User::$hidden` which hides `['password', 'remember_token']`). Any serialization of a `User` model (e.g., via `SaleNote::$with = ['user', ...]` or `json_encode(Auth::user())`) exposes the user's bcrypt `password` hash and plaintext `api_token` (`SEC-016`).
- Proposed fix: Add `'password'` and `'api_token'` to `Tenant\User::$hidden`, reading `$user->api_token` explicitly only in authenticated token issuance (`MobileController::login` after staff check).
- Verification idea: Assert `(new User([...]))->toArray()` omits `password` and `api_token`.

### SYS-024 — `Auth` controllers and `System\ServiceController` lack null checks on `Configuration::first()` and duplicate login-config lookup
- Category: functional-bug / maintainability
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Auth/{LoginController,ForgotPasswordController,ResetPasswordController}.php`, `app/Http/Controllers/System/ServiceController.php:11-50`
- Problem: `Auth\LoginController::showLoginForm`, `ForgotPasswordController::showLinkRequestForm`, and `ResetPasswordController::showResetForm` each duplicate the same `SystemConfiguration::first()` / `Configuration::first()` lookup and dereference `->use_login_global` and `->login` without null guards. `System\ServiceController::ruc()` dereferences `Configuration::first()->token_apiruc` without a null check, indexes `$data['data']['nombre_o_razon_social']` unguarded, and returns `$th->getMessage()` with HTTP 200 on error.
- Proposed fix: Extract a shared null-safe login-settings helper for the three `Auth` controllers and add null/key guards + generic error message in `System\ServiceController::ruc()`.
- Verification idea: Call `showLoginForm()` or `System\ServiceController::ruc()` with an empty `configurations` row/table and verify graceful handling.

### SYS-025 — Duplicate route, duplicate provider, `config/tenancy.php` typos/missing route file, and weak session/filesystem config defaults
- Category: maintainability / config
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `routes/web.php:597, 603`, `config/app.php:182, 188`, `config/tenancy.php:282, 304`, `config/session.php:127, 167, 195`, `config/cache.php:91`, `config/filesystems.php:54, 69-76`, `config/configuration.php:7`
- Problem:
  1. `routes/web.php` registers `Route::post('clients/locked_tenant', 'System\ClientController@lockedTenant')` twice (lines 597 and 603).
  2. `config/app.php` registers `Rap2hpoutre\LaravelLogViewer\LaravelLogViewerServiceProvider::class` twice (lines 182 and 188).
  3. `config/tenancy.php:282` contains typo `'force-tenant-connection-of-modelsforce-tenant-connection-of-models'`, and line 304 references nonexistent `routes/tenants.php`.
  4. `config/session.php` defaults `'same_site' => null` and `'secure' => env('SESSION_SECURE_COOKIE', false)` (`SEC-019`); `config/session.php:127` and `config/cache.php:91` still use global `str_slug()` (`laravel/helpers`) instead of `Str::slug()`.
  5. `config/filesystems.php` keeps an empty `'ftp'` disk definition (overwritten at runtime by `BackupController::upload`), `'public'` disk `'url'` hardcodes central `env('APP_URL').'/storage'`, and `config/configuration.php:7` defaults `'api_service_token'` to `false` instead of `null`.
- Proposed fix: Remove duplicate route and provider lines, fix `config/tenancy.php` key and `routes.path => false`, default `same_site => 'lax'`, and replace `str_slug` with `Str::slug`.
- Verification idea: `php artisan config:show tenancy` and `php artisan test` confirm clean config loading.

### SYS-026 — Dead code and broken duplicate stubs across `Auth`, `Middleware`, `Providers`, and `System`
- Category: dead-code
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (delete/unregister dead code; keep VCS history)
- Location: `app/Http/Controllers/Auth/{RegisterController,VerificationController}.php`, `app/Http/Middleware/CheckModule.php`, `app/Providers/{InventoryKardexServiceProvider,InventoryAnulationServiceProvider,InventoryServiceProvider}.php`, `config/app.php:173-175`, `app/Http/Controllers/System/CompanyController.php:14-26, 48-85`, `app/Http/Controllers/System/Api/ResellerController.php:42-59, 91-117`, `app/Http/Controllers/System/UpdateController.php:100-135`
- Problem:
  1. `Auth\RegisterController` and `VerificationController` are unreachable (`routes/web.php:7-10` disables `register` and `verify`; `VerificationController` extends `Illuminate\Routing\Controller` and redirects to nonexistent `/home`).
  2. `CheckModule` middleware is commented out in `app/Http/Kernel.php:67` and superseded by `EnforceModuleAccess`.
  3. `app/Providers/InventoryKardexServiceProvider`, `InventoryAnulationServiceProvider`, and `InventoryServiceProvider` have completely commented-out `boot()` methods while still registered in `config/app.php:173-175` (superseded by `Modules\Inventory\Providers\*`).
  4. `System\CompanyController` contains unrouted copy-paste methods `create()`, `tables()`, and `uploadFile()` referencing unimported `SoapType` and `Company` (fatal `Class not found` if called).
  5. `ResellerController` retains dead unrouted `login()` (calls `Auth::attempt` on the Tenant `web` guard) and a commented clone of `lockedAdmin()`; `UpdateController::keygen()` is an empty commented stub.
- Proposed fix: Unregister the three empty `Inventory*` providers from `config/app.php` and delete the dead classes/methods and unused imports (`DocumentRequest` in `AccountStatusController`/`ClientPaymentController`, `ProcessFailedException` in `HomeController`/`BackupDatabase`/`ChangelogGen`, `Storage` in `UpdateController`, etc.).
- Verification idea: `grep` confirms zero active callers/routes before removal; `php -l` and `Unit` suite pass.

### SYS-027 — `System\PlanController::destroy` lacks in-use FK guard; `ClientController::store` uses legacy `str_random()`
- Category: functional-bug / maintainability
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/System/PlanController.php:57-66`, `database/migrations/2019_01_29_170027_create_clients_table.php:29`, `app/Http/Controllers/System/ClientController.php:324`
- Problem: `clients.plan_id` references `plans.id` without `ON DELETE CASCADE`, and `PlanController::destroy($id)` calls `$plan->delete()` without checking `$plan->clients()->exists()` or catching `QueryException` (`23000`), returning an unhandled 500 when deleting an assigned plan. `System\ClientController::store:324` also relies on `laravel/helpers` `str_random(50)` instead of `Str::random(50)`.
- Proposed fix: Return 422 JSON `{success: false, message: ...}` when `$plan->clients()->exists()` and replace `str_random(50)` with `Str::random(50)`.
- Verification idea: `DELETE /plans/{id}` for a plan assigned to a client returns clean JSON error instead of SQLSTATE 23000 500.

## Overflow themes (not itemised)
- **Raw `Exception` messages returned to HTTP clients**: `System\ClientController`, `CertificateController`, `BackupController`, and `ServiceController` catch exceptions and return `$e->getMessage()` directly in JSON responses (`Handler` does the same for non-HTML requests).
- **Missing `$request->validated()` discipline**: System controllers (`ClientController::store`, `ClientPaymentController::store`, `PlanController::store`, `CompanyController::store`, `ConfigurationController::storeLoginSettings`) pass `$request->all()` into `fill()` or loops rather than `$request->validated()` / explicit allowlists.
- **Artisan `handle()` return types**: Several tenant/cron commands (`TenantCommand`, `ConsultVoidedDocumentsCommand`, `QueryAllServerCommand`, `SendAllServerCommand`, `SendAllSunatCommand`, `SummaryQueryCommand`, `SummarySendCommand`, `ValidateDocumentsCommand`, `RecurrencySaleNoteCommand`) omit explicit `return 0;` / `return Command::SUCCESS;` exit codes (`ChangelogGen` returns `false`, `BackupDatabase` returns an `array` on catch).
- **Inconsistent `env()` / `str_*` legacy helpers**: `str_slug` (`config/session.php`, `config/cache.php`) and `str_random` (`ClientController`, `Tenant\UserController`) remain dependent on `laravel/helpers` rather than `Illuminate\Support\Str`.
