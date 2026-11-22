# Revisión de cambios heredados — Grupo 1 (G1)

Rama `chore/system-audit`, comparación `git diff` del árbol de trabajo contra `HEAD`. Revisión independiente, solo lectura. Alcance: 19 rutas (16 modificadas, 3 eliminadas), unas 1240 líneas cambiadas. No se leyó ningún `.env`. Solo se ejecutó `php -l` (sin errores de sintaxis en los 9 archivos PHP con cambios sustantivos comprobados).

## 1. Resumen

- Los cambios son, en su gran mayoría, endurecimiento y corrección de errores coherentes entre sí: lista blanca de modelos/columnas/tipos de carga, transacciones sobre la conexión `tenant`, validación de entrada, eliminación de fugas de `file`/`line`, borrado seguro de certificados.
- Los tres archivos eliminados (`Dispatch2Controller`, `InventoryController`, `InventoryController2`) eran código muerto en `HEAD`: ninguna ruta, vista, binding ni prueba los referenciaba. No hay referencias colgantes que provoquen 404/500 en tiempo de ejecución (ver sección 4).
- Hallazgo principal (Alto): `app/Exceptions/Handler.php` enmascara ahora el mensaje de toda respuesta 5xx cuando `APP_DEBUG=false`. El código de la aplicación usa unas 115 `throw new Exception(...)` con mensajes pensados para el usuario, y la UI muestra `error.response.data.message`. En producción esos mensajes pasan a ser «Error interno del servidor».
- Segundo hallazgo (Medio): cambio de contrato HTTP/JSON para clientes de API y móvil (500 pasa a 404/403/422; desaparecen `file`/`line`; `documentStatus` cambia de código y forma).
- Conteo: Crítico 0, Alto 1, Medio 1, Bajo 10, Info 5.

## 2. Tabla de archivos

| Archivo | Tipo de cambio | Riesgo | Veredicto |
|---|---|---|---|
| app/Exceptions/Handler.php | Endurecimiento + cambio de contrato (status, mensaje, JSON) | Alto | Corregir: IDG1-001, 002, 003, 004 |
| app/Http/Controllers/Tenant/DownloadController.php | Endurecimiento (lista blanca de modelos y carpetas) + cambio de comportamiento (404 en vez de 500) | Bajo | Aceptable: IDG1-003, 013 |
| app/Http/Controllers/Tenant/CertificateController.php | Endurecimiento + cambio de comportamiento (borrado físico del PEM) | Bajo | Aceptable con reservas: IDG1-005, 006 |
| app/Http/Controllers/Tenant/CompanyController.php | Corrección de vulnerabilidad (escritura de atributo arbitrario `$company->$type`) + validación | Bajo | Aceptable: IDG1-007 |
| app/Http/Controllers/Tenant/CashController.php | Corrección (transacción + bloqueo, validación, lista blanca de columna) | Bajo | Aceptable: IDG1-011 |
| app/Http/Controllers/Tenant/Api/ServiceController.php | Validación de entrada + cambio de contrato en `documentStatus` | Medio | Aceptable con reservas: IDG1-002, 010 |
| app/Http/Controllers/Tenant/ExchangeRateController.php | Cambio de comportamiento (`create` pasa a `updateOrCreate`) + validación | Bajo | Aceptable: IDG1-009 |
| app/Http/Controllers/Tenant/EstablishmentController.php | Corrección (transacción tenant) | Bajo | Sin hallazgos |
| app/Http/Controllers/Tenant/DispatchController.php | Corrección de bug (`each` a `map`) + lista blanca de columna + consulta duplicada eliminada | Bajo | Aceptable: IDG1-012 |
| app/Http/Controllers/Tenant/ContingencyController.php | Endurecimiento (lista blanca de columna) | Bajo | Sin hallazgos |
| app/Http/Controllers/Tenant/LoginConfigurationController.php | Corrección (`firstOrFail`, extensión por MIME) | Bajo | Aceptable: IDG1-007, 015 |
| app/Http/Controllers/Tenant/Api/PerceptionController.php | Corrección (transacción sobre conexión `tenant`) | Bajo | Sin hallazgos |
| app/Http/Resources/Tenant/ItemCollection.php | Refactor/rendimiento (almacén resuelto una vez) | Bajo | Sin hallazgos: IDG1-017 |
| app/Models/Tenant/Item.php | Refactor retrocompatible (`getStockByWarehouse($warehouseId = null)`) | Bajo | Sin hallazgos: IDG1-017 |
| config/session.php | Endurecimiento (`same_site` pasa de `null` a `lax`, configurable por env) | Bajo | Aceptable: IDG1-008 |
| docker/nginx/default.conf | Endurecimiento (bloqueo de PHP y CSP para SVG bajo `/storage`) | Bajo | Aceptable: IDG1-007, 016 |
| app/Http/Controllers/Tenant/Dispatch2Controller.php | Eliminado (código muerto) | Bajo | Correcto |
| app/Http/Controllers/Tenant/InventoryController.php | Eliminado (código muerto) | Bajo | Correcto |
| app/Http/Controllers/Tenant/InventoryController2.php | Eliminado (código muerto) | Bajo | Correcto |

