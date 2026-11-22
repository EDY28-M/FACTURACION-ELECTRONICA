# Auditoría de FacturadorPro — registro único de lo hecho, lo pendiente y la cobertura

Fecha del documento: 2026-11-18. Rama de trabajo: `chore/system-audit` (creada el 2026-11-16 según `PROGRESS.md`; el repositorio tiene las ramas `main` y `master`). `HEAD` es `2064950`.

Estado general:

- La auditoría NO está terminada y el árbol de trabajo NO está listo para publicar.
- Nada está commiteado, subido ni desplegado: 98 archivos versionados modificados, 5 eliminados y 43 sin seguimiento, 44 contando este README (recontados con git el día de este documento).
- Hay 28 correcciones registradas (`FIX-001` a `FIX-028`) y 2 operaciones (`OPS-001`, `OPS-002`). La cobertura se describe por niveles de evidencia, no por porcentajes (sección 7).
- Ninguna verificación llegó a escribir en bases de negocio ni a usar SUNAT, OSE o Culqi reales (sección 7.3).
- El informe final `docs/audit/INFORME_FINAL.md` fue redactado el 2026-11-18; la auditoría integral sigue abierta por la verificación N6.

> **Addendum de verificación (2026-11-18, cierre documental).** Este registro se comprobó contra el repositorio después de escribirlo. Las cifras centrales se reproducen; el detalle está en `VERIFICACION_2026-11-18.md`. Correcciones: (a) sí existen restos de pruebas Dusk en `tests/Browser` (2 clases), aunque `laravel/dusk` no está instalado y no se ejecutaron; (b) el build de OPS-001 suma +21/−5 en los tres archivos versionados (+19/−3 es solo `mix-manifest.json`); (c) el lint cuenta hoy 3278 archivos (3277 en OPS-002; +1 por la prueba de FIX-028); (d) `CoreFacturalo` tiene 123 archivos PHP sin plantillas en `HEAD` y 124 hoy por el helper de FIX-011; (e) `tests/` tiene 33 archivos versionados en `HEAD`, no 29; (f) la base real de la rama es `main` (`HEAD`), no `master`; (g) el «Info 4» de G2 frente a 5 entradas queda como discrepancia documental; (h) la fila resumen de FIX-005 atribuía `lockForUpdate` a `addProductsToRoom`, que solo tiene transacción; corregido. `PROGRESS.md` se actualizó en las fases 4, 5 y 7. Tras añadir `VERIFICACION_2026-11-18.md` e `INFORME_FINAL.md`, el no seguimiento pasa a 45 (46 con el README); el 43/44 de la cabecera es el conteo del momento de la consolidación. La validación en navegador posterior (credenciales del usuario, solo lectura) confirmó FIX-022 y FIX-024 y encontró dos familias de 500 preexistentes: `GET /ecommerce/login` (AUD-01) y las subconsultas de pagos de finanzas en unpaid/to-pay y exportaciones (AUD-02, ocho subconsultas en `DashboardView`, `ToPay` y `AccountsReceivable`); detalle en `VERIFICACION_2026-11-18.md`, secciones 9 y 11 (validación integral por capas).

## Tabla de contenido

