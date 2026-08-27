# Buio Verticale — gestione magazzino

Applicativo web in PHP + JavaScript, senza database e senza Node.
Tutti i dati stanno in file JSON dentro `data/`, le foto in `foto/`.

## Cosa serve

- PHP 7.4 o successivo (va bene anche l'hosting condiviso più semplice)
- Apache o Nginx
- Nessuna estensione particolare, nessun Composer, nessuna build

## Installazione

1. Copia l'intera cartella nello spazio web, per esempio in `/magazzino`.
2. Apri il sito nel browser: parte da sola la procedura guidata, in cinque passi.
3. Al termine il gestionale cancella `installa.php` e sei già dentro come amministratore.

Non devi modificare nessun file a mano. Se il tuo hosting non permette a PHP di
creare le cartelle, prima di iniziare dai i permessi via FTP:

```
chmod -R 775 data foto
chown -R www-data:www-data data foto
```

### Cosa chiede la procedura guidata

**1. Controlli** — verifica versione di PHP, permessi delle cartelle, estensioni
GD (foto), ZIP (file XLSX), mbstring (accenti) e se sei in HTTPS. Le voci
indispensabili sono in rosso, le altre in giallo: senza quelle l'applicativo
funziona lo stesso, con qualche limite dichiarato. Il pulsante sistema da solo i
permessi di `data/` e `foto/` (0775 sulle cartelle, 0664 sui file).

**2. Il gruppo** — nome del gruppo speleo e logo. Da lì nasce l'intestazione
"Gestionale magazzino <nome>" che compare in cima a ogni pagina. Il logo viene
ridotto in automatico; senza logo resta il pallino giallo.

**3. Accessi** — l'amministratore (nome, utente, password ripetuta due volte) e
il codice unico per tutti gli altri soci, che puoi anche non mettere lasciando
l'area aperta. Qui decidi anche dopo quanti giorni un prelievo va in ritardo e
per quanto un telefono resta riconosciuto.

**4. Inventario** — importi il tuo foglio, parti da zero, o tieni i 57 articoli
di esempio già nel pacchetto. Sull'importazione: vedi sotto.

**5. Fine** — scrive tutto, ti fa entrare, ricontrolla i permessi e cancella
`installa.php`. Se la cancellazione non riesce, te lo dice: eliminalo via FTP.

Tutto quello che decidi nella procedura resta modificabile dopo, in
**Gestione → Impostazioni**.

## Importare l'inventario da un foglio di calcolo

Si fa nel passo 4 dell'installazione e, in qualunque momento dopo, da
**Inventario → Importa da foglio**. Formati accettati: **CSV** e **XLSX**. Il
vecchio `.xls` no: aprilo e risalvalo.

La prima riga contiene i nomi delle colonne, poi una riga per articolo:

| Categoria | Articolo | Tipo | Quantita | Soglia minima | Da comprare | Note |
|---|---|---|---|---|---|---|
| Corde | Corda 10mm | 40 m | 2 | 2 | 0 | una da lavare |
| Armo | Moschettoni | Ovali lega | 67 | 40 | 20 | |
| Attrezzatura personale | Caschi | | 21 | 15 | 0 | |
| Trasporto | Sacchi | Tubolare grande | 8 | 10 | 2 | |

- **Categoria** raggruppa il materiale. Se manca, l'articolo finisce in "Vario".
- **Articolo** è il nome del pezzo. **Obbligatorio.**
- **Tipo** è la variante: la misura di una corda, il modello di un moschettone.
- **Quantita** sono i pezzi contati adesso. **Obbligatorio**, solo numeri.
- **Soglia minima** è la scorta sotto la quale vuoi l'avviso. Vuoto = nessun avviso.
- **Da comprare** sono i pezzi già in lista acquisti.
- **Note** è testo libero.

Separatore: punto e virgola, virgola o tabulazione, riconosciuto da solo. Le
intestazioni possono essere scritte in altro modo: *Tipologia* per Categoria,
*Qta*, *Pezzi*, *Giacenza* per Quantita, e così via — maiuscole e accenti non
contano. Nel wizard trovi il pulsante per scaricare il **modello CSV già pronto**.

Prima di scrivere qualsiasi cosa ti viene mostrato cosa è stato capito: quanti
articoli, quali colonne riconosciute, quali righe scartate e perché. Confermi tu.

Reimportando più avanti, gli articoli già presenti non vengono toccati: giacenze,
foto e storico restano dove sono. Vengono aggiunti solo i nuovi.

L'inventario di esempio contiene i 57 articoli del file di magazzino attuale
(470 pezzi in carico), con i mancanti al conteggio e la lista acquisti.

## Sicurezza in breve

- **Gestione**: nome utente e password, hash bcrypt, mai in chiaro da nessuna parte.
- **Password**: almeno 8 caratteri, con una minuscola, una maiuscola e un numero.
  Si scrive due volte e un elenco sotto ai campi dice cosa manca mentre digiti,
  sia in installazione sia in **Gestione → Accessi**. Le regole stanno in un posto
  solo: `password_regole()` in `inc/auth.php`, ripetute in `assets/password.js`.
- **Area soci**: aperta, oppure protetta da un codice di gruppo (vedi sotto).
- **Freno agli attacchi**: 5 password sbagliate dallo stesso indirizzo bloccano
  l'accesso 15 minuti, poi 30, 60, fino a 2 ore.
- **Cookie**: `HttpOnly` (il JavaScript non li legge), `SameSite=Lax` (non partono
  da altri siti), `Secure` in automatico quando il sito e' in HTTPS.
- **Moduli**: ogni scrittura porta un gettone CSRF legato alla sessione.
- **Dati**: cartelle `data/`, `inc/` e i file `.json` bloccati dal web server.
- **Foto**: nella cartella `foto/` l'esecuzione di codice PHP e' disattivata, e i
  file caricati vengono riscritti come JPEG, non salvati come arrivano.
- **Uscite a video**: ogni testo scritto dagli utenti viene neutralizzato prima di
  finire nella pagina, sia lato PHP sia lato JavaScript.

Restano due cose che dipendono da te: **attivare HTTPS** e **tenere aggiornato PHP**.

### Il codice dell'area soci

Lo scegli durante l'installazione e lo cambi da **Gestione → Impostazioni**.
Chi arriva sul sito lo inserisce una volta per dispositivo e resta riconosciuto
per il numero di giorni che hai impostato. Per revocare l'accesso a tutti in un
colpo basta cambiare la parola: i dispositivi ricordati decadono all'istante.
Il codice è salvato come hash bcrypt, non in chiaro.

Lasciandolo vuoto l'area soci resta aperta a chiunque abbia il link.

## Come sono divisi gli accessi

| Chi | Dove | Cosa può fare |
|---|---|---|
| Tutti i soci | `index.php` | Vedere la disponibilità, segnare cosa prendono, registrare il rientro |
| Nessuno | `data/`, `inc/` | Cartelle chiuse dal web server: si leggono solo via FTP o SSH |
| Amministratori | `dashboard.php` | Tutto il resto: acquisti, scarti, schede articolo, storico, chiusure |

L'area soci non ha password: chi prende il materiale scrive nome e cognome.
La gestione è protetta da nome utente e password (hash bcrypt, sessione PHP).

## Come funziona il giro del materiale

**Prelievo.** Il socio sceglie gli articoli, indica nome, contatto, dove va e
quando pensa di riportarlo. I pezzi scendono subito dalla disponibilità.

**Rientro.** Chi riporta apre "Riporta", trova il proprio prelievo e conta i
pezzi riga per riga: quanti rientrano e quanti sono persi o rotti. Se qualcosa
non torna, il prelievo resta aperto con solo il residuo. I pezzi dichiarati
persi escono dalle giacenze e finiscono nei movimenti con nome e data.

**Controllo.** In dashboard, "Non rientrato" mostra chi ha cosa, da quanti
giorni e con quale contatto. Oltre 14 giorni il prelievo passa in ritardo
(cambia il numero in `inc/config.php`, costante `GIORNI_RITARDO`).

## Le foto del materiale

Ogni articolo puo' avere una foto: si carica dalla dashboard, in **Inventario →
Scheda**. Viene ridotta a 640 px e salvata in `foto/` come JPEG, quindi anche gli
scatti da telefono non appesantiscono il sito. L'orientamento EXIF viene raddrizzato.

La miniatura compare a fianco del nome in tutto l'applicativo: catalogo dei soci,
carrello, elenco del rientro, inventario, prelievi aperti, storico e movimenti.
Dove la foto manca compare una piastrella con le iniziali, con un colore fisso per
articolo, cosi' l'elenco resta leggibile anche senza immagini.

Cliccando una miniatura nell'area soci la foto si apre ingrandita.

Rendi scrivibile anche questa cartella:

```
chmod -R 775 foto
chown -R www-data:www-data foto
```

Se sul server manca l'estensione GD il file viene salvato cosi' com'e', senza
ridimensionamento: funziona lo stesso, pesa solo di piu'.

## I file dei dati

| File | Contenuto |
|---|---|
| `data/inventario.json` | Articoli, giacenze, soglie, lista acquisti |
| `data/prestiti/` | Una cartella: **un file per ogni prelievo**, con le sue righe e i rientri |
| `data/movimenti.json` | Acquisti, scarti, rettifiche, perdite |
| `data/utenti.json` | Amministratori (solo hash della password) |
| `data/tentativi.json` | Contatore dei tentativi di accesso falliti |
| `data/impostazioni.json` | Nome del gruppo, logo, codice soci, colori, preferenze |
| `data/aggiornamenti.json` | Esito dell'ultimo controllo delle nuove versioni |
| `data/.installato` | Segna che l'installazione è finita |
| `foto/` | Le immagini degli articoli |

Nessuno di questi file fa parte del pacchetto: quando scarichi una versione nuova,
`data/` e `foto/` arrivano vuote, con dentro il solo `.htaccess` che le protegge.
È per questo che aggiornare non può cancellare il magazzino.

Le scritture usano un lock esclusivo e la sostituzione atomica del file, quindi
due persone che salvano nello stesso momento non si sovrascrivono a vicenda.

Ogni richiesta di prelievo sta nel suo file, `data/prestiti/pre-AAMMGG-xxxxxx.json`:
si legge e si salva da sola, e quando un prelievo e' stato riconsegnato del tutto
si puo' cancellare dalla dashboard (**Storico → Elimina**, oppure **Elimina i chiusi…**
per fare pulizia di tutto quello chiuso prima di una data). Le giacenze non cambiano
— il materiale e' gia' rientrato — e nei Movimenti resta la nota di cosa e' stato tolto.
Se vieni da una versione precedente, il vecchio `data/prestiti.json` viene diviso in
automatico al primo avvio e messo da parte come `prestiti.json.migrato-<data>`:
puoi cancellarlo a mano quando hai controllato che sia tutto a posto.