## 3. Hallazgos

### IDG1-001 — Alto — El Handler oculta todos los mensajes 5xx en producción y rompe flujos que dependen de ellos
- Archivo: `app/Exceptions/Handler.php:124-150` (en particular 129-135 y 94-96).
- Cambio: `errorResponse()` sustituye el mensaje por `'Error interno del servidor'` cuando `$code >= 500 && !config('app.debug')`. Una excepción no HTTP en una petición no-frontend (XHR/JSON) llega a `errorResponse('', 500, $e)` (línea 96), así que el mensaje original ya no se devuelve.
- Escenario concreto: la aplicación lanza `Exception` con texto funcional que sube hasta el Handler sin captura. Ejemplos verificados: `app/CoreFacturalo/Facturalo.php:687` y `:705` (`SendDeadline::creationMessage/message`, plazo de envío vencido), `:750` y `:764` (`"Code: {$code}; Description: {$message}"` para retenciones y guías rechazadas por SUNAT), `:817-900` (respuestas de resumen/baja), y `Api/SaleNoteController.php:178`. En total hay unas 115 `throw new Exception` en `app/` y `modules/`. En `DocumentController::store` (`app/Http/Controllers/Tenant/DocumentController.php:375-388`) no hay `catch`, y `resources/js/views/tenant/documents/invoice.vue:2242` muestra `error.response.data.message`. Con `APP_DEBUG=false`, el usuario o el integrador deja de ver el motivo real (plazo SUNAT, código de rechazo) y recibe un mensaje genérico, sin forma de corregir.
- Contrapunto: ocultar mensajes de `QueryException`/PDO/`ErrorException` es una mejora real (antes se filtraban SQL y rutas). El problema es la granularidad: se enmascara también lo que es deliberadamente de cara al usuario.
- Acción recomendada: no enmascarar por código de estado sino por tipo. Opciones: (a) introducir una excepción de dominio (por ejemplo `UserFacingException`) que conserve el mensaje, y migrar los `throw` funcionales; (b) enmascarar solo `QueryException`, `PDOException`, `ErrorException`, `Error` y `Throwable` de vendor, y conservar el mensaje de `\Exception` lanzadas directamente desde `app/`/`modules/`. En ambos casos conservar el reporte/log completo.
- Confianza: alta en el mecanismo (código leído de punta a punta). Media en el impacto real en producción, porque no se leyó `.env` y se asume `APP_DEBUG=false` (el valor de `.env.example` es `true`, lo que explicaría que no se haya notado en desarrollo).

