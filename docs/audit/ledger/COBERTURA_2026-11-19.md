# Cobertura tras la revisión de Engram y los seis libros — 2026-11-19

## 1. Engram (memoria de Claude)

- Proyecto `facturadorpro4`: **224 observaciones** y **30 sesiones**.
- Últimas observaciones: **#945–#951** (2026-11-18 21:38–21:40), que son el
  README de auditoría en cinco partes y el addendum de MariaDB; **#952**
  (21:59) es de DeepSeek Harness y no de FacturadorPro.
- No hay ninguna observación de Engram que cierre los seis libros. Los libros
  A–F viven en `docs/audit/ledger/` y fueron escritos después (00:26–00:32).
- Conclusión: **Engram no contiene un cierre de los hallazgos**. La última
  palabra de Engram es «auditoría no terminada, no lista para producción»
  (#945–#950).

## 2. Los seis libros

| Libro | Alcance | Corregido | Parcial | Abierto | Otros |
|---|---|---:|---:|---:|---:|
| A | seguridad + system/auth | 9 | 18 | 20 | — |
| B | core + tenant controllers 1 | 21 | 14 | 18 | 1 no aplica, 1 no verificable |
| C | tenant controllers 2 + ventas | 5 | 17 | 31 | — |
| D | inventory/ops/SQL/dead | 18 | 20 | 27 | — |
| E | diff heredado + frontend + README | 11 (+3) | 2 (+6) | 36 (+28) | 7 no aplica, 3 no verificable |
| F | cambios del propietario | — | — | 17 | 0 críticos, 4 altos |

Los libros A–D suman **220** hallazgos: **53 corregidos, 69 parciales y 96
abiertos**. E y F se solapan con esos y no deben sumarse.

## 3. Lo que sí quedó corregido después de los libros

Estas correcciones se aplicaron en el árbol actual y se volvieron a probar:

| Hallazgo | Estado actual | Evidencia |
|---|---|---|
| OWN-001 (Clave SOL/certificado en `companies/record`) | **Corregido el secreto** | `CompanyResource` ya no devuelve `soap_password`, `soap_username` ni `certificate`; la ruta sigue abierta, pero sin esos datos. |
| REG-001 (comandos sin auth) | **Corregido** | `findDocumentForMutation` soporta `auth()` nulo; `SendAllSunatCommand`/`SendAllServerCommand` ya no fallan por usuario. |
| Modo noche 419 | **Corregido** | `configurations/change-mode` fuera de `PATTERNS`; el enlace plano vuelve a funcionar. |
| OWN-006 (`HEAD` evade CSRF) | **Corregido** | El middleware acepta `GET` y `HEAD`; prueba `test_head_cannot_bypass_csrf`. |
| OWN-005 (GET sin CSRF) | **Parcial** | Se añadieron `items/enable|disable`, `persons/enabled`, `addSeeder`, `client_payments/cancel_payment`, `contracts/changed|generate-quotation`, `documents/regularize-lots|consult_cdr`, `padron/*`, `purchase-orders/*`; `HEAD` ya se valida. Sigue siendo una lista de cadenas y no cubre todas las rutas. |
| OWN-004 (Padron destructivo) | **Parcial** | `padron` exige módulo `configuration` y CSRF; las rutas siguen siendo GET. |
| OWN-008 (rutas sin regla) | **Parcial** | Reglas para `quotation_payments`, `technical-service-payments`, `payment-method-types`, `web-platforms`, `api/inventory`; no hay prueba que recorra todas las rutas. |
| OWN-009 (`companies/uploads`) | **Parcial** | Exige módulo `configuration`; `finances/payment-file` sigue abierto por nombre. |
| OWN-015 (caja cerrada editable) | **Corregido** | `CashController@store` rechaza cajas cerradas. |
| Excepciones sin `use Exception;` | **Corregido** | `ExpenseController`, `FixedAssetPurchaseController`, imports, `PerceptionValidation`, `RetentionValidation`; Item/ItemSet ya lo tenían. |
| SEC-002/003 (`temp_path` y extensión) | **Corregido en los puntos listados** | `SafeTempFile`/`TempImageHelper` en Client, DocumentInput, Document, SaleOpportunity, PurchaseOrder; `PaymentFileController` con lista blanca. |
| SYS-004 (`tenancy:run`) | **Corregido** | Schedule `tenant:run`; ejecutor por tenant con whitelist de comandos. |
| SYS-006 (`goto` sin tope) | **Corregido** | Máximo 3 intentos con pausa y log. |
| SYS-007 (mes 0/temp_path/alta parcial) | **Parcial** | Mes 0 corregido, temp_path validado y limpieza de website/hostname/client; el alta sigue sin transacción distribuida. |
| CORE-002 (baja rechazada anulaba) | **Corregido** | `statusSummary` solo anula si el código es 0. |
| CORE-004 (tipo de cambio 0) | **Corregido** | `CartPricing` rechaza `<= 0`. |
| CORE-011/018/019/012 | **Corregido** | Imágenes de detracción validadas, `use Exception` en imports, redondeo de letras/fechas X509, contraseña WS escapada. |
| MSL-002/003 | **Parcial/Corregido** | Adjuntos validados; reglas de pagos y lista blanca de `PaymentFileController`. |
| MSL-017 | **Corregido** | `PurchaseOrder`, `Contract` y `OrderNote` preservan `external_id`/estado al editar; ítems de pedido quedan ligados a su nota. |
| CORE-012/018/019 | **Corregido** | Contraseña WS escapada, imports de excepción y redondeo de letras/fechas X509. |
| OWN-002/003 | **Corregido** | `AccessMap` con `methods`/`module_levels`; `documents/records` exige `list_document`; POS puede emitir. |
| SYS-018 (comandos) | **Corregido** | `orWhere` agrupado; `SendAllSunat` verifica `success` y no serializa la excepción. |
| FinanceTrait (crédito/global payments) | **Corregido** | Deduplicación por documento y limpieza de global payments. |
| Kardex convertidos / `regularize_stock` | **Corregido** | No se duplica kardex en convertidos; regularización con `confirm=1`. |

## 4. Lo que sigue abierto y no está cubierto

1. **SYS-002 — `route:cache`**: verificado con cache, `/documents` del tenant
   responde **404**; sin cache, 200. Las rutas de tenant se registran
   condicionalmente por hostname y no entran al cache. Sin cambios.
2. **SYS-005/OWN-007 — API**: las rutas `auth:api` no pasan por
   `EnforceModuleAccess`; solo se añadió `api/inventory`. Un token puede emitir
   o anular sin módulo/nivel.
3. **TC2-003 — rutas públicas de impresión**: `/print/*`, `/purchases/print`,
   `/sale-notes/print` siguen abiertas por `external_id`; no se pasaron a URLs
   firmadas.
4. **Dinero/stock**: CORE-005 (actualización de documento), MSL-007/009,
   MIN-002/004 y otros siguen parciales/abiertos; no se probaron escrituras
   reales de anulación, conversión ni cierre.
5. **SYS-007/SYS-008**: el alta de tenant no es atómica entre conexiones; el
   ciclo de facturación ya no falla en enero, pero quedan N+1 y guardas.
6. **OWN-010/011/012/013/014**: `ajax` sigue siendo código muerto, no se
   memoizó `isOwner`, la lista CSRF sigue siendo de cadenas y la CI no corre
   Node/tests de dashboard.
7. **PUBLIC/EXTERNAL**: no se ejecutaron SUNAT, OSE, Culqi, ni pruebas de
   concurrencia en MariaDB.

## 5. Pruebas de esta revisión

- **345 tests, 2132 aserciones, 0 fallos**.
- **Lint PHP: 0 errores**.
- HTTP: `/dashboard`, `/documents`, `/purchases`, `/cash`, `/finances/to-pay`,
  `/finances/unpaid`, `/ecommerce` → **200**; `/ecommerce/login` → 302.
- `route:list` → OK; `tenant:run` → OK.
- `route:cache` → **confirmado roto para tenant** (404; cache limpiado después).
- Sin escrituras a la base de datos real, sin commit/push/despliegue.

## Veredicto

**No, no está cubierto todo.** Los libros siguen siendo la fuente de verdad y
su conclusión sigue vigente: 96 abiertos y 69 parciales en A–D, más E/F. Esta
tanda cerró varias regresiones del propietario y varios hallazgos de seguridad,
pero no cambia el estado global a «completamente corregido».
