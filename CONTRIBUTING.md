# Contributing to AI Writer

Thanks for taking the time to contribute. This is a WordPress plugin with a PHP backend and a
block-editor sidebar built in React (JSX) through `@wordpress/scripts`. The sections below cover
the local setup, the checks that must pass, and what a good pull request looks like.

## Ground rules

- Read `docs/PRD.md`, `docs/rules.md`, `docs/architecture.md`, and `docs/phases.md` before making a
  change. The PRD and architecture are the source of truth; do not change them without flagging it
  first.
- No vendor or model names anywhere (code, comments, UI strings, commits). The plugin talks to a
  neutral "LLM provider API"; the model identifier is admin-supplied configuration, never a
  hardcoded default.
- Never commit secrets. The provider key is entered on the settings page and stored server-side;
  local values live in `.env` (see `.env.example`).
- One commit per feature or task, in a working state. Commit messages are short, imperative, and
  conventional (for example, `fix: handle empty cart`). No attribution trailers, no emoji.

## Prerequisites

- PHP 8.1+ (CI lints on 8.2) with `curl`, `mbstring`, and `mysqli`.
- Composer 2.
- Node 24 and npm (the version CI uses).
- A local MySQL or MariaDB server for the PHPUnit suite.

## Setup

```bash
composer install   # PHP dev tooling: PHPCS, WPCS, PHPUnit, polyfills
npm install        # JS build, lint, and test tooling
npm run build      # compile src/ into build/ (the sidebar does not load without this)
```

Copy `.env.example` to `.env` for local development values. Set the provider endpoint in
`wp-config.php` — it is a constant, never an admin setting (an admin-editable URL that receives the
key would be an SSRF hazard):

```php
define( 'AIWR_PROVIDER_ENDPOINT', 'https://your-provider.example/v1/generate' );
```

For a full editor environment, `npx wp-env start` brings up WordPress at `http://localhost:8888`
(requires Docker and the Docker Compose v2 plugin).

## Running the checks

Every change must keep all of these green before it is called done:

```bash
composer run lint      # PHPCS: WordPress-Extra + WordPress-Docs
composer run test      # PHPUnit against the WordPress test suite
npm run lint:js        # ESLint
npm run test:unit      # Jest
npm run build          # production build
```

The provider HTTP layer is mocked in every automated test (`pre_http_request` in PHP, a stubbed
`fetch` in Jest); no test makes a real provider request. A one-time live-provider smoke test with a
real key and endpoint is a manual QA step only.

### PHPUnit: pointing at a WordPress test suite

`composer run test` (PHPUnit) needs the WordPress core test suite plus a live database. `wp-env`
provisions both, but the suite runs against any WordPress test library by setting `WP_TESTS_DIR`:

```bash
WP_TESTS_DIR=/path/to/wordpress-tests-lib composer run test
```

If you do not already have that library, provision it by hand:

1. Extract `tests/phpunit/includes` and `tests/phpunit/data` from a matching
   [wordpress-develop](https://github.com/WordPress/wordpress-develop) checkout into a directory
   (this becomes your `WP_TESTS_DIR`).
2. Copy `wp-tests-config-sample.php` to `wp-tests-config.php` in that directory and edit it:
   - `ABSPATH` points at a WordPress core checkout.
   - `DB_NAME` / `DB_USER` / `DB_PASSWORD` / `DB_HOST` point at a **disposable** MySQL/MariaDB
     database — the suite drops and recreates its tables on every run, so never aim it at a database
     you care about.
3. Run `WP_TESTS_DIR=/path/to/that/dir composer run test`.

This setup has been verified against WordPress 6.8.2 with PHPUnit 9.6. The bootstrap
(`tests/bootstrap.php`) points the suite at the Composer-installed Yoast polyfills automatically, so
no extra polyfills configuration is needed.

CI (`.github/workflows/ci.yml`) deliberately does not run `composer run test`: a plain hosted runner
has no database or WordPress test suite. CI runs the PHP lint job (composer validate, `php -l` over
every file, PHPCS) and the Node job (ESLint, Jest, build). The PHPUnit suite is a local or
WP-capable-host step.

## Coding standards

- PHP follows the WordPress Coding Standards enforced by `phpcs.xml.dist`. Run `composer run
  lint:fix` (PHPCBF) for mechanical fixes.
- JavaScript follows the `@wordpress/scripts` ESLint config (`eslint.config.js`). Run `npm run
  lint:js:fix` to autofix.
- Follow the naming rules in `docs/rules.md`: PHP classes `AIWR_Pascal_Snake` in
  `includes/class-aiwr-*.php`; functions, hooks, options, and transients prefixed `aiwr_`; the REST
  namespace is `aiwr/v1`; the text domain is `wp-ai-writer`.
- Wrap every user-facing string in the `wp-ai-writer` text domain. If your change adds or edits a
  string, regenerate the template:

  ```bash
  wp i18n make-pot . languages/wp-ai-writer.pot
  ```

## Database changes

Never modify the schema directly. Every schema change is a new migration step in
`class-aiwr-migrations.php`, keyed off `aiwr_db_version`. Applied migrations are never edited
afterward.

## Pull requests

- Keep a PR focused on one change. Small, reviewable diffs merge faster.
- Fill in `.github/PULL_REQUEST_TEMPLATE.md`; confirm PHPCS, PHPUnit, ESLint, Jest, and the build
  all pass.
- Describe the change, the reason for it, and how you verified it. Note any user-facing string
  changes so the translation template can be regenerated.
- New behavior needs a test. Bug fixes should add a test that fails before the fix.
- Security-sensitive reports should follow `SECURITY.md`, not a public PR.

By contributing you agree that your contributions are licensed under the project's GPL-2.0-or-later
license.
