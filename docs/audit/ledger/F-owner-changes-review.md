# F. Revisión independiente de los cambios del propietario (paralelos a la auditoría)

Rama `chore/system-audit`, HEAD `2064950`, árbol de trabajo sin confirmar. Revisión adversarial, solo lectura.
Alcance: control de acceso por módulo/nivel, CSRF para GET con efectos, CI, proveedores eliminados y los demás cambios del propietario.

## Resumen

- Las pruebas existentes pasan: suite `tests/Unit` completa 342/342 (2119 aserciones, 2 deprecaciones de PHPUnit), también con la BD inalcanzable y sin `APP_KEY`. Pruebas Node de `tests/ui/*.test.js`: 9/9 OK. `php -l` limpio sobre `app modules routes config database tests bootstrap`.
- La dirección es correcta: modelo de acceso centralizado, fail-closed para mutaciones sin regla, GET con efectos protegidos por token, endurecimiento de pagos/caja/compras con bloqueo de filas y reglas de propiedad.
- Pero el modelo de acceso **rompe flujos legítimos** y **deja huecos**. Los hallazgos más importantes:
  1. `GET /companies/record` queda abierto a cualquier usuario autenticado y devuelve `soap_password` (Clave SOL) en claro (OWN-001).
  2. Un usuario con solo el módulo `pos` (cajero) recibe 403 al emitir desde el POS, y un usuario con el nivel `new_document` pero sin `list_document` no puede guardar un comprobante (OWN-002, OWN-003).
  3. `padron/charges_data` y `padron/demo` (TRUNCATE de `padrones`) son accesibles a cualquier usuario autenticado, por GET, sin token CSRF (OWN-004).
  4. La API móvil (`auth:api`) no pasa por `EnforceModuleAccess` (OWN-007).
  5. La lista de GET con CSRF es una lista negra incompleta y se puede esquivar con `HEAD` (OWN-005, OWN-006).
- Proveedores eliminados: **no quedan referencias colgantes** y su comportamiento ya estaba comentado (código muerto). Ver sección dedicada.
- CI: funciona en lo esencial (verificado por simulación), pero faltan permisos mínimos, deduplicación de disparadores y el job de Node (OWN-013).

Conteo por severidad: Crítico 0, Alto 4 (OWN-001 a 004), Medio 5 (OWN-005 a 009), Bajo 6 (OWN-010 a 015), Info 2 (OWN-016, 017): 17 hallazgos.

## Archivos revisados

