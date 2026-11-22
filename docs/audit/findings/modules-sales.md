# Findings — Modules: Document, Sale, Order, Pos, Purchase, Expense, Finance

Audit mode: read-only. Branch `chore/system-audit`. No secret values are quoted; secrets are referred to by config/column name.

## Coverage

| Module | Fully read | Pattern-scanned only | Not reviewed (why) | Routes | Controller actions |
|---|---|---|---|---|---|
| Document | Routes, all 4 controllers, ConsultCdr, SearchTrait, both Requests, all 4 Resources, SeriesConfiguration model, DocumentViewComposer | DocumentServiceProvider, RouteServiceProvider, Config, Seeder (diffed vs other modules) | Blade views (`not_sent`, `regularize_shipping`, `series_configurations`, `validate_documents`), Vue | 25 web + 1 api closure | 30 |
| Sale | Routes, all 10 controllers, ContractBillingService, ContractBillingPayload, RecurrenceSchedule, SaleOpportunityFileService, all 7 Requests, all 11 Resources, all 11 Models, both Mails, SaleNotePaymentProvider | SaleServiceProvider, RouteServiceProvider, Config, Seeder | Blade views (`contracts`, `production_orders`, `sale_opportunities`, `technical-services`, `user-commissions`), Vue | 77 web + 1 api closure | 89 |
| Order | Routes, all 5 controllers (incl. `Api\OrderNoteController`), OrderFormHelper, AddressFullService, all 4 Requests, all 10 Resources, all 6 Models, all 3 Mails | OrderServiceProvider, RouteServiceProvider, Config, Seeder | Blade views (`order_notes`, `order_forms`, `drivers`, `dispatchers`, `dispatches`), Vue | 55 web + 3 api | 60 |
| Pos | Routes, CashController, HistoryController, HistoryPurchasesCollection, HistorySalesCollection, CashTransaction model, ReportCashExport, CashEmail | PosServiceProvider, RouteServiceProvider, Config, Seeder | Blade views (`cash/report_*`, `email`), Vue | 5 web + 1 api closure | 5 |
| Purchase | Routes, all 6 controllers (incl. `Api\PurchaseController`), all 3 Requests, all 9 Resources, all 7 Models, all 3 Mails | PurchaseServiceProvider, RouteServiceProvider, Config, Seeder | Blade views, Vue | 54 web + 8 api | 76 |
| Expense | Routes, all 5 controllers, both Requests, all 6 Resources, all 6 Models | ExpenseServiceProvider, RouteServiceProvider, Config, Seeder | Blade views, Vue | 27 web + 1 api closure | 26 |
| Finance | Routes, all 11 controllers, ToPay, UploadFileHelper, FinanceTrait, UnpaidTrait, FilePaymentTrait, GlobalPaymentServiceProvider, IncomeRequest, all 7 Resources, all 9 Models, all 8 Exports | FinanceServiceProvider, RouteServiceProvider, Config, Seeder | Blade views (`report_pdf`, `report_excel`), Vue (only `to_pay/index.vue` read to confirm filter defaults) | 54 web + 1 api closure | 56 |

Out of scope / not done: Vue/Blade UI correctness (other auditors; Vue files `to_pay/index.vue`, `contracts/form.vue`, `unpaid/index.vue` checked only to confirm consumer fields/defaults), database execution, runtime checks. Cross-referenced `app/` and sibling modules only to verify findings (`config/permissions.php`, `AccessMap`, `EnforceModuleAccess`, `User`, `UserCollection`, `Document`, `DocumentItem`, `Purchase`, `PaymentMethodType`, `BankAccount`, `ValidateCpe2`, `DashboardView::getUnpaidFilterUser`, `InventoryKardexServiceProvider`, `InventoryVoidedServiceProvider`, `DocumentController`, `SaleNoteController`, `PurchaseController`, `migrations/tenant`, PDF template callers).

## Findings (ordered by severity)

### MSL-001 — `ContractController::tables()`, `UnpaidController::filter()`, and `ToPayController::filter()` leak `api_token` and password hashes
- Category: security
- Severity: critical
- Confidence: CONFIRMED (verified `User::$hidden`, `UserCollection`, and Vue dropdown consumers)
- Auto-fix: SAFE (select only `['id', 'name']`; Vue consumers only read `id` and `name`)
- Location: `modules/Sale/Http/Controllers/ContractController.php:160-163`; `modules/Finance/Http/Controllers/UnpaidController.php:55-58`; `modules/Finance/Http/Controllers/ToPayController.php:51-52`; `app/Models/Tenant/User.php:33-35`; `app/Http/Resources/Tenant/UserCollection.php:39`
- Problem: `App\Models\Tenant\User::$hidden` only hides `'remember_token'`, leaving `'password'` and `'api_token'` serialized by default. `ContractController::tables()` runs `User::without(['establishment'])->whereIn('type', ['seller'])->orWhere('id', auth()->user()->id)->get()` without column projection; `UnpaidController::filter()` runs `User::where('id', '!=', auth()->user()->id)->whereIn('type', ['admin', 'seller'])->get()` without column projection; `ToPayController::filter()` wraps `User::all()` in `UserCollection`, which explicitly returns `'api_token' => $row->api_token` for every user including admins and the tenant owner.
- Evidence:
```php
// ContractController.php:160-163
$sellers = User::without(['establishment'])
    ->whereIn('type', ['seller'])
    ->orWhere('id', auth()->user()->id)
    ->get();

// UnpaidController.php:57
$users = User::where('id', '!=', auth()->user()->id)->whereIn('type', ['admin', 'seller'])->get();

// ToPayController.php:51-52 -> UserCollection.php:39
$query_users = User::all();
$users = new UserCollection($query_users); // includes 'api_token' => $row->api_token
```
- Failure scenario: any non-owner user with `finance` (or `documents` + `contracts`) permission requests `GET /finances/to-pay/filter`, `GET /finances/unpaid/filter`, or `GET /contracts/tables` and extracts the tenant owner's/admin's `api_token` (or bcrypt password hashes) to authenticate against `auth:api` routes (`/api/documents`, `/api/purchases`, `/api/sale-note`, `/api/items`) with another user's identity and full API access.
- Proposed fix: replace all three queries with `User::get(['id', 'name'])` (matching `UserCommissionController::tables()`), and add `'password'` and `'api_token'` to `User::$hidden` (adjusting admin user management if needed). Checked `contracts/form.vue:130`, `unpaid/index.vue:96`, and `to_pay/index.vue:19`: all three only read `id` and `name`.
- Verification idea: feature test calling those three endpoints as a restricted user and asserting `api_token` and `password` are absent from the JSON payload.

### MSL-002 — Arbitrary local file read (`temp_path`) and client-controlled extensions across Document, Sale, Purchase, and Finance uploads
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY for changing the `temp_path` upload/save protocol (Vue consumers echo `temp_path`); SAFE for content-derived extensions, null/dot guards, and dropping `svg`
- Location: `modules/Document/Http/Controllers/DocumentController.php:125-167, 239-253`; `modules/Sale/Http/Controllers/SaleOpportunityFileController.php:15-92`; `modules/Purchase/Http/Controllers/PurchaseOrderController.php:161-174, 412-454`; `modules/Finance/Http/Controllers/PaymentFileController.php:20-66`; `modules/Finance/Traits/FilePaymentTrait.php:16-38`; `modules/Finance/Helpers/UploadFileHelper.php:10-28`
- Problem: All four upload flows return a raw `sys_get_temp_dir()` path as `temp_path` and trust the client to send back `temp_path` (or `attached_temp_path`) and original filename on save (related to SEC-002 / SEC-003). Specifically in these modules:
  1. `DocumentController::savePayConstancy`, `PurchaseOrderController::store`, `SaleOpportunityFileController::saveFiles`, and `FilePaymentTrait::saveFiles` pass request-controlled `temp_path` / `attached_temp_path` straight to `file_get_contents()` without checking that it was issued for this session or lives inside `sys_get_temp_dir()`.
  2. All four split the client-supplied filename on `'.'` and take index `[1]` (`$file_name_old_array[1]`) as the stored extension — allowing `.php` (and `DocumentController::savePayConstancy` writes to `public/uploads/image_detractions/`, web-served under `/storage`), using the wrong middle token on `a.b.pdf`, and throwing `Undefined array key 1` on a filename without a dot.
  3. `UploadFileHelper::validateUploadFile` allows `svg` by default (`'jpg,jpeg,png,gif,svg,pdf,xlsx'`), does not enforce `file`/`max` size rules, and `DocumentController::upload` explicitly enables `svg` for detraction pay constancies.
