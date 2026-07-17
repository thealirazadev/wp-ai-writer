# Product Requirements: wp-ai-writer

## What we're building

A WordPress plugin that adds a writing assistant sidebar to the block editor. An editor opens the
sidebar and runs one of five actions: draft content from a prompt, rewrite the currently selected
block with tone and length presets, generate an SEO title and meta description, summarize the post
into an excerpt, or generate alt text for images in the post that are missing it. The plugin calls
the LLM provider API exclusively server-side through its own REST proxy — the provider key lives in
the options table and is never sent to the browser. Responses stream into the sidebar as they
generate, with a graceful non-streaming fallback when the host blocks streaming. Nothing is ever
written into the post silently: every result is previewed in the sidebar and applied only when the
editor confirms. A per-site monthly token budget and per-user rate limits are enforced on the
server, and every request is recorded in an activity log (user, action, tokens, cost estimate)
visible to administrators.

## Target user

Content editors and site owners on self-hosted WordPress sites who want assisted drafting and
editing inside the block editor without pasting content into an external tool. Administrators are a
secondary user: they configure the provider key and model, set the monthly budget, and monitor
usage and spend from the settings and log screens. The plugin assumes a normal shared or VPS host
(PHP 8.1+, cURL available on most but not all hosts), so streaming must degrade cleanly.

## Core features (prioritized)

1. Secure server-side provider proxy (highest priority). A REST route under `aiwr/v1` that builds
   the provider request server-side, attaches the stored key, and relays the result. Capability
   checks and nonces on every route; the key is never exposed to the client in any response.
2. Cost guardrails. A per-site monthly token budget enforced server-side before every request, with
   a clear friendly error when exhausted; a per-user requests-per-minute rate limit; current-month
   usage displayed on the settings page.
3. Settings page. Provider API key (write-only, masked on display), model identifier string,
   monthly token budget, and optional per-million-token prices used for cost estimates.
4. Sidebar with draft action and confirmed insertion. A block editor sidebar panel where the editor
   enters a prompt, previews the generated content, and inserts it as blocks only on confirmation.
5. Streaming responses. The REST proxy streams the provider's SSE through to the sidebar so text
   appears as it generates, with automatic fallback to a normal JSON response when the server or
   host cannot stream.
6. Rewrite selected block. Send the selected block's text with a tone preset (professional,
   friendly, casual, confident) and length preset (shorter, same, longer); preview the result and
   replace the block content only on confirmation.
7. SEO and excerpt actions. Generate an SEO title and meta description (shown with copy buttons and
   a "Use as post title" apply action) and a post excerpt (with an "Apply to excerpt" action).
8. Alt text action. Scan the post for image blocks with empty alt text, generate a description per
   image using the provider's image input support, and apply it to the block's alt attribute on
   confirmation.
9. Activity log. An append-only log of every request — user, action, model, input/output tokens,
   cost estimate, status, duration — browsable by administrators with pagination.

## Non-goals / out of scope

- No bulk or automated content generation (no batch posts, no scheduled generation, no autonomous
  agents). One action per explicit user click.
- No multi-provider abstraction. One provider client, one endpoint shape, until a real second
  provider requirement exists (rule of three).
- No image generation. Images are only read (for alt text), never created.
- No front-end, visitor-facing features. Everything lives in wp-admin and the editor.
- No SEO plugin integration in v1 (writing the meta description into Yoast/Rank Math meta keys is a
  backlog candidate). The meta description is delivered via copy button.
- No storage of prompt or response bodies in the activity log — metadata only (privacy decision).
- No classic editor support; the sidebar requires the block editor.
- No multisite-specific UI; the plugin is configured and budgeted per site.
- No client-side calls to the provider under any circumstances.

## Success criteria per core feature

### 1. Secure provider proxy
- `POST /wp-json/aiwr/v1/generate` returns 401/403 for logged-out users, users without
  `edit_posts`, and requests with a missing or invalid `X-WP-Nonce` header.
- The API key appears in no REST response, no localized script data, no HTML source, and no log
  row; grepping a full page + response capture for the key finds only the options table.
- With no key configured, the route returns the `aiwr_not_configured` error (409) and the sidebar
  shows a friendly "ask an administrator to configure the plugin" notice.

### 2. Cost guardrails
- With monthly usage at or above the configured budget, every generate request returns
  `aiwr_budget_exhausted` (403) with a friendly message, and no provider request is made.
- The 11th request within one minute from the same user returns `aiwr_rate_limited` (429) while a
  second user can still generate; the counter resets within 60 seconds.
- The settings page shows current-month input/output tokens, the budget, and percent used, and the
  numbers match the sum of the month's activity-log rows.

### 3. Settings page
- An administrator can save key, model, budget, and prices; a non-admin cannot load or save the
  page. The saved key displays only its last four characters; resubmitting the masked value keeps
  the stored key; clearing the field deletes it.
- Invalid values (negative budget, non-numeric prices) are rejected with an admin error notice and
  do not overwrite stored settings.

### 4. Sidebar with draft action and confirmed insertion
- The sidebar opens from the editor toolbar, shows the five actions, and the draft action accepts a
  prompt of 1 to 2000 characters (longer input is blocked client-side and rejected server-side).
- Generated content renders in a preview area; nothing changes in the post until "Insert into post"
  is clicked, after which the content appears as proper blocks at the insertion point. Dismissing
  the preview discards it.

### 5. Streaming responses
- On a host that supports it, generated text visibly appears incrementally in the preview during
  generation (first text within a few seconds, before the response completes).
- When streaming is unavailable (no cURL, buffering proxy) the same request completes with a JSON
  response and the preview fills at once — same content, no user-visible error.
- A stream that fails before any text arrives is retried once automatically without streaming.

### 6. Rewrite selected block
- With a paragraph/heading/list block selected, the rewrite panel shows the block's text, tone and
  length selects, and a preview after generating; "Replace block content" swaps the content of that
  block only. With no supported block selected, the panel explains what to select and the generate
  button is disabled.

### 7. SEO and excerpt actions
- The SEO action returns a title of at most 60 characters and a meta description of at most 160
  characters (enforced server-side by truncation), each with a working copy button; "Use as post
  title" sets the post title. The excerpt action's "Apply to excerpt" sets the post excerpt field
  and the value survives saving the post.

### 8. Alt text action
- The panel lists every image block in the post with empty alt text (and states "all images have
  alt text" when none). Generating for one image produces a description under 150 characters;
  applying sets that block's alt attribute; the image drops off the missing list.
- Unsupported or unreadable attachments produce the `aiwr_image_unreadable` error with a friendly
  message and do not consume budget.

### 9. Activity log
- Every completed or failed provider request creates exactly one log row with user, action, model,
  tokens, cost estimate (or null when prices are unset), status, and timestamp; duplicate
  submissions create one row per actual request.
- The log screen is visible only to `manage_options`, paginates at 20 rows, and shows no prompt or
  response content anywhere.
