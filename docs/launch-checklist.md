# Launch Checklist: wp-ai-writer

Skeleton — work through near the end, before calling the plugin releasable. Check items off only
after verifying on a production-like host (not just wp-env).

## Configuration and secrets

- [ ] Production provider key entered via the settings page only; no key in code, `.env`, or repo
      history; `.env.example` contains dummies only.
- [ ] Model identifier, monthly budget, and token prices set to real production values.
- [ ] `AIWR_PROVIDER_ENDPOINT` override absent from production `wp-config.php` (default endpoint
      in effect).
- [ ] `WP_DEBUG` and `WP_DEBUG_DISPLAY` off in production; `aiwr_log()` output verified to contain
      no key and no prompt/response bodies.

## Guardrails verified in production conditions

- [ ] Budget exhaustion returns the friendly error and blocks provider calls (test with budget=1).
- [ ] Rate limit verified with a second user account (limit is per-user, not global).
- [ ] Streaming works on the production host, or falls back cleanly behind its proxy/CDN
      (verify both the `Content-Type` fallback and the mid-stream abort path).
- [ ] Usage panel numbers reconcile with the activity log after a day of real use.

## UX completeness

- [ ] Loading states everywhere: every Generate/apply path shows busy state; no frozen UI.
- [ ] All empty states render (unconfigured, no selection, empty post, all-alts-present, empty
      log, no usage).
- [ ] All error notices show friendly translated messages; no raw codes or provider text.
- [ ] Editor undo behaves after every apply action (insert, replace, title, excerpt, alt).
- [ ] Sidebar usable at narrow editor widths and in the mobile editor layout.

## Quality gates

- [ ] `composer run lint`, `composer run test`, `npm run lint:js`, `npm run test:unit`,
      `npm run build` all clean on a fresh checkout.
- [ ] Manual accessibility pass: keyboard-only run of all five actions; screen reader announces
      completion/copy/apply; contrast spot-checked.
- [ ] `languages/wp-ai-writer.pot` regenerated and current.
- [ ] Fresh install -> configure -> use -> uninstall leaves no table, options, or transients.
- [ ] Upgrade path tested: activate an older build, then the release build; migration runs once.
- [ ] readme.txt (tested-up-to, requirements, changelog) current; version bumped consistently in
      plugin header and constant.
