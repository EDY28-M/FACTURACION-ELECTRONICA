# Informe final de auditoría — FacturadorPro

| Campo | Valor |
|---|---|
| Fecha | 2026-11-18 |
| Rama | `chore/system-audit` |
| `HEAD` | `2064950` («fix(payments): harden Culqi payments and remove the PayPal HTML paste») |
| Base de la rama | `main` (`2064950`); `master` es un commit anterior (`0755d75`, «version 1») |
| Alcance | Auditoría integral del repositorio: backend, frontend, SQL, seguridad, rutas, configuración, diff heredado y pruebas |
| Estado del árbol | Sin commitear: 98 archivos versionados modificados, 5 eliminados y 45 archivos nuevos sin seguimiento (46 con el README); 43/44 era el conteo al consolidar el registro |
| Estado de la auditoría | Fase documental cerrada con este informe; la auditoría integral **no** está terminada porque la verificación con escritura real (N6) sigue pendiente |
| Recomendación | **No publicar ni fusionar todavía.** Hay cinco condiciones P0 abiertas |

Este informe consolida la evidencia existente. No sustituye a los registros originales (`AUDIT_LOG.md`, `fixes-batch1.md`, `PROGRESS.md`, `findings/*`) ni a la verificación del registro consolidado (`VERIFICACION_2026-11-18.md`). Todo lo que aquí se afirma se apoya en esos documentos o en comprobaciones reproducibles ejecutadas el 2026-11-18; lo que no se pudo comprobar se marca como tal.

---

## 1. Resumen ejecutivo

Se auditó el repositorio con la cobertura descrita y una regla estricta: solo lectura y pruebas que no escriben en bases de negocio. Se revisaron los 70 controladores de tenant, los 19 de System/Auth, los 142 archivos de Middleware/Requests/Resources, el núcleo `CoreFacturalo` (123 archivos PHP sin plantillas en `HEAD`), 1379 plantillas, unos 205 modelos y archivos restantes de `app/`, 20 módulos (750 PHP y 210 Vue), 204 Vue de `resources/js`, las rutas, la configuración, Docker/nginx y el diff heredado de 89 archivos de código.

El resultado se registra en 16 informes de hallazgos, 28 correcciones (`FIX-001` a `FIX-028`) y 2 operaciones (`OPS-001`, `OPS-002`). La suite Unit pasó de 218 pruebas / 846 aserciones con 1 error a **336 pruebas / 2097 aserciones** con 0 errores y 2 deprecaciones de PHPUnit. Se añadieron 12 archivos de pruebas PHPUnit y 7 pruebas Node; las 7 pasan. El lint de todo el PHP no encuentra errores de sintaxis.

Nada de esto equivale a un sistema verificado de extremo a extremo. Ninguna prueba escribió en una base de negocio, ninguna petición llegó a SUNAT, OSE o Culqi, no se verificó el `APP_DEBUG` ni el driver de caché de producción, y los consumidores externos de la API no son visibles desde el repositorio. La cobertura máxima llega a **N5** (verificación en ejecución sin escritura) en partes de System/Auth, rutas y frontend, y a **N4** (prueba automatizada + revisión independiente) en las correcciones mejor cubiertas. **Ningún área llega a N6.**

La conclusión operativa es clara: el trabajo es sustancial y está bien registrado, pero **el árbol no es publicable**. Antes de un PR o despliegue hay que commitear por unidades (incluidos cuatro helpers de producción que hoy no están versionados), ejecutar la verificación funcional con escritura en un entorno desechable, confirmar la configuración de producción, avisar los cambios de contrato a los integradores y resolver el despliegue del bundle, cuyos chunks están ignorados por git mientras el manifiesto versionado los referencia.

## 2. Objeto, alcance y método

### 2.1 Objeto

Determinar el estado real del sistema y corregir, sin romperlo, los defectos que pudieran verificarse de forma segura. La prioridad declarada por el usuario fue «continuar sin romper el sistema»; el trabajo se organizó para preservar los cambios heredados y no tocar datos, credenciales ni integraciones reales.

