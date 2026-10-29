# Loggedin Roadmap — v3.4 to v4

Goal: grow Loggedin from a concurrent-login limiter into the complete session
and login manager for WordPress. The free core has to earn more installs and
fewer lockout reviews, and the paid add-ons have to sell something free
plugins don't already give away.

Decided 2026-09-26, built from the market and demand research of the same day
(wp.org API, the Loggedin and competitor support forums, competitor pricing).
Revised 2026-10-26: the admin UI moves to the Foxe Plugin Admin Kit, and the
Auto Logout add-on is retired into the free core (B8).

**Order is fixed:** Phase A (admin UI on the Foxe Plugin Admin Kit) ships
before any new core feature or add-on, so everything after it is built once,
on the new components.

---

## Research summary (why this plan)

- Loggedin: 8,000 installs, rating 98 from 111 ratings (2026-09-26).
- Auto Logout has no sales and no checkout visits. `inactive-logout` (10k
  installs) gives idle timeout, a countdown and per-role timeouts away free.
  Its paid tier ($39.99/yr) sells browser-close logout, login history and
  concurrent limits. An idle timeout can't be sold against that, so it
  becomes a free core feature instead (B8).
- Biggest forum complaint, about 14 threads plus most low reviews: Block New
  locks people out after they close a browser without logging out.
- About 14 threads ask for a custom limit message or a redirect. Today that
  is filter-only.
- About 20 threads ask for per-role, per-user or per-membership limits. Role
  and user limits are already sold; membership-level limits are not.
- About 10 threads report logins that bypass Loggedin: Nextend social login,
  magic links, popup logins, Ultimate Membership Pro.
- Login history is sold by at least five plugins, at $29.99 to $139/yr.
- PMPro folded the free WP Bouncer into its paid Limit Logins add-on.
  SessionQuota Pro (HandyPlugins, March 2026) clones the Loggedin add-on
  lineup. It sells membership limits, recovery links and a front-end session
  manager.

---

## Constraints that apply to every phase

1. **Lightweight.** No cron, no polling and no remote calls on a normal page
   load. Work happens at login, logout or on the plugin's own screens.
2. **`session_tokens` is shared state.** Nothing new writes it frequently.
   Per-login data goes in its own user meta row or table.
3. **Don't cannibalise Active Sessions.** Active Sessions owns the cross-user
   "who is signed in now" view and per-device detail for other users. Core
   may show counts, and a user's own devices to that user.
4. **Add-ons update independently of core.** Every JS and PHP extension point
   that add-ons use today keeps working through at least one release after it
   is replaced:
   - JS: `loggedin.admin.tabs`, `loggedin.settings.panels` and
     `loggedin.settings.force_logout.cross_sell`.
   - PHP: the add-on registration hooks.
5. **Backward-compatible defaults.** An existing install behaves the same
   after upgrading until the admin changes a setting. New defaults apply to
   new installs only.

---

## Phase A — Admin UI on the Foxe Plugin Admin Kit (v3.4.0)

Design system: **Foxe Plugin Admin Kit**,
https://claude.ai/artifact/3GMTBRMn2xZB5jk7wZfX9w. Read its
`project/README.md` before building. It looks like WordPress itself: the
user's own admin colour, WordPress's grey ground, white bordered cards, a
light sidebar of pages, and `@wordpress/components` underneath.

The reference implementation is Convocept
(`~/Sites/duckdev/wordpress/comments/wp-content/plugins/convocept`):
- commit `fbb2376` "Settings screen: rebuild on the Foxe Plugin Admin Kit"
- commit `d564b24` "Settings screen: only the active sidebar item in blue;
  icons on Help cards"
- source in `assets/src/admin/ui/` (`layout.js`, `fields.js`,
  `feedback.js`, `icons.js`, `ui.css`) and `assets/src/admin/app/`

Port from Convocept; don't redesign from scratch.

