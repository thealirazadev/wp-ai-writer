# wp-ai-writer

A WordPress plugin that adds a writing assistant to the block editor. Editors open a sidebar panel
and can draft content from a prompt, rewrite the selected block with tone and length presets,
generate an SEO title and meta description, summarize the post into an excerpt, and generate alt
text for images that are missing it. Every request to the LLM provider API runs server-side through
the plugin's REST proxy, with streaming responses, per-user rate limits, and a monthly token budget
enforced on the server; the API key never reaches the browser.

Status: planning — docs under review

## Planned stack

- PHP 8.1+, WordPress 6.6+
- Block editor sidebar in React (JSX) built with `@wordpress/scripts`
- REST proxy under the `aiwr/v1` namespace; `wp_remote_*` for non-streaming HTTP, PHP cURL for
  the SSE streaming relay
- Custom activity-log table with versioned migrations; settings in the options table
- Tooling: PHPCS (WordPress Coding Standards), PHPUnit with the WP test suite, ESLint, Jest,
  `@wordpress/env` for local development

## Install

TBD until implementation starts.

## Run

TBD until implementation starts.

## Test

TBD until implementation starts.
