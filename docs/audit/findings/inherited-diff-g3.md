# Revisión independiente del diff — Grupo 3 (`modules/*`)

Rama `chore/system-audit`, comparación árbol de trabajo frente a `HEAD`. Solo lectura; no se ejecutaron pruebas, base de datos, HTTP ni migraciones. Fuentes de referencia: `docs/audit/AUDIT_LOG.md` (FIX-001…FIX-025) y `docs/audit/fixes-batch1.md` (FIX-003…FIX-010).

## 1. Resumen

- 46 rutas de la lista (43 PHP/Blade + 3 Vue ignoradas por indicación). `php -l` sobre los 41 PHP existentes (incluido el no versionado `modules/Finance/Helpers/PaymentFileHelper.php`): 0 errores de sintaxis (PHP del host). Los dos archivos eliminados se leyeron con `git show HEAD:`.
- Mapeo de hunks: **todos los hunks de los 43 archivos están explicados por una entrada FIX** (FIX-002…FIX-019). No encontré hunks funcionales heredados o sin registrar, salvo uno menor sin registro: la eliminación de los métodos vacíos `PadronController::index()` y `create()` (IDG3-011, Info).
- No hay referencias colgantes de los dos archivos eliminados (ver sección 4).
- Ninguna ruta del storefront de Ecommerce dejó de existir ni cambió de método; solo se añadió `throttle` a cuatro POST y se renombró el nombre de ruta del POST de login (sección 6).
- Hallazgos nuevos: 0 Crítico, 0 Alto, 2 Medio, 5 Bajo, 4 Info. Los dos Medio son riesgos que ya existían en Padron y que FIX-002 no cierra; no son regresiones del diff.

## 2. Tabla de archivos

Leyenda de riesgo residual: B = bajo, M = medio, — = sin riesgo nuevo.

