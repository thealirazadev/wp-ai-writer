# Testing: wp-ai-writer

## Strategy

The rule for every layer: no real provider calls in any automated test. PHP tests mock HTTP via
the `pre_http_request` filter; JS tests feed the SSE parser recorded frames. The live provider is
touched only in manual QA with the dev key.

### Unit tests (PHPUnit + WP test suite)

- `AIWR_Prompts`: each action builds the expected messages array; truncation caps applied;
  tone/length presets alter the built prompt.
- `AIWR_Limits`: rate-limit window counts, resets after 60s, is per-user; budget check against the
  usage counter; counter update math; month rollover and recompute-from-log path.
- `AIWR_Provider::request()`: maps timeouts, non-2xx, and malformed JSON to the correct
  `WP_Error` codes (`aiwr_provider_error`, `aiwr_provider_timeout`); never returns raw provider
  bodies. `can_stream()` condition branches.
- `AIWR_Log`: row insert shape, prepared queries, monthly SUM query, migration creates the table
  and sets `aiwr_db_version`.
- Settings sanitization: masked-key resubmit keeps the key, blank clears it, invalid budget/prices
  rejected.
- Stream relay: `AIWR_Rest::sanitize_stream_chunk()` keeps tags and entities split across
  provider fragments intact and still strips disallowed markup.
- Log prune: the scheduled prune never deletes current-month rows the budget is computed from.

### Integration tests (PHPUnit, REST dispatch)

- `POST /aiwr/v1/generate` through `rest_do_request`: 401/403 matrix (logged out, subscriber,
  bad nonce), 409 unconfigured, 400 invalid input per action, 429 rate limited, 403 budget
  exhausted, 200 happy path per action with mocked provider - asserting response shape, log row,
  and usage counter side effects.
- `alt_text` attachment validation: missing file, wrong mime, oversize -> 422, no budget change.
- Uninstall routine removes table, options, transients.

### JS unit tests (Jest via wp-scripts)

- `api/client.js`: SSE frame parsing (split chunks, multi-event chunks, comment lines), `done` /
  `error` terminal handling, Content-Type branching, single retry with `stream: false`.
- `utils/blocks.js`: missing-alt image scan against block fixtures; selected-block text
  extraction; html-to-blocks conversion delegates to `rawHandler`.

### Manual QA

- Real streaming behavior in `wp-env` (and once behind a buffering proxy to prove the fallback),
  editor insertion/undo behavior, accessibility passes (keyboard-only run, screen reader
  announcements), and one live-provider smoke test with a real key and `AIWR_PROVIDER_ENDPOINT`.

Checklist:

- Settings: a saved key shows only its last four characters; resubmitting the mask keeps it; a
  negative budget or retention shows an error and keeps the old value; an out-of-allowlist default
  tone is rejected.
- Route guards: logged out 401; subscriber or missing nonce 403; the 11th request in a minute 429;
  budget of 1 then a second request 403 `aiwr_budget_exhausted`; a dead `AIWR_PROVIDER_ENDPOINT`
  502 `aiwr_provider_error` with a `provider_error` log row.
- Draft: text streams into the preview; Insert places well-formed blocks and one undo removes them;
  Discard leaves the post unchanged. With streaming unavailable the same request completes as JSON.
  Killing the provider mid-stream shows a friendly error and logs an `aborted` row.
- Rewrite: the chosen tone and length change the result; Replace swaps only the selected block and
  undo restores it; with nothing or an image selected, Generate is disabled.
- SEO and excerpt: title at most 60 characters and description at most 160; copy buttons and
  "Use as post title" work; an applied excerpt persists after save; an empty post returns 400.
- Alt text: only images missing alt are listed; Apply sets the block attribute and the attachment
  meta; a deleted file or a 10 MB image is refused with no budget change; a contributor applying to
  media they cannot edit gets 403.
- SEO meta: Apply writes `_aiwr_meta_description`; the `aiwr_seo_meta_key` filter redirects or
  (with `''`) skips the write; `aiwr_seo_meta_saved` fires.
- Log: rows are correct and paginate past 20; non-admins get no menu entry. A 30-day retention
  prunes older rows when `aiwr_prune_log` runs; 0 keeps everything; deactivation unschedules it.
- Tone: draft and rewrite selects list all six presets and default to the site default; a bogus
  `options.tone` returns 400 `aiwr_invalid_input`.
- Uninstall removes the table, options, transients, and cron event; reinstall migrates cleanly.

Not covered in v1: browser e2e automation (Playwright) - manual QA covers the editor flows; add
e2e only if regressions justify it.

## Commands

```
# PHP
composer install
composer run lint        # PHPCS (WordPress-Extra + WordPress-Docs)
composer run lint:fix    # PHPCBF
composer run test        # PHPUnit against the WP test suite

# JS
npm install
npm run build            # wp-scripts build src/ -> build/
npm start                # watch mode
npm run lint:js          # ESLint
npm run test:unit        # Jest

# Environment
npx wp-env start         # local WordPress at http://localhost:8888
npx wp-env logs          # PHP error log
```

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request to `main` with two jobs:

- **PHP 8.2 lint**: `composer validate --no-check-publish`, `composer install`, `php -l` over every
  PHP file outside `vendor/`, `node_modules/`, and `build/`, then `composer run lint` (PHPCS).
- **Node 24 lint, test, build**: `npm ci`, `npm run lint:js`, `npm run test:unit`, and
  `npm run build`.

CI does not run `composer run test`. The PHPUnit suite extends `WP_UnitTestCase` and dispatches REST
requests, so it needs the WordPress core test library and a MySQL or MariaDB database; run it
locally with `wp-env`, or point `WP_TESTS_DIR` at a hand-provisioned test library as described in
`CONTRIBUTING.md`.

## The rule

After creating or editing any file, run the build and the relevant test/lint commands and fix all
errors and warnings before reporting the task done. A change is not complete until
`composer run test`, `composer run lint`, `npm run lint:js`, and `npm run test:unit` all pass and
`npm run build` exits 0.
