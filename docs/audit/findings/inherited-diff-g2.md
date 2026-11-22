# Revisión independiente del diff heredado — Grupo 2 (núcleo/app)

Rama `chore/system-audit`, árbol de trabajo frente a `HEAD`. Revisión de solo lectura: no se ejecutaron pruebas, composer, migraciones, base de datos ni peticiones de red. Se usaron `git diff`, lectura de código, `rg` y `php -l` (PHP 8.5.5 del host).

## 1. Resumen

- 24 rutas revisadas (22 modificadas + 2 nuevas sin seguimiento): ~980 líneas cambiadas.
- Los hunks de `Facturalo`, `DomCdrReader`, `CdrResponse`, `BaseSunat`, `Authenticate`, `User`, `AnulationServiceProvider`, `ResellerController`, `TempImageHelper`, la parte de `ItemController`/`ItemSetController`/`PromotionController` que gestiona imágenes y `CulqiCharger`/`CulqiErrorPolyfills` corresponden a FIX-001/011/012/014/015/017/018/020 y coinciden con lo descrito en `AUDIT_LOG.md`. No se les aplicó nueva crítica salvo en interacciones.
- Hunks heredados/no registrados relevantes: `routes/api.php` (traslado de 2 rutas a `auth:api`), `Api/DocumentController`, `Api/MobileController` (todo salvo el rechazo `client`), `ItemController::store` (transacción, lotes, tipos de unidad, lista blanca de columnas), `ConfigurationController`, `AccountController::updatePlan`, `Tenant/DocumentController` (agrupación de `searchCustomers`, `SearchTrait`), `Api/SaleNoteController`, y cambios de lista de MIME (svg fuera, webp dentro).
- Conteo de hallazgos: **Crítico 0 · Alto 0 · Medio 2 · Bajo 5 · Info 4**.
- **Retirada del SDK `culqi/culqi-php`: no se encontró ningún riesgo de error fatal por clase inexistente.** Detalle en la sección 3.
- `php -l`: 24/24 archivos PHP sin errores de sintaxis (solo un aviso `Deprecated` de PHP 8.4+ en `CulqiCharger.php:25`, ver IDG2-008). `composer.json` es JSON válido.
- `composer.json` y `composer.lock` son coherentes: recalculé el `content-hash` con el algoritmo de Composer; el de `HEAD` (`d3eaf98d…`) se reproduce exactamente y el del árbol de trabajo (`cc7a1cb3…`) coincide con el del lock. El lock elimina `culqi/culqi-php` y su dependencia exclusiva `rmccue/requests` (sin otras referencias en `composer.lock`, `app/`, `modules/`, `config/`, `routes/`, `tests/`).

## 2. Tabla de archivos