Le foto stanno in `foto/`, fuori dai JSON: nell'inventario e' salvato solo il nome del file.

**Backup:** copia `data/` e `foto/`. Un cron settimanale va benissimo:

```
0 3 * * 1 tar czf /backup/magazzino-$(date +\%F).tgz /var/www/magazzino/data /var/www/magazzino/foto
```

## Protezione delle cartelle

Su Apache ci pensano gli `.htaccess` inclusi (radice, `data/`, `inc/`, `foto/`).
Su Nginx gli `.htaccess` non vengono letti: aggiungi al server block

```nginx
location ~ ^/magazzino/(data|inc)/ { deny all; return 404; }
location ~ ^/magazzino/foto/.*\.(php|phtml|phar)$ { deny all; return 404; }
```

**Verifica sempre a mano** aprendo nel browser
`https://iltuosito/magazzino/data/utenti.json`: deve rispondere 403 o 404. Se
scarica il file, la protezione non e' attiva e va sistemata prima di usare l'app.

### Ancora piu' solido: dati fuori dalla cartella pubblica

La difesa migliore e' che i file non stiano proprio sotto il sito. Se il tuo
hosting te lo permette, sposta `data/` e `foto/` un livello sopra la webroot e
aggiorna `inc/config.php`:

```php
define('DATA_DIR', dirname(__DIR__, 2) . '/magazzino-dati');
define('FOTO_DIR', dirname(__DIR__, 2) . '/magazzino-foto');
```

