<?php
require_once __DIR__ . '/inc/config.php';
store_init();

if (!installato()) {
    header('Location: installa.php');
    exit;
}
if (accesso_soci() !== 'account') {
    header('Location: index.php');
    exit;
}

$id    = trim((string)($_GET['id'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

if ($id === '' || $token === '') {
    $esito = ['ok' => false, 'errore' => 'Link incompleto.'];
} else {
    $esito = account_socio_verifica_email($id, $token);
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conferma email - <?= h(APP_NOME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= h(APP_VERSIONE) ?>">
<?= aspetto_html() ?>
</head>
<body class="accesso">
  <div class="riquadro"><div class="corpo">
    <div class="marchio">
      <?= marchio_html() ?>
      <div><h1><?= h(APP_NOME) ?></h1><span style="color:var(--grigio)">Conferma email</span></div>
    </div>

    <?php if ($esito['ok']): ?>
      <div class="avviso ok">
        <?= !empty($esito['gia_fatto']) ? 'Questo indirizzo era gia\' confermato.' : 'Indirizzo confermato.' ?>
        Se un amministratore ha gia' approvato la registrazione, puoi entrare.
      </div>
    <?php else: ?>
      <div class="avviso male"><?= h($esito['errore']) ?></div>
    <?php endif; ?>

    <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
      <a href="soci-entra.php">Vai alla pagina di accesso</a>.
    </p>
  </div></div>
</body>
</html>