**Superseded:** the Foxe Labs Plugin UI port (the original plan) and the
"Night shift" design on the Foxe Plugin Admin UI (black sidebar, floating
save pill). The WIP for Night shift is commit `5e27361` on
`feature/plugin-ui`. Keep its non-visual work, which carries over unchanged:
`schema.js` and the `loggedin_settings_schema` filter, `hooks/use-save.js`,
the legacy panel and tab compatibility, the Force Logout card's logic and the
Help content. Throw away its visual layer: `_tokens.scss`, `_layout.scss`,
the black sidebar, `sticky-save-bar.js` and the floating Save.

### A1. Kit components and tokens
- Port the kit's `ui/` layer from Convocept: Sidebar, PageHeader,
  PageFooter, Card, Button, Badge, Toggle, NumberField, TextField,
  SelectField, TextareaField, CheckboxList, ChoiceTiles, Notice, Snackbar,
  StatusList, StatTiles, Table, AddonCard, AddonHint and BundleCard. Port
  ColorField, FileField, ProgressBar and Steps only when a screen needs them.
- Screens import the kit's wrappers, never `@wordpress/components` directly.
- The accent is `theme`, compiled to `var(--wp-admin-theme-color, #3858e9)`.
  Never set `--wp-admin-theme-color`.
- Scope every rule and token under the Loggedin root with the `loggedin-`
  prefix, so two Foxe Labs plugins on one site never collide.
- Delete the old styles `_page-header.scss`, `_page-body.scss` and
  `_footer.scss`, and the old `common/page-header.js` and `common/page-body.js`.

### A2. Screen anatomy
- **Sidebar:** the Loggedin 32px mark and name, a status line
  ("● On · 3.4.0"), then three groups:
  - **Settings:** Login limits, Lockout & recovery (B1, B2), Auto logout (B8).
  - **Manage:** Sessions.
  - **More:** Add-ons, Help (Help replaces the current Support tab).
  - Add-ons add pages to the group their topic belongs to (Login History
    adds Manage › Login history, Login Alerts adds Settings › Notifications).
    They never add a group, a tab or a banner.
  - Pages are URL-addressable (`#login-limits`).
- **PageHeader:** page title, and on settings pages the save status, Discard
  and Save. Save is disabled until something changes. No floating or sticky
  save bar.
- **Login limits page:** maximum sessions as a NumberField with its unit
  inside ("3 sessions"), and the login logic as ChoiceTiles, each option with
  one sentence. AddonHint lines under the limit point to Limit Per Role and
  Limit Per User, hidden once installed.
- **Sessions page:** the Force Logout card, holding:
  - the "Sign out a user" field and button
  - "Logout All Users" (secondary button with a confirm step)
- **Add-ons page:** the BundleCard first, then a grid of AddonCards. Prices
  appear only on the BundleCard.
- **Saving:** one Save for every card on the page, add-on cards included,
  through `use-save`. Snackbar "Settings saved."
- **PageFooter:** name, version, Foxe Labs, Docs, Support forum, Leave a
  review. Replace `admin_footer_text` on the Loggedin screen only.
- **Below 782px:** the sidebar shrinks to the brand row, the page list becomes
  a dropdown in the header with Save beside it, and controls grow to 44px.

### A3. Add-on extension API
- `loggedin_settings_schema` (from the WIP, modelled on `dcl_settings_schema`):
  - An add-on declares its page, the sidebar group it sits in, its Cards and
    its Fields (number, select, toggle, text, role-table) in PHP. An add-on
    that only extends an existing page declares Cards for that page.
  - Core draws them with the kit's controls and saves them through the
    shared Save.
  - Simple add-ons (Realtime Logout, Limit Per Role) can then drop their
    admin JS bundles.
- Keep `loggedin.settings.panels` working. A legacy panel renders inside a
  Card on the Login limits page, and its `PanelBody` chrome is neutralised by
  CSS, so an add-on that hasn't updated still looks acceptable.
- Keep `loggedin.admin.tabs` for add-ons that need a whole screen (Active
  Sessions). A legacy tab renders as a page in the Manage group.
- Add a minimum-core-version check. Core shows a Notice when an add-on is
  older than the version that supports the new UI.

