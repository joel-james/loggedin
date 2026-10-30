# Decisions

Engineering decisions not covered by `ROADMAP.md`, which holds the product ones. Newest first.

## 2026-10-31 — Switched sessions and password sync
- **A session opened by User Switching isn't a login.** User Switching adds `switched_from_id` to
  the session it creates for the user switched to. When every new session in the request carries
  it, `SessionGuard` makes no room: support switching to a customer would otherwise sign the
  customer out of their devices. Reading the mark is possible because room is made at the end of
  the request, after User Switching's own `attach_session_information` filter (priority 99).
- **A password written during a login isn't a change.** LDAP, Active Directory and SSO plugins
  copy the directory password into WordPress on each login, through `wp_set_password()` or
  `wp_update_user()`. `PasswordSignout` skips while `authenticate`, `wp_authenticate_user` or
  `wp_login` is running; a real reset or profile change never runs inside them.
- **Sign out on password change is off for sites upgrading from before 3.4.0**, like self-unlock
  and the administrator exemption, under the roadmap's backward-compatible defaults rule. It had
  been left on for upgrades; new installs still get it on.

## 2026-10-30 — B6 third-party login tests
- **Room is made at the end of the request.** `attach_session_information` notes the user's
  sessions; on `shutdown`, if a session not in that list still exists, Logout All or Logout Oldest
  ends sessions from the list only. Two Factor (and WP 2FA) create a session after the password,
  then destroy it and ask for the code, so ending sessions at creation let someone with only the
  password sign every device out. The new session is never counted or ended, which also covers a
  `loggedin_reached_limit` filter that always says yes. With a custom session backend the sessions
  can't be listed, so room is still made at once there.
- **`SessionStore::end_all()` takes verifiers to keep**, so Logout All at the end of the request
  still goes through the one sign-out path and fires `loggedin_destroy_all_sessions`.
- **Block New treats an empty password as vouched for.** WordPress never runs
  `wp_authenticate_user` without a password; Nextend Social Login's "Login restriction" calls it
  with `''` after the provider confirms the user. Checking the password first (3.4.0) had let
  those logins through.
- **The limit message is put back after other plugins rewrite it.** Limit Login Attempts Reloaded
  replaces every login error on `login_errors`; `SessionGuard` restores ours at priority 99 when it
  refused the login in that request. It only appears after the right password, so it reveals no
  accounts. Ultimate Member shows unknown error codes as "Password is incorrect", so the code is
  added to `um_custom_authenticate_error_codes`. Both are a filter each, with no check for the
  other plugin.
- Not fixed: LLAR counts a Block New refusal as a failed login (it listens on `wp_login_failed`,
  which WordPress fires for any refusal). Documented on the compatibility page.

## 2026-10-30 — A5 QA fixes
- **An idle window starts no earlier than the last timeout change.** A session with no activity
  stamp counted from its login, so turning idle logout on (or shortening it) signed out at once
  every session older than the timeout, the admin who saved it included. `Settings` records when
  an idle timeout changes (`loggedin_auto_logout_changed`, autoloaded) and `IdleGuard` counts from
  the later of that and the login. A pruned stamp still ages out: its window had already closed.
