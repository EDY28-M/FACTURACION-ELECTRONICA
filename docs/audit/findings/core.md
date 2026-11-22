# Findings — CoreFacturalo, Services, Support, Models, Traits, Exports, Imports & Observers

## Coverage
- Scope:
  - `app/CoreFacturalo/` (all 131 non-template files read fully, excluding `app/CoreFacturalo/Templates/` per scope; `app/CoreFacturalo/Templates/xml/*.blade.php` pattern-scanned for `{!! !!}` and escaping).
  - `app/Services/` (all 11 files read fully, including `Payments/{CartPricing,CulqiCharger,CulqiErrorPolyfills,EcommerceCheckout,PlatformPayment}`).
  - `app/Support/` (all 2 files read fully: `Access/{AccessMap,PermissionTree}`).
  - `app/Observers/` (1 file read fully: `DocumentObserver`).
  - `app/Traits/` (all 7 files read fully: `BackupTrait`, `CommandTrait`, `InventoryKardexTrait`, `KardexTrait`, `OfflineTrait`, `ReportTrait`, `SummaryTrait`).
  - `app/Exports/` (all 14 files read fully).
  - `app/Imports/` (all 4 files read fully).
  - `app/Models/System/` (all 12 files read fully) and `app/Models/Tenant/` (all core business models, `User`, `Company`, `Configuration*`, `Item*`, `Person*`, `Document*`, `Dispatch*`, `Purchase*`, `Quotation*`, `SaleNote*`, `Summary*`, `Voided*`, `Retention*`, `Perception*`, `Cash*`, `Order`, `Series`, `SireRequest`, `AccountPayment`, `BankAccount`, `Kardex`, `Inventory*`, `Warehouse`, and key `Catalogs/*` read fully; simple 12-line catalog subclasses pattern-checked).
- Supporting verification reads: `composer.json`, `config/database.php`, `config/app.php`, `app/Providers/{AnulationServiceProvider,DocumentPaymentProvider,InventoryKardexServiceProvider}`, `modules/Inventory/Providers/InventoryKardexServiceProvider.php`, `app/Http/Controllers/Tenant/{AccountController,DocumentController,Api/ServiceController}`, `tests/Unit/PaymentsTest.php`, `database/migrations/tenant/2018_06_17_000002_tenant_documents_table.php`, `docs/audit/findings/{tenant-controllers-1,security}.md`.
- Baseline check run: `php vendor/bin/phpunit tests/Unit/PaymentsTest.php` -> 15 tests, 76 assertions, OK (1 deprecation from `FakeRecord`, 1 PHPUnit deprecation).

## Findings (ordered by severity)

### CORE-001 — `Facturalo::validationCodeResponse` marks failed SOAP sends with non-numeric fault codes as `ACCEPTED` (`05`)
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/Facturalo.php:742-781` (`validationCodeResponse`), `app/CoreFacturalo/WS/Services/BaseSunat.php:91-125` (`getErrorFromFault`, `getErrorByCode`), `app/CoreFacturalo/WS/Reader/DomCdrReader.php:29`
- Problem: `BaseSunat::getErrorByCode` strips non-digits from `$fault->faultcode` and `$fault->faultstring`; when neither contains digits (e.g., `'soap-env:Client'`, `'soap-env:Server'`, `'WSDL'`, `'Could not connect to host'`, `'Unauthorized'`), `$error->getCode()` stays a non-numeric string. `Facturalo::validationCodeResponse` only checks `$code === 'ERROR_CDR'` and `$code === 'HTTP'`, then evaluates `if ((int)$code === 0)`. In PHP, `(int) 'soap-env:Client'` is `0`, so the document is marked `ACCEPTED` (`05`). Additionally, `DomCdrReader::getCdrResponse` throws `new \Exception('Not found cdr response in xml', 'ERROR_CDR')` — in PHP 8.3 `Exception::__construct` requires `int $code`, triggering a fatal `TypeError` before `'ERROR_CDR'` can ever be handled; and `BaseSunat::getErrorFromFault` reads `$fault->detail->message` without checking that `$fault->detail` has a `message` property.
- Evidence:
```php
// app/CoreFacturalo/Facturalo.php:745-761
if($code === 'ERROR_CDR') { return; }
if($code === 'HTTP') { ... return; }
if((int)$code === 0) {
    $this->updateState(self::ACCEPTED);
    return;
}
```
```php
// app/CoreFacturalo/WS/Reader/DomCdrReader.php:29
throw new \Exception('Not found cdr response in xml', 'ERROR_CDR');
```
- Failure scenario: An invoice fails SOAP delivery with a transport/auth fault containing no digits -> `Facturalo` persists `state_type_id = '05'` (Accepted by SUNAT) and tells the caller it succeeded. A malformed CDR XML crashes with `TypeError`.
- Proposed fix: Only accept when `ctype_digit((string) $code) && (int) $code === 0` (or `(string) $code === '0'`); treat non-numeric error codes as transport/regularize failures (`updateRegularizeShipping` or throw for retention/dispatch). In `DomCdrReader`, pass integer `0` (or a dedicated exception) and suppress/handle `DOMDocument::loadXML` failures; in `BaseSunat::getErrorFromFault`, use `$fault->detail->message ?? $fault->faultstring`.
- Verification idea: Unit test `validationCodeResponse('soap-env:Client', 'Unauthorized')` and assert state does not transition to `'05'`; test `DomCdrReader::getCdrResponse('<empty/>')` throws without `TypeError`.

### CORE-002 — `Facturalo::statusSummary` marks voided documents `VOIDED` (`11`) even when SUNAT rejects the Voided batch (`99`)
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/Facturalo.php:830-891` (`statusSummary`, `validationStatusCodeResponse`)
- Problem: When querying a `Voided` (`Comunicación de Baja`) ticket via `ExtService::getStatus`, SUNAT `statusCode = '99'` with a rejection CDR code (`>= 2000`, not `100..1999`) returns `isSuccess() = true` and `getCustomStatusCode() = 99`. `Facturalo::statusSummary` marks the `Voided` header `REJECTED` (`09`), then enters the `else` branch for non-`summary` types and unconditionally calls `$this->updateStateDocuments(self::VOIDED)`. Also, for `summary` with `summary_status_type_id === '3'` (voiding accepted boletas) rejected with `99`, `updateStateDocuments(self::REGISTERED)` resets accepted `05` boletas back to `01`.
- Evidence:
```php
// app/CoreFacturalo/Facturalo.php:851-872
$this->validationStatusCodeResponse($extService->getCustomStatusCode());
if($this->type === 'summary') {
    if($extService->getCustomStatusCode() === 0){ ... }
    else if($extService->getCustomStatusCode() === 99){
        $this->updateStateDocuments(self::REGISTERED);
    }
} else {
    $this->updateStateDocuments(self::VOIDED);
}
```
- Failure scenario: A `Voided` submission rejected by SUNAT (`99`) marks all referenced facturas as `VOIDED` (`11`) locally while SUNAT still considers them valid and active (causing fiscal/SIRE mismatch).
- Proposed fix: For `voided`, call `updateStateDocuments(self::VOIDED)` only when `$extService->getCustomStatusCode() === 0`, and restore `self::ACCEPTED` (`05`) on `99`. For `summary` with `summary_status_type_id !== '1'`, restore `self::ACCEPTED` (`05`) instead of `self::REGISTERED` on `99`.
- Verification idea: Mock `ExtService` returning `statusCode = '99'` with CDR code `2200` for a `Voided` batch and assert referenced documents are not set to `'11'`.

