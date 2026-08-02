<?php
declare(strict_types=1);

const SQLITE_ADMIN_VERSION = '1.1.4';
const SQLITE_ADMIN_PROJECT_URL = 'https://github.com/abilenetechguy/sqlite-admin';

error_reporting(E_ALL);

// ----- CONFIGURATION -----
$configFile = __DIR__ . '/config.php';
$installed = false;
$dbFile = '';
$username = '';
$passwordHash = '';
$debug = false;
$sessionName = 'sqlite_admin';

if (is_file($configFile)) {
    require $configFile;
}

ini_set('display_errors', !empty($debug) ? '1' : '0');
ini_set('log_errors', '1');

if (empty($installed)) {
    header('Location: install.php');
    exit;
}

if (!extension_loaded('sqlite3')) {
    http_response_code(500);
    exit('SQLite Admin requires the PHP SQLite3 extension.');
}

// ----- SESSION & SECURITY -----
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) $sessionName);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $cookiePath = $scriptDirectory === '/' ? '/' : rtrim($scriptDirectory, '/') . '/';
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    } else {
        session_set_cookie_params(0, $cookiePath, '', $isHttps, true);
    }
    session_start();
}

// Eight-hour inactivity timeout.
$now = time();
if (isset($_SESSION['last_activity'])
    && $now - (int) $_SESSION['last_activity'] > 28800) {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = $now;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; script-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Compatibility helpers for PHP 7.x. PHP 8 provides these functions natively.
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos((string) $haystack, (string) $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, 0, strlen($needle)) === $needle;
    }
}

function isListArray(array $array)
{
    $expectedKey = 0;
    foreach ($array as $key => $value) {
        if ($key !== $expectedKey) {
            return false;
        }
        $expectedKey++;
    }
    return true;
}

function jsonEncodeChecked($value, $options = 0)
{
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $options |= constant('JSON_INVALID_UTF8_SUBSTITUTE');
    }
    $encoded = json_encode($value, $options);
    if ($encoded === false) {
        throw new RuntimeException('JSON encoding failed: ' . json_last_error_msg());
    }
    return $encoded;
}

function jsonDecodeChecked($json, $associative = true)
{
    $decoded = json_decode((string) $json, (bool) $associative);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('JSON parsing failed: ' . json_last_error_msg());
    }
    return $decoded;
}

function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="'
        . h($_SESSION['csrf_token'] ?? '') . '">';
}

function requireCsrf()
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('The security token is missing or invalid. Reload the page and try again.');
    }
}

function quoteIdentifier($identifier)
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function isDatabaseFilename($filename)
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ['sqlite', 'db', 'sqlite3'], true)
        && basename($filename) === $filename;
}

function setFlash($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getQueryString($table, $search, array $colFilters, array $extra = [])
{
    $params = [];
    if ($table !== '') $params['table'] = $table;
    if ($search !== '') $params['search'] = $search;
    if ($colFilters !== []) $params['col_filters'] = $colFilters;
    $params = array_merge($params, $extra);
    return $params === [] ? '?' : '?' . http_build_query($params);
}

function currentAppPath()
{
    // Build a canonical URL for the real PHP file instead of trusting a rewritten
    // request such as /admin/admin. This also recovers cleanly from directory-index
    // requests where SCRIPT_NAME may end with a slash.
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptName !== '') {
        if (substr($scriptName, -1) === '/') {
            return rtrim($scriptName, '/') . '/admin.php';
        }
        if (strcasecmp(basename($scriptName), 'admin.php') === 0) {
            return $scriptName;
        }

        $directory = str_replace('\\', '/', dirname($scriptName));
        return ($directory === '/' ? '' : rtrim($directory, '/')) . '/admin.php';
    }

    return 'admin.php';
}

function redirectTo($location)
{
    header('Location: ' . $location);
    exit;
}

