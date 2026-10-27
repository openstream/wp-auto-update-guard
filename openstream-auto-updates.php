<?php
/**
 * Plugin Name: Openstream Auto-Update Guard
 * Plugin URI:  https://github.com/openstream/wp-auto-update-guard
 * Description: Safe WordPress auto-updates: core minor/security releases only, plugins and themes only within the same major version, translations always. Works in Git checkouts and can commit and push the updates straight from production.
 * Version:     1.16.0
 * Author:      Openstream Internet Solutions
 * Author URI:  https://www.openstream.ch
 * License:     GPL-2.0-or-later
 *
 * Install as a must-use plugin: wp-content/mu-plugins/openstream-auto-updates.php
 * Documentation: README.md
 *
 * Optional constants in wp-config.php:
 *   define('OPENSTREAM_AUTO_UPDATE_EXCLUDE', 'slug-a,slug-b');     // never update automatically (plugin/theme slugs)
 *   define('OPENSTREAM_AUTO_UPDATE_TICKET', 'PROJ-123');           // commit + push updates with this ticket prefix
 *   define('OPENSTREAM_AUTO_UPDATE_EMAIL', 'ops@example.com');     // copy of all update mails + Git error mails
 *   define('OPENSTREAM_AUTO_UPDATE_NOTIFY', 'client@example.com'); // further recipients of the update mails (comma list)
 *   define('OPENSTREAM_AUTO_UPDATE_NOTIFY_ADMIN', false);          // no update mails to the site admin address (default true)
 *   define('OPENSTREAM_AUTO_UPDATE_CONTACT', 'support@example.com'); // replaces the WordPress.org support hint in update mails
 *   define('OPENSTREAM_AUTO_UPDATE_TEAM', 'Example Agency');       // replaces "The WordPress Team" signature
 *   define('OPENSTREAM_AUTO_UPDATE_GREETING', 'Guten Tag Frau Muster'); // replaces "Howdy!" at the start of update mails
 *   define('OPENSTREAM_AUTO_UPDATE_FORMAL', true);                 // German mails: true = "Sie", false = "du"
 *   define('OPENSTREAM_AUTO_UPDATE_ANY_ENV', true);                // also run outside production (testing)
 *   define('OPENSTREAM_DASHBOARD_URL', 'https://dashboard.example.com'); // risk API (see README)
 *   define('OPENSTREAM_DASHBOARD_TOKEN', '…');                     // token for the risk API
 *   define('OPENSTREAM_AUTO_UPDATE_MAX_RISK', 'medium');           // highest risk installed automatically: low|medium
 *   define('OPENSTREAM_AUTO_UPDATE_GOODLAYERS', 'goodlayers-core,goodlayers-core-portfolio'); // GoodLayers theme plugins as regular updates (true = all)
 */

defined('ABSPATH') || exit;

// Only on production (the WordPress default without WP_ENVIRONMENT_TYPE) and never in DDEV,
// so local copies stay untouched without any configuration.
$openstream_is_ddev = getenv('IS_DDEV_PROJECT') === 'true'
    || substr((string) parse_url((string) (defined('WP_HOME') ? WP_HOME : ($_SERVER['HTTP_HOST'] ?? '')), PHP_URL_HOST) ?: (string) ($_SERVER['HTTP_HOST'] ?? ''), -10) === '.ddev.site';
if ((wp_get_environment_type() !== 'production' || $openstream_is_ddev)
    && !(defined('OPENSTREAM_AUTO_UPDATE_ANY_ENV') && OPENSTREAM_AUTO_UPDATE_ANY_ENV)) {
    return;
}
unset($openstream_is_ddev);

// Turn on the WordPress "auto-updates" toggle for new plugins/themes: on first start
// (option missing) for all of them, afterwards before each run for new ones only.
// Toggles switched off deliberately in the admin stay off.
add_action('init', function () {
    if (get_site_option('openstream_auto_update_known') === false) {
        openstream_auto_update_sync_toggles();
    }
});
add_action('wp_maybe_auto_update', 'openstream_auto_update_sync_toggles', 1);

function openstream_auto_update_sync_toggles(): void
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $known = (array) get_site_option('openstream_auto_update_known', ['plugins' => [], 'themes' => []]);
    $known += ['plugins' => [], 'themes' => []];
    $current = [
        'plugins' => array_keys(get_plugins()),
        'themes' => array_keys(wp_get_themes()),
    ];
    foreach (['plugins' => 'auto_update_plugins', 'themes' => 'auto_update_themes'] as $type => $option) {
        $new = array_diff($current[$type], (array) $known[$type]);
        if ($new !== []) {
            $enabled = (array) get_site_option($option, []);
            update_site_option($option, array_values(array_unique(array_merge($enabled, $new))));
        }
        $known[$type] = $current[$type];
    }
    update_site_option('openstream_auto_update_known', $known);
}

// WordPress silently disables auto-updates as soon as it finds a .git (or .svn, .hg, .bzr)
// in the install directory or above. Here the code base lives in Git on purpose.
add_filter('automatic_updates_is_vcs_checkout', '__return_false', 1);

// Override AUTOMATIC_UPDATER_DISABLED from wp-config.php (the filter takes precedence).
add_filter('automatic_updater_disabled', '__return_false', 99);

// DISALLOW_FILE_MODS (common on Git-managed sites) also blocks the automatic updater.
// Allow only that context – installing/editing in the admin stays locked.
add_filter('file_mod_allowed', function ($allowed, $context) {
    return $context === 'automatic_updater' ? true : $allowed;
}, 10, 2);

// Core: minor/security releases only. The filters take precedence over
// WP_AUTO_UPDATE_CORE in wp-config.php.
add_filter('allow_minor_auto_core_updates', '__return_true', 99);
add_filter('allow_major_auto_core_updates', '__return_false', 99);
add_filter('allow_dev_auto_core_updates', '__return_false', 99);

add_filter('auto_update_translation', '__return_true');