### CORE-003 — `CulqiCharger::charge` treats HTTP `402` card declines as `unknown`, locking platform payments and misclassifying store declines
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Services/Payments/CulqiCharger.php:79-94, 135-154`, `app/Services/Payments/PlatformPayment.php:29-31, 40-43`, `app/Services/Payments/EcommerceCheckout.php:34-44`
- Problem: Culqi API v2 `/v2/charges` returns HTTP `402` (`Payment Required`, `type: card_error`) for card declines (insufficient funds, issuer decline, etc.). The new Guzzle fallback in `CulqiCharger` throws `new \Culqi\Error\UnhandledError($body, $status)` for `402`, and `CulqiCharger::charge()` line 136 only treats `(int) $e->getCode() === 400` as a card decline. Consequently, `402` falls through to line 151 (`unknown => true`).
- Evidence:
```php
// app/Services/Payments/CulqiCharger.php:135-140, 151-153
// Only a clear HTTP 400 is a card decline.
if ($e instanceof \Culqi\Error\UnhandledError && (int) $e->getCode() === 400) {
    $this->log('warning', 'Culqi charge declined', $logContext);
    return $this->fail('El pago fue rechazado. Verifique los datos de su tarjeta o utilice otro medio de pago.');
}
...
$this->log('error', 'Culqi charge outcome unknown', $logContext);
return ['paid' => false, 'unknown' => true, 'id' => null, 'message' => self::UNKNOWN_MESSAGE];
```
- Failure scenario: A tenant or shopper tries a card that is declined with HTTP `402`. `PlatformPayment` writes `reference_payment = 'culqui:pendiente'`, which permanently blocks any retry with a different card (line 29) until manual database intervention; `EcommerceCheckout` leaves the declined order un-deleted as `culqui:pendiente`.
- Proposed fix: Treat `UnhandledError` with HTTP `400` or `402` as a card decline (`in_array((int) $e->getCode(), [400, 402], true)`).
- Verification idea: Add a case in `tests/Unit/PaymentsTest.php` throwing `UnhandledError('{"object":"error","type":"card_error"}', 402)` and assert `unknown === false` and `paid === false`.

### CORE-004 — `CartPricing::forStore` prices USD store items at `S/ 0.00` (or `1:1` PEN) when exchange rate lookup fails
- Category: functional-bug (calculation)
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Services/Payments/CartPricing.php:57-61, 78-80, 96-102`, `app/Http/Controllers/Tenant/Api/ServiceController.php:118-120`
- Problem: `ServiceController::exchangeRateTest` returns `['date' => ..., 'sale' => 0]` when no exchange rate exists in DB and SUNAT lookup fails. `CartPricing::forStore` casts `(float) ($rate['sale'] ?? 1)`, yielding `0.0` (or `1.0` if `sale` is absent), and `CartPricing::total` never validates `$exchangeRate > 0`. It only rejects when the aggregate cart `round($total, 2) <= 0`. (Related to `MOP-005`.)
- Evidence:
```php
// app/Services/Payments/CartPricing.php:100-101
$rate = app(ServiceController::class)->exchangeRateTest(date('Y-m-d'));
$exchangeRate = (float) ($rate['sale'] ?? 1);
```
- Failure scenario: When no exchange rate is available, adding any PEN item alongside high-value USD items passes `total > 0` and checks out USD items for `S/ 0.00` (or charges `$100` USD as `S/ 100` if `sale` is null).
- Proposed fix: In `CartPricing::total` (and `forStore`), throw `InvalidArgumentException` if any item has `currency_type_id !== 'PEN'` and `$exchangeRate <= 0` (and do not fall back to `1` when `sale` is missing).
- Verification idea: Unit test `CartPricing::total` with a USD item + PEN item and `$exchangeRate = 0.0` expecting `InvalidArgumentException`.

### CORE-005 — `Facturalo::update` corrupts inventory/kardex, orphans `global_payments`, and lacks state/null guards
- Category: functional-bug (data-integrity)
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (touches inventory/kardex/payment side effects and invoice edit rules)
- Location: `app/CoreFacturalo/Facturalo.php:1100-1146`, `app/CoreFacturalo/Requests/Inputs/DocumentUpdateInput.php:117, 127, 210, 440`
- Problem: Wired to `DocumentController::update` (`input.request:documentUpdate,web`): (a) `Document::find($id)` has no null check and no `state_type_id === '01'` guard; (b) `$document->payments()->delete()` runs a SQL bulk delete that skips `DocumentPayment::deleted` in `DocumentPaymentProvider` and `GlobalPaymentServiceProvider`, leaving orphaned `global_payments` rows; (c) lines 1126-1134 restore stock via `KardexTrait::restoreStockInWarehpuse` into `auth()->user()->establishment_id`'s warehouse (ignoring `$it->warehouse_id`, `presentation->quantity_unit`, `is_set` bundles, and leaving old `inventory_kardex` rows intact), then SQL-deletes `items()` and recreates them (firing `DocumentItem::created`, creating duplicate `inventory_kardex` rows); (d) re-runs `updatePrepaymentDocuments` without reverting prior deductions; (e) `DocumentUpdateInput` accesses `$inputs['operation_type_id']` (crashes on notes), overwrites `filename` with `null` (line 127), reads `'start_date'` into `end_date` (line 210), and has typo `$$affected_document_id = $affected_document->id;` (line 440).
- Evidence:
```php
// app/CoreFacturalo/Facturalo.php:1123-1135
$document->payments()->delete();
$this->savePayments($document, $inputs['payments']);
$warehouse = Warehouse::where('establishment_id', auth()->user()->establishment_id)->first();
foreach ($document->items as $it) {
    $this->restoreStockInWarehpuse($it->item_id, $warehouse->id, $it->quantity);
}
$document->items()->delete();
```
- Failure scenario: Editing a document corrupts warehouse stock for sets/presentations/other establishments, orphans `global_payments`, double-deducts prepayments, wipes `filename`, and fatals on notes or missing IDs.
- Proposed fix: Enforce `findOrFail` + state `'01'` guard; delete old payments/items through model events or explicitly clean up `global_payments` and reverse `inventory_kardex`; preserve `filename` and fix `DocumentUpdateInput` typos.
- Verification idea: Integration test editing a draft invoice with payments and items; verify `global_payments` sum, `inventory_kardex` count, and stock match the updated lines only.