// ----- LOGIN -----
if (empty($_SESSION['admin_logged'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        requireCsrf();
        $submittedUser = trim((string) ($_POST['username'] ?? ''));
        $submittedPass = (string) ($_POST['password'] ?? '');
        $failures = (int) ($_SESSION['login_failures'] ?? 0);

        if ($failures >= 5) {
            usleep(750000);
        }

        if ($submittedUser !== ''
            && hash_equals((string) $username, $submittedUser)
            && password_verify($submittedPass, (string) $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged'] = true;
            $_SESSION['theme'] = $_SESSION['theme'] ?? 'light';
            $_SESSION['undo_history'] = $_SESSION['undo_history'] ?? [];
            $_SESSION['login_failures'] = 0;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } else {
            $_SESSION['login_failures'] = $failures + 1;
            $error = 'Invalid username or password.';
        }
    }

    if (empty($_SESSION['admin_logged'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SQLite Admin – Login</title>
    </head>
    <body style="font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f4f4f4;margin:0;padding:1rem;">
        <div style="background:#fff;padding:30px;border-radius:10px;width:min(360px,100%);box-shadow:0 4px 18px rgba(0,0,0,.12);">
            <h1 style="color:#2563eb;margin:0 0 1.25rem;text-align:center;font-size:1.6rem;">SQLite Admin</h1>
            <?php if ($error !== ''): ?>
                <p style="color:#991b1b;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;padding:.65rem;text-align:center;"><?php echo h($error); ?></p>
            <?php endif; ?>
            <form method="post" autocomplete="on">
                <?php echo csrfField(); ?>
                <input type="hidden" name="login" value="1">
                <div style="margin-bottom:12px;">
                    <label for="login-username" style="display:block;font-weight:600;margin-bottom:4px;">Username</label>
                    <input id="login-username" type="text" name="username" autocomplete="username" required value="<?php echo h($_POST['username'] ?? ''); ?>" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;">
                </div>
                <div style="margin-bottom:16px;">
                    <label for="login-password" style="display:block;font-weight:600;margin-bottom:4px;">Password</label>
                    <input id="login-password" type="password" name="password" autocomplete="current-password" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;">
                </div>
                <button type="submit" style="width:100%;padding:10px;background:#2563eb;color:white;border:none;border-radius:4px;cursor:pointer;font-size:1rem;">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
        exit;
    }
}

// ----- MULTIPLE DATABASE SUPPORT -----
$configuredDbFile = (string) $dbFile;
$dbDir = dirname($configuredDbFile);
$databases = [];
if (is_dir($dbDir)) {
    foreach (scandir($dbDir) ?: [] as $file) {
        if (isDatabaseFilename($file) && is_file($dbDir . DIRECTORY_SEPARATOR . $file)) {
            $databases[] = $file;
        }
    }
    natcasesort($databases);
    $databases = array_values($databases);
}

if (isset($_GET['db']) && in_array((string) $_GET['db'], $databases, true)) {
    $dbFile = $dbDir . DIRECTORY_SEPARATOR . (string) $_GET['db'];
    $_SESSION['current_db'] = $dbFile;
} elseif (!empty($_SESSION['current_db'])
    && is_file((string) $_SESSION['current_db'])
    && dirname((string) $_SESSION['current_db']) === $dbDir) {
    $dbFile = (string) $_SESSION['current_db'];
} else {
    $dbFile = $configuredDbFile;
    $_SESSION['current_db'] = $dbFile;
}

// ----- THEME TOGGLE -----
if (isset($_GET['theme'])) {
    $_SESSION['theme'] = $_GET['theme'] === 'dark' ? 'dark' : 'light';
    $themeReturnParameters = $_GET;
    unset($themeReturnParameters['theme']);
    $themeReturnUrl = currentAppPath();
    if ($themeReturnParameters !== []) {
        $themeReturnUrl .= '?' . http_build_query($themeReturnParameters);
    }
    redirectTo($themeReturnUrl);
}
$theme = $_SESSION['theme'] ?? 'light';
$isDark = ($theme === 'dark');

// ----- DATABASE CONNECTION -----
try {
    if (!is_file($dbFile)) {
        throw new RuntimeException('Database file not found: ' . $dbFile);
    }
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys = ON');
} catch (Throwable $exception) {
    http_response_code(500);
    $message = !empty($debug)
        ? $exception->getMessage()
        : 'The database could not be opened. Check its path and permissions.';
    exit('<div style="font-family:system-ui,sans-serif;padding:20px;max-width:680px;margin:40px auto;background:#fff;border:1px solid #ddd;border-radius:8px;"><h2 style="color:#b91c1c;">Database Error</h2><p>' . h($message) . '</p></div>');
}

// ----- HANDLE ACTIONS -----
$action = (string) ($_GET['action'] ?? 'browse');
$table = trim((string) ($_GET['table'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$allowedLimits = [25, 50, 100, 250, 500];
$limit = (int) ($_GET['limit'] ?? ($_SESSION['row_limit'] ?? 100));
if (!in_array($limit, $allowedLimits, true)) $limit = 100;
$_SESSION['row_limit'] = $limit;

$colFilters = [];
if (isset($_GET['col_filters']) && is_array($_GET['col_filters'])) {
    foreach ($_GET['col_filters'] as $column => $value) {
        $column = trim((string) $column);
        $value = trim((string) $value);
        if ($column !== '' && $value !== '') $colFilters[$column] = $value;
    }
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $sessionCookieName = session_name();
    $sessionCookieParameters = session_get_cookie_params();
    session_unset();
    session_destroy();
    if (ini_get('session.use_cookies')) {
        $logoutCookiePath = !empty($sessionCookieParameters['path']) ? $sessionCookieParameters['path'] : '/';
        $logoutCookieDomain = isset($sessionCookieParameters['domain']) ? $sessionCookieParameters['domain'] : '';
        $logoutCookieSecure = !empty($sessionCookieParameters['secure']);
        $logoutCookieHttpOnly = !isset($sessionCookieParameters['httponly']) || !empty($sessionCookieParameters['httponly']);
        if (PHP_VERSION_ID >= 70300) {
            setcookie($sessionCookieName, '', [
                'expires' => time() - 42000,
                'path' => $logoutCookiePath,
                'domain' => $logoutCookieDomain,
                'secure' => $logoutCookieSecure,
                'httponly' => $logoutCookieHttpOnly,
                'samesite' => 'Strict',
            ]);
        } else {
            setcookie(
                $sessionCookieName,
                '',
                time() - 42000,
                $logoutCookiePath,
                $logoutCookieDomain,
                $logoutCookieSecure,
                $logoutCookieHttpOnly
            );
        }
    }
    redirectTo(currentAppPath());
}

function getDatabaseObjectType(SQLite3 $db, $name)
{
    if ($name === '') return null;
    $statement = $db->prepare(
        "SELECT type FROM sqlite_master WHERE name = :name AND type IN ('table','view')"
    );
    $statement->bindValue(':name', $name, SQLITE3_TEXT);
    $result = $statement->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? (string) $row['type'] : null;
}

$objectType = getDatabaseObjectType($db, $table);
if ($table !== '' && $objectType === null) {
    setFlash('error', 'The selected table or view no longer exists.');
    $table = '';
}
$isView = ($objectType === 'view');

$viewWriteActions = [
    'import_table', 'bulk_delete', 'delete', 'update', 'insert',
    'drop_table', 'rename_table', 'add_column', 'rename_column'
];
if ($isView && in_array($action, $viewWriteActions, true)) {
    setFlash('error', 'Views are read-only in the visual editor. Edit the underlying tables instead.');
    redirectTo(getQueryString($table, $search, $colFilters));
}

$mutatingActions = [
    'undo', 'import_db', 'delete_db', 'drop_table', 'rename_table',
    'add_column', 'rename_column', 'create_table', 'import_table',
    'bulk_delete', 'delete', 'update'
];
if (in_array($action, $mutatingActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('This action requires POST.');
    }
    requireCsrf();
}
if ($action === 'query'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && array_key_exists('sql', $_POST)) {
    requireCsrf();
}

// ----- DATABASE HELPERS -----
function pushHistory($action, $table, array $data)
{
    $_SESSION['undo_history'] = $_SESSION['undo_history'] ?? [];
    array_unshift($_SESSION['undo_history'], [
        'action' => $action,
        'table' => $table,
        'data' => $data,
        'time' => time(),
    ]);
    $_SESSION['undo_history'] = array_slice($_SESSION['undo_history'], 0, 5);
}

function tableInfo(SQLite3 $db, $table)
{
    $result = $db->query('PRAGMA table_info(' . quoteIdentifier($table) . ')');
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row;
    }
    return $columns;
}

function tableLocator(SQLite3 $db, $table)
{
    $primaryKeys = [];
    foreach (tableInfo($db, $table) as $column) {
        if ((int) $column['pk'] > 0) {
            $primaryKeys[(int) $column['pk']] = [
                'name' => (string) $column['name'],
                'type' => (string) $column['type'],
            ];
        }
    }
    ksort($primaryKeys);
    if (count($primaryKeys) === 1) {
        $primaryKey = array_values($primaryKeys)[0];
        return [
            'column' => $primaryKey['name'],
            'rowid' => false,
            'type' => $primaryKey['type'],
        ];
    }

    try {
        $db->querySingle('SELECT rowid FROM ' . quoteIdentifier($table) . ' LIMIT 1');
        return ['column' => 'rowid', 'rowid' => true, 'type' => 'INTEGER'];
    } catch (Throwable $ignoredException) {
        return ['column' => null, 'rowid' => false, 'type' => ''];
    }
}

function bindTyped(SQLite3Stmt $statement, $parameter, $value, $declaredType = '')
{
    if ($value === null) {
        $statement->bindValue($parameter, null, SQLITE3_NULL);
        return;
    }

    $type = strtoupper($declaredType);
    if (is_int($value) || (str_contains($type, 'INT') && is_numeric((string) $value))) {
        $statement->bindValue($parameter, (int) $value, SQLITE3_INTEGER);
    } elseif (is_float($value)
        || ((str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB'))
            && is_numeric((string) $value))) {
        $statement->bindValue($parameter, (float) $value, SQLITE3_FLOAT);
    } elseif (str_contains($type, 'BLOB')) {
        $statement->bindValue($parameter, (string) $value, SQLITE3_BLOB);
    } else {
        $statement->bindValue($parameter, (string) $value, SQLITE3_TEXT);
    }
}

function fetchRowByLocator(SQLite3 $db, $table, array $locator, $value)
{
    if (empty($locator['column'])) return null;
    $columnSql = !empty($locator['rowid']) ? 'rowid' : quoteIdentifier((string) $locator['column']);
    $statement = $db->prepare(
        'SELECT * FROM ' . quoteIdentifier($table) . ' WHERE ' . $columnSql . ' = :locator LIMIT 1'
    );
    bindTyped($statement, ':locator', $value, (string) ($locator['type'] ?? ''));
    $result = $statement->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function deleteByLocator(SQLite3 $db, $table, array $locator, $value)
{
    if (empty($locator['column'])) {
        throw new RuntimeException('This table has no single editable row identifier.');
    }
    $columnSql = !empty($locator['rowid']) ? 'rowid' : quoteIdentifier((string) $locator['column']);
    $statement = $db->prepare(
        'DELETE FROM ' . quoteIdentifier($table) . ' WHERE ' . $columnSql . ' = :locator'
    );
    bindTyped($statement, ':locator', $value, (string) ($locator['type'] ?? ''));
    $statement->execute();
}

function insertAssocRow(SQLite3 $db, $table, array $row)
{
    if ($row === []) return;
    $columns = array_keys($row);
    $declaredTypes = [];
    foreach (tableInfo($db, $table) as $columnInfo) {
        $declaredTypes[(string) $columnInfo['name']] = (string) $columnInfo['type'];
    }
    $columnSql = implode(', ', array_map('quoteIdentifier', $columns));
    $placeholders = [];
    foreach ($columns as $index => $column) $placeholders[] = ':v' . $index;
    $statement = $db->prepare(
        'INSERT INTO ' . quoteIdentifier($table)
        . ' (' . $columnSql . ') VALUES (' . implode(', ', $placeholders) . ')'
    );
    foreach ($columns as $index => $column) {
        bindTyped(
            $statement,
            ':v' . $index,
            $row[$column],
            $declaredTypes[(string) $column] ?? ''
        );
    }
    $statement->execute();
}

function restoreAssocRow(SQLite3 $db, $table, array $row, array $locator, $locatorValue)
{
    $assignments = [];
    $statementValues = [];
    $declaredTypes = [];
    foreach (tableInfo($db, $table) as $columnInfo) {
        $declaredTypes[(string) $columnInfo['name']] = (string) $columnInfo['type'];
    }
    foreach ($row as $column => $value) {
        if (empty($locator['rowid']) && $column === $locator['column']) continue;
        $placeholder = ':v' . count($statementValues);
        $assignments[] = quoteIdentifier((string) $column) . ' = ' . $placeholder;
        $statementValues[$placeholder] = [
            'value' => $value,
            'type' => $declaredTypes[(string) $column] ?? '',
        ];
    }
    if ($assignments === []) return;
    $locatorSql = !empty($locator['rowid']) ? 'rowid' : quoteIdentifier((string) $locator['column']);
    $statement = $db->prepare(
        'UPDATE ' . quoteIdentifier($table) . ' SET ' . implode(', ', $assignments)
        . ' WHERE ' . $locatorSql . ' = :locator'
    );
    foreach ($statementValues as $placeholder => $entry) {
        bindTyped($statement, $placeholder, $entry['value'], $entry['type']);
    }
    $locatorType = empty($locator['rowid'])
        ? ($declaredTypes[(string) $locator['column']] ?? '')
        : 'INTEGER';
    bindTyped($statement, ':locator', $locatorValue, $locatorType);
    $statement->execute();
}

function buildWhere(array $columns, $search, array $filters, array &$parameters)
{
    $clauses = [];
    $parameters = [];
    if ($search !== '') {
        $parts = [];
        foreach ($columns as $index => $column) {
            $placeholder = ':search' . $index;
            $parts[] = 'CAST(' . quoteIdentifier((string) $column) . ' AS TEXT) LIKE ' . $placeholder;
            $parameters[$placeholder] = '%' . $search . '%';
        }
        if ($parts !== []) $clauses[] = '(' . implode(' OR ', $parts) . ')';
    }
    foreach ($filters as $column => $value) {
        if (!in_array($column, $columns, true)) continue;
        $placeholder = ':filter' . count($parameters);
        $clauses[] = 'CAST(' . quoteIdentifier($column) . ' AS TEXT) LIKE ' . $placeholder;
        $parameters[$placeholder] = '%' . $value . '%';
    }
    return $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses);
}

function queryWithTextParameters(SQLite3 $db, $sql, array $parameters)
{
    $statement = $db->prepare($sql);
    foreach ($parameters as $placeholder => $value) {
        $statement->bindValue($placeholder, (string) $value, SQLITE3_TEXT);
    }
    return $statement->execute();
}

function sqlLiteral($value, $declaredType = '')
{
    if ($value === null) return 'NULL';
    if (is_int($value) || is_float($value)) return (string) $value;
    $stringValue = (string) $value;
    if (str_contains(strtoupper($declaredType), 'BLOB')
        || preg_match('//u', $stringValue) !== 1) {
        return "X'" . bin2hex($stringValue) . "'";
    }
    return "'" . SQLite3::escapeString($stringValue) . "'";
}

function safeDownloadName($name, $fallback)
{
    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: '';
    return trim($clean, '._-') !== '' ? $clean : $fallback;
}

function createDatabaseSnapshot(SQLite3 $db, $sourcePath, $destinationPath)
{
    if (method_exists($db, 'backup')) {
        $backup = new SQLite3($destinationPath);
        $backup->enableExceptions(true);
        try {
            if (!$db->backup($backup)) {
                throw new RuntimeException('SQLite backup failed.');
            }
        } finally {
            $backup->close();
        }
        return;
    }

    $version = SQLite3::version();
    $versionNumber = isset($version['versionNumber']) ? (int) $version['versionNumber'] : 0;
    if ($versionNumber >= 3027000) {
        @unlink($destinationPath);
        $escapedPath = SQLite3::escapeString($destinationPath);
        $db->exec("VACUUM INTO '" . $escapedPath . "'");
        return;
    }

    // PHP versions before 7.4 do not expose SQLite3::backup(). Flush WAL data
    // before using the safest fallback available on older SQLite libraries.
    @$db->exec('PRAGMA wal_checkpoint(FULL)');
    if (!copy($sourcePath, $destinationPath)) {
        throw new RuntimeException('Database snapshot could not be copied.');
    }
}

function encodePortableValue($value, $declaredType, $forCsv = false)
{
    if ($value === null) return $forCsv ? '\\N' : null;
    if (str_contains(strtoupper($declaredType), 'BLOB')) {
        return 'base64:' . base64_encode((string) $value);
    }
    if ($forCsv && (string) $value === '\\N') return '\\\\N';
    return $value;
}

function decodePortableValue($value, $declaredType, $fromCsv = false)
{
    if ($fromCsv && $value === '\\N') return null;
    if ($fromCsv && $value === '\\\\N') return '\\N';
    if (str_contains(strtoupper($declaredType), 'BLOB')
        && is_string($value)
        && str_starts_with($value, 'base64:')) {
        $decoded = base64_decode(substr($value, 7), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64 BLOB value.');
        }
        return $decoded;
    }
    return $value;
}

// ----- UNDO -----
if ($action === 'undo') {
    if (empty($_SESSION['undo_history'])) {
        setFlash('error', 'Nothing to undo.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }

    $entry = array_shift($_SESSION['undo_history']);
    $undoTable = (string) $entry['table'];
    $undoAction = (string) $entry['action'];
    $undoData = (array) $entry['data'];

    try {
        $db->exec('BEGIN IMMEDIATE');
        switch ($undoAction) {
            case 'insert':
                deleteByLocator($db, $undoTable, (array) $undoData['locator'], $undoData['locator_value']);
                setFlash('success', 'Undo insert: row deleted.');
                break;
            case 'update':
                restoreAssocRow(
                    $db,
                    $undoTable,
                    (array) $undoData['old_data'],
                    (array) $undoData['locator'],
                    $undoData['locator_value']
                );
                setFlash('success', 'Undo update: row restored.');
                break;
            case 'delete':
                insertAssocRow($db, $undoTable, (array) $undoData['row_data']);
                setFlash('success', 'Undo delete: row restored.');
                break;
            case 'bulk_delete':
                foreach ((array) $undoData['rows'] as $row) insertAssocRow($db, $undoTable, (array) $row);
                setFlash('success', 'Undo bulk delete: rows restored.');
                break;
            case 'rename_table':
                $db->exec(
                    'ALTER TABLE ' . quoteIdentifier($undoTable)
                    . ' RENAME TO ' . quoteIdentifier((string) $undoData['old_name'])
                );
                $table = (string) $undoData['old_name'];
                setFlash('success', 'Undo rename: table renamed back.');
                break;
            case 'rename_column':
                $db->exec(
                    'ALTER TABLE ' . quoteIdentifier($undoTable)
                    . ' RENAME COLUMN ' . quoteIdentifier((string) $undoData['new_col'])
                    . ' TO ' . quoteIdentifier((string) $undoData['old_col'])
                );
                setFlash('success', 'Undo rename column: column renamed back.');
                break;
            case 'add_column':
                $version = (string) $db->querySingle('SELECT sqlite_version()');
                if (version_compare($version, '3.35.0', '<')) {
                    throw new RuntimeException('DROP COLUMN requires SQLite 3.35.0 or newer.');
                }
                $db->exec(
                    'ALTER TABLE ' . quoteIdentifier($undoTable)
                    . ' DROP COLUMN ' . quoteIdentifier((string) $undoData['col_name'])
                );
                setFlash('success', 'Undo add column: column dropped.');
                break;
            default:
                throw new RuntimeException('This action cannot be undone.');
        }
        $db->exec('COMMIT');
    } catch (Throwable $exception) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $ignoredException) {}
        setFlash('error', 'Undo failed: ' . $exception->getMessage());
    }
    redirectTo(getQueryString($table, $search, $colFilters));
}

// ----- GET SCHEMA (AJAX) -----
if ($action === 'get_schema' && isset($_GET['table'])) {
    $statement = $db->prepare(
        "SELECT sql FROM sqlite_master WHERE name = :name AND type IN ('table','view')"
    );
    $statement->bindValue(':name', trim((string) $_GET['table']), SQLITE3_TEXT);
    $result = $statement->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $row ? (string) $row['sql'] : 'Schema not found.';
    exit;
}

// ----- GET COLUMNS (AJAX) -----
if ($action === 'get_columns' && isset($_GET['table'])) {
    $tableName = trim((string) $_GET['table']);
    if (getDatabaseObjectType($db, $tableName) === null) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo '[]';
        exit;
    }
    $columns = [];
    foreach (tableInfo($db, $tableName) as $row) {
        $columns[] = [
            'name' => $row['name'],
            'type' => $row['type'],
            'pk' => (bool) $row['pk'],
        ];
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ----- EXPORT TABLE OR VIEW AS SQL -----
if ($action === 'export_sql' && $table !== '') {
    $statement = $db->prepare(
        "SELECT type, sql FROM sqlite_master WHERE name = :name AND type IN ('table','view')"
    );
    $statement->bindValue(':name', $table, SQLITE3_TEXT);
    $schemaResult = $statement->execute();
    $schema = $schemaResult->fetchArray(SQLITE3_ASSOC);
    if (!$schema) {
        setFlash('error', 'Schema not found.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }

    $content = '-- Exported from SQLite Admin' . "\n"
        . '-- ' . ucfirst((string) $schema['type']) . ': ' . $table . "\n"
        . '-- Date: ' . date('Y-m-d H:i:s') . "\n\n"
        . rtrim((string) $schema['sql'], "; \t\r\n") . ";\n\n";

    if ($schema['type'] === 'table') {
        $columnInfo = tableInfo($db, $table);
        $columns = array_map(static function (array $column) { return (string) $column['name']; }, $columnInfo);
        $declaredTypes = [];
        foreach ($columnInfo as $column) {
            $declaredTypes[(string) $column['name']] = (string) $column['type'];
        }
        $result = $db->query('SELECT * FROM ' . quoteIdentifier($table));
        $columnList = implode(', ', array_map('quoteIdentifier', $columns));
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = sqlLiteral($row[$column], $declaredTypes[$column] ?? '');
            }
            $content .= 'INSERT INTO ' . quoteIdentifier($table)
                . ' (' . $columnList . ') VALUES (' . implode(', ', $values) . ");\n";
        }
    }

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . safeDownloadName($table, 'export') . '.sql"');
    echo $content;
    exit;
}

// ----- DELETE AN IMPORTED DATABASE -----
if ($action === 'delete_db') {
    $databaseName = basename(trim((string) ($_POST['db'] ?? '')));
    $databasePath = $dbDir . DIRECTORY_SEPARATOR . $databaseName;
    if (!in_array($databaseName, $databases, true) || !isDatabaseFilename($databaseName)) {
        setFlash('error', 'Invalid database file.');
        redirectTo('?');
    }
    if (realpath($databasePath) === realpath($configuredDbFile)) {
        setFlash('error', 'The configured primary database cannot be deleted from the web interface.');
        redirectTo('?');
    }
    if ((string) ($_POST['confirm_name'] ?? '') !== $databaseName) {
        setFlash('error', 'Database deletion was not confirmed.');
        redirectTo('?');
    }

    $wasCurrent = realpath($databasePath) === realpath($dbFile);
    if ($wasCurrent) $db->close();
    if (!@unlink($databasePath)) {
        setFlash('error', 'The database could not be deleted. Check file permissions.');
        redirectTo('?');
    }
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
    if ($wasCurrent) $_SESSION['current_db'] = $configuredDbFile;
    setFlash('success', 'Database "' . $databaseName . '" deleted.');
    redirectTo('?');
}

// ----- IMPORT DATABASE -----
if ($action === 'import_db') {
    $file = $_FILES['db_file'] ?? null;
    if (!is_array($file) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Database upload failed.');
        redirectTo('?');
    }
    if ((int) $file['size'] > 104857600) {
        setFlash('error', 'Database files are limited to 100 MB.');
        redirectTo('?');
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['sqlite', 'db', 'sqlite3'], true)) {
        setFlash('error', 'Only .sqlite, .db, and .sqlite3 files are allowed.');
        redirectTo('?');
    }
    $handle = fopen((string) $file['tmp_name'], 'rb');
    $header = $handle ? fread($handle, 16) : false;
    if (is_resource($handle)) fclose($handle);
    if ($header !== "SQLite format 3\x00") {
        setFlash('error', 'The uploaded file is not a valid SQLite database.');
        redirectTo('?');
    }

    $base = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo((string) $file['name'], PATHINFO_FILENAME)) ?: 'database';
    $destinationName = $base . '.' . $extension;
    $destinationPath = $dbDir . DIRECTORY_SEPARATOR . $destinationName;
    for ($counter = 1; is_file($destinationPath); $counter++) {
        $destinationName = $base . '_' . $counter . '.' . $extension;
        $destinationPath = $dbDir . DIRECTORY_SEPARATOR . $destinationName;
    }

    if (!move_uploaded_file((string) $file['tmp_name'], $destinationPath)) {
        setFlash('error', 'The uploaded database could not be saved.');
        redirectTo('?');
    }
    try {
        $testDb = new SQLite3($destinationPath, SQLITE3_OPEN_READONLY);
        $testDb->enableExceptions(true);
        $integrity = (string) $testDb->querySingle('PRAGMA integrity_check');
        $testDb->close();
        if ($integrity !== 'ok') throw new RuntimeException('Integrity check failed.');
    } catch (Throwable $exception) {
        @unlink($destinationPath);
        setFlash('error', 'The uploaded database failed validation: ' . $exception->getMessage());
        redirectTo('?');
    }

    $_SESSION['current_db'] = $destinationPath;
    setFlash('success', 'Database "' . $destinationName . '" imported.');
    redirectTo('?db=' . urlencode($destinationName));
}

// ----- EXPORT DATABASE AS A CONSISTENT SNAPSHOT -----
if ($action === 'export_db') {
    $temporary = tempnam(sys_get_temp_dir(), 'sqlite-admin-');
    if ($temporary === false) {
        http_response_code(500);
        exit('A temporary export file could not be created.');
    }
    try {
        createDatabaseSnapshot($db, $dbFile, $temporary);
        $downloadName = safeDownloadName(basename($dbFile), 'database.sqlite');
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($temporary));
        readfile($temporary);
    } finally {
        @unlink($temporary);
    }
    exit;
}

// ----- DROP TABLE -----
if ($action === 'drop_table') {
    $tableName = trim((string) ($_POST['table'] ?? ''));
    if (getDatabaseObjectType($db, $tableName) !== 'table') {
        setFlash('error', 'Table not found.');
        redirectTo('?');
    }
    try {
        $db->exec('DROP TABLE ' . quoteIdentifier($tableName));
        setFlash('success', 'Table "' . $tableName . '" dropped.');
    } catch (Throwable $exception) {
        setFlash('error', 'Failed to drop table: ' . $exception->getMessage());
    }
    redirectTo('?');
}

// ----- RENAME TABLE -----
if ($action === 'rename_table') {
    $oldName = trim((string) ($_POST['old_name'] ?? ''));
    $newName = trim((string) ($_POST['new_name'] ?? ''));
    if (getDatabaseObjectType($db, $oldName) !== 'table'
        || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $newName)) {
        setFlash('error', 'Enter a valid table name.');
        redirectTo('?');
    }
    try {
        $db->exec('ALTER TABLE ' . quoteIdentifier($oldName) . ' RENAME TO ' . quoteIdentifier($newName));
        pushHistory('rename_table', $newName, ['old_name' => $oldName]);
        setFlash('success', 'Table renamed to "' . $newName . '".');
        redirectTo(getQueryString($newName, $search, $colFilters));
    } catch (Throwable $exception) {
        setFlash('error', 'Failed to rename table: ' . $exception->getMessage());
        redirectTo('?');
    }
}

// ----- ADD COLUMN -----
if ($action === 'add_column') {
    $tableName = trim((string) ($_POST['table'] ?? ''));
    $columnName = trim((string) ($_POST['col_name'] ?? ''));
    $columnType = strtoupper(trim((string) ($_POST['col_type'] ?? 'TEXT')));
    $allowedTypes = ['TEXT', 'INTEGER', 'REAL', 'NUMERIC', 'BLOB'];
    if (getDatabaseObjectType($db, $tableName) !== 'table'
        || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $columnName)
        || !in_array($columnType, $allowedTypes, true)) {
        setFlash('error', 'Invalid table, column name, or column type.');
        redirectTo(getQueryString($tableName, $search, $colFilters));
    }
    try {
        $db->exec(
            'ALTER TABLE ' . quoteIdentifier($tableName)
            . ' ADD COLUMN ' . quoteIdentifier($columnName) . ' ' . $columnType
        );
        pushHistory('add_column', $tableName, ['col_name' => $columnName]);
        setFlash('success', 'Column "' . $columnName . '" added.');
    } catch (Throwable $exception) {
        setFlash('error', 'Failed to add column: ' . $exception->getMessage());
    }
    redirectTo(getQueryString($tableName, $search, $colFilters));
}

// ----- RENAME COLUMN -----
if ($action === 'rename_column') {
    $tableName = trim((string) ($_POST['table'] ?? ''));
    $oldColumn = trim((string) ($_POST['old_col'] ?? ''));
    $newColumn = trim((string) ($_POST['new_col'] ?? ''));
    $columnNames = array_map(static function (array $column) { return (string) $column['name']; }, tableInfo($db, $tableName));
    if (getDatabaseObjectType($db, $tableName) !== 'table'
        || !in_array($oldColumn, $columnNames, true)
        || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $newColumn)) {
        setFlash('error', 'Invalid table or column name.');
        redirectTo(getQueryString($tableName, $search, $colFilters));
    }
    try {
        $db->exec(
            'ALTER TABLE ' . quoteIdentifier($tableName)
            . ' RENAME COLUMN ' . quoteIdentifier($oldColumn)
            . ' TO ' . quoteIdentifier($newColumn)
        );
        pushHistory('rename_column', $tableName, ['old_col' => $oldColumn, 'new_col' => $newColumn]);
        setFlash('success', 'Column renamed to "' . $newColumn . '".');
    } catch (Throwable $exception) {
        setFlash('error', 'Failed to rename column: ' . $exception->getMessage());
    }
    redirectTo(getQueryString($tableName, $search, $colFilters));
}

// ----- CREATE TABLE -----
if ($action === 'create_table') {
    $tableName = trim((string) ($_POST['table_name'] ?? ''));
    $columnNames = is_array($_POST['col_name'] ?? null) ? $_POST['col_name'] : [];
    $columnTypes = is_array($_POST['col_type'] ?? null) ? $_POST['col_type'] : [];
    $primaryKeyIndex = isset($_POST['primary_key_index']) ? (int) $_POST['primary_key_index'] : -1;
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName)
        || getDatabaseObjectType($db, $tableName) !== null) {
        setFlash('error', 'Enter a unique, valid table name.');
        redirectTo('?');
    }

    $definitions = [];
    $hasPrimaryKey = false;
    $allowedTypes = ['TEXT', 'INTEGER', 'REAL', 'NUMERIC', 'BLOB'];
    foreach ($columnNames as $index => $rawName) {
        $name = trim((string) $rawName);
        $type = strtoupper(trim((string) ($columnTypes[$index] ?? 'TEXT')));
        if ($name === '') continue;
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || !in_array($type, $allowedTypes, true)) {
            setFlash('error', 'Invalid column definition: ' . $name);
            redirectTo('?');
        }
        $definition = quoteIdentifier($name) . ' ' . $type;
        if ($index === $primaryKeyIndex) {
            if ($hasPrimaryKey) {
                setFlash('error', 'The visual table creator supports one primary key.');
                redirectTo('?');
            }
            $definition .= ' PRIMARY KEY';
            $hasPrimaryKey = true;
        }
        $definitions[] = $definition;
    }
    if ($definitions === []) {
        setFlash('error', 'Add at least one column.');
        redirectTo('?');
    }
    try {
        $db->exec(
            'CREATE TABLE ' . quoteIdentifier($tableName)
            . ' (' . implode(', ', $definitions) . ')'
        );
        setFlash('success', 'Table "' . $tableName . '" created.');
        redirectTo(getQueryString($tableName, '', []));
    } catch (Throwable $exception) {
        setFlash('error', 'Failed to create table: ' . $exception->getMessage());
        redirectTo('?');
    }
}

