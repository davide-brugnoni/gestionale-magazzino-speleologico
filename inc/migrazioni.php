<?php
// ---------------------------------------------------------------
// Migrazioni dei dati fra una versione e l'altra.
//
// A cosa servono: quando si aggiorna l'applicativo caricando i file
// via FTP, i dati sul server restano nel formato vecchio. Al primo
// caricamento di pagina con il codice nuovo, queste funzioni li
// portano al formato nuovo da sole, senza che nessuno debba mettere
// mano ai file JSON.
//
// Il numero raggiunto sta in impostazioni.json, chiave
// 'schema_versione'. Le migrazioni girano in ordine, una sola volta,
// e il numero avanza a ogni passo riuscito: se il server si spegne a
// meta', alla ripresa si riparte da dove si era arrivati.
//
// ---------------------------------------------------------------
// Regole per scriverne di nuove. Vanno rispettate tutte.
//
// 1. SOLO AGGIUNTE. Mai togliere o rinominare un campo che il codice
//    della versione precedente legge: se si torna indietro con il
//    codice, i dati devono restare utilizzabili.
// 2. RIPETIBILI. Devono poter girare due volte senza fare danno.
//    Si controlla prima e si esce se il lavoro e' gia' fatto.
// 3. NIENTE RETE, NIENTE ECHO. Girano dentro il lock dei dati, su
//    una richiesta qualsiasi, anche di un socio che sta prelevando.
// 4. NIENTE store_transazione() QUI DENTRO. Il lock ce l'ha gia' chi
//    chiama.
// 5. VELOCI. Se una migrazione dovesse toccare tanti dati da
//    rischiare il tempo massimo, va spezzata salvando un segnaposto
//    e ripresa alla richiesta successiva.
// 6. IL NUMERO NON SI SALTA MAI. Se una migrazione risulta ancora da
//    fare ma la sua funzione non c'e' (i file stanno arrivando via
//    FTP uno alla volta), ci si ferma e si riprova dopo. Avanzare il
//    numero senza aver fatto il lavoro la perderebbe per sempre.
// ---------------------------------------------------------------

/**
 * Le migrazioni conosciute: numero => nome della funzione.
 * Numeri crescenti, senza buchi, e mai riusati.
 */
function migrazioni_elenco(): array
{
    return [
        1 => 'migrazione_0001_superadmin',
        2 => 'migrazione_0002_accesso_soci',
    ];
}

/**
 * Nomina il Superadmin nelle installazioni fatte prima che il ruolo
 * esistesse.
 *
 * Prima di questa versione tutti gli amministratori erano uguali.
 * L'unica traccia di "chi se ne occupa" era il responsabile degli
 * aggiornamenti, che pero' era solo un nome accanto all'avviso: se
 * qualcuno l'aveva scelto lo si prende per buono, altrimenti vale il
 * primo account creato, cioe' chi ha fatto l'installazione.
 *
 * Attenzione: qui dentro si usano solo store_read() e store_write().
 * salva_impostazioni() aprirebbe una seconda store_transazione() e il
 * lock si bloccherebbe su se' stesso, e impostazione() leggerebbe la
 * cache riempita prima del lock, cioe' un dato gia' vecchio.
 */
function migrazione_0001_superadmin(): void
{
    $salvate = store_read('impostazioni');
    if (!empty($salvate['superadmin_id'])) {
        return;                                     // gia' fatta
    }
    $utenti = store_read('utenti');
    if (!$utenti) {
        return;                                     // niente accessi, niente da nominare
    }

    $scelto = (string)($salvate['responsabile_aggiornamenti'] ?? '');
    $nuovo  = '';
    foreach ($utenti as $u) {
        if (($u['id'] ?? '') === $scelto && $scelto !== '') {
            $nuovo = $scelto;
            break;
        }
    }
    if ($nuovo === '') {
        $nuovo = (string)($utenti[0]['id'] ?? '');
    }
    if ($nuovo === '') {
        return;
    }

    $salvate['superadmin_id'] = $nuovo;
    store_write('impostazioni', $salvate);
}

/**
 * Rende esplicito su disco il modo in cui i soci entravano prima che
 * esistesse la scelta: il codice di gruppo. Senza questa migrazione
 * il valore di serie arriverebbe comunque (impostazioni() fa il
 * merge con impostazioni_predefinite()), ma qui si scrive per
 * davvero: lo schema_versione resta un riferimento affidabile di
 * cosa e' gia' stato scritto e cosa no.
 */
function migrazione_0002_accesso_soci(): void
{
    $salvate = store_read('impostazioni');
    if (isset($salvate['accesso_soci'])) {
        return;                                     // gia' fatta
    }
    $salvate['accesso_soci'] = 'codice';
    store_write('impostazioni', $salvate);
}

/**
 * Fin dove devono arrivare i dati: il numero piu' alto dell'elenco
 * qui sopra.
 *
 * Attenzione, e' un punto delicato: il traguardo si legge dal CODICE,
 * non da versione.json. Caricando i file via FTP arrivano uno alla
 * volta, e versione.json e' piccolo: se il traguardo stesse li',
 * potrebbe arrivare prima di questo file. Il primo che apre una pagina
 * in quel momento farebbe avanzare il numero senza che la migrazione
 * esista ancora, e quando il file arrivasse davvero la migrazione
 * risulterebbe gia' fatta. Persa per sempre, in silenzio.
 * Leggendolo da qui, il traguardo si alza solo insieme al codice che
 * lo sa raggiungere.
 */
function migrazioni_bersaglio(): int
{
    $elenco = migrazioni_elenco();
    return $elenco ? max(array_map('intval', array_keys($elenco))) : 0;
}

/**
 * Porta i dati allo schema della versione installata.
 * Si chiama da store_init(), a ogni richiesta: quando non c'e'
 * niente da fare costa il confronto di due numeri.
 */
function migrazioni_esegui(): array
{
    if (!installato()) {
        return [];                                  // durante l'installazione non c'e' niente da migrare
    }
    if ((int)impostazione('schema_versione', 0) >= migrazioni_bersaglio()) {
        return [];
    }

    $fatte = store_transazione(function () {
        // si rilegge dentro il lock: due richieste insieme non la fanno due volte
        $salvate = store_read('impostazioni');
        $da      = (int)($salvate['schema_versione'] ?? 0);
        $fatte   = [];

        $elenco = migrazioni_elenco();
        ksort($elenco);

        foreach ($elenco as $numero => $funzione) {
            $numero = (int)$numero;
            if ($numero <= $da) {
                continue;                            // gia' fatta
            }
            if (!function_exists($funzione)) {
                // L'elenco la nomina ma la funzione non c'e': il file e'
                // incompleto. Ci si ferma senza far avanzare il numero,
                // e si riprova al prossimo caricamento di pagina.
                break;
            }
            $funzione();
            $salvate = store_read('impostazioni');   // la migrazione puo' aver scritto le impostazioni
            $salvate['schema_versione'] = $numero;
            store_write('impostazioni', $salvate);   // si avanza un passo alla volta
            $fatte[] = $numero;
        }
        return $fatte;
    });

    impostazioni(true);
    return is_array($fatte) ? $fatte : [];
}
