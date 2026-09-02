<?php
// ---------------------------------------------------------------
// Procedura guidata di installazione.
// Al termine questo file si cancella da solo.
// ---------------------------------------------------------------

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/importa.php';
store_init();

// A installazione fatta questa pagina non esiste piu'.
//
// Unica eccezione: la schermata di riepilogo e il pulsante che cancella
// questo file. Arrivano subito dopo l'installazione, quando chi ha
// appena finito e' gia' entrato come amministratore. Senza questa
// eccezione il riepilogo non si vedrebbe mai e installa.php resterebbe
// sul server per sempre, che e' proprio quello che si vuole evitare.
$chiusura = e_admin()
    && (!empty($_GET['fatto']) || ($_POST['azione'] ?? '') === 'pulisci');

if (installato() && !$chiusura) {
    header('Location: index.php');
    exit;
}

// --------------------------------------------------- utilita'

function permessi_ok(string $dir): bool
{
    return is_dir($dir) && is_writable($dir);
}

/**
 * L'inventario di esempio che viaggia nel pacchetto.
 * Sta in esempi/, non in data/: cosi' un aggiornamento non puo'
 * mai finire sopra il magazzino vero.
 */
function esempio_inventario(): array
{
    $file = __DIR__ . '/esempi/inventario-esempio.json';
    if (!is_file($file)) {
        return [];
    }
    $letto = json_decode((string)file_get_contents($file), true);
    return is_array($letto) ? $letto : [];
}

function sistema_permessi(): array
{
    $esito = [];
    foreach ([DATA_DIR => 0775, FOTO_DIR => 0775] as $dir => $modo) {
        if (!is_dir($dir)) {
            @mkdir($dir, $modo, true);
        }
        @chmod($dir, $modo);
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @chmod($f, 0664);
            }
        }
        $esito[basename($dir)] = is_writable($dir);
    }
    return $esito;
}

function requisiti(): array
{
    return [
        ['PHP 7.4 o successivo', PHP_VERSION_ID >= 70400, PHP_VERSION, true],
        ['Sessioni attive', session_status() === PHP_SESSION_ACTIVE, '', true],
        ['Cartella data/ scrivibile', permessi_ok(DATA_DIR), DATA_DIR, true],
        ['Cartella foto/ scrivibile', permessi_ok(FOTO_DIR), FOTO_DIR, true],
        ['Estensione GD (ridimensiona le foto)', function_exists('imagecreatefromstring'), '', false],
        ['Estensione ZIP (legge i file XLSX)', class_exists('ZipArchive'), '', false],
        ['Estensione mbstring (accenti nei CSV)', function_exists('mb_check_encoding'), '', false],
        ['Connessione HTTPS', in_https(), '', false],
    ];
}

// --------------------------------------------------- scarico del modello