// ----- EXPORT TABLE / VIEW (CSV OR JSON) -----
if ($action === 'export_table' && $table !== '') {
    $format = strtolower((string) ($_GET['format'] ?? 'csv'));
    if (!in_array($format, ['csv', 'json'], true)) {
        http_response_code(400);
        exit('Unsupported export format.');
    }
    $exportColumnInfo = tableInfo($db, $table);
    $columns = array_map(static function (array $column) { return (string) $column['name']; }, $exportColumnInfo);
    $exportTypes = [];
    foreach ($exportColumnInfo as $column) {
        $exportTypes[(string) $column['name']] = (string) $column['type'];
    }
    $parameters = [];
    $where = buildWhere($columns, $search, $colFilters, $parameters);
    $result = queryWithTextParameters(
        $db,
        'SELECT * FROM ' . quoteIdentifier($table) . $where,
        $parameters
    );

    $downloadBase = safeDownloadName($table, 'table');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $downloadBase . '.csv"');
        $output = fopen('php://output', 'wb');
        fputcsv($output, $columns);
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $csvRow = [];
            foreach ($columns as $column) {
                $csvRow[] = encodePortableValue(
                    $row[$column] ?? null,
                    $exportTypes[$column] ?? '',
                    true
                );
            }
            fputcsv($output, $csvRow);
        }
        fclose($output);
    } else {
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $portableRow = [];
            foreach ($columns as $column) {
                $portableRow[$column] = encodePortableValue(
                    $row[$column] ?? null,
                    $exportTypes[$column] ?? ''
                );
            }
            $rows[] = $portableRow;
        }
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $downloadBase . '.json"');
        echo jsonEncodeChecked(
            $rows,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
    exit;
}

// ----- IMPORT TABLE (CSV OR JSON) -----
if ($action === 'import_table' && $table !== '') {
    $file = $_FILES['import_file'] ?? null;
    if (!is_array($file) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Table import failed.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }
    if ((int) ($file['size'] ?? 0) > 26214400) {
        setFlash('error', 'Table import files are limited to 25 MB.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'json'], true)) {
        setFlash('error', 'Only CSV and JSON files are supported.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }

    $importColumnInfo = tableInfo($db, $table);
    $tableColumns = array_map(static function (array $column) { return (string) $column['name']; }, $importColumnInfo);
    $importTypes = [];
    foreach ($importColumnInfo as $column) {
        $importTypes[(string) $column['name']] = (string) $column['type'];
    }
    $rows = [];
    try {
        if ($extension === 'csv') {
            $handle = fopen((string) $file['tmp_name'], 'rb');
            if (!$handle) throw new RuntimeException('The CSV file could not be read.');
            $header = fgetcsv($handle);
            if (!is_array($header) || $header === []) throw new RuntimeException('CSV header row is missing.');
            $header = array_map(static function ($value) { return trim((string) $value); }, $header);
            if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
            if (count(array_unique($header)) !== count($header)) {
                throw new RuntimeException('CSV column names must be unique.');
            }
            foreach ($header as $column) {
                if (!in_array($column, $tableColumns, true)) {
                    throw new RuntimeException('Unknown CSV column: ' . $column);
                }
            }
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($header)) continue;
                $csvRow = array_combine($header, $values);
                if (!is_array($csvRow)) continue;
                foreach ($csvRow as $column => $value) {
                    $csvRow[$column] = decodePortableValue(
                        $value,
                        $importTypes[(string) $column] ?? '',
                        true
                    );
                }
                $rows[] = $csvRow;
            }
            fclose($handle);
        } else {
            $decoded = jsonDecodeChecked(file_get_contents((string) $file['tmp_name']), true);
            if (!is_array($decoded) || !isListArray($decoded)) {
                throw new RuntimeException('JSON must contain a top-level array of objects.');
            }
            foreach ($decoded as $row) {
                if (!is_array($row)) continue;
                $clean = [];
                foreach ($row as $column => $value) {
                    if (!in_array((string) $column, $tableColumns, true)) continue;
                    if (is_array($value) || is_object($value)) {
                        $value = jsonEncodeChecked(
                            $value,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                    }
                    $clean[(string) $column] = decodePortableValue(
                        $value,
                        $importTypes[(string) $column] ?? ''
                    );
                }
                if ($clean !== []) $rows[] = $clean;
            }
        }

        if ($rows === []) {
            throw new RuntimeException('The import file contains no usable rows.');
        }

        $inserted = 0;
        $errors = 0;
        $db->exec('BEGIN IMMEDIATE');
        foreach ($rows as $row) {
            try {
                insertAssocRow($db, $table, $row);
                $inserted++;
            } catch (Throwable $ignoredException) {
                $errors++;
            }
        }
        $db->exec('COMMIT');
        setFlash('success', "Import completed: $inserted inserted, $errors skipped.");
    } catch (Throwable $exception) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $ignoredException) {}
        setFlash('error', 'Import failed: ' . $exception->getMessage());
    }
    redirectTo(getQueryString($table, $search, $colFilters));
}

// ----- BULK DELETE -----
if ($action === 'bulk_delete' && $table !== '') {
    $selected = is_array($_POST['selected'] ?? null) ? array_values(array_unique($_POST['selected'])) : [];
    $locator = tableLocator($db, $table);
    if ($selected === [] || empty($locator['column'])) {
        setFlash('error', $selected === [] ? 'No rows selected.' : 'This table has no editable row identifier.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }
    $rows = [];
    try {
        $db->exec('BEGIN IMMEDIATE');
        foreach ($selected as $value) {
            $row = fetchRowByLocator($db, $table, $locator, $value);
            if ($row !== null) {
                $rows[] = $row;
                deleteByLocator($db, $table, $locator, $value);
            }
        }
        $db->exec('COMMIT');
        pushHistory('bulk_delete', $table, ['rows' => $rows]);
        setFlash('success', count($rows) . ' rows deleted.');
    } catch (Throwable $exception) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $ignoredException) {}
        setFlash('error', 'Bulk delete failed: ' . $exception->getMessage());
    }
    redirectTo(getQueryString($table, $search, $colFilters));
}

