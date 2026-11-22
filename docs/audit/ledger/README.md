# Re-verificación de la auditoría contra el código actual — índice consolidado

Fecha: 2026-11-19. Rama `chore/system-audit`, árbol de trabajo sin commitear (HEAD `2064950`).

## Por qué existe este libro

Entre el README de la auditoría (2026-11-18) y hoy el árbol cambió: pasó de 98 a 145 archivos modificados, de 5 a 8 eliminados y de 43 a 49 sin seguimiento, porque el propietario del repositorio hizo cambios en paralelo (control de acceso por módulo y nivel, CSRF para GET con efectos, CI, borrado de providers, entre otros) además de las correcciones `FIX-001…FIX-028`. Por eso se **re-verificó cada hallazgo contra el código actual** y no contra los documentos de la auditoría: seis verificadores independientes de solo lectura, cada uno con su parte, más una revisión aparte del código nuevo del propietario.

Método (reproducir → aislar → diagnosticar): para cada hallazgo se abre el código tal como está hoy y se clasifica como **Corregido** (con archivo:línea y la prueba que lo fija), **Parcial**, **Abierto**, **No aplica** (falso positivo o aceptado) o **No verificable** (requiere base de datos, producción o un sistema externo). Todo es lectura estática más pruebas existentes ejecutadas en el contenedor PHP 8.3.36; no se escribió en bases de negocio ni se usó SUNAT/OSE/Culqi reales.

## Libros

| Libro | Alcance | Ítems | Corregido | Parcial | Abierto | Otros |
|---|---|---|---|---|---|---|
| [A](A-security-system-auth.md) | `security.md` + `system-auth-infra.md` | 47 | 9 | 18 | 20 | — |
| [B](B-core-tenant-controllers-1.md) | `core.md` + `tenant-controllers-1.md` | 55 | 21 | 14 | 18 | 1 descartado, 1 no verificable |
| [C](C-tenant-controllers-2-sales.md) | `tenant-controllers-2.md` + `modules-sales.md` | 53 | 5 | 17 | 31 | — |
| [D](D-inventory-ops-sql-dead.md) | inventario, operaciones, SQL, código muerto | 65 | 18 | 20 | 27 | — |
| [E](E-inherited-frontend-pending.md) | diff heredado (40) + frontend (5) + hermanos (11) + pendientes del README (46) | 102 + 46 | 11 (+3 del README) | 2 (+6) | 36 (+28) | 7 no aplica, 3 no verificable (+8) |
| [F](F-owner-changes-review.md) | código nuevo del propietario (acceso, CSRF, CI, providers) | 17 | — | — | 17 | 0 críticos, 4 altos, 5 medios, 6 bajos, 2 informativos |

Las filas «pendientes del README» de E se solapan con los hallazgos de los otros libros y no deben sumarse. Los libros A a D suman 220 hallazgos: 53 corregidos, 69 parciales, 96 abiertos y 2 más entre descartados y no verificables.

Conclusión honesta: **la auditoría no está cerrada ni el sistema «completamente corregido»**. Los 5 hallazgos de frontend sí están corregidos y los hallazgos críticos originales (SEC-001, SYS-001) también, pero quedan decenas de hallazgos altos y medios abiertos, y el código nuevo del propietario introdujo regresiones.

## Regresiones del trabajo paralelo (verificadas por lectura de código)

