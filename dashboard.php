<?php
require_once __DIR__ . '/inc/config.php';
store_init();
if (!installato()) {
    header('Location: installa.php');
    exit;
}
richiedi_admin();
$token = csrf();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestione — <?= h(titolo_app()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= h(APP_VERSIONE) ?>">
<?= aspetto_html() ?>
</head>
<body data-csrf="<?= h($token) ?>">

<header class="testata">
  <div class="testata-in">
    <a class="marchio" href="dashboard.php">
      <?= marchio_html() ?>
      <div>
        <h1><?= h(APP_NOME) ?></h1>
        <span>Gestione magazzino</span>
      </div>
    </a>
    <nav>
      <button class="tab att" data-vai="panoramica">Panoramica</button>
      <button class="tab" data-vai="inventario">Inventario</button>
      <button class="tab" data-vai="fuori">Non rientrato</button>
      <button class="tab" data-vai="storico">Storico</button>
      <button class="tab" data-vai="movimenti">Movimenti</button>
      <button class="tab" data-vai="accessi">Accessi</button>
      <button class="tab" data-vai="impostazioni">Impostazioni</button>
      <button class="tab" data-vai="aggiornamenti">Aggiornamenti<span class="pallino" id="badge-agg" hidden></span></button>
      <a href="index.php">Area soci</a>
      <span class="chi"><?= h($_SESSION['utente']['nome']) ?></span>
      <a href="logout.php">Esci</a>
    </nav>
  </div>
</header>

<main class="pagina">

  <!-- ============ PANORAMICA ============ -->
  <section id="sez-panoramica" class="sezione att">
    <div class="profilo-box">
      <div class="profilo-testa">
        <span>Profilo del magazzino — una colonna per articolo</span>
        <span id="profilo-nota">&nbsp;</span>
      </div>
      <div class="profilo" id="profilo"></div>
      <div class="legenda">
        <span><b style="background:#4E6A72"></b>a magazzino</span>
        <span><b style="background:var(--lampada)"></b>in prestito</span>
        <span><b style="background:var(--rosso)"></b>mancante al conteggio</span>
      </div>
    </div>

    <div class="titolo-sez">Numeri di oggi</div>
    <div class="griglia g3" id="kpi"></div>

    <div class="titolo-sez">Da recuperare</div>
    <div id="ritardi" class="griglia g2"></div>

    <div class="titolo-sez">Da comprare</div>
    <div class="riquadro tabella-scroll lista-acquisti">
      <table id="tab-comprare">
        <thead><tr><th>Articolo</th><th class="num">A magazzino</th><th class="num">Da comprare</th><th class="num">Mancanti al conteggio</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- ============ INVENTARIO ============ -->
  <section id="sez-inventario" class="sezione">
    <div class="titolo-sez">Inventario</div>
    <div class="riga-controlli">
      <input type="search" id="inv-cerca" placeholder="Cerca articolo" autocomplete="off">
      <select id="inv-cat"><option value="">Tutte le categorie</option></select>
      <select id="inv-ordine">
        <option value="cat">Ordina per categoria</option>
        <option value="scorta">Prima le scorte basse</option>
        <option value="fuori">Prima quelli piu' in giro</option>
      </select>
      <span class="spinta"></span>
      <a class="bottone chiaro" href="export.php?cosa=inventario">Scarica CSV</a>
      <button class="bottone chiaro" id="btn-importa">Importa da foglio</button>
      <button class="bottone" id="btn-nuovo">Nuovo articolo</button>
    </div>
    <div class="riquadro tabella-scroll">
      <table id="tab-inventario">
        <thead><tr>
          <th>Articolo</th><th>Scorta</th><th class="num">Totale</th><th class="num">Fuori</th>
          <th class="num">Disponibili</th><th style="width:250px"></th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- ============ NON RIENTRATO ============ -->
  <section id="sez-fuori" class="sezione">
    <div class="titolo-sez">Attrezzatura non rientrata</div>
    <div class="riga-controlli">
      <input type="search" id="fuori-cerca" placeholder="Cerca per persona, grotta o articolo" autocomplete="off">
      <label style="font-size:13.5px;display:flex;align-items:center;gap:6px;color:var(--grigio)">
        <input type="checkbox" id="fuori-solo-ritardo" style="width:auto"> solo in ritardo
      </label>
      <span class="spinta"></span>
      <a class="bottone chiaro" href="export.php?cosa=aperti">Scarica CSV</a>
    </div>
    <div id="lista-fuori" class="griglia g2"></div>
  </section>

  <!-- ============ STORICO ============ -->
  <section id="sez-storico" class="sezione">
    <div class="titolo-sez">Storico dei prelievi</div>
    <div class="riga-controlli">
      <input type="search" id="st-cerca" placeholder="Cerca per persona, grotta o articolo" autocomplete="off">
      <select id="st-stato">
        <option value="">Tutti gli stati</option>
        <option value="aperto">Aperti</option>
        <option value="parziale">Rientrati in parte</option>
        <option value="chiuso">Chiusi</option>
      </select>
      <input type="date" id="st-dal" style="max-width:170px" title="Dalla data di uscita">
      <input type="date" id="st-al" style="max-width:170px" title="Fino alla data di uscita">
      <span class="spinta"></span>
      <button class="bottone chiaro" id="btn-pulisci-chiusi">Elimina i chiusi&hellip;</button>
      <a class="bottone chiaro" id="csv-storico" href="export.php?cosa=storico">Scarica CSV</a>
    </div>

    <div class="avviso">
      <strong>Materiale prestato nel periodo</strong>
      <span id="st-periodo" class="meta" style="text-transform:none;letter-spacing:0"></span>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <a class="bottone chiaro mini" id="csv-prestato" href="export.php?cosa=prestato">CSV dettaglio</a>
        <a class="bottone chiaro mini" id="csv-prestato-riep" href="export.php?cosa=prestato_riepilogo">CSV riepilogo</a>
      </div>
    </div>
    <div class="riquadro tabella-scroll">
      <table id="tab-storico">
        <thead><tr>
          <th>Uscita</th><th>Persona</th><th>Dove</th><th class="num">Pezzi</th>
          <th class="num">Fuori</th><th class="num">Persi</th><th>Stato</th><th></th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- ============ MOVIMENTI ============ -->
  <section id="sez-movimenti" class="sezione">
    <div class="titolo-sez">Movimenti di magazzino</div>
    <div class="riga-controlli">
      <input type="search" id="mv-cerca" placeholder="Cerca articolo o nota" autocomplete="off">
      <span class="spinta"></span>
      <a class="bottone chiaro" href="export.php?cosa=movimenti">Scarica CSV</a>
    </div>
    <div class="riquadro tabella-scroll">
      <table id="tab-movimenti">
        <thead><tr><th>Quando</th><th>Tipo</th><th>Articolo</th><th class="num">Variazione</th><th class="num">Giacenza</th><th>Chi</th><th>Nota</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- ============ ACCESSI ============ -->
  <section id="sez-accessi" class="sezione">
    <div class="titolo-sez">Chi puo' entrare in gestione</div>
    <div class="griglia g2" style="align-items:start">
      <div class="riquadro tabella-scroll">
        <table id="tab-utenti"><thead><tr><th>Nome</th><th>Utente</th><th></th></tr></thead><tbody></tbody></table>
      </div>
      <div class="riquadro"><div class="corpo">
        <label class="campo"><span>Nome e cognome</span><input type="text" id="u-nome"></label>
        <label class="campo"><span>Nome utente</span><input type="text" id="u-user" autocomplete="off"></label>
        <label class="campo"><span>Password</span><input type="password" id="u-pass" autocomplete="new-password"></label>
        <label class="campo"><span>Ripeti la password</span><input type="password" id="u-pass2" autocomplete="new-password"></label>
        <ul class="regole-pass" id="u-pass-esito"></ul>
        <button class="bottone" id="btn-nuovo-utente">Aggiungi amministratore</button>
      </div></div>
    </div>

    <div class="titolo-sez" style="margin-top:22px">Chi tiene aggiornato il programma</div>
    <div class="riquadro"><div class="corpo">
      <p class="meta" style="text-transform:none;letter-spacing:0;margin-top:0">
        L'avviso di nuova versione lo vedono tutti. Questo e' il nome che compare
        accanto all'avviso, cosi' gli altri sanno a chi chiedere.
      </p>
      <label class="campo"><span>Se ne occupa</span>
        <select id="i-responsabile"></select></label>
      <button class="bottone chiaro" id="btn-salva-responsabile">Salva</button>
    </div></div>
  </section>

  <!-- ============ IMPOSTAZIONI ============ -->
  <section id="sez-impostazioni" class="sezione">
    <div class="titolo-sez">Il gruppo</div>
    <div class="griglia g2" style="align-items:start">
      <div class="riquadro"><div class="corpo">
        <form id="form-impostazioni" enctype="multipart/form-data">
          <label class="campo"><span>Nome del gruppo</span><input type="text" name="nome_gruppo" id="i-nome"></label>
          <label class="campo"><span>Dicitura sopra il nome</span><input type="text" name="sottotitolo" id="i-sotto"></label>

          <div class="foto-scheda">
            <div id="i-logo-vista"></div>
            <div style="flex:1;min-width:0">
              <div class="meta" style="margin-bottom:6px">Logo del gruppo</div>
              <input type="file" name="logo" accept="image/*">
              <p class="meta" style="margin:8px 0 0;text-transform:none;letter-spacing:0">Quadrato, almeno 200 px.</p>
              <label class="riga-spunta" style="margin-top:10px"><input type="checkbox" name="togli_logo" value="1"> togli il logo attuale</label>
            </div>
          </div>

          <div class="due">
            <label class="campo"><span>Ritardo dopo (giorni)</span><input type="number" name="giorni_ritardo" id="i-ritardo" min="1" max="365"></label>
            <label class="campo"><span>Ricorda dispositivo (giorni)</span><input type="number" name="codice_giorni" id="i-giorni" min="1" max="365"></label>
          </div>

          <div class="titolo-sez" style="margin-top:8px">Area soci</div>
          <p class="meta" id="i-stato-area" style="text-transform:none;letter-spacing:0;margin-bottom:10px"></p>
          <label class="campo"><span>Nuovo codice del gruppo</span>
            <input type="text" name="codice_soci" autocomplete="off" placeholder="lascia vuoto per non cambiarlo"></label>
          <label class="riga-spunta"><input type="checkbox" name="apri_area" value="1"> apri l'area soci a chiunque abbia il link</label>
          <p class="meta" style="text-transform:none;letter-spacing:0">Cambiando il codice, i dispositivi ricordati devono reinserirlo.</p>

          <button class="bottone luce" type="button" id="btn-salva-impostazioni" style="margin-top:16px">Salva le impostazioni</button>
        </form>
      </div></div>

      <div class="riquadro"><div class="corpo">
        <div class="meta" style="margin-bottom:10px">Come si vedrà</div>
        <div class="testata" style="border-radius:var(--raggio)">
          <div class="testata-in" style="padding:14px 16px">
            <div class="marchio"><span id="i-anteprima-logo"></span>
              <div><h1 id="i-anteprima-nome"></h1><span id="i-anteprima-sotto"></span></div></div>
          </div>
        </div>
        <p class="meta" style="margin-top:16px;text-transform:none;letter-spacing:0">
          Il nome compare in cima a ogni pagina e nel titolo della scheda del browser.
        </p>

        <div class="titolo-sez" style="margin-top:22px">Aspetto</div>
        <p class="meta" style="text-transform:none;letter-spacing:0;margin-top:0">
          I colori si salvano insieme agli altri dati del gruppo: restano al loro posto
          anche quando aggiorni il programma.
        </p>
        <div class="due">
          <label class="campo"><span>Colore principale</span>
            <input type="color" name="colore_luce" id="i-col-luce"></label>
          <label class="campo"><span>Scritta dei pulsanti colorati</span>
            <input type="color" name="colore_luce_testo" id="i-col-luce-testo"></label>
        </div>
        <div class="due">
          <label class="campo"><span>Sfondo della pagina</span>
            <input type="color" name="colore_fondo" id="i-col-fondo"></label>
          <label class="campo"><span>Testo e testata</span>
            <input type="color" name="colore_inchiostro" id="i-col-ink"></label>
        </div>
        <label class="campo"><span>Angoli stondati (px)</span>
          <input type="number" name="raggio" id="i-raggio" min="0" max="24" placeholder="4"></label>
        <button class="bottone chiaro" type="button" id="btn-colori-serie" style="margin-top:4px">
          Rimetti i colori di serie</button>
      </div></div>
    </div>
  </section>

  <!-- ============ AGGIORNAMENTI ============ -->
  <section id="sez-aggiornamenti" class="sezione">
    <div class="titolo-sez">Versione installata</div>
    <div class="riquadro"><div class="corpo">
      <p style="margin-top:0">
        Qui c'e' la versione <strong id="agg-versione"><?= h(APP_VERSIONE) ?></strong>.
        <span id="agg-esito" class="meta" style="text-transform:none;letter-spacing:0"></span>
      </p>
      <div id="agg-novita"></div>
      <button class="bottone chiaro" id="btn-agg-controlla">Controlla adesso</button>
    </div></div>

    <div class="titolo-sez" style="margin-top:22px">Prima di aggiornare</div>
    <div class="griglia g2" style="align-items:start">
      <div class="riquadro"><div class="corpo">
        <div class="meta" style="margin-bottom:8px">1 — Metti al sicuro i dati</div>
        <p class="meta" style="text-transform:none;letter-spacing:0;margin-top:0">
          Uno zip con inventario, prelievi, movimenti, accessi e impostazioni.
          Le foto non ci sono: stanno in <code>foto/</code> e l'aggiornamento non le tocca.
        </p>
        <a class="bottone luce" id="btn-backup" href="api.php?azione=backup_scarica&amp;csrf=<?= h($token) ?>">
          Scarica i dati</a>
      </div></div>

      <div class="riquadro"><div class="corpo">
        <div class="meta" style="margin-bottom:8px">2 — Cosa hai modificato a mano</div>
        <div id="agg-file"></div>
      </div></div>
    </div>

    <div class="titolo-sez" style="margin-top:22px">Come si aggiorna</div>
    <div class="riquadro"><div class="corpo">
      <ol class="guida-passi">
        <li>Scarica lo zip della versione nuova da GitHub.
            <span id="agg-link-zip"></span></li>
        <li>Aprilo e carica il contenuto via FTP sopra il sito,
            <strong>tranne le cartelle <code>data/</code> e <code>foto/</code></strong>:
            li' dentro c'e' il tuo magazzino.</li>
        <li><strong>Non caricare <code>installa.php</code></strong>. Si e' cancellato da
            solo a installazione finita: rimetterlo online aprirebbe la procedura
            guidata a chiunque conosca l'indirizzo.</li>
        <li>Non sovrascrivere <code>inc/config-locale.php</code> e
            <code>assets/stile-locale.css</code>, se ci sono: sono tuoi. E nemmeno il
            <code>.htaccess</code> nella cartella principale, se ci hai messo mano:
            sono le regole del tuo server, e una sbagliata manda in errore tutto il sito.</li>
        <li>Aspetta un minuto, poi apri il sito. Molti server tengono in memoria il
            programma per qualche secondo: appena finito il caricamento potresti
            vedere ancora la versione vecchia, o un misto delle due. Passa da se'.</li>
        <li>Se il formato dei dati e' cambiato, viene adattato da solo al primo
            caricamento di pagina: non c'e' niente da fare a mano.</li>
        <li>Torna qui e ricarica: il numero di versione qui sopra deve essere cambiato.
            Se dopo qualche minuto e' ancora quello vecchio, i file non sono arrivati
            dove dovevano.</li>
      </ol>
    </div></div>
  </section>

  <div class="pie">
    <span>Dati su file JSON — nessun database</span>
    <span id="pie-aggiornato"></span>
  </div>
</main>

<!-- pannello generico -->
<div class="velo" id="velo" hidden>
  <div class="pannello" role="dialog" aria-modal="true" aria-labelledby="velo-tit">
    <header><h3 id="velo-tit"></h3><button type="button" data-chiudi aria-label="Chiudi">&times;</button></header>
    <div class="corpo" id="velo-corpo"></div>
    <footer>
      <button class="bottone chiaro" type="button" data-chiudi>Annulla</button>
      <button class="bottone luce" type="button" id="velo-ok">Conferma</button>
    </footer>
  </div>
</div>

<div id="toast" role="status" aria-live="polite"></div>

<script src="assets/password.js?v=<?= h(APP_VERSIONE) ?>"></script>
<script src="assets/dashboard.js?v=<?= h(APP_VERSIONE) ?>"></script>
</body>
</html>
