# Findings — Tenant controllers (part 2)

## Coverage
- Scope: second 35 of 70 files of `find app/Http/Controllers/Tenant -type f -name '*.php' | sort` (`ItemSetController.php` ... `VoidedController.php`).
- Fully read (all 35): ItemSetController, LoginConfigurationController, NoteController, OptionController, OrderController, PaymentMethodTypeController, PerceptionController, PersonController, PersonTypeController, PosController, PromotionController, PurchaseController, PurchaseSunatValidationController, QuotationController, ReportConsistencyDocumentController, ReportController, ReportInventoryController, ReportKardexController, ReportPurchaseController, ReportQuotationController, ReportSaleNoteController, RetentionController, SaleNoteController, SaleNotePaymentController, SearchController, SeriesController, SettingController, StatusOrdersController, SummaryController, TagController, TaskController, TributeConceptTypeController, UnitTypeController, UserController, VoidedController.
- Clean / low-noise controllers in scope: `PurchaseSunatValidationController` (uses `setEagerLoads([])`, regex period check, proper 404/422/502 JSON responses, gated by `purchases` `ajax: true`) and `SettingController` (14 pure view-rendering methods mapped in `config/permissions.php`).
- Supporting files read fully: `routes/web.php` (all tenant routes + controller@method verification across all 35 files), `routes/api.php`, `config/permissions.php`, `app/Support/Access/AccessMap.php`, `app/Http/Middleware/EnforceModuleAccess.php`, all 21 associated FormRequests in `app/Http/Requests/Tenant/` (`ItemRequest`, `PaymentMethodTypeRequest`, `PerceptionRequest`, `PersonRequest`, `PersonTypeRequest`, `PromotionRequest`, `PurchaseImportRequest`, `PurchaseRequest`, `QuotationRequest`, `RetentionRequest`, `SaleNotePaymentRequest`, `SaleNoteRequest`, `SearchRequest`, `SeriesRequest`, `SummaryDocumentsRequest`, `SummaryRequest`, `TagRequest`, `TaskRequest`, `TributeConceptTypeRequest`, `UnitTypeRequest`, `UserRequest`), associated Resources/Collections in `app/Http/Resources/Tenant/` (`ItemCollection`, `ItemResource`, `ItemWarehouseCollection`, `OrderCollection`, `OrderResource`, `PaymentMethodTypeCollection`, `PaymentMethodTypeResource`, `PerceptionCollection`, `PerceptionResource`, `PersonCollection`, `PersonResource`, `PersonTypeCollection`, `PosCollection`, `PromotionCollection`, `PromotionResource`, `PurchaseCollection`, `PurchaseResource`, `QuotationCollection`, `QuotationResource`, `QuotationResource2`, `RetentionCollection`, `RetentionResource`, `SaleNoteCollection`, `SaleNotePaymentCollection`, `SaleNoteResource`, `SaleNoteResource2`, `SearchResource`, `SeriesCollection`, `SummaryCollection`, `SummaryResource`, `TagCollection`, `TagResource`, `TributeConceptTypeCollection`, `TributeConceptTypeResource`, `UnitTypeCollection`, `UnitTypeResource`, `UserCollection`, `UserResource`, `VoidedCollection`), models (`Item`, `Purchase`, `PurchaseItem`, `Quotation`, `SaleNote`, `SaleNoteItem`, `SaleNotePayment`, `Person`, `PersonAddress`, `Order`, `Task`, `Configuration`, `User`, `Summary`, `Voided`, `Perception`, `Retention`, `Series`, `Note`, `Document`, `Promotion`, `Tag`, `PaymentMethodType`, `Catalogs\PaymentMethodType`), traits/providers (`SearchTrait`, `InventoryTrait`, `SummaryTrait`, `FinanceTrait`, `StorageDocument`, `InventoryKardexServiceProvider`, `GlobalPaymentServiceProvider`, `AnulationServiceProvider`), `TenantCommand`, relevant migrations (`persons`, `purchase_items`, `purchases`, `sale_note_items`, `person_addresses`, `users`), and Vue consumers (`purchases/index.vue`, `sale_notes/form.vue`, `sale_notes/partials/{item,lots_group}.vue`, `persons/index.vue`, `orders/index.vue`).
- Not reviewed: CoreFacturalo internals beyond `StorageDocument`, Excel export/import internals, `modules/*` controllers beyond shared traits/providers and route checks.
- Controller methods analysed: 305 (across the 35 controllers, including constructors and private helpers).
- Working-tree note: While this read-only audit ran, parallel working-tree edits updated `ItemSetController`, `PromotionController` (adding `app/CoreFacturalo/Helpers/Storage/TempImageHelper.php`), and `LoginConfigurationController::uploadBgImage`; both the audited baseline and the remaining unpatched gaps in the dirty working tree are noted under `TC2-001` and `TC2-002`.

## Findings (ordered by severity)

### TC2-001 — `ItemSetController::store` & `PromotionController::store`: arbitrary local file read via `temp_path`, client-controlled extension, and SVG stored XSS (remediated in dirty working tree)
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes the `upload()` → `store()` payload contract if replaced by an opaque token; dropping SVG may affect existing promotional graphics)
- Location: `app/Http/Controllers/Tenant/ItemSetController.php:114-126, 216-258`; `app/Http/Controllers/Tenant/PromotionController.php:80-91, 119-161`
- Problem: At audit start (matching `HEAD`), both `store()` methods read an arbitrary `temp_path` from the request body with `file_get_contents($temp_path)` and wrote the contents into web-served public directories (`public/uploads/items/` and `public/uploads/promotions/`). The output extension came from `explode('.', $request->input('image'))[1]` (client string, never checked against MIME type), both `upload()` endpoints whitelisted `svg`, and `upload_image()` passed potentially null `$type` to `tempnam()` without cleaning up temp files. *(Concurrent working-tree diff now routes both controllers through `TempImageHelper::storeTemp()` and `TempImageHelper::resolveValidated()`, restricts extensions to `jpg,jpeg,png,gif,webp` verified by `getimagesize()` inside `sys_get_temp_dir()`, and unlinks the temp file on save; however, `temp_path` is still accepted from the client rather than session-bound.)*
- Evidence:
```php
// Audited HEAD baseline:
$temp_path = $request->input('temp_path');
if ($temp_path) {
    $file_name_old = $request->input('image');
    $file_name_old_array = explode('.', $file_name_old);
    $file_content = file_get_contents($temp_path);
    $file_name = Str::slug($item->description).'-'.$datenow.'.'.$file_name_old_array[1];
    Storage::put($directory.$file_name, $file_content);
}
```
- Failure scenario: An authenticated user submits `temp_path=/var/www/.../.env` (or a tenant certificate PEM) and `image=leak.txt`, publishing server secrets under `/storage/uploads/items/` or `/storage/uploads/promotions/`. Uploading an SVG with `<script>` yields stored XSS under the tenant origin; sending `image=x.php` stores a `.php` file; sending `image` without a dot crashes with `Undefined array key 1` (500).
- Proposed fix: Keep `TempImageHelper` validation and additionally bind issued `temp_path` / tokens to the user session (or dedicated per-session temp directory) so temp files created by other tenants/users in `sys_get_temp_dir()` cannot be referenced.
- Verification idea: Feature test posting `temp_path=/etc/hosts` (and a temp image owned by another session) to `POST /item-sets` and `POST /promotions` must fail with 422; uploading `a.php` or `x.svg` must be rejected.

