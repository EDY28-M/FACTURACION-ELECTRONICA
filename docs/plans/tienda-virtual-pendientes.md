# Tienda virtual (ecommerce): estado y pendientes

Última actualización: 2026-11-23. Documento de traspaso para cualquier persona o IA que continúe este trabajo.

## 1. Contexto

- Proyecto: ERP multi-tenant de facturación electrónica SUNAT (Laravel 10, Vue 2, Element UI, hyn/multi-tenant).
- Entorno local: Docker (`facturador_fpm` para PHP, `facturador_mariadb`, web en `http://localhost:8080`). Para probar un tenant con curl se envía `-H "Host: net.localhost:8080"`.
- Remoto de trabajo: `github` → `EDY28-M/FACTURACION-ELECTRONICA` (repo **público**), rama `main`. Último commit subido: `b1a5262`.
- Convención de commits del dueño: mensaje en español, descripción normal **sin** prefijos tipo `fix:`/`feat:` y **sin** atribución de IA.

## 2. Estado del árbol de trabajo (sin commitear)

Hay ~83 archivos modificados sin commitear. Se mezclan tres trabajos:

1. **Tanda 1 de QA (completa y probada).** Corrige QA-01 a QA-12, QA-16, QA-18 y QA-20 (ver sección 4). Cuando terminó, la suite tenía 389 tests y solo fallaba `tests/Feature/ExampleTest.php` (falla preexistente).
2. **Arreglo de correo en emisión de comprobantes (completo).** `app/CoreFacturalo/Facturalo.php::sendEmail()` ya no puede revertir un comprobante si el SMTP falla (try/catch + `report()`, omite correos vacíos o inválidos).
3. **Tanda 2 de QA (INTERRUMPIDA, sin verificar).** El agente que la hacía se cortó a mitad de camino. Dejó cambios parciales, entre otros:
   - Rediseño de Configuración de tienda: se borraron `modules/Ecommerce/Resources/assets/js/views/configuration_{culqi,image,logo,social,tags}/index.vue` y se agregaron `configuration/partials/` y `configuration/section.js`.
   - Se borró `modules/Ecommerce/Resources/views/cart/pay.blade.php`.
   - Cambios en `app/Exceptions/Handler.php`, `app/Http/Controllers/Tenant/DocumentController.php`, `OrderController.php`, `PromotionController.php`, `TagController.php`, la plantilla `resources/views/tenant/templates/email/store_order.blade.php` y otros.

   **No se sabe si compila, si pasa los tests ni si el frontend está reconstruido.** Antes de cualquier otra cosa hay que verificarlo (sección 6).

Archivos nuevos (sin seguimiento): `app/Http/Middleware/RequireStorefrontModule.php`, `app/Services/Ecommerce/`, `modules/Ecommerce/Resources/assets/js/views/configuration/partials/`, `modules/Ecommerce/Resources/assets/js/views/configuration/section.js`, `resources/views/tenant/templates/email/store_order.blade.php`, `tests/Unit/EcommerceAdminEndpointsTest.php`, `tests/Unit/EcommerceStorefrontFixesTest.php`.

## 3. Decisiones del dueño (obligatorias)

- **Stock de pedidos de la tienda:** se descuenta **solo al emitir el comprobante** (vía la ruta normal `DocumentItem::created` en `modules/Inventory/Providers/InventoryKardexServiceProvider.php`, igual que cualquier venta). El estado **"Despachado" (3) es solo un estado**: no descuenta stock ni escribe kardex. La tanda 1 había dejado el descuento al despachar (`OrderFulfillment`); **hay que quitarlo** si la tanda 2 no llegó a hacerlo. Hoy, sin ese cambio, un pedido con comprobante descuenta stock dos veces.
- **Correo:** el envío de correos nunca debe romper un pago, un pedido ni un comprobante. Es "mejor esfuerzo": se registra en el log y se continúa.
- **Pagos en efectivo y con tarjeta en la tienda:** exigen sesión del cliente (la tanda 1 lo aplicó también en el servidor).

## 4. Bugs del QA

### 4.1 Resueltos en la tanda 1 (sin commitear, probados)