add_filter('auto_update_plugin', function ($update, $item) {
    $slug = isset($item->slug) ? (string) $item->slug : '';
    if (openstream_auto_update_excluded($slug)) {
        return false;
    }
    $file = isset($item->plugin) ? (string) $item->plugin : '';
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ($file !== '' && !is_plugin_active($file)) {
        return true; // inactive = not loaded, cannot break the site
    }
    if ($update !== true) {
        return $update; // WordPress auto-update toggle is off
    }
    $installed = get_plugins()[$file]['Version'] ?? '';
    $new = (string) ($item->new_version ?? '');
    $decision = openstream_auto_update_decide($update, $installed, $new);
    return $decision === true ? openstream_auto_update_risk_ok('plugin', $slug, $installed, $new) : $decision;
}, 20, 2);

add_filter('auto_update_theme', function ($update, $item) {
    $slug = isset($item->theme) ? (string) $item->theme : '';
    if (openstream_auto_update_excluded($slug)) {
        return false;
    }
    if ($slug !== '' && !in_array($slug, [get_stylesheet(), get_template()], true)) {
        return true; // inactive theme (neither active nor parent of the active one)
    }
    if ($update !== true) {
        return $update; // WordPress auto-update toggle is off
    }
    $installed = (string) wp_get_theme($slug)->get('Version');
    $new = (string) ($item->new_version ?? '');
    $decision = openstream_auto_update_decide($update, $installed, $new);
    if ($decision === true && ($addon = openstream_auto_update_addon_blocker($slug)) !== '') {
        error_log(sprintf('[openstream-auto-updates] theme %s %s -> %s held back: %s', $slug, $installed, $new, $addon));
        return false;
    }
    return $decision === true ? openstream_auto_update_risk_ok('theme', $slug, $installed, $new) : $decision;
}, 20, 2);

/**
 * Paid add-on plugins that must keep pace with their theme. Updating the theme alone
 * breaks things (seen with Astra 4.14 + Astra Pro 4.5: empty settings page), so the
 * theme waits until the add-on can be updated in the same run.
 * Extend with the filter openstream_auto_update_theme_addons.
 *
 * @return array<string, string[]> theme slug (template) => plugin files
 */
function openstream_auto_update_theme_addons(): array
{
    $map = [
        'astra' => ['astra-addon/astra-addon.php'],
        'generatepress' => ['gp-premium/gp-premium.php'],
        'kadence' => ['kadence-pro/kadence-pro.php'],
    ];
    if (openstream_auto_update_goodlayers_plugins() !== []) {
        $map[get_template()] = ['goodlayers-core/goodlayers-core.php'];
    }
    return (array) apply_filters('openstream_auto_update_theme_addons', $map);
}

// ---------------------------------------------------------------------------
// GoodLayers: plugins the theme only offers on its own "Install Plugins" page
//
// GoodLayers themes (OnePagePro, Infinite, Kingster …) download goodlayers-core,
// -portfolio, revslider etc. from goodlayers.com once the theme is registered, but only
// show them on their TGMPA page – WordPress' update system never sees them. With
// OPENSTREAM_AUTO_UPDATE_GOODLAYERS they are announced as regular plugin updates.
// The theme loads its registration code in the admin only, so the package URL is
// rebuilt here (and remembered from admin requests for Freemius-registered sites).
// ---------------------------------------------------------------------------

/**
 * Plugin slugs to fetch from GoodLayers: comma list, or true for every installed plugin
 * the vendor's version list knows.
 *
 * @return string[]|true
 */
function openstream_auto_update_goodlayers_plugins()
{
    if (!defined('OPENSTREAM_AUTO_UPDATE_GOODLAYERS') || !OPENSTREAM_AUTO_UPDATE_GOODLAYERS) {
        return [];
    }
    if (OPENSTREAM_AUTO_UPDATE_GOODLAYERS === true) {
        return true;
    }
    return array_values(array_filter(array_map('trim', explode(',', (string) OPENSTREAM_AUTO_UPDATE_GOODLAYERS))));
}

/** Download URL of a GoodLayers plugin ('' = theme not registered). */
function openstream_auto_update_goodlayers_package(string $theme, string $slug): string
{
    $cache = (array) get_site_option('openstream_auto_update_goodlayers_packages', []);

    // Admin request: the theme's own function knows both registration types (purchase code, Freemius).
    $fn = str_replace('-', '_', $theme) . '_get_download_url';
    if (function_exists($fn)) {
        $url = (string) $fn($slug);
        if (($cache[$slug] ?? '') !== $url) {
            $cache[$slug] = $url;
            update_site_option('openstream_auto_update_goodlayers_packages', $cache);
        }
        return $url;
    }

    // Cron: same URL as the theme builds it from the ThemeForest registration.
    $key = (string) get_option($theme . '_download_key', '');
    $code = '';
    $verification = get_template_directory() . '/admin/installer/verification.php';
    if (is_readable($verification) && preg_match("/_ITEM_ID'\\s*,\\s*(\\d+)/", (string) file_get_contents($verification), $m)) {
        $code = (string) get_option('envato_purchase_code_' . $m[1], '');
    }
    if ($key !== '' && $code !== '') {
        return add_query_arg([
            'purchase_code' => urlencode($code),
            'download_key' => $key,
            'file' => $slug,
        ], 'https://goodlayers.com/licenses/wp-content/plugins/goodlayers-verification/download/');
    }
    return $key !== '' ? (string) ($cache[$slug] ?? '') : '';
}

/**
 * Latest versions offered by GoodLayers (slug => version). The theme refreshes its list
 * on admin visits only; refreshed here every 12 hours.
 *
 * @return array<string, string>
 */
