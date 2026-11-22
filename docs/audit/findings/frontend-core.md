# Hallazgos de frontend — flujos principales

Se documentan dos riesgos detectados por revisión estática: envíos repetidos de pagos y borradores de POS compartidos entre usuarios del mismo origen. Este documento registra hallazgos; no acredita correcciones ni una auditoría completa del frontend.

## Alcance y evidencia

- Fuente: informe estático previo de frontend, recuperado de Engram (`Mapped high-risk frontend audit findings`, observación 860, 2026-11-16).
- Verificación documental: rutas y fragmentos relevantes consultados localmente el 2026-11-17. Las líneas son referencias de esa consulta y pueden desplazarse con cambios posteriores.
- Cobertura limitada: envío de pagos en documentos, notas de venta, compras y gastos; persistencia local del borrador y montos del POS.
- No ejecutado: reproducciones, navegador, pruebas, compilaciones, solicitudes HTTP ni acceso a bases de datos. No se verificaron pagos duplicados persistidos ni garantías de idempotencia del servidor.
- Estado de los hallazgos: registrados para seguimiento. Las correcciones concurrentes o posteriores deben validarse por separado.

## FEC-001 — Repetir el clic puede enviar el mismo pago mientras sigue pendiente

**Prioridad: alta. Categoría: integridad de operaciones / interacción. Evidencia: estática.**

### Ubicación

| Flujo | Archivo y referencias |
|---|---|
| Documentos | `resources/js/views/tenant/documents/partials/payments.vue:103,241,250-284` |
| Notas de venta | `resources/js/views/tenant/sale_notes/partials/payments.vue:98,234,243-286` |
| Compras | `modules/Purchase/Resources/assets/js/views/purchase_payments/payments.vue:98,233,242-261` |
| Gastos | `modules/Expense/Resources/assets/js/views/expense_payments/payments.vue:98,240,249-268` |

**Problema.** Los botones llaman a `clickSubmit(index)` sin deshabilitar la fila pendiente. La fila declara `loading`, pero el flujo observado no lo utiliza para impedir una segunda solicitud antes de recibir la primera respuesta.

**Caso e impacto.** Con una respuesta lenta, repetir el clic sobre un pago nuevo puede enviar varias solicitudes con el mismo contenido. Si el servidor admite ambas, pueden registrarse pagos repetidos; ese resultado no se reprodujo en esta verificación documental.

**Corrección propuesta.** Bloquear sincrónicamente el reingreso por fila, deshabilitar guardar/cancelar durante el envío y liberar el estado al terminar, incluidos errores. Conservar las validaciones y la presentación de errores existentes. Esta protección de interfaz NO sustituye la idempotencia del servidor frente a reintentos, otras pestañas o solicitudes independientes.

**Validación pendiente.** Con una respuesta controlada pendiente, dos clics deben producir una sola solicitud. Comprobar también recuperación tras error y nuevo envío legítimo. Validar la idempotencia del backend en una tarea separada.

## FEC-002 — El borrador del POS no distingue usuarios del mismo origen

**Prioridad: media. Categoría: aislamiento de datos locales. Evidencia: estática.**

**Ubicación.** `resources/js/views/tenant/pos/index.vue:996-998,1129-1152,1319-1334`; `resources/js/views/tenant/pos/partials/payment.vue:625-637,735-747,771-774`.

**Problema.** El POS guarda y recupera claves genéricas de `localStorage`, como `form_pos`, `customer`, `amount`, `enter_amount` y `difference`, sin incorporar la identidad del usuario. El almacenamiento pertenece al origen del navegador, no a la sesión autenticada.

**Caso e impacto.** En un equipo compartido, otro usuario del mismo tenant y origen puede recuperar el borrador, cliente o montos dejados por el usuario anterior. El riesgo descrito no implica acceso entre dominios de tenants diferentes.

**Corrección propuesta.** Definir claves con contexto de tenant y usuario, comprobar ese contexto antes de restaurar y descartar el estado heredado sin propietario verificable. Tratar el cambio de usuario y cierre de sesión sin borrar datos locales ajenos a este flujo.

**Validación pendiente.** Alternar dos usuarios del mismo tenant en el mismo navegador: cada uno debe recuperar únicamente su propio borrador; comprobar también compatibilidad con claves antiguas y restauración normal del mismo usuario.

## Siguiente paso

Resolver y verificar cada hallazgo por separado. No marcarlo como corregido únicamente por existir este informe o por añadir un bloqueo visual.
