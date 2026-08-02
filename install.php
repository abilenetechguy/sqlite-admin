<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const SQLITE_ADMIN_MIN_PHP = '7.0.0';
const SQLITE_ADMIN_MIN_PASSWORD_LENGTH = 12;

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
        || preg_match('/^[A-Za-z]:[\\\/]/', $path) === 1;
}

function installResolveDatabasePath($path)
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (!installIsAbsolutePath($path)) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
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

$error = '';
$success = '';
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
        $dbPathInput = trim((string) ($_POST['db_path'] ?? ''));
        $dbPath = installResolveDatabasePath($dbPathInput);
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $sessionName = trim((string) ($_POST['session_name'] ?? 'sqlite_admin'));

        if ($dbPath === '') {
            $error = 'Database filename or path is required.';
        } elseif (!preg_match('/\.(?:sqlite|sqlite3|db)$/i', $dbPath)) {
            $error = 'The database filename must end in .sqlite, .sqlite3, or .db.';
        } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $username)) {
            $error = 'Username must be 3–64 characters and may contain letters, numbers, periods, underscores, @, and hyphens.';
        } elseif (strlen($password) < SQLITE_ADMIN_MIN_PASSWORD_LENGTH) {
            $error = 'Password must be at least ' . SQLITE_ADMIN_MIN_PASSWORD_LENGTH . ' characters.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } elseif (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $sessionName)) {
            $error = 'Session name must begin with a letter and may contain letters, numbers, underscores, and hyphens.';
        } else {
            $dbDirectory = dirname($dbPath);
            $createdDirectory = false;

            try {
                if (!is_dir($dbDirectory)) {
                    if (!mkdir($dbDirectory, 0750, true) && !is_dir($dbDirectory)) {
                        throw new RuntimeException('Could not create the database directory.');
                    }
                    $createdDirectory = true;
                }

                if (!is_writable($dbDirectory)) {
                    throw new RuntimeException('The database directory is not writable by PHP.');
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
                $success = 'Installation completed successfully. You can now log in.';
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
            width: min(560px, 100%);
            padding: 2.25rem;
            border: 1px solid #e2e8f0;
            border-radius: .75rem;
            background: #fff;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .1);
        }
        h1 { color: #2563eb; text-align: center; font-size: 1.9rem; }
        .subtitle { margin: .45rem 0 1.75rem; color: #64748b; text-align: center; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: .3rem; color: #334155; font-size: .9rem; font-weight: 650; }
        input {
            width: 100%;
            padding: .68rem .8rem;
            border: 1px solid #cbd5e1;
            border-radius: .4rem;
            color: #0f172a;
            font: inherit;
        }
        input:focus { outline: 3px solid rgba(37, 99, 235, .16); border-color: #2563eb; }
        .help-text { margin-top: .25rem; color: #64748b; font-size: .79rem; line-height: 1.45; }
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
        .notice { margin-bottom: 1rem; padding: .8rem 1rem; border-radius: .45rem; line-height: 1.5; }
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
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo installEscape($_SESSION['install_csrf']); ?>">

                <div class="form-group">
                    <label for="db_path">Database Filename or Path</label>
                    <input type="text" id="db_path" name="db_path" required value="<?php echo installEscape($_POST['db_path'] ?? ''); ?>">
                    <p class="help-text">Enter the database filename when it is stored with SQLite Admin, such as <code>catalog.sqlite</code>. You may also enter a relative path such as <code>data/catalog.sqlite</code> or an absolute server filesystem path. Existing files are opened; a new database is created only when the entered filename or path does not already exist. Keep the database outside the public web root when practical.</p>
                </div>

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

        <footer class="footer">SQLite Admin 1.1.4 · MIT License</footer>
    </main>
</body>
</html>
