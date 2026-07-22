# Build Phases: wp-ai-writer

Phase N+1 does not start until the owner approves phase N. Phases are ordered
smallest-useful-shippable first; each ends in a plugin that activates, lints, and passes tests.
One commit per feature/task, in the listed order.

The senior differentiators are hard requirements placed early, not stretch goals: the security
posture and cost guardrails land in Phase 1; streaming with graceful fallback lands in Phase 2.

---

## Phase 1: Foundation, settings, and the secured proxy (non-streaming)

Goal: an activatable plugin where an admin configures key/model/budget, an editor-capable user can
hit `POST /aiwr/v1/generate` for the draft action (via curl or the browser console — no UI yet),
and every request is capability-checked, rate-limited, budget-enforced, and logged.

### Definition of done

- Plugin activates cleanly on WP 6.6+ / PHP 8.1+; migration creates `{$wpdb->prefix}aiwr_log` and
  sets `aiwr_db_version`.
- Settings page (Settings > AI Writer) saves key (masked display, keep-on-masked-resubmit, clear
  on blank), model, budget, and optional prices; invalid values rejected with an admin notice; a
  usage panel shows current-month tokens, budget, and percent used.
- `POST /aiwr/v1/generate` with `action: draft` returns generated HTML as JSON via
  `wp_remote_post`. All auth failures (logged out, no `edit_posts`, bad nonce) return 401/403; a
  missing key returns `aiwr_not_configured` (409); invalid input returns `aiwr_invalid_input`
  (400) naming the field.
- The 11th request in a minute from one user returns `aiwr_rate_limited` (429); usage at/over
  budget returns `aiwr_budget_exhausted` (403) without calling the provider.
- Every request writes one log row (metadata only) and updates the `aiwr_usage` counter.
- `aiwr_log()` exists and is called at every failure branch; the key appears in no response or log.

### Manual test checklist

- Activate the plugin, check the DB: log table exists, `aiwr_db_version` set, no notices.
- Save settings with a dummy key; reload: key shows only last 4 chars; resubmit untouched: key
  unchanged; save a negative budget: error notice, old value kept.
- As an editor (browser console with a valid nonce), POST a draft request: JSON result returns and
  one log row appears with tokens and status `success` (against a mock/dev provider).
- Logged out curl POST: 401. Subscriber-role POST: 403. Missing nonce: 403.
- Fire 11 requests in a minute: the 11th is a 429 with a friendly message; wait 60s: works again.
- Set budget to 1, make one request, make another: 403 `aiwr_budget_exhausted`; settings usage
  panel reflects the tokens.
- Break the provider endpoint (point `AIWR_PROVIDER_ENDPOINT` at a dead port): request returns
  `aiwr_provider_error` 502 with a friendly message; log row status `provider_error`.

### Commits

- `chore: scaffold plugin folder structure with stub files`
- `feat: add plugin bootstrap with constants and requirements check`
- `feat: create activity log table with versioned migration`
- `chore: add structured aiwr_log helper`
- `feat: add settings page for provider key, model, budget, and prices`
- `feat: add provider client with non-streaming request and error mapping`
- `feat: register generate route with capability, nonce, and input validation`
- `feat: enforce per-user rate limit on the generate route`
- `feat: enforce monthly token budget with friendly exhaustion error`
- `feat: record activity log rows and update the monthly usage counter`
- `feat: show current month usage on the settings page`
- `test: cover permissions, validation, rate limit, and budget enforcement`

---

## Phase 2: Sidebar, draft action, and streaming with fallback

Goal: the editor sidebar exists with the draft action end to end — prompt in, text streaming into
a preview, blocks inserted only on confirmation — degrading cleanly to non-streaming.

### Definition of done

- `npm run build` compiles `src/` to `build/`; the sidebar registers via `PluginSidebar` with a
  toolbar icon and shows the five actions (draft live; the other four visible but marked
  "coming in a later phase" and disabled).
- Draft panel: prompt textarea (client cap 2000 chars), Generate button (disabled while in-flight,
  with spinner), streaming preview area, Insert into post / Discard buttons. Insert converts the
  result to blocks via `rawHandler` and inserts at the insertion point; Discard clears the preview.
  Nothing touches the post without the click.
