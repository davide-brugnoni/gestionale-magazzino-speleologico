<?php
// ---------------------------------------------------------------
// API JSON. Tutte le richieste passano da qui.
// ---------------------------------------------------------------
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/importa.php';
require_once __DIR__ . '/inc/aggiornamenti.php';
store_init();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function risposta($dati, int $codice = 200): void
{
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function errore(string $messaggio, int $codice = 400): void
{
    risposta(['ok' => false, 'errore' => $messaggio], $codice);
}

function corpo(): array
{
    $raw = file_get_contents('php://input');
    $d   = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function solo_admin(): void
{
    if (!e_admin()) {
        errore('Sessione scaduta. Rientra per continuare.', 401);
    }
    // Password provvisoria ancora da cambiare: non si fa niente finche'
    // non se ne sceglie una propria. Si risponde 401 apposta, cosi' la
    // dashboard rimbalza su login.php, che essendo gia' dentro rimanda
    // a dashboard.php e da li' richiedi_admin() porta al cambio.
    if (deve_cambiare_password()) {
        errore('Prima devi cambiare la password.', 401);
    }
}

/**
 * Le operazioni riservate a chi ha le chiavi di casa: gli account
 * degli amministratori, le impostazioni del gruppo, gli aggiornamenti.
 * Nascondere i comandi nella pagina e' solo cortesia: e' qui che si
 * dice di no davvero.
 */
function solo_superadmin(): void
{
    solo_admin();
    if (!e_superadmin()) {
        errore('Questa cosa la puo\' fare solo il Superadmin.', 403);
    }
}

function verifica_csrf(array $in): void
{
    if (!csrf_valido($in['csrf'] ?? null)) {
        errore('Sessione non valida. Ricarica la pagina.', 419);
    }
}

$azione = $_GET['azione'] ?? '';
$in     = corpo();

// se il gruppo ha impostato un codice d'ingresso, vale anche per le chiamate
if (in_array($azione, ['catalogo', 'prelievo', 'riconsegna'], true) && !soci_autorizzato()) {
    errore('Serve il codice del gruppo per usare l\'area soci.', 403);
}

switch ($azione) {

// ================= LETTURE PUBBLICHE =============================

case 'catalogo':
    $inv    = inventario_completo();
    $aperti = array_values(array_filter(prestiti_leggi_tutti(), fn($p) => $p['stato'] !== 'chiuso'));

    // Con gli account personali, un utente semplice vede tutto quello che
    // e' fuori (trasparenza sul gruppo) ma puo' riportare solo il proprio:
    // e' l'etichetta 'mio' a dirlo al client. Gli amministratori, e i
    // gruppi che usano ancora il codice condiviso senza identita'
    // individuali, restano senza questo limite.
    $socioSessione      = accesso_soci() === 'account' ? account_socio_sessione() : null;
    $puoRiportareTutti  = e_admin() || !$socioSessione;

    $pubblici = array_map(function ($p) use ($socioSessione) {
        $righe = [];
        foreach ($p['righe'] as $r) {
            $residuo = (int)$r['qta'] - (int)($r['qta_rientrata'] ?? 0) - (int)($r['qta_persa'] ?? 0);
            $righe[] = [
                'id_articolo' => $r['id_articolo'],
                'nome'        => $r['nome'],
                'qta'         => (int)$r['qta'],
                'residuo'     => max(0, $residuo),
            ];
        }
        return [
            'id'             => $p['id'],
            'persona'        => $p['persona'],
            'uscita'         => $p['uscita'],
            'rientro_atteso' => $p['rientro_atteso'] ?? '',
            'destinazione'   => $p['destinazione'] ?? '',
            'giorni'         => giorni_da($p['uscita']),
            'righe'          => $righe,
            'mio'            => $socioSessione ? (($p['id_socio'] ?? null) === $socioSessione['id']) : true,
        ];
    }, $aperti);

    usort($pubblici, fn($a, $b) => strcmp($b['uscita'], $a['uscita']));

    $foto = [];
    foreach ($inv as $a) {
        if ($a['foto'] !== '') {
            $foto[$a['id']] = $a['foto'];
        }
    }

    risposta(['ok' => true, 'inventario' => $inv, 'prestiti' => $pubblici, 'foto' => $foto, 'giorni_ritardo' => GIORNI_RITARDO, 'puo_riportare_tutti' => $puoRiportareTutti]);

// ================= PRELIEVO (pubblico) ===========================

case 'prelievo':
    // Il campo si precompila col nome di chi e' loggato ma resta
    // modificabile: capita che chi ritira materialmente la roba non
    // sia chi ha fatto l'accesso (es. Pina ritira "per conto di"
    // Alessandro, che se la portera' fisicamente via lui). Chi ha
    // fatto l'accesso resta comunque tracciato in 'id_socio', qui
    // sotto, indipendentemente dal nome scritto.
    $socioPrelievo = accesso_soci() === 'account' ? account_socio_sessione() : null;
    $persona = trim($in['persona'] ?? '');
    if ($persona === '' && $socioPrelievo) {
        $persona = $socioPrelievo['nome'];
    }
    $righe   = $in['righe'] ?? [];

    if (mb_strlen($persona) < 3) {
        errore('Scrivi nome e cognome di chi ritira.');
    }
    if (!is_array($righe) || count($righe) === 0) {
        errore('Aggiungi almeno un articolo prima di confermare.');
    }

    $esito = store_transazione(function () use ($in, $persona, $righe, $socioPrelievo) {
        $inv = inventario_completo();
        $out = [];
        foreach ($righe as $r) {
            $id  = (string)($r['id_articolo'] ?? '');
            $qta = (int)($r['qta'] ?? 0);
            if ($qta <= 0) {
                continue;
            }
            $a = trova_articolo($inv, $id);
            if (!$a) {
                return ['ok' => false, 'errore' => 'Articolo non piu\' presente a magazzino.'];
            }
            if (isset($a['prestabile']) && $a['prestabile'] === false) {
                return ['ok' => false, 'errore' => $a['nome'] . ' non e\' prelevabile dall\'area soci.'];
            }
            if ($qta > $a['disponibile']) {
                return ['ok' => false, 'errore' => 'Di ' . $a['nome'] . ' restano ' . $a['disponibile'] . ' pezzi disponibili.'];
            }
            $out[] = [
                'id_articolo'    => $a['id'],
                'nome'           => $a['nome'],
                'categoria'      => $a['categoria'],
                'qta'            => $qta,
                'qta_rientrata'  => 0,
                'qta_persa'      => 0,
                'note_rientro'   => '',
            ];
        }
        if (!$out) {
            return ['ok' => false, 'errore' => 'Aggiungi almeno un articolo prima di confermare.'];
        }

        $prestito   = [
            'id'             => nuovo_id('pre'),
            'id_socio'       => $socioPrelievo['id'] ?? null,
            'persona'        => $persona,
            'contatto'       => trim($in['contatto'] ?? '') ?: ($socioPrelievo['email'] ?? ''),
            'destinazione'   => trim($in['destinazione'] ?? ''),
            'note'           => trim($in['note'] ?? ''),
            'uscita'         => adesso(),
            'rientro_atteso' => trim($in['rientro_atteso'] ?? ''),
            'stato'          => 'aperto',
            'righe'          => $out,
            'rientri'        => [],
            'chiuso_il'      => null,
        ];
        if (!prestito_salva($prestito)) {
            return ['ok' => false, 'errore' => 'Non riesco a salvare la richiesta. Controlla i permessi della cartella data/prestiti.'];
        }
        return ['ok' => true, 'prestito' => $prestito];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

// ================= RICONSEGNA (pubblico) =========================

case 'riconsegna':
    $idPrestito = (string)($in['id_prestito'] ?? '');
    // Con gli account personali, chi riconsegna e' chi e' loggato:
    // resta comunque permesso chiudere un prelievo altrui (capita, nei
    // gruppi reali), si traccia solo chi ha fatto l'operazione.
    $socioRiconsegna = accesso_soci() === 'account' ? account_socio_sessione() : null;
    $chi        = $socioRiconsegna ? $socioRiconsegna['nome'] : trim($in['chi_riconsegna'] ?? '');
    $righeIn    = $in['righe'] ?? [];

    if ($idPrestito === '' || !is_array($righeIn)) {
        errore('Richiesta incompleta.');
    }

    $esito = store_transazione(function () use ($idPrestito, $chi, $righeIn, $in, $socioRiconsegna) {
        $p = prestito_leggi($idPrestito);
        if ($p === null) {
            return ['ok' => false, 'errore' => 'Prelievo non trovato.'];
        }
        // Un utente semplice puo' riportare solo la roba prenotata da lui:
        // gli amministratori restano senza questo limite, possono chiudere
        // qualunque prelievo.
        if ($socioRiconsegna && !e_admin() && ($p['id_socio'] ?? null) !== $socioRiconsegna['id']) {
            return ['ok' => false, 'errore' => 'Puoi riportare solo l\'attrezzatura che hai prenotato tu.'];
        }
        if ($p['stato'] === 'chiuso') {
            return ['ok' => false, 'errore' => 'Questo prelievo risulta gia\' chiuso.'];
        }

        $perdite   = [];
        $dettaglio = [];

        foreach ($p['righe'] as $i => $riga) {
            $residuo = (int)$riga['qta'] - (int)$riga['qta_rientrata'] - (int)$riga['qta_persa'];
            $rese    = 0;
            $perse   = 0;
            $nota    = '';
            foreach ($righeIn as $r) {
                if ((string)($r['id_articolo'] ?? '') === $riga['id_articolo']) {
                    $rese  = max(0, (int)($r['rientrate'] ?? 0));
                    $perse = max(0, (int)($r['perse'] ?? 0));
                    $nota  = trim((string)($r['nota'] ?? ''));
                    break;
                }
            }
            if ($rese + $perse > $residuo) {
                return ['ok' => false, 'errore' => 'Su ' . $riga['nome'] . ' risultano fuori solo ' . $residuo . ' pezzi.'];
            }
            $p['righe'][$i]['qta_rientrata'] += $rese;
            $p['righe'][$i]['qta_persa']     += $perse;
            if ($nota !== '') {
                $p['righe'][$i]['note_rientro'] = trim($p['righe'][$i]['note_rientro'] . ' ' . $nota);
            }
            if ($perse > 0) {
                $perdite[] = ['id' => $riga['id_articolo'], 'nome' => $riga['nome'], 'qta' => $perse, 'nota' => $nota];
            }
            if ($rese || $perse) {
                $dettaglio[] = ['nome' => $riga['nome'], 'rientrate' => $rese, 'perse' => $perse, 'nota' => $nota];
            }
        }

        if (!$dettaglio) {
            return ['ok' => false, 'errore' => 'Indica almeno un pezzo rientrato o mancante.'];
        }

        // I pezzi dichiarati persi o rotti escono dalle giacenze
        if ($perdite) {
            $inv = store_read('inventario');
            foreach ($inv as $i => $a) {
                foreach ($perdite as $pd) {
                    if ($a['id'] === $pd['id']) {
                        $inv[$i]['quantita'] = max(0, (int)$a['quantita'] - $pd['qta']);
                    }
                }
            }
            store_write('inventario', $inv);
            foreach ($perdite as $pd) {
                registra_movimento('perdita', [
                    'id_articolo' => $pd['id'],
                    'nome'        => $pd['nome'],
                    'qta'         => -$pd['qta'],
                    'nota'        => trim('Perso o rotto durante il prelievo ' . $idPrestito . '. ' . $pd['nota']),
                    'da'          => $chi !== '' ? $chi : $p['persona'],
                ]);
            }
        }

        $p['rientri'][] = [
            'quando'    => adesso(),
            'chi'       => $chi !== '' ? $chi : $p['persona'],
            'id_socio'  => $socioRiconsegna['id'] ?? null,
            'nota'      => trim((string)($in['nota'] ?? '')),
            'dettaglio' => $dettaglio,
        ];

        $aperti = 0;
        foreach ($p['righe'] as $riga) {
            $aperti += (int)$riga['qta'] - (int)$riga['qta_rientrata'] - (int)$riga['qta_persa'];
        }
        if ($aperti === 0) {
            $p['stato']     = 'chiuso';
            $p['chiuso_il'] = adesso();
        } else {
            $p['stato'] = 'parziale';
        }

        prestito_salva($p);
        return ['ok' => true, 'prestito' => $p, 'residui' => $aperti];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

// ================= DASHBOARD AMMINISTRATORI ======================

case 'stato':
    solo_admin();
    $inv      = inventario_completo();
    $prestiti = prestiti_leggi_tutti();
    $movimenti = array_reverse(store_read('movimenti'));

    $totale = 0; $fuori = 0; $daComprare = 0; $mancanti = 0;
    foreach ($inv as $a) {
        $totale     += $a['quantita'];
        $fuori      += $a['in_prestito'];
        $daComprare += (int)($a['da_comprare'] ?? 0);
        $mancanti   += max(0, (int)($a['quantita_teorica'] ?? $a['quantita']) - $a['quantita']);
    }

    $aperti = [];
    foreach ($prestiti as $p) {
        if ($p['stato'] === 'chiuso') {
            continue;
        }
        $righe = [];
        foreach ($p['righe'] as $r) {
            $residuo = (int)$r['qta'] - (int)$r['qta_rientrata'] - (int)$r['qta_persa'];
            if ($residuo > 0) {
                $righe[] = ['id_articolo' => $r['id_articolo'], 'nome' => $r['nome'], 'residuo' => $residuo, 'qta' => (int)$r['qta']];
            }
        }
        if (!$righe) {
            continue;
        }
        $aperti[] = [
            'id'             => $p['id'],
            'persona'        => $p['persona'],
            'contatto'       => $p['contatto'] ?? '',
            'destinazione'   => $p['destinazione'] ?? '',
            'note'           => $p['note'] ?? '',
            'uscita'         => $p['uscita'],
            'rientro_atteso' => $p['rientro_atteso'] ?? '',
            'giorni'         => giorni_da($p['uscita']),
            'stato'          => $p['stato'],
            'righe'          => $righe,
            'pezzi_fuori'    => array_sum(array_column($righe, 'residuo')),
        ];
    }
    usort($aperti, fn($a, $b) => $b['giorni'] <=> $a['giorni']);

    $storico = array_map(function ($p) {
        $tot = 0; $res = 0; $persi = 0;
        foreach ($p['righe'] as $r) {
            $tot   += (int)$r['qta'];
            $persi += (int)$r['qta_persa'];
            $res   += (int)$r['qta'] - (int)$r['qta_rientrata'] - (int)$r['qta_persa'];
        }
        return [
            'id'             => $p['id'],
            'persona'        => $p['persona'],
            'contatto'       => $p['contatto'] ?? '',
            'destinazione'   => $p['destinazione'] ?? '',
            'note'           => $p['note'] ?? '',
            'uscita'         => $p['uscita'],
            'rientro_atteso' => $p['rientro_atteso'] ?? '',
            'chiuso_il'      => $p['chiuso_il'],
            'stato'          => $p['stato'],
            'pezzi'          => $tot,
            'residui'        => $res,
            'persi'          => $persi,
            'righe'          => $p['righe'],
            'rientri'        => $p['rientri'] ?? [],
        ];
    }, $prestiti);
    usort($storico, fn($a, $b) => strcmp($b['uscita'], $a['uscita']));

    $foto = [];
    foreach ($inv as $a) {
        if ($a['foto'] !== '') {
            $foto[$a['id']] = $a['foto'];
        }
    }

    risposta([
        'ok' => true,
        'foto' => $foto,
        'kpi' => [
            'pezzi_totali'   => $totale,
            'in_prestito'    => $fuori,
            'disponibili'    => $totale - $fuori,
            'articoli'       => count($inv),
            'prestiti_aperti'=> count($aperti),
            'in_ritardo'     => count(array_filter($aperti, fn($p) => $p['giorni'] >= GIORNI_RITARDO)),
            'da_comprare'    => $daComprare,
            'mancanti'       => $mancanti,
        ],
        'inventario'     => $inv,
        'aperti'         => $aperti,
        'storico'        => $storico,
        'movimenti'      => array_slice($movimenti, 0, 300),
        'impostazioni'   => [
            'nome_gruppo'    => APP_NOME,
            'sottotitolo'    => APP_SOTTOTITOLO,
            'logo'           => APP_LOGO,
            'giorni_ritardo' => GIORNI_RITARDO,
            'codice_giorni'  => CODICE_GIORNI,
            'area_protetta'  => serve_codice_soci(),
            'accesso_soci'      => accesso_soci(),
            'colore_luce'       => (string)impostazione('colore_luce', ''),
            'colore_luce_testo' => (string)impostazione('colore_luce_testo', ''),
            'colore_inchiostro' => (string)impostazione('colore_inchiostro', ''),
            'colore_fondo'      => (string)impostazione('colore_fondo', ''),
            'raggio'            => (string)impostazione('raggio', ''),
        ] + (e_superadmin() ? [
            // La password SMTP non torna mai indietro: solo se e' stata
            // impostata, cosi' il form sa se dire "invariata" o meno.
            'smtp_host'              => (string)impostazione('smtp_host', ''),
            'smtp_porta'             => (int)impostazione('smtp_porta', 587),
            'smtp_sicurezza'         => (string)impostazione('smtp_sicurezza', 'tls'),
            'smtp_utente'            => (string)impostazione('smtp_utente', ''),
            'smtp_password_impostata' => (string)impostazione('smtp_password', '') !== '',
            'smtp_mittente'          => (string)impostazione('smtp_mittente', ''),
            'smtp_nome_mittente'     => (string)impostazione('smtp_nome_mittente', ''),
        ] : []),
        // l'elenco degli amministratori lo vede solo chi puo' farci
        // qualcosa: e' il Superadmin ad avere la tabella
        'utenti'         => e_superadmin()
            ? array_map(fn($u) => ['id' => $u['id'], 'user' => $u['user'], 'nome' => $u['nome']], store_read('utenti'))
            : [],
        // gli account soci li gestisce ogni amministratore, non solo il Superadmin
        'account_soci'   => array_map(fn($s) => [
            'id' => $s['id'], 'nome' => $s['nome'], 'email' => $s['email'],
            'stato' => $s['stato'], 'creato_il' => $s['creato_il'],
            'email_verificata' => !empty($s['email_verificata']),
        ], soci_leggi_tutti()),
        'io'             => $_SESSION['utente'],
        'superadmin'     => superadmin_id(),
        'sono_superadmin' => e_superadmin(),
        'giorni_ritardo' => GIORNI_RITARDO,
        'versione'       => APP_VERSIONE,
    ]);

// ---- carico / scarico / rettifica giacenza ----------------------

case 'giacenza':
    solo_admin();
    verifica_csrf($in);
    $id    = (string)($in['id'] ?? '');
    $tipo  = (string)($in['tipo'] ?? '');   // acquisto | scarto | rettifica
    $qta   = (int)($in['qta'] ?? 0);
    $nota  = trim((string)($in['nota'] ?? ''));

    if (!in_array($tipo, ['acquisto', 'scarto', 'rettifica'], true)) {
        errore('Operazione non riconosciuta.');
    }
    if ($tipo !== 'rettifica' && $qta <= 0) {
        errore('Indica quanti pezzi.');
    }

    $esito = store_transazione(function () use ($id, $tipo, $qta, $nota) {
        $inv    = store_read('inventario');
        $indice = null;
        foreach ($inv as $k => $a) {
            if ($a['id'] === $id) {
                $indice = $k;
                break;
            }
        }
        if ($indice === null) {
            return ['ok' => false, 'errore' => 'Articolo non trovato.'];
        }
        $prima = (int)$inv[$indice]['quantita'];

        if ($tipo === 'acquisto') {
            $dopo = $prima + $qta;
            $inv[$indice]['da_comprare'] = max(0, (int)($inv[$indice]['da_comprare'] ?? 0) - $qta);
        } elseif ($tipo === 'scarto') {
            $fuori = pezzi_fuori(prestiti_leggi_tutti())[$id] ?? 0;
            if ($prima - $qta < $fuori) {
                return ['ok' => false, 'errore' => 'Ci sono ' . $fuori . ' pezzi ancora in prestito: non puoi scendere sotto questa quota.'];
            }
            $dopo = max(0, $prima - $qta);
        } else {
            $fuori = pezzi_fuori(prestiti_leggi_tutti())[$id] ?? 0;
            if ($qta < $fuori) {
                return ['ok' => false, 'errore' => 'Ci sono ' . $fuori . ' pezzi ancora in prestito: la giacenza non puo\' essere inferiore.'];
            }
            $dopo = max(0, $qta);
        }

        $inv[$indice]['quantita'] = $dopo;
        store_write('inventario', $inv);
        registra_movimento($tipo, [
            'id_articolo' => $id,
            'nome'        => trim($inv[$indice]['articolo'] . ' ' . $inv[$indice]['tipo']),
            'qta'         => $dopo - $prima,
            'giacenza'    => $dopo,
            'nota'        => $nota,
        ]);
        return ['ok' => true];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

// ---- crea o modifica articolo -----------------------------------

case 'articolo_salva':
    solo_admin();
    verifica_csrf($in);
    $categoria = trim((string)($in['categoria'] ?? ''));
    $articolo  = trim((string)($in['articolo'] ?? ''));
    if ($categoria === '' || $articolo === '') {
        errore('Categoria e articolo sono obbligatori.');
    }

    $esito = store_transazione(function () use ($in, $categoria, $articolo) {
        $inv = store_read('inventario');
        $id  = trim((string)($in['id'] ?? ''));
        $campi = [
            'categoria'        => $categoria,
            'articolo'         => $articolo,
            'tipo'             => trim((string)($in['tipo'] ?? '')),
            'soglia_minima'    => max(0, (int)($in['soglia_minima'] ?? 0)),
            'da_comprare'      => max(0, (int)($in['da_comprare'] ?? 0)),
            'prestabile'       => !empty($in['prestabile']),
            'note'             => trim((string)($in['note'] ?? '')),
        ];

        if ($id !== '') {
            foreach ($inv as $k => $a) {
                if ($a['id'] === $id) {
                    $inv[$k] = array_merge($a, $campi);
                    store_write('inventario', $inv);
                    registra_movimento('modifica', ['id_articolo' => $id, 'nome' => trim($articolo . ' ' . $campi['tipo']), 'qta' => 0, 'nota' => 'Scheda aggiornata']);
                    return ['ok' => true, 'id' => $id];
                }
            }
            return ['ok' => false, 'errore' => 'Articolo non trovato.'];
        }

        $qta = max(0, (int)($in['quantita'] ?? 0));
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $categoria . '-' . $articolo . '-' . $campi['tipo']));
        $nuovoId = trim($base, '-');
        $esistenti = array_column($inv, 'id');
        while (in_array($nuovoId, $esistenti, true)) {
            $nuovoId .= '-2';
        }
        $inv[] = array_merge($campi, [
            'id'               => $nuovoId,
            'quantita'         => $qta,
            'quantita_teorica' => $qta,
            'foto'             => '',
            'creato_il'        => adesso(),
        ]);
        store_write('inventario', $inv);
        registra_movimento('nuovo', ['id_articolo' => $nuovoId, 'nome' => trim($articolo . ' ' . $campi['tipo']), 'qta' => $qta, 'giacenza' => $qta, 'nota' => 'Articolo creato']);
        return ['ok' => true, 'id' => $nuovoId];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

// ---- elimina articolo -------------------------------------------

case 'articolo_elimina':
    solo_admin();
    verifica_csrf($in);
    $id = (string)($in['id'] ?? '');

    $esito = store_transazione(function () use ($id) {
        $fuori = pezzi_fuori(prestiti_leggi_tutti())[$id] ?? 0;
        if ($fuori > 0) {
            return ['ok' => false, 'errore' => 'Ci sono ancora ' . $fuori . ' pezzi in prestito. Chiudi prima i rientri.'];
        }
        $inv   = store_read('inventario');
        $nome  = '';
        $nuovi = [];
        foreach ($inv as $a) {
            if ($a['id'] === $id) {
                $nome = trim($a['articolo'] . ' ' . $a['tipo']);
                if (!foto_condivisa($inv, $a['foto'] ?? '', $id)) {
                    foto_cancella($a['foto'] ?? '');
                }
                continue;
            }
            $nuovi[] = $a;
        }
        if ($nome === '') {
            return ['ok' => false, 'errore' => 'Articolo non trovato.'];
        }
        store_write('inventario', $nuovi);
        registra_movimento('eliminato', ['id_articolo' => $id, 'nome' => $nome, 'qta' => 0, 'nota' => 'Articolo rimosso dal magazzino']);
        return ['ok' => true];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

// ---- chiusura forzata di un prelievo -----------------------------

case 'prestito_chiudi':
    solo_admin();
    verifica_csrf($in);
    $id    = (string)($in['id'] ?? '');
    $modo  = (string)($in['modo'] ?? 'rientrato'); // rientrato | perso
    $nota  = trim((string)($in['nota'] ?? ''));

    $esito = store_transazione(function () use ($id, $modo, $nota) {
        $p = prestito_leggi($id);
        if ($p === null) {
            return ['ok' => false, 'errore' => 'Prelievo non trovato.'];
        }
        $perdite   = [];
        $dettaglio = [];
        foreach ($p['righe'] as $i => $r) {
            $residuo = (int)$r['qta'] - (int)$r['qta_rientrata'] - (int)$r['qta_persa'];
            if ($residuo <= 0) { continue; }
            if ($modo === 'perso') {
                $p['righe'][$i]['qta_persa'] += $residuo;
                $perdite[] = ['id' => $r['id_articolo'], 'nome' => $r['nome'], 'qta' => $residuo];
                $dettaglio[] = ['nome' => $r['nome'], 'rientrate' => 0, 'perse' => $residuo, 'nota' => $nota];
            } else {
                $p['righe'][$i]['qta_rientrata'] += $residuo;
                $dettaglio[] = ['nome' => $r['nome'], 'rientrate' => $residuo, 'perse' => 0, 'nota' => $nota];
            }
        }
        if ($perdite) {
            $inv = store_read('inventario');
            foreach ($inv as $i => $a) {
                foreach ($perdite as $pd) {
                    if ($a['id'] === $pd['id']) {
                        $inv[$i]['quantita'] = max(0, (int)$a['quantita'] - $pd['qta']);
                    }
                }
            }
            store_write('inventario', $inv);
            foreach ($perdite as $pd) {
                registra_movimento('perdita', [
                    'id_articolo' => $pd['id'], 'nome' => $pd['nome'], 'qta' => -$pd['qta'],
                    'nota' => trim('Chiusura amministrativa del prelievo ' . $id . '. ' . $nota),
                ]);
            }
        }
        $p['rientri'][] = [
            'quando'    => adesso(),
            'chi'       => ($_SESSION['utente']['nome'] ?? 'admin') . ' (chiusura in dashboard)',
            'nota'      => $nota,
            'dettaglio' => $dettaglio,
        ];
        $p['stato']     = 'chiuso';
        $p['chiuso_il'] = adesso();
        prestito_salva($p);
        return ['ok' => true];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

// ---- eliminazione dei prelievi conclusi ---------------------------
// Si cancella il file del prelievo: le giacenze non cambiano (i pezzi sono
// gia' rientrati e le perdite gia' scalate), ma nei movimenti resta la traccia.

case 'prestito_elimina':
    solo_admin();
    verifica_csrf($in);
    $id = (string)($in['id'] ?? '');

    $esito = store_transazione(function () use ($id) {
        $p = prestito_leggi($id);
        if ($p === null) {
            return ['ok' => false, 'errore' => 'Prelievo non trovato.'];
        }
        $fuori = 0;
        foreach ($p['righe'] as $r) {
            $fuori += (int)$r['qta'] - (int)$r['qta_rientrata'] - (int)$r['qta_persa'];
        }
        if (($p['stato'] ?? '') !== 'chiuso' || $fuori > 0) {
            return ['ok' => false, 'errore' => 'Si possono eliminare solo i prelievi completamente riconsegnati.'];
        }
        if (!prestito_elimina($id)) {
            return ['ok' => false, 'errore' => 'Non riesco a cancellare il file. Controlla i permessi della cartella data/prestiti.'];
        }
        registra_movimento('archivio', [
            'nome' => 'Prelievo di ' . $p['persona'],
            'qta'  => 0,
            'nota' => 'Eliminato il prelievo ' . $id . ' del ' . date('d/m/Y', strtotime($p['uscita'])) .
                      ' (' . count($p['righe']) . (count($p['righe']) === 1 ? ' articolo' : ' articoli') . ', tutto riconsegnato).',
        ]);
        return ['ok' => true];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

case 'prestiti_pulisci':
    solo_admin();
    verifica_csrf($in);
    $primaDel = (string)($in['prima_del'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $primaDel)) {
        errore('Indica la data prima della quale fare pulizia.');
    }

    $esito = store_transazione(function () use ($primaDel) {
        $eliminati = 0;
        foreach (prestiti_leggi_tutti() as $p) {
            if (($p['stato'] ?? '') !== 'chiuso') {
                continue;
            }
            $quando = substr((string)($p['chiuso_il'] ?: $p['uscita']), 0, 10);
            if ($quando === '' || $quando >= $primaDel) {
                continue;
            }
            if (prestito_elimina((string)$p['id'])) {
                $eliminati++;
            }
        }
        if ($eliminati > 0) {
            registra_movimento('archivio', [
                'nome' => '',
                'qta'  => 0,
                'nota' => ($eliminati === 1
                              ? 'Un prelievo chiuso prima del ' . date('d/m/Y', strtotime($primaDel)) . ' eliminato dall\'archivio.'
                              : $eliminati . ' prelievi chiusi prima del ' . date('d/m/Y', strtotime($primaDel)) . ' eliminati dall\'archivio.'),
            ]);
        }
        return ['ok' => true, 'eliminati' => $eliminati];
    });

    risposta($esito);

// ---- gestione amministratori -------------------------------------

case 'utente_nuovo':
    solo_superadmin();
    verifica_csrf($in);
    risposta(utente_crea((string)($in['user'] ?? ''), (string)($in['password'] ?? ''), (string)($in['nome'] ?? '')));

case 'utente_elimina':
    solo_superadmin();
    verifica_csrf($in);
    if (($in['id'] ?? '') === ($_SESSION['utente']['id'] ?? '')) {
        errore('Non puoi eliminare il tuo stesso accesso.');
    }
    risposta(utente_elimina((string)($in['id'] ?? '')));

// Password provvisoria per chi la sua l'ha dimenticata. Chi la riceve
// e' obbligato a sceglierne una nuova al primo accesso, cosi' il
// Superadmin non resta a conoscenza della password di nessuno.
case 'utente_reset_password':
    solo_superadmin();
    verifica_csrf($in);
    $idBersaglio = (string)($in['id'] ?? '');
    if ($idBersaglio === ($_SESSION['utente']['id'] ?? '')) {
        errore('Per la tua password usa il riquadro "La tua password".');
    }
    $esito = utente_imposta_password($idBersaglio, (string)($in['nuova'] ?? ''));
    if ($esito['ok']) {
        // nel registro finisce il fatto, mai la password
        registra_movimento('impostazioni', [
            'nome' => '',
            'qta'  => 0,
            'nota' => 'Password reimpostata per ' . ($esito['nome'] ?? 'un amministratore'),
        ]);
    }
    risposta(['ok' => $esito['ok'], 'errore' => $esito['errore'] ?? '']);

case 'utente_mia_password':
    solo_admin();
    verifica_csrf($in);
    $esito = utente_cambia_password(
        (string)($_SESSION['utente']['id'] ?? ''),
        (string)($in['attuale'] ?? ''),
        (string)($in['nuova'] ?? '')
    );
    if ($esito['ok']) {
        unset($_SESSION['cambio_password']);     // se era provvisoria, adesso non lo e' piu'
    }
    risposta($esito);

// ---- gestione degli account soci ----------------------------------
// Approvare, rifiutare, disabilitare, riabilitare, eliminare e
// resettare la password di un socio e' un compito operativo, come
// chiudere un prelievo: lo puo' fare ogni amministratore, non solo
// il Superadmin.

case 'socio_approva':
    solo_admin();
    verifica_csrf($in);
    risposta(account_socio_approva((string)($in['id'] ?? ''), (string)($_SESSION['utente']['id'] ?? '')));

case 'socio_rifiuta':
    solo_admin();
    verifica_csrf($in);
    risposta(account_socio_rifiuta((string)($in['id'] ?? '')));

case 'socio_disabilita':
    solo_admin();
    verifica_csrf($in);
    risposta(account_socio_disabilita((string)($in['id'] ?? '')));

case 'socio_riabilita':
    solo_admin();
    verifica_csrf($in);
    risposta(account_socio_riabilita((string)($in['id'] ?? '')));

case 'socio_elimina':
    solo_admin();
    verifica_csrf($in);
    risposta(account_socio_elimina((string)($in['id'] ?? '')));

// Password provvisoria per il socio che l'ha dimenticata. Come per
// utente_reset_password: chi la riceve deve sceglierne una nuova al
// primo accesso, cosi' nessun admin resta a conoscenza della password.
case 'socio_reset_password':
    solo_admin();
    verifica_csrf($in);
    $esito = account_socio_imposta_password((string)($in['id'] ?? ''), (string)($in['nuova'] ?? ''));
    if ($esito['ok']) {
        registra_movimento('impostazioni', [
            'nome' => '',
            'qta'  => 0,
            'nota' => 'Password reimpostata per il socio ' . ($esito['nome'] ?? ''),
        ]);
    }
    risposta(['ok' => $esito['ok'], 'errore' => $esito['errore'] ?? '']);

// ---- passaggio del ruolo di Superadmin ---------------------------
//
// La via d'uscita quando chi ha installato lascia il gruppo. Si chiede
// la sua password perche' e' l'operazione che regala le chiavi di casa:
// una scheda lasciata aperta non deve bastare.
case 'superadmin_trasferisci':
    solo_superadmin();
    verifica_csrf($in);
    $idNuovo = (string)($in['id'] ?? '');
    if ($idNuovo === '' || $idNuovo === ($_SESSION['utente']['id'] ?? '')) {
        errore('Scegli un altro amministratore.');
    }

    $utenti  = store_read('utenti');
    $nuovo   = null;
    $ioStesso = null;
    foreach ($utenti as $u) {
        if ($u['id'] === $idNuovo) {
            $nuovo = $u;
        }
        if ($u['id'] === ($_SESSION['utente']['id'] ?? '')) {
            $ioStesso = $u;
        }
    }
    if (!$nuovo) {
        errore('Amministratore non trovato.');
    }
    if (!$ioStesso || !password_verify((string)($in['password'] ?? ''), $ioStesso['hash'])) {
        errore('La tua password non e\' corretta.');
    }

    salva_impostazioni(['superadmin_id' => $idNuovo]);
    registra_movimento('impostazioni', [
        'nome' => '',
        'qta'  => 0,
        'nota' => 'Ruolo di Superadmin passato a ' . $nuovo['nome'],
    ]);
    risposta(['ok' => true, 'nome' => $nuovo['nome']]);

// ---- impostazioni del gruppo -------------------------------------

case 'impostazioni_salva':
    solo_superadmin();
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        errore('Sessione non valida. Ricarica la pagina.', 419);
    }

    $nome = trim((string)($_POST['nome_gruppo'] ?? ''));
    if (mb_strlen($nome) < 2) {
        errore('Scrivi il nome del gruppo.');
    }

    $nuove = [
        'nome_gruppo'    => $nome,
        'sottotitolo'    => trim((string)($_POST['sottotitolo'] ?? '')) ?: 'Gestionale magazzino',
        'giorni_ritardo' => max(1, (int)($_POST['giorni_ritardo'] ?? 14)),
        'codice_giorni'  => max(1, (int)($_POST['codice_giorni'] ?? 90)),
        'accesso_soci'   => ($_POST['accesso_soci'] ?? 'codice') === 'account' ? 'account' : 'codice',
    ];

    // ---- aspetto. Questi valori finiscono dentro un <style>, quindi
    // si accetta solo la forma #rrggbb: qui h() non basterebbe.
    if (!empty($_POST['colori_di_serie'])) {
        $nuove['colore_luce']       = '';
        $nuove['colore_luce_testo'] = '';
        $nuove['colore_inchiostro'] = '';
        $nuove['colore_fondo']      = '';
        $nuove['raggio']            = '';
    } else {
        foreach (['colore_luce', 'colore_luce_testo', 'colore_inchiostro', 'colore_fondo'] as $chiave) {
            if (!isset($_POST[$chiave])) {
                continue;
            }
            $valore = trim((string)$_POST[$chiave]);
            if ($valore !== '' && colore_valido($valore) === '') {
                errore('Il colore scelto non e\' valido: serve la forma #rrggbb.');
            }
            $nuove[$chiave] = colore_valido($valore);
        }
        if (isset($_POST['raggio'])) {
            $raggio = trim((string)$_POST['raggio']);
            if ($raggio !== '' && (!ctype_digit($raggio) || (int)$raggio > 24)) {
                errore('Gli angoli devono essere un numero da 0 a 24.');
            }
            $nuove['raggio'] = $raggio === '' ? '' : (string)(int)$raggio;
        }
    }

    if (!empty($_FILES['logo']['name'])) {
        $caricato = foto_salva('logo-gruppo', $_FILES['logo']);
        if (!$caricato['ok']) {
            errore($caricato['errore']);
        }
        foto_cancella(APP_LOGO);
        $nuove['logo'] = $caricato['nome'];
    } elseif (!empty($_POST['togli_logo'])) {
        foto_cancella(APP_LOGO);
        $nuove['logo'] = '';
    }

    // ---- SMTP. La password si cambia solo se e' stata scritta di nuovo:
    // il form non la rimanda mai indietro, quindi vuota non vuol dire
    // "toglila", vuol dire "non l'ho toccata".
    if (isset($_POST['smtp_host'])) {
        $smtpMittente = trim((string)($_POST['smtp_mittente'] ?? ''));
        if (trim((string)$_POST['smtp_host']) !== '' && !filter_var($smtpMittente, FILTER_VALIDATE_EMAIL)) {
            errore('Se compili il server SMTP, scrivi anche un indirizzo email valido come mittente.');
        }
        $nuove['smtp_host']          = trim((string)$_POST['smtp_host']);
        $nuove['smtp_porta']         = max(1, min(65535, (int)($_POST['smtp_porta'] ?? 587)));
        $nuove['smtp_sicurezza']     = in_array($_POST['smtp_sicurezza'] ?? 'tls', ['tls', 'ssl', 'nessuna'], true) ? $_POST['smtp_sicurezza'] : 'tls';
        $nuove['smtp_utente']        = trim((string)($_POST['smtp_utente'] ?? ''));
        $nuove['smtp_mittente']      = $smtpMittente;
        $nuove['smtp_nome_mittente'] = trim((string)($_POST['smtp_nome_mittente'] ?? ''));
        if (trim((string)($_POST['smtp_password'] ?? '')) !== '') {
            $nuove['smtp_password'] = (string)$_POST['smtp_password'];
        }
    }

    salva_impostazioni($nuove);

    // il codice dei soci si cambia solo se e' stato scritto qualcosa
    $codice = (string)($_POST['codice_soci'] ?? '');
    if (!empty($_POST['apri_area'])) {
        imposta_codice_soci('');
    } elseif (trim($codice) !== '') {
        if (strlen(trim($codice)) < 4) {
            errore('Il codice dei soci deve avere almeno 4 caratteri.');
        }
        imposta_codice_soci(trim($codice));
    }

    registra_movimento('impostazioni', ['nome' => '', 'qta' => 0, 'nota' => 'Impostazioni aggiornate']);
    risposta(['ok' => true]);

case 'impostazioni_email_test':
    solo_superadmin();
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        errore('Sessione non valida. Ricarica la pagina.', 419);
    }

    // Prova con i valori che stanno nel modulo in questo momento, non
    // (solo) quelli gia' salvati: cosi' si controlla prima di salvare.
    // La password, se lasciata vuota, vale quella gia' salvata.
    $host      = trim((string)($_POST['smtp_host'] ?? ''));
    $mittente  = trim((string)($_POST['smtp_mittente'] ?? ''));
    if ($host === '' || !filter_var($mittente, FILTER_VALIDATE_EMAIL)) {
        errore('Compila almeno il server SMTP e un\'email mittente valida prima di provare.');
    }
    $porta     = max(1, min(65535, (int)($_POST['smtp_porta'] ?? 587)));
    $sicurezza = in_array($_POST['smtp_sicurezza'] ?? '', ['tls', 'ssl', 'nessuna'], true) ? $_POST['smtp_sicurezza'] : 'tls';
    $utente    = trim((string)($_POST['smtp_utente'] ?? ''));
    $password  = trim((string)($_POST['smtp_password'] ?? '')) !== '' ? (string)$_POST['smtp_password'] : (string)impostazione('smtp_password', '');
    $nomeMitt  = trim((string)($_POST['smtp_nome_mittente'] ?? '')) ?: APP_NOME;

    $a = (string)($_SESSION['utente']['user'] ?? '');
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
        errore('Il tuo account non ha un\'email valida a cui mandare la prova.');
    }

    $esito = email_smtp_invia(
        $host, $porta, $sicurezza, $utente, $password, $mittente, $nomeMitt,
        $a, (string)($_SESSION['utente']['nome'] ?? ''),
        'Email di prova - ' . APP_NOME,
        "Questa e' un'email di prova mandata dal gestionale magazzino di " . APP_NOME . ".\n"
            . "Se la ricevi, la configurazione SMTP funziona.\n"
    );
    if (!$esito['ok']) {
        errore($esito['errore']);
    }
    risposta(['ok' => true, 'a' => $a]);

// ---- importazione da foglio di calcolo ---------------------------

case 'modello_csv':
    solo_admin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modello-inventario.csv"');
    echo csv_esempio();
    exit;

case 'importa':
    solo_admin();
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        errore('Sessione non valida. Ricarica la pagina.', 419);
    }
    if (empty($_FILES['foglio']['name']) || ($_FILES['foglio']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        errore('Scegli un file CSV o XLSX da caricare.');
    }

    $lettura = prepara_importazione(leggi_tabella($_FILES['foglio']['tmp_name'], $_FILES['foglio']['name']));
    if (!$lettura['ok']) {
        errore($lettura['errore']);
    }

    $modo = ($_POST['modo'] ?? 'aggiungi') === 'sostituisci' ? 'sostituisci' : 'aggiungi';
    if ($modo === 'sostituisci' && array_sum(pezzi_fuori(prestiti_leggi_tutti())) > 0) {
        errore('C\'e\' attrezzatura ancora in prestito: non posso azzerare il magazzino. Usa "aggiungi ai presenti".');
    }

    $esito = salva_importazione($lettura['articoli'], $modo);
    registra_movimento('importazione', [
        'nome' => '', 'qta' => 0,
        'nota' => $esito['nuovi'] . ' articoli importati da ' . basename($_FILES['foglio']['name']) .
                  ($esito['saltati'] ? ', ' . $esito['saltati'] . ' gia\' presenti saltati' : ''),
    ]);

    risposta(['ok' => true] + $esito + [
        'colonne'  => $lettura['colonne'],
        'ignorate' => $lettura['ignorate'],
        'scartate' => count($lettura['scartate']),
    ]);

// ---- foto degli articoli ----------------------------------------

case 'foto_carica':
    solo_admin();
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        errore('Sessione non valida. Ricarica la pagina.', 419);
    }
    $idArt = (string)($_POST['id'] ?? '');
    if ($idArt === '' || empty($_FILES['foto'])) {
        errore('Scegli una foto da caricare.');
    }

    $caricata = foto_salva($idArt, $_FILES['foto']);
    if (!$caricata['ok']) {
        errore($caricata['errore']);
    }

    $esito = store_transazione(function () use ($idArt, $caricata) {
        $inv = store_read('inventario');
        foreach ($inv as $k => $a) {
            if ($a['id'] === $idArt) {
                if (!foto_condivisa($inv, $a['foto'] ?? '', $idArt)) {
                    foto_cancella($a['foto'] ?? '');
                }
                $inv[$k]['foto'] = $caricata['nome'];
                store_write('inventario', $inv);
                registra_movimento('foto', [
                    'id_articolo' => $idArt,
                    'nome'        => trim($a['articolo'] . ' ' . $a['tipo']),
                    'qta'         => 0,
                    'nota'        => 'Foto aggiornata',
                ]);
                return ['ok' => true, 'foto' => $caricata['nome']];
            }
        }
        foto_cancella($caricata['nome']);
        return ['ok' => false, 'errore' => 'Articolo non trovato.'];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

case 'foto_elimina':
    solo_admin();
    verifica_csrf($in);
    $idArt = (string)($in['id'] ?? '');

    $esito = store_transazione(function () use ($idArt) {
        $inv = store_read('inventario');
        foreach ($inv as $k => $a) {
            if ($a['id'] === $idArt) {
                if (!foto_condivisa($inv, $a['foto'] ?? '', $idArt)) {
                    foto_cancella($a['foto'] ?? '');
                }
                $inv[$k]['foto'] = '';
                store_write('inventario', $inv);
                return ['ok' => true];
            }
        }
        return ['ok' => false, 'errore' => 'Articolo non trovato.'];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

// Diverso da foto_elimina qui sopra: quello stacca la foto da UN
// articolo (la cancella dal disco solo se non serve piu' altrove).
// Questo la toglie dal server per davvero, e la stacca da tutti gli
// articoli che la usano ancora: serve dalla galleria delle foto
// gia' presenti, per fare pulizia di quelle che non servono piu'.
case 'foto_elimina_ovunque':
    solo_admin();
    verifica_csrf($in);
    $nomeFoto = basename((string)($in['foto'] ?? ''));

    $esito = store_transazione(function () use ($nomeFoto) {
        $inv = store_read('inventario');
        if ($nomeFoto === '' || !foto_condivisa($inv, $nomeFoto, '')) {
            return ['ok' => false, 'errore' => 'Questa foto non esiste piu\'. Ricarica la pagina.'];
        }
        $tolta = 0;
        foreach ($inv as $k => $a) {
            if (($a['foto'] ?? '') === $nomeFoto) {
                $inv[$k]['foto'] = '';
                $tolta++;
            }
        }
        store_write('inventario', $inv);
        foto_cancella($nomeFoto);
        registra_movimento('foto', [
            'nome' => '',
            'qta'  => 0,
            'nota' => 'Foto eliminata dal server' . ($tolta > 1 ? ', tolta da ' . $tolta . ' articoli' : ''),
        ]);
        return ['ok' => true];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

case 'foto_assegna':
    solo_admin();
    verifica_csrf($in);
    $idArt    = (string)($in['id'] ?? '');
    $nomeFoto = basename((string)($in['foto'] ?? ''));

    $esito = store_transazione(function () use ($idArt, $nomeFoto) {
        $inv = store_read('inventario');
        // nessun id di articolo e' mai una stringa vuota: questa chiamata
        // controlla solo che la foto sia davvero in uso da qualche scheda,
        // cosi' non si puo' assegnare un nome file a caso.
        if ($nomeFoto === '' || !foto_condivisa($inv, $nomeFoto, '')) {
            return ['ok' => false, 'errore' => 'Questa foto non esiste piu\'. Ricarica la pagina.'];
        }
        foreach ($inv as $k => $a) {
            if ($a['id'] === $idArt) {
                if ($a['foto'] === $nomeFoto) {
                    return ['ok' => true, 'foto' => $nomeFoto];
                }
                if (!foto_condivisa($inv, $a['foto'] ?? '', $idArt)) {
                    foto_cancella($a['foto'] ?? '');
                }
                $inv[$k]['foto'] = $nomeFoto;
                store_write('inventario', $inv);
                registra_movimento('foto', [
                    'id_articolo' => $idArt,
                    'nome'        => trim($a['articolo'] . ' ' . $a['tipo']),
                    'qta'         => 0,
                    'nota'        => 'Foto scelta tra quelle gia\' presenti',
                ]);
                return ['ok' => true, 'foto' => $nomeFoto];
            }
        }
        return ['ok' => false, 'errore' => 'Articolo non trovato.'];
    });

    risposta($esito, $esito['ok'] ? 200 : 400);

// ---- aggiornamenti ----------------------------------------------
// Qui non si scarica e non si installa niente: si guarda soltanto se
// e' uscita una versione nuova e si aiuta chi deve caricarla a mano.

case 'aggiornamenti_controlla':
    solo_superadmin();
    $forza = isset($_GET['forza']);
    $esito = agg_controlla($forza);
    $esito['php']             = PHP_VERSION;
    // una versione che chiede un PHP piu' recente va detta, altrimenti
    // l'installazione resta ferma per sempre senza che si capisca perche'
    if (!empty($esito['php_minimo']) && version_compare(PHP_VERSION, $esito['php_minimo'], '<')) {
        $esito['php_insufficiente'] = true;
    }
    risposta($esito);

case 'stato_file':
    solo_superadmin();
    $modificati = agg_file_modificati();
    foreach ($modificati['file'] as $k => $m) {
        $modificati['file'][$k]['consiglio'] = agg_consiglio($m['file']);
    }
    risposta([
        'ok'       => true,
        'versione' => APP_VERSIONE,
        'noto'     => $modificati['noto'],
        'file'     => $modificati['file'],
        'locali'   => [
            'inc/config-locale.php'   => is_file(__DIR__ . '/inc/config-locale.php'),
            'assets/stile-locale.css' => is_file(__DIR__ . '/assets/stile-locale.css'),
        ],
    ]);

// Lo zip porta con se' utenti.json e impostazioni.json, cioe' gli hash
// delle password e il segreto che firma il cookie dei soci: se lo
// scarica solo chi ha gia' le chiavi di casa.
case 'backup_scarica':
    solo_superadmin();
    if (!csrf_valido($_GET['csrf'] ?? null)) {
        errore('Sessione non valida. Ricarica la pagina.', 419);
    }
    $backup = agg_backup_crea();
    if (!$backup['ok']) {
        errore($backup['errore']);
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="magazzino-' . $backup['nome'] . '"');
    header('Content-Length: ' . filesize($backup['file']));
    header('Cache-Control: no-store');
    readfile($backup['file']);
    @unlink($backup['file']);          // era di passaggio: non resta in giro
    exit;

default:
    errore('Azione sconosciuta.', 404);
}
