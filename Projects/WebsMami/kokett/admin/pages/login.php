<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (auth_login($password)) {
        redirect('/admin/');
    }
    $error = 'Contraseña incorrecta';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin — <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-login-page">
<div class="login-box">
  <h1><?= e(SITE_NAME) ?> Admin</h1>
  <?php if (isset($error)): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="POST">
    <input type="password" name="password" placeholder="Contraseña" required autofocus>
    <button type="submit">Entrar</button>
  </form>
</div>
</body>
</html>