- Evidence:
```php
// DocumentController.php:244-249
$file_name_old_array = explode('.', $file_name_old);
$file_content = file_get_contents($image_pay_constancy['temp_path']);
$file_name = $detraction->detraction_type_id.'-'.$detraction->bank_account.'-'.$datenow.'.'.$file_name_old_array[1];
Storage::put($directory.$file_name, $file_content);
```
- Failure scenario: an authenticated user submits `temp_path=/var/www/html/.env` and `image=leak.txt` to `POST /documents/pay-constancy/save` (or `purchase-orders`, `sale-opportunities`, `*-payments`) and downloads `/storage/uploads/image_detractions/...txt` or `/finances/payment-file/download-file/...`, reading `.env` secrets or tenant certificates; or uploads an SVG containing `<script>` (stored XSS).
- Proposed fix: store uploaded files in a dedicated private temp directory keyed by an opaque token or session-bound basename validated via `realpath()` prefix check; derive the stored extension from `finfo`/`mime_content_type` against a strict whitelist (`jpg,jpeg,png,gif,pdf,xlsx`), drop `svg`, add `max:10240`, and guard missing dots.
- Verification idea: post `temp_path=/etc/hostname` and `filename=test` (no extension) to `savePayConstancy` and payment `store`; assert 422 and no file written.

### MSL-003 — Unmapped routes, missing `ajax => true` rules, and `payment-file` traversal bypass module permissions
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (requires confirming no cross-module form legitimately depends on the AJAX exemption or open `finances/payment-file` rule)
- Location: `config/permissions.php:39, 43, 48, 49, 57, 92, 94-98, 122-123, 138, 140-141, 165`; `modules/Sale/Routes/web.php:36-43, 118-136`; `modules/Finance/Routes/web.php:40-41, 81-97`; `modules/Document/Routes/web.php:17-40`; `modules/Order/Routes/web.php:13-41, 65-87`; `modules/Purchase/Routes/web.php:49-57`; `modules/Expense/Routes/web.php:11-62`; `modules/Pos/Routes/web.php:17-23`; `modules/Finance/Http/Controllers/PaymentFileController.php:16-18`
- Problem: `EnforceModuleAccess` allows any request whose path has no rule (`reason: unmapped`), has `module => null` (`open`), or sends `X-Requested-With: XMLHttpRequest` to a rule without `'ajax' => true` (`ajax-not-enforced`):
  1. **Completely unmapped** (open to any authenticated user, even non-AJAX): `payment-method-types/*` (full CRUD on `PaymentMethodType`; `permissions.php` only maps `list-payment-methods`), `quotation_payments/*` (`permissions.php` only maps `quotations`), and `technical-service-payments/*` (`permissions.php` only maps `technical-services`).
  2. **Explicitly open (`module => null`) + directory traversal**: `finances/payment-file` allows any authenticated user (including storefront `type='client'` users) to upload temp files and call `GET /finances/payment-file/download-file/{filename}/{type}` (`Storage::disk('tenant')->download('payment_files/'.$type.'/'.$filename)`). Passing `$type = '..'` escapes `payment_files/` to the tenant storage root (`signed/`, `pdf/`, `cdr/`, `certificates/`, `sale_opportunity_files/`, `purchase_order_attached/`), and `SaleOpportunityFileController::download($filename)` also lacks DB record/ownership validation.
  3. **Missing `'ajax' => true` on write/admin endpoints**: `series-configurations/*` (`configuration`), `documents/*` (`pay-constancy/save`, `consult_cdr`), `order-notes/*` (`store`, `update`, `voided`, `documents`), `drivers/*` & `dispatchers/*` (also return `config('configuration.api_service_token')` in `tables()`, cf. MOP-012), `cash/*`, `purchase-payments/*`, `expenses/*`, `expense-payments/*`, `expense-types/*`, `expense-reasons/*`, `expense-method-types/*`, `income-types/*`, `income-reasons/*`.
- Failure scenario: a user without `configuration` or `finance` permissions sends `POST /payment-method-types` or `DELETE /quotation_payments/1` directly, or calls `POST /series-configurations` / `DELETE /expense-types/1` with `X-Requested-With: XMLHttpRequest`, or downloads another document's files via `finances/payment-file/download-file/<name>/..`.
- Proposed fix: add rules for `payment-method-types`, `quotation_payments`, `technical-service-payments`; add `'ajax' => true` to write/admin routes (keeping exemptions only on read-only cross-module form lookups if needed); whitelist `$type` in `PaymentFileController::download` (`['documents','sale_notes','purchases','expenses','quotations']`) and verify a `PaymentFile` record exists with `basename($filename)`.
- Verification idea: `AccessMap` unit tests asserting 403 for unprivileged users on `payment-method-types`, `quotation_payments`, `series-configurations`, and traversal test on `payment-file/download-file/x/..`.

### MSL-004 — Infinite `goto reValidate;` retry loop in `ValidateDocumentController` (including inside a DB transaction)
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (replace `goto` with bounded retry loop and fail cleanly)
- Location: `modules/Document/Http/Controllers/ValidateDocumentController.php:44-70` (`validateDocuments`), `127-159` (`regularize`); `app/CoreFacturalo/Services/Extras/ValidateCpe2.php:79-163`
- Problem: Both `validateDocuments()` (triggered on `GET reports/validate-documents/records`) and `regularize()` (triggered on `POST reports/validate-documents/regularize`) use `reValidate: ... if ($response['success']) { ... } else { goto reValidate; }` with no attempt limit or delay. `ValidateCpe2::search()` fetches a CAPTCHA image from SUNAT and runs `TesseractOCR`, returning `['success' => false]` on any HTTP failure, OCR failure, missing Tesseract binary, or non-1 `rpta`. In `regularize()`, this infinite loop runs inside `DB::connection('tenant')->transaction(...)` over an unbounded `->get()`.
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
- Failure scenario: whenever SUNAT's CAPTCHA endpoint is slow/down, changes HTML/JSON, or Tesseract misreads a CAPTCHA/fails, the PHP-FPM worker spins in an endless loop hammering SUNAT until `max_execution_time` kills it; in `regularize()`, the open DB transaction holds locks across the entire hang.
- Proposed fix: replace `goto reValidate;` with a bounded loop (e.g. max 3 attempts with short backoff), return an error status/message per document when retries exhaust, and only wrap the state updates in the DB transaction (never external HTTP/OCR calls).
- Verification idea: unit test with mocked `ValidateCpe2` returning `success => false` verifying `validateDocuments`/`regularize` terminates after N attempts.

### MSL-005 — Un-grouped `orWhere` clauses bypass `whereTypeUser()`, `whereWarehouse()`, `whereType()`, and `whereIsEnabled()`
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (wrap OR predicates inside `->where(function ($query) { ... })`)
- Location: `modules/Document/Traits/SearchTrait.php:28-43` (`getItemsServices`), `61-74` (`getItemsNotServices`); `modules/Document/Http/Controllers/DocumentController.php:213-216`; `modules/Purchase/Http/Controllers/Api/PurchaseController.php:78-83`; `modules/Order/Http/Controllers/OrderNoteController.php:174-176`; `modules/Sale/Http/Controllers/ContractController.php:128-131`; `modules/Sale/Http/Controllers/SaleOpportunityController.php:109-111`; `modules/Sale/Http/Controllers/TechnicalServiceController.php:75-77`
- Problem: Because SQL `AND` binds tighter than `OR`, chaining `->where(...)->whereTypeUser()->orWhere(...)->orWhereHas(...)` without a closure means every `orWhere*` branch drops the preceding `whereTypeUser()`, `where('description', ...)`, and for `getItemsNotServices` all `orWhere` branches bypass `whereTypeUser()` (while `whereWarehouse()->whereIsActive()` only bind to the last `OrWhereJsonContains` branch!). Similarly, all six customer/supplier searches run `Person::where('number', 'like', ...)->orWhere('name', 'like', ...)->whereType(...)->whereIsEnabled()`, so matching `number` returns disabled persons and wrong types (suppliers in customer search, customers in supplier search). All eight queries also execute unbounded `->get()`.
- Evidence:
```php
// SearchTrait.php:28-43
return Item::where('description','like', "%{$request->input}%")
    ->whereTypeUser()
    ->orWhere('internal_id','like', "%{$request->input}%")
    ->orWhereHas('category', ...)
    ->orWhereHas('brand', ...)
    ->OrWhereJsonContains('attributes', ['value' => $request->input])
    ->with(['item_lots'])
    ->where('unit_type_id','ZZ')...
```
- Failure scenario: a seller restricted to Establishment 2 searches items by `internal_id` or `brand` and receives items from Establishment 1 (and inactive/service items in the wrong branch); searching customers by RUC/DNI returns supplier records or disabled customers.
- Proposed fix: group all search `where`/`orWhere`/`orWhereHas` predicates inside `->where(function ($q) use ($input) { ... })`, apply `whereTypeUser()`, `whereType()`, `whereIsEnabled()`, `whereIsActive()` outside the closure, and add a `take(50)`/`limit()` cap.
- Verification idea: inspect generated SQL via `toSql()` or feature test with a disabled supplier matching customer `number` search -> must not be returned.

