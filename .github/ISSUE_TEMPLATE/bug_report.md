---
name: Bug report
about: Report a problem with the plugin
title: ''
labels: bug
assignees: ''
---

<!--
Do NOT report security issues here. Follow SECURITY.md and use private
vulnerability reporting instead.
-->

## Description

A clear description of what the bug is.

## Steps to reproduce

1. Go to '...'
2. Click on '...'
3. See the error

## Expected behavior

What you expected to happen instead.

## Actual behavior

What actually happened. Include the exact error message or the friendly notice
shown in the sidebar, and any relevant entry from the activity log
(**Tools → AI Writer Log**).

## Environment

- Plugin version:
- WordPress version:
- PHP version:
- Browser (for editor/sidebar issues):
- Other active plugins that might interact:

## Configuration

- Streaming: does the host support it, or did it fall back to non-streaming?
- Is a monthly token budget set?
- `AIWR_PROVIDER_ENDPOINT`: pointing at a real provider or a local mock? (do not
  paste the API key or endpoint URL)

## Logs

If `WP_DEBUG` is enabled, paste any `aiwr` lines from the PHP error log. Redact
anything sensitive first — never include the provider API key.

## Additional context

Screenshots or anything else that helps explain the problem.