| ID | Severidad | Resumen | Evidencia |
|---|---|---|---|
| OWN-001 | Alta | `GET /companies/record` no exige módulo y `CompanyResource` devuelve `soap_password` (Clave SOL) y el certificado a cualquier usuario autenticado del tenant. Verificado. | `config/permissions.php:190`, `app/Http/Resources/Tenant/CompanyResource.php:27-30` |
| REG-001 | Alta | `findDocumentForMutation` (nuevo) lee `auth()->user()->type`; los comandos de consola `SendAllSunatCommand`, `SendAllServerCommand` y `QueryAllServerCommand` llaman `send`, `sendServer` y `checkServer` vía `Facades\…\DocumentController` sin usuario autenticado, de modo que los envíos y consultas automáticos fallan. Verificado. | `app/Http/Controllers/Tenant/DocumentController.php:1046-1064` |
| OWN-002/003 | Alta | Un usuario con solo el módulo `pos` o con `new_document` recibe 403 en `POST documents`, `sale-notes`, `document_payments`, `sale_note_payments`, `persons`. | `config/permissions.php`, `app/Support/Access/AccessMap.php:94-114` |
| OWN-004 | Alta | `padron/charges_data` y `padron/demo` truncan `padrones` por GET, sin módulo ni CSRF, y descargan por HTTP sin verificar TLS. | `modules/Padron/...PadronController.php:80,105,166` |
| Modo noche | Media | El middleware CSRF protege `configurations/change-mode`, pero el botón es un `<a href>` que no envía `X-CSRF-TOKEN`: responde 419. Verificado. | `RequireCsrfForStateChangingGet.php:35`, `resources/views/tenant/layouts/partials/header.blade.php:152` |
| OWN-005/006 | Media | La lista de GET protegidos omite `items/enable|disable`, `persons/enabled`, `configurations/addSeeder`, `preprinted/addSeeder`, `inventory/regularize_stock`, `client_payments/cancel_payment`, `padron/*`; además `HEAD` esquiva el middleware. | `RequireCsrfForStateChangingGet.php:17-45` |
| OWN-007/008 | Media | Las 62 rutas `auth:api` no pasan por `EnforceModuleAccess`; `quotation_payments`, `technical-service-payments`, `payment-method-types`, `web-platforms` y `api/inventory/transaction` no tienen regla y sus mutaciones se deniegan a todo no propietario. | `AccessMap.php`, `config/permissions.php` |
| OWN-009 | Media | `companies/uploads` está abierta: cualquier usuario cambia logo, firma y favicon. | `config/permissions.php:191` |
| Excepciones | Media | Varios controladores con `catch (Exception)` y sin `use Exception;` en un namespace: nunca capturan (reproducido): `ItemController`, `ItemSetController`, `ExpenseController`, `FixedAssetPurchaseController`, `DocumentsImport*`. | cada archivo |

## Backlog de corrección (orden y lotes)

Principios: cambio mínimo, una sola fuente de verdad (sin duplicar reglas), sin números mágicos, con prueba que lo fije y revisión independiente fresca por lote; un lote no toca los archivos de otro lote en paralelo.

1. **Lote 1 — acceso y credenciales:** OWN-001, OWN-009, OWN-002/003, OWN-007/008 y las demás reglas sin mapear.
2. **Lote 2 — regresión de consola y excepciones:** REG-001 y el barrido de `use Exception;` con una prueba que impida reincidir.
3. **Lote 3 — CSRF en GET:** Modo noche, acciones omitidas, `HEAD`.
4. **Lote 4 — archivos y rutas:** validación de `temp_path` y extensión con un único ayudante compartido (SEC-002/003, MSL-002, CORE-011), lista blanca de `type` en `PaymentFileController` (MSL-003), rutas públicas de impresión (TC2-003).
5. **Lote 5 — usuarios y asignación masiva:** TC2-005, TC2-002, MOP-001, TC2-024, SYS-007.
6. **Lote 6 — dinero y stock:** MSL-009, MSL-007, CORE-004, CORE-002, MIN-002, MIN-004, TC2-006/007, CORE-005. Requiere pruebas finas y decisiones de negocio.
7. **Lote 7 — operación:** `goto` sin tope (SYS-006), `tenancy:run` (conflicto entre libros A y E: verificar si el comando existe), mes `0` (SYS-008).
8. **Lote 8 — Padron:** GET destructivos → POST con CSRF, verificación TLS, reemplazo transaccional; verificación solo en base descartable.
9. **Pendientes de infraestructura y CI:** MariaDB `:3307` publicada en `0.0.0.0` aunque el compose use `127.0.0.1`; el CI no corre los tests de Node ni `test:dashboard` ni lintea `database`; faltan `permissions: contents: read` y filtrar `push`.

## Límites

Es una revisión estática más pruebas existentes: no hubo escrituras reales, concurrencia en MariaDB, integraciones SUNAT/OSE/Culqi, producción ni consumidores externos (app móvil, integradores). Los libros A, B, C, D y E indican en cada fila qué no se pudo verificar. Los conteos de este índice proceden de los resúmenes de los verificadores y no se recalcularon fila por fila.

---

## Addendum 2026-11-19 (post-libros)

Se revisó Engram (`facturadorpro4`, 224 observaciones; últimas #945–#952) y se
re-verificaron las regresiones del propietario y varios hallazgos altos. El
detalle de lo corregido y de lo que sigue abierto está en
[COBERTURA_2026-11-19.md](COBERTURA_2026-11-19.md).

Conclusión: **no está todo cubierto**. Persisten, entre otros, SYS-002
(`route:cache`), SYS-005/OWN-007 (API sin módulo/nivel), TC2-003 (impresión pública) y los hallazgos de dinero y stock. OWN-002/003
quedaron corregidos con reglas por método y niveles por módulo.