### CORE-006 — `Api\Validation\Functions::item` / `item2` bind empty `internal_id` (`''`) to arbitrary catalog items
- Category: functional-bug (data-integrity)
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/Requests/Api/Validation/Functions.php:67-111`, `app/CoreFacturalo/Requests/Api/Transform/DocumentTransform.php:83`, `app/CoreFacturalo/Requests/Api/Transform/DispatchTransform.php:120`, `app/CoreFacturalo/Requests/Web/Validation/Functions.php:65-79`
- Problem: `DocumentTransform::items` and `DispatchTransform::items` convert missing `codigo_interno` to `''`. `Api\Validation\Functions::item` runs `Item::where('internal_id', $inputs['internal_id'])->first()` (`item2` runs `Item::firstOrCreate(['internal_id' => $inputs['internal_id']], ...)` and also sets `stock` to `quantity` on the `items` table). `Web\Validation\Functions::item` has the same `updateOrCreate` collision and writes non-existent column `unit_price`.
- Evidence:
```php
// app/CoreFacturalo/Requests/Api/Validation/Functions.php:69-70
$item = Item::where('internal_id', $inputs['internal_id'])->first();
```
- Failure scenario: Any API invoice or dispatch line without `codigo_interno` matches the first item in `items` with `internal_id = ''`, writing the wrong `item_id` / description onto the fiscal document and deducting that unrelated item's stock.
- Proposed fix: Only query by `internal_id` when `trim((string) $inputs['internal_id']) !== ''`; otherwise create a new item with `internal_id = null` (or match by `description` + `unit_type_id` per business contract).
- Verification idea: Seed an item with `internal_id = ''`; post an API invoice line with no `codigo_interno` and a different description; assert the seeded item is not matched or deducted.

### CORE-007 — `Item::scopeWhereWarehouse` has an ungrouped `orWhere('unit_type_id', 'ZZ')` that breaks item filters
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Models/Tenant/Item.php:159-169`
- Problem: `scopeWhereWarehouse` chains `->whereHas('warehouses', ...)->orWhere('unit_type_id', 'ZZ')` without wrapping both conditions in a closure. SQL operator precedence turns `WHERE active = 1 AND is_set = 0 AND EXISTS(...) OR unit_type_id = 'ZZ'` into `(active = 1 AND ... AND EXISTS(...)) OR (unit_type_id = 'ZZ')`.
- Evidence:
```php
// app/Models/Tenant/Item.php:164-166
return $query->whereHas('warehouses', function($query) use($warehouse) {
    $query->where('warehouse_id', $warehouse->id);
})->orWhere('unit_type_id', 'ZZ');
```
- Failure scenario: Whenever a seller runs an item query (`whereTypeUser()` invokes `scopeWhereWarehouse`), all prior `WHERE` filters (`active = 1`, `is_set = 0`, `apply_store = 1`, search terms) are bypassed for every service item (`unit_type_id = 'ZZ'`).
- Proposed fix: Group in `->where(function ($q) use ($warehouse) { $q->whereHas(...)->orWhere('unit_type_id', 'ZZ'); })`, and null-check `auth()->user()`.
- Verification idea: Seed an inactive service (`active = 0, unit_type_id = 'ZZ'`) and run `Item::whereIsActive()->whereWarehouse()->get()`; assert it is excluded.

### CORE-008 — Heavy `$with` cascades (`Person` -> full Peru ubigeo tree, `Document` 10-relation graph, `RvieReplacementBuilder`)
- Category: performance / n+1
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (controllers/resources/templates may rely on default eager loads being present)
- Location: `app/Models/Tenant/Person.php:16`, `Establishment.php:13`, `Customer.php:13`, `Supplier.php:13`, `Catalogs/Department.php:11`, `Catalogs/Province.php:11`, `Document.php:14`, `Note.php:10`, `Dispatch.php:14-15`, `Voided.php:8`, `VoidedDocument.php:7`, `SummaryDocument.php:7`, `app/CoreFacturalo/Services/Sire/RvieReplacementBuilder.php:173, 250`
- Problem:
  1. `Person::$with` (and `Establishment`, `Customer`, `Supplier`) eager-loads `department` and `province`. `Department::$with = ['provinces']` and `Province::$with = ['districts']`. Loading any single `Person` or `Establishment` (including via `User::$with = ['establishment']`) loads every province in that department and every district in all of those provinces (~180 models for Lima).
  2. `Document::$with` loads 10 relations (`user` -> `establishment` ubigeo cascade, `items` -> 3 catalog relations, `invoice` -> `operation_type`, `payments` -> 2 catalog relations, `note` -> `affected_document` which re-triggers `Document::$with`!).
  3. `Dispatch::$with` loads `reference_document` (`Document`), `Voided::$with` loads `documents` (`VoidedDocument::$with = ['document']`), and `SummaryDocument::$with = ['document']`.
  4. `RvieReplacementBuilder::build` and `reconciliationRows` run `Document::query()` without `->setEagerLoads([])`, hydrating every item, payment, user, and catalog for an entire month of sales (and lazy-loading `note.affected_document` per note); `RceReplacementBuilder::build` and `PurchaseValidationService::periodQuery` omit `->with('supplier')`, causing 1 query per purchase.
