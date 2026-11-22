# Correcciones aplicadas — 2026-11-19

Registro de la auditoría/corrección ejecutada directamente sobre el repositorio.
No se modificó la estructura ni los datos de la base de datos real: las consultas
fueron de lectura y las verificaciones HTTP usaron lecturas o IDs inexistentes.

## Alcance

- Backend Laravel 10 / PHP 8.3 del proyecto FacturadorPro.
- Middleware de acceso, CSRF, controladores de documentos, compras, caja, pagos,
  finanzas, ecommerce, consola y recursos de sistema.
- Frontend: enlaces de login ecommerce y banderas de acciones de documentos.
- No se reescribieron módulos completos ni se cambió el diseño funcional.

## Correcciones

| ID | Área | Archivos | Problema | Corrección | Verificación |
|---|---|---|---|---|---|
| FIX-101 | Seguridad | `app/Support/Access/AccessMap.php`, `app/Http/Middleware/EnforceModuleAccess.php`, `config/permissions.php` | El bypass AJAX permitía alcanzar endpoints de un módulo sin tenerlo; las mutaciones no mapeadas se permitían. | Se valida módulo/nivel antes del bypass AJAX; POST/PUT/DELETE no mapeados se deniegan; reglas para pagos, personas, catálogos, hotel y `api/inventory`. | `AccessMapTest` 13/13; suite completa. |
| FIX-102 | Seguridad | `app/Http/Middleware/RequireCsrfForStateChangingGet.php`, `app/Http/Kernel.php` | Acciones de estado seguían por GET sin CSRF. | Middleware exige `X-CSRF-TOKEN` en acciones de estado; se excluyó el toggle de modo noche, que es un enlace plano. | `StateChangingGetCsrfTest` 4/4; GET de anulación sin cabecera responde 419. |
| FIX-103 | Backend | `app/CoreFacturalo/Facturalo.php` | El request podía inyectar `state_type_id`, `user_id` o `soap_type_id` al crear comprobantes. | Al crear se fuerzan estado `01`, usuario autenticado y empresa activa. | Suite; creación no ejecutada contra SUNAT. |
| FIX-104 | Backend | `app/Http/Controllers/Tenant/DocumentController.php`, `app/Http/Controllers/Tenant/Api/DocumentController.php` | Borrado, recreación, envío y cambio de estado sin guardas completas; API de estado aceptaba cualquier valor. | Guardas de usuario/estado, 404, transición validada y estados permitidos; `destroyDocument` revierte por providers antes de borrar. | Suite; IDs inexistentes 404/422. |
| FIX-105 | Backend | `app/Http/Controllers/Tenant/PurchaseController.php` | Anulación no idempotente, borrado sin reversión y actualización sin control de estado/propietario. | Transacción + lock + idempotencia; reversión de stock en anulación/borrado; propietario y estado en update; payload sin campos fiscales. | Suite; lint. |
| FIX-106 | Backend | `app/Http/Controllers/Tenant/CashController.php` | Mass assignment, caja de otro usuario, doble apertura y carrera con `cash_document`. | Solo propietario (admin puede gestionar sin cambiar dueño), apertura serializada, cierre/borrado con lock, `cash_document` con lock y validación de documento/nota. | Suite; páginas 200. |
| FIX-107 | Backend | `DocumentPaymentController`, `SaleNotePaymentController`, `PurchasePaymentController` | Pagos editables/borrables por ID y sobre documentos anulados. | Lock del padre, ownership, bloqueo de anulados, `total_canceled` recalculado y `records` con alcance. | Suite; pago con documento inexistente 404. |
| FIX-108 | Backend | `modules/Finance/Traits/FinanceTrait.php` | Nota de crédito restada por cada fila de pago; `global_payments` duplicados al editar/borrar. | Deduplicación por documento y limpieza del global payment anterior. | Suite; lint. |
| FIX-109 | Backend | `app/Services/Payments/CartPricing.php` | Tipo de cambio 0 cotizaba USD a S/ 0. | Se rechaza tipo de cambio <= 0. | Suite. |
| FIX-110 | Backend | `app/CoreFacturalo/Facturalo.php` | Una baja rechazada por SUNAT (99) anulaba documentos. | Solo se anulan documentos si el código es aceptado. | Suite; no se ejecutó SUNAT. |
| FIX-111 | Backend | `modules/Inventory/Providers/InventoryKardexServiceProvider.php` | Kardex duplicado en documentos convertidos de nota de venta/pedido. | No se crea kardex/stock del documento cuando ya existe `sale_note_id`/`order_note_id`. | Suite; pendiente prueba de conversión real. |
| FIX-112 | Backend | `modules/Inventory/Http/Controllers/InventoryController.php` | `regularize_stock` sobrescribía stock sin confirmación. | Vista previa por defecto; aplica solo con `confirm=1`; CSRF. | Suite; lint. |
| FIX-113 | Seguridad | `app/Http/Resources/Tenant/CompanyResource.php` | `companies/record` exponía Clave SOL y certificado. | Se retiraron `soap_password`, `soap_username` y `certificate` del recurso. | Lectura de código; UI que consume nombre/logo no usa esos campos. |
| FIX-114 | Seguridad | `app/Support/Files/SafeTempFile.php`, `System/ClientController`, `DocumentInput`, `modules/Document`, `SaleOpportunityFileController`, `PurchaseOrderController` | `temp_path` y adjuntos sin validar. | Solo archivos reales en `sys_get_temp_dir()`, imágenes verificadas y extensiones permitidas. | Suite; lint. |
| FIX-115 | Seguridad | `modules/Finance/Http/Controllers/PaymentFileController.php` | Descarga con `type` arbitrario. | Lista blanca y `basename()` del archivo. | Suite; lint. |
| FIX-116 | Seguridad | `app/Http/Requests/Tenant/UserRequest.php`, `System/UserRequest.php`, `Tenant/UserController.php` | Contraseña vacía aceptada al crear/editar. | Contraseña obligatoria al crear; edición solo con valor no vacío. | Suite; lint. |
| FIX-117 | Operación | `app/Console/Kernel.php`, `app/Console/Commands/TenantCommand.php`, `ValidateDocumentsCommand.php` | `tenancy:run` inexistente, `Artisan::call` con dato libre y `goto` sin tope. | Schedule `tenant:run`, recorrido de tenants, whitelist de comandos y máximo 3 reintentos. | `php artisan tenant:run` OK; suite. |
| FIX-118 | Operación | `System/ClientController.php` | Mes 0 en enero, `temp_path` de certificado y alta parcial de tenant. | Cálculo de mes con año anterior; ruta temporal validada; limpieza de website/hostname/client si falla la inicialización del tenant. | Suite; lint. |
| FIX-119 | Frontend/Backend | `modules/Ecommerce/Http/Controllers/EcommerceController.php`, vistas ecommerce | `/ecommerce/login` devolvía 500 y el constructor rompía `route:list`. | Redirección al store, enlaces al modal y consulta solo fuera de consola. | `/ecommerce/login` 302; `/ecommerce` 200; `route:list` OK. |
| FIX-120 | Calidad | `.github/workflows/ci.yml`, `composer.json`, `tests/Unit/StateChangingGetCsrfTest.php` | Sin CI ni prueba de CSRF en GET. | CI de lint + Unit, scripts `composer test:unit`/`lint` y prueba del middleware. | 342 tests / 2119 aserciones; lint 0 errores. |

