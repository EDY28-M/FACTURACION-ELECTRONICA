# Verificación del registro consolidado — 2026-11-18

**Objeto.** Comprobar, antes de continuar, si el estado descrito en `docs/audit/README.md` (documento fechado el 2026-11-18, `HEAD` `2064950`) coincide con el repositorio y con lo que se puede reproducir hoy.

**Naturaleza de esta comprobación.** No es una revisión independiente fresca: la hace el agente que continúa la misma sesión de auditoría. Sirve para verificar hechos recomputables (git, archivos, pruebas, lint, artefactos), no para sustituir la revisión independiente global que el propio registro deja pendiente. No se leyó `.env`, no se escribió en bases de negocio, no se hicieron peticiones a SUNAT/OSE/Culqi y no se crearon commits, push ni despliegues.

**Método.** Lectura de git en solo lectura; ejecución de la suite Unit y del lint dentro de `facturador_fpm` (PHP 8.3.36); ejecución de las pruebas Node y de `npm run test:dashboard` con `TMPDIR` en un directorio temporal; comprobación de archivos y artefactos con `ls`, `find`, `grep` y `git`.

## 1. Resultado global

El registro es **sustancialmente cierto**. Todos los números grandes que estructuran el documento (HEAD, rama, 98 modificados, 5 eliminados, 43+1 sin seguimiento, 28 FIX, 2 OPS, 209 hallazgos, 336 pruebas/2097 aserciones, 12 pruebas PHPUnit y 7 Node nuevas, la ausencia de `INFORME_FINAL.md` en ese momento) se recomputan y coinciden. Las diferencias encontradas son de detalle, de redacción o de un archivo generado; ninguna cambia las conclusiones ni indica trabajo inventado.

- No hay commits en la rama: `git log main..chore/system-audit` está vacío y `chore/system-audit` apunta al mismo commit que `main` (`2064950`).
- El árbol sigue sucio exactamente en la proporción declarada: 98 archivos versionados modificados, 5 eliminados y 44 sin seguimiento en ese momento (43 sin contar el README).
- La suite Unit pasa completa: **336 pruebas, 2097 aserciones, 2 deprecaciones de PHPUnit** en PHP 8.3.36. Cada archivo nuevo da el conteo que el registro le atribuye.
- El lint no encuentra errores de sintaxis.
- Las 7 pruebas UI nuevas, `npm run test:dashboard` (con `TMPDIR`) y `chart-month-boundaries.php` pasan.
- Los artefactos del build de OPS-001 existen y el `manifest.js` versionado los referencia.

## 2. Estado del repositorio

| Afirmación del README | Observado | Veredicto |
|---|---|---|
| `HEAD` es `2064950` | `git rev-parse --short HEAD` → `2064950` | Confirmado |
| Rama de trabajo `chore/system-audit` | Rama actual `chore/system-audit`; existen `main` y `master` | Confirmado |
| Nada commiteado, subido ni desplegado | `git log main..chore/system-audit` vacío; el tip de la rama es `2064950`, igual que `main`; `git reflog` solo registra la creación de la rama el 2026-11-16 17:40 | Confirmado |
| 98 archivos versionados modificados | `git diff --name-only` = 103; `--diff-filter=D` = 5; 103 − 5 = 98 | Confirmado |
| 5 archivos versionados eliminados | 5 rutas con estado `D`; las cinco no existen en disco | Confirmado |
| 43 sin seguimiento, 44 con el README | `git ls-files --others --exclude-standard` = 44 en el momento de la comprobación (antes de añadir este documento y el informe final); 21 son de `docs/audit` (20 + README), 19 pruebas nuevas y 4 helpers de producción. Hoy el total es 46. | Confirmado |
| 103 entradas en `git diff --name-status`; 2096 inserciones / 2177 eliminaciones | `git diff --stat` → `103 files changed, 2096 insertions(+), 2177 deletions(-)` | Confirmado |
| `INFORME_FINAL.md` no existe | No existía en `docs/audit/` al comprobar | Confirmado (antes de este trabajo) |

**Base de la rama.** El registro deja sin resolver si la rama nació de `main` o de `master`. Hoy se puede resolver: `main` apunta a `2064950` (el mismo commit que `HEAD`) y `master` apunta a `0755d75` («version 1»), un commit anterior. El `reflog` dice «Created from HEAD» el 2026-11-16 17:40; como `HEAD` era `main`, la afirmación de `PROGRESS.md` («creada desde `main`») es correcta en la práctica. `master` no es la base de esta rama.

## 3. Inventario, documentación y registros