- Failure scenario: Document/person listings, Voided/Dispatch views, `AccountsReceivable` exports, and SIRE generation multiply queries and RAM usage by orders of magnitude.
- Proposed fix: Remove `$with` from `Department` and `Province`; strip recursive `$with` from `Note` (`affected_document`), `Dispatch` (`reference_document`), `VoidedDocument`, and `SummaryDocument`; add `->setEagerLoads([])->with(['note.affected_document', 'fee'])` in `RvieReplacementBuilder` and `->with('supplier')` in `RceReplacementBuilder` / `PurchaseValidationService`.
- Verification idea: Enable query log on `RvieReplacementBuilder::reconciliationRows` and `Person::first()`; assert query count drops to O(1) per chunk and ubigeo rows are not loaded for the whole department.

### MEDIUM SEVERITY

### CORE-009 — `Api\Transform\*` reads required keys before validation (500 on malformed JSON) and has `codigo_producto_gsl` typo
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/InputRequest.php:22-26`, `app/CoreFacturalo/Requests/Api/Transform/DocumentTransform.php:15, 32, 84, 89-91, 278, 287, 290`, `DispatchTransform.php:20-21, 126`, `PerceptionTransform.php:16, 24`, `RetentionTransform.php:16, 23-24`, `SummaryTransform.php:12-13`, `VoidedTransform.php:12`, `Common/{Establishment,Exchange,Legend,Payment,Person}Transform.php`
- Problem: `InputRequest::handle` invokes `*Transform::transform($inputs)` before `*Validation::validation($inputs)`. Every API Transform class directly indexes required keys (`$inputs['totales']`, `$inputs['datos_del_cliente_o_receptor']`, `$inputs['codigo_tipo_documento']`, `$inputs['documento_afectado']`, `$row['descripcion']`, `strtoupper($row['unidad_de_medida'])`, etc.) without checks. Separately, `DocumentTransform.php:89` and `DispatchTransform.php:126` read `'codigo_producto_gsl'` (typo for `gs1`).
- Failure scenario: Missing/malformed fields in API requests throw `Undefined array key` / `TypeError` (HTTP 500) instead of a validation error; GS1 product codes sent as `codigo_producto_gs1` are silently dropped as `null`.
- Proposed fix: Guard array accesses in Transform classes (or validate payload shape first); support `$row['codigo_producto_gs1'] ?? $row['codigo_producto_gsl'] ?? null`.
- Verification idea: POST `{}` to `/api/documents` (expect 422 JSON, not PHP Undefined array key); send `codigo_producto_gs1` and assert `item_code_gs1` is populated.

### CORE-010 — Missing `use Exception;` in `PerceptionValidation` / `RetentionValidation`, typos and missing `find()` null checks across `Requests/`
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/Requests/Web/Validation/PerceptionValidation.php:11`, `RetentionValidation.php:15`, `DocumentValidation.php:9`, `DocumentUpdateValidation.php:9`, `Functions.php:40, 127`, `Requests/Inputs/DocumentInput.php:156, 338, 414, 472, 515-516`, `Requests/Inputs/Transform/DocumentWebTransform.php:94, 118, 179`, `Requests/Inputs/DispatchInput.php:149, 227`, `Requests/Inputs/Common/EstablishmentInput.php:11-32`
- Problem:
  - `Web\Validation\PerceptionValidation:11` and `RetentionValidation:15` throw `new Exception(...)` without `use Exception;` -> fatal `Class "...\Web\Validation\Exception" not found`.
  - `DocumentValidation:9` and `DocumentUpdateValidation:9` read `$series->number` with no null check.
  - `Web\Validation\Functions::identityDocumentTypeInvoice:127` reads `$inputs['operation_type_id']` unguarded (absent on credit/debit notes `07`/`08`); line 40 reads `Person::find($inputs['id'])->id`.
  - `DocumentInput:516` and `DocumentUpdateInput:440` have `$$affected_document_id = $affected_document->id;` (variable-variable + null deref); `DocumentInput:414` reads `$inputs['operation_type_id']` in `detraction()` (crashes if a note carries a detraction); `DocumentInput:472` (`hotel()`) and `DocumentUpdateInput:408` read `$inputs['hotel']` without `?? []`.
  - `DocumentInput:156,338`, `DispatchInput:227`, `DocumentWebTransform:118,179`, `EstablishmentInput:11-32` dereference `find()` and catalog relations (`country`, `department`, `province`, `district`) without null checks; `DispatchInput::ubigeo([])` returns `false`.
- Failure scenario: Invalid `series_id`, missing item/document/establishment, or submitting a web credit note with detraction triggers fatal PHP errors instead of clean validation errors.
- Proposed fix: Import `Exception`, guard all `find()` calls and array keys, fix `$$affected_document_id`, and wrap catalog relations in `optional()`.
- Verification idea: Submit `Web\Validation\PerceptionValidation::validation(['series_id' => 999999])` and verify `\Exception` is thrown instead of class-not-found `Error`.

### CORE-011 — `DocumentInput::detraction` arbitrary local file read (`temp_path`) and client-controlled file extension
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/Requests/Inputs/DocumentInput.php:432-444`
- Problem: When `detraction[image_pay_constancy][temp_path]` is present, `DocumentInput` passes the client-supplied `temp_path` directly to `file_get_contents()` and copies it into `public/uploads/image_detractions/` using `explode('.', $image_pay_constancy['image'])[1]` as extension (cross-ref `SEC-002` / `SEC-003`).
- Evidence:
```php
// app/CoreFacturalo/Requests/Inputs/DocumentInput.php:436-441
$file_name_old = $image_pay_constancy['image'];
$file_name_old_array = explode('.', $file_name_old);
$file_content = file_get_contents($image_pay_constancy['temp_path']);
$file_name = $detraction_type_id.'-'.$bank_account.'-'.$datenow.'.'.$file_name_old_array[1];
Storage::put($directory.$file_name, $file_content);
```
- Failure scenario: An authenticated user supplies `temp_path=/var/www/html/.env` and `image=a.txt` to expose secrets via the public storage URL, or sends `image=noext` to trigger `Undefined array key 1` (500).
- Proposed fix: Validate that `temp_path` matches a server-issued session token inside `sys_get_temp_dir()` / dedicated tmp folder, verify image MIME type, and derive extension from MIME.
- Verification idea: Submit `temp_path=/etc/hosts` in document `detraction` payload; assert rejection (422).

### CORE-012 — Unescaped SOL password in `WsSecurityHeader` breaks SOAP auth for special XML characters
- Category: security / functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/WS/Client/WsSecurityHeader.php:20, 26`
- Problem: `$password` is formatted directly via `sprintf` into an XML `<o:Password>` string and wrapped in `SoapVar(..., XSD_ANYXML)` without XML escaping.
- Evidence:
```php
// app/CoreFacturalo/WS/Client/WsSecurityHeader.php:20, 26
$header_password = sprintf(self::PASSWORD_FORMAT, self::WSS_NAMESPACE, self::PASSWORD_TYPE, $password);
...
new SoapVar($header_password, XSD_ANYXML),
```
- Failure scenario: Any tenant whose SOL password contains `&`, `<`, or `>` sends malformed XML in the WS-Security header, breaking every SUNAT/OSE SOAP submission.
- Proposed fix: Escape `$password` with `htmlspecialchars((string) $password, ENT_XML1 | ENT_QUOTES, 'UTF-8')`.
- Verification idea: Construct `new WsSecurityHeader('USER', 'a&b<c>')` and verify serialized SOAP XML parses cleanly with `DOMDocument::loadXML`.

