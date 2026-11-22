# Findings — Tenant controllers (part 1)

## Coverage
- Scope: first 35 of 70 files of `fd -e php . app/Http/Controllers/Tenant | sort` (AccountController ... ItemController).
- Fully read (all 35): AccountController, AdvancedController, Api/{DispatchController, DocumentController, MobileController, PerceptionController, RetentionController, SaleNoteController, ServiceController, SummaryController, VoidedController (head + diff vs Summary)}, BankAccountController, BankController, CardBrandController, CashController, CatalogController, CertificateController, ChargeDiscountController, CompanyController, ConfigurationController, ContingencyController, CurrencyTypeController, DetractionTypeController, Dispatch2Controller, DispatchController, DocumentController, DocumentPaymentController, DownloadController, EstablishmentController, ExchangeRateController, FormatTemplateController, HomeController, InventoryController, InventoryController2, ItemController.
- Supporting files read fully: routes/api.php, routes/web.php (lines 1-130 + route grep for every controller), app/Http/Middleware/EnforceModuleAccess + AccessMap::decide + config/permissions.php, Requests (CashRequest, DocumentPaymentRequest, ItemRequest), Resources (ItemCollection, DocumentPaymentCollection, DocumentCollection partial), DocumentPaymentProvider, GlobalPaymentServiceProvider, SearchTrait::getFullDescription, Item/ItemWarehouse/DocumentPayment/Cash model fragments, payments.vue (consumer).
- Pattern-scanned only: the remaining FormRequests/Resources of the simple CRUD controllers (Bank*, CardBrand, ChargeDiscount, CurrencyType, DetractionType, Establishment), Facturalo (only updateSoap/updateState), all Excel exports/imports.
- Not reviewed: CoreFacturalo internals, `input.request` middleware, modules/* (other auditors).
- Controller actions analysed: ~265 (incl. private helpers).

## Findings (ordered by severity)

### TC1-001 — CompanyController::uploadFile lets any logged-in user overwrite any `companies` column
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/CompanyController.php:62-113 (uploadFile); config/permissions.php:159
- Problem: `$type = $request->input('type')` is never whitelisted and is used (a) in the stored filename, (b) in `$company->$type = $name; $company->save();` (direct attribute assignment, bypasses `$fillable`). `companies/uploads` is mapped `'module' => null` (open to every authenticated user, sellers included).
- Evidence:
```php
$type = $request->input('type');
$name = $type.'_'.$company->number.'.'.$ext;
...
$file->storeAs(($type === 'img_firm') ? 'public/uploads/firms' : 'certificates', $name);
$company->$type = $name;
$company->save();
```
- Failure scenario: POST `companies/uploads` with `type=soap_send_id` (or `number`, `certificate`, `soap_type_id`, ...) and any file → the column now holds `soap_send_id_<ruc>.ext`, breaking SUNAT sending / identity. For unknown types the file is also written into `storage/app/certificates` with a client-controlled extension and, because `type` is in the path, a `type` with `../` segments targets other directories. In addition `logo`/`logo_store` are stored twice (line 77 and again line 95 into `certificates`), and svg is accepted for `logo` (served publicly, stored XSS).
- Proposed fix: `abort_unless(in_array($type, ['logo','logo_store','favicon','img_firm'], true), 422)` before anything else; return after each branch instead of falling through to the line-95 store; drop svg or sanitize it. Restrict the route to the `configuration` module (or keep open only for those 4 types).
- Verification idea: feature test POST with `type=number` must be 422 and `companies.number` unchanged.

### TC1-002 — ItemController::store reads any server file path sent by the client (`temp_path`) and publishes it
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes upload protocol: upload() returns an absolute path the Vue code echoes back)
- Location: app/Http/Controllers/Tenant/ItemController.php:179-211, 319-325 (store), 426-445 (upload_image); same pattern in ItemSetController (other auditor)
- Problem: `temp_path` and `multi_images[].temp_path` come from the request body and go straight to `file_get_contents()` / `Image::make()`, then are copied to `storage/app/public/uploads/items/`, which is web-served. File extension is `explode('.', $request->image)[1]` (client string).
- Evidence:
```php
$temp_path = $request->input('temp_path');
$file_content = file_get_contents($temp_path);
$file_name = Str::slug($item->description).'-'.$datenow.'.'.$file_name_old_array[1];
Storage::put($directory.$file_name, $file_content);
```
- Failure scenario: authenticated user posts `temp_path=/var/www/.../.env` and `image=a.txt` → the file is copied to a public URL they can fetch. With `image=a.php` the extension is `php` (only the webserver config prevents execution). Also `image` without a dot → "Undefined array key 1" 500; `my.photo.jpg` → extension `photo`.
- Proposed fix: only accept a temp token (basename) and resolve it inside a dedicated dir (e.g. `storage/app/tmp/items/<basename>`), `realpath` + prefix check; derive the extension from `mime_content_type`/`getimagesize`, whitelist jpg/jpeg/png/gif/webp.
- Verification idea: request with `temp_path=/etc/hosts` must be rejected.

### TC1-003 — Unauthenticated tenant API/web routes expose data and trigger actions
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (external integrations may rely on them; decide per route)
- Location: routes/api.php:46-53, routes/web.php:17-19
- Problem: after the `auth:api` group closes (line 45) these are registered with no auth: `documents/search/customers` (DocumentController::searchCustomers), `documents/status` (ServiceController::documentStatus), `sendserver/{document_id}/{query?}` (DocumentController::sendServer), `configurations/generateDispatch` (ConfigurationController::generateDispatch), and web `exchange_rate/ecommence/{date}` (ServiceController::exchangeRateTest; writes `exchange_rates` rows from an external lookup using the raw `{date}`).
- Evidence:
```php
Route::get('documents/search/customers', 'Tenant\DocumentController@searchCustomers');
Route::post('documents/status', 'Tenant\Api\ServiceController@documentStatus');
Route::get('sendserver/{document_id}/{query?}', 'Tenant\DocumentController@sendServer');
```
- Failure scenario: anyone can list customers (name, document number, addresses, state/condition); query a document by `serie_number` only (e.g. `F001-1`, sequential) and get number, QR, xml/pdf/cdr download links; call `sendserver/1`, `/2`, ... to make the tenant push each document to the offline "server" with its bearer token (state change `send_server=true`).
- Proposed fix: move into the `auth:api` group (or add a shared secret / signed URL for the integrations that need it); validate `{date}` as `Y-m-d`.
- Verification idea: curl without Authorization must return 401.

### TC1-004 — ItemController::store (update branch) deletes lots and then dereferences them
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE for the null-deref; RISKY for the lot-group part (see below)
- Location: app/Http/Controllers/Tenant/ItemController.php:275-313
- Problem: on edit it runs `$item->lots()->delete()` (deletes every lot, including `has_sale=true`) and then loops `$request->lots` doing `ItemLot::find($lot['id'])->delete()/->update(...)`. `ItemLot` has no SoftDeletes, so `find()` returns null.
- Evidence:
```php
$item->lots()->delete();
...
if ($lot['deleted'] == true) { ItemLot::find($lot['id'])->delete(); }
else if (isset($lot['id'])) { ItemLot::find($lot['id'])->update([...]); }
```
- Failure scenario: edit any item that has series/lots (the form sends existing lots with `id`) → "Call to a member function update() on null", whole save fails after the item row and unit types were already written (no transaction, see TC1-018). Lots without id are silently recreated, losing `has_sale`.
- Proposed fix: remove the blanket `$item->lots()->delete()` (the loop already handles deleted/updated/new lots).
- Related RISKY point: `ItemLotsGroup::where('item_id')->delete()` + single `create` with `$request->stock` (lines 303-312) collapses multiple lot groups into one and changes group ids; needs business confirmation.
- Verification idea: update an item with 2 lots, delete one in payload; expect 1 lot left, no 500.

### TC1-005 — AJAX requests bypass the module access map for most state-changing endpoints
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (needs per-rule review of which routes are shared by other modules)
- Location: config/permissions.php (rules without `'ajax' => true`: `documents`, `cash`, `cuenta`, `pos_full`, `orders`...), app/Support/Access/AccessMap.php:96-98; unmapped: `configurations/*` (store, icbper, addSeeder, uploads, changeFormat), `document_payments`, `series`, `exchange_rates`, `cuenta/*`
- Problem: `decide()` returns allowed with reason `ajax-not-enforced` for any XHR on a rule lacking `ajax => true`, and unmapped paths are allowed. Controllers add no checks of their own.
- Failure scenario: a user with only a seller role/module runs `axios.post('/cuenta/update_plan', {plan_id: 1})` (changes the tenant plan in both DBs, TC1-022), `DELETE /documents/delete_document/{id}` (TC1-006), `POST /configurations` (any Configuration fillable column), `GET /configurations/addSeeder` (truncates `format_templates`), `POST /cuenta/payment_culqui`.
- Proposed fix: add `ajax => true` to `documents`, `cash`, `cuenta`, and map `configurations`, `document_payments`, `exchange_rates`, `series` to `configuration` (keeping explicit `module => null` exceptions for the read endpoints other forms need). Keep the sidebar-parity tests.
- Verification idea: feature test per sensitive route with a seller user (expect 403 JSON).

### TC1-006 — Delete / recreate / force-"registered" documents have no server-side state guard
- Category: functional-bug (business rule)
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (fiscal documents; rules must come from the business)
- Location: app/Http/Controllers/Tenant/DocumentController.php:971-995 (destroyDocument), 506-539 (reStore), 704-716 (changeToRegisteredStatus)
- Problem: the UI only shows these buttons for specific states/config (`btn_delete_doc_type_03` needs state 01, doc type 03, `tenant.delete_document_type_03`, no summary; see DocumentCollection.php:56-60), but the endpoints accept any id.
- Evidence:
```php
$record = Document::findOrFail($document_id);
$this->deleteAllPayments($record->payments);
$record->delete();
```
```php
$facturalo->updateSoap('02', $type);   // reStore: forces soap_type_id = 02 (demo)
$facturalo->updateState('01');
```
- Failure scenario: DELETE on an accepted (state 05) invoice removes a SUNAT-accepted CPE from the books; `re_store` on an accepted invoice regenerates XML/hash, flips it to demo (`02`) and state 01; `change_to_registered_status` marks a state-01 document as accepted (05) without any CDR. `Document::find()` null also fatals in reStore.
- Proposed fix: re-check the same conditions the Collection uses before acting (and 403/422 otherwise); `findOrFail` in reStore.
- Verification idea: test that DELETE of a state-05 document returns an error and the row remains.

### TC1-007 — `orWhere` breaks the filters in customer/item searches (3 places)
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: DocumentController.php:106-110 (searchCustomers, unauthenticated route); Api/MobileController.php:252-258 (searchItems), 309-313 (searchCustomers)
- Evidence:
```php
Person::where('number','like', "%{$request->input}%")
      ->orWhere('name','like', "%{$request->input}%")
      ->whereType('customers')->...->whereIn('identity_document_type_id',$ids)->whereIsEnabled()
```
  SQL precedence = `number LIKE x OR (name LIKE x AND type='customers' AND ...)`.
- Failure scenario: searching by document number returns suppliers and disabled persons and ignores the identity-type filter (e.g. RUC-only for a factura); MobileController::searchItems returns inactive items, sets and items outside the user's warehouse whenever `description` matches.
- Proposed fix: group the OR: `->where(fn($q)=>$q->where('number','like',..)->orWhere('name','like',..))`.
- Verification idea: seed a supplier whose number matches, assert it is absent.

### TC1-008 — Api\DocumentController::updatestatus reads a misspelled key and dereferences null
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (public API contract; also allows setting any `state_type_id`)
- Location: app/Http/Controllers/Tenant/Api/DocumentController.php:171-179
- Evidence:
```php
$record = Document::whereExternal_id($request->externail_id)->first();
$record->state_type_id = $request->state_type_id;
```
- Failure scenario: client sends `external_id` → `externail_id` is null → query becomes `external_id IS NULL` → null → 500 (or, if a row with NULL external_id exists, its state is overwritten). No state validation either (any string can be stored, e.g. `05`).
- Proposed fix: accept `external_id` (keep `externail_id` as fallback during transition), 404 when not found, validate `state_type_id` exists in `state_types`; ideally forbid manual 05.
- Verification idea: POST with a real external_id updates exactly that document.

### TC1-009 — Api\ServiceController::documentStatus undefined offset; Api\DocumentController::send/documentCheckServer null paths
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: Api/ServiceController.php:130-170; Api/DocumentController.php:64-95, 134-146
- Evidence:
```php
if($request->has('external_id') OR $request->has('serie_number')) {
    $serie_number = explode('-', $request->input('serie_number'));
    $serie = $serie_number[0]; $number = $serie_number[1];
```
- Failure scenario: request with only `external_id` (the documented first alternative) → `explode('-', null)` (PHP 8.1 deprecation) then undefined index 1 → ErrorException 500. `send()` returns null (empty 200) when `external_id` is absent; `documentCheckServer` does `$document->state_type_id` on a possibly null `first()`; `documentStatus` uses `$document->state_type->description` unguarded. Errors are raised as bare `Exception` (500) instead of 404/422.
- Proposed fix: only split `serie_number` when present, use `external_id` alone otherwise; return 404 JSON for missing docs; 422 when neither key is sent.
- Verification idea: POST `{external_id: <uuid>}` returns the document.

### TC1-010 — Api\MobileController: null dereferences, wrong `category`, unbounded searches, N+1
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/Api/MobileController.php:94-134 (tables), 247-302 (searchItems), 304-336
- Evidence:
```php
'brand' => $row->brand->name,
'category' => $row->brand->name,
'stock' => ItemWarehouse::where([['item_id',$row->id],['warehouse_id',$warehouse->id]])->first()->stock,
```
- Failure scenario: an item without brand (brand is optional on the item form) → whole `document/tables` / `search-items` call returns 500 for the mobile app; user whose establishment has no warehouse → `$warehouse->id` fatal; item without ItemWarehouse row → `->first()->stock` fatal. `category` always repeats the brand (copy/paste; the query eager loads `category`). `searchItems`/`searchCustomers` use unbounded `->get()` (empty string matches everything) with 1 extra query per row for stock and per-row `identity_document_type`/`warehouse` lazy loads. `Item::$with` already covers currency_type/warehouses, so use `$row->warehouses->firstWhere('warehouse_id', $warehouse->id)`.
- Proposed fix: `optional($row->brand)->name`, `optional($row->category)->name`, stock from the already-loaded `warehouses` relation with `?? 0`, `->take(50)` on the searches.
- Verification idea: item without brand returns 200 with `brand: null`.

### TC1-011 — Api\PerceptionController::store opens its transaction on the default connection
- Category: functional-bug
- Severity: medium
- Confidence: PLAUSIBLE
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/Api/PerceptionController.php:18
- Evidence: `DB::transaction(function () ...)` while every sibling controller uses `DB::connection('tenant')->transaction(...)`; `config/database.php` default is `env('DB_CONNECTION','system')` and `tenancy.db.default` is env-driven.
- Failure scenario: when the default connection is `system`, the perception write (tenant connection) is not wrapped; a failure in XML/signing/PDF leaves a half-created perception.
- Proposed fix: use `DB::connection('tenant')`.
- Verification idea: force an exception after `save()` and assert no `perceptions` row.

### TC1-012 — DocumentPaymentController::store: no server-side validation of the amount, duplicated global payment on edit
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (payment/cash rules)
- Location: app/Http/Controllers/Tenant/DocumentPaymentController.php:55-75; app/Http/Requests/Tenant/DocumentPaymentRequest.php:15-33
- Problem: `payment` is only `required` (no numeric/min/max against pending balance, `document_id` not validated). The "amount exceeds pending" check exists only in the Vue file (payments.vue:251). `store` with an `id` runs `createGlobalPayment` again (morphOne `create`), so editing a payment adds a second global_payment row, and `DocumentPaymentProvider` only listens to `created`/`deleted`, so `total_canceled` is not refreshed on update.
- Failure scenario: API call with `payment=-50` or 1e9 is accepted and moves cash/bank totals; editing a payment doubles it in the cash report.
- Proposed fix: rules `document_id exists`, `payment numeric|gt:0`, compare against `document->total - paid`; on update `updateOrCreate` the global payment.
- Verification idea: POST an overpayment → 422.

### TC1-013 — CashController: close() without transaction/state check and with N+1; null deref in cash_document; foreign `user_id`
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE for null/state guards; RISKY for balance logic (do not touch the totals math)
- Location: app/Http/Controllers/Tenant/CashController.php:106-132, 155-217, 220-229
- Problem: (a) `close()` never checks `$cash->state`, so closing twice rewrites `date_closed/final_balance`; (b) loops `cash_documents` and lazy loads `sale_note`, `document`, `expense_payment->expense` per row (N+1, 1-4 queries per payment); (c) `cash_document()` does `Cash::...->first()` then `->cash_documents()` with no open cash → fatal, and `create($request->all())` mass-assigns the whole body; (d) `store()` takes `user_id` from the body (`createCashTransaction` uses `$request->user_id`) and does not check that the user has no open cash already, so a seller can open/overwrite a cash for another user or open two.
- Proposed fix: `abort_if(!$cash->state)`, `Cash::with('cash_documents.sale_note','cash_documents.document','cash_documents.expense_payment.expense')`, null-check open cash, force `user_id = auth()->id()` for sellers and reject when an open cash exists.
- Verification idea: close twice → second call returns an error; query count on a 200-row cash.

### TC1-014 — ConfigurationController::generateDispatch: undefined variable, unvalidated path, public route
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/ConfigurationController.php:29-94; routes/api.php:53
- Evidence:
```php
app_path('CoreFacturalo'.DS.'Templates'.DS.'pdf'.DS.$base_pdf_template.DS.'font')   // $base_pdf_template is never defined
$path_css = app_path(... $request->base_pdf_template ...'style.css');
$stylesheet = file_get_contents($path_css);
Storage::put('preprintedpdf'.DS.$request->base_pdf_template.'.pdf', ...);
```
- Failure scenario: with custom fonts configured (`tenant.pdf_name_regular`) the branch hits "Undefined variable" (PHP 8: warning→exception); `base_pdf_template` is not whitelisted (`../` lets `file_get_contents` read any `.../style.css` outside the templates dir; filename of the stored pdf is client-controlled); and the route is unauthenticated (TC1-003). `$pdf = new Mpdf()` at line 32 is immediately overwritten.
- Proposed fix: validate against `PreprintedFormatTemplate` formats (or `Rule::in(scandir)`), use `$request->base_pdf_template` for the font path, delete the dead first `new Mpdf()`.

### TC1-015 — ConfigurationController::addSeeder / addPreprintedSeeder: state-changing GET that truncates, no transaction
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (change verb to POST + wrap in a delete/insert transaction; `truncate` causes an implicit commit)
- Location: ConfigurationController.php:128-196; routes/web.php:81-82
- Problem: `DB::table('format_templates')->truncate()` then row-by-row `insert`; if the directory listing or an insert fails the table stays empty and the PDF format selector (`getFormats`) shows nothing. The files copy block is also unguarded. Being a GET it can be fired by an `<img src>`/link (CSRF) or by prefetch.
- Proposed fix: POST route, `DB::connection('tenant')->transaction(fn() => delete()+insert(array))`.

### TC1-016 — ConfigurationController::icbper updates items one by one
- Category: performance
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (Item model events/observers would be skipped by a bulk query; check before changing)
- Location: ConfigurationController.php:264-284
- Evidence: `Item::get([...])` then `foreach ... $item->update()` — one UPDATE per item, fully in memory, inside one transaction; also `Configuration::find($id)` null → fatal.
- Proposed fix: `Item::query()->update(['amount_plastic_bag_taxes' => $amount])` if no Item observer relies on `update` events (verify first), else `chunkById`.

### TC1-017 — DispatchController::tables: `each()` used as a map, whole item catalog loaded
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/DispatchController.php:200-208, 237-245, 214-249
- Evidence:
```php
'lots_group' => $row->lots_group->each(function($lot){ return [ ..., 'checked' => false ]; }),
```
  `Collection::each` ignores the callback return value, so the response contains raw `ItemLotsGroup` models without the `checked` flag the other controllers add (compare DocumentController::table, `transform`). Also `Item::...->get()` without limit (all items of type 01) on every dispatch form open (and again for `itemsFromSummary`), plus `$departments` queried twice.
- Proposed fix: `->map(...)`; paginate/limit or use the existing remote search; remove the duplicate department query.

### TC1-018 — ItemController::store: multi-write without transaction; ItemUnitType id hijack; null establishment
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (wrap in `DB::connection('tenant')->transaction`)
- Location: ItemController.php:166-339
- Problem: item row, unit types, tags, lots, lot group, files and ItemImage rows are written sequentially without a transaction (TC1-004 crash happens after half the writes). `ItemUnitType::firstOrNew(['id' => $value['id']])` then `item_id = $item->id` re-parents any unit-type row whose id the client sends. `$request->item_unit_types` is not validated (null → foreach warning→500). `$establishment->id` fatals when the user has no establishment.
- Proposed fix: transaction + restrict `ItemUnitType` lookup to `->where('item_id', $item->id)`, validate `item_unit_types` as array.

### TC1-019 — Item/Document list responses trigger N+1 queries
- Category: n+1/sql
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Resources/Tenant/ItemCollection.php:52,68-73 (via Item::getStockByWarehouse, Item.php:192-205); DocumentController.php:291-334, 368-393 (table('items'))
- Problem: `getStockByWarehouse()` runs `Warehouse::where('establishment_id')->first()` once per row; `$row->warehouse->description` lazy-loads per ItemWarehouse (ItemWarehouse has no `$with`); `table('items')`/`getFullDescription` lazy-load `brand`, `category`, `warehouse` for 30 items; `Item::$with` already eager loads the other relations.
- Also: `DocumentController::getFullDescription` (line 378) does `->first()->stock` with no null check (the SearchTrait copy handles it) → fatal when an item has no row for the user's warehouse. The duplicate private logic should reuse `SearchTrait::getFullDescription`.
- Proposed fix: resolve the user's warehouse once per request, `->with(['warehouses.warehouse','brand','category'])`, reuse the trait method.

### TC1-020 — Api\SaleNoteController::store: edit path loses payments, keeps stale items; numbering without lock
- Category: functional-bug
- Severity: medium
- Confidence: PLAUSIBLE
- Auto-fix: RISKY (numbering and sale-note business rules)
- Location: app/Http/Controllers/Tenant/Api/SaleNoteController.php:63-100, 158-178
- Problem: for an existing `id` it runs `$this->sale_note->payments()->delete()` but the re-creation is commented out (lines 102-104); items are `firstOrNew(['id' => $row['id']])` and never removed when the payload omits them; foreign item ids are adopted. `getDataSeries()` reads `max(number)` without lock (two simultaneous mobile saves produce the same number) and `Series::find($series_id)->number` fatals on a bad id. `createPdf` runs inside the DB transaction. `$this->document` is an undeclared (dynamic) property → PHP 8.2 deprecation.
- Proposed fix: report only; for numbering use the same mechanism as Tenant\SaleNoteController; remove the unused `payments()->delete()`; sync items.

### TC1-021 — DownloadController: model class built from the URL, temp files never removed
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/DownloadController.php:16-24, 60-72; routes/web.php:17-18 (public)
- Problem: `"App\\Models\\Tenant\\".ucfirst($model)` is instantiated for any path segment (e.g. `Catalogs\\Code`, `Person`) and `where('external_id')` is run on it; models without that column give a 500 with the SQL error. `toPrint` writes every PDF to `tempnam()` and never unlinks it (grows `/tmp` per print); `getStorage()` null content writes an empty file and returns an empty "PDF". A `$format` value regenerates (`reloadPDF`) the PDF for anyone who knows the external id.
- Proposed fix: whitelist `['document','sale_note','quotation','purchase','dispatch','retention',...]`, return `response()->make($content, 200, ['Content-Type'=>'application/pdf'])->` instead of temp files, 404 on missing storage.

### TC1-022 — AccountController::updatePlan: no validation, two databases, null client
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/AccountController.php:51-74
- Evidence:
```php
$configuration->plan = Plan::find($request->plan_id);
$configuration->save();
$client = Client::where('number', $company->number)->first();   // fetched before, used after
$client->plan_id = $request->plan_id;
```
- Failure scenario: unknown `plan_id` → tenant config gets `plan = null` and the system client row a dangling id; a missing system client fails after the tenant config was already saved (inconsistent plans); the message returns `$e->getMessage()`. Combined with TC1-005 any user can change the plan.
- Proposed fix: `Plan::findOrFail`, null-check client, write the system record first/inside try, generic error message.

### TC1-023 — Heavy report/maintenance actions load whole tables with lazy relations
- Category: performance
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (chunk/eager load only)
- Location: DocumentPaymentController.php:88-120 (initialize_balance: `Document::get()` + `payments` per row + 1 UPDATE each, reachable by GET) and 122-159 (report: payment_method_type, global_payment, document.customer per row); DocumentController.php:952-969 (report_payments: unbounded `get()`, `customer->department->description` fatals when null); ItemController::export/exportWp/exportBarCode (unbounded; `period` missing → `whereBetween(null,null)` silently empty)
- Proposed fix: `->with([...])->chunkById()`; use `cursor()` for exports; make initialize_balance a POST/artisan command.

### TC1-024 — ExchangeRateController::store creates whatever array it receives
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/ExchangeRateController.php:18-28
- Problem: `foreach ($request->all() as $row) ExchangeRate::create($row)` — a scalar entry (e.g. `_token` or a non-array) throws a TypeError; no validation (`date`, `purchase`, `sale`), no transaction, no upsert so re-fetching a date creates duplicates (ServiceController::exchangeRateTest handles dups with `where('date')`, this path does not).
- Proposed fix: validate `*.date|date, *.sale|numeric`, `updateOrCreate(['date'=>...], ...)` inside a transaction.

### TC1-025 — Dead code: Inventory/Dispatch2 duplicates
- Category: dead-code
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (delete; keep VCS history)
- Location: app/Http/Controllers/Tenant/InventoryController.php, InventoryController2.php (near copy), Dispatch2Controller.php
- Evidence: no route in routes/*.php or modules/*/Routes (module routes are `Modules\Inventory\Http\Controllers\InventoryController`, a different class), no string/DI/provider reference (`rg "Tenant\\InventoryController|InventoryController2|Dispatch2Controller"` only hits the files themselves; `Dispatch2Validation` does not reference them). `InventoryController::move/remove` validate against a client-sent `quantity` instead of the real stock, so reviving them would be unsafe.
- Proposed fix: delete the three files.

