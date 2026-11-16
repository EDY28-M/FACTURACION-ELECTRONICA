#!/usr/bin/env bash
#
# One-command deployment / update for the Facturador stack (Docker Compose v2).
#
#   ./deploy.sh                 install or update (idempotent, safe to re-run)
#   ./deploy.sh --skip-build    do not run the JavaScript build
#   ./deploy.sh --force-build   run the JavaScript build even if nothing changed
#   ./deploy.sh --pull          git pull --ff-only before deploying (default: no pull)
#   ./deploy.sh --no-pull       explicit no-op, kept for clarity (default behaviour)
#   ./deploy.sh --allow-non-production   run even if APP_ENV in .env is not "production" (never on a dev machine)
#
# Optional environment variables (only used when .env does not exist yet):
#   APP_URL_BASE   main domain, e.g. facturador.example.com   (asked interactively if omitted)
#   DEPLOY_HTTPS   "true" when the site is served over HTTPS (default: false)
#   ADMIN_EMAIL / ADMIN_PASSWORD   first system administrator (default: admin@<domain> / generated)
#   WEB_PORT, WEB_BIND, DB_HOST_PORT, DB_BIND, CONTAINER_PREFIX, COMPOSE_PROJECT_NAME
#                  written to .env so a second isolated stack can run on the same host
#
# An existing .env is NEVER overwritten.

set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

SKIP_BUILD=false
ALLOW_NON_PROD=false
FORCE_BUILD=false
DO_PULL=false
for arg in "$@"; do
  case "$arg" in
    --skip-build)  SKIP_BUILD=true ;;
    --force-build) FORCE_BUILD=true ;;
    --pull)        DO_PULL=true ;;
    --no-pull)     DO_PULL=false ;;
    --allow-non-production) ALLOW_NON_PROD=true ;;
    -h|--help)     sed -n '3,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Opcion desconocida: $arg (usa --help)" >&2; exit 2 ;;
  esac
done

if [ -t 1 ]; then B=$'\033[1m'; G=$'\033[32m'; Y=$'\033[33m'; R=$'\033[31m'; N=$'\033[0m'; else B=''; G=''; Y=''; R=''; N=''; fi
step() { printf '\n%s==> %s%s\n' "$B" "$*" "$N"; }
ok()   { printf '%s  OK%s %s\n' "$G" "$N" "$*"; }
warn() { printf '%s  AVISO%s %s\n' "$Y" "$N" "$*" >&2; }
die()  { printf '%s  ERROR%s %s\n' "$R" "$N" "$*" >&2; exit 1; }
trap 'printf "%s  ERROR%s el despliegue fallo en la linea %s. Revisa el mensaje anterior y vuelve a ejecutar ./deploy.sh.\n" "$R" "$N" "$LINENO" >&2' ERR

dc()  { docker compose "$@"; }
php() { dc exec -T -u www-data fpm php "$@"; }
# Runtime files must belong to www-data (uid 82), the user of php-fpm and the scheduler.
fix_perms() {
  if [ "$(uname -s)" = Linux ]; then
    dc exec -T -u root fpm sh -c 'chown -R www-data:www-data storage bootstrap/cache' || warn "No se pudo ajustar el propietario de storage"
  fi
  dc exec -T -u root fpm sh -c 'chmod -R ug+rwX storage bootstrap/cache' || warn "No se pudieron ajustar los permisos de storage"
  # .env: owner keeps read/write, www-data (group) can read; others get nothing.
  dc exec -T -u root fpm sh -c 'chgrp www-data .env && chmod 640 .env' || chmod 644 .env
}

env_get() { [ -f .env ] && grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }
env_set() { # env_set KEY VALUE  (replace or append, value passed through the environment: no escaping issues)
  local tmp; tmp="$(mktemp)"
  VAL="$2" awk -v k="$1" 'BEGIN{v=ENVIRON["VAL"]} index($0,k"=")==1 {print k"="v; f=1; next} {print} END{if(!f) print k"="v}' .env > "$tmp"
  cat "$tmp" > .env; rm -f "$tmp"
}
rand_hex() { openssl rand -hex "$1"; }

# --------------------------------------------------------------------------------------------------
step "Comprobando requisitos"
command -v docker >/dev/null 2>&1 || die "Docker no esta instalado. Instalalo desde https://docs.docker.com/engine/install/"
docker compose version >/dev/null 2>&1 || die "Se requiere Docker Compose v2 (comando 'docker compose')."
docker info >/dev/null 2>&1 || die "No se puede conectar con el demonio de Docker (permisos o servicio detenido)."
command -v openssl >/dev/null 2>&1 || die "Se requiere openssl para generar claves."
[ -f docker-compose.yml ] || die "No se encontro docker-compose.yml. Ejecuta el script desde la raiz del proyecto."
ok "docker $(docker --version | awk '{print $3}' | tr -d ,), $(docker compose version --short)"

