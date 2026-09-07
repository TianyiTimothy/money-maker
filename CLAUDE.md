# Project Overview: Questrade Tracker & Tax Assistant (WordPress Plugin)

## 1. Project Goal
A custom WordPress plugin that integrates with the Questrade API. **Stage 1 is read-only.**
Pull personal investment data (accounts, positions, activities), store it locally in custom
WordPress tables, provide analytics, and assist with Canadian tax reporting (pooled ACB
calculation, superficial-loss warnings).

This is a personal, single-site plugin. It is **not** intended for the wordpress.org
repository, but it should still follow WordPress coding and security standards.

## 2. Naming & Conventions
* **Repo layout:** the shippable plugin is the `money-maker/` subfolder; the repo root
  holds dev-only files (this spec, license, build/test config). All plugin code paths
  below are relative to `money-maker/`.
* **Plugin slug / folder:** `money-maker`
* **Display name:** "Questrade Tracker & Tax Assistant"
* **Text domain:** `money-maker`
* **Prefix everything:** functions `mm_`, classes `MM_`, DB tables `{$wpdb->prefix}mm_*`,
  options `mm_*`, hooks `mm/*`.

## 3. Tech Stack & Standards
* **Environment:** WordPress plugin architecture.
* **Language:** PHP 8.0+ (develop/test against 8.1–8.2), JavaScript, HTML/CSS.
* **Minimum WordPress:** 6.2.
* **Database:** Custom tables via `$wpdb`. DO NOT use Custom Post Types or post meta for
  financial data — performance and query structure require real tables.
* **Security:**
  * All queries go through `$wpdb->prepare()`.
  * Sanitize every input (`sanitize_text_field`, `absint`, etc.); escape every output
    (`esc_html`, `esc_attr`, `esc_url`).
  * Nonces on every form submission and AJAX request; capability checks (`manage_options`).
  * Encrypt sensitive tokens at rest (see Module A).
  * **Never log tokens or full account numbers.** Mask account numbers in the UI.

## 4. Core Modules (Stage 1)

### Module A: Auth & Token Management (CRITICAL)
Questrade OAuth 2.0 uses a **rolling, one-time-use refresh token**. Every access-token
request returns a *new* refresh token; the old one is immediately dead.

**Rules:**
1. **Concurrency lock.** Before any refresh, acquire a lock (MySQL `GET_LOCK()` or an
   `mm_token_lock` option holding a timestamp with a stale-timeout fallback). Only one
   process refreshes at a time; others wait and then re-read the stored token. Two
   concurrent refreshes (cron + manual sync) will permanently break the token chain.
2. **Persist the whole token response**, not just the tokens:
   `access_token`, `refresh_token`, `api_server`, `token_type`, and a computed
   `expires_at` (from `expires_in`, ~1800s). `api_server` is the host for all data calls
   and changes over time — always read it from storage, never hardcode.
3. **Save order.** Write and commit the new `refresh_token` *before* making any other API
   call. Keep a rolling history of the last ~5 refresh tokens for manual recovery.
4. **Proactive refresh** ~2–5 minutes before `expires_at`, not reactively on a 401.
5. **Manual recovery path** in settings: a field to paste a fresh refresh token when the
   chain breaks.
6. **Encryption at rest.** WordPress has no crypto primitive. Use libsodium
   (`sodium_crypto_secretbox`) with a key from a `wp-config.php` constant `MM_CRYPTO_KEY`
   (not stored in the DB). Document that losing the key means re-authentication. Store the
   token option with `autoload = 'no'`.
7. **Practice environment.** Support Questrade's practice login
   (`practicelogin.questrade.com`) via a settings toggle so development does not hit the
   live account.

### Module B: Data Sync Engine
* **Scheduler.** WP-Cron only fires on site traffic and is unreliable for this. Bundle
  Action Scheduler, or document a real system cron calling `wp-cron.php`. Provide a
  "Sync now" button regardless.
* **Target endpoints:** `/v1/accounts`, `/v1/accounts/{id}/positions`,
  `/v1/accounts/{id}/activities`.