| Archivo | Tipo de cambio | Riesgo | Veredicto |
|---|---|---|---|
| `app/Support/Access/AccessMap.php` | Funcionalidad nueva + corrección (AJAX tras comprobar módulo; mutación no mapeada denegada) | Alto | Bien orientado; reglas incompletas (OWN-002/003/008) |
| `app/Http/Middleware/EnforceModuleAccess.php` | Nuevo (alias de `redirect.module`/`redirect.level`) | Alto | Aceptable; 3 consultas por petición, se ejecuta dos veces en algunas rutas (OWN-011) |
| `config/permissions.php` | Mapa de rutas a módulo/nivel | Alto | Cobertura de módulos completa; huecos y reglas abiertas (OWN-001/008/009) |
| `app/Http/Middleware/Authenticate.php` | Endurecimiento (bloquea `type=client` en rutas `auth`) | Medio | Correcto; aplica también a `auth:api` |
| `app/Http/Middleware/RequireCsrfForStateChangingGet.php` (nuevo) | Endurecimiento | Alto | Útil pero incompleto y eludible por `HEAD` (OWN-005/006/012) |
| `app/Http/Kernel.php` | Registro del middleware en el grupo `web` | Bajo | Correcto |
| `.github/workflows/ci.yml` (nuevo) | CI | Bajo | Funciona; mejoras mínimas (OWN-013) |
| `app/Providers/Inventory{,Kardex,Anulation}ServiceProvider.php`, `config/app.php` | Eliminación de código muerto | Bajo | Sin referencias colgantes |
| `app/Http/Controllers/Tenant/{Dispatch2,Inventory,Inventory2}Controller.php` | Eliminación (no enrutados en HEAD) | Bajo | Correcto |
| `modules/Padron/{Routes,Http/Controllers}` | Ahora exige `auth`+`locked.tenant`, `throttle`, validación RUC | Alto | Mejora grande; sigue sin control de módulo (OWN-004) |
| `routes/api.php` | `documents/search/customers`, `documents/status`, `sendserver`, `generateDispatch` movidas a `auth:api`; rutas muertas `validate_cpe`/`consult_status` eliminadas | Medio | Endurecimiento correcto; posible rotura de integraciones sin token (verificar) |
| `routes/web.php` | `config('app.url_base')`, `LockedAdmin` fuera del login de sistema | Bajo | Correcto |
| `config/session.php` | `same_site` pasa de `null` a `lax` | Medio | Correcto; mitiga CSRF por `<img>`/fetch, no por navegación superior |
| `docker/nginx/default.conf` | Bloqueo de `.php` en `/storage` y CSP en SVG | Bajo | Correcto (ver orden de `location`) |
| `app/Http/Controllers/Tenant/CashController.php`, `Models/Tenant/Cash.php` | Corrección + endurecimiento (transacciones, apertura única, propiedad) | Alto (dinero) | Bien; OWN-015 |
| `Tenant/{DocumentPayment,SaleNotePayment}Controller`, `Purchase/PurchasePaymentController` | Endurecimiento (bloqueo, estado anulado, `except` de campos) | Alto (dinero) | Bien |
| `Tenant/PurchaseController.php` | Corrección (anulación idempotente, stock, propiedad) | Alto (inventario) | Bien; sin doble reversión (no hay observador de `Purchase`) |
| `Tenant/DocumentController.php`, `Models/Tenant/Document.php` | Endurecimiento (estados permitidos, propiedad, banderas de configuración) | Alto (emisión) | Bien; las banderas existen en `config/tenant.php` con valor por defecto `false` |
| `app/CoreFacturalo/{Facturalo,WS/Reader/DomCdrReader,WS/Response/CdrResponse,WS/Services/BaseSunat}.php` | Endurecimiento (aceptación exige CDR válido, `LIBXML_NONET`, código SOAP estricto) | Alto (SUNAT) | Bien; sin hallazgos |
| `Providers/AnulationServiceProvider.php`, `modules/Inventory/Providers/InventoryVoidedServiceProvider.php` | Corrección (idempotencia: solo si `state_type_id` cambia) | Medio | Correcto (`isDirty` es válido dentro de `updated`) |
| `app/Exceptions/Handler.php` | Endurecimiento (oculta mensajes internos en producción) | Medio | Bien; efecto lateral OWN-014 |
| `System/{Backup,Certificate,Client,Service}Controller`, `Api/ResellerController` | Endurecimiento (mensajes genéricos, validación booleana) | Bajo | Bien |
| `Tenant/CertificateController.php` | Corrección + borrado físico del PEM al eliminar | Medio | OWN-016 |
| `Tenant/CompanyController.php`, `Tenant/ConfigurationController.php` | Endurecimiento (lista blanca de subidas, `findOrFail`, plantillas preimpresas) | Medio | Bien |
| `Tenant/{Person,PurchaseSunatValidation,Quotation,Task}Controller`, `Tenant/DownloadController.php` | Endurecimiento (mapa de modelos, propiedad, estados) | Medio | Bien; OWN-014 |
| `modules/Finance/{PaymentFileController,Helpers/PaymentFileHelper (nuevo),Traits/FilePaymentTrait}`, `ToPay`, `IncomeController` | Endurecimiento (adjuntos temporales validados; filtros de usuario corregidos) | Alto | Bien; OWN-009 |
| `modules/{Expense,Order,Purchase,Sale,Report,Hotel,Dashboard,...}` | Corrección/refactor (`whereTypeUser`, `findOrFail`, eliminación de `getUnpaid` sin llamadas) | Bajo-Medio | Sin hallazgos nuevos |
| `app/Services/Payments/CulqiCharger.php`, `CulqiErrorPolyfills.php` (nuevo), `composer.json/lock` | Dependencia `culqi/culqi-php` retirada; el cargador usa Guzzle de respaldo | Medio | `composer validate` OK; `require_once` del polyfill en `CulqiCharger.php:5` |
| `app/Http/Controllers/Tenant/ItemController.php` | Endurecimiento (columnas permitidas, imágenes temporales validadas, transacción) | Medio | Bien; archivos escritos dentro de la transacción pueden quedar huérfanos al revertir (Info) |