## Pruebas ejecutadas

- `php vendor/bin/phpunit --testsuite Unit`: **342 tests, 2119 assertions, 0 fallos**.
- Lint PHP de `app`, `modules`, `routes`, `config`, `tests`, `bootstrap`: **0 errores**.
- HTTP tenant: `/dashboard`, `/documents`, `/purchases`, `/cash`,
  `/finances/to-pay`, `/finances/unpaid`, `/ecommerce` → **200**.
- `/ecommerce/login` → **302**; `route:list` → OK; `tenant:run` → OK.
- `DELETE /documents/delete_document/{id}` sin CSRF → 419; con CSRF e ID inexistente → 404.
- Base de datos: solo lectura; no se ejecutaron migraciones, ALTER, INSERT, UPDATE ni DELETE.

## Pendientes conocidos (no cerrados en esta tanda)

1. **SYS-002 — `route:cache` y rutas de tenant.** Verificado: con `route:cache`
   el tenant recibe 404 en `/documents`; al limpiar cache vuelve 200. Las rutas
   de tenant se registran condicionalmente por hostname y no entran en el cache.
   Requiere rediseño del registro de rutas, no un parche local.
2. **SYS-008 — atomicidad del alta de tenant.** Se añadió limpieza de
   website/hostname/client si falla la inicialización, pero `system` y `tenant`
   son conexiones distintas; no hay transacción distribuida.
