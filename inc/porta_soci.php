<?php
// ---------------------------------------------------------------
// Chiede il codice del gruppo prima di aprire l'area soci.
// Viene incluso da index.php solo se CODICE_SOCI non e' vuoto.
// ---------------------------------------------------------------

$errore = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['codice'])) {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errore = 'Pagina scaduta. Riprova.';
    } elseif (($attesa = attesa_residua()) > 0) {
        $errore = 'Troppi tentativi. Riprova fra ' . ceil($attesa / 60) . ' minuti.';
    } elseif (soci_entra($_POST['codice'], !empty($_POST['ricorda']))) {
        header('Location: index.php');
        exit;
    } else {
        $errore = 'Codice non corretto.';
    }
}
$token = csrf();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Area soci — <?= h(APP_NOME) ?></title>
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

    <div class="avviso">Per prendere o riportare attrezzatura serve il codice del gruppo. Chiedilo a chi gestisce il magazzino.</div>
    <?php if ($errore): ?><div class="avviso male"><?= h($errore) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <label class="campo"><span>Codice del gruppo</span>
        <input type="password" name="codice" autocomplete="off" autofocus required></label>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:16px">
        <input type="checkbox" name="ricorda" value="1" checked style="width:auto">
        ricorda questo dispositivo per <?= (int)CODICE_GIORNI ?> giorni</label>
      <button class="bottone luce" type="submit" style="width:100%;justify-content:center">Entra</button>
    </form>

    <p style="margin:18px 0 0;font-size:13.5px;color:var(--grigio)">
      Gestisci il magazzino? <a href="login.php">Entra con il tuo account</a>.
    </p>
  </div></div>
<script src="assets/mostra-password.js?v=<?= h(APP_VERSIONE) ?>"></script>
</body>
</html>