### A4. Migrate the add-ons' admin UI (one release each, after core 3.4.0)
| Add-on | Current UI | Target |
|---|---|---|
| Active Sessions | `loggedin.admin.tabs` React tab | Rebuild on the kit as a Manage page. The kit has no DataViews table yet: add one to the kit, in its style, with this screen |
| Limit Per Role | settings panel | Move to the schema (role-table field) |
| Realtime Logout | settings panel | Move to the schema; drop its admin bundle |
| Limit Per User | profile field only | Restyle only |

Auto Logout is not migrated: it moves into core (B8).

### A5. QA checklist
- 1280px, 782px and 390px, keyboard only, RTL, and two admin colour schemes.
  The accent must equal the admin theme colour.
- Oldest supported WordPress (6.5) and trunk, with a check that fails on any
  deprecation warning.
- Contrast 4.5:1 for text, 3:1 for control edges and focus rings.
- Windows high contrast mode keeps status dots and outlines the active page.
- Every combination of old and new core and add-ons renders without a fatal
  error or a blank screen.
- Assets load on the Loggedin screen only. The PHPUnit suite is green.

---

## Phase B — Free core features (v3.5.0; split into 3.5 and 3.6 if large)

Build on the Phase A components. Each item lists the approach, then what to
check before or while building it.