## Hallazgos

### OWN-001 — Alto — `GET /companies/record` abierto expone la Clave SOL
- Dónde: `config/permissions.php:190` (`companies/record` con `module => null`); `app/Http/Resources/Tenant/CompanyResource.php:27-28` (`soap_username`, `soap_password`); `app/Models/Tenant/Company.php:19` (`soap_password` sin cifrado ni `hidden`).
- Escenario: un cajero con solo el módulo `pos` abre `/companies/record` (o llama por AJAX) y obtiene usuario y contraseña SOL de SUNAT en texto claro, además de certificado, cuenta de detracción y `*_client_id`. Con eso puede operar en SUNAT fuera del sistema.
- Causa: la regla abierta se creó porque el dashboard (`modules/Dashboard/.../index.vue:1055`) y el formulario de documentos (`documents/partials/options.vue:166`) leen la empresa; se abrió el recurso completo en lugar de un recurso reducido.
- Acción mínima: devolver un recurso público reducido (nombre, RUC, logos, `config_system_env`) para los consumidores compartidos y dejar el recurso completo bajo el módulo `configuration`; o quitar `soap_password` del recurso y cifrarlo como ya se hace con `gre_client_secret`.
- Confianza: alta (lectura directa de la regla, del recurso y del modelo; no se ejecutó la petición contra un tenant real).

### OWN-002 — Alto — Un usuario solo con el módulo `pos` no puede emitir desde el POS (regresión funcional)
- Dónde: `resources/js/views/tenant/pos/partials/payment.vue:883,925,938` (POST a `/documents` o `/sale-notes`, `/cash/cash_document`, `/document_payments` o `/sale_note_payments`); `config/permissions.php:38-42,47,76`.
- Verificado con `AccessMap::decide` (usuario `['pos']`, AJAX): `POST documents`, `POST sale-notes`, `POST document_payments`, `POST sale_note_payments`, `POST persons`, `GET persons/customers`, `GET items/1`, `GET documents/tables` resultan DENEGADOS (`module`); solo `pos/*` y `cash/cash_document` pasan.
- Escenario: perfil de cajero con el módulo `pos` únicamente: la pantalla carga, pero cobrar devuelve 403 (la boleta no se emite). Antes del cambio esas rutas no tenían control de módulo.
- Acción mínima: decidir la regla de negocio. Opciones: (a) que el módulo `pos` conceda explícitamente `POST documents|sale-notes|document_payments|sale_note_payments|persons` (reglas con lista de métodos), o (b) documentar que el cajero necesita además `documents`/`persons` y validarlo en el formulario de usuarios. Añadir pruebas por perfil (cajero, vendedor, compras).
- Confianza: media-alta en el comportamiento del mapa (ejecutado); media en el impacto real (depende de cómo se asignan módulos en producción).

### OWN-003 — Alto — La regla exacta `documents` (nivel `list_document`) se aplica a todos los métodos y no protege los datos del listado
- Dónde: `config/permissions.php:38` (`documents`, `exact`, `level => list_document`); `app/Support/Access/AccessMap.php:64-80` (sin distinción de método).
- Verificado: con `['documents']` y nivel `new_document` únicamente: `POST documents` (guardar comprobante, ruta `routes/web.php:207`) => DENEGADO por nivel, mientras `GET documents/records` y `GET documents/1/edit` => PERMITIDOS.
- Escenario A: un usuario autorizado a "Nuevo comprobante" ve el formulario pero no puede emitir (403 al guardar). Escenario B: un usuario sin el nivel "Listado de comprobantes" lee igualmente todas las ventas con `GET /documents/records` (el nivel solo oculta la página HTML).
- Acción mínima: añadir una regla `documents` con `methods: ['POST']` y nivel `new_document` antes de la exacta, y una regla para `documents/records|columns|tables` con nivel `list_document`. Soportar `methods` en `AccessMap::resolve`.
- Confianza: alta (reproducido con el mapa real).

