<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Test bootstrap: a minimal WordPress environment, so the plugin can be
 * exercised without a WordPress install.
 *
 *  - options, transients, users, hooks, the object cache and the REST
 *    primitives are in-memory stubs
 *  - $wpdb is a thin adapter over SQLite (PDO), so every SQL statement in the
 *    plugin is executed for real rather than asserted as a string; the few
 *    MySQL-only constructs are handled by lwb_test_sql_to_sqlite()
 *  - wp_safe_redirect() throws LwbRedirect, so the SSO flow can be asserted
 *    without the process exiting
 *
 * The point of running real SQL: these queries are the product. A mock that
 * returns what the test expects proves only that the test agrees with itself.
 */

declare(strict_types=1);

date_default_timezone_set('UTC');

define('ABSPATH', __DIR__ . '/fake-abspath/');   // holds an empty wp-admin/includes/upgrade.php
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('YEAR_IN_SECONDS', 31536000);

// ---------------------------------------------------------------------------
// Global in-memory state (reset with lwb_test_reset())
// ---------------------------------------------------------------------------
$GLOBALS['lwb_test'] = [
    'options'    => [],
    'transients' => [],
    'users'      => [],
    'next_user'  => 1,
    'hooks'      => [],
    'routes'     => [],
    'now'        => null,
    'headers'    => [],
    'current_user' => 0,
    'auth_cookie_for' => null,
    'cron'       => [],
    'cache'      => [],
    'cache_hits' => 0,
    'cache_misses' => 0,
];

function lwb_test_reset(): void {
    $t = &$GLOBALS['lwb_test'];
    $t['options'] = [];
    $t['transients'] = [];
    $t['users'] = [];
    $t['next_user'] = 1;
    $t['now'] = null;
    $t['headers'] = [];
    $t['current_user'] = 0;
    $t['auth_cookie_for'] = null;
    $t['cron'] = [];
    $t['cache'] = [];
    $t['cache_hits'] = 0;
    $t['cache_misses'] = 0;
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0'];
    $_GET = [];
    $_POST = [];
    $GLOBALS['wpdb'] = new LwbFakeWpdb();
    lwb_install_tables();
}

function lwb_test_set_now(string $mysqlDatetime): void {
    $GLOBALS['lwb_test']['now'] = strtotime($mysqlDatetime . ' UTC');
}

// ---------------------------------------------------------------------------
// Object cache
//
// Hit and miss counters are kept so a test can assert that a second lookup did
// not reach the database, which is the only way to prove the cache is wired up
// rather than merely present.
// ---------------------------------------------------------------------------
function wp_cache_get($key, $group = '') {
    $t = &$GLOBALS['lwb_test'];
    if (array_key_exists("$group|$key", $t['cache'])) {
        $t['cache_hits']++;
        return $t['cache']["$group|$key"];
    }
    $t['cache_misses']++;
    return false;
}
function wp_cache_set($key, $value, $group = '', $ttl = 0) {
    $GLOBALS['lwb_test']['cache']["$group|$key"] = $value;
    return true;
}
function wp_cache_delete($key, $group = '') {
    unset($GLOBALS['lwb_test']['cache']["$group|$key"]);
    return true;
}
function wp_cache_add_non_persistent_groups($groups) {}
function wp_using_ext_object_cache() { return true; }
function lwb_test_cache_counts(): array {
    return ['hits' => $GLOBALS['lwb_test']['cache_hits'], 'misses' => $GLOBALS['lwb_test']['cache_misses']];
}

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------
function add_action($hook, $cb, $prio = 10, $args = 1) { add_filter($hook, $cb, $prio, $args); }
function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['lwb_test']['hooks'][$hook][$prio][] = $cb; }
function remove_filter($hook, $cb, $prio = 10) {
    if (!isset($GLOBALS['lwb_test']['hooks'][$hook][$prio])) return;
    $GLOBALS['lwb_test']['hooks'][$hook][$prio] = array_values(array_filter(
        $GLOBALS['lwb_test']['hooks'][$hook][$prio], fn($c) => $c !== $cb));
}
function apply_filters($hook, $value, ...$args) {
    $levels = $GLOBALS['lwb_test']['hooks'][$hook] ?? [];
    ksort($levels);
    foreach ($levels as $cbs) foreach ($cbs as $cb) $value = $cb($value, ...$args);
    return $value;
}
function do_action($hook, ...$args) {
    $levels = $GLOBALS['lwb_test']['hooks'][$hook] ?? [];
    ksort($levels);
    foreach ($levels as $cbs) foreach ($cbs as $cb) $cb(...$args);
}
function lwb_test_clear_filter($hook) { unset($GLOBALS['lwb_test']['hooks'][$hook]); }
function register_activation_hook($file, $cb) {}
function register_deactivation_hook($file, $cb) {}
function register_rest_route($ns, $route, $args) { $GLOBALS['lwb_test']['routes'][$ns . $route] = $args; }
function is_admin() { return false; }
function __return_true() { return true; }

