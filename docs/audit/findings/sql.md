# Auditoría SQL — corrección, concurrencia y rendimiento

**Estado:** ocho hallazgos documentados; SQL-001 está parcialmente corregido y SQL-004 corregido mediante FIX-016. Los demás siguen pendientes. Este informe consolida evidencia previa; su creación no ejecuta consultas, pruebas ni cambios de código.

## Alcance y calidad de la evidencia

- **Inspección estática:** búsquedas de documentos/personas, inventario, Finance, validación SUNAT, numeración y Dashboard; contraste con consumidores, observers y migraciones.
- **Pruebas aisladas registradas:** FIX-016, `tests/Unit/QueryCorrectnessTest.php`: 9 pruebas/45 aserciones aprobadas; suite Unit: 276 pruebas/1619 aserciones, PHP 8.3.36. Usan SQLite `:memory:` y funciones fixture para expresiones MySQL/JSON. No prueban el motor MariaDB ni concurrencia. Revisión independiente de FIX-016 pendiente según el registro consultado.
- **Metadatos reales registrados:** un esquema tenant accesible contenía `documents`, `dispatches` e `item_warehouse`; en las tres tablas solo `PRIMARY(id)` era único. Los cinco conteos de grupos duplicados examinados dieron cero: tupla fiscal y `external_id` de documentos y guías, y pareja artículo/almacén. Es una instantánea sin bloqueo, no prueba de concurrencia ni autorización para migrar índices.
- **Rendimiento:** N+1 y materialización identificados por código; sin EXPLAIN, mediciones de latencia, memoria o pruebas de carga. Los metadatos anteriores no cubren `padrones` ni todos los tenants.

