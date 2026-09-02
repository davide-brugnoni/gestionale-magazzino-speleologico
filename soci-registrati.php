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
if (account_socio_autorizzato()) {
    header('Location: index.php');
    exit;
}

$errore = '';
$fatto  = false;
$nome   = '';
$email  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errore = 'Pagina scaduta. Riprova.';
    } elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
        $errore = 'Le due password non coincidono.';
    } else {
        $esito = account_socio_crea($nome, $email, (string)($_POST['password'] ?? ''));
        if ($esito['ok']) {
            $fatto = true;
        } else {
            $errore = $esito['errore'];
        }
    }
}
$token = csrf();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registrati - <?= h(APP_NOME) ?></title>
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
      <div><h1><?= h(APP_NOME) ?></h1><span style="color:var(--grigio)">Registrazione soci</span></div>
    </div>

    <?php if ($fatto): ?>
      <div class="avviso ok">Registrazione ricevuta. Un amministratore deve approvarla prima che tu possa entrare: ti avvisera' lui.</div>
      <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
        <a href="soci-entra.php">Torna alla pagina di accesso</a>.
      </p>
    <?php else: ?>

    <?php if ($errore): ?><div class="avviso male"><?= h($errore) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <label class="campo"><span>Nome e cognome</span>
        <input type="text" name="nome" value="<?= h($nome) ?>" autofocus required></label>
      <label class="campo"><span>Email</span>
        <input type="email" name="email" value="<?= h($email) ?>" autocomplete="email" required></label>
      <label class="campo"><span>Password</span>
        <input type="password" name="password" id="r-pass" autocomplete="new-password" required></label>
      <label class="campo"><span>Ripeti la password</span>
        <input type="password" name="password2" id="r-pass2" autocomplete="new-password" required></label>
      <ul class="regole-pass" id="r-esito"></ul>
      <button class="bottone luce" type="submit" id="r-invia" style="width:100%;justify-content:center">Registrati</button>
    </form>

    <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
      Hai gia' un account? <a href="soci-entra.php">Entra</a>.
    </p>
    <?php endif; ?>
  </div></div>
<script src="assets/mostra-password.js?v=<?= h(APP_VERSIONE) ?>"></script>
<script src="assets/password.js?v=<?= h(APP_VERSIONE) ?>"></script>
<script>
  window.ControlloPassword.collega({
    pass:     document.getElementById('r-pass'),
    conferma: document.getElementById('r-pass2'),
    esito:    document.getElementById('r-esito'),
    bottone:  document.getElementById('r-invia')
  });
</script>
</body>
</html>
