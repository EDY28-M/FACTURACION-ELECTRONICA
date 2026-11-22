# Findings — Modules: Inventory, Item, Report

Audit mode: read-only. Branch `chore/system-audit`. No secret values are quoted; secrets are referred to by config/column name.

## Coverage

| Module | Fully read | Pattern-scanned only | Not reviewed (why) | Routes | Controller actions |
|---|---|---|---|---|---|
| Inventory | Routes, Providers (all 5), Traits (`InventoryTrait`), Models (all 10), Helpers (all 3), Requests (all 6), Resources (all 13), Exports (all 5), all 12 Controllers (incl. `Api\InventoryController`), `Config`, `Seeder`, report PDF/Excel Blade views (`inventory`, `kardex`, `kardex_lots`, `kardex_series`, `valued_kardex`) | CRUD index/form Blade views | Vue JS assets (other auditor) | 68 web + 1 api (4 commented web routes excluded) | 79 public methods across 12 controllers |
| Item | Routes, Providers (all 2), Models (all 5), Imports (all 3), Exports (`ItemLotExport`), Requests (all 6), Resources (all 4), all 7 Controllers, `Config`, `Seeder`, `item-lots/report_excel.blade.php` | CRUD index Blade views | Vue JS assets | 33 web + 1 api closure | 35 public methods across 7 controllers |
| Report | Routes, Providers (all 2), Traits (all 3), Helpers (`UserCommissionHelper`), Services (`ReportService`), Exports (all 15), Resources (all 15), all 23 Controllers, `Config`, `Seeder`; export views read/checked: `documents`, `general_items`, `commercial_analysis`, `commissions`, `user_commissions`, `customers`, `items`, `income_summary`, `order_notes_consolidated`, `order_notes_general` | Remaining report PDF/Excel Blade views (`purchases`, `sale_notes`, `fixed-asset-purchases`, `sales_consolidated`, `document_hotels`, `massive-downloads`) scanned for `filters`/`params`/null derefs | Vue JS assets, index Blade shells | 104 web + 1 api closure (1 commented web route excluded) | 114 public methods across 23 controllers |

Out of scope / not done: Vue/JS components (only searched for endpoint callers to verify dead routes/views), database runtime checks, `app/` controllers beyond cross-checks to confirm module bugs (`Item`, `ItemSet`, `DocumentItem`, `SaleNoteItem`, `OrderNote`, `OrderNoteItem`, `CreditNoteStock`, `DocumentController`, `config/permissions.php`, `MySqlGrammar`/`CompilesJsonPaths`).

## Findings (ordered by severity)

### MIN-001 — `InventoryVoidedServiceProvider` re-credits stock, kardex, lots, and prepayments on every update to a voided/rejected document or order note
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (guard with `wasChanged('state_type_id')` and previous-state check; verify warehouse target)
- Location: `modules/Inventory/Providers/InventoryVoidedServiceProvider.php:27-56` (`voided`), `60-79` (`voidedWasDeductedPrepayment`), `81-102` (`voided_order_note`); `modules/Inventory/Traits/InventoryTrait.php:348-367` (`voidedDocumentItemSet`)
- Problem:
  1. `Document::updated` triggers whenever `$document['document_type_id']` is `'01'` or `'03'` and `$document['state_type_id']` is `'09'` or `'11'`, without checking `$document->wasChanged('state_type_id')` or that `$document->getOriginal('state_type_id')` was not already `'09'`/`'11'`. Any later `save()` on an already-voided/rejected document (e.g. `DocumentController::sendServer` setting `send_server = true`, `checkServer`, CDR/XML flags, or `Api\DocumentController`) re-runs the observer: adding stock again, inserting duplicate positive `inventory_kardex` rows, incrementing `ItemLotsGroup.quantity`, resetting `ItemLot.has_sale = false`, and re-crediting `pending_amount_prepayment`.
  2. `OrderNote::updated` (`voided_order_note`, lines 83-100) has the exact same missing `wasChanged('state_type_id')` check.
  3. Warehouse mismatch on void: `voided()` resolves `$warehouse = $this->findWarehouse($document['establishment_id'])` instead of `$detail->warehouse_id` (whereas `InventoryKardexServiceProvider::sale()` deducts from `$document_item->warehouse_id`), and `voidedDocumentItemSet()` calls `$this->findWarehouse()` with no arguments (using the current user's establishment instead of the document's establishment).
  4. `voidedWasDeductedPrepayment()` (lines 66-68) calls `explode('-', $row->number)` without checking for null or two segments (`Undefined array key 1`, and PHP 8.1+ `explode()` null deprecation).
- Evidence:
```php
Document::updated(function ($document) {
    if($document['document_type_id'] == '01' || $document['document_type_id'] == '03'){
        if(in_array($document['state_type_id'], [ '09', '11' ], true)){
            $warehouse = $this->findWarehouse($document['establishment_id']);
            foreach ($document['items'] as $detail) {
                ...
                $this->createInventoryKardex($document, $detail['item_id'], $detail['quantity'] * $presentationQuantity, $warehouse->id);
                if(!$detail->document->sale_note_id && !$detail->document->order_note_id) $this->updateStock(...);
```
- Failure scenario: a rejected (`09`) or voided (`11`) invoice is later synced (`send_server = true` or state re-saved) — every save duplicates its stock restoration, kardex entry, lot quantities, and prepayment balance.
- Proposed fix: gate both `Document::updated` and `OrderNote::updated` with `if (!$document->wasChanged('state_type_id') || in_array($document->getOriginal('state_type_id'), ['09', '11'], true)) { return; }`; resolve the item's `warehouse_id` when present (passing `$document->establishment_id` as fallback, including inside `voidedDocumentItemSet`); validate `str_contains((string) $row->number, '-')` before `explode`.
- Verification idea: unit/feature test updating `send_server` on a `state_type_id = '11'` document and asserting `item_warehouse.stock`, `inventory_kardex` count, and `item_lots_group.quantity` remain unchanged.

