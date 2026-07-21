# Architecture: wp-ai-writer

## App flow and architecture

A single WordPress plugin with three surfaces bootstrapped from one main file:

1. Admin (settings + log): `AIWR_Settings` registers a Settings API options page (key, model,
   budget, prices) with a server-rendered usage panel; `AIWR_Log_Screen` renders the activity log
   with `WP_List_Table`. Both are classic server-rendered admin screens gated on `manage_options` —
   no REST surface is exposed for them.
2. Editor (sidebar app): a React sidebar registered with `registerPlugin` + `PluginSidebar`, built
   by `@wordpress/scripts` from `src/` into `build/`. It reads editor state (selected block, post
   content, images) via `@wordpress/data` and calls the plugin's REST proxy. It writes to the post
   only through explicit confirm buttons (insert blocks, replace block content, set title/excerpt,
   set alt attribute).
3. REST proxy: one route, `POST /aiwr/v1/generate`, that validates input, enforces the rate limit
   and monthly budget, builds the provider payload, attaches the stored key, relays the response
   (streaming or JSON), and records an activity-log row. The provider is called from PHP only.

### Generate flow

```
Sidebar action (Generate clicked)
  -> src/api/client.js: fetch POST /wp-json/aiwr/v1/generate  (X-WP-Nonce header, JSON body)
  -> REST permission callback: user logged in + current_user_can('edit_posts')
     (+ current_user_can('edit_post', post_id) when post_id present); core verifies the nonce
  -> AIWR_Rest::generate()
       validate action enum + per-action input rules, sanitize all fields
       AIWR_Limits::check_rate_limit(user_id)      -> WP_Error aiwr_rate_limited (429)
       AIWR_Limits::check_budget()                 -> WP_Error aiwr_budget_exhausted (403)
       AIWR_Prompts::build(action, input, options) -> messages array + max output tokens
       if stream requested AND action supports it AND AIWR_Provider::can_stream():
           AIWR_Provider::stream(payload, emitter)   [SSE relay, below]
       else:
           AIWR_Provider::request(payload)           [wp_remote_post, timeout 60s]
       AIWR_Log::record(user, action, model, tokens, cost, status, duration)
       AIWR_Limits::add_usage(tokens)
  -> JSON result or SSE stream back to the sidebar; result previewed; editor confirms apply
```

### Streaming relay

`wp_remote_post` buffers whole responses, so the streaming path uses a direct PHP cURL handle —
the one deliberate exception to the wp_remote_* rule, isolated inside `AIWR_Provider`:

- Preconditions checked by `can_stream()`: `curl_init` exists, `headers_sent()` is false. If any
  fail, the request silently proceeds non-streaming (the fallback is a response-shape change the
  client detects, not an error).
- The route callback takes over the response: sends `Content-Type: text/event-stream`,
  `Cache-Control: no-cache`, `X-Accel-Buffering: no`, disables zlib output compression, closes all
  output buffers, then runs cURL with a `CURLOPT_WRITEFUNCTION` callback (connect timeout 15s,
  total timeout 120s).
- The callback parses the provider's SSE frames and re-emits normalized events (`delta`, `done`,
  `error` — see `docs/api-contracts.md`), calling `flush()` per chunk. Normalizing keeps the
  browser contract provider-independent and guarantees no provider headers or auth details leak.
- The provider's terminal events carry token usage; the relay captures those figures while
  streaming and records the log row after the stream closes, then `exit`s (bypassing normal REST
  serialization, which is why this route self-manages its output).
- If the stream aborts after partial output, the relay emits `event: error`, logs the row with
  status `aborted` and estimated output tokens (`strlen/4` heuristic, flagged in the row).
- Client fallback: `src/api/client.js` branches on the response `Content-Type`; on a network or
  parse failure before the first `delta`, it retries once with `stream: false`.

### Provider client

All provider specifics live in `class-aiwr-provider.php` and nowhere else: endpoint URL, request
path, auth header name, payload shape (model, messages, max tokens, stream flag), image content
encoding, and response/SSE parsing. The endpoint defaults to a constant and can be overridden by
defining `AIWR_PROVIDER_ENDPOINT` in `wp-config.php` (used in dev to point at a mock server). The
endpoint is deliberately not an admin setting: an admin-editable URL that receives the API key
would be a server-side request forgery hazard.

The alt text action loads the attachment via `get_attached_file()`, validates type (jpeg, png,
gif, webp) and size (max 5 MB), base64-encodes it into the provider's image content block, and
errors with `aiwr_image_unreadable` when the file is missing locally (offloaded media is a known
v1 limitation, noted in the PRD).

## Proposed folder / file tree

