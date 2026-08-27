<?php
// ---------------------------------------------------------------
// Avviso di nuova versione e aiuto per l'aggiornamento a mano.
//
// Cosa fa: chiede a GitHub qual e' l'ultima versione pubblicata e lo
// dice in dashboard. Nient'altro.
//
// Cosa NON fa, per scelta: non scarica il programma, non scrive
// niente nella cartella del sito, non esegue codice preso da fuori.
// Aggiornarsi da soli richiederebbe che il web server possa
// riscrivere i propri file .php, e allora qualunque falla
// diventerebbe una porta di servizio permanente. Il caricamento dei
// file lo fa una persona, via FTP, quando decide lei.
//
// Questo file viene incluso solo da api.php: le pagine dei soci non
// lo caricano nemmeno.
// ---------------------------------------------------------------

define('AGG_CACHE_SECONDI', 12 * 3600);   // ogni quanto si richiede a GitHub
define('AGG_RISPOSTA_MAX', 262144);       // tetto alla risposta scaricata

/** Gli host da cui accettiamo una risposta. Niente altro, mai. */
function agg_host_consentiti(): array
{
    $ok = ['api.github.com', 'raw.githubusercontent.com'];
    // durante le prove si puo' puntare a un finto GitHub locale: serve
    // sia la costante in inc/config-locale.php sia un indirizzo locale.
    if (defined('AGG_PROVA') && AGG_PROVA) {
        $ok[] = '127.0.0.1';
        $ok[] = 'localhost';
    }
    return $ok;
}

function agg_url_release(): string
{
    if (defined('AGG_URL_PROVA') && defined('AGG_PROVA') && AGG_PROVA) {
        return AGG_URL_PROVA;
    }
    return 'https://api.github.com/repos/' . APP_REPO . '/releases/latest';
}

/** Il versione.json di un tag: da li' arrivano novita' e PHP minimo. */
function agg_url_versione(string $tag): string
{
    if (defined('AGG_URL_VERSIONE_PROVA') && defined('AGG_PROVA') && AGG_PROVA) {
        return AGG_URL_VERSIONE_PROVA;
    }
    return 'https://raw.githubusercontent.com/' . APP_REPO . '/' . rawurlencode($tag) . '/versione.json';
}

/**
 * Una GET, e basta. Il certificato si verifica sempre: se il server
 * non ha i certificati radice si fallisce dicendolo, mai si abbassa
 * la guardia.
 */
function agg_scarica(string $url): array
{
    $pezzi = parse_url($url);
    $host  = strtolower($pezzi['host'] ?? '');
    $schema = strtolower($pezzi['scheme'] ?? '');

    if (!in_array($host, agg_host_consentiti(), true)) {
        return ['ok' => false, 'errore' => 'Indirizzo non consentito.'];
    }
    if ($schema !== 'https' && !(defined('AGG_PROVA') && AGG_PROVA && $schema === 'http')) {
        return ['ok' => false, 'errore' => 'Serve una connessione sicura.'];
    }

    $agente = 'Gestionale-Magazzino/' . APP_VERSIONE;   // GitHub lo pretende; non dice altro di noi

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => $agente,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
        ]);
        $corpo   = curl_exec($ch);
        $codice  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $problema = curl_error($ch);
        curl_close($ch);

        if ($corpo === false) {
            return ['ok' => false, 'errore' => agg_spiega_rete($problema)];
        }
        return agg_valuta_risposta($codice, (string)$corpo);
    }

    if (!ini_get('allow_url_fopen')) {
        return ['ok' => false, 'errore' => 'Questo hosting non permette al sito di collegarsi a internet. Chiedi di attivare cURL.'];
    }

    $contesto = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 20,
            'ignore_errors' => true,
            'header'        => "User-Agent: $agente\r\nAccept: application/vnd.github+json\r\n",
        ],
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ],
    ]);
    $corpo = @file_get_contents($url, false, $contesto, 0, AGG_RISPOSTA_MAX);
    if ($corpo === false) {
        $ultimo = error_get_last();
        return ['ok' => false, 'errore' => agg_spiega_rete($ultimo['message'] ?? '')];
    }
    $codice = 0;
    foreach ($http_response_header ?? [] as $riga) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $riga, $m)) {
            $codice = (int)$m[1];
        }
    }
    return agg_valuta_risposta($codice, (string)$corpo);
}

