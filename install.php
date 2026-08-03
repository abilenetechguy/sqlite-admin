<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const SQLITE_ADMIN_MIN_PHP = '7.0.0';
const SQLITE_ADMIN_MIN_PASSWORD_LENGTH = 12;
const SQLITE_ADMIN_VERSION = '1.1.5';

$configFile = __DIR__ . '/config.php';

if (is_file($configFile)) {
    $installed = false;
    require $configFile;
    if (!empty($installed)) {
        header('Location: admin.php');
        exit;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
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

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

function installEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installIsAbsolutePath($path)
{
    return substr($path, 0, 1) === '/'
        || substr($path, 0, 2) === '\\\\'
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function installHasParentTraversal($path)
{
    return preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $path) === 1;
}

function installCanonicalizePath($path)
{
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

    if (file_exists($path)) {
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    $directory = dirname($path);
    $realDirectory = realpath($directory);
    if ($realDirectory !== false) {
        return rtrim($realDirectory, '/\\') . DIRECTORY_SEPARATOR . basename($path);
    }

    return $path;
}

function installResolveDatabasePath($locationKey, $pathInput, $locations)
{
    $pathInput = trim($pathInput);
    if ($pathInput === '') {
        throw new InvalidArgumentException('Database filename or path is required.');
    }

    if ($locationKey === 'custom') {
        if (!installIsAbsolutePath($pathInput)) {
            $example = DIRECTORY_SEPARATOR === '\\'
                ? 'C:\\path\\to\\database.sqlite'
                : '/www/wwwroot/example.com/database.sqlite';
            throw new InvalidArgumentException('A custom database path must be an absolute server filesystem path. Use a path such as ' . $example . '.');
        }

        return installCanonicalizePath($pathInput);
    }

    if (!isset($locations[$locationKey])) {
        throw new InvalidArgumentException('Choose where the database file is located.');
    }

    if (installIsAbsolutePath($pathInput)) {
        throw new InvalidArgumentException('For the selected location, enter only a filename or a path relative to that folder. Choose “Custom absolute path” to enter a complete server path.');
    }

    if (installHasParentTraversal($pathInput)) {
        throw new InvalidArgumentException('Do not use .. in the database path. Choose the parent folder or website root from the location list instead.');
    }

    $baseDirectory = $locations[$locationKey]['path'];
    $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pathInput), DIRECTORY_SEPARATOR);

    return installCanonicalizePath(rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR . $relativePath);
}

function installWriteApacheProtection($directory)
{
    $protectionFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($protectionFile)) {
        return;
    }

    $rules = "# Protect SQLite Admin data on Apache.\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Deny from all\n"
        . "</IfModule>\n";

    @file_put_contents($protectionFile, $rules, LOCK_EX);
}

$applicationDirectory = realpath(__DIR__);
if ($applicationDirectory === false) {
    $applicationDirectory = __DIR__;
}
$parentDirectory = dirname($applicationDirectory);
$documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
if ($documentRoot !== '' && is_dir($documentRoot)) {
    $realDocumentRoot = realpath($documentRoot);
    if ($realDocumentRoot !== false) {
        $documentRoot = $realDocumentRoot;
    }
} else {
    $documentRoot = '';
}

$databaseLocations = [
    'application' => [
        'label' => 'SQLite Admin folder',
        'path' => $applicationDirectory,
    ],
    'parent' => [
        'label' => 'Parent folder (one level above SQLite Admin)',
        'path' => $parentDirectory,
    ],
];

if ($documentRoot !== '' && $documentRoot !== $applicationDirectory && $documentRoot !== $parentDirectory) {
    $databaseLocations['document_root'] = [
        'label' => 'Website document root',
        'path' => $documentRoot,
    ];
} elseif ($documentRoot !== '' && $documentRoot === $parentDirectory) {
    $databaseLocations['parent']['label'] = 'Parent folder / website document root';
}

