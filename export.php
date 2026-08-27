<?php
require_once __DIR__ . '/inc/config.php';
store_init();
richiedi_admin();

$cosa = $_GET['cosa'] ?? 'inventario';
$nome = 'bv-' . preg_replace('/[^a-z]/', '', $cosa) . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nome . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel legge gli accenti

function riga($out, array $c): void
{
    fputcsv($out, $c, ';');
}

switch ($cosa) {

case 'aperti':
case 'storico':
    $prestiti = store_read('prestiti');
    riga($out, ['ID prelievo', 'Persona', 'Contatto', 'Dove', 'Uscita', 'Rientro previsto', 'Stato', 'Chiuso il', 'Articolo', 'Presi', 'Rientrati', 'Persi', 'Ancora fuori', 'Note']);
    foreach ($prestiti as $p) {
        if ($cosa === 'aperti' && $p['stato'] === 'chiuso') {
            continue;
        }
        foreach ($p['righe'] as $r) {
            $res = (int)$r['qta'] - (int)$r['qta_rientrata'] - (int)$r['qta_persa'];
            if ($cosa === 'aperti' && $res <= 0) {
                continue;
            }
            riga($out, [
                $p['id'], $p['persona'], $p['contatto'] ?? '', $p['destinazione'] ?? '',
                date('d/m/Y H:i', strtotime($p['uscita'])),
                $p['rientro_atteso'] ?? '', $p['stato'],
                $p['chiuso_il'] ? date('d/m/Y H:i', strtotime($p['chiuso_il'])) : '',
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
            date('d/m/Y H:i', strtotime($m['quando'])), $m['tipo'], $m['nome'] ?? '',
            $m['qta'] ?? 0, $m['giacenza'] ?? '', $m['da'] ?? '', $m['nota'] ?? '',
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
