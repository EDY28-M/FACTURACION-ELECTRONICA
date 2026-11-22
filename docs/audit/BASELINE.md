# Audit BASELINE (before fixes)

Branch: chore/system-audit. Recorded read-only; no source or DB was modified.

## Runtime
- Container `facturador_fpm`: PHP 8.3.36 (cli, NTS). Repo bind-mounted rw: host `/Volumes/Mac/juniorbardales/Downloads/sunat` -> `/var/www/html`.
- Host: Node v24.14.0. Host PHP 8.5 (not the supported runtime; not used).
- Containers up: facturador_fpm, facturador_mariadb, facturador_web.

## Lint
- `php -l` over app/, modules/, routes/, config/, database/, tests/, bootstrap/ (excluding vendor, node_modules, storage), run in the container.
- Files linted: 3268 (includes git-ignored files). Syntax errors: **0**.

## PHPUnit
- Unit suite (container): **Tests 218, Assertions 846, Errors 1, Failures 0**, PHPUnit Deprecations 2.
- Error: `Tests\Unit\PaymentsTest::test_unknown_outcomes_are_not_declines_and_keep_records_pending`
  -> `Error: Class "Culqi\Error\UnableToConnect" not found` (tests/Unit/PaymentsTest.php:203). Cause: `culqi/culqi-php` is removed from the working-tree composer.json/lock (pre-existing user change), so the vendor class is absent/unresolved.
- Also noisy (not failures): "Creation of dynamic property" deprecations and an "Undefined property FakeRecord::$reference_payment" warning from PaymentsTest/PlatformPayment on PHP 8.3.
- DB safety check: no RefreshDatabase/DatabaseMigrations/DatabaseTransactions usage (only unused imports in ExampleTest), no DB:: calls in tests/Unit. Some tests extend `Tests\TestCase` (boots the app) but do no DB work.
- Feature suite: **SKIPPED**. Its only test (`tests/Feature/ExampleTest.php`) does `GET /`, which boots the full multi-tenant stack and may touch the real system DB; cannot guarantee it is DB-free.

## Node UI tests (host)
- `npm run test:dashboard`: **PASS** (both files).
  - system-dashboard.test.js: template compile, data/empty state, chart race, shell navigation, tenant shell assertions passed. Read-only (reads resources/js/views/system/clients/index.vue, uses mocks).
  - build-js-publish.test.js: JS publish success + five I/O-failure rollback scenarios passed. Confirmed safe beforehand: it only operates on temp-dir fixtures (os.tmpdir) and `require`s scripts/build-js.js, which only builds when run as main; public/js not touched.

## Other checks
- `php tests/ui/chart-month-boundaries.php` (container): PASS ("five cases passed"). Read-only (reads ClientController.php, Carbon math).
- `php tests/ui/phase2-php83-verification.php`: **NOT RUN**. It executes `DROP DATABASE IF EXISTS` / `CREATE DATABASE` through the `system` connection (lines ~149-160), i.e. DB writes.
- `php artisan route:list` (container): **fails**: `InvalidArgumentException: Database connection [tenant] not configured.` (needs tenant context). No routes-baseline.txt produced.

## Exact commands to reproduce
```sh
cd /Volumes/Mac/juniorbardales/Downloads/sunat
# runtime
docker exec facturador_fpm php -v | head -1; node -v
# lint (prints only error lines, then total count, then error-line count)
docker exec facturador_fpm sh -c 'cd /var/www/html && find app modules routes config database tests bootstrap -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" -not -path "*/storage/*" -print0 | xargs -0 -n 50 -P 4 php -l 2>&1 | grep -v "^No syntax errors detected"; find app modules routes config database tests bootstrap -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" -not -path "*/storage/*" | wc -l'
# unit tests
docker exec facturador_fpm sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite Unit 2>&1 | tail -30'
# node UI tests
npm run test:dashboard
# read-only php check
docker exec facturador_fpm sh -c 'cd /var/www/html && php tests/ui/chart-month-boundaries.php'
git status --short
```

## Limitations
- Feature suite and phase2-php83-verification.php skipped (possible/definite DB writes).
- route:list unavailable without tenant DB config.
- Lint count (3268) comes from `find` in the container; `fd` on host reports 3246 because it honors .gitignore.
- The 1 PHPUnit error is tied to the user's pending composer change (culqi-php removed); expect it to persist or change with PaymentsTest/composer fixes.
- No Dusk/browser tests, no JS build, no composer install.
