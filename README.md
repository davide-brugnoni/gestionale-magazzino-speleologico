# Gestionale magazzino speleologico

Applicativo web per gestire il magazzino attrezzatura di un gruppo speleologico:
inventario, prelievi e rientri dei soci, acquisti, scarti, storico e foto degli
articoli. **PHP + JavaScript, senza database e senza build**: i dati stanno in
file JSON dentro `data/`, le foto in `foto/`.

Progetto nato per il gruppo speleo Buio Verticale, pensato per girare su un
hosting condiviso qualsiasi.

## Caratteristiche principali

- **Nessun database**: tutto in JSON, scritture con lock esclusivo e sostituzione
  atomica dei file.
- **Nessuna build, nessun Composer**: si carica via FTP e funziona.
- **Installazione guidata** in cinque passi, con controllo automatico di
  versione PHP, permessi ed estensioni necessarie.
- **Due aree distinte**: area soci aperta (o protetta da un codice di gruppo)
  per prelievi e rientri, e dashboard con login per amministratori e Superadmin.
- **Import/export CSV e XLSX** dell'inventario, esportazioni CSV da ogni
  sezione della dashboard.
- **Foto degli articoli**, ridimensionate e riscritte in JPEG in automatico.
- **Aggiornamenti**: controllo versione da GitHub, backup dei dati e verifica
  dei file modificati a mano prima di aggiornare.
- Attenzione alla sicurezza di base: password con hash bcrypt, CSRF sui form,
  cookie protetti, blocco dei tentativi di accesso, cartelle dati bloccate dal
  web server.

## Cosa serve

- PHP 7.4 o successivo
- Apache o Nginx
- Nessuna estensione obbligatoria (GD, ZIP e mbstring sono consigliate, non
  indispensabili)

## Avvio rapido

1. Copia l'intera cartella nello spazio web.
2. Apri il sito nel browser: parte da sola la procedura guidata.
3. Segui i cinque passi (controlli, gruppo, accessi, inventario, fine).

Guida completa all'installazione, all'importazione dell'inventario, alla
sicurezza, ai ruoli, al formato dei dati e agli aggiornamenti: **[LEGGIMI.md](LEGGIMI.md)**.

## Struttura del progetto

```
index.php              area soci: catalogo, prelievo, rientro
dashboard.php           dashboard amministratori/Superadmin
api.php                 tutte le operazioni di scrittura/lettura via API
installa.php             procedura guidata di installazione
inc/                    logica applicativa (auth, store, migrazioni, import, ...)
assets/                 CSS e JavaScript
data/                   dati del magazzino in JSON (non versionato)
foto/                   foto degli articoli (non versionato)
esempi/                 inventario di esempio
```

## Sviluppo e rilasci

Il progetto non richiede build: si modifica il PHP direttamente. Le versioni
si pubblicano taggando `vX.Y.Z` dopo aver aggiornato `versione.json`; il
workflow `.github/workflows/rilascio.yml` verifica la sintassi PHP, genera
`manifest.json` e pubblica la Release con il pacchetto pronto per il caricamento
via FTP. Dettagli nella sezione "Pubblicare una versione" di [LEGGIMI.md](LEGGIMI.md).
