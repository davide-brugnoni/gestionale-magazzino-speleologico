<?php
// ---------------------------------------------------------------
// Invio email via SMTP.
//
// Niente Composer nel progetto, quindi niente PHPMailer: e' un
// client SMTP minimo scritto a mano, su socket. Usato per la
// conferma di registrazione e il reset della password dei soci.
//
// Non lancia mai eccezioni verso chi chiama e non fa mai echo: le
// pagine decidono loro cosa mostrare in caso di fallimento (vedi il
// principio "niente rete, niente echo" gia' seguito da
// inc/migrazioni.php, qui applicato all'invio invece che al lock).
// ---------------------------------------------------------------

/** Le email sono configurate? Vuoto = non configurato, si salta l'invio. */
function email_configurata(): bool
{
    return trim((string)impostazione('smtp_host', '')) !== ''
        && trim((string)impostazione('smtp_mittente', '')) !== '';
}

/** Toglie ritorni a capo da un valore che finisce in un header: niente CRLF injection. */
function email_sanifica_header(string $v): string
{
    return trim(str_replace(["\r", "\n"], '', $v));
}

function email_codifica_assunto(string $testo): string
{
    return '=?UTF-8?B?' . base64_encode($testo) . '?=';
}

/** Nome per un header From/To: fra virgolette se e' ASCII, altrimenti codificato. */
function email_intestazione_nome(string $nome): string
{
    $nome = email_sanifica_header($nome);
    if ($nome === '') {
        return '';
    }
    if (preg_match('/^[\x20-\x7E]*$/', $nome)) {
        return '"' . str_replace('"', '', $nome) . '"';
    }
    return email_codifica_assunto($nome);
}

/**
 * Manda un'email di solo testo, leggendo la configurazione SMTP dalle
 * impostazioni del gruppo. Ritorna sempre ['ok' => bool, 'errore' => string],
 * mai un'eccezione: chi chiama decide cosa mostrare.
 */
function email_invia(string $a, string $nomeA, string $oggetto, string $corpo): array
{
    if (!email_configurata()) {
        return ['ok' => false, 'errore' => 'L\'invio email non e\' configurato.'];
    }
    $a = trim($a);
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'errore' => 'Indirizzo email non valido.'];
    }

    try {
        return email_smtp_invia(
            (string)impostazione('smtp_host', ''),
            (int)impostazione('smtp_porta', 587),
            (string)impostazione('smtp_sicurezza', 'tls'),
            (string)impostazione('smtp_utente', ''),
            (string)impostazione('smtp_password', ''),
            (string)impostazione('smtp_mittente', ''),
            (string)impostazione('smtp_nome_mittente', '') ?: APP_NOME,
            $a,
            $nomeA,
            $oggetto,
            $corpo
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'errore' => 'Invio non riuscito: ' . $e->getMessage()];
    }
}

/**
 * Il client SMTP vero e proprio. E' qui, separato da email_invia(), solo
 * per tenere la costruzione del messaggio e il dialogo col server in una
 * funzione sola invece che sparsi.
 */
function email_smtp_invia(
    string $host,
    int $porta,
    string $sicurezza,
    string $utente,
    string $password,
    string $mittente,
    string $nomeMittente,
    string $a,
    string $nomeA,
    string $oggetto,
    string $corpo
): array {
    $mittente = email_sanifica_header($mittente);
    $a        = email_sanifica_header($a);
    $timeout  = 12;

    $prefisso = $sicurezza === 'ssl' ? 'ssl://' : 'tcp://';
    $contesto = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $host,
            'SNI_enabled'      => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client($prefisso . $host . ':' . $porta, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $contesto);
    if (!$sock) {
        return ['ok' => false, 'errore' => 'Non riesco a collegarmi al server SMTP (' . $errstr . ').'];
    }
    stream_set_timeout($sock, $timeout);

    // Legge una risposta SMTP, righe multiple comprese (es. "250-...").
    $leggi = function () use ($sock): array {
        $righe = [];
        do {
            $riga = fgets($sock, 515);
            if ($riga === false) {
                break;
            }
            $righe[] = $riga;
        } while (isset($riga[3]) && $riga[3] === '-');
        $ultima = end($righe) ?: '';
        return [(int)substr($ultima, 0, 3), implode('', $righe)];
    };

    $comando = function (string $riga, array $attesi) use ($sock, $leggi): array {
        fwrite($sock, $riga . "\r\n");
        [$codice, $testo] = $leggi();
        if (!in_array($codice, $attesi, true)) {
            throw new RuntimeException('Il server SMTP ha risposto: ' . trim($testo));
        }
        return [$codice, $testo];
    };

    try {
        [$codice, ] = $leggi();                 // saluto iniziale del server
        if ($codice !== 220) {
            throw new RuntimeException('Il server SMTP non ha risposto correttamente alla connessione.');
        }

        $comando('EHLO buiovertic.local', [250]);

        if ($sicurezza === 'tls') {
            $comando('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Non riesco ad attivare la cifratura TLS.');
            }
            $comando('EHLO buiovertic.local', [250]);
        }

        if ($utente !== '') {
            $comando('AUTH LOGIN', [334]);
            $comando(base64_encode($utente), [334]);
            $comando(base64_encode($password), [235]);
        }

        $comando('MAIL FROM:<' . $mittente . '>', [250]);
        $comando('RCPT TO:<' . $a . '>', [250, 251]);
        $comando('DATA', [354]);

        $intestazioni = [];
        $daNome = email_intestazione_nome($nomeMittente);
        $intestazioni[] = 'From: ' . ($daNome !== '' ? $daNome . ' ' : '') . '<' . $mittente . '>';
        $aNome = email_intestazione_nome($nomeA);
        $intestazioni[] = 'To: ' . ($aNome !== '' ? $aNome . ' ' : '') . '<' . $a . '>';
        $intestazioni[] = 'Subject: ' . email_codifica_assunto(email_sanifica_header($oggetto));
        $intestazioni[] = 'Date: ' . date('r');
        $intestazioni[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (preg_replace('/[^a-z0-9.-]/i', '', $host) ?: 'localhost') . '>';
        $intestazioni[] = 'MIME-Version: 1.0';
        $intestazioni[] = 'Content-Type: text/plain; charset=UTF-8';
        $intestazioni[] = 'Content-Transfer-Encoding: 8bit';

        // Punto a inizio riga raddoppiato: e' cosi' che SMTP distingue un
        // rigo di testo che inizia per "." dalla riga "." che chiude i dati.
        $righeCorpo = explode("\n", str_replace(["\r\n", "\r"], "\n", $corpo));
        foreach ($righeCorpo as &$riga) {
            if (isset($riga[0]) && $riga[0] === '.') {
                $riga = '.' . $riga;
            }
        }
        unset($riga);

        $messaggio = implode("\r\n", $intestazioni) . "\r\n\r\n" . implode("\r\n", $righeCorpo) . "\r\n.";
        $comando($messaggio, [250]);
        $comando('QUIT', [221, 250]);
    } catch (Throwable $e) {
        if (is_resource($sock)) {
            fclose($sock);
        }
        return ['ok' => false, 'errore' => $e->getMessage()];
    }

    fclose($sock);
    return ['ok' => true, 'errore' => ''];
}