function openstream_auto_update_goodlayers_versions(string $theme): array
{
    $list = get_option($theme . '-plugins-version', []);
    if (get_site_transient('openstream_auto_update_goodlayers_checked') === false) {
        set_site_transient('openstream_auto_update_goodlayers_checked', 1, 12 * HOUR_IN_SECONDS);
        $res = wp_remote_get('https://goodlayers.com/licenses/wp-json/version/plugin', ['timeout' => 15]);
        $fresh = is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200
            ? null : json_decode((string) wp_remote_retrieve_body($res), true);
        if (is_array($fresh) && $fresh !== []) {
            update_option($theme . '-plugins-version', $fresh);
            $list = $fresh;
        } else {
            error_log('[openstream-auto-updates] GoodLayers version list not available – using the stored one');
        }
    }
    $out = [];
    foreach ((array) $list as $slug => $info) {
        if (!empty($info['version'])) {
            $out[(string) $slug] = (string) $info['version'];
        }
    }
    return $out;
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    $wanted = openstream_auto_update_goodlayers_plugins();
    if ($wanted === [] || !is_object($transient)) {
        return $transient;
    }
    $theme = get_template();
    $versions = openstream_auto_update_goodlayers_versions($theme);
    if ($versions === []) {
        return $transient; // not a GoodLayers theme
    }
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    foreach (get_plugins() as $file => $data) {
        $slug = dirname($file);
        if (!isset($versions[$slug]) || ($wanted !== true && !in_array($slug, $wanted, true))) {
            continue;
        }
        $package = openstream_auto_update_goodlayers_package($theme, $slug);
        if ($package === '') {
            continue; // not registered: leave it to the theme's own page
        }
        $item = (object) [
            'id' => 'goodlayers.com/' . $slug,
            'slug' => $slug,
            'plugin' => $file,
            'new_version' => $versions[$slug],
            'url' => 'https://goodlayers.com',
            'package' => $package,
        ];
        // Listed in no_update as well, otherwise WordPress shows no auto-update toggle.
        if (version_compare($versions[$slug], (string) $data['Version'], '>')) {
            $transient->response[$file] = $item;
            unset($transient->no_update[$file]);
        } else {
            $transient->no_update[$file] = $item;
            unset($transient->response[$file]);
        }
    }
    return $transient;
});

/**
 * Reason why the theme's add-on cannot be updated in this run ('' = nothing blocks):
 * its pending update has no download package (missing/expired license) or is vetoed
 * (WordPress toggle off, excluded, other major version, risk).
 */
function openstream_auto_update_addon_blocker(string $theme): string
{
    $files = openstream_auto_update_theme_addons()[$theme] ?? [];
    if ($files === []) {
        return '';
    }
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugins = get_plugins();
    $pending = get_site_transient('update_plugins');
    $pending = is_object($pending) ? (array) ($pending->response ?? []) : [];
    $toggles = (array) get_site_option('auto_update_plugins', []);
    foreach ($files as $file) {
        if (!isset($plugins[$file]) || !is_plugin_active($file) || !isset($pending[$file])) {
            continue; // not in use or nothing pending
        }
        $u = $pending[$file];
        $name = $plugins[$file]['Name'] . ' ' . $plugins[$file]['Version'];
        $slug = (string) ($u->slug ?? dirname($file));
        $current = (string) $plugins[$file]['Version'];
        $target = (string) ($u->new_version ?? '');
        if (empty($u->package)) {
            return $name . ' cannot be updated to ' . $target . ' (no download package – license missing?)';
        }
        if (!in_array($file, $toggles, true) || openstream_auto_update_excluded($slug)
            || openstream_auto_update_decide(true, $current, $target) !== true
            || !openstream_auto_update_risk_ok('plugin', $slug, $current, $target)) {
            return $name . ' -> ' . $target . ' is not updated automatically';
        }
    }
    return '';
}

/**
 * true if the major version (first number) stays the same and the new version is newer.
 * Versions that cannot be compared: keep the WordPress default ($update).
 */
function openstream_auto_update_decide($update, string $installed, string $new)
{
    $installed = ltrim(trim($installed), 'vV');
    $new = ltrim(trim($new), 'vV');
    if (!preg_match('/^(\d+)/', $installed, $a) || !preg_match('/^(\d+)/', $new, $b)) {
        return $update;
    }
    if (version_compare($new, $installed, '<=')) {
        return $update;
    }
    return $a[1] === $b[1];
}

/**
 * May this update run automatically according to its update risk?
 */
function openstream_auto_update_risk_ok(string $type, string $slug, string $installed, string $new): bool
{
    // The risk API answers in German (niedrig|mittel|hoch); English names are accepted too.
    $ranks = ['niedrig' => 0, 'low' => 0, 'mittel' => 1, 'medium' => 1, 'hoch' => 2, 'high' => 2];
    $max = defined('OPENSTREAM_AUTO_UPDATE_MAX_RISK') ? strtolower((string) OPENSTREAM_AUTO_UPDATE_MAX_RISK) : 'medium';
    $maxRank = $ranks[$max] ?? 1;

    $risks = openstream_auto_update_dashboard_risks();
    if ($risks === null) {
        // No token / risk API unreachable: allow a jump of at most one minor version.
        preg_match_all('/\d+/', $installed, $a);
        preg_match_all('/\d+/', $new, $b);
        return ((int) ($b[0][1] ?? 0)) - ((int) ($a[0][1] ?? 0)) <= 1;
    }
    $risk = $risks[$type . ':' . $slug . ':' . $new] ?? null;
    if ($risk === null) {
        return false; // not rated by the risk API -> not automatic
    }
    $ok = ($ranks[$risk['level'] ?? 'hoch'] ?? 2) <= $maxRank;
    if (!$ok) {
        error_log(sprintf('[openstream-auto-updates] %s %s %s -> %s held back: update risk %s (%d)',
            $type, $slug, $installed, $new, $risk['level'] ?? '?', (int) ($risk['score'] ?? 0)));
    }
    return $ok;
}

/**
 * Fetch the update risk of all pending plugin/theme updates from the risk API in ONE
 * request (cached per PHP request). null = no token/URL or error.
 *
 * @return array<string, array{level:string, score:int}>|null  key "type:slug:version"
 */