if $DO_PULL; then
  step "Actualizando codigo (git pull --ff-only)"
  # The JS build rewrites the tracked public/mix-manifest.json; restore it so the pull cannot conflict.
  git diff --quiet -- public/mix-manifest.json || git checkout -- public/mix-manifest.json
  git pull --ff-only
fi

# --------------------------------------------------------------------------------------------------
step "Configuracion (.env)"
if [ -f .env ]; then
  ok ".env existente: no se modifica"
else
  [ -f .env.example ] || die "Falta .env.example"
  proj="$(dc config 2>/dev/null | awk '/^name:/{print $2; exit}')"
  if [ -n "$proj" ] && [ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$proj" --filter label=com.docker.compose.volume=facturador_mariadb_data)" ]; then
    die "Falta .env pero ya existe el volumen de base de datos del proyecto '$proj'. Generar nuevas claves dejaria la base de datos inaccesible. Restaura tu .env original (DB_PASSWORD y APP_KEY) o elimina el volumen a conciencia."
  fi
  DOMAIN="${APP_URL_BASE:-}"
  if [ -z "$DOMAIN" ]; then
    if [ -t 0 ]; then
      read -r -p "Dominio principal del sistema (ej. facturador.midominio.com) [localhost]: " DOMAIN
    fi
    DOMAIN="${DOMAIN:-localhost}"
  fi
  DOMAIN="$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed -e 's#^[a-z]*://##' -e 's#[/?\#].*$##' -e 's#:[0-9]*$##')"
  printf '%s' "$DOMAIN" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$' \
    || die "APP_URL_BASE invalido ('$DOMAIN'). Usa solo el nombre de dominio, ej. facturador.midominio.com"
  [ "$DOMAIN" = "localhost" ] && warn "Dominio 'localhost': solo apto para pruebas. Define APP_URL_BASE con el dominio real para produccion."
  SCHEME=http; [ "${DEPLOY_HTTPS:-false}" = "true" ] && SCHEME=https
  cp .env.example .env
  chmod 600 .env  # relaxed to 640 (group www-data) by fix_perms once the containers are up
  env_set APP_ENV production
  env_set APP_DEBUG false
  env_set APP_KEY "base64:$(openssl rand -base64 32)"
  env_set APP_URL_BASE "$DOMAIN"
  env_set APP_URL "$SCHEME://\${APP_URL_BASE}"
  env_set FORCE_HTTPS "$([ "$SCHEME" = https ] && echo true || echo false)"
  env_set DB_CONNECTION system
  env_set DB_HOST mariadb
  env_set DB_PORT 3306
  env_set DB_DATABASE tenancy
  env_set DB_USERNAME root
  env_set DB_PASSWORD "$(rand_hex 16)"
  env_set DB_BIND 127.0.0.1
  # With HTTPS a reverse proxy sits in front: keep nginx reachable only from this host.
  [ "$SCHEME" = https ] && env_set WEB_BIND 127.0.0.1
  for v in WEB_PORT WEB_BIND DB_HOST_PORT DB_BIND CONTAINER_PREFIX COMPOSE_PROJECT_NAME; do
    [ -n "${!v:-}" ] && env_set "$v" "${!v}"
  done
  ok ".env creado (APP_KEY y contrasena de base de datos generadas; MariaDB solo en 127.0.0.1)"
fi
if [ -z "$(env_get APP_KEY)" ]; then
  env_set APP_KEY "base64:$(openssl rand -base64 32)"
  ok "APP_KEY generada"
fi
APP_ENV_VALUE="$(env_get APP_ENV)"
if [ "$APP_ENV_VALUE" != production ] && ! $ALLOW_NON_PROD; then
  die "APP_ENV en .env es '${APP_ENV_VALUE:-vacio}', no 'production'. Este script es para servidores: no lo ejecutes en tu entorno local (reinstala dependencias sin dev y cambia permisos). Usa --allow-non-production solo si sabes lo que haces."
fi
DOMAIN="$(env_get APP_URL_BASE)"
[ -n "$DOMAIN" ] || warn "APP_URL_BASE esta vacio en .env: las rutas del sistema no se restringiran a un dominio."

# --------------------------------------------------------------------------------------------------
step "Levantando contenedores"
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
dc up -d --build

step "Esperando a MariaDB"
cid="$(dc ps -q mariadb)"
for _ in $(seq 1 60); do
  status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid")"
  [ "$status" = healthy ] && break
  sleep 3
done
[ "$status" = healthy ] || die "MariaDB no llego a estado 'healthy' (estado: $status). Revisa: docker compose logs mariadb"
ok "MariaDB healthy"

# --------------------------------------------------------------------------------------------------
step "Dependencias PHP (composer install --no-dev)"
dc exec -T -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_MEMORY_LIMIT=-1 fpm \
  composer install --no-dev --optimize-autoloader --no-interaction --no-progress

step "Permisos de storage y cache"
fix_perms
if [ ! -e public/storage ]; then
  ln -s ../storage/app/public public/storage
  ok "enlace public/storage creado"
else
  ok "public/storage ya existe"
fi

