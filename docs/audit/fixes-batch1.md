# Registro de correcciones — Batch 1b (Módulos de operación)

Sub-lote ejecutado sobre hallazgos seguros (`SAFE`) de [modules-ops.md](file:///Volumes/Mac/juniorbardales/Downloads/sunat/docs/audit/findings/modules-ops.md), sin modificar `composer.json`, `composer.lock`, `app/`, `tests/`, `routes/`, `config/`, `modules/Padron/`, `docs/audit/AUDIT_LOG.md` ni la base de datos.

## Correcciones

### FIX-003 — Optimización de `globalData` en Dashboard y guardas contra nulos
- Área: Backend / Rendimiento
- Hallazgo de origen: MOP-003, MOP-037
- Archivo: `modules/Dashboard/Helpers/DashboardData.php`, `modules/Dashboard/Helpers/DashboardStock.php`, `modules/Dashboard/Http/Controllers/DashboardController.php`
- Función o componente: `DashboardData::globalData()`, `DashboardData::sale_note_totals_global()`, `DashboardData::document_totals_globals()`, `DashboardStock::stock_by_products()`, `DashboardController::index()`
- Problema: `document_totals_globals()` y `sale_note_totals_global()` cargaban todas las filas de `Document` y `SaleNote` hidratando todas las relaciones definidas en `$with` (10 en `Document` y 6 en `SaleNote`) en cada carga del dashboard; además `Configuration::first()`, `Establishment::first()` y `Company::first()` se desreferenciaban sin guarda contra `null` en inquilinos recién creados.
- Causa: `Document::$with` y `SaleNote::$with` cargan relaciones por defecto en toda consulta Eloquent aunque los totales globales solo requieren columnas escalares; `->payments` se leía en un acumulador muerto cuyo resultado nunca se devolvía.
- Corrección aplicada: Se añadió `->without(['user', 'soap_type', 'state_type', 'document_type', 'currency_type', 'group', 'items', 'invoice', 'note', 'payments'])` y `->whereIn('state_type_id', ['01','03','05','07','13'])` en `document_totals_globals()`, y `->without(['user', 'soap_type', 'state_type', 'currency_type', 'items', 'payments'])` en `sale_note_totals_global()`, eliminando la lectura muerta de `->payments` para evitar lazy-loading N+1 y preservando exactamente el mismo valor retornado. Se protegieron con `optional(...)` / operador ternario `Configuration::first()` en `DashboardData::globalData()`, `Establishment::select('id')->first()` en `DashboardStock::stock_by_products()`, y `Company::select('soap_type_id')->first()` en `DashboardController::index()`.
- Motivo: Reducir drásticamente la cantidad de consultas y memoria en `GET /dashboard` sin alterar los montos calculados y evitar errores fatales en inquilinos sin configuración inicial.
- Archivos modificados:
  - `modules/Dashboard/Helpers/DashboardData.php`
  - `modules/Dashboard/Helpers/DashboardStock.php`
  - `modules/Dashboard/Http/Controllers/DashboardController.php`
- Riesgo: Bajo
- Verificación: Revisión de diff para paridad numérica y `php -l` (PHP 8.5.5 en host, 0 errores en los módulos modificados). Pendiente verificación en runtime/PHPUnit dentro del contenedor `facturador_fpm`.
- Resultado: Corregido sin verificación completa

### FIX-004 — Corrección de operador de comparación, validación y timeout en `ServiceData`
- Área: Backend / Seguridad
- Hallazgo de origen: MOP-011
- Archivo: `modules/Services/Data/ServiceData.php`
- Función o componente: `ServiceData::service()`
- Problema: `$configuration->url_apiruc =! ''` y `$configuration->token_apiruc =! ''` realizaban una asignación (`=!`) en lugar de comparación, impidiendo el fallback a `config('configuration.api_service_*')`; además `$type` y `$number` se interpolaban sin validar en la ruta `/api/{$type}/{$number}`, Guzzle carecía de `timeout` total y respuestas no-JSON devolvían `null` sin control.
- Causa: Error tipográfico (`=!` en lugar de `!=`) y ausencia de validación de entrada, guarda de configuración nula/vacía, timeout y manejo de cuerpo no-JSON o excepciones HTTP.
- Corrección aplicada: Se validó `$type` (`in_array($type, ['ruc', 'dni'], true)`) y `$number` (`ctype_digit($number) && in_array(strlen($number), [8, 11], true)`), se corrigió la comparación con guarda de `$configuration` nulo (`$configuration && $configuration->url_apiruc != ''` / `token_apiruc != ''`), se validó `$url` no vacío, se agregó `'timeout' => 10` al cliente Guzzle y se envolvió la petición en `try/catch (\Throwable $th)` con `report($th)` y verificación `is_array($response)` retornando una respuesta JSON controlada con `success => false`.
- Motivo: Evitar path traversal hacia el host upstream, prevenir bloqueo indefinido de workers PHP, restaurar el fallback de configuración y manejar fallos del servicio externo sin 500.
- Archivos modificados:
  - `modules/Services/Data/ServiceData.php`
- Riesgo: Bajo
- Verificación: Verificación de consumidores (`Modules\Services\Http\Controllers\ServiceController` y `App\Http\Controllers\System\ServiceController`) y `php -l` en host (0 errores). Pendiente prueba funcional contra MockHandler/contenedor.
- Resultado: Corregido sin verificación completa

### FIX-005 — Concurrencia, transacciones, validaciones y N+1 en el módulo Hotel
- Área: Backend / Base de datos / Rendimiento / Seguridad
- Hallazgo de origen: MOP-013, MOP-015, MOP-016, MOP-017, MOP-033, MOP-035
- Archivo: `modules/Hotel/Http/Controllers/HotelRentController.php`, `modules/Hotel/Http/Controllers/HotelRoomController.php`, `modules/Hotel/Http/Controllers/HotelFloorController.php`, `modules/Hotel/Http/Controllers/HotelCategoryController.php`, `modules/Hotel/Http/Controllers/HotelRateController.php`, `modules/Hotel/Http/Controllers/HotelReceptionController.php`, `modules/Hotel/Http/Requests/HotelAddRateToRoomRequest.php`
- Función o componente: `HotelRentController::{store, addProductsToRoom, showFormChekout, finalizeRent}`, `HotelRoomController::{update, destroy, changeRoomStatus, addRateToRoom}`, `HotelFloorController::destroy`, `HotelCategoryController::destroy`, `HotelRateController::destroy`, `HotelReceptionController::getRooms`, `HotelAddRateToRoomRequest::rules`
- Problema: `HotelRentController::store` no bloqueaba la habitación (`lockForUpdate`), dejaba la transacción abierta en retorno temprano, devolvía `'success' => true` en errores y exponía `$th->getMessage()`; `finalizeRent` no verificaba estado ni usaba transacción (y `HotelRent::$fillable` descartaba `arrears`/`status` al usar `update()`); `addProductsToRoom` iteraba sin transacción; `showFormChekout` desreferenciaba `$establishment->id` sin guarda; `HotelRoomController::update` usaba `HotelFloorRequest`; `changeRoomStatus` no validaba contra `HotelRoom::$status` y respondía siempre `DISPONIBLE`; `addRateToRoom` ignoraba `$roomId` de la ruta; `destroy()` en 4 controladores exponía excepciones SQL crudas en `data`/`message`; `HotelReceptionController::getRooms` ejecutaba N+1 consultas de `HotelRent` por cada habitación ocupada.
- Causa: Falta de bloqueo pesimista/transacciones en operaciones de renta, FormRequests mal asignados o incompletos, uso de `$th->getMessage()` en respuestas HTTP y consulta por habitación dentro de `each()`.
- Corrección aplicada:
  - `HotelRentController::store`: `HotelRoom::lockForUpdate()->findOrFail($roomId)`, `rollBack()` explícito antes del retorno temprano si no está `DISPONIBLE`, `'success' => false` en errores y `report($th)` con mensaje genérico en `catch`.
  - `HotelRentController::finalizeRent`: `DB::connection('tenant')->transaction(...)` con `HotelRent::lockForUpdate()->findOrFail($rentId)`, validación de `$rent->status !== 'FINALIZADO'` (422 si ya estaba finalizada), asignación explícita de `$rent->arrears` y `$rent->status = 'FINALIZADO'` antes de `save()`, y actualización de habitación a `LIMPIEZA`.
  - `HotelRentController::addProductsToRoom`: envuelto en `DB::connection('tenant')->transaction(...)` verificando `HotelRent::findOrFail($rentId)`.
  - `HotelRentController::showFormChekout`: guarda contra nulo para `$establishment` (`$establishment ? Series::...->get() : collect()`).
  - `HotelRoomController::update`: cambio de `HotelFloorRequest` a `HotelRoomRequest`.
  - `HotelRoomController::changeRoomStatus`: validación de `status` con `Rule::in(HotelRoom::$status)`, `findOrFail($roomId)` y mensaje con el estado real (`$room->status`).
  - `HotelRoomController::addRateToRoom` y `HotelAddRateToRoomRequest`: uso obligatorio de `$roomId` de ruta al crear `HotelRoomRate` (verificando `HotelRoom::findOrFail($roomId)`) y `hotel_room_id` pasado a `nullable|numeric` en el request.
  - `destroy()` en `HotelRoomController`, `HotelFloorController`, `HotelCategoryController`, `HotelRateController`: `findOrFail($id)` antes de `try`, `report($th)` y mensaje limpio en clave `message` sin detalles SQL.
  - `HotelReceptionController::getRooms`: carga en lote con `HotelRent::whereIn('hotel_room_id', $occupiedRoomIds)->orderBy('id', 'DESC')->get()->unique('hotel_room_id')->keyBy('hotel_room_id')`.
- Motivo: Eliminar condiciones de carrera en alquiler/cierre de habitaciones, evitar filtración de errores SQL, asegurar integridad referencial de tarifas y eliminar N+1 en recepción.
- Archivos modificados:
  - `modules/Hotel/Http/Controllers/HotelRentController.php`
  - `modules/Hotel/Http/Controllers/HotelRoomController.php`
  - `modules/Hotel/Http/Controllers/HotelFloorController.php`
  - `modules/Hotel/Http/Controllers/HotelCategoryController.php`
  - `modules/Hotel/Http/Controllers/HotelRateController.php`
  - `modules/Hotel/Http/Controllers/HotelReceptionController.php`
  - `modules/Hotel/Http/Requests/HotelAddRateToRoomRequest.php`
- Riesgo: Bajo
- Verificación: Inspección de consumidores Vue (`Rent.vue`, `Checkout.vue`, `RoomRates.vue`, `List.vue`, `app.js::axiosError`) y `php -l` en host (0 errores). Pendiente prueba funcional en contenedor.
- Resultado: Corregido sin verificación completa

### FIX-006 — Corrección de listados AJAX vacíos, carga de archivos y errores en DocumentaryProcedure
- Área: Backend / Seguridad / Rendimiento
- Hallazgo de origen: MOP-010, MOP-014, MOP-033
- Archivo: `modules/DocumentaryProcedure/Http/Controllers/DocumentaryOfficeController.php`, `modules/DocumentaryProcedure/Http/Controllers/DocumentaryProcessController.php`, `modules/DocumentaryProcedure/Http/Controllers/DocumentaryDocumentController.php`, `modules/DocumentaryProcedure/Http/Controllers/DocumentaryActionController.php`, `modules/DocumentaryProcedure/Http/Controllers/DocumentaryFileController.php`, `modules/DocumentaryProcedure/Http/Requests/FileRequest.php`
- Función o componente: `index()` en los 4 controladores catálogo, `destroy()` en los 5 controladores, `DocumentaryFileController::{index, storeFile, store}`, `FileRequest::rules()`
- Problema: Cuando el filtro `name` estaba vacío, `index()` devolvía el objeto `Builder` serializado como `{}` en lugar de un arreglo; `DocumentaryFileController::index()` ejecutaba `$files->load('offices')` redundante teniendo `with('offices')`; `FileRequest` no validaba `file` y `storeFile()` conservaba nombre y extensión del cliente; `destroy()`/`store()` devolvían `$th->getMessage()`.
- Causa: `->get()` estaba dentro de `if ($filter)`, faltaba regla `file` en `FileRequest` y sanitización de nombre de archivo, y los `catch` retornaban texto crudo de excepción.
- Corrección aplicada: Se movió `->get()` fuera de `if ($filter)` en `DocumentaryOfficeController`, `DocumentaryProcessController`, `DocumentaryDocumentController` y `DocumentaryActionController`; se eliminó `$files->load('offices')` en `DocumentaryFileController::index()`; se agregó `'file' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png|max:10240'` en `FileRequest`; `storeFile()` genera nombres seguros con `Str::uuid() . '.' . ($file->extension() ?: 'bin')` manteniendo el prefijo `storage/uploads/files/` esperado por `ModalAddEdit.vue`; se añadió `report($th)` y mensajes genéricos sin detalles SQL en `destroy()` y `store()`.
- Motivo: Corregir la pérdida de filas en búsquedas vacías, impedir subida de archivos ejecutables/HTML/SVG en almacenamiento público y evitar exposición de errores SQL.
- Archivos modificados:
  - `modules/DocumentaryProcedure/Http/Controllers/DocumentaryOfficeController.php`
  - `modules/DocumentaryProcedure/Http/Controllers/DocumentaryProcessController.php`
  - `modules/DocumentaryProcedure/Http/Controllers/DocumentaryDocumentController.php`
  - `modules/DocumentaryProcedure/Http/Controllers/DocumentaryActionController.php`
  - `modules/DocumentaryProcedure/Http/Controllers/DocumentaryFileController.php`
  - `modules/DocumentaryProcedure/Http/Requests/FileRequest.php`
- Riesgo: Bajo
- Verificación: Verificación de manejo de `attached_file` en `ModalAddEdit.vue` y `php -l` en host (0 errores). Pendiente prueba funcional en contenedor.
- Resultado: Corregido sin verificación completa

### FIX-007 — Validaciones, limpieza de temporales, guardas de nulos y optimización en Account
- Área: Backend / Rendimiento / Seguridad
- Hallazgo de origen: MOP-022, MOP-023, MOP-025
- Archivo: `modules/Account/Http/Controllers/CompanyAccountController.php`, `modules/Account/Http/Controllers/AccountController.php`, `modules/Account/Http/Controllers/SummaryReportController.php`
- Función o componente: `CompanyAccountController::{record, store}`, `AccountController::{download, getStructureConcar, getStructureSiscont, getStructureContasis}`, `SummaryReportController::{records, download, getAcceptedDocuments, getVoidedDocuments}`
- Problema: `CompanyAccountController` usaba `first()`/`find($id)` sin guarda de nulos; `AccountController::download()` ejecutaba `getDocuments()` incluso para `concar`, no validaba `type` ni `month`, devolvía 200 vacío en formato desconocido, filtraba archivos temporales en `siscont` y desreferenciaba `$company_account` sin guarda (además de consultarlo por fila en `getStructureContasis`); `SummaryReportController` cargaba las 10 relaciones de `Document::$with` en ambos `with(['documents' => ...])` y no validaba fechas en `download()`.
- Causa: Consultas previas al `switch`, ausencia de `deleteFileAfterSend(true)`, falta de `validate()`, omisión de `without(...)` en cierres de relación `documents` y falta de `firstOrFail`/`findOrFail`.
- Corrección aplicada:
  - `CompanyAccountController`: uso de `firstOrFail()` en `record()` y `findOrFail($id)` en `store()`.
  - `AccountController::download`: validación `type` (`required|in:concar,siscont,foxcont,contasis,adsoft,sumerius`) y `month` (`required|date_format:Y-m`), carga de `getDocuments()` únicamente en los casos que lo requieren, `->deleteFileAfterSend(true)` en `siscont`, rama `default:` con `abort(422, 'Formato no válido')`, guarda `optional($company_account)` en `getStructureConcar`/`getStructureSiscont`/`getStructureContasis` y elevación de `CompanyAccount::first()` fuera del `transform()` en `getStructureContasis`.
  - `SummaryReportController`: validación `date_start`/`date_end` como `required|date` en `records()` y `download()`, y agregado de `->without(['user', 'soap_type', 'state_type', 'document_type', 'currency_type', 'group', 'items', 'invoice', 'note', 'payments'])` en ambos cierres `with(['documents' => ...])`.
- Motivo: Evitar fugas de archivos temporales en `/tmp`, errores 500 por entradas inválidas o cuentas no inicializadas, y consultas redundantes en reportes contables.
- Archivos modificados:
  - `modules/Account/Http/Controllers/CompanyAccountController.php`
  - `modules/Account/Http/Controllers/AccountController.php`
  - `modules/Account/Http/Controllers/SummaryReportController.php`
- Riesgo: Bajo
- Verificación: Revisión de callers en `company_accounts/form.vue` y `php -l` en host (0 errores). Pendiente prueba funcional en contenedor.
- Resultado: Corregido sin verificación completa

### FIX-008 — Regla `required_if` y guarda contra nulos en Offline
- Área: Backend
- Hallazgo de origen: MOP-024
- Archivo: `modules/Offline/Http/Requests/OfflineConfigurationRequest.php`, `modules/Offline/Http/Controllers/OfflineConfigurationController.php`
- Función o componente: `OfflineConfigurationRequest::rules()`, `OfflineConfigurationController::{record, store}`
- Problema: `'required_if:is_client, "true"'` comparaba contra el literal con espacio y comillas, anulando la obligatoriedad de `token_server` y `url_server`; `record()` y `store()` usaban `first()`/`find($id)` sin guarda contra `null`.
- Causa: Comillas y espacio sobrantes en la definición de la regla Laravel y ausencia de `*OrFail()`.
- Corrección aplicada: Se corrigieron ambas reglas a `'required_if:is_client,true'` y se sustituyeron `first()`/`find($id)` por `firstOrFail()` y `findOrFail($id)`.
- Motivo: Hacer efectiva la validación cuando el nodo opera como cliente offline y evitar errores fatales si falta el registro.
- Archivos modificados:
  - `modules/Offline/Http/Requests/OfflineConfigurationRequest.php`
  - `modules/Offline/Http/Controllers/OfflineConfigurationController.php`
- Riesgo: Bajo
- Verificación: `php -l` en host (0 errores). Pendiente prueba funcional en contenedor.
- Resultado: Corregido sin verificación completa

### FIX-009 — Correcciones seguras en rutas, controladores y ViewComposer de Ecommerce
- Área: Backend / Seguridad / Rendimiento
- Hallazgo de origen: MOP-008, MOP-021, MOP-026, MOP-027, MOP-028, MOP-037
- Archivo: `modules/Ecommerce/Routes/web.php`, `modules/Ecommerce/Http/Controllers/EcommerceController.php`, `modules/Ecommerce/Http/Controllers/ConfigurationController.php`, `modules/Ecommerce/Http/ViewComposers/FeaturedProductsViewComposer.php`
- Función o componente: rutas de `ecommerce`, `EcommerceController::{index, category, item, partialItem, ratingItem, saveDataUser}`, `ConfigurationController::uploadFile`, `FeaturedProductsViewComposer::{compose, getExchangeRateSale}`
- Problema: Colisión del nombre de ruta `tenant_ecommerce_login` entre GET y POST y ausencia de `throttle` en login/registro/pagos; `saveDataUser` asignaba en condición (`$user->telephone = $request->telephone`) y fallaba con 500 si no había usuario autenticado; `ratingItem` devolvía `'success' => false` al guardar y no validaba rango; `item`/`partialItem`/`index`/`category` desreferenciaban modelos posiblemente nulos; `uploadFile` leía la extensión del cliente antes de validar, aceptaba `svg` y cualquier `type`; `FeaturedProductsViewComposer` sufría N+1 en `currency_type`/`tags` y repetía `exchangeRateTest` en 4 parciales por petición.
- Causa: Duplicación de nombres de rutas, falta de middleware `throttle`, error `=` en lugar de `$request->filled()`, falta de validación/whitelisting y falta de `with()` + memoización.
- Corrección aplicada:
  - `modules/Ecommerce/Routes/web.php`: se renombró el `POST login` a `tenant_ecommerce_login_post` (conservando `tenant_ecommerce_login` en el GET del mismo URI), se agregó `throttle:5,1` a `login`/`storeUser` y `throttle:10,1` a `culqi`/`payment_cash`.
  - `EcommerceController`: `optional($configuration)->stock_control` en `index`/`category`; `Item::findOrFail($id)` en `item`/`partialItem`; validación (`item_id` existente y `value` numérico `1..5`) + `'success' => true` en `ratingItem`; retorno HTTP 401 cuando `!auth()->user()` y uso de `$request->filled('address')`/`$request->filled('telephone')` en `saveDataUser`.
  - `ConfigurationController::uploadFile`: validación de `file` (`required|image|mimes:jpeg,png,jpg,gif|max:2048`) y `type` (`Rule::in(['logo_store'])`) antes de leer la extensión, uso de `$file->extension() ?: 'png'` y `firstOrFail()` en `ConfigurationEcommerce` y `Company`.
  - `FeaturedProductsViewComposer`: `Item::with(['currency_type', 'tags'])` y memoización estática por fecha en `getExchangeRateSale()`.
- Motivo: Prevenir colisión de nombres de rutas, abuso de fuerza bruta/pagos, sobrescritura accidental de teléfono, XSS por logos SVG/nombres arbitrarios, 500 por nulos y N+1 en la portada.
- Archivos modificados:
  - `modules/Ecommerce/Routes/web.php`
  - `modules/Ecommerce/Http/Controllers/EcommerceController.php`
  - `modules/Ecommerce/Http/Controllers/ConfigurationController.php`
  - `modules/Ecommerce/Http/ViewComposers/FeaturedProductsViewComposer.php`
- Riesgo: Bajo
- Verificación: Búsqueda con `git grep` de `tenant_ecommerce_login` (todos los callers apuntan al mismo URI `/ecommerce/login`), inspección de `configuration_logo/index.vue` y `php -l` en host (0 errores). Pendiente prueba funcional en contenedor.
- Resultado: Corregido sin verificación completa

### FIX-010 — Limpieza de código muerto confirmado y protección de ruta en LevelAccess
- Área: Backend / Seguridad / Mantenibilidad
- Hallazgo de origen: MOP-030, MOP-031, MOP-032
- Archivo: `modules/Dashboard/Helpers/DashboardView.php`, `modules/Dashboard/Http/Controllers/DashboardController.php`, `modules/LevelAccess/Routes/web.php`, `modules/Ecommerce/Http/ViewComposers/TakeProductoViewComposer.php` (eliminado), `modules/Account/Resources/views/accounting/templates/excel_concar_bk.blade.php` (eliminado)
- Función o componente: `DashboardView::getUnpaid()`, `DashboardController` (imports y métodos comentados `unpaid`/`unpaidall`), `modules/LevelAccess/Routes/web.php`
- Problema: `DashboardView::getUnpaid` solo era referenciado por código comentado en `DashboardController`; `TakeProductoViewComposer.php` tenía namespace incorrecto y solo figuraba comentado en `ViewServiceProvider`; `excel_concar_bk.blade.php` era una vista respaldo sin referencias; `GET /levelaccess` estaba registrado sin `Route::domain($hostname->fqdn)`, `auth` ni `locked.tenant`.
- Causa: Restos de código histórico sin uso y archivo de rutas scaffold sin envoltura multi-tenant ni autenticación.
- Corrección aplicada: Verificado previamente con `git grep`; se eliminó `DashboardView::getUnpaid` junto con sus imports exclusivos (`Dispatch`, `DocumentPayment`, `SaleNotePayment`, `Invoice`), manteniendo intacto `getUnpaidFilterUser`; se limpiaron imports no usados (`AccountsReceivable`, `Excel`, `DB`, `Document`, `Arr`) y bloques comentados en `DashboardController.php`; se eliminaron `TakeProductoViewComposer.php` y `excel_concar_bk.blade.php`; se envolvió `/levelaccess` en `Route::domain($hostname->fqdn)` + `['web', 'auth', 'locked.tenant']`.
- Motivo: Reducir código muerto y cerrar un endpoint accesible sin autenticación ni contexto de inquilino.
- Archivos modificados:
  - `modules/Dashboard/Helpers/DashboardView.php`
  - `modules/Dashboard/Http/Controllers/DashboardController.php`
  - `modules/LevelAccess/Routes/web.php`
  - `modules/Ecommerce/Http/ViewComposers/TakeProductoViewComposer.php` (eliminado)
  - `modules/Account/Resources/views/accounting/templates/excel_concar_bk.blade.php` (eliminado)
- Riesgo: Bajo
- Verificación: Verificación previa con `git grep` sobre `getUnpaid`, `TakeProductoViewComposer` y `excel_concar_bk` + `php -l` en host (0 errores).
- Resultado: Corregido sin verificación completa

## Pendientes y no aplicados (`MOP-*` omitidos por riesgo o alcance)

Los siguientes hallazgos de `modules/Account`, `Dashboard`, `Hotel`, `Ecommerce`, `BusinessTurn`, `DocumentaryProcedure`, `LevelAccess`, `Offline`, `Padron` y `Services` no se modificaron en este sub-lote:

- **MOP-001** (RISKY — `modules/Ecommerce`): Auto-registro público asigna módulo back-office `10` (`ecommerce`) y `api_token`; requiere decisión de producto/arquitectura sobre el modelo de usuario cliente y reglas en `app/`/`config/`.
- **MOP-002** (Fuera de sub-lote): `modules/Padron/` ya fue intervenido previamente; no se tocó.
- **MOP-003 (parte SQL SUM)** / **MOP-004** (RISKY — `modules/Dashboard`): Reescritura de `DashboardData` y `TotalsTrait` a agregaciones SQL requiere batería golden-master para asegurar paridad al céntimo.
- **MOP-005** (RISKY — `app/Services/Payments/CartPricing.php`): Manejo de tasa de cambio `0` en items USD afecta flujo de cobro y reside en `app/`.
- **MOP-006** (RISKY — `config/permissions.php`): Añadir `'ajax' => true` a `bussiness_turns`, `offline-configurations` y `company_accounts` altera autorización transversal y reside en `config/`.
- **MOP-007** (RISKY — `app/Services/Payments/CulqiCharger.php`): Dependencia de `culqi/culqi-php` ligada a cambios del usuario en `composer.json` y archivos en `app/`.
- **MOP-008 (parte semántica)** (RISKY — `modules/Ecommerce`): Validación de `correo_electronico` en invitados y regeneración de sesión tras login quedaron fuera del alcance seguro de rutas/throttle.
- **MOP-009** (RISKY — `modules/Ecommerce`): Ocultar campos de costo (`purchase_unit_price`, stock por almacén) en `ItemBarCollection` y filtrar `apply_store`/`active` en `item`/`partialItem` puede afectar vistas JS/Blade del storefront.
- **MOP-011 (parte TLS/throttle)** (RISKY — `modules/Services`): Cambiar `'verify' => false` a `true` puede romper instalaciones locales/autofirmadas de `apiruc`; throttle en ruta Services no incluido en el sub-lote.
- **MOP-012** (RISKY — `modules/BusinessTurn`): Exposición de `api_service_token` en `bussiness_turns/tables` requiere migrar consumidores Vue de `BusinessTurn` y `Order` al proxy del servidor.
- **MOP-017 (parte repricing)** (RISKY — `modules/Hotel`): Recalcular precios desde `items` en `addProductsToRoom` cambia reglas comerciales (tarifas negociadas/descuentos manuales).
- **MOP-018** (RISKY — `modules/Dashboard`): Cambio de claves de buckets en `getDocumentsByDays`/`getDocumentsByMonths` modifica el formato de series consumido por gráficos Vue.
- **MOP-019** (RISKY — `modules/Dashboard`): Doble conteo de percepciones USD en `TotalsTrait` altera montos reportados y requiere confirmación de negocio.
- **MOP-020** (RISKY — `modules/Dashboard`): Omisión de ventas USD en `DashboardUtility` es paridad documentada de regla legacy.
- **MOP-021 (parte paginación/constructor)** (RISKY — `modules/Ecommerce`): Paginar `items_bar` o retirar la consulta en `EcommerceController::__construct` puede romper scripts del storefront que esperan todos los ítems o `$records` compartido.
- **MOP-023 (parte `whereHas` y `exchange_rate_sale`)**: Filtro de fechas en `whereHas('documents')` y columna faltante en `getVoidedDocuments` omitidos para no alterar filas/totales reportados sin confirmación.
- **MOP-027 (parte `transactionFinally`)** (RISKY — `modules/Ecommerce`): Validación estricta de `document_external_id`/`number_document` ligada al flujo de pago/emisión de comprobante.
- **MOP-028 (parte `store_configuration_social`/`tags`)**: Validación de URLs en redes sociales omitida por posible uso de formatos parciales en formularios existentes.
- **MOP-029** (Fuera de sub-lote): Ruta y método `DashboardController::df` no incluidos en el alcance de `FIX-003`..`FIX-010`.
- **MOP-031 (resto de scaffolds)**: `ReportAccountConcarExport`, `DocumentaryProcedureController`, `LevelAccessController` scaffold y `Padron`/`ExtractorTrait` conservados sin borrar fuera de los ítems explícitos de `FIX-010`.
- **MOP-033 (parte refactorización/clave `succes`)**: Deduplicación de controladores CRUD Hotel/Documentary y corrección de clave `succes` omitidos para mantener compatibilidad con consumidores Vue.
- **MOP-034** (RISKY — `modules/DocumentaryProcedure`): Numeración por `count()+1` requiere índice único en BD y migración.
- **MOP-036** (Fuera de sub-lote): Endpoints en `modules/Padron/` ya atendidos fuera de este batch.
- **MOP-037 (resto de misceláneos)**: `DashboardInsights::whereYear`, `System\AccountingController`, `@json` en `cart/detail.blade.php`, código HTTP en `EcommerceController::login` y logging en `paymentCashEmail` quedaron fuera del alcance del sub-lote.
