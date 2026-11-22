# Revisión estática del diff heredado — índice consolidado

Fecha: 2026-11-18. Rama `chore/system-audit`. Revisión independiente de solo lectura (sin ejecutar pruebas, HTTP, bases de datos ni jobs; no se leyó `.env`) de los archivos de código modificados o eliminados respecto de `HEAD`, repartida en tres grupos. Los informes completos están en:

- `inherited-diff-g1.md` — 19 archivos que ningún FIX había revisado (incluye 3 controladores eliminados).
- `inherited-diff-g2.md` — 24 archivos del núcleo (`app/`, `routes/`, `composer.json`).
- `inherited-diff-g3.md` — 46 archivos de `modules/*`.

Quedaron fuera por ser correcciones ya revisadas una a una: los archivos de frontend de FIX-021 a FIX-025 y el bundle compilado.

## Totales

| Severidad | G1 | G2 | G3 | Total |
|---|---|---|---|---|
| Crítico | 0 | 0 | 0 | 0 |
| Alto | 1 | 0 | 0 | 1 |
| Medio | 1 | 2 | 2 | 5 |
| Bajo | 10 | 5 | 5 | 20 |
| Info | 5 | 4 | 4 | 13 |

Sin referencias colgantes de los cinco archivos eliminados (`Dispatch2Controller`, `InventoryController`, `InventoryController2`, `excel_concar_bk.blade.php`, `TakeProductoViewComposer`). La retirada de `culqi/culqi-php` no provoca error fatal por clase inexistente (referencias protegidas con `class_exists`, polyfill cargado con `require_once`, `composer.json` y `composer.lock` coherentes). `php -l` limpio en todos los archivos revisados. Todos los hunks funcionales de `modules/*` están explicados por un FIX; el único hunk heredado sin registro allí es la eliminación de dos métodos vacíos de `PadronController`.

## Prioridad sugerida

### P1 — decidir o corregir primero

| ID | Sev. | Dónde | Resumen |
|---|---|---|---|
| IDG1-001 | Alto — **corregido en FIX-026** | `app/Exceptions/Handler.php:124-150` | Con `APP_DEBUG=false` toda respuesta 5xx devuelve «Error interno del servidor». Decenas de `throw new Exception(...)` llevan mensajes pensados para el usuario (plazo de envío vencido en `Facturalo.php:687/705`, rechazos SUNAT en `750/764/817/835/900`) que la UI muestra; en producción se pierden. Verificado en el diff. Corrección razonable: enmascarar solo errores de infraestructura (`QueryException`, `ErrorException`, …) o introducir una excepción de dominio. Supone `APP_DEBUG=false` en producción (no se leyó `.env`). |
| IDG2-001 | Medio — **corregido en FIX-027** | `routes/api.php:49-53` | Solo 2 de 4 rutas públicas pasaron a `auth:api`; `sendserver/{document_id}/{query?}` y `documents/status` (y `services/validate_cpe`, `services/consult_status`) siguen fuera. `sendServer` (`Tenant/DocumentController.php:563`) empuja XML, PDF y `data_json` al servidor offline sin autenticación y falla con 500 si el id no existe. Verificado en el árbol de trabajo. |
| IDG2-002 | Medio — **impacto resuelto por FIX-026; contrato fijado con pruebas en FIX-028** | `Api/DocumentController.php:64-105` | `send` cambió de 500 con mensaje a 404/422 sin estar registrado; las excepciones posteriores (plazo vencido, CDR inválido) no se capturan y, con el `Handler` en modo no debug, el integrador recibe el mensaje genérico. Va unido a IDG1-001. |
| IDG3-001 | Medio | `Padron/PadronController.php:73,80,97-98` | Descarga el padrón por HTTP sin verificar TLS y lo extrae en `public/padron_extract/`; nginx pasa cualquier `.php` a FPM (`default.conf:49`). Un atacante en ruta podría dejar un `.php` ejecutable. Anterior al diff. |
| IDG3-002 | Medio | `Padron/Routes/web.php:20,22`, `PadronController.php:105,166` | `charges_data` y `demo` son GET destructivos; `truncate()` precede a `LOAD DATA` y no es transaccional; `padron` no está en `config/permissions.php`, así que cualquier usuario autenticado del tenant puede dispararlos (y por CSRF con navegación GET, `same_site=lax`). Anterior al diff. IDG3-001/002 coinciden con la tarea pendiente de Padron (POST con CSRF y reemplazo transaccional, solo en base desechable). |

### P2 — corregir en una tanda posterior

- IDG1-002 (Medio): el `Handler` cambia el contrato HTTP (500→404 en `ModelNotFound`, estado real en `HttpException`, desaparecen `file`/`line`); impacto sobre móvil e integradores sin verificar.
- IDG1-005/006: `CertificateController::destroy` borra un PEM de un directorio compartido entre tenants sin vuelta atrás; la clave privada se escribe con permisos por defecto (probablemente 0644).
- IDG1-007: la protección CSP contra SVG solo existe en la imagen docker y `LoginConfigurationController` sigue aceptando SVG.
- IDG1-010: la ruta pública de tipo de cambio no tiene límite de tasa y dispara consultas externas y escrituras.
- IDG1-011: cerrar una caja ya cerrada devuelve 422 y la UI solo hace `console.log`; siguen el cierre por `GET` y la carrera con `cash_document`.
- IDG3-003: `HotelRoomController::changeRoomStatus` no valida transiciones (se puede forzar `DISPONIBLE` en una habitación `OCUPADO` con renta activa); `addProductsToRoom` no comprueba renta finalizada.
- IDG3-004: los `throttle:N,M` numéricos comparten un solo contador por usuario/IP (login, registro, `culqi` y `payment_cash` de la tienda; `consulta`, `charges_data` y `demo` de Padron); `TrustProxies::$proxies` es `null`.
- IDG3-005: `records` y `POST regularize` de Document no llevan `tenant.internal.mode`.
- IDG2-007: `AccountController::updatePlan` escribe en dos conexiones sin atomicidad y cualquier usuario con el módulo `cuenta` puede fijar un `plan_id` sin pago.
- IDG2-003/004/005/006 y IDG3-006/007: sin pruebas de regresión en `ItemController::store`, `ResponseCode` del CDR sin recortar espacios, cliente de tienda con sesión que abre el login del personal queda en 403, cambios del JSON móvil, `FileRequest` dependiente de `guessExtension()`, regularizar `09→05` sin reaplicar stock.

### P3 — información

Restos huérfanos inofensivos (vista `tenant/inventories/index.blade.php`, `Dispatch2Validation`, entradas del classmap de vendor), rutas `POST services/exchange_rate`/`search_exchange_rate` que apuntan a métodos inexistentes (preexistente) y un aviso de PHP 8.4+ por `callable $factory = null` en `CulqiCharger.php:25`. Detalle en los tres informes.

## No verificado en esta revisión

`APP_DEBUG` ni el driver de caché de producción, contenido de `vendor/`, consumidores externos (app móvil e integradores de la API), `FileRequest` con docx/xlsx reales, soporte `webp` de GD, que SUNAT sirva HTTPS, proxy inverso delante de la aplicación, y cualquier comportamiento en ejecución. Es una revisión estática: no sustituye pruebas contra MariaDB, el navegador autenticado con escrituras ni las integraciones SUNAT/OSE/Culqi.