1. [Cómo leer este documento y mapa de `docs/audit/`](#1-cómo-leer-este-documento-y-mapa-de-docsaudit)
2. [Reglas y restricciones de trabajo](#2-reglas-y-restricciones-de-trabajo)
3. [Cronología por fases y por sesiones](#3-cronología-por-fases-y-por-sesiones)
4. [Todo lo que se hizo: catálogo de correcciones](#4-todo-lo-que-se-hizo-catálogo-de-correcciones)
5. [Pruebas añadidas](#5-pruebas-añadidas)
6. [Apéndice A: inventario de modificaciones por archivo](#6-apéndice-a-inventario-de-modificaciones-por-archivo)
7. [Cobertura de la auditoría (cualitativa)](#7-cobertura-de-la-auditoría-cualitativa)
8. [Todo lo que falta: pendientes priorizados](#8-todo-lo-que-falta-pendientes-priorizados)
9. [Riesgos, avisos de despliegue y cambios que rompen compatibilidad](#9-riesgos-avisos-de-despliegue-y-cambios-que-rompen-compatibilidad)
10. [Cómo reproducir las verificaciones](#10-cómo-reproducir-las-verificaciones)
11. [Decisiones tomadas](#11-decisiones-tomadas)
12. [Glosario de IDs](#12-glosario-de-ids)

Nota de numeración: las secciones 5 (pruebas) y 6 (apéndice A, inventario por archivo) están en ese orden de lectura (qué se hizo, qué pruebas, qué archivos); el encargo las numeraba al revés. El resto de los capítulos conserva su numeración.

---

## 1. Cómo leer este documento y mapa de `docs/audit/`

Este README es un índice y una consolidación. La fuente de verdad sigue siendo cada registro original; cuando dos fuentes discrepan se prefiere la entrada más reciente y se anota la discrepancia (sección 1.3). Todo lo escrito aquí proviene de `docs/audit/*`, de la memoria persistente (Engram, proyecto `facturadorpro4`) o de la salida de `git` recomputada hoy. Lo que los registros no dicen se marca «no consta en los registros».

### 1.1 Mapa de documentos

| Documento | Contenido |
| --- | --- |
| `PROGRESS.md` | Inventario inicial, tabla de fases, áreas de auditoría, reanudaciones por día (2026-11-16, 2026-11-17, 2026-11-18) y «Prioridades residuales». |
| `AUDIT_LOG.md` | Registro formal de correcciones `FIX-001`, `FIX-002`, `FIX-011` a `FIX-028`, y de `OPS-001` y `OPS-002`; sección «Pendientes y no aplicados» (hoy solo la exposición de MariaDB:3307). |
| `fixes-batch1.md` | `FIX-003` a `FIX-010` (módulos de operación) y la lista larga de subpartes de `MOP-*` no aplicadas por riesgo o alcance. |
| `BASELINE.md` | Línea base previa a cualquier cambio: lint, PHPUnit, pruebas UI, lo que se omitió y comandos para reproducirla. |
| `findings/tenant-controllers-1.md`, `tenant-controllers-2.md` | Hallazgos `TC1-*` y `TC2-*` de los 70 controladores de `app/Http/Controllers/Tenant` (35 + 35). |
| `findings/system-auth-infra.md` | Hallazgos `SYS-*`: System, Auth, Middleware, Providers, Tenancy, Console, rutas y config. |
| `findings/core.md` | Hallazgos `CORE-*`: `CoreFacturalo`, Services, Support, Models, Traits, Exports, Imports, Observers. |
| `findings/modules-sales.md` | Hallazgos `MSL-*`: Document, Sale, Order, Pos, Purchase, Expense, Finance. |
| `findings/modules-inventory.md` | Hallazgos `MIN-*`: Inventory, Item, Report. |
| `findings/modules-ops.md` | Hallazgos `MOP-*`: Account, Dashboard, Hotel, Ecommerce, BusinessTurn, DocumentaryProcedure, LevelAccess, Offline, Padron, Services. |
| `findings/security.md` | Hallazgos `SEC-*` y barridos por patrón (XSS, CSRF, subidas, secretos, dependencias). |
| `findings/frontend-core.md`, `frontend-modules.md` | `FEC-001`, `FEC-002`, `FEM-001`, `FEM-002`, `FEM-003` (frontend, cobertura limitada). |
| `findings/sql.md` | `SQL-001` a `SQL-008` (corrección, concurrencia, rendimiento). |
| `findings/dead-code.md` | `DEAD-001` a `DEAD-003`. |
| `findings/inherited-diff.md` | Índice consolidado de la revisión estática del diff heredado, con prioridades P1/P2/P3. |
| `findings/inherited-diff-g1.md`, `g2.md`, `g3.md` | Informes completos de los tres revisores (G1: 19 archivos; G2: 24 archivos del núcleo; G3: 46 archivos de `modules/*`). |
| `INFORME_FINAL.md` | Informe final de la auditoría, redactado el 2026-11-18; la verificación N6 sigue pendiente. |
| `README.md` | Este documento. |

### 1.2 Cómo se registran las cosas

- Cada corrección aplicada lleva un `FIX-NNN` correlativo. Los estados posibles definidos en `AUDIT_LOG.md` son `Corregido y verificado`, `Corregido sin verificación completa` y `Pendiente`.
- `OPS-NNN` registra operaciones que no son correcciones de código (build, verificación en navegador, verificación en contenedor).
- «Revisión independiente» significa que un revisor con contexto nuevo, distinto del escritor, examinó el cambio. Cuando no la hubo, la tabla lo dice.

### 1.3 Discrepancias entre fuentes que conviene conocer

| Tema | Qué dicen las fuentes | Cómo se trata aquí |
| --- | --- | --- |
| Estado de fases 4 y 5 | `PROGRESS.md` (tabla de fases) conserva «Pendiente» para la fase 4 (frontend) aunque su propio texto registra `FIX-021` a `FIX-025`. | Se usa el estado real según los registros (sección 3.1). La tabla de `PROGRESS.md` está desactualizada en esas filas. |
| Rama de origen | `PROGRESS.md` dice «creada desde `main`»; el repositorio tiene `main` y `master`, y el contexto de trabajo usa `master` como rama principal. | Se cita tal cual; no se verificó cuál de las dos es la base real. |
| Archivos de `CoreFacturalo` | `PROGRESS.md` cuenta 123 archivos PHP (sin `Templates`); `findings/core.md` dice 131 archivos no-plantilla leídos. | Se cita cada cifra con su fuente; no se reconcilió. |
| Plantillas | `PROGRESS.md`: 1379; `security.md`: «1,380». | Se usa 1379 (inventario) y se aclara que solo hubo barrido por patrones. |
| Conteo de `Info` en G2 | `inherited-diff-g2.md` resume «Info 4» pero lista 5 entradas `Info` (`IDG2-008` a `IDG2-012`). | Se anota; el índice consolidado usa 4. |
| Línea base de PHPUnit | `BASELINE.md`: 218 pruebas / 846 aserciones con 1 error; `AUDIT_LOG.md` (`FIX-001`): 218 / 866 con 0 errores. | Son dos momentos distintos (antes y después de `FIX-001`). |
| Exposición de MariaDB:3307 | La auditoría la registra como «publicada en todas las interfaces». El `docker-compose.yml` versionado documenta por defecto `127.0.0.1:3307` (`DB_BIND`). | Resuelto el «no consta» el 2026-11-18: `docker ps` mostró el contenedor `facturador_mariadb` publicado como `0.0.0.0:3307->3306/tcp, [::]:3307->3306/tcp`, es decir, el estado vivo expone la base en todas las interfaces aunque el compose versionado use `127.0.0.1` por defecto (el contenedor se creó con `DB_BIND` distinto o con una versión anterior del compose; no se verificó cuál). Queda como revisión de infraestructura (P1-09). |
| `FIX-011` y `TC2-002` | La reconciliación documental de Engram (#875) señala que `FIX-011` lista `TC2-002` fuera de su alcance real. | `TC2-002` no se considera cerrado por `FIX-011`. |

---

## 2. Reglas y restricciones de trabajo

Estas reglas salen del traspaso de Engram #928 (y su actualización #938), de la preferencia #839 («continuar sin romper el sistema») y de los registros de cada `FIX`. Se respetaron durante toda la auditoría.

### 2.1 Qué NO se hizo, por regla

- Sin commits, sin push, sin despliegues y sin PR. El árbol de trabajo sigue sin commitear.
- Sin mutar datos de negocio: ninguna prueba escribe en bases configuradas. Los efectos de base se probaron solo con SQLite `:memory:` (con funciones fixture para JSON/MySQL) o con fakes.
- Sin migraciones, sin ejecutar jobs ni el scheduler, sin cambiar esquema.
- Sin cobros reales ni integraciones reales: no hubo peticiones a SUNAT, OSE ni Culqi. Las pruebas de pagos usan `MockHandler` y claves sintéticas.
- Sin leer `.env` ni credenciales; no se reproducen secretos en ningún documento.
- Sin instalaciones de dependencias. El único build permitido fue `npm run production:js` (autorizado explícitamente por el usuario en `OPS-001`); no se ejecutó `npm run production` (Sass/CSS) ni `watch`.
- No se ejecutaron `tests/Feature/ExampleTest.php` (su `GET /` arranca el stack multi-tenant y puede tocar la base del sistema) ni `tests/ui/phase2-php83-verification.php` (ejecuta `DROP DATABASE IF EXISTS`/`CREATE DATABASE`). Tampoco hay pruebas Dusk.

### 2.2 Cómo se trabajó

- Un solo escritor de código por vez, con una revisión independiente fresca después de cada corrección (salvo las excepciones señaladas en la sección 4).
- Correcciones pequeñas, con prueba roja antes cuando fue posible, y verificación en el contenedor `facturador_fpm`.
- Se conservaron los cambios heredados del usuario y, en especial, la retirada del SDK `culqi/culqi-php` en `composer.json` y `composer.lock`. No se restauró nada con `git reset`/`git checkout` sobre cambios ajenos; cada tanda tomó un snapshot privado previo.
- Los comandos de lectura del repositorio se hicieron con `bat`, `rg`, `fd` y `eza`.
- Los temporales de prueba van al directorio de trabajo de la sesión (scratchpad), no al temporal del sistema.

### 2.3 Diferencias del entorno de verificación

| Entorno | Detalle |
| --- | --- |
| Contenedor `facturador_fpm` | PHP 8.3.36 (NTS); es la versión de la línea base y el entorno de referencia para PHPUnit. El repo está montado en lectura/escritura en `/var/www/html`. |
| Host macOS | PHP 8.5.5 (no es el runtime soportado) y Node v24.14.0. Las pruebas PHP del host se usaron como comprobación adicional. |
| Docker caído | Durante `FIX-026` y `FIX-027` el daemon de Docker estaba caído: esas dos correcciones se verificaron en el PHP 8.5.5 del host con `memory_limit=1G` (con el valor por defecto de 128 M `SireReconcilerTest` agota memoria por `ZipArchive`, ajeno a los cambios). `OPS-002` las reconfirmó después en el contenedor 8.3.36. |
| Docker activo | Contenedores `facturador_fpm`, `facturador_web` (publica `:8080`) y `facturador_mariadb`. |
| `APP_DEBUG` local | El contenedor local corre con `APP_DEBUG=true` (las respuestas de error incluyen `file` y rutas de `vendor/`). El valor de producción no se verificó (no se leyó `.env`). |
| `npm run test:dashboard` | Pasa completo solo con `TMPDIR` apuntando al scratchpad; con el temporal del sistema, `build-js-publish.test.js:50` falla con `EPERM` al limpiar su fixture. |
| `route:list` | Falla sin contexto de tenant (`Database connection [tenant] not configured.`); no hay línea base de rutas. |

---

## 3. Cronología por fases y por sesiones

### 3.1 Fases de `PROGRESS.md` y su estado real hoy

| # | Fase | Estado en `PROGRESS.md` | Estado real según los registros |
| --- | --- | --- | --- |
| 1 | Mapa del repositorio y línea base | En curso | Línea base registrada (`BASELINE.md`) e inventario hecho; no se produjo una línea base de rutas. |
| 2 | Auditoría de solo lectura por área | En curso | Ocho informes de hallazgos por área (209 encabezados de hallazgo recontados), más frontend, SQL y código muerto con cobertura limitada, más la revisión estática del diff heredado. Cobertura por área en la sección 7. |
| 3 | Correcciones de backend | En curso | `FIX-001` a `FIX-020`, `FIX-026` a `FIX-028` registrados; la mayoría de los hallazgos de los informes no tiene corrección registrada (sección 8). |
| 4 | Correcciones de frontend e integración | Pendiente (corregido el 2026-11-18 a «completada en código») | Los cinco hallazgos de frontend registrados (`FEC-001`, `FEC-002`, `FEM-001`, `FEM-002`, `FEM-003`) están corregidos en el código fuente (`FIX-021` a `FIX-025`). Siguen sin verificarse en ejecución con escritura. |
| 5 | Optimización de SQL y código muerto | Pendiente (corregido el 2026-11-18 a «parcial») | Parcial: `FIX-016` (consultas), `FIX-018` y `FIX-019` (integridad) y `FIX-010` (código muerto). `SQL-002` y `SQL-006` quedan acotados; `SQL-003`, `SQL-005`, `SQL-007`, `SQL-008` y `DEAD-002` siguen pendientes. |
| 6 | Pruebas y revisión independiente del diff | En curso | Tandas `FIX-011` a `FIX-027` con revisión independiente; revisión estática del diff heredado completada (0 críticos, 1 alto, 5 medios); verificación integral pendiente. |
| 7 | Informe final | Completado el 2026-11-18 | `INFORME_FINAL.md` redactado; la auditoría integral no se declara terminada porque la verificación N6 sigue pendiente. |

### 3.2 Cronología por sesiones

**Sesión inicial (2026-11-16, hasta las ~19:01 según las marcas de los archivos).** Se creó la rama `chore/system-audit`. Se hizo el inventario (70 controladores Tenant, 19 de System/Auth, 142 de Middleware/Requests/Resources, 123 de `CoreFacturalo` sin plantillas, 1379 plantillas, unos 205 archivos de Models/resto, 20 módulos con 750 PHP y 210 Vue, 204 Vue en `resources/js`, 29 archivos de pruebas). Se registró la línea base (`BASELINE.md`, 17:45): lint de 3268 archivos PHP sin errores, Unit 218 pruebas / 846 aserciones con 1 error (`PaymentsTest` por la clase `Culqi\Error\UnableToConnect` ausente tras retirar el SDK), `npm run test:dashboard` en verde y `chart-month-boundaries.php` en verde. Entre las 17:50 y las 19:01 se escribieron los ocho informes de hallazgos de solo lectura (`tenant-controllers-1/2`, `system-auth-infra`, `core`, `modules-sales`, `modules-inventory`, `modules-ops`, `security`). Se aplicaron `FIX-001` y `FIX-002` (`AUDIT_LOG.md`) y el sub-lote `FIX-003` a `FIX-010` (`fixes-batch1.md`, sobre hallazgos `SAFE` de `modules-ops.md`). Esta sesión se agotó por créditos; el relevo la reconstruyó desde `docs/audit` y git (Engram #808, #809).

**Reanudación del 2026-11-16 por la tarde.** Se recuperó el estado (66 archivos rastreados modificados, sin errores de sintaxis en 60 archivos PHP; Unit 218/866; `test:dashboard` y `chart-month-boundaries.php` en verde; HTTP local: `/login` 200 y `/dashboard` 302 hacia el login). Se aplicaron `FIX-011` (imágenes temporales), `FIX-012` (aceptación SUNAT por coerción; tres rondas de revisión independiente) y `FIX-013` (adjuntos de pagos). Por la noche se mapearon los hallazgos de frontend (Engram #860) y SQL (#859).

**Reanudación del 2026-11-17.** Reconciliación documental de los ocho informes (209 IDs: 4 críticos, 58 altos, 95 medios, 52 bajos; Engram #875). `FIX-014` (frontera cliente de tienda/backoffice) y `FIX-015` (reseller con sesión de sistema y CSRF), con revisión independiente. Hacia el final del día se persistieron los cuatro informes que faltaban (`frontend-core`, `frontend-modules`, `sql`, `dead-code`) y se aplicaron `FIX-016` a `FIX-020`, cada uno con revisión independiente. El traspaso #928 cerró el día; quedó bloqueada `npm run test:dashboard` por el `EPERM` del temporal.

**2026-11-18, madrugada: revisión de frontend y `FIX-021` a `FIX-025`.** Se cerraron los cinco hallazgos de frontend en el código fuente (doble envío de pagos, borrador del POS por usuario, checkout de Hotel, XSS del carrito de la tienda con parche manual de `main.min.js`, loader del pago en efectivo). Cada uno con una prueba Node nueva y revisión independiente. Traspaso #938.

**2026-11-18: `OPS-001`.** El usuario autorizó `npm run production:js`; se publicó el bundle con `FIX-021`/`022`/`023`, se verificó el contenido de los chunks y el servicio local, se simuló el rollback y se desbloqueó `npm run test:dashboard` con `TMPDIR` en el scratchpad. El usuario inició sesión por su cuenta en el navegador integrado y se hicieron comprobaciones de solo lectura (POS, cuatro listados de pagos, checkout de Hotel).

**2026-11-18: revisión del diff heredado.** Tres revisores independientes de solo lectura cubrieron 89 archivos (19 nunca revisados, 24 del núcleo, 46 de módulos): 0 críticos, 1 alto, 5 medios, 20 bajos, 13 informativos. Sin referencias colgantes de los cinco archivos eliminados y sin error fatal por la retirada del SDK de Culqi.

**2026-11-18 (hasta las 16:24): `FIX-026` a `FIX-028` y `OPS-002`.** Corrección del hallazgo alto (`Handler`), cierre de dos rutas de API abiertas con borrado de dos rutas muertas, pruebas de contrato de `send` (sin revisión independiente), y verificación en el contenedor 8.3.36 y por HTTP real (solo lectura) cuando Docker volvió a estar disponible (Engram #944).

---

## 4. Todo lo que se hizo: catálogo de correcciones

### 4.1 Cómo leer la tabla

- «Verificación» resume lo que consta en el registro. Salvo que se indique otra cosa, las pruebas PHP se ejecutaron en PHP 8.3.36 del contenedor con SQLite `:memory:` o fakes, sin red ni bases de negocio.
- «Rev. indep.»: `Sí` = revisión independiente registrada; `No consta` = el registro de la corrección no menciona una; `No` = el registro afirma que no la hubo.
- Para `FIX-001` a `FIX-010` no hay revisión independiente propia en sus registros. Sus hunks sí fueron leídos después por los revisores del diff heredado (G2 para `FIX-001`; G3 para `FIX-002` a `FIX-019`), una revisión estática de solo lectura.

### 4.2 Catálogo `FIX-001` a `FIX-010`

| ID | Hallazgo / origen | Qué se cambió | Archivos principales | Verificación | Rev. indep. | Límites y observaciones abiertas |
| --- | --- | --- | --- | --- | --- | --- |
| FIX-001 | `MOP-007`; error de `BASELINE.md` | Tras retirar `culqi/culqi-php`, `CulqiCharger` usa un cliente Guzzle contra `/v2/charges` (timeout 15, connect 10, TLS activo) y mapea HTTP/transporte a clases `Culqi\Error\*` locales; se relanzan subclases de `\Error` para no ocultarlas como `unknown`. `FakeRecord` de `PaymentsTest` compatible con PHP 8.3. | `app/Services/Payments/CulqiCharger.php`, `CulqiErrorPolyfills.php` (nuevo), `tests/Unit/PaymentsTest.php` | `php -l`; Unit 218/866, 0 errores | No consta | Riesgo medio: sin cobro sandbox real. Aviso de PHP 8.4+ por `callable $factory = null` (`IDG2-008`). |
| FIX-002 | `MOP-002`, `MOP-036` | Rutas de Padron bajo el dominio del tenant con `web`, `auth`, `locked.tenant` y `throttle` (30,1 en `consulta`; 2,1 en `charges_data` y `demo`); `consulta` valida RUC (dígitos, 11); timeouts cURL; se verifica HTTP 200 y extracción antes de `truncate()`; captura `\Throwable` con `report()` y mensaje genérico. | `modules/Padron/Routes/web.php`, `PadronController.php` | `php -l`; Unit 218/866 | No consta | Sigue abierto: descarga sin verificar TLS extraída en `public/`, GET destructivos, `truncate()` no transaccional, sin permiso de módulo (`IDG3-001`, `IDG3-002`; sección 8). |
| FIX-003 | `MOP-003`, `MOP-037` | Dashboard: `without(...)` de relaciones y filtro de estados en totales globales; `optional(...)` ante `Configuration`/`Establishment`/`Company` nulos. | `modules/Dashboard/Helpers/DashboardData.php`, `DashboardStock.php`, `DashboardController.php` | Revisión de diff por paridad numérica; `php -l` en el host (8.5.5) | No consta | «Corregido sin verificación completa»: sin comparación numérica con datos reales ni prueba funcional. Reescritura a agregación SQL (`MOP-003/004`) no aplicada. |
| FIX-004 | `MOP-011` | `ServiceData`: `=!` por `!=` con guarda de configuración nula; valida `type` (`ruc`/`dni`) y `number`; timeout 10; `try/catch` con `report()` y respuesta controlada. | `modules/Services/Data/ServiceData.php` | Revisión de consumidores; `php -l` en el host | No consta | Sin prueba contra `MockHandler`. `'verify' => false` y throttle de la ruta (`MOP-011`, parte TLS) no aplicados. |
| FIX-005 | `MOP-013`, `015`, `016`, `017`, `033`, `035` | Hotel: `lockForUpdate` y transacción en `store`/`finalizeRent`; transacción en `addProductsToRoom`; `rollBack` en retorno temprano; `success:false` en errores; validación de estado de habitación; tarifa con el `$roomId` de ruta; `destroy()` sin SQL crudo; carga en lote del último alquiler. | 7 archivos de `modules/Hotel/Http` | Revisión de consumidores Vue; `php -l` en el host | No consta | Sin prueba funcional propia (la consulta de recepción y la regla de tarifa se probaron luego en `FIX-016`). Repricing de productos (`MOP-017`) no aplicado; transiciones de estado (`IDG3-003`) abiertas. |
| FIX-006 | `MOP-010`, `014`, `033` | DocumentaryProcedure: `index()` devolvía un `Builder` serializado como `{}` con filtro vacío; `FileRequest` valida `file`; nombres de archivo UUID; `destroy()`/`store()` sin texto de excepción. | 5 controladores y `FileRequest.php` de `modules/DocumentaryProcedure` | Revisión de `ModalAddEdit.vue`; `php -l` | No consta | Sin prueba funcional. `mimes:` depende de `guessExtension()` (`IDG3-006`). Numeración por `count()+1` (`MOP-034`) pendiente. |
| FIX-007 | `MOP-022`, `023`, `025` | Account: `firstOrFail`/`findOrFail`; `download()` valida `type` y `month`, carga documentos solo donde hace falta, `deleteFileAfterSend`, `default: abort(422)`; `SummaryReportController` valida fechas y recorta relaciones. | 3 controladores de `modules/Account` | Revisión de consumidores; `php -l` | No consta | Sin prueba funcional. Filtro de fechas en `whereHas('documents')` y columna faltante de `getVoidedDocuments` no aplicados. |
| FIX-008 | `MOP-024` | Offline: `required_if:is_client,true` (la regla anterior comparaba contra un literal con espacio y comillas); `firstOrFail`/`findOrFail`. | `OfflineConfigurationRequest.php`, `OfflineConfigurationController.php` | `php -l` | No consta | Sin prueba funcional. |
| FIX-009 | `MOP-008`, `021`, `026`, `027`, `028`, `037` | Ecommerce: nombre de ruta del POST de login (`tenant_ecommerce_login_post`), `throttle` en login/registro/pagos, validación y nulos en controladores, `uploadFile` valida `image`/`mimes` y `type`, N+1 y memoización en `FeaturedProductsViewComposer`. | `modules/Ecommerce/Routes/web.php`, `EcommerceController.php`, `ConfigurationController.php`, `FeaturedProductsViewComposer.php` | `git grep` de consumidores; `php -l` | No consta | Sin prueba funcional. Subpartes no aplicadas: `MOP-001`, `008` (semántica), `009`, `021` (paginación), `027`, `028`. Los `throttle:N,M` numéricos comparten contador (`IDG3-004`). |
| FIX-010 | `MOP-030`, `031`, `032` | Elimina `DashboardView::getUnpaid`, imports y comentarios muertos; elimina `TakeProductoViewComposer.php` y `excel_concar_bk.blade.php`; `/levelaccess` bajo dominio de tenant con `web`, `auth`, `locked.tenant`. | `modules/Dashboard/...`, `modules/LevelAccess/Routes/web.php`, 2 archivos eliminados | `git grep` previo; `php -l` | No consta | Sin verificación funcional de los flujos afectados. Scaffolds restantes conservados (`MOP-031`). |

### 4.3 Catálogo `FIX-011` a `FIX-020` (backend)

| ID | Hallazgo / origen | Qué se cambió | Archivos principales | Verificación | Rev. indep. | Límites y observaciones abiertas |
| --- | --- | --- | --- | --- | --- | --- |
| FIX-011 | `TC2-001`, `TC2-002` (brecha del helper) | Cada imagen temporal se registra (caché, 2 h) ligada a tenant, usuario y sesión; el guardado exige coincidencia; el temporal se consume solo tras guardar con éxito. Contrato `filename`/`temp_path`/`temp_image` intacto. | `app/CoreFacturalo/Helpers/Storage/TempImageHelper.php` (nuevo), `ItemController`, `ItemSetController`, `PromotionController` | `TempImageHelperTest` 7/26; Unit 225/892; host 8.5.5 7/26 | Sí | Cargas anteriores o expiradas deben resubirse; limpiar la caché invalida cargas pendientes; los temporales abandonados no se recolectan. Sin guardado real, rollback de almacenamiento, concurrencia ni caché caída. |
| FIX-012 | `CORE-001` | Códigos SOAP no numéricos ya no se coercionan a `0` (que marcaba aceptado `05`); `hasCdr` impide aceptar/observar un fault sin CDR; lector CDR exige respuesta y código válidos, desactiva red del parser y registra namespaces UBL; el fault conserva código y mensaje originales. | `Facturalo.php`, `WS/Reader/DomCdrReader.php`, `WS/Response/CdrResponse.php`, `WS/Services/BaseSunat.php` | `SunatResponseTest` 13/407 (con cliente SOAP falso, sin red); Unit 238/1299; host 8.5.5 13/407 | Sí (3 rondas) | Una ronda halló extracción de números del texto libre y sobreescritura de namespaces XPath; otra, aceptación por fault `0`/`4001` sin CDR; las tres reproducidas en rojo y corregidas. Sin peticiones SUNAT/OSE ni reparación de estados históricos. |
| FIX-013 | `SEC-002`, `SEC-003`, `MSL-002` (adjuntos de pagos) | Adjuntos de pagos: registro de emisión por tenant/usuario/sesión/propósito, extensión decidida por el servidor, nombre UUID, relación creada tras escribir, consumo tras el commit más externo, descarga con `attachment` y `nosniff`. XLSX exige lector reconocido y libro con hojas. | `modules/Finance/Helpers/PaymentFileHelper.php` (nuevo), `PaymentFileController.php`, `FilePaymentTrait.php` | `PaymentFileTest` 12/144; Unit 250/1443; host 12/144; HTTP local sin cambio | Sí | Un rollback externo puede dejar un destino UUID huérfano; un fallo de limpieza tras el commit puede dejar el temporal. Autorización/descarga de `MSL-003` no abordadas. |
| FIX-014 | `SEC-001` (frontera de credenciales) | `Authenticate` rechaza con 403 al modelo `User` de tipo `client` en `auth` y `auth:api` sin destruir su sesión; el login móvil lo rechaza; nuevos clientes de tienda quedan con `api_token = null`; login y registro de tienda regeneran sesión y CSRF conservando el carrito. | `app/Http/Middleware/Authenticate.php`, `Api/MobileController.php`, `modules/Ecommerce/.../EcommerceController.php` | `CustomerAccessBoundaryTest` 9/58; Unit 259/1501 | Sí | Sin navegador ni operaciones autenticadas reales. Validaciones del formulario de registro y asignación del módulo `10` (`MOP-001`) no cambiadas. Un cliente con sesión que abre el login del personal queda en 403 (`IDG2-005`). |
| FIX-015 | `SYS-001` | Los POST `reseller/detail` y `reseller/lockedAdmin` usan `web` + `auth:admin` + CSRF; `LockedAdmin` se excluye en esos dos POST y solo en GET/POST `/login` del sistema; `locked_admin` es booleano requerido validado antes de consultar o escribir. | `routes/api.php`, `routes/web.php`, `ResellerController.php` | `ResellerAccessTest` 8/73 (pipeline real de middleware de ruta, cookies cifradas, CSRF efectivo); Unit 267/1574 | Sí | Cambio intencional de contrato: las integraciones anónimas dejan de funcionar. El revisor final no comprobó el snapshot privado. Sin POST reales ni flags reales. |
| FIX-016 | Búsquedas de `Document`, cuentas por pagar, Hotel (`SQL-004`, `SQL-001` parcial) | Búsqueda de servicios agrupa sus alternativas `OR` (ya no devuelve productos `NIU`); cuentas por pagar aplica el filtro de usuario solo si tiene valor y etiqueta gastos como `expense`; recepción de Hotel elige el último alquiler por habitación en SQL; validación única de tarifa con la habitación de ruta. | `modules/Document/Traits/SearchTrait.php`, `modules/Finance/Helpers/ToPay.php`, `HotelReceptionController.php`, `HotelAddRateToRoomRequest.php` | `QueryCorrectnessTest` 9/45; Unit 276/1619 (5 fallos rojos reproducidos) | Sí | SQLite con funciones fixture, no MariaDB ni planes. Las sospechas de `OR` sobre almacén, productos y personas no se reprodujeron (Eloquent agrupa scopes locales); sus fuentes no se tocaron y quedaron controles de regresión. |
| FIX-017 | `MSL-001`, `SYS-023` | `User::$hidden` incluye `password`, `remember_token` y `api_token`; los selectores de ToPay/Unpaid/Contract consultan solo `id`/`name`; el payload Blade de pedidos añade explícitamente el token propio del usuario autenticado. | `app/Models/Tenant/User.php`, `ToPayController.php`, `UnpaidController.php`, `ContractController.php`, `resources/views/tenant/orders/index.blade.php` | `UserSerializationTest` 5/91 (+14/149 junto a la frontera de clientes); Unit 281/1710 | Sí | Clientes externos que dependieran de credenciales en la serialización deben usar el contrato explícito. Solo se compila la sección de contenido de pedidos; no se validó conversión/emisión real. |
| FIX-018 | `MIN-001`, `SQL-002` | Los callbacks de anulación exigen transición real de estado (capa global: entrada a `11`, incluida `09`→`11`; almacén/lotes/anticipos: solo primera entrada a `09`/`11`); `SaleNoteController::anulate` y `OrderNoteController::voided` bloquean el registro con `lockForUpdate` dentro de la transacción y no repiten efectos si ya está en `11`. | `app/Providers/AnulationServiceProvider.php`, `modules/Inventory/Providers/InventoryVoidedServiceProvider.php`, `SaleNoteController.php`, `OrderNoteController.php` | `StockReversalTest` 8/61 (6 fallos rojos); Unit 289/1771 | Sí | SQLite no prueba bloqueos de fila ni concurrencia real. No es un libro idempotente; reactivación, reparación histórica y otras rutas sin bloqueo fuera de alcance. |
| FIX-019 | `SQL-006` (controlador) | Ambos bucles `goto` del controlador se limitan a 3 intentos por documento; `regularize` reúne resultados fuera de transacción, no escribe si se agota una consulta, bloquea todos los IDs en orden determinista, comprueba todos los estados antes del primer `save()` y conserva el orden lógico de eventos. | `modules/Document/Http/Controllers/ValidateDocumentController.php` | `ValidateDocumentRetryTest` 7/54; Unit 296/1825 | Sí (2 rondas) | Tres intentos NO equivalen a un plazo total. Servicio/CLI, OCR/CAPTCHA, transporte y MariaDB sin verificar. La primera revisión halló eventos emitidos antes de descubrir una fila obsoleta; reproducido y corregido. |
| FIX-020 | Brecha de pruebas del fallback Guzzle (`FIX-001`) | Parámetro opcional `ClientInterface` en `CulqiCharger` para probar el fallback sin red; defaults, clasificación y prioridad del factory intactos. | `app/Services/Payments/CulqiCharger.php`, `tests/Unit/PaymentsTest.php` | `PaymentsTest` 21/144 (6 nuevas, 5 fallos rojos); Unit 302/1893 | Sí | No prueba cobro real, credenciales ni idempotencia. Un HTTP 201 sin `outcome` explícito o un reto 3DS nunca se marcan pagados. |

### 4.4 Catálogo `FIX-021` a `FIX-028` y operaciones

| ID | Hallazgo / origen | Qué se cambió | Archivos principales | Verificación | Rev. indep. | Límites y observaciones abiertas |
| --- | --- | --- | --- | --- | --- | --- |
| FIX-021 | `FEC-001` | Guard síncrono de doble envío por fila en los cuatro formularios de pagos (documentos, notas de venta, compras, gastos): botones deshabilitados y liberación en `finally`. | 4 `payments.vue` (`resources/js/...` y `modules/Purchase`, `modules/Expense`) | `payments-double-submit.test.js` 4/4 (extrae el `clickSubmit` real) | Sí | Solo UI; no es idempotencia del servidor. Sin Vue real ni navegador con escritura. Formularios hermanos (cotizaciones, servicios técnicos, `system/clients`) sin cubrir. |
| FIX-022 | `FEC-002` | Borrador del POS con claves `pos_draft:{host}:{userId}:{key}` mediante helper único; las claves genéricas heredadas se descartan sin adoptarlas; sin identidad no se guarda ni restaura. | `resources/js/helpers/pos_draft.js` (nuevo), `pos/index.vue`, `pos/partials/payment.vue`, `pos/partials/multiple_payment.vue` | `pos-draft-isolation.test.js` 5/5; en navegador (`OPS-001`) el POS descartó claves genéricas sembradas y conservó una ajena | Sí | No se probó con dos usuarios reales ni se observó la creación de claves `pos_draft:…`. Pérdida única de borradores en curso. |
| FIX-023 | `FEM-002` | El checkout de Hotel espera la finalización dentro de la cadena, conserva el id del comprobante emitido (`pendingFinalizationDocumentId`) y reintenta solo la finalización sin re-emitir ni depender de validaciones del formulario. | `modules/Hotel/Resources/assets/js/views/rooms/Checkout.vue` | `hotel-checkout-retry.test.js` 6/6 | Sí (L2 corregida después) | Ver 4.5. Abiertos: `arrears` editable tras emitir (L1), mensaje engañoso ante 422 «ya finalizada» (L3), pérdida de estado al recargar. |
| FIX-024 | `FEM-001` | Carrito/quick view de la tienda reconstruidos con nodos DOM, `.text()` y listeners; validación de ruta de imagen e ids numéricos; `main.min.js` parcheado a mano en tres regiones. | 2 vistas Blade, `cart.js`, `main.js`, `main.min.js` de `public/porto-ecommerce` | 3 pruebas Node (`ecommerce-cart-xss`, `ecommerce-public-cart-xss`, `ecommerce-main-min-xss`, esta última ejecuta el min completo); mutantes fallan las pruebas | Sí (final, sin hallazgos) | Ver 4.5. Cerrado en código; sin navegador, jQuery real ni explotación real. Aviso de caché nginx de 30 días sin versionado. |
| FIX-025 | `FEM-003` | `paymentCash()` de la tienda cierra el loader ante rechazo de negocio (200 con `success !== true`), error HTTP y error de red; éxito solo con `success === true`; mensaje del servidor solo como texto; guard `payment_cash.processing`. | `modules/Ecommerce/Resources/views/cart/detail.blade.php` | `ecommerce-cash-loader.test.js` (7/7 pruebas UI en total); 11 mutantes la fallan | Sí | Sin navegador, sweetalert2 real ni pago real. Reportados sin corregir: `culqi()` sin `try/catch`/guard, `ReferenceError` preexistente de `order`, `token_public_culqui` sin escapar. |
| FIX-026 | `IDG1-001` (alto) | El `Handler` ya no enmascara en 5xx los mensajes de negocio: nuevo `isUserFacing()` (`\Exception` exacta, `InvalidArgumentException`, `HttpException`, clases `App\`/`Modules\`); `QueryException`, `PDOException`, `ErrorException`, `\Error` y excepciones de librerías siguen enmascaradas y reportadas. | `app/Exceptions/Handler.php` | `ExceptionHandlerTest` 11/84; Unit 313/1977 en el host 8.5.5; luego contenedor 8.3.36 (`OPS-002`) | Sí | Fugas residuales abiertas (sección 8). Sin `APP_DEBUG` de producción verificado. Las excepciones de librerías (`Culqi\Error\*`, Guzzle, SOAP) salen ahora como «Error interno del servidor». |
| FIX-027 | `IDG2-001` | `documents/status` y `sendserver/{id}` pasan al grupo `auth:api` + `locked.tenant`; se eliminan las rutas muertas `services/validate_cpe` y `services/consult_status`. | `routes/api.php` | `ApiRouteAuthTest` 14/67 (pipeline real de middleware); Unit 327/2044 en el host; contenedor y HTTP real en `OPS-002` | Sí (borrado de rutas aplicado después) | Rompe a integradores anónimos (ahora 401). Sin tokens de producción. Ver 4.5. |
| FIX-028 | `IDG2-002` | Solo pruebas: fija el contrato de `send` de la API (422/404/422 previos; mensaje de negocio preservado en 500 con `APP_DEBUG=false`; `QueryException` enmascarada). | `tests/Unit/ApiDocumentSendTest.php` (nuevo) | 9/53; Unit 336/2097 en el contenedor 8.3.36 | No | No cubre el éxito de `send` ni el rechazo SUNAT real. Verificado por el orquestador, no por un revisor fresco. El contrato HTTP cambió respecto de `HEAD` (500 → 404/422). |
| OPS-001 | Operación autorizada por el usuario | `npm run production:js`; verificación de chunks y servicio local; rollback simulado; `test:dashboard` en verde con `TMPDIR`; comprobación en navegador autenticado (solo lectura). | `public/js/manifest.js`, `manifest.js.map`, `public/mix-manifest.json`; 8 chunks nuevos (16 archivos con mapas) ignorados por git | Ver 4.5 | No consta | Chunks ignorados por git con manifiesto versionado; sin verificar el flujo real de despliegue. |
| OPS-002 | Reverificación al volver Docker | En el contenedor 8.3.36: lint de 3277 archivos sin errores, Unit 336/2097 (2 deprecaciones de PHPUnit), `chart-month-boundaries.php` (5 casos); HTTP real de solo lectura. | Ninguno (solo verificación) | `documents/status`, `sendserver`, `documents` → 401 sin token; `validate_cpe` → 404 | No consta | El contenedor local corre con `APP_DEBUG=true`: el enmascarado de `FIX-026` no se ejercita en local. |

### 4.5 Matices que la tabla no puede contener

**FIX-012 (tres rondas).** La revisión inicial encontró dos rutas de aceptación engañosa que quedaban (extracción de dígitos del texto SOAP y sobreescritura de namespaces por el registro automático de XPath); se reprodujeron con pruebas rojas y se corrigieron con normalización anclada y `query(..., null, false)`. Otra revisión reprodujo aceptación por faults numéricos `0`/`4001` sin CDR; una prueba roja del canal real confirmó `fault 0 → 05` y se añadió el indicador `hasCdr`. La revisión final no halló bloqueantes. Se conservan las clasificaciones legítimas (`0` → `05`, `1033` → regularización, `2335` → rechazo `09`, `4001` → observado `07`).

**FIX-015 (recuperación de sesión).** La revisión independiente detectó que el bloqueo de plataforma impedía iniciar una sesión nueva de recuperación; se reprodujo y se excluyó `LockedAdmin` también en GET/POST `/login` del sistema, conservando `guest:admin`, CSRF y la validación de credenciales.

**FIX-019 (evento obsoleto).** Comprobar cada fila justo antes de guardarla permitía emitir el evento de la primera antes de descubrir una segunda fila obsoleta. Se separó el bloqueo/verificación de todas las filas de la escritura; el orden de locks (por ID) es independiente del orden lógico de eventos.

**FIX-023 (corrección L2 posterior a la revisión).** Las validaciones de alojamiento y destino de pago corrían antes del chequeo del id pendiente, de modo que editar el formulario tras emitir podía bloquear el reintento de solo-finalizar con un error ajeno. Se envolvió esa preparación en `if (!this.pendingFinalizationDocumentId)` y se añadió el caso (f) a la prueba. El caso (f) no se comprobó en rojo contra el código anterior y el registro no menciona una segunda revisión de esa corrección. El backend (`finalizeRent`) ya devuelve 422 «La renta ya fue finalizada previamente.» sin cambios, por lo que finalizar es seguro; las entrañas de `POST /documents` y de `POST /cash/cash_document` no se verificaron.

**FIX-024 (tres tandas).**
1. Blade y `cart.js`/`main.js`: nodos DOM, `.text()`, listeners; `partial.blade.php` pasa a `data-product`. Antes, una comilla simple en un campo del producto cerraba el string del `onclick` (el navegador decodifica `&#039;` antes de parsear el JS).
2. Descubrimiento (F1): las cuatro layouts de la tienda cargan `main.min.js`, no `main.js`; el min conservaba el renderizador viejo con `.html()` e `onclick` inline y era la ruta principal de agregar al carrito.
3. `uglify-js` instalado es la 2.8.29 (solo ES5) y `main.min.js` contiene template literals, y no hay terser: el usuario eligió parchear el min a mano. Un script de Node exige que cada marcador aparezca exactamente una vez y reemplaza tres regiones (A: `append` del dropdown; B: dos escrituras `.html()` del handler `.add-cart`; C: helpers nuevos `cartImageUrl`, `buildCartProduct`, `cartImage`). 13622 → 14048 bytes; prefijo (10852) y sufijo (892) idénticos byte a byte frente a `HEAD`. `ecommerce-main-min-xss.test.js` ejecuta el min completo; ocho mutantes del escritor y diez del revisor lo fallan. El archivo `main.min.js.bkp` queda sin cambios y sin referencias. El hook de diseño que alertó sobre un `<img>` en una aserción fue un falso positivo (texto de un mensaje).

**FIX-027 (borrado posterior a la revisión).** `services/validate_cpe` y `services/consult_status` apuntaban a `ServiceController@validateCpe` y `@consultStatus`, métodos inexistentes en `app/` y `modules/`; cualquier llamada anónima solo producía un 500. Fue una recomendación del revisor aplicada después de su revisión (dos líneas más la lista de permitidos del test, con la suite completa en verde). Una comprobación posterior por HTTP real devolvió 404 para `validate_cpe` (`OPS-002`). Además, de los 19 `modules/*/Routes/api.php` solo se leyó entero el de Purchase; en los otros 18 se contaron las apariciones de `auth:api` sin comprobar el anidamiento de cada ruta.

**FIX-028 (sin revisión independiente).** Solo agrega una prueba y no toca producción; fue verificado por el orquestador (el escritor reportó 9/53 y el orquestador comprobó 336/2097 en el contenedor), no por un revisor fresco. Confirma que `FIX-026` resolvió el impacto práctico de `IDG2-002` y que el contrato HTTP de `send` cambió respecto de `HEAD`.

**OPS-001 (detalle).**
- Antes: `public/js` y `public/mix-manifest.json` limpios en git; las nueve fuentes de frontend modificadas eran exactamente las de `FIX-021`/`022`/`023`. Snapshot en el scratchpad para el rollback.
- Resultado: salida 0; cambiaron como versionados solo `manifest.js`, `manifest.js.map` y `mix-manifest.json` (+19/−3); `app.js` no cambió; el CSS no se tocó; los chunks antiguos permanecen y el script fusiona el manifiesto sin borrarlos. Los chunks nuevos son `tenant-pos-index`, `tenant-hotel-rent-checkout`, `tenant-documents-index`, `tenant-sale-notes-index`, `tenant-purchases-index`, `tenant-expenses-index`, `tenant-finance-to-pay-index` y `tenant-finance-unpaid-index`.
- Contenido: `pos_draft` en el chunk del POS, `pendingFinalizationDocumentId` en el del checkout de Hotel, y el guard conservado en los cinco chunks con formularios de pagos. `finance-to-pay-index` y `finance-unpaid-index` solo se verificaron por contenido y manifiesto.
- Servicio local: los cuatro assets pedidos devolvieron 200 y el chunk del POS coincide byte a byte (SHA-1) con el de disco.
- Navegador autenticado (el usuario inició sesión por su cuenta; solo GET y lecturas de `localStorage`/consola/red; no se escribieron credenciales ni se emitió, guardó, pagó o finalizó nada): el POS y los listados `/documents`, `/sale-notes`, `/purchases`, `/expenses` cargaron sus chunks nuevos con 200 y sin errores de consola; el checkout de Hotel de una habitación ocupada renderiza completo sin pulsar el botón.
- Aviso: `/public/js/chunk-*.js` y sus mapas están en `.gitignore`, mientras el `manifest.js` versionado los referencia (sección 9).

---

## 5. Pruebas añadidas

### 5.1 PHPUnit (`tests/Unit/*.php`)

Todos los archivos de `tests/Unit` con sufijo `Test.php` los recoge la suite `Unit` de `phpunit.xml`. No existe configuración de CI en el repositorio (no hay `.github`). Los doce archivos nuevos están sin seguimiento en git.

| Archivo | Qué fija | Tipo | Enlazado a `package.json`/CI |
| --- | --- | --- | --- |
| `SunatResponseTest.php` (`FIX-012`, 13/407) | Códigos SOAP numéricos válidos e inválidos, lector CDR, fallos SOAP, retenciones y guías, ramas reales de `onlySenderXmlSignedBill` y `BillSender` con un cliente SOAP falso. | Unitario y extracción del código real, sin red | Suite `Unit` de `phpunit.xml`; sin CI |
| `TempImageHelperTest.php` (`FIX-011`, 7/26) | Emisión, propiedad (tenant/usuario/sesión), expiración, reintento sin consumo, MIME alterado. | Unitario | Suite `Unit` |
| `PaymentFileTest.php` (`FIX-013`, 12/144) | Adjuntos de pagos: formatos, XLSX, ZIP, propósito, orden de almacenamiento, commit/rollback, cabeceras SVG. | Unitario con SQLite `:memory:` y disco local privado | Suite `Unit` |
| `CustomerAccessBoundaryTest.php` (`FIX-014`, 9/58) | Rechazo de clientes en `auth`/`auth:api`/login móvil; sesión y CSRF regenerados; personal intacto. | Guards reales y archivo real de rutas de Ecommerce con acciones inertes | Suite `Unit` |
| `ResellerAccessTest.php` (`FIX-015`, 8/73) | Sesión de sistema + CSRF en los POST reseller; login de recuperación bajo bloqueo; rutas ordinarias siguen bloqueadas. | Pipeline real de middleware de ruta (alias/grupos/prioridad del Kernel), cookies cifradas | Suite `Unit` |
| `QueryCorrectnessTest.php` (`FIX-016`, 9/45) | Servicios sin `NIU`, filtro de usuario de cuentas por pagar, alquiler más reciente de Hotel, regla única de tarifa; controles de regresión de scopes. | Unitario sobre SQLite con funciones fixture (no MariaDB) | Suite `Unit` |
| `UserSerializationTest.php` (`FIX-017`, 5/91) | Serialización sin credenciales, selectores `id`/`name`, payload Blade de pedidos con el token propio, permisos por propietario/módulo. | Unitario; compila el contenido real del Blade | Suite `Unit` |
| `StockReversalTest.php` (`FIX-018`, 8/61) | Reversión solo en la transición real; `09`→`11`; notas `07`/`08`; endpoints repetidos; lotes/anticipos una sola vez. | Providers y eventos Eloquent reales sobre SQLite | Suite `Unit` |
| `ValidateDocumentRetryTest.php` (`FIX-019`, 7/54) | Máximo de tres intentos, regularización atómica, cero eventos ante estado obsoleto, orden lógico de eventos. | Unitario con consulta ficticia y SQLite | Suite `Unit` |
| `ExceptionHandlerTest.php` (`FIX-026`, 11/84) | Mensajes de negocio preservados en 5xx; `QueryException`/`PDOException`/`ErrorException`/`TypeError`/librerías enmascaradas; reporte único. | `Handler` real con `Request` JSON, sin base de datos | Suite `Unit` |
| `ApiRouteAuthTest.php` (`FIX-027`, 14/67) | Rutas de la API con `auth:api` y `locked.tenant`; 401 sin token; 403 con token `client` o tenant bloqueado; lista de rutas públicas exacta. | Archivo real de rutas y pipeline real de middleware, guard por token con `User` real en SQLite | Suite `Unit` |
| `ApiDocumentSendTest.php` (`FIX-028`, 9/53) | Contrato de `send` y `documentCheckServer`: 422/404/422, mensaje de plazo vencido preservado, `QueryException` enmascarada. | Controlador, `Handler` y `Facturalo` reales sobre SQLite como conexión `tenant` y disco temporal | Suite `Unit` |
| `PaymentsTest.php` (existente, ampliado en `FIX-001`/`FIX-020`; 21/144 el archivo completo) | Fallback Guzzle: método, URL, cuerpo, cabeceras, aprobación coincidente, errores conocidos/desconocidos, sin reintentos, 201 sin `outcome` y 3DS nunca pagados. | Unitario con `MockHandler`/historial y clave sintética | Suite `Unit` |

### 5.2 Pruebas UI en Node (`tests/ui/*.test.js`), todas nuevas y sin seguimiento

Las pruebas extraen el texto real de las funciones de cada `.vue`/Blade/JS y lo ejecutan en `vm` con stubs; no renderizan Vue ni usan navegador. Ninguna está enlazada a `package.json` (solo existe `test:dashboard`, que ejecuta `system-dashboard.test.js` y `build-js-publish.test.js`, ambas preexistentes): se ejecutan a mano con `node`.

| Archivo | Qué fija | Tipo |
| --- | --- | --- |
| `payments-double-submit.test.js` (`FIX-021`, 4/4) | Dos clics con solicitud pendiente envían una; liberación tras éxito, `success:false` y 422; reenvío legítimo; monto excedido no bloquea. | Extracción del `clickSubmit` real de los cuatro `.vue` |
| `pos-draft-isolation.test.js` (`FIX-022`, 5/5) | Aislamiento por usuario y host; claves heredadas eliminadas y no adoptadas; falla cerrado sin identidad; limpieza solo de claves propias. | Carga del helper real con `localStorage` falso |
| `hotel-checkout-retry.test.js` (`FIX-023`, 6/6) | Loader retenido hasta ambos pasos; reintento solo finaliza; estado limpio en checkout nuevo; reintento ignora validaciones fallidas. | Extracción de `onGoToInvoice` y del watcher `rent.id` |
| `ecommerce-cart-xss.test.js` (`FIX-024`) | Vistas Blade del carrito/quick view construyen nodos y muestran payloads hostiles solo como texto. | Extracción del código real con DOM/jQuery falsos que lanzan ante HTML con datos |
| `ecommerce-public-cart-xss.test.js` (`FIX-024`) | Lo mismo para `cart.js` y `main.js` públicos. | Ídem |
| `ecommerce-main-min-xss.test.js` (`FIX-024`) | Ejecuta el `main.min.js` completo, dispara el ready y comprueba el handler real de `.add-cart`. | Ejecución del archivo completo en `vm` |
| `ecommerce-cash-loader.test.js` (`FIX-025`) | Éxito solo con `success === true`; rechazo de negocio, 422, 500 y red cierran el loader; doble clic envía una solicitud; mensaje del servidor solo como texto. | Extracción de `paymentCash` del Blade por conteo de llaves |

Pruebas UI preexistentes: `system-dashboard.test.js` y `build-js-publish.test.js` (enlazadas a `test:dashboard`), `chart-month-boundaries.php` (manual, solo lectura) y `phase2-php83-verification.php` (NO ejecutada: escribe en bases).

### 5.3 Totales de la última verificación (no son cobertura)

| Verificación | Resultado | Cuándo / dónde |
| --- | --- | --- |
| Lint (`php -l`) | 0 errores; 3277 archivos en OPS-002 y 3278 tras FIX-028 | OPS-002 y verificación 2026-11-18, contenedor 8.3.36 |
| Suite Unit | 336 pruebas / 2097 aserciones; 2 deprecaciones de PHPUnit | `OPS-002`, contenedor 8.3.36 |
| Suite Unit en el host | 327 pruebas / 2044 aserciones (antes de `FIX-028`) | `FIX-027`, PHP 8.5.5, `memory_limit=1G` |
| Pruebas UI nuevas | 7 archivos, todos en verde | `FIX-025` (a mano con `node`) |
| `npm run test:dashboard` | Completo en verde con `TMPDIR` en el scratchpad | `OPS-001` |
| `chart-month-boundaries.php` | 5 casos aprobados | `OPS-002`, contenedor |
| Línea base original | 218 pruebas / 846 aserciones, 1 error (hoy resuelto) | `BASELINE.md` |

Estos números dicen que lo ya escrito pasa; no dicen que el sistema esté verificado de extremo a extremo. Persisten avisos de compatibilidad preexistentes (propiedades dinámicas de fixtures, deprecaciones de PHPUnit).

---

## 6. Apéndice A: inventario de modificaciones por archivo

Recontado con `git status --short`, `git diff --name-status`, `git diff --stat` y `git ls-files --others --exclude-standard` el 2026-11-18.

| Concepto | Cantidad |
| --- | --- |
| Archivos versionados modificados | 98 |
| Archivos versionados eliminados | 5 |
| Total en `git diff --name-status` | 103 (2096 inserciones, 2177 eliminaciones; incluye `composer.lock` y mapas de build) |
| Archivos sin seguimiento | 43 (44 con este README) |
| Entradas `FIX-NNN` | 28 (`FIX-001` a `FIX-028`) |
| Entradas `OPS-NNN` | 2 |
| Archivos nuevos de prueba | 12 PHPUnit + 7 UI Node |

**El árbol de trabajo NO está commiteado.** `HEAD` sigue siendo `2064950`. Todo lo de este apéndice existe solo en el disco local.

Convención de la columna «Entradas»: `FIX-NNN`/`OPS-NNN` según el registro de archivos de cada entrada (`Archivos`/`Archivos modificados`). `heredado/no registrado` = ninguna entrada lo nombra: son cambios previos del usuario o del árbol de partida, revisados solo estáticamente en `findings/inherited-diff*.md`. Un archivo con FIX puede contener además hunks heredados (p. ej. `ItemController::store`, `Api/MobileController`, `routes/api.php`, `Handler`).

### 6.1 (a) Archivos de código modificados, por área

**`app/` (33 modificados).**

| Ubicación | Archivos | Entradas |
| --- | --- | --- |
| `app/CoreFacturalo` | `Facturalo.php`, `WS/Reader/DomCdrReader.php`, `WS/Response/CdrResponse.php`, `WS/Services/BaseSunat.php` | `FIX-012` |
| `app/Exceptions` | `Handler.php` | `FIX-026` (+ hunks heredados: `IDG1-001` a `IDG1-004`) |
| `app/Http/Controllers/System/Api` | `ResellerController.php` | `FIX-015` |
| `app/Http/Controllers/Tenant` | `ItemController.php`, `ItemSetController.php`, `PromotionController.php` | `FIX-011` (+ `ItemController::store` heredado, `IDG2-003`) |
| `app/Http/Controllers/Tenant` | `SaleNoteController.php` | `FIX-018` |
| `app/Http/Controllers/Tenant/Api` | `MobileController.php` | `FIX-014` (+ heredado, `IDG2-006`) |
| `app/Http/Controllers/Tenant` y `.../Api` | `AccountController.php`, `Api/DocumentController.php`, `Api/PerceptionController.php`, `Api/SaleNoteController.php`, `Api/ServiceController.php`, `CashController.php`, `CertificateController.php`, `CompanyController.php`, `ConfigurationController.php`, `ContingencyController.php`, `DispatchController.php`, `DocumentController.php`, `DownloadController.php`, `EstablishmentController.php`, `ExchangeRateController.php`, `LoginConfigurationController.php` | heredado/no registrado (`Api/DocumentController` solo recibe la prueba de `FIX-028` y la ruta de `FIX-027`) |
| `app/Http/Middleware` | `Authenticate.php` | `FIX-014` |
| `app/Http/Resources/Tenant`, `app/Models/Tenant` | `ItemCollection.php`, `Item.php` | heredado/no registrado (`IDG1-017`) |
| `app/Models/Tenant` | `User.php` | `FIX-017` |
| `app/Providers` | `AnulationServiceProvider.php` | `FIX-018` |
| `app/Services/Payments` | `CulqiCharger.php` | `FIX-001`, `FIX-020` |

**`modules/*` (46 modificados).**

| Módulo | Archivos | Entradas |
| --- | --- | --- |
| Account (3) | `AccountController.php`, `CompanyAccountController.php`, `SummaryReportController.php` | `FIX-007` |
| Dashboard (4) | `Helpers/DashboardData.php`, `DashboardStock.php`, `DashboardView.php`, `DashboardController.php` | `FIX-003`; `DashboardView` y `DashboardController` también `FIX-010` |
| Document (2) | `Traits/SearchTrait.php`; `ValidateDocumentController.php` | `FIX-016`; `FIX-019` |
| DocumentaryProcedure (6) | 5 controladores y `Requests/FileRequest.php` | `FIX-006` |
| Ecommerce (7) | `ConfigurationController.php`, `EcommerceController.php`, `FeaturedProductsViewComposer.php`, `Routes/web.php`; vistas `cart/detail.blade.php`, `items/partial.blade.php`, `layouts/partials_ecommerce/cart_dropdown.blade.php` | `FIX-009` (`EcommerceController` también `FIX-014`); `detail` `FIX-025`; `partial` y `cart_dropdown` `FIX-024` |
| Expense (1), Purchase (1) | `payments.vue` de pagos de gastos y de compras | `FIX-021` |
| Finance (5) | `Helpers/ToPay.php`; `PaymentFileController.php`, `Traits/FilePaymentTrait.php`; `ToPayController.php`, `UnpaidController.php` | `FIX-016`; `FIX-013`; `FIX-017` |
| Hotel (8) | `HotelCategoryController`, `HotelFloorController`, `HotelRateController`, `HotelReceptionController`, `HotelRentController`, `HotelRoomController`, `Requests/HotelAddRateToRoomRequest.php`; `Checkout.vue` | `FIX-005` (`HotelReceptionController` y `HotelAddRateToRoomRequest` también `FIX-016`); `Checkout.vue` `FIX-023` |
| Inventory (1), Order (1) | `InventoryVoidedServiceProvider.php`; `OrderNoteController.php` | `FIX-018` |
| LevelAccess (1) | `Routes/web.php` | `FIX-010` |
| Offline (2) | `OfflineConfigurationController.php`, `OfflineConfigurationRequest.php` | `FIX-008` |
| Padron (2) | `PadronController.php`, `Routes/web.php` | `FIX-002` (+ eliminación de dos métodos vacíos sin registro, `IDG3-011`) |
| Sale (1) | `ContractController.php` | `FIX-017` |
| Services (1) | `Data/ServiceData.php` | `FIX-004` |

**Frontend, rutas, configuración y dependencias (19 modificados).**

| Ubicación | Archivos | Entradas |
| --- | --- | --- |
| `resources/js/views/tenant` | `documents/partials/payments.vue`, `sale_notes/partials/payments.vue` | `FIX-021` |
| `resources/js/views/tenant/pos` | `index.vue`, `partials/payment.vue`, `partials/multiple_payment.vue` | `FIX-022` |
| `resources/views/tenant/orders` | `index.blade.php` | `FIX-017` |
| `public/porto-ecommerce/assets/js` | `cart.js`, `main.js`, `main.min.js` | `FIX-024` |
| `routes` | `api.php`; `web.php` | `FIX-015` y `FIX-027` (+ hunks heredados, `TC1-013`); `FIX-015` |
| `config` | `session.php` (`same_site` pasa a `env('SESSION_SAME_SITE', 'lax')`) | heredado/no registrado (`IDG1-008`) |
| `docker/nginx` | `default.conf` (niega `.php` bajo `/storage` y sirve SVG con CSP y `nosniff`) | heredado/no registrado (`IDG1-007`) |
| raíz | `composer.json`, `composer.lock` (retirada de `culqi/culqi-php`) | heredado/cambio del usuario, preservado a propósito |
| `tests/Unit` | `PaymentsTest.php` | `FIX-001`, `FIX-020` |
| `public/js`, `public/` | `manifest.js`, `manifest.js.map`, `mix-manifest.json` | `OPS-001` (build) |

### 6.2 (b) Archivos eliminados (5)

| Archivo | Entrada | Observación |
| --- | --- | --- |
| `app/Http/Controllers/Tenant/Dispatch2Controller.php` | heredado/no registrado | Código muerto en `HEAD` según `findings/dead-code.md` (`DEAD-001`) y G1; sin referencias colgantes. |
| `app/Http/Controllers/Tenant/InventoryController.php` | heredado/no registrado | Ídem; las rutas vigentes usan el controlador del módulo Inventory. |
| `app/Http/Controllers/Tenant/InventoryController2.php` | heredado/no registrado | Ídem. |
| `modules/Account/Resources/views/accounting/templates/excel_concar_bk.blade.php` | `FIX-010` | Vista de respaldo sin referencias. |
| `modules/Ecommerce/Http/ViewComposers/TakeProductoViewComposer.php` | `FIX-010` | La referencia en `ViewServiceProvider` está dentro de un comentario de bloque. |

La verificación de «sin consumidores» es estática; no descarta referencias construidas dinámicamente ni plantillas externas.

### 6.3 (c) Archivos nuevos sin seguimiento: código, pruebas y UI (23)

| Archivo | Entrada |
| --- | --- |
| `app/CoreFacturalo/Helpers/Storage/TempImageHelper.php` | `FIX-011` |
| `app/Services/Payments/CulqiErrorPolyfills.php` | `FIX-001` |
| `modules/Finance/Helpers/PaymentFileHelper.php` | `FIX-013` |
| `resources/js/helpers/pos_draft.js` | `FIX-022` |
| `tests/Unit/{SunatResponse, TempImageHelper, PaymentFile, CustomerAccessBoundary, ResellerAccess, QueryCorrectness, UserSerialization, StockReversal, ValidateDocumentRetry, ExceptionHandler, ApiRouteAuth, ApiDocumentSend}Test.php` (12) | `FIX-012`, `011`, `013`, `014`, `015`, `016`, `017`, `018`, `019`, `026`, `027`, `028` respectivamente |
| `tests/ui/{payments-double-submit, pos-draft-isolation, hotel-checkout-retry, ecommerce-cart-xss, ecommerce-public-cart-xss, ecommerce-main-min-xss, ecommerce-cash-loader}.test.js` (7) | `FIX-021`, `022`, `023`, `024`, `024`, `024`, `025` respectivamente |

Los tres helpers nuevos son código de producción que sus `FIX` necesitan; al no estar versionados, un commit que olvide agregarlos rompería `ItemController`, `FilePaymentTrait`, el POS y `CulqiCharger`.

### 6.4 (d) Documentación de auditoría (22 archivos sin seguimiento, 23 con este README)

`docs/audit/{AUDIT_LOG, BASELINE, PROGRESS, fixes-batch1}.md` y 16 archivos en `docs/audit/findings/` (`core`, `dead-code`, `frontend-core`, `frontend-modules`, `inherited-diff`, `inherited-diff-g1`, `inherited-diff-g2`, `inherited-diff-g3`, `modules-inventory`, `modules-ops`, `modules-sales`, `security`, `sql`, `system-auth-infra`, `tenant-controllers-1`, `tenant-controllers-2`). Más `docs/audit/README.md`.

### 6.5 (e) Artefactos de build (`OPS-001`)

| Artefacto | Estado en git |
| --- | --- |
| `public/js/manifest.js`, `public/js/manifest.js.map`, `public/mix-manifest.json` | Versionados y modificados (+19/−3). |
| 8 chunks nuevos (`chunk-tenant-pos-index`, `…-hotel-rent-checkout`, `…-documents-index`, `…-sale-notes-index`, `…-purchases-index`, `…-expenses-index`, `…-finance-to-pay-index`, `…-finance-unpaid-index`) con sus mapas (16 archivos) | Ignorados por `.gitignore` (no aparecen en `git status`); existen solo en disco. Chunks de compilaciones anteriores con los mismos prefijos siguen en el disco. |

Rollback del build: ver sección 10.6.

### 6.6 Cómo recomputar los números

```bash
cd /Volumes/Mac/juniorbardales/Downloads/sunat
git status --short
git diff --stat | tail -1
git diff --name-status
git ls-files --others --exclude-standard
git log --oneline -8
```

---

## 7. Cobertura de la auditoría (cualitativa)

### 7.1 Niveles de evidencia

| Nivel | Significado |
| --- | --- |
| N0 | No revisado. |
| N1 | Lectura estática dirigida (búsquedas por patrón, lectura de fragmentos, `php -l`, comprobación de consumidores). |
| N2 | Revisión estática de solo lectura de un área completa, con informe de hallazgos. |
| N3 | Corregido y con prueba automatizada que fija el comportamiento. |
| N4 | Corregido, con prueba automatizada y revisión independiente fresca. |
| N5 | Verificado en ejecución sin escribir (contenedor, HTTP de solo lectura, navegador autenticado en solo lectura, metadatos de esquema). |
| N6 | Verificado con escritura real o integración real (bases de negocio, SUNAT/OSE/Culqi). NINGUNA área llega a este nivel. |

Los niveles no son una escala de calidad lineal: un área puede tener N4 en unas partes y N2 en el resto. La columna «Nivel máximo» dice lo más alto alcanzado en alguna parte del área, no en toda ella.

### 7.2 Matriz por área

| Área | Qué se cubrió | Nivel máximo | Qué NO se cubrió | Evidencia |
| --- | --- | --- | --- | --- |
| `app/CoreFacturalo` (sin plantillas) | Lectura completa de los archivos no-plantilla (131 según `core.md`; 123 según `PROGRESS.md`); `CORE-001` corregido (CDR/SOAP); aceptación fiscal. | N4 (SOAP/CDR con fake, sin red) | Efecto en estados reales; resto de hallazgos `CORE-*` sin corregir (p. ej. `CORE-007` descartado como falso positivo). | `findings/core.md`, `FIX-012`, `SunatResponseTest` |
| WS/SUNAT (`WS/*`, envío) | Lectura; clasificación de respuestas y rechazo de CDR inválidos; plazo de envío fijado indirectamente por `ApiDocumentSendTest`. | N4 offline | Envío SOAP real, CDR real, OSE, comportamiento de la regularización contra SUNAT. | `FIX-012`, `FIX-028`, `inherited-diff-g2.md` |
| `app/Services/Payments` (Culqi) | Lectura completa; fallback HTTP sin SDK; clasificación de resultados (aprobado/declinado/desconocido). | N4 offline | Cobro real o sandbox, credenciales válidas, idempotencia; `CartPricing` con tasa de cambio `0` (`MOP-005`). | `FIX-001`, `FIX-020`, `PaymentsTest` |
| `app/Http/Controllers/Tenant` (mitad 1: 35 controladores; `tenant-controllers-1.md`) | Lectura completa de los 35, unas 265 acciones; `FIX-011`, `FIX-014`, `FIX-018` tocan partes. Hunks heredados (CompanyController, CertificateController, DownloadController, ConfigurationController…) revisados estáticamente (G1). | N4 en las partes corregidas | `CoreFacturalo` interno, módulos y `input.request`; la mayoría de los `TC1-*` sin corrección registrada; CRUD simples solo por patrón. | `tenant-controllers-1.md`, `inherited-diff-g1.md` |
| `app/Http/Controllers/Tenant` (mitad 2: 35 controladores; `tenant-controllers-2.md`) | Lectura completa de los 35 con 21 FormRequests y sus recursos; `FIX-011`, `FIX-017`, `FIX-018`, `FIX-016` tocan partes. | N4 en las partes corregidas | Internos de Excel; `TC2-002` no cerrado; mayoría de los `TC2-*` sin corrección. | `tenant-controllers-2.md` |
| System, Auth, Middleware, Providers, Tenancy, Console, rutas, config | Lectura completa (13 controladores de System, 5 de Auth, 14 middleware, 15 providers, 18 comandos, rutas y config); `SYS-001` corregido; guards de `client`; rutas API abiertas cerradas; HTTP real de solo lectura (401/404, `/login` 200, `/dashboard` 302). | N5 (solo lectura), N4 en las partes corregidas | `route:list` (no hay línea base de rutas); `route:cache` frente al registro condicional `if ($hostname)`; `tenancy:run` no registrado; la mayoría de los `SYS-*` sin corregir. | `system-auth-infra.md`, `FIX-014`, `FIX-015`, `FIX-026`, `FIX-027`, `OPS-002` |
| Models (`app/Models`) | Lectura de los modelos de negocio del núcleo; `User` corregido (serialización). | N4 (solo `User`) | Modelos simples de catálogos solo por patrón; relaciones y scopes no probados contra datos. | `findings/core.md`, `FIX-017` |
| Módulos Document, Sale, Order, Pos, Purchase, Expense, Finance | Lectura de rutas, controladores, requests, recursos y modelos; `FIX-013`, `016`, `017`, `018`, `019` corrigen partes. | N4 en las partes corregidas | Vistas Blade y Vue de esos módulos; 28 hallazgos `MSL-*` en su mayoría sin corregir; consultas por fila de Finance (`SQL-005`). | `modules-sales.md` |
| Módulos Inventory, Item, Report | Lectura de rutas, providers, modelos, controladores, exports y recursos; `MIN-001` corregido (`FIX-018`). | N4 (reversión de stock, SQLite) | Vue/JS de esos módulos; Blade de reportes salvo los leídos; concurrencia de stock (`SQL-003`); 17 hallazgos `MIN-*` mayormente sin corregir. | `modules-inventory.md`, `FIX-018` |
| Módulos Account, Dashboard, Hotel, Ecommerce, BusinessTurn, DocumentaryProcedure, LevelAccess, Offline, Padron, Services | Lectura (con partes solo por patrón, p. ej. `AccountController` 330-790, `DashboardInsights`); `FIX-002` a `FIX-010` y partes de `FIX-014`/`016`/`024`/`025`. | N4 (solo `FIX-014`, `016`, `024`, `025`); `FIX-002` a `010` en N1 | Blade y Vue de esos módulos; sin pruebas funcionales propias para `FIX-002` a `FIX-010`; Padron, BusinessTurn y la mayoría de los `MOP-*` `RISKY` sin aplicar. | `modules-ops.md`, `fixes-batch1.md`, `inherited-diff-g3.md` |
| Frontend `resources/js` | Cobertura limitada: envío de pagos (4 formularios) y borrador del POS; corregidos con pruebas Node; el bundle cargó en un navegador autenticado en solo lectura. | N5 (carga y descarte de claves en solo lectura), N4 en las partes corregidas | Resto de los 204 archivos Vue, ejecución real de Vue, guard de doble envío en vivo, aislamiento con dos usuarios, formularios hermanos. | `frontend-core.md`, `FIX-021`, `FIX-022`, `OPS-001` |
| Frontend de módulos (`modules/*/Resources/assets/js`) | Cobertura limitada: carrito de Ecommerce, pago en efectivo y checkout de Hotel; corregidos con pruebas Node. | N4 (carga del chunk de Hotel en navegador, solo lectura) | Resto de los componentes de 210 Vue de módulos; reintento de Hotel en vivo; `modules/*/Resources/views` salvo los leídos. | `frontend-modules.md`, `FIX-023`, `OPS-001` |
| Blade y JS públicos de la tienda (`public/porto-ecommerce`) | Sinks de XSS del carrito en `cart.js`, `main.js`, `main.min.js` y dos Blade; `main.min.js` parcheado a mano y ejecutado completo en una prueba Node. | N4 | Navegador real, jQuery real, explotación real; caché de 30 días sin versionado; `culqi()`, `token_public_culqui` y `tag_*` sin escapar; AddThis por `http`. | `FIX-024`, `FIX-025` |
| SQL | Revisión estática de búsquedas, inventario, Finance, validación SUNAT, numeración y Dashboard; metadatos reales de esquema (una instantánea sin bloqueo: sin claves únicas de negocio y cero grupos duplicados en tres tablas); `SQL-001` (servicio) y `SQL-004` corregidos. | N4 (SQLite) y N5 (metadatos) | `EXPLAIN`, latencias, memoria, carga; `padrones` y otros tenants; concurrencia real de numeración y stock; `SQL-003`, `005`, `007`, `008` sin corregir. | `findings/sql.md`, `FIX-016` |
| Código muerto | Consolidación de referencias estáticas de cinco eliminaciones heredadas, `DashboardView::getUnpaid` y duplicados de Finance. | N2 (+ N1 `php -l`) | Referencias dinámicas, plantillas externas, prueba funcional de los flujos afectados; `DEAD-002` activo y sin consolidar. | `findings/dead-code.md`, `FIX-010` |
| Seguridad (XSS, CSRF, subidas, secretos, dependencias) | Barridos por patrón (`{!! !!}`, `eval`, shell, `->all()`, TLS desactivado, descargas, redirects, CSRF); `composer audit --locked` y `npm audit --package-lock-only`; subidas de imágenes y adjuntos corregidas. | N4 en subidas/clientes/reseller/XSS de tienda; N1 en barridos | 1380 plantillas más allá del barrido; módulos Hotel/Offline/BusinessTurn en el informe original; clases de importación Excel; 196 sitios `$request->all()` solo muestreados; cinco avisos de `laravel/framework` 10.50.4 (uno alto) y 170 vulnerabilidades de npm sin tratar. | `findings/security.md`, `FIX-011`, `013`, `014`, `015`, `024` |
| Rutas y API | Rutas web/API del núcleo y de módulos; cierre de `documents/status`, `sendserver` y reseller; borrado de dos rutas muertas; HTTP real de solo lectura. | N5 (401/404) | Consumidores externos (app móvil, integradores); tokens reales de producción; rutas de 18 de los 19 `modules/*/Routes/api.php` solo contadas, no leídas en su anidamiento; `route:cache`. | `ApiRouteAuthTest`, `FIX-015`, `FIX-027`, `OPS-002` |
| Config, Docker, nginx | Lectura de `config/*` clave y de `docker/nginx/default.conf`; hunks heredados de `config/session.php` y nginx revisados estáticamente. | N2 | Comportamiento del nginx modificado en ejecución, cabeceras reales, caché de 30 días, exposición de MariaDB:3307. | `system-auth-infra.md`, `inherited-diff-g1.md` |
| `CoreFacturalo/Templates` (1379 plantillas PDF/XML) | Barrido por patrones (`{!! !!}` y escape en las plantillas XML). | N1 | Lectura de cada plantilla; renderizado de PDF/XML. | `findings/security.md`, `findings/core.md` |
| Pruebas (`tests/`) | 12 archivos PHPUnit y 7 Node nuevos; suite Unit y UI en verde. | N4 (según cada `FIX`) | Suite Feature y Dusk (no ejecutadas); `phase2-php83-verification.php` (no ejecutada, escribe en bases); pruebas funcionales que escriben datos; pruebas Node sin enlazar a `package.json`; sin CI. | `BASELINE.md`, secciones 5 y 10 |
| Diff heredado (89 archivos de código) | Tres revisores independientes de solo lectura; todos los hunks funcionales de `modules/*` están explicados por un `FIX`; 0 críticos, 1 alto, 5 medios, 20 bajos, 13 informativos. | N2 | Ejecución, HTTP, bases, jobs; `APP_DEBUG`/caché de producción; consumidores externos; `FileRequest` con docx/xlsx reales; `webp`; que SUNAT sirva HTTPS; proxy inverso. | `findings/inherited-diff.md`, `g1`, `g2`, `g3` |

### 7.3 Lo que NINGUNA verificación cubrió

- Escrituras reales de negocio: ningún flujo de emisión, pago, nota de venta, compra, gasto, anulación, alquiler ni pedido se ejecutó contra una base con datos. SQLite `:memory:` con funciones fixture no es MariaDB.
- Concurrencia real en MariaDB: numeración de comprobantes, stock por almacén, reversiones, bloqueos de fila y regularización. El esquema local sin claves únicas y con cero duplicados es una instantánea, no una prueba de concurrencia.
- SUNAT, OSE y Culqi reales: ningún envío, consulta de CDR, rechazo, cobro, 3DS ni sandbox.
- Producción: valor real de `APP_DEBUG`, driver de caché, proxy inverso (`TrustProxies::$proxies` es `null`), HTTPS de SUNAT, nginx real con la configuración modificada, caché de 30 días de estáticos y flujo real de despliegue.
- Consumidores externos: app móvil e integradores de la API (los cambios de contrato no se verificaron contra ellos).
- Navegador autenticado con operaciones que escriben: guard de doble envío de pagos (`FIX-021`), aislamiento del POS entre dos usuarios (`FIX-022`), creación de claves `pos_draft:…`, reintento solo-finalizar de Hotel (`FIX-023`), carrito/dropdown de la tienda (`FIX-024`), loader de efectivo (`FIX-025`).
- Dos usuarios simultáneos en el mismo navegador y dos pestañas con el mismo formulario.
- Idempotencia en servidor para pagos y emisión.
- Scheduler y jobs: `tenancy:run` no está registrado; no se observó proceso de scheduler en Docker/FPM y no se descarta un cron del host.
- Importaciones y exportaciones Excel, generación de PDF/XML real, correos, backups, comandos de consola y plantillas.
- Dependencias: no se actualizó ni se probó ninguna versión de `laravel/framework` ni de paquetes npm.
- La suite Feature y las pruebas Dusk.

---

## 8. Todo lo que falta: pendientes priorizados

Prioridades usadas aquí: **P0** = condición para poder publicar; **P1** = riesgo alto o decisión que debe tomarse pronto; **P2** = corregir en una tanda posterior; **P3** = informativo o limpieza. La priorización de los hallazgos `IDG*` sigue la de `findings/inherited-diff.md`; el resto es una ordenación propia a partir de los registros y debe confirmarla quien decide.

### 8.1 P0 — antes de publicar

| ID | Descripción | Por qué importa | Dependencia/precondición | Decide |
| --- | --- | --- | --- | --- |
| P0-01 | Commitear por unidades de trabajo (`work-unit-commits`) y revisar el diff completo antes de cualquier PR. Hoy hay 98 modificados, 5 eliminados y 43 nuevos sin commit, incluidos helpers de producción sin seguimiento (`TempImageHelper.php`, `PaymentFileHelper.php`, `CulqiErrorPolyfills.php`, `pos_draft.js`). | Nada de lo hecho está a salvo ni es desplegable; olvidar un archivo nuevo rompe código. El presupuesto de líneas y la estrategia de PR siguen sin definir. | Decidir la estrategia de entrega y de cadena de PR (el diff supera ampliamente las 400 líneas). | Usuario |
| P0-02 | Verificación funcional con escritura real en un entorno desechable: `FIX-021` (doble envío), `FIX-022` (POS con dos usuarios), `FIX-023` (reintento de Hotel), `FIX-024` (carrito de la tienda), `FIX-025` (efectivo), flujos de emisión/pago/anulación afectados por `FIX-012`, `013`, `017`, `018`, `019`, y las correcciones `FIX-003` a `FIX-010` que nunca tuvieron prueba funcional. | Todo está verificado con fakes, SQLite o lectura; ninguna corrección se vio funcionar contra datos reales. | Base descartable y copia de datos; navegador autenticado. | Usuario / responsable de QA |
| P0-03 | Confirmar `APP_DEBUG=false` y el driver de caché de producción, y ejercitar el enmascarado del `Handler` (`FIX-026`) donde `APP_DEBUG=false`. | Local corre con `APP_DEBUG=true`; el comportamiento real en producción nunca se observó. | Acceso a la configuración de producción por quien corresponda (no se leyó `.env`). | Usuario / infraestructura |
| P0-04 | Comunicar a integradores y a la app móvil los cambios de contrato (sección 9.2) y comprobar que sus tokens funcionan en `documents/status` y `sendserver`. | Cambian a 401/404/422 conductas previas; los consumidores externos no son visibles desde el repositorio. | Lista de integradores; ventana de aviso. | Usuario |
| P0-05 | Despliegue del bundle y de los estáticos: `chunk-*.js` está ignorado por git mientras el `manifest.js` versionado los referencia; `main.min.js` sin parámetro de versión con caché de 30 días en nginx. | Un despliegue basado solo en git llevaría un manifiesto que apunta a archivos inexistentes y navegadores recurrentes podrían seguir ejecutando el JS vulnerable. | Definir si el destino ejecuta `npm run production:js` o recibe `public/js` completo; cache-busting o expiración menor. | Usuario / infraestructura |

### 8.2 P1 — riesgo alto o decisión pronta

| ID | Descripción | Por qué importa | Dependencia/precondición | Decide |
| --- | --- | --- | --- | --- |
| P1-01 | **Padron** (`IDG3-001`, `IDG3-002`): descarga del padrón por HTTP en claro sin verificar TLS y extracción en `public/padron_extract/` (nginx pasa cualquier `.php` a FPM); `charges_data` y `demo` son GET destructivos, `truncate()` precede a `LOAD DATA` y no es transaccional, no hay permiso de módulo (`padron` no figura en `config/permissions.php`) y son disparables por navegación GET (`same_site=lax`). | Posible ejecución de un `.php` colocado por un atacante en ruta y pérdida del padrón ante fallo a medias. | Plan acordado: POST + CSRF; reemplazo transaccional por staging (tabla/versión activa); verificar motor, `LOCAL INFILE` y el layout oficial del archivo; ejecutar solo en una base desechable; sin `TRUNCATE` destructivo; validación del archivo y política de concurrencia/rollback. La metadata viva falló con `PDOException` en la sesión de auditoría. | Usuario / producto |
| P1-02 | `tenancy:run` no está registrado en el scheduler. Registrarlo puede activar trabajos de negocio reales. | Los trabajos programados podrían no estar ejecutándose o activarse con efectos automáticos al registrarlos. | Revisar primero el runtime y los trabajos que ejecutaría; activación aislada, sin jobs reales; no se observó scheduler en Docker/FPM (el compose versionado documenta un servicio de scheduler) y no se descarta un cron del host. | Usuario / infraestructura |
| P1-03 | Concurrencia real de numeración (`SQL-007`, `Functions::newNumber` y `DocumentObserver`) y de stock (`SQL-003`, `updateStock`/`updateStockPurchase`) en MariaDB; no hay claves únicas de `(soap_type_id, document_type_id, series, number)`, de `external_id` ni de `(item_id, warehouse_id)`. | Números repetidos y pérdida de movimientos de stock bajo emisiones o movimientos simultáneos. | Motor aislado equivalente; revisión de datos antes de cualquier índice único; autorización explícita para migrar. El esquema local sin duplicados no prueba la ausencia de la carrera. | Usuario |
| P1-04 | Integraciones reales SUNAT, OSE y Culqi (sandbox): envío, CDR, rechazo, regularización, cobro, 3DS, idempotencia. | La capa de SUNAT y de pagos se verificó con fakes; los comportamientos reales siguen ignorados. | Credenciales y entorno de pruebas del proveedor, aportados por el usuario; no se usan credenciales reales de producción. | Usuario |
| P1-05 | Flujo `culqi()` de la tienda: abre el loader antes de `await app_cart.getFormPaymentCash()` sin `try/catch` ni guard de doble envío; `{!! json_encode($configuration->token_public_culqui) !!}` (`cart/detail.blade.php`, hacia la línea 683) y `{!! $information->tag_* !!}` (`sidebar_product_right`) se imprimen sin escapar; `app_cart.order_generated = order` lanza `ReferenceError` por una variable inexistente. | Misma clase de defectos que `FIX-025` y `FIX-024` en el camino de pago con tarjeta; la entrada es configuración de administrador, no datos de carrito. | Pruebas Node análogas a `ecommerce-cash-loader`; decisión sobre escapado. | Usuario |
| P1-06 | Idempotencia en servidor para pagos y emisión (clave de idempotencia o restricción única). | Los guards de `FIX-021`/`FIX-025` son solo de interfaz; otra pestaña, reintentos o solicitudes independientes siguen pudiendo duplicar. | Diseño de contrato (cabecera/clave) y cambios coordinados con el cliente. | Usuario / producto |
| P1-07 | Fugas residuales de mensajes tras `FIX-026`: `modules/Sale/Services/ContractBillingService.php:219` reenvuelve cualquier `Throwable` no `Exception` (p. ej. `TypeError`) como `\Exception($e->getMessage())`, lo que evita la lista de denegación; `GreConnectionException` (`WS/Gre/GreClient.php:268`) incluye el mensaje de Guzzle/cURL con host y URL; unos 25 `catch` de controladores devuelven `$e->getMessage()` directamente (p. ej. `TaskController.php:78`, `PurchaseController.php:680` y `:801`). | Siguen pudiendo mostrar mensajes y rutas internas al cliente. | Prueba por caso; decidir una excepción de dominio frente a enmascarar por tipo. | Usuario |
| P1-08 | `IDG1-002`: el `Handler` cambia el contrato HTTP (500→404 en `ModelNotFound`, estado real en `HttpException`, desaparecen `file`/`line`); `documentStatus` cambia de código y forma. | Impacto sobre móvil e integradores sin verificar. | Contrastar con los consumidores reales. | Usuario |
| P1-09 | Revisión de infraestructura: MariaDB publicada en `:3307` sobre todas las interfaces según la observación registrada (`AUDIT_LOG.md`, «Pendientes y no aplicados»). El compose versionado documenta `127.0.0.1` por defecto, pero el 2026-11-18 el contenedor vivo seguía publicado en `0.0.0.0:3307` (observado con `docker ps`), así que la exposición es real en este entorno. | Exposición de la base de datos a la red local. | Recrear el contenedor con `DB_BIND=127.0.0.1` (o dejar el valor por defecto del compose) y revisar firewall; no se cambió configuración desde la auditoría. | Usuario / infraestructura |
| P1-10 | Dependencias con avisos: `composer audit --locked` reportó 5 avisos, todos en `laravel/framework` 10.50.4 (uno alto, uno medio, dos bajos, uno sin clasificar) y `laravelcollective/html` abandonado; `npm audit --package-lock-only` reportó 170 vulnerabilidades (20 críticas, 73 altas, 61 moderadas, 16 bajas), casi todas transitivas de build. | Superficie conocida de vulnerabilidades sin atender. | Planificar actualizaciones con pruebas; no se instaló ni actualizó nada. | Usuario |

### 8.3 P2 — tanda posterior

| ID | Descripción | Por qué importa | Dependencia/precondición | Decide |
| --- | --- | --- | --- | --- |
| P2-01 | Formularios hermanos sin guard de doble envío: cotizaciones, servicios técnicos, `system/clients`. | Misma brecha que `FEC-001` en formularios no cubiertos. | Extender `FIX-021` con su prueba. | Usuario |
| P2-02 | Hotel: `arrears` editable tras emitir (L1); mensaje engañoso ante 422 «ya finalizada» cuando la primera finalización sí tuvo éxito (L3); la recarga pierde `pendingFinalizationDocumentId` y el botón volvería a emitir (evitarlo exige cambio de backend); `saveCashDocument()` sin reintento; `changeRoomStatus` sin validación de transiciones y `addProductsToRoom` sin comprobar renta finalizada (`IDG3-003`). | Estados inconsistentes y re-emisión tras recargar. | Cambio de backend para el estado pendiente. | Usuario |
| P2-03 | Prueba funcional con escritura de los cinco flujos de frontend (ver `P0-02`) y aislamiento de dos usuarios en el POS. | Los guards se probaron por extracción de código, no con Vue real. | Entorno desechable. | Usuario |
| P2-04 | `IDG1-005`/`IDG1-006`: `CertificateController::destroy` borra un PEM de un directorio compartido entre tenants sin vuelta atrás; la clave privada se escribe con permisos por defecto (probablemente 0644). | Pérdida de certificado y exposición de la clave. | Diseño por tenant del almacenamiento. | Usuario |
| P2-05 | `IDG1-007`: la protección CSP contra SVG solo existe en la imagen docker; `LoginConfigurationController` sigue aceptando SVG. | Protección dependiente del entorno de despliegue. | Decidir política común. | Usuario |
| P2-06 | `IDG1-010`: la ruta pública de tipo de cambio no tiene límite de tasa y dispara consultas externas y escrituras. | Abuso y costos. | Throttle y revisión de la semántica de upsert (`IDG1-009`). | Usuario |
| P2-07 | `IDG1-011`: cerrar una caja ya cerrada devuelve 422 y la UI solo hace `console.log`; el cierre sigue siendo por `GET` y persiste la carrera con `cash_document` (`TC1-013`). | Retroalimentación al usuario y carrera. | Cambio de UI y de método HTTP. | Usuario |
| P2-08 | `IDG3-004`: los `throttle:N,M` numéricos comparten un solo contador por usuario/IP (login, registro, `culqi`, `payment_cash`; `consulta`, `charges_data`, `demo` de Padron); `TrustProxies::$proxies` es `null`. | Los límites no protegen por ruta; la IP real depende del proxy. | Throttles nombrados; configuración del proxy. | Usuario / infraestructura |
| P2-09 | `IDG3-005`: `records` y `POST regularize` de Document no llevan `tenant.internal.mode`. | Operaciones disponibles en modos donde no deberían. | Decisión de producto. | Usuario |
| P2-10 | `IDG2-007`: `AccountController::updatePlan` escribe en dos conexiones sin atomicidad y cualquier usuario con el módulo `cuenta` puede fijar un `plan_id` sin pago. | Integridad de planes y facturación. | Diseño transaccional entre conexiones. | Usuario |
| P2-11 | `IDG2-003`, `IDG2-004`, `IDG2-005`, `IDG2-006`, `IDG3-006`, `IDG3-007`: sin pruebas de regresión en `ItemController::store`; `ResponseCode` del CDR sin recortar espacios; cliente de tienda con sesión que abre el login del personal queda en 403; cambios del JSON móvil sin registrar; `FileRequest` dependiente de `guessExtension()`; regularizar `09→05` no reaplica stock. | Brechas de pruebas y de contrato. | Pruebas dirigidas. | Usuario |
| P2-12 | Hallazgos `RISKY` de `fixes-batch1.md` no aplicados: `MOP-001` (auto-registro asigna el módulo `10`), `MOP-003` parte SQL y `MOP-004` (golden-master), `MOP-005` (tasa de cambio `0` en `CartPricing`), `MOP-006` (`'ajax' => true` en `config/permissions.php`), `MOP-009`, `MOP-011` parte TLS, `MOP-012` (token expuesto en `bussiness_turns/tables`), `MOP-017` parte repricing, `MOP-018`, `MOP-019`, `MOP-020`, `MOP-021` (paginación/constructor), `MOP-023` (parte `whereHas`), `MOP-027`, `MOP-028`, `MOP-034` (numeración por `count()+1`), `MOP-037` resto. | Cada uno altera contratos o reglas comerciales y exige decisión. | Cada ítem tiene su motivo en `fixes-batch1.md`. | Usuario / producto |
| P2-13 | SQL pendientes: `SQL-005` (consultas por fila en Finance), `SQL-006` resto (bucles ilimitados de servicio/CLI/OCR, `ValidateDocumentsCommand`, `ValidateCpe2`; el máximo de tres no es un plazo total), `SQL-008` (Dashboard materializa historial), `DEAD-002` (transformadores duplicados de Finance). | Rendimiento y disponibilidad a escala. | Fixtures de equivalencia y medición. | Usuario |
| P2-14 | Enlazar las pruebas nuevas: los 7 `tests/ui/*.test.js` no están en `package.json` (solo `test:dashboard`); no hay CI. | Las pruebas pueden dejar de ejecutarse sin que nadie lo note. | Decisión de CI y de scripts. | Usuario |
| P2-15 | `php artisan route:cache` puede romper el registro condicional `if ($hostname)` de `routes/api.php`; `SYS-002` (lectura de `env('APP_URL_BASE')` al arrancar). | Riesgo de despliegue preexistente que ninguna prueba cubre. | Probar con caché de rutas en entorno de prueba. | Usuario / infraestructura |
| P2-16 | Resto de los hallazgos de los ocho informes sin corrección registrada. Al 2026-11-17 (Engram #875), 33 IDs tenían un `FIX` explícito frente a 176 sin mapear; ese conteo no prueba resolución ni severidad actual porque los informes se solapan, y desde entonces se añadieron `FIX-014` a `FIX-028`. | La mayoría de los `TC1-*`, `TC2-*`, `SYS-*`, `CORE-*`, `MSL-*`, `MIN-*`, `MOP-*` y `SEC-*` requiere triage. | Reconciliar cada ID contra su estado real. | Usuario |
| P2-17 | Temporales UUID huérfanos tras un rollback externo (`FIX-013`) y temporales abandonados o expirados que ninguna comprobación elimina (`FIX-011`). | Acumulación de archivos. | Diseño de recolección. | Usuario |
| P2-18 | Revisar el resto de los `.getMessage()` y de endpoints que el `Handler` no cubre (rutas Blade/web sin prueba). | Brecha de pruebas del `Handler` (`parent::render`). | Pruebas dirigidas. | Usuario |
| P2-19 | `INFORME_FINAL.md` (fase 7). | Es el entregable final de la auditoría. | Redactado el 2026-11-18; no cierra la auditoría integral, que depende de los P0 y de la verificación N6. | Usuario |
| P2-20 | Defectos preexistentes detectados en la validación integral: `GET /ecommerce/login` 500 (vista `ecommerce::user.login` ausente, AUD-01) y ocho subconsultas de pagos de finanzas en la conexión por defecto (`DashboardView`, `ToPay`, `AccountsReceivable`; AUD-02), que rompen `unpaid`/`to-pay` y sus exportaciones. | Funciones de tienda y finanzas rotas en este entorno; no las introdujo la auditoría. | Restaurar/redirigir la vista de login y usar `DB::connection('tenant')`; añadir una prueba con dos conexiones. | Usuario |

### 8.4 P3 — información y limpieza

- `AddThis` carga un script de terceros por `http` (`$.getScript("http://s7.addthis.com/...")`); fuera del alcance de `FEM-001`, a revisar aparte.
- `modules/Offline/Http/Requests/OfflineConfigurationRequest.php` conserva dos líneas con espacios finales; `git diff --check` las señala. Limpieza cosmética.
- Restos huérfanos inofensivos: vista `tenant/inventories/index.blade.php`, `Dispatch2Validation` y entradas del classmap de `vendor`; rutas `POST services/exchange_rate`/`search_exchange_rate` que apuntan a métodos inexistentes (preexistente, `IDG1-014`); aviso de PHP 8.4+ en `CulqiCharger.php:25` (`IDG2-008`).
- `reportedIds` del `Handler` usa `spl_object_id` (reutilizable tras el recolector de basura y sin límite en procesos longevos; alternativa `WeakMap`; no hay Octane en `composer.json`) (`IDG1-004`).
- La lista de denegación de `isUserFacing` no tiene prueba propia (quitarla no hace fallar la suite porque no existe hoy una clase `App\` que herede de esas excepciones).
- `sendServer` sigue dando 500 con un id inexistente (`Document::find` → `null->data_json`), ahora solo con autenticación.
- `FIX-025`: `processing = true` se fija antes del `swal` de carga, que queda fuera del `try`; una excepción de ese `swal` dejaría el botón deshabilitado hasta recargar.
- `FIX-021`: sin timeout por defecto en axios, y el `.catch` heredado accede a `error.response.status` y falla ante errores de red; el `finally` libera la fila igualmente.
- `FIX-024`: el título del dropdown usa `name` en `main.js`/`main.min.js` y `description` en `populate()`/`cart.js` (inconsistencia previa, no de seguridad); `remove()` con índice inexistente hace `splice(-1,1)`.
- Memoización estática del tipo de cambio (`IDG3-009`), dependencias operativas de `PaymentFileHelper` (`IDG3-010`) y asimetría de `LoginConfigurationController` (`IDG1-015`).
- Los borradores del POS de usuarios pasados quedan en el navegador, legibles solo por el mismo usuario y host.

---

## 9. Riesgos, avisos de despliegue y cambios que rompen compatibilidad

### 9.1 Avisos de despliegue

1. **Bundle de frontend.** `chunk-*.js` y sus mapas están en `.gitignore`; `public/js/manifest.js` (versionado) los referencia. Desplegar solo desde git rompe el manifiesto. El destino debe ejecutar `npm run production:js` o recibir `public/js` completo. No se verificó el flujo real de despliegue.
2. **Cache-busting de la tienda.** nginx serviría `.js` con `expires 30d` y las layouts cargan `main.min.js` sin parámetro de versión; navegadores recurrentes podrían seguir ejecutando el archivo vulnerable hasta 30 días (observación del revisor, sin verificar en servidor).
3. **Archivos nuevos sin seguimiento.** Cuatro archivos de producción (`TempImageHelper.php`, `PaymentFileHelper.php`, `CulqiErrorPolyfills.php`, `pos_draft.js`) no están versionados.
4. **Cambios heredados de infraestructura.** `docker/nginx/default.conf` (negar `.php` bajo `/storage`; SVG con CSP y `nosniff`) y `config/session.php` (`same_site` pasa a `lax` por defecto); su efecto en ejecución no se verificó.
5. **`route:cache`.** Puede romper el registro condicional de rutas por `$hostname`.
6. **`APP_DEBUG` y caché de producción.** No verificados. Si `APP_DEBUG=true` en producción, el enmascarado de `FIX-026` no actúa.
7. **Caché de cargas temporales.** `FIX-011`/`FIX-013` guardan el registro de emisión en la caché por dos horas; limpiar la caché invalida cargas pendientes.
8. **Pérdida única de borradores del POS** al actualizar (`FIX-022`): las claves genéricas se descartan sin adoptarse.
9. **Descarga de dependencias.** La retirada de `culqi/culqi-php` ya está en `composer.json`/`composer.lock` y el código usa un fallback Guzzle; cualquier despliegue que reinstale dependencias debe partir de ese lock.
10. **Rollback del build.** Procedimiento en la sección 10.6; no se ejecutó sobre `public/` real.

### 9.2 Cambios que rompen compatibilidad o alteran contratos

| Cambio | Quién lo nota | Entrada |
| --- | --- | --- |
| `POST /api/reseller/detail` y `POST /api/reseller/lockedAdmin` exigen sesión de System admin y CSRF | Integraciones anónimas de reseller | `FIX-015` |
| `POST /api/documents/status` y `GET /api/sendserver/{id}` responden 401 sin token (403 con token `client` o tenant bloqueado) | Integradores externos, app móvil | `FIX-027` |
| `services/validate_cpe` y `services/consult_status` eliminadas (404) | Cualquier llamador externo (no hay consumidores en el repositorio) | `FIX-027` |
| `Api\DocumentController@send`: comprobaciones previas pasan de 500 a 404/422 con JSON `{success:false,message}`; `documentCheckServer` devuelve 404 JSON | Integradores que dependan del 500 | `IDG2-002`, `FIX-028` |
| `Handler`: 404 en `ModelNotFound`, estado real en `HttpException`, sin `file`/`line`; en producción las excepciones de librerías salen como «Error interno del servidor» | App móvil e integradores | `IDG1-002`, `FIX-026` |
| Cuentas tenant de tipo `client` reciben 403 en `auth`/`auth:api` y en el login móvil; los nuevos clientes de tienda no reciben token API; los tokens anteriores de clientes quedan bloqueados | Clientes de tienda con tokens antiguos | `FIX-014` |
| `User` oculta `password`, `remember_token` y `api_token` al serializar | Consumidores que dependieran de la serialización incidental | `FIX-017` |
| Cargas temporales de imágenes y adjuntos deben haber sido emitidas a la misma sesión; las anteriores o de más de dos horas requieren volver a subirse | Usuarios con cargas en curso | `FIX-011`, `FIX-013` |
| Un CDR con estructura, namespace o código inválido se rechaza en lugar de producir aceptación | Flujos de SUNAT | `FIX-012` |
| Subidas: `svg` fuera y `webp` dentro de las listas de MIME; `uploadFile` de Ecommerce solo acepta `image` con `type` en lista; validación de `type` de `CompanyController::uploadFile` | Usuarios que subían SVG; formularios con `type` no listado (`IDG3-008`) | heredado, `FIX-009`, `FIX-013` |
| Nombre de ruta del POST de login de la tienda: `tenant_ecommerce_login_post` | Código que la referencie por nombre | `FIX-009` |
| `throttle` añadido a login, registro, `culqi` y `payment_cash` de la tienda y a Padron | Uso intensivo legítimo | `FIX-009`, `FIX-002` |
| Padron: requiere sesión, dominio de tenant y `locked.tenant`; `consulta` valida RUC | Procesos externos sin sesión | `FIX-002` |
| `/levelaccess` requiere dominio de tenant y `auth` | Acceso anónimo previo | `FIX-010` |
| Cierre de caja ya cerrada: 422 | UI de caja | heredado, `IDG1-011` |
| `ExchangeRateController::store` cambia a upsert y rellena `purchase` con `sale` | Consumidores del tipo de cambio | heredado, `IDG1-009` |
| `lots_group` de `DispatchController::tables` cambia de forma | UI de guías | heredado, `IDG1-012` |

---

## 10. Cómo reproducir las verificaciones

Los comandos salen de `BASELINE.md`, `PROGRESS.md` y `AUDIT_LOG.md`. Ninguno escribe en bases de negocio. Ejecutarlos desde la raíz del repositorio.

### 10.1 Entorno

```bash
cd /Volumes/Mac/juniorbardales/Downloads/sunat
docker exec facturador_fpm php -v | head -1; node -v
```

### 10.2 Lint de todo el PHP en el contenedor

Imprime solo líneas de error, después el total de archivos. El resultado esperado es cero líneas de error (`OPS-002`: 3277 archivos; 3278 tras añadir la prueba de FIX-028). El conteo del `find` del contenedor incluye archivos ignorados por git.

```bash
docker exec facturador_fpm sh -c 'cd /var/www/html && find app modules routes config database tests bootstrap -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" -not -path "*/storage/*" -print0 | xargs -0 -n 50 -P 4 php -l 2>&1 | grep -v "^No syntax errors detected"; find app modules routes config database tests bootstrap -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" -not -path "*/storage/*" | wc -l'
```

### 10.3 Suite Unit en el contenedor (PHP 8.3.36)

Último resultado: 336 pruebas / 2097 aserciones, con 2 deprecaciones de PHPUnit. Para una sola clase se añadió `--do-not-cache-result` en los registros.

```bash
docker exec facturador_fpm sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite Unit 2>&1 | tail -30'
```

Ejemplo para una clase (los registros usaron este formato):

```bash
docker exec facturador_fpm sh -c 'cd /var/www/html && php vendor/bin/phpunit tests/Unit/ExceptionHandlerTest.php --do-not-cache-result'
```

En el host (PHP 8.5.5, no soportado) la suite necesita más memoria porque `SireReconcilerTest` agota los 128 M por defecto por `ZipArchive`; los registros usaron `memory_limit=1G`.

### 10.4 Pruebas UI en Node

`npm run test:dashboard` (enlaza `system-dashboard.test.js` y `build-js-publish.test.js`) necesita un `TMPDIR` propio para que la limpieza de su fixture no falle con `EPERM`. Sustituir `<dir>` por un directorio escribible.

```bash
TMPDIR=<dir> npm run test:dashboard
```

Las siete pruebas nuevas no están enlazadas a `package.json`; se ejecutan una por una:

```bash
node tests/ui/payments-double-submit.test.js
```

```bash
node tests/ui/pos-draft-isolation.test.js
```

```bash
node tests/ui/hotel-checkout-retry.test.js
```

```bash
node tests/ui/ecommerce-cart-xss.test.js
```

```bash
node tests/ui/ecommerce-public-cart-xss.test.js
```

```bash
node tests/ui/ecommerce-main-min-xss.test.js
```

```bash
node tests/ui/ecommerce-cash-loader.test.js
```

Gráfico de límites mensuales (solo lectura, en el contenedor; resultado esperado «five cases passed»):

```bash
docker exec facturador_fpm sh -c 'cd /var/www/html && php tests/ui/chart-month-boundaries.php'
```

NO ejecutar `tests/ui/phase2-php83-verification.php` (hace `DROP DATABASE IF EXISTS`/`CREATE DATABASE`) ni la suite Feature sin una base desechable.

### 10.5 Build del frontend

Solo con autorización explícita (genera 16 archivos nuevos ignorados por git y modifica tres versionados). No ejecutar `npm run production` (Sass/CSS) ni `watch`.

```bash
npm run production:js
```

### 10.6 Rollback del build (`OPS-001`)

El procedimiento fue simulado sobre una copia (el árbol resultante fue idéntico al snapshot previo al build); no se ejecutó sobre `public/` real. Restaura los tres archivos versionados desde git:

```bash
git checkout -- public/js/manifest.js public/js/manifest.js.map public/mix-manifest.json
```

Después hay que borrar los 16 archivos de chunk nuevos (ocho `chunk-tenant-*.js` y sus `.map`). Nombres exactos verificados el 2026-11-18:

```text
public/js/chunk-tenant-purchases-index.2d728b0634fe.js
public/js/chunk-tenant-pos-index.14140d779a76.js
public/js/chunk-tenant-documents-index.5e2b807dd0ee.js
public/js/chunk-tenant-sale-notes-index.b96b6371a5b8.js
public/js/chunk-tenant-expenses-index.dcccf25d3b2a.js
public/js/chunk-tenant-finance-to-pay-index.f7566be48e78.js
public/js/chunk-tenant-finance-unpaid-index.3246a3b65005.js
public/js/chunk-tenant-hotel-rent-checkout.b7ac927fd53f.js
```

Más el `.map` de cada uno. Los chunks antiguos con los mismos prefijos deben permanecer.

### 10.6.1 Comprobaciones HTTP locales de solo lectura

Los registros describen estas comprobaciones contra `localhost:8080` y `net.localhost:8080` (sin sesión, ids inexistentes): `/login` responde 200 y `/dashboard` 302 hacia el login del mismo dominio; `POST /api/documents/status`, `GET /api/sendserver/999999999` y `POST /api/documents` responden 401 sin token; `POST /api/services/validate_cpe` responde 404. Las líneas de comando exactas no constan en los registros.

### 10.7 Qué no se reprodujo

`php artisan route:list` falla sin contexto de tenant. La verificación en navegador autenticado de `OPS-001` dependió de que el usuario iniciara sesión por su cuenta y no es repetible sin él.

---

## 11. Decisiones tomadas

| # | Decisión | Por qué |
| --- | --- | --- |
| 1 | Verificar efectos de base solo con SQLite `:memory:` o fakes; no ejecutar la suite Feature ni `phase2-php83-verification.php`. | Evitar cualquier escritura contra bases configuradas; contrapartida: SQLite no prueba bloqueos ni el motor MariaDB. |
| 2 | Parchear a mano `public/porto-ecommerce/assets/js/main.min.js` (tres regiones, marcadores únicos, prefijo y sufijo idénticos a `HEAD`) en lugar de regenerarlo o apuntar las layouts a `main.js`. | `uglify-js` es la 2.8.29 (solo ES5) y el archivo contiene template literals; no hay terser; elegido por el usuario. La falsa seguridad de pruebas que solo cubrían `main.js` se cubrió con una prueba que ejecuta el min completo. |
| 3 | El `Handler` usa una lista de permitidos (`isUserFacing`) y no una lista de enmascarado total ni de denegación. | La aplicación lanza unos 90 `throw new Exception('<mensaje de negocio>')` que la UI muestra (plazo vencido, rechazos SUNAT); enmascarar todo los ocultaba en producción, y exponer todo filtraría SQL y rutas. |
| 4 | Borrar las rutas muertas `services/validate_cpe` y `services/consult_status` en lugar de protegerlas. | Apuntaban a métodos inexistentes; solo producían 500 (y filtraban `file`/`line` con `APP_DEBUG`); recomendación del revisor; sin consumidores en el repositorio. |
| 5 | Reutilizar `web` + `auth:admin` + CSRF para los POST reseller, y excluir `LockedAdmin` solo en esos dos POST y en `/login` de sistema. | No inventar secretos ni un guard nuevo; permitir recuperar acceso cuando la plataforma está bloqueada sin abrir el resto de rutas de negocio. |
| 6 | Rechazar cuentas por tipo de modelo `client` en `Authenticate`, sin lista cerrada de personal. | Conservar `admin`, `seller`, `integrator` y el guard de sistema; no confundir un cliente con personal. |
| 7 | Mantener separadas las dos capas de reversión de stock (global legacy y almacén/lotes/anticipos). | No son providers duplicados; usan cantidades y factores distintos; `09`→`11` debe restituir la capa global pero no repetir la de almacén. |
| 8 | No modificar `Item::scopeWhereWarehouse`, controladores de personas ni `getItemsNotServices`, y dejar controles de regresión. | Las advertencias de precedencia `OR` no se reprodujeron: Eloquent agrupa los scopes locales. |
| 9 | Añadir un `ClientInterface` opcional a `CulqiCharger` en lugar de cambiar su lógica. | Probar el fallback sin red ni SDK manteniendo defaults, clasificación y prioridad del factory. |
| 10 | Mantener el registro de adjuntos de pagos separado del de imágenes temporales. | No refactorizar ni invalidar el registro de imágenes en uso. |
| 11 | `regularize`: reunir consultas fuera de transacción, bloquear todos los IDs por orden determinista, verificar todos los estados antes del primer `save()`, conservar `save()` con eventos. | Evitar transacciones abiertas durante consultas externas y eventos de negocio emitidos para un lote que luego se aborta. |
| 12 | Limitar el build a `npm run production:js` y preservar las entradas de CSS del manifiesto; snapshot previo y rollback simulado. | Reducir el alcance al mínimo que lleva `FIX-021`/`022`/`023` a producción; no tocar Sass. |
| 13 | Guards de interfaz (`FIX-021`, `FIX-025`) sin pretender idempotencia de servidor. | La idempotencia es un cambio de backend aparte y coordinado con el cliente. |
| 14 | El borrador del POS falla cerrado sin identidad de usuario. | Preferir no guardar ni restaurar nada a usar claves genéricas. |
| 15 | No cambiar el backend de Hotel para persistir el estado pendiente tras recargar. | Evitar cambios de backend en una corrección de frontend acotada; queda como pendiente. |
| 16 | Preservar siempre los cambios heredados del usuario, en especial la retirada de `culqi/culqi-php`. | Es una decisión del usuario; el código se adaptó con polyfills y fallback en vez de reintroducir la dependencia. |
| 17 | La suite de referencia es la del contenedor PHP 8.3.36; el host 8.5.5 solo como comprobación adicional. | El runtime soportado es el del contenedor; `FIX-026`/`027` se repitieron allí (`OPS-002`). |
| 18 | `TMPDIR` en el scratchpad para `npm run test:dashboard`. | Evitar el `EPERM` del temporal del sistema sin tocar la lógica de la prueba. |
| 19 | No leer `.env` ni credenciales. | Regla de seguridad del trabajo; deja `APP_DEBUG` de producción sin verificar. |

---

## 12. Glosario de IDs

| ID | Significado |
| --- | --- |
| `FIX-NNN` | Corrección aplicada al código o a las pruebas, correlativa (`FIX-001` a `FIX-028`). |
| `OPS-NNN` | Operación que no es corrección de código (`OPS-001` build y navegador; `OPS-002` verificación en contenedor y HTTP). |
| `TC1-NNN`, `TC2-NNN` | Hallazgos de los controladores de `app/Http/Controllers/Tenant` (mitad 1 y mitad 2). `TC1-013`: `CashController::close` sin transacción ni comprobación de estado. |
| `SYS-NNN` | Hallazgos de System, Auth, Middleware, Providers, Tenancy, Console, rutas y config (`SYS-001`: reseller anónimo; `SYS-002`: rutas con `env('APP_URL_BASE')`). |
| `CORE-NNN` | Hallazgos de `CoreFacturalo`, Services, Support, Models, Traits, Exports e Imports (`CORE-001`: aceptación SUNAT por coerción). |
| `MSL-NNN` | Hallazgos de los módulos de ventas (Document, Sale, Order, Pos, Purchase, Expense, Finance). |
| `MIN-NNN` | Hallazgos de Inventory, Item y Report. |
| `MOP-NNN` | Hallazgos de los módulos de operación (Account, Dashboard, Hotel, Ecommerce, BusinessTurn, DocumentaryProcedure, LevelAccess, Offline, Padron, Services). |
| `SEC-NNN` | Hallazgos de seguridad (`SEC-001`: auto-registro de tienda con sesión de backoffice y token API). |
| `FEC-NNN` | Hallazgos de frontend del núcleo (`FEC-001` doble envío de pagos; `FEC-002` borrador del POS). |
| `FEM-NNN` | Hallazgos de frontend de módulos (`FEM-001` XSS del carrito; `FEM-002` checkout de Hotel; `FEM-003` loader del pago en efectivo). |
| `SQL-NNN` | Hallazgos de SQL (corrección, concurrencia, rendimiento), `SQL-001` a `SQL-008`. |
| `DEAD-NNN` | Hallazgos de código muerto o duplicado, `DEAD-001` a `DEAD-003`. |
| `IDG1-NNN`, `IDG2-NNN`, `IDG3-NNN` | Hallazgos de la revisión del diff heredado por grupos: G1 (19 archivos nunca revisados), G2 (24 del núcleo) y G3 (46 de módulos). |
| `SAFE` / `RISKY` | Etiqueta de «auto-fix» en los informes: corrección acotada frente a corrección que toca contratos o reglas y exige decisión. |
| Estados de comprobante | Los registros nombran `05` aceptado, `07` observado, `09` rechazado (código SUNAT `2335`) y `11` como estado terminal de anulación (`FIX-012`, `FIX-018`). El significado de los demás códigos (`01`, `03`, `13`) no consta en los registros. |
| CDR | Constancia de recepción que devuelve SUNAT; `ERROR_CDR` marca un CDR ausente o inválido. |
| GRE | Guía de remisión electrónica. |
| OSE | Operador de servicios electrónicos (alternativa a SUNAT directa). |
| Heredado | Cambio presente en el árbol de trabajo antes de que la auditoría lo tocara o no registrado en ningún `FIX`; se revisó solo estáticamente. |
| N0 a N6 | Niveles de evidencia de la sección 7.1. |
| Engram | Memoria persistente del proyecto `facturadorpro4`; los traspasos relevantes son #928 (hasta `FIX-020`), #938 (hasta `FIX-025`) y #944 (`FIX-028` y `OPS-002`). |
