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

The short version: copy `openstream-auto-updates.php` to `wp-content/mu-plugins/`. On a site
whose install directory is a Git repository, follow the steps below – the plugin commits
everything that is not ignored, so the working tree must be clean before it starts.

The plugin is only active when `wp_get_environment_type()` is `production` (the WordPress
default if `WP_ENVIRONMENT_TYPE` is not set) and the site does not run in DDEV
(`IS_DDEV_PROJECT` or a `*.ddev.site` host). Local and staging copies stay untouched
without any configuration.

### 1. Check the site (read-only)

In the WordPress install directory on production:

```bash
wp core version; php -v | head -1        # WordPress 5.6+ and PHP 7.4+ required
git status --short | head -20             # must be empty
git fetch -q && git status -sb | head -1  # must not be behind origin
git remote -v                             # the server must be able to push here
wp config get WP_ENVIRONMENT_TYPE 2>/dev/null || echo "not set = production"
crontab -l 2>/dev/null | grep -i cron     # real cron job for WP-Cron?
```

Go on only if WordPress is 5.6 or newer, the working tree is clean and the branch is not
behind `origin` (otherwise `git pull --ff-only` first). On WordPress below 5.5 the plugin
causes a fatal error. Also check:

- Hosting tools that update on their own (e.g. Plesk WP Toolkit) – detach the site there.
- Staging copies that share the branch – set `WP_ENVIRONMENT_TYPE` to `'staging'` there.

### 2. Configure `wp-config.php`