### OWN-004 — Alto — `padron/charges_data` y `padron/demo` hacen TRUNCATE y carga masiva por GET, sin control de módulo ni CSRF
- Dónde: `modules/Padron/Routes/web.php:20,22`; `modules/Padron/Http/Controllers/PadronController.php:105,166` (`padrones` truncada); ruta `['web','auth','locked.tenant']` sin `redirect.module` (por eso `EnforceModuleAccess` no se ejecuta); `RequireCsrfForStateChangingGet.php` no la incluye.
- Escenario: cualquier usuario autenticado (no `client`) abre `/padron/charges_data` (o lo incrusta en un enlace/imagen de otro sitio mismo-sitio): se descargan ~100 MB desde `http://www2.sunat.gob.pe` con `CURLOPT_SSL_VERIFYPEER=false` (`PadronController.php`, bloque de cURL) y se reemplaza la tabla de RUC. Limitado a 2 peticiones/minuto por `throttle:2,1`, pero igualmente destructivo y con coste. El contenido llega por HTTP sin verificación: quien intercepte la descarga controla datos que luego se cargan con `LOAD DATA LOCAL INFILE`.
- Acción mínima: pasar ambas a `POST`, restringirlas a propietario/módulo `configuration` (añadir regla `padron` en `permissions.php` y `redirect.module`), usar `https` con verificación de certificado, y mientras tanto añadir los patrones a `RequireCsrfForStateChangingGet`.
- Confianza: alta (lectura de rutas y controlador; la carga no se ejecutó por restricción de la revisión).

### OWN-005 — Medio — La lista de GET con CSRF es una lista negra incompleta
Verificado con volcado estático de rutas (1101 rutas, sin tocar BD). GET con efectos que no están en `PATTERNS` (`RequireCsrfForStateChangingGet.php:17-47`):
- `items/disable/{item}`, `items/enable/{item}` (`routes/web.php:168-169`, cambian `active`).
- `persons/enabled/{type}/{person}` (`routes/web.php:187`).
- `configurations/addSeeder`, `configurations/preprinted/addSeeder` (`routes/web.php:81-82`; hacen `truncate()` de `format_templates` y `preprinted_format_templates`).
- `inventory/regularize_stock` (`modules/Inventory/Routes/web.php:35`; reescribe `item_warehouses.stock` desde el kardex).
- `contracts/changed/{contract}` (`modules/Sale/Routes/web.php:75`, equivalente a `quotations/changed` que sí está protegida), `documents/regularize-lots/{id}`, `documents/consult_cdr/{document}` (`modules/Document/Routes/web.php:25,28`).
- `client_payments/cancel_payment/{id}` (sistema, `routes/web.php:612`; marca el pago y actualiza `account_payments` del tenant).
- `padron/charges_data`, `padron/demo` (OWN-004).
- Escenario: con `SameSite=Lax` (`config/session.php`) una navegación superior (enlace) desde otro sitio envía la cookie y ejecuta la acción; las rutas anteriores no exigen cabecera.
- Acción mínima: convertir estas acciones a POST/PUT (la solución de fondo) o añadirlas a la lista. Para evitar la deriva, aplicar un middleware de ruta (`->middleware('csrf.get')`) en la definición de la ruta en lugar de duplicar patrones.
- Confianza: alta para las verificadas leyendo el controlador (`items/*`, `persons/enabled`, `addSeeder`, `regularize_stock`, `cancel_payment`, `padron/*`); media para `contracts/changed`, `regularize-lots`, `consult_cdr` (análogas, controlador no leído).

### OWN-006 — Medio — `HEAD` elude `RequireCsrfForStateChangingGet`
- Dónde: `RequireCsrfForStateChangingGet.php:51` (`if (!$request->isMethod('GET')) return $next(...)`).
- Reproducido: con token ausente, `GET /cash/close/1` => 419 pero `HEAD /cash/close/1` => llega al controlador (200). Laravel enruta `HEAD` a las rutas `GET`, por lo que la acción se ejecuta (solo se omite el cuerpo).
- Escenario: un origen del mismo sitio (otro subdominio de la misma plataforma multi-tenant) lanza `fetch(url, {method:'HEAD', mode:'no-cors', credentials:'include'})`; `HEAD` es un método seguro de CORS y la cookie `Lax` viaja en peticiones del mismo sitio. No aplica desde un origen de otro sitio.
- Acción mínima: tratar `GET` y `HEAD` igual (`in_array($request->method(), ['GET','HEAD'], true)`) y añadir prueba.
- Confianza: alta (reproducido).