/** Trasforma un errore di rete in una frase che si capisce. */
function agg_spiega_rete(string $tecnico): string
{
    $t = strtolower($tecnico);
    if (strpos($t, 'certificat') !== false || strpos($t, 'ssl') !== false || strpos($t, 'cafile') !== false) {
        return 'Il server non riesce a verificare il certificato di GitHub: mancano i certificati radice. Segnalalo a chi gestisce l\'hosting.';
    }
    if (strpos($t, 'resolve') !== false || strpos($t, 'getaddrinfo') !== false) {
        return 'Il server non riesce a raggiungere GitHub. Forse non ha accesso a internet.';
    }
    return 'Non sono riuscito a contattare GitHub. Riprova piu\' tardi.';
}

function agg_valuta_risposta(int $codice, string $corpo): array
{
    if ($codice === 403 || $codice === 429) {
        // GitHub concede 60 richieste all'ora per indirizzo IP, e su un
        // hosting condiviso l'indirizzo e' di tutti gli inquilini.
        return ['ok' => false, 'errore' => 'GitHub ha chiesto di aspettare (troppe richieste da questo server). Riprova fra un\'ora.'];
    }
    if ($codice === 404) {
        return ['ok' => false, 'errore' => 'Non ho trovato nessuna versione pubblicata.'];
    }
    if ($codice < 200 || $codice >= 300) {
        return ['ok' => false, 'errore' => 'GitHub ha risposto con un errore (' . $codice . '). Riprova piu\' tardi.'];
    }
    if (strlen($corpo) > AGG_RISPOSTA_MAX) {
        return ['ok' => false, 'errore' => 'Risposta troppo grande.'];
    }
    $dati = json_decode($corpo, true);
    if (!is_array($dati)) {
        return ['ok' => false, 'errore' => 'Risposta di GitHub non leggibile.'];
    }
    return ['ok' => true, 'dati' => $dati];
}

// --------------------------- memoria dell'ultimo controllo -------

function agg_cache_leggi(): array
{
    $letto = store_read('aggiornamenti');
    return is_array($letto) ? $letto : [];
}

function agg_cache_scrivi(array $esito): void
{
    store_transazione(function () use ($esito) {
        store_write('aggiornamenti', $esito);
    });
}

/** Taglia e ripulisce un testo che arriva da fuori. */
function agg_testo(string $s, int $max = 200): string
{
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
    return mb_substr($s, 0, $max);
}

/**
 * C'e' una versione nuova?
 * L'esito resta in cache 12 ore: non e' una furbizia, e' un obbligo.
 */