3. **Rutas públicas de impresión** (`/print/document`, `/purchases/print`,
   `/sale-notes/print`, etc.). Se mantienen para no romper enlaces enviados por
   correo; requieren URLs firmadas o autenticación.
4. **SYS-005 — API.** Solo se añadió regla para `api/inventory`; el resto de la
   API sigue dependiendo de `auth:api` y no del mapa de módulos.
5. **Pruebas de escritura reales**: emisión, anulación, cierre de caja,
   conversión de nota de venta, SUNAT/OSE/Culqi. No ejecutadas.
6. **Deuda estructural**: `Facturalo` y controladores extensos, y las dos capas
   de stock (`items.stock` y `item_warehouse.stock`). No se refactorizaron.

## Estado

Los cambios están en el working tree, **sin commit, sin push y sin despliegue**.
La base de datos real no fue modificada.

## Ampliación (cierre de regresiones del ledger)

- **FIX-121 — Acceso por método/perfil.** `AccessMap` soporta `methods` y
  `module_levels`; un usuario con solo `pos` puede emitir documentos/notas y
  registrar pagos; `GET documents/records` exige `list_document`. Pruebas en
  `tests/Unit/AccessMapTest.php`.
- **FIX-122 — CSRF en GET/HEAD.** El middleware cubre `HEAD` y se añadieron
  `items/enable|disable`, `persons/enabled`, `addSeeder`, `client_payments`,
  `contracts/changed|generate-quotation`, `documents/regularize-lots|consult_cdr`,
  `padron/*` y `purchase-orders/*`. Prueba `test_head_cannot_bypass_csrf`.
- **FIX-123 — Padrón y uploads.** `padron` exige módulo `configuration` y CSRF;
  `companies/uploads` exige `configuration`.
- **FIX-124 — XML/fechas/letras.** Contraseña WS escapada, campos X509
  `issuer/validFrom/validTo` corregidos y `NumberLetter` normaliza a 2 decimales.
- **FIX-125 — Identificadores al editar.** `PurchaseOrder`, `Contract` y
  `OrderNote` preservan `external_id` y estado; ítems de pedido quedan ligados a
  su nota.
- **FIX-126 — Comandos de servidor.** `QueryAllServerCommand` agrupa el `orWhere`;
  `SendAllSunatCommand` solo marca éxito si `send()` devuelve `success`.
- **FIX-127 — Caja cerrada.** `CashController@store` rechaza editar una caja
  cerrada.
- **FIX-128 — Finanzas y excepciones.** `getSumByPMT` deduplica notas de crédito;
  se añadieron `use Exception;` en los controladores/validaciones que faltaban;
  PEM de tenant por RUC y `subdomain` con `alpha_dash`.

Verificación final de esta ampliación: **345 tests, 2132 assertions, 0 fallos**;
lint PHP 0 errores; páginas HTTP 200.