// ---------------------------------------------------------------------------
// Options, transients, time
// ---------------------------------------------------------------------------
function get_option($k, $d = false) { return $GLOBALS['lwb_test']['options'][$k] ?? $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['lwb_test']['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['lwb_test']['options'][$k]); return true; }
function get_transient($k) {
    $t = $GLOBALS['lwb_test']['transients'][$k] ?? null;
    if ($t === null) return false;
    if ($t['exp'] < lwb_test_time()) { unset($GLOBALS['lwb_test']['transients'][$k]); return false; }
    return $t['v'];
}
function set_transient($k, $v, $ttl) { $GLOBALS['lwb_test']['transients'][$k] = ['v' => $v, 'exp' => lwb_test_time() + $ttl]; return true; }
function lwb_test_time(): int { return $GLOBALS['lwb_test']['now'] ?? time(); }
function current_time($type) { return $type === 'mysql' ? gmdate('Y-m-d H:i:s', lwb_test_time()) : lwb_test_time(); }
function wp_next_scheduled($hook) { return $GLOBALS['lwb_test']['cron'][$hook] ?? false; }
function wp_schedule_event($ts, $rec, $hook) { $GLOBALS['lwb_test']['cron'][$hook] = $ts; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['lwb_test']['cron'][$hook]); }

// ---------------------------------------------------------------------------
// Sanitizers, escaping, URLs
// ---------------------------------------------------------------------------
function sanitize_email($e) { $e = trim((string) $e); return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : ''; }
function is_email($e) { return (bool) filter_var((string) $e, FILTER_VALIDATE_EMAIL); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return (string) $s; }
function home_url($path = '') { return 'https://wp.example.test' . $path; }
function admin_url($path = '') { return home_url('/wp-admin/' . $path); }
// Like WordPress, values are NOT url-encoded here: callers encode what needs encoding.
function add_query_arg($args, $url) {
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $q);
    $q = array_merge($q, $args);
    $pairs = [];
    foreach ($q as $k => $v) $pairs[] = $k . '=' . $v;
    return $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '') . '?' . implode('&', $pairs);
}
function wp_validate_redirect($location, $default) {
    $host = parse_url($location, PHP_URL_HOST);
    if (!$host) return $location ?: $default;   // relative paths are fine
    $allowed = apply_filters('allowed_redirect_hosts', [parse_url(home_url(), PHP_URL_HOST)]);
    return in_array($host, $allowed, true) ? $location : $default;
}
function wp_generate_password($len = 12) { return bin2hex(random_bytes(intdiv($len, 2))); }
function status_header($code) { $GLOBALS['lwb_test']['status'] = $code; }
// header() is a PHP built-in and a no-op on the CLI; CORS tests assert on return values.
function wp_nonce_field($a, $n) { echo '<input type="hidden" name="' . $n . '" value="nonce">'; }
function wp_verify_nonce($v, $a) { return $v === 'nonce'; }
function checked($a, $b) { echo $a == $b ? 'checked' : ''; }
function selected($a, $b) { echo $a == $b ? 'selected' : ''; }
function submit_button($label) { echo '<button type="submit">' . esc_html($label) . '</button>'; }
function current_user_can($cap) { return true; }
function add_menu_page(...$a) {}
function add_submenu_page(...$a) {}

