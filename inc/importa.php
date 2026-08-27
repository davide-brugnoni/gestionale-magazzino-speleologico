<?php
// ---------------------------------------------------------------
// Importazione dell'inventario da foglio di calcolo.
// Legge CSV (qualsiasi separatore) e XLSX senza librerie esterne.
// ---------------------------------------------------------------

/** Colonne riconosciute e i nomi accettati per ognuna. */
function colonne_attese(): array
{
    return [
        'categoria'     => ['categoria', 'tipologia', 'gruppo', 'famiglia', 'reparto'],
        'articolo'      => ['articolo', 'materiale', 'oggetto', 'descrizione', 'nome'],
        'tipo'          => ['tipo', 'misura', 'modello', 'variante', 'taglia', 'lunghezza'],
        'quantita'      => ['quantita', 'quntita', 'quantit', 'qta', 'qt', 'qty', 'pezzi', 'pz',
                            'numero', 'nr', 'giacenza', 'totale', 'sumdiquntita', 'sumdiquantita'],
        'soglia_minima' => ['soglia', 'sogliaminima', 'minimo', 'minima', 'scortaminima', 'scorta'],
        'da_comprare'   => ['dacomprare', 'dacomprre', 'dacomprare', 'daacquistare', 'daordinare',
                            'daordinre', 'mancanti', 'mancano'],
        'note'          => ['note', 'nota', 'osservazioni', 'commento'],
    ];
}

/** Nome leggibile di una colonna, per i messaggi a video. */
function etichetta_campo(string $campo): string
{
    return [
        'categoria'     => 'Categoria',
        'articolo'      => 'Articolo',
        'tipo'          => 'Tipo',
        'quantita'      => 'Quantita',
        'soglia_minima' => 'Soglia minima',
        'da_comprare'   => 'Da comprare',
        'note'          => 'Note',
    ][$campo] ?? $campo;
}

/** Normalizza un'intestazione: minuscole, senza accenti ne' spazi. */
function chiave_intestazione(string $t): string
{
    $t = function_exists('mb_strtolower') ? mb_strtolower(trim($t), 'UTF-8') : strtolower(trim($t));
    $t = strtr($t, [
        'à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u', 'ç' => 'c',
        'À' => 'a', 'È' => 'e', 'É' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u',
    ]);
    return preg_replace('/[^a-z0-9]/', '', $t);
}

// --------------------------------------------------- lettura file

/** Indovina il separatore guardando la prima riga. */
function separatore_csv(string $riga): string
{
    $migliore = ';';
    $max = 0;
    foreach ([';', ',', "\t", '|'] as $sep) {
        $n = substr_count($riga, $sep);
        if ($n > $max) {
            $max = $n;
            $migliore = $sep;
        }
    }
    return $migliore;
}

function leggi_csv(string $percorso): array
{
    $testo = file_get_contents($percorso);
    if ($testo === false) {
        return [];
    }
    $testo = preg_replace('/^\xEF\xBB\xBF/', '', $testo);      // toglie il BOM di Excel
    if (!mb_check_encoding($testo, 'UTF-8')) {
        $testo = mb_convert_encoding($testo, 'UTF-8', 'Windows-1252');
    }

    $prima = strtok($testo, "\r\n");
    $sep   = separatore_csv((string)$prima);

    $tmp = fopen('php://temp', 'r+');
    fwrite($tmp, $testo);
    rewind($tmp);

    $righe = [];
    while (($r = fgetcsv($tmp, 0, $sep)) !== false) {
        if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) {
            continue;
        }
        $righe[] = array_map(fn($c) => trim((string)$c), $r);
    }
    fclose($tmp);
    return $righe;
}

/** Da "BC" a 2 (indice di colonna, zero based). */
function colonna_a_indice(string $rif): int
{
    preg_match('/^([A-Z]+)/', strtoupper($rif), $m);
    $n = 0;
    foreach (str_split($m[1] ?? 'A') as $c) {
        $n = $n * 26 + (ord($c) - 64);
    }
    return max(0, $n - 1);
}

