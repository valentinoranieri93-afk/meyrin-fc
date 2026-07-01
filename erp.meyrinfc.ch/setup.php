<?php
/**
 * setup.php — Configuration initiale du mot de passe admin.
 * À supprimer du serveur après la première utilisation.
 */
define('ERP_ROOT', true);
require_once __DIR__ . '/config.php';

$users_file = DATA_DIR . 'users.json';
$users = json_decode(file_get_contents($users_file), true) ?: [];

$admin = null;
foreach ($users as &$u) {
    if ($u['login'] === 'admin') { $admin = &$u; break; }
}

if (!$admin) {
    die('Utilisateur admin introuvable dans users.json.');
}
if ($admin['password_hash'] !== '__SETUP_REQUIRED__') {
    die('Setup déjà effectué. Supprimez ce fichier du serveur.');
}

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = $_POST['password']  ?? '';
    $pw2 = $_POST['password2'] ?? '';
    if (strlen($pw) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($pw !== $pw2) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        $admin['password_hash'] = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
        file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup ERP · Meyrin FC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Inter",sans-serif;background:#F6F4ED;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:#fff;border:1px solid #E9E6DC;border-radius:16px;padding:40px;width:100%;max-width:420px;box-shadow:0 6px 24px rgba(20,18,10,.08)}
.logo{width:48px;height:48px;background:#FFD000;border-radius:12px;display:flex;align-items:center;justify-content:center;font-family:"Sora";font-weight:800;font-size:22px;color:#15140F;margin:0 auto 20px}
h1{font-family:"Sora";font-size:20px;font-weight:800;text-align:center;color:#15140F;margin-bottom:6px}
.sub{text-align:center;font-size:13px;color:#6E6C61;margin-bottom:28px}
label{display:block;font-size:13px;font-weight:600;color:#2C2A21;margin-bottom:6px}
input{width:100%;padding:10px 14px;border:1px solid #E9E6DC;border-radius:10px;font-size:14px;background:#F6F4ED;color:#1A1915;outline:none;transition:border .15s}
input:focus{border-color:#15140F;background:#fff}
.field{margin-bottom:16px}
.btn{width:100%;padding:11px;background:#15140F;color:#FFD000;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:"Sora";margin-top:8px;letter-spacing:.2px}
.btn:hover{background:#2C2A21}
.error{background:#FBE7E4;color:#D8463A;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
.success{background:#E4F4EA;color:#1F9D5B;border-radius:8px;padding:16px;font-size:13px;text-align:center;line-height:1.6}
.warning{background:#FFF3C4;color:#7A6000;border-radius:8px;padding:10px 14px;font-size:12px;margin-top:16px;text-align:center}
</style>
</head>
<body>
<div class="card">
  <div class="logo">M</div>
  <h1>Configuration initiale</h1>
  <p class="sub">Définissez le mot de passe du compte admin</p>

  <?php if ($done): ?>
    <div class="success">
      Mot de passe configuré avec succès.<br>
      <strong>Supprimez ce fichier (<code>setup.php</code>) du serveur maintenant.</strong><br><br>
      <a href="<?= ERP_URL ?>" style="color:#1F9D5B;font-weight:600">Accéder à l'ERP &rarr;</a>
    </div>
  <?php else: ?>
    <?php if ($error): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif ?>
    <form method="POST">
      <div class="field">
        <label>Login admin</label>
        <input type="text" value="admin" disabled>
      </div>
      <div class="field">
        <label>Mot de passe</label>
        <input type="password" name="password" placeholder="Min. 8 caractères" required autofocus>
      </div>
      <div class="field">
        <label>Confirmer le mot de passe</label>
        <input type="password" name="password2" placeholder="Répétez le mot de passe" required>
      </div>
      <button type="submit" class="btn">Configurer</button>
    </form>
    <div class="warning">Supprimez ce fichier du serveur après configuration.</div>
  <?php endif ?>
</div>
</body>
</html>