### IDG1-002 — Medio — Cambio de contrato HTTP/JSON para clientes de API y móvil
- Archivos: `app/Exceptions/Handler.php:74-78`, `:93`, `:124-150`; `app/Http/Controllers/Tenant/Api/ServiceController.php:145-206`.
- Cambios: (1) toda `HttpException` (`abort(403)`, 422, 429…) antes devolvía 500 (se pasaba `''` como código) y ahora devuelve su estado real. (2) `ModelNotFoundException` en peticiones no-frontend pasa de 500 a 404 con mensaje «Registro no encontrado». (3) Con `APP_DEBUG=false` desaparecen `file` y `line` del JSON. (4) `documentStatus`: antes un documento inexistente lanzaba una excepción (500 con mensaje), ahora devuelve 404 `{success:false,message}`; sin `external_id` ni `serie_number` antes devolvía 200 con cuerpo vacío, ahora 422; antes `external_id` solo producía `ErrorException` por índice indefinido (`explode` de `null`), ahora funciona.
- Escenario: un cliente móvil o integrador que tratase «5xx = error de negocio» o que lea `file`/`line` verá un comportamiento distinto. Los cambios de estado son correctos semánticamente, pero son rupturas silenciosas de contrato.
- Acción: confirmar con los consumidores (app móvil, integraciones por `routes/api.php:51`) y documentarlo en el changelog. Probar el flujo de `Api\DocumentController` y `MobileController` con el nuevo código de estado.
- Confianza: alta en el cambio; baja en el impacto sobre clientes externos (el código de la app móvil no está en este repositorio).

### IDG1-003 — Bajo — La rama `NotFoundHttpException` descarta el mensaje de `abort()` y devuelve JSON a navegadores
- Archivos: `app/Exceptions/Handler.php:79-81`; `app/Http/Controllers/Tenant/DownloadController.php:67,71,84,97,101`.
- Escenario: `abort(404, 'Archivo no encontrado.')` es una `NotFoundHttpException`; el Handler la captura antes que la rama `HttpException` y responde «No se encontró la URL especificada» como JSON, incluso para un navegador (rutas públicas `/print/...` y `/downloads/...`). El mensaje específico nunca llega al cliente, y el texto genérico sugiere un problema de URL cuando en realidad falta el archivo. Además, un 503 (`ServiceUnavailableHttpException`) sale enmascarado como «Error interno del servidor» por la regla `>= 500`.
- Acción: devolver `$exception->getMessage()` cuando no esté vacío en la rama de 404, y excluir 503 del enmascarado.
- Confianza: alta.

### IDG1-004 — Bajo — `reportedIds` indexado por `spl_object_id`
- Archivo: `app/Exceptions/Handler.php:38,49,129`.
- Escenario: los ids de objeto se reutilizan tras la recolección de basura. En un proceso de larga vida (worker de cola, Octane) una excepción nueva podría heredar el id de una ya reportada y su 5xx no se registraría; además el arreglo crece sin límite. En PHP-FPM clásico el Handler vive una petición, así que el riesgo es mínimo.
- Acción: usar `SplObjectStorage`/`WeakMap` (PHP ≥ 8.0) en lugar de ids.
- Confianza: media (depende del modo de ejecución, no verificado).

### IDG1-005 — Bajo — `destroy()`/`uploadFile()` borran físicamente el PEM en un directorio compartido
- Archivo: `app/Http/Controllers/Tenant/CertificateController.php:48-53,77-100,103-112`.
- Escenario: los certificados están en `storage/app/certificates` (global, no por tenant) con nombre `certificate_{RUC}.pem`, y también los escribe `System\CertificateController` y `System\ClientController`. Antes «eliminar» solo ponía `certificate = null`; ahora borra el archivo de forma irreversible, y al subir uno con nombre distinto borra el anterior. Si dos tenants comparten RUC (pruebas/clones) o el administrador del sistema había provisionado el archivo, el borrado afecta a otros. La protección contra traversal (`basename` + `realpath` + prefijo) es correcta.
- Acción: confirmar que es el comportamiento deseado; opcionalmente mover a una papelera o exigir confirmación en la UI.
- Confianza: alta en el comportamiento; media en la exposición real (depende de si hay RUC duplicados).