### MSL-006 — `ToPay::getToPay` returns 0 rows when no user is chosen and mislabels expenses as `'sale_note'`
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED (verified against `modules/Finance/Resources/assets/js/views/to_pay/index.vue:421-438`)
- Auto-fix: SAFE (apply `user_id`/`establishment_id` filters conditionally with `if ($user)`/`if ($establishment_id)`, and return `'expense'` instead of `'sale_note'`)
- Location: `modules/Finance/Helpers/ToPay.php:60, 75, 83, 98, 117, 131, 141, 154, 155, 260`; `modules/Finance/Resources/assets/js/views/to_pay/index.vue:421-438`
- Problem: `ToPay::getToPay()` unconditionally chains `->where('user_id', $user)` and `->where('purchases.establishment_id', $establishment_id)` (and `expenses.establishment_id`) even when `$user` or `$establishment_id` is `null` or empty. In `to_pay/index.vue`, `form.user` initializes to `null` (and `filter()` never populates a default user). In SQL, `WHERE user_id = NULL` (`user_id IS NULL`) matches zero rows. Additionally, the `else` branch of `expenses` (line 154) and `ToPay::getToPayNoFilter()` (line 260, used by `ToPayAllExport`) select `'sale_note' AS 'type'` instead of `'expense' AS 'type'`, and lines 190-196 / 290-296 run an N+1 query (`PurchasePayment::where` / `ExpensePayment::where`) per row.
- Evidence:
```php
// ToPay.php:57-61, 154
$purchases = DB::connection('tenant')->table('purchases')
    ->where('user_id', $user) // $user is null by default -> 0 rows
...
"'sale_note' AS 'type', " . "expenses.currency_type_id, " // line 154 & line 260
```
- Failure scenario: opening "Cuentas por pagar" and clicking Search without selecting a user returns an empty list (`records = []`); exporting "Exportar Todo" (`ToPayAllExport`) or querying without a valid period labels expenses as `'sale_note'`.
- Proposed fix: guard `if ($user) { ...->where('user_id', $user); }` and `if ($establishment_id) { ... }` (mirroring `DashboardView::getUnpaidFilterUser`), change `'sale_note' AS 'type'` to `'expense' AS 'type'`, and fetch latest payment dates with a grouped subquery instead of N+1.
- Verification idea: test `ToPay::getToPay` with `user => null` against seeded purchases/expenses and assert rows are returned with `type === 'expense'` for expenses.

### MSL-007 — `*PaymentController::store` duplicates `GlobalPayment`/`PaymentFile` on edit, breaks on closed cash, and skips `total_canceled` sync
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (delete/update prior `GlobalPayment`/`PaymentFile` on edit, validate open cash, add `updated`/`deleted` listeners)
- Location: `modules/Expense/Http/Controllers/ExpensePaymentController.php:52-86`; `modules/Purchase/Http/Controllers/PurchasePaymentController.php:51-80`; `modules/Sale/Http/Controllers/QuotationPaymentController.php:50-79`; `modules/Sale/Http/Controllers/TechnicalServicePaymentController.php:51-80`; `modules/Finance/Traits/FinanceTrait.php:93-125`; `modules/Finance/Traits/FilePaymentTrait.php:16-38`; `modules/Finance/Providers/GlobalPaymentServiceProvider.php:61-92`; `modules/Sale/Providers/SaleNotePaymentProvider.php:35-67`
- Problem:
  1. All four `*PaymentController::store()` methods use `firstOrNew(['id' => $id])` to support editing an existing payment, then unconditionally call `$this->createGlobalPayment($record, ...)` (and `saveFiles(...)`), which always calls `->create(...)` without deleting or updating the prior `GlobalPayment` or `PaymentFile`. Editing a payment creates a second `global_payments` row (double-counting balances and leaving an orphaned `global_payments` row when the payment is later deleted, since `morphOne` only deletes one).
  2. `FinanceTrait::getDestinationRecord()` reads `$this->getCash()['cash_id']` without checking if `getCash()` returned `null` (when the user has no open `Cash`). On PHP 8.3 this emits a warning and attempts `destination_id = null` on a non-nullable column inside the transaction.
  3. `GlobalPaymentServiceProvider::paymentsPurchases()` hooks `PurchasePayment::created` and `deleted` but not `updated`, so editing a `PurchasePayment` leaves `purchases.total_canceled` stale.
  4. `SaleNotePaymentProvider::payments()` has `SaleNotePayment::deleted` commented out (`:42-44`) and no `updated` listener, so deleting or editing a `SaleNotePayment` leaves `sale_notes.total_canceled = true` even when balance remains.
- Failure scenario: editing an existing expense/purchase/quotation/technical-service payment doubles its movement and balance impact in Finance; deleting a `SaleNotePayment` leaves the sale note falsely marked paid (`total_canceled = true`); saving a cash payment without an open cash box crashes with SQL integrity violation.
- Proposed fix: on edit (`$record->exists` / `$id`), update or delete existing `global_payment` (and old `payment_file` + disk file when a new file is uploaded) before creating; fail with 422 if `payment_destination_id === 'cash'` and `!$this->getCash()`; add `PurchasePayment::updated` and restore `SaleNotePayment::deleted` + `updated`.
- Verification idea: feature test editing a `PurchasePayment` and deleting a `SaleNotePayment`, asserting `global_payments` count stays 1 and `total_canceled` updates accurately.

### MSL-008 — `OrderNoteController::update` and `voided` allow `OrderNoteItem` IDOR and corrupt inventory stock
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (touches stock reconciliation and update/void workflow)
- Location: `modules/Order/Http/Controllers/OrderNoteController.php:280-312` (`update`), `315-329` (`destroy_order_note_item`), `359-393` (`voided`, `mergeData`); `modules/Inventory/Providers/InventoryKardexServiceProvider.php:284-330`; `modules/Inventory/Providers/InventoryVoidedServiceProvider.php:81-101`
- Problem:
  1. **IDOR in `update()` and `destroy_order_note_item()`**: `OrderNoteController::update()` runs `OrderNoteItem::firstOrNew(['id' => $item_id])`, fills `$row`, and sets `order_note_id = $this->order_note->id` without verifying that `$item_id` already belongs to `$this->order_note`. Submitting another order note's `item['id']` reassigns and overwrites that item. `destroy_order_note_item($id)` similarly deletes any `OrderNoteItem` without checking order note ownership or state.
  2. **Stock drift on item edits**: `InventoryKardexServiceProvider` hooks `OrderNoteItem::created` (deducts stock) and `deleted` (restores stock), NOT `updated`. Updating an existing item's `quantity` in `OrderNoteController::update()` never adjusts warehouse stock or kardex.
  3. **Stock inflation on re-voiding**: `mergeData()` resets `state_type_id = '01'` even on `update()`. Voiding an order note (`GET order-notes/voided/{id}`) triggers `InventoryVoidedServiceProvider::voided_order_note()` (`OrderNote::updated` when `state_type_id` is `'09'` or `'11'`), restoring stock. Calling `update()` on a voided order note flips `state_type_id` back to `'01'` (without re-deducting stock), and calling `GET order-notes/voided/{id}` again restores stock a second time. `voided($id)` also has no null check on `OrderNote::find($id)`.
- Failure scenario: user edits a voided order note and voids it again -> inventory stock increases infinitely without any real stock entry; or passes another order note's item ID in `update()` to steal/corrupt its line items.
- Proposed fix: scope `OrderNoteItem` lookup to `$this->order_note->items()->findOrFail($item_id)`, forbid editing/voiding already-voided or invoiced order notes (`abort_if(in_array($order_note->state_type_id, ['09','11']), 422)`), adjust stock delta on `OrderNoteItem::updated` (or delete/recreate inside transaction), and check `wasChanged('state_type_id')` in `voided_order_note()`.
- Verification idea: test voiding an OrderNote twice and updating a voided OrderNote; assert stock only restores once and foreign `OrderNoteItem` ID returns 404/422.

