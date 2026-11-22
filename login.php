<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/universal-header.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!az_is_installed()) {
    az_redirect('install.php');
}
if ($signedIn = az_current_user()) {
    az_redirect(az_account_home($signedIn));
}
$error = '';
$email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        az_require_csrf();
        $password = $_POST['password'] ?? '';
        $key = hash_hmac('sha256', substr($email, 0, 254) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), az_config()['app_secret']);
        $statement = az_db()->prepare('SELECT attempt_count, window_started FROM auth_attempts WHERE attempt_key = ?');
        $statement->execute([$key]);
        $attempt = $statement->fetch();
        $now = time();
        if ($attempt && (int)$attempt['window_started'] > $now - 900 && (int)$attempt['attempt_count'] >= 8) {
            throw new AzHttpError(429, 'Too many unsuccessful sign-in attempts. Try again in 15 minutes.');
        }
        $statement = az_db()->prepare('SELECT * FROM users WHERE email = ?');
        $statement->execute([substr($email, 0, 254)]);
        $user = $statement->fetch();
        $valid = is_string($password) && strlen($password) <= 72 && $user && $user['status'] === 'active' && password_verify($password, $user['password_hash']);
        if (!$valid) {
            if ($attempt) {
                $withinWindow = (int)$attempt['window_started'] > $now - 900;
                $statement = az_db()->prepare('UPDATE auth_attempts SET attempt_count = ?, window_started = ? WHERE attempt_key = ?');
                $statement->execute([$withinWindow ? (int)$attempt['attempt_count'] + 1 : 1, $withinWindow ? (int)$attempt['window_started'] : $now, $key]);
            } else {
                $statement = az_db()->prepare('INSERT INTO auth_attempts (attempt_key, attempt_count, window_started) VALUES (?, 1, ?)');
                $statement->execute([$key, $now]);
            }
            throw new AzHttpError(401, 'The email address or password did not match. Please try again.');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $statement = az_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $statement->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        $statement = az_db()->prepare('DELETE FROM auth_attempts WHERE attempt_key = ? OR window_started < ?');
        $statement->execute([$key, $now - 86400]);
        az_login_user($user);
        az_redirect(az_account_home(az_account_user((int)$user['id'])));
    } catch (AzHttpError $exception) {
        $error = $exception->getMessage();
        http_response_code($exception->status);
    } catch (Throwable $exception) {
        az_log_exception($exception);
        $error = 'The studio could not complete sign-in. Check the server database connection and try again.';
        http_response_code(500);
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · AZHottie Interview Studio</title>
    <link rel="stylesheet" href="<?= az_h(az_url('assets/auth.css')) ?>">
<link rel="stylesheet" href="assets/universal-header.css?v=1.18.1"></head>
<body>
<?php az_render_universal_header('login', null, true); ?>
<main class="auth-shell">
    <a class="auth-brand" href="<?= az_h(az_url('index.php')) ?>">AZHOTTIE <span>INTERVIEW STUDIO</span></a>
    <section class="auth-card">
        <p class="eyebrow">Welcome back</p>
        <h1>Welcome back.</h1>
        <p>One account for events, classes, appointments, and your AZHottie roles.</p>
        <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= az_h($error) ?></div><?php endif; ?>
        <?php if (isset($_GET['signed_out'])): ?><div class="notice">You are signed out. Your saved interviews are ready when you return.</div><?php endif; ?>
        <form method="post" action="<?= az_h(az_url('login.php')) ?>">
            <input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>">
            <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="<?= az_h($email) ?>" maxlength="254" autocomplete="username" required autofocus></div>
            <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" maxlength="72" autocomplete="current-password" required></div>
            <button type="submit" class="button">Sign in</button>
        </form>
        <p class="auth-actions">New here? <a href="<?= az_h(az_url('signup.php')) ?>">Create an account</a></p>
    </section>
    <p class="auth-footnote">AZHottie Interview Studio · Your work stays with your account.</p>
</main>
</body>
</html>
