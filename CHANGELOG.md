# Changelog

## 0.3.0

- First public release: translations (English, Spanish). Every user-facing text (menu
  entry, page title, form, preview table, confirmations, flash messages and guard-rail
  errors) now goes through the translator, in the `messages` and `flashmessages`
  domains.
- Guard-rail errors (`WorkingTimeLockException`) carry a translation key and parameters:
  the UI shows them in the user's language, the console commands print them in English.
- Confirmation prompts are passed to JavaScript through `data-confirm` attributes instead
  of being embedded in inline JavaScript.
- Exclude `vendor/` from service discovery, so a development checkout with
  `composer install` does not break the container.
- Package renamed to `moui/working-time-lock-bundle`; MIT license, code style headers,
  PHPStan level 9 and CI linting added.

## 0.2.1

- Fix empty Expected/Actual/Difference columns in the preview table. Durations were
  formatted by a Twig macro imported in `{% block main %}`, but macros imported in a
  block are not in scope inside `{% embed %}` blocks. With `strict_variables: false`
  in production that failed silently, so the table rendered with blank cells instead
  of raising an error. Formatting now happens in PHP (`MonthState::formatDuration`),
  which no Twig scoping rule can reach.

## 0.2.0

- Target Kimai 2.66 (`extra.kimai.require` 23800 → 26600) and PHP 8.2+.
- Add an admin UI at **Administration → Working time lock** with preview-then-apply
  locking and a cascading unlock panel.
- Register a `working_time_lock` permission for `ROLE_ADMIN` / `ROLE_SUPER_ADMIN`.
- Move every guard rail into `WorkingTimeLockService` so the UI and the console
  commands cannot drift apart; the commands are now thin wrappers over it.
- Show the actual-vs-expected difference per month, so a wrong balance is visible
  before it gets frozen.
- Register the routes under Kimai's `/{_locale}` prefix, which is what puts them
  behind the authenticated `ROLE_USER` rule in `security.yaml`.

The four core APIs used (`WorkingTimeService::getMonth()`, `approveMonth()`,
`unlockMonth()`, `getLatestApprovalDate()`) were verified unchanged from 2.38 to 2.66,
so no call-site changes were needed.

## 0.1.0

- Add dry-run-first month-range locking command.
- Add cascading unlock command.
- Refuse current/future months and ranges containing running timesheets.
- Verify every selected month after persistence.
