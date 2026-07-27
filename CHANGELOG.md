# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The plugin is pre-1.0 and has not yet had a tagged release. Version `0.1.0` below
describes the current development state; it will get a release date when it is
tagged.

## [Unreleased]

### Added

- Contributor documentation: `CONTRIBUTING.md` (local setup, the hand-provisioned
  `WP_TESTS_DIR` PHPUnit path, the lint/test/build commands, and PR expectations),
  `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1), GitHub issue and pull-request
  templates, and an `.editorconfig` matching the PHP and JS styles.
- Accessible names for the alt-text controls: each per-image Generate and Apply
  button is now labelled with its image so screen-reader users can tell the
  repeated controls apart, and the streaming preview is exposed as a labelled
  region.

### Fixed

- Wired label associations (`label_for`) and `aria-describedby` on the settings
  fields, so each control has a programmatically associated label and description.

## [0.1.0] - Initial development version

### Added

- Block-editor sidebar with five writing actions: draft from a prompt, rewrite
  the selected block with tone and length presets, generate an SEO title and meta
  description, summarize the post into an excerpt, and generate alt text for
  images missing it. Nothing is written into the post until the editor confirms.
- Secure server-side provider proxy: a single `POST /aiwr/v1/generate` REST route
  builds the provider request server-side and attaches the stored key, which never
  reaches the browser. Capability and nonce checks on every request.
- Streaming responses relayed as normalized `delta` / `done` / `error` server-sent
  events, with automatic fallback to a JSON response and a single non-streaming
  retry when the host cannot stream.
- Cost guardrails: a per-site monthly token budget and a per-user
  requests-per-minute rate limit, both enforced server-side before any provider
  call, plus a current-month usage panel on the settings page.
- Settings page: write-only masked API key, model identifier, monthly token
  budget, optional per-million-token prices, and a log-retention window.
- Activity log (Tools → AI Writer Log): one metadata-only row per request - user,
  action, model, tokens, cost estimate, status, duration - paginated for
  administrators. No prompt or response content is stored.
- Log retention with a daily WP-Cron prune, applying generated alt text back to
  the attachment's `_wp_attachment_image_alt` meta, and persisting an applied SEO
  meta description to a filterable post meta key (`aiwr_seo_meta_key` /
  `aiwr_seo_meta_saved`).
- Versioned activity-log table migration, `uninstall.php` cleanup, and a
  translation template (`languages/wp-ai-writer.pot`).

[Unreleased]: https://github.com/thealirazadev/wp-ai-writer/compare/main...HEAD