function agg_controlla(bool $forza = false): array
{
    $locale = APP_VERSIONE;

    if (!impostazione('avvisa_aggiornamenti', true)) {
        return ['ok' => true, 'attivo' => false, 'versione_locale' => $locale];
    }

    $cache = agg_cache_leggi();
    $eta   = time() - (int)($cache['quando'] ?? 0);
    if (!$forza && $cache && $eta < AGG_CACHE_SECONDI) {
        $cache['dalla_memoria'] = true;
        return $cache;
    }

    $risposta = agg_scarica(agg_url_release());
    if (!$risposta['ok']) {
        // si tiene memoria dell'errore, ma NON si dice "sei aggiornato":
        // non saperlo e' un'altra cosa dall'essere a posto
        $esito = [
            'ok'              => false,
            'attivo'          => true,
            'quando'          => time(),
            'versione_locale' => $locale,
            'errore'          => $risposta['errore'],
        ];
        agg_cache_scrivi($esito);
        return $esito;
    }

    $dati   = $risposta['dati'];
    $tag    = agg_testo((string)($dati['tag_name'] ?? ''), 40);
    $remota = ltrim($tag, 'vV');

    // il corpo della release, come ripiego per le novita'
    $novita = [];
    foreach (preg_split('/\R/', (string)($dati['body'] ?? '')) ?: [] as $riga) {
        $riga = agg_testo(ltrim($riga, "-*# \t"), 160);
        if ($riga !== '') {
            $novita[] = $riga;
        }
        if (count($novita) >= 12) {
            break;
        }
    }

    // il versione.json del tag e' la fonte buona: novita' scritte
    // apposta e, soprattutto, il PHP che quella versione pretende
    $phpMinimo = '';
    if ($tag !== '') {
        $manifesto = agg_scarica(agg_url_versione($tag));
        if ($manifesto['ok']) {
            $m = $manifesto['dati'];
            if (!empty($m['versione'])) {
                $remota = agg_testo((string)$m['versione'], 20);
            }
            if (!empty($m['php_minimo'])) {
                $phpMinimo = agg_testo((string)$m['php_minimo'], 10);
            }
            if (!empty($m['novita']) && is_array($m['novita'])) {
                $novita = [];
                foreach (array_slice($m['novita'], 0, 12) as $riga) {
                    $riga = agg_testo((string)$riga, 160);
                    if ($riga !== '') {
                        $novita[] = $riga;
                    }
                }
            }
        }
    }

    $esito = [
        'ok'              => true,
        'attivo'          => true,
        'quando'          => time(),
        'versione_locale' => $locale,
        'versione_remota' => $remota,
        'disponibile'     => $remota !== '' && version_compare($remota, $locale, '>'),
        'novita'          => $novita,
        'php_minimo'      => $phpMinimo,
        'pubblicata_il'   => agg_testo((string)($dati['published_at'] ?? ''), 30),
        'pagina'          => 'https://github.com/' . APP_REPO . '/releases/latest',
        'zip'             => 'https://github.com/' . APP_REPO . '/archive/refs/tags/' . rawurlencode($tag) . '.zip',
    ];
    agg_cache_scrivi($esito);
    return $esito;
}

// --------------------------- chi se ne occupa --------------------
//
// Non c'e' piu' un responsabile da scegliere: degli aggiornamenti si
// occupa il Superadmin, ed e' l'unico che vede questa scheda. Il nome
// da scrivere accanto all'avviso non serve piu' a nessuno, perche'
// chi legge l'avviso e' gia' quello che deve agire.

// --------------------------- i file toccati a mano ---------------

/** I due modi di guardare lo stesso file. */
function agg_impronte(string $percorso): array
{
    $contenuto = (string)file_get_contents($percorso);
    return [
        'sha'  => hash('sha256', $contenuto),
        // stesso file con i fine riga normalizzati: un client FTP in
        // modalita' testo li riscrive, e senza questo risulterebbero
        // "modificati" tutti i file, rendendo l'elenco inutile
        'norm' => hash('sha256', str_replace(["\r\n", "\r"], "\n", $contenuto)),
    ];
}

/** File di cui non ci interessa lo stato: sono tuoi, o sono dati. */
function agg_fuori_controllo(string $rel): bool
{
    $prefissi = ['data/', 'foto/', '.git/'];
    foreach ($prefissi as $p) {
        if (strpos($rel, $p) === 0) {
            return true;
        }
    }
    return in_array($rel, [
        // questi sono tuoi per definizione: non c'e' niente da dire
        'inc/config-locale.php',
        'assets/stile-locale.css',
        // la mappa stessa non si controlla da sola
        'manifest.json',
        // si e' cancellato a installazione finita: "manca" e' giusto cosi'
        'installa.php',
    ], true);
    // il .htaccess di root NON e' qui: se lo hai modificato (per esempio
    // togliendo il commento al blocco che forza HTTPS) devi saperlo prima
    // di sovrascriverlo, perche' uno sbagliato manda in errore tutto il sito.
}

/** La mappa degli hash che viaggia nel pacchetto, se c'e'. */
function agg_manifest(): array
{
    $file = dirname(__DIR__) . '/manifest.json';
    if (!is_file($file)) {
        return [];
    }
    $letto = json_decode((string)file_get_contents($file), true);
    return isset($letto['file']) && is_array($letto['file']) ? $letto['file'] : [];
}

