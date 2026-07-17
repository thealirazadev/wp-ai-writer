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

### Integration tests (PHPUnit, REST dispatch)

- `POST /aiwr/v1/generate` through `rest_do_request`: 401/403 matrix (logged out, subscriber,
  bad nonce), 409 unconfigured, 400 invalid input per action, 429 rate limited, 403 budget
  exhausted, 200 happy path per action with mocked provider — asserting response shape, log row,
  and usage counter side effects.
- `alt_text` attachment validation: missing file, wrong mime, oversize -> 422, no budget change.
- Uninstall routine removes table, options, transients.

### JS unit tests (Jest via wp-scripts)

- `api/client.js`: SSE frame parsing (split chunks, multi-event chunks, comment lines), `done` /
  `error` terminal handling, Content-Type branching, single retry with `stream: false`.
- `utils/blocks.js`: missing-alt image scan against block fixtures; selected-block text
  extraction; html-to-blocks conversion delegates to `rawHandler`.

### Manual QA (per phase checklist in docs/phases.md)

- Real streaming behavior in `wp-env` (and once behind a buffering proxy to prove the fallback),
  editor insertion/undo behavior, accessibility passes (keyboard-only run, screen reader
  announcements), and one live-provider smoke test per provider-touching phase.

Not covered in v1: browser e2e automation (Playwright) — manual QA covers the editor flows; add
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

## The rule

After creating or editing any file, run the build and the relevant test/lint commands and fix all
errors and warnings before reporting the task done. A phase is not complete until
`composer run test`, `composer run lint`, `npm run lint:js`, and `npm run test:unit` all pass and
`npm run build` exits 0.