Las referencias nombran métodos para seguir siendo navegables tras cambios de línea. Los detalles de implementación y pruebas de FIX-016 están en [AUDIT_LOG.md](../AUDIT_LOG.md#fix-016--consultas-verificadas-de-servicios-cuentas-por-pagar-y-hotel).

## Resumen

| ID | Tema | Estado |
|---|---|---|
| SQL-001 | Alcance de búsquedas con OR | Servicio corregido; otras sospechas no reproducidas |
| SQL-002 | Reversión repetida de inventario | Pendiente; saldos globales y por almacén son distintos |
| SQL-003 | Actualización concurrente de stock | Pendiente |
| SQL-004 | Usuario opcional y etiquetas de cuentas por pagar | Corregido en FIX-016 |
| SQL-005 | Consultas por fila en Finance | Pendiente; impacto no medido |
| SQL-006 | Reintentos externos ilimitados | Pendiente |
| SQL-007 | Asignación concurrente de números | Pendiente; falta protección única verificada |
| SQL-008 | Historial completo para totales de Dashboard | Pendiente; impacto no medido |

## SQL-001 — Alcance de búsquedas con alternativas OR

- **Ubicación:** `modules/Document/Traits/SearchTrait.php`, `getItemsServices` y `getItemsNotServices`; `app/Models/Tenant/Item.php`, `scopeWhereWarehouse`; búsquedas de clientes/proveedores en sus controladores tenant.
- **Confirmado y corregido:** la búsqueda textual de servicios podía incluir productos `NIU` porque `unit_type_id = ZZ` restringía solo una alternativa. FIX-016 agrupa las alternativas conservando los filtros y las ramas existentes por ID/código de barras.
- **Rectificación de la hipótesis inicial:** las supuestas filtraciones de almacén/productos y de tipo de persona/documento **no se reprodujeron** con las consultas Eloquent instaladas. El framework agrupa automáticamente los scopes locales; leer un OR aislado no demuestra el SQL final. No se modificaron esos caminos y quedaron controles de regresión.
- **Estado y límite:** parcialmente corregido respecto del informe inicial, con parte de sus sospechas descartada para los casos probados. No afirmar una filtración vigente en esas ramas ni extender la prueba a todas las combinaciones de datos.

## SQL-002 — Eventos repetidos vuelven a restaurar inventario

- **Ubicación:** `app/Providers/AnulationServiceProvider.php`, callback `Document::updated`; `modules/Inventory/Providers/InventoryVoidedServiceProvider.php`, `voided` y `voided_order_note`; cancelación directa en `app/Http/Controllers/Tenant/SaleNoteController.php`.
- **Evidencia estática:** las acciones de reversión dependen del estado terminal actual y pueden repetirse ante nuevas actualizaciones o cancelaciones del mismo registro.
- **Distinción necesaria:** el provider legacy modifica `items.stock` y kardex global al llegar a `11`. El provider de Inventory modifica stock por almacén, kardex, lotes y anticipos para `09`/`11`. **No son providers duplicados eliminables.** La transición `09 → 11` debe restaurar el global si aún no lo hizo, pero no repetir la reversión por almacén.
- **Corrección acotada propuesta:** controlar la transición desde el estado original, preservando esas diferencias; en la cancelación directa de notas de venta, bloquear y salir si ya está cancelada. Probar actualización repetida, transiciones terminales y efectos de ambos almacenes por separado.
- **Pendiente:** sin prueba de concurrencia real; no ampliar por este cambio las reglas de notas de crédito, reapertura ni selección de almacén.

## SQL-003 — Stock calculado mediante lectura, modificación y guardado

- **Ubicación:** `modules/Inventory/Traits/InventoryTrait.php`, `updateStock` y `updateStockPurchase` (referencias originales: líneas 200–220 y 429–436).
- **Evidencia:** el saldo se lee y se vuelve a guardar sin serializar las modificaciones concurrentes. Dos operaciones pueden partir del mismo saldo y perder uno de los movimientos. El esquema tenant inspeccionado tampoco tenía unicidad de `(item_id, warehouse_id)`.
- **Corrección propuesta:** actualización aritmética atómica o bloqueo de la fila dentro de la transacción del movimiento; tratar también la creación concurrente de la pareja artículo/almacén. Cualquier índice único requiere revisión de datos y despliegue explícito.
- **Verificación pendiente:** movimientos simultáneos sobre un motor aislado equivalente. Cero duplicados en la instantánea no descarta esta carrera.

## SQL-004 — Cuentas por pagar excluidas y gastos mal etiquetados

- **Ubicación:** `modules/Finance/Helpers/ToPay.php`, `getToPay` y `getToPayNoFilter`.
- **Defecto reproducido:** un usuario opcional vacío se convertía en un filtro por usuario nulo/vacío, ocultando compras y gastos asignados. Dos ramas emitían `sale_note` para gastos.
- **Corrección FIX-016:** aplicar el filtro de usuario solo cuando tiene valor, calificando la columna de compra/gasto; emitir `expense` en ambas ramas. Las pruebas aisladas registradas comprobaron la consulta y etiquetas.
- **Estado:** corregido con evidencia aislada; no implica optimizar las consultas por fila descritas en SQL-005 ni validar todos los filtros del reporte.

## SQL-005 — Finance ejecuta consultas adicionales por cada registro

- **Ubicación:** `modules/Finance/Http/Resources/UnpaidCollection.php`, `toArray`; `modules/Finance/Traits/UnpaidTrait.php`, `transformRecords`; `modules/Finance/Helpers/ToPay.php`, búsquedas de último pago en ambos métodos.
- **Evidencia estática:** vencimientos, guías y último pago se consultan dentro de transformaciones por fila. El número de consultas crece con los resultados; no se midió cuánto tarda.
- **Corrección propuesta:** cargar relaciones y últimas fechas en bloque mediante relaciones/subconsultas apropiadas; conservar campos, orden y resultados. Comparar salida y número de consultas con fixtures de documentos, notas de venta, compras y gastos.
- **Estado:** pendiente. La duplicación de transformadores se documenta aparte en [DEAD-002](dead-code.md#dead-002--transformadores-duplicados-pero-activos).

## SQL-006 — Reintentos SUNAT/OCR ilimitados, también dentro de una transacción

- **Ubicación:** `modules/Document/Http/Controllers/ValidateDocumentController.php`, `validateDocuments` y `regularize`; `app/Console/Commands/ValidateDocumentsCommand.php`, `handle`; `app/CoreFacturalo/Services/Extras/ValidateCpe2.php`, `validateAndChangeDocuments` y transporte HTTP/OCR.
- **Evidencia:** ambos bucles del controlador reintentan mediante `goto` sin límite. `regularize` hace la consulta externa dentro de la transacción tenant del lote; otros dos consumidores mantienen el mismo patrón ilimitado.
- **Corrección propuesta:** intentos y tiempos de espera finitos; reunir los resultados antes de abrir la transacción de escritura y no cambiar estados si falla la validación del lote. Conservar la tabla de estados y las actualizaciones Eloquent, cuyos eventos tienen efectos de negocio. En la consulta paginada, informar la validación fallida sin persistir un estado fiscal inventado.
- **Límite técnico:** el timeout del OCR instalado corta el sondeo, pero no demuestra terminación del proceso hijo. La solución debe verificar ese límite sin llamadas reales a SUNAT.
- **Estado:** no corregido. Pruebas pendientes con respuestas/OCR falsos: agotamiento de intentos, ausencia de transacción durante consultas externas y rollback completo ante fallo de escritura.

## SQL-007 — Numeración sin exclusión mutua ni unicidad fiscal

- **Ubicación:** `app/CoreFacturalo/Requests/Inputs/Functions.php`, `newNumber`; `app/CoreFacturalo/Requests/Inputs/DispatchInput.php`; `app/Observers/DocumentObserver.php`, `creating`; registro del observer en `app/Providers/AppServiceProvider.php`.
- **Evidencia:** la asignación basada en el máximo actual permite elegir el mismo siguiente número en emisiones concurrentes. **Las facturas también alcanzan `newNumber` mediante el observer registrado**; una llamada comentada en el input no las excluye del problema.
- **Esquema real inspeccionado:** sin unicidad de `(soap_type_id, document_type_id, series, number)` ni de `external_id` en documentos/guías. Los cuatro conteos de duplicados correspondientes eran cero al auditar, no una garantía futura.
- **Corrección propuesta:** serializar la reserva del correlativo y definir una defensa única con reintento controlado, después de revisar datos y compatibilidad. No agregar índices automáticamente desde esta auditoría.
- **Estado:** pendiente; requiere pruebas de emisiones simultáneas en base aislada, además de pruebas unitarias de formato y partición del correlativo.

## SQL-008 — Dashboard materializa historial para calcular sumas

- **Ubicación:** `modules/Dashboard/Helpers/DashboardData.php`, totales globales de documentos y notas de venta; consumidores en `modules/Dashboard/Http/Controllers/DashboardController.php` (referencias originales: helper 162–168/290–296 y controlador 65–66).
- **Evidencia estática:** se recuperan modelos históricos para sumar en memoria. La reducción heredada de relaciones/pagos innecesarios disminuye carga, pero no convierte el cálculo en un agregado SQL ni demuestra su latencia real.
- **Corrección propuesta:** mover agregaciones al motor solo tras pruebas de equivalencia de estados, monedas y notas de crédito. Mantener los contratos de respuesta y acotar el historial cuando corresponda.
- **Estado:** pendiente; sin plan de ejecución ni medición de memoria/tiempo. No presentar el riesgo de escala como una caída ya observada.

## Próximo paso

Priorizar las correcciones de integridad/concurrencia y SQL-006 con pruebas aisladas y revisión independiente. Reservar índices, cambios de reglas de stock y optimizaciones de Dashboard para cambios explícitos con evidencia; este informe no los aplica.