```
wp-ai-writer/
  wp-ai-writer.php                 Plugin header, constants (AIWR_VERSION, AIWR_PATH, AIWR_URL,
                                   AIWR_PROVIDER_ENDPOINT default), requires, boot AIWR_Plugin
  uninstall.php                    Drops the log table, deletes options and transients
  readme.txt                       WordPress.org-style plugin readme
  composer.json                    Dev deps + scripts (lint, lint:fix, test)
  phpcs.xml.dist                   PHPCS ruleset (WordPress-Extra + WordPress-Docs, aiwr prefix)
  phpunit.xml.dist                 PHPUnit config
  package.json                     wp-scripts build/start/lint/test scripts, security overrides
                                   for transitive dev deps (lockfile committed)
  webpack.config.js                Stock wp-scripts config with devServer.proxy in array form
  .wp-env.json                     Local WordPress environment config
  .gitignore                       /build, /node_modules, /vendor

  includes/
    class-aiwr-plugin.php          Orchestrator; instantiates the classes below, hooks init
    class-aiwr-settings.php        Settings API page: key (masked), model, budget, prices; usage panel
    class-aiwr-rest.php            Route registration, permission callbacks, validation, generate()
    class-aiwr-provider.php        Provider HTTP client: request(), stream(), can_stream()
    class-aiwr-prompts.php         Per-action prompt builders (draft, rewrite, seo, excerpt, alt_text)
    class-aiwr-limits.php          Rate limiter (transient window) + budget check + usage counter
    class-aiwr-log.php             Log table writes and queries ($wpdb->insert / prepare)
    class-aiwr-log-screen.php      WP_List_Table admin screen for the log
    class-aiwr-migrations.php      dbDelta schema, versioned via aiwr_db_version option
    aiwr-functions.php             aiwr_log() structured logger, aiwr_get_settings() accessor

  src/
    index.js                       registerPlugin + PluginSidebar registration
    sidebar.js                     Sidebar shell: action tabs, shared error notice
    actions/
      draft.js                     Prompt form -> preview -> insert blocks
      rewrite.js                   Selected-block text + tone/length -> preview -> replace content
      seo.js                       Title + meta description results, copy + apply title
      excerpt.js                   Excerpt result, apply to excerpt
      alt-text.js                  Missing-alt image list -> per-image generate/apply
    components/
      result-preview.js            Streaming/complete preview, confirm + discard buttons
    api/
      client.js                    fetch wrapper: SSE reader, JSON fallback, one retry
    utils/
      blocks.js                    Selected-block text extraction, html-to-blocks (rawHandler),
                                   image-blocks-missing-alt scan
    editor.scss                    Sidebar styles (thin layer over @wordpress/components)

  build/                           wp-scripts output (gitignored)
  languages/
    wp-ai-writer.pot               Translation template
  tests/
    bootstrap.php                  WP test suite + plugin loader
    test-rest-generate.php         Permissions, validation, error codes (provider mocked)
    test-limits.php                Rate limit window + budget enforcement + usage counter
    test-prompts.php               Per-action prompt builder output
    test-log.php                   Log writes, queries, migration
    js/
      client.test.js               SSE frame parser + fallback branching (Jest)
      blocks.test.js               Missing-alt scan + text extraction (Jest)
  docs/                            Planning docs (this folder)
  README.md
```

## Tech stack with rationale

- WordPress plugin, PHP 8.1+, WordPress 6.6+: the sidebar uses `PluginSidebar` from
  `@wordpress/editor` (its home since 6.6). Exact dependency versions are pinned at install time
  and both lockfiles are committed.
- `@wordpress/scripts` build with plain JavaScript (JSX): the officially maintained toolchain, no
  custom build pipeline. TypeScript is skipped deliberately — one small app, one REST endpoint, no
  complex data model; stock wp-scripts defaults keep the build boring. (The container's
  `wp-blocks-starter` uses TS because types document a block-attribute API; that rationale does not
  apply here.) The one deviation from "stock" is `webpack.config.js`, which re-exports the wp-scripts
  config with `devServer.proxy` converted to the array form; wp-scripts 33 still emits the
  webpack-dev-server 4 object form, which the security-pinned v5 rejects. It touches nothing the
  production build uses and is removed once wp-scripts ships a v5-compatible devServer.
- React via `@wordpress/element` and UI via `@wordpress/components` (`PanelBody`, `TextareaControl`,
  `SelectControl`, `Button`, `Notice`, `Spinner`): matches the editor's look, accessibility, and
  future compatibility for free.
- No external PHP dependencies at runtime: `wp_remote_post` for non-streaming HTTP; direct cURL
  only for the streaming relay (justified above). No provider SDK — the API surface used is one
  endpoint, and an SDK would both add a dependency and hardcode a vendor.
