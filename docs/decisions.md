# Design decisions

## 1. Every provider call goes through one server-side REST route

The editor never talks to the provider. All five actions post to `POST /aiwr/v1/generate`, and the PHP side attaches the key, builds the prompt and calls the provider. The browser receives only the route URL and a `wp_rest` nonce through `wp_localize_script`; the key stays in the `aiwr_settings` option and is rendered on the settings screen as eight bullets plus its last four characters. Resubmitting that mask keeps the stored key, clearing the field deletes it, and a new value replaces it. Calling the provider from the editor would be less code, but it would hand a working key to every author on the site.

## 2. The provider endpoint is a constant, not a setting

`AIWR_PROVIDER_ENDPOINT` defaults to a reserved example host and is overridden in `wp-config.php`. An endpoint field on the settings page would let anyone who can reach that screen send the stored key to a host of their choosing, which is request forgery with the credential already attached. A fresh install therefore sends nothing anywhere until the site owner configures it.

## 3. One provider client, confined to one file

The endpoint, auth header, request body (`model`, `max_tokens`, `messages`, `stream`), image encoding, and response and stream parsing all live in `includes/class-aiwr-provider.php`. The rest of the plugin works with a neutral shape: text content plus `input_tokens` and `output_tokens`. There is no driver interface and no vendor SDK, because there is exactly one provider shape to support and an SDK would add a runtime dependency for a single endpoint. Transport failures map to `aiwr_provider_timeout` or `aiwr_provider_error`, and raw provider bodies are never returned to the editor.

## 4. Streaming is a relay with a non-streaming fallback

`wp_remote_post()` buffers the whole response and cannot forward server-sent events, so the streaming path uses a direct cURL handle inside the provider class, the only place the plugin bypasses `wp_remote_*`. The relay parses the provider's frames and re-emits normalized `delta`, `done` and `error` events, so the browser contract does not depend on the provider. When cURL is missing or headers are already sent, `can_stream()` returns false and the same request is answered as JSON. In `src/api/client.js` the client branches on `Content-Type` and retries once with `stream: false` only if the stream fails before the first delta; after a delta has arrived it does not retry, because a retry would duplicate text already shown.

## 5. An abandoned stream still settles its accounting

The relay calls `ignore_user_abort( true )`. Without it, PHP ended the script at the first flush after the editor closed the tab, which skipped both the activity-log row and the usage update even though the provider had already produced tokens, so repeated cancels could run past the budget. When a stream ends without a `done` event, the row is logged with status `aborted` and an output token estimate of `strlen / 4`.

## 6. Guardrails run before the provider call, in a fixed order

The route checks capability and nonce (`edit_posts`, plus `edit_post` on the supplied `post_id`), then configuration (key and model present, otherwise `aiwr_not_configured` with 409), then input validation (400), then the per-user rate limit (429), then the monthly budget (403). The rate limit is a fixed 60-second window per user stored in an `aiwr_rl_<user_id>` transient, 10 requests by default and filterable through `aiwr_requests_per_minute`. A rejected request never reaches the provider and never consumes budget.

## 7. The usage counter is recomputed from the log

The monthly counter in `aiwr_usage` is a cache for the budget check; the activity log is the source of truth. After each request the row is written first and `AIWR_Limits::refresh_usage()` then reads the month's sum back from the log. The earlier version incremented the counter instead, and when the counter was missing or stale it first rebuilt it from a log that already held the new row and then added the same tokens again: a request the provider reported as 120 in and 60 out was recorded as 240 and 120. Recomputing also means two requests finishing together cannot overwrite each other's increment. The remaining looseness is deliberate: a request that passes the budget check runs to completion, so concurrent requests can overshoot the cap by about one request each, and closing that would mean holding a lock across a provider call of up to two minutes.

## 8. The activity log is a custom table that stores metadata only

Each request writes one row with user, action, model, token counts, cost estimate, status and duration, never prompt or response text. Storing bodies would make debugging easier but would turn the log into a store of unpublished drafts readable by every administrator. The table is created by `dbDelta` behind a versioned `aiwr_db_version` option, because the budget needs a fast monthly `SUM` and the Tools screen needs indexed pagination, neither of which fits a custom post type. A daily `aiwr_prune_log` WP-Cron event deletes rows older than the retention setting (default 90 days, 0 keeps everything) with an exclusive `created_at < cutoff` boundary, then refreshes the usage counter, since an aggressive retention can remove current-month rows.

## 9. Alt text checks the attachment, not just the post

The `alt_text` action takes an `attachment_id` from the request body, and the route's permission check only covers the optional `post_id`. Before the fix, any user with `edit_posts` could have the site read and describe media they could not edit. The route now requires `edit_post` on the attachment itself (403 otherwise), which is deliberately strict: an author cannot describe library media uploaded by someone else. The image must be a local jpeg, png, gif or webp file of at most 5 MB; anything else returns `aiwr_image_unreadable` (422) with no provider call. Offloaded media is unsupported for that reason.

## 10. Generation previews, an explicit Apply writes

Generated text is shown in the sidebar and only reaches the post when the editor inserts or applies it: drafts are converted to blocks with `rawHandler`, rewrites replace the selected block, and SEO and excerpt results are copied or applied by button. The two server-side writes, `apply_alt_text` (to `_wp_attachment_image_alt`) and `apply_seo_meta`, use the same route but return before the configuration, rate-limit and budget checks, because they persist an already-previewed result, call no provider and write no log row. `apply_alt_text` re-runs the attachment `edit_post` check. The SEO description goes to `_aiwr_meta_description` by default; the `aiwr_seo_meta_key` filter redirects it to another plugin's key (or disables the direct write with `''`), and `aiwr_seo_meta_saved` fires afterwards, so there is no hard dependency on any SEO plugin. Server-side caps truncate rather than reject output: 60 characters for the SEO title, 160 for the description, 150 for alt text.

## 11. Tone presets come from one allowlist

`AIWR_Prompts::tones()` maps each tone key (professional, friendly, concise, persuasive, casual, confident) to its prompt fragment, and the valid keys, the validator and the default are derived from it. The route resolves `options.tone` for both draft and rewrite in one place: a supplied but unknown tone is rejected with 400, an omitted tone falls back to the site default, which is itself guarded to a valid key. The fragments are model-facing and untranslated; the human labels live in the settings select and `src/tones.js`. The list is the union of the original rewrite tones and the draft tones, so no previously accepted value became invalid.

## 12. The sidebar is plain JSX on stock wp-scripts

The editor bundle is a small React app built with `@wordpress/scripts` defaults, without TypeScript. The only build customization is a root `webpack.config.js` that re-exports the stock config with `devServer.proxy` normalized to an array, because webpack-dev-server 5 rejects the object form that wp-scripts still emits and `start --hot` failed schema validation. `build/` is not committed; the asset enqueue checks for `build/index.asset.php`, so an unbuilt checkout simply does not load the sidebar instead of failing.

## 13. No automated test calls the provider

PHP tests intercept HTTP with `pre_http_request` and Jest tests use a stubbed `fetch` and recorded stream frames; a live provider is used only in a manual smoke test. Two PHP tests originally could not pass: the non-image fixture was created with `wp_tempnam()`, whose `.tmp` name `wp_upload_bits()` refuses, so the mime-rejection path was never exercised; and the uninstall test ran against the test case's `DROP TABLE` to `DROP TEMPORARY TABLE` rewrite, so it asserted against a table uninstall never dropped. CI runs PHPCS, `php -l`, ESLint, Jest and the build, but not PHPUnit, because the suite needs the WordPress core test library and a database that a plain hosted runner does not have.