### TC2-002 — `LoginConfigurationController`: arbitrary key mass-assignment into `configurations.login`, SVG stored XSS, null dereference, and AJAX bypass
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/LoginConfigurationController.php:18-43, 48-61, 63-88`; `app/Models/Tenant/Configuration.php:77-80`; `config/permissions.php:168`
- Problem:
  1. `update()` validates 8 keys, then loops `foreach (request()->all() as $key => $option)` instead of `validated()`, assigning arbitrary request keys onto `$config->login` (overwriting `type`, `image`, or injecting unexpected JSON properties).
  2. `uploadBgImage()` accepts `svg` (`mimes:webp,jpeg,png,jpg,gif,svg`) into `public/uploads/login` (and at `HEAD` used `$file->getClientOriginalExtension()`, allowing `.php` polyglots; a concurrent working-tree edit switched `uploadBgImage()` to `$file->extension()`, `firstOrFail()`, and `$config->login ?? new \stdClass()`, while leaving `svg` allowed).
  3. `resetBgImage()` and `update()` still do `$config = Configuration::first(); $loginConfig = $config->login;` then `$loginConfig->type = ...` / `$loginConfig->$key = ...` without null-checking `$config` or `$config->login` (`Configuration::getLoginAttribute()` returns `null` when `configurations.login` is `NULL`, throwing `Error: Attempt to assign property on null` in PHP 8).
  4. `config/permissions.php:168` still maps `login-page` to `configuration` without `'ajax' => true`, so any authenticated seller (or storefront client) can modify the tenant login page via AJAX.
- Evidence:
```php
foreach(request()->all() as $key => $option) {
    $loginConfig->$key = $option;
}
$config->login = $loginConfig;
$config->save();
```
- Failure scenario: A seller sends an XHR `POST /login-page/update` with `{..., "image": "https://evil.test/bg.png"}` or uploads an XSS SVG via `POST /login-page/upload-bg-image`, defacing the tenant login screen or executing scripts on the login page. On a tenant where `configurations.login` is `NULL`, `resetBgImage()` or `update()` throws 500.
- Proposed fix: Store `$request->validate(...)` return value and iterate only `$validated`; use `firstOrFail()` and `$config->login ?? new \stdClass()` in `resetBgImage()` and `update()`; drop `svg` from `uploadBgImage()`; add `'ajax' => true` to `login-page` in `config/permissions.php`.
- Verification idea: Test as seller with `X-Requested-With: XMLHttpRequest` expecting 403; test `POST /login-page/update` with extra `image` key expecting it ignored; test `reset-bg-image` and `update` with `configurations.login = null` expecting 200.

### TC2-003 — Unauthenticated `purchases/print` and `sale-notes/print` routes leak documents and crash with 500 when `{format}` is omitted
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (external links or thermal receipt integrations may rely on public `sale-notes/print`; adding `= 'a4'` default is SAFE)
- Location: `routes/web.php:21-22`; `app/Http/Controllers/Tenant/PurchaseController.php:255-266`; `app/Http/Controllers/Tenant/SaleNoteController.php:401-413`
- Problem: Both `GET sale-notes/print/{external_id}/{format?}` and `GET purchases/print/{external_id}/{format?}` sit outside the `auth` middleware group. In particular, `purchases/print` publicly exposes internal supplier invoices, costs, and purchase lines to anyone who obtains an `external_id`. In addition, both route signatures declare `{format?}` optional, but neither `PurchaseController::toPrint($external_id, $format)` nor `SaleNoteController::toPrint($external_id, $format)` defines a default value for `$format`. Both methods also create `tempnam()` files in `/tmp` and return `response()->file($temp)` without deleting them.
- Evidence:
```php
// routes/web.php:21-22 (outside auth middleware)
Route::get('sale-notes/print/{external_id}/{format?}', 'Tenant\SaleNoteController@toPrint');
Route::get('purchases/print/{external_id}/{format?}', 'Tenant\PurchaseController@toPrint');