### TC1-026 — Dead routes/actions pointing at missing methods or commented-out features
- Category: dead-code
- Severity: low
- Confidence: CONFIRMED (missing methods), PLAUSIBLE (unused callers outside resources/js)
- Auto-fix: SAFE
- Location: routes/web.php:499-501 (`cash/item/tables`, `cash/search/customers`, `cash/search/customer/{id}` → CashController has none of these); routes/api.php:48-49 (`ServiceController@validateCpe`, `@consultStatus` do not exist anywhere in app/); routes/web.php:308 (`ServiceController@searchExchangeRateByDate` missing); also unreferenced: DocumentController::consultCdr (route commented out; also broken: the transaction closure returns nothing so `$fact->getResponse()` is a call on null), ConfigurationController::getSystemPhone (fully commented body), Api\SaleNoteController::toPrint/reloadPDF/createPdf copy (no route; web uses Tenant\SaleNoteController), ConfigurationController::dispatch commented block.
- Failure scenario: hitting any of those routes returns a 500 BadMethodCallException.
- Proposed fix: remove the routes/methods (or implement), confirm with the Vue consumers first (`rg` shows no caller for the missing ones).

### TC1-027 — User-controlled `column` in generic `records()` actions
- Category: maintainability
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: CashController.php:51, ContingencyController.php:41, DispatchController.php:66, Dispatch2Controller.php:47, ItemController.php:114
- Problem: `where($request->column, 'like', ...)` with no whitelist (identifiers are quoted by the builder, so no injection, but `column=null` → 500 and any column can be probed, including encrypted/secret ones on models that expose them).
- Proposed fix: `in_array($request->column, array_keys($this->columns()))` fallback to default.

