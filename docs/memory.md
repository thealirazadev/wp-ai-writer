# Memory: wp-ai-writer

Update this file after every meaningful chunk of work: what is done, what is in progress, and
every non-obvious decision with its reason.

## Completed

- Planning docs written (PRD, architecture, rules, phases, design, testing, api-contracts,
  launch-checklist) — awaiting owner review before implementation.

## In progress

(Nothing. Implementation starts after doc approval, at Phase 1.)

## Decisions log

- Custom table (not CPT) for the activity log: budget enforcement needs fast monthly SUMs and the
  log screen needs indexed pagination; post meta/CPT is the wrong shape for aggregates.
- Streaming uses direct cURL inside `class-aiwr-provider.php` only: `wp_remote_*` buffers whole
  responses and cannot relay SSE. Documented as the single sanctioned exception in rules.md.
- Settings/log are server-rendered admin screens, not REST: smaller auth surface, boring and
  correct; the only REST route is `POST /aiwr/v1/generate`.
- Provider endpoint is a constant (overridable in wp-config), never an admin setting: an
  admin-editable URL receiving the API key is an SSRF hazard.
- No prompt/response bodies stored in the log: privacy over debuggability; metadata is enough for
  budget and audit purposes.
- Plain JSX instead of TypeScript for the sidebar: one small app, stock wp-scripts defaults; the
  TS rationale from wp-blocks-starter (typed block-attribute API) does not apply here.
