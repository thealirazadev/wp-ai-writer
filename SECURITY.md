# Security policy

## Supported versions

This plugin is pre-1.0. Security fixes land on the latest released version only.

| Version | Supported |
| --- | --- |
| 0.1.x | Yes |
| < 0.1 | No |

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through GitHub's
[private vulnerability reporting](https://github.com/thealirazadev/wp-ai-writer/security/advisories/new)
on this repository. Include the affected version, the WordPress and PHP versions, the role of the
account needed to trigger it, and the steps to reproduce.

Expect an acknowledgement within seven days and a status update within thirty. Fixes are released
before the report is made public, and reporters are credited in the advisory unless they ask not to
be.

## Scope

The plugin's security posture rests on a few properties. Reports that break any of these are in
scope:

- The provider API key stays server-side. It must never appear in a REST response, in page output,
  in a log line, or in anything sent to the browser.
- Every request to `POST /aiwr/v1/generate` requires a logged-in user with `edit_posts`, plus
  `edit_post` on the target post when `post_id` is sent and on the attachment for the `alt_text`
  action. Both admin screens require `manage_options`.
- Nonce verification: the route relies on core's REST cookie authentication, which rejects
  cookie-authenticated requests that arrive without a valid `X-WP-Nonce`.
- The provider endpoint is a `wp-config.php` constant rather than a stored setting, so no
  request-supplied value ever selects the host that receives the key.
- Model output is sanitized server-side before it reaches the editor, and the activity log stores
  request metadata only, never prompt or response content.
- Server-side guardrails (per-user rate limit, monthly token budget) cannot be bypassed from the
  browser.

Out of scope: findings that require an administrator account or `wp-config.php` write access, since
both already imply full control of the site. Self-inflicted misconfiguration, such as pointing
`AIWR_PROVIDER_ENDPOINT` at an untrusted host, is also out of scope.