### CORE-013 — Race conditions in correlative numbering / Summary-Voided identifiers, negative overpayment in `savePayments`, and loose prepayment matching
- Category: functional-bug (data-integrity)
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (adds row locks and alters payment/prepayment matching rules)
- Location: `app/CoreFacturalo/Requests/Inputs/Functions.php:13-39, 69-86`, `app/CoreFacturalo/Facturalo.php:1000-1028, 1043-1098`
- Problem:
  - `Functions::newNumber` reads `max(number)` without `lockForUpdate()`, while `documents` (`database/migrations/tenant/2018_06_17_000002_tenant_documents_table.php`) has no unique index on `(soap_type_id, document_type_id, series, number)`. Concurrent emissions can produce duplicate fiscal numbers.
  - `Functions::identifier` fetches all `Summary`/`Voided` rows for the date (`->get()`) and computes `count($documents) + 1` without a lock; deleting any row causes identifier/filename collisions (`RC-YYYYMMDD-N`).
  - `Facturalo::savePayments`: when `$balance < 0` (sum of payments exceeds total) and there is no cash (`'01'`) payment, subtracts `abs($balance)` from the first payment row (`$row['payment'] - abs($balance)`), which goes **negative** if the first payment is smaller than the excess.
  - `Facturalo::updatePrepaymentDocuments`: splits `$row['number']` on `'-'` unguarded and matches `Document::where([['series', $series], ['number', $number]])` without filtering `document_type_id` or `soap_type_id`.
- Failure scenario: Concurrent POS sales receive the same invoice number; voiding/summary generation after deleting a failed batch overwrites an existing XML/identifier; mixed-method overpayments create negative payment records; prepayments deduct from a same-series document in another environment/type.
- Proposed fix: Use `lockForUpdate()` inside the transaction for numbering and parse max suffix + 1 for `identifier`; clamp payment deductions or distribute change cleanly; filter `updatePrepaymentDocuments` by `soap_type_id` and `document_type_id`.
- Verification idea: Unit test `savePayments` with `total = 100`, payments `[20 (card), 100 (card)]` and assert no payment row becomes negative.

### CORE-014 — `Facturalo::consultCdr` unconditionally marks documents `ACCEPTED` (`05`) and crashes on missing CDR ZIP
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/Facturalo.php:893-914`, `app/CoreFacturalo/WS/Services/ConsultCdrService.php:55-70`, `app/Http/Controllers/Tenant/DocumentController.php:573-588`
- Problem: `WS\Services\ConsultCdrService::getStatusResult` sets `$result->setSuccess(true)` even when `$statusCdr->content` is absent (e.g. `statusCode = '0004'`/`'0127'`, since `isExceptionCode(4)` is false). `Facturalo::consultCdr` does not check `$res->getCdrResponse()` for null (fatal `$cdrResponse->getCode()`) and always calls `$this->updateState(self::ACCEPTED)` without passing the CDR response code through `validationCodeResponse()`. (Note: `DocumentController::consultCdr` route is commented out per `TC1-026`, though `Facturalo::consultCdr` remains public.)
- Failure scenario: Calling `consultCdr()` when SUNAT returns no CDR ZIP crashes with a null dereference; a CDR containing a rejection code (`2000-3999`) marks the document `ACCEPTED` (`05`).
- Proposed fix: Guard `if (!$cdrResponse) { throw new Exception(...); }` and pass `$cdrResponse->getCode()` to `validationCodeResponse()`.
- Verification idea: Mock `ConsultCdrService` returning `statusCode = '0004'` without `content`, or a CDR with code `2325`, and assert state never flips to `'05'`.

### CORE-015 — `Api\Validation\Functions::person` blanks existing customer fields on partial updates; `PersonInput` has un-scoped `address_id` lookup
- Category: functional-bug / idor
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes API person upsert behavior)
- Location: `app/CoreFacturalo/Requests/Api/Validation/Functions.php:27-65`, `app/CoreFacturalo/Requests/Inputs/Common/PersonInput.php:25, 37-39`
- Problem: `Api\Validation\Functions::person` passes `updateOrCreate` with `address`, `email`, `telephone`, `department_id`, `province_id`, `district_id`, `trade_name`, `country_id` taken from `valueKeyInArray(..., null)`. When an API client omits optional fields on a repeat invoice, existing customer contact/address data in `persons` is overwritten with `null`. `validateUbigeo` also passes `null` to `strlen()` (PHP 8.1+ deprecation). Separately, `PersonInput::set($person_id, $address_id)` runs `PersonAddress::find($address_id)` without checking `person_id`, and sets `'country_id' => $person->country_id` even when `$customer_address` supplies `'country'['id']`.
- Failure scenario: An API integration omitting optional email/address wipes stored customer records; passing another customer's `customer_address_id` embeds their address on the emitted CPE.
- Proposed fix: Only overwrite non-null attributes on existing `Person` records (or `firstOrCreate`), cast `(string) $ubigeo`, and scope `PersonAddress::where('person_id', $person_id)->find($address_id)`.
- Verification idea: Post two API invoices for the same RUC (second omitting `correo_electronico`), assert stored email is preserved.

### CORE-016 — `System\ClientPayment`, `CardBrand`, `PlanDocument`, and `System\FormatTemplate` have misconfigured DB connections
- Category: functional-bug (multi-tenancy)
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Models/System/ClientPayment.php:4, 7`, `app/Models/System/CardBrand.php:4, 7`, `app/Models/System/PlanDocument.php:7`, `app/Models/System/FormatTemplate.php:8, 16`
- Problem: `ClientPayment` and `CardBrand` import `Hyn\Tenancy\Traits\UsesSystemConnection` on line 4 but omit `use UsesSystemConnection;` inside the class. `PlanDocument` also omits `UsesSystemConnection`. Meanwhile, `app/Models/System/FormatTemplate.php` uses `UsesTenantConnection` inside the `System` namespace.
- Evidence:
```php
// app/Models/System/ClientPayment.php:3-9
namespace App\Models\System;
use Hyn\Tenancy\Traits\UsesSystemConnection;
use Illuminate\Database\Eloquent\Model;

class ClientPayment extends Model
{ // missing: use UsesSystemConnection;
```
- Failure scenario: When the default DB connection is not `system`, `AccountController::paymentCulqui`'s `ClientPayment::find(...)` and its `card_brand` eager load hit the wrong database; `System\FormatTemplate` writes/reads the tenant DB.
- Proposed fix: Add `use UsesSystemConnection;` to `ClientPayment`, `CardBrand`, and `PlanDocument`; remove or fix `System\FormatTemplate`.
- Verification idea: Check `(new ClientPayment)->getConnectionName()` equals `'system'` regardless of default `DB_CONNECTION`.

