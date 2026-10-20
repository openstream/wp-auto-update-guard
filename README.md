# Openstream Auto-Update Guard

A WordPress must-use plugin that keeps sites up to date automatically – without the
risky part. It turns WordPress' built-in auto-updater on, then vetoes every update
that is likely to break a site.

- **Core:** minor and security releases only (e.g. 6.8.2 → 6.8.3), never a major release.
- **Plugins and themes:** only within the same major version (9.4 → 9.8, not 9.x → 10.0).
- **Translations:** always.
- **Inactive plugins/themes:** always updated (they are not loaded and cannot break the site).
- **Works in Git checkouts:** WordPress normally disables auto-updates silently as soon as
  it finds a `.git` directory. This plugin lifts that block and can commit and push every
  update straight from production.

## Requirements

- WordPress 5.6+ (per-plugin/theme auto-update toggles), PHP 7.4+
- A working WP-Cron (or a real cron job calling `wp cron event run --due-now`)
- For the Git feature: `exec()` enabled, `git` on the server, push access from the server
  (e.g. a deploy key)

## Installation

Copy `openstream-auto-updates.php` to `wp-content/mu-plugins/` – that's it.

The plugin is only active when `wp_get_environment_type()` is `production` (the WordPress
default if `WP_ENVIRONMENT_TYPE` is not set) and the site does not run in DDEV
(`IS_DDEV_PROJECT` or a `*.ddev.site` host). Local and staging copies stay untouched
without any configuration.

## What it does

### Lifts blocks that silently disable auto-updates

| Block | What the plugin does |
|---|---|
| `.git`/`.svn`/`.hg`/`.bzr` in or above the install directory | `automatic_updates_is_vcs_checkout` → `false` |
| `AUTOMATIC_UPDATER_DISABLED` | `automatic_updater_disabled` → `false` |
| `DISALLOW_FILE_MODS` | allowed **only** in the `automatic_updater` context – installing or editing in the admin stays locked |
| `WP_AUTO_UPDATE_CORE` | overridden: minor yes, major/dev no |

### Respects the WordPress toggles

The per-plugin/per-theme "Enable auto-updates" toggle in the admin stays in charge. The
plugin switches it on once for everything on first start, and afterwards only for
plugins/themes that are **new** since the last run. A toggle you switch off deliberately
stays off. Known items are tracked in the site option `openstream_auto_update_known`.

### Vetoes risky updates

For active plugins and the active theme (and its parent), an update only runs if

1. the major version stays the same,
2. the slug is not listed in `OPENSTREAM_AUTO_UPDATE_EXCLUDE`, and
3. the update risk is acceptable (see below).

### Themes wait for their paid add-on

Some themes come with a paid add-on plugin that must keep pace with the theme (Astra +
Astra Pro, GeneratePress + GP Premium, Kadence + Kadence Pro). Updating only the theme can
break things – e.g. Astra 4.14 with Astra Pro 4.5 leaves the Astra settings page empty.
So a theme update is held back (and logged) while the active add-on has a pending update
that cannot run in the same pass: no download package (license missing or expired), or
vetoed by the rules here (toggle off, excluded, other major version, risk). Once the
add-on can be updated, both go through together.

Further pairs can be added with a filter (theme slug => plugin files):

```php
add_filter('openstream_auto_update_theme_addons', function (array $map) {
    $map['my-theme'] = ['my-theme-pro/my-theme-pro.php'];
    return $map;
});
```

### Update risk

Without further configuration a cautious rule applies: an update may jump at most **one
minor version** (9.4 → 9.5 yes, 9.4 → 9.6 no).

Optionally the plugin asks a risk API before each run – one `POST {OPENSTREAM_DASHBOARD_URL}/api/risk`
with header `X-Dashboard-Token` and the body

```json
{"core_version": "6.8.3", "items": [
  {"type": "plugin", "slug": "woocommerce", "current": "9.4.1", "available": "9.8.0", "status": "active"}
]}
```

and expects

```json
{"items": [{"type": "plugin", "slug": "woocommerce", "available": "9.8.0", "level": "mittel", "score": 30}]}
```

with `level` one of `niedrig`/`low`, `mittel`/`medium`, `hoch`/`high`. Updates above
`OPENSTREAM_AUTO_UPDATE_MAX_RISK` (default `medium`, i.e. "high" never runs automatically)
are held back and logged; items the API does not rate are not updated automatically. If
the API is unreachable, the cautious rule applies. openstream runs its own scoring service
(release distance, changelog signals, "Tested up to", theme coupling); any service that
speaks this format will do.

### Follow-up run

If plugins or themes were updated, **one** more run follows two minutes later with
cleared update caches. This picks up updates that are only offered after the first one –
e.g. bundled plugins matching a new theme version, or WooCommerce extensions.

### Cache flush

After every run that changed something, the object cache and common page caches are
flushed (W3 Total Cache, WP Rocket, LiteSpeed, WP Super Cache, WP Fastest Cache,
Autoptimize, SiteGround, Breeze, Hummingbird). Otherwise a page cache may keep serving
HTML that references files of the old version.

### Update mails

WordPress' own update mails stay as they are, with these optional additions:

- copies to `OPENSTREAM_AUTO_UPDATE_EMAIL` and `OPENSTREAM_AUTO_UPDATE_NOTIFY`,
- your own greeting instead of "Howdy!" (`OPENSTREAM_AUTO_UPDATE_GREETING`, e.g.
  `Guten Tag Frau Muster` or `Hallo Peter`),
- the form of address in German mails (`OPENSTREAM_AUTO_UPDATE_FORMAL`: `true` = "Sie",
  `false` = "du"). WordPress takes it from the translation: `de_DE` and `de_CH_informal`
  say "du", `de_DE_formal` and current `de_CH` say "Sie" (older `de_CH` translations, e.g.
  WordPress 6.7, still say "du" in the update mails). During the update run the plugin
  checks the translation actually loaded and, if needed, switches to the next variant that
  uses the wanted form (`de_CH` → `de_DE_formal`, `de_DE` → `de_DE_formal`, …) – installing
  its core translation on first use – and back afterwards. The site's language setting
  stays unchanged.
- your own contact instead of the WordPress.org support hint (`OPENSTREAM_AUTO_UPDATE_CONTACT`,
  `OPENSTREAM_AUTO_UPDATE_TEAM`; German or English depending on the site locale, German in
  the same form of address),
- the plugin/theme mail is also sent when core was updated in the same run (WordPress
  skips it in that case).

### Commit and push from production

With `OPENSTREAM_AUTO_UPDATE_TICKET` set and the install directory being a Git
repository, every run that changed tracked files ends with

```
PROJ-123: Automatic updates: WordPress 6.8.3, contact-form-7 6.1.2, translations (de_DE)
```

committed and pushed to `origin`. Everything not covered by `.gitignore` is committed;
the updater's work folders (`wp-content/upgrade`, `wp-content/upgrade-temp-backup`) are
excluded via `.git/info/exclude`. The plugin never pulls. If `git add`, `commit` or
`push` fails, a mail goes to `OPENSTREAM_AUTO_UPDATE_EMAIL` (or the admin address) so
someone can resolve it by hand.

Make sure your `.gitignore` covers uploads, caches and other runtime files, and that
`wp-config.php` is not tracked.

## Configuration

All constants are optional and go into `wp-config.php`:

| Constant | Default | Purpose |
|---|---|---|
| `OPENSTREAM_AUTO_UPDATE_EXCLUDE` | – | Comma-separated plugin/theme slugs never updated automatically |
| `OPENSTREAM_AUTO_UPDATE_TICKET` | – | Enables commit + push; used as commit message prefix (e.g. a Jira key) |
| `OPENSTREAM_AUTO_UPDATE_EMAIL` | – | Copy of all update mails, recipient of Git error mails |
| `OPENSTREAM_AUTO_UPDATE_NOTIFY` | – | Further recipients of the update mails (comma list) |
| `OPENSTREAM_AUTO_UPDATE_CONTACT` | – | Contact (e.g. e-mail address) replacing the WordPress.org support hint |
| `OPENSTREAM_AUTO_UPDATE_TEAM` | – | Signature replacing "The WordPress Team" (only with `…_CONTACT`) |
| `OPENSTREAM_AUTO_UPDATE_GREETING` | – | Greeting replacing "Howdy!" at the start of the update mails |
| `OPENSTREAM_AUTO_UPDATE_FORMAL` | as translated | German mails: `true` = "Sie", `false` = "du" |
| `OPENSTREAM_AUTO_UPDATE_MAX_RISK` | `medium` | Highest risk level installed automatically: `low` or `medium` |
| `OPENSTREAM_DASHBOARD_URL` | openstream's service | Base URL of the risk API |
| `OPENSTREAM_DASHBOARD_TOKEN` | – | Token for the risk API; without it the cautious rule applies |
| `OPENSTREAM_AUTO_UPDATE_ANY_ENV` | `false` | Also run outside production (testing only) |

Example:

```php
define('OPENSTREAM_AUTO_UPDATE_TICKET', 'PROJ-123');
define('OPENSTREAM_AUTO_UPDATE_EMAIL', 'ops@example.com');
define('OPENSTREAM_AUTO_UPDATE_CONTACT', 'support@example.com');
define('OPENSTREAM_AUTO_UPDATE_TEAM', 'Example Agency');
define('OPENSTREAM_AUTO_UPDATE_GREETING', 'Guten Tag Frau Muster');
define('OPENSTREAM_AUTO_UPDATE_FORMAL', true);
define('OPENSTREAM_AUTO_UPDATE_EXCLUDE', 'woocommerce-subscriptions');
```

## Logging

Held-back updates, risk API problems, cache flushes and Git errors are written to the PHP
error log with the prefix `[openstream-auto-updates]`.

## Notes

- `OPENSTREAM_AUTO_UPDATE_ANY_ENV` really runs updates wherever WP-Cron fires – on a local
  copy too. Use it on a disposable copy only.

- Hosting tools that update on their own (e.g. Plesk WP Toolkit's "smart updates") bypass
  this plugin's veto. Detach the site there if the guard should be in charge.
- Premium plugins and themes only get updates if their vendor updater is licensed/registered.
- WordPress itself skips plugin updates that require a newer WordPress or PHP version.
- If a run gets killed by a web server time limit, use a real cron job
  (`DISABLE_WP_CRON` plus `wp cron event run --due-now` every 15 minutes).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
