<?php
require_once __DIR__ . '/inc/config.php';
store_init();
if (!installato()) {
    header('Location: installa.php');
    exit;
}

// Se il gruppo ha impostato un codice d'ingresso o gli account
// personali, si passa di qui.
if (!soci_autorizzato()) {
    if (accesso_soci() === 'account') {
        header('Location: soci-entra.php');
        exit;
    }
    require __DIR__ . '/inc/porta_soci.php';
    exit;
}
if (accesso_soci() === 'account' && account_socio_deve_cambiare_password()) {
    header('Location: soci-cambia-password.php');
    exit;
}
$socioSessione = accesso_soci() === 'account' ? account_socio_sessione() : null;
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(titolo_app()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= h(APP_VERSIONE) ?>">
<?= aspetto_html() ?>
</head>
<body
  data-accesso-soci="<?= h(accesso_soci()) ?>"
  data-socio-nome="<?= h($socioSessione['nome'] ?? '') ?>"
  data-socio-email="<?= h($socioSessione['email'] ?? '') ?>">

<header class="testata">
  <div class="testata-in">
    <a class="marchio" href="index.php">
      <?= marchio_html() ?>
      <div>
        <h1><?= h(APP_NOME) ?></h1>
        <span><?= h(APP_SOTTOTITOLO) ?></span>
      </div>
    </a>
    <nav>
      <button class="tab att" data-vai="prelievo">Prendi</button>
      <button class="tab" data-vai="riconsegna">Riporta</button>
      <button class="tab" data-vai="fuori">Fuori adesso</button>
      <?php if ($socioSessione): ?>
      <span class="chi"><?= h($socioSessione['nome']) ?></span>
      <a href="soci-esce.php">Esci</a>
      <?php endif; ?>
      <a href="<?= e_admin() ? 'dashboard.php' : 'login.php' ?>">Gestione</a>
    </nav>
  </div>
</header>

<main class="pagina">

  <!-- ============ PRELIEVO ============ -->
  <section id="sez-prelievo" class="sezione att">
    <div class="griglia impianto-prelievo">

      <div>
        <div class="titolo-sez">Cosa stai prendendo</div>
        <div class="riga-controlli">
          <input type="search" id="cerca" placeholder="Cerca: corda, croll, ovali…" autocomplete="off">
          <select id="filtro-cat"><option value="">Tutte le categorie</option></select>
          <label style="font-size:13.5px;display:flex;align-items:center;gap:6px;color:var(--grigio)">
            <input type="checkbox" id="solo-disp" style="width:auto" checked> solo disponibili
          </label>
        </div>
        <div class="riquadro tabella-scroll">
          <table id="tab-catalogo">
            <thead>
              <tr>
                <th>Articolo</th>
                <th class="num">Disponibili</th>
                <th class="num">Fuori</th>
                <th style="width:120px"></th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <div class="carrello">
        <div class="titolo-sez">Il tuo prelievo</div>
        <div class="riquadro"><div class="corpo">
          <ul id="carrello-lista"></ul>
          <p class="vuoto" id="carrello-vuoto">Non hai ancora scelto niente. Aggiungi l'attrezzatura dall'elenco.</p>

          <label class="campo"><span>Nome e cognome *</span>
            <input type="text" id="p-persona" autocomplete="name" placeholder="Chi ritira il materiale"></label>
          <label class="campo"><span>Telefono o mail</span>
            <input type="text" id="p-contatto" placeholder="Per ricontattarti se serve"></label>
          <label class="campo"><span>Dove lo porti</span>
            <input type="text" id="p-destinazione" placeholder="Grotta, corso, esercitazione…"></label>
          <label class="campo"><span>Rientro previsto</span>
            <input type="date" id="p-rientro"></label>
          <label class="campo"><span>Note</span>
            <textarea id="p-note" placeholder="Stato del materiale, accordi presi, altro"></textarea></label>

          <button class="bottone luce" id="btn-preleva" style="width:100%;justify-content:center">Conferma il prelievo</button>
        </div></div>
      </div>

    </div>
  </section>

  <!-- ============ RICONSEGNA ============ -->
  <section id="sez-riconsegna" class="sezione">
    <div class="titolo-sez">Riporta il materiale</div>
    <div class="avviso">Scegli il prelievo che stai chiudendo e conta i pezzi uno per uno. Quello che non torna resta segnato come fuori.</div>
    <div class="riga-controlli">
      <input type="search" id="cerca-prestito" placeholder="Cerca per nome o per grotta" autocomplete="off">
    </div>
    <div id="lista-prestiti-rientro" class="griglia g2"></div>
  </section>

  <!-- ============ FUORI ADESSO ============ -->
  <section id="sez-fuori" class="sezione">
    <div class="titolo-sez">Attrezzatura fuori adesso</div>
    <div id="lista-fuori" class="griglia g2"></div>
  </section>

  <div class="pie">
    <span>Magazzino <?= h(APP_NOME) ?></span>
    <span id="pie-aggiornato"></span>
  </div>
</main>

<!-- pannello riconsegna -->
<div class="velo" id="velo-rientro" hidden>
  <div class="pannello" role="dialog" aria-modal="true" aria-labelledby="tit-rientro">
    <header>
      <h3 id="tit-rientro">Controllo del rientro</h3>
      <button type="button" data-chiudi aria-label="Chiudi">&times;</button>
    </header>
    <div class="corpo" id="corpo-rientro"></div>
    <footer>
      <button class="bottone chiaro" type="button" data-chiudi>Annulla</button>
      <button class="bottone luce" type="button" id="btn-conferma-rientro">Registra il rientro</button>
    </footer>
  </div>
</div>

<div id="toast" role="status" aria-live="polite"></div>

<script src="assets/public.js?v=<?= h(APP_VERSIONE) ?>"></script>
</body>
</html>
