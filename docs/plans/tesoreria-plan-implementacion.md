# Módulo Tesorería: plan de implementación

Versión 1 · 2026-11-23 · Estado: **plan aprobado para implementar, sin código escrito**.

Este documento está pensado para que **otra IA o un desarrollador** implemente el módulo sin romper nada de lo existente. Léelo completo antes de tocar código.

Documentos relacionados:

- Investigación normativa con fuentes: [tesoreria-investigacion-sunat.md](tesoreria-investigacion-sunat.md). Cada dato regulatorio de este plan sale de ahí.
- Pendientes de la tienda virtual: [tienda-virtual-pendientes.md](tienda-virtual-pendientes.md). Es independiente de este módulo.

---

## 0. Resumen ejecutivo

- **SUNAT no emite ni exige un comprobante de "tesorería".** La tesorería es un control interno (caja y bancos). Lo que sí está enlazado con los movimientos de dinero son documentos SUNAT que ya existen o que el sistema ya emite:
  - comprobante de retención (tipo 20) y de percepción (tipo 40);
  - factura al crédito con cuotas (RS 193-2020);
  - detracciones (SPOT, constancia de depósito en el Banco de la Nación);
  - notas de crédito y débito;
  - recibos por honorarios y liquidaciones de compra;
  - además, la obligación de **bancarización** (Ley 28194) y, para contabilidad completa, el **Libro Caja y Bancos** (formatos PLE 1.1 y 1.2).
- **El sistema ya tiene la base**: módulo `Finanzas` (`modules/Finance`) con pagos globales (`global_payments`) que llevan cada cobro o pago a una **caja** (`Cash`) o a una **cuenta bancaria** (`BankAccount`). También tiene cuentas por cobrar y por pagar, ingresos manuales, balance y caja POS con apertura y cierre.
- **Tesorería se construye encima de Finanzas, no al lado.** Reutiliza `global_payments`, `BankAccount`, `Cash`, `PaymentMethodType`, `ExchangeRate` y los modelos de pago existentes. Agrega lo que falta:
  - transferencias entre cuentas;
  - egresos e ingresos manuales con evidencia de bancarización;
  - ITF y comisiones;
  - cronograma de cobranzas y pagos;
  - control de detracciones, retenciones y percepciones;
  - conciliación bancaria;
  - flujo de caja;
  - Libro Caja y Bancos.
- **Regla de oro:** ningún cambio puede alterar los saldos que hoy muestran Finanzas, Caja y Reportes, salvo cuando se agreguen a propósito los movimientos nuevos de Tesorería. Por eso la **Fase 0** escribe tests de caracterización **antes** de tocar nada.

---

## 1. Contexto obligatorio para quien implemente

### 1.1 Stack y entorno

- Laravel 10, PHP 8.3, Vue 2.5, Element UI, hyn/multi-tenant (base central `tenancy` y una base por tenant), módulos estilo nwidart en `modules/*`.
- Docker local: `facturador_fpm` (PHP, workdir `/var/www/html`, código montado), `facturador_mariadb` y la web en `http://localhost:8080`. Para probar un tenant con curl: `-H "Host: net.localhost:8080"`.
- Migraciones de tenant en `database/migrations/tenant/`; migraciones de la base central en `database/migrations/`.
- Tests: `docker exec facturador_fpm sh -c 'vendor/bin/phpunit'`. Hay **una falla preexistente conocida** en `tests/Feature/ExampleTest.php`; cualquier otra falla es tuya. Los tests unitarios usan SQLite en memoria y fakes (ver `tests/Unit/PaymentsTest.php`, `tests/Unit/PaymentFileTest.php`, `tests/Unit/AccessMapTest.php`).
- **Reconstrucción del frontend** (los bundles compilados se versionan):
  1. Copiar `public/mix-manifest.json` a un lugar temporal.
  2. Ejecutar `npm run production:js`.
  3. Verificar que `rg -c '"/css/(app|auth|pos)\.css"' public/mix-manifest.json` devuelva `3`. Si no, restaurar las entradas CSS desde la copia.
- Después de desplegar: `docker exec facturador_fpm php artisan optimize:clear`.

### 1.2 Convenciones del dueño (obligatorias)

- Commits en **español, descripción normal, sin prefijos** (`fix:`, `feat:`…) y **sin atribución de IA** (nada de `Co-Authored-By`). Ejemplo: `Agrega transferencias entre cuentas en Tesorería`.
- Remoto `github` → `EDY28-M/FACTURACION-ELECTRONICA` (repo **público**), rama `main`. Nunca subir secretos.
- Antes de cada commit o push, hacer una **revisión con contexto fresco** del diff.
- Código, comentarios e identificadores en **inglés**; textos visibles para el usuario en **español neutro**.
- Herramientas de terminal: `rg`, `fd`, `bat`, `eza`, `sd` (no `grep`, `find`, `cat`, `ls`, `sed`).

### 1.3 Lo que ya existe (mapa verificado del código)