### CORE-017 — `AccountsReceivable` export queries wrong DB connection, misuses `DB::raw`, and runs unbounded N+1 + `Dispatch::$with` cascade
- Category: functional-bug / performance
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Exports/AccountsReceivable.php:26, 44, 46, 69-135`
- Problem: Lines 26 and 46 run `DB::table('document_payments')` / `DB::table('sale_note_payments')` on the default connection instead of `DB::connection('tenant')` (used on lines 30 and 52). Line 44 passes `"companies.trade_name"` as an ignored second argument to `DB::raw(...)`. Line 69 calls `->get()` on all unpaid rows and executes per-row queries (`Invoice::where`, `Dispatch::where` triggering `Dispatch::$with` -> `Document::$with` cascade, `DocumentPayment::where`/`SaleNotePayment::where`) and calls `->format('Y-m-d')` on nullable dates (`date_of_due`, `date_of_shipping`, `date_of_payment`).
- Failure scenario: Exporting unpaid receivables fails if `DB_CONNECTION != tenant`, crashes on a dispatch/invoice/payment with null dates, and fires thousands of queries on moderate datasets.
- Proposed fix: Use `DB::connection('tenant')->table(...)`, drop the stray `DB::raw` arg, preload invoices/dispatches/latest payments in bulk with `setEagerLoads([])`, and use `optional($date)->format('Y-m-d')`.
- Verification idea: Unit/feature test `AccountsReceivable::view()` with a document having null `date_of_due` and verify constant query count.

### CORE-018 — All 4 Excel `Imports/` load entire files in memory (`ToCollection`), lack validation, and `DocumentsImport*` have critical bugs
- Category: functional-bug / performance
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE for missing `use Exception`, `dd()`, and copy-paste formula fix; RISKY for queued/chunked refactor
- Location: `app/Imports/DocumentsImport.php:49-76, 187-205`, `app/Imports/DocumentsImportTwoFormat.php:52-75, 189, 203-221`, `app/Imports/ItemsImport.php:46, 74-170`, `app/Imports/PersonsImport.php:22-63`
- Problem:
  - All 4 importers use `ToCollection` without `WithChunkReading`, `WithValidation`, or transactions; `$total = count($rows)` counts the header row; `ItemsImport` and `PersonsImport` run N+1 `updateOrCreate`/`where` queries per row without short-row guards.
  - `DocumentsImport` & `DocumentsImportTwoFormat` catch `Exception` without `use Exception;` (never catches `\Exception`), invoke `dd($e)` (dumps stack traces and Bearer token), execute synchronous self-HTTP `POST url('/api/documents')` via `new \GuzzleHttp\Client()` per row (no timeout; PHP-FPM self-deadlock risk), leave `$client_document_type`/`$company_number`/`$company_name` undefined on edge rows, and pass literal `'Registre nueva moneda'` as `codigo_tipo_moneda` when column 11/5 != `'S'`.
  - `DocumentsImportTwoFormat:189` computes the second item's `valor_unitario` using the **first item's** `$precio_unitario - ($total_impuesto / $cantidad)` (wrong calculation + `DivisionByZeroError` if `$cantidad == 0`).
  - `ItemsImport:81` calls `Date::excelToDateTimeObject($date_of_due)` without checking if Excel already parsed a string date; line 46 calls `strtoupper($row[8])` (PHP 8 deprecation if null).
- Failure scenario: Large Excel uploads exhaust memory or deadlock PHP-FPM; importing two-line documents in `DocumentsImportTwoFormat` miscalculates item 2's unit value; any Guzzle error passes uncaught or terminates with `dd()`.
- Proposed fix: Add `use Exception;`, remove `dd($e)`, fix line 189 variables (`$precio_unitario_s`, `$total_impuesto_s`, `$cantidad_s` with zero guard), initialize client vars safely, handle string/numeric dates in `ItemsImport`; long-term, call `Facturalo` directly in chunks rather than self-HTTP Guzzle.
- Verification idea: Import a 2-item row via `DocumentsImportTwoFormat` where item 1 and item 2 have different prices and verify item 2 `valor_unitario`.

### CORE-019 — `NumberLetter::convertToLetter` drops decimals >2 digits (`00/100`) and `X509Certificate` swaps `getValidFrom` / `getExpiration`
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/Helpers/Number/NumberLetter.php:65-76`, `app/CoreFacturalo/WS/Signed/Certificate/X509Certificate.php:72-105`
- Problem:
  - `NumberLetter::convertToLetter`: when `$number` has 3+ decimal digits (e.g. `100.125`), `$decNumberStr = str_pad('125', 2, '0', STR_PAD_RIGHT)` stays `'125'`, `if (strlen($decNumberStr) == 2)` is false, `$decimales` stays `''`, and line 124 outputs `00/100` instead of `13/100`.
  - `X509Certificate`: `getValidFrom()` reads `validTo_time_t` (expiration), `getExpiration()` reads `validFrom_time_t` (valid-from), and `getIssuer()` reads `'subject'` instead of `'issuer'`.