- The generate route streams: provider SSE relayed as normalized `delta`/`done`/`error` events per
  `docs/api-contracts.md`; the log row (with real usage from the stream's terminal events) is
  written after the stream closes.
- Fallback works end to end: server falls back to JSON when it cannot stream; client branches on
  `Content-Type` and also retries once with `stream: false` if the stream dies before the first
  delta. Same content either way, no user-visible error.
- Streaming preview is announced accessibly: `aria-busy` during the stream, completion announced
  via `@wordpress/a11y` `speak()`.

### Manual test checklist

- Open a post, open the sidebar, enter a prompt, Generate: text appears incrementally; Insert
  places well-formed blocks at the cursor; undo (Ctrl+Z) removes them in one step.
- Generate then Discard: post unchanged, preview cleared.
- Simulate no-stream (disable cURL in a dev toggle or filter): same request completes as JSON, the
  preview fills at once, no error shown.
- Kill the mock provider mid-stream: preview shows a friendly error; log row status `aborted` with
  `tokens_estimated = 1`.
- Budget exhausted or rate limited: sidebar shows the friendly message from the server verbatim.
- Empty prompt: Generate disabled; a 2001-char prompt is blocked client-side; forcing it via
  console returns 400 `aiwr_invalid_input`.

### Commits

- `chore: add wp-scripts build and editor asset registration`
- `feat: register editor sidebar with action navigation`
- `feat: add draft action panel with prompt form and preview`
- `feat: stream provider events through the generate route`
- `feat: add streaming client with json fallback and single retry`
- `feat: insert drafted content as blocks on confirmation`
- `test: cover sse parsing, fallback branching, and stream logging`

---

## Phase 3: Rewrite, SEO, and excerpt actions

Goal: three more actions reusing the Phase 2 pattern (panel -> generate -> preview -> confirm).

### Definition of done

- Rewrite: with a supported block selected (paragraph, heading, list, quote), the panel shows its
  text plus tone (professional, friendly, casual, confident) and length (shorter, same, longer)
  selects; result streams into the preview; "Replace block content" updates only that block. With
  no supported selection the panel explains what to select and Generate is disabled.
- SEO: returns `seo_title` (max 60 chars) and `meta_description` (max 160 chars), server-enforced;
  each has a copy button; "Use as post title" sets the title via `editPost`. Always JSON (no
  streaming).
- Excerpt: streams a summary; "Apply to excerpt" sets the post excerpt.
- Server-side prompt builders for all three live in `class-aiwr-prompts.php` with the content
  truncation caps from `docs/api-contracts.md`.

### Manual test checklist

- Select a paragraph, rewrite with tone "casual" + "shorter": preview differs in tone and is
  shorter; Replace swaps only that block; undo restores the original.
- Select nothing / an image block: rewrite panel shows guidance, Generate disabled.
- SEO on a real post: title <= 60 chars, description <= 160; copy buttons put the exact text on
  the clipboard; "Use as post title" updates the title field.
- Excerpt: apply, save the post, reload: excerpt persisted.
- Run SEO on an empty post: 400 `aiwr_invalid_input` with a friendly message.

### Commits

- `feat: add rewrite action with tone and length presets`
- `feat: replace selected block content on confirmation`
- `feat: add seo action with title and meta description results`
- `feat: add excerpt action with apply to excerpt`
- `test: cover rewrite, seo, and excerpt prompt builders and limits`

---

## Phase 4: Alt text action

Goal: find images missing alt text and fix them one confirmed image at a time.

### Definition of done

- The alt text panel lists every image block in the post with an empty `alt` attribute (thumbnail
  + filename), or states that all images have alt text.
- Per image: Generate sends `attachment_id` to the route; the server validates type/size, encodes
  the image for the provider's image input, and returns `alt_text` (<= 150 chars). Apply sets that
  block's `alt` attribute; the image leaves the list.
- Unreadable/unsupported/oversized attachments return `aiwr_image_unreadable` (422) without
  consuming budget; external-URL images without an attachment are listed as not supported in v1.

### Manual test checklist

- Post with three images, one missing alt: the panel lists exactly that one.
- Generate + Apply: alt set on the block (verify in the block inspector), list now empty state.
- Attachment file deleted from disk: friendly error, no log-row token usage, no budget change.
- A 10 MB image: 422 with a friendly size message.
- Post with no images: panel shows the empty state.

### Commits

- `feat: scan post content for image blocks missing alt text`
- `feat: generate alt text via provider image input`
- `feat: apply alt text to the image block on confirmation`
- `test: cover missing-alt scan and image validation`

---

## Phase 5: Activity log screen, i18n, and hardening

Goal: the admin-facing log, translation readiness, uninstall cleanup, and a final quality pass.

### Definition of done

- Tools > AI Writer Log: `WP_List_Table` over the log table — date, user, action, model, tokens,
  cost, status — 20 rows per page, newest first, `manage_options` only. No prompt/response content
  displayed (none is stored).
- `uninstall.php` drops the log table and deletes `aiwr_settings`, `aiwr_usage`,
  `aiwr_db_version`, and `aiwr_rl_*` transients.
- All user-facing strings wrapped with the `wp-ai-writer` text domain; `languages/wp-ai-writer.pot`
  generated.
- PHPCS clean, PHPUnit green, `npm run lint:js` and `npm run test:unit` exit 0.

### Manual test checklist

- Generate a few requests, open the log screen: rows correct, pagination works past 20 rows,
  non-admin gets no menu entry and a direct URL is refused.
- Switch site language with a test translation: strings translate.
- Uninstall the plugin: table and options gone; reinstall: clean migration, fresh state.
- Full lint + test run: all clean.

### Commits

- `feat: add activity log admin screen with pagination`
- `feat: clean up table, options, and transients on uninstall`
- `refactor: wrap all user-facing strings in the text domain`
- `chore: generate translation template pot file`
- `test: cover log screen query and uninstall cleanup`

---

## Phase 6: Deferred enhancements — retention, attachment alt meta, and SEO meta integration

Goal: ship three items promoted from the backlog. Administrators can bound how long activity-log
rows live (with an automatic daily prune); applying generated alt text also writes the attachment's
`_wp_attachment_image_alt` meta; and an applied SEO meta description is persisted to a post meta key
that SEO plugins can consume through a documented filter and action. Each write reuses the
capability checks already enforced and stays inside the "preview then confirm" rule — nothing is
written until the editor clicks Apply.

### Definition of done

- Settings gains a "Log retention (days)" field (0 = keep forever) that saves and validates like the
  other numeric fields; an invalid value is rejected with the admin notice and the old value kept.
- A daily `aiwr_prune_log` WP-Cron event deletes log rows older than the retention window and then
  refreshes the usage counter; it is scheduled on activation, ensured on load, cleared on
  deactivation, and cleared on uninstall. `AIWR_Log::prune_older_than()` deletes strictly older than
  the cutoff (`created_at < cutoff`), so a row exactly on the boundary is kept.
- Applying alt text sends a confirmed `apply_alt_text` request that writes
  `_wp_attachment_image_alt`, guarded by the same `edit_post` check as generation; it makes no
  provider call and consumes no budget. The block attribute is still set client-side.
- Applying the SEO meta description sends a confirmed `apply_seo_meta` request that writes the
  description to the meta key returned by the `aiwr_seo_meta_key` filter (default
  `_aiwr_meta_description`) and fires `aiwr_seo_meta_saved` for integrators (Yoast/Rank Math bridges);
  when the filter returns an empty key the direct write is skipped safely and only the action fires.

### Manual test checklist

- Save a retention of 30: the value persists; `wp cron event list` shows `aiwr_prune_log`. Age a log
  row past 30 days, run the event: the old row is gone, recent rows remain. Set retention to 0: no
  pruning occurs. Save a negative retention: error notice, old value kept.
- Generate + Apply alt text on an image: the block alt is set and the attachment's alt meta is
  updated (visible in the Media library). A contributor applying to media they cannot edit gets a
  403 and no meta change.
- Generate SEO, Apply meta description: `get_post_meta( $id, '_aiwr_meta_description', true )`
  returns the text; filtering `aiwr_seo_meta_key` to a Yoast/Rank Math key redirects the write;
  hooking `aiwr_seo_meta_saved` receives the post ID and description; returning `''` from the filter
  skips the direct write with no error.
- Deactivate the plugin: `aiwr_prune_log` is unscheduled. Uninstall: option, table, and cron gone.

### Commits

- `feat: add log retention setting to the settings page`
- `feat: prune activity log rows past the retention window on a daily cron`
- `feat: write generated alt text back to attachment meta on apply`
- `feat: persist the seo meta description to a post meta key on apply`
- `chore: regenerate the translation template for phase 6 strings`

---

## Phase verification (run at the end of every phase)

- Run the app: `npm run build`, start `wp-env`, activate the plugin; exercise the phase's features
  in the editor and admin against the mock provider (and once against the live provider with the
  dev key where the phase touches provider behavior).
- Run tests: `composer run test`, `composer run lint`, `npm run lint:js`, `npm run test:unit` all
  pass. Build and tests must be clean before a feature is called done.
- Check the browser console and the wp-env PHP log for warnings/notices on every touched screen.
- Unhappy paths:
  - Wrong input: out-of-enum action/tone/length, oversized prompt/content, non-numeric IDs.
  - Empty forms: empty prompt, empty post for seo/excerpt, post with no images.
  - No network: provider unreachable — friendly 502/504, correct log row, sidebar recovers.
  - Duplicate submissions: double-click Generate — button disabled in flight; exactly one request
    and one log row.
  - Refresh mid-action: reload the editor during a stream — no orphaned state, log row closed as
    `aborted`, next request works.
- Empty states: unconfigured plugin (sidebar notice), no usage yet (settings panel), empty log
  screen, all-images-have-alt.
- Long inputs: 2000-char prompt, very long post content (truncation applies), long model string —
  no layout break, no truncated DB writes.

## Backlog

Out-of-scope or deferred items land here. Phase 6 promoted and shipped three of the original
candidates (SEO plugin meta-key integration, writing alt text back to attachment meta, and the log
pruning/retention setting). Remaining deferred candidate:

- Offloaded-media support: describing attachments whose files live off-server (e.g. an object-store
  offload plugin), where `get_attached_file()` returns a remote or absent path. The alt-text action
  currently requires a readable local file.