### MSL-009 — `FinanceTrait::getSumPayment` and `getSumByPMT` subtract credit notes once per payment row
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes financial report totals; confirm intended credit-note allocation rule with product owner)
- Location: `modules/Finance/Traits/FinanceTrait.php:281-310` (`getSumPayment`, `getTotalCreditNotes`), `384-395` (`getSumByPMT`)
- Problem: Both `getSumPayment()` (used in `BalanceController` for Cash and Bank Accounts) and `getSumByPMT($records, true)` (used in `PaymentMethodTypeController`) iterate over each `DocumentPayment` row and subtract the entire `getTotalCreditNotes($document)` for every single payment row (`return $total_currency_type - $total_credit_notes;`). Also `$row->payment->associated_record_payment` lacks a null guard if `payment` or its parent was deleted.
- Evidence:
```php
// FinanceTrait.php:283-290
return $record->where('payment_type', $model)->sum(function($row){
    $total_credit_notes = ($row->instance_type == 'document') ? $this->getTotalCreditNotes($row->payment->associated_record_payment) : 0;
    $total_currency_type = $this->calculateTotalCurrencyType($row->payment->associated_record_payment, $row->payment->payment);
    return $total_currency_type - $total_credit_notes;
});
```
- Failure scenario: an invoice paid in 3 installments (e.g., 50 cash + 50 card + 50 transfer) with a single credit note of S/ 150 subtracts S/ 150 three times (total S/ -300 instead of S/ 0, and S/ -100 on each destination/payment method).
- Proposed fix: deduct each document's credit notes at most once per document (or track credit note refunds explicitly) instead of subtracting per payment row, and guard `$row->payment` against null.
- Verification idea: unit/feature test on `BalanceController::getRecords` and `PaymentMethodTypeController::getRecords` with one document having two payments and one credit note.

### MSL-010 — `FixedAssetItem::scopeWhereTypeUser` crashes for sellers; `PurchaseOrder::supplier()` points to `CurrencyType`
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (`PurchaseOrder::supplier()` -> `Person::class`; remove/fix broken `scopeWhereWarehouse` call and missing import)
- Location: `modules/Purchase/Models/FixedAssetItem.php:53-57`; `modules/Purchase/Models/PurchaseOrder.php:103-105`; `modules/Purchase/Models/PurchaseOrderItem.php:16, 104-107`; `modules/Purchase/Http/Resources/PurchaseOrderResource.php:28`; `modules/Purchase/Models/FixedAssetPurchase.php:139, 191, 222`
- Problem:
  1. `FixedAssetItem::scopeWhereTypeUser()` executes `($user->type == 'seller') ? $this->scopeWhereWarehouse($query) : null;`, but `FixedAssetItem` has no `scopeWhereWarehouse` method (nor a `warehouse_id` column). Any seller loading `/fixed-asset/items/records` hits a fatal `BadMethodCallException`.
  2. `PurchaseOrder::supplier()` declares `return $this->belongsTo(CurrencyType::class, 'supplier_id');` instead of `Person::class`.
  3. `PurchaseOrderItem` puts `'purchase_id'` in `$fillable` instead of `'purchase_order_id'` and defines `purchase()` (`belongsTo(Purchase::class)`) instead of `purchase_order()`.
  4. `PurchaseOrderResource` looks up `'warehouse' => Warehouse::find($this->establishment_id)` by primary key `id` instead of `Warehouse::where('establishment_id', $this->establishment_id)->first()`.
  5. `FixedAssetPurchase` defines `setRelatedDocumentsAttribute()` instead of `setRelatedAttribute()` (mutator mismatch with `getRelatedAttribute`), references `Group::class` without importing `App\Models\Tenant\Catalogs\Group`, and `getNumberToLetterAttribute()` dereferences `$legend->value` with no null check.
- Failure scenario: seller opens Fixed Asset Items -> HTTP 500; calling `$purchaseOrder->supplier` returns a `CurrencyType` (or null) instead of the supplier `Person`; `PurchaseOrderResource` assigns the wrong warehouse whenever `establishment_id != warehouse.id`.
- Proposed fix: fix `FixedAssetItem::scopeWhereTypeUser` (return `$query` or filter if warehouse-scoped), point `PurchaseOrder::supplier()` to `Person::class`, fix `PurchaseOrderItem` FK/fillable, fix `PurchaseOrderResource` warehouse query, and fix `FixedAssetPurchase` mutator/import/null check.
- Verification idea: call `FixedAssetItem::whereTypeUser()` as a seller user and assert `$purchaseOrder->supplier` resolves to `Person`.

### MSL-011 — Missing controller methods and missing `$format` parameter defaults throw 500 on defined routes
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (add `= 'a4'` defaults for `$format`; remove dead routes or implement missing methods)
- Location: `modules/Expense/Routes/web.php:16` (`ExpenseController@recordsExpensePayments`); `modules/Sale/Routes/web.php:74` (`ContractController@record2`), `:99` (`TechnicalServiceController@download`), `:29, 71-72, 100`; `modules/Order/Routes/web.php:8-9, 31, 58`; `modules/Order/Http/Controllers/{OrderFormController.php:290, 302, OrderNoteController.php:515, 525}`; `modules/Sale/Http/Controllers/{ContractController.php:452, 463, SaleOpportunityController.php:349, TechnicalServiceController.php:202}`; `modules/Sale/Http/Controllers/SaleOpportunityController.php:38`; `modules/Finance/Http/Controllers/IncomeController.php:12`
- Problem:
  1. Three routed actions do not exist on their controllers: `ExpenseController@recordsExpensePayments`, `ContractController@record2`, and `TechnicalServiceController@download`.
  2. Eight `download`/`toPrint` methods declare `($id_or_external_id, $format)` without a default value even though their routes define `{format?}` as optional (`order-forms/print/{external_id}/{format?}`, `order-notes/print/{external_id}/{format?}`, `contracts/download|print/{external_id}/{format?}`, `sale-opportunities/print/{external_id}/{format?}`, `technical-services/print/{id}/{format?}`). Omitting `$format` throws `ArgumentCountError` (500).
  3. `SaleOpportunityController.php:38` and `IncomeController.php:12` import non-existent classes (`SaleOpportunityResource2`, `IncomeMethodType`).
- Failure scenario: visiting `/order-notes/print/<uuid>` or `/order-forms/print/<uuid>` (public unauthenticated routes) or `/technical-services/download/1` returns HTTP 500.
- Proposed fix: add `= 'a4'` to `$format` on all eight methods, remove the three dead routes (or implement them if needed), and remove the non-existent `use` imports.
- Verification idea: GET `/order-forms/print/invalid` and `/contracts/print/invalid` without `/{format}` -> must not throw `ArgumentCountError`.

### MSL-012 — `MovementCollection` running balance uses wrong page size, backwards order, and mutable static state; `MovementController` drops filters
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes movement running-balance math and pagination behavior)
- Location: `modules/Finance/Http/Resources/MovementCollection.php:18-20, 23-65, 83-98`; `modules/Finance/Http/Controllers/MovementController.php:42-78`
- Problem:
  1. `MovementCollection` stores `$balance`, `$residuary`, and `$request` in `protected static` fields that are never reset at the start of `toArray()`, leaking balances across calls in the same PHP process.
  2. `calculateResiduary()` assumes 20 items per page (`->limit(($request->page * 20) - 20)`) instead of `config('tenant.items_per_page')`, and applies it on `latest()`-sorted rows — so page 2 adds *newer* movements from page 1 into `$residuary` and then mutates `$balance` over *older* rows. Furthermore, `calculateResiduary` triggers when `$request->page >= 2`, passing the `Request` object into `MovementController::getRecords($request, ...)` (which reads array keys).
  3. `MovementController::getRecords()` reads `$payment_type = $request['payment_type']` and `$destination_type = $request['destination_type']` and never applies them to `$records` (unlike `GlobalPaymentController::getRecords`), and runs an unused `$last_cash = GlobalPayment::...->first()` query (`:63-67`).
- Failure scenario: filtering Movements by payment type or destination type returns unfiltered results; navigating to page 2 displays inverted/corrupted running balances, and changing `ITEMS_PER_PAGE` desynchronizes `calculateResiduary` completely.
- Proposed fix: apply `$payment_type` / `$destination_type` filters in `MovementController::getRecords()`, compute running balances in chronological order via SQL window function or instance-scoped cumulative sum using `perPage()`, and reset/drop the static properties.
- Verification idea: paginate `finances/movements/records?page=2` with `ITEMS_PER_PAGE=10` and verify balance continuity and filter application.