function openstream_auto_update_dashboard_risks(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    if (!defined('OPENSTREAM_DASHBOARD_TOKEN') || OPENSTREAM_DASHBOARD_TOKEN === '') {
        return null;
    }
    $url = (defined('OPENSTREAM_DASHBOARD_URL') ? rtrim((string) OPENSTREAM_DASHBOARD_URL, '/') : 'https://update-dashboard.openstream.ch') . '/api/risk';

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugins = get_plugins();
    $items = [];
    $tp = get_site_transient('update_plugins');
    $tt = get_site_transient('update_themes');
    foreach ((array) (is_object($tp) ? ($tp->response ?? []) : []) as $file => $u) {
        if (!isset($plugins[$file], $u->slug, $u->new_version)) {
            continue;
        }
        $items[] = ['type' => 'plugin', 'slug' => (string) $u->slug, 'current' => (string) $plugins[$file]['Version'],
            'available' => (string) $u->new_version, 'status' => is_plugin_active($file) ? 'active' : 'inactive'];
    }
    $active = [get_stylesheet(), get_template()];
    foreach ((array) (is_object($tt) ? ($tt->response ?? []) : []) as $theme => $u) {
        $u = (array) $u;
        if (empty($u['new_version'])) {
            continue;
        }
        $items[] = ['type' => 'theme', 'slug' => (string) $theme, 'current' => (string) wp_get_theme($theme)->get('Version'),
            'available' => (string) $u['new_version'], 'status' => in_array($theme, $active, true) ? 'active' : 'inactive'];
    }
    if ($items === []) {
        return $cache = [];
    }

    $res = wp_remote_post($url, [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json', 'X-Dashboard-Token' => (string) OPENSTREAM_DASHBOARD_TOKEN],
        'body' => wp_json_encode(['core_version' => get_bloginfo('version'), 'items' => $items]),
    ]);
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    $data = $code === 200 ? json_decode((string) wp_remote_retrieve_body($res), true) : null;
    if (!is_array($data['items'] ?? null)) {
        error_log('[openstream-auto-updates] Update risk not available (HTTP ' . $code
            . (is_wp_error($res) ? ', ' . $res->get_error_message() : '') . ') – cautious rule active.');
        return null;
    }
    $cache = [];
    foreach ($data['items'] as $i) {
        $cache[$i['type'] . ':' . $i['slug'] . ':' . $i['available']] = ['level' => (string) $i['level'], 'score' => (int) $i['score']];
    }
    return $cache;
}

function openstream_auto_update_excluded(string $slug): bool
{
    if ($slug === '' || !defined('OPENSTREAM_AUTO_UPDATE_EXCLUDE')) {
        return false;
    }
    $list = array_map('trim', explode(',', (string) OPENSTREAM_AUTO_UPDATE_EXCLUDE));
    return in_array($slug, $list, true);
}

// ---------------------------------------------------------------------------
// Update mails: extra recipients and own contact (the admin address stays a recipient)
// ---------------------------------------------------------------------------

/** Copy recipient of the update mails and recipient of Git error mails ('' = none). */
function openstream_auto_update_email(): string
{
    return defined('OPENSTREAM_AUTO_UPDATE_EMAIL') ? trim((string) OPENSTREAM_AUTO_UPDATE_EMAIL) : '';
}

/** false = the site admin address no longer receives the update mails (only EMAIL/NOTIFY). */
function openstream_auto_update_notify_admin(): bool
{
    return !defined('OPENSTREAM_AUTO_UPDATE_NOTIFY_ADMIN') || OPENSTREAM_AUTO_UPDATE_NOTIFY_ADMIN;
}

/** Without the admin and without EMAIL/NOTIFY nobody is left: do not send at all. */
function openstream_auto_update_has_recipients(): bool
{
    return openstream_auto_update_notify_admin()
        || is_email(openstream_auto_update_email())
        || (defined('OPENSTREAM_AUTO_UPDATE_NOTIFY') && array_filter(array_map('trim', explode(',', (string) OPENSTREAM_AUTO_UPDATE_NOTIFY)), 'is_email') !== []);
}

foreach (['auto_core_update_send_email', 'auto_plugin_update_send_email', 'auto_theme_update_send_email'] as $openstream_hook) {
    add_filter($openstream_hook, static function ($send) {
        return $send && openstream_auto_update_has_recipients();
    }, 99);
}

foreach (['auto_core_update_email', 'auto_plugin_theme_update_email', 'automatic_updates_debug_email'] as $openstream_hook) {
    add_filter($openstream_hook, function ($email) {
        $to = is_array($email['to'] ?? null) ? $email['to'] : array_filter(array_map('trim', explode(',', (string) ($email['to'] ?? ''))));
        if (!openstream_auto_update_notify_admin()) {
            $to = [];
        }
        if (is_email(openstream_auto_update_email())) {
            $to[] = openstream_auto_update_email();
        }
        if (defined('OPENSTREAM_AUTO_UPDATE_NOTIFY')) {
            $to = array_merge($to, array_filter(array_map('trim', explode(',', (string) OPENSTREAM_AUTO_UPDATE_NOTIFY)), 'is_email'));
        }
        $email['to'] = array_values(array_unique($to));
        $email['body'] = openstream_auto_update_mail_footer(openstream_auto_update_mail_greeting((string) ($email['body'] ?? '')));
        return $email;
    }, 99);
}
unset($openstream_hook);

/**
 * Replace the WordPress.org support forum hint and "The WordPress Team" with your own
 * contact (only if OPENSTREAM_AUTO_UPDATE_CONTACT is set). Paragraph-based rather than
 * by wording, because the text differs per language, formality and mail type.
 */