Le foto pero' devono restare raggiungibili dal browser: se le sposti fuori, servono
tramite uno script PHP che le legge e le rimanda. Nel dubbio lascia `foto/` dov'e'
e sposta solo `data/`, che e' la parte delicata.

## Esportazioni

Ogni sezione della dashboard ha il suo CSV (punto e virgola, UTF-8 con BOM:
Excel lo apre senza sistemare gli accenti). Da lì passi in un attimo al foglio
di calcolo che usate oggi.

Nello **Storico** i due campi data non filtrano solo la tabella: valgono anche per
gli scarichi. Impostato il periodo che ti interessa trovi due file pronti:

- **CSV dettaglio** — una riga per ogni articolo di ogni prelievo uscito in quel
  periodo: chi, dove, quando, presi, rientrati, persi, ancora fuori.
- **CSV riepilogo** — una riga per articolo, con quante volte e' stato prestato,
  quanti pezzi sono usciti in tutto e quanti sono ancora in giro.

Senza date si scarica tutto. Il periodo si conta sulla **data di uscita** del prelievo.

## Se vuoi cambiare qualcosa

La regola è una sola: **non modificare i file del programma**. Al prossimo
aggiornamento verrebbero sovrascritti e le tue modifiche sparirebbero. Per ogni
cosa c'è un posto che l'aggiornamento non tocca mai.