| Afirmación | Observado | Veredicto |
|---|---|---|
| 28 entradas FIX (001–028) | 20 encabezados en `AUDIT_LOG.md` (001, 002, 011–028) + 8 en `fixes-batch1.md` (003–010) = 28 | Confirmado |
| 2 entradas OPS | `OPS-001` y `OPS-002` en `AUDIT_LOG.md` | Confirmado |
| 209 encabezados de hallazgo en los ocho informes | 24 (core) + 17 (inventory) + 37 (ops) + 28 (sales) + 20 (security) + 27 (system-auth) + 31 (tenant-1) + 25 (tenant-2) = 209 | Confirmado |
| 16 archivos en `findings/` | 16 archivos | Confirmado |
| 4 documentos base + README | `AUDIT_LOG.md`, `BASELINE.md`, `PROGRESS.md`, `fixes-batch1.md`, `README.md` | Confirmado |
| `PROGRESS.md` mantiene «Pendiente» las fases 4 y 5 | filas 4 y 5 de la tabla de fases: «Pendiente» al momento de la comprobación; se corrigieron después | Confirmado (tabla desactualizada) |
| `BASELINE.md` 218/846 con 1 error; FIX-001 218/866 con 0 errores | Coincide literalmente en ambos documentos; son dos momentos distintos | Confirmado |
| `inherited-diff.md`: 0 críticos, 1 alto, 5 medios, 20 bajos, 13 informativos | Tabla de totales: 0/1/5/20/13 | Confirmado |
| G2 resume «Info 4» pero lista 5 entradas | `inherited-diff.md` pone 4 en la columna G2; `inherited-diff-g2.md` tiene IDG2-008 a IDG2-012 (5) | Confirmado (discrepancia ya anotada) |
| `security.md` habla de 1380 plantillas y `PROGRESS.md` de 1379 | 1379 archivos `.php` bajo `Templates`; hay 51 CSS y algunas imágenes además | Confirmado: 1379 es el conteo de plantillas PHP; el «1380» de `security.md` no se reproduce (diferencia de una unidad) |

## 4. Pruebas y lint

Ejecutado en `facturador_fpm` (PHP 8.3.36), sin red y sin bases de negocio.

| Afirmación | Observado | Veredicto |
|---|---|---|
| Suite Unit: 336 / 2097, 2 deprecaciones | `Tests: 336, Assertions: 2097, PHPUnit Deprecations: 2` | Confirmado |
| 12 archivos PHPUnit nuevos | 12 archivos sin seguimiento en `tests/Unit` | Confirmado |
| Conteos por archivo (13/407, 7/26, 12/144, 9/58, 8/73, 9/45, 5/91, 8/61, 7/54, 11/84, 14/67, 9/53, PaymentsTest 21/144) | Los 13 conteos coinciden exactamente | Confirmado |
| Lint: 3277 archivos sin errores | 0 líneas de error; el `find` actual cuenta 3278 archivos `.php` | Confirmado en lo esencial; +1 archivo (ver V-03) |
| 7 pruebas UI nuevas, todas en verde | Las 7 pasan con `node` | Confirmado |
| `npm run test:dashboard` verde con `TMPDIR` | Pasa completo (dashboard, carrera del gráfico, shells, publicación JS y sus cinco escenarios de rollback) | Confirmado |
| `chart-month-boundaries.php`: 5 casos | «five cases passed» | Confirmado |
| HTTP real de solo lectura (OPS-002) | Reproducido contra `net.localhost:8080`: `documents/status`, `sendserver` y `documents` → 401; `validate_cpe` → 404; `/login` → 200; `/dashboard` → 302 | Confirmado |
| Sin CI en el repositorio | No existe `.github` | Confirmado |

## 5. Código y artefactos citados

| Afirmación | Observado | Veredicto |
|---|---|---|
| `culqi/culqi-php` retirado de `composer.json` y `composer.lock` | No hay ninguna aparición de `culqi` en ninguno de los dos archivos | Confirmado |
| `documents/status` y `sendserver` dentro de `auth:api` + `locked.tenant` | Ambas rutas están dentro del grupo `['auth:api', 'locked.tenant']` de `routes/api.php` | Confirmado |
| `services/validate_cpe` y `services/consult_status` eliminadas | No aparecen en `routes/api.php` | Confirmado |
| `Handler::isUserFacing` existe y enmascara solo lo previsto | Método privado presente; condición `$code >= 500 && !$debug && !$this->isUserFacing($exception)` | Confirmado |
| `main.min.js` parcheado: 13622 → 14048 bytes, prefijo (10852) y sufijo (892) idénticos a `HEAD` | `wc -c` → 14048; `git show HEAD:…` → 13622; comparación byte a byte del prefijo y del sufijo: idénticos | Confirmado |
| Chunks ignorados por git y referenciados por el manifiesto | `.gitignore` ignora `/public/js/chunk-*.js` y sus mapas; `git ls-files public/js` no tiene chunks; los 8 chunks nuevos y sus 8 mapas existen en disco | Confirmado |
| 4 helpers de producción sin seguimiento | `TempImageHelper.php`, `CulqiErrorPolyfills.php`, `PaymentFileHelper.php` y `pos_draft.js` existen, están sin seguimiento y tienen sintaxis válida (`php -l`/`node --check`); los tres PHP están importados por los controladores/traits que los usan y `pos_draft` por las tres vistas del POS | Confirmado |
| 5 archivos eliminados sin referencias colgantes | No hay referencias a `Dispatch2Controller`, `InventoryController2` ni `excel_concar_bk`; la única mención a `TakeProductoViewComposer` está dentro de un comentario de bloque en `ViewServiceProvider.php`; `Dispatch2Validation` es otra clase (validación de request) | Confirmado |
| MariaDB publicada en `0.0.0.0:3307` | `docker ps`: `facturador_mariadb` con `0.0.0.0:3307->3306/tcp, [::]:3307->3306/tcp` | Confirmado |

