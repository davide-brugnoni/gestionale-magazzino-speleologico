<?php
// ---------------------------------------------------------------
// Persistenza su file JSON (nessun database).
// Scritture atomiche + lock esclusivo per evitare conflitti.
// ---------------------------------------------------------------

function store_path(string $nome): string
{
    return DATA_DIR . '/' . basename($nome) . '.json';
}

function store_init(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    foreach (['inventario', 'prestiti', 'movimenti', 'utenti', 'tentativi', 'impostazioni'] as $f) {
        if (!file_exists(store_path($f))) {
            file_put_contents(store_path($f), in_array($f, ['tentativi', 'impostazioni'], true) ? "{}\n" : "[]\n");
        }
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Require all denied\nDeny from all\n");
    }

    if (!is_dir(FOTO_DIR)) {
        mkdir(FOTO_DIR, 0775, true);
    }
    $htFoto = FOTO_DIR . '/.htaccess';
    if (!file_exists($htFoto)) {
        // le immagini si devono vedere, ma qui dentro non deve girare codice
        file_put_contents($htFoto,
            "php_flag engine off\n" .
            "<FilesMatch \"\\.(php|phtml|phar|cgi|pl)$\">\n  Require all denied\n</FilesMatch>\n" .
            "Options -Indexes\n");
    }
}

// --------------------------- Foto degli articoli ----------------

/**
 * Salva l'immagine caricata come JPEG ridimensionato.
 * Se GD non c'e', tiene il file originale senza ridimensionarlo.
 * Restituisce il nome del file salvato oppure un messaggio di errore.
 */
function foto_salva(string $idArticolo, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errore' => 'Il caricamento della foto non e\' andato a buon fine.'];
    }
    if (($file['size'] ?? 0) > FOTO_MAX_BYTE) {
        return ['ok' => false, 'errore' => 'La foto pesa troppo: il limite e\' ' . (int)(FOTO_MAX_BYTE / 1048576) . ' MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'errore' => 'File non valido.'];
    }

    $info = @getimagesize($file['tmp_name']);
    $estensioni = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (!$info || !isset($estensioni[$info[2]])) {
        return ['ok' => false, 'errore' => 'Carica una foto in JPG, PNG, GIF o WEBP.'];
    }

    if (!is_dir(FOTO_DIR)) {
        mkdir(FOTO_DIR, 0775, true);
    }
    $sicuro = preg_replace('/[^a-z0-9\-]/', '', strtolower($idArticolo));
    $nome   = $sicuro . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$img) {
            return ['ok' => false, 'errore' => 'Non riesco a leggere questa immagine.'];
        }
        // le foto da telefono portano dietro l'orientamento nei dati EXIF
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file['tmp_name']);
            $or   = $exif['Orientation'] ?? 1;
            if ($or === 3) { $img = imagerotate($img, 180, 0); }
            if ($or === 6) { $img = imagerotate($img, -90, 0); }
            if ($or === 8) { $img = imagerotate($img, 90, 0); }
        }
        $l = imagesx($img);
        $a = imagesy($img);
        $s = min(1, FOTO_LATO / max($l, $a));
        $nl = max(1, (int)round($l * $s));
        $na = max(1, (int)round($a * $s));

        $out = imagecreatetruecolor($nl, $na);
        imagefilledrectangle($out, 0, 0, $nl, $na, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nl, $na, $l, $a);

        $nome .= '.jpg';
        imagejpeg($out, FOTO_DIR . '/' . $nome, 82);
        imagedestroy($out);
        imagedestroy($img);
    } else {
        $nome .= '.' . $estensioni[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], FOTO_DIR . '/' . $nome)) {
            return ['ok' => false, 'errore' => 'Non riesco a salvare il file. Controlla i permessi della cartella foto.'];
        }
    }

    @chmod(FOTO_DIR . '/' . $nome, 0664);
    return ['ok' => true, 'nome' => $nome];
}