| Pieza | Dónde | Notas |
|---|---|---|
| Rutas de Finanzas | `modules/Finance/Routes/web.php` (prefijo `finances/`, middleware `auth, redirect.module, locked.tenant`) | Global payments, balance, unpaid, to-pay, income, movements, payment-file |
| Pagos globales | Tabla `global_payments` (migración tenant `2020_03_06_101730_...`), modelo `modules/Finance/Models/GlobalPayment.php` | Morph `destination` (Cash o BankAccount) y morph `payment` (9 tipos de pago). No tiene importe, fecha, moneda ni sede propios: se leen del `payment` |
| Tipos de pago soportados | `GlobalPayment.php:104-117` (mapa), `:149-170` (entrada o salida), `:200-260` (`scopeWhereFilterPaymentType`) | Entradas: cpe, nota de venta, cotización, contrato, ingreso, cash_transaction, servicio técnico. Salidas: compra, gasto |
| Lógica de saldos | `modules/Finance/Traits/FinanceTrait.php` | `getPaymentDestinations` `:25`, `getCash` `:53` (caja abierta del usuario autenticado), `createGlobalPayment` `:93`, `getDestinationRecord` `:112`, `getBalanceByCash` `:205`, `getBalanceByBankAcounts` `:250`, `getSumPayment` `:290`, `calculateTotalCurrencyType` `:336` |
| Borrado en cascada de globales | `GlobalPaymentServiceProvider` (`config/app.php:184`), `:34-94` | Al borrar un pago borra su global y su archivo; recalcula `purchases.total_canceled` |
| Caja POS | `app/Http/Controllers/Tenant/CashController.php` (rutas `routes/web.php:482-500`), modelos `Cash` y `CashDocument` | Una caja abierta por usuario. `close()` (`:188`) calcula `final_balance` **solo** con `cash_documents`, no con `global_payments`. Moneda fija PEN |
| Guard de caja para gastos | `app/Providers/CashServiceProvider.php:34` | Lanza excepción si un pago de gasto en efectivo (`expense_method_type_id=1`) se crea sin caja abierta |
| Cuentas bancarias | `BankAccount` (`bank_id, description, number, currency_type_id, cci, status, initial_balance, show_in_documents`), `Bank`; controladores en `app/Http/Controllers/Tenant/`, rutas `routes/web.php:113-120` y `:334-338` | Bajo el módulo `configuration` en permisos. Saldo = `initial_balance` + entradas − salidas vía globales |
| Tablas de pago | `document_payments`, `sale_note_payments`, `purchase_payments`, `quotation_payments`, `contract_payments`, `technical_service_payments`, `income_payments`, `expense_payments` (usa `expense_method_type_id`), `cash_transactions` (usa `date`) | Campos comunes: `date_of_payment, payment_method_type_id, has_card, card_brand_id, reference, payment`. Sin `establishment_id` |
| Métodos de pago | `PaymentMethodType` (`id` char 2, `description, has_card, charge, number_days`) | Ids: 01 Efectivo, 02/03/06 tarjetas, 04 Transferencia, 05/08/09 crédito, 07/10 contado. **Sin código SUNAT (Tabla 1)** |
| Crédito en ventas | `documents.payment_condition_id` (01 Contado, 02 Crédito), cuotas en `document_fee` (`document_id, date, currency_type_id, amount`, sin estado), saldo en `documents.total_canceled` | — |
| Crédito en compras | `purchases.date_of_due`, `purchases.total_canceled` | No hay tabla de cuotas de compra (búsqueda con grep, sin verificar a fondo) |
| Por cobrar y por pagar | `modules/Dashboard/Helpers/DashboardView.php:20`, `modules/Finance/Helpers/ToPay.php`, `UnpaidController` (filtra métodos 05, 08, 09) | — |
| Retenciones y percepciones | `RetentionController`/`Retention` (tipo 20), `PerceptionController`/`Perception` (tipo 40), rutas `routes/web.php:269-279` y `:350-360`, módulo `advanced` | **No se enlazan con pagos** |
| Detracciones | `documents.detraction` y `purchases.detraction` (JSON), `companies.detraction_account`, `DetractionTypeController`, `ReportDocumentDetractionController` | Sin seguimiento de constancia ni de estado depositado o cobrado |
| Tipo de cambio | `ExchangeRate` (`date, purchase, sale`); consulta o creación por fecha en `ServiceController.php:120-137` | Los documentos guardan `exchange_rate_sale` |
| Control de acceso | `config/permissions.php` (reglas de finanzas `:156-159`, array `menu` `:252-273`), `EnforceModuleAccess` (`redirect.module`/`redirect.level`), `RequirePlanModule` (`plan.module`, APIs), `app/Support/Access/TenantPlan.php`, `ModuleViewComposer` ($vc_modules), sidebar `resources/views/tenant/layouts/partials/sidebar.blade.php` (Finanzas `:592-622`) | **Una ruta sin regla en `config/permissions.php` queda ABIERTA** (solo deja un log de debug) |
| Catálogo de módulos | Base central: `database/migrations/2020_03_10_165827_add_data_module_for_finance.php` (+ `2021_03_19_201634` sort). Tenant: `database/migrations/tenant/2020_03_10_165850_tenant_add_data_module_for_finance.php` (id 12, `finance`) | El módulo se asigna al cliente desde el admin (`System/ClientController` `:200`, `:299-317`, `:523-541`) |
| Vue | Registro con `Vue.component(..., require.ensure(..., 'chunk-name'))` en `resources/js/app.js:174-184`; alias `@viewsModuleFinance` en `webpack.mix.js:74` | Tablas: `modules/Finance/Resources/assets/js/components/DataTable*.vue` |
| Exportaciones | Maatwebsite Excel (`Exports/*.php`), DomPDF con vistas `report_pdf`/`report_excel` | — |

### 1.4 Riesgos conocidos del código actual (no empeorarlos)

1. **Saldo de caja inconsistente:** `CashController::close` suma totales de documentos de `cash_documents`, mientras `getBalanceByCash` suma desde `global_payments`. Pueden discrepar **hoy**.
2. **Patrón de 9 tipos de pago duplicado** en `GlobalPayment::scopeWhereFilterPaymentType`, `PaymentMethodType::scopeWhereFilterPayments`, `getCollectionPaymentTypes`, `getBalanceBy*` y el provider. Agregar un tipo nuevo de pago a `global_payments` obliga a tocar todos esos lugares. **Por eso este plan NO agrega un tipo nuevo a `global_payments`** (ver la decisión D2).
3. `getCash()` depende de `auth()->id()`: no sirve en jobs ni para cajas de otros usuarios.
4. Anular un ingreso (`state_type_id=11`) no limpia sus pagos ni sus globales.
5. En `GlobalPayment::getDataPersonAttribute` (~línea 178), `case 'income'` no tiene `break` y cae en `cash_transaction`. Una fila nueva mal formada en `global_payments` puede romper reportes.
6. **Moneda:** el balance suma cuentas en USD sin convertir (lectura del código, sin prueba).