## 6. Discrepancias y matices encontrados

Ninguna afecta a las conclusiones del registro; se anotan para que la siguiente persona no las herede.

| ID | Dónde | Qué se observó | Corrección propuesta |
|---|---|---|---|
| V-01 | README §2.1: «Tampoco hay pruebas Dusk» | Existen `tests/Browser/DocumentTest.php`, `tests/Browser/ExampleTest.php`, `Pages/`, `console/` y `screenshots/`; `phpunit.xml` fija `DB_DATABASE=multifacturalo_dusk`. Pero `composer.json` no declara `laravel/dusk` en `require-dev`, así que no son ejecutables como suite Dusk. | Decir «hay restos de pruebas Dusk que no se ejecutaron y cuya dependencia no está instalada», no «no hay pruebas Dusk». La conclusión operativa (no se ejecutaron) es correcta. |
| V-02 | README §4.5 (OPS-001): «(+19/−3)» para los tres archivos versionados | `git diff --numstat`: `manifest.js` 1/1, `manifest.js.map` 1/1, `mix-manifest.json` 19/3. El total es **+21/−5**; +19/−3 es solo `mix-manifest.json`. | Citar +21/−5 para los tres, o aclarar que +19/−3 es el manifiesto de Mix. |
| V-03 | README §4.4/§5.3: lint de 3277 archivos | El mismo `find` cuenta hoy **3278**. La diferencia es exactamente `tests/Unit/ApiDocumentSendTest.php` (FIX-028, creado a las 16:23:46); el conteo de OPS-002 es consistente con haberse tomado antes de su creación. No hay errores de sintaxis. | Decir 3278 hoy, o «3277 en el momento de OPS-002; 3278 tras añadir la prueba de FIX-028». |
| V-04 | README §1.3: 123 frente a 131 archivos de CoreFacturalo | En `HEAD` hay **123** archivos `.php` fuera de `Templates`; hoy hay **124** porque FIX-011 añadió `TempImageHelper.php`. La cifra 131 de `core.md` no se reproduce con ese criterio. | Mantener 123 en `HEAD` y explicar el 124 actual por el helper nuevo; la cifra 131 sigue sin reconciliar. |
| V-05 | `PROGRESS.md`, inventario: `tests/` = 29 archivos | En `HEAD` hay **33** archivos versionados bajo `tests/` (incluye `tests/Browser` y soporte). «29» no se reproduce con un criterio obvio. | Actualizar el inventario o indicar qué subconjunto se contó. |
| V-06 | README §1.3: no se verificó si la rama nació de `main` o `master` | Resuelto: `main` = `2064950` = `HEAD`; `master` = `0755d75` («version 1»). El `reflog` dice «Created from HEAD»; como `HEAD` era `main`, la base real es `main`. | Anotar la resolución; ya no es una incógnita. |
| V-07 | README §4.4: FIX-028 «Unit 336/2097» | Confirmado; el archivo da 9/53 y la suite completa 336/2097. Sin objeción. | — |
| V-08 | README §4.2, fila FIX-005: «`lockForUpdate` y transacción en `store`/`finalizeRent`/`addProductsToRoom`» | El código y `fixes-batch1.md` coinciden en que `addProductsToRoom` solo quedó envuelto en transacción, con `HotelRent::findOrFail($rentId)` sin `lockForUpdate`; el bloqueo está en `store` y `finalizeRent`. | Corregir la fila resumen para no atribuir `lockForUpdate` a `addProductsToRoom`. Aplicado en el README el 2026-11-18. |

También se confirman las discrepancias que el propio README ya documenta: la tabla de fases 4/5 estaba desactualizada y se corrigió después, los dos momentos de PHPUnit (846 con 1 error frente a 866 sin errores), el «Info 4» frente a 5 entradas de G2 y la exposición viva de MariaDB en `0.0.0.0:3307` pese al `127.0.0.1` por defecto del compose versionado.