### 2.2 Áreas auditadas

- `app/Http/Controllers/Tenant` (70 controladores, dos mitades).
- `System`, `Auth`, `Middleware`, `Requests`, `Resources`, `Providers`, `Tenancy`, `Console`, rutas y configuración.
- `app/CoreFacturalo` (sin plantillas), `Services`, `Support`, `Models`, `Traits`, `Exports`, `Imports`, `Observers`, y las 1379 plantillas PDF/XML mediante barrido de patrones.
- Módulos de ventas (Document, Sale, Order, Pos, Purchase, Expense, Finance), inventario (Inventory, Item, Report) y operación (Account, Dashboard, Hotel, Ecommerce, BusinessTurn, DocumentaryProcedure, LevelAccess, Offline, Padron, Services).
- Frontend del núcleo (204 Vue), frontend de módulos (210 Vue) y JS público de la tienda (`public/porto-ecommerce`).
- SQL (corrección, concurrencia, rendimiento), seguridad (XSS, CSRF, subidas, secretos, dependencias), rutas/API, Docker/nginx y código muerto.
- Revisión estática del diff heredado en tres grupos (G1: 19 archivos nunca revisados; G2: 24 del núcleo; G3: 46 de módulos).

### 2.3 Reglas de trabajo

1. Sin commits, push, despliegues ni PR.
2. Sin escrituras en bases de negocio: SQLite `:memory:` con funciones fixture o fakes; ninguna prueba contra MariaDB con datos.
3. Sin migraciones, jobs, scheduler ni cambios de esquema.
4. Sin SUNAT, OSE ni Culqi reales; `MockHandler`, cliente SOAP falso y claves sintéticas.
5. Sin leer `.env` ni credenciales; sin reproducir secretos.
6. Sin instalar dependencias. El único build fue `npm run production:js`, autorizado expresamente (OPS-001).
7. No se ejecutaron `tests/Feature/ExampleTest.php` (arranca el stack multi-tenant), `tests/ui/phase2-php83-verification.php` (hace `DROP DATABASE`/`CREATE DATABASE`) ni pruebas Dusk.
8. Un solo escritor de código por vez, con revisión independiente fresca después de cada corrección, salvo las excepciones documentadas (FIX-003 a FIX-010 y FIX-028).

### 2.4 Niveles de evidencia

| Nivel | Significado |
|---|---|
| N0 | No revisado. |
| N1 | Lectura estática dirigida, `php -l`, comprobación de consumidores. |
| N2 | Revisión estática de solo lectura de un área completa, con informe. |
| N3 | Corregido con prueba automatizada. |
| N4 | Corregido con prueba automatizada y revisión independiente fresca. |
| N5 | Verificado en ejecución sin escribir (contenedor, HTTP de solo lectura, navegador autenticado en solo lectura). |
| N6 | Verificado con escritura real o integración real. **Ninguna área llega aquí.** |

## 3. Estado verificado del repositorio

| Concepto | Cantidad |
|---|---|
| Archivos versionados modificados | 98 |
| Archivos versionados eliminados | 5 |
| Entradas en `git diff --name-status` | 103 (2096 inserciones, 2177 eliminaciones) |
| Archivos sin seguimiento | 45 (46 con el README); 43/44 era el conteo al consolidar el registro |
| Correcciones registradas | 28 (`FIX-001` a `FIX-028`) |
| Operaciones registradas | 2 (`OPS-001`, `OPS-002`) |
| Pruebas PHPUnit nuevas | 12 archivos |
| Pruebas Node nuevas | 7 archivos |
| Commit | Ninguno; `HEAD` sigue en `2064950` |

