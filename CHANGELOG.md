# Changelog

All notable changes to `bjthecod3r/laravel-docket` are documented here.

## 0.1.0 - 2026-10-24

Initial release.

- Threaded reports: one `Report` model with `kind` and `visibility`, replies under a root, owners, assignees, subjects and services.
- Statuses from the `CoreStatus` enum, config and `Docket::statuses()->register()`, each in a category (`open`, `active`, `resolved`, `closed`).
- Transitions with guards, an optional `transition` policy method and an audit trail; status rollup from children to parents (`none`, `latest_child`, `all_terminal`, `severest`, or your own `RollupStrategy`).
- Revisions for title, description, meta and visibility; an optional meta index for `whereMeta()`.
- Services with owners and subscribers; subscriptions to reports, services and whole kinds.
- Notifications: recipient resolvers, per-event and per-channel preferences, `about` and `for` mutes, quiet hours, cooldowns and digests, with a `NotificationSuppressed` event explaining anything not sent.
- Change management: propose, approve, apply, rebase, force-apply and revert, with conflict detection, `patch` or `replace` strategies and custom `ChangeApplier`s.
- File proposals (replace, add, remove, reorder), staged privately and promoted on apply; `FileBinding` for files stored outside Docket.
- Attachments on Laravel's filesystem through a swappable `AttachmentDriver`, with image variants from your own `VariantGenerator`.
- Optional tenancy scoping and an optional JSON API.
- `docket:install` and `docket:prune` commands.
- Testing helpers: `Docket::fake()` assertions, `FakeAttachmentDriver` and model factories.