### MIN-002 — Double kardex/lot deduction on converted documents, `regularize_stock` stock corruption, and broken `ReportKardexCollection` balance/transfer logic
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes stored `inventory_kardex` rows for converted documents and reported kardex figures; `ReportKardexCollection` transfer/pagination fixes are SAFE once semantics are agreed)
- Location: `modules/Inventory/Providers/InventoryKardexServiceProvider.php:72-73,87-89,95-125,138-165,199-227,237-274,365-404`; `modules/Inventory/Http/Controllers/InventoryController.php:379-400` (`regularize_stock`); `modules/Inventory/Http/Resources/ReportKardexCollection.php:31-333`; `modules/Inventory/Http/Controllers/ReportKardexController.php:104-144,166-253`; `modules/Inventory/Resources/views/reports/kardex/report_{pdf,excel}.blade.php`
- Problem:
  1. **Duplicate kardex rows + `regularize_stock` corruption**: When a `SaleNote` or `OrderNote` is converted to a `Document`, `sale_note()`/`order_note()` already recorded a negative `inventory_kardex` row and deducted stock. `InventoryKardexServiceProvider::sale()` skips `updateStock()` when `sale_note_id` or `order_note_id` is set, **yet still creates a second negative `inventory_kardex` row** (lines 72, 88), and `InventoryVoidedServiceProvider::voided()` (line 39) creates another positive row on void. Calling `GET /inventory/regularize_stock` (`InventoryController::regularize_stock`) executes `InventoryKardex::where(...)->sum('quantity')` and overwrites `item_warehouse.stock`, double-deducting every converted SaleNote/OrderNote.
  2. **Double lot/series deduction & presentation mismatch**: `sale()` lines 95-125 still decrement `ItemLotsGroup.quantity` and update `ItemLot.has_sale` even when `sale_note_id`/`order_note_id` is present. Also `sale()` (line 101), `updateDataLots()` (`InventoryTrait.php:293`), and `devolution()` (lines 374-384) subtract `$item->quantity` without multiplying by `$presentationQuantity` (`quantity_unit`), causing `item_lots_group.quantity` (and devolution stock) to diverge from `item_warehouse.stock` when selling/returning non-base presentations.
  3. **Set warehouse & delete bugs**: `sale()` (line 87), `sale_note_item_delete()` (line 218), and `sale_document_type_03_delete()` (lines 247, 264) resolve `$this->findWarehouse()` (current user's warehouse) instead of the document/item warehouse; for `is_set` items, `sale_note_item_delete()` stores only one `inventory_kardex_id` on `sale_note_item` (the last component), so deleting the `SaleNoteItem` leaves all prior component kardex rows orphaned; `sale_document_type_03_delete()` checks `!$document->sale_note_id` but omits `order_note_id` (line 251, 267).
  4. **`ReportKardexCollection` / PDF / Excel bugs**:
     - `ReportKardexCollection.php:133` and `kardex/report_{pdf,excel}.blade.php:278,334,252,299` compare `$row->inventory_kardexable->warehouse_destination_id === auth()->user()->establishment_id` (a `warehouse_id` against an `establishment_id`). Whenever a warehouse's ID differs from its establishment's ID, incoming transfers appear in Output instead of Input.
     - `ReportKardexCollection` keeps `protected static $balance = 0; protected static $restante = 0;` across calls and never resets them on page 1 without dates. `calcularRestante()` forces `$quantityOld->quantity = 0` whenever an unordered `first()` row has `date_of_issue == $date_start` (erasing prior history), sums `<= $date_start` when `$ultimate->t == 1` (double-counting `$date_start`), does not exclude converted `sale_note_id`/`order_note_id` rows from SQL `SUM(quantity)` while excluding them in the loop, omits `orderBy('item_id')->orderBy('id')`, and hardcodes page size `20` instead of `config('tenant.items_per_page')`.
     - `ReportKardexController::pdf` (line 173) and `excel` (line 218) call `Item::findOrFail($request->item_id)`, returning 404 whenever `item_id` is omitted for "all items" even though both views explicitly handle `@if(!$item_id)`; both also filter `$item_id` in PHP *after* loading the entire warehouse's `InventoryKardex` table, start `$balance = 0` with no opening balance when `date_start` is provided, and crash if `Warehouse::where('establishment_id', ...)->first()` or `affected_document`/`sale_note`/`order_note` is null.
- Evidence:
```php
// InventoryKardexServiceProvider.php:72-73
$this->createInventoryKardex($document_item->document, $document_item->item_id, ($factor * ($document_item->quantity * $presentationQuantity)), $warehouse->id);
if(!$document_item->document->sale_note_id && !$document_item->document->order_note_id) $this->updateStock(...);
// ReportKardexCollection.php:133
if ($row->inventory_kardexable->warehouse_destination_id === $user->establishment_id) {
```
- Failure scenario: tenant converts 100 SaleNotes to Invoices and runs `regularize_stock` -> stock for every sold item is deducted a second time; filtered Kardex UI shows positive opening balance erased or mismatched vs PDF/Excel; destination warehouse transfers appear in the wrong column.
- Proposed fix: do not insert stock-moving `inventory_kardex` rows (or skip lot/series mutation) when `$document->sale_note_id || $document->order_note_id` (or exclude them in `regularize_stock` via `whereHasMorph`/join); multiply lot quantities by `$presentationQuantity`; compare `warehouse_destination_id === $warehouse->id` in Kardex report; reset balance state per instance; compute opening balance consistently across UI/PDF/Excel with `date_of_issue < $date_start`; make `$item = $item_id ? Item::findOrFail($item_id) : null` optional in `pdf`/`excel` and filter `item_id` in SQL.
- Verification idea: test SaleNote -> Document conversion followed by `regularize_stock`, asserting `item_warehouse.stock` equals initial - sold; test Kardex page 2 and filtered PDF/Excel balances against seeded movements.

### MIN-003 — Inventory, Transfer, Devolution, Warehouse, InventoryConfiguration, Incentive, and WebPlatform endpoints bypass module/level checks
- Category: security
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (add `'ajax' => true` on inventory/configuration/incentive rules and add a `web-platforms` rule in `config/permissions.php`)
- Location: `config/permissions.php:51,77-86,102-106,167`; `modules/Inventory/Routes/web.php:10-36,79-128`; `modules/Item/Routes/web.php:24-29,50-58`
- Problem:
  1. Rules for `warehouses`, `inventory`, `moves`, `transfers`, `devolutions`, `inventories` (stock-control configuration), and `incentives` in `config/permissions.php` omit `'ajax' => true`. Because `AccessMap::decide()` returns `allowed ('ajax-not-enforced')` when `X-Requested-With: XMLHttpRequest` or `Accept: application/json` is sent on rules without `'ajax' => true`, any authenticated user lacking the `inventory`, `configuration`, or `documents/incentives` permissions can call `POST /inventory/transaction`, `POST /inventory/move`, `POST /inventory/remove`, `GET /inventory/initialize`, `GET /inventory/regularize_stock`, `POST /transfers`, `DELETE /transfers/{id}`, `POST /devolutions`, `POST /warehouses`, `POST /inventories/configuration` (disable `stock_control`), and `POST/DELETE /incentives`.
  2. `web-platforms` (`modules/Item/Routes/web.php:50-58`) has no rule at all in `config/permissions.php`, so even HTML page access (`GET /web-platforms`) and CRUD writes are allowed for every authenticated tenant user (including storefront `client` users per `MOP-001`/`SEC-001`).
- Evidence:
```php
// config/permissions.php:102-106, 167
['path' => 'warehouses', 'module' => 'inventory'],
['path' => 'inventory', 'module' => 'inventory'],
['path' => 'transfers', 'module' => 'inventory'],
['path' => 'devolutions', 'module' => 'inventory'],
...
['path' => 'inventories', 'module' => 'configuration'],
```
- Failure scenario: a cashier with only `pos` sends `POST /inventories/configuration` with `X-Requested-With: XMLHttpRequest` to turn off `stock_control`, or runs `POST /inventory/transaction` to inject fake stock or alter `item_warehouse`.
- Proposed fix: add `'ajax' => true` to `warehouses`, `inventory`, `moves`, `transfers`, `devolutions`, `inventories`, and `incentives` (if cross-module reads exist, split read-only lookup paths); add `['path' => 'web-platforms', 'module' => 'items', 'ajax' => true]`.
- Verification idea: `AccessMap` unit test asserting 403 for `pos`-only user with `ajax=true` on `POST /inventory/move`, `POST /inventories/configuration`, `POST /incentives`, and `GET /web-platforms`.

### MIN-004 — Stock manipulation, client-trusted quantities, missing locks, and broken transfer delete in `InventoryController` / `TransferController` / `InventoryTrait`
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (for `InventoryController` input validation, server-side stock checks, `<= 0` guard, `(int)` warehouse check, and `lockForUpdate()`); RISKY for `TransferController::destroy` ID contract (verify whether Vue sends `Inventory.id` or `InventoryTransfer.id`)
- Location: `modules/Inventory/Http/Controllers/InventoryController.php:95-129` (`store`), `132-234` (`store_transaction`), `237-312` (`move`), `314-371` (`remove`); `modules/Inventory/Http/Controllers/Api/InventoryController.php:23-130`; `modules/Inventory/Http/Controllers/TransferController.php:169-257`; `modules/Inventory/Traits/InventoryTrait.php:200-221` (`updateStock`)
- Problem:
  1. `InventoryController::move()` and `remove()` accept raw `Request` without validation and validate `$quantity_move` / `$quantity_remove` against client-supplied `$request->input('quantity')` instead of DB `ItemWarehouse->stock`. `remove()` never checks `$quantity_remove <= 0`: passing a negative `quantity_remove` causes `Inventory::created` case 3 (`-1 * $inventory->quantity`) to **add** stock and kardex entries. `move()` checks `$warehouse_id === $warehouse_new_id` with strict `===`, which fails when one value is an integer and the other a string (`1 === '1'` -> false).
  2. `InventoryController::store()` has no FormRequest validation (accepts null or negative `quantity`).
  3. `InventoryTrait::updateStock()` and `store_transaction()` (web & API) read and modify `ItemWarehouse->stock` without `lockForUpdate()`, allowing concurrent sales, transfers, or transactions to lose updates or drive stock negative even when `stock_control = true`.
  4. `store_transaction()` (`type == 'output'`) calls `ItemLotsGroup::find($request->IdLoteSelected)` with no null check and no check that `$lot->quantity >= $quantity` (lot stock can go negative); `type == 'input'` creates a new `ItemLotsGroup` without checking existing lot codes.
  5. `TransferController::store()` iterates `$it['lots']` without checking it exists, and `TransferController::destroy($id)` calls `Inventory::findOrFail($id)` (even though `TransferController::records()` returns `InventoryTransfer` IDs via `TransferCollection`), indexes `inventory_kardex->first()` and `->last()` without verifying `type == 2`, distinct rows, or non-null `ItemWarehouse` rows, and never restores moved `ItemLot.warehouse_id` or updates/deletes `InventoryTransfer`.
- Evidence:
```php
// InventoryController.php:245,257,263 (move) & 320,335 (remove)
$quantity = $request->input('quantity');
if($warehouse_id === $warehouse_new_id) { ... }
if($quantity < $quantity_move) { ... }
// remove() has no ($quantity_remove <= 0) guard
```
- Failure scenario: POST `/inventory/remove` with `quantity=100&quantity_remove=-50` increases warehouse stock by 50; two concurrent requests to `/inventory/move` or `/api/inventory/transaction` oversell stock past zero.
- Proposed fix: validate `store`, `move`, and `remove` with FormRequests (`quantity > 0`, `exists:tenant.warehouses,id`); lock `ItemWarehouse` rows with `lockForUpdate()` inside `DB::connection('tenant')->transaction` before checking and updating stock; cast warehouse IDs to `(int)` or `!=` and check `$lot->quantity >= $quantity` on `ItemLotsGroup`.
- Verification idea: feature test posting negative `quantity_remove` -> 422, inflated client `quantity` -> 422/insufficient stock, and `move` with `'1'` and `1` -> rejected.

### MIN-005 — Report & search filter bugs (`LIKE '%id%'` cross-establishment leaks, ungrouped `OR` precedence, and ignored filter parameters)
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `modules/Report/Traits/ReportTrait.php:81-90,180-183`; `modules/Report/Http/Controllers/ReportDocumentDetractionController.php:93-105`; `modules/Report/Http/Controllers/ReportCommercialAnalysisController.php:79,88`; `modules/Report/Http/Controllers/ReportCustomerController.php:97-109`; `modules/Report/Http/Controllers/ReportItemController.php:92-116`; `modules/Report/Http/Controllers/ReportGeneralItemController.php:85-114`; `modules/Inventory/Http/Controllers/DevolutionController.php:182-187`; `app/Models/Tenant/DocumentItem.php:150-161` & `SaleNoteItem.php:129-140`; `modules/Order/Models/OrderNoteItem.php:128-133,153-158,176-180`
- Problem:
  1. **`LIKE '%id%'` cross-establishment/type leak & null drop**: `ReportTrait::data()` (lines 84, 89) and `ReportDocumentDetractionController::data()` (lines 97, 103) use `->where('establishment_id', 'like', '%' . $establishment_id . '%')` and `->where('document_type_id', 'like', '%' . $document_type_id . '%')` whenever only one of those two filters is provided. Filtering by establishment `1` returns records for establishments `1, 10, 11, 21, ...`. Similarly, `ReportCommercialAnalysisController::data()` uses `->where('person_type_id', 'like', '%' . $person_type_id . '%')`, which cross-matches numeric IDs and excludes all customers whose `person_type_id` is `NULL` when no type filter is selected (`NULL LIKE '%%'` is falsy in SQL).
  2. **Ungrouped `OR` precedence**: `ReportTrait::getDataTablePerson()` (lines 180-182, also `module => null` in `permissions.php`) chains `Person::where('number', 'like', ...)->orWhere('name', 'like', ...)->whereType($type)` without a grouping closure, so querying `/reports/data-table/persons/customers?input=...` also returns suppliers whose `number` matches. `DevolutionController::searchItems()` (lines 182-186) chains `where('description',...)->orWhere('internal_id',...)->whereWarehouse()->whereNotIsSet()->whereIsActive()`, returning sets, inactive items, and items from other warehouses whenever `description` matches (and `Item::scopeWhereWarehouse()` itself appends an ungrouped `->orWhere('unit_type_id', 'ZZ')`).
  3. **Ignored filter parameters**:
     - `ReportCustomerController::dataCustomers()` accepts `$document_type_id` and `$establishment_id` and ignores both (`whereIn('document_type_id', ['01','03'])` is hardcoded).
     - `ReportItemController::dataItems()` accepts `$document_type_id` and `$establishment_id` and ignores both.
     - `ReportGeneralItemController::dataItems()` ignores `$user` when `document_type_id == '80'`.
     - `DocumentItem::scopeWhereDefaultDocumentType()` and `SaleNoteItem::scopeWhereDefaultDocumentType()` return early when `person_id` is set, skipping the `$sellers` filter; `OrderNoteItem::scopeWherePendingState/WhereProcessedState/WhereDefaultState` apply `->where('user_id', $params['seller_id'])` unconditionally even when `seller_id` is null/omitted.
- Evidence:
```php
// ReportTrait.php:86-89
}elseif($establishment_id){
    $data = $model::whereBetween('date_of_issue', [$date_start, $date_end])->latest()
                        ->where('establishment_id', 'like', '%' . $establishment_id . '%')->whereTypeUser();
}
```
- Failure scenario: selecting establishment `1` in Sales/Purchases/SaleNotes/Quotations/Detractions reports mixes establishment `10` revenue into establishment `1`; Commercial Analysis hides all customers without a `person_type_id`; `/reports/data-table/persons/customers?input=20` exposes supplier records.
- Proposed fix: use strict `where('establishment_id', $establishment_id)`, `where('document_type_id', $document_type_id)`, and conditional `if ($person_type_id) ->where('person_type_id', $person_type_id)`; wrap `(number LIKE ... OR name LIKE ...)` and `(description LIKE ... OR internal_id LIKE ...)` inside `where(function ($q) ...)`; apply `$establishment_id`/`$document_type_id`/`$user`/`$sellers` consistently.
- Verification idea: feature test with establishments `1` and `10` asserting filtering by `1` returns only `1`, and `customers` lookup never returns `suppliers`.

### MIN-006 — `ReportCashController` crashes on every endpoint (`records`, `pdf`, `excel`)
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (for passing `filters`/`categories` on `pdf`/`excel`, though rewriting `dataCash` to merge Document + SaleNote pagination needs design choice)
- Location: `modules/Report/Http/Controllers/ReportCashController.php:43-81`; `modules/Report/Traits/ReportTrait.php:117-157` (`getRecordsCash`, `dataCash`); `modules/Report/Exports/DocumentExport.php:51-60`; `modules/Report/Resources/views/documents/report_pdf.blade.php:79,84`
- Problem:
  1. `records()` calls `ReportTrait::dataCash()`, which creates Eloquent `Builder` instances (`Document::whereIn(...)->latest()`, `SaleNote::latest()`) without `->get()`, then iterates `$sale_notes` (a `Builder`!) and calls `$documents->push($sn)` (`BadMethodCallException`: `Builder::push` does not exist). When `document_type_id == '03'`, `$documents` is never initialized (undefined variable). When `document_type_id` is absent or unrecognized, `$documents` is undefined.
  2. `pdf()` calls `getRecords($request->all(), Document::class)` (wrong model/logic for cash) and renders `report::documents.report_pdf` without `$filters`, which crashes at `report_pdf.blade.php:79` (`Undefined variable $filters`).
  3. `excel()` builds `DocumentExport` without calling `->filters(...)`, `->categories(...)`, or `->categories_services(...)`, causing `DocumentExport::view()` to crash on uninitialized properties (`Undefined property ...::$filters`).
- Failure scenario: opening `/reports/cash` and loading table records, PDF, or Excel always fails with 500.
- Proposed fix: implement `dataCash()` cleanly (e.g. SQL `UNION` or separate/tagged query returning a valid `Builder` or paginator) and pass/default `$filters`, `$categories`, `$categories_services` (or give Cash its own export/view).
- Verification idea: GET `/reports/cash/records?document_type_id=01`, `/reports/cash/pdf?...`, `/reports/cash/excel?...` return HTTP 200.

### MIN-007 — Empty-collection `!empty($records)` crashes and broken routed actions across Inventory, Item, and Report
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location:
  - `modules/Report/Resources/views/order_notes_consolidated/report_pdf.blade.php:74,76`
  - `modules/Report/Resources/views/order_notes_general/report_pdf.blade.php:74,76`
  - `modules/Report/Resources/views/customers/report_excel.blade.php:17,23`
  - `modules/Report/Resources/views/items/report_excel.blade.php:17,23`
  - `modules/Inventory/Http/Controllers/DevolutionController.php:260` (`download`) & `modules/Inventory/Routes/web.php:126`
  - `modules/Inventory/Http/Controllers/WarehouseController.php:12-67` & `modules/Inventory/Routes/web.php:14,17`
  - `modules/Inventory/Http/Controllers/ReportInventoryController.php:54-62` (`search`)
  - `modules/Item/Http/Controllers/ItemLotController.php:70-83` (`store`)
  - `modules/Report/Http/Controllers/ReportSaleConsolidatedController.php:84-103` & `app/Models/Tenant/{DocumentItem:159,171,174-178,SaleNoteItem:138,150,153-157}`
- Problem:
  1. **Empty Eloquent `Collection` check**: `!empty($records)` is always `true` for an `Illuminate\Database\Eloquent\Collection` instance (an object), even when it contains 0 models. When 0 records match, `order_notes_consolidated/report_pdf.blade.php` and `order_notes_general/report_pdf.blade.php` dereference `$records->first()->order_note->...` / `$records->first()->customer->...`, and `customers/report_excel.blade.php` and `items/report_excel.blade.php` index `$records[0]->...` -> 500 error instead of rendering the "No se encontraron registros" block (which is dead across all report PDF/Excel blades).
  2. `DevolutionController::download($external_id, $format)` lacks a default `= null` for `$format`, while `Routes/web.php:126` declares `download/{external_id}/{format?}` (`ArgumentCountError` when `{format}` is omitted).
  3. `Routes/web.php:14,17` route `warehouses/tables` and `warehouses/initialize` to `WarehouseController@tables` and `@initialize`, neither of which exists on `WarehouseController`.
  4. `ReportInventoryController::search()` renders `inventory::reports.inventory.index` without `$warehouses`, crashing the view (`Undefined variable $warehouses`).
  5. `ItemLotController::store()` calls `ItemLot::findOrFail($id)` even though line 80 has a create message (`($id)?'Serie editada...':'Serie registrada...'`), failing 404 when `id` is null.
  6. `ReportSaleConsolidatedController::dataSalesConsolidated()` calls `json_decode($request['document_type_id'])` (non-JSON scalar like `'01'` decodes to `null`; `'80'` decodes to int `80`, crashing `wherein`/`in_array`), `DocumentItem`/`SaleNoteItem::scopeWhereDefaultDocumentType` calls `->latest('id')` after `JOIN documents`/`sale_notes` (ambiguous `id` column in SQL count/union), `count(json_decode($params['sellers']))` throws `TypeError` when `sellers` is absent/invalid, and the `UNION` ignores `state_type_id` (including voided `'11'` and rejected `'09'` documents) and treats Credit Notes (`'07'`) as positive quantities.
- Failure scenario: exporting Customers, Items, or Order Notes reports on a date range with zero sales crashes with 500; downloading `/devolutions/download/{uuid}` without format throws 500.
- Proposed fix: replace `!empty($records)` with `$records->isNotEmpty()` / `count($records) > 0`; default `$format = null` in `download()`; remove or implement `warehouses/tables` & `initialize` and `ReportInventoryController::search`; handle create vs update or require `id` in `ItemLotRequest`; fix `json_decode` / `sellers` array handling and table-qualify `document_items.id` / `sale_note_items.id` in `scopeWhereDefaultDocumentType`.
- Verification idea: export each affected PDF/Excel with a no-match date range and assert 200; call `/devolutions/download/{uuid}` without format.

### MIN-008 — Financial, cost, profit, and commission calculation errors across Inventory Valued Kardex and Report module
- Category: functional-bug
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: RISKY (changes financial totals and commission amounts; business rules must be confirmed with owner)
- Location: `modules/Inventory/Helpers/InventoryValuedKardex.php:53-78`; `modules/Report/Http/Controllers/ReportCommissionController.php:99-131` & `modules/Report/Http/Resources/ReportCommissionCollection.php:27-73` & `modules/Report/Resources/views/commissions/report_{pdf,excel}.blade.php`; `modules/Report/Helpers/UserCommissionHelper.php:18-88`; `modules/Report/Http/Resources/GeneralItemCollection.php:19,52-93` & `modules/Report/Resources/views/general_items/report_excel.blade.php:93-94`; `modules/Report/Resources/views/documents/report_{pdf,excel}.blade.php:218-259`; `modules/Report/Resources/views/customers/report_excel.blade.php:124-160`; `modules/Report/Http/Resources/CommercialAnalysisCollection.php:35,54-56`
- Problem:
  1. **Valued Kardex (`InventoryValuedKardex`)**: `getValuesRecords()` counts Debit Notes (`08`) as positive sold physical units and ALL Credit Notes (`07` — including financial discount/adjustment notes 03/04/05/08/09/10) as negative sold physical units, and multiplies `$quantity_sale` (in presentation units) by base-unit `$row->purchase_unit_price` without `$presentation->quantity_unit`.
  2. **Seller Commissions (`ReportCommission*`)**: `ReportCommissionController::data()` queries `sale_notes` without `->whereNotChanged()`, so a `SaleNote` converted to an Invoice (`Document`) is counted twice (both transaction count, accumulated sales, and commissions); excludes Credit Notes (`07`); and `ReportCommissionCollection` + PDF/Excel blades sum `$row->documents->sum('total')` and `$item->quantity * $item->unit_price` directly across PEN and USD without `exchange_rate_sale` conversion.
  3. **Utility Commissions (`UserCommissionHelper`)**: when `$type == 'amount'`, `getCommission()` computes `$utilities['total_utility'] * $amount` (multiplying profit by the fixed currency amount instead of fixed/per-sale logic); `getPurchaseUnitPrice()` ignores presentation `quantity_unit`.
  4. **General Items (`GeneralItemCollection` + `general_items/report_excel.blade.php`)**: for `is_set` items (`ItemSet` model), `getIndividualPurchaseUnitPrice($item_set)` queries `PurchaseItem::where('item_id', $record->item_id)` using the SET parent's `item_id` instead of `$record->individual_item_id`, returning 0 purchase history; `$utility_item = $row->total - $total_item_purchase` neither converts USD sales to PEN nor negates Credit Notes `07`. In `general_items/report_excel.blade.php:93-94` (`document_type_id == '80'`), the `UNIDAD DE MEDIDA` and `CÓDIGO INTERNO` cells output `$value->sale_note->unit_type_id` (always null) and `$value->relation_item->brand->name`.
  5. **Rejected Credit Notes in Documents & Customers exports**: `documents/report_{pdf,excel}.blade.php` and `customers/report_excel.blade.php` check `if(($signal == '07' && $state !== '11'))`, subtracting SUNAT-rejected (`state_type_id == '09'`) Credit Notes from `$acum_total` instead of treating `['09', '11']` as 0.
  6. **Commercial Analysis (`CommercialAnalysisCollection` + `report_excel.blade.php`)**: `$documents->sum('total')` mixes currencies, includes voided/rejected (`09`/`11`) documents, and adds Credit Notes (`07`) positively; `number_format($acum_difference_days / $acum_comparations, 2)` adds a comma separator above 1,000 before passing to `Carbon::addDays()`; `Carbon::parse(null)` emits today's date as `contact_date` for customers with zero documents.
- Failure scenario: seller converts a S/10,000 SaleNote to an Invoice -> Seller Commissions report credits S/20,000 in sales and double commission; USD invoices distort PEN totals and profit; a user-commission of `amount = 5` on S/1,000 utility computes S/5,000 commission instead of S/5 or per-unit.
- Proposed fix: validate and align business rules with product owner, then add `whereNotChanged()`, currency conversion via `exchange_rate_sale`, `CreditNoteStock`-aware unit filtering, `individual_item_id` lookup for sets, `['09', '11']` zeroing for Credit Notes, and unformatted numeric days for Carbon.
- Verification idea: golden-master fixture tests per report with mixed PEN/USD, converted SaleNotes, Credit Notes (`07` types `01` vs `04` and state `09`), and ItemSets.

### MIN-009 — Unbounded exports and severe N+1 query cascades across Report and Inventory (memory exhaustion & timeouts)
- Category: performance
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE (eager-loading `with(...)`, `without([...])`, hoisting loop-invariant queries, and SQL `GROUP BY` in `totalsByItem()`; chunking/streaming for large exports is SAFE if memory-bounded)
- Location:
  - `modules/Report/Http/Resources/CommercialAnalysisCollection.php:25,62,67-78` & `modules/Report/Resources/views/commercial_analysis/report_excel.blade.php:75,118-132`
  - `modules/Report/Http/Resources/GeneralItemCollection.php:77,113-143` & `modules/Report/Http/Controllers/ReportGeneralItemController.php:167,182`
  - `modules/Report/Http/Controllers/ReportDocumentController.php:60,78,107-116` & `modules/Report/Resources/views/documents/report_excel.blade.php:149,188-211`
  - `modules/Report/Http/Controllers/{ReportCommissionController,ReportUserCommissionController}.php` & `UserCommissionHelper.php:69-75` & `ReportCommissionCollection.php:34-70`
  - `modules/Report/Traits/MassiveDownloadTrait.php:22-49,128-248`
  - `modules/Report/Http/Controllers/ReportSaleConsolidatedController.php:58-69` (`totalsByItem`)
  - `modules/Report/Resources/views/income_summary/report_pdf.blade.php:137-200` (`ReportIncomeSummaryController`)
  - `modules/Inventory/Http/Controllers/{ReportInventoryController,ReportValuedKardexController,ReportKardexLotsController,ReportKardexSeriesController,ReportKardexController}.php` (`filter` line 54-65)
  - `modules/Inventory/Traits/InventoryTrait.php:46-134`; `modules/Inventory/Http/Resources/TransferCollection.php:27-45`; `modules/Item/Http/Controllers/ItemLotController.php:36-43,85-94`
- Problem:
  1. `CommercialAnalysisCollection` and `commercial_analysis/report_excel.blade.php` execute `Category::all()` on **every customer row**, load every customer's entire `documents` history (with `Document::$with` 9 relations), and run `Item::findOrFail($it->item_id)` + `$item->category` inside a triple loop (`customers × documents × items`).
  2. `ReportGeneralItemController` (`pdf`/`excel`) runs unbounded `->get()` with no eager loading; `GeneralItemCollection` and `general_items/report_{pdf,excel}.blade.php` lazy-load `document`/`purchase`/`sale_note` (triggering `Document::$with` 9 relations), `customer`/`supplier`, `relation_item`, `web_platform`, and execute `PurchaseItem::select('unit_price')->where(...)->latest('id')->first()` per row (and per set component).
  3. `ReportDocumentController` loads unbounded `Document::get()` (9 eager relations per document); `getCategories()` lazy-loads `relation_item` per line item; `documents/report_excel.blade.php:149` runs 2 extra queries per row (`$value->payments()->count()` + `$value->payments()->first()`) even though `payments` is already eager loaded.
  4. `MassiveDownloadTrait` loads all matching `Document`/`SaleNote`/`Dispatch` models into memory at once and renders every single document's HTML into one `Mpdf` instance.
  5. `ReportSaleConsolidatedController::totalsByItem()` pulls all matching item lines into PHP (`->get()->groupBy('item_id')`) instead of aggregating `SUM(quantity)` in SQL.
  6. `ReportInventoryController` lacks `with('item.category', 'warehouse')`; `ReportKardexController::filter()` loads all non-set items without `with('category', 'brand')`; `ReportValuedKardexController` loads all items with unbounded `->get()` and lazy-loads `category`, `brand`, `document_items.document`, `sale_note_items.sale_note`; `InventoryTrait::optionsItemWareHousexId` / `optionsItemFull` and `TransferCollection` query `item_lots` and polymorphic `item_loteable` per item; `ItemLotController` lacks `with('item')`.
- Failure scenario: exporting a few thousand rows in Commercial Analysis, General Items, Sales, or Massive Downloads triggers tens of thousands of SQL queries, PHP memory exhaustion (`FromView` builds the entire DOM + PhpSpreadsheet worksheet in memory), or request timeouts.
- Proposed fix: eager-load required relations and pass `->without([...])` on `Document` queries where `items`/`payments`/`invoice`/`note` are unneeded; hoist `Category::all()` and latest `PurchaseItem` prices into pre-indexed maps; compute `totalsByItem()` in SQL; replace `payments()->count()`/`first()` with `$value->payments->first()`; use `FromQuery` / chunked exports where feasible and cap `MassiveDownloadTrait` batch size.
- Verification idea: query-count test with 50 seeded records asserting `DB::getQueryLog()` stays O(1) instead of O(N) or O(N×M).

### MIN-010 — PHP 8.3 crashes: unimported `Exception` in 5 catch blocks, unguarded `$request['...']` array accesses, `strtoupper(null)`, and `count(null)`
- Category: php83
- Severity: high
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location:
  - Missing `use Exception;`: `modules/Item/Http/Controllers/ItemController.php:51`; `modules/Item/Http/Controllers/ItemSetController.php:28,55`; `modules/Item/Http/Controllers/BrandController.php:73`; `modules/Item/Http/Controllers/CategoryController.php:74`
  - Unguarded `$request['key']` accesses: `modules/Report/Traits/ReportTrait.php:29-39,119-120,259-263`; `modules/Report/Http/Controllers/{ReportCommissionController:57-63,ReportCustomerController:56-64,ReportDocumentDetractionController:47-53,ReportDocumentHotelController:74-75,ReportCommercialAnalysisController:71-73,ReportGeneralItemController:64-74,149,ReportItemController:51-59,ReportOrderNoteConsolidatedController:61,ReportOrderNoteGeneralController:61,ReportUserCommissionController:53-59}.php`; `modules/Inventory/Http/Controllers/{ReportKardexController:93-95,257-259,304-306,ReportKardexLotsController:31-33,ReportKardexSeriesController:25-27,ReportValuedKardexController:53}.php`
  - Unguarded `$filters['key']` / `$params['key']` in views: `modules/Report/Resources/views/{documents,purchases,sale_notes,fixed-asset-purchases}/report_{pdf,excel}.blade.php`; `sales_consolidated/report_{pdf,pdf_totals,excel,excel_totals}.blade.php`; `order_notes_{consolidated,general}/report_pdf.blade.php`
  - `strtoupper(null)` deprecation: `modules/Item/Imports/ItemSetImport.php:47`
  - `count(null)` `TypeError`: `modules/Report/Http/Controllers/ReportMassiveDownloadController.php:51,68`; `app/Models/Tenant/{DocumentItem:178,SaleNoteItem:155}`; `modules/Report/Resources/views/sales_consolidated/report_{pdf,pdf_totals,excel,excel_totals}.blade.php`
- Problem:
  1. Five `catch (Exception $e)` blocks in `Modules\Item\Http\Controllers` lack `use Exception;`, so they resolve to non-existent `Modules\Item\Http\Controllers\Exception` and never catch `\Exception` (including PDO SQLSTATE `23000` FK errors on `BrandController::destroy`/`CategoryController::destroy` or malformed imports).
  2. Under PHP 8.3 with Laravel's `HandleExceptions`, reading missing keys from `$request->all()` (`$request['period']`, `$request['establishment_id']`, `$request['item_id']`, etc.) or `$filters['seller_id']` emits `Undefined array key`, converted into a 500 `ErrorException`.
  3. In `ItemSetImport.php:47`, `strtoupper($row[12])` throws a deprecation `ErrorException` if column 12 is null, or `Undefined array key 12` if the Excel row has fewer than 13 cells.
  4. `ReportMassiveDownloadController` (`json_decode($request->form)`), `DocumentItem`/`SaleNoteItem::scopeWhereDefaultDocumentType` (`json_decode($params['sellers'])`), and `sales_consolidated` views throw `TypeError` when `count()` receives `null` from missing/malformed JSON.
- Evidence:
```php
// BrandController.php:1-12 (no use Exception;), :73
} catch (Exception $e) {
    return ($e->getCode() == '23000') ? ...
// ReportTrait.php:29-39
$document_type_id = $request['document_type_id'];
```
- Failure scenario: deleting a Brand/Category linked to an Item returns a raw 500 instead of the friendly message; calling any report `/records` or `/pdf` endpoint without every optional filter key in the query string crashes with 500 on PHP 8.3.
- Proposed fix: add `use Exception;` (or `\Throwable` / `\Illuminate\Database\QueryException`) in the 4 Item controllers; replace `$arr['key']` with `$arr['key'] ?? null` across controllers, traits, and blades; use `strtoupper((string) ($row[12] ?? ''))`; validate/default `json_decode(...) ?? []` before `count()`.
- Verification idea: call `GET /reports/sales/records?period=month&month_start=2026-11` (omitting `document_type_id`, `establishment_id`, `person_id`, `seller_id`, `state_type_id`) on PHP 8.3 and assert 200; delete a referenced Brand/Category and assert JSON `success:false`.

### MIN-011 — Excel imports lack file validation, DB transactions, and row-bounds checks, and overwrite item-set stock/cost
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (for file validation, DB transaction, bounds checks, and `$registered` count); check business rule on `ItemSetImport` update overwriting `stock`/`purchase_unit_price`
- Location: `modules/Item/Http/Controllers/ItemController.php:39-62`; `modules/Item/Http/Controllers/ItemSetController.php:16-66`; `modules/Item/Imports/{ItemListPriceImport,ItemSetImport,ItemSetIndividualImport}.php`
- Problem:
  1. `importItemPriceLists`, `importItemSets`, and `importItemSetsIndividual` perform no file validation (`mimes:xlsx,xls`, `max:size`) before invoking `$import->import($request->file('file'), null, Excel::XLSX)`.
  2. None of the 3 import classes run inside `DB::connection('tenant')->transaction(...)`, so an exception mid-sheet leaves partial records committed.
  3. All 3 use `unset($rows[0])` after `$total = count($rows)` (overcounting `$total` by 1) and index `$row[0]`..`$row[12]` without bounds/type validation (`Sale_unit_price`, `quantity`, `factor`, `price1..3`).
  4. `ItemSetImport::collection()` (lines 98-118) resets `purchase_unit_price => 0`, `stock => 0`, and `stock_min => 0` when updating an existing set item; `ItemListPriceImport` (line 70) increments `$registered` even when an existing `ItemUnitType` was skipped.
- Proposed fix: validate `file` (`required|file|mimes:xlsx,xls|max:10240`); wrap `collection()` in a tenant transaction; skip empty rows and validate required/numeric columns with `$row[$i] ?? null`; avoid resetting `stock`/`purchase_unit_price` on update; count `$total` after header removal and `$registered` only on insert/update.
- Verification idea: upload a `.txt` or malformed `.xlsx` to `items/import/item-price-lists` -> 422; test rollback on invalid row 3.

### MIN-012 — Null dereferences, transaction-bound PDF rendering, and barcode download defects
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location:
  - `modules/Inventory/Http/Resources/ReportKardexLotsCollection.php:20-34` (used by `ReportKardexController::records_lots`)
  - `modules/Inventory/Http/Controllers/InventoryConfigurationController.php:22-43`
  - `modules/Inventory/Http/Resources/TransferResource.php:25`
  - `modules/Inventory/Providers/InventoryChangeServiceProvider.php:35` & `modules/Inventory/Traits/InventoryTrait.php:165-178`
  - `modules/Inventory/Http/Controllers/DevolutionController.php:101-126,281,318`
  - `modules/Item/Http/Controllers/ItemController.php:17-36` (`generateBarcode`)
  - `modules/Report/Http/Resources/DocumentCollection.php:19-20,65` & `modules/Report/Http/Resources/GeneralItemCollection.php:104-146`
- Problem:
  1. `ReportKardexLotsCollection` receives `ItemWarehouse` rows (not `Item`) and reads `$row->internal_id`, `$row->unit_type_id`, `$row->lot_code`, `$row->date_of_due`, `$row->brand->name`, `$row->category->name` -> null dereference on `$row->brand->name` and `$row->category->name`, and hardcodes `'days_to_expire' => 1`.
  2. `InventoryConfigurationController::store()` runs `InventoryConfiguration::find($id)->fill(...)` with no null check (`record()` also passes nullable `first()` to `InventoryConfigurationResource`).
  3. `TransferResource.php:25` dereferences `ItemWarehouse::where(...)->first()->stock` with no null check.
  4. `InventoryChangeServiceProvider::createdItem()` calls `$this->findWarehouse()` which assumes `auth()->user()->establishment` is non-null (fails in CLI/seeders/jobs when `warehouse_id` is absent).
  5. `DevolutionController::store()` generates the PDF (`createPdf`) and writes to storage *inside* `DB::connection('tenant')->transaction()`, holding DB locks during mPDF rendering and leaving orphaned files on DB rollback; `createPdf()` also assumes `Configuration::first()` and `$path_css` exist.
  6. `ItemController::generateBarcode()` passes `$item->barcode` directly to `BarcodeGeneratorPNG::getBarcode()` (throws `BarcodeException` 500 when empty/null), sends invalid `Content-Type: application/png` instead of `image/png`, and puts unsanitized `$item->barcode` into the download filename (`/` or `"` breaks `ResponseHeaderBag`).
  7. `DocumentCollection` (`affected_document` / `notes` line 65) and `documents/report_{pdf,excel}.blade.php` (`affected_document` / `data_affected_document`) dereference potentially null relations; `GeneralItemCollection::getDocument()` leaves `$data` keys unset if parent model is missing.
- Proposed fix: read `$row->item->*` with `optional()` in `ReportKardexLotsCollection`; use `findOrFail`/`firstOrFail` in `InventoryConfigurationController`; guard null `ItemWarehouse`/`Establishment`/`Company`/`Configuration` and `affected_document`; move `createPdf()` after transaction commit in `DevolutionController::store`; guard empty `barcode`, sanitize filename, and set `image/png`.
- Verification idea: call `items/barcode/{id}` on an item with empty `barcode` (expect 422); test `InventoryConfigurationController::store` with invalid `id` -> 404.

### MIN-013 — Unvalidated `$request->column` passed to `where($request->column, ...)` causes 500s on missing params and allows column probing
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `modules/Inventory/Http/Controllers/{InventoryController:62,WarehouseController:28,DevolutionController:58}.php`; `modules/Item/Http/Controllers/{ItemLotController:55,BrandController:31,CategoryController:31,IncentiveController:64}.php`
- Problem: all seven `records()` / `getRecords()` endpoints pass `$request->column` directly into Eloquent `->where($request->column, 'like', ...)` without checking it against the controller's `columns()` whitelist. Omitting `column` passes `null`, causing `SQLSTATE[42S22]: Unknown column ''` (500 error leaking SQL via `Handler.php`), and passing arbitrary column names or `->` JSON selectors probes internal columns of `items`, `devolutions`, `warehouses`, `item_lots`, `brands`, and `categories`. (Laravel 10's `MySqlGrammar::wrapValue` and `CompilesJsonPaths` escape backticks and single quotes, so this is column probing / 500 error rather than raw SQL injection.)
- Proposed fix: validate `column` against `array_keys($this->columns())` (or default to the primary searchable column when empty).
- Verification idea: request `/devolutions/records` without `column` -> 200; request with `column=unknown` -> 422 or safe default.