- Custom table for the activity log (not a CPT): rows are append-only structured metadata queried
  with aggregates — the budget check needs a fast monthly SUM and the log screen needs indexed
  pagination by date/user. Post meta and CPTs are the wrong shape for SUM/COUNT over ranges and
  would bloat `wp_posts`. The table ships via `dbDelta` with a versioned schema
  (`aiwr_db_version` option) and an upgrade routine; applied migrations are never edited.
- Transients for the rate limiter: per-user fixed-window counters are ephemeral by nature and
  transients ride the object cache when one exists.
- Composer + PHPCS/WPCS + PHPUnit, ESLint + Jest via wp-scripts, `@wordpress/env`: the standard
  quality gates for a distributable plugin.

## Data model

### Option: `aiwr_settings` (array, autoload off)

| Key | Type | Notes |
| --- | --- | --- |
| `api_key` | string | Provider key. Write-only: settings UI shows last 4 chars; never in REST/JS. |
| `model` | string | Model identifier as documented by the provider; entered by the admin. |
| `monthly_budget_tokens` | int | Input+output tokens per calendar month. 0 = no cap. Default 500000. |
| `price_input_per_mtok` | float | Optional, for cost estimates. 0 = estimates shown as null. |
| `price_output_per_mtok` | float | Optional, same. |

### Option: `aiwr_usage` (array, autoload off)

`{ month: 'YYYY-MM', input_tokens: int, output_tokens: int }` — O(1) budget check, updated after
every request. Reset when the stored month differs from the current month. If missing or stale it
is recomputed from the log table (the log is the source of truth; the option is a counter cache).

### Table: `{$wpdb->prefix}aiwr_log`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `user_id` | BIGINT UNSIGNED, KEY | |
| `action` | VARCHAR(20) | draft, rewrite, seo, excerpt, alt_text |
| `model` | VARCHAR(100) | Model string in effect at request time |
| `input_tokens` | INT UNSIGNED | |
| `output_tokens` | INT UNSIGNED | |
| `tokens_estimated` | TINYINT(1) | 1 when usage was estimated after an aborted stream |
| `cost_estimate` | DECIMAL(10,6) NULL | NULL when prices unset |
| `status` | VARCHAR(20) | success, provider_error, aborted |
| `duration_ms` | INT UNSIGNED | |
| `created_at` | DATETIME, KEY | UTC |

No prompt or response content is stored — metadata only. Rate-limit state lives in transient
`aiwr_rl_{user_id}` (`{count, window_start}`, 60s fixed window, default 10 requests, filterable
via `aiwr_requests_per_minute`).

Relationships: one user has many log rows; one site has one settings option and one usage counter.
Nothing references posts persistently — generated content becomes ordinary block/post data owned by
WordPress once applied.

## Where state lives

- Server, options table: settings and the monthly usage counter.
- Server, custom table: the activity log (source of truth for usage).
- Server, transients: per-user rate-limit windows (ephemeral, cache-backed).
- Client, editor data stores: post content, selection, title, excerpt — all owned by
  `core/block-editor` / `core/editor`; the sidebar writes via their dispatch actions only.
- Client, React component state: in-flight request status, streaming preview text, unconfirmed
  results. Deliberately ephemeral — closing the sidebar discards unapplied results; nothing is
  persisted client-side (no localStorage, no cookies).
- Never stored anywhere: prompt/response bodies (beyond the in-memory request) and the API key on
  the client.

## External dependencies, APIs, environment

- LLM provider API (the only external service): JSON request/response plus SSE for streaming;
  called with the admin-entered key over HTTPS. Endpoint constant `AIWR_PROVIDER_ENDPOINT`,
  overridable in `wp-config.php`. Every call handles timeout, non-2xx, and malformed-body failure
  and maps to the plugin error format.
- Runtime WordPress packages (externalized by the build): `@wordpress/plugins`, `editor`,
  `element`, `components`, `data`, `blocks` (rawHandler), `block-editor`, `i18n`, `a11y`.
- Dev dependencies: `@wordpress/scripts`, `@wordpress/env` (npm); `wp-coding-standards/wpcs`,
  `squizlabs/php_codesniffer`, `phpunit/phpunit`, `yoast/phpunit-polyfills` (composer). Exact
  versions pinned at install time; lockfiles committed. The plugin ships no npm runtime
  dependencies, so the whole npm tree is build-time only and none of it reaches a site visitor.
  Advisories against packages buried under the two wp-* toolchains are therefore cleared with
  `overrides` in `package.json` rather than by moving off the toolchain: both are already on their
  latest release, and forcing the patched transitive version is the only way to reach it. Each
  override is dropped once the parent ships the fix itself.
- Environment variables: none in production — configuration is entered on the settings page.
  `.env.example` documents local-dev-only values (a dev provider key, a mock endpoint override, a
  model string) used when testing with `wp-env`; see that file.