if (isset($_GET['modello'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modello-inventario.csv"');
    echo csv_esempio();
    exit;
}

// --------------------------------------------------- stato del percorso

$passo   = max(1, min(5, (int)($_GET['passo'] ?? 1)));
$errore  = '';
$avviso  = '';
$bozza   = $_SESSION['installa'] ?? [];
$token   = csrf();

// --------------------------------------------------- invii dei moduli

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errore = 'Pagina scaduta. Ricarica e riprova.';
    } else {
        $azione = $_POST['azione'] ?? '';

        // ---- passo 1: requisiti
        if ($azione === 'requisiti') {
            sistema_permessi();
            $bloccanti = array_filter(requisiti(), fn($r) => $r[3] && !$r[1]);
            if ($bloccanti) {
                $errore = 'Manca ancora qualcosa di indispensabile: sistema le voci in rosso e riprova.';
            } else {
                header('Location: installa.php?passo=2');
                exit;
            }
        }

        // ---- passo 2: identita' del gruppo
        if ($azione === 'gruppo') {
            $nome = trim($_POST['nome_gruppo'] ?? '');
            if (mb_strlen($nome) < 2) {
                $errore = 'Scrivi il nome del gruppo speleo.';
            } else {
                $bozza['nome_gruppo'] = $nome;
                $bozza['sottotitolo'] = trim($_POST['sottotitolo'] ?? '') ?: 'Gestionale magazzino';

                if (!empty($_FILES['logo']['name'])) {
                    $caricato = foto_salva('logo-gruppo', $_FILES['logo']);
                    if (!$caricato['ok']) {
                        $errore = $caricato['errore'];
                    } else {
                        if (!empty($bozza['logo'])) {
                            foto_cancella($bozza['logo']);
                        }
                        $bozza['logo'] = $caricato['nome'];
                    }
                }
                if (!$errore) {
                    $_SESSION['installa'] = $bozza;
                    header('Location: installa.php?passo=3');
                    exit;
                }
            }
        }

        // ---- passo 3: accessi
        if ($azione === 'accessi') {
            $nome   = trim($_POST['admin_nome'] ?? '');
            $user   = trim($_POST['admin_user'] ?? '');
            $pass   = (string)($_POST['admin_pass'] ?? '');
            $pass2  = (string)($_POST['admin_pass2'] ?? '');
            $accessoSoci = ($_POST['accesso_soci'] ?? 'codice') === 'account' ? 'account' : 'codice';
            $codice = trim($_POST['codice_soci'] ?? '');
            $aperta = !empty($_POST['area_aperta']);

            if (mb_strlen($nome) < 3) {
                $errore = 'Scrivi nome e cognome dell\'amministratore.';
            } elseif (!filter_var($user, FILTER_VALIDATE_EMAIL)) {
                $errore = 'Scrivi un indirizzo email valido per l\'amministratore.';
            } elseif (!($regola = password_valida($pass))['ok']) {
                $errore = $regola['errore'];
            } elseif ($pass !== $pass2) {
                $errore = 'Le due password dell\'amministratore non coincidono.';
            } elseif ($accessoSoci === 'codice' && !$aperta && strlen($codice) < 4) {
                $errore = 'Il codice per i soci deve avere almeno 4 caratteri, oppure lascia l\'area aperta.';
            } else {
                $bozza['admin'] = ['nome' => $nome, 'user' => $user, 'pass' => $pass];
                $bozza['accesso_soci'] = $accessoSoci;
                $bozza['codice_soci']  = $accessoSoci === 'account' ? '' : ($aperta ? '' : $codice);
                $bozza['codice_giorni'] = max(1, (int)($_POST['codice_giorni'] ?? 90));
                $bozza['giorni_ritardo'] = max(1, (int)($_POST['giorni_ritardo'] ?? 14));
                $_SESSION['installa'] = $bozza;
                header('Location: installa.php?passo=4');
                exit;
            }
        }

        // ---- passo 4: inventario di partenza
        if ($azione === 'inventario') {
            $scelta = $_POST['scelta'] ?? 'vuoto';

            if ($scelta === 'file') {
                if (empty($_FILES['foglio']['name'])) {
                    $errore = 'Scegli il file da caricare.';
                } elseif (($_FILES['foglio']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                    $errore = 'Il caricamento non e\' riuscito. Il file supera forse il limite del server.';
                } else {
                    $righe   = leggi_tabella($_FILES['foglio']['tmp_name'], $_FILES['foglio']['name']);
                    $lettura = prepara_importazione($righe);
                    if (!$lettura['ok']) {
                        $errore = $lettura['errore'];
                    } else {
                        $bozza['import'] = $lettura;
                        $bozza['scelta_inventario'] = 'file';
                        $_SESSION['installa'] = $bozza;
                        $avviso = 'Ho letto ' . count($lettura['articoli']) . ' articoli. Controlla l\'anteprima qui sotto e conferma.';
                    }
                }
            } elseif ($scelta === 'conferma_file') {
                $bozza['scelta_inventario'] = 'file';
                $_SESSION['installa'] = $bozza;
                header('Location: installa.php?passo=5');
                exit;
            } else {
                $bozza['scelta_inventario'] = $scelta;   // vuoto | esempio
                unset($bozza['import']);
                $_SESSION['installa'] = $bozza;
                header('Location: installa.php?passo=5');
                exit;
            }
        }

        // ---- passo 5: scrittura finale
        if ($azione === 'completa') {
            if (empty($bozza['admin'])) {
                $errore = 'Sono ripartito da capo: rifai i passaggi.';
            } else {
                // 1. impostazioni
                salva_impostazioni([
                    'nome_gruppo'    => $bozza['nome_gruppo'],
                    'sottotitolo'    => $bozza['sottotitolo'],
                    'logo'           => $bozza['logo'] ?? '',
                    'accesso_soci'   => $bozza['accesso_soci'] ?? 'codice',
                    'codice_giorni'  => $bozza['codice_giorni'] ?? 90,
                    'giorni_ritardo' => $bozza['giorni_ritardo'] ?? 14,
                    'segreto'        => bin2hex(random_bytes(16)),
                    'installato_il'  => date('c'),
                    // dati gia' nel formato di questa versione: niente da migrare
                    'schema_versione' => migrazioni_bersaglio(),
                ]);
                imposta_codice_soci($bozza['codice_soci'] ?? '');

                // 2. amministratore
                $creato = utente_crea($bozza['admin']['user'], $bozza['admin']['pass'], $bozza['admin']['nome']);
                if (!$creato['ok']) {
                    $errore = $creato['errore'];
                } else {
                    // chi installa tiene le chiavi di casa: accessi,
                    // impostazioni e aggiornamenti. Poi, dalla scheda
                    // Accessi, si puo' passare la mano a qualcun altro.
                    $utenti = store_read('utenti');
                    if (!empty($utenti[0]['id'])) {
                        salva_impostazioni(['superadmin_id' => $utenti[0]['id']]);
                    }
                }

                // 3. inventario
                if (!$errore) {
                    $scelta = $bozza['scelta_inventario'] ?? 'vuoto';
                    if ($scelta === 'file' && !empty($bozza['import']['articoli'])) {
                        salva_importazione($bozza['import']['articoli'], 'sostituisci');
                    } elseif ($scelta === 'esempio') {
                        store_write('inventario', esempio_inventario());
                    } else {
                        store_write('inventario', []);
                    }

                    // 4. permessi e chiusura
                    sistema_permessi();
                    segna_installato();
                    login($bozza['admin']['user'], $bozza['admin']['pass']);
                    unset($_SESSION['installa']);
                    header('Location: installa.php?passo=5&fatto=1');
                    exit;
                }
            }
        }

        // ---- pulizia finale dei file dell'installatore
        if ($azione === 'pulisci') {
            $rimossi = [];
            foreach ([__DIR__ . '/installa.php'] as $f) {
                if (is_file($f) && @unlink($f)) {
                    $rimossi[] = basename($f);
                }
            }
            $_SESSION['rimossi'] = $rimossi;
            header('Location: dashboard.php');
            exit;
        }
    }
}

$fatto = !empty($_GET['fatto']);
if ($passo === 5 && !$fatto && empty($bozza['admin'])) {
    $passo = 1;
}

$passi = [
    1 => 'Controlli',
    2 => 'Il gruppo',
    3 => 'Accessi',
    4 => 'Inventario',
    5 => 'Fine',
];
$nomeProvvisorio = $bozza['nome_gruppo'] ?? 'il tuo gruppo';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installazione - Gestionale magazzino</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= h(APP_VERSIONE) ?>">
<?= aspetto_html() ?>
</head>
<body class="installa">

<header class="testata">
  <div class="testata-in">
    <div class="marchio">
      <span class="faro" aria-hidden="true"></span>
      <div>
        <h1>Installazione</h1>
        <span>Gestionale magazzino speleo</span>
      </div>
    </div>
  </div>
</header>

<main class="pagina stretta">

  <ol class="passi">
    <?php foreach ($passi as $n => $etichetta): ?>
      <li class="<?= $n === $passo ? 'att' : ($n < $passo ? 'fatto' : '') ?>">
        <span class="pallino"><?= $n < $passo ? '&check;' : $n ?></span><?= h($etichetta) ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($errore): ?><div class="avviso male"><?= h($errore) ?></div><?php endif; ?>
  <?php if ($avviso): ?><div class="avviso ok"><?= h($avviso) ?></div><?php endif; ?>

  <div class="riquadro"><div class="corpo">

  <?php if ($passo === 1): ?>
    <h2>Controlliamo il server</h2>
    <p class="guida">Le voci in rosso vanno sistemate prima di procedere. Le altre sono consigliate: se mancano, l'applicativo funziona lo stesso con qualche limite.</p>

    <table class="controlli">
      <?php foreach (requisiti() as [$nome, $ok, $extra, $obbligatorio]): ?>
        <tr>
          <td class="esito"><span class="palla <?= $ok ? 'si' : ($obbligatorio ? 'no' : 'forse') ?>"><?= $ok ? '&check;' : '!' ?></span></td>
          <td>
            <?= h($nome) ?>
            <?php if ($extra): ?><small><?= h($extra) ?></small><?php endif; ?>
            <?php if (!$ok && !$obbligatorio): ?>
              <small><?php
                if (strpos($nome, 'GD') !== false)    echo 'Le foto verranno salvate senza ridimensionamento.';
                elseif (strpos($nome, 'ZIP') !== false) echo 'Potrai importare solo file CSV, non XLSX.';
                elseif (strpos($nome, 'HTTPS') !== false) echo 'Attivalo prima di usare l\'applicativo: senza, le password viaggiano in chiaro.';
                else echo 'Consigliata ma non indispensabile.';
              ?></small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <form method="post" class="azioni-fondo">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="azione" value="requisiti">
      <button class="bottone luce" type="submit">Sistema i permessi e prosegui</button>
    </form>

  <?php elseif ($passo === 2): ?>
    <h2>Il tuo gruppo</h2>
    <p class="guida">Nome e logo compaiono in cima a ogni pagina e nel titolo della scheda del browser.</p>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="azione" value="gruppo">

      <label class="campo"><span>Nome del gruppo speleo *</span>
        <input type="text" name="nome_gruppo" value="<?= h($bozza['nome_gruppo'] ?? '') ?>" placeholder="Nome Gruppo" required autofocus></label>

      <label class="campo"><span>Dicitura sopra il nome</span>
        <input type="text" name="sottotitolo" value="<?= h($bozza['sottotitolo'] ?? 'Gestionale magazzino') ?>"></label>

      <label class="campo"><span>Logo (facoltativo)</span>
        <input type="file" name="logo" accept="image/*"></label>
      <p class="guida">Quadrato, almeno 200 px. Viene ridotto in automatico. Se non lo carichi resta il pallino di serie.</p>

      <?php if (!empty($bozza['logo'])): ?>
        <p class="guida">Logo caricato: <img class="mini" src="foto/<?= h(rawurlencode($bozza['logo'])) ?>" alt="" style="vertical-align:middle"></p>
      <?php endif; ?>

      <div class="azioni-fondo">
        <a class="bottone chiaro" href="installa.php?passo=1">Indietro</a>
        <button class="bottone luce" type="submit">Avanti</button>
      </div>
    </form>

  <?php elseif ($passo === 3): ?>
    <h2>Chi entra e come</h2>
    <p class="guida">Tre livelli: tu che stai installando tieni le chiavi di casa, chi gestisce il magazzino ha un account personale, tutti gli altri usano un unico codice di gruppo.</p>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="azione" value="accessi">

      <h3 class="sotto-titolo">Superadmin</h3>
      <p class="guida">Questo primo account tiene le chiavi di casa: e' l'unico
        che potra' aggiungere e revocare gli altri amministratori, reimpostare
        le loro password, cambiare le impostazioni del gruppo e il codice dei
        soci, e curare gli aggiornamenti del programma. Se un domani il compito
        passa a qualcun altro, il ruolo si consegna dalla scheda Accessi.</p>
      <label class="campo"><span>Nome e cognome *</span>
        <input type="text" name="admin_nome" value="<?= h($bozza['admin']['nome'] ?? '') ?>" required autofocus></label>
      <div class="due">
        <label class="campo"><span>Indirizzo email *</span>
          <input type="email" name="admin_user" value="<?= h($bozza['admin']['user'] ?? '') ?>" autocomplete="email" required></label>
        <label class="campo"><span>Password *</span>
          <input type="password" name="admin_pass" id="admin_pass" autocomplete="new-password" minlength="8" required></label>
      </div>
      <label class="campo"><span>Ripeti la password *</span>
        <input type="password" name="admin_pass2" id="admin_pass2" autocomplete="new-password" minlength="8" required></label>
      <ul class="regole-pass" id="admin_pass_esito"></ul>
      <p class="guida">Gli altri amministratori li aggiungi tu dopo, dalla dashboard: si occupano del magazzino, non degli accessi.</p>

      <h3 class="sotto-titolo">Tutti gli altri soci</h3>
      <label class="campo"><span>Come entrano</span>
        <select name="accesso_soci" id="ins-accesso-soci">
          <option value="codice" <?= ($bozza['accesso_soci'] ?? 'codice') === 'codice' ? 'selected' : '' ?>>Codice di gruppo condiviso</option>
          <option value="account" <?= ($bozza['accesso_soci'] ?? 'codice') === 'account' ? 'selected' : '' ?>>Account personali (email + password), con approvazione</option>
        </select>
      </label>
      <p class="guida">Puoi cambiare idea in qualsiasi momento dalla dashboard, in Impostazioni.</p>

      <div id="ins-blocco-codice">
        <label class="campo"><span>Codice del gruppo</span>
          <input type="text" name="codice_soci" value="<?= h($bozza['codice_soci'] ?? '') ?>" autocomplete="off" placeholder="una parola facile da dire a voce"></label>
        <label class="riga-spunta">
          <input type="checkbox" name="area_aperta" value="1" <?= isset($bozza['codice_soci']) && $bozza['codice_soci'] === '' ? 'checked' : '' ?>>
          lascia l'area soci aperta a chiunque abbia il link
        </label>
        <p class="guida">Il codice viene chiesto una volta per telefono. Per revocarlo a tutti basta cambiarlo dalla dashboard.</p>
      </div>
      <div id="ins-blocco-account" hidden>
        <p class="guida">Ogni socio si registra da se' con email e password: l'account resta in attesa finche' non lo approvi tu dalla dashboard.</p>
      </div>
      <script>
        (function () {
          var sel = document.getElementById('ins-accesso-soci');
          var mostra = function () {
            var account = sel.value === 'account';
            document.getElementById('ins-blocco-codice').hidden = account;
            document.getElementById('ins-blocco-account').hidden = !account;
          };
          sel.addEventListener('change', mostra);
          mostra();
        })();
      </script>

      <div class="due">
        <label class="campo"><span>Ricorda il dispositivo per (giorni)</span>
          <input type="number" name="codice_giorni" min="1" max="365" value="<?= (int)($bozza['codice_giorni'] ?? 90) ?>"></label>
        <label class="campo"><span>Prelievo in ritardo dopo (giorni)</span>
          <input type="number" name="giorni_ritardo" min="1" max="365" value="<?= (int)($bozza['giorni_ritardo'] ?? 14) ?>"></label>
      </div>

      <div class="azioni-fondo">
        <a class="bottone chiaro" href="installa.php?passo=2">Indietro</a>
        <button class="bottone luce" type="submit" id="admin_avanti">Avanti</button>
      </div>
    </form>

    <script src="assets/mostra-password.js?v=<?= h(APP_VERSIONE) ?>"></script>
    <script src="assets/password.js?v=<?= h(APP_VERSIONE) ?>"></script>
    <script>
      // il bottone si blocca solo adesso: senza JavaScript il modulo resta usabile
      // e a fare da rete c'e' password_valida() lato server
      window.ControlloPassword.collega({
        pass:     document.getElementById('admin_pass'),
        conferma: document.getElementById('admin_pass2'),
        esito:    document.getElementById('admin_pass_esito'),
        bottone:  document.getElementById('admin_avanti')
      });
    </script>

  <?php elseif ($passo === 4): ?>
    <h2>L'inventario di partenza</h2>

    <?php $lettura = $bozza['import'] ?? null; ?>

    <?php if ($lettura && $lettura['ok']): ?>
      <div class="avviso ok">
        <strong><?= count($lettura['articoli']) ?> articoli letti</strong>, per un totale di
        <?= array_sum(array_column($lettura['articoli'], 'quantita')) ?> pezzi.<br>
        Colonne riconosciute: <?= h(implode(', ', array_map('etichetta_campo', $lettura['colonne']))) ?>.
        <?php if ($lettura['ignorate']): ?><br>Non trovate, resteranno vuote: <?= h(implode(', ', array_map('etichetta_campo', $lettura['ignorate']))) ?>.<?php endif; ?>
      </div>

      <?php if ($lettura['scartate']): ?>
        <div class="avviso male"><strong><?= count($lettura['scartate']) ?> righe scartate:</strong>
          <?php foreach (array_slice($lettura['scartate'], 0, 5) as $s): ?>
            <br>riga <?= (int)$s['riga'] ?> &mdash; <?= h($s['articolo']) ?> (<?= h($s['motivo']) ?>)
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="tabella-scroll anteprima-import">
        <table>
          <thead><tr><th>Categoria</th><th>Articolo</th><th>Tipo</th><th class="num">Qta</th><th class="num">Soglia</th><th class="num">Da comprare</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($lettura['articoli'], 0, 12) as $a): ?>
            <tr>
              <td><?= h($a['categoria']) ?></td>
              <td class="nome-art"><?= h($a['articolo']) ?></td>
              <td><?= h($a['tipo']) ?></td>
              <td class="num"><?= (int)$a['quantita'] ?></td>
              <td class="num"><?= (int)$a['soglia_minima'] ?: '—' ?></td>
              <td class="num"><?= (int)$a['da_comprare'] ?: '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($lettura['articoli']) > 12): ?>
        <p class="guida">…e altri <?= count($lettura['articoli']) - 12 ?> articoli.</p>
      <?php endif; ?>

      <form method="post" class="azioni-fondo">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="azione" value="inventario">
        <input type="hidden" name="scelta" value="conferma_file">
        <a class="bottone chiaro" href="installa.php?passo=4">Carico un altro file</a>
        <button class="bottone luce" type="submit">Va bene, importa questi</button>
      </form>

    <?php else: ?>

      <p class="guida">Puoi partire da un foglio che hai gia', oppure a magazzino vuoto e inserire gli articoli a mano dalla dashboard.</p>

      <h3 class="sotto-titolo">Come deve essere fatto il foglio</h3>
      <p class="guida">Una riga per articolo. La prima riga contiene i nomi delle colonne.
        Servono almeno <strong>Articolo</strong> e <strong>Quantita</strong>; tutte le altre sono facoltative.</p>

      <div class="tabella-scroll modello-scroll">
        <table class="modello">
          <thead><tr><th>Categoria</th><th>Articolo</th><th>Tipo</th><th>Quantita</th><th>Soglia minima</th><th>Da comprare</th><th>Note</th></tr></thead>
          <tbody>
            <tr><td>Corde</td><td>Corda 10mm</td><td>40 m</td><td>2</td><td>2</td><td>0</td><td>una da lavare</td></tr>
            <tr><td>Armo</td><td>Moschettoni</td><td>Ovali lega</td><td>67</td><td>40</td><td>20</td><td></td></tr>
            <tr><td>Attrezzatura personale</td><td>Caschi</td><td></td><td>21</td><td>15</td><td>0</td><td></td></tr>
            <tr><td>Trasporto</td><td>Sacchi</td><td>Tubolare grande</td><td>8</td><td>10</td><td>2</td><td></td></tr>
          </tbody>
        </table>
      </div>

      <dl class="campi">
        <dt>Categoria</dt><dd>raggruppa il materiale: Corde, Armo, Attrezzatura personale, Trasporto, Rilievo, Vario&hellip; Se manca, finisce in &ldquo;Vario&rdquo;.</dd>
        <dt>Articolo</dt><dd>il nome del pezzo: Moschettoni, Caschi, Corda 10mm. <strong>Obbligatorio.</strong></dd>
        <dt>Tipo</dt><dd>la variante dello stesso articolo: la misura di una corda, il modello di un moschettone. Puoi lasciarlo vuoto.</dd>
        <dt>Quantita</dt><dd>quanti pezzi avete adesso in magazzino, contati. <strong>Obbligatorio</strong>, solo numeri.</dd>
        <dt>Soglia minima</dt><dd>sotto quanti pezzi vuoi essere avvisato. Zero o vuoto per non avere avvisi.</dd>
        <dt>Da comprare</dt><dd>quanti pezzi sono gia' in lista acquisti.</dd>
        <dt>Note</dt><dd>qualsiasi cosa: &ldquo;una da lavare&rdquo;, &ldquo;da tarare&rdquo;, il numero di serie.</dd>
      </dl>

      <p class="guida">Va bene sia CSV sia XLSX. Come separatore accetta punto e virgola, virgola o tabulazione,
        e riconosce le intestazioni anche scritte in altro modo: <em>Tipologia</em> al posto di Categoria,
        <em>Qta</em>, <em>Pezzi</em> o <em>Giacenza</em> al posto di Quantita, e cosi' via.
        Il vecchio formato <code>.xls</code> non si legge: aprilo e salvalo come <code>.xlsx</code> o CSV.</p>

      <p><a class="bottone chiaro" href="installa.php?modello=csv">Scarica il modello CSV gia' pronto</a></p>

      <div class="scelte">
        <form method="post" enctype="multipart/form-data" class="scelta">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="azione" value="inventario">
          <input type="hidden" name="scelta" value="file">
          <h3 class="sotto-titolo">Carica il tuo foglio</h3>
          <label class="campo"><span>File CSV o XLSX</span>
            <input type="file" name="foglio" accept=".csv,.txt,.tsv,.xlsx,.xlsm" required></label>
          <p class="guida">Prima di scrivere niente ti mostro cosa ho capito, e confermi tu.</p>
          <button class="bottone luce" type="submit">Leggi il file</button>
        </form>

        <div class="scelta">
          <h3 class="sotto-titolo">Oppure parti senza file</h3>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($token) ?>">
            <input type="hidden" name="azione" value="inventario">
            <input type="hidden" name="scelta" value="vuoto">
            <p class="guida">Magazzino vuoto: aggiungi gli articoli uno a uno dalla dashboard.</p>
            <button class="bottone chiaro" type="submit">Parti da zero</button>
          </form>

          <?php $precaricati = count(esempio_inventario()); ?>
          <?php if ($precaricati): ?>
            <form method="post" style="margin-top:18px">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="azione" value="inventario">
              <input type="hidden" name="scelta" value="esempio">
              <p class="guida">Nel pacchetto ci sono gia' <strong><?= $precaricati ?> articoli</strong> di esempio: puoi tenerli e modificarli.</p>
              <button class="bottone chiaro" type="submit">Tieni l'elenco precaricato</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="azioni-fondo">
        <a class="bottone chiaro" href="installa.php?passo=3">Indietro</a>
      </div>

    <?php endif; ?>

  <?php elseif ($passo === 5 && !$fatto): ?>
    <h2>Riepilogo</h2>
    <p class="guida">Controlla e conferma: scrivo le impostazioni, creo il tuo accesso e preparo il magazzino.</p>

    <table class="riepilogo">
      <tr><th>Gruppo</th><td><?= h($bozza['nome_gruppo']) ?></td></tr>
      <tr><th>Intestazione</th><td><?= h($bozza['sottotitolo'] . ' ' . $bozza['nome_gruppo']) ?></td></tr>
      <tr><th>Logo</th><td><?= !empty($bozza['logo']) ? 'caricato' : 'nessuno, resta il pallino di serie' ?></td></tr>
      <tr><th>Superadmin</th><td><?= h($bozza['admin']['nome']) ?> (<?= h($bozza['admin']['user']) ?>)</td></tr>
      <tr><th>Area soci</th><td><?php
        if (($bozza['accesso_soci'] ?? 'codice') === 'account') {
            echo 'account personali (email + password), con approvazione';
        } else {
            echo ($bozza['codice_soci'] ?? '') === '' ? 'aperta a chi ha il link' : 'protetta da codice, ricordato ' . (int)$bozza['codice_giorni'] . ' giorni';
        }
      ?></td></tr>
      <tr><th>Prelievo in ritardo</th><td>dopo <?= (int)$bozza['giorni_ritardo'] ?> giorni</td></tr>
      <tr><th>Inventario</th><td><?php
        $sc = $bozza['scelta_inventario'] ?? 'vuoto';
        if ($sc === 'file') echo count($bozza['import']['articoli']) . ' articoli importati dal tuo foglio';
        elseif ($sc === 'esempio') echo 'elenco precaricato mantenuto';
        else echo 'magazzino vuoto';
      ?></td></tr>
    </table>

    <form method="post" class="azioni-fondo">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="azione" value="completa">
      <a class="bottone chiaro" href="installa.php?passo=4">Indietro</a>
      <button class="bottone luce" type="submit">Installa</button>
    </form>

  <?php else: ?>
    <h2>Fatto</h2>
    <div class="avviso ok">Il gestionale e' installato e sei gia' entrato come Superadmin.</div>

    <?php $perm = sistema_permessi(); ?>
    <table class="controlli">
      <tr><td class="esito"><span class="palla si">&check;</span></td><td>Impostazioni salvate</td></tr>
      <tr><td class="esito"><span class="palla si">&check;</span></td><td>Superadmin creato: gli accessi, le impostazioni e gli aggiornamenti sono tuoi</td></tr>
      <tr><td class="esito"><span class="palla si">&check;</span></td><td>Inventario preparato</td></tr>
      <?php foreach ($perm as $cartella => $ok): ?>
        <tr><td class="esito"><span class="palla <?= $ok ? 'si' : 'no' ?>"><?= $ok ? '&check;' : '!' ?></span></td>
            <td>Permessi della cartella <?= h($cartella) ?>/ <small><?= $ok ? 'scrivibile, 0775' : 'ancora non scrivibile: sistemala via FTP' ?></small></td></tr>
      <?php endforeach; ?>
    </table>

    <h3 class="sotto-titolo">Ultimo passaggio</h3>
    <p class="guida">Per sicurezza vanno cancellati i file dell'installazione: se restassero, chiunque potrebbe rilanciare la procedura. Viene rimosso <code>installa.php</code>.</p>

    <form method="post" class="azioni-fondo">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="azione" value="pulisci">
      <button class="bottone luce" type="submit">Cancella i file e vai alla dashboard</button>
    </form>

    <p class="guida">Se la cancellazione non riesce (permessi del server), elimina i due file a mano via FTP.</p>
  <?php endif; ?>

  </div></div>

  <div class="pie">
    <span>Installazione &mdash; <?= h($nomeProvvisorio) ?></span>
    <span>Passo <?= $passo ?> di 5</span>
  </div>
</main>
</body>
</html>
