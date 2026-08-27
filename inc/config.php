<?php
// ---------------------------------------------------------------
// Gestionale magazzino attrezzatura speleo
// Configurazione generale
//
// Quasi tutto si imposta dalla procedura guidata e poi dalla
// dashboard: qui restano solo le scelte tecniche.
// ---------------------------------------------------------------

// Cartelle dei dati e delle foto. Devono essere scrivibili dal web server.
// Per maggiore sicurezza puoi spostare i dati fuori dalla cartella pubblica:
//   define('DATA_DIR', dirname(__DIR__, 2) . '/magazzino-dati');
define('DATA_DIR', dirname(__DIR__) . '/data');
define('FOTO_DIR', dirname(__DIR__) . '/foto');

// Foto degli articoli
define('FOTO_MAX_BYTE', 12 * 1024 * 1024);   // peso massimo del file caricato
define('FOTO_LATO', 640);                     // lato lungo dell'immagine salvata

// Fuso orario
date_default_timezone_set('Europe/Rome');

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