### OWN-007 — Medio — La API móvil/token (`auth:api`) no pasa por `EnforceModuleAccess`
- Dónde: `routes/api.php` (grupo `['auth:api','locked.tenant']`); 62 rutas sin `redirect.*` según el volcado: `POST api/documents`, `api/summaries`, `api/voided`, `api/retentions`, `api/dispatches`, `api/perceptions`, `api/purchases`, `api/item`, `api/items/{id}/update`, `api/person`, `api/sale-note`, `api/order-notes`, etc. Solo `api/inventory/transaction` lleva `redirect.module`.
- Escenario: un usuario sin el nivel `new_document` (o sin el módulo `purchases`) obtiene su `api_token` por `POST api/login` y emite comprobantes, anula o registra compras por API; la web se lo impediría.
- Acción mínima: aplicar el mismo middleware a los grupos API con reglas por prefijo (`api/documents` => `documents`/`new_document`, `api/purchases` => `purchases`, ...), o documentar que la API móvil es de confianza y limitar el token por rol. El token sí rechaza `type=client` (`Authenticate.php`, `MobileController.php:41`).
- Confianza: alta en la ausencia de middleware; media en que sea un defecto y no una decisión de producto.

### OWN-008 — Medio — Rutas sin regla: los GET quedan abiertos y las mutaciones denegadas a todo no-propietario
Volcado estático de las 1001 rutas con `redirect.*` contra `AccessMap`:
- Mutaciones denegadas a TODOS los no-propietarios incluso con todos los módulos (regresión): `POST|DELETE quotation_payments`, `POST|DELETE technical-service-payments`, `POST|DELETE payment-method-types`, `POST|DELETE web-platforms` (módulos `Sale` e `Item`), y `POST api/inventory/transaction`.
- GET abiertos a cualquier usuario autenticado: `quotation_payments/records|document|tables`, `technical-service-payments/records|document|tables`, `payment-method-types/records|record`, `web-platforms*`, `templates`, `command/df` (ejecuta `df` con comando fijo, sin riesgo de inyección).
- Inconsistencia: los pagos de cotizaciones y servicios técnicos se pueden leer sin el módulo `documents` pero no registrar con él. Esto afecta incluso a usuarios con acceso completo que no son el propietario.
- Acción mínima: añadir reglas `quotation_payments` (documents/quotations), `technical-service-payments` (documents/technical-service), `payment-method-types`, `web-platforms` (items), `templates`, `api/inventory` (inventory). Añadir una prueba que cargue las rutas (como el volcado usado aquí) y falle si alguna ruta con `redirect.*` no tiene regla.
- Confianza: alta (ejecutado con el mapa real y las rutas reales).

### OWN-009 — Medio — Reglas `module => null` demasiado amplias
- `companies/uploads` (`config/permissions.php:191`, POST): cualquier usuario autenticado puede reemplazar logo, logo de tienda, favicon y firma de la empresa (`CompanyController::uploadFile` guarda en `company->{type}`).
- `finances/payment-file/download-file/{filename}/{type}` (`permissions.php:143`): cualquier usuario descarga adjuntos de pagos por nombre; los nombres antiguos son predecibles (`slug-tipo-id`), los nuevos usan UUID (`FilePaymentTrait.php`).
- `configurations/record`, `bank_accounts/records`, `banks/records`, `series/records`, etc.: lecturas de catálogos; `series/records` y `bank_accounts/records` revelan datos de negocio a cualquier usuario autenticado.
- Acción mínima: restringir `companies/uploads` a `configuration`; para adjuntos de pagos usar comprobación de propietario del registro; revisar caso por caso `bank_accounts/records`.
- Confianza: alta en la regla; media en el impacto.

