<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$error = '';
$locked = is_file(AZ_CONFIG_FILE);
$drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
$storageReady = false;
try {
    az_prepare_storage();
    $storageReady = true;
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'SQLite or MySQL PDO driver' => in_array('sqlite', $drivers, true) || in_array('mysql', $drivers, true),
    'Writable private storage folders' => $storageReady,
    'File uploads enabled' => (bool)ini_get('file_uploads'),
    'Upload requests of at least 128 KB' => az_bytes((string)ini_get('post_max_size')) >= 131072 && az_bytes((string)ini_get('upload_max_filesize')) >= 65536,
];
$canInstall = !in_array(false, $checks, true) && !$locked;
$values = [
    'admin_name' => '', 'admin_email' => '', 'database_driver' => in_array('sqlite', $drivers, true) ? 'sqlite' : 'mysql',
    'database_host' => 'localhost', 'database_port' => '3306', 'database_name' => '', 'database_user' => '',
];
foreach ($values as $key => $default) {
    if (isset($_POST[$key]) && is_string($_POST[$key])) {
        $values[$key] = trim($_POST[$key]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$locked) {
    $installLock = null;
    $database = null;
    $createdUserId = null;
    $pendingFile = null;
    try {
        az_require_csrf();
        if (!$canInstall) {
            throw new RuntimeException('Resolve the server checks below before installing.');
        }
        $name = az_text($values['admin_name'], 120, 'Administrator name');
        $email = strtolower(az_text($values['admin_email'], 254, 'Email address'));
        $password = $_POST['admin_password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter your name and a valid email address.');
        }
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new RuntimeException('Choose an administrator password between 8 and 72 characters.');
        }
        $driver = $values['database_driver'];
        if (!in_array($driver, ['sqlite', 'mysql'], true) || !in_array($driver, $drivers, true)) {
            throw new RuntimeException('The selected database driver is unavailable on this server.');
        }
        $databaseConfig = ['driver' => $driver];
        if ($driver === 'mysql') {
            $databaseConfig += [
                'host' => az_text($values['database_host'], 255, 'Database host'),
                'port' => az_integer($values['database_port'], 1, 65535, 'Database port'),
                'name' => az_text($values['database_name'], 120, 'Database name'),
                'user' => az_text($values['database_user'], 120, 'Database user'),
                'password' => is_string($_POST['database_password'] ?? null) ? $_POST['database_password'] : '',
            ];
            if ($databaseConfig['host'] === '' || $databaseConfig['name'] === '' || $databaseConfig['user'] === '') {
                throw new RuntimeException('Enter the MySQL host, database name, and database user.');
            }
        }
        $installLock = fopen(AZ_STORAGE . '/install.lock', 'c+b');
        if (!$installLock || !flock($installLock, LOCK_EX)) {
            throw new RuntimeException('The installer is busy. Reload this page and try again.');
        }
        if (is_file(AZ_CONFIG_FILE)) {
            $locked = true;
            throw new RuntimeException('The studio has already been installed. Sign in with its administrator account.');
        }
        $database = az_connect_database($databaseConfig);
        az_create_schema($database);
        if ((int)$database->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            throw new RuntimeException('This database already contains an administrator. Restore the existing storage/config.php instead of replacing that account, or choose an empty database.');
        }
        $configuration = [
            'installed' => true, 'installed_at' => az_now(), 'version' => AZ_VERSION,
            'app_secret' => bin2hex(random_bytes(32)), 'database' => $databaseConfig,
            'limits' => ['max_upload_bytes' => 2147483648, 'max_state_bytes' => 2097152],
        ];
        $pendingFile = AZ_STORAGE . '/config-' . az_id() . '.tmp.php';
        $configurationText = "<?php\ndeclare(strict_types=1);\n// Private installation settings. Keep this file with database and media backups.\nreturn " . var_export($configuration, true) . ";\n";
        if (file_put_contents($pendingFile, $configurationText, LOCK_EX) !== strlen($configurationText)) {
            throw new RuntimeException('The installer could not save the private configuration file.');
        }
        @chmod($pendingFile, 0640);
        $database->beginTransaction();
        $statement = $database->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)');
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), az_now()]);
        $createdUserId = (int)$database->lastInsertId();
        $database->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)')->execute([$createdUserId, 'admin']);
        $database->commit();
        if (!rename($pendingFile, AZ_CONFIG_FILE)) {
            throw new RuntimeException('The installer could not activate the private configuration. Check storage permissions and try again.');
        }
        $pendingFile = null;
        $GLOBALS['az_config_cache'] = $configuration;
        $GLOBALS['az_database'] = $database;
        $locked = true;
        az_login_user(['id' => $createdUserId]);
        az_redirect('library.php');
    } catch (Throwable $exception) {
        if ($database instanceof PDO && $database->inTransaction()) {
            $database->rollBack();
        }
        if ($createdUserId !== null && !$locked && $database instanceof PDO) {
            try {
                $statement = $database->prepare('DELETE FROM users WHERE id = ?');
                $statement->execute([$createdUserId]);
            } catch (Throwable $cleanupException) {
                az_log_exception($cleanupException);
            }
        }
        if ($pendingFile !== null && is_file($pendingFile)) {
            @unlink($pendingFile);
        }
        if ($exception instanceof PDOException) {
            az_log_exception($exception);
            $error = 'The database connection or setup failed. Check the database details, available PDO driver, and storage permissions. Technical details are in the PHP error log.';
        } else {
            $error = $exception->getMessage();
        }
        http_response_code($exception instanceof AzHttpError ? $exception->status : 400);
    } finally {
        if (is_resource($installLock)) {
            flock($installLock, LOCK_UN);
            fclose($installLock);
        }
    }
}