function leggi_xlsx(string $percorso): array
{
    if (!class_exists('ZipArchive')) {
        return ['errore' => 'Sul server manca il supporto ZIP: salva il foglio come CSV e ricaricalo.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($percorso) !== true) {
        return ['errore' => 'Non riesco ad aprire il file XLSX.'];
    }

    // testi condivisi
    $condivisi = [];
    $xmlStr = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlStr !== false) {
        $x = @simplexml_load_string($xmlStr);
        if ($x) {
            foreach ($x->si as $si) {
                $condivisi[] = trim((string)(isset($si->t) ? $si->t : implode('', array_map(
                    fn($r) => (string)$r->t,
                    iterator_to_array($si->r ?? [])
                ))));
            }
        }
    }

    // primo foglio
    $nomeFoglio = 'xl/worksheets/sheet1.xml';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (strpos($n, 'xl/worksheets/sheet') === 0) {
            $nomeFoglio = $n;
            break;
        }
    }
    $xmlFoglio = $zip->getFromName($nomeFoglio);
    $zip->close();

    if ($xmlFoglio === false) {
        return ['errore' => 'Il file XLSX non contiene fogli leggibili.'];
    }
    $x = @simplexml_load_string($xmlFoglio);
    if (!$x) {
        return ['errore' => 'Il file XLSX non e\' leggibile.'];
    }

    $righe = [];
    foreach ($x->sheetData->row as $riga) {
        $cellePiene = [];
        foreach ($riga->c as $c) {
            $idx  = colonna_a_indice((string)$c['r']);
            $tipo = (string)$c['t'];
            if ($tipo === 's') {
                $v = $condivisi[(int)$c->v] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $v = trim((string)$c->is->t);
            } else {
                $v = trim((string)$c->v);
            }
            $cellePiene[$idx] = $v;
        }
        if (!$cellePiene) {
            continue;
        }
        $larghezza = max(array_keys($cellePiene)) + 1;
        $piatta = [];
        for ($i = 0; $i < $larghezza; $i++) {
            $piatta[] = $cellePiene[$i] ?? '';
        }
        if (trim(implode('', $piatta)) !== '') {
            $righe[] = $piatta;
        }
    }
    return $righe;
}

/** Legge il file caricato e restituisce le righe grezze. */
function leggi_tabella(string $percorso, string $nomeOriginale): array
{
    $est = strtolower(pathinfo($nomeOriginale, PATHINFO_EXTENSION));
    if ($est === 'xls') {
        return ['errore' => 'Il formato .xls vecchio non e\' supportato. Apri il file e salvalo come .xlsx oppure come CSV.'];
    }
    if ($est === 'xlsx' || $est === 'xlsm') {
        return leggi_xlsx($percorso);
    }
    if (in_array($est, ['csv', 'txt', 'tsv'], true)) {
        return leggi_csv($percorso);
    }
    return ['errore' => 'Carica un file CSV o XLSX.'];
}

// --------------------------------------------------- importazione

/**
 * Trasforma le righe grezze in articoli.
 * Restituisce: articoli, colonne riconosciute, righe scartate.
 */