### OWN-010 — Bajo — La bandera `ajax` es código muerto y la documentación quedó desactualizada
- `AccessMap.php:112`: tras mover la comprobación después del módulo/nivel, ambas ramas devuelven `allowed => true`; la clave solo cambia el campo `reason`. Las ~60 entradas `'ajax' => true` de `permissions.php` y el comentario de `permissions.php:17-19` ("endpoints ... stay page-level only") ya no describen el comportamiento real. La prueba `test_ajax_is_enforced_only_where_flagged` (`AccessMapTest.php:119`) tiene un nombre engañoso: no demuestra ninguna diferencia por bandera.
- Acción mínima: eliminar la clave `ajax` y el estado `ajax-not-enforced`, o restituir su semántica; renombrar la prueba.
- Confianza: alta.

### OWN-011 — Bajo — Coste por petición y ejecución duplicada
- `User::isOwner()` (`app/Models/Tenant/User.php:59-66`) ejecuta `min('id')` en cada petición; `moduleValues()` y `levelValues()` añaden 2 consultas más (caché solo por instancia). Cada llamada AJAX de la UI paga 3 consultas. `AccessMap::fromConfig()` usa una instancia estática (por proceso FPM; reconstruye ordenando ~150 reglas en cada petición, coste despreciable).
- Rutas con grupo `redirect.module` y además `redirect.level` (p. ej. `routes/web.php:153,197,401`) ejecutan el middleware dos veces (clases distintas, Laravel no las deduplica).
- Acción mínima: memoizar el id del propietario (caché de 5 minutos) y, si se desea, quitar `redirect.level` de las rutas del grupo ya cubierto.
- Confianza: alta.

### OWN-012 — Bajo — Mantenibilidad del middleware CSRF y cobertura de la prueba
- 28 patrones de cadena duplican las URIs de `routes/web.php` y de los módulos (deriva garantizada). `StateChangingGetCsrfTest` cubre 10 de los 28 patrones y no prueba: `HEAD`, rutas reales, registro en `Kernel.php`, ni la ausencia de falsos positivos en rutas legítimas.
- Los patrones `auto-update/*` pertenecen a las rutas del dominio de sistema (rama `else` de `routes/web.php`); funcionan, pero un volcado solo del tenant no los ve.
- La cabecera aceptada es solo `X-CSRF-TOKEN` (no `X-XSRF-TOKEN`): compatible con la UI porque `resources/js/bootstrap.js:15-21` fija la cabecera en axios, y todos los consumidores GET de las acciones protegidas usan `this.$http.get` (verificado en `documents/index.vue`, `cash/index.vue`, `deletable.js`, `contingencies/index.vue`, módulos Expense/Finance/Order/Purchase/Sale/Hotel); no hay enlaces `href` ni `window.open` a estas rutas. Sin falsos positivos detectados.
- Acción mínima: middleware de ruta o migración a POST (OWN-005) y ampliar la prueba con un caso `HEAD` y una prueba de integración con el router.
- Confianza: alta.

### OWN-013 — Bajo — CI (`.github/workflows/ci.yml`)
Veredicto: funciona. Verificado: `composer validate` OK y el `composer.lock` coincide con `composer.json`; `php artisan package:discover` (se ejecuta en `composer install`) termina bien con la BD inalcanzable y `APP_KEY` vacío; la suite Unit no usa BD ni `.env`; el comando de lint de la CI se probó en el contenedor (limpio, incluyendo `modules`).
Mejoras mínimas:
1. `on: push` y `pull_request` sin filtro duplican las ejecuciones en ramas con PR: usar `push: branches: [master]` + `pull_request`, y `concurrency` con `cancel-in-progress`.
2. Falta `permissions: contents: read` (por defecto el token puede ser de escritura) y `timeout-minutes`.
3. No hay job de Node: las 9 pruebas `tests/ui/*.test.js` pasan en local con Node 24, pero `npm run test:dashboard` (`package.json:12`) solo ejecuta 2; añadir un script que recorra todos los `*.test.js` y un job con `actions/setup-node` y `npm ci`. `system-dashboard.test.js` requiere `vue-template-compiler`, que no figura en `package.json` (llega como dependencia transitiva; verificar tras `npm ci`).
4. El lint de la CI omite `database` (el lint del contenedor la incluye) y el comando está duplicado con `composer lint`: llamar a `composer lint` y `composer test:unit` desde la CI para tener una sola fuente.
5. Sin caché de Composer (aceptable, la instalación es corta); acciones fijadas a etiquetas mayores (`@v4`, `@v2`): fijar por SHA al menos `shivammathur/setup-php`. Sin secretos en el archivo.
6. `phpunit.xml` usa el esquema antiguo de PHPUnit 9 con PHPUnit 10.5 (2 deprecaciones): ejecutar `phpunit --migrate-configuration`. No se ejecuta la suite `Feature` (requiere BD; solo existe `ExampleTest`).
- Confianza: alta en lo verificado; media en el comportamiento en un runner real (no ejecutado).