### MIN-014 — Leaked temporary files in `generateBarcode` and `MassiveDownloadTrait`, plus `income-summary/{cash}` permission/IDOR gap
- Category: security
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE (temp file cleanup + filename sanitization); check admin expectations before restricting `cash` ownership
- Location: `modules/Item/Http/Controllers/ItemController.php:26-34` (`generateBarcode`); `modules/Report/Traits/MassiveDownloadTrait.php:120-124` (`toPrintByView`); `modules/Report/Http/Controllers/ReportIncomeSummaryController.php:24-35`; `modules/Report/Routes/web.php:169`; `config/permissions.php:57`
- Problem:
  1. Both `ItemController::generateBarcode()` and `MassiveDownloadTrait::toPrintByView()` create files via `tempnam(sys_get_temp_dir(), ...)` and return `response()->download($temp, ...)` / `response()->file($temp)` without `->deleteFileAfterSend(true)`, leaking temporary files on every download/PDF generation.
  2. `GET /cash/report/income-summary/{cash}` (`modules/Report/Routes/web.php:169`) is governed by `['path' => 'cash', 'module' => 'pos']` in `config/permissions.php:57` (not `reports`, and lacks `'ajax' => true`), and `ReportIncomeSummaryController::pdf()` calls `Cash::findOrFail($cash_id)` with no ownership check (`$cash->user_id !== auth()->id()` for non-admins), allowing any POS user to view any other cashier's income summary PDF. It also embeds raw `$cash->user->name` into the download filename (`/` or `\` triggers `InvalidArgumentException`).