---

## 2. Decisiones de arquitectura

| ID | Decisión | Motivo |
|---|---|---|
| D1 | Tesorería es un **módulo nuevo** con valor `treasury` (vendible por plan), con su propio prefijo de rutas `treasury/` y su grupo en el sidebar. **Reutiliza** la capa de datos de Finanzas | Se puede activar o desactivar por cliente sin tocar el permiso `finance`. Evita mezclar rutas `finances/*` |
| D2 | Los movimientos propios de Tesorería (transferencias, ingresos y egresos manuales, ITF, comisiones, ajustes, depósitos de detracción, pagos de tributos) viven en una tabla nueva `treasury_movements`. **No** se registran como un décimo tipo en `global_payments` | Agregar un tipo a `global_payments` obliga a tocar el patrón de 9 tipos repetido en 5 o más lugares (riesgo 2). Una tabla aparte se integra en **un solo punto**: el servicio de saldos (D3) |
| D3 | Se crea `TreasuryBalanceService`, **la única fuente de verdad de los saldos**: `saldo = initial_balance + globales (entradas − salidas) + treasury_movements (entradas − salidas)`. `FinanceTrait::getBalanceByCash` y `getBalanceByBankAcounts` pasan a delegar en ese servicio, sin cambiar su firma ni su salida para datos existentes | Finanzas (Balance) y Tesorería muestran el mismo número. Los tests de caracterización de la Fase 0 prueban que, sin movimientos de Tesorería, la salida es **idéntica** a la de hoy |
| D4 | Los cobros y pagos **de documentos** (facturas, notas de venta, compras, gastos) se siguen registrando con los modelos de pago existentes (`DocumentPayment`, `PurchasePayment`, etc.) y sus endpoints. Tesorería los **invoca o reutiliza**, nunca los duplica | Mantiene intacta la lógica de `total_canceled`, caja, kardex y reportes |
| D5 | La evidencia de bancarización de cualquier pago existente va en una tabla lateral polimórfica `payment_evidences` (morph a cualquiera de los 9 modelos de pago o a `treasury_movements`). **No** se agregan columnas a las tablas de pago existentes | Cambio aditivo y de cero riesgo para los flujos actuales |
| D6 | Nada se borra físicamente: los movimientos se **anulan** (`state = voided`, motivo, usuario, fecha). Una transferencia anula sus dos patas en una sola transacción | Auditoría, y coherencia con el patrón del sistema (`state_type_id=11`) |
| D7 | Las tasas regulatorias (detracción por código del Catálogo 54, retención 3%, percepción 2/1/0,5%, umbral de bancarización, ITF 0,005%) se guardan como **datos versionados con fecha de vigencia** (tabla `treasury_tax_rates`), nunca escritos en el código | SUNAT anunció el 11-09-2026 que cambiará tasas de detracción y retención (todavía sin norma). Ver la investigación, §2 |
| D8 | Multimoneda: cada movimiento guarda `currency_type_id`, `amount` (en la moneda de la cuenta), `exchange_rate_purchase`, `exchange_rate_sale` y `exchange_rate_used` (SBS por fecha desde `ExchangeRate`) y `amount_pen`. Los saldos por cuenta se muestran en la moneda de la cuenta; los consolidados, en PEN con el tipo de cambio guardado | Requisito tributario: activos a compra y pasivos a venta al cierre; la diferencia de cambio se calcula en la liquidación. Investigación, §8 |
| D9 | Las sedes: `treasury_movements.establishment_id` es obligatorio (el de la cuenta, o el del usuario si es caja). Los filtros de reportes por sede usan ese campo | Hoy las tablas de pago no tienen sede; Tesorería no hereda ese problema |
| D10 | Concurrencia: las operaciones que crean dos o más filas (transferencias, anulaciones, conciliación) corren en `DB::connection('tenant')->transaction()` con `lockForUpdate` sobre las cuentas involucradas. Los saldos se **calculan**, no se guardan (salvo snapshots de conciliación) | Evita carreras y saldos desfasados |

---

## 3. Modelo de datos (todas las migraciones son de TENANT y aditivas)

Ubicación: `database/migrations/tenant/2026_xx_xx_xxxxxx_*.php`. Cada migración tiene que ser idempotente (`hasTable` / `hasColumn`) y con un `down()` que revierta solo lo suyo.

### 3.1 Cambios aditivos a tablas existentes

```text
bank_accounts
  + account_type      string(20) default 'checking'   -- checking | savings | detraction_bn | card | other
  + pcge_account      string(20) nullable              -- cuenta contable 10x
  + itf_exempt        boolean default false             -- true para la cuenta de detracciones del BN
  + establishment_id  unsigned int nullable             -- sede dueña de la cuenta (null = todas)

payment_method_types
  + sunat_code        char(3) nullable                  -- Tabla 1 / SIRE Tabla 10 (001..013, 101..108, 999)
  + is_cash           boolean default false

companies (tenant)
  + is_retention_agent    boolean default false         -- según el padrón SUNAT (RS 000367-2025, vigente desde 2026-02-01)
  + retention_agent_since date nullable
  + is_perception_agent   boolean default false
  + is_good_taxpayer      boolean default false         -- Buen Contribuyente (afecta retenciones y liberación de detracciones)
```

Seed de `payment_method_types.sunat_code` (mapeo inicial; **debe confirmarlo el dueño**):