### OWN-014 — Bajo — Mensajes genéricos que ocultan información accionable
- `modules/Report/.../ReportSireRceController.php`, `ReportSireRvieController.php`, `PurchaseSunatValidationController.php:58-62`: se capturan `InvalidArgumentException`/`RuntimeException` (errores de negocio: credenciales SIRE sin configurar, periodo inválido) y se reemplazan por "No se pudo consultar el SIRE." / "No se pudo validar el comprobante con SUNAT." más `report($e)`. El usuario pierde la causa y el log se llena de errores de validación esperados. Lo mismo en importaciones (`PersonController`, `DocumentController`: "Verifique el formato").
- Acción mínima: mantener el mensaje para excepciones de dominio conocidas (ya existe `Handler::isUserFacing`) y reportar solo las inesperadas.
- Confianza: media (según el diff; no se ejecutaron los flujos).

### OWN-015 — Bajo — `CashController` coherencia de autorización y edición de caja cerrada
- `CashController.php:116-160` (`store`): con `id` existente no se comprueba `state`; se puede cambiar `beginning_balance` de una caja ya cerrada y se regenera la transacción de "Saldo inicial" (`createCashTransaction`), alterando cierres históricos.
- Autorización: el controlador usa `type !== 'admin'` para ver cualquier caja, mientras `Cash::scopeWhereTypeUser` (`Cash.php:44`) solo limita a `seller`; para otros tipos el listado y el detalle difieren. Por la memoria del proyecto, el acceso de administración se rige por propietario/módulo y no solo por tipo `admin`; aquí un administrador no propietario ve y cierra cajas de otros.
- Acción mínima: rechazar `store` sobre cajas con `state=false` y unificar el criterio (scope del modelo).
- Confianza: media.

### OWN-016 — Info — Borrado físico del PEM al eliminar el certificado
- `Tenant/CertificateController.php` (`destroy` y reemplazo en `uploadFile`) ahora eliminan el archivo `.pem`. Es una decisión razonable (evita huérfanos) pero irreversible: antes quedaba un respaldo implícito. Confirmar que es la intención y que `Company::save()` ocurre antes del borrado si se quiere evitar perder el certificado ante un fallo.
- Confianza: alta.

### OWN-017 — Info — Observaciones menores
- `config/cors.php` usa claves en camelCase (`supportsCredentials`, `allowedOrigins`); `HandleCors` de Laravel 10 espera `supports_credentials`, `allowed_origins`, `paths`. Si es así, no se emite ninguna cabecera CORS (efecto neutro o protector, pero la configuración está muerta). Confianza baja (no ejecutado).
- `Facturalo::save` ahora fuerza `state_type_id='01'`, `user_id` y `soap_type_id`; todos los `*Input` ya generan `'01'`, por lo que no hay cambio de comportamiento observable.
- `DownloadController::MODEL_MAP` sustituye la instanciación dinámica `App\Models\Tenant\{ucfirst($model)}` por una lista blanca; los enlaces de la UI (`saleNote`, `Document`, `document`, `dispatch`) se normalizan con `strtolower` y siguen resolviendo.
- Confianza: media/alta según el ítem.

## Archivos eliminados y referencias

