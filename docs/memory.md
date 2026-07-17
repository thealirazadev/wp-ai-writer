# Memory: wp-ai-writer

Update this file after every meaningful chunk of work: what is done, what is in progress, and
every non-obvious decision with its reason.

## Completed

- Planning docs written (PRD, architecture, rules, phases, design, testing, api-contracts,
  launch-checklist).
- Toolchain installed and pinned: @wordpress/scripts 33.0.0, @wordpress/env 11.11.0 (package-lock
  committed); composer dev deps squizlabs/php_codesniffer, wp-coding-standards/wpcs 3.x,
  phpunit/phpunit 9.6, yoast/phpunit-polyfills (composer.lock committed).
- Phase 1 complete: bootstrap + requirements check; versioned log-table migration; aiwr_log
  structured logger; settings page (masked key, model, budget, prices) with usage panel; provider
  client (non-streaming request + timeout/error mapping, neutral response shape); generate route
  with capability/nonce/validation; per-user rate limit; monthly budget; log recording + usage
  counter with recompute-from-log. PHPUnit tests written for limits and the REST route (provider
  mocked via pre_http_request). phpcs clean, all PHP `php -l` clean.

## In progress

- Phase 2 next: sidebar, draft action, streaming relay with fallback.

## Decisions log

- Custom table (not CPT) for the activity log: budget enforcement needs fast monthly SUMs and the
  log screen needs indexed pagination; post meta/CPT is the wrong shape for aggregates.
- Streaming uses direct cURL inside `class-aiwr-provider.php` only: `wp_remote_*` buffers whole
  responses and cannot relay SSE. Documented as the single sanctioned exception in rules.md.
- Settings/log are server-rendered admin screens, not REST: smaller auth surface, boring and
  correct; the only REST route is `POST /aiwr/v1/generate`.
- Provider endpoint is a constant (overridable in wp-config), never an admin setting: an
  admin-editable URL receiving the API key is an SSRF hazard.
- No prompt/response bodies stored in the log: privacy over debuggability; metadata is enough for
  budget and audit purposes.
- Plain JSX instead of TypeScript for the sidebar: one small app, stock wp-scripts defaults; the
  TS rationale from wp-blocks-starter (typed block-attribute API) does not apply here.
- Default AIWR_PROVIDER_ENDPOINT is the reserved `https://api.example.com/v1/generate`. The
  no-vendor rule forbids shipping a real provider host, and the endpoint is a constant (not an admin
  setting) for SSRF safety, so a real deployment must define AIWR_PROVIDER_ENDPOINT in wp-config.php.
  Automated tests mock the HTTP layer, so the placeholder never causes a real call.
- Neutral provider wire shape (confined to class-aiwr-provider.php): request body
  `{model, max_tokens, messages, stream}` with a `Bearer` auth header; non-streaming response
  `{content, usage:{input_tokens,output_tokens}}` where `content` is a string or an array of
  `{text}` blocks. Chosen to stay provider-independent; the mock provider in tests uses this shape.
- ENVIRONMENT FALLBACK (wp-env blocked): the host has Docker (server 29.1.3) but no `docker compose`
  v2 plugin and no `docker-compose` v1, and no MySQL/MariaDB server or client. `npx wp-env start`
  fails with "unknown shorthand flag: 'f'" because wp-env shells out to a compose command that does
  not exist. This is not a transient failure — the compose plugin is simply not installed and cannot
  be added here. Per the build brief, verification falls back to static: `composer run lint` (phpcs,
  WordPress-Extra + Docs), `php -l` on every file, plus the full JS chain (`npm run build`,
  `npm run lint:js`, `npm run test:unit`). The PHPUnit suite is written to the WP test-suite contract
  (WP_UnitTestCase, rest dispatch, pre_http_request provider mock) and will run under wp-env once a
  host with docker compose + a DB is available; it could not be executed here. Left as a documented
  manual step. A one-time live-provider smoke test (real key + real AIWR_PROVIDER_ENDPOINT) is also a
  manual step, per testing.md.