- Failure scenario: Totals not pre-rounded to 2 decimals render as `00/100` in words on PDF/XML; callers checking certificate validity/expiration via `X509Certificate` read swapped dates.
- Proposed fix: Normalize `$number = number_format((float) $number, 2, '.', '')` in `NumberLetter::convertToLetter`; swap `validFrom_time_t`/`validTo_time_t` and read `'issuer'` in `X509Certificate`.
- Verification idea: Assert `NumberLetter::convertToLetter(100.125)` returns `'Cien con 13/100 '` and `X509Certificate::getExpiration() > getValidFrom()`.

### CORE-020 — Eloquent model mismatches in `Purchase`, `Configuration`, `UnitType`, `Invoice`, and `PersonAddress`
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Models/Tenant/Purchase.php:58, 154-162`, `Configuration.php:87-90`, `Catalogs/UnitType.php:32-35`, `Invoice.php:22-25`, `PersonAddress.php:49-54`
- Problem:
  - `Purchase` has fillable `'related'` and getter `getRelatedAttribute`, but the mutator is named `setRelatedDocumentsAttribute` -> assigning `related` bypasses `json_encode`.
  - `Configuration::getFinancesAttribute` returns an `array` when null and an `object` (`stdClass`) when JSON is stored.
  - `UnitType::item_unit_types()` references `ItemUnitType::class` without importing `App\Models\Tenant\ItemUnitType` (resolves to non-existent `Catalogs\ItemUnitType`).
  - `Invoice::document()` defines `hasOne(Document::class)` instead of `belongsTo(Document::class)` (`document_id` is on `invoices`, not `documents.invoice_id`).
  - `PersonAddress::setLocationIdAttribute` calls `count($value)` without `is_array($value)` (PHP 8 `TypeError` on null/string).
- Failure scenario: Setting `$purchase->related`, calling `$unitType->item_unit_types`, calling `$invoice->document`, or passing null `location_id` triggers PHP/SQL errors; `Configuration::finances` breaks object/array consumers inconsistently.
- Proposed fix: Rename `setRelatedDocumentsAttribute` -> `setRelatedAttribute`, cast default `finances` to `(object)`, import `ItemUnitType`, change `Invoice::document()` to `belongsTo`, and guard `is_array($value)`.
- Verification idea: Call `$invoice->document`, `$unitType->item_unit_types`, and `$address->location_id = null` in unit tests.

### LOW SEVERITY

### CORE-021 — PHP 8.2/8.3 dynamic properties and null-to-string deprecations in Exports, `Facturalo`, `Reniec`, and `Document*`
- Category: maintainability / php8-compat
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Exports/{CashProductExport,ClientExport,DocumentExport,DocumentPaymentExport,InventoryExport,ItemExport,ItemExportBarCode,ItemExportWp,KardexExport,PaymentExport,PurchaseExport,QuotationExport,SaleNoteExport}.php` (13 files), `app/Exports/PaymentExport.php:6` (unused non-existent `App\Models\Tenant\Documents`), `app/Models/Tenant/{Document:228-232,DocumentItem:125-134}.php`, `app/CoreFacturalo/Facturalo.php:290, 336-337, 362-369, 416-417, 537`, `app/CoreFacturalo/Services/Dni/Reniec.php:116-118`, `app/CoreFacturalo/Requests/Inputs/DocumentInput.php:163`
- Problem: All 13 non-`AccountsReceivable` Exports assign `$this->records`/`$this->company`/etc. without property declarations (PHP 8.2+ dynamic property deprecation). `Document` and `DocumentItem` `getAdditionalInformationAttribute($value)` run `explode('|', $value)` when `$value` is `null` (PHP 8.1+ deprecation, and returns `[""]`, causing `Facturalo::createPdf` line 369 to add `5` mm per item even when empty!). `Facturalo::createPdf` also creates a dead `new Mpdf()` (line 290), calls `strlen()` on nullable `customer->name`/`address`, and passes `$document` (method arg, null when omitted) instead of `$this->document` to `pdfFooterLegend` (line 537). `Reniec` uses deprecated `utf8_encode()`; `DocumentInput:163` calls `trim($item->item_code)` on null.
- Proposed fix: Declare properties on all 13 Export classes, remove unused `Documents` import in `PaymentExport`, return `[]` for null/empty `additional_information`, cast `(string)` before `strlen`/`trim`, pass `$this->document` to `pdfFooterLegend`, remove dead `new Mpdf()`.
- Verification idea: Run any export and `createPdf()` under PHP 8.3 with `E_DEPRECATED` promoted to exceptions.