| Archivo | Hunks cubiertos por FIX | Hunks heredados / no registrados | Riesgo | Veredicto |
|---|---|---|---|---|
| `app/CoreFacturalo/Facturalo.php` | `validationCodeResponse` + `hasCdr` (FIX-012) | — | Bajo | Aceptable |
| `app/CoreFacturalo/WS/Reader/DomCdrReader.php` | Lector estricto (FIX-012) | — | Bajo (IDG2-004) | Aceptable con mejora |
| `app/CoreFacturalo/WS/Response/CdrResponse.php` | `isValidCode`, `isAccepted` (FIX-012) | — | Bajo | Aceptable |
| `app/CoreFacturalo/WS/Services/BaseSunat.php` | `getErrorByCode` (FIX-012) | — | Bajo (IDG2-012) | Aceptable |
| `app/Http/Controllers/System/Api/ResellerController.php` | Validación booleana (FIX-015) | — | Bajo (IDG2-011) | Aceptable |
| `app/Http/Controllers/Tenant/AccountController.php` | — | `paymentIndex` y `updatePlan` completos | Bajo (IDG2-007) | Aceptable con observación |
| `app/Http/Controllers/Tenant/Api/DocumentController.php` | — | `send`, `documentCheckServer`, `getStateTypeDescription` | Medio (IDG2-002) | Requiere decisión |
| `app/Http/Controllers/Tenant/Api/MobileController.php` | Rechazo `client` en `login` (FIX-014) | Guardas de almacén/marca/categoría, `take(50)`, agrupación `where`, 404 en email | Bajo (IDG2-006) | Aceptable con observación |
| `app/Http/Controllers/Tenant/Api/SaleNoteController.php` | — | Imports eliminados (verificados sin uso), `findOrFail`, propiedad `$document` | Bajo | Sin hallazgos |
| `app/Http/Controllers/Tenant/ConfigurationController.php` | — | Lista blanca de plantillas, `findOrFail`, subida (svg fuera) | Bajo (IDG2-009) | Aceptable |
| `app/Http/Controllers/Tenant/DocumentController.php` | — | `searchCustomers` agrupado, `SearchTrait`, null-guards, `findOrFail` | Bajo | Sin hallazgos |
| `app/Http/Controllers/Tenant/ItemController.php` | Guardado de imágenes (FIX-011) | Transacción, lotes, tipos de unidad, columna permitida, `destroy`, `findOrFail`, svg/webp | Medio-Bajo (IDG2-003, 009, 010) | Requiere pruebas |
| `app/Http/Controllers/Tenant/ItemSetController.php` | Imágenes (FIX-011) | MIME svg→webp | Bajo | Aceptable |
| `app/Http/Controllers/Tenant/PromotionController.php` | Imágenes (FIX-011) | MIME svg→webp | Bajo | Aceptable |
| `app/Http/Controllers/Tenant/SaleNoteController.php` | Bloqueo y guarda 11 (FIX-018) | — | Bajo | Sin hallazgos |
| `app/Http/Middleware/Authenticate.php` | Rechazo `client` (FIX-014) | — | Bajo (IDG2-005) | Aceptable con observación |
| `app/Models/Tenant/User.php` | `$hidden` (FIX-017) | — | Bajo | Sin hallazgos |
| `app/Providers/AnulationServiceProvider.php` | Guarda de estado (FIX-018) | — | Bajo | Sin hallazgos |
| `app/Services/Payments/CulqiCharger.php` | Fallback Guzzle + errores (FIX-001/020) | — | Bajo (IDG2-008) | Aceptable |
| `app/Services/Payments/CulqiErrorPolyfills.php` (nuevo) | FIX-001 | — | Bajo (IDG2-008) | Aceptable |
| `app/CoreFacturalo/Helpers/Storage/TempImageHelper.php` (nuevo) | FIX-011 | — | Bajo (IDG2-010) | Sin hallazgos propios |
| `composer.json` | Retirada del SDK (intención del usuario) | — | Bajo | Coherente con lock |
| `routes/api.php` | Rutas reseller (FIX-015) | Traslado de `documents/search/customers` y `configurations/generateDispatch` a `auth:api` | Medio (IDG2-001) | Parcial |
| `routes/web.php` | Login de sistema sin `LockedAdmin` (FIX-015) | — | Bajo | Aceptable |

## 3. Retirada del SDK Culqi: análisis de error fatal por clase inexistente

Verificado por lectura de código:

