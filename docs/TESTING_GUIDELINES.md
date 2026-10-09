# OpenSparrow Testing Guidelines

**Version:** 4.1  
**Audience:** Developers  
**Focus:** PHPUnit unit test suite in `tests/` and manual verification of behaviour changes  

---

## I. Test policy

OpenSparrow has two verification layers, and neither is optional:

1. **PHPUnit unit tests** (`tests/`, run with `vendor/bin/phpunit`) — no database required, run on every change.
2. **Manual verification against a local instance** — every change that touches user-visible behaviour is verified by hand against a local server (`php -S localhost:8080 -t public`).

The former Cypress E2E suite (`cypress/`, `cypress.config.js`, `package.json` npm tooling) has been **removed from the repository and excluded from development**. Do not re-introduce browser-E2E tooling: the vanilla-only CI contract (`.github/workflows/vanilla-check.yml`) forbids runtime dependencies, and Cypress was never needed to run the application. `public/cypress_seed.php` remains in the tree as a legacy dev seeding endpoint (it hard-404s unless `APP_ENV=development`), but nothing in CI or the docs refers to Cypress suites anymore.

## II. PHPUnit suite

- **Configuration:** `phpunit.xml` (bootstrap, test suite directory, coverage source).
- **Dependencies:** `composer install` once (dev-only; the application itself never needs Composer).
- **No database required** — the suite is pure unit tests over the `src/` layer, the admin API guards (CSRF / demo-mode / migration registry), the language-file parity contract and the source-scanning guards.

```bash
vendor/bin/phpunit                                    # full suite
vendor/bin/phpunit --filter SomeTest                  # single test
vendor/bin/phpunit tests/Security/TableAccessTest.php # single file
```

### Writing tests

- Mirror the `src/` namespace structure under `Tests\`.
- Source-scanning guard tests (request scope inventory, front-api guards, exit-free path, cron CLI guard) pin **file paths and variable names** — when moving or renaming code, update them in the same change or they go green while checking nothing.
- Keep body arrays named `$body` and locals named `$queryParameters` — the request-scope scanner matches those names.
- New admin actions must be registered in the `$adminModules` map and `$postActions` in `public/admin/api.php`, both pinned by `tests/Admin/AdminApiGuardsTest.php`.
- New reads of request-supplied table/view/print/board/workflow names must be recorded in `tests/Security/request_scope_inventory.php`.
- Language parity: adding an i18n key means editing **all 20** `languages/*.json` files — `tests/I18n/LanguageFilesTest.php` enforces hard parity.

## III. Manual verification

1. `php -l` on touched files → 2. `phpcs` → 3. `phpstan` → 4. `phpunit`.
5. For behaviour changes: verify manually against a local instance.

Before verifying, check whether `http://localhost:8080` already responds (e.g. `Invoke-WebRequest -UseBasicParsing http://localhost:8080`); only start the server yourself (`php -S localhost:8080 -t public`, as a background process) when nothing is listening — never start a second instance on a busy port.

For plain-HTTP local work set `SECURE_COOKIES=false` in your environment, otherwise the session cookie will not stick.

Touch-gesture behaviour (board drag & drop, calendar) **cannot be verified by synthetic events** — real-device testing on the same Wi-Fi (`php -S 0.0.0.0:8080 -t public`) is mandatory before shipping changes to the touch DnD shim (`public/assets/js/util/touch-dnd.js`).

## IV. Refactor proof

Refactor-vs-regression proof uses the behaviour baseline harness: `scripts/baseline/` (`record.php` + `compare.php` against a HEAD export on another port) — see `docs/MAINTENANCE.md` § "The behaviour baseline" before large request-path refactors.

---

**Version:** 4.1

