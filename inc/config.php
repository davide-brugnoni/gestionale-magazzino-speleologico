<?php
// ---------------------------------------------------------------
// Gestionale magazzino attrezzatura speleo
// Configurazione generale
//
// Quasi tutto si imposta dalla procedura guidata e poi dalla
// dashboard: qui restano solo le scelte tecniche.
//
// NON modificare questo file: al prossimo aggiornamento verrebbe
// sovrascritto. Copia inc/config-locale.esempio.php in
// inc/config-locale.php e scrivi li' le tue righe: quel file non
// viene mai toccato.
// ---------------------------------------------------------------

// Le tue impostazioni, se ci sono. Vengono lette per prime, cosi'
// le define() qui sotto lasciano stare quello che hai gia' scelto.
if (is_file(__DIR__ . '/config-locale.php')) {
    require_once __DIR__ . '/config-locale.php';
}

// Cartelle dei dati e delle foto. Devono essere scrivibili dal web server.
// Per maggiore sicurezza puoi spostare i dati fuori dalla cartella pubblica,
// scrivendo in inc/config-locale.php:
//   define('DATA_DIR', dirname(__DIR__, 2) . '/magazzino-dati');
defined('DATA_DIR') || define('DATA_DIR', dirname(__DIR__) . '/data');
defined('FOTO_DIR') || define('FOTO_DIR', dirname(__DIR__) . '/foto');

// Foto degli articoli
defined('FOTO_MAX_BYTE') || define('FOTO_MAX_BYTE', 12 * 1024 * 1024);  // peso massimo del file caricato
defined('FOTO_LATO')     || define('FOTO_LATO', 640);                    // lato lungo dell'immagine salvata

// Fuso orario
defined('APP_FUSO') || define('APP_FUSO', 'Europe/Rome');
date_default_timezone_set(APP_FUSO);

// Da dove si controlla se e' uscita una versione nuova
defined('APP_REPO') || define('APP_REPO', 'davide-brugnoni/gestionale-magazzino-speleologico');

// ---------------------------------------------------------------
// Versione dell'applicativo, letta da versione.json.
// Il file si legge, non si include: dentro non deve girare codice.
// ---------------------------------------------------------------

function versione_pacchetto(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = ['versione' => '0.0.0', 'data' => '', 'php_minimo' => '7.4', 'novita' => []];
        $file  = dirname(__DIR__) . '/versione.json';
        if (is_file($file)) {
            $letto = json_decode((string)file_get_contents($file), true);
            if (is_array($letto)) {
                $cache = array_merge($cache, $letto);
            }
        }
    }
    return $cache;
}

define('APP_VERSIONE', (string)versione_pacchetto()['versione']);

// La connessione e' cifrata?
function in_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

// Sessione
if (session_status() === PHP_SESSION_NONE) {
    session_name('BVMAG');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                 // il cookie non e' leggibile dal JavaScript
        'secure'   => in_https(),           // in HTTPS non viaggia mai in chiaro
        'samesite' => 'Lax',                // niente invio da siti terzi
    ]);
    session_start();
}

// Intestazioni di sicurezza su ogni pagina
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');          // il sito non si puo' incorniciare altrove
    header('Referrer-Policy: same-origin');
    if (in_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/impostazioni.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/migrazioni.php';

// Valori che dipendono dalle impostazioni scelte in installazione
define('APP_NOME',        impostazione('nome_gruppo', 'Gruppo Speleo'));
define('APP_SOTTOTITOLO', impostazione('sottotitolo', 'Gestionale magazzino'));
define('APP_LOGO',        impostazione('logo', ''));
define('GIORNI_RITARDO',  max(1, (int)impostazione('giorni_ritardo', 14)));
define('CODICE_GIORNI',   max(1, (int)impostazione('codice_giorni', 90)));

/** Titolo lungo, per il tag title e le intestazioni */
function titolo_app(): string
{
    return APP_SOTTOTITOLO . ' ' . APP_NOME;
}

/** Logo del gruppo, oppure il pallino di serie */
function marchio_html(): string
{
    $logo = APP_LOGO;
    if ($logo !== '' && is_file(FOTO_DIR . '/' . $logo)) {
        return '<img class="logo" src="foto/' . rawurlencode($logo) . '" alt="">';
    }
    return '<span class="faro" aria-hidden="true"></span>';
}

// ---------------------------------------------------------------
// Aspetto: i colori scelti dalla dashboard e il foglio di stile
// personale. Vanno messi nell'<head>, dopo assets/style.css.
// ---------------------------------------------------------------

/** Un colore valido in forma #rrggbb, oppure stringa vuota. */
function colore_valido($valore): string
{
    $v = strtolower(trim((string)$valore));
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : '';
}

/** Schiarisce un colore verso il bianco: 0 = uguale, 1 = bianco. */
function colore_schiarito(string $colore, float $quanto): string
{
    $r = hexdec(substr($colore, 1, 2));
    $g = hexdec(substr($colore, 3, 2));
    $b = hexdec(substr($colore, 5, 2));
    $m = function (int $c) use ($quanto) {
        return (int)round($c + (255 - $c) * $quanto);
    };
    return sprintf('#%02x%02x%02x', $m($r), $m($g), $m($b));
}

/**
 * Le variabili di colore scelte dal gruppo.
 * Esce solo quello che e' stato impostato davvero: se non si tocca
 * niente, vale quanto sta in assets/style.css.
 */
function stile_personalizzato_html(): string
{
    $regole = [];

    $luce = colore_valido(impostazione('colore_luce', ''));
    if ($luce !== '') {
        // lo sfondo tenue si ricava dal colore scelto: un colore solo da scegliere
        $regole[] = '--lampada:' . $luce;
        $regole[] = '--lampada-sf:' . colore_schiarito($luce, 0.88);
    }
    $inchiostro = colore_valido(impostazione('colore_inchiostro', ''));
    if ($inchiostro !== '') {
        $regole[] = '--ink:' . $inchiostro;
        $regole[] = '--ink-2:' . colore_schiarito($inchiostro, 0.12);
    }
    $fondo = colore_valido(impostazione('colore_fondo', ''));
    if ($fondo !== '') {
        $regole[] = '--fondo:' . $fondo;
    }
    // attenzione: vuoto non e' zero. Senza questo controllo un raggio
    // mai impostato uscirebbe come 0px e squadrerebbe tutti gli angoli.
    $raggio = trim((string)impostazione('raggio', ''));
    if ($raggio !== '' && ctype_digit($raggio) && (int)$raggio <= 24) {
        $regole[] = '--raggio:' . (int)$raggio . 'px';
    }

    if (!$regole) {
        return '';
    }
    return '<style>:root{' . implode(';', $regole) . '}</style>';
}

/** Il foglio di stile personale, se e' stato creato. */
function stile_locale_html(): string
{
    if (!is_file(dirname(__DIR__) . '/assets/stile-locale.css')) {
        return '';
    }
    return '<link rel="stylesheet" href="assets/stile-locale.css?v=' . rawurlencode(APP_VERSIONE) . '">';
}

/** Le due cose insieme, nell'ordine giusto. */
function aspetto_html(): string
{
    return stile_personalizzato_html() . stile_locale_html();
}