| Cosa | Dove si cambia |
|---|---|
| Nome del gruppo, logo, codice soci, giorni di ritardo | **Gestione → Impostazioni** |
| Colori principali e angoli stondati | **Gestione → Impostazioni → Aspetto** |
| Cartelle dei dati e delle foto, peso e dimensione delle immagini, fuso orario | `inc/config-locale.php` |
| Tutto il resto dell'aspetto (caratteri, spaziature, dettagli) | `assets/stile-locale.css` |

I due file locali non esistono di serie: si creano copiando i modelli che trovi
già nel pacchetto.

```
cp inc/config-locale.esempio.php inc/config-locale.php
cp assets/stile-locale.esempio.css assets/stile-locale.css
```

Dentro ci sono le istruzioni e gli esempi commentati. Da quel momento sono roba
tua: non fanno parte del pacchetto, non vengono mai sovrascritti, e non c'è più
niente da riportare a mano dopo un aggiornamento.

I caratteri arrivano da Google Fonts. Se il server è offline il sito resta
leggibile: c'è già il fallback di sistema.

## Aggiornare l'applicativo

Quando esce una versione nuova, chi entra in gestione la vede: **Gestione →
Aggiornamenti** dice che versione c'è installata, se ne è uscita una più recente
e cosa cambia. Il controllo passa da GitHub una volta ogni dodici ore e si può
spegnere del tutto.

L'aggiornamento **non è automatico, per scelta**. Perché lo fosse, il server web
dovrebbe poter riscrivere i file `.php` del sito: da quel momento qualunque falla
diventerebbe una porta di servizio permanente, e la stessa cosa vale per chiunque
riuscisse a entrare nell'account GitHub del progetto. Oggi il programma è in sola
lettura e resta così: i file li carichi tu, quando decidi tu.

Nella stessa scheda trovi le due cose che servono prima di farlo:

1. **Scarica i dati** — uno zip con inventario, prelievi, movimenti, accessi e
   impostazioni. Le foto non ci sono: stanno in `foto/` e l'aggiornamento non le
   tocca.
2. **Cosa hai modificato a mano** — l'applicativo conosce le impronte dei propri
   file, quindi ti dice esattamente quali hai toccato e dove riportare quelle
   modifiche.

Poi, con il client FTP:

- carica tutto **tranne `data/` e `foto/`** — lì dentro c'è il tuo magazzino;
- **non caricare `installa.php`**: si era cancellato da solo a installazione
  finita, e rimetterlo online aprirebbe la procedura guidata a chiunque conosca
  l'indirizzo;
- non sovrascrivere `inc/config-locale.php` e `assets/stile-locale.css`, né il
  `.htaccess` della cartella principale se ci hai messo mano (per esempio per
  forzare l'HTTPS): sono le regole del tuo server, e una sbagliata manda in
  errore tutto il sito;
- aspetta un minuto prima di aprire il sito: molti server tengono in memoria il
  programma per qualche secondo (è OPcache), quindi appena finito il caricamento
  potresti vedere ancora la versione vecchia o un misto delle due. Passa da sé, e
  nel frattempo non si rompe niente;
- poi apri il sito: se il formato dei dati è cambiato, viene sistemato da solo al
  primo caricamento di pagina;
- torna in **Aggiornamenti** e ricarica. Il numero di versione deve essere
  cambiato: se dopo qualche minuto è ancora quello vecchio, i file non sono
  arrivati dove dovevano.

### Pubblicare una versione (per chi sviluppa)

1. alza `versione` in `versione.json`
2. scrivi le `novita`
3. `git commit`, poi `git tag v0.3.0 && git push origin v0.3.0`

Il resto lo fa `.github/workflows/rilascio.yml`: controlla che tag e
`versione.json` combacino, verifica la sintassi PHP con la versione minima
dichiarata, genera `manifest.json` e pubblica la Release con il pacchetto pronto.

Se una versione cambia il formato dei dati, va aggiunta una migrazione in
`inc/migrazioni.php`, con in cima le regole per scriverne. Le due che contano:
**solo aggiunte** — mai togliere o rinominare un campo che la versione precedente
legge — e **il traguardo si legge dall'elenco delle migrazioni, non da
`versione.json`**: caricando i file via FTP arrivano uno alla volta, e un numero
che arrivasse prima del codice che lo implementa farebbe saltare la migrazione.
