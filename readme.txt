=== Loggedin - Session Manager: Limit Concurrent Logins & Auto Logout ===
Contributors: joelcj91,foxelabs
Tags: session management, concurrent login, auto logout, force logout, account sharing
Donate link: https://paypal.me/JoelCJ
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 3.4.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Session and login manager for WordPress. Limit concurrent logins, stop account sharing, auto logout idle users and force logout any device.

== Description ==

**Loggedin is a free session and login manager for WordPress.** It limits how many devices one account can be signed in on at the same time, signs out idle users automatically, and lets administrators sign any user out of every device. It stops account sharing on membership sites, online courses and subscription stores, without slowing logins down.

Loggedin works with the sessions WordPress already creates, through the standard `WP_Session_Tokens` API. It needs no cron jobs, no background polling and no external services, and it works with any theme and host.

= Loggedin at a glance =

* **What it does:** limits concurrent logins per account, logs out idle users, and manages every signed-in device.
* **Price:** free. Optional add-ons add per-role limits, per-user limits, a live sessions view and real-time logout.
* **Default:** one device per account. A new login signs out the others.
* **Where the settings are:** Users → Loggedin in the WordPress admin.
* **Requirements:** WordPress 6.5 or later, PHP 7.4 or later.
* **Privacy:** no external calls, no tracking, no data sent anywhere.
* **Made by:** [Foxe Labs](https://foxelabs.com/). Active since 2016.

= What you can do with Loggedin =

**Limit concurrent logins**

* Set how many devices an account can be signed in on at once, from 1 upwards.
* Choose what happens when someone goes over the limit:
    * **Logout Oldest** signs out the oldest device and lets the new login in.
    * **Logout All** signs out every other device, so the new login is the only one.
    * **Block New** refuses the new login and shows a message on the login screen.
* Leave administrators out of the limit, so nobody is locked out of their own site.
* Covers social login, magic-link and popup login plugins too, under Logout Oldest and Logout All.

**Auto logout idle users**

* Sign out sessions nobody has used for a set number of minutes.
* Show a countdown warning before the logout happens.
* Give each role its own idle timeout.
* The check runs on the server, so a forgotten open tab doesn't keep a session alive.
* Set how long a login lasts, with and without "Remember Me".

**Force logout and session management**

* End every session of any user from the Sessions page, the Users screen or WP-CLI.
* Log out all users on the site at once, instantly, even with thousands of accounts. Your own session stays signed in.
* See each user's session count and last login on the Users screen, with a sortable Last login column.
* Sign users out everywhere one at a time or with a bulk action.
* Sign users out of their other devices when their password changes or is reset.

**Lockout recovery**

* A user blocked by the limit can email themselves a link that signs out their other devices. It is offered only after the right password, and the link expires after 15 minutes.
* Write your own blocked-login message, with links.
* Tell users why they were signed out ("your account signed in on another device"), or send them to a page you choose.

**For your users**

* "Your sessions" on the profile screen lists each device a user is signed in on, where and when, with a Sign out button for each.

**For developers**

* WP-CLI commands: `wp loggedin sessions list`, `wp loggedin sessions destroy`, `wp loggedin sessions destroy-all` and `wp loggedin settings`.
* A REST API under `loggedin/v1`.
* Documented actions and filters for every decision: change the limit per user or role, exempt service accounts, log forced logouts, or add your own login rule. See the [developer docs](https://docs.foxelabs.com/software/loggedin/developer-docs).

= How the concurrent login limit works =

WordPress creates a session each time someone logs in: one per browser, per device. A phone and a laptop are two sessions. Two browsers on one laptop are two sessions too. Closing a tab doesn't end a session; it stays until the user logs out, it expires, or something ends it.

On every login, Loggedin counts the account's active sessions and compares the count with your limit. If the new login would go over it, Loggedin applies the rule you picked: sign out the oldest device, sign out every other device, or refuse the new login.

Changing the limit never signs anyone out on the spot. It applies from the next login.

= Who uses Loggedin =

* **Membership sites** on MemberPress, Paid Memberships Pro, Restrict Content Pro, s2Member or WooCommerce Memberships, to stop one paid account being shared by a group.
* **Online courses and LMS sites** on LearnDash, LifterLMS, Tutor LMS or Sensei, so each seat is used by the person who paid for it.
* **Subscription stores** on WooCommerce Subscriptions or Easy Digital Downloads, to keep subscriber accounts personal.
* **Intranets and client portals** that need one device at a time per staff or client account.
* **Communities** on BuddyPress or BuddyBoss, to cut down on shared and duplicate accounts.
* **Sites with security or compliance rules** (healthcare, finance, education) that require a session limit and an idle timeout.

= Add-ons =

Loggedin is complete on its own. These official [add-ons](https://foxelabs.com/software/plugins/loggedin) extend it:

* **[Active Sessions](https://foxelabs.com/software/plugins/loggedin/active-sessions):** see who is logged in right now, every device for each user, and sign out one session or all of them.
* **[Limit Per Role](https://foxelabs.com/software/plugins/loggedin/limit-per-role):** give each WordPress role its own concurrent login limit.
* **[Limit Per User](https://foxelabs.com/software/plugins/loggedin/limit-per-user):** set a different limit for one user from their profile.
* **[Real-time Logout](https://foxelabs.com/software/plugins/loggedin/realtime-logout):** when a session ends, the user's other open tabs go to the login screen straight away.

= Documentation =

* [Getting started](https://docs.foxelabs.com/software/loggedin/getting-started)
* [Login limits](https://docs.foxelabs.com/software/loggedin/general-settings)
* [Lockout and recovery](https://docs.foxelabs.com/software/loggedin/lockout-recovery)
* [Auto logout](https://docs.foxelabs.com/software/loggedin/auto-logout)
* [Force logout](https://docs.foxelabs.com/software/loggedin/force-logout)
* [Users screen and profile sessions](https://docs.foxelabs.com/software/loggedin/user-sessions)
* [Compatibility with social, magic-link and popup logins](https://docs.foxelabs.com/software/loggedin/compatibility)
* [WP-CLI commands](https://docs.foxelabs.com/software/loggedin/wp-cli)
* [Add-ons](https://docs.foxelabs.com/software/loggedin/addons/)
* [Developer docs: hooks, filters and REST API](https://docs.foxelabs.com/software/loggedin/developer-docs)

= Bug reports =

Report bugs on [GitHub](https://github.com/joel-james/loggedin/issues). For help using the plugin, use the [WordPress.org support forum](https://wordpress.org/support/plugin/loggedin/).

== Installation ==

1. In the WordPress admin, go to **Plugins → Add New Plugin**, search for "Loggedin" and click **Install Now**.
2. Click **Activate**.
3. Go to **Users → Loggedin** to set the login limit and choose what happens when it's reached.

On a new site the limit is one device per account, and a new login signs out the others. Turn on auto logout under **Users → Loggedin → Auto logout**.

== Frequently Asked Questions ==

= How do I limit concurrent logins in WordPress? =

Install and activate Loggedin, go to **Users → Loggedin → Login limits**, set the maximum number of active logins and choose a login logic: Logout Oldest, Logout All or Block New. Save. The limit applies from each user's next login.

= How do I prevent account sharing on my membership site? =

Set the limit to 1. With **Logout All** or **Logout Oldest**, a second person logging in signs the first one out, so sharing one account becomes annoying fast. With **Block New**, the second login is refused until the first device signs out. Loggedin works with MemberPress, Paid Memberships Pro, WooCommerce, LearnDash and other plugins that log users in through WordPress.

= How do I automatically log out inactive users? =

Go to **Users → Loggedin → Auto logout**, turn on idle logout and set the timeout in minutes. Users see a countdown before they are signed out, and each role can have its own timeout. It's free and built in since version 3.4.0.

= How do I force logout a user in WordPress? =

Go to **Users → Loggedin → Sessions**, enter the user's ID, email or username under **Force Logout** and click the button. Every session of that user ends immediately. You can also use **Sign out everywhere** on the user's row on the Users screen, or run `wp loggedin sessions destroy <user>`.

= How do I log out all users at once? =

Click **Logout All Users** on **Users → Loggedin → Sessions**, or run `wp loggedin sessions destroy-all`. It finishes instantly at any number of users because it sets one site-wide cut-off time instead of looping through accounts. Your own session stays signed in.

= How can I see who is logged in to my WordPress site? =

The Users screen shows each user's number of active sessions and their last login. Each user sees their own devices under **Your sessions** on their profile. For a live list of everyone signed in right now, with every device, add the [Active Sessions](https://foxelabs.com/software/plugins/loggedin/active-sessions) add-on.

= Can I set different limits for administrators and subscribers? =

Yes, with the [Limit Per Role](https://foxelabs.com/software/plugins/loggedin/limit-per-role) add-on. Each role gets its own limit, for example 5 for administrators and 1 for subscribers. A user with several roles gets the highest. To leave administrators out of the limit altogether, turn on **Don't limit administrators** in the free plugin.

= Can I set a different limit for one user? =

Yes, with the [Limit Per User](https://foxelabs.com/software/plugins/loggedin/limit-per-user) add-on, which adds a limit field to the user's profile.

= A user is locked out by Block New. What can they do? =

If self-unlock is on, the error shows a link after the right password. The user gets an email with a link that signs out all their devices, and can then log in. An administrator can also force logout the user from the Sessions page.

= Why was I signed out with "your account signed in on another device"? =

Someone logged in to your account on another device, and the site allows only a set number of devices at once, so this one was signed out. If it wasn't you, change your password.

= Will installing Loggedin or changing the limit log out current users? =

No. Loggedin acts only when someone logs in. Existing sessions stay until they expire, the user logs out or a later login replaces them. Turning on auto logout gives every session the full timeout from the moment you save.

= Does Loggedin work with WooCommerce, MemberPress, LearnDash and BuddyPress? =

Yes. Any plugin that logs users in through WordPress is covered with no extra setup. Social login, magic-link and popup login plugins are covered under Logout Oldest and Logout All; Block New can refuse them only when the plugin checks first, as Nextend Social Login does. Tested with WooCommerce, Ultimate Member, Nextend Social Login, Magic Login, Two Factor, Limit Login Attempts Reloaded, WPS Hide Login and User Switching. See [compatibility](https://docs.foxelabs.com/software/loggedin/compatibility).

= Is Loggedin the same as a "limit login attempts" plugin? =

No. Limit login attempts plugins block repeated wrong passwords to stop brute-force attacks. Loggedin limits how many devices a valid account can be signed in on at the same time. They do different jobs and work well together.

= Does Loggedin work with Redis or other session storage? =

Yes for Logout All, Block New, force logout and log out all users, which go through the standard `WP_Session_Tokens` API. Logout Oldest and the per-device lists need WordPress's default session storage in user meta.

= Does Loggedin slow down my site? =

No. Loggedin does its main work when someone logs in or out, and on its own admin screens. On other pages it only reads its settings and, with auto logout on, the signed-in user's last activity. There's no cron, no polling and no remote request. With auto logout on, a small script tracks activity in the browser and checks in with the server at most once a minute while the page is in use.

= Is Loggedin GDPR compliant? =

Loggedin calls no external services and sends no data anywhere. It uses the session records WordPress already keeps, which hold the IP address and browser of each login, and stores each user's last login time and, with auto logout on, when each session was last active. The profile screen shows only part of the IP address. Mention WordPress sessions in your privacy policy as you would without the plugin.

= How long does a WordPress login last? =

By default, 14 days with "Remember Me" checked and 2 days without. Change both under **Users → Loggedin → Auto logout → Session length**, or with the `auth_cookie_expiration` filter.

= Can I change the message shown when a login is blocked? =

Yes, under **Users → Loggedin → Lockout & recovery**. Developers can also use the `loggedin_error_message` filter:

```php
add_filter( 'loggedin_error_message', function ( $message ) {
    return 'Your account is already signed in on another device.';
} );
```

= Does Loggedin support WP-CLI? =

Yes. `wp loggedin sessions` lists, counts and ends a user's sessions, and `wp loggedin settings` reads and changes settings. Commands that end sessions ask for confirmation unless you pass `--yes`. Run `wp help loggedin` or see the [WP-CLI docs](https://docs.foxelabs.com/software/loggedin/wp-cli).

== Screenshots ==

1. Login limits: how many devices an account can be signed in on at once, and what happens when a new login goes over the limit.
2. Lockout and recovery: leave administrators out of the limit, write the blocked-login message, let blocked users sign out their other devices by email, and choose where signed-out devices go.
3. Auto logout: sign out idle sessions after a countdown, set timeouts per role, sign out other devices on a password change, and set how long a login lasts.
4. Sessions: sign one user out of every device, or sign out every user on the site at once.
5. The Users screen with Sessions and Last login columns, and a Sign out everywhere link for each user.
6. Your sessions on the profile screen: each user sees the devices they're signed in on and can sign any of them out.
7. A login refused by Block New, with the link that emails the user a way to sign out their other devices.
8. The countdown shown before an idle session is signed out.

== Changelog ==

= 3.4.1 =
* Fix: The login screen no longer shows a critical error when another plugin's login message filter returns nothing.
* Fix: A device signed out by a newer login no longer gets a critical error on its next page on WordPress 6.5, or on sites that set `COOKIE_DOMAIN` to false.
* Fix: Admin screens that another plugin prints outside wp-admin no longer show a critical error.
* Fix: Loggedin no longer causes a critical error when another plugin or an add-on returns nothing from one of its filters, or passes something other than a user to the login check.

= 3.4.0 =
* New: Settings screen rebuilt with a sidebar of pages — Login limits, Lockout & recovery, Auto logout, Sessions, Add-ons and Help — and Save in the page header.
* New: Self-unlock — a user blocked by Block New can email themselves a link that signs out their other devices. Only offered after the right password; links are single-use and expire after 15 minutes.
* New: "Don't limit administrators" setting.
* New: Set the blocked-login message on the Lockout & recovery page.
* New: A device signed out by a newer login is told why on the login screen, or sent to a page you choose.
* New: Auto logout is now free and built in — idle logout with a countdown, timeouts per role and session length.
* New: A password change or reset can sign the user out of their other devices. On for new sites. A password an LDAP or SSO plugin copies in as the user signs in doesn't count.
* New: Sessions and Last login columns, and a "Sign out everywhere" action, on the Users screen.
* New: "Your sessions" on the profile screen.
* New: Logout Oldest and Logout All now also apply to logins that skip the login form (social login, magic links, popup logins).
* New: Switching to a user with User Switching isn't treated as their login, so it signs out none of their devices.
* New: Add-ons can add pages, cards and fields from PHP with the `loggedin_settings_schema` filter.
* New: `loggedin_sessions_displacing` action before Logout All or Logout Oldest ends sessions, and `loggedin_uninstalled` after the plugin is deleted.
* New: `loggedin_settings_properties` filter, so an add-on's own key in Loggedin's settings is saved, and `loggedin_session_device` to name the devices in a user's session list.
* New: A notice lists add-ons that need an update for the new settings screen.
* Improve: Block New checks the password first. A wrong password gets WordPress's usual error, not the limit message.
* Improve: Add-ons built for the old screen keep working, and links from before 3.4.0 still open the right page.
* Improve: `wp loggedin settings set` accepts true/false, yes/no, on/off or 1/0 for on/off settings.
* Improve: Keyboard, screen reader and contrast fixes on the settings screen, the auto logout warning and the profile's sign-out buttons. The current page and focused fields stay visible in Windows high contrast mode.
* Improve: Login modes added by other plugins through `loggedin_logics` can be saved.
* Improve: `loggedin_destroy_all_sessions` now fires for every way a user is signed out everywhere, including the Users screen, a password change and the unlock email.
* Improve: `POST /loggedin/v1/settings` accepts every setting, not only the limit and the login mode.
* Improve: Fewer database queries on each page: settings that are often unset are stored so they load with the rest, and pages that don't sign anyone in no longer load the translations to check a setting.
* Fix: Checking a password without signing in, such as WooCommerce's "current password" field, an application password, or the first step of two-factor login, no longer signs the user out of their other devices under Logout All or Logout Oldest. With a two-factor plugin, devices are signed out only once the code is entered.
* Fix: The Block New message, and its unlock link, now show through Ultimate Member's login form and Limit Login Attempts Reloaded, which replaced them with an "incorrect password" error.
* Fix: The old force-logout link now also checks that the user can manage options.
* Fix: Logout Oldest skips sessions that already expired, and ends as many as needed when the limit was lowered.
* Fix: `wp loggedin sessions list` no longer shows leftover sessions on a site that stores sessions elsewhere, such as Redis.
* Fix: Deleting the plugin now also removes the review notice's settings, and on multisite removes Loggedin's settings from every site.
* Changed: Requires WordPress 6.5.
* Changed: Renamed to "Loggedin - Session Manager: Limit Concurrent Logins & Auto Logout", now that auto logout is built in.
* Changed: Force Logout moved to Manage › Sessions, and Support is now Help.
* Changed: `loggedin_session_invalidated` passes the hashed session token instead of the raw one.
* Changed: `loggedin_error_message` also passes the user, and `loggedin_init` passes the `Plugin` instance.
* Changed: Sites updating from an earlier version keep self-unlock, the administrator exemption and sign-out on password change off until turned on.

= 3.3.1 =
* Fix: Some valid add-on license keys were rejected as invalid. Keys containing a `%` followed by two characters from 0–9 or a–f, in either case (for example `%bB`) were shortened before being sent to the licensing server. Affected add-ons could not be activated and so stopped receiving updates.

= 3.3.0 =
* New: **Logout All Users** — sign out every user on the site in one click from the Force Logout panel, with a confirmation dialog. Instant at any user count, and the admin who triggers it stays logged in.
* New: `wp loggedin sessions destroy-all` — the same site-wide logout from WP-CLI.
* New: REST endpoint `POST /loggedin/v1/sessions/destroy-all` backing the admin button.
* New: `loggedin_logout_all_users` action fired when a site-wide logout is triggered, and `loggedin_session_invalidated` action fired for each stale session as it is rejected.
* Improve: The site-wide logout goes through the standard `WP_Session_Tokens` API, so it works with any session storage backend.

= 3.2.0 =
* Improve: Faster settings reads — the plugin's settings are now built once per request instead of on every read, cutting repeat work on each login and admin page load.
* Improve: Loggedin is now published under the Foxe Labs brand. Nothing about how the plugin works has changed.
* Improve: Updated every link in the plugin to the new foxelabs.com product pages and docs.foxelabs.com documentation.
* Improve: Removed the leftover Loggedin section on Settings → General. It only pointed at the real settings page and has been redundant since 2.0 — settings live under Users → Loggedin.
* Improve: Dropped the manual translation loading call — WordPress has loaded translations for wordpress.org plugins by itself since 4.6, so the call was redundant.
* Compat: Tested with WordPress 7.1.

= 3.1.0 =
* New: WP-CLI support — manage Loggedin from the command line with `wp loggedin sessions` (list, count, destroy) and `wp loggedin settings` (list, get, set). Run `wp help loggedin` for the full reference.
* New: `wp loggedin sessions destroy <user> --token=<hash>` signs a user out of a single device instead of all of them.
* New: `loggedin_cli_init` action so add-ons can register their own subcommands under the `wp loggedin` namespace.
* New: `loggedin_destroy_session` action fired when an individual session is destroyed.
* Improve: Renamed to "Loggedin - Session Manager, Limit Concurrent Logins & Force Logout" to reflect the plugin's broader scope. Nothing about the concurrent-login limiting has changed — same settings, same behaviour, same plugin.
* Improve: The CLI refuses to write a setting the sanitizer would reject, so a typo can no longer silently reset your login logic to the default.
* Improve: CLI commands are only loaded on WP-CLI requests — a normal page load doesn't pay for them.

= 3.0.2 =
* New: Review-request notice restored, powered by the `duckdev/wp-review-notice` library and scoped to the Loggedin settings screen with a 7-day delay.
* Improve: Legacy review-notice state migrated to the new storage keys so users who already dismissed the prompt stay dismissed.
* Improve: Admin notices now render inside the plugin's centered page column instead of above the header.
* Fix: Left-hand gap between the plugin header and the admin sidebar caused by WordPress's default `#wpcontent` padding.

= 3.0.1 =
* New: `loggedin.admin.tabs` JS filter — addons can register their own React component as a tab in the Loggedin admin nav, with optional `before` / `after` positioning hints. Powers the new Active Sessions addon.
* New: Cross-sell banner on the Force Logout panel routed through `loggedin.settings.force_logout.cross_sell` so addons can hide or replace it once installed.
* Improve: Addon card layout reworked — primary CTA pinned to the left of the footer, "More details" link on the right, title-cased license button labels.
* Fix: The v2 → v3 settings migration never ran on existing installs, leaving legacy option keys in place after the upgrade.

= 3.0.0 =
* New: Modern React-powered admin under Users → Loggedin with two tabs — Settings (concurrent-login limit + login logic + Force Logout panel) and Add-ons (catalogue + license management).
* New: REST API at `/loggedin/v1/` for settings, session management and add-on licensing.
* New: Unified `loggedin_settings` option registered with `show_in_rest`, readable and writable by the React admin and by external integrations through the standard core-data flow.
* New: Force Logout panel now accepts a user ID, email or username — the resolver detects the input shape automatically.
* New: Add-ons module powered by Freemius — official add-ons (Real-time Logout, Limit Per User, Limit Per Role) self-register via the new `loggedin_register_addon` filter and appear in the Add-ons tab.
* New: JavaScript extension slot — add-ons can append their own React `PanelBody` to the Settings tab via the `loggedin.settings.panels` filter.
* New: Documented PHP hook surface — `loggedin_init`, `loggedin_settings_defaults`, `loggedin_admin_script_vars`, `loggedin_addons_catalog`, `loggedin_destroy_oldest_session` and more.
* Improve: Reorganised plugin structure (PSR-4 namespaces under `DuckDev\Loggedin\`) and aligned with WordPress Coding Standards.
* Improve: Comprehensive sanitisation pass across every input and option write path.
* Improve: PHP 7.4 is now the minimum supported version.

For the full release history, see the [changelog](https://docs.foxelabs.com/software/loggedin/changelog).

== Upgrade Notice ==

= 3.4.0 =
New settings screen, free built-in auto logout, self-unlock by email and sessions on the Users screen. Requires WordPress 6.5. Update your Loggedin add-ons too.

= 3.3.1 =
Fixes some valid add-on license keys being rejected as invalid. Update, then activate your add-on license again if it failed before.