| id | descripción actual | sunat_code | is_cash |
|---|---|---|---|
| 01 | Efectivo | 008 (o 009 si supera el umbral) | true |
| 02, 06 | Tarjeta de crédito | 006 | false |
| 03 | Tarjeta de débito | 005 | false |
| 04 | Transferencia | 003 | false |
| 05, 08, 09 | Crédito o a plazo | null (no es medio de pago, es condición) | false |
| 07, 10 | Contado / contraentrega | null (se completa al registrar el cobro real) | false |

### 3.2 Tablas nuevas

```text
treasury_concepts                 -- catálogo de conceptos de ingreso/egreso manual
  id, type (income|expense), description, pcge_account nullable, active, timestamps
  (seed: Préstamo recibido, Aporte de capital, Intereses ganados, Comisión bancaria,
   Pago de tributos, Pago de planilla, Pago de servicios, Otros ingresos, Otros egresos)
  Nota: evaluar si se reutilizan IncomeType/IncomeReason y ExpenseType/ExpenseReason.
  Si se reutilizan, NO crear esta tabla (documentar la elección en la Fase 1).

treasury_movements
  id
  establishment_id                 unsigned, required
  date                             date, required
  kind                             string(30): income | expense | transfer_out | transfer_in |
                                   itf | bank_fee | interest | adjustment |
                                   detraction_deposit | detraction_release | tax_payment
  direction                        tinyint: +1 entrada / -1 salida (derivado de kind, guardado para queries)
  destination_type, destination_id morph: App\Models\Tenant\Cash | App\Models\Tenant\BankAccount
  currency_type_id                 char(3)
  amount                           decimal(14,2) > 0 (en la moneda de la cuenta)
  exchange_rate_purchase, exchange_rate_sale, exchange_rate_used  decimal(10,4) nullable
  amount_pen                       decimal(14,2)
  payment_method_type_id           char(2) nullable  (FK payment_method_types)
  sunat_payment_code               char(3) nullable  (Tabla 1)
  operation_number                 string(60) nullable  (n.º de operación, cheque, voucher)
  counterparty_person_id           unsigned nullable (persons)
  counterparty_document            string(20) nullable / counterparty_name string(255) nullable (si no es persona registrada)
  treasury_concept_id              nullable
  description                      string(500)
  transfer_group                   uuid nullable   (une las dos patas de una transferencia)
  related_movement_id              nullable        (ITF o comisión enlazada al movimiento origen)
  source_type, source_id           morph nullable  (documento SUNAT relacionado: Document, Purchase, Retention, Perception…)
  reconciled_at                    datetime nullable
  bank_statement_line_id           nullable
  state                            string(10): active | voided
  voided_at, voided_by, void_reason
  user_id
  timestamps
  índices: (destination_type, destination_id, date), (establishment_id, date), transfer_group, state

payment_evidences                  -- evidencia de bancarización para CUALQUIER pago (D5)
  id, payable_type, payable_id (morph a DocumentPayment, PurchasePayment, ExpensePayment, …, TreasuryMovement)
  sunat_payment_code char(3), operation_number string(60), bank_id nullable, bank_account_id nullable,
  operation_date date, payer_or_beneficiary_document string(20), payer_or_beneficiary_name string(255),
  third_party_payment boolean default false, third_party_reported_to_sunat boolean default false,
  bancarization_exception string(30) nullable  (art. 6: financial_entity | tax_administration | court | no_bank_district)
  timestamps
  unique(payable_type, payable_id)

treasury_tax_rates                 -- tasas versionadas (D7)
  id, tax (detraction | retention_igv | perception_igv | itf | bancarization_threshold_pen | bancarization_threshold_usd),
  code nullable (código del Catálogo 54 para detracción; clase de bien para percepción),
  rate decimal(8,5) nullable, amount decimal(14,2) nullable, min_operation decimal(14,2) nullable,
  valid_from date, valid_to date nullable, legal_reference string(255), timestamps

detraction_records                 -- control del SPOT
  id, side (receivable | payable)   -- receivable: me detraen (soy proveedor). payable: debo depositar (soy comprador)
  source_type, source_id            -- Document (venta) | Purchase (compra)
  code char(3) (Catálogo 54), rate, base_amount, amount_pen,
  bn_account_number string(30),
  due_date date nullable            -- payable: el que ocurra primero entre la fecha de pago y el 5.º día hábil del mes siguiente a la anotación en el Registro de Compras
  deposited_at date nullable, constancia_number string(30) nullable, deposit_movement_id nullable,
  status (pending | deposited | received | overdue), timestamps

bank_statements / bank_statement_lines   -- Fase 5 (conciliación)
  bank_statements: id, bank_account_id, period_from, period_to, opening_balance, closing_balance,
                   file_path, imported_by, timestamps
  bank_statement_lines: id, bank_statement_id, date, description, reference, amount (+/-),
                   matched_type, matched_id (morph: TreasuryMovement | GlobalPayment), status (unmatched | matched | ignored)

treasury_cash_counts                -- arqueo de caja (Fase 6)
  id, cash_id, counted_at, user_id, system_balance, counted_balance, difference, denominations json, notes

(Fase 7, opcional) treasury_instruments  -- cheques y letras
  id, type (cheque_issued | cheque_received | bill_payable | bill_receivable), number, bank_id,
  bank_account_id nullable, person_id, issue_date, due_date, amount, currency_type_id,
  status (issued | delivered | deposited | collected | bounced | protested | voided), movement_id nullable
```

Modelos en `modules/Treasury/Models/` (o `app/Models/Tenant/Treasury/` si se decide no crear módulo nwidart; ver la Fase 1). Todos con `$connection = 'tenant'` vía el trait `UsesTenantConnection`, igual que el resto de los modelos de tenant.

---

## 4. Permisos, plan y navegación (hacerlo ANTES de publicar cualquier ruta)

