=== AI Writer ===
Contributors: thealirazadev
Tags: editor, writing, assistant, seo, accessibility
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A block-editor writing assistant backed by a secured server-side LLM provider proxy. The API key
never reaches the browser.

== Description ==

AI Writer adds a sidebar to the block editor with five actions:

* Draft content from a prompt, streamed into a preview and inserted as blocks on confirmation.
* Rewrite the selected block with tone and length presets.
* Generate an SEO title and meta description, with copy buttons and an apply-to-title action.
* Summarize the post into an excerpt.
* Generate alt text for images that are missing it.

Every request runs server-side through the plugin's REST proxy. The provider key is stored in the
options table and is never sent to the browser, returned by REST, or written to logs. A per-site
monthly token budget and per-user rate limits are enforced on the server, and every request is
recorded as metadata (user, action, model, tokens, cost estimate, status) in an activity log
visible to administrators. No prompt or response content is stored.

Responses stream into the sidebar as they generate, with a graceful non-streaming fallback when the
host cannot stream.

== Installation ==

1. Install and activate the plugin.
2. Define your provider endpoint in wp-config.php:
   `define( 'AIWR_PROVIDER_ENDPOINT', 'https://your-provider.example/v1/generate' );`
3. Go to Settings > AI Writer and enter the provider API key, model identifier, monthly token
   budget, and optional token prices.
4. Open a post, open the AI Writer sidebar from the editor, and use any of the five actions.

== Frequently Asked Questions ==

= Is the API key exposed to the browser? =

No. The key is stored server-side and used only by the REST proxy. It appears in no REST response,
no localized script data, and no log row.

= What happens when the monthly budget is reached? =

Requests return a friendly error and no provider call is made until an administrator raises the
budget.

== Changelog ==

= 0.1.0 =
* Initial release: secured provider proxy, cost guardrails, settings, draft, rewrite, SEO, excerpt,
  and alt text actions, streaming with fallback, and the activity log.
