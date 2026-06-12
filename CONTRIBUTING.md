# Contributing to OpenSparrow

First of all, thank you for considering contributing to OpenSparrow!
We welcome contributions of all kinds — bug reports, feature suggestions, documentation improvements, and code.

---

## Getting Started

### 1. Fork the repository
Click the **Fork** button on GitHub and clone your fork:

    git clone https://github.com/YOUR-USERNAME/open-sparrow.git
    cd open-sparrow

### 2. Requirements

- PHP >= 8.1  
- PostgreSQL >= 14  
- Web server (Apache/Nginx)

### 3. Run the project locally

**Option A — Docker (recommended):**

    docker compose up -d --build

Open http://localhost:8080 — on first run you are redirected to the setup wizard.

**Option B — your own web server:**

- Place the project in your server directory  
- Open http://localhost/open-sparrow/ in a browser  

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

### PHP

- **PSR-12**, enforced via `phpcs.xml` — check with `php phpcs.phar --standard=phpcs.xml`, auto-fix with `php phpcbf.phar`  
- Use meaningful variable and function names  
- Keep functions small and focused  

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
