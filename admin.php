<?php
error_reporting(E_ALL);
ini_set('display_errors', 1); // Turn off in production

// ----- CONFIGURATION -----
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
} else {
    $dbFile = __DIR__ . '/database.sqlite';
    $username = 'admin';
    $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
    $installed = false;
}

if (!isset($installed) || !$installed) {
    header('Location: install.php');
    exit;
}

// ----- MULTIPLE DATABASE SUPPORT -----
session_start();
$dbDir = dirname($dbFile);
$databases = [];
if (is_dir($dbDir)) {
    $files = scandir($dbDir);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['sqlite', 'db', 'sqlite3'])) {
            $databases[] = $file;
        }
    }
}

if (isset($_GET['db']) && in_array($_GET['db'], $databases)) {
    $dbFile = $dbDir . '/' . $_GET['db'];
    $_SESSION['current_db'] = $dbFile;
} elseif (isset($_SESSION['current_db']) && file_exists($_SESSION['current_db'])) {
    $dbFile = $_SESSION['current_db'];
} else {
    $_SESSION['current_db'] = $dbFile;
}

// ----- LOGIN -----
if (!isset($_SESSION['admin_logged'])) {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        $submittedUser = $_POST['username'];
        $submittedPass = $_POST['password'];
        if ($submittedUser === $username && password_verify($submittedPass, $passwordHash)) {
            $_SESSION['admin_logged'] = true;
            if (!isset($_SESSION['theme'])) {
                $_SESSION['theme'] = 'light';
            }
            unset($_SESSION['flash']);
            if (!isset($_SESSION['undo_history'])) {
                $_SESSION['undo_history'] = [];
            }
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        if (isset($_POST['username']) || isset($_POST['password'])) {
            $error = 'Please fill in both fields';
        }
    }
    if (!isset($_SESSION['admin_logged'])) {
        // Show login screen
        ?>
        <!DOCTYPE html>
        <html>
        <head><title>SQLite Admin – Login</title></head>
        <body style="font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;background:#f4f4f4;margin:0;">
            <div style="background:#fff;padding:30px;border-radius:8px;width:320px;box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                <h1 style="color:#0056b3;margin-top:0;text-align:center;">🔐 SQLite Admin</h1>
                <?php if (isset($error)) echo '<p style="color:red;text-align:center;">'.$error.'</p>'; ?>
                <form method="post">
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Username</label>
                        <input type="text" name="username" placeholder="Enter username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;">
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-weight:600;margin-bottom:4px;">Password</label>
                        <input type="password" name="password" placeholder="Enter password" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;">
                    </div>
                    <button type="submit" style="width:100%;padding:10px;background:#0056b3;color:white;border:none;border-radius:4px;cursor:pointer;font-size:1rem;">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// ----- THEME TOGGLE -----
if (isset($_GET['theme'])) {
    $_SESSION['theme'] = $_GET['theme'] === 'dark' ? 'dark' : 'light';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
$theme = $_SESSION['theme'] ?? 'light';
$isDark = ($theme === 'dark');

// ----- DATABASE CONNECTION -----
try {
    if (!extension_loaded('sqlite3')) {
        throw new Exception('SQLite3 extension not loaded.');
    }
    if (!file_exists($dbFile)) {
        throw new Exception('Database file not found: ' . $dbFile);
    }
    $db = new SQLite3($dbFile);
    $db->enableExceptions(true);
} catch (Exception $e) {
    die('<div style="font-family:sans-serif;padding:20px;max-width:600px;margin:40px auto;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
            <h2 style="color:#d9534f;">Database Error</h2>
            <p>' . htmlspecialchars($e->getMessage()) . '</p>
            <p><small>Please check the database file path and permissions.</small></p>
          </div>');
}

// ----- LOGOUT -----
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ----- HANDLE ACTIONS -----
$action = $_GET['action'] ?? 'browse';
$table = isset($_GET['table']) ? trim($_GET['table']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 100;

$colFilters = [];
if (isset($_GET['col_filters']) && is_array($_GET['col_filters'])) {
    foreach ($_GET['col_filters'] as $col => $val) {
        $col = trim($col);
        $val = trim($val);
        if ($col !== '' && $val !== '') {
            $colFilters[$col] = $val;
        }
    }
}

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getQueryString($table, $search, $colFilters, $extra = []) {
    $params = ['table' => $table];
    if ($search) $params['search'] = $search;
    if (!empty($colFilters)) $params['col_filters'] = $colFilters;
    $params = array_merge($params, $extra);
    return '?' . http_build_query($params);
}

// ----- UNDO SYSTEM -----
function pushHistory($action, $table, $data) {
    if (!isset($_SESSION['undo_history'])) {
        $_SESSION['undo_history'] = [];
    }
    $entry = ['action' => $action, 'table' => $table, 'data' => $data, 'time' => time()];
    array_unshift($_SESSION['undo_history'], $entry);
    if (count($_SESSION['undo_history']) > 5) {
        array_pop($_SESSION['undo_history']);
    }
}

// ----- UNDO -----
if ($action === 'undo') {
    if (empty($_SESSION['undo_history'])) {
        setFlash('error', 'Nothing to undo.');
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
    $entry = array_shift($_SESSION['undo_history']);
    $undoTable = $entry['table'];
    $undoAction = $entry['action'];
    $undoData = $entry['data'];
    $safeTable = SQLite3::escapeString($undoTable);
    try {
        switch ($undoAction) {
            case 'insert':
                if (isset($undoData['pk_col']) && isset($undoData['pk_val'])) {
                    $pkCol = SQLite3::escapeString($undoData['pk_col']);
                    $pkVal = SQLite3::escapeString($undoData['pk_val']);
                    $db->exec("DELETE FROM $safeTable WHERE $pkCol = '$pkVal'");
                    setFlash('success', 'Undo insert: row deleted.');
                } else {
                    setFlash('error', 'Cannot undo insert: missing primary key.');
                }
                break;
            case 'update':
                if (isset($undoData['pk_col']) && isset($undoData['pk_val']) && isset($undoData['old_data'])) {
                    $pkCol = SQLite3::escapeString($undoData['pk_col']);
                    $pkVal = SQLite3::escapeString($undoData['pk_val']);
                    $set = [];
                    foreach ($undoData['old_data'] as $col => $val) {
                        if ($col !== $pkCol) {
                            $safeCol = SQLite3::escapeString($col);
                            $safeVal = SQLite3::escapeString($val);
                            $set[] = "$safeCol = '$safeVal'";
                        }
                    }
                    if (!empty($set)) {
                        $db->exec("UPDATE $safeTable SET " . implode(', ', $set) . " WHERE $pkCol = '$pkVal'");
                        setFlash('success', 'Undo update: row restored.');
                    } else {
                        setFlash('error', 'No changes to restore.');
                    }
                } else {
                    setFlash('error', 'Cannot undo update: missing data.');
                }
                break;
            case 'delete':
                if (isset($undoData['row_data']) && is_array($undoData['row_data'])) {
                    $cols = array_keys($undoData['row_data']);
                    $vals = array_map(function($v) { return "'" . SQLite3::escapeString($v) . "'"; }, array_values($undoData['row_data']));
                    $colStr = implode(',', array_map(function($c) { return SQLite3::escapeString($c); }, $cols));
                    $valStr = implode(',', $vals);
                    $db->exec("INSERT INTO $safeTable ($colStr) VALUES ($valStr)");
                    setFlash('success', 'Undo delete: row restored.');
                } else {
                    setFlash('error', 'Cannot undo delete: missing row data.');
                }
                break;
            case 'bulk_delete':
                if (isset($undoData['rows']) && is_array($undoData['rows'])) {
                    $inserted = 0;
                    foreach ($undoData['rows'] as $row) {
                        $cols = array_keys($row);
                        $vals = array_map(function($v) { return "'" . SQLite3::escapeString($v) . "'"; }, array_values($row));
                        $colStr = implode(',', array_map(function($c) { return SQLite3::escapeString($c); }, $cols));
                        $valStr = implode(',', $vals);
                        $db->exec("INSERT INTO $safeTable ($colStr) VALUES ($valStr)");
                        $inserted++;
                    }
                    setFlash('success', "Undo bulk delete: $inserted rows restored.");
                } else {
                    setFlash('error', 'Cannot undo bulk delete: missing rows data.');
                }
                break;
            case 'rename_table':
                if (isset($undoData['old_name'])) {
                    $newName = SQLite3::escapeString($undoTable);
                    $oldName = SQLite3::escapeString($undoData['old_name']);
                    $db->exec("ALTER TABLE $newName RENAME TO $oldName");
                    setFlash('success', 'Undo rename: table renamed back.');
                    $table = $undoData['old_name'];
                } else {
                    setFlash('error', 'Cannot undo rename: missing old name.');
                }
                break;
            case 'rename_column':
                if (isset($undoData['old_col']) && isset($undoData['new_col'])) {
                    $safeOld = SQLite3::escapeString($undoData['old_col']);
                    $safeNew = SQLite3::escapeString($undoData['new_col']);
                    $db->exec("ALTER TABLE $safeTable RENAME COLUMN $safeNew TO $safeOld");
                    setFlash('success', 'Undo rename column: column renamed back.');
                } else {
                    setFlash('error', 'Cannot undo rename column: missing data.');
                }
                break;
            case 'add_column':
                if (isset($undoData['col_name'])) {
                    $version = $db->querySingle("SELECT sqlite_version()");
                    if (version_compare($version, '3.35.0', '>=')) {
                        $safeCol = SQLite3::escapeString($undoData['col_name']);
                        $db->exec("ALTER TABLE $safeTable DROP COLUMN $safeCol");
                        setFlash('success', 'Undo add column: column dropped.');
                    } else {
                        setFlash('error', 'Cannot undo add column: SQLite version does not support DROP COLUMN (requires 3.35.0+).');
                    }
                } else {
                    setFlash('error', 'Cannot undo add column: missing column name.');
                }
                break;
            default:
                setFlash('error', 'Unknown action, cannot undo.');
        }
    } catch (Exception $e) {
        setFlash('error', 'Undo failed: ' . $e->getMessage());
    }
    header('Location: ' . getQueryString($table, $search, $colFilters));
    exit;
}

// ----- GET SCHEMA (AJAX) -----
if ($action === 'get_schema' && isset($_GET['table'])) {
    $tableName = trim($_GET['table']);
    $safeTable = SQLite3::escapeString($tableName);
    $result = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$safeTable'");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $schema = $row ? $row['sql'] : 'Schema not found.';
    header('Content-Type: text/plain');
    echo $schema;
    exit;
}

// ----- GET COLUMNS (AJAX) for Edit Schema -----
if ($action === 'get_columns' && isset($_GET['table'])) {
    $tableName = trim($_GET['table']);
    $safeTable = SQLite3::escapeString($tableName);
    $info = $db->query("PRAGMA table_info($safeTable)");
    $columns = [];
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = [
            'name' => $row['name'],
            'type' => $row['type'],
            'pk' => (bool)$row['pk']
        ];
    }
    header('Content-Type: application/json');
    echo json_encode($columns);
    exit;
}

// ----- EXPORT TABLE AS SQL -----
if ($action === 'export_sql' && $table) {
    $safeTable = SQLite3::escapeString($table);
    $createResult = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$safeTable'");
    $createRow = $createResult->fetchArray(SQLITE3_ASSOC);
    if (!$createRow) {
        setFlash('error', 'Table schema not found.');
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
    $createSQL = $createRow['sql'] . ";\n\n";
    $rowsResult = $db->query("SELECT * FROM $safeTable");
    $inserts = [];
    $cols = [];
    $info = $db->query("PRAGMA table_info($safeTable)");
    while ($col = $info->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = $col['name'];
    }
    $colList = implode(',', array_map(function($c) { return '"' . SQLite3::escapeString($c) . '"'; }, $cols));
    while ($row = $rowsResult->fetchArray(SQLITE3_ASSOC)) {
        $vals = [];
        foreach ($cols as $col) {
            $val = $row[$col];
            if ($val === null) {
                $vals[] = 'NULL';
            } else {
                $vals[] = "'" . SQLite3::escapeString($val) . "'";
            }
        }
        $inserts[] = "INSERT INTO \"$safeTable\" ($colList) VALUES (" . implode(',', $vals) . ");";
    }
    $sqlContent = "-- Exported from SQLite Admin\n-- Table: $table\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
    $sqlContent .= $createSQL;
    if (!empty($inserts)) {
        $sqlContent .= "-- Data\n" . implode("\n", $inserts) . "\n";
    } else {
        $sqlContent .= "-- No data rows.\n";
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $table . '.sql"');
    echo $sqlContent;
    exit;
}

// ----- IMPORT DATABASE (Upload .sqlite file) -----
if ($action === 'import_db' && isset($_FILES['db_file'])) {
    $file = $_FILES['db_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'File upload error: ' . $file['error']);
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['sqlite', 'db', 'sqlite3'])) {
        setFlash('error', 'Only SQLite database files (.sqlite, .db, .sqlite3) are allowed.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $destName = $baseName . '.' . $ext;
    $destPath = $dbDir . '/' . $destName;
    $counter = 1;
    while (file_exists($destPath)) {
        $destName = $baseName . '_' . $counter . '.' . $ext;
        $destPath = $dbDir . '/' . $destName;
        $counter++;
    }
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        setFlash('success', 'Database "' . htmlspecialchars($destName) . '" imported successfully.');
        $databases = array_merge($databases, [$destName]);
        sort($databases);
        $_SESSION['current_db'] = $destPath;
        header('Location: ?db=' . urlencode($destName));
    } else {
        setFlash('error', 'Failed to save database file. Please check write permissions.');
        header('Location: ' . getQueryString('', '', []));
    }
    exit;
}

// ----- EXPORT DATABASE -----
if ($action === 'export_db') {
    if (!file_exists($dbFile)) {
        setFlash('error', 'Database file not found.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $fileName = basename($dbFile);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($dbFile));
    readfile($dbFile);
    exit;
}

// ----- DROP TABLE -----
if ($action === 'drop_table' && isset($_GET['table'])) {
    $tableName = trim($_GET['table']);
    if (empty($tableName)) {
        setFlash('error', 'Table name is required.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $safeTable = SQLite3::escapeString($tableName);
    try {
        $db->exec("DROP TABLE $safeTable");
        setFlash('success', 'Table "' . htmlspecialchars($tableName) . '" dropped successfully.');
        header('Location: ' . getQueryString('', '', []));
    } catch (Exception $e) {
        setFlash('error', 'Failed to drop table: ' . $e->getMessage());
        header('Location: ' . getQueryString('', '', []));
    }
    exit;
}

// ----- RENAME TABLE -----
if ($action === 'rename_table' && isset($_POST['old_name']) && isset($_POST['new_name'])) {
    $oldName = trim($_POST['old_name']);
    $newName = trim($_POST['new_name']);
    if (empty($oldName) || empty($newName)) {
        setFlash('error', 'Table names are required.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $newName)) {
        setFlash('error', 'Invalid table name. Use only letters, numbers, and underscores, starting with a letter or underscore.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $safeOld = SQLite3::escapeString($oldName);
    $safeNew = SQLite3::escapeString($newName);
    try {
        $db->exec("ALTER TABLE $safeOld RENAME TO $safeNew");
        pushHistory('rename_table', $newName, ['old_name' => $oldName]);
        setFlash('success', 'Table renamed to "' . htmlspecialchars($newName) . '".');
        header('Location: ' . getQueryString($newName, $search, $colFilters));
    } catch (Exception $e) {
        setFlash('error', 'Failed to rename table: ' . $e->getMessage());
        header('Location: ' . getQueryString('', '', []));
    }
    exit;
}

// ----- ADD COLUMN -----
if ($action === 'add_column' && isset($_POST['table']) && isset($_POST['col_name']) && isset($_POST['col_type'])) {
    $tableName = trim($_POST['table']);
    $colName = trim($_POST['col_name']);
    $colType = strtoupper(trim($_POST['col_type']));
    if (empty($tableName) || empty($colName)) {
        setFlash('error', 'Table name and column name are required.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $colName)) {
        setFlash('error', 'Invalid column name.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $allowedTypes = ['TEXT', 'INTEGER', 'REAL', 'NUMERIC', 'BLOB', 'NULL'];
    if (!in_array($colType, $allowedTypes)) {
        setFlash('error', 'Invalid column type.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $safeTable = SQLite3::escapeString($tableName);
    $safeCol = SQLite3::escapeString($colName);
    try {
        $db->exec("ALTER TABLE $safeTable ADD COLUMN $safeCol $colType");
        pushHistory('add_column', $tableName, ['col_name' => $colName]);
        setFlash('success', 'Column "' . htmlspecialchars($colName) . '" added to table "' . htmlspecialchars($tableName) . '".');
        header('Location: ' . getQueryString($tableName, $search, $colFilters));
    } catch (Exception $e) {
        setFlash('error', 'Failed to add column: ' . $e->getMessage());
        header('Location: ' . getQueryString($tableName, $search, $colFilters));
    }
    exit;
}

// ----- RENAME COLUMN -----
if ($action === 'rename_column' && isset($_POST['table']) && isset($_POST['old_col']) && isset($_POST['new_col'])) {
    $tableName = trim($_POST['table']);
    $oldCol = trim($_POST['old_col']);
    $newCol = trim($_POST['new_col']);
    if (empty($tableName) || empty($oldCol) || empty($newCol)) {
        setFlash('error', 'All fields are required.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $newCol)) {
        setFlash('error', 'Invalid column name.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $safeTable = SQLite3::escapeString($tableName);
    $safeOld = SQLite3::escapeString($oldCol);
    $safeNew = SQLite3::escapeString($newCol);
    try {
        $db->exec("ALTER TABLE $safeTable RENAME COLUMN $safeOld TO $safeNew");
        pushHistory('rename_column', $tableName, ['old_col' => $oldCol, 'new_col' => $newCol]);
        setFlash('success', 'Column renamed to "' . htmlspecialchars($newCol) . '".');
        header('Location: ' . getQueryString($tableName, $search, $colFilters));
    } catch (Exception $e) {
        setFlash('error', 'Failed to rename column: ' . $e->getMessage());
        header('Location: ' . getQueryString($tableName, $search, $colFilters));
    }
    exit;
}

// ----- CREATE TABLE -----
if ($action === 'create_table' && isset($_POST['table_name']) && isset($_POST['col_name'])) {
    $tableName = trim($_POST['table_name']);
    $colNames = $_POST['col_name'];
    $colTypes = $_POST['col_type'];
    $colPk = isset($_POST['col_pk']) ? $_POST['col_pk'] : [];
    
    if (empty($tableName)) {
        setFlash('error', 'Table name is required.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
        setFlash('error', 'Invalid table name.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($tableName) . "'");
    if ($check->fetchArray(SQLITE3_ASSOC)) {
        setFlash('error', 'Table "' . htmlspecialchars($tableName) . '" already exists.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $safeTable = SQLite3::escapeString($tableName);
    $colDefs = [];
    $hasPk = false;
    foreach ($colNames as $idx => $colName) {
        $colName = trim($colName);
        $colType = strtoupper(trim($colTypes[$idx] ?? 'TEXT'));
        $isPk = isset($colPk[$idx]) && $colPk[$idx] == '1';
        if (empty($colName)) continue;
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $colName)) {
            setFlash('error', 'Invalid column name: ' . htmlspecialchars($colName));
            header('Location: ' . getQueryString('', '', []));
            exit;
        }
        $allowedTypes = ['TEXT', 'INTEGER', 'REAL', 'NUMERIC', 'BLOB', 'NULL'];
        if (!in_array($colType, $allowedTypes)) {
            setFlash('error', 'Invalid column type: ' . htmlspecialchars($colType));
            header('Location: ' . getQueryString('', '', []));
            exit;
        }
        $colDef = SQLite3::escapeString($colName) . ' ' . $colType;
        if ($isPk) {
            if ($hasPk) {
                setFlash('error', 'Only one primary key allowed.');
                header('Location: ' . getQueryString('', '', []));
                exit;
            }
            $hasPk = true;
            $colDef .= ' PRIMARY KEY';
        }
        $colDefs[] = $colDef;
    }
    if (empty($colDefs)) {
        setFlash('error', 'No valid columns provided.');
        header('Location: ' . getQueryString('', '', []));
        exit;
    }
    $sql = "CREATE TABLE $safeTable (\n  " . implode(",\n  ", $colDefs) . "\n)";
    try {
        if ($db->exec($sql)) {
            setFlash('success', 'Table "' . htmlspecialchars($tableName) . '" created successfully.');
            header('Location: ?table=' . urlencode($tableName));
        } else {
            setFlash('error', 'Failed to create table: ' . $db->lastErrorMsg());
            header('Location: ' . getQueryString('', '', []));
        }
    } catch (Exception $e) {
        setFlash('error', 'Failed to create table: ' . $e->getMessage());
        header('Location: ' . getQueryString('', '', []));
    }
    exit;
}

// ----- EXPORT TABLE (CSV/JSON) -----
if ($action === 'export_table' && $table) {
    $format = isset($_GET['format']) ? $_GET['format'] : 'csv';
    $safeTable = SQLite3::escapeString($table);
    $result = $db->query("SELECT * FROM $safeTable");
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    if (empty($rows)) {
        setFlash('error', 'Table is empty, nothing to export.');
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
    $cols = array_keys($rows[0]);
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $table . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, $cols);
        foreach ($rows as $row) {
            fputcsv($output, array_values($row));
        }
        fclose($output);
        exit;
    } elseif ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $table . '.json"');
        echo json_encode($rows, JSON_PRETTY_PRINT);
        exit;
    } else {
        setFlash('error', 'Unsupported export format.');
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
}

// ----- IMPORT TABLE (CSV/JSON) -----
if ($action === 'import_table' && $table && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'File upload error: ' . $file['error']);
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'json'])) {
        setFlash('error', 'Only CSV and JSON files are allowed.');
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
        setFlash('error', 'Failed to read uploaded file.');
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
    $cols = [];
    $info = $db->query("PRAGMA table_info(" . SQLite3::escapeString($table) . ")");
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = $row['name'];
    }
    if (empty($cols)) {
        setFlash('error', 'Table has no columns.');
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
    $safeTable = SQLite3::escapeString($table);
    $inserted = 0;
    $errors = 0;
    if ($ext === 'csv') {
        $lines = explode("\n", trim($content));
        if (count($lines) < 2) {
            setFlash('error', 'CSV must have a header row and at least one data row.');
            header('Location: ' . getQueryString($table, $search, $colFilters));
            exit;
        }
        $header = str_getcsv(array_shift($lines));
        $headerMap = [];
        foreach ($header as $h) {
            $h = trim($h);
            foreach ($cols as $col) {
                if (strcasecmp($h, $col) === 0) {
                    $headerMap[$h] = $col;
                    break;
                }
            }
        }
        if (count($headerMap) !== count($header)) {
            setFlash('error', 'CSV header does not match table columns.');
            header('Location: ' . getQueryString($table, $search, $colFilters));
            exit;
        }
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $data = str_getcsv($line);
            if (count($data) !== count($header)) {
                $errors++;
                continue;
            }
            $values = [];
            foreach ($header as $idx => $h) {
                $col = $headerMap[$h];
                $values[] = "'" . SQLite3::escapeString($data[$idx]) . "'";
            }
            $colNames = array_map(function($c) { return SQLite3::escapeString($c); }, array_values($headerMap));
            $sql = "INSERT INTO $safeTable (" . implode(',', $colNames) . ") VALUES (" . implode(',', $values) . ")";
            try {
                if ($db->exec($sql)) $inserted++;
                else $errors++;
            } catch (Exception $e) {
                $errors++;
            }
        }
    } elseif ($ext === 'json') {
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data)) {
            setFlash('error', 'Invalid JSON format or empty array.');
            header('Location: ' . getQueryString($table, $search, $colFilters));
            exit;
        }
        $first = $data[0];
        if (!is_array($first)) {
            setFlash('error', 'JSON must be an array of objects.');
            header('Location: ' . getQueryString($table, $search, $colFilters));
            exit;
        }
        $jsonKeys = array_keys($first);
        $validCols = array_intersect($jsonKeys, $cols);
        if (empty($validCols)) {
            setFlash('error', 'JSON keys do not match any table columns.');
            header('Location: ' . getQueryString($table, $search, $colFilters));
            exit;
        }
        foreach ($data as $row) {
            $values = [];
            $colNames = [];
            foreach ($row as $key => $val) {
                if (in_array($key, $cols)) {
                    $colNames[] = SQLite3::escapeString($key);
                    $values[] = "'" . SQLite3::escapeString($val) . "'";
                }
            }
            if (!empty($colNames)) {
                $sql = "INSERT INTO $safeTable (" . implode(',', $colNames) . ") VALUES (" . implode(',', $values) . ")";
                try {
                    if ($db->exec($sql)) $inserted++;
                    else $errors++;
                } catch (Exception $e) {
                    $errors++;
                }
            } else {
                $errors++;
            }
        }
    }
    setFlash('success', "Import completed: $inserted rows inserted, $errors errors.");
    header('Location: ' . getQueryString($table, $search, $colFilters));
    exit;
}

// ----- BULK DELETE -----
if ($action === 'bulk_delete' && $table && isset($_POST['selected'])) {
    $pkCol = null;
    $info = $db->query("PRAGMA table_info(" . SQLite3::escapeString($table) . ")");
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        if ($row['pk']) { $pkCol = $row['name']; break; }
    }
    $useRowid = false;
    if ($pkCol === null) {
        $pkCol = 'rowid';
        $useRowid = true;
    }
    $safeTable = SQLite3::escapeString($table);
    $ids = array_map(function($id) use ($db) {
        return "'" . SQLite3::escapeString($id) . "'";
    }, $_POST['selected']);
    if (!empty($ids)) {
        $rowsToDelete = [];
        if ($useRowid) {
            $result = $db->query("SELECT * FROM $safeTable WHERE rowid IN (" . implode(',', $ids) . ")");
        } else {
            $safePk = SQLite3::escapeString($pkCol);
            $result = $db->query("SELECT * FROM $safeTable WHERE $safePk IN (" . implode(',', $ids) . ")");
        }
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rowsToDelete[] = $row;
        }
        try {
            if ($useRowid) {
                $db->exec("DELETE FROM $safeTable WHERE rowid IN (" . implode(',', $ids) . ")");
            } else {
                $safePk = SQLite3::escapeString($pkCol);
                $db->exec("DELETE FROM $safeTable WHERE $safePk IN (" . implode(',', $ids) . ")");
            }
            pushHistory('bulk_delete', $table, ['rows' => $rowsToDelete]);
            setFlash('success', count($_POST['selected']) . ' rows deleted successfully.');
        } catch (Exception $e) {
            setFlash('error', 'Failed to delete rows: ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'No rows selected for deletion.');
    }
    header('Location: ' . getQueryString($table, $search, $colFilters));
    exit;
}

// ----- DELETE SINGLE ROW -----
if ($action === 'delete' && $table && isset($_GET['pk'])) {
    $pkCol = null;
    $info = $db->query("PRAGMA table_info(" . SQLite3::escapeString($table) . ")");
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        if ($row['pk']) { $pkCol = $row['name']; break; }
    }
    $useRowid = false;
    if ($pkCol === null) {
        $pkCol = 'rowid';
        $useRowid = true;
    }
    $safeTable = SQLite3::escapeString($table);
    $safeVal = SQLite3::escapeString($_GET['pk']);
    if ($useRowid) {
        $result = $db->query("SELECT * FROM $safeTable WHERE rowid = '$safeVal'");
    } else {
        $safePk = SQLite3::escapeString($pkCol);
        $result = $db->query("SELECT * FROM $safeTable WHERE $safePk = '$safeVal'");
    }
    $rowData = $result->fetchArray(SQLITE3_ASSOC);
    if ($rowData) {
        try {
            if ($useRowid) {
                $db->exec("DELETE FROM $safeTable WHERE rowid = '$safeVal'");
            } else {
                $safePk = SQLite3::escapeString($pkCol);
                $db->exec("DELETE FROM $safeTable WHERE $safePk = '$safeVal'");
            }
            pushHistory('delete', $table, ['row_data' => $rowData]);
            setFlash('success', 'Row deleted successfully.');
        } catch (Exception $e) {
            setFlash('error', 'Failed to delete row: ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'Row not found.');
    }
    header('Location: ' . getQueryString($table, $search, $colFilters));
    exit;
}

// ----- UPDATE ROW -----
if ($action === 'update' && $table && isset($_POST['pk'])) {
    $pkCol = null;
    $info = $db->query("PRAGMA table_info(" . SQLite3::escapeString($table) . ")");
    while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
        if ($row['pk']) { $pkCol = $row['name']; break; }
    }
    $useRowid = false;
    if ($pkCol === null) {
        $pkCol = 'rowid';
        $useRowid = true;
    }
    $safeTable = SQLite3::escapeString($table);
    $safePkVal = SQLite3::escapeString($_POST['pk']);
    if ($useRowid) {
        $result = $db->query("SELECT * FROM $safeTable WHERE rowid = '$safePkVal'");
    } else {
        $safePk = SQLite3::escapeString($pkCol);
        $result = $db->query("SELECT * FROM $safeTable WHERE $safePk = '$safePkVal'");
    }
    $oldData = $result->fetchArray(SQLITE3_ASSOC);
    if (!$oldData) {
        setFlash('error', 'Row not found.');
        header('Location: ' . getQueryString($table, $search, $colFilters));
        exit;
    }
    $cols = $db->query("PRAGMA table_info(" . SQLite3::escapeString($table) . ")");
    $set = [];
    $hasChanges = false;
    while ($col = $cols->fetchArray(SQLITE3_ASSOC)) {
        $name = $col['name'];
        if (!$useRowid && $name === $pkCol) continue;
        $safeName = SQLite3::escapeString($name);
        $safeVal = SQLite3::escapeString($_POST[$name] ?? '');
        $set[] = "$safeName = '$safeVal'";
        $hasChanges = true;
    }
    if ($set && $hasChanges) {
        try {
            if ($useRowid) {
                $db->exec("UPDATE $safeTable SET " . implode(', ', $set) . " WHERE rowid = '$safePkVal'");
            } else {
                $safePk = SQLite3::escapeString($pkCol);
                $db->exec("UPDATE $safeTable SET " . implode(', ', $set) . " WHERE $safePk = '$safePkVal'");
            }
            pushHistory('update', $table, ['pk_col' => $pkCol, 'pk_val' => $_POST['pk'], 'old_data' => $oldData]);
            setFlash('success', 'Row updated successfully.');
        } catch (Exception $e) {
            setFlash('error', 'Failed to update row: ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'No changes made or invalid data.');
    }
    header('Location: ' . getQueryString($table, $search, $colFilters));
    exit;
}

// ----- INSERT ROW -----
if ($action === 'insert' && $table && isset($_POST['new'])) {
    $safeTable = SQLite3::escapeString($table);
    $cols = $db->query("PRAGMA table_info(" . SQLite3::escapeString($table) . ")");
    $colNames = [];
    $colValues = [];
    $pkCol = null;
    while ($col = $cols->fetchArray(SQLITE3_ASSOC)) {
        $name = $col['name'];
        $colNames[] = SQLite3::escapeString($name);
        $colValues[] = "'" . SQLite3::escapeString($_POST[$name] ?? '') . "'";
        if ($col['pk']) $pkCol = $name;
    }
    if (!empty($colNames)) {
        $sql = "INSERT INTO $safeTable (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $colValues) . ")";
        try {
            $db->exec($sql);
            $pkVal = null;
            if ($pkCol) {
                $lastId = $db->lastInsertRowID();
                $pkVal = $db->querySingle("SELECT $pkCol FROM $safeTable WHERE rowid = $lastId");
            }
            pushHistory('insert', $table, ['pk_col' => $pkCol, 'pk_val' => $pkVal]);
            setFlash('success', 'New row inserted successfully.');
        } catch (SQLite3Exception $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                setFlash('error', 'Insert failed: A row with this primary key already exists.');
            } else {
                setFlash('error', 'Insert failed: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            setFlash('error', 'Insert failed: ' . $e->getMessage());
        }
    } else {
        setFlash('error', 'No columns found to insert.');
    }
    header('Location: ' . getQueryString($table, $search, $colFilters));
    exit;
}

// ----- GATHER DATABASE INFO (for sidebar) -----
$dbSize = file_exists($dbFile) ? filesize($dbFile) : 0;
$dbSizeFormatted = $dbSize ? round($dbSize / 1024, 1) . ' KB' : '0 KB';
if ($dbSize > 1048576) $dbSizeFormatted = round($dbSize / 1048576, 1) . ' MB';
$tableCountResult = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
$tableCount = $tableCountResult->fetchArray(SQLITE3_NUM)[0] ?? 0;
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

    #col-filter-form,
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
        top: 0;
        z-index: 10;
    }
    .table-wrap thead th {
        background: #e2e8f0;
    }
    .dark .table-wrap thead th {
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
            var tableName = document.getElementById('drop-confirm-table').value;
            if (tableName) {
                window.location.href = '?action=drop_table&table=' + encodeURIComponent(tableName);
            }
            hideDropConfirm();
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
                    showModal('edit-schema-modal');
                })
                .catch(err => {
                    alert('Error fetching columns: ' + err);
                });
        }
        function hideEditSchemaModal() { hideModal('edit-schema-modal'); }
        function addColumnRow() {
            const container = document.getElementById('column-rows');
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
                    <option value="NULL">NULL</option>
                </select>
                <label><input type="checkbox" name="col_pk[]" value="1"> PK</label>
                <button type="button" class="remove-col" onclick="removeColumnRow(this)" title="Remove column"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(row);
        }
        function removeColumnRow(btn) {
            const row = btn.closest('.column-row');
            if (document.querySelectorAll('.column-row').length > 1) {
                row.remove();
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
            <i class="fas fa-database"></i> SQLite Admin
        </div>
        <div class="header-actions">
            <a href="?theme=<?php echo $isDark ? 'light' : 'dark'; ?>" class="theme-toggle" title="Toggle theme">
                <i class="fas <?php echo $isDark ? 'fa-sun' : 'fa-moon'; ?>"></i>
            </a>
            <a href="?logout=1" title="Logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

    <div class="app-container">
        <nav class="sidebar">
            <div class="db-header">
                <div class="db-name">
                    <i class="fas fa-database"></i>
                    <?php echo htmlspecialchars(basename($dbFile)); ?>
                    <span class="size">(<?php echo $dbSizeFormatted; ?>)</span>
                </div>
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
                <h3><i class="fas fa-table"></i> Tables (<?php echo $tableCount; ?>)</h3>
                <button class="btn-plus" onclick="showCreateTableModal();" title="Create new table"><i class="fas fa-plus-circle"></i></button>
            </div>

            <div class="table-list">
                <?php
                $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
                $hasTables = false;
                while ($row = $tables->fetchArray(SQLITE3_ASSOC)) {
                    $hasTables = true;
                    $name = $row['name'];
                    $active = ($name === $table) ? 'active' : '';
                    $countResult = $db->query("SELECT COUNT(*) FROM " . SQLite3::escapeString($name));
                    $rowCount = $countResult->fetchArray(SQLITE3_NUM)[0] ?? 0;
                    echo '<div class="table-item">';
                    echo '<a href="?table=' . urlencode($name) . '" class="' . $active . '">';
                    echo '<span class="icon"><i class="fas fa-chevron-right"></i></span>';
                    echo '<span class="name">' . htmlspecialchars($name) . ' <span class="row-count">' . $rowCount . '</span></span>';
                    echo '</a>';
                    echo '<div class="table-actions">';
                    echo '<button onclick="fetchSchema(\'' . addslashes($name) . '\')" title="Show schema"><i class="fas fa-info-circle"></i></button>';
                    echo '<button onclick="showEditSchema(\'' . addslashes($name) . '\')" title="Edit schema"><i class="fas fa-cog"></i></button>';
                    echo '<button onclick="showRenameModal(\'' . addslashes($name) . '\')" title="Rename table"><i class="fas fa-pencil-alt"></i></button>';
                    echo '<button class="danger" onclick="confirmDrop(\'' . addslashes($name) . '\')" title="Drop table"><i class="fas fa-trash-alt"></i></button>';
                    echo '</div>';
                    echo '</div>';
                }
                if (!$hasTables) {
                    echo '<div style="padding:0.5rem 1.2rem;color:var(--text-muted);font-size:0.85rem;font-style:italic;">No tables yet</div>';
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
                    <input type="hidden" id="drop-confirm-table" value="">
                    <div class="actions">
                        <button type="button" class="btn btn-outline" onclick="hideDropConfirm();">Cancel</button>
                        <button type="button" class="btn btn-danger" onclick="proceedDrop();"><i class="fas fa-trash-alt"></i> Drop Table</button>
                    </div>
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
                    <?php if ($table): ?>
                    <form method="post" enctype="multipart/form-data" action="?table=<?php echo urlencode($table); ?>&action=import_table<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>">
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
                    <p style="color:var(--danger);">Please select a table first.</p>
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
                                    <option value="NULL">NULL</option>
                                </select>
                                <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add</button>
                            </div>
                        </div>
                    </form>
                    <div style="margin-top:0.8rem;border-top:1px solid var(--border-color);padding-top:0.8rem;">
                        <form method="post" action="?action=rename_column" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                            <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
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
                    <form method="post">
                        <textarea name="sql" placeholder="Write your SQL query here…"><?php echo isset($_POST['sql']) ? htmlspecialchars($_POST['sql']) : ''; ?></textarea>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Execute</button>
                    </form>
                    <?php
                    if (isset($_POST['sql']) && $_POST['sql'] !== '') {
                        try {
                            $result = $db->query($_POST['sql']);
                            if ($result === false) {
                                echo '<p style="color:var(--danger);margin-top:0.5rem;">Error: ' . $db->lastErrorMsg() . '</p>';
                            } else {
                                $cols = $result->numColumns();
                                if ($cols == 0) {
                                    echo '<p style="color:var(--success);margin-top:0.5rem;">✓ Query executed successfully (no rows returned).</p>';
                                } else {
                                    echo '<div class="table-wrap"><table><tr>';
                                    for ($i = 0; $i < $cols; $i++) echo '<th>' . htmlspecialchars($result->columnName($i)) . '</th>';
                                    echo '</tr>';
                                    while ($row = $result->fetchArray(SQLITE3_NUM)) {
                                        echo '<tr>';
                                        foreach ($row as $val) {
                                            echo '<td>' . ($val === null ? '<i>NULL</i>' : htmlspecialchars($val)) . '</td>';
                                        }
                                        echo '</tr>';
                                    }
                                    echo '</table></div>';
                                }
                            }
                        } catch (Exception $e) {
                            echo '<p style="color:var(--danger);margin-top:0.5rem;">Error: ' . $e->getMessage() . '</p>';
                        }
                    }
                    ?>
                </div>

            <?php elseif ($table === ''): ?>
                <div class="welcome">
                    <div class="icon"><i class="fas fa-database"></i></div>
                    <h1>Welcome to SQLite Admin</h1>
                    <p>This is a lightweight, self‑contained admin tool for SQLite databases.<br>
                    To get started, select a table from the sidebar or run a SQL query.</p>
                    <p style="margin-top:0.5rem;font-size:0.95rem;">
                        <i class="fas fa-chevron-left" style="color:var(--primary);"></i> 
                        Click a table name to browse, or use the <strong>SQL Query</strong> link below.
                    </p>
                    <div class="license">
                        <p><i class="fas fa-code"></i> SQLite Admin – Open Source under the <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener">MIT License</a></p>
                        <p style="margin-top:0.3rem;font-size:0.8rem;">
                            <a href="https://github.com/yourusername/sqlite-admin" target="_blank" rel="noopener"><i class="fab fa-github"></i> GitHub</a>
                        </p>
                    </div>
                </div>

            <?php else:
                $safeTable = SQLite3::escapeString($table);
                $pkCol = null;
                $cols = [];
                $info = $db->query("PRAGMA table_info($safeTable)");
                while ($row = $info->fetchArray(SQLITE3_ASSOC)) {
                    $cols[] = $row['name'];
                    if ($row['pk']) $pkCol = $row['name'];
                }
                $useRowid = false;
                if ($pkCol === null) {
                    $pkCol = 'rowid';
                    $useRowid = true;
                }

                if ($action === 'insert') {
                    echo '<h2 style="margin-bottom:0.75rem;"><i class="fas fa-plus-circle"></i> Insert new row into ' . htmlspecialchars($table) . '</h2>';
                    echo '<div class="edit-form"><form method="post">';
                    echo '<input type="hidden" name="new" value="1">';
                    echo '<div class="form-group">';
                    foreach ($cols as $col) {
                        echo '<label>' . htmlspecialchars($col) . ': <input type="text" name="' . htmlspecialchars($col) . '"></label>';
                    }
                    echo '</div>';
                    echo '<div class="actions"><button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Insert</button>';
                    echo ' <a href="' . getQueryString($table, $search, $colFilters) . '" class="btn btn-outline">Cancel</a></div>';
                    echo '</form></div>';
                    $showTable = false;
                } elseif ($action === 'edit' && isset($_GET['pk'])) {
                    $pkVal = $_GET['pk'];
                    $safePkVal = SQLite3::escapeString($pkVal);
                    if ($useRowid) {
                        $result = $db->query("SELECT * FROM $safeTable WHERE rowid = '$safePkVal'");
                    } else {
                        $safePk = SQLite3::escapeString($pkCol);
                        $result = $db->query("SELECT * FROM $safeTable WHERE $safePk = '$safePkVal'");
                    }
                    $row = $result->fetchArray(SQLITE3_ASSOC);
                    if ($row) {
                        echo '<h2 style="margin-bottom:0.75rem;"><i class="fas fa-edit"></i> Edit row in ' . htmlspecialchars($table) . '</h2>';
                        echo '<div class="edit-form"><form method="post" action="' . getQueryString($table, $search, $colFilters, ['action' => 'update']) . '">';
                        echo '<input type="hidden" name="pk" value="' . htmlspecialchars($pkVal) . '">';
                        echo '<div class="form-group">';
                        foreach ($cols as $col) {
                            $val = $row[$col] ?? '';
                            echo '<label>' . htmlspecialchars($col) . ': <input type="text" name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '"></label>';
                        }
                        echo '</div>';
                        echo '<div class="actions"><button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Update</button>';
                        echo ' <a href="' . getQueryString($table, $search, $colFilters) . '" class="btn btn-outline">Cancel</a></div>';
                        echo '</form></div>';
                        $showTable = false;
                    } else {
                        $showTable = true;
                    }
                } else {
                    $showTable = true;
                }

                if ($showTable):
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.5rem;">
                    <h2 style="margin:0;font-size:1.4rem;"><i class="fas fa-table"></i> <?php echo htmlspecialchars($table); ?></h2>
                </div>

                <div class="toolbar-row">
                    <div class="btn-group">
                        <a href="?table=<?php echo urlencode($table); ?>&action=insert<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>" class="btn btn-success"><i class="fas fa-plus"></i> Insert Row</a>
                        <button id="toggle-filter-btn" class="btn btn-purple" onclick="toggleFilterRow();"><i class="fas fa-filter"></i> Filters</button>
                        <form method="post" action="?table=<?php echo urlencode($table); ?>&action=bulk_delete<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>" onsubmit="return confirmBulkDelete();" style="display:inline;">
                            <button type="submit" id="bulk-delete-btn" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Delete</button>
                        </form>
                        <?php
                        $undoCount = isset($_SESSION['undo_history']) ? count($_SESSION['undo_history']) : 0;
                        ?>
                        <a href="?action=undo<?php echo $table ? '&table='.urlencode($table) : ''; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>" class="undo-btn <?php echo $undoCount > 0 ? '' : 'disabled'; ?>" title="Undo last action" <?php echo $undoCount > 0 ? '' : 'disabled'; ?>>
                            <i class="fas fa-undo-alt"></i> Undo <?php echo $undoCount > 0 ? '('.$undoCount.')' : ''; ?>
                        </a>
                    </div>
                    <form method="get" class="search-box" id="search-form">
                        <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                        <input type="text" name="search" placeholder="Search all…" value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                        <?php if ($search || !empty($colFilters)): ?>
                            <a href="?table=<?php echo urlencode($table); ?>" class="btn btn-outline"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php
                $whereClauses = [];
                if ($search) {
                    $likeParts = [];
                    foreach ($cols as $col) {
                        $safeCol = SQLite3::escapeString($col);
                        $safeSearch = SQLite3::escapeString($search);
                        $likeParts[] = "$safeCol LIKE '%$safeSearch%'";
                    }
                    if ($likeParts) {
                        $whereClauses[] = '(' . implode(' OR ', $likeParts) . ')';
                    }
                }
                foreach ($colFilters as $col => $val) {
                    if (in_array($col, $cols)) {
                        $safeCol = SQLite3::escapeString($col);
                        $safeVal = SQLite3::escapeString($val);
                        $whereClauses[] = "$safeCol LIKE '%$safeVal%'";
                    }
                }
                $where = '';
                if (!empty($whereClauses)) {
                    $where = ' WHERE ' . implode(' AND ', $whereClauses);
                }
                $countQuery = "SELECT COUNT(*) FROM $safeTable $where";
                $totalRows = $db->querySingle($countQuery);
                $pages = ceil($totalRows / $limit);
                $currentPage = floor($offset / $limit) + 1;
                $selectCols = $useRowid ? "*, rowid" : "*";
                $query = "SELECT $selectCols FROM $safeTable $where LIMIT $limit OFFSET $offset";
                $result = $db->query($query);
                $rows = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $rows[] = $row;
                }
                ?>

                <?php if (empty($rows)): ?>
                    <p style="color:var(--text-muted);">No rows found<?php echo $search ? ' matching "' . htmlspecialchars($search) . '"' : ''; ?>.</p>
                <?php else: ?>
                    <form method="get" id="col-filter-form" action="">
                        <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" class="select-all" onclick="toggleAll(this);">
                                        </th>
                                        <th style="width:80px;">Actions</th>
                                        <?php foreach ($cols as $col): ?>
                                            <th><?php echo htmlspecialchars($col); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                    <tr id="filter-row" class="filter-row <?php echo empty($colFilters) ? 'hidden' : ''; ?>">
                                        <td></td>
                                        <td></td>
                                        <?php foreach ($cols as $col): ?>
                                            <td>
                                                <input type="text" name="col_filters[<?php echo htmlspecialchars($col); ?>]" value="<?php echo htmlspecialchars($colFilters[$col] ?? ''); ?>" placeholder="Filter…" onchange="this.form.submit();">
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="selected[]" value="<?php echo htmlspecialchars($row[$pkCol] ?? ''); ?>" class="row-checkbox" onchange="updateBulkDeleteButton();">
                                            </td>
                                            <td>
                                                <div class="row-actions">
                                                    <a href="?table=<?php echo urlencode($table); ?>&action=edit&pk=<?php echo urlencode($row[$pkCol] ?? ''); ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>" title="Edit"><i class="fas fa-edit"></i></a>
                                                    <a href="?table=<?php echo urlencode($table); ?>&action=delete&pk=<?php echo urlencode($row[$pkCol] ?? ''); ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo !empty($colFilters) ? '&' . http_build_query(['col_filters' => $colFilters]) : ''; ?>" class="danger" title="Delete" onclick="return confirm('Delete this row?');"><i class="fas fa-trash-alt"></i></a>
                                                </div>
                                            </td>
                                            <?php foreach ($cols as $col): ?>
                                                <?php $val = $row[$col] ?? ''; ?>
                                                <td><?php echo $val === '' ? '<i>NULL</i>' : htmlspecialchars($val); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <!-- Fixed Footer Bar -->
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
            <span>Showing <?php echo min($limit, $totalRows - $offset); ?> of <?php echo $totalRows; ?> rows</span>
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
            SQLite Admin 1.0 – <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener">MIT License</a> - 
            Powered by PHP, JS &amp; SQLite3
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
<?php
$db->close();
?>