Back up the file **outside** the document root (a backup inside it would be untracked and
end up in the next automatic commit), then add the constants you need (see
[Configuration](#configuration)):

```bash
cp wp-config.php ~/wp-config.backup-$(date +%Y%m%d).php
wp config set OPENSTREAM_AUTO_UPDATE_TICKET 'PROJ-123'      # only for Git sites
wp config set OPENSTREAM_AUTO_UPDATE_EMAIL 'ops@example.com'
wp config set OPENSTREAM_AUTO_UPDATE_CONTACT 'support@example.com'
wp config set OPENSTREAM_AUTO_UPDATE_TEAM 'Example Agency'
wp config set OPENSTREAM_AUTO_UPDATE_NOTIFY_ADMIN false --raw  # no mails to the site admin
wp config set OPENSTREAM_DASHBOARD_TOKEN '<token>'           # only with a risk API
```

Keep tokens out of chats, tickets and shell histories you share.

### 3. Install the plugin and commit it

```bash
mkdir -p wp-content/mu-plugins
curl -fsSL https://raw.githubusercontent.com/openstream/wp-auto-update-guard/main/openstream-auto-updates.php \
  -o wp-content/mu-plugins/openstream-auto-updates.php
git add wp-content/mu-plugins/openstream-auto-updates.php
git commit -m "PROJ-123: Add Openstream Auto-Update Guard" -- wp-content/mu-plugins/openstream-auto-updates.php
git push origin HEAD
```

Commit only the plugin file; anything else lying around belongs to someone else.

### 4. Verify

```bash
wp eval 'var_dump(has_filter("automatic_updates_is_vcs_checkout"));'  # int(...) = active
curl -s -o /dev/null -w "%{http_code}\n" https://example.com/          # 200
```

A short 503 is normal: the request can fire WP-Cron and with it a real update run
(WordPress shows the maintenance page while updating). After the first run (WordPress
checks twice a day) the Git log shows a commit `PROJ-123: Automatic updates: …`; if
committing fails, a mail goes to `OPENSTREAM_AUTO_UPDATE_EMAIL`.

### Without shell access

Upload `openstream-auto-updates.php` to `wp-content/mu-plugins/` via SFTP or the hosting
file manager and add the constants to `wp-config.php` by hand, leaving out
`OPENSTREAM_AUTO_UPDATE_TICKET`. This is only suitable for sites **without** Git: on a Git
site, updates would either never reach the repository or the first automatic commit would
pick up unchecked changes.

### With a coding agent

An AI coding agent with SSH access can do all of the above. Have it run step 1 read-only
and report back, and give the go for steps 2–4 only after reading that report. Without
SSH access for the agent, run the commands yourself and paste the output back to it.

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

### GoodLayers theme plugins

GoodLayers themes (OnePagePro, Infinite, Kingster …) need goodlayers-core, -portfolio,
Revolution Slider etc. They are not in the theme package: once the theme is registered
(ThemeForest purchase code or GoodLayers license), the theme downloads them from
goodlayers.com – but only offers them on its own "Install Plugins" page. WordPress' update
system, WP-CLI and auto-updates never see them.

`OPENSTREAM_AUTO_UPDATE_GOODLAYERS` announces them as regular plugin updates, with the same
download URL the theme uses. The vendor's version list is refreshed every 12 hours (the
theme does that on admin visits only). The usual rules apply (same major version, risk),
and the theme waits for goodlayers-core like for any other paid add-on. Without
registration nothing changes.

```php
define('OPENSTREAM_AUTO_UPDATE_GOODLAYERS', 'goodlayers-core,goodlayers-core-portfolio');
// or every GoodLayers plugin installed on the site:
define('OPENSTREAM_AUTO_UPDATE_GOODLAYERS', true);
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

### Pause

While a major update is done by hand, automatic runs would get in the way (and commit
from production in between). Pause them for a while:

```bash
wp option update openstream_auto_update_pause '{"until": 1793700000, "by": "nick", "reason": "PROJ-123 WooCommerce 10"}' --format=json
wp option delete openstream_auto_update_pause   # resume early
```

`until` is a Unix timestamp; after it the pause ends by itself (the option is removed on
the next check). `by` and `reason` are optional and only for whoever looks at the site.
While paused, WordPress runs no automatic updates at all – core, plugins, themes and
translations.

### Cache flush

After every run that changed something, the object cache and common page caches are
flushed (W3 Total Cache, WP Rocket, LiteSpeed, WP Super Cache, WP Fastest Cache,
Autoptimize, SiteGround, Breeze, Hummingbird). Otherwise a page cache may keep serving
HTML that references files of the old version.

### Update mails

WordPress' own update mails stay as they are, with these optional additions:

- copies to `OPENSTREAM_AUTO_UPDATE_EMAIL` and `OPENSTREAM_AUTO_UPDATE_NOTIFY`,
- no mails to the site admin address (`OPENSTREAM_AUTO_UPDATE_NOTIFY_ADMIN` = `false`):
  only `…_EMAIL`/`…_NOTIFY` receive them – e.g. the agency instead of the client, who can
  get a monthly report from the update log instead. With no recipient left, no mail is sent,
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
| `OPENSTREAM_AUTO_UPDATE_NOTIFY_ADMIN` | `true` | `false` = no update mails to the site admin address |
| `OPENSTREAM_AUTO_UPDATE_CONTACT` | – | Contact (e.g. e-mail address) replacing the WordPress.org support hint |
| `OPENSTREAM_AUTO_UPDATE_TEAM` | – | Signature replacing "The WordPress Team" (only with `…_CONTACT`) |
| `OPENSTREAM_AUTO_UPDATE_GREETING` | – | Greeting replacing "Howdy!" at the start of the update mails |
| `OPENSTREAM_AUTO_UPDATE_FORMAL` | as translated | German mails: `true` = "Sie", `false` = "du" |
| `OPENSTREAM_AUTO_UPDATE_MAX_RISK` | `medium` | Highest risk level installed automatically: `low` or `medium` |
| `OPENSTREAM_AUTO_UPDATE_GOODLAYERS` | – | GoodLayers theme plugins as regular updates: comma list of slugs or `true` for all |
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

### Update log

Every run that changed something appends one entry per component to the site option
`openstream_auto_update_log` (not autoloaded; entries older than 400 days are dropped):

```json
{"time": "2026-10-27T10:45:59+00:00", "followup": false, "commit": "b5ab3ad", "git_error": null,
 "type": "plugin", "slug": "contact-form-7", "name": "Contact Form 7",
 "from": "6.1.1", "to": "6.1.2", "result": "updated"}
```

`result` is `updated` or `failed` (then with `error`); `commit` is the commit made by the
run (see above). Translations are summarised in one entry per run (`slug` = languages).
Read it for reports with

```bash
wp option get openstream_auto_update_log --format=json
```

### PHP error log

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
