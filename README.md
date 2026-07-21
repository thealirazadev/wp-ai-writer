# wp-ai-writer

[![CI](https://github.com/thealirazadev/wp-ai-writer/actions/workflows/ci.yml/badge.svg)](https://github.com/thealirazadev/wp-ai-writer/actions/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

A WordPress plugin that adds a writing assistant to the block editor. Editors open a sidebar panel
and can draft content from a prompt, rewrite the selected block with tone and length presets,
generate an SEO title and meta description, summarize the post into an excerpt, and generate alt
text for images that are missing it. Every request to the LLM provider API runs server-side through
the plugin's REST proxy, with streaming responses, per-user rate limits, and a monthly token budget
enforced on the server; the API key never reaches the browser.

## Stack

- PHP 8.1+, WordPress 6.6+
- Block editor sidebar in React (JSX) built with `@wordpress/scripts`
- REST proxy under the `aiwr/v1` namespace; `wp_remote_*` for non-streaming HTTP, PHP cURL for the
  SSE streaming relay
- Custom activity-log table with versioned migrations; settings in the options table
- Tooling: PHPCS (WordPress Coding Standards), PHPUnit with the WP test suite, ESLint, Jest,
  `@wordpress/env` for local development

## Configuration

The plugin talks to a single LLM provider endpoint. For security the endpoint is a constant, never
an admin-editable setting (an admin-editable URL that receives the key would be an SSRF hazard).
Define it in `wp-config.php`:

```php
define( 'AIWR_PROVIDER_ENDPOINT', 'https://your-provider.example/v1/generate' );
```

The remaining configuration is entered on **Settings → AI Writer**: the provider API key (stored
server-side, shown masked, never sent to the browser), the model identifier, the monthly token
budget, and optional per-million-token prices for cost estimates. Usage for the current month and
the full activity log (**Tools → AI Writer Log**) are visible to administrators.

Copy `.env.example` to `.env` for local development values (dev key, mock endpoint, model string).
Nothing in `.env` is read in production.

## Install

```
composer install   # PHP dev tooling (PHPCS, PHPUnit)
npm install        # JS build + lint + test tooling
npm run build      # compile src/ into build/
```

## Run

```
npx wp-env start   # local WordPress at http://localhost:8888 (requires Docker + docker compose)
```

Then activate the plugin, set `AIWR_PROVIDER_ENDPOINT`, and configure the key and model under
Settings → AI Writer.

## Test

```
composer run lint      # PHPCS (WordPress-Extra + WordPress-Docs)
composer run test      # PHPUnit against the WP test suite (run via wp-env)
npm run lint:js        # ESLint
npm run test:unit      # Jest
npm run build          # production build
```

The provider HTTP layer is mocked in all automated tests; no real provider request is made. A
one-time live-provider smoke test with a real key and endpoint is a manual QA step.

The PHPUnit suite needs the WordPress core test suite and a database. `wp-env` provisions both, but
any existing checkout works by pointing `WP_TESTS_DIR` at it:

```
WP_TESTS_DIR=/path/to/wordpress-tests-lib composer run test
```

## Design decisions

The trade-offs worth knowing about, and the alternative each one rejected.

**The provider key never leaves the server.** It is stored in the `aiwr_settings` option, attached
to the outbound request in PHP, and rendered in the settings screen only as a mask of its last four
characters. The editor bundle receives the proxy URL and a `wp_rest` nonce, nothing else. Calling
the LLM provider API directly from the block editor would have been far less code, but any
browser-side call ships a usable key to every author, so client-side provider calls are a hard
non-goal. The cost is that each generation is a WordPress request, and provider features that assume
a browser client are unavailable.

**The provider endpoint is a `wp-config.php` constant, not a setting.** `AIWR_PROVIDER_ENDPOINT`
defaults to a reserved example host, so a fresh install sends nothing anywhere until it is
configured. An endpoint field on the settings page would have been friendlier, but it would let
anyone who reaches that screen redirect the stored key to a host of their choosing — server-side
request forgery with the credential already attached. The cost is that pointing at a mock server
during development means editing `wp-config.php` instead of a form field.

**One provider client, no abstraction layer.** Everything provider-specific — endpoint, auth header,
body shape, response and SSE parsing — lives in `class-aiwr-provider.php` and nowhere else. A
provider-agnostic driver interface was rejected under the rule of three: there is no second provider
yet, and plumbing for an imagined one is surface area that has to be maintained and tested. A vendor
SDK was rejected too, since the API surface used is a single endpoint and an SDK would add a runtime
dependency while hardcoding a vendor. Adding a second provider later means writing that abstraction
then, against two real shapes rather than one guessed one.

**Streaming through a server-side relay, with a non-streaming fallback.** `wp_remote_post` buffers
whole responses and cannot relay server-sent events, so the streaming path uses a direct cURL handle
inside the provider class — the single sanctioned exception to the `wp_remote_*` rule, deliberately
confined to one file. The relay parses the provider's frames and re-emits normalized
`delta` / `done` / `error` events, so the browser contract stays provider-independent and no provider
headers or auth details can leak. Streaming from the provider straight to the browser was rejected
for the same reason as above (it needs the key client-side), and dropping streaming was rejected
because a long generation then looks frozen. When cURL is unavailable or headers have already been
sent, the same request is answered as ordinary JSON; the client branches on `Content-Type` and
retries once without streaming if a stream fails before the first delta.

**The activity log stores metadata only.** One row per request holding user, action, model, token
counts, cost estimate, status and duration — never prompt or response text. Logging bodies would
make debugging a bad generation much easier, but the log would accumulate draft content and become a
disclosure risk on any site with more than one editor. Privacy won; debugging a bad generation means
reproducing it rather than reading it back.

**A custom table for the log, not a custom post type.** The rows are append-only structured metadata
that get queried as aggregates: the budget check needs a fast monthly `SUM` and the log screen needs
indexed pagination. A CPT with post meta is the wrong shape for aggregates over a date range and
would bloat `wp_posts`. The table ships through `dbDelta` behind a versioned `aiwr_db_version`
option, so the cost is a schema to migrate and none of the free CPT admin tooling.
