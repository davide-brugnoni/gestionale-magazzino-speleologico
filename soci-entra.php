<?php
require_once __DIR__ . '/inc/config.php';
store_init();

if (!installato()) {
    header('Location: installa.php');
    exit;
}
// Questa pagina esiste solo quando il gruppo usa gli account
// personali: con il codice condiviso non c'e' niente da fare qui.
if (accesso_soci() !== 'account') {
    header('Location: index.php');
    exit;
}
if (account_socio_autorizzato()) {
    header('Location: index.php');
    exit;
}

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errore = 'Pagina scaduta. Riprova.';
    } elseif (($attesa = attesa_residua()) > 0) {
        $errore = 'Troppi tentativi. Riprova fra ' . ceil($attesa / 60) . ' minuti.';
    } elseif (account_socio_login($_POST['email'] ?? '', $_POST['password'] ?? '')) {
        header('Location: index.php');
        exit;
    } else {
        // volutamente generico: non deve far capire se un'email non
        // esiste, e' ancora in attesa di approvazione, non ha ancora
        // confermato l'email, o e' bloccata
        $errore = 'Email o password non corretti, oppure l\'account e\' ancora in attesa di approvazione o di conferma email.';
    }
}
$token = csrf();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accesso soci - <?= h(APP_NOME) ?></title>
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
      <div><h1><?= h(APP_NOME) ?></h1><span style="color:var(--grigio)">Area soci</span></div>
    </div>

    <?php if ($errore): ?><div class="avviso male"><?= h($errore) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <label class="campo"><span>Email</span>
        <input type="email" name="email" autocomplete="email" autofocus required></label>
      <label class="campo"><span>Password</span>
        <input type="password" name="password" autocomplete="current-password" required></label>
      <button class="bottone luce" type="submit" style="width:100%;justify-content:center">Entra</button>
    </form>

    <p style="margin:14px 0 0;font-size:13.5px;color:var(--grigio)">
      <a href="soci-password-dimenticata.php">Password dimenticata?</a>
    </p>
    <p style="margin:8px 0 0;font-size:13.5px;color:var(--grigio)">
      Non hai un account? <a href="soci-registrati.php">Registrati</a>.
    </p>
    <p style="margin:8px 0 0;font-size:13.5px;color:var(--grigio)">
      Gestisci il magazzino? <a href="login.php">Entra con il tuo account</a>.
    </p>
  </div></div>
<script src="assets/mostra-password.js?v=<?= h(APP_VERSIONE) ?>"></script>
</body>
</html>