/**
 * Quali file di programma sono stati modificati a mano dopo
 * l'installazione. Serve a dire, prima di aggiornare: "attento, qui
 * ci hai messo mano, riportalo nei file locali".
 */
function agg_file_modificati(): array
{
    $manifest = agg_manifest();
    if (!$manifest) {
        return ['noto' => false, 'file' => []];
    }
    $radice    = dirname(__DIR__);
    $modificati = [];

    foreach ($manifest as $rel => $atteso) {
        if (agg_fuori_controllo($rel)) {
            continue;
        }
        $percorso = $radice . '/' . $rel;
        if (!is_file($percorso)) {
            $modificati[] = ['file' => $rel, 'come' => 'mancante'];
            continue;
        }
        $ora = agg_impronte($percorso);
        // basta che combaci uno dei due: cosi' i soli fine riga cambiati
        // non fanno scattare un falso allarme
        if ($ora['sha'] !== ($atteso['sha'] ?? '') && $ora['norm'] !== ($atteso['norm'] ?? '')) {
            $modificati[] = ['file' => $rel, 'come' => 'modificato'];
        }
    }

    return ['noto' => true, 'file' => $modificati];
}

/** Dove riportare la modifica, detto in modo utile. */
function agg_consiglio(string $rel): string
{
    if ($rel === 'inc/config.php') {
        return 'Riporta le tue righe in inc/config-locale.php: quel file non viene mai sovrascritto.';
    }
    if ($rel === 'assets/style.css') {
        return 'I colori si cambiano da Impostazioni > Aspetto. Per il resto usa assets/stile-locale.css.';
    }
    if ($rel === '.htaccess') {
        return 'Non sovrascriverlo: sono le regole del tuo server. Se la versione nuova ne porta di diverse, confrontale a mano.';
    }
    return 'Tieni da parte la tua copia prima di sovrascrivere questo file.';
}

// --------------------------- backup dei dati ---------------------

/**
 * Uno zip con tutti i dati del magazzino, da scaricare prima di
 * aggiornare. Le foto restano fuori: pesano molto e un aggiornamento
 * fatto come si deve non le tocca.
 * Il file nasce dentro DATA_DIR (gia' scrivibile, e protetta) e si
 * cancella appena inviato.
 */
function agg_backup_crea(): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'errore' => 'Su questo server manca l\'estensione ZIP: scarica i dati con le esportazioni CSV.'];
    }

    $nome = 'dati-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
    $temp = DATA_DIR . '/' . $nome;

    $zip = new ZipArchive();
    if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'errore' => 'Non riesco a creare il file di backup. Controlla i permessi della cartella dei dati.'];
    }

    foreach (['inventario', 'movimenti', 'utenti', 'impostazioni'] as $f) {
        $percorso = store_path($f);
        if (is_file($percorso)) {
            $zip->addFile($percorso, 'dati/' . $f . '.json');
        }
    }
    foreach (glob(prestiti_dir() . '/*.json') ?: [] as $p) {
        $zip->addFile($p, 'dati/prestiti/' . basename($p));
    }
    $zip->addFromString('LEGGIMI-BACKUP.txt', implode("\n", [
        'Backup dei dati del magazzino',
        'Gruppo:   ' . APP_NOME,
        'Versione: ' . APP_VERSIONE,
        'Fatto il: ' . date('d/m/Y H:i'),
        '',
        'Per rimettere a posto i dati: copia il contenuto di dati/ dentro',
        'la cartella data/ del sito, sovrascrivendo quello che c\'e\'.',
        'Le foto non sono in questo file: stanno in foto/ e un',
        'aggiornamento non le tocca.',
    ]) . "\n");
    $zip->close();

    if (!is_file($temp)) {
        return ['ok' => false, 'errore' => 'Il file di backup non e\' stato creato.'];
    }
    return ['ok' => true, 'file' => $temp, 'nome' => $nome];
}
