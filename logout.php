<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
header('Cache-Control: no-store');
az_require_auth();
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        az_require_csrf();
        $_SESSION = [];
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $parameters['path'], 'secure' => $parameters['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
        az_redirect('login.php?signed_out=1');
    } catch (AzHttpError $exception) {
        $error = $exception->getMessage();
        http_response_code($exception->status);
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign out · AZHottie Interview Studio</title>
    <link rel="stylesheet" href="<?= az_h(az_url('assets/auth.css')) ?>">
</head>
<body>
<main class="auth-shell">
    <section class="auth-card">
        <p class="eyebrow">AZHottie Interview Studio</p>
        <h1>Leave the studio?</h1>
        <p>Your saved interviews and recordings will be here when you return.</p>
        <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= az_h($error) ?></div><?php endif; ?>
        <form method="post" action="<?= az_h(az_url('logout.php')) ?>">
            <input type="hidden" name="csrf" value="<?= az_h(az_csrf_token()) ?>">
            <button class="button" type="submit">Sign out</button>
            <a class="button secondary" href="<?= az_h(az_url('library.php')) ?>">Keep working</a>
        </form>
    </section>
</main>
</body>
</html>
