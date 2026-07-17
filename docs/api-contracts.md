# API Contracts: wp-ai-writer

REST namespace: `aiwr/v1` under `/wp-json/`. This contract is agreed before any frontend or
backend code is written. The plugin exposes exactly one application route — settings, usage, and
the activity log are server-rendered admin screens (`manage_options`), not REST endpoints, and the
provider API key appears in no response of any kind.

## Authentication

- Standard WordPress cookie authentication plus the `X-WP-Nonce` header carrying a `wp_rest`
  nonce. Core REST verifies the nonce; the streaming client sends the same header on its raw
  `fetch` calls.
- Permission callback for `POST /aiwr/v1/generate`: user is logged in, has `edit_posts`, and — when
  `post_id` is present — `edit_post` for that post. Failures return core's `rest_forbidden` /
  `rest_cookie_invalid_nonce` errors (401/403).

## POST /aiwr/v1/generate

One route, five actions. Request body (`application/json`):

```json
{
  "action": "draft",
  "stream": true,
  "post_id": 123,
  "input": { "prompt": "Outline a beginners guide to composting" },
  "options": {}
}
```

| Field | Rules |
| --- | --- |
| `action` | Required. One of `draft`, `rewrite`, `seo`, `excerpt`, `alt_text`. |
| `stream` | Optional bool, default `false`. Honored for `draft`, `rewrite`, `excerpt`. Ignored for `seo` and `alt_text` (short structured outputs — always JSON). |
| `post_id` | Optional int. When present, must be a post the user can edit. Used for logging context and the `edit_post` check. |
| `input` | Required object; shape depends on `action` (below). |
| `options` | Optional object; only `rewrite` reads it. |

Per-action `input` (all length caps enforced server-side; content over the cap for `seo`/`excerpt`
is truncated server-side, other violations are rejected):

| Action | `input` | `options` | Result payload |
| --- | --- | --- | --- |
| `draft` | `{"prompt": string}` 1-2000 chars | — | `{"html": string}` sanitized via `wp_kses_post` |
| `rewrite` | `{"text": string}` 1-8000 chars | `{"tone": "professional"\|"friendly"\|"casual"\|"confident", "length": "shorter"\|"same"\|"longer"}` | `{"html": string}` |
| `seo` | `{"title": string 0-300, "content": string 1-20000}` | — | `{"seo_title": string <=60, "meta_description": string <=160}` |
| `excerpt` | `{"content": string 1-20000}` | — | `{"excerpt": string}` |
| `alt_text` | `{"attachment_id": int > 0}` | — | `{"alt_text": string <=150}` |

### Non-streaming response (200, `application/json`)

```json
{
  "action": "draft",
  "result": { "html": "<p>Composting turns kitchen scraps into...</p>" },
  "usage": { "input_tokens": 812, "output_tokens": 431 },
  "cost_estimate": 0.0123
}
```

`cost_estimate` is a float in the site currency-agnostic provider unit, or `null` when the price
settings are unset. `usage` comes from the provider's reported figures.

### Streaming response (200, `text/event-stream`)

Sent when `stream` is `true`, the action supports it, and the server can stream (cURL available,
headers not sent). Headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`,
`X-Accel-Buffering: no`. The proxy parses the provider's SSE and re-emits normalized events, so
the browser contract is provider-independent:

```
event: delta
data: {"text": "Composting turns kitchen "}

event: delta
data: {"text": "scraps into rich soil..."}

event: done
data: {"usage": {"input_tokens": 812, "output_tokens": 431}, "cost_estimate": 0.0123}
```

- `delta` events carry text fragments in order; the client concatenates them.
- `done` is the terminal success event; the assembled text is authoritative only after `done`
  (the server applies `wp_kses_post` to the deltas it relays).
- On failure after headers are sent, the terminal event is `error`; its `data` is the standard
  error object (below), and the stream closes:

```
event: error
data: {"code": "aiwr_provider_error", "message": "The writing service returned an error. Please try again.", "data": {"status": 502}}
```

- Comment lines (`: ping`) may appear as keepalive; clients must ignore them.

### Fallback negotiation

- Server side: when streaming was requested but is unavailable, the server answers the same
  request with the non-streaming JSON shape. This is a silent downgrade, not an error.
- Client side: the client branches on the response `Content-Type` header
  (`text/event-stream` -> SSE reader, `application/json` -> parse whole body). If a streaming
  request fails at the network/parse level before the first `delta`, the client retries exactly
  once with `"stream": false`.

## Error response format

Every pre-stream error uses the standard WordPress REST envelope, produced from `WP_Error` — this
is the single error format for the whole plugin:

```json
{
  "code": "aiwr_budget_exhausted",
  "message": "The monthly usage budget for this site has been reached. Ask an administrator to raise it.",
  "data": { "status": 403 }
}
```

| Code | Status | When |
| --- | --- | --- |
| `rest_forbidden` / `rest_cookie_invalid_nonce` | 401 / 403 | Core auth, capability, or nonce failure |
| `aiwr_not_configured` | 409 | No API key or model saved in settings |
| `aiwr_invalid_input` | 400 | Validation failure; `message` names the offending field |
| `aiwr_rate_limited` | 429 | Per-user per-minute limit hit; `message` says when to retry |
| `aiwr_budget_exhausted` | 403 | Monthly token budget reached; no provider call was made |
| `aiwr_image_unreadable` | 422 | `alt_text` attachment missing, unsupported type, or over 5 MB |
| `aiwr_provider_error` | 502 | Provider returned an error; detail logged server-side, generic user message |
| `aiwr_provider_timeout` | 504 | Provider did not respond within the timeout |

Rules: user-facing `message` strings are friendly, translated, and never contain provider response
bodies, stack traces, or the key. Full provider detail goes to `aiwr_log()` only. Mid-stream
failures reuse the identical object as the `data` of an SSE `error` event.

## Provider-side contract (internal, server-only)

Not a public API, documented so the client class has one agreed shape: `AIWR_Provider` POSTs JSON
to the configured endpoint (`AIWR_PROVIDER_ENDPOINT`, overridable in `wp-config.php`) with the
stored key in the provider's documented auth header, a body of
`{model, max_tokens, messages: [...], stream}`, and — for `alt_text` — a base64 image content
block in the user message. Timeouts: 60s non-streaming, 15s connect / 120s total streaming. The
provider's terminal stream events report token usage, which the relay records. Everything
provider-specific (paths, header names, event names, payload keys) is confined to
`class-aiwr-provider.php`.