// ---------------------------------------------------------------------------
// Users & auth
// ---------------------------------------------------------------------------
class LwbFakeUser {
    public $ID; public $user_email; public $roles = [];
    public function __construct($id, $email, $roles) { $this->ID = $id; $this->user_email = $email; $this->roles = $roles; }
    public function set_role($r) { $this->roles = [$r]; }
}
class WP_Error { public $msg; public function __construct($m = '') { $this->msg = $m; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function lwb_test_add_user(string $email, array $roles = ['subscriber']): LwbFakeUser {
    $id = $GLOBALS['lwb_test']['next_user']++;
    return $GLOBALS['lwb_test']['users'][$id] = new LwbFakeUser($id, $email, $roles);
}
function wp_create_user($login, $pass, $email) { return lwb_test_add_user($email)->ID; }
function get_user_by($field, $value) {
    foreach ($GLOBALS['lwb_test']['users'] as $u) {
        if ($field === 'id' && $u->ID == $value) return $u;
        if ($field === 'email' && strcasecmp($u->user_email, (string) $value) === 0) return $u;
    }
    return false;
}
function user_can($user, $cap) {
    $caps = ['administrator' => ['manage_options', 'edit_posts'], 'editor' => ['edit_posts'], 'author' => ['edit_posts'], 'subscriber' => []];
    foreach ($user->roles as $r) if (in_array($cap, $caps[$r] ?? [], true)) return true;
    return false;
}
function wp_clear_auth_cookie() { $GLOBALS['lwb_test']['auth_cookie_for'] = null; }
function wp_set_current_user($id) { $GLOBALS['lwb_test']['current_user'] = $id; }
function wp_get_current_user() {
    $id = $GLOBALS['lwb_test']['current_user'];
    return $GLOBALS['lwb_test']['users'][$id] ?? new LwbFakeUser(0, '', []);
}
function wp_set_auth_cookie($id, $remember) { $GLOBALS['lwb_test']['auth_cookie_for'] = $id; }

class LwbRedirect extends Exception { public $location; public function __construct($l) { parent::__construct('redirect'); $this->location = $l; } }
function wp_safe_redirect($location) {
    $host = parse_url($location, PHP_URL_HOST);
    $allowed = apply_filters('allowed_redirect_hosts', [parse_url(home_url(), PHP_URL_HOST)]);
    if ($host && !in_array($host, $allowed, true)) $location = home_url('/');
    throw new LwbRedirect($location);
}

// ---------------------------------------------------------------------------
// REST primitives
// ---------------------------------------------------------------------------
class WP_REST_Request {
    private $params; private $headers; private $json;
    public function __construct(array $params = [], array $headers = [], $json = null) {
        $this->params = $params; $this->headers = array_change_key_case($headers, CASE_LOWER); $this->json = $json;
    }
    public function get_param($k) { return $this->params[$k] ?? ($this->json[$k] ?? null); }
    public function get_header($k) { return $this->headers[strtolower($k)] ?? null; }
    public function get_json_params() { return is_array($this->json) ? $this->json : []; }
}
class WP_REST_Response {
    public $data; public $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
}

// ---------------------------------------------------------------------------
// $wpdb over SQLite
// ---------------------------------------------------------------------------
function lwb_test_sql_to_sqlite(string $sql): string {
    // dbDelta CREATE TABLE â†’ SQLite DDL (dbDelta is idempotent: create-or-alter)
    $sql = preg_replace('/^\s*CREATE TABLE (?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $sql);
    $sql = preg_replace('/\b(\w+) (mediumint|int|bigint)\(\d+\)( unsigned)? NOT NULL AUTO_INCREMENT/i', '$1 INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/,\s*PRIMARY KEY\s+\(id\)/i', '', $sql);
    $sql = preg_replace('/,\s*UNIQUE KEY \w+ \(([^)]+)\)/i', ', UNIQUE ($1)', $sql);
    $sql = preg_replace('/,\s*KEY \w+ \([^)]+\)/i', '', $sql);
    $sql = preg_replace('/\bvarbinary\(\d+\)/i', 'BLOB', $sql);
    $sql = preg_replace('/\bunsigned\b/i', '', $sql);
    return $sql;
}
function dbDelta(string $sql) { $GLOBALS['wpdb']->pdo->exec(lwb_test_sql_to_sqlite($sql)); }

class LwbFakeWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $pdo;
    public $queries = [];

    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // MySQL functions the plugin relies on
        $this->pdo->sqliteCreateFunction('DATE_FORMAT', function ($ts, $fmt) {
            return date(strtr($fmt, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's']), strtotime($ts . ' UTC'));
        }, 2);
    }
    public function get_charset_collate() { return ''; }
    public function esc_like($s) { return addcslashes($s, '_%\\'); }

