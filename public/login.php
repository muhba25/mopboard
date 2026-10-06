<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    if (!csrf_verify($token)) {
        $error = 'Sesi form kedaluwarsa, silakan coba lagi.';
    } else {
        $pdo = Database::get();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        [$ok, $err] = attempt_login($pdo, $username, $password);
        if ($ok) {
            header('Location: index.php');
            exit;
        }
        $error = $err;
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk — MOP Board</title>
<!--
  login.css is self-contained (no dependency on app.css, which changes on
  every dashboard feature) and SRI-pinned: the browser refuses to apply it if
  its bytes don't match this hash. Regenerate after editing the file:
    openssl dgst -sha384 -binary public/css/login.css | openssl base64 -A
-->
<link rel="stylesheet" href="css/login.css"
      integrity="sha384-JiInLuVXgi0NenIPlXBjVEMnBA8lXSQyAWzzNzPJBmYUEICLJk5+WtKdJqZHndh9"
      crossorigin="anonymous">
</head>
<body>
<div class="login-wrap">
  <form class="card login-card" method="post" action="login.php" autocomplete="off">
    <div class="sidebar-brand login-brand">
      <span class="brand-mark">MOP</span>
      <div>
        <div class="brand-title">MOP Board</div>
        <div class="brand-sub">Method of Procedure</div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" autocomplete="username" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Masuk</button>
  </form>
</div>
</body>
</html>