## 7. Qué no se puede verificar desde el repositorio

Se aceptan como declaraciones del registro, sin evidencia local que las confirme o las refute:

- Que las revisiones independientes ocurrieran realmente y con la profundidad que se describe (no hay actas ni firmas; solo el relato y, en algunos casos, los informes de los revisores).
- Que no se hicieran peticiones reales a SUNAT, OSE o Culqi. El código y las pruebas son compatibles con esa afirmación (MockHandler, cliente SOAP falso, claves sintéticas), pero la ausencia histórica de red no se puede probar.
- El contenido de Engram (#808, #809, #875, #928, #938, #944) y sus conteos de severidad (4 críticos, 58 altos, 95 medios, 52 bajos).
- El valor real de `APP_DEBUG`, el driver de caché y el proxy inverso en producción (no se leyó `.env`).
- El comportamiento de consumidores externos (app móvil, integradores) y el flujo real de despliegue.
- Que las cifras de pruebas y lint se tomaran exactamente en los momentos que el registro indica; lo que sí se verifica es que hoy se reproducen.

## 8. Conclusión

El README no infla el estado: describe un árbol sin commitear, una auditoría sin terminar y una cobertura que no llega a escritura real. Las cifras centrales se reproducen. Las observaciones V-01 a V-06 y V-08 son erratas o matices; V-07 es una confirmación. Ninguna es una contradicción de fondo. Con esta comprobación se puede continuar sin corregir el rumbo; lo que sigue pendiente no es «verificar si el registro es cierto», sino las decisiones y pruebas que el propio registro bloquea (commit, verificación con escritura, configuración de producción, consumidores externos y despliegue).

## 9. Validación en navegador (2026-11-18)

Se abrió un Chrome headless local contra `localhost:8080` y `net.localhost:8080` mediante CDP, sin Playwright ni servicios externos. Se usaron las credenciales aportadas por el usuario (`admin@gmail.com` para el panel de sistema y `<correo-cliente-redactado>` para el tenant KeraAI). Solo se navegó y se leyeron páginas y `localStorage`; no se enviaron formularios de negocio, no se emitió, guardó, pagó ni finalizó nada. El perfil de Chrome y los scripts de navegación viven en `/tmp/fprobe`, fuera del repositorio.

### 9.1 Matriz de resultados

| Flujo | Resultado |
|---|---|
| Login de sistema (`localhost:8080/login`) | 200; entra al dashboard de FacturadorPro; marca «Panel de administración»; sin errores de consola ni de red |
| Login del tenant (`net.localhost:8080/login`) | 200; H1 «KeraAI»; entra al dashboard; sin errores |
| Dashboard del tenant | Carga con «Buenas tardes, Administrador», métricas y gráficos; sin errores |
| POS (`/pos`) | Carga productos y carrito; sin errores |
| FIX-022 (borrador del POS) | Se sembraron `amount`, `enter_amount`, `difference` y una sonda `__audit_probe`; tras recargar, las tres claves heredadas desaparecieron y la sonda se conservó: **confirmado** |
| Comprobantes (`/documents`) | 20 filas, chunk `chunk-tenant-documents-index.5e2b807dd0ee.js`, sin errores |
| Notas de venta / compras / gastos | 20 / 73 / 20 filas, cada una con su chunk, sin errores |
| `/finances/to-pay` | La página carga con su chunk y sin errores; el POST de `records` con CSRF devuelve 500 (AUD-02). La matriz no lo disparó porque la página no busca automáticamente |
| `/finances/unpaid` | La página carga, pero el listado AJAX (`/finances/unpaid/records`) devuelve 500; ver AUD-02 |
| `/reports/sales` y `/reports/purchases` | 20 y 10 filas, chunks correctos, sin errores |
| Hotel: recepción y checkout de la habitación 102 (rent 6) | Renderiza habitación, cliente, costo, pagos y el botón «Guardar y Generar Comprobante»; sin errores |
| Tienda (`/ecommerce`) | Carga con cabecera, categorías, carrito y footer; sin errores de consola |
| FIX-024 (carrito) | Se inyectó en `localStorage` un producto con `description` hostil (`<img onerror=…>`); el carrito lo mostró como texto escapado, `window.__xss` quedó indefinido y no se creó ningún `<script>`: **confirmado** en el `main.min.js`/`cart.js` servidos |
| Login de tienda (`/ecommerce/login`) | El GET devuelve 500 (AUD-01); el POST AJAX con las mismas credenciales KeraAI responde 200 `{"success":true,"message":"Login Success"}` |
| Panel de sistema: clientes y planes | Cargan; KeraAI aparece como cliente demo; sin errores |

### 9.2 Hallazgos nuevos (preexistentes en `HEAD`)

**AUD-01 — `GET /ecommerce/login` devuelve 500.**
- `EcommerceController::showLogin()` hace `view('ecommerce::user.login')`.
- El namespace `ecommerce` se carga desde `modules/Ecommerce/Resources/views` y `resources/views/modules/ecommerce`; no existe `user/login.blade.php` en ninguna de las dos.
- Sí existe `resources/views/tenant/ecommerce/user/login.blade.php`, pero queda fuera del namespace y además su formulario apunta a `route('login')` (login del personal), no al POST de la tienda.
- La cabecera de la tienda enlaza «LOGIN» a `/ecommerce/login`, así que el cliente que pulse ese enlace recibe un 500. El POST AJAX del modal sí funciona: con `<correo-cliente-redactado>` devolvió 200 `{"success":true,"message":"Login Success"}`. El problema es la página GET enlazada, no el endpoint de autenticación.
- Es preexistente: `git show HEAD:modules/Ecommerce/Http/Controllers/EcommerceController.php` ya contiene `view('ecommerce::user.login')` y en `HEAD` no hay vista en el módulo. La revisión G3 del diff heredado afirmó que el login seguía funcionando porque la ruta existía; no comprobó el renderizado del GET.
- Corrección propuesta: decidir si la página sigue en uso; si sí, mover/crear la vista en `modules/Ecommerce/Resources/views/user/login.blade.php` con el formulario correcto (`tenant_ecommerce_login_post` y el flujo AJAX), o redirigir la cabecera al modal de login de la tienda.

**AUD-02 — Finanzas: las subconsultas de pagos usan la conexión por defecto y los listados/exportaciones devuelven 500.**

- `modules/Dashboard/Helpers/DashboardView.php` (`getUnpaidFilterUser`, líneas 61 y 154) usa `DB::table('document_payments')` y `DB::table('sale_note_payments')`.
- `modules/Finance/Helpers/ToPay.php` (`getToPay`, líneas 51 y 109; `getToPayNoFilter`, líneas 221 y 244) usa `DB::table('purchase_payments')` y `DB::table('expense_payments')`.
- `app/Exports/AccountsReceivable.php` (líneas 26 y 46) usa `DB::table('document_payments')` y `DB::table('sale_note_payments')`.
- En la petición web, la conexión por defecto es `tenancy` (sistema); las tablas viven en `tenancy_net` (tenant KeraAI). El error es `Table 'tenancy.document_payments' doesn't exist` o `Table 'tenancy.purchase_payments' doesn't exist`.
- Endpoints probados con sesión y CSRF: `/finances/unpaid/records` 500; `/finances/unpaid/unpaidall` 500; `/finances/to-pay/records` 500; `/finances/to-pay/pdf` 500; `/finances/to-pay/to-pay-all` 500. `/finances/unpaid/pdf` falla primero por `user_id` ausente; con el parámetro correcto llegaría al mismo error de conexión.
- La página `/finances/unpaid` carga y su AJAX falla; `/finances/to-pay` no busca automáticamente, por eso la matriz de navegador la vio «vacía pero sin errores». La prueba directa del endpoint confirma el 500.
- Es preexistente: `git show HEAD:...DashboardView.php` ya tenía las dos subconsultas y `git show HEAD:...ToPay.php` las cuatro; FIX-010/FIX-016 no las tocaron.
- La prueba `QueryCorrectnessTest` de FIX-016 pasa porque en SQLite `DB::table()` y `DB::connection('tenant')` apuntan a la misma base en memoria; no reproduce la separación `tenancy`/`tenancy_net` de MariaDB y por eso no detectó este fallo.
- Corrección propuesta: usar `DB::connection('tenant')->table(...)` en las ocho subconsultas (2 + 4 + 2) y añadir valores por defecto (`??`) a las claves de `$request` que los helpers leen directamente (`month_start`, `month_end`, `supplier_id`, `user`/`user_id`), para que un cliente que omita una clave no reciba un 500.

```diff
-        $document_payments = DB::table('document_payments')
+        $document_payments = DB::connection('tenant')->table('document_payments')
```

  El mismo cambio aplica a `sale_note_payments`, `purchase_payments` y `expense_payments`.

**AUD-03 — Tienda sin contenido (informativo).**
- `/ecommerce` responde 200 y no lanza errores, pero no hay productos ni elementos del slider: el contenedor `.home-slider.owl-carousel` queda sin slides y conserva el fondo gris. No es un fallo de JS; la base del tenant tiene `items.apply_store = 0` para los 138 ítems y `promotions = 0`.
- El logo `http://net.localhost:8080/logo/700x300.jpg` responde 200 (es una imagen de relleno con el texto «700 x 300»), no un 404.

### 9.3 Lo que no se validó en navegador

- Envío real de pagos (FIX-021/FIX-025): habría que emitir/guardar y se evitó por la regla de no escribir datos de negocio.
- Reintento solo-finalizar del checkout de Hotel (FIX-023): exige emitir primero.
- Aislamiento del POS entre dos usuarios: solo hay una sesión de tenant disponible.
- Login de cliente de tienda: el GET está roto (AUD-01); el POST AJAX sí se probó con curl y devolvió `success:true`.
- Cualquier flujo de emisión, anulación, compra o inventario que escriba.

## 10. Comandos ejecutados

```bash
git rev-parse --short HEAD
git branch -a
git reflog show chore/system-audit --date=iso
git log --oneline main..chore/system-audit
git status --short
git diff --stat | tail -1
git diff --name-status
git diff --numstat -- public/js public/mix-manifest.json
git ls-files --others --exclude-standard

docker exec facturador_fpm sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite Unit'
for f in tests/Unit/*Test.php; do php vendor/bin/phpunit "$f" --do-not-cache-result; done
docker exec facturador_fpm sh -c 'cd /var/www/html && php tests/ui/chart-month-boundaries.php'

TMPDIR=<dir> npm run test:dashboard
for f in tests/ui/*.test.js; do node "$f"; done

# HTTP real de solo lectura
curl -s -o /dev/null -w '%{http_code}' -X POST http://net.localhost:8080/api/documents/status
curl -s -o /dev/null -w '%{http_code}' http://net.localhost:8080/api/sendserver/999999999
curl -s -o /dev/null -w '%{http_code}' -X POST http://net.localhost:8080/api/documents
curl -s -o /dev/null -w '%{http_code}' -X POST http://net.localhost:8080/api/services/validate_cpe
curl -s -o /dev/null -w '%{http_code}' http://net.localhost:8080/login
curl -s -o /dev/null -w '%{http_code}' http://net.localhost:8080/dashboard

# Navegador local (solo para esta validación; sin Playwright)
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --no-sandbox --disable-gpu \
  --remote-debugging-port=9333 --user-data-dir=/tmp/fprobe/chrome-profile about:blank
# cliente CDP mínimo en /tmp/fprobe (no forma parte del repositorio)
```

Chrome requirió `--no-sandbox` porque el sandbox del entorno deniega su inicialización; se usó un perfil temporal y se cerró al terminar. Los scripts CDP y las capturas están en `/tmp/fprobe`, no en el proyecto.

## 11. Validación integral por capas (2026-11-18)

Se validó el sistema contra el registro en cuatro capas (frontend, backend, base de datos y consistencia documental). La validación fue de solo lectura: sesiones con las credenciales aportadas, consultas SQL de lectura y ninguna escritura de negocio. Los detalles de navegador están en la sección 9; esta sección consolida el veredicto por capa.

### 11.1 Veredicto

| Capa | Veredicto | Evidencia |
|---|---|---|
| Frontend | **PASA** con dos excepciones | 185 chunks del manifiesto presentes; 8 chunks nuevos con mapas; `main.min.js` 13622→14048 con prefijo/sufijo idénticos; login KeraAI y admin; 16 rutas de navegador sin errores salvo AUD-01/AUD-02; FIX-022 y FIX-024 confirmados en ejecución |
| Backend | **PASA** en las correcciones auditadas; **FALLA** en un defecto preexistente | 28 FIX con marcadores de código localizados; 336/2097 en Unit; 12 archivos PHPUnit y 7 Node en verde; rutas 401/404/302 correctas; AUD-01/AUD-02 preexistentes |
| Base de datos | **PASA** en mapeo, esquema e integridad; confirma el defecto de conexión | `tenancy` (16 tablas) y `tenancy_net` (172); hostname `net.localhost` → `tenancy_net`; 809 comprobantes; sin duplicados; sin índices únicos de negocio; `document_payments` solo en el tenant |
| Documentación | **PASA** con ocho correcciones menores | Cifras centrales reproducidas; V-01 a V-08 aplicadas; enlaces y tablas sin errores |
| Global | **NO publicable** | P0-01 a P0-05 siguen abiertas; ningún flujo N6 |

### 11.2 Frontend

| Comprobación | Resultado |
|---|---|
| Manifiesto y chunks | 185 chunks en `manifest.js`; 0 ausentes; los 185 `id` de `mix-manifest.json` coinciden con el prefijo MD5 del archivo |
| Chunks nuevos | 8 `.js` + 8 `.map`: purchases-index, pos-index, documents-index, sale-notes-index, expenses-index, finance-to-pay-index, finance-unpaid-index y hotel-rent-checkout |
| Marcadores | `pos_draft` en el chunk del POS; `pendingFinalizationDocumentId` (6 apariciones) en el checkout; `clickSubmit` y el comentario «Synchronous re-entry guard» en los cuatro chunks de pagos |
| `main.min.js` | 13622 → 14048 bytes; prefijo (10852) y sufijo (892) idénticos a `HEAD`; `cartImageUrl`, `buildCartProduct` y `cartImage` presentes |
| Login admin | `admin@gmail.com` / `123456` → `/dashboard`; H1 «Dashboard»; sin errores |
| Login tenant | `<correo-cliente-redactado>` / `123456` → `/dashboard`; H1 «KeraAI» antes de entrar; sin errores |
| Páginas del tenant | dashboard, POS, documents (20 filas), documents/create, sale-notes (20), purchases (73), expenses (20), reports/sales (20), hotel reception y checkout (rent 6) — todas 200 y sin errores |
| Páginas del sistema | dashboard, clients (1 fila), plans y configurations — todas 200 y sin errores |
| Tienda | `/ecommerce` 200; sin errores; sin productos ni slider por datos (`apply_store=0`, `promotions=0`) |
| FIX-022 | Claves genéricas `amount`/`enter_amount`/`difference` eliminadas al recargar; sonda ajena conservada |
| FIX-024 | `description` hostil escapada en el carrito; `window.__xss` indefinido; 0 `<script>` inyectados |
| Excepciones | `GET /ecommerce/login` 500 (AUD-01); `/finances/unpaid/records` y `/finances/to-pay/records` 500 (AUD-02) |

### 11.3 Backend

| Bloque | Comprobación | Resultado |
|---|---|---|
| FIX-001 | Fallback Guzzle, polyfills, `MockHandler` | PASA |
| FIX-002 | Padron con `web/auth/locked.tenant`, validación de RUC y timeouts | PASA |
| FIX-003 | Dashboard `without`/`optional` | PASA |
| FIX-004 | ServiceData valida tipo/número, timeout y captura | PASA (TLS sigue desactivado, ya declarado) |
| FIX-005 | `lockForUpdate` en `store`/`finalizeRent`; transacción en `addProductsToRoom` | PASA; corregida la fila resumen (V-08) |
| FIX-006 | Listado AJAX, `FileRequest`, nombres UUID | PASA |
| FIX-007 | Validación de `type`/`month`, `deleteFileAfterSend`, `abort(422)` | PASA |
| FIX-008 | `required_if:is_client,true` | PASA; dos espacios finales en el archivo (limpieza P3) |
| FIX-009 | Ruta `tenant_ecommerce_login_post`, throttle, `uploadFile` con validación `image` y `mimes`, y `type` | PASA |
| FIX-010 | `getUnpaid` eliminado; archivos muertos ausentes; `/levelaccess` protegido | PASA |
| FIX-011 | `TempImageHelper` con caché 7200 s y propiedad | PASA |
| FIX-012 | `hasCdr`, `isValidCode`, `LIBXML_NONET` | PASA |
| FIX-013 | `PaymentFileHelper` con propósito, extensión, `nosniff` | PASA |
| FIX-014 | `Authenticate` 403 para `client`, login móvil, `api_token = null` | PASA en código/prueba; sin usuario client real para probar en vivo |
| FIX-015 | Rutas reseller con `web/auth:admin`, `LockedAdmin` excluido, `locked_admin` booleano | PASA |
| FIX-016 | Búsqueda agrupada, ToPay/Unpaid, Hotel, tarifa | PASA en SQLite; **no cubre** la conexión real (AUD-02) |
| FIX-017 | `$hidden`, selectores `id/name`, token en Blade de pedidos | PASA |
| FIX-018 | Transiciones 09/11 y bloqueos en notas/pedidos | PASA |
| FIX-019 | Tres intentos, regularización atómica, orden de eventos | PASA |
| FIX-020 | `ClientInterface` opcional y pruebas del fallback | PASA |
| FIX-021 | Guard en los cuatro `payments.vue` y prueba Node | PASA en código/prueba; no se envió un pago real |
| FIX-022 | Helper `pos_draft:{host}:{userId}` y prueba Node | PASA en código y navegador |
| FIX-023 | `pendingFinalizationDocumentId` y reintento solo-finalizar | PASA en código/prueba; no se emitió |
| FIX-024 | Carrito con nodos/texto y `main.min.js` parcheado | PASA en código, prueba y navegador |
| FIX-025 | Loader de efectivo con `success === true` y guard | PASA en código/prueba; no se pagó |
| FIX-026 | `isUserFacing` y enmascarado selectivo | PASA |
| FIX-027 | `documents/status` y `sendserver` dentro de `auth:api`/`locked.tenant` | PASA |
| FIX-028 | Prueba de contrato de `send` | PASA; sin revisión independiente |

Pruebas: suite Unit **336/336, 2097 aserciones, 2 deprecaciones**; los 13 archivos dan los conteos registrados; las 7 pruebas Node, `npm run test:dashboard` y `chart-month-boundaries.php` pasan.

Rutas y middleware: `POST /api/documents/status` 401, `GET /api/sendserver/999999999` 401, `POST /api/documents` 401, `POST /api/services/validate_cpe` 404; `/padron/consulta/...` y `/levelaccess` redirigen a login; `POST /api/reseller/detail` redirige a login; `php artisan route:list` falla sin tenant (`Database connection [tenant] not configured`), como estaba registrado.

### 11.4 Base de datos

| Comprobación | Resultado |
|---|---|
| Bases | `tenancy` (sistema, 16 tablas) y `tenancy_net` (tenant KeraAI, 172 tablas) |
| Mapeo | `hostnames`: `net.localhost`; `websites`: `tenancy_net`; `companies`: KeraAI / 10767283747 |
| Usuarios | Tenant: `Administrador` (admin) y `junior` (seller), sin usuarios `client`; sistema: 1 usuario (`Admin Instrador`, sin columna `type`); `tenancy.clients`: 1 (KeraAI) |
| Volumen | 809 documentos, 150 notas, 157 compras, 74 gastos, 138 ítems, 107 personas, 816 `document_payments`, 147 `sale_note_payments` |
| Estados | Documentos: 7 en `01`, 769 en `05`, 11 en `07`, 22 en `11` |
| Tienda | 0 ítems con `apply_store=1` y 0 promociones (AUD-03) |
| Índices | `documents`: `external_id` no único; sin compuesto único `(soap_type_id, document_type_id, series, number)`; `item_warehouse` sin único `(item_id, warehouse_id)`; `sale_notes`/`purchases` sin único de serie/número |
| Duplicados | 0 grupos duplicados en documentos, `external_id`, notas, compras y `item_warehouse` |
| Stock | `item_warehouse.stock`: mínimo 0, máximo 164; sin negativos |
| Defecto confirmado | `tenancy.document_payments` no existe; `tenancy_net.document_payments` tiene 816 filas. La consulta de finanzas usa la conexión por defecto y falla (AUD-02) |

### 11.5 Documento contra sistema

| Afirmación | Resultado |
|---|---|
| 98 modificados / 5 eliminados / 46 sin seguimiento | PASA; 33 app, 46 modules, 6 resources, 6 public, 2 routes, 1 tests, 1 docker, 1 config, 2 composer |
| 28 FIX y 2 OPS | PASA; 20 encabezados en `AUDIT_LOG` + 8 en `fixes-batch1` |
| 209 hallazgos | PASA; 24+17+37+28+20+27+31+25 |
| 336/2097 y 12+7 pruebas | PASA |
| Manifiesto/chunks y `main.min.js` | PASA |
| Observaciones V-01..V-08 | V-01..V-06 y V-08 corregidas; V-07 confirmada |
| Enlaces y tablas Markdown | 0 enlaces rotos; 0 tablas descuadradas |

Observaciones documentales de esta validación: V-01 (Dusk), V-02 (+21/−5), V-03 (3278), V-04 (123/124), V-05 (33 tests), V-06 (base `main`), V-07 (confirmación FIX-028) y V-08 (FIX-005/`addProductsToRoom`). Además se corrigieron el conteo de `tests/` y la fase 6 en `PROGRESS.md`, el conteo de documentos en el apéndice A y los hashes exactos de rollback en §10.6.

### 11.6 Hallazgos de esta validación

- **AUD-01:** `GET /ecommerce/login` 500 por vista `ecommerce::user.login` ausente; el POST AJAX funciona. Preexistente.
- **AUD-02:** ocho subconsultas de pagos usan la conexión por defecto y rompen `/finances/unpaid/records`, `/finances/unpaid/unpaidall`, `/finances/to-pay/records`, `/finances/to-pay/pdf` y `/finances/to-pay/to-pay-all`. Preexistente; el test de FIX-016 no lo detecta porque SQLite usa una sola conexión.
- **AUD-03:** tienda sin productos/slider por datos (`apply_store=0`, `promotions=0`), no por JS.
- **V-08:** la fila resumen de FIX-005 atribuía `lockForUpdate` a `addProductsToRoom`; el código y el registro detallado solo tienen transacción. Corregido.
- **P3:** `OfflineConfigurationRequest.php` tiene dos líneas con espacios finales (`git diff --check`); no afecta la ejecución.

### 11.7 Lo que sigue sin verificarse

- Escrituras reales de emisión, pago, anulación, compra, gasto o inventario (N6).
- Envío real a SUNAT/OSE/Culqi.
- `APP_DEBUG=false` y caché de producción.
- Consumidores externos y tokens de producción.
- Aislamiento del POS con dos usuarios y reintento de Hotel con una emisión real.