### B1. Blocked-login self-unlock (highest priority)
`wp_authenticate_user` runs before WordPress checks the password, so Block
New checks it itself first: only a correct password learns the limit was
reached (a wrong one gets WordPress's own error). Use that moment:
- Issue a short-lived unlock token and store it hashed in its own user meta.
  It expires after 15 minutes and is single-use.
- Append "Email me a link to sign out my other devices" to the error.
- The link sends the user an email. The emailed link destroys all of their
  sessions, then returns them to the login form with a notice.
- Rate-limit to one email per user per 5 minutes.
- Settings, on the Lockout & recovery page:
  - Toggle: allow self-unlock by email. On by default for new installs.
  - Toggle: exempt administrators from the limit. On for new installs,
    unchanged on upgrade. It uses the existing `loggedin_bypass` filter.
- New action: `loggedin_unlock_requested`, `loggedin_unlocked`.
- Test the token's expiry, reuse, wrong user and rate limit.
- The error must never reveal whether an account exists: the link only
  appears after a correct password.

### B2. Limit message and redirect settings
- Setting for the blocked-login message: a text field, defaulting to the
  current string. The `loggedin_error_message` filter still wins.
- **Displaced notice:** the user signed out by a newer login currently sees
  nothing.
  - Record `loggedin_displaced_at` in user meta when Loggedin destroys a
    session (Logout Oldest and Logout All).
  - On `auth_cookie_bad_session_token`, the cookie still carries the
    username. If that user was displaced in the last few minutes, show "You
    were signed out because your account signed in on another device" on
    wp-login.
- Optional redirect URL for displaced users, for example a membership
  site's own page.

### B3. Users list integration
- Add a "Sessions" column: count from the user's tokens. The user meta is
  already primed for the listed users, so there are no extra queries per row.
- Add a sortable "Last login" column. It reads `loggedin_last_login` user
  meta, written once on `wp_login`. This is also the foundation for the Login
  History add-on and its dormant-accounts report.
- Row action and bulk action: "Sign out everywhere".
- Counts only, with no per-device detail (constraint 3).
- ~~Add a WP-CLI `last_login` field to `sessions list`.~~ Dropped: that
  command lists one user's sessions, and each row already has its `login`
  time. The per-user value is plain user meta (`wp user meta get <user>
  loggedin_last_login`).

### B4. "Your sessions" on the profile screen
- On the user's own profile, list their sessions: device, IP (shortened),
  last login and "This device".
- Each session gets a Sign out button, plus "Sign out everywhere else".
- Reuse core's existing user-agent parsing from `wp loggedin sessions list`.
- The per-session destroy writes `session_tokens`. Reuse the single guarded
  helper from Session Guard; don't write a third raw-meta implementation.
- On another user's profile: count and "Sign out everywhere" only. The
  per-device view there belongs to Active Sessions.
- A front-end shortcode or My Account version is **not** in core. It becomes
  an Active Sessions upgrade (Phase C4).

### B5. Sign out other sessions on password change
- Hook `after_password_reset` and password changes through `profile_update`.
  Destroy the user's other sessions and keep the current one when users
  change their own password.
- Toggle, on by default.
- **Check core first:** confirm what WordPress 6.5+ already destroys on reset
  and on profile change, so this only fills the gap.

### B6. Cover logins that bypass `authenticate`
- Social login, magic link and popup plugins call `wp_set_auth_cookie`
  directly, so today's `wp_authenticate_user` check never runs.
- Enforce Logout Oldest and Logout All when a session is created
  (`attach_session_information` or `set_auth_cookie`). Block New can't reject
  cleanly there, so document it as a limitation.
- Test against Nextend Social Login, a magic-link plugin, WooCommerce My
  Account, Ultimate Member and MemberPress.
- Publish a compatibility page on docs.foxelabs.com.

### B7. Readme, docs and store-page updates
- Rewrite the readme feature list around "session and login manager", with
  auto logout listed as a free feature.
- Add FAQ entries for self-unlock, the displaced notice, the Users list and
  auto logout.
- New docs pages under `~/Work/FoxeLabs/docs.foxelabs.com/software/loggedin/`.

### B8. Auto logout in core (retires the Auto Logout add-on)
Port `loggedin-auto-logout` 1.0.0 into core as a free feature, on the
Settings › Auto logout page. It is finished, tested code (`Idle_Guard`,
`Lifetime`, `Settings`, the `idle` script and its PHPUnit suite), so this is
a port, not a rebuild.
- **Idle logout:** timeout in minutes with the countdown first, enforced on
  the server, per-role timeouts with the shortest one winning.
- **Session length:** the normal and "Remember Me" cookie lifetimes, through
  `auth_cookie_expiration`.
- **Off by default**, on new and upgraded installs alike (constraint 5). With
  idle logout off, the idle script isn't enqueued and nothing is written.
- Keep the add-on's storage and hooks byte for byte, so an existing install
  carries over without a migration and its snippets keep working:
  `loggedin_auto_logout_settings`, `loggedin_auto_logout_role_timeouts`,
  `loggedin_auto_logout_activity` (keyed by the SHA-256 session verifier),
  the `loggedin_auto_logout_ping` AJAX action, and the
  `loggedin_auto_logout_timeout` and `loggedin_auto_logout_expired` hooks.
- Keep the activity stamp in its own user meta row, never in
  `session_tokens` (constraint 2). Port
  `test_stamping_never_writes_the_session_tokens_blob` with it.
- **The add-on still active:** core stops the add-on from booting (it
  unhooks the add-on's `loggedin_init` callback) and runs auto logout itself
  from the same settings, so nothing is enforced twice and the add-on's panel
  never loads. A Notice on Plugins and on Loggedin's screen says the add-on
  can be deactivated.
- **Fix in the port:** an idle logout during a REST request returns a bare
  401 that neither admin React app handles. Return an error the apps
  recognise and send the user to the login screen.
- **Retire the product:** check Freemius (id 38081) for any licence first,
  then stop selling it and remove it from the Add-ons page and the bundle.
  Move the docs from `software/loggedin/addons/auto-logout.md` to a core
  feature page, with a redirect from the old URL. Archive the
  `loggedin-auto-logout` repository after core ships.
- Pitch: parity with `inactive-logout`'s free tier, plus session length
  control and server-side enforcement, in the plugin that already manages
  the sessions.

---

## Phase C — New paid add-ons (after Phase B)

### C1. Loggedin – Login History (first)
- Custom table `{prefix}loggedin_log`. Columns:
  - user_id, event, time
  - ip, user-agent hash, device label
  - session verifier (joins with Active Sessions' `token_hash` and core's
    auto logout activity meta)
- Events: login, logout, blocked, displaced, force logout, unlock, idle
  logout and session invalidated. Core actions already exist for most; B1,
  B2 and B8 add the rest.
- Retention setting (default 90 days). Pruning happens on insert,
  probabilistically, never by cron.
- Screens:
  - a Manage › Login history page with filters and CSV export
  - a per-user history on the profile
  - a dormant-accounts report, built on B3's last-login meta
- **Sharing detection:** flag accounts with more than N distinct IPs or
  devices in X days, shown as a Badge in history and the Users list. It
  reports; it never blocks, so the parked IP-limit idea stays parked.
- Positioned at the $29–79 range. See Phase D bundle pricing.

### C2. Loggedin – Membership Limits (second)
- Per-level limits for MemberPress, Paid Memberships Pro, WooCommerce
  Memberships, WooCommerce Subscriptions, LearnDash groups, Restrict
  Content Pro and s2Member.
- One adapter per integration. Each adapter loads only when its plugin is
  active.
- Precedence: Limit Per User, then Membership, then Limit Per Role, then
  global. Within membership levels the most permissive wins, the same as
  Limit Per Role.
- Target buyers: displaced WP Bouncer users and PMPro users who don't want a
  $247/yr plan.

### C3. Loggedin – Login Alerts (third)
- As designed: one add-on with pluggable channels. Email and Telegram
  first, then Slack, Discord and webhooks.
- Adds the Settings › Notifications page.
- Asynchronous dispatch.
- New-device detection reads C1's history when it is active, and falls back
  to a small known-devices user meta otherwise.
- Two audiences:
  - the user: "new device signed in", with a link to B1-style sign-out
  - the admin: "this account looks shared", from C1's sharing flag

### C4. Active Sessions upgrade
- Front-end "My devices" shortcode and block, plus a WooCommerce My Account
  endpoint.
- "Last active" column from core's auto logout activity meta.
- Fix the open cross-add-on issue: an idle-but-not-yet-collected session is
  counted as active. (The bare-401 issue is fixed in core by B8.)

---

## Phase D — Commercial and distribution (runs alongside)

- **Bundle plan in Freemius:** all add-ons, future ones included, priced
  $59–79/yr for one site. Competitor anchors:

  | Plugin | Price per site per year |
  |---|---|
  | Loginizer | $24 |
  | User Login History | $29.99 |
  | Inactive Logout | $39.99 |
  | LoginWP | $49 |
  | Melapress | $79 |
  | Simple History | $79 |
  | LoginPress | $99 |
  | WP Activity Log | $139 |

- **Discovery:** the Add-ons page leads with the BundleCard. Each add-on is
  also linked from the matching docs pages and readme FAQ, and from one
  AddonHint line under the setting it extends. Measure checkout visits per
  add-on after 3.4.0.

---

## Decisions

Settled 2026-09-26 unless dated otherwise.

1. **Accent colour** (revised 2026-10-26): the user's admin colour, as the kit
   prescribes, not a Loggedin brand colour. The Loggedin mark is black and
   white: a simple SVG (a person with a lock or key theme) until a real logo
   exists.
2. **WordPress minimum:** raised to 6.5 in 3.4.0.
3. **CSS prefix:** `loggedin-`.
4. **Add-on promotion:** allowed wherever relevant, kept quiet. One AddonHint
   line under the setting it extends, hidden once installed. No badges,
   banners or repeated nags.
5. **Idle timeout in core** (revised 2026-10-26): yes, by retiring the Auto
   Logout add-on into core (B8). It was skipped on 2026-09-26 while the
   add-on was still on sale.
6. **"Exempt administrators" on existing installs:** unchanged on upgrade,
   with a one-time dismissible Notice suggesting it.
7. **Admin UI** (2026-10-26): the Foxe Plugin Admin Kit, ported from
   Convocept. It supersedes both the Foxe Labs Plugin UI port and Night
   shift.

---

## Progress

- [x] A1 kit components and tokens, ported from Convocept — branch
      `feature/admin-kit`, 2026-10-26. 2026-10-29: TextareaField and
      TableCard added; the kit is exposed to add-on scripts as
      `window.loggedin.ui`. Not ported: Badge (no screen uses it yet) and
      BundleCard (waits for the bundle plan in Phase D)
- [x] A2 screen: sidebar, Login limits, Lockout & recovery, Auto logout,
      Sessions, Add-ons, Help (BundleCard waits for the bundle plan in Phase D).
      Pages are addressed with `&tab=`; old `#hash` links still work.
      2026-10-29: AddonHint for Limit Per User beside the Limit Per Role one;
      placeholder cards while the Add-ons list loads
- [x] A3 `loggedin_settings_schema` (pages, cards, fields) and legacy
      panel/tab compatibility, checked with Limit Per Role and Real-time Logout.
      2026-10-29: `role-table` fields, fields without a `key` (whole option),
      `default_from`, `unit_one`
- [x] A3 notice for add-ons older than the new screen (Active Sessions 1.2.0,
      Limit Per Role 2.2.0, Real-time Logout 2.2.0) — 2026-10-29
- [x] A4 add-on UI migrations, each on its own `feature/admin-kit` branch,
      uncommitted — 2026-10-29:
      Active Sessions 1.2.0 (Manage › Active sessions on the kit),
      Limit Per Role 2.2.0 and Real-time Logout 2.2.0 (schema cards, no admin
      bundle on core 3.4.0+), Limit Per User 2.2.0 (profile field restyled;
      security fix: the field now needs `edit_users`, so users can no longer
      raise their own limit from their profile).
      Each keeps its old UI on core before 3.4.0
- [ ] A5 QA. Done: PHPUnit on WordPress 7.1 and 6.5.14, JS lint, phpcs.
      Found and fixed: the bundles need `react-jsx-runtime`, which WordPress
      only registers from 6.6, so 6.5 showed a blank screen; core now
      registers a shim on its screen.
      Browser pass, 2026-10-29, WordPress 7.1 with all add-ons on their
      `feature/admin-kit` branches: every page at 1280px and 375px (no
      sideways scroll), RTL, saving, validation, Discard, notices, the Users
      list and profile screens, and the retired Auto Logout add-on active.
      Found and fixed: role rows misaligned (the select's label had an extra
      8px gap), Active Sessions table wider than its card at 1280px, focus
      lost after removing a role row, the Message textarea's label not
      linked, profile Sign out buttons without a device name for screen
      readers, stacked role rows running together on phones.
      Left: 782px, two admin colour schemes and the accent, contrast, Windows
      high contrast, keyboard-only pass of every page, older core with new
      add-ons and the reverse, and the login flows end to end (Block New
      message, self-unlock email, displaced notice and redirect, idle
      warning)
- [x] B8 auto logout in core, add-on takeover, REST idle-logout error —
      `feature/admin-kit`, 2026-10-26. Docs moved to a core page with a
      redirect, 2026-10-29 (docs repo branch `loggedin-3.4.0`). The add-on
      never sold, so it is simply removed from Freemius (2026-10-29); no
      licence check or migration is needed
- [x] B1 self-unlock and "Don't limit administrators", B3 Users list, B5
      password-change sign-out — `feature/admin-kit`, 2026-10-26. One-time
      "exempt administrators" notice (decision 6), 2026-10-29
- [x] B2 limit message setting, displaced notice and redirect — 2026-10-29.
      The notice matches the exact session Loggedin ended (its verifier),
      not a time window, so other sign-outs never show it
- [x] B4 "Your sessions" on the profile; a count on other users' profiles —
      2026-10-29. WordPress's own "Log Out Everywhere Else" (and "Log Out
      Everywhere" on another user's profile) stays the way to end the rest,
      instead of a second button of ours. Single-session writes go through `Session_Store`
      (also used by Logout Oldest and WP-CLI)
- [x] B6 Logout Oldest and Logout All enforced as a session is created —
      2026-10-29. Not yet tested against Nextend Social Login, a magic-link
      plugin, WooCommerce My Account, Ultimate Member or MemberPress; the
      compatibility page says so
- [x] B7 readme, FAQ and docs pages — 2026-10-29. Store pages beyond the
      WordPress.org readme are not updated yet
- [ ] Phase C — Login History, Membership Limits, Login Alerts, Active Sessions upgrade
- [ ] Phase D — bundle plan, discovery