- Proposed fix: chain `->deleteFileAfterSend(true)` on both responses; check `if (auth()->user()->type !== 'admin' && $cash->user_id !== auth()->id()) abort(403);` and sanitize `$filename`.
- Verification idea: assert temp file does not exist after download; test cashier A requesting cashier B's `income-summary/{id}` -> 403.

### MIN-015 — Unvalidated report `period`/date inputs (`Carbon::parse` 500s), hotel date-range bug, and `.xlsx.pdf` filename
- Category: functional-bug
- Severity: medium
- Confidence: CONFIRMED
- Auto-fix: SAFE
- Location: `modules/Report/Traits/ReportTrait.php:45-64,268-285` and duplicated switches in `Report{Commission,Customer,DocumentDetraction,Item,UserCommission}Controller.php`; `modules/Report/Http/Controllers/ReportGeneralItemController.php:174-176`; `modules/Report/Http/Controllers/ReportDocumentHotelController.php:89`
- Problem:
  1. None of the report controllers validate `period`, `month_start`, `month_end`, `date_start`, or `date_end`. An unknown `period` leaves `$d_start = null; $d_end = null;` (`whereBetween` silently returns empty), while invalid `month_start`/`month_end` throws an uncaught `Carbon\Exceptions\InvalidFormatException` (500).
  2. `ReportGeneralItemController::pdf()` builds `$filename = 'Reporte_General_Productos_'.$type_name.Carbon::now().'.xlsx';` and calls `$pdf->download($filename.'.pdf')`, producing `*.xlsx.pdf`.
  3. `ReportDocumentHotelController::data()` filters `[['date_entry', '>=', $date_start], ['date_exit', '<=', $date_end]]`, omitting stays that overlap the range (check-in before `$date_start` or check-out after `$date_end`).
