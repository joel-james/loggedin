# CLAUDE.md — Loggedin

## What this is
Loggedin is a free WordPress.org plugin (slug `loggedin`, by Foxe Labs) that limits how many
devices an account can be signed in on at once, and manages those sessions: Logout All, Logout
Oldest or Block New when the limit is reached, self-unlock by email, auto logout, a Sessions
page for forced sign-outs, and WP-CLI commands. Paid add-ons are separate plugins sold through
Freemius: Active Sessions, Limit Per Role, Limit Per User and Real-time Logout.

- **Plan:** `ROADMAP.md` holds the phases, the product decisions and the progress log. Engineering
  decisions go in `docs/decisions.md` (newest first).
- **Branches:** `master` holds released code. Work on a feature branch; merge only when the owner asks.
- **User and developer docs:** docs.foxelabs.com/software/loggedin, in the VitePress repo
  `foxelabs/docs` (local checkout `~/Work/FoxeLabs/docs.foxelabs.com`, pages in
  `software/loggedin/`). Never edit `~/Sites/duckdev/docs`; it is the old Duck Dev site.
  `developer-docs.md` there is the hooks, REST and class reference: update it when a hook, route
  or public class changes.
- **Admin UI:** follow the Foxe Plugin Admin Kit design system
  (https://claude.ai/artifact/3GMTBRMn2xZB5jk7wZfX9w), ported from Convocept. Components live in
  `assets/src/ui/` (prefix `loggedin-`), free of Loggedin logic. Add-ons reach them through
  `window.loggedin.ui`.

## Non-negotiables
- **Lightweight.** No cron, no polling and no remote calls on a normal page load. Work happens at
  login, logout, or on the plugin's own screens.
- **`session_tokens` is shared state.** WordPress, core Loggedin and Active Sessions all rewrite
  that one user meta blob without locking. Nothing writes it often: per-session data that changes
  often goes in its own user meta row keyed by the session verifier.
- **Add-ons update on their own.** Add-ons check `\FoxeLabs\Loggedin\Plugin` and
  `Plugin::VERSION`, and use `loggedin_`-prefixed hooks and the JS extension points listed in
  `ROADMAP.md`. Keep each one working for at least one release after it is replaced.
- **Backward-compatible defaults.** An existing site behaves the same after upgrading until the
  admin changes a setting.
- **WordPress.org guidelines:** no trialware, no locked features, no phoning home. Add-on hints
  are one quiet line under the setting they extend, hidden once the add-on is installed.

## Structure
- `loggedin.php` defines the path constants, loads Composer and calls `Plugin::instance()->boot()`.
- `src/Plugin.php` is the composition root. It builds every service once, passes dependencies
  through constructors, and calls each service's `register()`, which adds its hooks. Nothing else
  builds services and there are no singletons. Add-ons get the instance from `loggedin_init`.
- Core domains: `Sessions/` (limit enforcement, session store, logout epoch, last login),
  `Settings/`, `Admin/`, `Addons/`, `Rest/`, `Cli/`, and `Uninstaller.php`.
- `src/Modules/<Name>/` holds a feature that could be switched off without breaking core. Each has
  a class named after the module that implements `Modules\Module`, builds its own parts and
  registers them. A module may use core services, but never another module, and core never
  reaches into a module: they talk through hooks. Add a module to `Plugin::modules()`.
- Tests in `tests/php/` mirror `src/`.

## Conventions
- Namespace `FoxeLabs\Loggedin\`, PSR-4 from `src/`. Hooks, options, user meta, REST namespace
  (`loggedin/v1`), CSS classes and JS globals use the `loggedin` prefix. Text domain `loggedin`.
- Option and meta names live in class constants (`Plugin::OPTION_KEY`, `LastLogin::META_KEY`), and
  the Uninstaller reads those constants. A new stored key goes in both places.
- Minimum WordPress 6.5 and PHP 7.4. Use newer APIs through feature detection only.
- WordPress Coding Standards, with one deliberate deviation: PSR-4 file names in `src/`.
  PHPStan level 6. No ignore comment without a `--` reason.
- Every file, class, method, property, constant and hook has a docblock with `@since`, following
  the WordPress PHP documentation standards. A changed signature gets its own `@since` line.
  Comments explain why, not what.
- Every hook we fire gets a hook docblock directly above `do_action` / `apply_filters`, and an
  entry in the developer docs.

## Commands
- `composer install && npm install` — set up
- `composer lint` — phpcs and PHPStan; `composer lint:php:fix` — phpcbf
- `composer test` — PHPUnit. Needs the WordPress test suite: `bin/install-wp-tests.sh`, then
  `WP_TESTS_DIR` pointing at it if it isn't in the system temp directory
- `npm run build` / `npm start` — build the admin and front-end scripts into `build/`
- `npm run lint:js` — ESLint
- `npm run pack` — the WordPress.org release zip, in `releases/`

## Definition of done
1. Tests added or updated and passing; `composer lint` and `npm run lint:js` clean.
2. Existing sites keep their behaviour; add-ons on their current release keep working.
3. `readme.txt` changelog, the docs site, and `ROADMAP.md` progress updated when behaviour,
   labels, defaults, hooks or routes change.
4. Commit with a plain, clear message.