| Archivo | Hunks cubiertos por FIX | Hunks heredados / no registrados | Riesgo | Veredicto |
|---|---|---|---|---|
| Account/AccountController.php | FIX-007 (validación, `getDocuments` por rama, `deleteFileAfterSend`, `optional`, `default: abort`) | ninguno | B | OK. Tipos del formulario (`export.vue`) coinciden con el `in:` |
| Account/CompanyAccountController.php | FIX-007 (`firstOrFail`/`findOrFail`) | ninguno | — | OK. La fila se siembra en la migración `2019_08_01_110045` |
| Account/SummaryReportController.php | FIX-007 | ninguno | — | OK |
| Account/.../excel_concar_bk.blade.php (eliminado) | FIX-010 | ninguno | — | OK, sin referencias |
| Dashboard/DashboardData.php | FIX-003 | ninguno | B | OK; paridad numérica revisada a mano (ver sección 3) |
| Dashboard/DashboardStock.php | FIX-003 | ninguno | — | OK |
| Dashboard/DashboardView.php | FIX-010 (−208 líneas `getUnpaid`) | ninguno | — | OK |
| Dashboard/DashboardController.php | FIX-003, FIX-010 | ninguno | — | OK; sin usos residuales de los imports retirados |
| Document/ValidateDocumentController.php | FIX-019 | ninguno | B | OK; ver IDG3-005 |
| Document/SearchTrait.php | FIX-016 | ninguno | — | OK |
| DocumentaryProcedure/* (5 controladores) | FIX-006 | ninguno | — | OK |
| DocumentaryProcedure/FileRequest.php | FIX-006 | ninguno | B | OK; ver IDG3-006 |
| Ecommerce/ConfigurationController.php | FIX-009 | ninguno | — | OK; ver IDG3-009 (Info) |
| Ecommerce/EcommerceController.php | FIX-009, FIX-014 (`regenerate()`, `api_token = null`) | ninguno | B | OK |
| Ecommerce/FeaturedProductsViewComposer.php | FIX-009 | ninguno | B | OK; relaciones `currency_type` y `tags` existen en `Item` |
| Ecommerce/TakeProductoViewComposer.php (eliminado) | FIX-010 | ninguno | — | OK, sin referencias |
| Ecommerce/Routes/web.php | FIX-009 | ninguno | B | OK; ver IDG3-004 |
| Finance/ToPay.php | FIX-016 | ninguno | — | OK; el cliente maneja `expense` en el `else` |
| Finance/PaymentFileController.php | FIX-013 | ninguno | B | OK |
| Finance/PaymentFileHelper.php (nuevo) | FIX-013 | ninguno | B | OK; ver IDG3-010 |
| Finance/FilePaymentTrait.php | FIX-013 | ninguno | — | OK |
| Finance/ToPayController.php, UnpaidController.php | FIX-017 | ninguno | — | OK |
| Hotel/HotelRentController.php | FIX-005 | ninguno | B | OK; ver IDG3-003 |
| Hotel/HotelRoomController.php | FIX-005 | ninguno | B | OK; ver IDG3-003 |
| Hotel/HotelReceptionController.php | FIX-005, FIX-016 | ninguno | — | OK |
| Hotel/HotelCategory/Floor/RateController.php | FIX-005 | ninguno | — | OK |
| Hotel/HotelAddRateToRoomRequest.php | FIX-005, FIX-016 | ninguno | — | OK; el parámetro de ruta se llama `id` (verificado en `Routes/web.php`) |
| Inventory/InventoryVoidedServiceProvider.php | FIX-018 | ninguno | — | OK |
| LevelAccess/Routes/web.php | FIX-010 | ninguno | — | OK (Info, IDG3-011) |
| Offline/OfflineConfigurationController.php, Request | FIX-008 | ninguno | — | OK; fila sembrada por migración |
| Order/OrderNoteController.php | FIX-018 | ninguno | — | OK |
| Padron/PadronController.php | FIX-002 | eliminación de `index()`/`create()` vacíos (sin registro) | M | Ver IDG3-001/002 y sección 5 |
| Padron/Routes/web.php | FIX-002 | ninguno | M | Ver sección 5 |
| Sale/ContractController.php | FIX-017 | ninguno | — | OK |
| Services/ServiceData.php | FIX-004 | ninguno | — | OK; ver nota de comportamiento en «Sin hallazgos» |

## 3. Hallazgos

### IDG3-001 — Medio — Descarga del padrón por HTTP en claro, sin verificación TLS, extraída bajo `public/` donde nginx ejecuta `.php`
- Ubicación: `modules/Padron/Http/Controllers/PadronController.php:73` (`http://www2.sunat.gob.pe/...`), `:80` (`CURLOPT_SSL_VERIFYPEER` = false), `:97-98` (`extract` hacia `public/padron_extract/`); `docker/nginx/default.conf:49` (`location ~ \.php$` pasa a FPM cualquier `.php` bajo el docroot; solo se bloquea `/storage/*.php`, línea 23).
- Escenario: un atacante en ruta (MITM) entre el servidor y `www2.sunat.gob.pe` sustituye el ZIP por uno que contenga `x.php`. `ZipArchive::extractTo` lo deposita en `public/padron_extract/x.php` y es invocable por URL. Tampoco hay comprobación de contenido: el `.txt` falso se cargaría con `LOAD DATA`. Se requiere un usuario autenticado que dispare `charges_data` (o que alguien lo haga por CSRF, ver IDG3-002).
- Estado: heredado y no modificado; FIX-002 añadió timeouts y orden, no transporte. No es regresión.
- Acción recomendada: usar HTTPS con verificación, o validar hash/lista de miembros; extraer a `storage/app` (fuera del docroot) y validar que solo exista `padron_reducido_ruc.txt`; denegar `.php` en `/padron_*`.
- Confianza: Media. Verifiqué el código y la configuración nginx; no verifiqué que SUNAT sirva HTTPS ni el contenido real del ZIP.

### IDG3-002 — Medio — `charges_data`/`demo`: GET destructivo, `TRUNCATE` antes de cargar, sin módulo asociado
- Ubicación: `modules/Padron/Routes/web.php:20,22`; `PadronController.php:105` y `:166` (`truncate()`), `:126` y `:187` (`LOAD DATA LOCAL INFILE`).
- Escenario (a): `truncate()` hace commit implícito en MariaDB y no es transaccional. Si `LOAD DATA` falla después (permiso `LOCAL INFILE`, archivo corrupto, timeout), la tabla `padrones` queda vacía. La fila de historial `ChargePadron` queda con `state = 0` y la respuesta es genérica.
- Escenario (b): las rutas están bajo `auth` + `locked.tenant`, pero no bajo `redirect.module`, y `padron` no figura en `config/permissions.php` (verificado: no hay regla). `AccessMap` devuelve `allowed/unmapped`. Cualquier usuario autenticado del tenant (vendedor sin módulos) puede disparar descarga + truncado. Con `same_site = lax` (config/session.php:195) una navegación GET de nivel superior desde otro sitio envía la cookie, por lo que se puede disparar por CSRF mediante enlace/redirección (límite `throttle:2,1`, ver IDG3-004).
- Escenario (c): si el ZIP nuevo no trae `padron_reducido_ruc.txt` pero existe uno anterior en `padron_extract/`, `is_file` pasa y se carga el archivo viejo.
- Estado: el truncado ya existía; FIX-002 solo lo movió después de validar descarga/archivo. La auditoría ya planifica POST + CSRF + importación escalonada transaccional (no se implementa aquí).
- Acción recomendada: POST + CSRF, regla de permiso (`'module' => 'configuration'` o similar), carga a tabla temporal y `RENAME`, borrar el `.txt` previo antes de extraer.
- Confianza: Alta en (a) y (b) por lectura directa; Media en (c).

### IDG3-003 — Bajo — Estado de habitación sin guarda de transición ni bloqueo; productos agregables a rentas finalizadas
- Ubicación: `modules/Hotel/Http/Controllers/HotelRoomController.php:126-141` (`changeRoomStatus`); `HotelRentController.php:109-128` (`addProductsToRoom`).
- Escenario: FIX-005 validó solo que `status` pertenezca a `HotelRoom::$status`. Una petición manual puede poner `DISPONIBLE` a una habitación `OCUPADO` con renta activa y `store()` (que sí bloquea la fila) permitiría una segunda renta. La UI solo ofrece `DISPONIBLE` desde `LIMPIEZA`/`MANTENIMIENTO`, de modo que es un problema de servidor, no de uso normal. `addProductsToRoom` usa transacción pero no `lockForUpdate` ni comprueba `status === 'FINALIZADO'`, por lo que se pueden añadir líneas tras el checkout.
- Estado: no es regresión (antes ni siquiera se validaba el enum). Requiere un usuario con módulo `hotels`.
- Acción recomendada: limitar transiciones permitidas (no salir de `OCUPADO` salvo finalizar la renta), bloquear la habitación en `changeRoomStatus`, rechazar productos si la renta está finalizada.
- Confianza: Alta en el código; no se probó el flujo.

### IDG3-004 — Bajo — Los `throttle:N,M` numéricos comparten un único contador por usuario/IP
- Ubicación: `modules/Ecommerce/Routes/web.php:27,28,36,38`; `modules/Padron/Routes/web.php:19,20,22`. Evidencia: `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:95` (clave = `$prefix` vacío + firma) y `:200-209` (firma = id de usuario, o `dominio|IP` si es invitado). No hay prefijo por ruta.
- Escenario: en Padron, `consulta` (30/min), `charges_data` y `demo` (2/min) comparten contador; dos llamadas de `consulta` en un minuto ya bloquean `charges_data`. En Ecommerce, `login`, `storeUser` (5/min), `culqi` y `payment_cash` (10/min) comparten contador por IP de invitado: 5 intentos de login o registro desde una misma IP (CGNAT, oficina) bloquean el login y registro de todos los demás en esa IP durante un minuto. Si hay un proxy inverso delante, `TrustProxies::$proxies` es `null` (`app/Http/Middleware/TrustProxies.php:15`) y todos los clientes comparten una IP, con el mismo efecto a escala de tienda. El `error:` de los formularios (`layouts/partials_ecommerce/footer.blade.php:267-285`) solo hace `console.log`, de modo que un 429 se ve como «no pasa nada».
- Estado: introducido por FIX-002/FIX-009. No lo compensa ninguna prueba.
- Acción recomendada: usar prefijos o limitadores nombrados (`RateLimiter::for`) por ruta y, para login, por `email|IP`; configurar `TrustProxies` si hay proxy; mostrar el mensaje 429 en el formulario.
- Confianza: Alta para el mecanismo (código de vendor leído); la exposición real depende del despliegue, que no verifiqué (`docker-compose.yml` no define proxy).

### IDG3-005 — Bajo — `records` y `regularize` no heredan `tenant.internal.mode`
- Ubicación: `modules/Document/Routes/web.php:44-47`: solo `GET ''` lleva `->middleware('tenant.internal.mode')`; `records` y `POST regularize` no. El middleware se resuelve con `Company::first()->soap_type_id == '03'` → 403 (`app/Http/Middleware/InternalModeTenant.php`).
- Escenario: en un tenant en modo interno (soap `03`), la página está bloqueada pero un POST directo a `reports/validate-documents/regularize` consulta SUNAT y puede reescribir `state_type_id` (con `save()` y eventos de stock tras FIX-019). La ruta exige módulo `reports` (`permissions.php:132`).
- Estado: heredado; FIX-019 no cambió las rutas. No es regresión.
- Acción recomendada: aplicar el middleware al grupo completo.
- Confianza: Alta en la lectura; el efecto en datos no se probó.

### IDG3-006 — Bajo — `mimes:` de FileRequest depende de `guessExtension()`; nombre original perdido
- Ubicación: `modules/DocumentaryProcedure/Http/Requests/FileRequest.php:26`; `DocumentaryFileController.php:53-61`.
- Escenario: en Laravel 10 `validateMimes` compara `guessExtension()` (contenido, vía finfo) con la lista (`vendor/.../ValidatesAttributes.php:1508`). Un `.docx`/`.xlsx` que finfo identifique como `application/zip` o `octet-stream` se rechazaría pese a ser legítimo; y `storeFile` guarda `uuid.ext` sin conservar el nombre original, de modo que el usuario ve un UUID al descargar. No hay problema de seguridad: `.php`/`.svg`/`.html` quedan excluidos, y nginx bloquea `.php` en `/storage/`.
- Acción recomendada: probar con un docx/xlsx real de Office y de LibreOffice; si se rechazan, validar también por `getMimeType()`.
- Confianza: Baja a Media (no ejecuté la validación con archivos reales).

### IDG3-007 — Bajo — La interacción entre FIX-018 y la regularización de FIX-019 no reaplica stock
- Ubicación: `modules/Inventory/Providers/InventoryVoidedServiceProvider.php:28-30`, `ValidateDocumentController.php` (mapa `ACEPTADO => '05'`).
- Escenario: un comprobante en `09` (stock revertido al llegar a `09`) que SUNAT reporta como `ACEPTADO` pasa a `05` mediante `regularize`; no hay compensación del stock revertido. FIX-018 declara la reactivación fuera de alcance. Quedo anotado como riesgo funcional heredado, no como defecto del diff.
- Confianza: Media (lectura de ambos flujos, sin ejecución).

### IDG3-008 — Info — `configuration_image/index.vue` enviaría `type=producto_store` y ahora recibiría 422
- Ubicación: `modules/Ecommerce/Resources/assets/js/views/configuration_image/index.vue:19-20` frente a `ConfigurationController.php:93` (`Rule::in(['logo_store'])`).
- Sin impacto observable: `rg` no encuentra ninguna referencia al componente (no está registrado en `app.js`, ni en vistas). Antes del cambio ese `type` habría sobrescrito `config->logo`, es decir, el defecto previo era peor.

### IDG3-009 — Info — Memoización estática del tipo de cambio
- `FeaturedProductsViewComposer.php:13,59-65`: la caché `static` por fecha es segura en FPM (vida de petición). En un proceso persistente (Octane, worker) conservaría un `1` de contingencia durante todo el día si el servicio de tipo de cambio falló una vez.

### IDG3-010 — Info — Dependencias operativas de `PaymentFileHelper`
- `modules/Finance/Helpers/PaymentFileHelper.php:30,77`: el registro del adjunto temporal vive en `Cache::store()` por defecto y exige sesión iniciada. Con `CACHE_DRIVER=array` o cachés locales distintas por contenedor FPM, todos los adjuntos darían 422. No verifiqué el `.env` de producción. La ruta `finances/payment-file` sigue con `module => null` en `permissions.php:138` (cualquier usuario autenticado puede subir/descargar; ya conocido como MSL-003 y fuera del alcance de FIX-013).

### IDG3-011 — Info — Hunks menores sin registro o con alcance reducido
- `PadronController`: se eliminaron `index()` y `create()` (cuerpos comentados, sin ruta; la ruta de `index` ya estaba comentada en `HEAD`). No figuran en FIX-002; el hallazgo MOP-031 decía conservarlos. Sin efecto funcional.
- `LevelAccess/Routes/web.php`: ahora `auth` + `locked.tenant`, sin `redirect.module`; la vista es el scaffold «Hello World». Sin impacto.
- `EcommerceController::item`/`partialItem`: un id inexistente devuelve 404 en lugar de error 500.

## 4. Referencias colgantes de archivos eliminados

**No hay referencias colgantes.**

- `excel_concar_bk.blade.php`: los nombres de vista se construyen por literal en `modules/Account/Exports/*` (`excel_concar`, `excel_adsoft`, `excel_foxcont`, `excel_contasis`, `excel_sumerius`, `format_*`); ninguno usa `_bk`. Las plantillas activas siguen en `accounting/templates/` y `account/templates/`. El resto de coincidencias del nombre están en `docs/audit/*`.
- `TakeProductoViewComposer.php`: la única mención de código es `app/Providers/ViewServiceProvider.php:160-163`, dentro de un bloque `/* ... */` (confirmado leyendo las líneas). No hay `View::composer`, ni referencia en `config/`, ni en otros proveedores. Además su namespace declarado (`App\Http\View\Composers`) no correspondía a la ruta de archivo.
- Dashboard: no queda referencia a `DashboardView::getUnpaid`, `Dashboard@unpaid` ni `unpaidall` fuera de líneas comentadas (`modules/Dashboard/Routes/web.php:16-17`). `unpaidall` vivo pertenece a `Finance/UnpaidController` (otra ruta, otra clase). `DashboardView.php` conserva `DB` y `Carbon` que sigue usando `getUnpaidFilterUser`; `DashboardController.php` ya no usa ninguno de los imports retirados (`DB::`, `Document::`, `Arr::`, `Excel::`).

## 5. Estado de Padron (descripción, sin implementar nada)

Cambios del diff (todos FIX-002, salvo la eliminación de métodos vacíos):

- `Routes/web.php`: de rutas públicas `GET padron/{consulta/{ruc}, charges_data, list_history, demo}` sin dominio, auth ni tenant, a `Route::domain($hostname->fqdn)` + `['web','auth','locked.tenant']` con `throttle:30,1` (`consulta`) y `throttle:2,1` (`charges_data`, `demo`). `list_history` sin throttle. No se registran en el dominio de sistema. `web` aparece dos veces (el `RouteServiceProvider` del módulo lo aplica también); Laravel deduplica, es inocuo.
- `consulta`: valida `ctype_digit` + 11 dígitos (la consulta es de solo lectura sobre `padrones`).
- `charges_data`: crea `padron_rar/` y `padron_extract/` bajo `public/`, timeouts cURL 15/120 s, exige HTTP 200 y cuerpo no vacío, valida extracción y existencia del `.txt` antes de `truncate()` + `LOAD DATA LOCAL INFILE`, atrapa `\Throwable` con mensaje genérico.
- `demo`: comprueba que exista `demo.txt` antes del `truncate()`.

Riesgos que subsisten (no son introducidos por el diff): destructivas por **GET** sin CSRF y sin permiso de módulo; `TRUNCATE` previo a la carga y no transaccional; descarga por HTTP sin TLS y extracción dentro del docroot (IDG3-001/002); el `.txt` anterior se reutiliza si el ZIP nuevo no lo trae. No hay `DROP`/`TRUNCATE` en rutas sin autenticación: ese era el riesgo grave original y quedó cerrado. No se encontraron llamadores de estas rutas en `resources/js` ni `modules/*/Resources` (el consumo es externo o inexistente).

## 6. Ecommerce: rutas del storefront

Rutas definidas tras el cambio (todas bajo `locked.tenant`, prefijo `ecommerce`): `/`, `category/{category}`, `item/{id}`, `items`, `item_partial/{id}`, `detail_cart`, `pay_cart`, `login` GET y POST, `logout`, `items_bar`, `storeUser`, `rating_item` POST/GET, `cart_quote`, `culqi`, `transaction_finally`, `payment_cash`, `saveDataUser`. Ninguna cambió de método ni ganó `auth`. Cambios reales: `throttle` en `login`, `storeUser`, `culqi`, `payment_cash`; el POST de login pasa a llamarse `tenant_ecommerce_login_post`.

Verificación de consumidores: todos los `route('tenant_ecommerce_*')`, `route('tenant.ecommerce.*')`, `route('item_partial')` y `route('tenant_detail_cart')` usados en `modules/Ecommerce/Resources/views` existen. El login AJAX (`footer.blade.php:267`) usa `route('tenant_ecommerce_login')`, que resuelve a `/ecommerce/login` (mismo URI para GET y POST), por lo que sigue funcionando. `public/porto-ecommerce/assets/js/rating.js` llama a `/ecommerce/rating_item` (existe); descarta la respuesta, así que el cambio de `success: false` a `true` no tiene efecto. `route('logout')` de los parciales es una ruta ajena al módulo. Las rutas de configuración (`auth` + `redirect.module`) están mapeadas en `permissions.php:64-69`. La única consecuencia observable es el límite de tasa (IDG3-004).

## 7. Archivos sin hallazgos

Account (3), `DashboardData/Stock/View/Controller`, `SearchTrait`, `ToPay`, `PaymentFileController`, `FilePaymentTrait`, `ToPayController`, `UnpaidController`, `HotelCategory/Floor/Rate/Reception` controllers, `HotelAddRateToRoomRequest`, `InventoryVoidedServiceProvider`, Offline (2), `OrderNoteController`, `ContractController`, `ServiceData`, los cinco controladores y la Request de DocumentaryProcedure (salvo IDG3-006).

Notas de comportamiento verificadas, sin defecto:
- `ServiceData`: el `=!` original dejaba siempre la URL/token configurados; el fallback a `config('configuration.api_service_*')` ahora se activa cuando están vacíos (cambio intencional de FIX-004).
- Dashboard: confirmé a mano que los totales de documentos y notas de venta se calculan con las mismas condiciones de estado/moneda/tipo; el `whereIn('state_type_id', ...)` añadido en SQL duplica un filtro que ya existía en memoria. Lo eliminado eran acumuladores de pagos que nunca se devolvían.
- `HotelRoomController::update` (`HotelRoomRequest`): el formulario `AddEdit.vue` envía `name`, `hotel_category_id`, `hotel_floor_id`, `description` y `active`; coincide con las reglas.
- Los usuarios de ToPay/Unpaid/Contract (`id`, `name`) conservan la forma que consumen las vistas.

## 8. No verificado

- Ninguna ejecución: sin pruebas unitarias/UI, sin HTTP, sin base de datos (MariaDB). Los argumentos sobre bloqueos, `TRUNCATE` implícito y planes de consulta se basan en lectura de código.
- `FileRequest`: comportamiento de `guessExtension()` con docx/xlsx reales (IDG3-006).
- Que SUNAT sirva el padrón por HTTPS y el contenido real del ZIP (IDG3-001).
- Valores de `.env` de producción (driver de caché, proxy, `SESSION_SAME_SITE`); no se leyó `.env` por instrucción.
- Existencia de proxy inverso delante de nginx (IDG3-004).
- Que `HotelRoom::$with` o scopes globales no alteren `lockForUpdate()->findOrFail`.
- Comportamiento de los `.vue` ignorados por indicación (payments de Expense/Purchase, Checkout de Hotel).