function openstream_auto_update_mail_footer(string $body): string
{
    $contact = defined('OPENSTREAM_AUTO_UPDATE_CONTACT') ? trim((string) OPENSTREAM_AUTO_UPDATE_CONTACT) : '';
    if ($contact === '') {
        return $body;
    }
    $team = defined('OPENSTREAM_AUTO_UPDATE_TEAM') ? trim((string) OPENSTREAM_AUTO_UPDATE_TEAM) : '';
    $paragraphs = preg_split('/\R{2,}/', trim($body)) ?: [];
    $keep = array_filter($paragraphs, static function (string $p): bool {
        $t = trim($p);
        return !preg_match('~https?://([a-z-]+\.)?wordpress\.org/support~i', $t)
            && !preg_match('/^(Das WordPress-Team|The WordPress Team|L.équipe WordPress|Il team di WordPress)\.?$/iu', $t);
    });
    $locale = (string) get_locale();
    $german = strpos($locale, 'de') === 0;
    if (!$german) {
        $keep[] = 'If you have any questions or notice anything after the update, please contact us: ' . $contact;
    } elseif (openstream_auto_update_formal() ?? openstream_auto_update_locale_is_formal($locale)) {
        $keep[] = 'Bei Fragen oder falls Ihnen nach dem Update etwas auffällt, melden Sie sich bitte bei uns: ' . $contact;
    } else {
        $keep[] = 'Bei Fragen oder falls dir nach dem Update etwas auffällt, melde dich bitte bei uns: ' . $contact;
    }
    if ($team !== '') {
        $keep[] = $german ? 'Das Team von ' . $team : 'The ' . $team . ' team';
    }
    return implode("\n\n", $keep) . "\n";
}

/**
 * Replace the leading "Howdy!" (translated: "Hallo!", "Bonjour !" …) with
 * OPENSTREAM_AUTO_UPDATE_GREETING, followed by an empty line.
 */
function openstream_auto_update_mail_greeting(string $body): string
{
    $greeting = defined('OPENSTREAM_AUTO_UPDATE_GREETING') ? trim((string) OPENSTREAM_AUTO_UPDATE_GREETING) : '';
    if ($greeting === '') {
        return $body;
    }
    return (string) preg_replace('/^\s*\p{L}+(?: \p{L}+)?\s?!\s*/u', $greeting . "\n\n", $body, 1);
}

// ---------------------------------------------------------------------------
// Du/Sie: German update mails in the wanted form of address
// ---------------------------------------------------------------------------
//
// WordPress translates the mails with the locale of the admin user (or the site). The form
// of address comes from that translation: de_DE and de_CH_informal say "du", de_DE_formal
// and (current) de_CH say "Sie". With OPENSTREAM_AUTO_UPDATE_FORMAL the run checks the
// translation actually loaded and, if it uses the other form, switches to the next variant
// that does (installed on demand) – and back afterwards. Without the constant nothing changes.

add_action('wp_maybe_auto_update', 'openstream_auto_update_switch_locale', -1);
add_action('wp_maybe_auto_update', 'openstream_auto_update_restore_locale', 100);

/** true = "Sie", false = "du", null = as translated. */
function openstream_auto_update_formal(): ?bool
{
    return defined('OPENSTREAM_AUTO_UPDATE_FORMAL') ? (bool) OPENSTREAM_AUTO_UPDATE_FORMAL : null;
}

function openstream_auto_update_locale_is_formal(string $locale): bool
{
    if (substr($locale, -9) === '_informal') {
        return false;
    }
    return substr($locale, -7) === '_formal' || $locale === 'de_CH';
}

/**
 * Locales to try for the wanted form of address, in order. The current locale comes
 * first: its translation may already be right (or wrong – older de_CH translations
 * say "du" in the update mails although de_CH is formal today).
 *
 * @return string[]
 */
function openstream_auto_update_candidates(string $locale, bool $formal): array
{
    $base = (string) preg_replace('/_(in)?formal$/', '', $locale);
    if ($base === 'de_CH') {
        $list = $formal ? ['de_CH', 'de_DE_formal'] : ['de_CH_informal', 'de_DE'];
    } else {
        $list = [$formal ? $base . '_formal' : $base];
    }
    return array_values(array_unique(array_merge([$locale], $list)));
}

/** Does the currently loaded translation of the update mail use the wanted form of address? */
function openstream_auto_update_translation_matches(bool $formal): bool
{
    $text = __('Howdy! Some plugins have automatically updated to their latest versions on your site at %s. No further action is needed on your part.');
    return (bool) ($formal
        ? preg_match('/\b(Ihre|Ihrer|Ihrerseits|Ihnen)\b/u', $text)
        : preg_match('/\b(deine|deiner|deinerseits|dir|du)\b/u', $text));
}