### MSL-013 — `GlobalPayment::getDataPersonAttribute` missing `break;` drops income person; `PaymentFile::instance_type` crashes on non-document/sale-note files
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (add `break;`, extend `PaymentFile` map, add null guards)
- Location: `modules/Finance/Models/GlobalPayment.php:196-224`; `modules/Finance/Models/PaymentFile.php:41-84`; `modules/Finance/Http/Resources/GlobalPaymentCollection.php:23-75`; `modules/Finance/Http/Resources/MovementCollection.php:101-132`
- Problem:
  1. In `GlobalPayment::getDataPersonAttribute()`, `case 'income':` lacks `break;` (line 217) and falls directly into `case 'cash_transaction':`, overwriting `$person['name']` with `'-'`.
  2. In `GlobalPaymentCollection` and `MovementCollection`, `$row->payment->associated_record_payment->number_full` and `->currency_type_id` assume the parent is a document/invoice/expense — when `instance_type` is `cash_transaction`, `associated_record_payment` is `Cash` (which lacks `number_full` and `currency_type_id`), and `getPaymentMethodTypeDescription()` dereferences `$row->payment->expense_method_type->description` without checking if either `payment_method_type` or `expense_method_type` exists or if `$row->payment` is null (orphaned `global_payments` row).
  3. `PaymentFile::getInstanceTypeAttribute()` maps only `DocumentPayment` and `SaleNotePayment` (`:43-48`), whereas `PurchasePaymentController`, `ExpensePaymentController`, and `QuotationPaymentController` also create `PaymentFile` records via `FilePaymentTrait`. Accessing `instance_type` or `data_person` on those throws `Undefined array key`.
- Failure scenario: opening Global Payments / Movements hides customer names on all Income rows (showing `'-'`) and crashes with 500 on any orphaned `global_payments` row; accessing `PaymentFile::instance_type` for purchase/expense/quotation attachments throws `Undefined array key`.
- Proposed fix: add `break;` after `case 'income':`, map `PurchasePayment`, `ExpensePayment`, and `QuotationPayment` in `PaymentFile`, and guard `$row->payment` / `$record` against null.
- Verification idea: unit test `GlobalPayment::data_person` for `IncomePayment` and `PaymentFile::instance_type` for `PurchasePayment`.

### MSL-014 — Unvalidated `$request->column` in 15 `records` endpoints causes 500 SQL errors and leaks schema/SQL
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (whitelist `$request->column` against `array_keys($this->columns())`)
- Location: `modules/Expense/Http/Controllers/ExpenseController.php:53`; `modules/Finance/Http/Controllers/IncomeController.php:54`; `modules/Purchase/Http/Controllers/{PurchaseOrderController.php:86, PurchaseQuotationController.php:58, FixedAssetItemController.php:45, FixedAssetPurchaseController.php:67, 73}`; `modules/Order/Http/Controllers/{OrderNoteController.php:108, OrderFormController.php:65, DriverController.php:31, DispatcherController.php:31}`; `modules/Sale/Http/Controllers/{ContractController.php:114, ProductionOrderController.php:59, SaleOpportunityController.php:97, TechnicalServiceController.php:65, UserCommissionController.php:49}`
- Problem: All 15 `records()`/`getRecords()` methods pass `$request->column` directly as the first argument to `Model::where($request->column, 'like', "%{$request->value}%")` with no whitelist or fallback. Calling `/records` without `column` (passing `null`) or with an invalid/JSON-path column (`col->secret`) causes SQL errors (which `Handler.php` returns with SQL message/file/line) or queries unexposed model columns.
- Failure scenario: `GET /expenses/records` (no query params) runs `WHERE NULL LIKE '%%'` / invalid column syntax error (500), or an attacker probes table columns / `external_id` prefixes via `column=external_id&value=a`.
- Proposed fix: validate `$column` against `array_keys($this->columns())` (defaulting to the first allowed column or returning 422 on unknown column).
- Verification idea: `GET /expenses/records?column=invalid_col` returns 422 (or falls back safely) instead of 500.

### MSL-015 — Missing `use Exception;` causes `catch (Exception $e)` blocks to miss `\Exception` (500 + SQL leak)
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (import `use Exception;` and stop returning raw `$e->getMessage()`)
- Location: `modules/Document/Http/Controllers/SeriesConfigurationController.php:126` (`destroy`); `modules/Expense/Http/Controllers/ExpenseController.php:181` (`voided`); `modules/Purchase/Http/Controllers/FixedAssetPurchaseController.php:247-253` (`delete`)
- Problem: Neither `SeriesConfigurationController` nor `ExpenseController` imports `use Exception;`, so `catch (Exception $e)` resolves to `Modules\...\Http\Controllers\Exception` and never catches `\Exception` / `QueryException`. A foreign-key `23000` violation in `SeriesConfigurationController::destroy` or an invalid ID/error in `ExpenseController::voided` escapes unhandled (500 with SQL details). `FixedAssetPurchaseController::delete` imports `Exception` but returns `'message' => $e->getMessage()` directly to the client.
- Failure scenario: deleting a referenced series configuration throws an unhandled `QueryException` 500 instead of returning the friendly `'La Configuración de serie esta siendo usada...'` JSON error.
- Proposed fix: add `use Exception;` to `SeriesConfigurationController` and `ExpenseController`, and return a generic message + `report($e)` in `FixedAssetPurchaseController::delete`.
- Verification idea: delete a `SeriesConfiguration` referenced by FK (or mock `QueryException` code `'23000'`) and assert HTTP 200 with `success: false` and friendly message.

### MSL-016 — `Api/PurchaseController` is unbounded without `whereTypeUser()`, skips lot/payment handling; `PurchaseQuotationController::store` has no validation
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (`Api/PurchaseController::store` behavior may have existing API consumers; pagination/validation are SAFE once client expectations are checked)
- Location: `modules/Purchase/Http/Controllers/Api/PurchaseController.php:54-59, 125-152, 271`; `modules/Purchase/Http/Controllers/PurchaseQuotationController.php:105-131`
- Problem:
  1. `Api/PurchaseController::records()` calls `Purchase::latest()->get()` with no pagination, limit, or `whereTypeUser()`, eagerly loading 8 `$with` relations for every purchase in the tenant.
  2. Unlike web `Tenant\PurchaseController::store`, `Api/PurchaseController::store` creates `Purchase` + `PurchaseItem` but skips creating `ItemLot`, `ItemLotsGroup`, `PurchasePayment`, and `GlobalPayment` (leaving `PaymentMethodType`, `Warehouse`, `InventoryKardex`, `ItemWarehouse`, `ItemLotsGroup` as unused imports).
  3. `PurchaseQuotationController::store(Request $request)` uses raw `Request` with zero validation rules; missing `establishment_id` or `items` throws undefined key / null errors.
- Failure scenario: API `GET /api/purchases/records` times out / exhausts memory on large tenants and returns other sellers' purchases; API `POST /api/purchases` with lot-tracked items or payments silently drops lots and payments.
- Proposed fix: paginate `Api/PurchaseController::records()` with `whereTypeUser()`, add `PurchaseQuotationRequest` validation for `PurchaseQuotationController::store`, and align `Api/PurchaseController::store` lot/payment handling with web `PurchaseController` (or explicitly validate/reject unsupported fields).
- Verification idea: call `POST /purchase-quotations` with `{}` -> 422 instead of 500; check `Api/PurchaseController::records()` query bounds.

### MSL-017 — Edit flows overwrite `external_id`, `user_id`, and reset `state_type_id` to `'01'` (`IncomeController::store` creates duplicates)
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (preserve existing `external_id`, `user_id`, and `state_type_id` on update)
- Location: `modules/Purchase/Http/Controllers/PurchaseOrderController.php:191-208`; `modules/Purchase/Http/Controllers/PurchaseQuotationController.php:134-150`; `modules/Purchase/Http/Controllers/FixedAssetPurchaseController.php:164-179`; `modules/Order/Http/Controllers/OrderNoteController.php:376-393`; `modules/Sale/Http/Controllers/ContractController.php:325-342`; `modules/Sale/Http/Controllers/SaleOpportunityController.php:212-229`; `modules/Finance/Http/Controllers/IncomeController.php:89, 113-129`
- Problem: On update (`id` supplied), `mergeData()` / `convert()` in `PurchaseOrderController`, `PurchaseQuotationController`, `FixedAssetPurchaseController`, `OrderNoteController`, and `ContractController` unconditionally overwrite `external_id = Str::uuid()->toString()`, `user_id = auth()->id()`, and `state_type_id = '01'` (`SaleOpportunityController` preserves `external_id` when `$inputs->id` is set, but still resets `user_id` and `state_type_id`). `IncomeController::store()` always calls `Income::create($data)` even when `id` is provided, creating a duplicate record instead of updating, and `IncomeController::merge_inputs` reads `$inputs['id']` without `isset` (undefined array key on create).
- Failure scenario: editing a voided (`'11'`) contract, order note, or purchase order silently un-voids it (`'01'`) and invalidates any previously shared `/print/{external_id}` URL; editing an `Income` with `id` inserts a duplicate row.
- Proposed fix: load the existing record when `id` is present (`whereTypeUser()`), preserve `external_id`, `user_id`, and `state_type_id`, and disallow editing voided/rejected records.
- Verification idea: edit a record with `state_type_id = '11'` or custom `external_id` and verify `external_id` is unchanged (or edit of voided record is rejected).

