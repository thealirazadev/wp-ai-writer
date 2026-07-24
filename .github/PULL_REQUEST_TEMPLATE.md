## Summary

What does this change do, and why?

Closes #

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Refactor / internal change
- [ ] Documentation
- [ ] Tooling / CI

## How it was tested

Describe how you verified the change (manual editor steps, new automated tests,
unhappy paths exercised).

## Checklist

- [ ] `composer run lint` (PHPCS) passes.
- [ ] `composer run test` (PHPUnit against the WP test suite) passes.
- [ ] `npm run lint:js` (ESLint) passes.
- [ ] `npm run test:unit` (Jest) passes.
- [ ] `npm run build` succeeds.
- [ ] New behavior has a test; a bug fix adds a test that fails before the fix.
- [ ] User-facing strings are wrapped in the `wp-ai-writer` text domain, and
      `languages/wp-ai-writer.pot` was regenerated if strings changed.
- [ ] No provider or model names appear anywhere (code, comments, UI, commits).
- [ ] No secrets committed; `.env.example` updated if configuration changed.
- [ ] Schema changes go through a new migration in `class-aiwr-migrations.php`
      (existing migrations are not edited).
- [ ] Commits are small, imperative, and conventional; no attribution trailers,
      no emoji.

## Notes for reviewers

Anything that needs extra attention, follow-ups, or intentional trade-offs.