### TC1-028 — Missing existence checks (`find()` then property access)
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (`findOrFail` → 404)
- Location: CompanyController::store:38, ConfigurationController::store:254 / icbper:268 / visualSettings:320 (`find(1)`), changeMode:389 (`$configuration->visual` may be null), DocumentController::send:555, consultCdr, checkServer:631, email:544, changeToRegisteredStatus (also returns null body when state != 01), DocumentPaymentController::document:40, DispatchController::email:374, generateDocumentTables:387-389 (`$establishment`/`$warehouse` null), ItemController::visibleStore:458, duplicate:482 (also `replicate()` keeps `internal_id`/`barcode`, which collides with the unique rule in ItemRequest and does not copy unit types or stock), printBarCode:643-645, itemLast:685, Api\MobileController::document_email:163 and SaleNoteController::email:406, AccountController::paymentIndex:32.
- Failure scenario: stale id / fresh tenant without data → HTML 500 page where the Vue code expects JSON `{success:false}`.

### TC1-029 — Multi-write without transaction in simple CRUD
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: EstablishmentController.php:55-83 (establishment saved, then warehouse; a failure leaves an establishment with no warehouse, which breaks `whereWarehouse()` scopes and `generateDocumentTables`), ItemController::destroy:341-361 (`deleteRecordInitialKardex` deletes the initial kardex row before `$item->delete()` can fail with a FK error, so the kardex row is lost; only when exactly one kardex row exists)
- Proposed fix: wrap in `DB::connection('tenant')->transaction`.