1. **Registrar el módulo** `treasury` en las dos bases:
   - Base central: una migración que inserte en `modules` (verificar el **último id libre**; se vieron ids hasta 18, sin confirmar) con `value='treasury'`, `description='Tesorería'`, `sort` y, si corresponde, `module_levels` (sugerido: `treasury_accounts`, `treasury_movements`, `treasury_receivables_payables`, `treasury_reconciliation`, `treasury_reports`).
   - Tenant: una migración equivalente en `database/migrations/tenant/`, con los **mismos ids**. Ver el patrón en `2020_03_10_165850_tenant_add_data_module_for_finance.php`.
2. **`config/permissions.php`:** agregar la regla `treasury` → módulo `treasury` (y niveles por subruta) **en el mismo commit** que las rutas. Agregar también la entrada en el array `menu` para la redirección. Sin esto, las rutas quedan abiertas a cualquier usuario (riesgo crítico).
3. **Rutas:** `modules/Treasury/Routes/web.php` con `Route::domain($hostname->fqdn)` y el middleware `['auth', 'redirect.module', 'locked.tenant']`, prefijo `treasury`. Si hay endpoints API para integraciones, usar `plan.module:treasury`.
4. **Plan:** el admin del sistema asigna el módulo al cliente (`System/ClientController::validatePlanModules`). `TenantPlan` ya resuelve el plan del dueño; verificar que `treasury` aparece en la UI de asignación de módulos (`resources/js/views/system/clients/...`).
5. **Sidebar:** nuevo grupo "Tesorería" en `sidebar.blade.php`, con `in_array('treasury', $vc_modules)`. Incluir `treasury` en la condición del grupo de encabezado `$tnShowAnalyze` (línea 12) o en el grupo que corresponda. Ítems:
   - Resumen (dashboard de saldos);
   - Cuentas;
   - Movimientos;
   - Transferencias;
   - Cobranzas y pagos;
   - Detracciones;
   - Conciliación bancaria;
   - Flujo de caja;
   - Libro Caja y Bancos;
   - Arqueo de caja.
6. **Tests de acceso:** un test de `tests/Unit/AccessMapTest.php` (o uno nuevo) que verifique que **toda** ruta `treasury/*` tiene una regla en `config/permissions.php`, y que un usuario sin el módulo recibe la redirección o un 403.

---

## 5. Fases de implementación

Cada fase es **un entregable desplegable por separado**, con sus tests, y se commitea aparte (en español, sin prefijo). **No pasar a la siguiente fase hasta que la actual tenga sus tests en verde y una revisión con contexto fresco.**

### Fase 0: Red de seguridad (sin funcionalidad nueva)

Objetivo: congelar el comportamiento actual de los saldos para detectar cualquier regresión.

- Tests de caracterización (SQLite + fakes) para:
  - `FinanceTrait::getBalanceByCash`, `getBalanceByBankAcounts`, `getSumPayment` y `calculateTotalCurrencyType` con un set de datos fijo (pagos de documento, nota de venta, compra, gasto, ingreso, cash_transaction; cuentas en PEN y USD);
  - `CashController::close` (cálculo de `final_balance`);
  - `UnpaidController` / `DashboardView::getUnpaidFilterUser` y `ToPay`;
  - `GlobalPaymentServiceProvider` (borrado en cascada).
- Documentar en el test el comportamiento **actual**, incluso si es raro (por ejemplo, la suma en USD sin convertir). Los arreglos de esos casos son tareas aparte y explícitas, nunca un efecto colateral.
- Confirmar los ids libres de módulos en las dos bases.
- **Aceptación:** la suite pasa (solo falla `ExampleTest`) y existen tests de caracterización para cada función listada.

### Fase 1: Fundaciones y "Resumen de saldos"

- Módulo `modules/Treasury` (estructura nwidart como `modules/Finance`: Providers, Routes, Http, Models, Resources/assets/js, Resources/views). Registrar el provider en `config/app.php` y en `modules_statuses.json`, igual que Finance.
- Migraciones de §3.1 y de §3.2: `treasury_movements`, `payment_evidences`, `treasury_tax_rates` (con seed de vigencias actuales: retención 3% con mínimo S/ 700; ITF 0,005%; umbral de bancarización S/ 2.000 y US$ 500 desde el 2022-04-01; percepción 2%, 1% y 0,5%; tabla de detracciones según la investigación §2, **marcada para revisar** por el anuncio de 2026-09), y `treasury_concepts` si aplica.
- `TreasuryBalanceService` (D3) y migración de `FinanceTrait::getBalanceBy*` para delegar en él. **Los tests de la Fase 0 tienen que seguir pasando sin cambios.**
- Permisos, plan y sidebar (§4).
- Pantalla **Resumen**: saldo por cuenta bancaria (en su moneda), cajas abiertas, total consolidado en PEN, saldo de la cuenta de detracciones del BN y alertas (cuentas en negativo, detracciones vencidas: se llena desde la Fase 4).
- Pantalla **Cuentas**: lista de cuentas bancarias y cajas con su saldo. Para editar los datos bancarios se reutiliza `BankAccountController` (vive en Configuración), solo agregando los campos nuevos (`account_type`, `pcge_account`, `itf_exempt`, `establishment_id`).
- **Aceptación:** con el módulo apagado, el usuario no ve ni accede a nada (test). Con el módulo encendido, los saldos del Resumen son **idénticos** a los de Finanzas → Balance (test que compara las dos fuentes).

### Fase 2: Movimientos manuales, transferencias, ITF y comisiones

- CRUD de **movimientos manuales** (`kind` income/expense/interest/bank_fee/tax_payment/adjustment), con validación por `FormRequest`. Monto > 0, fecha no futura (configurable), cuenta activa, moneda igual a la de la cuenta y concepto obligatorio. Si el monto supera el umbral de bancarización vigente y el medio es efectivo, mostrar una advertencia y pedir el motivo de la excepción (art. 6) o cambiar el medio.
- **Transferencias**:
  - Crean dos patas (`transfer_out` y `transfer_in`) con el mismo `transfer_group`, en una transacción con `lockForUpdate` sobre las dos cuentas.
  - Si las monedas son distintas, se guarda el tipo de cambio usado y el monto de cada pata en su moneda.
  - Casos: banco↔banco, caja→banco (depósito de efectivo), banco→caja (retiro para caja chica).
  - Si una pata es una caja POS, la caja debe estar **abierta**.