### IDG1-006 — Bajo — Clave privada PEM escrita con permisos por defecto
- Archivo: `app/Http/Controllers/Tenant/CertificateController.php:55`.
- Escenario: `file_put_contents` crea el PEM (certificado + clave privada) con el `umask` del proceso (normalmente 0644); el directorio se crea con 0755. Preexistente y no empeorado por el diff, pero el diff reescribe exactamente esta zona y era el momento de corregirlo.
- Acción: `chmod($path, 0600)` tras escribir (y 0700 en el directorio).
- Confianza: alta (lectura de código; el `umask` real no verificado).

### IDG1-007 — Bajo — SVG: mitigación solo en nginx/docker; cambios funcionales en cargas
- Archivos: `docker/nginx/default.conf:23-34`; `CompanyController.php:76-94`; `LoginConfigurationController.php:20,27`.
- Observaciones: (a) `CompanyController::uploadFile` ya no acepta SVG para logos (cambio funcional; no hay vector XSS por ahí). (b) `LoginConfigurationController::uploadBgImage` sigue aceptando `svg`; el SVG servido como documento ejecutaría scripts en el origen del tenant. El bloque nginx añade `Content-Security-Policy: default-src 'none'; sandbox` y `nosniff` bajo `/storage/*.svg`, lo que neutraliza el script en navegación directa, y deniega `/storage/*.php`. (c) Esa protección existe solo en la imagen docker de nginx: un despliegue con Apache/otro nginx o un CDN no la hereda.
- Verificado: el orden de `location` regex es correcto (el bloque de PHP y el de SVG preceden al genérico de estáticos y al de `\.php$`); `add_header` en el bloque SVG no hereda nada porque no hay cabeceras a nivel `server`.
- Acción: dejar de aceptar SVG en `uploadBgImage` o sanearlo; replicar la política en cualquier otro servidor web.
- Confianza: alta en el análisis estático; no se probó con nginx real.

### IDG1-008 — Bajo — `SameSite=lax` explícito en la cookie de sesión
- Archivo: `config/session.php:195`.
- Análisis: `same_site` pasa de `null` a `env('SESSION_SAME_SITE','lax')`. `domain` queda en `env('SESSION_DOMAIN', null)` (cookie por host) y `secure` en `false` por defecto, de modo que el modelo multi-tenant por subdominio no cambia: cada tenant tiene su propia cookie y las navegaciones GET de primer nivel entre subdominios siguen enviándola. Mejora la protección CSRF en Safari/Firefox, que con `null` no aplican Lax por defecto. Riesgos: (a) la aplicación cargada en `iframe` de otro sitio dejaría de enviar la cookie; (b) un retorno POST desde una pasarela externa a una ruta autenticada también la perdería. No se halló ninguno en el repositorio (los pagos Culqi son por token JS).
- Acción: fijar `SESSION_SAME_SITE` explícitamente por entorno; si se usa `none`, exigir `SESSION_SECURE_COOKIE=true`.
- Confianza: media (flujos externos del cliente no verificables desde el código).

### IDG1-009 — Bajo — `ExchangeRateController::store` cambia la semántica a upsert y rellena `purchase` con `sale`
- Archivo: `app/Http/Controllers/Tenant/ExchangeRateController.php:20-75`.
- Escenario: antes `create` producía filas duplicadas para la misma fecha; ahora `updateOrCreate(['date' => …])` es idempotente (mejora). Pero si falta `purchase` se guarda el valor de `sale` como compra, lo que fabrica un dato sin avisar; `sale` pasa a ser obligatorio. Además, ningún código del repositorio llama a `POST /exchange_rates` (la UI solo usa `GET exchange_rates/records`), por lo que es un endpoint sin consumidor visible.
- Acción: exigir `purchase` o rechazar su ausencia; confirmar si el endpoint puede retirarse.
- Confianza: alta.