$error = '';
$success = '';
$resolvedPathDisplay = '';
$environmentErrors = [];

if (version_compare(PHP_VERSION, SQLITE_ADMIN_MIN_PHP, '<')) {
    $environmentErrors[] = 'PHP ' . SQLITE_ADMIN_MIN_PHP . ' or newer is required. This server is running PHP ' . PHP_VERSION . '.';
}
if (!extension_loaded('sqlite3')) {
    $environmentErrors[] = 'The PHP SQLite3 extension is not enabled.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $environmentErrors === []) {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $expectedToken = (string) ($_SESSION['install_csrf'] ?? '');

    if ($submittedToken === '' || $expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
        $error = 'The installation security token is invalid. Reload the page and try again.';
    } else {
        $dbLocation = trim((string) ($_POST['db_location'] ?? ''));
        $dbPathInput = trim((string) ($_POST['db_path'] ?? ''));
        $createIfMissing = isset($_POST['create_if_missing']) && (string) $_POST['create_if_missing'] === '1';
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $sessionName = trim((string) ($_POST['session_name'] ?? 'sqlite_admin'));
        $dbPath = '';

        try {
            $dbPath = installResolveDatabasePath($dbLocation, $dbPathInput, $databaseLocations);
            $resolvedPathDisplay = $dbPath;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }

        if ($error === '' && !preg_match('/\.(?:sqlite|sqlite3|db)$/i', $dbPath)) {
            $error = 'The database filename must end in .sqlite, .sqlite3, or .db.';
        } elseif ($error === '' && !preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $username)) {
            $error = 'Username must be 3–64 characters and may contain letters, numbers, periods, underscores, @, and hyphens.';
        } elseif ($error === '' && strlen($password) < SQLITE_ADMIN_MIN_PASSWORD_LENGTH) {
            $error = 'Password must be at least ' . SQLITE_ADMIN_MIN_PASSWORD_LENGTH . ' characters.';
        } elseif ($error === '' && $password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } elseif ($error === '' && !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $sessionName)) {
            $error = 'Session name must begin with a letter and may contain letters, numbers, underscores, and hyphens.';
        } elseif ($error === '' && file_exists($dbPath) && !is_file($dbPath)) {
            $error = 'The resolved database path is not a file: ' . $dbPath;
        } elseif ($error === '' && !is_file($dbPath) && !$createIfMissing) {
            $error = 'No existing database was found at the resolved path: ' . $dbPath . '. Check the location and filename, or select “Create a new database if it does not exist.”';
        } elseif ($error === '') {
            $dbDirectory = dirname($dbPath);
            $createdDirectory = false;

            try {
                if (!is_dir($dbDirectory)) {
                    if (!$createIfMissing) {
                        throw new RuntimeException('The database directory does not exist: ' . $dbDirectory);
                    }
                    if (!mkdir($dbDirectory, 0750, true) && !is_dir($dbDirectory)) {
                        throw new RuntimeException('Could not create the database directory.');
                    }
                    $createdDirectory = true;
                }

                if (!is_writable($dbDirectory)) {
                    throw new RuntimeException('The database directory is not writable by PHP: ' . $dbDirectory);
                }

                if (is_file($dbPath) && !is_writable($dbPath)) {
                    throw new RuntimeException('The database file is not writable by PHP: ' . $dbPath);
                }

                $database = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                $database->enableExceptions(true);
                $database->busyTimeout(5000);
                $database->exec('PRAGMA foreign_keys = ON');
                $integrity = (string) $database->querySingle('PRAGMA integrity_check');
                $database->close();

                if (strtolower($integrity) !== 'ok') {
                    throw new RuntimeException('SQLite integrity check failed: ' . $integrity);
                }

                if ($createdDirectory || realpath($dbDirectory) === realpath(__DIR__ . '/data')) {
                    installWriteApacheProtection($dbDirectory);
                }

                $configContent = "<?php\n"
                    . "declare(strict_types=1);\n\n"
                    . "// Generated by SQLite Admin on " . date(DATE_ATOM) . "\n"
                    . '$dbFile = ' . var_export($dbPath, true) . ";\n"
                    . '$username = ' . var_export($username, true) . ";\n"
                    . '$passwordHash = ' . var_export(password_hash($password, PASSWORD_DEFAULT), true) . ";\n"
                    . '$installed = true;' . "\n"
                    . '$debug = false;' . "\n"
                    . '$sessionName = ' . var_export($sessionName, true) . ";\n";

                $temporaryConfig = $configFile . '.tmp-' . bin2hex(random_bytes(6));
                if (file_put_contents($temporaryConfig, $configContent, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write the temporary configuration file.');
                }
                @chmod($temporaryConfig, 0600);

                if (!rename($temporaryConfig, $configFile)) {
                    @unlink($temporaryConfig);
                    throw new RuntimeException('Could not activate config.php. Check directory permissions.');
                }
                @chmod($configFile, 0600);

                $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
                $success = 'Installation completed successfully. SQLite Admin is using: ' . $dbPath;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>SQLite Admin – Installation</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .container {
            width: min(640px, 100%);
            padding: 2.25rem;
            border: 1px solid #e2e8f0;
            border-radius: .75rem;
            background: #fff;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .1);
        }
        h1 { color: #2563eb; text-align: center; font-size: 1.9rem; }
        .subtitle { margin: .45rem 0 1.5rem; color: #64748b; text-align: center; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: .3rem; color: #334155; font-size: .9rem; font-weight: 650; }
        input, select {
            width: 100%;
            padding: .68rem .8rem;
            border: 1px solid #cbd5e1;
            border-radius: .4rem;
            background: #fff;
            color: #0f172a;
            font: inherit;
        }
        input:focus, select:focus { outline: 3px solid rgba(37, 99, 235, .16); border-color: #2563eb; }
        input[readonly] { background: #f8fafc; color: #475569; }
        code { overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
        .path-box {
            margin-bottom: 1rem;
            padding: .8rem 1rem;
            border: 1px solid #bfdbfe;
            border-radius: .45rem;
            background: #eff6ff;
            color: #1e3a8a;
            font-size: .8rem;
            line-height: 1.5;
        }
        .path-box strong { display: block; margin-bottom: .15rem; }
        .help-text { margin-top: .25rem; color: #64748b; font-size: .79rem; line-height: 1.45; }
        .checkbox-row { display: flex; align-items: flex-start; gap: .55rem; }
        .checkbox-row input { width: auto; margin-top: .2rem; }
        .checkbox-row label { margin: 0; font-weight: 600; line-height: 1.45; }
        .btn {
            width: 100%;
            padding: .72rem 1rem;
            border: 0;
            border-radius: .4rem;
            background: #2563eb;
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .btn:hover { background: #1d4ed8; }
        .notice { margin-bottom: 1rem; padding: .8rem 1rem; border-radius: .45rem; line-height: 1.5; overflow-wrap: anywhere; }
        .error { border: 1px solid #fecaca; background: #fee2e2; color: #991b1b; }
        .success { border: 1px solid #bbf7d0; background: #dcfce7; color: #166534; }
        .success a { color: inherit; font-weight: 800; }
        .environment-list { margin: .35rem 0 0 1.15rem; }
        .footer { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #e2e8f0; color: #64748b; font-size: .8rem; text-align: center; }
        @media (max-width: 600px) { .container { padding: 1.4rem; } }
    </style>
</head>
<body>
    <main class="container">
        <h1>SQLite Admin</h1>
        <p class="subtitle">Secure one-time setup</p>

        <?php if ($environmentErrors !== []): ?>
            <div class="notice error">
                <strong>The server does not meet the requirements:</strong>
                <ul class="environment-list">
                    <?php foreach ($environmentErrors as $environmentError): ?>
                        <li><?php echo installEscape($environmentError); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($error !== ''): ?>
            <div class="notice error"><strong>Installation failed:</strong> <?php echo installEscape($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="notice success">
                <?php echo installEscape($success); ?>
                <a href="admin.php">Open SQLite Admin</a>
            </div>
        <?php elseif ($environmentErrors === []): ?>
            <div class="path-box">
                <strong>Detected SQLite Admin folder</strong>
                <code><?php echo installEscape($applicationDirectory); ?></code>
                <?php if ($documentRoot !== ''): ?>
                    <strong style="margin-top:.55rem">Detected website document root</strong>
                    <code><?php echo installEscape($documentRoot); ?></code>
                <?php endif; ?>
            </div>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo installEscape($_SESSION['install_csrf']); ?>">

                <div class="form-group">
                    <label for="db_location">Database Location</label>
                    <select id="db_location" name="db_location" required>
                        <option value="">Choose a database location…</option>
                        <?php foreach ($databaseLocations as $locationKey => $location): ?>
                            <option value="<?php echo installEscape($locationKey); ?>"<?php echo (string) ($_POST['db_location'] ?? '') === $locationKey ? ' selected' : ''; ?>>
                                <?php echo installEscape($location['label'] . ' — ' . $location['path']); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom"<?php echo (string) ($_POST['db_location'] ?? '') === 'custom' ? ' selected' : ''; ?>>Custom absolute server path</option>
                    </select>
                    <p class="help-text">The SQLite Admin folder is detected automatically. This choice controls where the database is stored; it does not move the application.</p>
                </div>

                <div class="form-group">
                    <label for="db_path">Database Filename or Path Within That Location</label>
                    <input type="text" id="db_path" name="db_path" required value="<?php echo installEscape($_POST['db_path'] ?? ''); ?>">
                    <p class="help-text">For a listed location, enter <code>database.sqlite</code> or a subfolder path such as <code>storage/database.sqlite</code>. For “Custom absolute server path,” enter the complete filesystem path, beginning with <code>/</code> on Linux, such as <code>/www/wwwroot/example.com/database.sqlite</code>.</p>
                </div>

                <div class="form-group checkbox-row">
                    <input type="checkbox" id="create_if_missing" name="create_if_missing" value="1"<?php echo isset($_POST['create_if_missing']) ? ' checked' : ''; ?>>
                    <label for="create_if_missing">Create a new database if the file does not exist</label>
                </div>
                <p class="help-text" style="margin-top:-.7rem;margin-bottom:1rem">Leave this unchecked when connecting to an existing database. This prevents a typing mistake from silently creating an empty database in the wrong folder.</p>

                <div class="form-group">
                    <label for="username">Admin Username</label>
                    <input type="text" id="username" name="username" required minlength="3" maxlength="64" autocomplete="username" value="<?php echo installEscape($_POST['username'] ?? 'admin'); ?>">
                </div>

                <div class="form-group">
                    <label for="password">Admin Password</label>
                    <input type="password" id="password" name="password" required minlength="<?php echo SQLITE_ADMIN_MIN_PASSWORD_LENGTH; ?>" autocomplete="new-password">
                    <p class="help-text">Use at least <?php echo SQLITE_ADMIN_MIN_PASSWORD_LENGTH; ?> characters. The password is stored only as a secure hash.</p>
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label for="session_name">Session Name</label>
                    <input type="text" id="session_name" name="session_name" required value="<?php echo installEscape($_POST['session_name'] ?? 'sqlite_admin'); ?>">
                    <p class="help-text">Change this when hosting more than one installation on the same domain.</p>
                </div>

                <button type="submit" class="btn">Install SQLite Admin</button>
            </form>
        <?php endif; ?>

        <footer class="footer">SQLite Admin <?php echo SQLITE_ADMIN_VERSION; ?> · MIT License</footer>
    </main>
</body>
</html>