function foto_cancella(?string $nome): void
{
    if (!$nome) {
        return;
    }
    $f = FOTO_DIR . '/' . basename($nome);
    if (is_file($f)) {
        @unlink($f);
    }
}

function store_read(string $nome): array
{
    $file = store_path($nome);
    if (!file_exists($file)) {
        return [];
    }
    $fp = fopen($file, 'rb');
    if (!$fp) {
        return [];
    }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $dati = json_decode($raw, true);
    return is_array($dati) ? $dati : [];
}

function store_write(string $nome, array $dati): bool
{
    $file = store_path($nome);
    $json = json_encode(
        $dati,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        return false;
    }
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0664);
    return rename($tmp, $file);
}

/**
 * Esegue una operazione leggi-modifica-scrivi in mutua esclusione.
 * Tutte le scritture dell'applicativo passano da qui.
 */
function store_transazione(callable $fn)
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    $lock = fopen(DATA_DIR . '/.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function nuovo_id(string $prefisso): string
{
    return $prefisso . '-' . date('ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
}

function adesso(): string
{
    return date('c');
}

// --------------------------- Logica di dominio ------------------

/** Somma dei pezzi ancora fuori, per id articolo. */
function pezzi_fuori(array $prestiti): array
{
    $fuori = [];
    foreach ($prestiti as $p) {
        if (($p['stato'] ?? '') === 'chiuso') {
            continue;
        }
        foreach ($p['righe'] as $r) {
            $residuo = (int)$r['qta'] - (int)($r['qta_rientrata'] ?? 0) - (int)($r['qta_persa'] ?? 0);
            if ($residuo > 0) {
                $id = $r['id_articolo'];
                $fuori[$id] = ($fuori[$id] ?? 0) + $residuo;
            }
        }
    }
    return $fuori;
}

/** Inventario arricchito con disponibilita' calcolata. */
function inventario_completo(): array
{
    $inv       = store_read('inventario');
    $prestiti  = store_read('prestiti');
    $fuori     = pezzi_fuori($prestiti);

    foreach ($inv as &$a) {
        $a['quantita']      = (int)$a['quantita'];
        $a['in_prestito']   = (int)($fuori[$a['id']] ?? 0);
        $a['disponibile']   = max(0, $a['quantita'] - $a['in_prestito']);
        $a['soglia_minima'] = (int)($a['soglia_minima'] ?? 0);
        $a['nome']          = trim($a['articolo'] . ' ' . ($a['tipo'] ?? ''));
        $a['sotto_soglia']  = $a['soglia_minima'] > 0 && $a['quantita'] < $a['soglia_minima'];
        $a['foto']          = ($a['foto'] ?? '') !== '' && is_file(FOTO_DIR . '/' . $a['foto']) ? $a['foto'] : '';
    }
    unset($a);

    usort($inv, function ($x, $y) {
        return [$x['categoria'], $x['articolo'], $x['tipo']]
           <=> [$y['categoria'], $y['articolo'], $y['tipo']];
    });
    return $inv;
}

function trova_articolo(array $inv, string $id): ?array
{
    foreach ($inv as $a) {
        if ($a['id'] === $id) {
            return $a;
        }
    }
    return null;
}

/** Giorni trascorsi dalla data di uscita. */
function giorni_da(string $iso): int
{
    $t = strtotime($iso);
    if (!$t) {
        return 0;
    }
    return (int)floor((time() - $t) / 86400);
}

function registra_movimento(string $tipo, array $dati): void
{
    $mov   = store_read('movimenti');
    $mov[] = array_merge([
        'id'      => nuovo_id('mov'),
        'tipo'    => $tipo,           // acquisto | scarto | rettifica | nuovo | eliminato | perdita
        'quando'  => adesso(),
        'da'      => $_SESSION['utente']['nome'] ?? 'sistema',
    ], $dati);
    if (count($mov) > 5000) {
        $mov = array_slice($mov, -5000);
    }
    store_write('movimenti', $mov);
}