### IDG1-010 — Bajo — Ruta pública de tipo de cambio sin límite de tasa que dispara consultas externas
- Archivos: `routes/web.php:19` (fuera del grupo `auth`); `app/Http/Controllers/Tenant/Api/ServiceController.php:95-143`.
- Escenario: `GET /exchange_rate/ecommence/{date}` sin autenticación inserta en `exchange_rates` y llama a `ExchangeRate::searchDate`, que hace hasta cuatro consultas externas por fecha nueva. El diff añade validación estricta `Y-m-d` (positivo: ya no entra basura), pero un atacante puede iterar fechas válidas pasadas y forzar lecturas externas y escrituras en BD por tenant. Preexistente.
- Acción: aplicar `throttle`, o limitar el rango de fechas, o no persistir fechas pasadas arbitrarias desde la ruta pública.
- Confianza: alta en la ruta; los timeouts del cliente HTTP externo no se auditaron en este grupo.

### IDG1-011 — Bajo — `CashController::close`: errores sin retroalimentación y carreras residuales
- Archivo: `app/Http/Controllers/Tenant/CashController.php:158-210`, `:213-235`.
- Observaciones: (a) cerrar una caja ya cerrada ahora responde 422; `resources/js/views/tenant/cash/index.vue` solo hace `console.log` en el `catch`, así que el usuario no ve nada (antes reabría y recalculaba silenciosamente). Igual con `cash_document` sin caja abierta (antes 500). (b) La transacción con `lockForUpdate()` serializa dos cierres, pero `cash_document` inserta sin bloquear la caja: un documento insertado entre el cálculo y el `commit` queda vinculado a una caja cerrada y fuera del `final_balance`. (c) Preexistente: el cierre es un `GET` que cambia estado (CSRF por navegación) y no verifica que la caja pertenezca al usuario autenticado.
- Acción: mostrar el mensaje 422 en la UI; bloquear la caja también en `cash_document`; pasar el cierre a `POST` y validar propiedad.
- Confianza: alta en (a), media en (b)/(c).

### IDG1-012 — Bajo — Cambia la forma de `lots_group` en `DispatchController::tables`
- Archivo: `app/Http/Controllers/Tenant/DispatchController.php:203,240`.
- Escenario: `->each(...)` ignora el valor de retorno y devolvía los modelos completos; `->map(...)` devuelve ahora solo `id, code, quantity, date_of_due, checked=false`. Era un bug evidente y la forma nueva es la intencionada. Verificado: `resources/js/views/tenant/dispatches/` no lee `lots_group`; el único consumidor es el módulo Inventory, que tiene su propio endpoint. Riesgo bajo.
- Acción: ninguna; opcionalmente probar el formulario de guía con ítems con lotes.
- Confianza: media (la UI no se ejecutó).

### IDG1-013 — Info — `DownloadController`: endurecimiento verificado
- Archivo: `app/Http/Controllers/Tenant/DownloadController.php:27-122`.
- Antes: `"App\\Models\\Tenant\\".ucfirst($model)` permitía instanciar cualquier clase de ese namespace con un valor controlado por la URL. Ahora hay lista blanca (`MODEL_MAP`) y tipos de descarga fijos.
- Compatibilidad comprobada: los generadores de enlaces usan `document`, `summary`, `voided`, `retention`, `perception`, `dispatch` (modelos y `VoidedCollection` con `str_singular`: `voided`/`summary`) y la UI usa `Document` y `saleNote` (`pos/partials/options.vue:137`, `sale_notes/partials/options.vue:121`), todos cubiertos por la comparación en minúsculas. Los modelos añadidos (`Expense`, `OrderForm`, `OrderNote`, `FixedAssetPurchase`, `Order`) existen y tienen `external_id`. El mapa cubre todos los modelos de `App\Models\Tenant` con `external_id`.
- Observación menor: `readStorageContent()` lee el archivo completo en memoria y `downloadStorage()` lo vuelve a leer (doble lectura; costo irrelevante salvo XML/PDF muy grandes). `Content-Disposition` usa `basename($document->filename)`.
- Confianza: alta.

