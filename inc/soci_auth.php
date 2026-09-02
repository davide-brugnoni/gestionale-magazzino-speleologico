<?php
// ---------------------------------------------------------------
// Account personali dei soci (email + password).
//
// E' un sistema separato dal codice di gruppo condiviso che sta in
// auth.php: qui ogni socio ha un'identita' propria, non un segreto
// che si passa a voce. I due sistemi convivono: quale dei due vale
// per l'area soci lo decide l'impostazione accesso_soci(), letta da
// soci_autorizzato() in auth.php.
//
// Ogni account vive nel suo file data/soci/<id>.json (vedi
// inc/store.php): stessa idea dei prelievi, non un unico elenco.
// ---------------------------------------------------------------

/** 'codice' (di serie, comportamento invariato) oppure 'account'. */
function accesso_soci(): string
{
    $m = (string)impostazione('accesso_soci', 'codice');
    return $m === 'account' ? 'account' : 'codice';
}

function account_socio_trova(string $id): ?array
{
    return socio_leggi($id);
}

function account_socio_trova_per_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }
    foreach (soci_leggi_tutti() as $s) {
        if (($s['email'] ?? '') === $email) {
            return $s;
        }
    }
    return null;
}

/**
 * Autoregistrazione di un socio. L'account nasce 'in_attesa': non
 * puo' entrare finche' un amministratore non lo approva dalla
 * dashboard, e finche' non conferma il suo indirizzo email (vedi
 * account_socio_verifica_email()). Non logga nessuno.
 *
 * Se la creazione riesce, il risultato porta anche 'id' e
 * 'token_verifica' (in chiaro): a chi chiama tocca mandare l'email
 * col link di conferma, fuori da questa funzione — l'invio e' I/O di
 * rete e non deve girare dentro store_transazione().
 */
