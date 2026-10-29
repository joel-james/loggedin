# Decisions

Engineering decisions not covered by `ROADMAP.md`, which holds the product ones. Newest first.

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
