<?php
require_once __DIR__ . '/inc/config.php';
store_init();

if (!installato()) {
    header('Location: installa.php');
    exit;
}
if (e_admin()) {
    header('Location: dashboard.php');
    exit;
}

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errore = 'Pagina scaduta. Riprova.';
    } elseif (($attesa = attesa_residua()) > 0) {
        $errore = 'Troppi tentativi. Riprova fra ' . ceil($attesa / 60) . ' minuti.';
    } elseif (login($_POST['user'] ?? '', $_POST['password'] ?? '')) {
        header('Location: dashboard.php');
        exit;
    } else {
        $errore = 'Nome utente o password non corretti.';
    }
}
$token = csrf();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accesso gestione — <?= h(APP_NOME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body class="accesso">
  <div class="riquadro"><div class="corpo">
    <div class="marchio">
      <?= marchio_html() ?>
      <div><h1><?= h(APP_NOME) ?></h1><span style="color:var(--grigio)">Gestione magazzino</span></div>
    </div>

    <?php if ($errore): ?><div class="avviso male"><?= h($errore) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <label class="campo"><span>Nome utente</span>
        <input type="text" name="user" autocomplete="username" autofocus required></label>
      <label class="campo"><span>Password</span>
        <input type="password" name="password" autocomplete="current-password" required></label>
      <button class="bottone luce" type="submit" style="width:100%;justify-content:center">Entra</button>
    </form>

    <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
      Per prendere o riportare attrezzatura non serve entrare: <a href="index.php">vai all'area soci</a>.
    </p>
  </div></div>
</body>
</html>