- **ITF y comisiones:** al registrar un movimiento bancario, sugerir una línea `itf` = 0,005% (tasa de `treasury_tax_rates`) enlazada por `related_movement_id`, salvo que la cuenta sea `itf_exempt` o se trate de una transferencia entre cuentas del mismo titular. El usuario puede aceptar, editar o quitar la sugerencia.
- **Anulación** (D6): anula una pata o la transferencia completa. Exige motivo y deja auditoría.
- **Integración con la caja POS:** `CashController::close` debe sumar los `treasury_movements` activos de esa caja entre la apertura y el cierre. Hacerlo **detrás de una función nueva** y cubrirlo con un test nuevo, además de que los de la Fase 0 sigan pasando. Mostrar esos movimientos en el reporte de caja (`ReportCashController` / ticket).
- Evidencia de bancarización: formulario reutilizable `payment-evidence` (componente Vue) que se puede abrir desde cualquier pago existente (factura, compra, gasto) y desde los movimientos. Guarda en `payment_evidences`.
- **Aceptación:**
  - una transferencia banco→banco no cambia el total consolidado (salvo la diferencia de cambio);
  - anular una transferencia deja los saldos como antes;
  - el cierre de caja incluye los depósitos o retiros hechos desde Tesorería;
  - Finanzas → Balance y el Resumen siguen coincidiendo.

### Fase 3: Cobranzas y pagos (cuentas por cobrar y por pagar con cronograma)

- **Cronograma por cobrar:** desde `document_fee` (cuotas de facturas al crédito) y el `monto neto pendiente` (total − retención − detracción, según RS 193-2020). El estado de cada cuota se **calcula** asignando los pagos existentes (`document_payments`) por FIFO de vencimiento. No se agregan columnas de estado a `document_fee` en esta fase.
- **Cronograma por pagar:** compras con `date_of_due` y saldo = total − `total_canceled`. Si hace falta, cuotas manuales de compra en una tabla nueva `purchase_fees` (aditiva).
- **Antigüedad de saldos** (por vencer, 1-30, 31-60, 61-90, más de 90 días) por cliente y proveedor, filtrable por sede y moneda.
- **Registrar el cobro o pago desde Tesorería** reutilizando los endpoints existentes de `DocumentPayment` / `PurchasePayment` / `ExpensePayment` (D4). El destino (caja o banco) se elige con `getPaymentDestinations`. Se adjunta evidencia de bancarización (`payment_evidences`).
- **Alertas:** cuotas vencidas, cuotas que vencen en 7 días, obligaciones que superan el umbral de bancarización sin evidencia.
- **Aceptación:**
  - el saldo por cobrar de cada factura en Tesorería coincide con `UnpaidController`;
  - registrar un cobro desde Tesorería actualiza `total_canceled` igual que desde la pantalla de comprobantes;
  - no se duplican globales.

### Fase 4: Detracciones, retenciones y percepciones

- **Detracciones (`detraction_records`):**
  - **Como proveedor (receivable):** al emitir una venta con `documents.detraction`, se crea el registro con el monto que el cliente debe depositar en la cuenta BN de la empresa. La cuenta por cobrar de la factura pasa a ser total − detracción. Cuando llega el depósito, se marca "received" y se registra el `detraction_deposit` en la cuenta `detraction_bn`.
  - **Como comprador (payable):** al registrar una compra con `purchases.detraction`, se crea el registro con su fecha límite (la que ocurra primero entre la fecha de pago y el 5.º día hábil del mes siguiente a la anotación en el Registro de Compras). Al pagar la compra, se exige o sugiere registrar el depósito (constancia, número, fecha). El pago al proveedor es total − detracción.
  - **Cuenta de detracciones BN:** sus fondos solo sirven para pagar tributos SUNAT (`kind=tax_payment` desde esa cuenta). La liberación de fondos (Formulario 1697) se registra con `detraction_release`. Mostrar las ventanas de liberación (investigación, §2) solo como información.
  - Tasas y códigos desde `treasury_tax_rates` (Catálogo 54), **nunca escritos en el código**.
- **Retenciones (CRE tipo 20):**
  - **Recibidas** (un cliente agente de retención me retiene el 3%): permitir registrar el CRE recibido contra la factura, lo que reduce el saldo por cobrar.
  - **Emitidas** (si `companies.is_retention_agent`): al pagar a un proveedor una operación mayor a S/ 700 sin exclusión, calcular el 3% sobre el importe pagado y **ofrecer emitir el CRE con el flujo existente** (`RetentionController` / API). Enlazar el CRE emitido al pago (`source_type/source_id`) y llevar el monto retenido como deuda con SUNAT (formulario 626).
- **Percepciones (CPE tipo 40):** solo si `companies.is_perception_agent`. En cada cobro posterior a la factura, calcular la percepción (tasas versionadas) y ofrecer emitir el CPE con el flujo existente. El cobro registra precio de venta + percepción por separado.
- **Aceptación:**
  - una factura con detracción del 12% muestra por cobrar = total − detracción, y el depósito aparece en la cuenta BN;
  - un pago a proveedor con retención genera (opcionalmente) el CRE enlazado y el pago neto correcto;
  - sin las banderas de agente no aparece ninguna opción de emisión.

### Fase 5: Conciliación bancaria

- **Importación de extracto:** CSV o Excel con mapeo configurable de columnas por banco (fecha, descripción, referencia, cargo, abono). Guardar el archivo con `PaymentFileHelper` o similar.
- **Emparejamiento:**
  - automático por monto + fecha (±N días) + referencia;
  - manual uno a uno o uno a varios contra `treasury_movements` y `global_payments` de esa cuenta.