| ID | Resumen |
|---|---|
| QA-01 (crítico) | "Generar comprobante" en Pedidos: el vínculo pedido-comprobante lo hace ahora el admin con `GET orders/{id}/document-draft` y `POST orders/{id}/attach-document` (transacción, bloqueo de fila, idempotente). Se quitó `transactionFinally`. Ya no se emiten comprobantes duplicados. |
| QA-02 | Guardar el logo ya no borra el token público de Culqi. |
| QA-03 | El comprobante se arma en el servidor (`OrderDocumentBuilder`) desde las líneas guardadas del pedido; ya no se confía en el JSON `purchase` del cliente. |
| QA-04 | Checkout exige nombre, correo válido, teléfono, dirección y documento (`CheckoutRules`); `OrderCollection` es null-safe. |
| QA-05 | El IGV solo se aplica a afectaciones gravadas (10-17); el precio es el mismo en lista, detalle, buscador y carrito (`StoreItemPresenter`, `CartPricing`). |
| QA-06 | La tienda pública ya no expone costo, margen, stock ni almacenes (`items_bar` y `data-product` filtrados). |
| QA-07 | El carrito rechaza cantidades mayores al stock cuando hay control de stock. |
| QA-08 | El cambio de estado valida la entrada (422/404 en vez de 500). **Ojo:** el descuento de stock al despachar debe eliminarse (sección 3). |
| QA-09 | El cliente ve la confirmación tras pagar en efectivo; se corrigió la variable `order` indefinida. |
| QA-10 | No se puede borrar una categoría en uso; el render es null-safe. |
| QA-11 | Productos no publicados o inactivos dan 404 y no se pueden cotizar ni comprar. |
| QA-12 | Registro de clientes validado (nombre, correo único, contraseña de 8 o más caracteres). |
| QA-16 | Enlaces de redes sociales validados como URL http(s), sin XSS. |
| QA-18 | Validación de largo y tipo en tags, promociones, contacto, registro, etc. |
| QA-20 | La tienda pública respeta el módulo `ecommerce` del plan (middleware `plan.storefront`, 404 si no está). |

### 4.2 Pendientes (tanda 2: se empezó, no se terminó ni se verificó)

| ID | Severidad | Resumen |
|---|---|---|
| QA-13 | Media | "Aplica en Tienda" se acepta sin código interno del producto. |
| QA-14 | Media | Errores de validación del checkout mal mostrados al cliente. |
| QA-15 | Media | El select de estado en Pedidos se desincroniza de la base: debe reflejar el estado real tras éxito y tras error, revertir si se cancela el diálogo de comprobante y rechazar transiciones inválidas con mensaje en español. |
| QA-17 | Media | Promociones: varios huecos (validación, imagen, enlace al producto). |
| QA-19 | Media | El botón VISA se muestra con solo la clave pública de Culqi (debería exigir ambas llaves). |
| QA-21 | Media | Correo y flujo después de un pedido en efectivo, engañosos o incompletos. |
| QA-22 | Media | Formulario "Información de contacto" (validación y feedback). |
| QA-23 | Media | Endpoints de Pedidos: validación y rutas muertas. |
| QA-24 | Media | Throttle compartido y login sin mensaje ante HTTP 429. |
| QA-25 | Media | Tags: duplicados, edición de IDs inexistentes, `status` asignable en masa. |
| QA-26 | Baja | Rating de productos con datos falsos y sin feedback. |
| QA-27 | Baja | Error CSRF devuelve 500 en vez de 419. No cambiar `APP_DEBUG` global. |
| QA-28 | Baja | Restos de plantilla y marca de terceros. |
| QA-29 | Baja | Carrito: cantidades y totales inconsistentes en algunos casos. |
| QA-30 | Baja | Parámetro `column` de listados sin lista blanca. |
| QA-31 | Baja | Afectaciones de IGV distintas de 10/20/30 rompen la generación del comprobante (hoy se rechazan con 422). |
| QA-32 | Baja | Un `purchase` inválido rompe el diálogo del comprobante (pedidos antiguos). |
| QA-33 | Baja | Buscador de la tienda: rendimiento. |
| QA-34 | Baja | Logout por GET. |
| QA-35 | Baja | Categorías (detalles menores de la pantalla). |

Otros pendientes de la misma tanda:

