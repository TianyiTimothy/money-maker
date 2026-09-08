# Questrade Tracker &amp; Tax Assistant

A custom WordPress plugin that connects to the [Questrade API](https://www.questrade.com/api),
pulls your personal investment data into local database tables, and helps with Canadian
tax reporting.

> **Status:** Stage 1 — read-only. Early development.

## What it does (planned)

- **Auth** — Questrade OAuth 2.0 with safe handling of the rolling, one-time-use refresh token.
- **Sync** — scheduled + on-demand import of accounts, positions, and activities, plus daily
  Bank of Canada CAD/USD exchange rates.
- **Tax** — pooled Adjusted Cost Base (ACB) per CRA rules and superficial-loss warnings, for
  non-registered accounts.
- **Dashboard** — positions, realized gains/losses, and historical charts.

## Requirements

- WordPress 6.2+
- PHP 8.0+ (developed against 8.1–8.2)
- A Questrade account with API access (a manually generated refresh token)

## Repository layout

```
money-maker/        the shippable plugin (this is what goes into wp-content/plugins/)
CLAUDE.md           project spec
LICENSE  README.md  .gitignore  .gitattributes
```

## Installation (development)

Symlink the `money-maker/` subfolder into your WordPress install, then activate it
from the Plugins screen:

```powershell
# from wp-content/plugins/
New-Item -ItemType SymbolicLink -Name money-maker -Target C:\path\to\repo\money-maker
```

## Configuration

API tokens are encrypted at rest. Define an encryption key in `wp-config.php`:

```php
define( 'MM_CRYPTO_KEY', 'a-long-random-string-you-generate-once' );
```

Losing this key means you'll need to re-authenticate with Questrade.

## Scheduled sync (WP-Cron)

The plugin schedules an incremental sync (`twicedaily`) through WP-Cron. WP-Cron
only fires when the site receives a request, so on a low-traffic personal site it
can lag. Options:

- **Rely on WP-Cron** — fine if you visit the site regularly. A "Sync now" button
  on the *Money Maker → Data Sync* screen always works on demand.
- **Use a real system cron** (recommended for unattended syncing). Disable
  WP-Cron's request-time trigger in `wp-config.php`:

  ```php
  define( 'DISABLE_WP_CRON', true );
  ```

  then run WP-Cron from the OS scheduler, e.g. every 15 minutes:

  ```cron
  */15 * * * * cd /path/to/wordpress && wp cron event run --due-now > /dev/null 2>&1
  ```

  (or `curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null`).

Historical data is loaded separately via **Data Sync → Historical backfill**, which
walks activities month-by-month in small background steps.

## Security notes

- No credentials, tokens, or account data are stored in this repository.
- Account numbers are masked in the UI; tokens are never logged.

## Disclaimer

This plugin assists with tax reporting. It does **not** provide tax advice and does not file
anything on your behalf. Verify all figures with a qualified accountant.

## License

[GPL-2.0-or-later](LICENSE) — consistent with WordPress itself.