### IDG1-014 — Info — Rutas a métodos inexistentes (preexistente, fuera del diff)
- Archivos: `routes/web.php:307-308`; `resources/js/mixins/functions.js:12,76`.
- `POST services/exchange_rate` y `POST services/search_exchange_rate` apuntan a `Tenant\Api\ServiceController@exchange_rate` y `@searchExchangeRateByDate`, que no existen ni en `HEAD` ni en el árbol de trabajo (la clase solo define cinco métodos). El botón «Obtener» de tipo de cambio fallaría con `BadMethodCallException`. No es regresión de este diff, pero el Handler lo mostrará ahora como «Error interno del servidor».
- Confianza: media (no se ejecutó la ruta; podría existir otra definición en un módulo, no se encontró).

### IDG1-015 — Info — `LoginConfigurationController`: asimetría entre subida y restablecimiento
- Archivo: `app/Http/Controllers/Tenant/LoginConfigurationController.php:48-58`.
- `uploadBgImage` ahora tolera `login` nulo (`?? new \stdClass()`), pero `resetBgImage` sigue accediendo a `$config->login` sin comprobar nulo (fallo si la configuración de login está vacía). Además se persiste una URL absoluta con `asset()`, dependiente del host del momento de subida.
- Confianza: alta.

### IDG1-016 — Info — Cache de estáticos en nginx (no forma parte de este diff)
- Archivo: `docker/nginx/default.conf:36-41`.
- El bloque de estáticos con `expires 30d` y `Cache-Control public` ya existía en `HEAD`; el diff solo añade los dos bloques de `/storage`. Para el cache-busting: los bundles de Mix llevan `?id=` y no se ven afectados, pero `porto-ecommerce/assets/js/main.min.js` se carga sin parámetro de versión en cuatro layouts de Ecommerce (`modules/Ecommerce/Resources/views/layouts/*`), de modo que navegadores recurrentes pueden conservar el JS anterior hasta 30 días tras un despliegue (coincide con la nota ya registrada en `AUDIT_LOG.md`). Los bloques con `expires` + `add_header Cache-Control` emiten dos líneas `Cache-Control` (válido, redundante). Las rutas dinámicas con extensión estática caen por `try_files` a `index.php` y salen del bloque de caché, así que no se cachean respuestas dinámicas.
- Confianza: alta.

### IDG1-017 — Info — `Item`/`ItemCollection`: mejora de rendimiento sin pérdida funcional
- Archivos: `app/Http/Resources/Tenant/ItemCollection.php:21-26,57,60,75`; `app/Models/Tenant/Item.php:192-206`.
- El almacén del usuario se resuelve una vez en vez de una consulta por fila; el parámetro nuevo es opcional, así que los otros llamadores (`PosCollection`, `ItemResource`, `ItemBarCollection`) siguen válidos; `optional()` evita errores con moneda/almacén nulos. Sin usuario autenticado (Ecommerce público) devuelve 0, igual que antes. Persisten cargas perezosas de `warehouses` y `currency_type` por fila (no empeora).
- Confianza: alta.

## 4. Referencias colgantes de archivos eliminados

Conclusión: ninguna referencia colgante. Búsqueda con `rg` en `app/`, `routes/`, `modules/`, `resources/`, `config/`, `tests/`, `database/`, `bootstrap/` y `git grep` sobre `HEAD`.

