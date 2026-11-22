# Código muerto y duplicación — evidencia y límites

**Resultado:** las cinco eliminaciones heredadas no tienen consumidores estáticos activos identificados. Esto no prueba todos los flujos dinámicos. Los transformadores duplicados que siguen activos no deben eliminarse sin reemplazo.

## Alcance

Consolidación de la auditoría previa de referencias, rutas, namespaces, providers y vistas, junto con el registro de FIX-010. No es una nueva búsqueda exhaustiva ni una ejecución funcional; esta persistencia no modifica fuentes, elimina archivos ni ejecuta pruebas.

| ID | Tema | Estado |
|---|---|---|
| DEAD-001 | Cinco archivos legacy ya eliminados | Sin consumidores estáticos activos identificados |
| DEAD-002 | Transformadores Finance duplicados | Activos; consolidación pendiente |
| DEAD-003 | `DashboardView::getUnpaid` eliminado | Sin consumidor estático activo; conservar variante usada |

## DEAD-001 — Cinco eliminaciones heredadas sin consumidores activos identificados

| Archivo eliminado | Evidencia y distinción |
|---|---|
| `app/Http/Controllers/Tenant/Dispatch2Controller.php` | No se identificaron rutas ni consumidores activos del controlador legacy. |
| `app/Http/Controllers/Tenant/InventoryController.php` | Las rutas vigentes usan el controlador del módulo Inventory, no esta clase del namespace App. |
| `app/Http/Controllers/Tenant/InventoryController2.php` | No se identificaron consumidores activos; no confundir el nombre con el controlador del módulo. |
| `modules/Account/Resources/views/accounting/templates/excel_concar_bk.blade.php` | Respaldo sin referencia activa identificada. La plantilla Concar activa no es la variante `_bk`. |
| `modules/Ecommerce/Http/ViewComposers/TakeProductoViewComposer.php` | La referencia en `app/Providers/ViewServiceProvider.php` está dentro de un comentario de bloque, no registra el composer. |

- **Resultado:** verificación estática favorable de eliminaciones ya presentes; no se realizó ninguna eliminación adicional.
- **Falsos positivos descartados:** coincidencias de nombre de `InventoryController` en rutas de otro namespace, referencia comentada de `TakeProductoViewComposer` y plantilla Concar activa frente al respaldo `_bk`.
- **Límite:** las búsquedas de fuente no descartan referencias construidas dinámicamente, personalizaciones de instalación ni plantillas externas. Falta prueba funcional de los flujos afectados. Para los dos últimos archivos, [FIX-010](../fixes-batch1.md#fix-010--limpieza-de-código-muerto-confirmado-y-protección-de-ruta-en-levelaccess) registra comprobación de referencias y sintaxis, pero no verificación funcional completa.

## DEAD-002 — Transformadores duplicados pero activos

- **Ubicación:** `modules/Finance/Http/Resources/UnpaidCollection.php`, `toArray`; `modules/Finance/Traits/UnpaidTrait.php`, `transformRecords`.
- **Evidencia:** ambos construyen la misma representación de cuentas por cobrar y repiten búsquedas de vencimientos, guías y último pago. Duplicación no significa falta de uso; quitar uno sin adaptar sus consumidores cambia flujos activos.
- **Corrección propuesta:** compartir una sola transformación conservando ambos puntos de entrada cuando corresponda. Antes, comparar estructura, valores nulos, moneda, tipo de documento y último pago con fixtures equivalentes.
- **Estado:** pendiente; no se consolidó ni eliminó código. Las consultas por fila están documentadas en [SQL-005](sql.md#sql-005--finance-ejecuta-consultas-adicionales-por-cada-registro); no hay medición de rendimiento.

## DEAD-003 — `DashboardView::getUnpaid` eliminado sin retirar la variante activa

- **Ubicación:** `modules/Dashboard/Helpers/DashboardView.php`; referencias históricas comentadas en `modules/Dashboard/Http/Controllers/DashboardController.php`; consumidor vigente en `modules/Finance/Http/Controllers/UnpaidController.php`.
- **Evidencia:** el método eliminado `getUnpaid` solo tenía consumidores comentados en la inspección previa. FIX-010 también retiró imports exclusivos y bloques comentados relacionados.
- **Distinción necesaria:** `getUnpaidFilterUser` sí tiene consumidor activo en `UnpaidController`; **se conserva**. La semejanza de nombres no permite eliminar ambos.
- **Estado:** limpieza heredada documentada; sin nueva eliminación. FIX-010 registra búsqueda de referencias y comprobación de sintaxis, no una prueba integral de Dashboard/Finance.

## Próximo paso y exclusiones

Validar los flujos de inventario, guías, Concar y Ecommerce en un entorno aislado antes de declarar las eliminaciones listas para publicación. Consolidar Finance solo con pruebas de equivalencia. No reactivar providers comentados ni retirar observers globales/por almacén como supuestos duplicados: modifican almacenes de stock distintos, según [SQL-002](sql.md#sql-002--eventos-repetidos-vuelven-a-restaurar-inventario).