# --------------------------------------------------------------------------------------------------
step "Base de datos del sistema"
# The app reads env() directly in routes/web.php (APP_URL_BASE), so config:cache would break the
# system-domain guard. Only clear caches; never cache config on this tenancy setup.
php artisan config:clear >/dev/null
php artisan cache:clear >/dev/null || true
php artisan migrate --force

# shellcheck disable=SC2016 # $MYSQL_ROOT_PASSWORD must expand inside the container
codes="$(dc exec -T mariadb sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B tenancy -e "SELECT COUNT(*) FROM sunat_product_codes"' | tr -d '[:space:]')"
if [ "${codes:-0}" = "0" ]; then
  step "Importando catalogo SUNAT de codigos de producto"
  php artisan sunat:import-product-codes
else
  ok "Catalogo de codigos SUNAT ya cargado ($codes filas)"
fi

step "Migraciones de inquilinos (tenants)"
php artisan tenancy:migrate --force

step "Administrador del sistema"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@${DOMAIN:-localhost}}"
PASSWORD_GENERATED=false
if [ -z "${ADMIN_PASSWORD:-}" ]; then ADMIN_PASSWORD="$(rand_hex 8)"; PASSWORD_GENERATED=true; fi
export ADMIN_PASSWORD  # passed to the container by name (-e ADMIN_PASSWORD), never through argv
install_out="$(dc exec -T -u www-data -e ADMIN_PASSWORD fpm php artisan system:install --email="$ADMIN_EMAIL")"
unset ADMIN_PASSWORD_EXPORTED
ADMIN_CREATED=false
if printf '%s' "$install_out" | grep -q ADMIN_CREATED; then ADMIN_CREATED=true; ok "administrador creado"; else ok "ya existe un administrador del sistema"; fi

# --------------------------------------------------------------------------------------------------
build_stamp() {
  # Inputs of the build + the published manifest, so a restored/changed manifest forces a rebuild.
  { git ls-files -s resources package.json package-lock.json webpack.mix.js scripts 2>/dev/null || echo "nogit-$(date +%s)"; \
    cksum < public/mix-manifest.json 2>/dev/null || true; } | cksum | cut -d' ' -f1,2
}
verify_assets() {
  local e missing=0
  for e in /css/app.css /css/auth.css /css/pos.css /js/app.js; do
    grep -q "\"$e\"" public/mix-manifest.json || { warn "public/mix-manifest.json no contiene $e"; missing=1; }
    [ -f "public$e" ] || { warn "falta public$e"; missing=1; }
  done
  return $missing
}

step "Assets JavaScript"
mkdir -p .local
if $SKIP_BUILD; then
  warn "Omitido por --skip-build"
else
  stamp="$(build_stamp)"
  if ! $FORCE_BUILD && [ -f .local/js-build.stamp ] && [ "$(cat .local/js-build.stamp)" = "$stamp" ] && verify_assets 2>/dev/null; then
    ok "sin cambios desde la ultima compilacion (usa --force-build para recompilar)"
  else
    # Only 'production:js' is safe: it preserves the tracked CSS entries of mix-manifest.json.
    dc --profile build run --rm -T node sh -c 'npm ci --ignore-scripts --legacy-peer-deps && npm run production:js'
    build_stamp > .local/js-build.stamp
  fi
fi
verify_assets || die "Faltan assets compilados (ver avisos). Los estilos CSS vienen versionados en el repositorio: no ejecutes 'npm run dev/prod'; usa solo 'npm run production:js'."
ok "mix-manifest con app.css, auth.css y pos.css"

# --------------------------------------------------------------------------------------------------
step "Resumen"
dc ps
echo
echo "Tareas programadas (scheduler):"
php artisan schedule:list || warn "No se pudo listar el scheduler"
fix_perms  # last step after every artisan call
SCHEME_OUT="$(env_get FORCE_HTTPS)"; [ "$SCHEME_OUT" = true ] && SCHEME_OUT=https || SCHEME_OUT=http
WEBP="$(env_get WEB_PORT)"; WEBP="${WEBP:-8080}"
echo
echo "${B}URL del sistema:${N} $SCHEME_OUT://${DOMAIN:-localhost}  (puerto HTTP publicado: $WEBP)"
if $ADMIN_CREATED; then
  echo "${B}Administrador:${N}   $ADMIN_EMAIL"
  if $PASSWORD_GENERATED; then
    echo "${B}Contrasena:${N}      $ADMIN_PASSWORD   (generada; se muestra una sola vez, cambiala al ingresar)"
  else
    echo "${B}Contrasena:${N}      la definida en ADMIN_PASSWORD"
  fi
fi
cat <<'EOF'

Siguientes pasos:
  1. DNS: registro A del dominio y comodin *.dominio hacia este servidor.
  2. SSL / proxy inverso (Caddy, Traefik o Certbot): ver docs/DEPLOY.md.
  3. Revisa MAIL_* y demas variables en .env (reinicia con: docker compose up -d).
  4. Actualizar: ./deploy.sh --pull
  5. Comandos artisan: docker compose exec fpm php artisan <comando>
EOF