    private static function is_binary($v): bool {
        return is_string($v) && (preg_match('//u', $v) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v) === 1);
    }
    private function quote($v): string {
        if ($v === null) return 'NULL';
        if (is_int($v) || is_float($v)) return (string) $v;
        if (self::is_binary($v)) return "X'" . bin2hex($v) . "'";
        return $this->pdo->quote((string) $v);
    }

    /** Same contract as wpdb::prepare: %s %d %f placeholders, %% for a literal percent. */
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        $out = preg_replace_callback('/%%|%[sdf]/', function ($m) use (&$i, $args) {
            if ($m[0] === '%%') return '%';
            $v = $args[$i++] ?? null;
            if ($m[0] === '%d') return (string) intval($v);
            if ($m[0] === '%f') return (string) floatval($v);
            return $this->quote($v === null ? '' : (string) $v);
        }, $sql);
        return $out;
    }

    public function query($sql) {
        $this->queries[] = $sql;
        if (stripos(ltrim($sql), 'SET SESSION') === 0) return 0;   // MySQL-only session tweak
        return $this->pdo->exec($sql);
    }
    public function get_results($sql) { $this->queries[] = $sql; return $this->pdo->query($sql)->fetchAll(PDO::FETCH_OBJ); }
    public function get_row($sql) { $this->queries[] = $sql; $r = $this->pdo->query($sql)->fetch(PDO::FETCH_OBJ); return $r === false ? null : $r; }
    public function get_var($sql) { $this->queries[] = $sql; $v = $this->pdo->query($sql)->fetchColumn(); return $v === false ? null : $v; }
    public function get_col($sql) { $this->queries[] = $sql; return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }

    public function insert($table, array $data, $format = null) {
        $cols = array_keys($data);
        $sql  = "INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $st   = $this->pdo->prepare($sql);
        $n    = 1;
        foreach ($data as $v) {
            if ($v === null) $st->bindValue($n, null, PDO::PARAM_NULL);
            elseif (self::is_binary($v)) $st->bindValue($n, $v, PDO::PARAM_LOB);
            else $st->bindValue($n, $v);
            $n++;
        }
        $this->queries[] = $sql;
        $st->execute();
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return 1;
    }

    public function update($table, array $data, array $where) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(fn($c) => "$c = ?", array_keys($where)));
        $sql = "UPDATE $table SET $set WHERE $cond";
        $st  = $this->pdo->prepare($sql);
        $this->queries[] = $sql;
        $st->execute(array_merge(array_values($data), array_values($where)));
        return $st->rowCount();
    }

    // Test helpers
    public function count(string $table, string $where = '1=1'): int {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->prefix}$table WHERE $where")->fetchColumn();
    }
}

// ---------------------------------------------------------------------------
// Tiny assertion toolkit
// ---------------------------------------------------------------------------
class LwbAssertionFailed extends Exception {}
function assert_true($cond, string $msg = 'expected true'): void { if (!$cond) throw new LwbAssertionFailed($msg); }
function assert_false($cond, string $msg = 'expected false'): void { if ($cond) throw new LwbAssertionFailed($msg); }
function assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected != $actual) throw new LwbAssertionFailed(($msg ? "$msg â€” " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function assert_same($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) throw new LwbAssertionFailed(($msg ? "$msg â€” " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function assert_contains(string $needle, string $haystack, string $msg = ''): void {
    if (strpos($haystack, $needle) === false) throw new LwbAssertionFailed(($msg ? "$msg â€” " : '') . "'$needle' not found");
}
/** Run an SSO handler and return the redirect location it produced. */
function lwb_test_redirect(callable $fn): string {
    try { $fn(); } catch (LwbRedirect $r) { return $r->location; }
    throw new LwbAssertionFailed('expected a redirect');
}

$GLOBALS['lwb_tests'] = [];
function test(string $name, callable $fn): void { $GLOBALS['lwb_tests'][] = [$name, $fn]; }

// ---------------------------------------------------------------------------
// Load the plugin (admin pages too, so their render functions are covered)
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/lms-wordpress-bridge.php';
require_once dirname(__DIR__) . '/includes/admin/menu.php';
