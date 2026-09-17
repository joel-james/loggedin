# Loggedin — Session Manager, Limit Concurrent Logins & Force Logout

A session manager for WordPress — limit concurrent logins, stop account sharing, and force logout any user's active sessions.

[![Plugin Version](https://img.shields.io/wordpress/plugin/v/loggedin.svg?style=flat-square)](https://wordpress.org/plugins/loggedin/)
[![License](https://img.shields.io/badge/license-GPL_v2%2B-blue.svg?style=flat-square)](https://opensource.org/license/GPL-2.0)
[![WordPress Tested](https://img.shields.io/wordpress/v/loggedin.svg?style=flat-square)](https://wordpress.org/plugins/loggedin/)
[![Build Status](https://img.shields.io/badge/tests-passing-brightgreen.svg?style=flat-square)](https://github.com/joel-james/loggedin/actions)

Loggedin manages the login sessions your users hold and gives you the tools to end them. Its core job is enforcing a per-account session limit across every device a user signs in from: when the limit is hit, you choose what happens next — block the new login, or kick the oldest session out so the new device can take its place.

## Features

- **Session management** — inspect and end user sessions from the admin UI or WP-CLI.
- **Concurrent-session cap** — set a global maximum number of simultaneous logins per account.
- **Two enforcement modes** — block the new login, or auto-logout the oldest active session.
- **Force-logout tool** — terminate every active session for any user from the admin UI.
- **Logout all users** — sign out every account on the site at once via a session-validity epoch: one option write instead of a per-user loop, so it's instant at any user count and the triggering admin stays logged in.
- **Modern settings UI** — built with `@wordpress/components` and Gutenberg patterns, not a hand-rolled options page.
- **REST-backed** — all settings flow through the WordPress REST API.
- **WP-CLI commands** — inspect and destroy sessions and read/write settings from the shell; see [WP-CLI](#wp-cli).
- **Extensible** — addons hook into the settings UI via the `loggedin.settings.panels` JS filter and into the bootstrap via the `loggedin_init` action.
- **Privacy-respecting** — no third-party calls beyond Freemius for licensed addons.

## Premium addons

| Addon | Description |
| --- | --- |
| [Active Sessions](https://foxelabs.com/software/plugins/loggedin/active-sessions) | Browse every user with a live session, drill into each device, and sign out one session — or all of them — in one click. |
| [Auto Logout](https://foxelabs.com/software/plugins/loggedin/auto-logout) | Sign users out after a set period of inactivity, with an optional warning before the session ends. |
| [Limit Per User](https://foxelabs.com/software/plugins/loggedin/limit-per-user) | Override the global cap for individual users. |
| [Limit Per Role](https://foxelabs.com/software/plugins/loggedin/limit-per-role) | Set custom caps per WordPress role; highest applicable wins. |
| [Real-time Logout](https://foxelabs.com/software/plugins/loggedin/realtime-logout) | Detect background-terminated sessions and log the user out immediately. |

## Documentation

Full documentation lives at [docs.foxelabs.com](https://docs.foxelabs.com/software/loggedin/getting-started):

- [Getting started](https://docs.foxelabs.com/software/loggedin/getting-started)
- [General settings](https://docs.foxelabs.com/software/loggedin/general-settings)
- [Force Logout (Manage Sessions)](https://docs.foxelabs.com/software/loggedin/force-logout)
- [WP-CLI commands](https://docs.foxelabs.com/software/loggedin/wp-cli)
- [Developer docs — hooks, filters, REST](https://docs.foxelabs.com/software/loggedin/developer-docs)
- [Changelog](https://docs.foxelabs.com/software/loggedin/changelog)

## Requirements

- PHP **7.4+**
- WordPress **6.0+**

## Installation

**From WordPress.org**

1. Plugins → Add New → search "Loggedin".
2. Install and activate.
3. Configure under **Settings → Loggedin**.

**From source**

```bash
git clone https://github.com/joel-james/loggedin.git
cd loggedin
composer install --no-dev
npm install && npm run build
```

Then symlink or copy the directory into wp-content/plugins/ and activate.

## WP-CLI

Commands are registered only on WP-CLI requests, so they cost a normal page load nothing.

```bash
# Sessions — <user> accepts an ID, username or email address.
wp loggedin sessions list <user>                    # active sessions, newest first
wp loggedin sessions list <user> --field=token      # token hashes only
wp loggedin sessions count <user>
wp loggedin sessions destroy <user> --yes           # sign out everywhere
wp loggedin sessions destroy <user> --token=<hash>  # sign out one device
wp loggedin sessions destroy-all --yes              # sign out every user on the site

# Settings
wp loggedin settings list
wp loggedin settings get maximum
wp loggedin settings set maximum 3
wp loggedin settings set logic block                # allow | logout_oldest | block
```

`sessions list` supports the standard `--format`, `--fields` and `--field` flags. Destructive commands prompt unless `--yes` is passed, and `settings set` refuses a value the sanitizer would reject rather than silently storing the default.

Listing and single-session destroys read the token store directly and need the default user-meta session storage; `count` and full destroys work with any backend.

Add-ons can register subcommands under the same namespace on the `loggedin_cli_init` action.

## Development

```bash
composer install         # PHP dependencies (incl. dev tools)
npm install              # JS dependencies
npm run start            # JS watch build
npm run build            # production JS build
composer test            # PHPUnit
composer run phpcs       # WPCS lint
```

PHP code follows WordPress Coding Standards (see `phpcs.xml.dist`). JS uses `@wordpress/scripts` defaults.

## Extending

Plugin classes live under the `FoxeLabs\Loggedin\` namespace. It was
`DuckDev\Loggedin\` before 3.2.0; `DuckDev\Loggedin\Plugin` is kept as an
alias so existing add-ons keep booting, but new code should use the current
name.

Addons register themselves via two hooks wired at file load:

```php
// Register with the parent's Freemius-addons map.
add_filter( 'loggedin_register_addon', [ Plugin::class, 'register_addon' ] );

// Boot once the parent's modules are ready.
add_action( 'loggedin_init', [ Plugin::class, 'boot' ] );
```
Addons can contribute a panel to the Settings tab from JS:

```javascript
import { addFilter } from '@wordpress/hooks';

addFilter(
    'loggedin.settings.panels',
    'my-addon/panel',
    ( panels ) => [ ...panels, { id: 'my-addon', Component: MyPanel } ]
);
```

## Contributing
Pull requests welcome. Please read contributing.md and run composer test + composer run phpcs before opening a PR.

## Security
If you discover a security issue, please follow the disclosure process in security.md rather than opening a public issue.

## License
[GPL-2.0+](./license) © [Joel James](https://joe.to/)

