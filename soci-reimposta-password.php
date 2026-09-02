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

$id  = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$tok = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

$errore = ($id === '' || $tok === '') ? 'Link incompleto.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errore === '') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errore = 'Pagina scaduta. Riprova.';
    } elseif (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
        $errore = 'Le due password non coincidono.';
    } else {
        $nuova = (string)($_POST['password'] ?? '');
        $esito = account_socio_reset_password($id, $tok, $nuova);
        if ($esito['ok']) {
            // Il reset ha appena verificato che chi scrive controlla
            // quella casella: si entra subito, senza chiedere di nuovo
            // l'email e la password appena scelte.
            $s = socio_leggi($id);
            if ($s && account_socio_login($s['email'], $nuova)) {
                header('Location: index.php');
                exit;
            }
            header('Location: soci-entra.php');
            exit;
        }
        $errore = $esito['errore'];
    }
}
$token = csrf();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reimposta la password - <?= h(APP_NOME) ?></title>
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
      <div><h1><?= h(APP_NOME) ?></h1><span style="color:var(--grigio)">Reimposta la password</span></div>
    </div>

    <?php if ($errore): ?><div class="avviso male"><?= h($errore) ?></div><?php endif; ?>

    <?php if ($id !== '' && $tok !== ''): ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="id" value="<?= h($id) ?>">
      <input type="hidden" name="token" value="<?= h($tok) ?>">
      <label class="campo"><span>Nuova password</span>
        <input type="password" name="password" id="rp-pass" autocomplete="new-password" autofocus required></label>
      <label class="campo"><span>Ripeti la password</span>
        <input type="password" name="password2" id="rp-pass2" autocomplete="new-password" required></label>
      <ul class="regole-pass" id="rp-esito"></ul>
      <button class="bottone luce" type="submit" id="rp-invia" style="width:100%;justify-content:center">Reimposta e entra</button>
    </form>
    <?php else: ?>
    <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
      <a href="soci-password-dimenticata.php">Richiedi un nuovo link</a>.
    </p>
    <?php endif; ?>
  </div></div>
<script src="assets/mostra-password.js?v=<?= h(APP_VERSIONE) ?>"></script>
<script src="assets/password.js?v=<?= h(APP_VERSIONE) ?>"></script>
<script>
  window.ControlloPassword.collega({
    pass:     document.getElementById('rp-pass'),
    conferma: document.getElementById('rp-pass2'),
    esito:    document.getElementById('rp-esito'),
    bottone:  document.getElementById('rp-invia')
  });
</script>
</body>
</html>
