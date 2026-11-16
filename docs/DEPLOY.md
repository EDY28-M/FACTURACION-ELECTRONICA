# Despliegue en servidor

Despliegue con un solo comando sobre un servidor Linux con Docker. El script `deploy.sh`
es idempotente: sirve tanto para la primera instalacion como para las actualizaciones.

## Requisitos

- VPS Linux (Ubuntu/Debian recomendado) con **2 vCPU y 4 GB de RAM** como minimo.
- Docker Engine y Docker Compose v2 (`docker compose version`).
- `git` y `openssl`.
- Un dominio propio con acceso a su DNS.

## DNS

| Registro | Nombre | Destino |
|----------|--------|---------|
| A | `facturador.midominio.com` | IP publica del servidor |
| A | `*.facturador.midominio.com` | IP publica del servidor (cada cliente usa un subdominio) |

El dominio principal debe coincidir con `APP_URL_BASE`: las rutas del panel del sistema
(login de administrador) solo responden en ese dominio.

## Instalacion

```sh
git clone <url-del-repositorio> facturador && cd facturador
APP_URL_BASE=facturador.midominio.com DEPLOY_HTTPS=true ./deploy.sh
```

Si no defines `APP_URL_BASE`, el script lo pregunta. El script:

1. Verifica Docker y Compose v2.
2. Crea `.env` desde `.env.example` (nunca sobrescribe uno existente), generando `APP_KEY` y la
   contrasena de MariaDB. MariaDB queda publicada solo en `127.0.0.1`.
3. Levanta `mariadb`, `fpm`, `web` (nginx) y `scheduler`.
4. Ejecuta `composer install --no-dev`, ajusta permisos y crea `public/storage`.
5. Aplica `migrate --force`, importa el catalogo SUNAT de codigos de producto (solo si la tabla
   esta vacia) y ejecuta `tenancy:migrate --force`.
6. Crea el primer administrador del sistema si no existe ninguno (`ADMIN_EMAIL` / `ADMIN_PASSWORD`,
   o `admin@<dominio>` con contrasena generada). La contrasena se muestra **una sola vez** al final.
7. Compila el JavaScript en un contenedor Node 24 (`npm run production:js`) y comprueba que
   `public/mix-manifest.json` conserve `app.css`, `auth.css` y `pos.css`.
8. Imprime un resumen con el estado de los servicios y `php artisan schedule:list`.

> **No lo corras en tu entorno local.** El script instala dependencias sin paquetes de desarrollo,
> cambia propietarios de `storage/` y recompila assets. Por seguridad se niega a ejecutarse si
> `APP_ENV` en `.env` no es `production`; `--allow-non-production` anula la proteccion.
> Si falta `.env` pero ya existe el volumen de la base de datos, tambien se niega (generaria
> claves nuevas que dejarian la base inaccesible): restaura tu `.env` original.

Los archivos de `storage/` y `bootstrap/cache` pertenecen a `www-data` (uid 82), usuario de PHP-FPM y del
scheduler. La contrasena del administrador se pasa al contenedor por variable de entorno y solo se
imprime si fue generada.

Opciones: `--allow-non-production`, `--skip-build` (omite el JS), `--force-build` (recompila siempre), `--pull`
(`git pull --ff-only` antes de desplegar; por defecto no se hace `pull`).

> No ejecutes `npm run dev`, `development`, `prod` ni `production`: regeneran el CSS y rompen el
> manifiesto. Para JavaScript usa solo `npm run production:js`.

## Variables de `.env` relevantes

| Variable | Descripcion |
|----------|-------------|
| `APP_URL_BASE` | Dominio real del sistema (obligatorio en produccion). |
| `APP_URL`, `FORCE_HTTPS` | Esquema `https` y `FORCE_HTTPS=true` cuando hay SSL. |
| `DB_PASSWORD` | Contrasena de MariaDB (usuario `root`; se necesita para crear bases por cliente). |
| `MAIL_*` | Servidor SMTP para correos del sistema. |
| `WEB_PORT`, `WEB_BIND` | Puerto y direccion de nginx (defecto `8080` / `0.0.0.0`; con `DEPLOY_HTTPS=true` el script fija `127.0.0.1`). |
| `DB_HOST_PORT`, `DB_BIND` | Puerto y direccion de MariaDB (defecto `3307` / `127.0.0.1`). |
| `CONTAINER_PREFIX`, `COMPOSE_PROJECT_NAME` | Para ejecutar una segunda instancia aislada. |

Tras editar `.env`: `docker compose up -d` (y `docker compose restart scheduler`).
No se usa `config:cache`: `routes/web.php` lee `env('APP_URL_BASE')` directamente.

## Tareas programadas

El servicio `scheduler` ejecuta `php artisan schedule:work` y comparte el directorio del
proyecto con `fpm` (el candado `withoutOverlapping` usa cache en archivos). Programa:

- `tenancy:run tenant:run`: cada minuto.
- `contracts:bill-recurrent`: todos los dias a las 06:00 (hora de Lima), facturacion recurrente
  de contratos en modo automatico.

Comprobar: `docker compose exec fpm php artisan schedule:list` y `docker compose logs -f scheduler`.

## Firewall

Docker publica puertos saltandose `ufw`/`iptables` del host. Por eso MariaDB se publica solo en
`127.0.0.1` y, con HTTPS, nginx tambien (`WEB_BIND=127.0.0.1`): expone solo el proxy inverso (80/443).
No cambies `DB_BIND` a `0.0.0.0` en un servidor.

## SSL

`web` escucha en HTTP (`WEB_PORT`). Para HTTPS, coloca un proxy inverso delante y publica nginx solo
en local (`WEB_BIND=127.0.0.1`, `WEB_PORT=8080`):

- **Caddy**: la opcion mas simple. Un certificado comodin (`*.dominio`) requiere el desafio DNS
  mediante el plugin de tu proveedor de DNS; `reverse_proxy 127.0.0.1:8080`.
- **Traefik**: igual, con `dnsChallenge` en el resolvedor de certificados.
- **Certbot**: `certbot certonly --dns-<proveedor> -d dominio -d "*.dominio"` y un nginx del host que
  haga `proxy_pass http://127.0.0.1:8080` conservando la cabecera `Host`.

El comodin exige desafio DNS-01. Conserva siempre la cabecera `Host` original: el sistema
identifica al cliente por su subdominio. Con SSL activo define `DEPLOY_HTTPS=true` (o ajusta
`APP_URL` y `FORCE_HTTPS=true`).

## Respaldos

```sh
# Base de datos (sistema y clientes)
docker compose exec -T mariadb sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --all-databases --single-transaction' | gzip > backup-$(date +%F).sql.gz

# Archivos subidos, certificados y datos de clientes
tar czf storage-$(date +%F).tar.gz storage/ .env
```

Guarda las copias fuera del servidor. `.env` contiene `APP_KEY`: sin ella los datos cifrados no se recuperan.

## Actualizar

```sh
./deploy.sh --pull          # restaura public/mix-manifest.json, hace git pull --ff-only y despliega
# o manualmente:
git checkout -- public/mix-manifest.json && git pull && ./deploy.sh
```

La compilacion JS reescribe `public/mix-manifest.json` (archivo versionado); por eso se restaura antes
del `pull` para evitar conflictos.

Aplica dependencias, migraciones del sistema y de todos los clientes, y recompila el JS solo si
cambio. Es seguro repetirlo.

## Comandos artisan

```sh
docker compose exec fpm php artisan <comando>
# alias opcional (nombre de contenedor por defecto: facturador)
alias artisan='docker exec -it facturador_fpm php artisan'
```