### MSL-018 — Null dereferences across controllers, helpers, models, and collections cause 500s on missing/empty records
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (use `findOrFail()` / null checks / `optional()`)
- Location:
  - `modules/Document/Http/Controllers/DocumentController.php:235-236` (`$document->detraction` null in `savePayConstancy`), `:344, 401, 478, 491` (`$warehouse->id` when establishment has no warehouse), `:516` (`Document::find($document_id)` in `consultCdr`)
  - `modules/Document/Http/Resources/DocumentRegularizeShippingCollection.php:43-45, 66` (`$message_regularize_shipping` undefined when `!$row->regularize_shipping`; `$row->response_regularize_shipping` null)
  - `modules/Pos/Http/Controllers/HistoryController.php:21-26, 52-55` (`json_decode($request->form)` null -> `$form->item_id`); `HistoryPurchasesCollection.php:19, 28` (`json_decode($row->supplier)` null)
  - `modules/Expense/Http/Controllers/ExpensePaymentController.php:36-39`; `modules/Purchase/Http/Controllers/PurchasePaymentController.php:35-38`; `modules/Sale/Http/Controllers/QuotationPaymentController.php:36-39`; `modules/Sale/Http/Controllers/TechnicalServicePaymentController.php:36-39` (`::find()` without null check)
  - `modules/Purchase/Http/Controllers/{PurchaseOrderController.php:402, 458, FixedAssetPurchaseController.php:153}`; `modules/Order/Http/Controllers/{OrderNoteController.php:335, 364, 719-720, Api/OrderNoteController.php:23-24, OrderFormController.php:272}`; `modules/Sale/Http/Controllers/{ContractController.php:316, 585, 609, 620, SaleOpportunityController.php:438}` (`::find()` without null check on `voided`, `anular`, `duplicate`, `changed`, `updateStateType`, `email`)
  - `modules/Order/Http/Controllers/OrderNoteController.php:134, 209, 427, 469`; `modules/Sale/Http/Controllers/{ContractController.php:173, SaleOpportunityController.php:140}` (`$establishment->id` / `$warehouse->id` without null check)
  - `modules/Order/Helpers/OrderFormHelper.php:133-135` (`Item::find($row['item_id'])` without null check)
  - `modules/Sale/Models/Contract.php:248`, `modules/Order/Models/OrderNote.php:235`, `modules/Purchase/Models/FixedAssetPurchase.php:222` (`getNumberToLetterAttribute` `$legend->value` without null check)
  - `modules/Finance/Http/Controllers/UnpaidController.php:69-71` (`$config = Configuration::first()` -> `$config->finances`)
- Problem: All of these dereference properties on nullable `find()` / `first()` / `json_decode()` results or undefined variables (`$message_regularize_shipping`), turning bad IDs, missing establishments/warehouses, or empty payloads into PHP `Error`/500 exceptions instead of 404/422 or safe defaults.
- Failure scenario: requesting `GET /documents/consult_cdr/999999`, `POST /documents/pay-constancy/save` on a document without detractions, or `GET /pos/history-sales/records` without `form` triggers a fatal `Attempt to read/assign property on null`.
- Proposed fix: replace `find()` with `findOrFail()`, initialize `$message_regularize_shipping = null`, guard `$warehouse`/`$establishment`/`$detraction`/`$legend`/`$config`, and validate `form` JSON before dereferencing.
- Verification idea: request `consult_cdr/999999` and `history-sales/records` with empty query -> expect 404/422, not 500.

### MSL-019 — PHP 8.3 compatibility: undeclared dynamic properties on 8 Exports and `OrderFormController`, plus unchecked array keys
- Category: php83
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (declare properties on classes and use `?? null` / `Arr::get`)
- Location:
  - Undeclared properties (`Creation of dynamic property ... is deprecated` in PHP 8.2/8.3): `modules/Pos/Exports/ReportCashExport.php:16, 22, 28`; `modules/Finance/Exports/{BalanceExport.php:16,22,28, GlobalPaymentExport.php:16,22,28, MovementExport.php:16,22,28, PaymentMethodTypeExport.php:16,22,28, ToPayExport.php:16,22, ToPaymentMethodDayExport.php:16,22, UnpaidPaymentMethodDayExport.php:16,22}`; `modules/Order/Http/Controllers/OrderFormController.php:328, 330` (`$this->document`, `$this->configuration`)
  - Unchecked array offsets (`Undefined array key` warnings in PHP 8.3): `modules/Order/Helpers/OrderFormHelper.php:29, 40-60` (`$inputs['id']`, `$inputs['observations']`, `$inputs['port_code']`, etc.); `modules/Finance/Http/Controllers/IncomeController.php:120` (`$inputs['id']`); `modules/Finance/Traits/FinanceTrait.php:161-165`; `modules/Finance/Helpers/ToPay.php:16-23`; `modules/Finance/Http/Controllers/GlobalPaymentController.php:49-50`; `modules/Finance/Http/Controllers/MovementController.php:45-47`; `modules/Sale/Services/SaleOpportunityFileService.php:38` (`$array_filename[1]`)
- Problem: On PHP 8.3 (the container runtime per `BASELINE.md`), setting undeclared properties triggers dynamic property deprecation warnings (fatal in PHP 9.0, and breaks strict JSON/binary downloads when `display_errors` is on), and reading missing keys like `$inputs['id']` or `$request['period']` emits `Undefined array key` warnings.
- Failure scenario: generating any Cash/Finance Excel export or OrderForm PDF emits PHP 8.3 deprecation notices; submitting `OrderForm` or `Income` without `id` or hitting Finance `records` without `period` emits warnings/errors.
- Proposed fix: declare `protected $records, $company, $establishment, $cash, $methods_payment;` on the Export classes and `protected $document, $configuration;` on `OrderFormController`; replace direct indexing with `$inputs['key'] ?? null`.
- Verification idea: instantiate each Export / call `OrderFormHelper::set` without `'id'` under `error_reporting(E_ALL)` on PHP 8.3 -> zero warnings/deprecations.

### MSL-020 — `OrderNoteController::generateDocuments` bypasses FormRequest validation and lacks double-billing guard
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (adding validation/locking may expose invalid payloads sent by current Vue helper)
- Location: `modules/Order/Http/Controllers/OrderNoteController.php:141-168`; `app/Http/Controllers/Tenant/DocumentController.php:70, 403`; `app/Http/Controllers/Tenant/SaleNoteController.php:218`
- Problem: `generateDocuments()` iterates `$request->documents` without validating `$request->documents` is an array and invokes `app(SaleNoteController::class)->store(new SaleNoteRequest($row))` and `app(DocumentController::class)->store(new DocumentRequest(DocumentInput::set($data_val)))`. Manually instantiating `FormRequest` objects bypasses Laravel's `validateResolved()` lifecycle (so `DocumentRequest` and `SaleNoteRequest` rules never run, and `DocumentController`'s `input.request:document,web` middleware does not execute). Additionally, it never locks or checks whether the `OrderNote` already has a `Document` or `SaleNote`, enabling duplicate billing on double-submit.
- Failure scenario: two concurrent clicks on "Generar comprobante" or a malformed payload create duplicate invoices/sale notes for one `OrderNote`, or insert invalid document rows bypassing `DocumentRequest`.
- Proposed fix: validate `$request->documents`, lock the `OrderNote` (`lockForUpdate()`) inside the transaction and reject if `documents()->exists() || sale_notes()->exists()`, and run `Validator::make(...)` against the request rules before invoking `store()`.
- Verification idea: send two concurrent `POST /order-notes/documents` for the same order note -> second must fail with 422.