- Proposed fix: validate period/date parameters in a shared request/helper with a sensible `default:` range or 422 error; remove `.xlsx` from `ReportGeneralItemController::pdf()`; confirm intended date semantics for `ReportDocumentHotelController`.
- Verification idea: request `/reports/sales/records?period=month&month_start=bad` -> 422 instead of 500; download `general-items/pdf` and assert `.pdf` suffix only.

### MIN-016 — Dead code, unused routes, and backup files in Inventory and Report
- Category: dead-code
- Severity: low
- Confidence: CONFIRMED (verified via repo-wide grep across `app/`, `modules/`, `resources/`)
- Auto-fix: SAFE
- Location:
  - `modules/Inventory/Routes/web.php:17` (`warehouses/initialize` -> missing method), `14` (`warehouses/tables` -> missing method), `44-47` (commented routes), `59` (`kardex/lots/filter` -> `ReportKardexController::records_lots`, unreferenced and uses broken `ReportKardexLotsCollection`), `40` (`inventory/search` -> `ReportInventoryController::search`, only referenced in commented form in `inventory/index.blade.php:14`)
  - `modules/Inventory/Resources/views/reports/kardex/index.blade_bk.php` (backup view)
  - `modules/Inventory/Providers/InventoryKardexServiceProvider.php:185-190,352-361` (unused private `createInventory`, empty `item_lot_delete`)
  - `modules/Inventory/Traits/InventoryTrait.php:231-249` (`initializeInventory`, called only by unreferenced `GET /inventory/initialize`; iterates `Item::all()` including `ZZ` services and sets)
  - `modules/Inventory/Http/Controllers/TransferController.php:85-166` (commented `store`); `modules/Inventory/Http/Controllers/Api/InventoryController.php:70-121` (commented lots logic)
  - `modules/Report/Traits/ReportAnalysisTrait.php` (empty `getCalculateCountCategory`; imported unused in `CommercialAnalysisCollection.php:9`)
  - `modules/Report/Http/Resources/IncomeSummaryCollection.php` (unused stub)
  - `modules/Report/Http/Controllers/ReportSaleConsolidatedController.php:104-126` (unreachable code after `return $data;` on line 102)
  - `modules/Report/Http/Controllers/ReportOrderNoteConsolidatedController.php:98-111` (commented `excel()`)
  - `modules/Report/Resources/views/order_notes_consolidated/report_excel.blade.php` & `order_notes_general/report_excel.blade.php` (unreferenced copies of `customers/report_excel.blade.php`)
  - `modules/Report/Http/Controllers/ReportController.php:5-11` (unused imports `DocumentType`, `Establishment`, `Person`, `Company`)