// PurchaseController.php:255 / SaleNoteController.php:401
public function toPrint($external_id, $format) {
```
- Failure scenario: Requesting `/purchases/print/{external_id}` or `/sale-notes/print/{external_id}` without a trailing format segment throws `ArgumentCountError: Too few arguments` (HTTP 500). Unauthenticated callers with a purchase `external_id` can download internal purchase PDFs and trigger server-side mPDF regeneration + `/tmp` growth on each request.
- Proposed fix: Move `purchases/print` inside the `auth` + `redirect.module` group (and evaluate whether `sale-notes/print` should require auth or signed URLs); default `$format = 'a4'` in both `toPrint()` methods; use `deleteFileAfterSend(true)` or stream PDF bytes in memory (`response()->make(..., 200, ['Content-Type' => 'application/pdf'])`).
- Verification idea: Unauthenticated `GET /purchases/print/{uuid}/a4` must return 302/401; `GET /sale-notes/print/{uuid}` (no format) must not throw 500.

### TC2-004 — Unmapped and non-AJAX-enforced routes expose `persons`, `sale_note_payments`, `users`/catalogs, `tasks`, `orders`, `quotations`, and `sale-notes`
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (requires confirming which read endpoints sellers legitimately call across modules so `'module' => null` exceptions are preserved)
- Location: `config/permissions.php:43-44, 60, 62-63, 73-74, 78, 99, 162, 168`; `routes/web.php:50-63, 125-129, 179-188, 323-326, 364-367, 457-461, 507-535, 537-542, 553-556`
- Problem: Extending TC1-005 to part-2 controllers:
  1. Completely **unmapped** in `config/permissions.php` (`AccessMap::decide()` returns `reason: 'unmapped'` -> allowed even for non-AJAX): `sale_note_payments/*`, `series/*`, `payment_method/*`, `unit_types/*`, `tribute_concept_types/*`.
  2. Prefix mismatch on `persons`: `config/permissions.php` only maps `persons/customers` (`persons`) and `persons/suppliers` (`purchases`). All mutations and record endpoints (`POST /persons`, `DELETE /persons/{id}`, `GET /persons/record/{id}`, `POST /persons/import`, `GET /persons/enabled/{type}/{id}`, `GET /persons/{type}/exportation`, `GET /persons/tables`) do **not** match either rule and are open to any authenticated user (including storefront `client` users).
  3. Rules lacking `'ajax' => true`: `quotations`, `sale-notes`, `orders`, `tags`, `promotions`, `item-sets`, `person-types`, `tasks`, `login-page`. Every XHR mutation (`POST`, `DELETE`) on these controllers bypasses module checks.
- Evidence:
```php
// config/permissions.php:73, 99, 162
['path' => 'persons/customers', 'module' => 'persons'],
['path' => 'persons/suppliers', 'module' => 'purchases'],
['path' => 'tasks', 'module' => 'configuration'],
```
- Failure scenario: A restricted seller or storefront `client` user can create/delete customers and suppliers (`POST /persons`, `DELETE /persons/{id}`), register or delete sale note payments (`POST /sale_note_payments`), overwrite catalog payment/unit/attribute types, schedule Artisan tasks (`POST /tasks`), change order statuses (`POST /orders/...` via `orders` XHR), or annul quotations/sale notes (`GET /quotations/anular/{id}`, `GET /sale-notes/anulate/{id}` via AJAX).
- Proposed fix: Map `persons` (`module => 'persons'`, keeping `persons/suppliers` for `purchases` and explicitly scoping `POST/DELETE` by type or module), `sale_note_payments` (`documents`/`sale_notes`, `ajax: true`), `series`, `payment_method`, `unit_types`, `tribute_concept_types` (`configuration`, `ajax: true` where not needed cross-module), and add `'ajax' => true` to `quotations`, `sale-notes`, `orders`, `tags`, `promotions`, `item-sets`, `person-types`, `tasks`, `login-page` (with explicit `'module' => null` exceptions for cross-module lookup reads).
- Verification idea: Feature test as a seller lacking `persons`, `configuration`, and `sale_notes` modules expecting 403 JSON on `POST /persons`, `POST /sale_note_payments`, `POST /tasks`, `POST /payment_method`, and XHR `GET /sale-notes/anulate/1`.

### TC2-005 — `UserController::store`: any user with `establishments` access can modify the tenant owner, and sending blank password overwrites any user's password with `bcrypt('')`
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/UserController.php:43-124`; `app/Http/Requests/Tenant/UserRequest.php:14-31`
- Problem:
  1. In `UserController::store()`, `$isOwnerTarget` only suppresses permission syncing (`$syncPermissions`, lines 63 and 115). Lines 101–113 still run unconditionally: `$user->email`, `$user->name`, `$user->establishment_id`, `$user->type`, and `$user->password` (if `config('tenant.password_change')` is enabled) are overwritten on the tenant owner (`id == min('id')`).
  2. `UserRequest` sets `'password' => ['min:6', 'confirmed']` without `required` on create or `nullable` / `filled` guards. In Laravel's validator, `min:6` is skipped for `null` or `""`. Thus omitting `password` on create or sending `{"password": ""}` on create/edit passes validation; `store()` then runs `bcrypt($request->input('password'))`, setting the account password to the hash of an empty string (`""`).
  3. `UserRequest` also omits `email` format and `unique` rules (editing an email to an existing user's email triggers SQLSTATE `23000` 500), omits `Rule::in(['admin', 'seller'])` on `type`, and does not validate `establishment_id`. `store()` also runs `$user->save()` and permission `sync()` without a DB transaction.
- Evidence:
```php
// UserController.php:100-113
$user = User::firstOrNew(['id' => $id]);
$user->name = $request->input('name');
$user->email = $request->input('email');
$user->establishment_id = $request->input('establishment_id');
$user->type = $request->input('type');
if (!$id) {
    $user->api_token = str_random(50);
    $user->password = bcrypt($request->input('password'));
} elseif ($request->has('password')) {
    if (config('tenant.password_change')) {
        $user->password = bcrypt($request->input('password'));
    }
}
```
- Failure scenario: A sub-admin granted `establishments` module access edits `id = <ownerId>` to change the owner's email or password (account takeover) or change `type` to `'seller'`, or creates/edits any user with `password: ""` leaving a known empty password.
- Proposed fix: Forbid non-owner users from editing the owner record (`abort_if($isOwnerTarget && auth()->id() !== $ownerId, 403)` and prevent changing owner `type`); require `password` on create (`required|string|min:6|confirmed`) and on update use `filled('password')` with `'nullable|string|min:6|confirmed'`; validate `email` (`email|unique`), `type` (`in:admin,seller`), and `establishment_id` (`exists`); wrap save + sync in `DB::connection('tenant')->transaction(...)`.
- Verification idea: Test sub-admin `POST /users` with owner `id` → 403; test `POST /users` with `password: ""` on create and edit → 422 on create, password unchanged on edit.

### TC2-006 — `PurchaseController::anular` & `delete` and `SaleNoteController::anulate`: missing state guards allow double stock corruption and bypass `PurchaseItem::deleted`
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (fiscal/inventory annulment & deletion rules; guarding against `state_type_id === '11'` / `!== '11'` should match business policy)
- Location: `app/Http/Controllers/Tenant/PurchaseController.php:419-458, 658-683`; `app/Http/Controllers/Tenant/SaleNoteController.php:947-1022, 1127-1158`; `database/migrations/tenant/2019_02_12_000005_tenant_purchase_items_table.php:52`; `app/Models/Tenant/Purchase.php:13`
- Problem:
  1. `PurchaseController::anular($id)` never checks whether `$obj->state_type_id === '11'`. Calling `GET /purchases/anular/{id}` twice subtracts `$item->quantity` from `item_warehouses.stock` and creates negative `inventory_kardex` rows twice. Furthermore, unlike `PurchaseItem::created` in `InventoryKardexServiceProvider:53`, `anular()` does not multiply by `$presentationQuantity`, leaves `ItemLotsGroup` records intact (deleting only `$it->lots()`), and crashes on null `$obj` or `$wr`.
  2. `PurchaseController::delete($id)` calls `$row->delete()` on `Purchase` (`SoftDeletes` is commented out) with no check that `$row->state_type_id === '11'` (the Vue UI only shows `Eliminar` when `state_type_id == '11'`, `purchases/index.vue:123`). Because `purchase_items.purchase_id` has `ON DELETE CASCADE`, deleting an un-annulled purchase deletes `purchase_items` directly in SQL without firing `PurchaseItem::deleted` in `InventoryKardexServiceProvider:334`, leaving `item_warehouses.stock` inflated and `inventory_kardex` / `item_lots` / `item_lots_group` orphaned.
  3. `SaleNoteController::anulate($id)` never checks `$obj->state_type_id === '11'` (or null `$obj`): calling `GET /sale-notes/anulate/{id}` multiple times restores `item_warehouses.stock`, inserts positive `inventory_kardex` entries, and increments `ItemLotsGroup` quantity on every call. In addition, `voidedLots()` and `InventoryTrait::deleteItemLots()` look up `ItemLotsGroup` via `$row->checked` inside `$item->item->lots_group`, whereas `SaleNoteController::store()` decrements via `$row['IdLoteSelected']` and `sale_notes/partials/item.vue:556` attaches `IdLoteSelected` on the row (not guaranteed to set `checked` in `item->lots_group`).
- Evidence:
```php
// PurchaseController.php:437-449 (no check for $obj->state_type_id === '11')
$obj->state_type_id = 11;
$obj->save();
foreach ($obj->items as $item) {
    ...
    $wr->stock = $wr->stock - $item->quantity;
    $wr->save();
}
```
```php
// SaleNoteController.php:952-969 (no check for $obj->state_type_id === '11')
$obj = SaleNote::find($id);
$obj->state_type_id = 11;
$obj->save();
foreach ($obj->items as $sale_note_item) {
    $this->voidedSaleNoteItem($sale_note_item, $warehouse);
    $this->voidedLots($sale_note_item);
}
```
- Failure scenario: Double-clicking or re-requesting `anular/{id}` / `anulate/{id}` corrupts warehouse stock; deleting an active purchase via `GET /purchases/delete/{id}` removes the purchase record while keeping all purchased stock in inventory.
- Proposed fix: Reject `anular` / `anulate` with 422 when `state_type_id === '11'` (lock row with `lockForUpdate()`); reject `PurchaseController::delete` unless `state_type_id === '11'` (or delete items individually inside transaction); align `anular()` with `$presentationQuantity` and `ItemLotsGroup` cleanup; reconcile `IdLoteSelected` in `voidedLots()` / `deleteItemLots()`.
- Verification idea: Calling `anular` or `anulate` twice on the same id must change stock only once; `delete` on an active purchase must fail or revert stock cleanly.

### TC2-007 — `SaleNoteController::store` & `mergeData`: edit path corrupts inventory/lots/`external_id` and creates numbering race condition
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (sale note edit/inventory semantics need business sign-off; locking numbering and scoping `SaleNoteItem` by `sale_note_id` are SAFE)
- Location: `app/Http/Controllers/Tenant/SaleNoteController.php:218-285, 331-385`; `app/Http/Requests/Tenant/SaleNoteRequest.php:16-37`; `modules/Inventory/Providers/InventoryKardexServiceProvider.php:135-229`
- Problem:
  1. **Numbering race & null deref**: `mergeData()` runs `Series::find($inputs['series_id'])->number` without a null check, then reads `SaleNote::select('number')->...->orderBy('number', 'desc')->first()` without `lockForUpdate()`, causing duplicate `series-number` under concurrent requests.
  2. **Broken edit semantics**: When `$request->input('id')` is present:
     - `mergeData()` overwrites `external_id` with a new UUID (breaking printed/shared external links), resets `state_type_id` to `'01'`, overwrites `user_id`, and trusts client-supplied `number`.
     - `SaleNoteItem::firstOrNew(['id' => $item_id])` never constrains `sale_note_id`, allowing another sale note's line item to be reassigned.
     - Existing `SaleNoteItem` rows fire `updated`, not `created` (`InventoryKardexServiceProvider` only listens to `SaleNoteItem::created`/`deleted`), so changing an existing line item's quantity on edit never adjusts `item_warehouses.stock` or `inventory_kardex`.
     - Despite that, lines 256–261 still execute `ItemLotsGroup::find($row['IdLoteSelected'])` (without null check) and subtract `$row['quantity']` **again** from `$lot->quantity` on every edit—including when `sale_notes/form.vue:429-435` deletes one item and immediately POSTs the form again.
  3. **Incomplete validation**: `SaleNoteRequest` does not validate `items`, `payments`, or `establishment_id` (missing keys crash inside the transaction).
- Evidence:
```php
// SaleNoteController.php:236-261
$sale_note_item = SaleNoteItem::firstOrNew(['id' => $item_id]);
...
$sale_note_item->sale_note_id = $this->sale_note->id;
$sale_note_item->save(); // fires updated (no stock move) when $item_id exists
if (isset($row['IdLoteSelected'])) {
    $lot = ItemLotsGroup::find($row['IdLoteSelected']);
    $lot->quantity = ($lot->quantity - $row['quantity']); // subtracted again on every edit
    $lot->save();
}
```
- Failure scenario: Editing a sale note with lot-tracked items repeatedly drains `item_lots_group.quantity`; editing an item's quantity leaves warehouse stock wrong; concurrent POS/sale-note saves produce duplicate numbers; omitting `items` in an API/XHR payload throws a 500 `foreach` TypeError.
- Proposed fix: Only generate `external_id`, `state_type_id`, and `number` (using `lockForUpdate()`) on create; scope `SaleNoteItem` lookup with `where('sale_note_id', $this->sale_note->id)`; diff/reconcile stock & lot quantities on item update (or delete and recreate items cleanly); validate `items`, `payments`, `establishment_id`, and `series_id` in `SaleNoteRequest`.
- Verification idea: Update an existing sale note changing item quantity from 2 to 5 on a lot-tracked product; verify `item_warehouses.stock` and `item_lots_group.quantity` decrease by 3 (not 0 or 5), and `external_id` stays unchanged.

### TC2-008 — `SummaryController::destroy` & `VoidedController::destroy`: unguarded deletion of accepted SUNAT records flips document states
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (fiscal/SUNAT rules)
- Location: `app/Http/Controllers/Tenant/SummaryController.php:87-102`; `app/Http/Controllers/Tenant/VoidedController.php:113-128`
- Problem: Neither `SummaryController::destroy()` nor `VoidedController::destroy()` checks `state_type_id` (e.g., whether SUNAT already accepted the summary/voided communication with `'05'`) or wraps its multi-table updates in a DB transaction. Both use `find()` without null checks and trigger N+1 updates across `$record->documents`.
- Evidence:
```php
// SummaryController.php:89-96
$summary = Summary::find($voided_id);
foreach ($summary->documents as $doc) {
    $doc->document->update([
        'state_type_id' => ($summary->summary_status_type_id === '1') ? '01' : '05'
    ]);
}
$summary->delete();
```
- Failure scenario: Deleting an accepted voided communication (`state_type_id === '05'`) or voiding summary (`summary_status_type_id === '3'`) removes the SUNAT void record and sets every voided invoice/boleta back to `'05'` (accepted), creating a fiscal discrepancy with SUNAT. Deleting a missing id fatals on `$summary->documents` / `$document->documents`.
- Proposed fix: Reject deletion when `state_type_id === '05'` (or require state `'01'`/`'09'`); use `findOrFail`, eager load `documents.document`, and wrap in `DB::connection('tenant')->transaction(...)`. (Also note `PerceptionController::destroy:134-143` deletes a `Perception` without any state guard or `try/catch`.)
- Verification idea: `DELETE /voided/{id}` and `DELETE /summaries/{id}` for a record in `state_type_id = '05'` must return 422 and leave document states unchanged.

### TC2-009 — `PerceptionController::tables` crashes with 500 `Undefined variable $user_id`
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/PerceptionController.php:56-69`
- Problem: Line 68 calls `compact('user_id', ...)`, but `$user_id` is never defined in `tables()`. Under PHP 8.1+/Laravel 10 `HandleExceptions`, `compact()` with an undefined variable raises an `ErrorException` (500).
- Evidence:
```php
return compact('user_id', 'currency_types', 'customers', 'items', 'company', 'establishments','document_types', 'series', 'perception_types');
```
- Failure scenario: Opening `GET /perceptions/tables` to populate the new perception form fails with HTTP 500.
- Proposed fix: Define `$user_id = auth()->id();` or remove `'user_id'` from `compact()` if unused by the Vue component.
- Verification idea: Feature test `GET /perceptions/tables` returns 200 JSON.

### TC2-010 — `OrderController::updateStatusOrders`, `item`, and `searchWarehouse`: crashes on missing inputs and unguarded stock subtraction
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/OrderController.php:60-84, 93-125`
- Problem:
  1. `updateStatusOrders()` has no request validation: when `record['status_order_id'] == 3` and `discount` is missing/null (e.g., `orders/index.vue:268` only sends `{ record }` when not discounting), `count($request->discount)` throws `TypeError` in PHP 8.
  2. Inside the discount loop, `$itemWarehouse` is not null-checked, the stock check (`//if ($itemWarehouse->stock >= ...)`) is commented out, `cantidad` is not checked to be `> 0` (negative values increase stock), there is no DB transaction or row lock, and re-submitting status `3` subtracts stock again without checking the order's current `status_order_id`.
  3. `item($internal_id)` fails to null-check `$warehouse` and `$row` (`Item`), and `searchWarehouse()` passes unvalidated `$request->item_id` straight to `whereIn()` (`TypeError` if not an array).
- Evidence:
```php
if ($request->record['status_order_id'] == 3) {
    for ($i=0; $i <= count($request->discount)-1; $i++) {
        if (isset($request->discount[$i]['id'])) {
            $itemWarehouse = ItemWarehouse::where('id', $request->discount[$i]['id'])->first();
            //if ($itemWarehouse->stock >= $request->discount[$i]['cantidad']) {
            ItemWarehouse::where('id', $itemWarehouse->id)->update(['stock' => ($itemWarehouse->stock - $request->discount[$i]['cantidad'])]);
            //}
        }
    }
```
- Failure scenario: Posting `status_order_id = 3` without `discount` crashes 500; posting negative `cantidad` adds stock; posting twice double-deducts stock; unknown `internal_id` crashes `OrderController::item`.
- Proposed fix: Validate `record.id` (`exists`), `record.status_order_id` (`exists`), `discount` (`nullable|array`), `discount.*.id` (`exists`), `discount.*.cantidad` (`numeric|gt:0`); wrap in `DB::connection('tenant')->transaction(...)` with `lockForUpdate()`, guard against repeated status-3 transitions, and null-check `$warehouse`/`$row`.
- Verification idea: Test `POST /statusOrder/update` with `status_order_id: 3` and no `discount` (must not 500) and with `cantidad: -5` (422).

### TC2-011 — `PersonController`: `=!` assignment bug always outputs `true` for `api_service_token`, broken `document_type` filter, undefined variable on 404 destroy, and broken `addresses` sync / unique check
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (for `=!`, `destroy`, `columns`, `PersonRequest` unique rule, transaction; verify whether API clients omit `addresses`)
- Location: `app/Http/Controllers/Tenant/PersonController.php:25-49, 78-127`; `app/Http/Controllers/Tenant/PersonTypeController.php:84`; `app/Http/Requests/Tenant/PersonRequest.php:41-87`
- Problem:
  1. Line 28 writes `$configuration->token_apiruc =! '' ? ...` (`=!` instead of `!=`), assigning boolean `true` (`!''`) to `$configuration->token_apiruc` and always passing `$api_service_token = true` (`1`) to `tenant.persons.index`.
  2. `columns()` exposes `'document_type' => 'Tipo de documento'`, and `records()` runs `Person::where($request->column, 'like', ...)`—but `persons` has `identity_document_type_id`, not `document_type`, so filtering by it throws SQLSTATE `42S22: Unknown column 'document_type'`.
  3. In `PersonController::destroy()` (line 123) and `PersonTypeController::destroy()` (line 84), `$person_type` / `$person_type_type` is assigned *after* `findOrFail($id)`, so a 404 inside `try` enters `catch (Exception $e)` with an undefined variable (`ErrorException` 500).
  4. `store()` deletes `$person->addresses()->delete()` before looping `$request->input('addresses')` with no DB transaction and no `addresses` validation in `PersonRequest` (`foreach (null)` crashes after existing addresses were deleted). Because `person_addresses` has no `SoftDeletes`, `updateOrCreate(['id' => $row['id']], $row)` can never update previously deleted rows (and crashes if `'id'` key is omitted).
  5. `PersonRequest` lines 50 & 57 use `->where('id', '<>', $id)`. On create (`$id === null`), SQL `id <> NULL` is always unknown/false, so duplicate `number` or `name` validation never triggers.
- Evidence:
```php
// PersonController.php:28
$api_service_token = $configuration->token_apiruc =! '' ? $configuration->token_apiruc : config('configuration.api_service_token');

// PersonController.php:112-123
$person = Person::findOrFail($id);
$person_type = ($person->type == 'customers') ? 'Cliente':'Proveedor';
...
} catch (Exception $e) {
    return ... "Error inesperado, no se pudo eliminar el {$person_type}";
```
- Failure scenario: Persons index never receives the configured API RUC token; selecting "Tipo de documento" filter crashes the listing; `DELETE /persons/999999` or `DELETE /person-types/999999` returns 500 instead of 404/422; saving a person without `addresses` deletes all existing addresses then crashes 500.
- Proposed fix: Change `=!` to `!=`; remove `'document_type'` from `columns()` or map it to `identity_document_type_id` / `whereHas`; initialize `$person_type = 'Registro'` before `findOrFail` (or catch `ModelNotFoundException` separately); validate `addresses` (`nullable|array`) and `type` (`in:customers,suppliers`), wrap `store()` in a transaction without blind `delete()` before `updateOrCreate`; use `when($id, fn($q) => $q->where('id', '<>', $id))` in `PersonRequest`.
- Verification idea: Test `GET /persons/customers/records?column=document_type&value=RUC`, `DELETE /persons/999999`, and `POST /persons` omitting `addresses`.

### TC2-012 — Ungrouped `orWhere` / `orWhereHas` breaks active, warehouse, set, and customer filters (5 places)
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/PosController.php:62-75, 331-342`; `app/Http/Controllers/Tenant/PurchaseController.php:558-563`; `app/Http/Controllers/Tenant/QuotationController.php:144-148, 469-479`; `app/Http/Controllers/Tenant/SaleNoteController.php:138-142`; `modules/Document/Traits/SearchTrait.php:28-43, 61-74`; `app/Models/Tenant/Item.php:159-169`
- Problem: Chaining top-level `orWhere` / `orWhereHas` / `OrWhereJsonContains` clauses alongside `whereIsActive()`, `whereWarehouse()`, `whereNotIsSet()`, `whereTypeUser()`, `where('series_enabled', 0)`, `where('unit_type_id', 'ZZ')`, `whereType('customers')`, and `whereIsEnabled()` causes SQL `AND` to bind tighter than `OR`, applying those filters only to the final `OR` branch. In addition, `PosController::search_items_cat:342` passes `config(50)` (evaluates to `null`) to `->paginate(...)`.
- Evidence:
```php
// PurchaseController.php:558-563
Item::where('description','like', "%{$request->input}%")
    ->orWhere('internal_id','like', "%{$request->input}%")
    ->whereNotIsSet()
    ->whereIsActive()
```
```php
// QuotationController.php:144-148 & SaleNoteController.php:138-142
Person::where('number','like', "%{$request->input}%")
    ->orWhere('name','like', "%{$request->input}%")
    ->whereType('customers')->orderBy('name')->whereIsEnabled()
```
- Failure scenario:
  - `PosController::search_items` & `search_items_cat` return inactive items, series-enabled items, and items from other establishments' warehouses whenever `description`, `internal_id`, `barcode`, or `category` matches.
  - `PurchaseController::searchItems` returns disabled items and item sets.
  - `QuotationController::searchCustomers` and `SaleNoteController::searchCustomers` return suppliers and disabled customers whenever `number` matches.
  - `QuotationController::searchItems` and `SaleNoteController::searchItems` (via `SearchTrait::getItemsServices` and `getItemsNotServices`) return inactive items, sets, and items outside the seller's warehouse (and `Item::scopeWhereWarehouse` also leaks inactive `ZZ` services via ungrouped `->orWhere('unit_type_id', 'ZZ')`).
- Proposed fix: Wrap all search `OR` conditions inside `->where(function ($q) { ... })` (including `scopeWhereWarehouse`), and pass `50` instead of `config(50)` in `PosController::search_items_cat`.
- Verification idea: Seed an inactive item and a supplier whose `description`/`number` matches the query; assert neither appears in item/customer search endpoints.

### TC2-013 — `PosController::validate_stock`: set items ignore component quantity (`$set->quantity`) and lack null/positive-quantity checks
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/PosController.php:248-317`
- Problem: When validating stock for a composite item (`$item->is_set`), line 270 computes `$stock = $item_warehouse->stock - $quantity;` without multiplying `$quantity` by `$set->quantity`. Also `$warehouse` (`Warehouse::where(...)->first()`) is dereferenced without null check (`$warehouse->id`), and `$quantity` from the route parameter is not validated to be numeric and `> 0`.
- Evidence:
```php
foreach ($sets as $set) {
    $individual_item = $set->individual_item;
    $item_warehouse = ItemWarehouse::where([['item_id',$individual_item->id], ['warehouse_id',$warehouse->id]])->first();
    ...
    $stock = $item_warehouse->stock - $quantity;
```
- Failure scenario: A set requiring 5 units of component A passes POS stock validation when component A only has stock 1 and the cashier sells 1 set; negative `$quantity` always passes validation; a user whose establishment has no warehouse gets a 500.
- Proposed fix: Multiply by `($set->quantity ?: 1)` (`$stock = $item_warehouse->stock - ($quantity * ($set->quantity ?: 1));`), guard `if (!$warehouse)` and `if (!is_numeric($quantity) || $quantity <= 0)` with 422 JSON.
- Verification idea: Test `GET /pos/validate_stock/{set_id}/1` where set requires 5 units and component has 2 units → must return `success: false`.

### TC2-014 — `SaleNotePaymentController`: missing payment validation, duplicate `global_payments` on edit, conflicting `total_canceled` logic, and broken `a5` PDF format
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (for validation, `findOrFail`, and `createPdf` format handling; RISKY for changing the `< 1` vs `== 0` `total_canceled` rule)
- Location: `app/Http/Controllers/Tenant/SaleNotePaymentController.php:42-63, 65-92, 112-213`; `app/Http/Requests/Tenant/SaleNotePaymentRequest.php:15-33`
- Problem:
  1. `SaleNotePaymentRequest` validates `payment` only as `required` (no `numeric`, `gt:0`, or pending-balance check) and does not validate `sale_note_id`.
  2. `store()` calls `$this->createGlobalPayment($record, $request->all())` unconditionally, which uses `morphOne()->create(...)`, duplicating `global_payments` rows whenever an existing payment (`$id` set) is edited.
  3. `document()` sets `total_canceled = true` when `$total_difference < 1`, whereas `createPdf()` overwrites `total_canceled = ($total_difference == 0)`—even overriding `$request->paid == true` from `store()` lines 79–84.
  4. `createPdf($sale_note_id, $format)` passes hardcoded `"a4"` to `$template->pdf(..., "a4")` regardless of `$format`, and when `config('tenant.pdf_name_regular')` is set (line 168), `new Mpdf([...])` overwrites the `a5` `Mpdf` instance created at line 135, losing `[78, 220]` dimensions and margins. Both `document()` and `createPdf()` call `SaleNote::find($sale_note_id)` without null checks, and `toPrint()` leaks `tempnam()` files in `/tmp`.
- Evidence:
```php
if ($format == 'a5') {
    $pdf = new Mpdf(['mode' => 'utf-8', 'format' => [78, 220], ...]);
} else { $pdf = new Mpdf(); }
...
$html = $template->pdf($base_template, "sale_note", $company, $document, "a4");
if ($pdf_font_regular != false) {
    ...
    $pdf = new Mpdf(['fontDir' => ..., 'fontdata' => ...]); // overwrites $pdf format & margins
}
```
- Failure scenario: Negative or excessive payments can be submitted via API; editing a payment creates duplicate cash/bank inflows in Finance reports; A5 sale note payment PDFs render with A4 template and default margins when custom fonts are enabled; invalid `sale_note_id` crashes 500.
- Proposed fix: Validate `sale_note_id` (`exists`), `payment` (`numeric|gt:0` and `<= pending`), `updateOrCreate` `global_payment` on edit, pass `$format ?? 'a4'` to `$template->pdf()`, merge custom font config into the `$format == 'a5'` mPDF options, use `findOrFail`, and stream PDF bytes instead of leaving `/tmp` files.
- Verification idea: Test overpayment and negative payment (`422`); edit payment and verify `global_payments` count stays `1`; print `a5` with custom font enabled.

### TC2-015 — `SeriesController::store` rejects edits to existing series; catalog code requests ignore the inserted primary key
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/SeriesController.php:31-51`; `app/Http/Requests/Tenant/{PaymentMethodTypeRequest,TributeConceptTypeRequest,UnitTypeRequest}.php:15-30`
- Problem:
  1. `SeriesController::store()` queries `Series::where([['document_type_id', $request->document_type_id], ['number', $request->number]])->first()` without excluding `$id`. Editing any existing series (e.g., changing `contingency` or `establishment_id`) hits `if ($record)` and fails with `"La serie ya ha sido registrada"`.
  2. Conversely, `PaymentMethodTypeRequest`, `TributeConceptTypeRequest`, and `UnitTypeRequest` all pass `Rule::unique(...)->ignore($id)` where `$id = $this->input('id')` is the **user-supplied string code** (non-auto-incrementing PK such as `'01'`, `'NIU'`). Ignoring `$id` disables uniqueness checks on creation, so `firstOrNew(['id' => $id])` silently overwrites an existing catalog entry instead of returning 422.
- Evidence:
```php
// SeriesController.php:33-42
$record = Series::where([['document_type_id',$request->document_type_id],['number', $request->number]])->first();
if ($record) {
    return ['success' => false, 'message' => 'La serie ya ha sido registrada'];
}
$id = $request->input('id');
```
```php
// UnitTypeRequest.php:17-22 (same in PaymentMethodTypeRequest, TributeConceptTypeRequest)
$id = $this->input('id');
return ['id' => ['required', Rule::unique('tenant.cat_unit_types')->ignore($id)], ...];
```
- Failure scenario: Admins cannot edit a series; creating a duplicate payment method, attribute type, or unit type with an existing code silently overwrites the existing row.
- Proposed fix: In `SeriesController::store()`, add `->when($id, fn($q) => $q->where('id', '!=', $id))` (also enforce unique `number` + `document_type_id` in `SeriesRequest`); for the three catalog requests, only apply `->ignore(...)` on update (e.g., pass an `is_update` flag or separate create/update rules).
- Verification idea: Update an existing series (`contingency` toggle) expecting `success: true`; POST a duplicate `id` to `unit_types` as new record expecting 422.

### TC2-016 — `PurchaseController` & `QuotationController` `update` / `import`: incomplete validation, `firstOrNew` creating partial rows, and broken purchase lot-group handling
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (business rules for editing purchases/quotations and lot matching)
- Location: `app/Http/Controllers/Tenant/PurchaseController.php:171-245, 272-366, 391-408, 745-805`; `app/Http/Controllers/Tenant/QuotationController.php:239-300`; `app/Http/Requests/Tenant/{PurchaseRequest,PurchaseImportRequest,QuotationRequest}.php`; `modules/Inventory/Traits/InventoryTrait.php:393-425`
- Problem:
  1. `PurchaseRequest`, `PurchaseImportRequest`, and `QuotationRequest` do not validate `items` or `payments` arrays (nor `establishment_id`, `currency_type_id`, `document_type_id`, `supplier_ruc`, `total`). Omitting `items` or `payments` crashes `foreach` inside DB transactions.
  2. `PurchaseController::update()` and `QuotationController::update()` use `firstOrNew(['id' => $request['id']])`: an invalid/missing `id` attempts to insert a brand-new row lacking `external_id`, `soap_type_id`, `state_type_id`, etc. (500 SQL error). `PurchaseController::update()` also omits `'state' => $lot['state']` on lots (line 307 vs `store()` line 197), creates new `ItemLotsGroup` rows unconditionally without checking `state_type_id != '11'`, and line 273 of `QuotationController::update()` executes a dead `Configuration::select('terms_condition')->first()` query and skips `createPdf()`.
  3. `PurchaseController::verifyHasSaleItems()` and `InventoryTrait::verifyHasSaleLotsGroup()`/`deleteItemSeriesAndGroup()` match `ItemLotsGroup::where('code', $lot_code)` without `item_id`, colliding if two products share a lot code.
- Evidence:
```php
// PurchaseController.php:277 & QuotationController.php:276
$doc = Purchase::firstOrNew(['id' => $request['id']]);
```
```php
// PurchaseController.php:393
$lot_group = ItemLotsGroup::where('code', $element->lot_code)->first();
```
- Failure scenario: Missing `items`/`payments` crashes with 500; updating a purchase with a non-existent `id` hits NOT NULL DB errors; two items sharing a lot code cause `anular` or item deletion to check/delete the wrong item's lot group.
- Proposed fix: Validate `items` (`required|array|min:1`), `payments` (`nullable|array`), and header foreign keys in the FormRequests; use `findOrFail($request->input('id'))` in `update()` and reject editing annulled purchases; scope `ItemLotsGroup` lookups by both `item_id` and `code`.
- Verification idea: Test `POST /purchases/update` with non-existent `id` → 404; missing `items` → 422; two items with same `lot_code` → annulment checks each item's own lot group.

### TC2-017 — External SUNAT SOAP calls held inside DB transactions (`VoidedController`, `RetentionController`, `PerceptionController`, `SummaryTrait`)
- Category: performance
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (error handling and rollback semantics around XML persistence vs SUNAT response)
- Location: `app/Http/Controllers/Tenant/VoidedController.php:50-111`; `app/Http/Controllers/Tenant/PerceptionController.php:113-122`; `app/Http/Controllers/Tenant/RetentionController.php:108-117`; `app/Traits/SummaryTrait.php:11-20, 30-47`; `config/permissions.php:127`
- Problem: `PerceptionController::store()`, `RetentionController::store()`, `VoidedController::{store, status, status_masive}`, and `SummaryTrait::{save, query}` execute synchronous SUNAT SOAP requests (`senderXmlSignedBill`, `senderXmlSignedSummary`, `statusSummary`) inside `DB::connection('tenant')->transaction(...)`. In particular, `VoidedController::status_masive()` holds a single transaction open across a loop over all pending `Voided` records (`state_type_id == '03'`), and `voided/status_masive` is mapped `'module' => null` in `config/permissions.php:127` (open to any user via GET).
- Evidence:
```php
// VoidedController.php:94-105
$records = Voided::where('state_type_id', '03')->get();
$fact = DB::connection('tenant')->transaction(function () use($records) {
    foreach ($records as $document) {
        ...
        $facturalo->statusSummary($document->ticket);
    }
});
```
- Failure scenario: Slow or unreachable SUNAT endpoints hold DB locks/transactions open across network timeouts and roll back previously persisted documents/XML files on a single SOAP exception; in `status_masive()`, failure on item N rolls back ticket updates for items 1..N-1.
- Proposed fix: Persist local records in a short transaction, execute the SUNAT SOAP call outside the transaction, then update status/CDR in a second short transaction; restrict `voided/status_masive` to POST/admin or appropriate module permission.
- Verification idea: Simulate SOAP timeout on the 2nd item in `status_masive()` and verify item 1's status update is preserved without holding long DB locks.

### TC2-018 — N+1 query explosions and unbounded `->get()` loads across controllers and Resources
- Category: n+1/sql
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location:
  - `app/Http/Controllers/Tenant/PersonController.php:154-185` (`getLocationCascade`)
  - `app/Http/Controllers/Tenant/ReportConsistencyDocumentController.php:29-65` (`lists`)
  - `app/Http/Resources/Tenant/PosCollection.php:20, 23, 46-57` & `app/Http/Controllers/Tenant/PosController.php:58-122, 156-224`
  - `app/Http/Resources/Tenant/VoidedCollection.php:42`
  - `app/Http/Resources/Tenant/QuotationCollection.php:25-27, 57, 62, 68-70` & `QuotationResource.php:69`
  - `app/Http/Resources/Tenant/SaleNoteCollection.php:44, 65-66` & `SaleNoteController.php:919-931, 1025-1050`
  - `app/Http/Resources/Tenant/PurchaseCollection.php:65-72` & `PurchaseResource.php:20, 95`
  - `app/Http/Controllers/Tenant/NoteController.php:25-44`
  - `app/Http/Controllers/Tenant/OptionController.php:72-74, 100-113`
  - `app/Http/Resources/Tenant/{SeriesCollection:20, OrderCollection, ItemWarehouseCollection:20-22, PerceptionCollection, RetentionCollection}`
  - Unbounded `->get()` / `all()` tables: `ItemSetController::item_tables:82`, `PromotionController::tables:36`, `PerceptionController::table:83,96`, `RetentionController::table:81`, `PurchaseController::table('suppliers'):482`, `SaleNoteController::dispatches:921`
- Problem:
  1. `PersonController::getLocationCascade()` (called on every `persons/tables`) loops all active `Department`s, lazy-loading `$department->provinces` (~25 queries) and `$province->districts` (~196 queries), >220 queries on top of `tables()` already loading all departments, provinces, and districts at lines 59–61.
  2. `ReportConsistencyDocumentController::lists()` eager-loads `Series::with(['documents' => ...])`, then ignores the eager-loaded collection and runs **8 separate DB queries per series** (`$serie->documents()->where('state_type_id', ...)->pluck(...)`, with line 48 duplicating line 47), bypassing the `whereBetween('created_at', ...)` filter applied in the `with()` closure so `start`/`end`/`diff` use this month's documents while status arrays use all-time documents; `range($start, $end)` also risks huge memory allocation on wide gaps.
  3. `PosCollection` executes `Configuration::first()` and `$row->getStockByWarehouse()` (`Warehouse::where(...)`) **inside the transform loop** per item, and lazy-loads `sets.individual_item` and `warehouses.warehouse` (`PosController::search_items` and `table('items')` do the same, with `search_items` and `table('customers')` also unbounded).
  4. `VoidedCollection` runs `StateType::find($row->state_type_id)->description` per row (N+1 + null deref).
  5. `QuotationCollection` lazy-loads `documents`, `sale_notes`, `contract`, and `sale_opportunity` per row (4 N+1 queries per row; none are in `Quotation::$with`); `QuotationResource` runs `Item::find($item->id)` per line item.
  6. `SaleNoteCollection` lazy-loads `documents`, `quotation`, and `quotation.sale_opportunity` per row; `SaleNoteController::totals()` runs an unbounded `$this->getRecords($request)->get()` loading every `SaleNote` with 6 `$with` relations and sums all currencies/states (including USD and annulled notes) under `total_pen`.
  7. `PurchaseCollection` lazy-loads `$row->item` per `PurchaseItem` (`PurchaseItem::$with` excludes `item`); `PurchaseResource` re-queries `Purchase::find($this->id)` and queries `Warehouse` per item; `NoteController::hasDocuments` lazy-loads `document.document_type` per `Note`; `OptionController::deleteInventoryKardex` loads full models via `->get()` and runs 1 `DELETE` per row.
- Proposed fix: Build location cascade from the already-fetched collections or eager-load `provinces.districts`; compute `ReportConsistencyDocumentController` status buckets in PHP from the eager-loaded `documents` collection and drop duplicate line 48; hoist `Configuration::first()` and user `Warehouse` outside collection loops; add `->with([...])` in `QuotationController`, `SaleNoteController`, `PurchaseController`, `NoteController`, `SeriesController`; preload `StateType` map in `VoidedCollection`; aggregate `SaleNoteController::totals()` in SQL; replace per-row deletes in `OptionController` with `InventoryKardex::where('inventory_kardexable_type', $model)->whereIn('inventory_kardexable_id', ...)->delete()`.
- Verification idea: Enable DB query logging on `GET /persons/tables`, `POST /reports/consistency-documents/lists`, `GET /pos/items`, `GET /quotations/records`, and `GET /voided/records` and assert constant query counts independent of page size.

### TC2-019 — `SearchController::store`: public endpoint throws unhandled `Exception` (500) leaking customer existence
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/SearchController.php:26-53`; `routes/web.php:12-15`
- Problem: On the unauthenticated public `/search` route, when no customer matches `customer_number`, line 32 throws `new Exception('El número del cliente ingresado no se encontró en la base de datos.')`. Via `Handler.php`, this produces HTTP 500 with file/line/message details, whereas a missing document returns 200 `{success: false, message: 'El documento no fue encontrado.'}`—both crashing with 500 and allowing anonymous callers to enumerate whether a RUC/DNI exists in the tenant's customer table.
- Evidence:
```php
if (!$customer) {
    throw new Exception('El número del cliente ingresado no se encontró en la base de datos.');
}
```
- Failure scenario: Anonymous POST to `/search` with an unknown `customer_number` returns a 500 error trace; iterating numbers reveals registered customers.
- Proposed fix: Return the same uniform `['success' => false, 'message' => 'El documento no fue encontrado.']` response (or 404 JSON) when `!$customer`, avoiding `throw new Exception`.
- Verification idea: `POST /search` with an unknown `customer_number` must return HTTP 200 `{success: false}` (or 404) without throwing 500.

### TC2-020 — `SaleNoteController::savePayments`: overpayment can store negative payment amounts
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/SaleNoteController.php:1061-1124`
- Problem: When `$balance < 0` (sum of submitted payments exceeds `total`), `savePayments()` subtracts `abs($balance)` from the first cash payment (`payment_method_type_id == '01'`) or from the first payment row if no cash row exists. If the selected row's `payment` is smaller than `abs($balance)` (e.g., total `100`, cash `10`, card `150` → `balance = -60`), `$row['payment'] - abs($balance)` stores `-50.00` as the payment amount.
- Evidence:
```php
if ($row['payment_method_type_id'] == '01' && !$this->apply_change) {
    $change = abs($balance);
    $payment = $row['payment'] - abs($balance);
    $this->apply_change = true;
}
```
- Failure scenario: Mixed-tender overpayment produces negative `sale_note_payments.payment` rows, skewing cash/payment reports.
- Proposed fix: Reject non-cash overpayments with 422, or ensure cash tender covers `abs($balance)` (`$cash >= abs($balance)` else 422).
- Verification idea: Submit a sale note of 100 with cash=10 and card=150; expect 422 rather than a `-50` payment row.

### TC2-021 — Dead controllers, dead routes to missing methods, and scaffold/duplicate actions
- Category: dead-code
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location:
  - Six completely unrouted controllers: `app/Http/Controllers/Tenant/ReportController.php`, `ReportInventoryController.php`, `ReportKardexController.php`, `ReportPurchaseController.php` (100% commented out), `ReportQuotationController.php`, `ReportSaleNoteController.php` (active reports live in `modules/Report` and `modules/Inventory`; these dead copies also contain unbounded `->get()` calls, PHP-side `establishment_id` filtering in `ReportController::pdf/excel`, ignored `$request` in `ReportInventoryController::search`, `whereBetween(null, null)` in `ReportQuotationController`/`ReportSaleNoteController::search`, and wrong filename `'Reporte_Cotizacion'` in `ReportSaleNoteController::pdf`).
  - Four active routes in `routes/web.php` pointing to **non-existent controller methods** (500 `BadMethodCallException`): `routes/web.php:53` (`OrderController@record`), `routes/web.php:56` (`OrderController@pdf`), `routes/web.php:133` (`UserController@create`), `routes/web.php:278` (`RetentionController@destroy`).
  - Dead/broken controller methods: `PurchaseController::download:864-870` (unrouted, references unimported/wrong `SaleOpportunity` class → fatal), `PurchaseController::{xml2array, XMLtoArray, DOMtoArray}:687-743`, `RetentionController::downloadExternal:133-155` (unrouted; `DownloadController` handles downloads), unrouted `create()` methods (`ItemSetController:58`, `OptionController:36`, `PersonController:51`, `PersonTypeController:42`, `PromotionController:48`, `TagController:41`), `StatusOrdersController::{index,create,store,show,edit,update,destroy}:16-90` (7 empty scaffold stubs), `OptionController::updateStockAfterDelete:115-125` (commented body), `QuotationController::record2:220-225` (identical to `record()`, with `QuotationResource2` imported unused).
- Proposed fix: Remove the dead controllers/methods/stubs and dead routes (or implement `RetentionController@destroy` if deletion of un-sent retentions is intended), keeping git history.
- Verification idea: `php artisan route:list` / controller reflection test asserting every routed `Controller@method` exists.

### TC2-022 — Missing existence checks (`find()`/`first()` null derefs) and unvalidated `$request->column` / URL parameters
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (`findOrFail` + column/state whitelists)
- Location:
  - Null dereferences after `find()` / `first()`: `ItemSetController::visibleStore:271`; `LoginConfigurationController::{uploadBgImage:24, resetBgImage:50, update:76}`; `NoteController::{create:12, record:20}`; `OptionController::update_quantity_documents:129`; `PosController::{index:42, validate_stock:251}`; `PurchaseController::{anular:421, createPdf:883}`; `QuotationController::{index:56, option_tables:191, duplicate:318, anular:344, email:848-849, changed:875, updateStateType:886}`; `SaleNoteController::{index:64, changed:183, searchItemById:825, option_tables:896, email:908, anulate:952, downloadExternal:1054}`; `SaleNotePaymentController::{document:44, destroy:100, createPdf:114}`; `SummaryController::{documents:59, destroy:89}` & `SummaryTrait::query:31`; `VoidedController::{status:73, destroy:115}`; `QuotationResource:59,69`; `OrderCollection:23` / `OrderResource:20,25` (`$this->number_full` does not exist on `Order`).
  - Unvalidated `$request->column` in `records()` (missing/null `column` causes SQL syntax error 500, and any column can be probed): `ItemSetController:52`, `OrderController:88`, `PerceptionController:44`, `PersonController:44`, `PersonTypeController:36`, `PurchaseController:88-116`, `QuotationController:121`, `RetentionController:45`, `SaleNoteController:115`, `SummaryController:40`, `TagController:36`, `VoidedController:38, 44`. (`PromotionController::records:43` ignores `column`/`value` entirely.)
  - Unvalidated URL path params: `PersonController::enabled($type, $id):188` assigns raw `$type` to boolean `enabled` column; `QuotationController::updateStateType($state_type_id, $id):884` assigns raw `$state_type_id` without checking `state_types`.
- Failure scenario: Stale/invalid IDs or direct queries without `?column=` return HTML/JSON 500 instead of 404/422; `enabled/foo/1` or `state-type/bad/1` triggers SQL errors.
- Proposed fix: Use `findOrFail` / `firstOrFail`, whitelist `$request->column` against `array_keys($this->columns())` with a safe default, validate `{type}` in `{0,1}` and `{state_type_id}` against `state_types`.
- Verification idea: `GET` / `POST` with non-existent IDs returns 404; `records` with missing or unknown `column` falls back to default column.

### TC2-023 — Multi-write actions without DB transactions and state-changing GET routes (CSRF)
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (wrap in `DB::connection('tenant')->transaction`; switch state-changing GETs to POST/PATCH/DELETE alongside Vue callers)
- Location:
  - Missing DB transactions: `ItemSetController::destroy:157-177` (deletes initial kardex before `$item->delete()` can throw FK `23000`, losing the kardex row), `OptionController::deleteDocuments:41-98` (partial wipe across 15+ tables on failure), `PersonController::store:90-99` (deletes addresses before re-creating), `QuotationController::duplicate:315-339` (parent saved even if item replication fails), `SaleNoteController::destroy_sale_note_item:308-329` (resets lots before item delete), `SaleNotePaymentController::destroy:94-110`, `UserController::store:100-118` (`$user->save()` before `modules()->sync()` / `levels()->sync()`).
  - State-changing GET routes (`routes/web.php`): `persons/enabled/{type}/{person}` (:187), `purchases/anular/{id}` (:380), `purchases/delete/{id}` (:381), `quotations/state-type/{state_type_id}/{id}` (:407), `quotations/anular/{id}` (:414), `quotations/changed/{quotation}` (:424), `sale-notes/anulate/{id}` (:465), `sale-notes/changed/{salenote}` (:449), `voided/status_masive` (:265).
- Failure scenario: A failed FK constraint during `ItemSetController::destroy` deletes the initial kardex while keeping the item; GET links/prefetching can silently annul purchases, sale notes, or quotations.
- Proposed fix: Wrap multi-step writes in `DB::connection('tenant')->transaction(...)`; move state-changing GET routes to POST/DELETE.
- Verification idea: Simulate FK failure in `ItemSetController::destroy` and assert initial kardex remains intact.

### TC2-024 — `TaskController::store`: unvalidated Artisan command string and raw exception leak
- Category: endpoint-contract
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Http/Controllers/Tenant/TaskController.php:63-81`; `app/Http/Requests/Tenant/TaskRequest.php:23-28`; `app/Console/Commands/TenantCommand.php:41-52`
- Problem: `TaskRequest` only requires `class` and `execution_time` without validating `class` against the allowed command list (`listsCommand()`) or validating `execution_time` format. Combined with `tasks` lacking `'ajax' => true` in `config/permissions.php:162`, any authenticated user can store arbitrary strings in `tasks.class` (later passed to `Artisan::call($task->class)` by `tenant:run`), and invalid `execution_time` returns raw `$e->getMessage()` from `Carbon::parse()`.
- Evidence:
```php
$task = Task::create([
    'class' => $request->class,
    'execution_time' => Carbon::parse($request->execution_time)->format('H:i:s'),
]);
```
- Proposed fix: Validate `class` against `Rule::in(...)` from `listsCommand()`, validate `execution_time` as `date_format:H:i` / `H:i:s`, and log/mask exceptions.
- Verification idea: `POST /tasks` with `class=Arbitrary` returns 422.

### TC2-025 — PHP 8.2+ dynamic properties, duplicate resource keys, raw exception leaks, and minor maintainability bugs
- Category: maintainability
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `SaleNoteController.php:426, 428`; `PurchaseController.php:879, 881`; `app/Http/Resources/Tenant/SaleNoteResource.php:25, 34`; `PaymentMethodTypeController.php:53`; `QuotationController.php:349`; `SeriesController.php:53-62`; `UserController.php:133-148`; `ItemSetController.php:206`; `PersonController.php:144`; `PurchaseController.php:680, 801`; `TaskController.php:78`; `SaleNotePaymentController.php:13`
- Problem:
  - Undeclared dynamic properties (deprecated in PHP 8.2+, fatal in PHP 9): `$this->document` and `$this->configuration` in `SaleNoteController::createPdf`, `$this->purchase` in `PurchaseController::createPdf`.
  - Duplicate `'number'` array key in `SaleNoteResource.php` (line 34 overwrites `'number' => $this->number_full` at line 25).
  - Copy-pasted error/success messages: `PaymentMethodTypeController::destroy:53` ("no se pudo eliminar la unidad"), `QuotationController::anular:349` ("Producto anulado con éxito").
  - Missing FK `try/catch` (`23000`) in `SeriesController::destroy` and `UserController::destroy` (and `UserController::destroy` does not block deleting the currently logged-in user `auth()->id()`).
  - Raw `$e->getMessage()` returned directly in `ItemSetController::import`, `PersonController::import`, `PurchaseController::{delete, import}`, `TaskController::store`.
  - Unused imports across `ItemSetController`, `PersonTypeController`, `SaleNotePaymentController` (`FontLib\Table\Type\loca`), `TagController`, `ReportSaleNoteController` (`QuotationCollection`).
- Proposed fix: Declare properties on controllers, fix `SaleNoteResource` key, fix messages, add FK `try/catch` and self-delete guard on `UserController::destroy`, log exceptions instead of returning `$e->getMessage()`, and remove unused imports.
- Verification idea: Run PHP 8.2/8.3 deprecation checks on `SaleNoteController::createPdf` and `UserController::destroy` self-delete test.

## Overflow themes (not itemised)
- Simple catalog/CRUD controllers (`PaymentMethodTypeController`, `PersonTypeController`, `TributeConceptTypeController`, `UnitTypeController`, `TagController`) return HTTP 200 with `success: false` on errors and swallow non-`23000` exceptions without logging; `findOrFail` inside `destroy()` `try/catch` blocks maps 404 to generic 200 error JSON.
- `SaleNoteController::createPdf` and `QuotationController::createPdf` repeat nearly identical multi-hundred-line mPDF layout calculation blocks (also duplicated across `DocumentController` and `SaleNotePaymentController`).
- All `toPrint()` methods (`PurchaseController`, `QuotationController`, `SaleNoteController`, `SaleNotePaymentController`) write temporary files via `tempnam(sys_get_temp_dir(), ...)` and return `response()->file($temp)` without cleanup.