| Archivo eliminado | Referencias en `HEAD` fuera del propio archivo | Reemplazo | Funcionalidad perdida |
|---|---|---|---|
| `Dispatch2Controller` | Ninguna (ni rutas, ni bindings, ni pruebas). Solo queda `app/CoreFacturalo/Requests/Web/Validation/Dispatch2Validation.php`, huérfana. | `DispatchController` (`routes/web.php:283-298`, mismas operaciones `index/columns/records/create/store/tables/send/status/downloadExternal`) | Ninguna. Su `records()` usaba `$request->column` sin lista blanca, ya corregido en `DispatchController`. |
| `InventoryController` (App\Tenant) | Ninguna ruta. Solo lo referenciaban las vistas `tenant.inventories.index` desde los propios controladores borrados. | Módulo Inventory (`modules/Inventory/Routes/web.php:20-35`: `index/records/columns/tables/record/store/move/remove`) | Ninguna. |
| `InventoryController2` | Ninguna. | Mismo módulo Inventory. | Ninguna. |

Restos huérfanos (no rompen nada, sin ejecución posible):
- `resources/views/tenant/inventories/index.blade.php` usa el componente `<tenant-inventories-index>`, que no está registrado en `resources/js` (sin coincidencias): vista muerta, ya inservible antes.
- `app/CoreFacturalo/Requests/Web/Validation/Dispatch2Validation.php` sin usos.
- `vendor/composer/autoload_classmap.php` (local, no versionado) aún lista las tres clases; es inocuo mientras nadie las instancie y se regenera con `composer dump-autoload`.
- `modules/Inventory` conserva los nombres `inventories` en otras rutas (`tenant.inventories.configuration.index`), que no dependen de los controladores eliminados.

## 5. Archivos sin hallazgos

- `app/Http/Controllers/Tenant/EstablishmentController.php`: la transacción sobre la conexión `tenant` es correcta (establecimiento y almacén atómicos). Detalle menor preexistente: `firstOrNew(['id' => $id])` permite elegir el id del registro nuevo.
- `app/Http/Controllers/Tenant/ContingencyController.php`: lista blanca basada en `columns()`, con `number` como valor por defecto.
- `app/Http/Controllers/Tenant/Api/PerceptionController.php`: `DB::transaction` → `DB::connection('tenant')->transaction` corrige una atomicidad que antes se perdía (la conexión por defecto no era la del tenant).
- `app/Http/Resources/Tenant/ItemCollection.php` y `app/Models/Tenant/Item.php`: ver IDG1-017 (informativo).

## 6. No verificado

- No hay base de datos, servidor HTTP ni nginx real: nada se ejecutó. No se corrieron pruebas ni rutas; solo `php -l`.
- No se leyó `.env`; se desconoce el valor real de `APP_DEBUG`, `SESSION_*` y del modo de ejecución (FPM/Octane/colas) en producción (afecta a IDG1-001, 004, 008).
- La app móvil y los integradores externos no están en el repositorio: el impacto de IDG1-002 sobre ellos es una inferencia.
- No se ejecutó el frontend: el comportamiento de Vue ante 404/422 (IDG1-011) se infiere de la lectura de `cash/index.vue` e `invoice.vue`.
- No se comprobó con nginx la precedencia de `location` ni las cabeceras resultantes (análisis estático de las reglas).
- No se auditaron los timeouts del cliente HTTP de `App\CoreFacturalo\Services\Extras\ExchangeRate` ni de las consultas SUNAT/RENIEC (fuera de las líneas del diff).
- No se comprobó la autorización por rol de las rutas de carga (`companies/uploads`, `certificates/uploads`, `cash/close`): son preexistentes y solo se constató que están dentro del grupo `auth`.
- Los cambios de `Authenticate`, `MobileController`, `Facturalo` y demás archivos del grupo 2 quedan fuera de este informe.