- Proposed fix: remove dead methods, unreferenced views/stubs, commented blocks, and broken unreferenced routes (`warehouses/initialize`, `warehouses/tables`, `kardex/lots/filter`, `inventory/search`).

### MIN-017 — Duplicate route names, duplicated logic, and missing uniqueness/FK guards
- Category: duplication
- Severity: low
- Confidence: CONFIRMED
- Auto-fix: SAFE (for route names, `WebPlatformController::destroy` FK handling, and deduplicating helpers; adding `unique` validation on `Brand`/`Category`/`WebPlatform` `name` is SAFE if existing tenants have no duplicates)
- Location:
  - Duplicate route names: `modules/Inventory/Routes/web.php:54-56` (`reports.kardex.filter` registered 3 times); `modules/Report/Routes/web.php:51,68` (`tenant.reports.document_hotels.records` overwritten by `document-detractions/records`)
  - Duplicated period-switching code: `ReportTrait::getRecords` vs `ReportTrait::getDataOfPeriod` vs 5 controllers (`ReportCommission`, `ReportCustomer`, `ReportDocumentDetraction`, `ReportItem`, `ReportUserCommission`)
  - Duplicated Kardex row rendering: `ReportKardexCollection.php`, `kardex/report_pdf.blade.php`, `kardex/report_excel.blade.php`
  - Duplicated lot/series/valued transforms: `InventoryKardexLots::transformRecords` vs `ReportKardexLotsGroupCollection`; `InventoryKardexSeries::transformRecords` vs `ReportKardexItemLotCollection`; `InventoryValuedKardex::getTransformRecords` vs `ReportValuedKardexCollection` (`|` vs `-` separator)
  - Duplicated CRUD in `modules/Item`: `BrandController`, `CategoryController`, `WebPlatformController` (`BrandRequest`/`CategoryRequest`/`WebPlatformRequest` lack `unique` on `name`; `WebPlatformController::destroy` has no FK `try/catch`; `fill($request->all())` on all three + `InventoryConfigurationController::store`)
  - Minor mismatches: `InventoryController::remove()` line 366 returns `'Producto trasladado con éxito'`; `ReportGeneralItemController.php:33` and `ReportItemController.php:26` pass unused argument `'items'` to `ReportTrait::getItems()`; `WarehouseController::store` checks "one warehouse per establishment" in PHP without DB uniqueness/lock; `DevolutionController::createPdf` and `MassiveDownloadTrait::createPdf` duplicate 60-line mPDF configuration; `MassiveDownloadTrait::getData` overwrites `$documents_01` in `default:` if `'01'` and unknown type are combined.
