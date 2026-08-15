# Contributing to OpenSparrow

First of all, thank you for considering contributing to OpenSparrow!
We welcome contributions of all kinds — bug reports, feature suggestions, documentation improvements, and code.

---

## Getting Started

### 1. Fork the repository
Click the **Fork** button on GitHub and clone your fork:

    git clone https://github.com/YOUR-USERNAME/OpenSparrow.git
    cd OpenSparrow

### 2. Requirements

- PHP >= 8.4 (CI runs the test suite on 8.4 and 8.5)  
- PostgreSQL >= 14  
- Web server (Apache/Nginx), or the PHP built-in server for local work

### 3. Run the project locally

**Option A — Docker (recommended):**

    docker compose up -d --build

Open http://localhost:8080 — on first run you are redirected to the setup wizard.

**Option B — your own web server or the PHP built-in server:**

The web document root is the `public/` directory — point your virtual host at it
(the shipped `nginx.conf` already does), or serve it directly:

    php -S localhost:8000 -t public

Open http://localhost:8000 in a browser. On plain HTTP set `SECURE_COOKIES=false`
first, otherwise the session cookie will not stick; add `APP_ENV=development` if you
plan to run the Cypress suite (`cypress_seed.php` 404s without it).

In both cases the **setup wizard** (`setup.php`) walks you through the database
connection and creates the `admin` account with a randomly generated password
shown once in the wizard — copy it before leaving the page.

---

## Reporting Issues

If you find a bug, please open an issue and include:

- Description of the problem  
- Steps to reproduce  
- Expected behavior  
- Screenshots (if applicable)  

Environment details:

- OS (Windows/Linux/macOS)  
- Browser (Chrome, Firefox, etc.)  
- PHP version  
- PostgreSQL version  

---

## Pull Request Process

### 1. Create a branch

Use clear naming:

- feature/your-feature-name  
- fix/bug-description  
- docs/update-docs  

### 2. Make your changes

- Keep changes focused and minimal  
- Follow existing project structure  
- Test your changes locally  

### 3. Commit messages

Use clear and descriptive messages:

- feat: add dashboard chart support  
- fix: resolve session validation issue  
- docs: add contributing guidelines  

### 4. Before submitting

- Ensure the project runs without errors  
- Test your changes manually  
- Avoid breaking existing features  
- Run the linter on modified PHP files: `php phpcs.phar --standard=phpcs.xml`  
- Run the unit tests: `composer install && vendor/bin/phpunit`  

### 5. Submit PR

- Open a Pull Request against main  
- Clearly describe what you changed and why  

---

## Code Style

We aim to keep the code clean and consistent.

### Licence headers

Every new PHP and JS source file starts with the four-line SPDX header used
throughout the tree — copy it from any existing file:

    // This file is part of OpenSparrow - https://opensparrow.org
    // SPDX-License-Identifier: LGPL-3.0-or-later
    // Copyright (C) 2024-2026 OpenSparrow Contributors
    // Licensed under LGPL v3. See COPYING.LESSER file for details.

Do not modify `COPYING`, `COPYING.LESSER` or the files in `licenses/` — the
Source Integrity workflow verifies their checksums.

### PHP

- **PSR-12**, enforced via `phpcs.xml` — check with `php phpcs.phar --standard=phpcs.xml`, auto-fix with `php phpcbf.phar`  
- Four spaces per indentation level, never tabs  
- Keep functions small and focused  

#### Variable names

Write the whole word. A variable name is documentation, and an abbreviation only
saves the author keystrokes while costing every later reader a lookup.

- **No single-letter and no shortened names.** Not `$r`, `$c`, `$sd`, `$si`,
  `$gi`, `$mi` — write `$row`, `$column`, `$subtableData`, `$subtableIndex`,
  `$galleryImage`, `$m2mIndex`. This applies to arrow functions and closures too:
  `fn($column) => pg_ident($column)`, never `fn($c) => pg_ident($c)`.
- **Name what the value actually is, not what the loop is called.** A
  `pg_query_params()` handle is `$countRes` or `$labelRes`, not `$row`; a
  `UserRole` is `$userRole`; an anonymization rule is `$rule`. Two variables in
  one scope must not be distinguishable only by length.
- **Do not leave a prefix behind when you rename.** If `$gi` becomes
  `$galleryImage`, then `$giUrl` becomes `$galleryImageUrl` in the same change.
- Established domain terms stay as they are — `$id`, `$fk`, `$m2m`, `$sql`,
  `$csrf`, `$conn`, `$cfg` are the project's vocabulary, not abbreviations to
  expand.

Longer names push lines over the 120-character limit that PSR-12 warns at. Wrap
the call — extract the shared expression into a named variable or split the
arguments one per line — rather than shortening the name back.

### JavaScript (Vanilla JS)

- Use modern ES6+ syntax where possible  
- **No external libraries or CDN resources** — the project must work fully offline (CI enforces this)  
- Keep code modular and readable  

### Formatting

CI runs PHP_CodeSniffer (PSR-12), PHPUnit, and a vanilla-code check on every PR.  
Match the existing code style in the project.  

## Contributor License Agreement

Before your PR can be merged, you must sign our [Contributor License Agreement](CLA.md). 
This is handled automatically via a bot comment in your PR. 
You only need to do this once.

---

## Contribution Tips

- Start with small issues if you're new  
- Read existing code before making changes  
- Ask questions if something is unclear  
- Be respectful and constructive in discussions  

---

## License

By contributing, you agree that your contributions will be licensed under the GNU LGPL v3 license.

---

Thanks again for helping improve OpenSparrow!
