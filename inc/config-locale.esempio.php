<?php
// ---------------------------------------------------------------
// Le TUE impostazioni tecniche.
//
// Come si usa: copia questo file come  inc/config-locale.php  e
// togli il commento alle righe che ti servono.
//
// inc/config-locale.php non fa parte del pacchetto: quando aggiorni
// l'applicativo non viene mai sovrascritto. E' il posto giusto per
// ogni modifica che prima avresti fatto dentro inc/config.php.
//
// Qui dentro ci vanno solo define(): niente altro codice.
// ---------------------------------------------------------------

// --- Dove stanno i dati -----------------------------------------
// Di serie: la cartella data/ dentro il sito.
// Portarli fuori dalla cartella pubblica e' piu' sicuro, ma la
// cartella deve gia' esistere ed essere scrivibile dal web server.
// define('DATA_DIR', dirname(__DIR__, 2) . '/magazzino-dati');
// define('FOTO_DIR', dirname(__DIR__, 2) . '/magazzino-foto');

// --- Foto degli articoli ----------------------------------------
// Peso massimo del file che si puo' caricare (di serie 12 MB).
// define('FOTO_MAX_BYTE', 20 * 1024 * 1024);
// Lato lungo dell'immagine salvata, in pixel (di serie 640).
// define('FOTO_LATO', 900);

// --- Fuso orario ------------------------------------------------
// Di serie Europe/Rome.
// define('APP_FUSO', 'Europe/Zurich');