- Proposed fix: rename duplicate route names (`reports.kardex_lots.filter`, `reports.kardex_series.filter`, `tenant.reports.document_detractions.records`); delegate period parsing to `ReportTrait::getDataOfPeriod()`; share Kardex/Lot/Series/Valued row presenters and mPDF setup; add `QueryException` handling on `WebPlatformController::destroy` and `unique` checks on master tables.

## Overflow themes (not itemised)
- Report PDF/Excel Blade views share a recurring pattern of copying `documents/report_*` (`$value->total_*` mutation inside `@foreach` loops, `empty($records)` on Eloquent Collections leaving the "No se encontraron registros" branch unreachable, and mixed `number_format` decimal/thousands separators across CSV/Excel/PDF).
- `User::scopeWhereTypeUser` is called in `ReportCommissionController` and `ReportUserCommissionController`, while `ReportOrderNoteConsolidatedController` omits `whereTypeUser()` (unlike `ReportOrderNoteGeneralController`).
- `Modules\Inventory\Providers\InventoryKardexServiceProvider` coexists with legacy `App\Providers\{KardexServiceProvider,AnulationServiceProvider}` (still enabled in `config/app.php:170-171`, writing `items.stock`/`kardex`) and disabled `App\Providers\Inventory*` stubs (`config/app.php:173-175`).