/** Make sure the core translation of $locale is installed (downloads it if needed). */
function openstream_auto_update_ensure_language(string $locale): bool
{
    if (in_array($locale, get_available_languages(), true)) {
        return true;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/translation-install.php';
    $allow = static function ($allowed, $context) {
        return $context === 'download_language_pack' ? true : $allowed;
    };
    add_filter('file_mod_allowed', $allow, 20, 2);
    $ok = (bool) wp_download_language_pack($locale);
    remove_filter('file_mod_allowed', $allow, 20);
    if (!$ok) {
        error_log('[openstream-auto-updates] translation ' . $locale . ' not available – form of address unchanged');
        return false;
    }
    // The locale switcher only knows the languages installed at startup – renew it so it
    // can switch to the one just downloaded.
    if (class_exists('WP_Locale_Switcher') && !is_locale_switched()) {
        $GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
        $GLOBALS['wp_locale_switcher']->init();
    }
    return true;
}

/** The admin's user locale (used by WordPress for the mails) replaced by the chosen locale. */
function openstream_auto_update_user_locale_filter($value, $object_id, $meta_key, $single)
{
    $chosen = (string) ($GLOBALS['openstream_auto_update_locale'] ?? '');
    if ($meta_key !== 'locale' || $chosen === '') {
        return $value;
    }
    return $single ? $chosen : [$chosen];
}

function openstream_auto_update_switch_locale(): void
{
    $formal = openstream_auto_update_formal();
    $locale = (string) get_locale();
    if ($formal === null || strpos($locale, 'de') !== 0) {
        return;
    }
    foreach (openstream_auto_update_candidates($locale, $formal) as $candidate) {
        $switched = false;
        if ($candidate !== $locale) {
            if (!openstream_auto_update_ensure_language($candidate) || !switch_to_locale($candidate)) {
                continue;
            }
            $switched = true;
        }
        // WordPress < 6.8 does not load the admin translations (which hold the update mail
        // texts) during the update run after a locale switch.
        if (is_readable(WP_LANG_DIR . '/admin-' . $candidate . '.mo')) {
            load_textdomain('default', WP_LANG_DIR . '/admin-' . $candidate . '.mo', $candidate);
        }
        if (openstream_auto_update_translation_matches($formal)) {
            $GLOBALS['openstream_auto_update_locale'] = $candidate;
            $GLOBALS['openstream_auto_update_switched'] = $switched;
            add_filter('get_user_metadata', 'openstream_auto_update_user_locale_filter', 10, 4);
            return;
        }
        if ($switched) {
            restore_previous_locale();
        }
    }
    error_log('[openstream-auto-updates] no translation with the wanted form of address found – mails as translated');
}

function openstream_auto_update_restore_locale(): void
{
    remove_filter('get_user_metadata', 'openstream_auto_update_user_locale_filter', 10);
    if (!empty($GLOBALS['openstream_auto_update_switched'])) {
        restore_previous_locale();
    }
    unset($GLOBALS['openstream_auto_update_switched'], $GLOBALS['openstream_auto_update_locale']);
}

// WordPress does NOT send the plugin/theme mail when core was updated in the same run
// (WP_Automatic_Updater::run: core ? after_core_update : after_plugin_theme_update).
// Send the original WordPress mail afterwards – same texts/translation, same recipient filters.
add_action('automatic_updates_complete', 'openstream_auto_update_plugin_mail_after_core', 10);

function openstream_auto_update_plugin_mail_after_core($results): void
{
    if (!is_array($results) || empty($results['core']) || (empty($results['plugin']) && empty($results['theme']))) {
        return;
    }
    if (!class_exists('WP_Automatic_Updater') || !method_exists('WP_Automatic_Updater', 'after_plugin_theme_update')) {
        return;
    }
    try {
        $method = new ReflectionMethod('WP_Automatic_Updater', 'after_plugin_theme_update');
        $method->setAccessible(true);
        $method->invoke(new WP_Automatic_Updater(), $results);
    } catch (Throwable $e) {
        error_log('[openstream-auto-updates] plugin mail after core update failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Follow-up run for dependent updates
//
// If plugins/themes were updated, ONE more run follows 2 minutes later with freshly
// cleared update caches. This picks up updates that are only offered after the first
// one (e.g. bundled plugins matching a new theme version, WooCommerce extensions).
// A follow-up run never triggers another one.
// ---------------------------------------------------------------------------

add_action('automatic_updates_complete', 'openstream_auto_update_schedule_followup', 30);
add_action('wp_maybe_auto_update', 'openstream_auto_update_prepare_followup', 0);
// After the run (even if it had nothing to do) remove the follow-up marker.
add_action('wp_maybe_auto_update', static function (): void {
    if (get_site_option('openstream_auto_update_followup') === 'running') {
        delete_site_option('openstream_auto_update_followup');
    }
}, 100);

function openstream_auto_update_schedule_followup($results): void
{
    // This run was a follow-up itself -> end the chain.
    if (get_site_option('openstream_auto_update_followup') === 'running') {
        delete_site_option('openstream_auto_update_followup');
        return;
    }
    $changed = false;
    foreach (['plugin', 'theme'] as $t) {
        foreach ((array) ($results[$t] ?? []) as $r) {
            if (is_object($r) && ($r->result ?? null) && !is_wp_error($r->result)) {
                $changed = true;
            }
        }
    }
    if (!$changed) {
        return;
    }
    update_site_option('openstream_auto_update_followup', 'scheduled');
    wp_schedule_single_event(time() + 120, 'wp_maybe_auto_update');
}

/** Before the follow-up run: clear update caches so vendor updaters offer again. */
function openstream_auto_update_prepare_followup(): void
{
    if (get_site_option('openstream_auto_update_followup') !== 'scheduled') {
        return;
    }
    update_site_option('openstream_auto_update_followup', 'running');
    delete_site_transient('update_plugins');
    delete_site_transient('update_themes');
}

// ---------------------------------------------------------------------------
// Flush caches after the run
//
// Otherwise a page cache keeps serving HTML that references files of the old version
// (seen in practice: a cookie banner script returned 404 until the page cache was flushed).
// ---------------------------------------------------------------------------

add_action('automatic_updates_complete', 'openstream_auto_update_flush_caches', 5);

function openstream_auto_update_flush_caches($results): void
{
    $any = false;
    foreach (['plugin', 'theme', 'core', 'translation'] as $t) {
        if (!empty($results[$t])) { $any = true; }
    }
    if (!$any) {
        return;
    }
    $done = [];
    $try = static function (string $label, callable $fn) use (&$done): void {
        try { $fn(); $done[] = $label; } catch (Throwable $e) { error_log('[openstream-auto-updates] Cache ' . $label . ': ' . $e->getMessage()); }
    };
    $try('object cache', static function () { wp_cache_flush(); });
    if (function_exists('w3tc_flush_all')) { $try('W3 Total Cache', static function () { w3tc_flush_all(); }); }
    if (function_exists('rocket_clean_domain')) { $try('WP Rocket', static function () { rocket_clean_domain(); }); }
    if (has_action('litespeed_purge_all') || defined('LSCWP_V')) { $try('LiteSpeed', static function () { do_action('litespeed_purge_all'); }); }
    if (function_exists('wp_cache_clear_cache')) { $try('WP Super Cache', static function () { wp_cache_clear_cache(); }); }
    if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) { $try('WP Fastest Cache', static function () { $GLOBALS['wp_fastest_cache']->deleteCache(true); }); }
    if (class_exists('autoptimizeCache')) { $try('Autoptimize', static function () { autoptimizeCache::clearall(); }); }
    if (function_exists('sg_cachepress_purge_cache')) { $try('SiteGround', static function () { sg_cachepress_purge_cache(); }); }
    if (has_action('breeze_clear_all_cache')) { $try('Breeze', static function () { do_action('breeze_clear_all_cache'); }); }
    if (has_action('wphb_clear_page_cache')) { $try('Hummingbird', static function () { do_action('wphb_clear_page_cache'); }); }
    error_log('[openstream-auto-updates] caches flushed: ' . implode(', ', $done));
}

// ---------------------------------------------------------------------------
// Git: commit and push the auto-updates on production
//
// Only if OPENSTREAM_AUTO_UPDATE_TICKET is set and ABSPATH is a Git repository.
// Never pulls. If commit/push fails, a mail goes to OPENSTREAM_AUTO_UPDATE_EMAIL
// (or the admin address).
// ---------------------------------------------------------------------------

add_action('automatic_updates_complete', 'openstream_auto_update_git_commit', 20);

/**
 * @param array $results ['core' => [...], 'plugin' => [...], 'theme' => [...], 'translation' => [...]]
 */
function openstream_auto_update_git_commit($results): void
{
    $ticket = defined('OPENSTREAM_AUTO_UPDATE_TICKET') ? trim((string) OPENSTREAM_AUTO_UPDATE_TICKET) : '';
    $root = untrailingslashit(ABSPATH);
    if ($ticket === '' || !is_dir($root . '/.git')) {
        return;
    }
    if (!function_exists('exec')) {
        openstream_auto_update_git_fail('exec() is disabled on this server', '');
        return;
    }

    $updated = openstream_auto_update_summary(is_array($results) ? $results : []);
    if ($updated === []) {
        return;
    }

    $git = 'cd ' . escapeshellarg($root) . ' && ' . openstream_auto_update_git_env() . ' git';

    // Everything .gitignore does not exclude (uploads/caches belong there) – except the
    // updater's work/backup folders: they still exist during automatic_updates_complete
    // and would otherwise add thousands of files. They go into .git/info/exclude (local,
    // does not change the repo). Do NOT pass them as :(exclude) pathspecs: if they are in
    // .gitignore as well, git add aborts with exit 1 after having staged files already.
    openstream_auto_update_local_excludes($root);
    $out = openstream_auto_update_run($git . ' add -A -- . 2>&1', $code);
    if ($code !== 0) {
        openstream_auto_update_git_fail('git add failed', $out);
        return;
    }
    openstream_auto_update_run($git . ' diff --cached --quiet', $code);
    if ($code === 0) {
        return; // nothing new in the repo (e.g. only ignored files changed)
    }

    $subject = $ticket . ': Automatic updates: ' . implode(', ', array_slice($updated, 0, 3))
        . (count($updated) > 3 ? ' (+' . (count($updated) - 3) . ' more)' : '');
    $message = $subject . "\n\nUpdated by the WordPress auto-updater on production:\n- " . implode("\n- ", $updated) . "\n";
    $msgFile = wp_tempnam('openstream-auto-update-commit');
    file_put_contents($msgFile, $message);
    $out = openstream_auto_update_run($git . ' commit -q -F ' . escapeshellarg($msgFile) . ' 2>&1', $code);
    @unlink($msgFile);
    if ($code !== 0) {
        openstream_auto_update_git_fail('git commit failed', $out);
        return;
    }

    $GLOBALS['openstream_auto_update_commit'] = trim(openstream_auto_update_run($git . ' rev-parse --short HEAD', $code));

    $out = openstream_auto_update_run($git . ' push -q origin HEAD 2>&1', $code);
    if ($code !== 0) {
        openstream_auto_update_git_fail('git push failed (the commit exists locally on production)', $out);
    }
}

/** Exclude the updater folders from Git locally (.git/info/exclude). */
function openstream_auto_update_local_excludes(string $root): void
{
    $file = $root . '/.git/info/exclude';
    $current = is_file($file) ? (string) file_get_contents($file) : '';
    $add = '';
    foreach (['/wp-content/upgrade/', '/wp-content/upgrade-temp-backup/'] as $pattern) {
        if (strpos($current, $pattern) === false) {
            $add .= $pattern . "\n";
        }
    }
    if ($add !== '') {
        @file_put_contents($file, rtrim($current) . "\n# openstream-auto-updates\n" . $add);
    }
}

/**
 * Readable list of the successfully updated components, e.g. "WordPress 6.9.10",
 * "contact-form-7 6.2.1", "translations (de_DE)".
 *
 * @return string[]
 */
function openstream_auto_update_summary(array $results): array
{
    $out = [];
    $translations = [];
    foreach ($results as $type => $items) {
        foreach ((array) $items as $r) {
            // Success = neither error nor empty (core returns the version number, not true).
            $res = is_object($r) ? ($r->result ?? null) : null;
            if (!$res || is_wp_error($res) || !isset($r->item)) {
                continue;
            }
            $item = $r->item;
            if ($type === 'core') {
                $out[] = 'WordPress ' . ($item->current ?? $item->version ?? '');
            } elseif ($type === 'translation') {
                $translations[(string) ($item->language ?? '')] = true;
            } else {
                $slug = (string) ($item->slug ?? $item->theme ?? $r->name ?? '');
                $out[] = trim($slug . ' ' . ($item->new_version ?? ''));
            }
        }
    }
    if ($translations !== []) {
        $out[] = 'translations (' . implode(', ', array_keys(array_filter($translations, 'strlen', ARRAY_FILTER_USE_KEY))) . ')';
    }
    return $out;
}

/**
 * Environment for git/ssh in web/cron context: HOME is often missing there, so ssh
 * finds neither key nor known_hosts. BatchMode prevents hanging password prompts.
 */
function openstream_auto_update_git_env(): string
{
    $home = getenv('HOME') ?: '';
    if (($home === '' || $home === '/') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        $home = is_array($pw) ? (string) ($pw['dir'] ?? '') : '';
    }
    $env = 'GIT_SSH_COMMAND=' . escapeshellarg('ssh -o BatchMode=yes -o ConnectTimeout=20') . ' GIT_TERMINAL_PROMPT=0';
    if ($home !== '') {
        $env = 'HOME=' . escapeshellarg($home) . ' ' . $env;
    }
    return $env;
}

function openstream_auto_update_run(string $cmd, &$code): string
{
    $lines = [];
    $code = 1;
    exec($cmd, $lines, $code);
    return implode("\n", $lines);
}

function openstream_auto_update_git_fail(string $what, string $output): void
{
    $site = home_url();
    error_log('[openstream-auto-updates] ' . $what . ': ' . $output);
    $GLOBALS['openstream_auto_update_git_error'] = $what;
    $to = openstream_auto_update_email();
    wp_mail(
        is_email($to) ? $to : (string) get_site_option('admin_email'),
        '[' . wp_parse_url($site, PHP_URL_HOST) . '] Auto-update: ' . $what,
        $what . "\n\nSite: " . $site . "\nDirectory: " . ABSPATH . "\n\nOutput:\n" . $output
            . "\n\nPlease commit/push manually on the server or resolve the conflict."
    );
}

// ---------------------------------------------------------------------------
// Update log
//
// Every auto-update run that changed something appends one entry per component to the
// site option openstream_auto_update_log (not autoloaded, entries older than 400 days are
// dropped) – the basis for a monthly report without sending mails to the client.
// Read it with: wp option get openstream_auto_update_log --format=json
// ---------------------------------------------------------------------------

add_action('wp_maybe_auto_update', 'openstream_auto_update_log_snapshot', 1);
add_action('automatic_updates_complete', 'openstream_auto_update_log_write', 25);

/** Installed versions before the run, so the log can show "from → to". */
function openstream_auto_update_log_snapshot(): void
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugins = [];
    foreach (get_plugins() as $file => $data) {
        $plugins[$file] = (string) ($data['Version'] ?? '');
    }
    $themes = [];
    foreach (wp_get_themes() as $slug => $theme) {
        $themes[$slug] = (string) $theme->get('Version');
    }
    $GLOBALS['openstream_auto_update_before'] = [
        'core' => (string) get_bloginfo('version'),
        'plugin' => $plugins,
        'theme' => $themes,
    ];
    unset($GLOBALS['openstream_auto_update_commit'], $GLOBALS['openstream_auto_update_git_error']);
}

/**
 * @param array $results ['core' => [...], 'plugin' => [...], 'theme' => [...], 'translation' => [...]]
 */
function openstream_auto_update_log_write($results): void
{
    if (!is_array($results)) {
        return;
    }
    $before = $GLOBALS['openstream_auto_update_before'] ?? ['core' => '', 'plugin' => [], 'theme' => []];
    $time = gmdate('c');
    $run = [
        'time' => $time,
        'followup' => get_site_option('openstream_auto_update_followup') === 'running',
        'commit' => $GLOBALS['openstream_auto_update_commit'] ?? null,
        'git_error' => $GLOBALS['openstream_auto_update_git_error'] ?? null,
    ];
    $entries = [];
    $languages = [];
    $translationErrors = 0;
    foreach ($results as $type => $items) {
        foreach ((array) $items as $r) {
            if (!is_object($r) || !isset($r->item)) {
                continue;
            }
            $res = $r->result ?? null;
            $ok = $res && !is_wp_error($res);
            $item = $r->item;
            if ($type === 'translation') {
                if ($ok) {
                    $languages[(string) ($item->language ?? '')] = true;
                } else {
                    $translationErrors++;
                }
                continue;
            }
            if ($type === 'core') {
                $slug = 'wordpress';
                $from = (string) $before['core'];
                $to = (string) ($item->current ?? $item->version ?? '');
            } elseif ($type === 'plugin') {
                $file = (string) ($item->plugin ?? '');
                $slug = (string) ($item->slug ?? dirname($file));
                $from = (string) ($before['plugin'][$file] ?? '');
                $to = (string) ($item->new_version ?? '');
            } else {
                $slug = (string) ($item->theme ?? $item->slug ?? '');
                $from = (string) ($before['theme'][$slug] ?? '');
                $to = (string) ($item->new_version ?? '');
            }
            $entry = [
                'type' => (string) $type,
                'slug' => $slug,
                'name' => wp_strip_all_tags((string) ($r->name ?? $slug)),
                'from' => $from,
                'to' => $to,
                'result' => $ok ? 'updated' : 'failed',
            ];
            if (!$ok) {
                $entry['error'] = is_wp_error($res) ? $res->get_error_code() . ': ' . $res->get_error_message() : 'unknown';
            }
            $entries[] = $run + $entry;
        }
    }
    $languages = array_filter(array_keys($languages), 'strlen');
    if ($languages !== [] || $translationErrors > 0) {
        $entries[] = $run + [
            'type' => 'translation',
            'slug' => implode(',', $languages),
            'name' => 'Translations',
            'from' => '',
            'to' => '',
            'result' => $translationErrors > 0 ? 'failed' : 'updated',
        ];
    }
    if ($entries === []) {
        return;
    }
    $log = get_site_option('openstream_auto_update_log', []);
    $log = is_array($log) ? $log : [];
    $cutoff = gmdate('c', time() - 400 * DAY_IN_SECONDS);
    $log = array_values(array_filter($log, static function ($e) use ($cutoff): bool {
        return is_array($e) && (string) ($e['time'] ?? '') >= $cutoff;
    }));
    $log = array_merge($log, $entries);
    // Not autoloaded: the log is only read for reports.
    if (is_multisite()) {
        update_site_option('openstream_auto_update_log', $log);
    } elseif (!update_option('openstream_auto_update_log', $log, false)) {
        add_option('openstream_auto_update_log', $log, '', false);
    }
}