### MSL-021 — Malformed `required_unless:expense_method_type_id, "1"` rule and missing validation on email/period endpoints
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (fix `required_unless` syntax, add email/date validation)
- Location: `modules/Expense/Http/Requests/ExpensePaymentRequest.php:27`; `modules/Pos/Http/Controllers/CashController.php:24-26`; `modules/Order/Http/Controllers/{OrderNoteController.php:716-729, Api/OrderNoteController.php:20-33, OrderFormController.php:269-280}`; `modules/Purchase/Http/Controllers/{Api/PurchaseController.php:302-314, PurchaseOrderController.php:400-410}`; `modules/Sale/Http/Controllers/{ContractController.php:581-592, SaleOpportunityController.php:434-446, ContractRecurrentController.php:34}`; `modules/Finance/Traits/FinanceTrait.php:159-193`
- Problem:
  1. `ExpensePaymentRequest` defines `'required_unless:expense_method_type_id, "1"'` with a leading space and literal double quotes (compare MOP-024), so `payment_destination_id` is still required even when `expense_method_type_id == 1` (unless `null` is rejected and UI works around it by sending a dummy value).
  2. All 8 `email()` endpoints fail to validate RFC email format (`CashController` checks only `'email' => 'required'`; the other 7 have no validation at all, crashing inside `Mail::to(null)`).
  3. `FinanceTrait::getDatesOfPeriod()`, `ToPay::getToPay()`, and `ContractRecurrentController::pending()` pass unvalidated dates directly to `Carbon::parse(...)`, throwing 500 on malformed dates; unknown `period` leaves `$d_start/$d_end` null (turning filtered Finance queries into unbounded full-table scans).
- Failure scenario: sending `expense_method_type_id=1` without `payment_destination_id` fails 422; sending malformed email or `month_start=bad` throws 500.
- Proposed fix: fix to `'required_unless:expense_method_type_id,1'`, add `['required', 'email']` + ID validation on `email()` endpoints, and validate `period` / `date*` / `month*` in Finance and `ContractRecurrentController::pending`.
- Verification idea: POST `expense-payments` with `expense_method_type_id=1` and no `payment_destination_id` -> 200; invalid email -> 422.

### MSL-022 — State-mutating `voided` / `anular` / `changed` / `updateStateType` exposed as `GET` routes without state checks
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: RISKY (switching `GET` routes to `POST`/`PATCH` requires updating Vue callers)
- Location: `modules/Expense/Routes/web.php:22` (`GET expenses/{record}/voided`); `modules/Purchase/Routes/web.php:41, 81` (`GET purchase-orders/anular/{id}`, `GET fixed-asset/purchases/voided/{id}`); `modules/Order/Routes/web.php:26` (`GET order-notes/voided/{id}`); `modules/Sale/Routes/web.php:60, 66, 75` (`GET contracts/state-type/{state_type_id}/{id}`, `GET contracts/voided/{id}`, `GET contracts/changed/{contract}`); `modules/Finance/Routes/web.php:64` (`GET finances/income/voided/{record}`)
- Problem: All eight state-mutating endpoints use HTTP `GET` (CSRF / browser link-prefetch risk). `ContractController::updateStateType($state_type_id, $id)` accepts any arbitrary `$state_type_id` string with no `exists:contract_state_types,id` check. `ExpenseController::voided` and `IncomeController::voided` do not check prior state or clean up associated payments directly (though `GlobalPayment::whereFilterPaymentType` excludes non-accepted states).
- Failure scenario: an img/link/prefetch or GET request voids expenses, purchase orders, fixed-asset purchases, incomes, order notes, or sets arbitrary `state_type_id` on contracts.
- Proposed fix: migrate these routes to `POST`/`PATCH`, validate `$state_type_id` against `contract_state_types`, and enforce valid state transitions.
- Verification idea: `GET` to any voided/state-change URI returns 405 once migrated to `POST`.

### MSL-023 — `SeriesConfigurationController` checks wrong columns for Dispatches (`09`) and lacks unique series enforcement
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (scope Dispatch check by `series`, restore unique validation)
- Location: `modules/Document/Http/Controllers/SeriesConfigurationController.php:36-40, 84-106`; `modules/Document/Http/Requests/SeriesConfigurationsRequest.php:19-26`
- Problem:
  1. In `getRecords()` (`:37`), for `document_type_id == '09'` (Guía de Remisión), it counts `Dispatch::where('number', $row->number)->count()` without filtering by `series`, and in `store()` (`:85`) checks `$number = Dispatch::max('number')` globally across *all* dispatch series rather than `where('series', $request->series)` — then line 94 still checks `getQuantityDocuments('09', $request->series)` against `documents` instead of `dispatches`.
  2. Unique validation rules in `SeriesConfigurationsRequest` (`:21, :25`) are commented out, allowing multiple `series_configurations` rows for the same `series_id` / `(document_type_id, series)` when POSTed without `id`.
- Failure scenario: a tenant with series `T001` up to number 100 cannot initialize a new dispatch series `T002` at number 1 because `Dispatch::max('number')` is 100; or duplicate configuration rows are saved for one series.
- Proposed fix: filter `Dispatch` queries by `where('series', ...)` in both `getRecords()` and `store()` (and skip `documents` count for `'09'`), and enforce `Rule::unique('tenant.series_configurations', 'series_id')->ignore($id)`.
- Verification idea: create two dispatch series `T001` (with dispatches up to 50) and `T002` (empty); configuring `T002` at number 1 must succeed.

### MSL-024 — Pervasive unbounded `->get()`/`all()` queries and N+1 loops across tables, exports, collections, and Finance
- Category: n+1/sql
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (add `with()`, `without()`, pagination/limits)
- Location:
  - Unbounded `->get()`/`all()` in form `tables()`/`item_tables()`/`filter()`/`prepayments`/`regularizeLots` and PDF/Excel exports: `OrderFormController::tables` (`modules/Order/Http/Controllers/OrderFormController.php:125-234`: loads all items, customers, establishments, drivers, dispatchers, AND builds `locations` with N+1 `Department->provinces->districts` then re-queries `Department`, `Province`, `District` separately); `DocumentController::{detractionTables:176-203, prepayments:269, regularizeLots:416}`; `PurchaseOrderController::table`; `PurchaseQuotationController::table`; `FixedAssetPurchaseController::table`; `OrderNoteController::table('items')`; `ContractController::table('items')`; `SaleOpportunityController::table('items')`; `ExpenseController::table('suppliers')`; `IncomeController::table('suppliers')`; `UnpaidController::{filter, reportPaymentMethodDays, pdf}`; `GlobalPaymentController::{pdf, excel}`; `MovementController::{pdf, excel}`
  - Collection/Resource N+1 loops: `OrderNoteCollection` (`documents`, `sale_notes`), `OrderFormCollection` (`dispatch`), `PurchaseOrderCollection` (`purchases`, `state_type`, `sale_opportunity`), `PurchaseQuotationCollection` (`purchase_orders`, `state_type`), `ContractCollection` (`quotation`), `ProductionOrderCollection` (`quotation`), `SaleOpportunityCollection` (`quotation`, `purchase_order`), `TechnicalServiceCollection` (`payments`), `ExpenseCollection` (`expense_type`, `expense_reason`), `IncomeCollection` (`income_type`, `income_reason`), `GlobalPaymentCollection` & `MovementCollection` (`GlobalPayment` lacks `$with`, lazy-loading `payment`, `associated_record_payment`, `destination->bank`, `user`, `payment_method_type`, `items`), `UnpaidTrait` & `UnpaidCollection` (3 queries per row: `Invoice`, `Dispatch`, `DocumentPayment`/`SaleNotePayment`)
  - `FinanceTrait::getBalanceByCash` & `getBalanceByBankAcounts` (`modules/Finance/Traits/FinanceTrait.php:196-310`): hydrates all `GlobalPayment` rows and lazy-loads `bank`, `associated_record_payment`, and `affected_documents` per row
  - `ContractRecurrentController::pending` (`:38-39`) + `ContractBillingService::dueContracts` (`:108-117`) + `itemsAsArrays` (`:335`): queries `ContractBilling` per due contract (twice when `respectRetryCap` is true and in `pending`) and `Item::find` per contract item
- Failure scenario: tenants with thousands of customers/items or tens of thousands of payments trigger O(N) queries and multi-second/OOM responses on form opens, list tables, and Finance PDF/Excel exports.
- Proposed fix: eager load required relations with `with(...)` at the query layer, `with('provinces.districts')` on `Department` (and drop redundant `Department`/`Province`/`District` queries in `OrderFormController::tables`), replace unbounded dropdown `table('items'/'customers'/'suppliers')` queries with paginated search endpoints, and aggregate Balance/ToPay/Unpaid metrics in SQL.
- Verification idea: assert query count (`DB::getQueryLog()`) stays constant for 50 vs 5 rows on `GlobalPaymentController::records`, `OrderNoteController::records`, `OrderFormController::tables`, and `UnpaidController::pdf`.