function account_socio_crea(string $nome, string $email, string $password): array
{
    $nome  = trim($nome);
    $email = strtolower(trim($email));
    if (mb_strlen($nome) < 3) {
        return ['ok' => false, 'errore' => 'Scrivi nome e cognome.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'errore' => 'Scrivi un indirizzo email valido.'];
    }
    $regola = password_valida($password);
    if (!$regola['ok']) {
        return ['ok' => false, 'errore' => $regola['errore']];
    }
    return store_transazione(function () use ($nome, $email, $password) {
        if (account_socio_trova_per_email($email)) {
            return ['ok' => false, 'errore' => 'Questa email ha gia\' un account. Se lo hai dimenticato, chiedi a chi gestisce il magazzino.'];
        }
        $token = bin2hex(random_bytes(32));
        $socio = [
            'id'                  => nuovo_id('soc'),
            'nome'                => $nome,
            'email'               => $email,
            'hash'                => password_hash($password, PASSWORD_DEFAULT),
            'stato'               => 'in_attesa',
            'creato_il'           => adesso(),
            'cambio_richiesto'    => false,
            'approvato_il'        => '',
            'approvato_da'        => '',
            'email_verificata'    => false,
            'token_verifica'      => hash('sha256', $token),
            'token_verifica_scad' => date('c', time() + 48 * 3600),
            'token_reset'         => '',
            'token_reset_scad'    => '',
        ];
        if (!socio_salva($socio)) {
            return ['ok' => false, 'errore' => 'Non riesco a salvare la registrazione. Controlla i permessi della cartella data/soci.'];
        }
        return ['ok' => true, 'id' => $socio['id'], 'token_verifica' => $token];
    });
}

/**
 * Conferma l'indirizzo email di un socio, dal link mandato alla
 * registrazione. Non basta di per se' a far entrare: serve comunque
 * l'approvazione di un amministratore (vedi account_socio_login()).
 */
function account_socio_verifica_email(string $id, string $token): array
{
    return store_transazione(function () use ($id, $token) {
        $s = socio_leggi($id);
        if ($s === null) {
            return ['ok' => false, 'errore' => 'Account non trovato.'];
        }
        if (!empty($s['email_verificata'])) {
            return ['ok' => true, 'nome' => $s['nome'], 'gia_fatto' => true];
        }
        $hash = (string)($s['token_verifica'] ?? '');
        $scad = (string)($s['token_verifica_scad'] ?? '');
        if ($hash === '' || !hash_equals($hash, hash('sha256', $token))) {
            return ['ok' => false, 'errore' => 'Il link non e\' valido. Se ti sei registrato di nuovo, usa l\'email piu\' recente.'];
        }
        if ($scad === '' || strtotime($scad) < time()) {
            return ['ok' => false, 'errore' => 'Il link e\' scaduto. Chiedi a chi gestisce il magazzino di rimandartelo.'];
        }
        $s['email_verificata']    = true;
        $s['token_verifica']      = '';
        $s['token_verifica_scad'] = '';
        if (!socio_salva($s)) {
            return ['ok' => false, 'errore' => 'Non riesco a salvare la conferma.'];
        }
        return ['ok' => true, 'nome' => $s['nome']];
    });
}

/**
 * Richiede il reset della password. Il messaggio verso chi chiama e'
 * sempre lo stesso, trovato o no: non deve rivelare se un'email ha un
 * account (stesso principio di account_socio_login()). Solo il
 * risultato interno ('trovato', 'id', 'nome', 'token') dice se mandare
 * davvero l'email, e tocca a chi chiama farlo fuori dalla transazione.
 */
function account_socio_richiedi_reset(string $email): array
{
    $email = strtolower(trim($email));
    return store_transazione(function () use ($email) {
        $s = account_socio_trova_per_email($email);
        // Solo gli account attivi possono farsi mandare un link: uno in
        // attesa o disabilitato non deve poter entrare comunque.
        if ($s === null || ($s['stato'] ?? '') !== 'attivo') {
            return ['ok' => true, 'trovato' => false];
        }
        $token = bin2hex(random_bytes(32));
        $s['token_reset']      = hash('sha256', $token);
        $s['token_reset_scad'] = date('c', time() + 3600);
        if (!socio_salva($s)) {
            return ['ok' => true, 'trovato' => false];
        }
        return ['ok' => true, 'trovato' => true, 'id' => $s['id'], 'nome' => $s['nome'], 'email' => $s['email'], 'token' => $token];
    });
}

/** Sceglie una nuova password dal link di reset. */
function account_socio_reset_password(string $id, string $token, string $nuova): array
{
    $regola = password_valida($nuova);
    if (!$regola['ok']) {
        return ['ok' => false, 'errore' => $regola['errore']];
    }
    return store_transazione(function () use ($id, $token, $nuova) {
        $s = socio_leggi($id);
        if ($s === null) {
            return ['ok' => false, 'errore' => 'Account non trovato.'];
        }
        $hash = (string)($s['token_reset'] ?? '');
        $scad = (string)($s['token_reset_scad'] ?? '');
        if ($hash === '' || !hash_equals($hash, hash('sha256', $token))) {
            return ['ok' => false, 'errore' => 'Il link non e\' valido. Richiedine uno nuovo.'];
        }
        if ($scad === '' || strtotime($scad) < time()) {
            return ['ok' => false, 'errore' => 'Il link e\' scaduto. Richiedine uno nuovo.'];
        }
        $s['hash']             = password_hash($nuova, PASSWORD_DEFAULT);
        $s['cambio_richiesto'] = false;
        $s['token_reset']      = '';
        $s['token_reset_scad'] = '';
        // chi ha ricevuto e usato il link ha dimostrato di possedere quella casella
        $s['email_verificata'] = true;
        $s['token_verifica']      = '';
        $s['token_verifica_scad'] = '';
        if (!socio_salva($s)) {
            return ['ok' => false, 'errore' => 'Non riesco a salvare la nuova password.'];
        }
        return ['ok' => true, 'nome' => $s['nome']];
    });
}

// ---------------------------------------------------------------
// Freno alle richieste di reset. Stesso schema di attesa_residua()/
// segna_tentativo() in inc/auth.php ma separato: qui non si tratta
// di indovinare una password, ma di non far diventare il modulo un
// modo per spammare email a indirizzi altrui.
// ---------------------------------------------------------------

function chiave_tentativi_reset(): string
{
    return hash('sha256', 'reset|' . ($_SERVER['REMOTE_ADDR'] ?? 'ignoto'));
}

/** Secondi di attesa ancora da scontare prima di poter richiedere un altro reset. */
function reset_attesa_residua(): int
{
    $reg = store_read('tentativi');
    $k   = chiave_tentativi_reset();
    if (!isset($reg[$k])) {
        return 0;
    }
    return max(0, (int)$reg[$k]['bloccato_fino'] - time());
}

function reset_segna_richiesta(): void
{
    store_transazione(function () {
        $reg = store_read('tentativi');
        $k   = chiave_tentativi_reset();
        // una richiesta ogni 60 secondi dallo stesso indirizzo: basta a
        // scoraggiare lo spam senza intralciare chi ha sbagliato a scrivere
        $reg[$k] = ['bloccato_fino' => time() + 60, 'ultimo' => time()];

        foreach ($reg as $id => $v) {
            if ((int)($v['ultimo'] ?? 0) < time() - 86400 && (int)($v['bloccato_fino'] ?? 0) < time()) {
                unset($reg[$id]);
            }
        }
        store_write('tentativi', $reg);
    });
}

/** Cambia lo stato di un account socio, con un controllo su quello di partenza. */
function account_socio_cambia_stato(string $id, string $daStato, string $aStato, ?string $adminId = null): array
{
    return store_transazione(function () use ($id, $daStato, $aStato, $adminId) {
        $s = socio_leggi($id);
        if ($s === null) {
            return ['ok' => false, 'errore' => 'Account non trovato.'];
        }
        if (($s['stato'] ?? '') !== $daStato) {
            return ['ok' => false, 'errore' => 'Questo account non e\' piu\' nello stato atteso. Ricarica la pagina.'];
        }
        $s['stato'] = $aStato;
        if ($aStato === 'attivo' && $daStato === 'in_attesa') {
            $s['approvato_il'] = adesso();
            $s['approvato_da'] = $adminId ?? '';
        }
        if (!socio_salva($s)) {
            return ['ok' => false, 'errore' => 'Non riesco a salvare la modifica.'];
        }
        return ['ok' => true, 'nome' => $s['nome']];
    });
}

function account_socio_approva(string $id, string $adminId): array
{
    return account_socio_cambia_stato($id, 'in_attesa', 'attivo', $adminId);
}

function account_socio_rifiuta(string $id): array
{
    return account_socio_cambia_stato($id, 'in_attesa', 'rifiutato');
}

function account_socio_disabilita(string $id): array
{
    return account_socio_cambia_stato($id, 'attivo', 'disabilitato');
}

function account_socio_riabilita(string $id): array
{
    return account_socio_cambia_stato($id, 'disabilitato', 'attivo');
}

function account_socio_elimina(string $id): array
{
    return store_transazione(function () use ($id) {
        $s = socio_leggi($id);
        if ($s === null) {
            return ['ok' => false, 'errore' => 'Account non trovato.'];
        }
        if (!socio_elimina($id)) {
            return ['ok' => false, 'errore' => 'Non riesco a cancellare il file. Controlla i permessi della cartella data/soci.'];
        }
        return ['ok' => true, 'nome' => $s['nome']];
    });
}

/**
 * Password provvisoria messa da un amministratore, come
 * utente_imposta_password() per gli account admin: vale per un
 * accesso solo, poi il socio ne sceglie una sua.
 */
function account_socio_imposta_password(string $id, string $nuova): array
{
    $regola = password_valida($nuova);
    if (!$regola['ok']) {
        return ['ok' => false, 'errore' => $regola['errore']];
    }
    return store_transazione(function () use ($id, $nuova) {
        $s = socio_leggi($id);
        if ($s === null) {
            return ['ok' => false, 'errore' => 'Account non trovato.'];
        }
        $s['hash']             = password_hash($nuova, PASSWORD_DEFAULT);
        $s['cambio_richiesto'] = true;
        if (!socio_salva($s)) {
            return ['ok' => false, 'errore' => 'Non riesco a salvare la modifica.'];
        }
        return ['ok' => true, 'nome' => $s['nome']];
    });
}

/** Cambio password del socio stesso: richiede quella attuale. */
function account_socio_cambia_password(string $id, string $attuale, string $nuova): array
{
    $regola = password_valida($nuova);
    if (!$regola['ok']) {
        return ['ok' => false, 'errore' => $regola['errore']];
    }
    return store_transazione(function () use ($id, $attuale, $nuova) {
        $s = socio_leggi($id);
        if ($s === null) {
            return ['ok' => false, 'errore' => 'Account non trovato.'];
        }
        if (!password_verify($attuale, $s['hash'])) {
            return ['ok' => false, 'errore' => 'La password attuale non e\' corretta.'];
        }
        $s['hash']             = password_hash($nuova, PASSWORD_DEFAULT);
        $s['cambio_richiesto'] = false;
        if (!socio_salva($s)) {
            return ['ok' => false, 'errore' => 'Non riesco a salvare la modifica.'];
        }
        return ['ok' => true];
    });
}

/**
 * Login socio. Condivide con login() e soci_entra() lo stesso freno
 * ai tentativi (chiave = IP, in data/tentativi.json): un attacco su
 * un fronte rallenta anche gli altri.
 *
 * Il messaggio d'errore resta generico apposta: non deve rivelare se
 * un'email non esiste, e' ancora in attesa, o e' stata disabilitata.
 *
 * Per entrare servono sia l'approvazione dell'amministratore
 * ('stato' === 'attivo') sia la conferma dell'indirizzo email
 * ('email_verificata'): due controlli indipendenti, in qualsiasi
 * ordine si completino.
 */
function account_socio_login(string $email, string $password): bool
{
    if (attesa_residua() > 0) {
        return false;
    }
    $email = strtolower(trim($email));
    $s     = account_socio_trova_per_email($email);
    if ($s && ($s['stato'] ?? '') === 'attivo' && !empty($s['email_verificata']) && password_verify($password, $s['hash'])) {
        segna_tentativo(true);
        session_regenerate_id(true);
        $_SESSION['account_socio'] = ['id' => $s['id'], 'nome' => $s['nome'], 'email' => $s['email']];
        $_SESSION['account_socio_cambio_password'] = !empty($s['cambio_richiesto']);
        return true;
    }
    // Il Superadmin non deve registrarsi una seconda volta per prendere o
    // riportare materiale come un socio qualunque: le stesse credenziali
    // della dashboard valgono anche qui.
    $admin = superadmin_utente();
    if ($admin && $email === strtolower(trim((string)$admin['user'])) && password_verify($password, $admin['hash'])) {
        segna_tentativo(true);
        session_regenerate_id(true);
        $_SESSION['account_socio'] = ['id' => 'admin:' . $admin['id'], 'nome' => $admin['nome'], 'email' => $admin['user']];
        $_SESSION['account_socio_cambio_password'] = false;
        return true;
    }
    usleep(400000);
    segna_tentativo(false);
    return false;
}

function account_socio_esce(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function account_socio_sessione(): ?array
{
    return $_SESSION['account_socio'] ?? null;
}

function account_socio_autorizzato(): bool
{
    return isset($_SESSION['account_socio']);
}

function account_socio_deve_cambiare_password(): bool
{
    return account_socio_autorizzato() && !empty($_SESSION['account_socio_cambio_password']);
}
