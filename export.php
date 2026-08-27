<?php
require_once __DIR__ . '/inc/config.php';
store_init();
richiedi_admin();

$cosa = $_GET['cosa'] ?? 'inventario';

// --------------------------------------------------- periodo scelto
// I due campi data dello Storico arrivano qui come dal / al (AAAA-MM-GG)
// e filtrano sulla data di uscita del prelievo. Vuoti = tutto.

function data_richiesta(string $chiave): string
{
    $v = trim((string)($_GET[$chiave] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}

$dal = data_richiesta('dal');
$al  = data_richiesta('al');

function nel_periodo(array $p, string $dal, string $al): bool
{
    $giorno = substr((string)($p['uscita'] ?? ''), 0, 10);
    if ($dal !== '' && $giorno < $dal) {
        return false;
    }
    if ($al !== '' && $giorno > $al) {
        return false;
    }
    return true;
}

/** Prelievi del periodo, i piu' recenti per primi. */
function prelievi_del_periodo(string $dal, string $al, bool $soloAperti = false): array
{
    $lista = array_filter(prestiti_leggi_tutti(), function ($p) use ($dal, $al, $soloAperti) {
        if ($soloAperti && ($p['stato'] ?? '') === 'chiuso') {
            return false;
        }
        return nel_periodo($p, $dal, $al);
    });
    usort($lista, fn($a, $b) => strcmp($b['uscita'], $a['uscita']));
    return $lista;
}

/** Categoria della riga: quella salvata col prelievo, altrimenti dall'inventario. */
function categoria_riga(array $riga): string
{
    static $cat = null;
    if (($riga['categoria'] ?? '') !== '') {
        return $riga['categoria'];
    }
    if ($cat === null) {
        $cat = [];
        foreach (inventario_completo() as $a) {
            $cat[$a['id']] = $a['categoria'];
        }
    }
    return $cat[$riga['id_articolo']] ?? '';
}

$periodo = ($dal !== '' || $al !== '') ? '-' . ($dal ?: 'inizio') . '_' . ($al ?: 'oggi') : '';
$nome    = 'bv-' . preg_replace('/[^a-z_]/', '', $cosa) . $periodo . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nome . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel legge gli accenti

function riga($out, array $c): void
{
    fputcsv($out, $c, ';');
}

function quando(?string $iso): string
{
    return $iso ? date('d/m/Y H:i', strtotime($iso)) : '';
}

switch ($cosa) {

case 'aperti':
case 'storico':
    riga($out, ['ID prelievo', 'Persona', 'Contatto', 'Dove', 'Uscita', 'Rientro previsto', 'Stato', 'Chiuso il', 'Articolo', 'Presi', 'Rientrati', 'Persi', 'Ancora fuori', 'Note']);
    foreach (prelievi_del_periodo($dal, $al, $cosa === 'aperti') as $p) {
        foreach ($p['righe'] as $r) {
            $res = (int)$r['qta'] - (int)$r['qta_rientrata'] - (int)$r['qta_persa'];
            if ($cosa === 'aperti' && $res <= 0) {
                continue;
            }
            riga($out, [
                $p['id'], $p['persona'], $p['contatto'] ?? '', $p['destinazione'] ?? '',
                quando($p['uscita']),
                $p['rientro_atteso'] ?? '', $p['stato'], quando($p['chiuso_il'] ?? null),
                $r['nome'], $r['qta'], $r['qta_rientrata'], $r['qta_persa'], $res,
                trim(($p['note'] ?? '') . ' ' . ($r['note_rientro'] ?? '')),
            ]);
        }
    }
    break;

case 'movimenti':
    riga($out, ['Quando', 'Tipo', 'Articolo', 'Variazione', 'Giacenza', 'Chi', 'Nota']);
    foreach (array_reverse(store_read('movimenti')) as $m) {
        riga($out, [
            quando($m['quando']), $m['tipo'], $m['nome'] ?? '',
            $m['qta'] ?? 0, $m['giacenza'] ?? '', $m['da'] ?? '', $m['nota'] ?? '',
        ]);
    }
    break;

// ---- materiale prestato nel periodo, riga per riga ------------------
case 'prestato':
    riga($out, ['Categoria', 'Articolo', 'Persona', 'Contatto', 'Dove', 'Uscita', 'Rientro previsto', 'Stato', 'Chiuso il', 'Presi', 'Rientrati', 'Persi', 'Ancora fuori', 'Note']);

    $righe = [];
    foreach (prelievi_del_periodo($dal, $al) as $p) {
        foreach ($p['righe'] as $r) {
            $righe[] = [
                categoria_riga($r), $r['nome'], $p['persona'], $p['contatto'] ?? '', $p['destinazione'] ?? '',
                quando($p['uscita']), $p['rientro_atteso'] ?? '', $p['stato'], quando($p['chiuso_il'] ?? null),
                (int)$r['qta'], (int)$r['qta_rientrata'], (int)$r['qta_persa'],
                (int)$r['qta'] - (int)$r['qta_rientrata'] - (int)$r['qta_persa'],
                trim(($p['note'] ?? '') . ' ' . ($r['note_rientro'] ?? '')),
            ];
        }
    }
    // ordinate per categoria e articolo, poi dalla piu' recente
    usort($righe, fn($a, $b) => [$a[0], $a[1], $b[5]] <=> [$b[0], $b[1], $a[5]]);
    foreach ($righe as $r) {
        riga($out, $r);
    }
    break;

// ---- stesso periodo, un totale per articolo -------------------------
case 'prestato_riepilogo':
    riga($out, ['Categoria', 'Articolo', 'Prelievi', 'Pezzi usciti', 'Rientrati', 'Persi', 'Ancora fuori', 'Ultimo prelievo']);

    $tot = [];
    foreach (prelievi_del_periodo($dal, $al) as $p) {
        foreach ($p['righe'] as $r) {
            $k = $r['id_articolo'];
            if (!isset($tot[$k])) {
                $tot[$k] = [
                    'categoria' => categoria_riga($r), 'nome' => $r['nome'],
                    'prelievi' => 0, 'usciti' => 0, 'rientrati' => 0, 'persi' => 0, 'fuori' => 0, 'ultimo' => '',
                ];
            }
            $tot[$k]['prelievi']++;
            $tot[$k]['usciti']    += (int)$r['qta'];
            $tot[$k]['rientrati'] += (int)$r['qta_rientrata'];
            $tot[$k]['persi']     += (int)$r['qta_persa'];
            $tot[$k]['fuori']     += (int)$r['qta'] - (int)$r['qta_rientrata'] - (int)$r['qta_persa'];
            if ($p['uscita'] > $tot[$k]['ultimo']) {
                $tot[$k]['ultimo'] = $p['uscita'];
            }
        }
    }
    uasort($tot, fn($a, $b) => [$a['categoria'], $a['nome']] <=> [$b['categoria'], $b['nome']]);

    foreach ($tot as $t) {
        riga($out, [
            $t['categoria'], $t['nome'], $t['prelievi'], $t['usciti'],
            $t['rientrati'], $t['persi'], $t['fuori'], quando($t['ultimo']),
        ]);
    }
    break;

default:
    riga($out, ['Categoria', 'Articolo', 'Tipo', 'A magazzino', 'In prestito', 'Disponibili', 'Inventario cartaceo', 'Mancanti', 'Da comprare', 'Soglia minima', 'Note']);
    foreach (inventario_completo() as $a) {
        $teo = (int)($a['quantita_teorica'] ?? $a['quantita']);
        riga($out, [
            $a['categoria'], $a['articolo'], $a['tipo'], $a['quantita'], $a['in_prestito'],
            $a['disponibile'], $teo, max(0, $teo - $a['quantita']),
            $a['da_comprare'] ?? 0, $a['soglia_minima'], $a['note'] ?? '',
        ]);
    }
}

fclose($out);
