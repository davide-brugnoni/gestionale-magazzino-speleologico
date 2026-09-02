<?php
// ---------------------------------------------------------------
// Accesso amministratori
// ---------------------------------------------------------------

// ---------------------------------------------------------------
// Regole della password. Sono le stesse che assets/password.js
// mostra mentre si digita: se cambi qui, cambia anche la'.
// ---------------------------------------------------------------

function password_regole(): array
{
    return [
        ['etichetta' => 'almeno 8 caratteri',      'prova' => '/.{8,}/'],
        ['etichetta' => 'una lettera minuscola',   'prova' => '/[a-z]/'],
        ['etichetta' => 'una lettera maiuscola',   'prova' => '/[A-Z]/'],
        ['etichetta' => 'un numero',               'prova' => '/[0-9]/'],
    ];
}

/** Controlla la password contro le regole. Elenca cosa manca. */
function password_valida(string $password): array
{
    $mancanti = [];
    foreach (password_regole() as $r) {
        if (!preg_match($r['prova'], $password)) {
            $mancanti[] = $r['etichetta'];
        }
    }
    if ($mancanti) {
        return ['ok' => false, 'errore' => 'Alla password manca ancora: ' . implode(', ', $mancanti) . '.'];
    }
    return ['ok' => true, 'errore' => ''];
}

function utenti_esistono(): bool
{
    return count(store_read('utenti')) > 0;
}

// ---------------------------------------------------------------
// Il Superadmin
//
// E' chi ha fatto l'installazione, salvo passaggio di mano. E' uno
// solo, e il suo id sta in impostazioni.json: non serve un campo su
// ogni account per dire "no" a tutti tranne uno.
//
// Solo lui puo': creare e revocare amministratori, reimpostare le
// loro password, cambiare le impostazioni del gruppo e il codice
// dell'area soci, e usare la scheda Aggiornamenti.
// ---------------------------------------------------------------

/**
 * L'id del Superadmin.
 *
 * Se l'id salvato non corrisponde piu' a nessun account - succede
 * solo mettendo mano ai file a mano o ripristinando un backup
 * vecchio - vale il primo creato, che e' anche il piu' probabile
 * fondatore: nessuno deve poter restare chiuso fuori di casa.
 * Il ripiego non riscrive l'impostazione (una pagina letta non deve
 * salvare niente): per rimetterla a posto basta un passaggio di
 * ruolo dalla scheda Accessi.
 */
function superadmin_id(): string
{
    $utenti = store_read('utenti');
    if (!$utenti) {
        return '';
    }
    $scelto = (string)impostazione('superadmin_id', '');
    foreach ($utenti as $u) {
        if ($u['id'] === $scelto) {
            return $scelto;
        }
    }
    return (string)($utenti[0]['id'] ?? '');
}

/** Il nome del Superadmin, da mostrare agli altri: sanno a chi chiedere. */
function superadmin_nome(): string
{
    $id = superadmin_id();
    foreach (store_read('utenti') as $u) {
        if ($u['id'] === $id) {
            return (string)$u['nome'];
        }
    }
    return '';
}

function e_superadmin(): bool
{
    static $esito = null;
    if ($esito === null) {
        $esito = e_admin() && ($_SESSION['utente']['id'] ?? '') === superadmin_id();
    }
    return $esito;
}

function utente_crea(string $user, string $password, string $nome): array
{
    $user = strtolower(trim($user));
    if ($user === '') {
        return ['ok' => false, 'errore' => 'Serve un nome utente.'];
    }
    $regola = password_valida($password);
    if (!$regola['ok']) {
        return ['ok' => false, 'errore' => $regola['errore']];
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

function utente_cambia_password(string $id, string $attuale, string $nuova): array
{
    $regola = password_valida($nuova);
    if (!$regola['ok']) {
        return ['ok' => false, 'errore' => $regola['errore']];
    }
    return store_transazione(function () use ($id, $attuale, $nuova) {
        $utenti = store_read('utenti');
        foreach ($utenti as $indice => $u) {
            if ($u['id'] === $id) {
                if (!password_verify($attuale, $u['hash'])) {
                    return ['ok' => false, 'errore' => 'La password attuale non e\' corretta.'];
                }
                $utenti[$indice]['hash'] = password_hash($nuova, PASSWORD_DEFAULT);
                // scelta da se': l'obbligo di cambiarla e' finito qui
                $utenti[$indice]['cambio_richiesto'] = false;
                store_write('utenti', $utenti);
                return ['ok' => true];
            }
        }
        return ['ok' => false, 'errore' => 'Amministratore non trovato.'];
    });
}

/**
 * Reimposta la password di un amministratore senza chiedere quella
 * vecchia: la usa il Superadmin quando qualcuno la dimentica.
 * La provvisoria vale per un accesso solo, poi il diretto interessato
 * deve sceglierne una sua (vedi deve_cambiare_password()).
 */
function utente_imposta_password(string $id, string $nuova): array
{
    $regola = password_valida($nuova);
    if (!$regola['ok']) {
        return ['ok' => false, 'errore' => $regola['errore']];
    }
    return store_transazione(function () use ($id, $nuova) {
        $utenti = store_read('utenti');
        foreach ($utenti as $indice => $u) {
            if ($u['id'] === $id) {
                $utenti[$indice]['hash']             = password_hash($nuova, PASSWORD_DEFAULT);
                $utenti[$indice]['cambio_richiesto'] = true;
                store_write('utenti', $utenti);
                return ['ok' => true, 'nome' => $u['nome']];
            }
        }
        return ['ok' => false, 'errore' => 'Amministratore non trovato.'];
    });
}

function utente_elimina(string $id): array
{
    return store_transazione(function () use ($id) {
        $utenti = store_read('utenti');
        if (count($utenti) <= 1) {
            return ['ok' => false, 'errore' => 'Deve restare almeno un amministratore.'];
        }
        if ($id !== '' && $id === superadmin_id()) {
            return ['ok' => false, 'errore' => 'Il Superadmin non si puo\' revocare. Passa prima il ruolo a un altro amministratore.'];
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
            // password reimpostata dal Superadmin: vale per questo accesso
            // e basta, poi se ne sceglie una propria
            $_SESSION['cambio_password'] = !empty($u['cambio_richiesto']);
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
    if (e_admin()) {
        return true;
    }
    // Il gruppo puo' aver scelto gli account personali al posto del
    // codice condiviso: in quel caso conta solo la sessione del
    // socio, il codice non c'entra piu' (vedi inc/soci_auth.php).
    if (accesso_soci() === 'account') {
        return account_socio_autorizzato();
    }
    if (!serve_codice_soci()) {
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

/**
 * Sta entrando con una password provvisoria, messa dal Superadmin?
 * Finche' non se ne sceglie una sua non va da nessuna parte.
 */
function deve_cambiare_password(): bool
{
    return e_admin() && !empty($_SESSION['cambio_password']);
}

function richiedi_admin(): void
{
    if (!e_admin()) {
        header('Location: login.php');
        exit;
    }
    if (deve_cambiare_password()) {
        header('Location: cambia-password.php');
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
