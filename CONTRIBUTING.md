# Contributing to Wayfinder

Thank you for improving Wayfinder. Contributions may target trip planning,
WebMCP tools, the AI place assistant, the map experience, documentation, tests,
or the modular Laravel foundation.

## Before you start

Requirements are defined by the root manifests:

- PHP `^8.4` and Composer dependencies in `composer.json`
- Frontend dependencies and commands in `package.json`

Wayfinder is Vue-only. Application pages live in `resources/js/`; module-owned
pages and components live under each module's `resources/js/` directory.

Install dependencies and prepare the application:

```bash
cp .env.example .env
# Add OPENAI_API_KEY to .env
composer setup
```

Docker users may instead run:

```bash
docker compose up -d
docker compose exec app composer setup
```

## Development

```bash
composer dev  # app, queue, logs, and Vite
npm run dev   # Vite only
npm run build # production client and SSR builds
```

The Docker development app is available at `https://localhost`. The local
certificate may require one browser confirmation.

## Modules

Wayfinder is built on the Saucebase modular starter kit. Modules are installed
under lowercase `modules/<name>/` directories and are active when installed.
Create an application module from the maintained recipe with:

```bash
php artisan saucebase:recipe example
composer dump-autoload
npm run build
```

Module directories are lowercase, while PHP namespaces are TitleCase. Main
module providers extend `App\Providers\ModuleServiceProvider`; `$name` and
`$nameLower` properties are obsolete because the base provider resolves the
module name through InterNACHI's `ModuleRegistry`.

Do not bypass `module-loader.js`; it discovers module assets, translations, and
Playwright projects.

## Tests and quality checks

Run the smallest relevant checks while developing, then broaden coverage before
submitting:

```bash
php artisan test --compact
php -d memory_limit=2048M artisan test --testsuite=Modules
composer analyse
vendor/bin/pint --dirty --format agent
npm run lint
npm run format:check
npm run test:e2e
npm run build
```

User-facing E2E tests must select stable `data-testid` attributes, not translated
text. The E2E database setup must never use `migrate:fresh` because it shares the
development database.

To exercise WebMCP manually, use ChatGPT's in-app browser or enable
`chrome://flags/#enable-webmcp-testing` in Chrome 149+ and relaunch the browser.

## Documentation

Documentation changes must be checked against implementation and manifests:

- `README.md` owns the public overview, installation instructions, architecture,
  and WebMCP tool inventory.
- `docs/HACKATHON_SUBMISSION.md` owns the Devpost copy, video script, demo
  prompts, and submission checklist.
- `CONTRIBUTING.md` owns contributor setup and verification workflows.
- `.ai/rules/` owns shared project conventions.
- `AGENTS.md` and `CLAUDE.md` contain generated Laravel Boost instructions.

Avoid repeating version tables across these files. When a dependency changes,
update its manifest first and adjust only documentation that describes the
affected supported version line.

Change source rules in `.ai/`, then regenerate the Boost outputs:

```bash
composer boost:update
```

Never edit the generated `<laravel-boost-guidelines>` blocks in `AGENTS.md` or
`CLAUDE.md` directly because regeneration overwrites them.

## Commits and pull requests

Use a lowercase, single-line Conventional Commit:

```text
type(scope): subject
```

The scope is optional. Allowed types are `feat`, `fix`, `docs`, `style`,
`refactor`, `perf`, `test`, `chore`, `ci`, `build`, and `revert`. Keep the
header at or below 150 characters and do not add a body or footer. See
`.github/COMMIT_CONVENTION.md` for examples.

Pull requests should explain the problem and solution, identify affected modules,
and list the checks run. Include screenshots for visible UI changes and keep
unrelated changes out of the branch.
