<?php
// ---------------------------------------------------------------
// Impostazioni dell'applicativo, decise durante l'installazione
// e modificabili poi dalla dashboard.
// ---------------------------------------------------------------

function impostazioni_predefinite(): array
{
    return [
        'nome_gruppo'       => 'Gruppo Speleo',
        'sottotitolo'       => 'Gestionale magazzino',
        'logo'              => '',
        'codice_soci_hash'  => '',      // vuoto = area soci aperta
        'accesso_soci'      => 'codice', // 'codice' | 'account' - come entra chi non e' amministratore
        'codice_giorni'     => 90,
        'giorni_ritardo'    => 14,
        'segreto'           => '',      // usato per firmare il cookie dei soci
        'installato_il'     => '',

        // Invio email (conferma registrazione, reset password dei soci).
        // Vuoto = non configurato: le email semplicemente non partono.
        'smtp_host'         => '',
        'smtp_porta'        => 587,
        'smtp_sicurezza'    => 'tls',   // 'tls' | 'ssl' | 'nessuna'
        'smtp_utente'       => '',
        'smtp_password'     => '',
        'smtp_mittente'     => '',
        'smtp_nome_mittente' => '',

        // Chi ha le chiavi di casa: l'id dell'amministratore che ha
        // fatto l'installazione, salvo passaggio di mano. Vuoto = vale
        // il primo account creato (vedi superadmin_id() in auth.php).
        'superadmin_id'     => '',

        // Aspetto: vuoto = vale quello che sta in assets/style.css
        'colore_luce'       => '',      // --lampada
        'colore_luce_testo' => '',      // --lampada-testo, vuoto = bianco
        'colore_inchiostro' => '',      // --ink
        'colore_fondo'      => '',      // --fondo
        'raggio'            => '',      // --raggio, in pixel

        // Aggiornamenti
        'schema_versione'          => 0,   // formato dei dati; 0 = mai migrato
        'avvisa_aggiornamenti'     => true,
        // Non piu' usata: se ne occupa il Superadmin. Resta nell'elenco
        // perche' le versioni precedenti la leggono, e tornare indietro
        // con il codice non deve trovare i dati monchi.
        'responsabile_aggiornamenti' => '',
    ];
}

function impostazioni(bool $ricarica = false): array
{
    static $cache = null;
    if ($cache === null || $ricarica) {
        $salvate = store_read('impostazioni');
        $cache   = array_merge(impostazioni_predefinite(), is_array($salvate) ? $salvate : []);
    }
    return $cache;
}

function impostazione(string $chiave, $altrimenti = null)
{
    $i = impostazioni();
    return $i[$chiave] ?? $altrimenti;
}

function salva_impostazioni(array $nuove): void
{
    store_transazione(function () use ($nuove) {
        $attuali = array_merge(impostazioni_predefinite(), store_read('impostazioni'));
        store_write('impostazioni', array_merge($attuali, $nuove));
    });
    impostazioni(true);
}

/** L'installazione e' stata completata? */
function installato(): bool
{
    return is_file(DATA_DIR . '/.installato');
}

function segna_installato(): void
{
    file_put_contents(DATA_DIR . '/.installato', date('c') . "\n");
    @chmod(DATA_DIR . '/.installato', 0664);
}