**Archivos de producción sin versionar.** Cuatro helpers nuevos son necesarios para que el código actual funcione y hoy no están en git: `app/CoreFacturalo/Helpers/Storage/TempImageHelper.php` (FIX-011), `app/Services/Payments/CulqiErrorPolyfills.php` (FIX-001), `modules/Finance/Helpers/PaymentFileHelper.php` (FIX-013) y `resources/js/helpers/pos_draft.js` (FIX-022). Un commit que los omita rompe ItemController, FilePaymentTrait, el POS y CulqiCharger.

**Eliminaciones.** `Dispatch2Controller.php`, `InventoryController.php`, `InventoryController2.php`, `excel_concar_bk.blade.php` y `TakeProductoViewComposer.php`. No hay referencias colgantes: la única mención a `TakeProductoViewComposer` está dentro de un comentario de bloque en `ViewServiceProvider.php`; `Dispatch2Validation` es otra clase (validación de request), no el controlador eliminado.

**Build.** `public/js/manifest.js`, `manifest.js.map` y `mix-manifest.json` están modificados (+21/−5 en total; +19/−3 es solo `mix-manifest.json`). Los 8 chunks nuevos y sus 8 mapas existen en disco y están ignorados por `.gitignore`, pero el manifiesto versionado los referencia. `app.js` y el CSS no cambiaron.

## 4. Trabajo realizado

### 4.1 Auditoría de solo lectura