// ----- DELETE SINGLE ROW -----
if ($action === 'delete' && $table !== '') {
    $locator = tableLocator($db, $table);
    $value = $_POST['pk'] ?? null;
    $row = $value !== null ? fetchRowByLocator($db, $table, $locator, $value) : null;
    if ($row === null) {
        setFlash('error', 'Row not found.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }
    try {
        deleteByLocator($db, $table, $locator, $value);
        pushHistory('delete', $table, ['row_data' => $row]);
        setFlash('success', 'Row deleted.');
    } catch (Throwable $exception) {
        setFlash('error', 'Delete failed: ' . $exception->getMessage());
    }
    redirectTo(getQueryString($table, $search, $colFilters));
}

// ----- UPDATE ROW -----
if ($action === 'update' && $table !== '') {
    $locator = tableLocator($db, $table);
    $locatorValue = $_POST['pk'] ?? null;
    $oldData = $locatorValue !== null ? fetchRowByLocator($db, $table, $locator, $locatorValue) : null;
    if ($oldData === null || empty($locator['column'])) {
        setFlash('error', 'Row not found or not editable.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }

    $nullFields = is_array($_POST['null_fields'] ?? null) ? $_POST['null_fields'] : [];
    $assignments = [];
    $values = [];
    foreach (tableInfo($db, $table) as $column) {
        $name = (string) $column['name'];
        if (str_contains(strtoupper((string) $column['type']), 'BLOB')) continue;
        if (empty($locator['rowid']) && $name === $locator['column']) continue;
        $placeholder = ':v' . count($values);
        $assignments[] = quoteIdentifier($name) . ' = ' . $placeholder;
        $values[] = [
            'placeholder' => $placeholder,
            'value' => in_array($name, $nullFields, true) ? null : ($_POST[$name] ?? ''),
            'type' => (string) $column['type'],
        ];
    }

    if ($assignments === []) {
        setFlash('error', 'This row has no visual-editor fields that can be changed.');
        redirectTo(getQueryString($table, $search, $colFilters));
    }

    try {
        $locatorSql = !empty($locator['rowid']) ? 'rowid' : quoteIdentifier((string) $locator['column']);
        $statement = $db->prepare(
            'UPDATE ' . quoteIdentifier($table) . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $locatorSql . ' = :locator'
        );
        foreach ($values as $item) bindTyped($statement, $item['placeholder'], $item['value'], $item['type']);
        bindTyped($statement, ':locator', $locatorValue, (string) ($locator['type'] ?? ''));
        $statement->execute();
        pushHistory('update', $table, [
            'locator' => $locator,
            'locator_value' => $locatorValue,
            'old_data' => $oldData,
        ]);
        setFlash('success', 'Row updated.');
    } catch (Throwable $exception) {
        setFlash('error', 'Update failed: ' . $exception->getMessage());
    }
    redirectTo(getQueryString($table, $search, $colFilters));
}

// ----- INSERT ROW -----
if ($action === 'insert' && $table !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $nullFields = is_array($_POST['null_fields'] ?? null) ? $_POST['null_fields'] : [];
    $columns = [];
    $values = [];
    $primaryKey = null;
    foreach (tableInfo($db, $table) as $column) {
        $name = (string) $column['name'];
        if (str_contains(strtoupper((string) $column['type']), 'BLOB')) continue;
        if ((int) $column['pk'] > 0 && $primaryKey === null) $primaryKey = $name;
        $submitted = $_POST[$name] ?? '';
        if ((int) $column['pk'] > 0
            && str_contains(strtoupper((string) $column['type']), 'INT')
            && $submitted === ''
            && !in_array($name, $nullFields, true)) {
            continue;
        }
        $columns[] = $column;
        $values[$name] = in_array($name, $nullFields, true) ? null : $submitted;
    }

    try {
        if ($columns === []) {
            $db->exec('INSERT INTO ' . quoteIdentifier($table) . ' DEFAULT VALUES');
        } else {
            $columnSql = implode(', ', array_map(
                static function (array $column) { return quoteIdentifier((string) $column['name']); },
                $columns
            ));
            $placeholders = [];
            foreach ($columns as $index => $column) $placeholders[] = ':v' . $index;
            $statement = $db->prepare(
                'INSERT INTO ' . quoteIdentifier($table)
                . ' (' . $columnSql . ') VALUES (' . implode(', ', $placeholders) . ')'
            );
            foreach ($columns as $index => $column) {
                $name = (string) $column['name'];
                bindTyped($statement, ':v' . $index, $values[$name], (string) $column['type']);
            }
            $statement->execute();
        }

        $locator = tableLocator($db, $table);
        $locatorValue = null;
        if (!empty($locator['rowid'])) {
            $locatorValue = $db->lastInsertRowID();
        } elseif (!empty($locator['column'])) {
            $locatorValue = $values[$locator['column']] ?? $db->lastInsertRowID();
        }
        if ($locatorValue !== null) {
            pushHistory('insert', $table, ['locator' => $locator, 'locator_value' => $locatorValue]);
        }
        setFlash('success', 'New row inserted.');
    } catch (Throwable $exception) {
        $message = str_contains($exception->getMessage(), 'UNIQUE constraint failed')
            ? 'A row with that unique or primary-key value already exists.'
            : $exception->getMessage();
        setFlash('error', 'Insert failed: ' . $message);
    }
    redirectTo(getQueryString($table, $search, $colFilters));
}

// ----- GATHER DATABASE INFO (for sidebar) -----
$dbSize = file_exists($dbFile) ? filesize($dbFile) : 0;
$dbSizeFormatted = $dbSize ? round($dbSize / 1024, 1) . ' KB' : '0 KB';
if ($dbSize > 1048576) $dbSizeFormatted = round($dbSize / 1048576, 1) . ' MB';
$tableCount = (int) $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
$viewCount = (int) $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='view' AND name NOT LIKE 'sqlite_%'");
$lastModified = file_exists($dbFile) ? date('Y-m-d H:i:s', filemtime($dbFile)) : 'N/A';

// ----- PAGE OUTPUT -----
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $isDark ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQLite Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
    /* ----- Base ----- */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
        height: 100vh;
        width: 100vw;
        overflow: hidden;
        margin: 0;
        padding: 0;
    }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        background: var(--bg-body);
        color: var(--text-body);
        transition: background 0.2s, color 0.2s;
        display: flex;
        flex-direction: column;
    }
    /* Compact scrollbars keep more room available for table data. */
    * {
        scrollbar-width: thin;
        scrollbar-color: var(--text-muted) transparent;
    }
    *::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    *::-webkit-scrollbar-track {
        background: transparent;
    }
    *::-webkit-scrollbar-thumb {
        background: var(--text-muted);
        border: 2px solid transparent;
        background-clip: padding-box;
        border-radius: 999px;
    }
    *::-webkit-scrollbar-thumb:hover {
        background: var(--primary);
        border: 2px solid transparent;
        background-clip: padding-box;
    }
    :root {
        --bg-body: #f1f5f9;
        --bg-sidebar: #f8fafc;
        --bg-header: #ffffff;
        --bg-main: #fafcff;
        --text-body: #1e293b;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --shadow: 0 1px 3px rgba(0,0,0,0.06);
        --primary: #2563eb;
        --primary-hover: #1d4ed8;
        --danger: #ef4444;
        --danger-hover: #dc2626;
        --success: #22c55e;
        --success-hover: #16a34a;
        --purple: #8b5cf6;
        --purple-hover: #7c3aed;
        --table-stripe: #f1f5f9;
        --input-bg: #ffffff;
        --input-border: #d1d5db;
        --flash-success-bg: #dcfce7;
        --flash-success-text: #166534;
        --flash-error-bg: #fee2e2;
        --flash-error-text: #991b1b;
        --footer-bg: #f8fafc;
        /* Sidebar width (resizable) */
        --sidebar-width: 360px;
    }
    .dark {
        --bg-body: #0f172a;
        --bg-sidebar: #1a2332;
        --bg-header: #1e293b;
        --bg-main: #172032;
        --text-body: #e2e8f0;
        --text-muted: #94a3b8;
        --border-color: #334155;
        --shadow: 0 1px 3px rgba(0,0,0,0.3);
        --primary: #3b82f6;
        --primary-hover: #60a5fa;
        --danger: #f87171;
        --danger-hover: #fca5a5;
        --success: #4ade80;
        --success-hover: #86efac;
        --purple: #a78bfa;
        --purple-hover: #8b5cf6;
        --table-stripe: #1e293b;
        --input-bg: #0f172a;
        --input-border: #475569;
        --flash-success-bg: #064e3b;
        --flash-success-text: #86efac;
        --flash-error-bg: #7f1d1d;
        --flash-error-text: #fca5a5;
        --footer-bg: #1a2332;
    }

    /* ----- Master Layout Containers ----- */
    .app-container {
        display: flex;
        flex: 1;
        min-height: 0;
        overflow: hidden;
        background: var(--bg-body);
    }

    /* Sidebar with variable width */
    .sidebar {
        width: var(--sidebar-width);
        background: var(--bg-sidebar);
        border-right: 1px solid var(--border-color);
        padding: 1rem 0;
        overflow-y: auto;
        flex-shrink: 0;
        transition: background 0.2s;
        display: flex;
        flex-direction: column;
    }

    .main {
        flex: 1;
        min-width: 0;
        padding: 1.5rem 2rem 0 2rem;
        background: var(--bg-main);
        overflow: hidden;
        transition: background 0.2s;
        border-left: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
    }

    /* Resize handle */
    .resize-handle {
        width: 6px;
        background: transparent;
        cursor: col-resize;
        flex-shrink: 0;
        transition: background 0.15s;
        position: relative;
        z-index: 20;
    }
    .resize-handle:hover,
    .resize-handle:active {
        background: var(--primary);
    }
    .resize-handle::after {
        content: '';
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 2px;
        height: 100%;
        background: var(--border-color);
        transition: background 0.15s;
    }
    .resize-handle:hover::after,
    .resize-handle:active::after {
        background: var(--primary);
    }

    /* The filter form is only a submission target and must not consume layout space. */
    #col-filter-form {
        display: none;
    }
    .query-area {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
    }

    /* ----- Sidebar Components ----- */
    .sidebar .db-header {
        padding: 0 1.2rem 0.5rem 1.2rem;
        border-bottom: 2px solid var(--primary);
        margin-bottom: 0.5rem;
    }
    .sidebar .db-header .db-name {
        font-weight: 700;
        font-size: 1rem;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }
    .sidebar .db-header .db-name .size {
        font-weight: 400;
        font-size: 0.75rem;
        color: var(--text-muted);
    }
    /* Database Switcher in sidebar */
    .db-switcher-sidebar {
        padding: 0.5rem 1.2rem;
        border-bottom: 1px solid var(--border-color);
        margin-bottom: 0.5rem;
    }
    .db-switcher-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-bottom: 0.2rem;
    }
    .db-switcher-label i {
        margin-right: 0.3rem;
    }
    .db-switcher-sidebar select {
        width: 100%;
        padding: 0.3rem 0.6rem;
        border: 1px solid var(--input-border);
        border-radius: 0.25rem;
        background: var(--input-bg);
        color: var(--text-body);
        font-size: 0.9rem;
        appearance: auto;
        cursor: pointer;
    }
    .db-switcher-sidebar select:focus {
        outline: 2px solid var(--primary);
        outline-offset: -1px;
    }

    .tables-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 1.2rem;
        margin-bottom: 0.3rem;
    }
    .tables-heading h3 {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        margin: 0;
    }
    .tables-heading .btn-plus {
        background: none;
        border: none;
        color: var(--primary);
        cursor: pointer;
        font-size: 1.2rem;
        padding: 0.1rem 0.3rem;
        border-radius: 0.25rem;
        transition: background 0.15s;
    }
    .tables-heading .btn-plus:hover {
        background: var(--border-color);
    }
    .table-item {
        display: flex;
        align-items: center;
        padding: 0.25rem 1.2rem;
        transition: background 0.15s;
        border-radius: 0.25rem;
        margin: 0.1rem 0;
    }
    .table-item:hover {
        background: var(--border-color);
    }
    .table-item a {
        flex: 1;
        display: flex;
        align-items: center;
        padding: 0.3rem 0;
        color: var(--text-body);
        text-decoration: none;
        font-size: 0.95rem;
        padding-left: 0;
        min-width: 0; /* allows truncation */
    }
    .table-item a.active {
        font-weight: 600;
    }
    .table-item a .icon {
        width: 1.8rem;
        font-size: 1.1rem;
        color: var(--primary);
        flex-shrink: 0;
        text-align: left;
        opacity: 0.85;
        transition: opacity 0.15s, transform 0.15s;
    }
    .table-item:hover a .icon {
        opacity: 1;
        transform: scale(1.05);
    }
    .table-item a .name {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
    }
    .table-item a .name .row-count {
        flex-shrink: 0;
        font-size: 0.7rem;
        background: var(--border-color);
        color: var(--text-muted);
        padding: 0.05rem 0.5rem;
        border-radius: 9999px;
        white-space: nowrap;
        font-weight: 400;
    }
    .table-item .table-actions {
        display: flex;
        gap: 0.4rem;
        opacity: 1;
        margin-left: 0.3rem;
        flex-shrink: 0;
    }
    .table-item .table-actions button {
        background: none;
        border: none;
        color: var(--primary);
        cursor: pointer;
        font-size: 1.1rem;
        padding: 0.15rem 0.3rem;
        border-radius: 0.2rem;
        transition: background 0.15s, color 0.15s, transform 0.15s;
    }
    .table-item .table-actions button:hover {
        background: var(--border-color);
        color: var(--primary-hover);
        transform: scale(1.1);
    }
    .table-item .table-actions button.danger {
        color: var(--danger);
    }
    .table-item .table-actions button.danger:hover {
        color: var(--danger-hover);
    }
    .sidebar .query-link {
        margin-top: 1rem;
        border-top: 1px solid var(--border-color);
        padding-top: 0.75rem;
    }
    .sidebar .query-link a {
        display: flex;
        align-items: center;
        padding: 0.5rem 1.2rem;
        color: var(--text-body);
        text-decoration: none;
        font-size: 0.9rem;
        border-left: 3px solid transparent;
    }
    .sidebar .query-link a i {
        width: 1.6rem;
        color: var(--text-muted);
    }
    .sidebar .query-link a.active {
        border-left-color: var(--primary);
        font-weight: 500;
    }
    .sidebar-actions {
        padding: 0.5rem 1.2rem;
        margin-top: 0.5rem;
        border-top: 1px solid var(--border-color);
        padding-top: 0.75rem;
        display: flex;
        flex-direction: row;
        gap: 0.3rem;
        justify-content: space-between;
    }
    .sidebar-actions .btn {
        display: flex;
        flex: 1;
        justify-content: center;
        padding: 0.25rem 0.4rem;
        font-size: 0.8rem;
        min-height: 30px;
    }

    /* ----- Header & Toolbars ----- */
    .flash {
        padding: 0.75rem 1rem;
        border-radius: 0.5rem;
        margin-bottom: 1rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid transparent;
    }
    .flash-success {
        background: var(--flash-success-bg);
        color: var(--flash-success-text);
        border-color: var(--flash-success-text);
    }
    .flash-error {
        background: var(--flash-error-bg);
        color: var(--flash-error-text);
        border-color: var(--flash-error-text);
    }
    .flash i {
        font-size: 1.2rem;
    }
    .header {
        background: var(--bg-header);
        border-bottom: 1px solid var(--border-color);
        padding: 0.75rem 2rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.5rem;
        flex-shrink: 0;
    }
    .header .brand {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .header-actions {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .header-actions a {
        color: var(--text-body);
        text-decoration: none;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }
    .header-actions a:hover {
        color: var(--primary);
    }
    .theme-toggle {
        background: none;
        border: none;
        color: var(--text-body);
        cursor: pointer;
        font-size: 1.2rem;
        padding: 0.2rem 0.4rem;
        border-radius: 0.25rem;
    }
    .theme-toggle:hover {
        background: var(--border-color);
    }
    .toolbar-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
        flex-shrink: 0;
    }
    .toolbar-row .btn-group {
        display: flex;
        gap: 0.3rem;
        align-items: center;
        flex-wrap: wrap;
    }
    .toolbar-row .search-box {
        display: flex;
        gap: 0.3rem;
        align-items: center;
    }
    .toolbar-row .search-box input[type="text"] {
        width: 160px;
        padding: 0.3rem 0.6rem;
        border: 1px solid var(--input-border);
        border-radius: 0.375rem;
        background: var(--input-bg);
        color: var(--text-body);
        font-size: 0.85rem;
    }
    .toolbar-row .search-box input[type="text"]:focus {
        outline: 2px solid var(--primary);
        outline-offset: -1px;
    }

    /* ----- Buttons & Undo ----- */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.3rem;
        padding: 0.35rem 0.8rem;
        border: none;
        border-radius: 0.375rem;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.15s, color 0.15s;
        background: var(--border-color);
        color: var(--text-body);
        white-space: nowrap;
        min-height: 34px;
        min-width: 34px;
    }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-hover); color: #fff; }
    .btn-success { background: var(--success); color: #fff; }
    .btn-success:hover { background: var(--success-hover); color: #fff; }
    .btn-danger { background: var(--danger); color: #fff; }
    .btn-danger:hover { background: var(--danger-hover); color: #fff; }
    .btn-purple { background: var(--purple); color: #fff; }
    .btn-purple:hover { background: var(--purple-hover); color: #fff; }
    .btn-outline { background: transparent; border: 1px solid var(--border-color); color: var(--text-body); }
    .btn-outline:hover { background: var(--border-color); }

    #bulk-delete-btn { display: none; }
    #bulk-delete-btn.visible {
        display: inline-flex;
        background: var(--danger);
        color: #fff;
        font-weight: 600;
    }
    #bulk-delete-btn.visible:hover {
        background: var(--danger-hover);
    }

    .undo-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.35rem 0.8rem;
        min-height: 34px;
        font-size: 0.8rem;
        font-weight: 500;
        border: none;
        border-radius: 0.375rem;
        background: var(--primary);
        color: #fff;
        cursor: pointer;
        transition: background 0.15s, opacity 0.15s;
        text-decoration: none;
        white-space: nowrap;
    }
    .undo-btn:hover {
        background: var(--primary-hover);
    }
    .undo-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }
    .undo-btn i {
        font-size: 0.9rem;
    }

    /* ----- Modal Buttons – compact and side‑by‑side ----- */
    .modal-content .btn {
        padding: 0.15rem 0.5rem;
        font-size: 0.7rem;
        min-height: 24px;
        min-width: 24px;
    }
    .modal-content .btn-success,
    .modal-content .btn-primary,
    .modal-content .btn-purple {
        padding: 0.15rem 0.5rem;
        font-size: 0.7rem;
        min-height: 24px;
        min-width: 24px;
    }
    .modal-content .btn-outline {
        padding: 0.15rem 0.5rem;
        font-size: 0.7rem;
        min-height: 24px;
        min-width: 24px;
    }
    /* New compact modal layout for import/export */
    .modal-options-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        margin: 0.5rem 0 0.8rem 0;
        justify-content: center;
    }
    .modal-options-grid .btn {
        flex: 0 1 auto;
        padding: 0.2rem 0.6rem;
        font-size: 0.75rem;
        min-height: 28px;
        min-width: 28px;
    }
    /* File input styling – modern */
    .file-input-wrapper {
        position: relative;
        display: inline-block;
        width: 100%;
        margin-bottom: 0.5rem;
    }
    .file-input-wrapper input[type="file"] {
        position: absolute;
        left: 0;
        top: 0;
        opacity: 0;
        width: 100%;
        height: 100%;
        cursor: pointer;
    }
    .file-input-wrapper .file-label {
        display: block;
        padding: 0.4rem 0.8rem;
        background: var(--input-bg);
        border: 1px solid var(--input-border);
        border-radius: 0.25rem;
        color: var(--text-body);
        font-size: 0.85rem;
        cursor: pointer;
        transition: border-color 0.15s;
        text-overflow: ellipsis;
        overflow: hidden;
        white-space: nowrap;
    }
    .file-input-wrapper .file-label:hover {
        border-color: var(--primary);
    }

    /* ----- Advanced Table Wrapper & Sticky Constraints ----- */
    .table-wrap {
        flex: 1;
        min-height: 0;
        overflow: auto;
        margin: 0 -2rem;
        border-top: 1px solid var(--border-color);
        border-bottom: 1px solid var(--border-color);
        border-left: none;
        border-right: none;
        border-radius: 0;
        background: var(--bg-main);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
        min-width: 600px;
    }

    th {
        background: #e2e8f0;
        font-weight: 600;
        white-space: nowrap;
        color: #1e293b;
        border-bottom: 2px solid var(--border-color);
        padding: 0.7rem 0.9rem;
    }
    .dark th {
        background: #334155;
        color: #e2e8f0;
    }

    th, td {
        border: 1px solid var(--border-color);
        padding: 0.7rem 0.9rem;
        text-align: left;
        vertical-align: middle;
        white-space: nowrap;
    }

    td {
        max-width: 280px;
        overflow: hidden;
        text-overflow: ellipsis;
        color: var(--text-body);
        font-size: 0.95rem;
    }

    .table-wrap thead {
        position: sticky;
        top: -1px;
        z-index: 10;
        box-shadow: 0 2px 0 var(--border-color);
    }
    .table-wrap thead th,
    .table-wrap thead td {
        background: #e2e8f0;
    }
    .dark .table-wrap thead th,
    .dark .table-wrap thead td {
        background: #334155;
    }

    /* Left Sticky Columns */
    .table-wrap th:nth-child(1),
    .table-wrap td:nth-child(1) {
        position: sticky;
        left: 0;
        min-width: 40px;
        background: var(--bg-main);
        z-index: 5;
    }
    .table-wrap th:nth-child(2),
    .table-wrap td:nth-child(2) {
        position: sticky;
        left: 48px;
        background: var(--bg-main);
        z-index: 5;
        border-right: 2px solid var(--border-color);
    }
    .table-wrap thead th:nth-child(1),
    .table-wrap thead th:nth-child(2) {
        background: #e2e8f0;
        z-index: 15;
    }
    .dark .table-wrap thead th:nth-child(1),
    .dark .table-wrap thead th:nth-child(2) {
        background: #334155;
    }

    .table-wrap td { background: var(--bg-main); }
    .table-wrap tr:hover td { background: var(--border-color); }

    .filter-row input[type="text"] {
        width: 100%;
        min-width: 50px;
        padding: 0.2rem 0.4rem;
        border: 1px solid var(--input-border);
        border-radius: 0.25rem;
        background: var(--input-bg);
        color: var(--text-body);
        font-size: 0.8rem;
        box-sizing: border-box;
    }
    .filter-row input[type="text"]:focus {
        outline: 2px solid var(--primary);
        outline-offset: -1px;
    }
    .filter-row.hidden { display: none; }
    .row-checkbox { width: 18px; height: 18px; cursor: pointer; }
    .select-all { width: 18px; height: 18px; cursor: pointer; }
    .row-actions {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }
    .row-actions a {
        color: var(--primary);
        text-decoration: none;
        font-size: 1.1rem;
        transition: color 0.15s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        min-height: 28px;
    }
    .row-actions a:hover { color: var(--primary-hover); }
    .row-actions a.danger { color: var(--danger); }
    .row-actions a.danger:hover { color: var(--danger-hover); }
    .info-bar { display: none; }

    /* ----- Forms & Queries ----- */
    .edit-form {
        background: var(--bg-sidebar);
        border: 1px solid var(--border-color);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
    .edit-form .form-group {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem 1rem;
        margin-bottom: 0.5rem;
    }
    .edit-form label {
        font-weight: 500;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }
    .edit-form input[type="text"] {
        padding: 0.3rem 0.6rem;
        border: 1px solid var(--input-border);
        border-radius: 0.25rem;
        background: var(--input-bg);
        color: var(--text-body);
        font-size: 0.9rem;
        min-width: 120px;
    }
    .edit-form .actions { margin-top: 0.5rem; display: flex; gap: 0.5rem; }

    .query-area textarea {
        width: 100%;
        height: 120px;
        font-family: monospace;
        padding: 0.5rem;
        border: 1px solid var(--input-border);
        border-radius: 0.375rem;
        background: var(--input-bg);
        color: var(--text-body);
        font-size: 0.9rem;
    }
    .query-area textarea:focus {
        outline: 2px solid var(--primary);
        outline-offset: -1px;
    }
    .query-area .btn { margin-top: 0.5rem; margin-bottom: 1rem; }

    /* ----- Modals ----- */
    .modal {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }
    .modal.active { display: flex; }
    .modal-content {
        background: var(--bg-main);
        border-radius: 0.5rem;
        padding: 1.5rem;
        max-width: 600px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        border: 1px solid var(--border-color);
    }
    .modal-content h3 { margin-bottom: 0.8rem; color: var(--text-body); }
    .modal-content .form-group { margin-bottom: 0.6rem; }
    .modal-content label {
        display: block;
        font-weight: 500;
        margin-bottom: 0.2rem;
        color: var(--text-body);
    }
    .modal-content input[type="text"],
    .modal-content select {
        width: 100%;
        padding: 0.3rem 0.6rem;
        border: 1px solid var(--input-border);
        border-radius: 0.25rem;
        background: var(--input-bg);
        color: var(--text-body);
        font-size: 0.9rem;
    }
    .modal-content .column-row {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        margin-bottom: 0.4rem;
        flex-wrap: wrap;
    }
    .modal-content .column-row input[type="text"] { flex: 2; min-width: 100px; }
    .modal-content .column-row select { flex: 1; min-width: 80px; }
    .modal-content .column-row label {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        font-weight: normal;
        font-size: 0.85rem;
        white-space: nowrap;
    }
    .modal-content .column-row .remove-col {
        background: none;
        border: none;
        color: var(--danger);
        cursor: pointer;
        font-size: 1.1rem;
        padding: 0.2rem;
    }
    .modal-content .column-row .remove-col:hover { color: var(--danger-hover); }
    .modal-content .actions {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
        margin-top: 0.8rem;
    }
    .modal-content .schema-sql {
        background: var(--bg-sidebar);
        padding: 0.8rem;
        border-radius: 0.25rem;
        font-family: monospace;
        font-size: 0.85rem;
        white-space: pre-wrap;
        word-break: break-all;
        border: 1px solid var(--border-color);
        max-height: 300px;
        overflow-y: auto;
    }
    .modal-content .current-columns {
        background: var(--bg-sidebar);
        padding: 0.5rem 0.8rem;
        border-radius: 0.25rem;
        border: 1px solid var(--border-color);
        margin-bottom: 0.8rem;
        overflow-x: auto;
    }
    .modal-content .current-columns table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }
    .modal-content .current-columns th {
        background: var(--table-stripe);
        text-align: left;
        padding: 0.2rem 0.4rem;
        border-bottom: 1px solid var(--border-color);
        font-weight: 600;
    }
    .modal-content .current-columns td {
        padding: 0.2rem 0.4rem;
        border-bottom: 1px solid var(--border-color);
    }

    /* ----- Welcome Screen ----- */
    .welcome {
        text-align: center;
        padding: 3rem 1rem;
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }
    .welcome h1 { font-size: 2.2rem; margin-bottom: 1rem; color: var(--primary); }
    .welcome p { font-size: 1.1rem; color: var(--text-muted); max-width: 500px; line-height: 1.6; }
    .welcome .icon { font-size: 4rem; color: var(--primary); margin-bottom: 1rem; }
    .welcome .license {
        margin-top: 2rem;
        font-size: 0.85rem;
        color: var(--text-muted);
        border-top: 1px solid var(--border-color);
        padding-top: 1.5rem;
        width: 100%;
        max-width: 500px;
    }

   /* ----- Dynamic Footer ----- */
.footer-bar {
    flex-shrink: 0;
    height: 65px;
    background: var(--footer-bg);
    border-top: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    padding: 0 2rem;
    z-index: 100;
    font-size: 0.85rem;
    box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
    gap: 0.5rem;
}

/* Credit section (under sidebar) */
.footer-credit {
    width: var(--sidebar-width);
    flex-shrink: 0;
    font-size: 0.8rem;
    color: var(--text-muted);
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.footer-credit a {
    color: var(--primary);
    text-decoration: none;
}
.footer-credit a:hover {
    text-decoration: underline;
}

/* Right group: row count, pagination, license */
.footer-right-group {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    min-width: 0;
}

.footer-left {
    display: flex;
    align-items: center;
    gap: 1rem;
    font-size: 0.9rem;
    color: var(--text-muted);
    flex-shrink: 0;
}
.footer-center {
    display: flex;
    align-items: center;
    gap: 0.2rem;
    flex-wrap: wrap;
    justify-content: center;
    flex: 0 1 auto;
}
.footer-center .btn {
    min-height: 30px;
    min-width: 30px;
    padding: 0.2rem 0.5rem;
    font-size: 0.75rem;
}
.footer-center .btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.footer-center input[type="number"] {
    width: 60px;
    padding: 0.2rem 0.3rem;
    border: 1px solid var(--input-border);
    border-radius: 0.25rem;
    background: var(--input-bg);
    color: var(--text-body);
    font-size: 0.85rem;
    text-align: center;
}
.footer-center .page-info {
    color: var(--text-muted);
    font-size: 0.8rem;
}
.footer-right {
    font-size: 0.9rem;
    color: var(--text-muted);
    text-align: right;
    line-height: 1.3;
    flex-shrink: 0;
}
.footer-right a {
    color: var(--primary);
    text-decoration: none;
}
.footer-right a:hover {
    text-decoration: underline;
}

    h2 i { margin-right: 0.3rem; color: var(--primary); }
    .header .brand i { color: var(--primary); }

    /* ----- Mobile Responsive ----- */
    @media (max-width: 768px) {
        .sidebar { display: none; }
        .main { padding: 1rem 1rem 0 1rem; }
        .table-wrap { margin: 0 -1rem; border-radius: 0.25rem; }
        .toolbar-row .search-box input[type="text"] { width: 120px; }
        .toolbar-row .btn-group { gap: 0.2rem; }
        th, td { padding: 0.5rem 0.6rem; font-size: 0.85rem; }
        .welcome h1 { font-size: 1.8rem; }
        .modal-content { padding: 1rem; }
        .modal-content .column-row { flex-direction: column; align-items: stretch; }
        .footer-bar { height: auto; padding: 0.3rem 0.5rem; flex-direction: column; align-items: center; gap: 0.2rem; margin-left: 0; }
        .footer-bar .footer-left { font-size: 0.7rem; }
        .footer-bar .footer-right { text-align: center; font-size: 0.65rem; }
        .footer-bar .footer-center input[type="number"] { width: 50px; }
        /* Hide resize handle on mobile */
        .resize-handle { display: none; }
    }


    /* ----- Release 1.1 additions ----- */
    .brand a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: .5rem; }
    .header-actions form { margin: 0; }
    .header-action-button {
        border: 0; background: transparent; color: var(--text-body); cursor: pointer;
        font: inherit; font-size: .9rem; display: inline-flex; align-items: center; gap: .3rem;
    }
    .header-action-button:hover { color: var(--primary); }
    .db-header { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
    .db-delete-button {
        border: 0; background: transparent; color: var(--danger); cursor: pointer;
        padding: .25rem; border-radius: .25rem; flex: 0 0 auto;
    }
    .db-delete-button:hover { background: var(--border-color); color: var(--danger-hover); }
    .object-badge {
        display: inline-flex; align-items: center; padding: .05rem .42rem; border-radius: 999px;
        background: var(--border-color); color: var(--text-muted); font-size: .64rem;
        font-weight: 650; text-transform: uppercase; letter-spacing: .04em;
    }
    .view-notice {
        display: inline-flex; align-items: center; gap: .4rem; margin-left: .5rem;
        padding: .2rem .55rem; border: 1px solid var(--border-color); border-radius: 999px;
        color: var(--text-muted); font-size: .74rem; font-weight: 600;
    }
    .feature-grid {
        display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .85rem;
        width: min(980px, 100%); margin-top: 2rem;
    }
    .feature-card {
        padding: 1rem; border: 1px solid var(--border-color); border-radius: .65rem;
        background: var(--bg-sidebar); text-align: left; box-shadow: var(--shadow);
    }
    .feature-card > i { color: var(--primary); font-size: 1.3rem; margin-bottom: .55rem; }
    .feature-card h4 { margin: 0 0 .3rem; font-size: .95rem; }
    .feature-card p { margin: 0; color: var(--text-muted); font-size: .82rem; line-height: 1.45; }
    .welcome-hero { text-align: center; }
    .welcome-hero .tagline { margin: 0 auto; }
    .data-field {
        display: grid; grid-template-columns: minmax(130px, 1fr) auto; align-items: center;
        gap: .35rem .6rem; min-width: min(100%, 310px);
    }
    .data-field > label { grid-column: 1 / -1; }
    .data-field input[type="text"] { width: 100%; }
    .null-toggle { display: inline-flex !important; align-items: center; gap: .25rem !important; font-size: .76rem !important; color: var(--text-muted); white-space: nowrap; }
    .empty-value { color: var(--text-muted); font-style: italic; }
    .inline-action-form { display: inline-flex; margin: 0; }
    .row-action-button {
        border: 0; background: transparent; color: var(--primary); cursor: pointer;
        font-size: 1.05rem; min-width: 28px; min-height: 28px;
    }
    .row-action-button.danger { color: var(--danger); }
    .row-action-button:hover { color: var(--primary-hover); }
    .row-action-button.danger:hover { color: var(--danger-hover); }
    .page-size-select {
        padding: .2rem .35rem; border: 1px solid var(--input-border); border-radius: .25rem;
        background: var(--input-bg); color: var(--text-body); font-size: .78rem;
    }
    .sql-warning {
        margin-bottom: .75rem; padding: .65rem .8rem; border: 1px solid #f59e0b;
        border-radius: .45rem; background: color-mix(in srgb, #f59e0b 12%, transparent);
        color: var(--text-body); font-size: .84rem;
    }
    @media (max-width: 980px) { .feature-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 560px) { .feature-grid { grid-template-columns: 1fr; } }

</style>
    <script>
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('input[name="selected[]"]');
            checkboxes.forEach(cb => cb.checked = source.checked);
            updateBulkDeleteButton();
        }
        function updateBulkDeleteButton() {
            const checkboxes = document.querySelectorAll('input[name="selected[]"]');
            const checked = document.querySelectorAll('input[name="selected[]"]:checked');
            const btn = document.getElementById('bulk-delete-btn');
            if (checked.length > 0) {
                btn.classList.add('visible');
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete (' + checked.length + ')';
            } else {
                btn.classList.remove('visible');
            }
        }
        function confirmBulkDelete() {
            const checked = document.querySelectorAll('input[name="selected[]"]:checked');
            if (checked.length === 0) return false;
            return confirm('Delete ' + checked.length + ' selected rows?');
        }
        function toggleFilterRow() {
            const row = document.getElementById('filter-row');
            row.classList.toggle('hidden');
            const btn = document.getElementById('toggle-filter-btn');
            if (row.classList.contains('hidden')) {
                btn.innerHTML = '<i class="fas fa-filter"></i> Filters';
            } else {
                btn.innerHTML = '<i class="fas fa-filter"></i> Hide Filters';
            }
        }
        function showModal(id) {
            document.getElementById(id).classList.add('active');
        }
        function hideModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        function showImportSelectModal() { showModal('import-select-modal'); }
        function showExportSelectModal() { showModal('export-select-modal'); }
        function showImportTableModal() { showModal('import-table-modal'); }
        function hideImportTableModal() { hideModal('import-table-modal'); }
        function showImportDbModal() { showModal('import-db-modal'); }
        function hideImportDbModal() { hideModal('import-db-modal'); }
        function showCreateTableModal() { showModal('create-table-modal'); }
        function hideCreateTableModal() { hideModal('create-table-modal'); }
        function showRenameModal(tableName) {
            document.getElementById('rename-old-name').value = tableName;
            document.getElementById('rename-new-name').value = tableName;
            showModal('rename-modal');
        }
        function hideRenameModal() { hideModal('rename-modal'); }
        function showSchemaModal(schema) {
            const el = document.getElementById('schema-sql');
            if (el) {
                el.textContent = schema;
                showModal('schema-modal');
            } else {
                alert('Error: schema element not found.');
            }
        }
        function hideSchemaModal() { hideModal('schema-modal'); }
        function confirmDrop(tableName) {
            document.getElementById('drop-confirm-table').value = tableName;
            showModal('drop-confirm-modal');
        }
        function hideDropConfirm() { hideModal('drop-confirm-modal'); }
        function proceedDrop() {
            const form = document.getElementById('drop-table-form');
            if (form && document.getElementById('drop-confirm-table').value) form.submit();
        }
        function showDeleteDatabaseModal(databaseName) {
            const nameField = document.getElementById('delete-db-name');
            const confirmField = document.getElementById('delete-db-confirm-name');
            const label = document.getElementById('delete-db-label');
            if (nameField) nameField.value = databaseName;
            if (confirmField) confirmField.value = '';
            if (label) label.textContent = databaseName;
            showModal('delete-db-modal');
        }
        function changePageSize(select) {
            const params = new URLSearchParams(window.location.search);
            params.set('limit', select.value);
            params.set('offset', '0');
            window.location.search = params.toString();
        }
        function fetchSchema(tableName) {
            fetch('?action=get_schema&table=' + encodeURIComponent(tableName))
                .then(response => response.text())
                .then(data => {
                    showSchemaModal(data);
                })
                .catch(err => {
                    alert('Error fetching schema: ' + err);
                });
        }
        function showEditSchema(tableName) {
            fetch('?action=get_columns&table=' + encodeURIComponent(tableName))
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('edit-schema-columns');
                    container.innerHTML = '';
                    const tableEl = document.createElement('table');
                    tableEl.innerHTML = '<thead><tr><th>Column</th><th>Type</th><th>PK</th></tr></thead><tbody>';
                    data.forEach(col => {
                        tableEl.innerHTML += `<tr><td>${col.name}</td><td>${col.type}</td><td>${col.pk ? '✓' : ''}</td></tr>`;
                    });
                    tableEl.innerHTML += '</tbody>';
                    container.appendChild(tableEl);
                    document.getElementById('edit-schema-table').value = tableName;
                    const renameTable = document.getElementById('rename-column-table');
                    if (renameTable) renameTable.value = tableName;
                    showModal('edit-schema-modal');
                })
                .catch(err => {
                    alert('Error fetching columns: ' + err);
                });
        }
        function hideEditSchemaModal() { hideModal('edit-schema-modal'); }
        let columnRowCounter = 0;
        function refreshColumnRowIndexes() {
            const container = document.getElementById('column-rows');
            if (!container) return;
            container.querySelectorAll('.column-row').forEach(function(row, index) {
                const primaryKey = row.querySelector('input[name="primary_key_index"]');
                if (primaryKey) primaryKey.value = String(index);
            });
        }
        function addColumnRow() {
            const container = document.getElementById('column-rows');
            const rowIndex = columnRowCounter++;
            const row = document.createElement('div');
            row.className = 'column-row';
            row.innerHTML = `
                <input type="text" name="col_name[]" placeholder="Column name" required>
                <select name="col_type[]">
                    <option value="TEXT">TEXT</option>
                    <option value="INTEGER">INTEGER</option>
                    <option value="REAL">REAL</option>
                    <option value="NUMERIC">NUMERIC</option>
                    <option value="BLOB">BLOB</option>
                </select>
                <label><input type="radio" name="primary_key_index" value="${rowIndex}"> PK</label>
                <button type="button" class="remove-col" onclick="removeColumnRow(this)" title="Remove column"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(row);
            refreshColumnRowIndexes();
        }
        function removeColumnRow(btn) {
            const container = document.getElementById('column-rows');
            const row = btn.closest('.column-row');
            if (container && container.querySelectorAll('.column-row').length > 1) {
                row.remove();
                refreshColumnRowIndexes();
            } else {
                alert('You need at least one column.');
            }
        }
        function switchDatabase(select) {
            const db = select.value;
            if (db) {
                window.location.href = '?db=' + encodeURIComponent(db);
            }
        }
        function goToPage(page, totalPages) {
            if (page < 1) page = 1;
            if (page > totalPages) page = totalPages;
            const input = document.getElementById('page-input');
            if (input) input.value = page;
            const params = new URLSearchParams(window.location.search);
            params.set('offset', (page - 1) * <?php echo $limit; ?>);
            window.location.search = params.toString();
        }

        // ----- Resizable Sidebar -----
        document.addEventListener('DOMContentLoaded', function() {
            // Flash auto-hide
            const flash = document.getElementById('flash-message');
            if (flash) {
                setTimeout(function() {
                    flash.style.transition = 'opacity 0.5s ease';
                    flash.style.opacity = '0';
                    setTimeout(function() { flash.style.display = 'none'; }, 500);
                }, 5000);
            }

            // Create table: initial column row
            const container = document.getElementById('column-rows');
            if (container && container.children.length === 0) {
                addColumnRow();
            }

            // Page input: Enter key
            const pageInput = document.getElementById('page-input');
            if (pageInput) {
                pageInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        const val = parseInt(this.value);
                        const total = parseInt(this.getAttribute('data-total'));
                        if (!isNaN(val) && !isNaN(total)) {
                            goToPage(val, total);
                        }
                    }
                });
            }

            // ----- Resizable Sidebar -----
            (function() {
                const handle = document.getElementById('resize-handle');
                if (!handle) return;
                const sidebar = document.querySelector('.sidebar');
                const root = document.documentElement;
                const MIN_WIDTH = 200;
                const MAX_WIDTH = 500;
                let isDragging = false;

                // Load saved width
                const savedWidth = localStorage.getItem('sqlite_admin_sidebar_width');
                if (savedWidth) {
                    const w = parseInt(savedWidth, 10);
                    if (!isNaN(w) && w >= MIN_WIDTH && w <= MAX_WIDTH) {
                        root.style.setProperty('--sidebar-width', w + 'px');
                    }
                }

                function startDrag(e) {
                    isDragging = true;
                    document.addEventListener('mousemove', onDrag);
                    document.addEventListener('mouseup', stopDrag);
                    handle.style.background = 'var(--primary)';
                    document.body.style.userSelect = 'none';
                    e.preventDefault();
                }

                function onDrag(e) {
                    if (!isDragging) return;
                    const rect = sidebar.getBoundingClientRect();
                    let newWidth = e.clientX - rect.left;
                    if (newWidth < MIN_WIDTH) newWidth = MIN_WIDTH;
                    if (newWidth > MAX_WIDTH) newWidth = MAX_WIDTH;
                    root.style.setProperty('--sidebar-width', newWidth + 'px');
                }

                function stopDrag() {
                    if (isDragging) {
                        isDragging = false;
                        document.removeEventListener('mousemove', onDrag);
                        document.removeEventListener('mouseup', stopDrag);
                        handle.style.background = 'transparent';
                        document.body.style.userSelect = '';
                        // Save width
                        const width = parseInt(getComputedStyle(root).getPropertyValue('--sidebar-width'), 10);
                        if (!isNaN(width)) {
                            localStorage.setItem('sqlite_admin_sidebar_width', width);
                        }
                    }
                }

                handle.addEventListener('mousedown', startDrag);
            })();
        });
    </script>
</head>
<body>
    <header class="header">
        <div class="brand">
            <a href="<?php echo h(currentAppPath()); ?>"><i class="fas fa-database"></i> SQLite Admin</a>
        </div>
        <div class="header-actions">
            <a href="?theme=<?php echo $isDark ? 'light' : 'dark'; ?>" class="theme-toggle" title="Toggle theme">
                <i class="fas <?php echo $isDark ? 'fa-sun' : 'fa-moon'; ?>"></i>
            </a>
            <form method="post" action="?action=logout">
                <?php echo csrfField(); ?>
                <button type="submit" class="header-action-button" title="Logout"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </div>
    </header>

    <div class="app-container">
        <nav class="sidebar">
            <div class="db-header">
                <div class="db-name">
                    <i class="fas fa-database"></i>
                    <span><?php echo h(basename($dbFile)); ?></span>
                    <span class="size">(<?php echo h($dbSizeFormatted); ?>)</span>
                </div>
                <?php if (realpath($dbFile) !== realpath($configuredDbFile)): ?>
                    <button type="button" class="db-delete-button" onclick="showDeleteDatabaseModal('<?php echo h(basename($dbFile)); ?>')" title="Delete this imported database">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Database Switcher -->
            <div class="db-switcher-sidebar">
                <label for="sidebar-db-select" class="db-switcher-label">
                    <i class="fas fa-exchange-alt"></i> Switch database
                </label>
                <select id="sidebar-db-select" onchange="switchDatabase(this);">
                    <?php foreach ($databases as $dbName): ?>
                        <option value="<?php echo htmlspecialchars($dbName); ?>" <?php echo basename($dbFile) === $dbName ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dbName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tables-heading">
                <h3><i class="fas fa-table"></i> Tables &amp; Views (<?php echo $tableCount + $viewCount; ?>)</h3>
                <button class="btn-plus" onclick="showCreateTableModal();" title="Create new table"><i class="fas fa-plus-circle"></i></button>
            </div>

            <div class="table-list">
                <?php
                $objects = $db->query(
                    "SELECT name, type FROM sqlite_master "
                    . "WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' "
                    . "ORDER BY type, name"
                );
                $hasObjects = false;
                while ($row = $objects->fetchArray(SQLITE3_ASSOC)) {
                    $hasObjects = true;
                    $name = (string) $row['name'];
                    $type = (string) $row['type'];
                    $active = ($name === $table) ? 'active' : '';
                    try {
                        $rowCount = (int) $db->querySingle('SELECT COUNT(*) FROM ' . quoteIdentifier($name));
                    } catch (Throwable $ignoredException) {
                        $rowCount = 0;
                    }
                    echo '<div class="table-item">';
                    echo '<a href="?table=' . urlencode($name) . '" class="' . $active . '">';
                    echo '<span class="icon"><i class="fas ' . ($type === 'view' ? 'fa-eye' : 'fa-table') . '"></i></span>';
                    echo '<span class="name">' . h($name)
                        . ($type === 'view' ? ' <span class="object-badge">view</span>' : '')
                        . ' <span class="row-count">' . number_format($rowCount) . '</span></span>';
                    echo '</a>';
                    echo '<div class="table-actions">';
                    echo '<button onclick="fetchSchema(' . h(json_encode($name)) . ')" title="Show schema"><i class="fas fa-info-circle"></i></button>';
                    if ($type === 'table') {
                        echo '<button onclick="showEditSchema(' . h(json_encode($name)) . ')" title="Edit schema"><i class="fas fa-cog"></i></button>';
                        echo '<button onclick="showRenameModal(' . h(json_encode($name)) . ')" title="Rename table"><i class="fas fa-pencil-alt"></i></button>';
                        echo '<button class="danger" onclick="confirmDrop(' . h(json_encode($name)) . ')" title="Drop table"><i class="fas fa-trash-alt"></i></button>';
                    }
                    echo '</div></div>';
                }
                if (!$hasObjects) {
                    echo '<div style="padding:0.5rem 1.2rem;color:var(--text-muted);font-size:0.85rem;font-style:italic;">No tables or views yet</div>';
                }
                ?>
            </div>

            <div class="sidebar-actions">
                <button onclick="showImportSelectModal();" class="btn btn-primary"><i class="fas fa-upload"></i> Import</button>
                <button onclick="showExportSelectModal();" class="btn btn-primary"><i class="fas fa-download"></i> Export</button>
            </div>

            <div class="query-link">
                <a href="?action=query" class="<?php echo $action === 'query' ? 'active' : ''; ?>"><i class="fas fa-terminal"></i> SQL Query</a>
            </div>
        </nav>

        <!-- Resize Handle -->
        <div id="resize-handle" class="resize-handle" title="Drag to resize sidebar"></div>

        <main class="main">
            <?php
            if (isset($_SESSION['flash'])) {
                $flash = $_SESSION['flash'];
                $type = $flash['type'] === 'success' ? 'success' : 'error';
                $icon = $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
                echo '<div id="flash-message" class="flash flash-' . $type . '"><i class="fas ' . $icon . '"></i> ' . htmlspecialchars($flash['message']) . '</div>';
                unset($_SESSION['flash']);
            }
            ?>

            <!-- ====== MODALS ====== -->

            <!-- Drop Confirm Modal -->
            <div id="drop-confirm-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-exclamation-triangle" style="color:var(--danger);"></i> Confirm Drop Table</h3>
                    <p style="color:var(--text-muted);margin-bottom:1rem;">Are you sure you want to drop this table? This action <strong>cannot be undone</strong>.</p>
                    <form id="drop-table-form" method="post" action="?action=drop_table">
                        <?php echo csrfField(); ?>
                        <input type="hidden" id="drop-confirm-table" name="table" value="">
                        <div class="actions">
                            <button type="button" class="btn btn-outline" onclick="hideDropConfirm();">Cancel</button>
                            <button type="button" class="btn btn-danger" onclick="proceedDrop();"><i class="fas fa-trash-alt"></i> Drop Table</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete Database Modal -->
            <div id="delete-db-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-database" style="color:var(--danger);"></i> Delete Imported Database</h3>
                    <p style="color:var(--text-muted);margin-bottom:.75rem;">This permanently deletes <strong id="delete-db-label"></strong> from the server. The configured primary database cannot be deleted here.</p>
                    <form method="post" action="?action=delete_db">
                        <?php echo csrfField(); ?>
                        <input type="hidden" id="delete-db-name" name="db" value="">
                        <div class="form-group">
                            <label for="delete-db-confirm-name">Type the filename to confirm</label>
                            <input type="text" id="delete-db-confirm-name" name="confirm_name" required autocomplete="off">
                        </div>
                        <div class="actions">
                            <button type="button" class="btn btn-outline" onclick="hideModal('delete-db-modal');">Cancel</button>
                            <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Delete Database</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Import Selection Modal -->
            <div id="import-select-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-upload"></i> Import</h3>
                    <p style="margin-bottom:0.5rem;color:var(--text-muted);">Choose what to import:</p>
                    <div class="modal-options-grid">
                        <button class="btn btn-success" onclick="hideModal('import-select-modal'); showImportTableModal();" title="Import table from CSV/JSON">
                            <i class="fas fa-table"></i> Table
                        </button>
                        <button class="btn btn-success" onclick="hideModal('import-select-modal'); showImportDbModal();" title="Import SQLite database">
                            <i class="fas fa-database"></i> Database
                        </button>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn btn-outline" onclick="hideModal('import-select-modal');">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- Export Selection Modal -->
            <div id="export-select-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-download"></i> Export Current Table</h3>
                    <p style="margin-bottom:0.5rem;color:var(--text-muted);">Choose what to export:</p>
                    <div class="modal-options-grid">
                        <?php if ($table): ?>
                        <a href="?table=<?php echo urlencode($table); ?>&action=export_table&format=csv<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>" class="btn btn-primary" title="Export table as CSV">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>
                        <a href="?table=<?php echo urlencode($table); ?>&action=export_table&format=json<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>" class="btn btn-primary" title="Export table as JSON">
                            <i class="fas fa-file-json"></i> JSON
                        </a>
                        <a href="?table=<?php echo urlencode($table); ?>&action=export_sql<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>" class="btn btn-purple" title="Export table as SQL">
                            <i class="fas fa-file-code"></i> SQL
                        </a>
                        <?php else: ?>
                        <span class="btn btn-primary" disabled>No table selected</span>
                        <?php endif; ?>
                        <a href="?action=export_db" class="btn btn-purple" title="Export entire database">
                            <i class="fas fa-database"></i> Database
                        </a>
                    </div>
                    <div class="actions">
                        <button type="button" class="btn btn-outline" onclick="hideModal('export-select-modal');">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- Import Table Modal -->
            <div id="import-table-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-upload"></i> Import Table</h3>
                    <p style="color:var(--text-muted);font-size:0.9rem;">Upload a CSV or JSON file to insert into the current table.</p>
                    <?php if ($table && !$isView): ?>
                    <form method="post" enctype="multipart/form-data" action="?table=<?php echo urlencode($table); ?>&action=import_table<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>">
                        <?php echo csrfField(); ?>
                        <div class="file-input-wrapper">
                            <span class="file-label" id="file-label">Choose a file...</span>
                            <input type="file" name="import_file" accept=".csv,.json" required onchange="document.getElementById('file-label').textContent = this.files[0] ? this.files[0].name : 'Choose a file...';">
                        </div>
                        <div class="actions">
                            <button type="button" class="btn btn-outline" onclick="hideModal('import-table-modal');">Cancel</button>
                            <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Upload</button>
                        </div>
                    </form>
                    <?php else: ?>
                    <p style="color:var(--danger);"><?php echo $isView ? 'Views are read-only. Import into an underlying table.' : 'Please select a table first.'; ?></p>
                    <div class="actions">
                        <button type="button" class="btn btn-outline" onclick="hideModal('import-table-modal');">Close</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Import Database Modal -->
            <div id="import-db-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-upload"></i> Import Database</h3>
                    <p style="color:var(--text-muted);font-size:0.9rem;">Upload a SQLite database file (.sqlite, .db, .sqlite3). It will be saved alongside the current database.</p>
                    <form method="post" enctype="multipart/form-data" action="?action=import_db">
                        <?php echo csrfField(); ?>
                        <div class="file-input-wrapper">
                            <span class="file-label" id="db-file-label">Choose a file...</span>
                            <input type="file" name="db_file" accept=".sqlite,.db,.sqlite3" required onchange="document.getElementById('db-file-label').textContent = this.files[0] ? this.files[0].name : 'Choose a file...';">
                        </div>
                        <div class="actions">
                            <button type="button" class="btn btn-outline" onclick="hideModal('import-db-modal');">Cancel</button>
                            <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Upload</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Create Table Modal -->
            <div id="create-table-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-plus-circle"></i> Create New Table</h3>
                    <form method="post" action="?action=create_table" id="create-table-form">
                        <?php echo csrfField(); ?>
                        <div class="form-group">
                            <label for="table_name">Table Name</label>
                            <input type="text" id="table_name" name="table_name" placeholder="e.g. products" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" title="Letters, numbers, underscores, starting with letter or underscore">
                        </div>
                        <div class="form-group">
                            <label>Columns</label>
                            <div id="column-rows"></div>
                            <button type="button" class="btn btn-outline btn-sm" onclick="addColumnRow();" style="margin-top:0.3rem;"><i class="fas fa-plus"></i> Add Column</button>
                        </div>
                        <div class="actions">
                            <button type="button" class="btn btn-outline" onclick="hideModal('create-table-modal');">Cancel</button>
                            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Create Table</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Rename Modal -->
            <div id="rename-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-edit"></i> Rename Table</h3>
                    <form method="post" action="?action=rename_table">
                        <?php echo csrfField(); ?>
                        <input type="hidden" id="rename-old-name" name="old_name">
                        <div class="form-group">
                            <label for="rename-new-name">New Table Name</label>
                            <input type="text" id="rename-new-name" name="new_name" placeholder="New table name" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" title="Letters, numbers, underscores, starting with letter or underscore">
                        </div>
                        <div class="actions">
                            <button type="button" class="btn btn-outline" onclick="hideModal('rename-modal');">Cancel</button>
                            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Rename</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Schema Modal -->
            <div id="schema-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-info-circle"></i> Table Schema</h3>
                    <div class="schema-sql" id="schema-sql"></div>
                    <div class="actions">
                        <button type="button" class="btn btn-outline" onclick="hideModal('schema-modal');">Close</button>
                    </div>
                </div>
            </div>

            <!-- Edit Schema Modal -->
            <div id="edit-schema-modal" class="modal">
                <div class="modal-content">
                    <h3><i class="fas fa-cog"></i> Edit Schema</h3>
                    <div id="edit-schema-columns" class="current-columns"></div>
                    <form method="post" action="?action=add_column">
                        <?php echo csrfField(); ?>
                        <input type="hidden" id="edit-schema-table" name="table">
                        <div class="form-group">
                            <label>Add New Column</label>
                            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                                <input type="text" name="col_name" placeholder="Column name" required pattern="[a-zA-Z_][a-zA-Z0-9_]*">
                                <select name="col_type">
                                    <option value="TEXT">TEXT</option>
                                    <option value="INTEGER">INTEGER</option>
                                    <option value="REAL">REAL</option>
                                    <option value="NUMERIC">NUMERIC</option>
                                    <option value="BLOB">BLOB</option>
                                </select>
                                <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add</button>
                            </div>
                        </div>
                    </form>
                    <div style="margin-top:0.8rem;border-top:1px solid var(--border-color);padding-top:0.8rem;">
                        <form method="post" action="?action=rename_column" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" id="rename-column-table" name="table" value="<?php echo h($table); ?>">
                            <input type="text" name="old_col" placeholder="Current column name" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" style="flex:1;min-width:100px;padding:0.3rem 0.6rem;border:1px solid var(--input-border);border-radius:0.25rem;background:var(--input-bg);color:var(--text-body);font-size:0.9rem;">
                            <span>→</span>
                            <input type="text" name="new_col" placeholder="New column name" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" style="flex:1;min-width:100px;padding:0.3rem 0.6rem;border:1px solid var(--input-border);border-radius:0.25rem;background:var(--input-bg);color:var(--text-body);font-size:0.9rem;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-edit"></i> Rename Column</button>
                        </form>
                    </div>
                    <div class="actions" style="margin-top:1rem;">
                        <button type="button" class="btn btn-outline" onclick="hideModal('edit-schema-modal');">Close</button>
                    </div>
                </div>
            </div>

            <?php if ($action === 'query'): ?>
                <h2 style="margin-bottom:0.75rem;"><i class="fas fa-terminal"></i> SQL Query</h2>
                <div class="query-area">
                    <div class="sql-warning"><strong>Advanced access:</strong> SQL entered here runs with full write permissions. Export a backup first before running destructive statements.</div>
                    <form method="post">
                        <?php echo csrfField(); ?>
                        <textarea name="sql" placeholder="Write your SQL query here…"><?php echo h($_POST['sql'] ?? ''); ?></textarea>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Execute</button>
                    </form>
                    <?php
                    if (isset($_POST['sql']) && $_POST['sql'] !== '') {
                        requireCsrf();
                        try {
                            $result = $db->query($_POST['sql']);
                            if ($result === false) {
                                echo '<p style="color:var(--danger);margin-top:0.5rem;">Error: ' . h($db->lastErrorMsg()) . '</p>';
                            } else {
                                $cols = $result->numColumns();
                                if ($cols == 0) {
                                    echo '<p style="color:var(--success);margin-top:0.5rem;">✓ Query executed successfully (no rows returned).</p>';
                                } else {
                                    echo '<div class="table-wrap"><table><tr>';
                                    for ($i = 0; $i < $cols; $i++) echo '<th>' . h($result->columnName($i)) . '</th>';
                                    echo '</tr>';
                                    while ($row = $result->fetchArray(SQLITE3_NUM)) {
                                        echo '<tr>';
                                        foreach ($row as $val) {
                                            echo '<td>' . ($val === null ? '<i>NULL</i>' : h($val)) . '</td>';
                                        }
                                        echo '</tr>';
                                    }
                                    echo '</table></div>';
                                }
                            }
                        } catch (Throwable $e) {
                            echo '<p style="color:var(--danger);margin-top:0.5rem;">Error: ' . h($e->getMessage()) . '</p>';
                        }
                    }
                    ?>
                </div>

            <?php elseif ($table === ''): ?>
                <div class="welcome">
                    <div class="welcome-hero">
                        <div class="icon"><i class="fas fa-database"></i></div>
                        <h1>SQLite Admin</h1>
                        <p class="tagline">A lightweight, self-hosted interface for browsing and maintaining SQLite databases.</p>
                    </div>

                    <div class="feature-grid">
                        <div class="feature-card"><i class="fas fa-table"></i><h4>Browse &amp; Edit</h4><p>View, insert, update, and delete rows while preserving real NULL values.</p></div>
                        <div class="feature-card"><i class="fas fa-eye"></i><h4>SQLite Views</h4><p>Browse and export views safely in read-only mode.</p></div>
                        <div class="feature-card"><i class="fas fa-upload"></i><h4>Import / Export</h4><p>CSV, JSON, SQL, and consistent full-database snapshots.</p></div>
                        <div class="feature-card"><i class="fas fa-undo-alt"></i><h4>Undo</h4><p>Restore the last five row or schema actions.</p></div>
                        <div class="feature-card"><i class="fas fa-columns"></i><h4>Schema Management</h4><p>Create and rename tables, then add or rename columns.</p></div>
                        <div class="feature-card"><i class="fas fa-search"></i><h4>Filter &amp; Search</h4><p>Search across all columns or narrow results column by column.</p></div>
                        <div class="feature-card"><i class="fas fa-database"></i><h4>Multiple Databases</h4><p>Import and switch between databases stored in the configured directory.</p></div>
                        <div class="feature-card"><i class="fas fa-arrows-alt-h"></i><h4>Resizable Sidebar</h4><p>Drag the sidebar width and keep the preference in your browser.</p></div>
                    </div>

                    <div class="license">
                        <p><i class="fas fa-code"></i> SQLite Admin <?php echo h(SQLITE_ADMIN_VERSION); ?> – MIT License</p>
                        <p style="margin-top:.3rem;font-size:.8rem;">Developed by <a href="https://abilenetechguy.com" target="_blank" rel="noopener">Abilene Tech Guy</a> &nbsp;•&nbsp; <a href="<?php echo h(SQLITE_ADMIN_PROJECT_URL); ?>" target="_blank" rel="noopener"><i class="fab fa-github"></i> Report bugs on GitHub</a></p>
                    </div>
                </div>

            <?php else:
                $columnInfo = tableInfo($db, $table);
                $cols = array_map(static function (array $column) { return (string) $column['name']; }, $columnInfo);
                $columnTypes = [];
                foreach ($columnInfo as $column) {
                    $columnTypes[(string) $column['name']] = (string) $column['type'];
                }
                $locator = $isView ? ['column' => null, 'rowid' => false] : tableLocator($db, $table);
                $pkCol = $locator['column'];
                $useRowid = !empty($locator['rowid']);
                $canEditRows = !$isView && !empty($pkCol);

                if ($action === 'insert' && !$isView) {
                    echo '<h2 style="margin-bottom:.75rem;"><i class="fas fa-plus-circle"></i> Insert new row into ' . h($table) . '</h2>';
                    echo '<div class="edit-form"><form method="post" action="' . h(getQueryString($table, $search, $colFilters, ['action' => 'insert'])) . '">';
                    echo csrfField();
                    echo '<input type="hidden" name="new" value="1"><div class="form-group">';
                    foreach ($columnInfo as $column) {
                        $name = (string) $column['name'];
                        if (str_contains(strtoupper((string) $column['type']), 'BLOB')) {
                            echo '<div class="data-field"><label>' . h($name) . ' <small>(BLOB)</small></label><span class="empty-value">Use SQL or import to set binary data.</span></div>';
                            continue;
                        }
                        $hint = ((int) $column['pk'] > 0 && str_contains(strtoupper((string) $column['type']), 'INT'))
                            ? ' placeholder="Leave blank for automatic value"'
                            : '';
                        echo '<div class="data-field"><label for="insert-' . h($name) . '">' . h($name) . ' <small>(' . h($column['type'] ?: 'ANY') . ')</small></label>';
                        echo '<input id="insert-' . h($name) . '" type="text" name="' . h($name) . '"' . $hint . '>';
                        echo '<label class="null-toggle"><input type="checkbox" name="null_fields[]" value="' . h($name) . '"> NULL</label></div>';
                    }
                    echo '</div><div class="actions"><button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Insert</button> ';
                    echo '<a href="' . h(getQueryString($table, $search, $colFilters)) . '" class="btn btn-outline">Cancel</a></div></form></div>';
                    $showTable = false;
                } elseif ($action === 'edit' && !$isView && isset($_GET['pk'])) {
                    $pkValue = (string) $_GET['pk'];
                    $editRow = fetchRowByLocator($db, $table, $locator, $pkValue);
                    if ($editRow) {
                        echo '<h2 style="margin-bottom:.75rem;"><i class="fas fa-edit"></i> Edit row in ' . h($table) . '</h2>';
                        echo '<div class="edit-form"><form method="post" action="' . h(getQueryString($table, $search, $colFilters, ['action' => 'update'])) . '">';
                        echo csrfField();
                        echo '<input type="hidden" name="pk" value="' . h($pkValue) . '"><div class="form-group">';
                        foreach ($columnInfo as $column) {
                            $name = (string) $column['name'];
                            $value = $editRow[$name] ?? null;
                            if (str_contains(strtoupper((string) $column['type']), 'BLOB')) {
                                echo '<div class="data-field"><label>' . h($name) . ' <small>(BLOB)</small></label><span class="empty-value">Binary field preserved; use SQL or import to replace it.</span></div>';
                                continue;
                            }
                            $isLocator = !$useRowid && $name === $pkCol;
                            echo '<div class="data-field"><label for="edit-' . h($name) . '">' . h($name) . ' <small>(' . h($column['type'] ?: 'ANY') . ')</small></label>';
                            echo '<input id="edit-' . h($name) . '" type="text" name="' . h($name) . '" value="' . h($value ?? '') . '"' . ($isLocator ? ' readonly' : '') . '>';
                            if (!$isLocator) {
                                echo '<label class="null-toggle"><input type="checkbox" name="null_fields[]" value="' . h($name) . '"' . ($value === null ? ' checked' : '') . '> NULL</label>';
                            } else {
                                echo '<span class="null-toggle">Primary key</span>';
                            }
                            echo '</div>';
                        }
                        echo '</div><div class="actions"><button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update</button> ';
                        echo '<a href="' . h(getQueryString($table, $search, $colFilters)) . '" class="btn btn-outline">Cancel</a></div></form></div>';
                        $showTable = false;
                    } else {
                        setFlash('error', 'Row not found.');
                        $showTable = true;
                    }
                } else {
                    $showTable = true;
                }

                if ($showTable):
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem;">
                    <h2 style="margin:0;font-size:1.4rem;">
                        <i class="fas <?php echo $isView ? 'fa-eye' : 'fa-table'; ?>"></i>
                        <?php echo h($table); ?>
                        <?php if ($isView): ?><span class="view-notice"><i class="fas fa-lock"></i> Read-only view</span><?php endif; ?>
                    </h2>
                </div>

                <?php if (!$isView && !$canEditRows): ?>
                    <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> This table has no single primary key or usable rowid, so row editing is disabled. Browsing and export still work.</div>
                <?php endif; ?>

                <div class="toolbar-row">
                    <div class="btn-group">
                        <?php if ($canEditRows): ?>
                            <a href="<?php echo h(getQueryString($table, $search, $colFilters, ['action' => 'insert'])); ?>" class="btn btn-success"><i class="fas fa-plus"></i> Insert Row</a>
                        <?php endif; ?>
                        <button id="toggle-filter-btn" class="btn btn-purple" onclick="toggleFilterRow();"><i class="fas fa-filter"></i> Filters</button>
                        <?php if ($canEditRows): ?>
                            <form id="bulk-delete-form" method="post" action="<?php echo h(getQueryString($table, $search, $colFilters, ['action' => 'bulk_delete'])); ?>" onsubmit="return confirmBulkDelete();" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <button type="submit" id="bulk-delete-btn" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Delete</button>
                            </form>
                            <?php $undoCount = count($_SESSION['undo_history'] ?? []); ?>
                            <form method="post" action="<?php echo h(getQueryString($table, $search, $colFilters, ['action' => 'undo'])); ?>" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <button type="submit" class="undo-btn" title="Undo last action" <?php echo $undoCount > 0 ? '' : 'disabled'; ?>>
                                    <i class="fas fa-undo-alt"></i> Undo <?php echo $undoCount > 0 ? '(' . $undoCount . ')' : ''; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <form method="get" class="search-box" id="search-form">
                        <input type="hidden" name="table" value="<?php echo h($table); ?>">
                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                        <input type="text" name="search" placeholder="Search all…" value="<?php echo h($search); ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                        <?php if ($search !== '' || $colFilters !== []): ?>
                            <a href="<?php echo h(getQueryString($table, '', [], ['limit' => $limit])); ?>" class="btn btn-outline"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php
                $whereParameters = [];
                $where = buildWhere($cols, $search, $colFilters, $whereParameters);
                $countResult = queryWithTextParameters(
                    $db,
                    'SELECT COUNT(*) AS total FROM ' . quoteIdentifier($table) . $where,
                    $whereParameters
                );
                $countRow = $countResult->fetchArray(SQLITE3_ASSOC);
                $totalRows = (int) ($countRow['total'] ?? 0);
                $pages = max(1, (int) ceil($totalRows / $limit));
                if ($offset >= $totalRows && $totalRows > 0) $offset = ($pages - 1) * $limit;
                $currentPage = min($pages, (int) floor($offset / $limit) + 1);
                $selectList = $useRowid ? '*, rowid AS "__sqlite_admin_rowid"' : '*';
                $dataSql = 'SELECT ' . $selectList . ' FROM ' . quoteIdentifier($table) . $where
                    . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
                $result = queryWithTextParameters($db, $dataSql, $whereParameters);
                $rows = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
                ?>

                <?php if ($rows === []): ?>
                    <p style="color:var(--text-muted);">No rows found<?php echo $search !== '' ? ' matching “' . h($search) . '”' : ''; ?>.</p>
                <?php else: ?>
                    <form method="get" id="col-filter-form" action="">
                        <input type="hidden" name="table" value="<?php echo h($table); ?>">
                        <input type="hidden" name="search" value="<?php echo h($search); ?>">
                        <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <?php if ($canEditRows): ?>
                                        <th style="width:40px;"><input type="checkbox" class="select-all" onclick="toggleAll(this);"></th>
                                        <th style="width:80px;">Actions</th>
                                    <?php endif; ?>
                                    <?php foreach ($cols as $column): ?><th><?php echo h($column); ?></th><?php endforeach; ?>
                                </tr>
                                <tr id="filter-row" class="filter-row <?php echo $colFilters === [] ? 'hidden' : ''; ?>">
                                    <?php if ($canEditRows): ?><td></td><td></td><?php endif; ?>
                                    <?php foreach ($cols as $column): ?>
                                        <td><input form="col-filter-form" type="text" name="col_filters[<?php echo h($column); ?>]" value="<?php echo h($colFilters[$column] ?? ''); ?>" placeholder="Filter…" onchange="document.getElementById('col-filter-form').submit();"></td>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row):
                                    $rowIdentity = $useRowid
                                        ? ($row['__sqlite_admin_rowid'] ?? '')
                                        : ($pkCol !== null ? ($row[$pkCol] ?? '') : '');
                                ?>
                                    <tr>
                                        <?php if ($canEditRows): ?>
                                            <td><input form="bulk-delete-form" type="checkbox" name="selected[]" value="<?php echo h($rowIdentity); ?>" class="row-checkbox" onchange="updateBulkDeleteButton();"></td>
                                            <td>
                                                <div class="row-actions">
                                                    <a href="<?php echo h(getQueryString($table, $search, $colFilters, ['action' => 'edit', 'pk' => $rowIdentity])); ?>" title="Edit"><i class="fas fa-edit"></i></a>
                                                    <form method="post" action="<?php echo h(getQueryString($table, $search, $colFilters, ['action' => 'delete'])); ?>" class="inline-action-form" onsubmit="return confirm('Delete this row?');">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="pk" value="<?php echo h($rowIdentity); ?>">
                                                        <button type="submit" class="row-action-button danger" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        <?php foreach ($cols as $column):
                                            $value = array_key_exists($column, $row) ? $row[$column] : null;
                                            $declaredType = strtoupper($columnTypes[$column] ?? '');
                                        ?>
                                            <td><?php
                                                if ($value === null) {
                                                    echo '<i>NULL</i>';
                                                } elseif ($value === '') {
                                                    echo '<span class="empty-value">(empty)</span>';
                                                } elseif (str_contains($declaredType, 'BLOB')) {
                                                    echo '<span title="Binary data">[BLOB ' . number_format(strlen((string) $value)) . ' bytes]</span>';
                                                } else {
                                                    echo h($value);
                                                }
                                            ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <!-- Fixed Footer Bar -->
<?php if ($table !== '' && isset($totalRows) && $totalRows > 0): ?>
<div class="footer-bar">
    <!-- Footer 1: Credit under sidebar -->
    <div class="footer-credit">
        <span>Developed by <a href="https://abilenetechguy.com" target="_blank" rel="noopener">Abilene Tech Guy</a></span>
    </div>

    <!-- Footer 2-4: Row count, pagination, license -->
    <div class="footer-right-group">
        <div class="footer-left">
            <span>Showing <?php echo number_format(min($limit, max(0, $totalRows - $offset))); ?> of <?php echo number_format($totalRows); ?> rows</span>
            <label>Rows
                <select class="page-size-select" onchange="changePageSize(this)">
                    <?php foreach ($allowedLimits as $option): ?>
                        <option value="<?php echo $option; ?>" <?php echo $limit === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="footer-center">
            <button class="btn btn-outline" onclick="goToPage(1, <?php echo $pages; ?>)" <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                <i class="fas fa-angle-double-left"></i> First
            </button>
            <button class="btn btn-outline" onclick="goToPage(<?php echo max(1, $currentPage - 1); ?>, <?php echo $pages; ?>)" <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>>
                <i class="fas fa-chevron-left"></i> Prev
            </button>
            <input type="number" id="page-input" data-total="<?php echo $pages; ?>" value="<?php echo $currentPage; ?>" min="1" max="<?php echo $pages; ?>" title="Go to page">
            <span class="page-info">of <?php echo $pages; ?></span>
            <button class="btn btn-outline" onclick="goToPage(<?php echo min($pages, $currentPage + 1); ?>, <?php echo $pages; ?>)" <?php echo $currentPage >= $pages ? 'disabled' : ''; ?>>
                Next <i class="fas fa-chevron-right"></i>
            </button>
            <button class="btn btn-outline" onclick="goToPage(<?php echo $pages; ?>, <?php echo $pages; ?>)" <?php echo $currentPage >= $pages ? 'disabled' : ''; ?>>
                Last <i class="fas fa-angle-double-right"></i>
            </button>
        </div>
        <div class="footer-right">
            SQLite Admin <?php echo h(SQLITE_ADMIN_VERSION); ?> – <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener">MIT License</a> ·
            <a href="<?php echo h(SQLITE_ADMIN_PROJECT_URL); ?>" target="_blank" rel="noopener"><i class="fab fa-github"></i> Report Bugs</a> · PHP &amp; SQLite3
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
<?php
$db->close();
?>