### CORE-022 — Unguarded null dereferences, `verificationCode` logic bug, and missing cURL timeouts
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Models/Tenant/{Document:337,Purchase:262,Quotation:232,SaleNote:240}.php` (`getNumberToLetterAttribute`), `app/Models/Tenant/{Person:96,PersonAddress:72,Establishment:54,Customer:60,Supplier:60}.php` (`getAddressFullAttribute`), `app/Models/Tenant/{Document:359,Dispatch:324,Item:161,174,Purchase:268,Quotation:238,SaleNote:254,Cash:44,User:174}.php` (`scopeWhereTypeUser`), `app/Models/Tenant/{DocumentItem:174-178,SaleNoteItem:153-155}.php` (`count(json_decode($params['sellers']))`), `app/Services/{ItemSetService:23,TagsIntersect:13}.php`, `app/Traits/{InventoryKardexTrait:28-38,KardexTrait:32,SummaryTrait:31}.php`, `app/CoreFacturalo/Services/Helpers/Functions.php:9`, `app/CoreFacturalo/Services/Dni/{Essalud:36,Jne:50}.php`, `app/CoreFacturalo/Services/Extras/ExchangeRate.php:43`, `app/CoreFacturalo/WS/Gre/GreDispatchService.php:124, 230, 357`, `app/CoreFacturalo/WS/Signed/XmlSigned.php:71`
- Problem:
  - `getNumberToLetterAttribute()` reads `$legend->value` without null-checking legend `'1000'`.
  - `getAddressFullAttribute()` reads `$this->department->description` / `province` / `district` when relations are null.
  - `scopeWhereTypeUser()` reads `$user->type` when `auth()->user()` is null (CLI/queues).
  - `DocumentItem`/`SaleNoteItem::scopeWhereDefaultDocumentType` call `count(json_decode($params['sellers']))` (PHP 8 `TypeError` if null).
  - `Services/Helpers/Functions::verificationCode` checks `$number !== '' || strlen($number) === 8` (`||` instead of `&&`), reading invalid offsets on non-8-char inputs.
  - `Jne::search` and `ExchangeRate::search` set `CURLOPT_TIMEOUT => 0` (infinite hang) and `Jne`/`Essalud` read `$data['data']` / `$json->DatosPerson[0]` unguarded.
  - `GreDispatchService` reads `$dispatch->series[0]` without empty check, `Company::active()->number`, `$dispatch->state_type->description`; `XmlSigned::signXml` reads `ID->item(0)->nodeValue` unguarded and never resets `$this->signatureId` between documents.
  - `ItemSetService`, `TagsIntersect`, `InventoryKardexTrait`, `KardexTrait`, `SummaryTrait` dereference `find()` / `first()` results without null checks.
- Proposed fix: Add null checks / `optional()`, change `||` to `&&` + `ctype_digit` in `verificationCode`, set 10-15s timeouts on cURL, reset `$this->signatureId = null` in `XmlSigned::signXml`.
- Verification idea: Unit test `Functions::verificationCode('123')` and `Person::make(['address' => 'Av 1'])->address_full`.

### CORE-023 — Dead, broken, and PSR-4-mismatched classes
- Category: dead-code / maintainability
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/CoreFacturalo/TemplateBackup.php`, `app/CoreFacturalo/Services/Cpe/ConsultCdrService.php`, `app/CoreFacturalo/Services/Ruc/UserValidator.php`, `app/CoreFacturalo/Services/Dni/Reniec.php`, `app/CoreFacturalo/Services/Extras/ValidateCpe.php`, `app/CoreFacturalo/Services/Extras/ValidateCpe2.php:100, 170-207`, `app/CoreFacturalo/Requests/Web/Validation/Dispatch2Validation.php`, `app/Models/Tenant/{Customer,Supplier}.php`
- Problem: `grep` across `app/`, `modules/`, `routes/`, `config/` confirms zero callers for: `TemplateBackup`, `Services\Cpe\ConsultCdrService` (wrong namespace `App\CoreFacturalo\Cpe`, missing `Greenter\*` package, reversed `uploadStorage` args), `UserValidator` (broken imports), `Reniec` (wrong namespace `App\CoreFacturalo\Services\Reniec`), `ValidateCpe` (wrong namespace `App\Core\Services\Extras`), `Dispatch2Validation` (orphan of dead `Dispatch2Controller`), and `Tenant\{Customer,Supplier}` (no `customers`/`suppliers` tables exist). `ValidateCpe2` (used by `ValidateDocumentsCommand` and `modules/Document`) stores `public_path('cookie.txt')` in webroot and has a broken `validateAndChangeDocuments()` missing `Company`/`Document` imports with an infinite `goto reValidate;` loop.
- Proposed fix: Delete the dead classes (retained in git history); in `ValidateCpe2`, move the cookie file to `sys_get_temp_dir()` and remove `validateAndChangeDocuments`.
- Verification idea: Re-run `composer dump-autoload` and unit tests after removal.

### CORE-024 — `Item::scopeWhereHasInternalId` SQL `!= null` bug, `StorageDocument` switch fallthrough, `ReportTrait` scan, and missing `$hidden` secrets
- Category: maintainability / performance / security
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `app/Models/Tenant/Item.php:328-331`, `app/CoreFacturalo/Helpers/Storage/StorageDocument.php:54-56, 72-76`, `app/Traits/ReportTrait.php:18-32`, `app/Models/Tenant/User.php:33-35`, `app/Models/Tenant/Company.php:33`, `app/Models/System/Configuration.php:13-31`, `app/Models/Tenant/Document.php:268-271`
- Problem:
  - `Item::scopeWhereHasInternalId` uses `where('internal_id', '!=', null)` (SQL `!= NULL` is always `UNKNOWN`, returning 0 rows instead of `whereNotNull('internal_id')`).
  - `StorageDocument::setData`: `case 'purchase':` misses `break;` (falls into `devolution`); `purchase_order_attached` sets `$extension = ''` leaving a trailing `.` on `$this->_filename`.
  - `ReportTrait::getTypeDoc` / `getEstablishmentId` load `DocumentType::all()` / `Establishment::all()` (with full ubigeo `$with` cascade!) on every call.
  - `Tenant\User::$hidden` omits `password` and `api_token` (`SEC-016`); `Company::$hidden` omits `soap_password`; `System\Configuration` has no `$hidden` (`token_private_culqui`, `token_apiruc`, `soap_password`).
  - `Document::getCompanyAttribute` runs `Company::first()` per document instead of caching per tenant/request.
- Proposed fix: Use `whereNotNull('internal_id')`, add `break;` and omit trailing dot when `$extension === ''` in `StorageDocument`, query by `UPPER(description)` in `ReportTrait`, and add credentials to `$hidden`.
- Verification idea: Assert `Item::whereHasInternalId()->toSql()` uses `is not null` and `(new System\Configuration)->toArray()` excludes `token_private_culqui` and `soap_password`.

## Overflow themes
- **Unvalidated mass assignment & wide `$fillable`**: Most models (`Document`, `User`, `Company`, `Configuration`, `Person`, `Item`, `Cash`) keep sensitive/status columns in `$fillable` (`state_type_id`, `locked`, `type`, `soap_type_id`), relying on controllers/FormRequests not to pass raw `$request->all()`.
- **Plain HTTP & disabled TLS on legacy scrapers**: `Services\Extras\ExchangeRate`, `ValidateCpe`, `Ruc\Sunat`, `Ruc\UserValidator`, and `WsClient` (when `soap_stream_context_ssl` is enabled, see `SEC-013`) use `http://` or disable TLS peer verification.
- **Unbounded Blade-to-Spreadsheet exports**: All 14 `app/Exports/*` implement `FromView`, buffering the full HTML DOM + PhpSpreadsheet workbook in memory for large datasets.