Ocho informes por área reúnen 209 encabezados de hallazgo (24 core, 17 inventory, 37 ops, 28 sales, 20 security, 27 system-auth, 31 tenant-1, 25 tenant-2). La reconciliación documental del 2026-11-17 los clasificó en 4 críticos, 58 altos, 95 medios y 52 bajos (Engram #875; ese conteo no se recomputa desde los informes y se acepta como declaración). A ellos se suman los informes de frontend (5 hallazgos), SQL (8), código muerto (3) y la revisión estática del diff heredado (0 críticos, 1 alto, 5 medios, 20 bajos, 13 informativos).

### 4.2 Correcciones

| Bloque | IDs | Contenido | Verificación |
|---|---|---|---|
| Pagos / Padron / operación | FIX-001 a FIX-010 | Fallback Guzzle de Culqi; rutas y descarga de Padron; Dashboard; ServiceData; Hotel (bloqueos y transacción); DocumentaryProcedure; Account; Offline; Ecommerce; código muerto y `/levelaccess` | `php -l` y revisión de consumidores. Sin prueba funcional propia; FIX-016 cubrió después partes de Hotel y Finance. FIX-003 queda «corregido sin verificación completa» |
| Backend núcleo | FIX-011 a FIX-020 | Imágenes temporales por propietario; CDR/SOAP sin aceptación por coerción; adjuntos de pagos; frontera client/backoffice; reseller con CSRF; consultas de servicios, cuentas por pagar y Hotel; serialización de usuarios; reversión de stock por transición; regularización acotada y atómica; testabilidad del fallback de pagos | Prueba automatizada + revisión independiente en todos. Suite acumulada hasta 302/1893 |
| Frontend | FIX-021 a FIX-025 | Doble envío de pagos; borrador del POS aislado por usuario; checkout de Hotel con reintento solo-finalizar; XSS del carrito de la tienda (incluido `main.min.js` parcheado a mano); loader del pago en efectivo | Pruebas Node + revisión independiente. Sin navegador con escritura ni Vue real |
| Manejo de errores y API | FIX-026 a FIX-028 | `Handler` deja de enmascarar mensajes de negocio en 5xx; cierre de `documents/status` y `sendserver` y borrado de dos rutas muertas; prueba de contrato de `send` | FIX-026/027 con prueba + revisión; FIX-028 solo prueba, sin revisión independiente |
| Operaciones | OPS-001, OPS-002 | Build `production:js`, verificación de chunks y rollback simulado, navegador autenticado en solo lectura; reverificación en contenedor 8.3.36 y HTTP real de solo lectura | Registradas; sin revisión independiente |

### 4.3 Pruebas añadidas

- **PHPUnit (12):** `SunatResponseTest`, `TempImageHelperTest`, `PaymentFileTest`, `CustomerAccessBoundaryTest`, `ResellerAccessTest`, `QueryCorrectnessTest`, `UserSerializationTest`, `StockReversalTest`, `ValidateDocumentRetryTest`, `ExceptionHandlerTest`, `ApiRouteAuthTest`, `ApiDocumentSendTest`. Todos se recogen por la suite Unit de `phpunit.xml`; no hay CI.
- **Node (7):** `payments-double-submit`, `pos-draft-isolation`, `hotel-checkout-retry`, `ecommerce-cart-xss`, `ecommerce-public-cart-xss`, `ecommerce-main-min-xss`, `ecommerce-cash-loader`. No están enlazadas a `package.json`; se ejecutan a mano.

## 5. Evidencia de verificación

Ejecutado el 2026-11-18 en `facturador_fpm` (PHP 8.3.36), salvo donde se indica.

| Verificación | Resultado |
|---|---|
| Lint de todo el PHP | 0 errores de sintaxis; 3278 archivos con el `find` actual (3277 en OPS-002; +1 por `ApiDocumentSendTest.php`) |
| Suite Unit completa | **336 pruebas / 2097 aserciones**, 0 errores, 0 fallos, 2 deprecaciones de PHPUnit |
| Cada archivo PHPUnit nuevo | Coincide con el conteo registrado: 13/407, 7/26, 12/144, 9/58, 8/73, 9/45, 5/91, 8/61, 7/54, 11/84, 14/67, 9/53; `PaymentsTest` 21/144 |
| 7 pruebas UI Node | Todas en verde |
| `npm run test:dashboard` | Verde con `TMPDIR` propio (dashboard, carrera del gráfico, shells, publicación JS y cinco escenarios de rollback) |
| `chart-month-boundaries.php` | 5 casos aprobados |
| HTTP real de solo lectura | `documents/status`, `sendserver` y `documents` → 401 sin token; `validate_cpe` → 404 |
| Navegador autenticado (solo lectura) | POS, cuatro listados de pagos y checkout de Hotel cargaron sus chunks nuevos con 200 y sin errores de consola; no se emitió, guardó, pagó ni finalizó nada |
| Validación en navegador del 2026-11-18 | Login de sistema y del tenant KeraAI, dashboard, POS, comprobantes, notas, compras, gastos, reportes, hotel y tienda cargan sin errores; FIX-022 y FIX-024 confirmados en ejecución; se encontraron dos familias de 500 preexistentes (AUD-01, AUD-02); la validación integral por capas está en la sección 11 de `VERIFICACION_2026-11-18.md` |

Lo que esta evidencia **sí** prueba: que el código actual pasa lo que se escribió, que las correcciones con prueba fijan el comportamiento previsto en SQLite/fakes y que el bundle servido contiene los cambios de FIX-021/022/023.

Lo que **no** prueba: que los flujos funcionen contra MariaDB con datos, que las carreras de numeración/stock no ocurran, que SUNAT/OSE/Culqi respondan como se espera, que `APP_DEBUG=false` en producción, ni que los consumidores externos sigan funcionando.

## 6. Cobertura

| Área | Nivel máximo | Cobertura |
|---|---|---|
| `CoreFacturalo` y WS/SUNAT | N4 offline (CDR/SOAP con fake) | Lectura completa de archivos no-plantilla; aceptación fiscal corregida; sin envío real |
| Pagos (Culqi) | N4 offline | Fallback HTTP y clasificación probados; sin cobro real, sandbox ni idempotencia |
| Controladores Tenant | N4 en partes corregidas | 70 controladores leídos; la mayoría de los hallazgos TC1/TC2 sigue sin corrección |
| System/Auth/Middleware/rutas | N5 en solo lectura; N4 en partes | Reseller, guards de client y rutas API corregidos; `route:list` sin línea base |
| Config/Docker/nginx | N2 | Lectura y revisión estática; sin comportamiento en ejecución del nginx modificado |
| Modelos | N4 solo `User` | Serialización corregida; resto por patrón |
| Módulos de ventas | N4 en partes corregidas | Rutas/controladores/requests; vistas Blade y Vue fuera |
| Inventario | N4 (reversión de stock en SQLite) | Concurrencia real sin probar; 17 hallazgos MIN-* en su mayoría abiertos |
| Módulos de operación | N4 en FIX-024/025 y en las partes de Hotel/Finance de FIX-016; FIX-002–010 en N1 | Sin prueba funcional propia para el primer lote; Padron y BusinessTurn con hallazgos RISKY |
| Frontend núcleo y módulos | N4 en partes; N5 solo carga en navegador | Resto de los 204+210 Vue sin ejecución real |
| Tienda pública | N4 | XSS del carrito y loader de efectivo corregidos; `culqi()` y tokens sin escapar siguen abiertos |
| SQL | N4 (SQLite) y N5 (metadatos) | Sin EXPLAIN, latencias ni concurrencia MariaDB; SQL-003/005/007/008 abiertos |
| Seguridad | N4 en subidas/clientes/reseller/XSS de tienda; N1 en barridos | 1379 plantillas solo por patrón; 5 avisos de Laravel y 170 de npm sin tratar |
| Diff heredado | N2 | 89 archivos revisados estáticamente; sin ejecución |
| Pruebas | N4 según cada FIX | Sin CI; Feature y Dusk no ejecutadas; pruebas Node sin enlazar |

## 7. Riesgos y pendientes

### 7.1 P0 — condiciones para publicar

| ID | Condición | Por qué |
|---|---|---|
| P0-01 | Commitear por unidades de trabajo y revisar el diff completo antes de un PR, incluidos los cuatro helpers sin seguimiento | Nada está a salvo; olvidar un archivo nuevo rompe código. El diff supera ampliamente las 400 líneas y exige una estrategia de PR |
| P0-02 | Verificación funcional con escritura real en entorno desechable: FIX-021 a FIX-025, flujos de emisión/pago/anulación de FIX-012/013/017/018/019 y las correcciones FIX-003 a FIX-010 que nunca tuvieron prueba funcional | Todo se verificó con fakes, SQLite o lectura; ningún flujo se vio funcionar contra datos |
| P0-03 | Confirmar `APP_DEBUG=false` y el driver de caché de producción, y ejercitar el enmascarado del `Handler` donde corresponda | En local `APP_DEBUG=true`; el comportamiento real de producción nunca se observó |
| P0-04 | Comunicar a integradores y app móvil los cambios de contrato y comprobar sus tokens | Varias rutas cambian a 401/404/422; los consumidores externos no son visibles desde el repositorio |
| P0-05 | Resolver el despliegue del bundle y de los estáticos (`chunk-*.js` ignorado; `main.min.js` sin cache-busting con caché de 30 días) | Un despliegue solo desde git dejaría un manifiesto que apunta a archivos inexistentes; navegadores recurrentes podrían seguir ejecutando el JS vulnerable |

### 7.2 P1 — riesgo alto o decisión pronta

1. **Padron (IDG3-001/002):** descarga HTTP sin verificar TLS, extracción en `public/padron_extract/` (nginx pasa `.php` a FPM), GET destructivos, `truncate()` no transaccional y sin permiso de módulo. Plan: POST + CSRF, staging transaccional, validación del archivo, solo en base desechable.
2. **`tenancy:run` no registrado** en el scheduler; registrarlo puede activar trabajos de negocio reales. Revisar runtime y trabajos antes.
3. **Concurrencia de numeración y stock en MariaDB:** no hay claves únicas de `(soap_type_id, document_type_id, series, number)`, `external_id` ni `(item_id, warehouse_id)`. Números repetidos y pérdida de movimientos bajo emisión simultánea.
4. **Integraciones reales SUNAT/OSE/Culqi (sandbox):** envío, CDR, rechazo, regularización, cobro, 3DS e idempotencia.
5. **Flujo `culqi()` de la tienda:** loader sin `try/catch` ni guard, `token_public_culqui` y `tag_*` sin escapar, `ReferenceError` por `order`.
6. **Idempotencia en servidor** para pagos y emisión; los guards actuales son solo de interfaz.
7. **Fugas residuales de mensajes:** `ContractBillingService` reenvuelve `Throwable` como `\Exception`; `GreConnectionException` incluye host/URL; unos 25 `catch` devuelven `getMessage()` directamente.
8. **Contrato del `Handler`:** 500→404 en `ModelNotFound`, estado real en `HttpException`, desaparecen `file`/`line`; impacto en móvil e integradores sin verificar.
9. **MariaDB publicada en `0.0.0.0:3307`** (observado con `docker ps`), aunque el compose versionado documenta `127.0.0.1`. Recrear con `DB_BIND=127.0.0.1` y revisar firewall.
10. **Dependencias:** 5 avisos de `laravel/framework 10.50.4` (uno alto) y 170 vulnerabilidades npm (20 críticas), casi todas transitivas de build.

### 7.3 P2/P3 — tanda posterior

Formularios hermanos sin guard de doble envío; estado de Hotel tras recargar y transiciones de habitación; permisos del certificado y clave privada; CSP de SVG solo en Docker; throttle compartido y `TrustProxies` nulo; `tenant.internal.mode` ausente en `records`/`regularize`; atomicidad de `updatePlan`; pruebas de regresión de IDG2-003/004/005/006 e IDG3-006/007; hallazgos RISKY de `fixes-batch1.md` (MOP-001, 003, 004, 005, 006, 009, 011 TLS, 012, 017, 018–021, 023, 027, 028, 034, 037); SQL-005/006/008 y DEAD-002; enlazar las pruebas a `package.json` y CI; `route:cache`; temporales huérfanos; `INFORME_FINAL` ya no es pendiente.

La validación en navegador del 2026-11-18 añadió dos defectos **preexistentes en `HEAD`**, ajenos a las correcciones: `GET /ecommerce/login` devuelve 500 porque `EcommerceController::showLogin()` usa `ecommerce::user.login` y esa vista no está en el namespace del módulo (AUD-01; el POST AJAX del modal sí funciona); y las subconsultas de pagos de finanzas usan la conexión por defecto `tenancy` en vez de la tenant, lo que rompe `/finances/unpaid/records`, `/finances/unpaid/unpaidall`, `/finances/to-pay/records`, `/finances/to-pay/pdf` y `/finances/to-pay/to-pay-all` (AUD-02; ocho subconsultas en `DashboardView`, `ToPay` y `AccountsReceivable`). Detalle, evidencia y corrección propuesta en `VERIFICACION_2026-11-18.md`, secciones 9 y 11.

### 7.4 Cambios que rompen compatibilidad

- `POST /api/reseller/detail` y `POST /api/reseller/lockedAdmin`: ahora exigen sesión de System admin y CSRF.
- `POST /api/documents/status` y `GET /api/sendserver/{id}`: 401 sin token (403 con token client o tenant bloqueado).
- `services/validate_cpe` y `services/consult_status`: eliminadas (404).
- `Api\DocumentController@send`: comprobaciones previas pasan de 500 a 404/422; `documentCheckServer` devuelve 404 JSON.
- `Handler`: 404 en `ModelNotFound`, estado real en `HttpException`, sin `file`/`line`; excepciones de librerías salen como «Error interno del servidor».
- Cuentas tenant de tipo `client`: 403 en `auth`/`auth:api` y login móvil; nuevos clientes de tienda sin token API.
- `User` oculta `password`, `remember_token` y `api_token` al serializar.
- Cargas temporales de imágenes/adjuntos deben provenir de la misma sesión y tener menos de dos horas.
- Un CDR con estructura, namespace o código inválido se rechaza en vez de aceptarse.
- Subidas: SVG fuera y WebP dentro de las listas; `uploadFile` de Ecommerce solo acepta `image` con `type` listado.
- Nombre de ruta del POST de login de tienda: `tenant_ecommerce_login_post`.
- `throttle` añadido a login, registro, `culqi`, `payment_cash` y Padron.
- Padron y `/levelaccess` requieren dominio de tenant y autenticación.
- Cierre de caja ya cerrada: 422; `ExchangeRateController::store` pasa a upsert; `lots_group` de Dispatch cambia de forma.

## 8. Verificación del registro consolidado

Se comprobó el README contra el repositorio y las pruebas. El resultado está en `VERIFICACION_2026-11-18.md`: el registro es sustancialmente cierto y las cifras centrales se reproducen. Esta comprobación la hizo el mismo agente que continúa la sesión: acredita los hechos recomputables, no sustituye una revisión independiente. La validación integral por capas (frontend, backend, base de datos y consistencia documental) está en la sección 11 del mismo documento. Se encontraron ocho observaciones —siete matices y una confirmación—, ninguna de fondo:

1. «No hay pruebas Dusk» es inexacto: hay restos en `tests/Browser` (2 clases) sin `laravel/dusk` instalado; no se ejecutaron.
2. El build suma +21/−5 en los tres archivos; +19/−3 es solo `mix-manifest.json`.
3. El lint cuenta hoy 3278 archivos (3277 en OPS-002; +1 por la prueba de FIX-028).
4. `CoreFacturalo` tiene 123 archivos PHP sin plantillas en `HEAD` y 124 hoy por el helper de FIX-011; la cifra 131 de `core.md` sigue sin reconciliar.
5. `PROGRESS.md` dice 29 archivos en `tests/`; hay 33 versionados en `HEAD`.
6. La base de la rama queda resuelta: `main` = `HEAD`; `master` es anterior. La rama se creó desde `main` en la práctica.
7. El «Info 4» de G2 frente a 5 entradas y la exposición viva de MariaDB ya estaban anotados y se confirman.
8. El resumen de FIX-005 atribuía `lockForUpdate` a `addProductsToRoom`; el código y el registro detallado solo tienen transacción. Corregido en el README.

## 9. Conclusión y recomendación

**La auditoría documental está cerrada; la auditoría integral no.** Se ha revisado y corregido mucho, con un registro inusualmente cuidadoso y una disciplina estricta de no tocar producción. Pero la evidencia disponible no permite afirmar que el sistema funcione de extremo a extremo. Ninguna corrección se ha visto operar contra MariaDB con datos, ninguna integración fiscal o de pagos se ha ejercitado de verdad y el árbol de trabajo no está versionado.

**Recomendación:** no abrir PR ni desplegar todavía. El orden responsable es:

1. Commitear por unidades de trabajo, empezando por los cuatro helpers sin seguimiento y las pruebas nuevas; revisar el diff completo.
2. Montar un entorno desechable con copia de datos y ejecutar la verificación funcional con escritura de los flujos críticos, incluidas las correcciones FIX-003 a FIX-010 que nunca tuvieron prueba funcional.
3. Confirmar `APP_DEBUG`, caché, proxy y HTTPS de producción; ejercitar el `Handler` en modo producción.
4. Avisar a integradores y app móvil de los cambios de contrato y probar sus tokens.
5. Resolver el despliegue del bundle (build en destino o publicar `public/js` completo) y el cache-busting de la tienda.
6. Planificar las integraciones reales en sandbox (SUNAT/OSE/Culqi) y la idempotencia de servidor.
7. Cerrar la revisión independiente que falta (FIX-028) y la del diff final antes del merge.

El criterio para declarar terminada la auditoría integral no es que pasen las pruebas actuales, sino que las cinco condiciones P0 estén cerradas y que exista evidencia N6 de los flujos de emisión, pago, anulación e inventario en un entorno equivalente a producción.

## 10. Anexos

### A. Inventario

- **Modificados (98):** 33 en `app/`, 46 en `modules/`, 6 en `resources/`, 6 en `public/`, 2 en `routes/`, y uno en `tests/`, `docker/`, `config/`, `composer.json` y `composer.lock`.
- **Eliminados (5):** los tres controladores muertos de Tenant, la vista `excel_concar_bk` y `TakeProductoViewComposer`.
- **Nuevos (45 + README):** 4 helpers de producción, 12 pruebas PHPUnit, 7 pruebas Node y 22 documentos de auditoría (los 20 originales más `VERIFICACION_2026-11-18.md` y este informe; 23 contando el README).
- **Build:** 3 archivos versionados modificados; 8 chunks y 8 mapas nuevos ignorados por git.
- El detalle archivo por archivo está en `README.md`, apéndice A; no se duplica aquí para no crear una segunda fuente que se desincronice.

### B. Reproducción

```bash
# Estado
git rev-parse --short HEAD && git status --short && git diff --stat | tail -1

# Lint (contenedor)
docker exec facturador_fpm sh -c 'cd /var/www/html && find app modules routes config database tests bootstrap -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" -not -path "*/storage/*" -print0 | xargs -0 -n 50 -P 4 php -l 2>&1 | grep -v "^No syntax errors detected"'

# Suite Unit (contenedor)
docker exec facturador_fpm sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite Unit'

# Pruebas UI
TMPDIR=<dir> npm run test:dashboard
node tests/ui/payments-double-submit.test.js
node tests/ui/pos-draft-isolation.test.js
node tests/ui/hotel-checkout-retry.test.js
node tests/ui/ecommerce-cart-xss.test.js
node tests/ui/ecommerce-public-cart-xss.test.js
node tests/ui/ecommerce-main-min-xss.test.js
node tests/ui/ecommerce-cash-loader.test.js

# Gráfico de límites
docker exec facturador_fpm sh -c 'cd /var/www/html && php tests/ui/chart-month-boundaries.php'
```

No ejecutar `tests/ui/phase2-php83-verification.php` (hace `DROP DATABASE`/`CREATE DATABASE`) ni la suite Feature sin una base desechable.

### C. Mapa de documentos

| Documento | Papel |
|---|---|
| `README.md` | Registro consolidado y mapa |
| `PROGRESS.md` | Fases, áreas, sesiones y prioridades residuales |
| `AUDIT_LOG.md` | FIX-001, FIX-002, FIX-011 a FIX-028, OPS-001/002 |
| `fixes-batch1.md` | FIX-003 a FIX-010 y subpartes MOP no aplicadas |
| `BASELINE.md` | Línea base previa a los cambios |
| `findings/*` | 16 informes de hallazgos y del diff heredado |
| `VERIFICACION_2026-11-18.md` | Comprobación del registro consolidado |
| `INFORME_FINAL.md` | Este informe |

### D. Glosario

`FIX-NNN`: corrección registrada. `OPS-NNN`: operación que no es corrección de código. `TC1/TC2`: controladores Tenant. `SYS`: System/Auth/Middleware/rutas/config. `CORE`: CoreFacturalo y núcleo. `MSL`: módulos de ventas. `MIN`: inventario. `MOP`: módulos de operación. `SEC`: seguridad. `FEC/FEM`: frontend núcleo/módulos. `SQL`: consultas y concurrencia. `DEAD`: código muerto. `IDG1/2/3`: hallazgos del diff heredado por grupo. `SAFE/RISKY`: auto-fix acotado frente a cambio que exige decisión. `CDR`: constancia de recepción de SUNAT. `GRE`: guía de remisión electrónica. `OSE`: operador de servicios electrónicos. `N0–N6`: niveles de evidencia. `Engram`: memoria persistente del proyecto.