### TC1-030 — CertificateController: wrong message, file left on disk, non-recursive mkdir
- Category: maintainability
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: app/Http/Controllers/Tenant/CertificateController.php:25-69
- Problem: `destroy()` answers "Cliente eliminado con éxito" (copy/paste), only nulls `company.certificate` and leaves the PEM with the private key in `storage/app/certificates`; `mkdir()` is not recursive and its failure is ignored; the uploaded file's type/size is not validated before `file_get_contents`; the exception text is returned to the browser.
- Proposed fix: delete the file, fix the message, `mkdir($dir, 0755, true)`, `File::ensureDirectoryExists`.

### TC1-031 — Misc maintainability
- Category: maintainability
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: many; examples: DocumentController.php imports (`Nexmo\Account\Price`, `Cache`, `ZipFly`, `Brand`...) unused; CashController imports (`ItemsImport`, catalog types) unused; CatalogController imports; Api\SaleNoteController (20+ unused imports and the undeclared `$this->document` property, PHP 8.2 dynamic-property deprecation); Api\DocumentController::storeServer commented `ZipFly`; Api\MobileController::tables/report (`establishment_id => 1` hard-coded in `report()`); Api\SummaryController/VoidedController::store are identical (duplicated logic); `Api\{Summary,Voided}Controller::status` throw bare `Exception` (500) for client errors; ItemController::upload_image calls `tempnam(..., $type)` with null `type` (PHP 8.1 deprecation) and never deletes the temp files; ChargeDiscount/Bank/etc. mass-assign `$request->all()` (rely on `$fillable`).

## Overflow themes (not itemised)
- Every simple CRUD controller (Bank, BankAccount, CardBrand, CurrencyType, DetractionType, ChargeDiscount) returns 200 with `success:false` for errors and maps any exception code `23000` to "in use"; consistent but swallows the real error (no logging).
- `findOrFail` inside try/catch blocks (`destroy`) turns a 404 into the generic "Error inesperado" message.
- State-changing GET routes (`items/enable|disable`, `cash/close`, `documents/re_store`, `change_to_registered_status`, `document_payments/initialize_balance`, `configurations/change-mode`) are CSRF-able; move to POST/PATCH.
- Items repeatedly built as the same 20-field array (Mobile x4, Document, Dispatch x3, Dispatch2): extract an ItemTransformer/Resource.