if ($locked) {
    http_response_code(403);
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Set up your studio · AZHottie</title>
    <link rel="stylesheet" href="<?= az_h(az_url('assets/auth.css')) ?>">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"></head>
<body>
<?php az_render_universal_header('install', $locked ? az_current_user() : null, (bool)$locked); ?>
<main class="auth-shell">
    <a class="auth-brand" href="<?= az_h(az_url('index.php')) ?>">AZHOTTIE <span>INTERVIEW STUDIO</span></a>
    <section class="auth-card">
        <?php if ($locked): ?>
            <p class="eyebrow">Setup complete</p>
            <h1>Your studio is installed.</h1>
            <p>The installer is locked. Your administrator account opens the interview library.</p>
            <a class="button" href="<?= az_h(az_url(az_current_user() ? 'library.php' : 'login.php')) ?>">Open your studio</a>
        <?php else: ?>
            <p class="eyebrow">Your private production space</p>
            <h1>Set up your studio.</h1>
            <p>Create the administrator account you will use to save interviews, recordings, and edits on this server.</p>
            <ul class="system-checks">
                <?php foreach ($checks as $label => $passed): ?>
                    <li class="<?= $passed ? 'check-pass' : 'check-fail' ?>"><span aria-hidden="true"><?= $passed ? '✓' : '!' ?></span> <?= az_h($label) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= az_h($error) ?></div><?php endif; ?>
            <form method="post" action="<?= az_h(az_url('install.php')) ?>">
                <input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>">
                <div class="field"><label for="admin-name">Your name</label><input id="admin-name" name="admin_name" value="<?= az_h($values['admin_name']) ?>" maxlength="120" autocomplete="name" required></div>
                <div class="field"><label for="admin-email">Administrator email</label><input id="admin-email" name="admin_email" type="email" value="<?= az_h($values['admin_email']) ?>" maxlength="254" autocomplete="username" required></div>
                <div class="field"><label for="admin-password">Administrator password</label><input id="admin-password" name="admin_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required><small>Use 8–72 characters. This signs you into your studio.</small></div>
                <div class="field"><label for="database-driver">Database</label><select id="database-driver" name="database_driver">
                    <option value="sqlite" <?= $values['database_driver'] === 'sqlite' ? 'selected' : '' ?> <?= !in_array('sqlite', $drivers, true) ? 'disabled' : '' ?>>SQLite — automatic setup</option>
                    <option value="mysql" <?= $values['database_driver'] === 'mysql' ? 'selected' : '' ?> <?= !in_array('mysql', $drivers, true) ? 'disabled' : '' ?>>MySQL — use an existing empty database</option>
                </select><small>SQLite needs no database account. For MySQL, first create an empty database and user in your hosting control panel.</small></div>
                <fieldset id="mysql-settings" <?= $values['database_driver'] !== 'mysql' ? 'hidden' : '' ?>>
                    <legend>MySQL connection</legend>
                    <div class="field"><label for="database-host">Host</label><input id="database-host" name="database_host" value="<?= az_h($values['database_host']) ?>" maxlength="255"></div>
                    <div class="field"><label for="database-port">Port</label><input id="database-port" name="database_port" type="number" min="1" max="65535" value="<?= az_h($values['database_port']) ?>"></div>
                    <div class="field"><label for="database-name">Database name</label><input id="database-name" name="database_name" value="<?= az_h($values['database_name']) ?>" maxlength="120"></div>
                    <div class="field"><label for="database-user">Database user</label><input id="database-user" name="database_user" value="<?= az_h($values['database_user']) ?>" maxlength="120" autocomplete="off"></div>
                    <div class="field"><label for="database-password">Database password</label><input id="database-password" name="database_password" type="password" autocomplete="off"></div>
                </fieldset>
                <button class="button" type="submit" <?= !$canInstall ? 'disabled' : '' ?>>Create my studio</button>
            </form>
        <?php endif; ?>
    </section>
    <p class="auth-footnote">AZHottie Interview Studio · Private interviews, saved takes, and considered edits.</p>
</main>
<script>
const databaseChoice = document.getElementById('database-driver');
if (databaseChoice) databaseChoice.addEventListener('change', () => { document.getElementById('mysql-settings').hidden = databaseChoice.value !== 'mysql'; });
</script>
</body>
</html>