1. Búsqueda de `use Culqi`, `Culqi\`, `Culqi::` y `new Culqi` en `app/`, `modules/`, `config/`, `routes/`, `tests/`, `bootstrap/`, `database/`: las únicas referencias a `\Culqi\Culqi` están en `CulqiCharger.php:7` (`use`, inocuo) y `:28`, protegida por `class_exists(Culqi::class)`. El resto son `\Culqi\Error\*`, definidos por el polyfill. `AccountController`, `CulqiController` y `EcommerceController` solo referencian `CulqiEmail` (clase propia). Las apariciones en blade son el JS `checkout.culqi.com`, no PHP.
2. El polyfill (`CulqiErrorPolyfills.php`) se carga con `require_once __DIR__ . '/CulqiErrorPolyfills.php'` en la cabecera de `CulqiCharger.php` (línea 5), antes de cualquier uso. No depende de `composer.json` `autoload.files`. Cada clase se declara con `if (!class_exists(X, false))`. El único consumidor de `\Culqi\Error\*` en `app/` es `CulqiCharger`, por lo que siempre se carga antes de lanzar o capturar. Las pruebas de `PaymentsTest` usan `\Culqi\Error\*` tras instanciar `CulqiCharger`.
3. `catch (\Throwable)` relanza `\Error` (línea ~131), de modo que un `Class not found` futuro no queda disfrazado de resultado `unknown`.
4. Conclusión: con el SDK ausente no hay camino que produzca `Class "Culqi\…" not found`. Confianza alta (lectura + `php -l`); no se ejecutó ninguna prueba.

Matices (ver IDG2-008): si el SDK se reinstalara, el polyfill declararía primero las excepciones y el classmap del SDK podría intentar redeclarar clases adicionales de su archivo multi-clase; y `composer dump-autoload -o` avisará de que el polyfill no cumple PSR-4 (no es fatal).

## 4. Hallazgos

### IDG2-001 — Medio — Traslado parcial de rutas públicas: `sendserver` y `documents/status` siguen sin autenticación
- Archivo: `routes/api.php:49-53` (fuera del grupo `auth:api`, cierre en la línea 46).
- Mapeo: heredado/no registrado. El diff mueve `documents/search/customers` y `configurations/generateDispatch` dentro de `auth:api` (propuesta de TC1-013 en `findings/tenant-controllers-1.md:55-66`), pero deja fuera las otras dos rutas de la misma propuesta.
- Escenario: `GET /api/sendserver/{document_id}` llama a `DocumentController::sendServer` (`app/Http/Controllers/Tenant/DocumentController.php:563`) sin autenticación: lee XML firmado, PDF y `data_json` del comprobante y los envía por POST al servidor offline configurado, con `Authorization: Bearer` y `verify=false`; marca `send_server=true`. Un visitante puede enumerar IDs y forzar envíos/carga de E/S. Con un ID inexistente, `$document->data_json` desreferencia `null` y produce un 500; `$response['success']` falla si el servidor remoto devuelve algo que no es JSON. `POST /api/documents/status` entrega, para `serie_number` secuencial, número, QR y enlaces de descarga.
- Acción: mover ambas rutas a `auth:api` (o exigir secreto/URL firmada si hay integraciones que dependan de ellas) y añadir `findOrFail`/validación de JSON en `sendServer`. Decidir explícitamente cuáles son públicas por diseño.
- Confianza: alta (lectura de rutas y controladores). No se verificó si alguna integración externa consume estas rutas.

### IDG2-002 — Medio — `Api\DocumentController::send` cambia el contrato y deja excepciones sin manejar frente al nuevo `Handler`
- Archivo: `app/Http/Controllers/Tenant/Api/DocumentController.php:64-105`.
- Mapeo: heredado/no registrado. Antes, falta de `external_id`, documento inexistente y grupo distinto de `01` lanzaban `Exception` (500 con mensaje); sin `external_id` la acción devolvía `null` (200 vacío). Ahora: 422/404 con `{success:false,message}`. La rama de éxito conserva la forma.
- Escenario concreto: el envío posterior (`loadXmlSigned()`/`onlySenderXmlSignedBill()`) puede lanzar `Exception` (plazo de envío vencido, `SendDeadline`), `RuntimeException` del lector CDR (FIX-012) o errores de transporte. No hay `try/catch`. Con `app.debug=false`, `Handler::errorResponse` (`app/Exceptions/Handler.php`, cambio no incluido en este grupo) enmascara los 5xx como «Error interno del servidor». Un integrador de `/api/documents/send` pierde el motivo accionable (p. ej. plazo vencido) que antes recibía. Cambia además el código HTTP en tres casos.
- Acción: capturar excepciones de negocio conocidas y devolver 422/409 con mensaje seguro; documentar el cambio de contrato en el changelog/API. 
- Confianza: media-alta (flujo leído; el enmascaramiento depende de `APP_DEBUG` que no se leyó). No se verificó qué consumidores externos usan el endpoint.

### IDG2-003 — Bajo — `ItemController::store`: semántica de lotes y tipos de unidad modificada sin pruebas
- Archivo: `app/Http/Controllers/Tenant/ItemController.php:170-366` (lotes 270-318, tipos de unidad 244-259).
- Mapeo: heredado/no registrado (FIX-011 solo cubre imágenes).
- Detalles: (a) se elimina `$item->lots()->delete()` en la edición; antes se borraban todos los lotes y luego `ItemLot::find($id)->update/delete` sobre filas ya eliminadas (`ItemLot` no usa `SoftDeletes`), es decir, la edición con lotes con `id` fallaba; el nuevo comportamiento es correcto, pero los lotes ausentes del payload ya no se eliminan. (b) `ItemUnitType` ahora se resuelve acotado a `item_id`; un `id` ajeno crea una fila nueva en lugar de modificar la de otro producto (mejora, corrige un IDOR). (c) Todo en `DB::connection('tenant')->transaction`. (d) `destroy` también en transacción.
- Riesgo: cambio de datos de inventario sin prueba de regresión; no se pudo ejecutar contra DB.
- Acción: añadir pruebas de edición con lotes (ids existentes, `deleted`, nuevos) y de tipos de unidad ajenos.
- Confianza: media (lectura; sin ejecución).

### IDG2-004 — Bajo — `ResponseCode` del CDR no se recorta antes de validarse
- Archivo: `app/CoreFacturalo/WS/Reader/DomCdrReader.php:96` (`return $values[0]->nodeValue;`) + `CdrResponse.php:45` (`ctype_digit`).
- Mapeo: FIX-012 (endurecimiento), consecuencia no cubierta.
- Escenario: un CDR (p. ej. de un OSE) con espacios o saltos de línea dentro de `<cbc:ResponseCode>` produce `"0\n"`. Antes `intval` lo aceptaba; ahora `isValidCode` lo rechaza y se lanza `RuntimeException`, de modo que un documento aceptado no se marca aceptado. Los CDR de SUNAT son compactos, por lo que el riesgo es bajo.
- Acción: `trim()` del valor en `getValueByName` (o en el código antes de validar).
- Confianza: media-baja (no se dispone de CDR de OSE reales).

### IDG2-005 — Bajo — Cliente de tienda con sesión activa queda en un callejón sin salida al visitar el login del backoffice
- Archivos: `app/Http/Middleware/RedirectIfAuthenticated.php:19-20` (redirige a `/dashboard` si la sesión es válida, sin conocer `type`), `app/Http/Middleware/Authenticate.php:12-17` (403 para `type=client`); `app/Http/Controllers/Auth/LoginController.php:40` usa `guest`.
- Mapeo: interacción FIX-014 con código preexistente.
- Escenario: una persona que compró en la tienda (sesión web `client` viva) abre el login del personal: `guest` ve una sesión autenticada y la redirige a `/dashboard`, donde `auth` responde 403. No puede iniciar sesión como personal en ese navegador hasta cerrar la sesión de tienda (`ecommerce/logout`) o borrar cookies.
- Acción: que `RedirectIfAuthenticated` no trate como autenticado a `Tenant\User` de tipo `client` (o permitir mostrar el login). Aceptable documentarlo.
- Confianza: media (no se ejecutó el flujo; se leyó el middleware y el constructor del `LoginController`).

### IDG2-006 — Bajo — `MobileController`: cambios de contrato JSON sin registrar
- Archivo: `app/Http/Controllers/Tenant/Api/MobileController.php:137,304` (`category`), `:276,336` (`take(50)`), `:111-116,265-282` (stock/almacén), `:169-175` (404).
- Mapeo: heredado/no registrado (solo el rechazo `client` en `login` es FIX-014).
- Detalles: `category` antes devolvía el nombre de la marca (bug) y ahora el de la categoría (o `null`); `searchItems`/`searchCustomers` quedan limitados a 50 filas y agrupan correctamente el `OR` (antes el `orWhere` rompía `whereWarehouse()`/`whereIsActive()` y los filtros no se aplicaban a la rama `internal_id`/`number`); `stock` sin registro devuelve `0` en lugar de un 500; `document/email` devuelve 404 JSON. No se detectó N+1 (`Item::$with` ya carga `warehouses`).
- Riesgo: la app móvil podría haber normalizado el comportamiento anterior (p. ej. usar `category` como marca, paginación implícita). Mejoras funcionales correctas, pero conviene avisar a quien mantenga la app.
- Confianza: alta en el comportamiento del servidor; el consumidor móvil no está en el repositorio.

### IDG2-007 — Bajo — `AccountController::updatePlan`: orden de escritura invertido y sin atomicidad entre conexiones
- Archivo: `app/Http/Controllers/Tenant/AccountController.php:61-100`.
- Mapeo: heredado/no registrado.
- Detalles: ahora se guarda primero `Client` (BD sistema) y después `Configuration` (BD tenant); antes era al revés. Son dos conexiones sin transacción distribuida: un fallo intermedio deja el plan del cliente distinto del de la configuración del tenant (la inversa del caso previo). Además cualquier usuario con el módulo `cuenta` (`config/permissions.php:179`) puede fijar cualquier `plan_id` sin pago; el cliente Vue que lo llamaba está comentado (`resources/js/views/tenant/account/configuration.vue:79-97`). Las respuestas de error pasan a 404/500 con JSON.
- Acción: retirar la ruta `cuenta/update_plan` si no se usa, o restringirla/atarla a un pago confirmado.
- Confianza: media (no se verificó la asignación del módulo `cuenta` a roles).

### IDG2-008 — Info — `CulqiCharger`/polyfill: avisos de compatibilidad
- `app/Services/Payments/CulqiCharger.php:25`: `callable $factory = null` es nullable implícito, obsoleto desde PHP 8.4 (el host usa 8.5.5; `composer.json` declara `^8.1|^8.2|^8.3`). Hoy solo emite `Deprecated`; usar `?callable`.
- `composer dump-autoload -o` (la config tiene `optimize-autoloader: true`) avisará de que `CulqiErrorPolyfills.php` declara clases `Culqi\Error\*` fuera de su regla PSR-4 `App\`; no es fatal (se omiten del classmap) y la carga por `require_once` no se ve afectada.
- Si algún día se reinstala el SDK, el polyfill declararía las excepciones antes que el SDK; no hay problema para las clases comunes, pero cualquier clase adicional del archivo multi-clase del SDK podría provocar «Cannot redeclare». No aplica con el SDK retirado.

### IDG2-009 — Info — Listas de MIME de subida: `svg` fuera, `webp` dentro
- Archivos: `ItemController.php:435`, `ItemSetController.php:223`, `PromotionController.php:128` (`jpg,jpeg,png,gif,webp`), `ConfigurationController.php:370` (`jpeg,png,jpg,gif`; el SVG de cabecera ya no se admite).
- Coherente con FIX-011 (`TempImageHelper::MIME_EXTENSIONS` rechaza svg, de modo que antes el svg se subía y fallaba al guardar). Cambio funcional para quien usaba svg como imagen de cabecera. `webp` depende de que GD/Imagick lo soporten en `Image::make` (no verificado).

### IDG2-010 — Info — Archivos escritos dentro de transacciones no se revierten
- `ItemController.php:195-345`, `ItemSetController.php:112-130`, `PromotionController.php:82-96`: `Storage::put` ocurre antes del commit; si una escritura posterior falla, la fila se revierte pero los archivos (principal, `_medium`, `_small`, adicionales) quedan huérfanos. El temporal no se consume (correcto, permite reintento). Impacto: acumulación de archivos, no de datos.

### IDG2-011 — Info — `lockedAdmin` y la ruta web: aspectos menores
- `ResellerController.php:63`: `required|boolean` no acepta las cadenas `"true"`/`"false"` (solo `true/false/1/0/"1"/"0"`); un cliente externo que las enviase recibiría 422. Con el grupo `web`, un fallo de validación sin cabecera JSON devuelve redirección 302 (`Handler::isFrontend`).
- `routes/web.php:573`: `logout` sigue bloqueado por `LockedAdmin` cuando la plataforma está bloqueada (el administrador puede iniciar sesión y desbloquear, pero no cerrar sesión por esa ruta mientras permanezca bloqueada).

### IDG2-012 — Info — `BaseSunat::getErrorByCode` ya no extrae códigos del `faultstring`
- `app/CoreFacturalo/WS/Services/BaseSunat.php:105-113`. Decisión documentada en FIX-012: un fault con `faultcode` sin código numérico (p. ej. `soap-env:Server`) queda como código no numérico y se regulariza (`updateRegularizeShipping`) en lugar de clasificarse por un número hallado en el texto. Un OSE que ponga el código solo en `faultstring` perderá la clasificación rechazo/observación inmediata (se reenvía/regulariza). No se detectó pérdida de documentos. No verificado con OSE reales.

## 5. Interacciones entre correcciones

| Interacción | Resultado |
|---|---|
| `Authenticate` (FIX-014) + `MobileController::login` + `User::$hidden` (FIX-017) | El login móvil lee `$user->api_token` como atributo; `$hidden` solo afecta la serialización, por lo que el token se sigue devolviendo y el cliente `client` queda rechazado antes. Los consumidores de `api_token` revisados (`Hotel`, `Imports`, `SummarySend/QueryCommand`, `UserCollection`/`UserResource`, blade de pedidos con `array_merge`) siguen funcionando; `cart/detail.blade.php:406` añade `Authorization` solo si existe el token (no lo envía la tienda). Sin regresión hallada. |
| `Authenticate` en rutas de tienda | Las rutas públicas de la tienda no usan `auth` (`modules/Ecommerce/Routes/web.php`; solo `configuration*` lo usa), por lo que el 403 a `client` no rompe el flujo de compra. Salvedad en IDG2-005. |
| `TempImageHelper` (FIX-011) + controladores + upload | Solo hay 3 consumidores web con sesión (`ItemController`, `ItemSetController`, `PromotionController`); ninguna ruta `auth:api` sin sesión lo usa (la requeriría y devolvería 422). La restricción svg del upload es coherente con `MIME_EXTENSIONS`. `resolveValidated` aborta con 422 (no devuelve `null`), así que un `temp_path` inválido no guarda el producto con imagen rota. |
| `Facturalo` + `DomCdrReader` + `BaseSunat` (FIX-012) | `BillSender` solo captura `SoapFault`; una `RuntimeException` del lector CDR se propaga (antes era `TypeError`). `GreDispatchService:303` la captura (`\Throwable`) y `ExtService`/`ConsultCdrService` la dejan subir; ver IDG2-002 para el efecto en `/api/documents/send`. `NUMBER_PATTERN` eliminada sin otras referencias. |
| `routes/api.php` + controladores | Las dos rutas movidas existen como rutas web usadas por la UI (`documents/search/customers` en `routes/web.php:193`, `configurations/preprinted/generateDispatch` en `routes/web.php:97`); ningún cliente del repositorio llama a las versiones `/api/…`, por lo que el único impacto es sobre integraciones externas. No hay rutas inalcanzables ni colisiones de nombre introducidas. Las rutas reseller ya no están en el grupo del dominio sin `web`; `LockedAdmin` es miembro del grupo `web` (`Kernel.php:39`), por lo que `withoutMiddleware` surte efecto. |
| `routes/web.php` login + `routes/api.php` reseller (FIX-015) | `LockedAdmin` solo se excluye en los 4 puntos deseados. `ResellerController::login` queda sin ruta en este diff (no se alteró). |
| `Handler` (otro grupo) + `findOrFail` (controladores de este grupo) | `ModelNotFoundException` ahora responde 404 JSON para peticiones no-frontend; las llamadas AJAX web reciben el JSON estándar de Laravel. Se mantiene coherente con los cambios `find→findOrFail`. |
| `Tenant\DocumentController` + `SearchTrait` | No hay colisión de métodos entre `StorageDocument`, `OfflineTrait`, `FinanceTrait` y `SearchTrait`; se elimina el `getFullDescription` local (que desreferenciaba `null`) y se usa la versión segura del trait (`SearchTrait.php:100`). El `use Nexmo\…\Price`, `Warehouse`, `ZipFly` y `Cache` eliminados no tienen referencias vivas (solo un comentario). |
| `AnulationServiceProvider` (FIX-018) + anulación manual en `Tenant\SaleNoteController` (~línea 949) | `isDirty('state_type_id')` es correcto en el evento `updated` (Laravel sincroniza el original después). Los cambios masivos con `Document::where()->update()` no disparan eventos (preexistente). |

## 6. Archivos sin hallazgos

`app/CoreFacturalo/Facturalo.php`, `WS/Response/CdrResponse.php`, `app/Http/Controllers/Tenant/Api/SaleNoteController.php`, `Tenant/DocumentController.php`, `Tenant/ItemSetController.php`, `Tenant/PromotionController.php`, `Tenant/SaleNoteController.php`, `app/Models/Tenant/User.php`, `app/Providers/AnulationServiceProvider.php`, `app/CoreFacturalo/Helpers/Storage/TempImageHelper.php`, `composer.json`, `routes/web.php` (salvo la nota menor de IDG2-011). Los 24 archivos PHP pasan `php -l`.

## 7. No verificado

- No se ejecutó ninguna prueba (PHPUnit), HTTP, base de datos, build ni `composer`; las afirmaciones provienen de lectura de código, `git diff`, `rg` y `php -l`.
- No se leyó `.env` ni otros secretos: no se conoce `APP_DEBUG`, `CACHE_DRIVER` real (el predeterminado de `config/cache.php` es `file`) ni `SESSION_DRIVER`, de los que dependen el enmascaramiento de errores (IDG2-002) y el registro de propietario de `TempImageHelper`.
- No se comprobó el contenido de `vendor/` ni si el SDK sigue instalado en algún entorno; el análisis del SDK ausente es estático.
- No se verificó soporte `webp` de GD/Imagick, ni los consumidores externos (app móvil, integradores de `/api/documents/send`, `documents/status`, `sendserver`).
- No se ejecutó el flujo real de login de tienda/personal (IDG2-005) ni se comprobó qué roles tienen el módulo `cuenta` (IDG2-007).
- Los hunks atribuidos a FIX-001/011/012/014/015/017/018/020 se contrastaron con el texto de `AUDIT_LOG.md`, no se volvieron a probar.