* **Activities date window.** The endpoint caps each request at ~31 days. Backfills must
  loop month-by-month; incremental syncs use an overlapping window (e.g. last 35 days).
* **Idempotency.** Questrade activities have no stable ID. Build a deterministic dedup key
  — hash of `account + settlementDate + action + symbol + quantity + price + netAmount +
  currency` — and **upsert**, never blind-insert (sync windows overlap by design).
* **Rate limits.** Respect Questrade's per-hour and per-second caps; on HTTP 429, back off
  using `Retry-After`.
* **Timezone.** Questrade timestamps are US Eastern. Normalize to UTC on store; keep
  trade date and settlement date as `DATE`.
* **Bank of Canada FX.** Fetch daily CAD/USD rates from the BoC Valet API, series
  `FXUSDCAD` (single daily rate since 2017-01-03; earlier dates need a different series).
  For weekends/holidays, use the most recent prior business day's rate.
* **`mm_sync_log`.** Record every run: endpoint, date range, status, rows affected, error.

### Module C: Canadian Tax Logic (Business Rules)
* **Scope.** ACB and superficial-loss logic apply to **non-registered accounts only**.
  Detect account type from `/v1/accounts` and exclude TFSA / RRSP / RESP / LIRA etc.
* **ACB (Adjusted Cost Base).** Pooled **average cost per security** per CRA rules — not
  FIFO, not per-lot. Commissions/fees increase ACB on a buy and reduce proceeds on a
  sell. USD transactions convert to CAD at the transaction date's exchange rate.