- Lo que está en el extracto y no en el sistema (comisiones, ITF, intereses) se convierte en movimiento con un clic.
- El cierre de conciliación guarda el snapshot (saldo según banco vs saldo según sistema, partidas en tránsito) y marca `reconciled_at`.
- **Aceptación:** con un extracto de prueba, todas las líneas quedan emparejadas o explicadas, y la diferencia final es 0.

### Fase 6: Reportes

- **Flujo de caja:**
  - **real**: movimientos por concepto y semana o mes;
  - **proyectado**: cuotas por cobrar y por pagar, detracciones por depositar y cheques diferidos.
- **Libro Caja y Bancos** (investigación, §6):
  - Formato 1.1 (efectivo) y 1.2 (cuenta corriente) en Excel y PDF con la "información mínima" oficial: correlativo, fecha, medio de pago (Tabla 1), descripción, girador o beneficiario, n.º de transacción o documento, cuenta contable, debe y haber, y totales.
  - La exportación **TXT PLE** (códigos 010100 / 010200) está implementada (`PleCashBookExporter`) con el layout oficial del Anexo 2 de la RS 286-2009; ver «PLE layout confirmado» en `tesoreria-investigacion-sunat.md` para las decisiones y los puntos sin confirmar (Tabla 1 y Tabla 3).
- **Arqueo de caja** (`treasury_cash_counts`): conteo por denominaciones, saldo según sistema (del `TreasuryBalanceService`) y diferencia, con PDF.
- Exportaciones con los patrones existentes (Maatwebsite y DomPDF).

### Fase 7: Cheques y letras (implementada)

- **Tablas:** `treasury_instruments` (cheques y letras) y `treasury_instrument_events` (historial de estados); migración `2026_11_23_000016`. Los plazos legales se cargan en `treasury_tax_rates` (`cheque_presentation_days` = 30, `cheque_deferred_max_days` = 30, `bill_protest_days` = 15), con respaldo en `config('treasury.instruments.*_fallback')`. Los tres vienen de una fuente secundaria (Ley 27287 según la investigación) y están marcados **[REVISAR]**.
- **Máquina de estados** (`InstrumentService::TRANSITIONS`; cualquier otra transición se rechaza con un mensaje en español):

| Tipo | Estado inicial | Transiciones permitidas |
|---|---|---|
| Cheque recibido | recibido (`delivered`) | recibido → depositado, anulado; depositado → cobrado, rechazado; cobrado → rechazado |
| Cheque emitido | emitido (`issued`) | emitido → entregado, anulado; entregado → cobrado, rechazado, anulado; cobrado → rechazado |
| Letra por cobrar | girada (`issued`) | girada → aceptada (`delivered`), anulada; aceptada → en banco (`deposited`), cobrada, protestada, anulada; en banco → cobrada, protestada; protestada → cobrada |
| Letra por pagar | girada (`issued`) | girada → aceptada, anulada; aceptada → pagada (`collected`), protestada, anulada; protestada → pagada |

- **Efectos de dinero** (solo por `MovementService::createInstrumentMovement`; nunca se toca `global_payments`):
  - cobrado/cobrada de cheque recibido o letra por cobrar → ingreso en la cuenta bancaria;
  - cobrado de cheque emitido o pagada de letra por pagar → egreso de la cuenta bancaria;
  - rechazado después de cobrado → se anula el movimiento (`MovementService::void`, que respeta la conciliación cerrada); depositar y protestar no mueven dinero;
  - el movimiento pertenece al documento (`source_type = TreasuryInstrument`) y solo puede anularse desde Cheques y letras.
- **Plazos:**
  - presentación del cheque = emisión + 30 días calendario (cheque de pago diferido: fecha de pago + 30; la fecha de pago no puede superar 30 días desde la emisión);
  - protesto de la letra = vencimiento + 15 días **hábiles** (`BusinessDayCalculator::addBusinessDays` con la tabla de feriados; `config('treasury.instruments.protest_day_type')` permite pasarlos a calendario). Si el año no tiene feriados cargados el plazo se marca como estimado.
- **Flujo de caja proyectado:** los documentos pendientes se proyectan en su fecha (el cheque al día se espera hoy, no como vencido). Un documento vinculado a un comprobante o compra descuenta su importe de las cuotas pendientes de ese comprobante (en orden, sin pasar de lo pendiente), tanto mientras está pendiente como una vez cobrado; si rebota, se anula, o es una letra por cobrar protestada, deja de cubrir y la cuota vuelve.
- **Alertas del Resumen:** cheques por vencer el plazo de presentación (7 días) o vencido, letras que vencen en 7 días o vencidas, plazo de protesto por vencer (3 días) o vencido.

---

## 6. Backend: estructura sugerida

```text
modules/Treasury/
  Providers/TreasuryServiceProvider.php, RouteServiceProvider.php
  Routes/web.php
  Http/Controllers/{Dashboard,Account,Movement,Transfer,ReceivablePayable,Detraction,Reconciliation,Report,CashCount}Controller.php
  Http/Requests/{Movement,Transfer,PaymentEvidence,Reconciliation...}Request.php
  Http/Resources/*Collection.php / *Resource.php
  Models/{TreasuryMovement,PaymentEvidence,TreasuryTaxRate,DetractionRecord,BankStatement,BankStatementLine,TreasuryCashCount,TreasuryConcept}.php
  Services/
    TreasuryBalanceService.php       // única fuente de saldos (D3)
    TransferService.php              // transferencias atómicas
    ItfCalculator.php
    BancarizationChecker.php         // umbral vigente, excepciones, evidencia
    TaxRateRepository.php            // tasas vigentes por fecha (D7)
    DetractionService.php
    RetentionPerceptionService.php   // delega la emisión al flujo existente de CRE/CPE
    ReceivablePayableService.php     // cronograma y antigüedad (lee document_fee, purchases, pagos)
    ReconciliationService.php
    CashBookReport.php               // Libro Caja y Bancos 1.1 / 1.2
  Resources/assets/js/views/...      // registrar en resources/js/app.js con chunks 'tenant-treasury-*'
  Resources/views/...                // index.blade.php por pantalla, como Finance
tests/Unit/Treasury/*Test.php
```

