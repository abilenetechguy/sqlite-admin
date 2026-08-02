<?php
// ----- INSTALLATION WIZARD -----
// This script runs once to configure the admin panel

// Check if already installed
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    // Load config and check if installed
    require_once $configFile;
    if (isset($installed) && $installed) {
        header('Location: admin.php');
        exit;
    }
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbPath = trim($_POST['db_path'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    // Validate
    if (empty($dbPath)) {
        $error = 'Database path is required.';
    } elseif (empty($username)) {
        $error = 'Username is required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        // Create config file
        $configContent = "<?php\n";
        $configContent .= "// Auto-generated configuration\n";
        $configContent .= "// Last updated: " . date('Y-m-d H:i:s') . "\n\n";
        $configContent .= "\$dbFile = '" . addslashes($dbPath) . "';\n";
        $configContent .= "\$username = '" . addslashes($username) . "';\n";
        $configContent .= "\$passwordHash = '" . password_hash($password, PASSWORD_DEFAULT) . "';\n";
        $configContent .= "\$installed = true;\n";
        $configContent .= "?>";
        
        if (file_put_contents($configFile, $configContent)) {
            // Try to create the database directory if it doesn't exist
            $dbDir = dirname($dbPath);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
            // Create empty database file
            try {
                $db = new SQLite3($dbPath);
                $db->close();
                $success = 'Installation completed successfully! You can now <a href="admin.php">log in</a>.';
            } catch (Exception $e) {
                $error = 'Could not create database file: ' . $e->getMessage();
                // Delete config if database creation failed
                unlink($configFile);
            }
        } else {
            $error = 'Could not write configuration file. Please check permissions.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQLite Admin – Installation</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .container {
            background: #ffffff;
            border-radius: 0.5rem;
            padding: 2.5rem;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        h1 {
            color: #2563eb;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1.8rem;
        }
        h1 i {
            margin-right: 0.5rem;
        }
        .subtitle {
            text-align: center;
            color: #64748b;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
            color: #334155;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            font-size: 0.95rem;
            transition: border-color 0.15s;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .help-text {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 0.2rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 0.375rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            width: 100%;
            background: #2563eb;
            color: #fff;
        }
        .btn:hover {
            background: #1d4ed8;
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border: 1px solid #fecaca;
        }
        .success {
            background: #dcfce7;
            color: #166534;
            padding: 0.75rem 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border: 1px solid #bbf7d0;
        }
        .success a {
            color: #166534;
            font-weight: 600;
        }
        .footer {
            margin-top: 2rem;
            text-align: center;
            font-size: 0.8rem;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 1.5rem;
        }
        .footer a {
            color: #2563eb;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-database"></i> SQLite Admin</h1>
        <p class="subtitle">Installation Wizard – One-time setup</p>
        
        <?php if ($error): ?>
            <div class="error"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (!$success): ?>
        <form method="post">
            <div class="form-group">
                <label for="db_path">Database Path</label>
                <input type="text" id="db_path" name="db_path" value="<?php echo htmlspecialchars($_POST['db_path'] ?? __DIR__ . '/database.sqlite'); ?>" required>
                <div class="help-text">Full path where the SQLite database file will be stored.</div>
            </div>
            
            <div class="form-group">
                <label for="username">Admin Username</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? 'admin'); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">Admin Password</label>
                <input type="password" id="password" name="password" required minlength="6">
                <div class="help-text">Minimum 6 characters.</div>
            </div>
            
            <div class="form-group">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>
            
            <button type="submit" class="btn"><i class="fas fa-check"></i> Install</button>
        </form>
        <?php endif; ?>
        
        <div class="footer">
            SQLite Admin is open source under the <a href="https://opensource.org/licenses/MIT" target="_blank">MIT License</a>
        </div>
    </div>
</body>
</html>