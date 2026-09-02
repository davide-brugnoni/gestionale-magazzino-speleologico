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
$email  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errore = 'Pagina scaduta. Riprova.';
    } elseif (reset_attesa_residua() > 0) {
        $errore = 'Hai gia\' chiesto un link da poco. Aspetta un momento e riprova.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Scrivi un indirizzo email valido.';
    } else {
        reset_segna_richiesta();
        $esito = account_socio_richiedi_reset($email);
        // Manda l'email fuori dalla transazione che ha generato il token:
        // e' I/O di rete, non deve girare dentro il lock dei dati.
        if (!empty($esito['trovato'])) {
            $link  = url_base() . 'soci-reimposta-password.php?id=' . rawurlencode($esito['id']) . '&token=' . rawurlencode($esito['token']);
            $corpo = "Ciao " . $esito['nome'] . ",\n\n"
                . "hai chiesto di reimpostare la password del tuo account su " . APP_NOME . ".\n"
                . "Apri questo link entro un'ora per scegliere una nuova password:\n\n"
                . $link . "\n\n"
                . "Se non sei stato tu, ignora questa email: la tua password resta quella di prima.\n";
            email_invia($esito['email'], $esito['nome'], 'Reimposta la password - ' . APP_NOME, $corpo);
        }
        $fatto = true;
    }
}
$token = csrf();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Password dimenticata - <?= h(APP_NOME) ?></title>
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
      <div><h1><?= h(APP_NOME) ?></h1><span style="color:var(--grigio)">Password dimenticata</span></div>
    </div>

    <?php if ($fatto): ?>
      <div class="avviso ok">Se quell'indirizzo ha un account attivo, ti abbiamo mandato un'email con il link per reimpostare la password. Controlla anche nello spam.</div>
      <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
        <a href="soci-entra.php">Torna alla pagina di accesso</a>.
      </p>
    <?php else: ?>

    <?php if ($errore): ?><div class="avviso male"><?= h($errore) ?></div><?php endif; ?>

    <p class="guida" style="margin-top:0">Scrivi l'email con cui ti sei registrato: se l'account e' attivo ti mandiamo un link per scegliere una nuova password.</p>

    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <label class="campo"><span>Email</span>
        <input type="email" name="email" value="<?= h($email) ?>" autocomplete="email" autofocus required></label>
      <button class="bottone luce" type="submit" style="width:100%;justify-content:center">Manda il link</button>
    </form>

    <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
      <a href="soci-entra.php">Torna alla pagina di accesso</a>.
    </p>
    <?php endif; ?>
  </div></div>
</body>
</html>