- `app/Providers/InventoryKardexServiceProvider.php`, `InventoryAnulationServiceProvider.php`, `InventoryServiceProvider.php`: en HEAD, todos los cuerpos de `boot()` tenían las llamadas comentadas (`// $this->sale();`, `// $this->anulation();`, `// $this->inventory();`); no tenían efecto en ejecución. El kardex y la anulación reales siguen en `App\Providers\KardexServiceProvider`, `App\Providers\AnulationServiceProvider` (ambos siguen registrados en `config/app.php`) y en `modules/Inventory` (`Modules\Inventory\Providers\InventoryServiceProvider` registra `InventoryKardexServiceProvider` del módulo, clases distintas en otro espacio de nombres).
- Referencias: `config/app.php` ya no las lista (`git diff`). Búsqueda en `app modules routes config tests bootstrap resources database` sin coincidencias con los FQCN `App\Providers\Inventory*`. Las coincidencias de `InventoryServiceProvider` en `modules/Inventory/{module.json,composer.json,Providers}` son del módulo (espacio de nombres `Modules\`). `bootstrap/cache` solo contiene `.gitignore` (sin `services.php`/`packages.php` obsoletos). `vendor/composer/autoload_classmap.php` y `autoload_static.php` aún listan las clases eliminadas (3 entradas); sin impacto salvo que algo las instancie, pero conviene `composer dump-autoload -o` en el despliegue.
- Controladores eliminados: `Tenant/Dispatch2Controller`, `Tenant/InventoryController`, `Tenant/InventoryController2` no estaban enrutados en HEAD (`git grep` solo encuentra sus propias declaraciones).
- `modules/Ecommerce/Http/ViewComposers/TakeProductoViewComposer.php`: único consumidor `app/Providers/ViewServiceProvider.php:162` está dentro de un bloque comentado. Sin referencias vivas. `modules/Account/.../excel_concar_bk.blade.php` (copia de respaldo): sin referencias.
- Conclusión: no hay referencias colgantes y no se pierde funcionalidad.

## Lo que las pruebas NO cubren

- `AccessMapTest` prueba solo la lógica pura de `AccessMap` con el config real; no prueba `EnforceModuleAccess` (formato JSON frente a HTML, redirección a `firstAllowedPath`, bucles de redirección), ni el orden de middleware, ni la integración con rutas reales.
- No hay prueba de cobertura de rutas: ninguna aserción garantiza que cada ruta con `redirect.*` tenga regla (OWN-008 se detectó con un volcado ad hoc), ni de que las rutas fuera de `redirect.*` (padron, levelaccess, API) estén gobernadas (OWN-004, OWN-007).
- No hay perfiles reales: cajero (`pos`), vendedor, comprador, administrador no propietario; los flujos transversales (alta de cliente/proveedor desde otro módulo, POS) no están probados (OWN-002).
- La prueba "ajax" (`:119`) no distingue reglas con y sin bandera; nada prueba `HEAD`, mutaciones por método con la misma ruta (OWN-003), ni el comportamiento de `Authenticate` para `auth:api`.
- `StateChangingGetCsrfTest` cubre 10 de 28 patrones, no prueba `HEAD` ni que las rutas listadas existan, ni los GET con efectos que faltan (OWN-005, OWN-006).
- No hay pruebas de integración HTTP (`tests/Feature` solo tiene `ExampleTest`) ni contra BD; todo se valida con dobles.

## No verificado

- No se ejecutaron peticiones HTTP reales ni se usó una BD de tenant: el análisis de rutas es estático (volcado con un enrutador sin arrancar la aplicación) y no incluye middleware declarado en constructores de controladores.
- No se ejecutó el workflow de GitHub Actions; la evaluación de la CI se basa en simulación local (`package:discover` sin BD, suite Unit sin `APP_KEY`, lint).
- No se leyeron controladores de `contracts/changed`, `documents/regularize-lots`, `documents/consult_cdr`, `purchase-orders/generate` para confirmar efectos (OWN-005, confianza media).
- No se revisaron a fondo `app/Imports/*`, los `*Collection`/`Resource`, vistas Blade/Vue ni `DashboardData`/`ItemSetController` más allá de comprobar que no hay funciones eliminadas con llamadas vivas.
- Escalada interna: un usuario con el módulo `establishments` puede gestionar usuarios (`users`); no se verificó si `UserController::store` impide que se asigne módulos que él no posee.
- Si `RECREATE_DOCUMENT`, `CHANGE_TO_REGISTERED_STATUS` y `DELETE_DOCUMENT_TYPE_03` (por defecto `false`) están activos en producción no se pudo comprobar (no se leyó la configuración de entorno).