- **Código muerto / no implementado:** tarjeta "Tags" del menú, `configuration_image`, `pay_cart`, `askedDocument`. Hay que decidir caso por caso si se implementa o se elimina limpiamente.
- **Correos no fatales:** volver "mejor esfuerzo" todos los envíos de la tienda y de Pedidos: `EcommerceController` (dos envíos de `CulqiEmail`), `CulqiController`, `OrderController`. En `DocumentController` (botón "Enviar correo"), devolver un error claro en español en vez de 500. Agregar tests con un mailer que lanza excepción.
- **Probar cada submódulo de Tienda virtual sin errores:** Ir a Tienda, Pedidos, Productos Tienda Virtual, Categorías, Promociones y Configuración. Cada botón, interruptor y diálogo.
- **Menú lateral:** renombrar "Tags - Categorias(Etiquetas)" a "Categorías" y "Promociones(Banners)" a "Promociones y banners" (`resources/views/tenant/layouts/partials/sidebar.blade.php`).
- **Rediseño de Configuración de tienda** (empezado y sin terminar). Debe ser una sola pantalla coherente con el panel (tarjetas o pestañas como "Empresa") con estos grupos: Información de contacto, Logo y apariencia, Redes sociales, Pagos con Culqi (llave privada de solo escritura, estado "configurada", aviso de prueba o producción según `pk_test_`/`pk_live_`). Cada campo con etiqueta y texto de ayuda, errores 422 junto al campo, un botón de guardar por grupo con estado de carga, y un resumen ("Productos publicados: N", enlace a Productos y "Ver tienda").

## 5. Acciones del dueño (no de código)

- **SMTP:** el correo del tenant usa Gmail y falla con `530 5.7.0 Authentication Required`. Hay que configurar una **contraseña de aplicación** de Google (Cuenta → Seguridad → Verificación en 2 pasos → Contraseñas de aplicaciones).
- **Token expuesto:** la migración `database/migrations/2020_07_27_184250_add_apiruc_to_configurations.php` (línea 24) tiene un token de apiperu.dev escrito en el código y el repo es público. Hay que **regenerarlo** en apiperu.dev.

## 6. Cómo retomar (orden recomendado)

1. **Verificar el árbol:** `git status`, luego `php -l` sobre todos los PHP modificados dentro de `facturador_fpm`, y luego `docker exec facturador_fpm sh -c 'vendor/bin/phpunit 2>&1 | tail -4'`. Solo puede fallar `ExampleTest`.
2. **Revisar el diff de la tanda 2** con contexto fresco. Lo que esté a medias se completa o se revierte: nunca se deja roto.
3. **Aplicar la decisión de stock** (sección 3) con tests: despachar no descuenta; emitir el comprobante de un pedido descuenta una sola vez.
4. **Terminar los pendientes de la sección 4.2** en orden de severidad, con tests.
5. **Reconstruir el frontend** si se tocaron archivos Vue:
   1. Copiar antes `public/mix-manifest.json` a un lugar temporal.
   2. Ejecutar `npm run production:js`.
   3. Verificar que `rg -c '"/css/(app|auth|pos)\.css"' public/mix-manifest.json` devuelva 3.
   4. Revisar cómo se compilan los assets de `modules/Ecommerce`.
6. `docker exec facturador_fpm php artisan optimize:clear`.
7. **QA en navegador** del panel completo y de la tienda pública: navegar, carrito, efectivo, Culqi en sandbox, pedidos y estados, y generar el comprobante de un pedido.
8. **Antes de commitear:** revisión con contexto fresco. Después, commits por tema en español sin prefijos y push a `github main`.

## 7. Datos locales de prueba

- Usuario admin de QA local en el tenant `net`: `qa-tienda@example.test` (id 10). Solo existe en el Docker local; borrarlo cuando termine el QA.
- Productos publicados por el dueño: ids 2056 y 2078. No modificarlos en pruebas.
- Las llaves Culqi de la plataforma (base central) son de prueba (`pk_test_`/`sk_test_`). El tenant `net` no tiene llaves de Culqi en la tienda.
- Verificado contra el sandbox de Culqi: los rechazos llegan como **HTTP 400** con `type: "operacion_denegada"` (no 402 como dice la documentación); el 3DS es HTTP 200 con `action_code: "REVIEW"`.
