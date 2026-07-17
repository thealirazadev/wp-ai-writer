# Working Rules: wp-ai-writer

These rules bind every change in this repository. Read them with `docs/PRD.md`,
`docs/architecture.md`, and `docs/phases.md` at the start of each session.

## Conventions

- Libraries and patterns to use: WordPress core APIs first — Settings API for the options page,
  `WP_List_Table` for the log screen, `register_rest_route` for the proxy, `wp_remote_post` for
  HTTP, transients for ephemeral counters, `dbDelta` for schema. On the editor side, only
  `@wordpress/*` packages (`components`, `data`, `element`, `plugins`, `editor`, `blocks`, `i18n`,
  `a11y`) via the `@wordpress/scripts` build.
- Avoid: any provider SDK or HTTP library, jQuery in new code, custom Redux stores (use
  `@wordpress/data` core stores), REST routes for things a server-rendered admin screen does
  (settings, log), and direct cURL anywhere except the single streaming relay in
  `class-aiwr-provider.php`.
- Naming: PHP classes `AIWR_Pascal_Snake` in `includes/class-aiwr-*.php`; functions, hooks,
  options, and transients prefixed `aiwr_`; constants `AIWR_*`; REST namespace `aiwr/v1`; text
  domain `wp-ai-writer`. JS files kebab-case, components PascalCase, functions/variables
  camelCase. CSS classes `aiwr-*`.
- Commit messages: short, imperative, conventional (`feat: enforce monthly token budget`). One
  commit per feature or task — never batch multiple features into one commit.
- Pin exact dependency versions and commit `package-lock.json` and `composer.lock`. No blanket
  upgrades or "latest" pulls without approval.
- Database: never modify the schema directly. Every schema change is a new migration step in
  `class-aiwr-migrations.php` keyed off `aiwr_db_version`; applied migrations are never edited
  afterward.

## Error handling & logging

- Every external call handles failure — no bare calls that assume success. That means: every
  provider HTTP call (timeout, connection failure, non-2xx, malformed JSON, truncated SSE), every
  `$wpdb` write (check the return value), every filesystem read for alt text (missing file, bad
  mime, oversize).
- User-facing errors are friendly and actionable ("The monthly usage budget for this site has been
  reached. Ask an administrator to raise it."); logged errors are detailed (provider status code,
  truncated body, request duration). Never show stack traces, provider error bodies, or raw
  `$wpdb` errors to users.
- One error response format everywhere: the standard WordPress REST envelope
  `{code, message, data: {status}}` built from `WP_Error`, with `aiwr_*` codes as defined in
  `docs/api-contracts.md`. Mid-stream failures emit the same object as an SSE `error` event.
- Structured logging from day one: `aiwr_log( string $event, array $context )` writes a
  single-line JSON entry via `error_log` when `WP_DEBUG` is on, and is called at every failure
  branch. The API key and full prompt/response bodies are never passed into `aiwr_log`.

## Security

- Never hardcode secrets. The provider key is entered on the settings page and stored in
  `aiwr_settings`; it is never echoed, localized to JS, returned by REST, or written to logs. The
  settings UI shows only the last four characters and treats the masked placeholder as "keep".
  Local-dev values live in `.env` (see `.env.example`); nothing secret is committed.
- Validate all input server-side regardless of client checks: action enum, per-field length caps,
  integer coercion for IDs, enum validation for tone/length. Reject, don't "fix", invalid values.
- Sanitize on input (`sanitize_text_field`, `wp_kses_post` where HTML is expected), escape on
  output (`esc_html`, `esc_attr`, `esc_url`) in every admin template. All log-table access goes
  through `$wpdb->prepare` / `$wpdb->insert` — no interpolated SQL, ever.
- Generated HTML returned by the provider is untrusted: pass it through `wp_kses_post` server-side
  before it reaches the client, and convert to blocks via `rawHandler` — never `innerHTML`.
- Protected surface (who can do what):

  | Surface | Check |
  | --- | --- |
  | `POST /aiwr/v1/generate` | logged in + `edit_posts` + valid `X-WP-Nonce`; `edit_post` on `post_id` when present |
  | Settings page | `manage_options` + Settings API nonce |
  | Activity log screen | `manage_options` |
  | Provider endpoint URL | constant only — never admin-editable (SSRF guard) |

- Rate limiting and budget checks run server-side on every generate request, before any provider
  call. Client-side gating is UX only, never the enforcement point.

## Simplicity rules (YAGNI & KISS)

- Write the minimum code that satisfies the current phase. No speculative features, no
  second-provider plumbing, no settings that nothing reads.
- Prefer the boring, direct solution over the clever or "scalable" one. Server-rendered admin
  screens beat a React settings app; a transient counter beats a rate-limit framework.
- No abstraction until three real, existing use cases exist. Five actions share one prompt-builder
  class and one route — that is the existing pattern; do not invent per-action classes, interfaces,
  or a "pipeline".
- No new wrapper classes, factories, managers, or utils files without owner approval first.
- Don't add config options, flags, or parameters that aren't needed today.
- Before submitting, self-review: "Can this be done in fewer lines without hurting readability?"
  If yes, rewrite first.
- If a solution exceeds roughly 150 lines, pause and justify it before continuing.
- Use existing library and core features instead of reimplementing them (`rawHandler` for
  html-to-blocks, `WP_List_Table` for the log, `number_format_i18n` for usage display).

## Code style — no AI fingerprints

- Never mention any assistant, model, or tool brand anywhere in the codebase — code, comments,
  commit messages, docstrings, README, UI strings, or docs. The product's own domain vocabulary
  ("writing assistant", "LLM provider API") is fine; naming a specific vendor or model is not. The
  model identifier is runtime configuration typed in by the admin — never a hardcoded default that
  names a vendor.
- No "Generated by...", "Co-authored-by: ...", or similar attribution lines in commits.
- Comments like an experienced human developer: sparse, only where logic is non-obvious (the SSE
  relay and the buffering workarounds will earn comments; getters will not).
- No emoji anywhere — code, comments, commit messages, UI strings.
- Commit messages: short, imperative, conventional (see Conventions).
- Concise docstrings; no boilerplate `@param`/`@return` blocks that restate the signature.

## Boundaries — never do without asking the owner first

- Never delete or rewrite a file wholesale — targeted edits only; flag destructive changes first.
  `uninstall.php` is inherently destructive (drops the log table): implement exactly what
  `docs/architecture.md` specifies and nothing more.
- Never modify `docs/PRD.md` or `docs/architecture.md` without flagging it — they are the source
  of truth.
- Never add a dependency (npm or composer, dev or runtime) without approval.
- If a task is ambiguous, ask instead of assuming.
- On an error you can't fix in two attempts, stop and explain instead of trying random changes.
- Mid-phase requests not in `docs/PRD.md`: ask whether to (a) add to the current phase, (b) create
  a new phase, or (c) log to the Backlog in `docs/phases.md`. Never silently absorb scope.