* **Superficial loss.** Flag a realized loss when the same (or identical) security was
  bought within the **61-day window**: 30 days before the sale, the sale day, and 30 days
  after — *and* substituted property is still held at the end of that window. The denied
  loss is added pro-rata to the ACB of the remaining shares.
  * Affiliated-person triggers (spouse, the user's own registered accounts) are **out of
    automated scope**. Superficial-loss output is **warning-only** — always surface
    "review with your accountant."
* **Disclaimer.** The plugin assists with reporting; it does not file taxes. The user is
  responsible for all filings.

## 5. Data Model (custom tables, prefix `{$wpdb->prefix}mm_`)
* `mm_accounts` — account number (masked for display), type, status, currency.
* `mm_activities` — normalized transactions + dedup hash (unique), raw JSON, FX rate used.
* `mm_positions_snapshots` — dated snapshots of open positions per account.
* `mm_fx_rates` — date, pair, rate, source.
* `mm_manual_adjustments` — user-entered ACB adjustments for corporate actions the API
  does not represent (splits, mergers, return of capital, ETF reinvested/"phantom"
  distributions).
* `mm_sync_log` — sync run history.

Use `dbDelta()` for schema; store a `mm_db_version` option and migrate on upgrade.

## 6. Edge Cases & Known Limitations
* Network failure mid-refresh — see Module A rules 1–3.
* Clock skew between server and Questrade — refresh proactively, tolerate early expiry.
* Corporate actions (splits, mergers, spin-offs, return of capital, reinvested
  distributions) are not reliably in the activities feed → handled via
  `mm_manual_adjustments`, not computed automatically.
* Currency: positions and activities can be USD or CAD; every tax figure is stored in CAD.
* Questrade "practice" and "live" have separate tokens — never mix them.
* Large backfills can exceed rate limits and PHP execution time — chunk and queue.

## 7. Development Workflow

### Milestones
* **Milestone 1:** Settings page, auth flow (safe save/refresh with lock + encryption),
  test-connection button, practice/live toggle. Split into testable sub-steps:
  * **M1a:** plugin skeleton (bootstrap wiring, activation/deactivation), settings page
    shell, practice/live environment toggle persisted to `mm_settings`.
  * **M1b:** `MM_Crypto` (libsodium `secretbox`) + key-management UI. Key resolution:
    `MM_CRYPTO_KEY` constant → `WP_CONTENT_DIR/mm-crypto-key.php` (UI-written, gitignored,
    out of DB) → not configured. Generate / rotate from the settings page.
  * **M1c — done:** `MM_Token_Store` (encrypted bundle option, rolling 5-token history),
    `MM_Lock` (option-based `mm_token_lock` + stale takeover), refresh-token paste field,
    `MM_Questrade_Client::exchange_refresh_token()`.
  * **M1d — done:** `get_valid_token()` (proactive refresh under lock), `request()` with
    401/429 retry, "Test connection" button + AJAX (`GET v1/time`).
  * **M1e — done:** admin UI moved out of Settings into its own top-level "Money Maker"
    menu (`MM_Admin`, view layer); rebuilt as a card-based Dashboard + Connection +
    Settings tabs. `MM_Settings` slimmed to storage + form handlers.
* **Milestone 2:** Custom tables + `dbDelta` migrations; scheduled + manual sync for
  accounts and activities with dedup; FX rate fetching.
* **Milestone 3:** Frontend/admin dashboard — positions, pooled ACB, realized gains/losses,
  superficial-loss warnings, historical charts.

### Testing
* **No automated test suite.** The user tests each sub-step manually in the local
  WordPress install. Do not add PHPUnit, Composer, npm, or CI without asking first.
* When finishing a sub-step, give the user a short manual test checklist.

### Git
* Remote: `origin` → https://github.com/TianyiTimothy/money-maker (public). Branch `main`.
* One short-lived branch per milestone/feature (e.g. `milestone-1-auth`); land it via a PR
  (`gh pr create`), squash-merge, delete the branch.
* **Conventional Commits** (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`),
  imperative mood, body explaining *why* when non-obvious.
* Commit at logical checkpoints, not per file. **Never auto-commit; never push without
  asking.** End commit messages with the Co-Authored-By trailer.
* Tag releases with SemVer (`v0.1.0`) once a milestone lands.
* Commit identity is set repo-local: Timothy Zhang &lt;timothy.tudis@gmail.com&gt;.

## 8. Claude AI Persona Instructions
* Act as a Senior WordPress Developer and FinTech Engineer.
* Keep code modular: separate Auth, DB, Sync, Tax, and UI concerns into their own classes.
* Always consider edge cases — especially network failures and concurrency during token
  refresh.
* When creating a **new** file, write it complete and state its path. When changing an
  **existing** file, give targeted edits, not a full re-dump.
* Do not add dependencies or build tooling without asking.
* **Claude may (and should) update this file.** Keep it current as the source of truth —
  when decisions change, conventions are set, a milestone completes, or something
  non-obvious is learned, edit the relevant section (and §9) in the same commit as the
  work. Mention the update in your reply. Don't rewrite wholesale or drop context without
  flagging it.

### 8.1 Coding Conventions — Backend & Frontend
Follow these on every change so future sessions stay consistent.

**PHP / backend**
* One class per file at `money-maker/includes/class-mm-{name}.php`; class names `MM_{Name}`.
  Classes are passive — they expose a `register()` (or similar) that adds their hooks, and
  `money-maker.php` calls it. Do not add hooks from constructors. Stateless static-only
  utilities with no hooks (e.g. `MM_Crypto`) are `require_once`d directly and have no
  `register()`.
* Admin UI split: `MM_Admin` is the view + navigation layer (top-level "Money Maker" menu,
  its Dashboard / Connection / Settings tabs, shared page chrome, asset enqueue, the
  "Test connection" AJAX, and the shared notice transient). `MM_Settings` is storage +
  `admin_post_*` form handlers only — it renders nothing. Handlers finish with
  `MM_Admin::redirect_with_notices( $slug )`.
* PHP 8.0+. Type-hint parameters and returns where practical. `defined( 'ABSPATH' ) || exit;`
  at the top of every file.
* Every DB read/write through `$wpdb->prepare()`. Options are `mm_*`; token/financial
  options use `autoload = 'no'`. Custom hooks are namespaced `mm/*`.
* Every admin-post / AJAX handler: `current_user_can( 'manage_options' )` **and** a nonce
  (`check_admin_referer` / `check_ajax_referer`). No exceptions.
* Sanitize on input (`sanitize_text_field`, `absint`, explicit whitelists like
  `MM_Settings::ENVIRONMENTS`). Escape on output at the echo site (`esc_html`, `esc_attr`,
  `esc_url`, `wp_kses_post`).
* All user-facing strings via `__()` / `esc_html__()` / `esc_html_e()` with text domain
  `money-maker`.
* HTTP only via `wp_remote_*`. Surface failures as `WP_Error`; show them to the admin with
  `add_settings_error` (persisted across redirects via the `settings_errors` transient).
* **Never** echo or log a full token or account number — mask to the last 4 characters.

**JS / CSS / frontend**
* Enqueue with `wp_enqueue_script` / `wp_enqueue_style`, version `MM_VERSION`, and only on
  this plugin's own admin screen (check the `$hook_suffix`).
* Assets live in `money-maker/assets/`. Vanilla JS, no build step, no bundler. jQuery only
  if a real need appears. No new JS/CSS dependencies without asking.
* Pass server data to JS with `wp_localize_script` (nonces, ajax URL, strings) — no inline
  `<script>` blobs.

## 9. Current Status
_Last updated: 2026-09-07 — keep this section current._
* **Milestone 1 COMPLETE and merged to `main`.** PR #1 squash-merged as `daa3deb`,
  branch `milestone-1-auth` deleted, tagged `v0.1.0`. All of M1a–M1e manually tested and
  confirmed working (incl. against a real Questrade practice account). Next work starts
  from `main` on a new `milestone-2-sync` branch.
  * **M1a — done, committed (`786be75`), manually tested.** Bootstrap wiring in
    `money-maker.php` (`mm_bootstrap`, activation seeds `mm_settings`, deactivation
    releases lock); `includes/class-mm-settings.php` (`MM_Settings` singleton — options
    page under Settings, practice/live radio, `admin_post_mm_save_settings`);
    `assets/admin.css`; `uninstall.php`.
  * **M1b — done, committed (`c79734d`), manually tested.** `includes/class-mm-crypto.php` (`MM_Crypto` — stateless static utility, no
    hooks/`register()`; `sodium_crypto_secretbox` encrypt/decrypt with `mmc1:` base64
    payload prefix; key resolution `MM_CRYPTO_KEY` constant →
    `WP_CONTENT_DIR/mm-crypto-key.php` → none; `generate_key()` writes the file
    atomically via `wp_tempnam`+`rename`, `chmod 0600`, fires `mm/crypto/key_generated`).
    `MM_Settings` gains an "Encryption key" section (status, non-reversible key
    fingerprint, generate/rotate button → `admin_post_mm_manage_crypto_key`) — render
    split into `render_environment_section()` / `render_encryption_section()`, redirect
    logic extracted to `persist_notices_and_redirect()`. `uninstall.php` deletes the key
    file. `.gitignore` ignores `mm-crypto-key.php`. Rotate uses an inline `onsubmit`
    confirm attribute (not a `<script>` blob).
  * **M1c + M1d — done, committed (`a1dc0fa`, `e5fbf39`), manually tested.** Three
    static-utility classes, all `require_once`d from `money-maker.php` (order: crypto →
    lock → token-store → client → settings → admin):
    * `includes/class-mm-lock.php` (`MM_Lock`) — option-based advisory lock
      (`mm_token_lock`, autoload no) around every refresh. `acquire($max_wait=12)`
      spin-waits (250ms poll); `add_option` is the compare-and-set primitive; a lock
      older than `STALE_SECONDS` (30) is taken over. Per-request owner UUID so
      `release()` never deletes a lock another process took over. `release()` called
      defensively on deactivation and "forget token".
    * `includes/class-mm-token-store.php` (`MM_Token_Store`) — the whole token response
      (`access_token`, `refresh_token`, `api_server` [untrailingslashit], `token_type`,
      computed `expires_at` = now + expires_in − 60s skew margin, `obtained_at`,
      `environment`) JSON-encoded → `MM_Crypto::encrypt` → option `mm_token_bundle`
      (autoload no). Rolling `refresh_history` (last 5, newest first) inside the same
      bundle. `store_from_response()` is the write path; UI only ever sees masked values
      (`mask()` → last 4 chars). `environment()` used for practice/live mismatch checks.
    * `includes/class-mm-questrade-client.php` (`MM_Questrade_Client`) — OAuth hosts per
      env (`practicelogin` / `login`; `api_server` for data calls always from the
      bundle). `exchange_refresh_token()` and `get_valid_token($force=false)` both run
      under `MM_Lock` and re-read the bundle inside the lock (another process may have
      just refreshed); new bundle is persisted *before* returning. Proactive refresh at
      `PROACTIVE_REFRESH_MARGIN` (300s) before expiry. `request($method,$path,$args)`:
      up to 3 attempts, 401 → one forced refresh + retry, 429 → sleep `Retry-After`
      (capped 10s) + retry. `test_connection()` = `GET v1/time`. Token endpoint call is
      `GET {login-host}/oauth2/token?grant_type=refresh_token&refresh_token=…` (per
      Questrade docs — no client id/secret for a personal app).
  * Token flows: refresh-token paste field (`<input type=password>`, **not**
    `sanitize_text_field` — trimmed only, tokens are opaque) → `admin_post_mm_save_refresh_token`;
    "Forget stored token" → `admin_post_mm_clear_token`; masked recovery history;
    "Test connection" → `wp_ajax_mm_test_connection` (nonce `mm_test_connection`).
    `assets/admin.js` (vanilla `fetch`, no jQuery) + `wp_localize_script( 'mmAdmin', … )`.
    `uninstall.php` deletes `mm_token_bundle` + `mm_token_lock`.
  * **M1e — done, committed (`ed11c39`), manually tested.** `includes/class-mm-admin.php` (`MM_Admin` singleton,
    registered last in `mm_bootstrap`). Own **top-level** menu "Money Maker"
    (`dashicons-chart-area`, pos 58) at `admin.php?page=money-maker`, no longer under
    Settings. Three screens as tabs:
    * **Dashboard** (`money-maker`) — status hero (connected / env-mismatch / not-connected),
      an ordered "Finish setup" checklist while incomplete, the "Test connection" button,
      and a 4-card grid (Connection / Encryption / Environment / Data-sync-M2 preview).
    * **Connection** (`mm-connection`) — token status + API-server host + obtained-age,
      connect/re-connect form, recovery history, "Danger zone" forget-token.
    * **Settings** (`mm-settings`) — environment radio cards, encryption-key status +
      generate/rotate.
    Shared chrome (`open()`/`close()`): brand bar, env badge, tab nav, `<hr class="wp-header-end">`,
    flash notices. `assets/admin.css` fully rewritten — card layout scoped to `.mm-app`,
    green accent, uses `:has()` for the checked-radio card. `MM_Settings` no longer renders
    or registers a menu; `MM_Admin` owns `enqueue_assets` (scoped to its 3 page hooks),
    `ajax_test_connection`, `page_url()`, `redirect_with_notices()`, `render_notices()`.
  * **Admin notices:** do NOT use core's `settings_errors` transient + `settings-updated`
    query arg — it renders every notice twice. Handlers call `add_settings_error()` then
    `MM_Admin::redirect_with_notices( $slug )` stashes `get_settings_errors()` into a
    private `mm_admin_notices` transient; `MM_Admin::render_notices()` prints and clears it
    inside the page chrome. No `settings_errors()` call anywhere.
* Options in use: `mm_settings` = `{ environment }`; `mm_token_bundle` (encrypted bundle,
  autoload no); `mm_token_lock` (refresh lock, autoload no).
* Out-of-DB files: `WP_CONTENT_DIR/mm-crypto-key.php` (key file, gitignored, `chmod 0600`,
  written from the Settings screen).
* Plugin files: `money-maker.php` + `includes/class-mm-{crypto,lock,token-store,
  questrade-client,settings,admin}.php` + `assets/{admin.css,admin.js}` + `uninstall.php`.
* M1a–M1d manually tested and working. M1e (UI reorg) is code-only so far — needs a visual
  pass in wp-admin.
* No dependencies, no Composer/npm, no CI, no tests (manual testing only — see §7).
