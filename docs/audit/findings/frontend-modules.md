# Hallazgos de frontend — Ecommerce y Hotel

Se documentan tres riesgos en flujos concretos: interpretación de datos del carrito como HTML/código, emisión previa a la finalización de una estancia y un indicador de carga sin salida ante una respuesta negativa. Este documento no acredita reparaciones ni cobertura completa de los módulos.

## Alcance y evidencia

- Fuente: informe estático previo de frontend, recuperado de Engram (`Mapped high-risk frontend audit findings`, observación 860, 2026-11-16).
- Verificación documental: rutas y fragmentos relevantes consultados localmente el 2026-11-17. Las líneas son referencias de esa consulta y pueden desplazarse con cambios posteriores.
- Cobertura limitada: representación del carrito de Ecommerce, respuesta del pago en efectivo y secuencia de emisión/finalización del checkout de Hotel.
- No ejecutado: pruebas de explotación, reproducciones, navegador, pruebas automatizadas, compilaciones, solicitudes HTTP ni acceso a bases de datos. Los impactos se deducen del flujo estático; no se presentan como incidentes observados.
- Estado: hallazgos registrados para seguimiento, sin verificación de correcciones concurrentes o posteriores.

## FEM-001 — Datos del carrito se interpretan como HTML y se insertan en eventos inline

**Prioridad: alta. Categoría: seguridad de representación / riesgo de XSS. Evidencia: estática.**

**Ubicación.** `modules/Ecommerce/Resources/views/layouts/partials_ecommerce/cart_dropdown.blade.php:64-89`; `modules/Ecommerce/Resources/views/items/partial.blade.php:83`.

**Problema.** El desplegable interpola descripción, imagen e identificador de productos recuperados de almacenamiento local dentro de una cadena HTML que se pasa a `append`. La tarjeta de producto inserta JSON en un argumento de un manejador `onclick`. Escapar HTML no equivale a codificar correctamente datos dentro del contexto de código JavaScript de un atributo de evento.

**Caso e impacto.** Una cadena de producto no confiable puede dejar de mostrarse como texto y modificar la estructura o interpretación del contenido del carrito. La combinación de HTML dinámico y eventos inline introduce riesgo de ejecución de contenido no deseado en el origen de la tienda. No se ejecutaron cadenas de prueba ni se comprobó explotación.

**Corrección propuesta.** Construir nodos y asignar texto con APIs de texto del DOM; validar los valores utilizados como URL. Sustituir eventos inline por listeners. Transportar los datos del producto en un atributo de datos correctamente escapado para HTML y leerlos como datos, nunca como código ejecutable.

**Validación pendiente.** Verificar que descripciones con caracteres especiales se muestran literalmente, que añadir/eliminar productos sigue funcionando y que los datos no se convierten en nodos ni manejadores ejecutables. Hacerlo en un entorno aislado autorizado.

## FEM-002 — Hotel emite el comprobante antes de completar la finalización de la estancia

**Prioridad: alta. Categoría: integridad del flujo / recuperación ante fallos parciales. Evidencia: estática.**

**Ubicación.** `modules/Hotel/Resources/assets/js/views/rooms/Checkout.vue:685-721`.

**Problema.** Tras emitir el comprobante se guarda su identificador, se muestran opciones y se cierra el diálogo. Después se inicia otra solicitud para finalizar la estancia, pero su promesa no se devuelve ni espera dentro de la cadena principal. El `finally` exterior puede liberar el estado de carga antes de terminar esa operación.

**Caso e impacto.** Si la emisión tiene éxito y la finalización falla, el comprobante ya existe pero la estancia puede quedar pendiente. Repetir el flujo completo puede volver a emitir en lugar de completar únicamente la operación pendiente. Esta secuencia se identificó en código; no se emitieron comprobantes ni se reprodujo el fallo.

**Corrección propuesta.** Conservar y reutilizar el identificador ya emitido, esperar la finalización y presentar explícitamente el estado parcial. Un reintento posterior debe completar la finalización pendiente, sin volver a emitir ni repetir operaciones ya completadas. No asumir que un error posterior revierte una emisión anterior.

**Validación pendiente.** Simular emisión exitosa y finalización fallida: debe conservarse el identificador, comunicarse el estado parcial y reintentarse solo la finalización. Verificar también éxito completo y cierre del estado de carga en todos los caminos.

## FEM-003 — El pago en efectivo deja el indicador de carga activo con `success: false`

**Prioridad: media. Categoría: manejo de errores / interacción. Evidencia: estática.**

**Ubicación.** `modules/Ecommerce/Resources/views/cart/detail.blade.php:345-376`; respuesta relacionada en `modules/Ecommerce/Http/Controllers/EcommerceController.php:287-294`; ruta en `modules/Ecommerce/Routes/web.php:38`.

**Problema.** La interfaz abre un modal de carga y solo procesa el caso `response.data.success` verdadero. El controlador puede devolver una respuesta normal con `success: false`; al resolverse la solicitud sin error HTTP, ese resultado no entra en `catch` y no hay una rama negativa que cierre o sustituya el indicador.

**Caso e impacto.** Un pedido rechazado con respuesta HTTP satisfactoria y resultado de negocio negativo deja al comprador esperando, sin una indicación clara del rechazo. El riesgo puede inducir reintentos; no se ejecutó un pago para comprobarlo.

**Corrección propuesta.** Manejar explícitamente el resultado negativo, cerrar o reemplazar el indicador y mostrar un mensaje seguro. Preservar el carrito para que el usuario pueda corregir o reintentar, y garantizar la salida del estado de carga tanto ante rechazo de negocio como ante error de transporte.

**Validación pendiente.** Cubrir respuesta positiva, respuesta normal con `success: false` y fallo de transporte. Ningún camino debe dejar el indicador indefinidamente ni mostrar éxito para una operación rechazada.

## Siguiente paso

Revisar primero los límites de confianza del carrito y la recuperación de Hotel. La verificación de cada reparación debe registrar por separado qué se comprobó estáticamente y qué se ejecutó.