### MSL-025 — Temp files (`tempnam`) leaked on PDF prints and image renders; `SaleOpportunityController::store` leaves deleted files on disk
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (`->deleteFileAfterSend(true)` / `unlink` in `finally`)
- Location: `modules/Pos/Http/Controllers/CashController.php:80-83`; `modules/Order/Http/Controllers/{OrderNoteController.php:531-535, OrderFormController.php:309-313}`; `modules/Purchase/Http/Controllers/{PurchaseOrderController.php:319-323, PurchaseQuotationController.php:228-232}`; `modules/Sale/Http/Controllers/{ContractController.php:469-473, SaleOpportunityController.php:355-359, TechnicalServiceController.php:209-213}`; `modules/Sale/Services/SaleOpportunityFileService.php:13-20`; `modules/Sale/Http/Controllers/SaleOpportunityController.php:190`
- Problem: Every `toPrint()` method writes a PDF to `tempnam(sys_get_temp_dir(), ...)` and returns `response()->file($temp)` without `->deleteFileAfterSend(true)` (leaving a temp file in `/tmp` on every print). `SaleOpportunityFileService::getFile()` likewise writes a `tempnam` file for `mime_content_type()` and never unlinks it. Additionally, `SaleOpportunityController::store()` deletes DB file records (`$this->sale_opportunity->files()->delete()`) on edit without deleting removed files from `Storage::disk('tenant')` (`sale_opportunity_files/`).
- Proposed fix: chain `->deleteFileAfterSend(true)` on `response()->file($temp)` and `unlink($temp)` in `finally` inside `SaleOpportunityFileService::getFile()` (or use `finfo_buffer` in memory).
- Verification idea: call `toPrint` and verify no file remains in `sys_get_temp_dir()`.

### MSL-026 — `createPdf()` footer passes null `$this->...` instead of `$document`, and `OrderNoteController::update()` breaks `email()` PDFs
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `modules/Purchase/Http/Controllers/PurchaseOrderController.php:386`; `modules/Purchase/Http/Controllers/PurchaseQuotationController.php:293`; `modules/Order/Http/Controllers/OrderNoteController.php:280-303, 702, 723`
- Problem:
  1. `PurchaseOrderController::createPdf`, `PurchaseQuotationController::createPdf`, and `OrderNoteController::createPdf` assign `$document = ($arg != null) ? $arg : $this->...`, then pass `$this->purchase_order` / `$this->purchase_quotation` / `$this->order_note` to `$template->pdfFooter(...)`. When called from `download()` or `toPrint()` with `config('tenant.pdf_template_footer') = true`, `$this->...` is `null`.
  2. `OrderNoteController::update()` updates `filename` via `$this->setFilename()` (using today's `date('Ymd')`) without calling `createPdf()`, and `OrderNoteController::email()` has `$this->reloadPDF(...)` commented out (`:723`). Emailing an order note edited on a different day fails when `OrderNoteEmail::build()` tries to read the missing PDF filename.
- Proposed fix: pass `$document` to `pdfFooter(...)`, and call `$this->createPdf($this->order_note, "a4", $this->order_note->filename)` at the end of `OrderNoteController::update()` (or reload in `email()`).
- Verification idea: edit an `OrderNote` with an older date filename and call `POST /order-notes/email`.

### MSL-027 — Dead code and duplicated classes/helpers across the 7 modules
- Category: dead-code
- Severity: low
- Confidence: CONFIRMED (grepped across `app/`, `modules/`, `resources/`, `routes/`, `config/`)
- Auto-fix: SAFE (remove dead classes/files; refactor duplicated clones optionally)
- Location:
  - **Dead files/methods (0 callers)**: `modules/Order/Http/Resources/OrderNoteResource2.php` (`OrderNoteController::record2` uses `OrderNoteResource` and duplicates `record`); `modules/Finance/Models/BankAccountIncome.php` (unused copy of `IncomePayment`); `modules/Finance/Http/Controllers/FinanceController.php` (not routed anywhere); `modules/Finance/Http/Controllers/MovementController.php:63-67` (unused `$last_cash` query)
  - **Duplicated logic**: `modules/Finance/Traits/UnpaidTrait.php` (`transformRecords`) and `modules/Finance/Http/Resources/UnpaidCollection.php` (`toArray`) are identical copies; `modules/Finance/Helpers/ToPay.php` duplicates the entire query/transform block between `getToPay` and `getToPayNoFilter`; 6 near-identical CRUD controllers (`Expense{MethodType,Reason,Type}Controller`, `Income{Reason,Type}Controller`, `PaymentMethodTypeController`); `DriverController` and `DispatcherController` are identical clones; 8 `Finance\Exports` + `ReportCashExport` repeat identical view-wrapper boilerplate
- Proposed fix: delete `OrderNoteResource2.php`, `BankAccountIncome.php`, `FinanceController.php`, unused `$last_cash` query, and consolidate `UnpaidTrait`/`UnpaidCollection` and `ToPay`.
- Verification idea: `grep` confirms zero references after removal and `php -l` passes.

### MSL-028 — Misc low-severity bugs (`diffInDays(null)`, unsigned `diffInDays`, union `orderBy`, timestamp check, missing `items()->delete()`)
- Category: functional-bug
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location:
  - `modules/Finance/Http/Controllers/ToPayController.php:93` & `modules/Finance/Http/Controllers/UnpaidController.php:88` — `Carbon::parse($row['date_of_issue'])->diffInDays($row['date_of_due'])` passes `null` when `date_of_due` is null, causing Carbon to diff against `now()` instead of returning `null`/`0`.
  - `modules/Document/Http/Resources/DocumentNotSentCollection.php:62-65` — `$difference_days = 7 - $date_document->diffInDays($now);` uses unsigned `diffInDays()`, so future-dated documents also subtract positive days.
  - `modules/Pos/Http/Controllers/HistoryController.php:30, 40, 58` — calls `->orderBy('created_at', 'desc')` on `SaleNoteItem`/`DocumentItem`/`PurchaseItem` joined queries (`*_items` tables have no `created_at` column in migrations) before an outer `orderBy('date_of_issue','desc')`.
  - `modules/Purchase/Http/Resources/FixedAssetItemCollection.php:30` — checks `($row->created_at)` instead of `($row->updated_at)` before calling `$row->updated_at->format(...)`.
  - `modules/Purchase/Http/Controllers/FixedAssetPurchaseController.php:237-238` — `delete()` deletes `FixedAssetPurchase` without deleting `items()` first (relies on DB cascade or fails with raw exception).
  - `modules/Order/Http/Controllers/OrderFormController.php:125` (`modules/Order/Routes/web.php:50`) — `order-forms/tables` is registered as `POST` for a read-only lookup.
  - `modules/Pos/Http/Controllers/CashController.php:42, 91` — `Company::first()` (and `Configuration::first()->formats` in `OrderNoteController:552`, `OrderFormController:331`, `ContractController:492`, `TechnicalServiceController:233`, `Api/PurchaseController:173`) lack null checks if unseeded.
- Proposed fix: guard `$row['date_of_due']` before `diffInDays()`, use `diffInDays($now, false)`, drop redundant inner `orderBy('created_at')`, check `$row->updated_at`, delete `items()` before `FixedAssetPurchase::delete()`, and guard `Company`/`Configuration` lookups.

## Overflow themes (not itemised)
- **PDF generation duplication**: `createPdf()` in `Api/PurchaseController`, `PurchaseOrderController`, `PurchaseQuotationController`, `OrderNoteController`, `OrderFormController`, `ContractController`, `SaleOpportunityController`, and `TechnicalServiceController` copy-paste the same Mpdf font/CSS/footer boilerplate 8 times (alongside `ini_set("pcre.backtrack_limit", "5000000")` and `CashController`'s `set_time_limit(0)`).
- **Provider stubs**: All 7 `RouteServiceProvider` and `*ServiceProvider` classes are stock nwidart stubs (`DocumentServiceProvider::registerFactories()` lacks `&& $this->app->runningInConsole()`, though no `Database/factories` directory exists).
- **Blade/Vue consumers**: Only checked selectively to confirm controller/helper assumptions (`to_pay/index.vue`, `contracts/form.vue`, `unpaid/index.vue`); full template/frontend audit is covered in `frontend-modules.md`.