Reglas:

- Toda escritura pasa por un Service, dentro de una transacción `tenant`.
- Los controladores solo validan (FormRequest), autorizan y delegan.
- Ningún Service llama a `auth()` internamente para decidir la caja: recibe `user_id` o `cash_id` explícito (evita el riesgo 3).
- Los montos se validan como `numeric|min:0.01|max:9999999999.99` y se redondean a 2 decimales en un solo lugar.

---

## 7. Frontend

- Mismo sistema de diseño del panel tenant (KeraAI): `public/css/tenant-theme.css`. Pantallas de referencia: `resources/js/views/tenant/companies/form.vue` (pestañas y tarjetas) y las de `modules/Finance/Resources/assets/js`.
- Cada pantalla tiene:
  - tabla con filtros (fecha, cuenta, sede, moneda, estado);
  - estados de carga, vacío y error;
  - mensajes de validación 422 junto al campo;
  - confirmación en las acciones destructivas (anular);
  - exportación a Excel o PDF cuando aplique.
- Componente reutilizable `PaymentEvidenceDialog.vue`, que se usa desde los pagos existentes y desde Tesorería.
- Textos en español neutro.
- Accesibilidad:
  - las etiquetas se asocian a sus campos;
  - todo se puede usar con teclado;
  - los colores de saldo se acompañan de signo o texto, no solo de color.

---

## 8. Plan de pruebas

| Tipo | Qué cubre |
|---|---|
| Caracterización (Fase 0) | Saldos actuales de Finanzas y Caja, por cobrar y por pagar, borrado en cascada |
| Unitarios de Services | Balance (con y sin movimientos de Tesorería), transferencias (misma y distinta moneda, anulación), ITF (exentas, mismo titular), umbral de bancarización (PEN, USD, 30% para contraparte de bajo cumplimiento), tasas versionadas por fecha, cálculo de la fecha límite de la detracción (días hábiles), retención (umbral S/ 700, pagos parciales), FIFO de cuotas |
| Acceso | Cada ruta `treasury/*` tiene regla; usuario sin módulo → bloqueado; plan sin módulo → bloqueado |
| Integración | Registrar un cobro desde Tesorería ≡ desde comprobantes (`total_canceled`, globales); el cierre de caja incluye los movimientos de Tesorería |
| Concurrencia | Dos transferencias simultáneas desde la misma cuenta no dejan datos inconsistentes (`lockForUpdate`) |
| Regresión | Suite completa: solo puede fallar `ExampleTest` |
| Manual (navegador) | Recorrer cada pantalla con un usuario de prueba **local** (nunca credenciales reales del dueño), con datos de prueba con prefijo `QA-` que se borran al final |

---

## 9. Criterios de "listo para producción"

1. Todas las fases entregadas con sus tests en verde y una revisión con contexto fresco aprobada.
2. Ninguna ruta `treasury/*` sin regla de permisos (test automático).
3. Los saldos de Tesorería y de Finanzas → Balance son iguales (test automático).
4. Las migraciones son aditivas, idempotentes, con `down()`, y probadas en una copia de la base de un tenant real.
5. Ningún monto, tasa ni umbral regulatorio está escrito en el código (todo en `treasury_tax_rates`).
6. Frontend reconstruido con la verificación del manifiesto (3 entradas CSS).
7. Documentación de usuario breve en `docs/` (cómo configurar las cuentas, la cuenta de detracciones y las banderas de agente).

---

## 10. Pendientes a verificar antes de codificar (de la investigación)

1. Valores oficiales del **Catálogo 59** y de la **Tabla 3 PLE (entidades financieras)**. Layout campo por campo de PLE 1.1 / 1.2 (anexo 2 de la RS 286-2009).
2. **Tabla de detracciones** vigente después de los cambios anunciados por SUNAT el 2026-09-11 (todavía sin norma).
3. Tasas de percepción a la importación después del DL 1714.
4. Si sigue vigente el umbral de 3.000 UIT para que los PRICO lleven libros electrónicos.
5. La condición de agente de retención o percepción de cada cliente se lee del **padrón SUNAT** (RS 000367-2025, vigente desde 2026-02-01): nunca se infiere.
6. Confirmar con el dueño:
   - el mapeo de `payment_method_types.sunat_code` (§3.1);
   - si se reutilizan `IncomeType`/`ExpenseType` en lugar de `treasury_concepts`;
   - el orden de las fases (el MVP recomendado es Fase 0 → 1 → 2 → 3).

---

## 11. Orden recomendado y estimación relativa

| Fase | Valor para el cliente | Riesgo | Esfuerzo relativo |
|---|---|---|---|
| 0 Red de seguridad | Protege lo existente | Bajo | S |
| 1 Fundaciones + Resumen | Saldos consolidados | Medio (toca `FinanceTrait`) | M |
| 2 Movimientos y transferencias | Alto (uso diario) | Medio (toca el cierre de caja) | M |
| 3 Cobranzas y pagos | Alto | Bajo-medio | M |
| 4 Detracciones, retenciones, percepciones | Alto (cumplimiento) | Medio | L |
| 5 Conciliación | Medio-alto | Bajo | M |
| 6 Reportes (flujo, Libro Caja y Bancos, arqueo) | Medio-alto | Bajo | M |
| 7 Cheques y letras | Bajo-medio | Bajo | S-M |

**MVP sugerido:** Fases 0, 1, 2 y 3.
