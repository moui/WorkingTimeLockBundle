# WorkingTimeLockBundle

[![CI](https://github.com/moui/WorkingTimeLockBundle/actions/workflows/linting.yaml/badge.svg)](https://github.com/moui/WorkingTimeLockBundle/actions/workflows/linting.yaml)

A Kimai plugin that exposes the month snapshot ("approve month") functions already present in Kimai core, for any range of completed months and any user. It solves the case where a change to a user's contract hours would otherwise recalculate the expected hours, and therefore the balance, of every completed historical month.

It does not modify Kimai core, add database tables or execute raw SQL. It is not a replacement for the commercial Work Contract bundle.

## Features

- **Admin UI** at *Administration → Working time lock*: pick a user and a month range, preview each month's state, expected hours, actual hours and difference, then lock with an explicit second action.
- **Cascading unlock panel**, shown only when the selected first month has locked months at or after it, listing exactly which months would be released.
- **Two console commands** (`lock` and `unlock`) that are dry-run by default.
- **One set of guard rails** in `WorkingTimeLockService`, shared by the UI and the CLI, so a lock started from the browser obeys exactly the same rules as one started from the console.
- Translated into English and Spanish.

## Requirements

- Kimai 2.66 or later
- PHP 8.2 or later

## Installation

Copy the plugin into your Kimai plugins directory. The directory must be called `WorkingTimeLockBundle` (Kimai rejects plugin directories that contain a version number):

```bash
cd /opt/kimai/var/plugins/
git clone https://github.com/moui/WorkingTimeLockBundle.git
```

Or download a release and extract it to `var/plugins/WorkingTimeLockBundle`.

Then clear the cache:

```bash
cd /opt/kimai/
bin/console kimai:reload -n
```

The plugin has no database migrations, so no install command is needed.

### Docker

Mount or copy the plugin into `/opt/kimai/var/plugins/WorkingTimeLockBundle` and reload as the web server user:

```bash
docker compose exec -u www-data kimai bin/console kimai:reload -n
```

Running as `www-data` matters: run as root, `kimai:reload` rebuilds `var/cache` owned by root, Apache can no longer write to it, and the JSON API starts failing (which breaks, for example, the project/activity dropdowns on the timesheet form). If that happens, repair it with `docker compose exec kimai chown -R www-data:www-data /opt/kimai/var/cache`.

## Configuration

There is nothing to configure. The page lives at `/{locale}/working-time-lock`.

The routes carry Kimai's `/{_locale}` prefix on purpose: Kimai's `security.yaml` protects `^/{_locale}/` with `ROLE_USER`, so a route registered without the prefix would be reachable without logging in. Do not remove it.

### Critical workflow

1. Back up the Kimai database.
2. Restore the employee's **old** contract hours.
3. Preview the range and verify the Expected and Actual totals.
4. Apply the lock.
5. Reload *Working times* and confirm the months show as locked and keep the correct balances.
6. Only then change the employee's contract hours for future months.

Never snapshot historical months while the new contract is active: that would preserve the wrong expected hours.

Choose the first month carefully. Kimai treats the latest approved date as a continuous cutoff, so snapshot every historical month whose displayed balance must remain stable, not merely the last month of the old contract.

### Guard rails

Enforced once, in `WorkingTimeLockService`, for both the UI and the CLI:

- the current and future months cannot be locked;
- the month range must be valid `YYYY-MM`, with the first month not later than the last;
- a range containing a **running** timesheet is refused, since its duration is still incomplete;
- the approving user must hold `ROLE_ADMIN` or `ROLE_SUPER_ADMIN`;
- already locked months are skipped rather than re-approved;
- after writing, every month in the range is re-read and the operation fails loudly if any is not locked.

Unlocking always cascades to every later locked month, because Kimai stores a single continuous approval cutoff. Unlocking deletes the stored snapshots, and the balances recalculate from the current contract.

## Permissions

| Permission          | Default roles                    | Grants                                                         |
|---------------------|----------------------------------|----------------------------------------------------------------|
| `working_time_lock` | `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` | The *Working time lock* menu entry, the page, lock and unlock. |

Independently of the permission, the service only accepts an approving user with `ROLE_ADMIN` or `ROLE_SUPER_ADMIN`, because locking rewrites historical balances.

## Console commands

Preview a lock (no writes):

```bash
bin/console kimai:working-time-lock:lock \
  --user=employee@example.com \
  --from=2026-01 \
  --through=2026-08 \
  --approved-by=admin@example.com
```

Add `--apply` to persist. Preview an unlock the same way, then add `--apply`:

```bash
bin/console kimai:working-time-lock:unlock \
  --user=employee@example.com \
  --from=2026-08 \
  --unlocked-by=admin@example.com
```

`--user`, `--approved-by` and `--unlocked-by` accept a username or an email address. In Docker, prefix the commands with `docker compose exec -u www-data kimai`.

## Translations

The plugin ships English (`en`, the fallback) and Spanish (`es`) in `Resources/translations/`, in two domains: `messages` (page, menu and error texts) and `flashmessages` (feedback after lock/unlock). Console output is always English.

To add a language, copy `messages.en.xlf` and `flashmessages.en.xlf` to `messages.<locale>.xlf` and `flashmessages.<locale>.xlf`, set `target-language` and translate the `<target>` elements. Then run `bin/console kimai:reload -n`. Pull requests with new languages are welcome.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT, see [LICENSE](LICENSE).
