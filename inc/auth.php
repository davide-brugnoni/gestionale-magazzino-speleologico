<?php
// ---------------------------------------------------------------
// Accesso amministratori
// ---------------------------------------------------------------

function utenti_esistono(): bool
{
    return count(store_read('utenti')) > 0;
}

function utente_crea(string $user, string $password, string $nome): array
{
    $user = strtolower(trim($user));
    if ($user === '' || strlen($password) < 8) {
        return ['ok' => false, 'errore' => 'Serve un nome utente e una password di almeno 8 caratteri.'];
    }
    return store_transazione(function () use ($user, $password, $nome) {
        $utenti = store_read('utenti');
        foreach ($utenti as $u) {
            if ($u['user'] === $user) {
                return ['ok' => false, 'errore' => 'Questo nome utente esiste gia\'.'];
            }
        }
        $utenti[] = [
            'id'        => nuovo_id('usr'),
            'user'      => $user,
            'nome'      => trim($nome) !== '' ? trim($nome) : $user,
            'hash'      => password_hash($password, PASSWORD_DEFAULT),
            'creato_il' => adesso(),
        ];
        store_write('utenti', $utenti);
        return ['ok' => true];
    });
}

function utente_elimina(string $id): array
{
    return store_transazione(function () use ($id) {
        $utenti = store_read('utenti');
        if (count($utenti) <= 1) {
            return ['ok' => false, 'errore' => 'Deve restare almeno un amministratore.'];
        }
        $nuovi = array_values(array_filter($utenti, fn($u) => $u['id'] !== $id));
        if (count($nuovi) === count($utenti)) {
            return ['ok' => false, 'errore' => 'Amministratore non trovato.'];
        }
        store_write('utenti', $nuovi);
        return ['ok' => true];
    });
}

// ---------------------------------------------------------------
// Freno ai tentativi di indovinare la password.
// Dopo 5 errori dallo stesso indirizzo l'accesso si blocca per 15 minuti,
// e il blocco raddoppia a ogni tornata successiva (max 2 ore).
// ---------------------------------------------------------------

function chiave_tentativi(): string
{
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'ignoto');
}

/** Secondi di attesa ancora da scontare, 0 se si puo' provare. */
function attesa_residua(): int
{
    $reg = store_read('tentativi');
    $k   = chiave_tentativi();
    if (!isset($reg[$k])) {
        return 0;
    }
    return max(0, (int)$reg[$k]['bloccato_fino'] - time());
}

function segna_tentativo(bool $riuscito): void
{
    store_transazione(function () use ($riuscito) {
        $reg = store_read('tentativi');
        $k   = chiave_tentativi();

        if ($riuscito) {
            unset($reg[$k]);
        } else {
            $v = $reg[$k] ?? ['errori' => 0, 'blocchi' => 0, 'bloccato_fino' => 0];
            $v['errori']++;
            $v['ultimo'] = time();
            if ($v['errori'] >= 5) {
                $v['blocchi']++;
                $v['errori'] = 0;
                $minuti = min(120, 15 * (2 ** ($v['blocchi'] - 1)));
                $v['bloccato_fino'] = time() + $minuti * 60;
            }
            $reg[$k] = $v;
        }

        // ripulisce le voci vecchie di oltre un giorno
        foreach ($reg as $id => $v) {
            if ((int)($v['ultimo'] ?? 0) < time() - 86400 && (int)($v['bloccato_fino'] ?? 0) < time()) {
                unset($reg[$id]);
            }
        }
        store_write('tentativi', $reg);
    });
}

function login(string $user, string $password): bool
{
    if (attesa_residua() > 0) {
        return false;
    }
    $user = strtolower(trim($user));
    foreach (store_read('utenti') as $u) {
        if ($u['user'] === $user && password_verify($password, $u['hash'])) {
            segna_tentativo(true);
            session_regenerate_id(true);           // nuovo identificativo di sessione
            $_SESSION['utente'] = ['id' => $u['id'], 'user' => $u['user'], 'nome' => $u['nome']];
            $_SESSION['nata']   = time();
            return true;
        }
    }
    usleep(400000);                                 // rallenta i tentativi a raffica
    segna_tentativo(false);
    return false;
}

// ---------------------------------------------------------------
// Codice d'ingresso dell'area soci (facoltativo)
// ---------------------------------------------------------------

function serve_codice_soci(): bool
{
    return impostazione('codice_soci_hash', '') !== '';
}

/** Firma del dispositivo ricordato: cambia se cambia il codice o il segreto. */
function impronta_codice(): string
{
    return hash_hmac('sha256', 'soci|' . impostazione('codice_soci_hash', ''), impostazione('segreto', 'x'));
}

/** Imposta o rimuove il codice del gruppo. Stringa vuota = area soci aperta. */
function imposta_codice_soci(string $codice): void
{
    $codice = trim($codice);
    salva_impostazioni([
        'codice_soci_hash' => $codice === '' ? '' : password_hash($codice, PASSWORD_DEFAULT),
    ]);
}

function soci_autorizzato(): bool
{
    if (!serve_codice_soci() || e_admin()) {
        return true;
    }
    if (!empty($_SESSION['soci'])) {
        return true;
    }
    if (isset($_COOKIE['BVSOCI']) && hash_equals(impronta_codice(), $_COOKIE['BVSOCI'])) {
        $_SESSION['soci'] = true;
        return true;
    }
    return false;
}

function soci_entra(string $codice, bool $ricorda = true): bool
{
    $hash = impostazione('codice_soci_hash', '');
    if (attesa_residua() > 0 || $hash === '' || !password_verify(trim($codice), $hash)) {
        segna_tentativo(false);
        usleep(400000);
        return false;
    }
    segna_tentativo(true);
    $_SESSION['soci'] = true;
    if ($ricorda) {
        setcookie('BVSOCI', impronta_codice(), [
            'expires'  => time() + CODICE_GIORNI * 86400,
            'path'     => '/',
            'httponly' => true,
            'secure'   => in_https(),
            'samesite' => 'Lax',
        ]);
    }
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function e_admin(): bool
{
    return isset($_SESSION['utente']);
}

function richiedi_admin(): void
{
    if (!e_admin()) {
        header('Location: login.php');
        exit;
    }
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
