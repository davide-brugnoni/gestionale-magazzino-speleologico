<?php
// ---------------------------------------------------------------
// Cambio obbligato della password.
//
// Ci si finisce quando il Superadmin ha reimpostato la password di un
// amministratore: la provvisoria serve a entrare una volta sola, poi
// se ne sceglie una propria che il Superadmin non conosce.
//
// Questa pagina NON chiama richiedi_admin(): sarebbe lei stessa la
// destinazione del rimbalzo, e si girerebbe in tondo.
// ---------------------------------------------------------------
require_once __DIR__ . '/inc/config.php';
store_init();

if (!installato()) {
    header('Location: installa.php');
    exit;
}
if (!e_admin()) {
    header('Location: login.php');
    exit;
}
if (!deve_cambiare_password()) {
    header('Location: dashboard.php');
    exit;
}

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errore = 'Pagina scaduta. Riprova.';
    } elseif (($_POST['nuova'] ?? '') !== ($_POST['nuova2'] ?? '')) {
        $errore = 'Le due password non coincidono.';
    } else {
        $esito = utente_cambia_password(
            (string)($_SESSION['utente']['id'] ?? ''),
            (string)($_POST['attuale'] ?? ''),
            (string)($_POST['nuova'] ?? '')
        );
        if ($esito['ok']) {
            // l'obbligo si spegne anche qui: senza questo si tornerebbe
            // su questa pagina a ogni caricamento
            unset($_SESSION['cambio_password']);
            header('Location: dashboard.php');
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
<title>Cambia la password - <?= h(APP_NOME) ?></title>
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
      <div><h1><?= h(APP_NOME) ?></h1><span style="color:var(--grigio)">Gestione magazzino</span></div>
    </div>

    <p style="margin:0 0 16px;font-size:13.5px;color:var(--grigio)">
      La tua password e' stata reimpostata dal Superadmin. Quella che hai
      usato per entrare vale solo adesso: scegline una tua per andare avanti.
    </p>

    <?php if ($errore): ?><div class="avviso male"><?= h($errore) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="user" value="<?= h($_SESSION['utente']['user']) ?>" autocomplete="username">
      <label class="campo"><span>Password provvisoria</span>
        <input type="password" name="attuale" id="cp-attuale" autocomplete="current-password" autofocus required></label>
      <label class="campo"><span>Nuova password</span>
        <input type="password" name="nuova" id="cp-nuova" autocomplete="new-password" required></label>
      <label class="campo"><span>Ripeti la nuova password</span>
        <input type="password" name="nuova2" id="cp-nuova2" autocomplete="new-password" required></label>
      <ul class="regole-pass" id="cp-esito"></ul>
      <button class="bottone luce" type="submit" id="cp-invia" style="width:100%;justify-content:center">Cambia password</button>
    </form>

    <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
      Non hai la password provvisoria? <a href="logout.php">Esci</a> e chiedila al Superadmin.
    </p>
  </div></div>

<script src="assets/mostra-password.js?v=<?= h(APP_VERSIONE) ?>"></script>
<script src="assets/password.js?v=<?= h(APP_VERSIONE) ?>"></script>
<script>
  // il bottone si blocca solo adesso: senza JavaScript il modulo resta usabile
  // e a fare da rete c'e' password_valida() lato server
  window.ControlloPassword.collega({
    pass:     document.getElementById('cp-nuova'),
    conferma: document.getElementById('cp-nuova2'),
    esito:    document.getElementById('cp-esito'),
    bottone:  document.getElementById('cp-invia')
  });
</script>
</body>
</html>