- **The browser counts from the server's stamp.** The server rewrites a stamp only every quarter
  of the timeout, so its deadline could fall before the browser's, and any background request
  (Real-time Logout's poll, the heartbeat) then ended the session with no warning.
  The script gets `lastActive` and counts from the older of that and local activity; when a timer
  is due and there is activity the server hasn't heard of, it pings and counts again instead of
  warning. A ping answered with a 400 (no session) goes straight to the login notice.
- **Every idle sign-out lands on `wp-login.php?loggedin_auto_logout=1`.** The flag used to sit
  inside `redirect_to` or on the home page, where the login screen never saw it.
- **`--loggedin-theme-text`** (`--wp-admin-theme-color-darker-10`) for theme-coloured text on a
  tint or on the grey page; the plain theme colour is below 4.5:1 there in seven of nine schemes.
  The Foxe Plugin Admin Kit artifact should take the same token.

## 2026-10-30 — Review of core and add-ons
- **Room is made only as a session is created.** `SessionGuard` no longer hooks `check_password`:
  that filter runs on every password check, not only logins, so a "current password" field, an
  application password or a 2FA first step ended sessions with nothing to replace them.
  `attach_session_information` covers every session that is created. `validate_allow_logic()`
  stays public for code that calls it.
- **One "sign out everywhere".** `SessionStore::end_all( $user_id, $keep_current )` ends the
  sessions through `WP_Session_Tokens` and fires `loggedin_destroy_all_sessions`. Every path
  (Logout All, settings screen, REST, WP-CLI, Users screen, password change, unlock email) calls
  it and fires its own, narrower hook as well.
- **One settings schema.** `Settings::properties()` feeds the option's REST schema, the
  `/loggedin/v1/settings` args and `sanitize()`. Add-on keys come in through
  `loggedin_settings_properties` and are sanitised against their schema; built-in keys can't be
  replaced. Before, a key added through `loggedin_settings_defaults` alone was dropped on save.
- **Options read on every request are stored on install or upgrade** (`Upgrader::autoload_options()`),
  so they autoload. Without a persistent object cache a missing option costs a query each request.
  The exempt-administrators flag is set to 0 when answered rather than deleted, for the same reason.
- **`Plugin::asset( $entry, $fallback_deps )`** reads a `build/*.asset.php` manifest for both the
  admin bundle and the idle timer. Each add-on has its own copy: they share no code with core.
- **`Admin::SCREEN`** is the one copy of the screen id `users_page_loggedin`.
- **`Endpoint::register()`** replaces `hook()` plus a `register()` in each controller.
- Not done: memoizing `Addons::get_registered_addons()`. Add-ons register on that filter as their
  main file loads, and a cached copy taken earlier would miss later ones.

## 2026-10-30 — Structure shared with Convocept
- **Layout:** PSR-4 classes in `src/`, grouped by domain, with tests in `tests/php/` mirroring
  them, as in Convocept. The `FoxeLabs\Loggedin\` namespace stays: add-ons check
  `\FoxeLabs\Loggedin\Plugin` and `Plugin::VERSION`.
- **Composition root:** `Plugin` builds every service once and passes dependencies through
  constructors. The `Singleton` trait and `Core` are gone. `loggedin_init` passes the `Plugin`
  instance instead of `Core`; no add-on read the argument.
- **Modules** (`src/Modules/`) go one step past Convocept. Auto Logout, Lockout, Password Signout
  and User Sessions each have a module class that builds and registers its parts. A module uses
  core services but never another module, and core never calls into a module. Two direct calls
  became hooks so that holds:
  - `SessionGuard` fires `loggedin_sessions_displacing( $user_id, $verifiers )` before Logout
    All or Logout Oldest ends sessions. Lockout's `Displaced` records them from it.
  - `loggedin_error_message` passes the `WP_User` as a second argument. Lockout's `Unlock`
    appends the self-unlock link on it at priority 100, after site filters have changed the text.
  - Auto Logout adds its own `autoLogout` entry to the admin script data through
    `loggedin_admin_script_vars`.
- **`Settings::register()` became `register_option()`**: `register()` is now the method every
  service uses to add its hooks.
- **No `Lifecycle` class.** Convocept needs one for migrations and cron; Loggedin has neither.
  Upgrades run from `Plugin::boot()` when the stored version is older.
- **`Uninstaller`** takes over from the inline code in `uninstall.php` and reads option and meta
  names from class constants. That fixed a gap: since 3.0.2 the review notice stores
  `loggedin_review_time` (a network option) and `loggedin_review_dismissed` (user meta), but
  uninstall still deleted only the pre-3.0.2 names. On multisite it now removes options from
  every site. `session_tokens` and the shared Freemius activation option stay.
- **No `docs/hooks.md`.** The developer docs page on docs.foxelabs.com is the hooks reference,
  and a second list here would drift from it.
- **PHPStan level 6** with the WordPress extension. WP-CLI is stubbed in
  `tests/phpstan/wp-cli-stubs.php` rather than pulling in the WP-CLI package.
  `SessionGuard::has_limit_reached()` is marked `@phpstan-impure`: it counts live sessions, and
  Logout Oldest calls it again after each session it ends.