function prepara_importazione(array $righe): array
{
    if (isset($righe['errore'])) {
        return ['ok' => false, 'errore' => $righe['errore']];
    }
    if (count($righe) < 2) {
        return ['ok' => false, 'errore' => 'Il file sembra vuoto: serve una riga di intestazione e almeno un articolo.'];
    }

    $intestazioni = array_shift($righe);
    $mappa  = [];
    $attese = colonne_attese();
    foreach ($intestazioni as $i => $testo) {
        $k = chiave_intestazione((string)$testo);
        foreach ($attese as $campo => $alias) {
            if (in_array($k, $alias, true)) {
                $mappa[$campo] = $i;
                break;
            }
        }
    }

    if (!isset($mappa['articolo'])) {
        return ['ok' => false, 'errore' => 'Non trovo la colonna "Articolo". Controlla la riga di intestazione: scarica il modello di esempio per vedere i nomi giusti.'];
    }
    if (!isset($mappa['quantita'])) {
        return ['ok' => false, 'errore' => 'Non trovo la colonna "Quantita". Controlla la riga di intestazione.'];
    }

    $prendi = function (array $r, ?int $i): string {
        return $i === null ? '' : trim((string)($r[$i] ?? ''));
    };

    $articoli = [];
    $scartate = [];
    $visti    = [];

    foreach ($righe as $n => $r) {
        $articolo = $prendi($r, $mappa['articolo'] ?? null);
        if ($articolo === '') {
            continue;                                   // riga vuota o separatore
        }
        $qtaTesto = str_replace(',', '.', $prendi($r, $mappa['quantita'] ?? null));
        if ($qtaTesto !== '' && !is_numeric($qtaTesto)) {
            $scartate[] = ['riga' => $n + 2, 'articolo' => $articolo, 'motivo' => 'quantita non numerica: ' . $qtaTesto];
            continue;
        }

        $categoria = $prendi($r, $mappa['categoria'] ?? null);
        $tipo      = $prendi($r, $mappa['tipo'] ?? null);
        $qta       = max(0, (int)round((float)$qtaTesto));

        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $categoria . '-' . $articolo . '-' . $tipo));
        $id   = trim($base, '-');
        if ($id === '') {
            $id = 'articolo';
        }
        while (in_array($id, $visti, true)) {
            $id .= '-2';
        }
        $visti[] = $id;

        $articoli[] = [
            'id'               => $id,
            'categoria'        => $categoria !== '' ? $categoria : 'Vario',
            'articolo'         => $articolo,
            'tipo'             => $tipo,
            'quantita'         => $qta,
            'quantita_teorica' => $qta,
            'da_comprare'      => max(0, (int)$prendi($r, $mappa['da_comprare'] ?? null)),
            'soglia_minima'    => max(0, (int)$prendi($r, $mappa['soglia_minima'] ?? null)),
            'prestabile'       => true,
            'foto'             => '',
            'note'             => $prendi($r, $mappa['note'] ?? null),
            'creato_il'        => date('c'),
        ];
    }

    if (!$articoli) {
        return ['ok' => false, 'errore' => 'Non ho trovato nessun articolo valido nel file.'];
    }

    return [
        'ok'         => true,
        'articoli'   => $articoli,
        'colonne'    => array_keys($mappa),
        'ignorate'   => array_values(array_diff(array_keys(colonne_attese()), array_keys($mappa))),
        'scartate'   => $scartate,
        'intestazioni' => $intestazioni,
    ];
}

/**
 * Scrive gli articoli in inventario.
 * $modo: 'sostituisci' azzera il magazzino, 'aggiungi' accoda i nuovi.
 */
function salva_importazione(array $articoli, string $modo): array
{
    return store_transazione(function () use ($articoli, $modo) {
        $attuale = $modo === 'sostituisci' ? [] : store_read('inventario');
        $idEsistenti = array_column($attuale, 'id');
        $nuovi = 0;
        $saltati = 0;

        foreach ($articoli as $a) {
            if (in_array($a['id'], $idEsistenti, true)) {
                $saltati++;
                continue;                               // gia' presente: non lo tocco
            }
            $attuale[] = $a;
            $idEsistenti[] = $a['id'];
            $nuovi++;
        }

        store_write('inventario', $attuale);
        return ['ok' => true, 'nuovi' => $nuovi, 'saltati' => $saltati, 'totale' => count($attuale)];
    });
}

/** Il CSV di esempio, con le intestazioni giuste e qualche riga compilata. */
function csv_esempio(): string
{
    $righe = [
        ['Categoria', 'Articolo', 'Tipo', 'Quantita', 'Soglia minima', 'Da comprare', 'Note'],
        ['Corde', 'Corda 10mm', '40 m', '2', '2', '0', 'una da lavare'],
        ['Corde', 'Corda 9mm', '80 m', '1', '0', '0', ''],
        ['Armo', 'Moschettoni', 'Ovali lega', '67', '40', '20', ''],
        ['Armo', 'Placchette', 'Ritorte da 8', '48', '30', '0', ''],
        ['Armo', 'Maglie rapide', 'Inox da 8', '0', '10', '10', 'da ordinare'],
        ['Attrezzatura personale', 'Caschi', '', '21', '15', '0', ''],
        ['Attrezzatura personale', 'Bloccanti ventrali', 'Croll', '10', '12', '2', ''],
        ['Trasporto', 'Sacchi', 'Tubolare grande', '8', '10', '2', ''],
        ['Rilievo', 'Distox', '', '2', '0', '0', 'uno da tarare'],
        ['Vario', 'Trapano', '', '2', '0', '0', ''],
    ];
    $out = "\xEF\xBB\xBF";                              // BOM: Excel legge gli accenti
    foreach ($righe as $r) {
        $out .= implode(';', array_map(function ($c) {
            return strpbrk($c, ";\"\n") !== false ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }, $r)) . "\r\n";
    }
    return $out;
}
