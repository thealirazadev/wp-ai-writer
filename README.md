# wp-ai-writer

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
