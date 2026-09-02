/* Buio Verticale — magazzino, gestione */
(function () {
  'use strict';

  var CSRF   = document.body.getAttribute('data-csrf');
  var TITOLO = document.body.getAttribute('data-titolo') || '';

  // Chi non e' Superadmin non ha Impostazioni, Aggiornamenti e la
  // tabella degli accessi: quelle parti di pagina non sono state
  // proprio scritte. Tutto cio' che le tocca sta dietro a questa
  // bandiera, altrimenti $() torna null e la dashboard muore intera.
  // A dire di no per davvero e' comunque il server, non questa riga.
  var SUPER  = document.body.getAttribute('data-super') === '1';
  var D = { inventario: [], aperti: [], storico: [], movimenti: [], utenti: [], accountSoci: [], kpi: {}, giorni: 14 };

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // Titolo della scheda del browser: cambia insieme alla scheda mostrata.
  function vaiA(bottone) {
    document.title = bottone.textContent.trim() + ' - ' + TITOLO;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }


  // ------------------------------------------------- miniature

  var FOTO = {};

  function iniziali(nome) {
    var parti = String(nome || '?').replace(/[^0-9A-Za-z\u00C0-\u024F ]/g, ' ').trim().split(/\s+/);
    return ((parti[0] || '?').charAt(0) + (parti[1] ? parti[1].charAt(0) : '')).toUpperCase();
  }

  function tinta(chiave) {
    var h = 0, s = String(chiave || '');
    for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) % 360; }
    return h;
  }

  function mini(nome, foto, piccola) {
    var c = 'mini' + (piccola ? ' piccola' : '');
    if (foto) {
      return '<img class="' + c + '" loading="lazy" alt="Foto di ' + esc(nome) + '" src="foto/' +
             encodeURIComponent(foto) + '" data-foto="' + esc(foto) + '" data-titolo="' + esc(nome) + '">';
    }
    var h = tinta(nome);
    return '<span class="' + c + ' mini-vuota" aria-hidden="true" style="--tinta-sf:hsl(' + h +
           ',26%,90%);--tinta-in:hsl(' + h + ',40%,33%)">' + esc(iniziali(nome)) + '</span>';
  }

  // miniatura + testo, per le celle e gli elenchi
  function conMini(nome, foto, dentro, piccola) {
    return '<span class="art-riga">' + mini(nome, foto, piccola) + '<span>' + dentro + '</span></span>';
  }

  function apriLente(src, titolo) {
    var l = document.createElement('div');
    l.className = 'lente';
    l.innerHTML = '<img src="' + src + '" alt="' + esc(titolo) + '"><p>' + esc(titolo) + '</p>';
    l.addEventListener('click', function () { l.remove(); });
    document.body.appendChild(l);
  }

  function data(iso, conOra) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d)) return esc(iso);
    var o = { day: '2-digit', month: '2-digit', year: '2-digit' };
    if (conOra) { o.hour = '2-digit'; o.minute = '2-digit'; }
    return d.toLocaleDateString('it-IT', o);
  }

  function toast(msg, tipo) {
    var t = $('#toast');
    t.textContent = msg;
    t.className = 'mostra ' + (tipo || '');
    clearTimeout(t._t);
    t._t = setTimeout(function () { t.className = tipo || ''; }, 4200);
  }

  function api(azione, dati) {
    var opt = { headers: { 'Content-Type': 'application/json' } };
    if (dati) { dati.csrf = CSRF; opt.method = 'POST'; opt.body = JSON.stringify(dati); }
    return fetch('api.php?azione=' + azione, opt).then(function (r) {
      if (r.status === 401) { location.href = 'login.php'; return { ok: false, errore: 'Sessione scaduta.' }; }
      return r.json().catch(function () { return { ok: false, errore: 'Risposta non leggibile dal server.' }; });
    });
  }

  function scrivi(azione, dati, messaggio) {
    return api(azione, dati).then(function (d) {
      if (!d.ok) { toast(d.errore || 'Operazione non riuscita.', 'male'); return false; }
      toast(messaggio, 'ok');
      return carica().then(function () { return true; });
    });
  }

  // ---------------------------------------------------------- pannello

  var okHandler = null;

  function apriPannello(titolo, html, onOk, etichetta) {
    $('#velo-tit').textContent = titolo;
    $('#velo-corpo').innerHTML = html;
    $('#velo-ok').textContent = etichetta || 'Conferma';
    $('#velo-ok').disabled = false;
    okHandler = onOk;
    $('#velo').hidden = false;
    var primo = $('#velo-corpo input, #velo-corpo select, #velo-corpo textarea');
    if (primo) primo.focus();
  }

  function chiudiPannello() { $('#velo').hidden = true; okHandler = null; }

  $('#velo-ok').addEventListener('click', function () { if (okHandler) okHandler(); });
  $('#velo').addEventListener('click', function (e) { if (e.target === this) chiudiPannello(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') chiudiPannello(); });

  // ---------------------------------------------------------- caricamento

  function carica() {
    return api('stato').then(function (d) {
      if (!d.ok) { toast(d.errore || 'Non riesco a leggere i dati.', 'male'); return; }
      D.inventario = d.inventario;
      D.aperti     = d.aperti;
      D.storico    = d.storico;
      D.movimenti  = d.movimenti;
      D.utenti     = d.utenti || [];
      D.accountSoci = d.account_soci || [];
      D.kpi        = d.kpi;
      D.giorni     = d.giorni_ritardo;
      FOTO         = d.foto || {};
      D.impostazioni = d.impostazioni || {};
      D.superadmin   = d.superadmin || '';
      D.versione     = d.versione || '';
      riempiCategorie();
      disegnaProfilo();
      disegnaKpi();
      disegnaRitardi();
      disegnaComprare();
      disegnaInventario();
      disegnaFuori();
      disegnaStorico();
      disegnaMovimenti();
      disegnaSoci();
      if (SUPER) { disegnaUtenti(); disegnaImpostazioni(); }
      $('#pie-aggiornato').textContent = 'Aggiornato ' + new Date().toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    });
  }

  function mancanti(a) {
    return Math.max(0, (a.quantita_teorica != null ? a.quantita_teorica : a.quantita) - a.quantita);
  }

  // ---------------------------------------------------------- profilo (firma)

  function disegnaProfilo() {
    var max = 1;
    D.inventario.forEach(function (a) { max = Math.max(max, a.quantita + mancanti(a)); });

    $('#profilo').innerHTML = D.inventario.map(function (a) {
      var m = mancanti(a);
      var tot = a.quantita + m;
      var h = function (n) { return (n / max * 100).toFixed(2) + '%'; };
      return '<span class="colonna" data-articolo="' + esc(a.id) + '" title="' + esc(a.nome) +
             ' — ' + a.disponibile + ' a magazzino, ' + a.in_prestito + ' fuori' + (m ? ', ' + m + ' mancanti' : '') + '">' +
             (m ? '<i class="q-mancante" style="height:' + h(m) + '"></i>' : '') +
             (a.in_prestito ? '<i class="q-fuori" style="height:' + h(a.in_prestito) + '"></i>' : '') +
             '<i class="q-disp" style="height:' + h(a.disponibile) + '"></i>' +
             '</span>';
    }).join('');

    $('#profilo-nota').textContent = D.inventario.length + ' articoli · ' + D.kpi.pezzi_totali + ' pezzi';
  }

  // ---------------------------------------------------------- kpi

  function kpi(et, val, sotto, classe) {
    return '<div class="kpi ' + (classe || '') + '"><div class="et">' + et + '</div>' +
           '<div class="val">' + val + '</div><div class="sotto">' + sotto + '</div></div>';
  }

  function disegnaKpi() {
    var k = D.kpi;
    $('#kpi').innerHTML =
      kpi('A magazzino', k.disponibili, 'pezzi pronti da usare') +
      kpi('In prestito', k.in_prestito, k.prestiti_aperti + ' prelievi aperti', k.in_prestito ? 'avviso' : '') +
      kpi('In ritardo', k.in_ritardo, 'fuori da oltre ' + D.giorni + ' giorni', k.in_ritardo ? 'allarme' : '') +
      kpi('Mancanti', k.mancanti, 'pezzi non trovati al conteggio', k.mancanti ? 'allarme' : '') +
      kpi('Da comprare', k.da_comprare, 'pezzi in lista acquisti') +
      kpi('Articoli', k.articoli, 'voci a catalogo');
  }

  function disegnaRitardi() {
    var r = D.aperti.filter(function (p) { return p.giorni >= D.giorni; });
    $('#ritardi').innerHTML = r.length
      ? r.map(schedaPrestito).join('')
      : '<div class="avviso ok">Niente di scaduto: tutti i prelievi aperti sono dentro i ' + D.giorni + ' giorni.</div>';
  }

  function disegnaComprare() {
    var righe = D.inventario.filter(function (a) {
      return (a.da_comprare || 0) > 0 || mancanti(a) > 0 || a.sotto_soglia;
    }).sort(function (x, y) {
      return ((y.da_comprare || 0) - (x.da_comprare || 0)) || (mancanti(y) - mancanti(x));
    });
    $('#tab-comprare tbody').innerHTML = righe.length ? righe.map(function (a) {
      return '<tr>' +
        '<td class="nome-art">' + conMini(a.nome, a.foto, esc(a.nome) + '<small>' + esc(a.categoria) + '</small>') + '</td>' +
        '<td class="num">' + a.quantita + '</td>' +
        '<td class="num">' + ((a.da_comprare || 0) ? '<span class="tag male">' + a.da_comprare + '</span>' : '—') + '</td>' +
        '<td class="num">' + (mancanti(a) || '—') + '</td>' +
        '<td style="text-align:right"><button class="bottone chiaro mini" data-carico="' + esc(a.id) + '">Registra acquisto</button></td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="5" class="vuoto" style="padding:20px 12px">Lista acquisti vuota.</td></tr>';
  }

  // ---------------------------------------------------------- inventario

  function riempiCategorie() {
    ['#inv-cat'].forEach(function (sel) {
      var s = $(sel);
      if (s.options.length > 1) return;
      var viste = {};
      D.inventario.forEach(function (a) { viste[a.categoria] = 1; });
      Object.keys(viste).sort().forEach(function (c) {
        var o = document.createElement('option');
        o.value = c; o.textContent = c;
        s.appendChild(o);
      });
    });
  }

  function barra(a) {
    var m = mancanti(a);
    var tot = Math.max(1, a.quantita + m);
    var pc = function (n) { return (n / tot * 100).toFixed(1) + '%'; };
    return '<span class="barra" title="' + a.disponibile + ' disponibili, ' + a.in_prestito + ' fuori, ' + m + ' mancanti">' +
      '<i class="b-disp" style="width:' + pc(a.disponibile) + '"></i>' +
      '<i class="b-fuori" style="width:' + pc(a.in_prestito) + '"></i>' +
      '<i class="b-manc" style="width:' + pc(m) + '"></i></span>';
  }

  function disegnaInventario() {
    var q   = $('#inv-cerca').value.trim().toLowerCase();
    var cat = $('#inv-cat').value;
    var ord = $('#inv-ordine').value;

    var lista = D.inventario.filter(function (a) {
      if (cat && a.categoria !== cat) return false;
      if (!q) return true;
      return (a.categoria + ' ' + a.nome + ' ' + (a.note || '')).toLowerCase().indexOf(q) !== -1;
    });

    if (ord === 'nome')        lista.sort(function (x, y) { return x.nome.localeCompare(y.nome, 'it'); });
    if (ord === 'totale')      lista.sort(function (x, y) { return y.quantita - x.quantita; });
    if (ord === 'disponibili') lista.sort(function (x, y) { return y.disponibile - x.disponibile; });
    if (ord === 'scorta')      lista.sort(function (x, y) { return x.disponibile - y.disponibile; });
    if (ord === 'fuori')       lista.sort(function (x, y) { return y.in_prestito - x.in_prestito; });
    if (ord === 'comprare')    lista.sort(function (x, y) { return ((y.da_comprare || 0) - (x.da_comprare || 0)) || (mancanti(y) - mancanti(x)); });

    $('#tab-inventario tbody').innerHTML = lista.length ? lista.map(function (a) {
      return '<tr id="art-' + esc(a.id) + '">' +
        '<td class="nome-art">' + conMini(a.nome, a.foto,
            esc(a.nome) + '<small>' + esc(a.categoria) + (a.note ? ' · ' + esc(a.note) : '') + '</small>') + '</td>' +
        '<td>' + barra(a) + '</td>' +
        '<td class="num">' + a.quantita + '</td>' +
        '<td class="num">' + (a.in_prestito ? '<span class="tag fuori">' + a.in_prestito + '</span>' : '—') + '</td>' +
        '<td class="num"><strong>' + a.disponibile + '</strong>' +
          (a.sotto_soglia ? ' <span class="tag male">sotto soglia</span>' : '') + '</td>' +
        '<td class="azioni"><span class="azioni-riga">' +
          '<button class="bottone chiaro mini stretto" data-carico="' + esc(a.id) + '" title="Registra un acquisto">+ Carico</button> ' +
          '<button class="bottone chiaro mini stretto" data-scarico="' + esc(a.id) + '" title="Scarta pezzi">&minus; Scarto</button> ' +
          '<button class="bottone chiaro mini stretto" data-modifica="' + esc(a.id) + '" title="Scheda e foto">Scheda</button>' +
        '</span></td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="6" class="vuoto" style="padding:22px 12px">Nessun articolo con questi filtri.</td></tr>';
  }

  function art(id) {
    return D.inventario.filter(function (a) { return a.id === id; })[0];
  }

  function pannelloGiacenza(id, tipo) {
    var a = art(id);
    if (!a) return;
    var titolo = tipo === 'acquisto' ? 'Registra un acquisto' : 'Scarta attrezzatura';
    var testo  = tipo === 'acquisto'
      ? 'I pezzi entrano subito in giacenza e scalano la lista acquisti.'
      : 'I pezzi escono dalla giacenza. Usalo per materiale buttato, rotto o fuori vita.';
    apriPannello(titolo,
      '<div class="avviso">' + esc(a.nome) + ' — oggi in carico <strong>' + a.quantita + '</strong> pezzi, ' + a.in_prestito + ' fuori.<br>' + testo + '</div>' +
      '<label class="campo"><span>Quanti pezzi</span><input type="number" id="g-qta" min="1" value="1"></label>' +
      '<label class="campo"><span>Nota</span><input type="text" id="g-nota" placeholder="' +
        (tipo === 'acquisto' ? 'Fornitore, fattura, data ordine…' : 'Motivo dello scarto') + '"></label>',
      function () {
        var q = parseInt($('#g-qta').value, 10) || 0;
        if (q <= 0) { toast('Indica quanti pezzi.', 'male'); return; }
        scrivi('giacenza', { id: id, tipo: tipo, qta: q, nota: $('#g-nota').value },
          tipo === 'acquisto' ? 'Acquisto registrato.' : 'Scarto registrato.').then(function (ok) { if (ok) chiudiPannello(); });
      },
      tipo === 'acquisto' ? 'Carica in magazzino' : 'Togli dal magazzino');
  }

  function pannelloScheda(id) {
    var a = id ? art(id) : null;
    var cats = {};
    D.inventario.forEach(function (x) { cats[x.categoria] = 1; });
    var catOrdinate = Object.keys(cats).sort();
    var catAttuale = a ? a.categoria : '';
    var catNuova = catAttuale === '' || catOrdinate.indexOf(catAttuale) === -1;

    var bloccoFoto = a
      ? '<div class="foto-scheda">' +
          '<div id="s-foto-vista">' + (a.foto
            ? '<img class="grande" src="foto/' + encodeURIComponent(a.foto) + '" alt="">'
            : mini(a.nome, '', false).replace('class="mini mini-vuota"', 'class="grande mini-vuota"')) + '</div>' +
          '<div style="flex:1;min-width:0">' +
            '<div class="meta" style="margin-bottom:6px">Foto dell\'articolo</div>' +
            '<input type="file" id="s-foto" accept="image/*">' +
            '<p class="meta" style="margin:8px 0 0;text-transform:none;letter-spacing:0">' +
              'JPG, PNG o WEBP. Viene ridotta a 640 px e salvata subito.</p>' +
            '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">' +
              (a.foto ? '<button class="bottone chiaro mini" type="button" id="s-foto-togli">Togli la foto</button>' : '') +
              '<button class="bottone chiaro mini" type="button" id="s-foto-sfoglia">Scegli tra le foto già presenti</button>' +
            '</div>' +
            '<div class="scegli-foto-griglia" id="s-foto-galleria" hidden></div>' +
          '</div>' +
        '</div>'
      : '<div class="avviso">La foto si aggiunge dalla scheda, dopo aver creato l\'articolo.</div>';

    apriPannello(a ? 'Scheda articolo' : 'Nuovo articolo',
      bloccoFoto +
      '<label class="campo"><span>Categoria</span>' +
        '<select id="s-cat">' +
          '<option value="__nuova__"' + (catNuova ? ' selected' : '') + '>+ Nuova categoria…</option>' +
          catOrdinate.map(function (c) {
            return '<option value="' + esc(c) + '"' + (!catNuova && c === catAttuale ? ' selected' : '') + '>' + esc(c) + '</option>';
          }).join('') +
        '</select>' +
        '<input type="text" id="s-cat-nuova" placeholder="Nome della nuova categoria" value="' + (catNuova ? esc(catAttuale) : '') + '"' +
          ' style="margin-top:8px' + (catNuova ? '' : ';display:none') + '">' +
      '</label>' +
      '<label class="campo"><span>Articolo</span><input type="text" id="s-art" value="' + esc(a ? a.articolo : '') + '" placeholder="Moschettoni, Corde 10mm…"></label>' +
      '<label class="campo"><span>Tipo o misura</span><input type="text" id="s-tipo" value="' + esc(a ? a.tipo : '') + '" placeholder="Ovali lega, 40 m…"></label>' +
      (a ? '' : '<label class="campo"><span>Pezzi iniziali</span><input type="number" id="s-qta" min="0" value="0"></label>') +
      '<div class="griglia g2" style="gap:12px">' +
        '<label class="campo"><span>Soglia minima</span><input type="number" id="s-soglia" min="0" value="' + (a ? a.soglia_minima : 0) + '"></label>' +
        '<label class="campo"><span>Da comprare</span><input type="number" id="s-comp" min="0" value="' + (a ? (a.da_comprare || 0) : 0) + '"></label>' +
      '</div>' +
      '<label class="campo"><span>Note</span><input type="text" id="s-note" value="' + esc(a ? a.note : '') + '"></label>' +
      '<label style="display:flex;align-items:center;gap:8px;font-size:14px"><input type="checkbox" id="s-prest" style="width:auto"' +
        (!a || a.prestabile !== false ? ' checked' : '') + '> i soci possono prenderlo dall\'area pubblica</label>' +
      (a ? '<div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--linea)">' +
           '<button class="bottone pericolo mini" id="s-elimina">Elimina l\'articolo</button></div>' : ''),
      function () {
        var payload = {
          id: a ? a.id : '',
          categoria: $('#s-cat').value === '__nuova__' ? $('#s-cat-nuova').value.trim() : $('#s-cat').value,
          articolo: $('#s-art').value,
          tipo: $('#s-tipo').value,
          soglia_minima: $('#s-soglia').value,
          da_comprare: $('#s-comp').value,
          note: $('#s-note').value,
          prestabile: $('#s-prest').checked
        };
        if (!a) payload.quantita = $('#s-qta').value;
        scrivi('articolo_salva', payload, a ? 'Scheda aggiornata.' : 'Articolo creato.').then(function (ok) { if (ok) chiudiPannello(); });
      },
      a ? 'Salva la scheda' : 'Crea l\'articolo');

    $('#s-cat').addEventListener('change', function () {
      var nuova = this.value === '__nuova__';
      $('#s-cat-nuova').style.display = nuova ? '' : 'none';
      if (nuova) $('#s-cat-nuova').focus();
    });

    if (a) {
      $('#s-foto').addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        caricaFoto(a.id, file, this);
      });
      var togli = $('#s-foto-togli');
      if (togli) {
        togli.addEventListener('click', function () {
          scrivi('foto_elimina', { id: a.id }, 'Foto rimossa.').then(function (ok) {
            if (ok) { chiudiPannello(); }
          });
        });
      }
      $('#s-foto-sfoglia').addEventListener('click', function () {
        var galleria = $('#s-foto-galleria');
        if (galleria.hidden) { disegnaGalleriaFoto(a); }
        galleria.hidden = !galleria.hidden;
        this.textContent = galleria.hidden ? 'Scegli tra le foto già presenti' : 'Nascondi le foto già presenti';
      });
      $('#s-elimina').addEventListener('click', function () {
        if (!confirm('Elimini ' + a.nome + ' dal magazzino? Lo storico dei prelievi resta.')) return;
        scrivi('articolo_elimina', { id: a.id }, 'Articolo eliminato.').then(function (ok) { if (ok) chiudiPannello(); });
      });
    }
  }

  function caricaFoto(id, file, input) {
    var fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('id', id);
    fd.append('foto', file);
    if (input) input.disabled = true;
    toast('Sto caricando la foto…');

    fetch('api.php?azione=foto_carica', { method: 'POST', body: fd })
      .then(function (r) {
        if (r.status === 401) { location.href = 'login.php'; return { ok: false, errore: 'Sessione scaduta.' }; }
        return r.json().catch(function () { return { ok: false, errore: 'Risposta non leggibile dal server.' }; });
      })
      .then(function (d) {
        if (input) { input.disabled = false; input.value = ''; }
        if (!d.ok) { toast(d.errore || 'Foto non caricata.', 'male'); return; }
        var vista = $('#s-foto-vista');
        if (vista) {
          vista.innerHTML = '<img class="grande" src="foto/' + encodeURIComponent(d.foto) + '" alt="">';
        }
        toast('Foto aggiornata.', 'ok');
        carica();
      })
      .catch(function () {
        if (input) input.disabled = false;
        toast('Server non raggiungibile.', 'male');
      });
  }

  // Le foto gia' caricate per altri articoli si possono riusare cosi'
  // com'e', senza doverle scaricare dal telefono e ricaricarle daccapo.
  function disegnaGalleriaFoto(a) {
    var galleria = $('#s-foto-galleria');
    var viste = {};
    var voci = [];
    D.inventario.forEach(function (x) {
      if (!x.foto || x.foto === a.foto || viste[x.foto]) return;
      viste[x.foto] = true;
      voci.push(x);
    });
    if (!voci.length) {
      galleria.innerHTML = '<p class="meta" style="text-transform:none;letter-spacing:0;margin:8px 0 0">' +
        'Non ci sono altre foto caricate su altri articoli.</p>';
      return;
    }
    galleria.innerHTML = voci.map(function (x) {
      return '<span class="scegli-foto-item">' +
               '<button type="button" class="scegli" data-foto="' + esc(x.foto) + '" title="Usata da ' + esc(x.nome) + '">' +
                 '<img src="foto/' + encodeURIComponent(x.foto) + '" alt="' + esc(x.nome) + '"></button>' +
               '<button type="button" class="elimina" data-elimina-foto="' + esc(x.foto) + '" title="Elimina questa foto dal server" aria-label="Elimina questa foto dal server">&times;</button>' +
             '</span>';
    }).join('');
    $$('.scegli-foto-item .scegli', galleria).forEach(function (btn) {
      btn.addEventListener('click', function () { assegnaFoto(a.id, btn.getAttribute('data-foto')); });
    });
    $$('.scegli-foto-item .elimina', galleria).forEach(function (btn) {
      btn.addEventListener('click', function () { eliminaFotoOvunque(btn.getAttribute('data-elimina-foto'), a); });
    });
  }

  /**
   * Toglie una foto dal server per davvero: diverso dal bottone "Togli
   * la foto" della scheda, che stacca solo quella dell'articolo aperto.
   * Qui si agisce su una foto della galleria, che puo' essere usata da
   * altri articoli: si tolgono a tutti, poi il file sparisce dal disco.
   */
  function eliminaFotoOvunque(nomeFoto, aCorrente) {
    if (!nomeFoto) return;
    if (!confirm('Elimini questa foto dal server? Se e\' usata da altri articoli, viene tolta anche a loro.')) return;
    scrivi('foto_elimina_ovunque', { foto: nomeFoto }, 'Foto eliminata dal server.').then(function (ok) {
      if (ok && aCorrente) { disegnaGalleriaFoto(art(aCorrente.id) || aCorrente); }
    });
  }

  function assegnaFoto(id, nomeFoto) {
    scrivi('foto_assegna', { id: id, foto: nomeFoto }, 'Foto scelta.').then(function (ok) {
      if (ok) { chiudiPannello(); }
    });
  }

  // ---------------------------------------------------------- non rientrato

  function schedaPrestito(p) {
    var ritardo = p.giorni >= D.giorni;
    return '<article class="scheda-prestito' + (ritardo ? ' ritardo' : '') + '">' +
      '<h4>' + esc(p.persona) + '</h4>' +
      '<div class="meta">Uscito il ' + data(p.uscita, true) + ' · ' + p.giorni + ' giorni fa · ' + p.pezzi_fuori + ' pezzi' +
        (p.destinazione ? ' · ' + esc(p.destinazione) : '') + '</div>' +
      (p.contatto ? '<div class="meta">' + esc(p.contatto) + '</div>' : '') +
      (p.rientro_atteso ? '<div class="meta">rientro previsto ' + data(p.rientro_atteso) + '</div>' : '') +
      (ritardo ? '<div style="margin-top:8px"><span class="tag male">in ritardo</span></div>' : '') +
      '<ul class="elenco-righe">' + p.righe.map(function (r) {
        return '<li><span class="q">' + r.residuo + '×</span>' +
               conMini(r.nome, FOTO[r.id_articolo], esc(r.nome), true) + '</li>';
      }).join('') + '</ul>' +
      (p.note ? '<div class="meta" style="margin-top:10px">Nota: ' + esc(p.note) + '</div>' : '') +
      '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">' +
        '<button class="bottone chiaro mini" data-chiudi-prestito="' + esc(p.id) + '">Chiudi il prelievo</button>' +
      '</div></article>';
  }

  function disegnaFuori() {
    var q = $('#fuori-cerca').value.trim().toLowerCase();
    var soloR = $('#fuori-solo-ritardo').checked;
    var lista = D.aperti.filter(function (p) {
      if (soloR && p.giorni < D.giorni) return false;
      if (!q) return true;
      var testo = p.persona + ' ' + p.destinazione + ' ' + p.righe.map(function (r) { return r.nome; }).join(' ');
      return testo.toLowerCase().indexOf(q) !== -1;
    });
    $('#lista-fuori').innerHTML = lista.length
      ? lista.map(schedaPrestito).join('')
      : '<div class="avviso ok">Non c\'e\' attrezzatura fuori.</div>';
  }

  function pannelloChiusura(id) {
    var p = D.aperti.filter(function (x) { return x.id === id; })[0];
    if (!p) return;
    apriPannello('Chiudi il prelievo di ' + p.persona,
      '<div class="avviso luce">Restano fuori ' + p.pezzi_fuori + ' pezzi. Scegli come chiudere.</div>' +
      '<label class="campo"><span>Cosa e\' successo</span><select id="c-modo">' +
        '<option value="rientrato">Tutto rientrato a magazzino</option>' +
        '<option value="perso">Perso o distrutto (esce dalle giacenze)</option>' +
      '</select></label>' +
      '<label class="campo"><span>Nota</span><textarea id="c-nota" placeholder="Cosa e\' successo, chi ha riportato, dove e\' rimasto"></textarea></label>',
      function () {
        scrivi('prestito_chiudi', { id: id, modo: $('#c-modo').value, nota: $('#c-nota').value }, 'Prelievo chiuso.')
          .then(function (ok) { if (ok) chiudiPannello(); });
      }, 'Chiudi il prelievo');
  }

  // ---------------------------------------------------------- storico

  function statoTag(s) {
    if (s === 'chiuso')   return '<span class="tag ok">chiuso</span>';
    if (s === 'parziale') return '<span class="tag fuori">rientro parziale</span>';
    return '<span class="tag male">aperto</span>';
  }

  function disegnaStorico() {
    var q     = $('#st-cerca').value.trim().toLowerCase();
    var st    = $('#st-stato').value;
    var dal   = $('#st-dal').value;
    var al    = $('#st-al').value;

    var lista = D.storico.filter(function (p) {
      if (st && p.stato !== st) return false;
      if (dal && p.uscita.slice(0, 10) < dal) return false;
      if (al  && p.uscita.slice(0, 10) > al)  return false;
      if (!q) return true;
      var testo = p.persona + ' ' + p.destinazione + ' ' + (p.note || '') + ' ' +
                  p.righe.map(function (r) { return r.nome; }).join(' ');
      return testo.toLowerCase().indexOf(q) !== -1;
    });

    $('#tab-storico tbody').innerHTML = lista.length ? lista.map(function (p) {
      var dettaglio =
        '<tr class="dettaglio" id="det-' + esc(p.id) + '" hidden><td colspan="8" style="background:#F7F9F8">' +
        '<div style="display:flex;gap:30px;flex-wrap:wrap">' +
          '<div><div class="meta">Materiale</div><ul class="elenco-righe">' +
            p.righe.map(function (r) {
              var res = r.qta - r.qta_rientrata - r.qta_persa;
              return '<li><span class="q">' + r.qta + '×</span>' +
                     conMini(r.nome, FOTO[r.id_articolo], esc(r.nome), true) +
                     '<span style="margin-left:auto" class="meta">rientrati ' + r.qta_rientrata +
                     (r.qta_persa ? ' · persi ' + r.qta_persa : '') +
                     (res > 0 ? ' · fuori ' + res : '') + '</span></li>';
            }).join('') + '</ul></div>' +
          '<div><div class="meta">Rientri</div><ul class="elenco-righe">' +
            (p.rientri.length ? p.rientri.map(function (r) {
              return '<li><span>' + data(r.quando, true) + ' — ' + esc(r.chi) +
                     (r.nota ? ': ' + esc(r.nota) : '') + '</span></li>';
            }).join('') : '<li><span class="meta">nessun rientro registrato</span></li>') + '</ul></div>' +
        '</div>' +
        (p.contatto ? '<div class="meta" style="margin-top:10px">Contatto: ' + esc(p.contatto) + '</div>' : '') +
        (p.note ? '<div class="meta">Nota del prelievo: ' + esc(p.note) + '</div>' : '') +
        '</td></tr>';

      return '<tr>' +
        '<td>' + data(p.uscita, true) + '</td>' +
        '<td class="nome-art">' + esc(p.persona) + '</td>' +
        '<td>' + (esc(p.destinazione) || '—') + '</td>' +
        '<td class="num">' + p.pezzi + '</td>' +
        '<td class="num">' + (p.residui || '—') + '</td>' +
        '<td class="num">' + (p.persi ? '<span class="tag male">' + p.persi + '</span>' : '—') + '</td>' +
        '<td>' + statoTag(p.stato) + '</td>' +
        '<td class="azioni"><span class="azioni-riga">' +
          '<button class="bottone chiaro mini" data-espandi="' + esc(p.id) + '">Dettagli</button>' +
          (p.stato === 'chiuso'
            ? ' <button class="bottone chiaro mini stretto" data-elimina-prestito="' + esc(p.id) +
              '" title="Cancella il file di questo prelievo">Elimina</button>'
            : '') +
        '</span></td>' +
      '</tr>' + dettaglio;
    }).join('') : '<tr><td colspan="8" class="vuoto" style="padding:22px 12px">Nessun prelievo con questi filtri.</td></tr>';
  }

  // I tre scarichi dello Storico seguono le date scelte nei filtri.
  function aggiornaLinkExport() {
    var dal = $('#st-dal').value;
    var al  = $('#st-al').value;
    var coda = (dal ? '&dal=' + dal : '') + (al ? '&al=' + al : '');

    [['#csv-storico', 'storico'], ['#csv-prestato', 'prestato'], ['#csv-prestato-riep', 'prestato_riepilogo']]
      .forEach(function (v) { $(v[0]).href = 'export.php?cosa=' + v[1] + coda; });

    $('#st-periodo').textContent = (dal || al)
      ? ' — prelievi usciti ' + (dal ? 'dal ' + data(dal) : '') + (dal && al ? ' ' : '') + (al ? 'al ' + data(al) : '') + '.'
      : ' — nessuna data scelta: scarica tutto. Imposta le due date qui sopra per limitare il periodo.';
  }

  // ---------------------------------------------------------- movimenti

  function disegnaMovimenti() {
    var q = $('#mv-cerca').value.trim().toLowerCase();
    var lista = D.movimenti.filter(function (m) {
      if (!q) return true;
      return ((m.nome || '') + ' ' + (m.nota || '') + ' ' + (m.tipo || '') + ' ' + (m.da || '')).toLowerCase().indexOf(q) !== -1;
    });
    $('#tab-movimenti tbody').innerHTML = lista.length ? lista.map(function (m) {
      var v = m.qta || 0;
      var classe = v > 0 ? 'ok' : (v < 0 ? 'male' : '');
      return '<tr>' +
        '<td>' + data(m.quando, true) + '</td>' +
        '<td><span class="tag ' + classe + '">' + esc(m.tipo) + '</span></td>' +
        '<td class="nome-art">' + (m.id_articolo
            ? conMini(m.nome || '', FOTO[m.id_articolo], esc(m.nome || '—'), true)
            : esc(m.nome || '—')) + '</td>' +
        '<td class="num">' + (v > 0 ? '+' + v : (v || '—')) + '</td>' +
        '<td class="num">' + (m.giacenza != null ? m.giacenza : '—') + '</td>' +
        '<td>' + esc(m.da || '—') + '</td>' +
        '<td style="max-width:280px">' + esc(m.nota || '') + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="7" class="vuoto" style="padding:22px 12px">Nessun movimento.</td></tr>';
  }

  // ---------------------------------------------------------- accessi

  function disegnaUtenti() {
    $('#tab-utenti tbody').innerHTML = D.utenti.map(function (u) {
      var eSuper = u.id === D.superadmin;
      // il Superadmin non si revoca: prima passa il ruolo, poi semmai esce
      var comandi = eSuper
        ? '<span class="meta">Superadmin</span>'
        : '<button class="bottone chiaro mini" data-reset-utente="' + esc(u.id) + '">Reimposta password</button> ' +
          '<button class="bottone chiaro mini" data-elimina-utente="' + esc(u.id) + '">Revoca</button>';
      return '<tr><td class="nome-art">' + esc(u.nome) + '</td><td class="meta">' + esc(u.user) + '</td>' +
        '<td style="text-align:right;white-space:nowrap">' + comandi + '</td></tr>';
    }).join('');

    // gli altri amministratori a cui si puo' passare il ruolo
    var sel = $('#sa-chi');
    if (!sel) return;
    var altri = D.utenti.filter(function (u) { return u.id !== D.superadmin; });
    var scelto = sel.value;
    sel.innerHTML = altri.length
      ? altri.map(function (u) {
          return '<option value="' + esc(u.id) + '">' + esc(u.nome) + ' (' + esc(u.user) + ')</option>';
        }).join('')
      : '<option value="">Non c\'e\' nessun altro amministratore</option>';
    if (scelto) { sel.value = scelto; }
    $('#btn-passa-ruolo').disabled = !altri.length;
  }

  function pannelloReset(id) {
    var u = D.utenti.filter(function (x) { return x.id === id; })[0];
    if (!u) return;
    apriPannello('Reimposta la password di ' + u.nome,
      '<p class="guida" style="margin-top:0">Scegli una password provvisoria e comunicagliela a voce. ' +
      'Al primo accesso dovra\' sostituirla con una sua: cosi\' non resti a conoscenza della password di nessuno.</p>' +
      '<label class="campo"><span>Password provvisoria</span>' +
      '<input type="password" id="rp-pass" autocomplete="new-password"></label>' +
      '<label class="campo"><span>Ripeti la password</span>' +
      '<input type="password" id="rp-pass2" autocomplete="new-password"></label>' +
      '<ul class="regole-pass" id="rp-esito"></ul>',
      function () {
        if (!controllo.valida()) { toast('La password non rispetta ancora le regole.', 'male'); return; }
        chiudiPannello();
        scrivi('utente_reset_password', { id: id, nuova: $('#rp-pass').value },
               'Password reimpostata. Comunicagliela a voce.');
      },
      'Reimposta');

    var controllo = window.ControlloPassword.collega({
      pass:     $('#rp-pass'),
      conferma: $('#rp-pass2'),
      esito:    $('#rp-esito'),
      bottone:  $('#velo-ok')
    });
  }

  function passaRuolo() {
    var id  = $('#sa-chi').value;
    var chi = D.utenti.filter(function (u) { return u.id === id; })[0];
    if (!id || !chi) { toast('Scegli a chi passare il ruolo.', 'male'); return; }
    if (!$('#sa-pass').value) { toast('Scrivi la tua password per confermare.', 'male'); return; }
    if (!confirm('Passi il ruolo di Superadmin a ' + chi.nome + '?\n\n' +
                 'Da subito sara\' lui a gestire accessi, impostazioni e aggiornamenti.\n' +
                 'Tu resti un amministratore come gli altri.')) return;

    // niente scrivi(): le sezioni della pagina le decide il server al
    // momento in cui la scrive, quindi qui serve un giro completo
    api('superadmin_trasferisci', { id: id, password: $('#sa-pass').value }).then(function (d) {
      $('#sa-pass').value = '';
      if (!d.ok) { toast(d.errore || 'Operazione non riuscita.', 'male'); return; }
      location.reload();
    });
  }

  // ---------------------------------------------------------- soci

  function disegnaSoci() {
    var attesa = D.accountSoci.filter(function (s) { return s.stato === 'in_attesa'; });
    var attivi = D.accountSoci.filter(function (s) { return s.stato === 'attivo'; });
    var altri  = D.accountSoci.filter(function (s) { return s.stato === 'disabilitato' || s.stato === 'rifiutato'; });

    var avviso = $('#soci-avviso-modalita');
    if (D.impostazioni && D.impostazioni.accesso_soci !== 'account') {
      avviso.hidden = false;
      avviso.textContent = 'L\'area soci usa ancora il codice di gruppo. Gli account restano in attesa ' +
        'finche\' non passi alla modalita\' "account personali" dalle Impostazioni.';
    } else {
      avviso.hidden = true;
    }

    $('#tab-soci-attesa tbody').innerHTML = attesa.length ? attesa.map(function (s) {
      return '<tr><td class="nome-art">' + esc(s.nome) + '</td><td class="meta">' + esc(s.email) + '</td>' +
        '<td>' + data(s.creato_il, true) + '</td>' +
        '<td style="text-align:right;white-space:nowrap">' +
          '<button class="bottone chiaro mini" data-approva-socio="' + esc(s.id) + '">Approva</button> ' +
          '<button class="bottone chiaro mini" data-rifiuta-socio="' + esc(s.id) + '">Rifiuta</button>' +
        '</td></tr>';
    }).join('') : '<tr><td colspan="4" class="vuoto" style="padding:20px 12px">Nessuna richiesta in attesa.</td></tr>';

    $('#tab-soci-attivi tbody').innerHTML = attivi.length ? attivi.map(function (s) {
      return '<tr><td class="nome-art">' + esc(s.nome) + '</td><td class="meta">' + esc(s.email) + '</td>' +
        '<td style="text-align:right;white-space:nowrap">' +
          '<button class="bottone chiaro mini" data-reset-socio="' + esc(s.id) + '">Reimposta password</button> ' +
          '<button class="bottone chiaro mini" data-disabilita-socio="' + esc(s.id) + '">Disabilita</button> ' +
          '<button class="bottone chiaro mini stretto" data-elimina-socio="' + esc(s.id) + '">Elimina</button>' +
        '</td></tr>';
    }).join('') : '<tr><td colspan="3" class="vuoto" style="padding:20px 12px">Nessun socio attivo.</td></tr>';

    $('#tab-soci-altri tbody').innerHTML = altri.length ? altri.map(function (s) {
      return '<tr><td class="nome-art">' + esc(s.nome) + '</td><td class="meta">' + esc(s.email) + '</td>' +
        '<td>' + (s.stato === 'disabilitato' ? '<span class="tag male">disabilitato</span>' : '<span class="tag">rifiutato</span>') + '</td>' +
        '<td style="text-align:right;white-space:nowrap">' +
          (s.stato === 'disabilitato' ? '<button class="bottone chiaro mini" data-riabilita-socio="' + esc(s.id) + '">Riabilita</button> ' : '') +
          '<button class="bottone chiaro mini stretto" data-elimina-socio="' + esc(s.id) + '">Elimina</button>' +
        '</td></tr>';
    }).join('') : '<tr><td colspan="4" class="vuoto" style="padding:20px 12px">Nessuno.</td></tr>';
  }

  function pannelloResetSocio(id) {
    var s = D.accountSoci.filter(function (x) { return x.id === id; })[0];
    if (!s) return;
    apriPannello('Reimposta la password di ' + s.nome,
      '<p class="guida" style="margin-top:0">Scegli una password provvisoria e comunicagliela a voce. ' +
      'Al primo accesso dovra\' sostituirla con una sua: cosi\' non resti a conoscenza della password di nessuno.</p>' +
      '<label class="campo"><span>Password provvisoria</span>' +
      '<input type="password" id="rps-pass" autocomplete="new-password"></label>' +
      '<label class="campo"><span>Ripeti la password</span>' +
      '<input type="password" id="rps-pass2" autocomplete="new-password"></label>' +
      '<ul class="regole-pass" id="rps-esito"></ul>',
      function () {
        if (!controllo.valida()) { toast('La password non rispetta ancora le regole.', 'male'); return; }
        chiudiPannello();
        scrivi('socio_reset_password', { id: id, nuova: $('#rps-pass').value },
               'Password reimpostata. Comunicagliela a voce.');
      },
      'Reimposta');

    var controllo = window.ControlloPassword.collega({
      pass:     $('#rps-pass'),
      conferma: $('#rps-pass2'),
      esito:    $('#rps-esito'),
      bottone:  $('#velo-ok')
    });
  }

  // ---------------------------------------------------------- impostazioni

  function disegnaImpostazioni() {
    var i = D.impostazioni || {};
    $('#i-nome').value    = i.nome_gruppo || '';
    $('#i-sotto').value   = i.sottotitolo || '';
    $('#i-ritardo').value = i.giorni_ritardo || 14;
    $('#i-giorni').value  = i.codice_giorni || 90;

    var logo = i.logo
      ? '<img class="grande" src="foto/' + encodeURIComponent(i.logo) + '" alt="">'
      : '<span class="grande mini-vuota" style="--tinta-sf:var(--linea-2);--tinta-in:var(--grigio)">?</span>';
    $('#i-logo-vista').innerHTML = logo;

    $('#i-stato-area').textContent = i.area_protetta
      ? 'Adesso serve il codice del gruppo per entrare nell\'area soci.'
      : 'Adesso l\'area soci è aperta a chiunque abbia il link.';

    $('#i-accesso-soci').value = i.accesso_soci === 'account' ? 'account' : 'codice';
    mostraBloccoAccessoSoci();

    disegnaColori();
    anteprimaTestata();
  }

  function mostraBloccoAccessoSoci() {
    var account = $('#i-accesso-soci').value === 'account';
    $('#i-blocco-codice').hidden  = account;
    $('#i-blocco-account').hidden = !account;
  }

  // ------------------------------------------------- aspetto

  // Il valore che vale adesso per una variabile CSS: quello scelto dal
  // gruppo, oppure quello di serie letto dal foglio di stile.
  function coloreCorrente(salvato, variabile) {
    if (/^#[0-9a-f]{6}$/i.test(salvato || '')) return salvato;
    var css = getComputedStyle(document.documentElement).getPropertyValue(variabile).trim();
    return /^#[0-9a-f]{6}$/i.test(css) ? css : '#000000';
  }

  function disegnaColori() {
    var i = D.impostazioni || {};
    $('#i-col-luce').value       = coloreCorrente(i.colore_luce, '--lampada');
    $('#i-col-luce-testo').value = coloreCorrente(i.colore_luce_testo, '--lampada-testo');
    $('#i-col-fondo').value      = coloreCorrente(i.colore_fondo, '--fondo');
    $('#i-col-ink').value        = coloreCorrente(i.colore_inchiostro, '--ink');

    var raggio = i.raggio;
    if (raggio === '' || raggio == null) {
      raggio = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--raggio'), 10);
      if (isNaN(raggio)) raggio = 4;
    }
    $('#i-raggio').value = raggio;
  }

  // L'anteprima si vede subito, senza salvare: si scrivono le variabili
  // sul riquadro di anteprima, non su tutta la pagina.
  function anteprimaColori() {
    var box = $('#i-anteprima-nome').closest('.testata');
    if (!box) return;
    box.style.setProperty('--ink', $('#i-col-ink').value);
    box.style.setProperty('--lampada', $('#i-col-luce').value);
    box.style.setProperty('--lampada-testo', $('#i-col-luce-testo').value);
    box.style.setProperty('--fondo', $('#i-col-fondo').value);
    var r = parseInt($('#i-raggio').value, 10);
    box.style.setProperty('--raggio', (isNaN(r) ? 4 : r) + 'px');
  }

  function coloriDiSerie() {
    if (!confirm('Rimetto i colori di serie?')) return;
    var fd = new FormData($('#form-impostazioni'));
    fd.append('csrf', CSRF);
    fd.append('colori_di_serie', '1');
    inviaImpostazioni(fd, $('#btn-colori-serie'));
  }

  function anteprimaTestata() {
    var i = D.impostazioni || {};
    $('#i-anteprima-nome').textContent  = $('#i-nome').value || i.nome_gruppo || '';
    $('#i-anteprima-sotto').textContent = $('#i-sotto').value || i.sottotitolo || '';
    $('#i-anteprima-logo').innerHTML = i.logo
      ? '<img class="logo" src="foto/' + encodeURIComponent(i.logo) + '" alt="">'
      : '<span class="faro"></span>';
  }

  function salvaImpostazioni() {
    var fd = new FormData($('#form-impostazioni'));
    fd.append('csrf', CSRF);
    inviaImpostazioni(fd, $('#btn-salva-impostazioni'));
  }

  function inviaImpostazioni(fd, btn) {
    btn.disabled = true;

    fetch('api.php?azione=impostazioni_salva', { method: 'POST', body: fd })
      .then(function (r) {
        if (r.status === 401) { location.href = 'login.php'; return { ok: false }; }
        return r.json().catch(function () { return { ok: false, errore: 'Risposta non leggibile.' }; });
      })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { toast(d.errore || 'Non salvato.', 'male'); return; }
        toast('Impostazioni salvate. Ricarico la pagina…', 'ok');
        sessionStorage.setItem('db-sezione-dopo-salvataggio', 'impostazioni');
        setTimeout(function () { location.reload(); }, 900);
      })
      .catch(function () { btn.disabled = false; toast('Server non raggiungibile.', 'male'); });
  }

  // ---------------------------------------------------------- pulizia archivio

  function contati(n) {
    return n === 1 ? '1 prelievo' : n + ' prelievi';
  }

  function chiusiPrimaDel(giorno) {
    return D.storico.filter(function (p) {
      if (p.stato !== 'chiuso') return false;
      var quando = (p.chiuso_il || p.uscita || '').slice(0, 10);
      return quando !== '' && quando < giorno;
    });
  }

  function pannelloPulizia() {
    var d = new Date();
    d.setFullYear(d.getFullYear() - 2);
    var predefinita = d.toISOString().slice(0, 10);

    apriPannello('Elimina i prelievi chiusi',
      '<div class="avviso">Vengono cancellati i file dei prelievi <strong>completamente riconsegnati</strong> ' +
        'chiusi prima della data scelta. Le giacenze non cambiano e nei movimenti resta la nota.</div>' +
      '<label class="campo"><span>Elimina i chiusi prima del</span>' +
        '<input type="date" id="pu-data" value="' + predefinita + '"></label>' +
      '<p class="meta" id="pu-conta" style="text-transform:none;letter-spacing:0"></p>',
      function () {
        var giorno = $('#pu-data').value;
        if (!giorno) { toast('Scegli una data.', 'male'); return; }
        var quanti = chiusiPrimaDel(giorno).length;
        if (!quanti) { toast('Non c\'e\' niente da eliminare prima di quella data.', 'male'); return; }
        if (!confirm('Elimino ' + contati(quanti) + ' chiusi? I file spariscono per sempre.')) return;
        scrivi('prestiti_pulisci', { prima_del: giorno }, 'Archivio ripulito.')
          .then(function (ok) { if (ok) chiudiPannello(); });
      }, 'Elimina');

    function conta() {
      var giorno = $('#pu-data').value;
      var quanti = giorno ? chiusiPrimaDel(giorno).length : 0;
      $('#pu-conta').textContent = giorno
        ? (quanti ? 'Verrebbero eliminati ' + contati(quanti) + '.' : 'Nessun prelievo chiuso prima di questa data.')
        : 'Scegli una data.';
      $('#velo-ok').disabled = !quanti;
    }
    $('#pu-data').addEventListener('input', conta);
    conta();
  }

  // ---------------------------------------------------------- importazione

  function pannelloImporta() {
    apriPannello('Importa da foglio di calcolo',
      '<p class="guida">La prima riga deve contenere i nomi delle colonne. Servono almeno <strong>Articolo</strong> e <strong>Quantita</strong>.</p>' +
      '<table class="modello"><thead><tr><th>Categoria</th><th>Articolo</th><th>Tipo</th><th>Quantita</th><th>Soglia minima</th><th>Da comprare</th><th>Note</th></tr></thead>' +
      '<tbody><tr><td>Corde</td><td>Corda 10mm</td><td>40 m</td><td>2</td><td>2</td><td>0</td><td>una da lavare</td></tr>' +
      '<tr><td>Armo</td><td>Moschettoni</td><td>Ovali lega</td><td>67</td><td>40</td><td>20</td><td></td></tr></tbody></table>' +
      '<p><a class="bottone chiaro mini" href="api.php?azione=modello_csv">Scarica il modello CSV</a></p>' +
      '<label class="campo"><span>File CSV o XLSX</span><input type="file" id="im-file" accept=".csv,.txt,.tsv,.xlsx,.xlsm"></label>' +
      '<label class="campo"><span>Cosa faccio con l\'inventario attuale</span><select id="im-modo">' +
        '<option value="aggiungi">Aggiungo solo gli articoli nuovi</option>' +
        '<option value="sostituisci">Azzero tutto e carico solo questi</option>' +
      '</select></label>' +
      '<p class="meta" style="text-transform:none;letter-spacing:0">Gli articoli gia\' presenti non vengono toccati: giacenze, foto e storico restano.</p>',
      function () {
        var f = $('#im-file').files && $('#im-file').files[0];
        if (!f) { toast('Scegli un file.', 'male'); return; }
        var fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('modo', $('#im-modo').value);
        fd.append('foglio', f);
        $('#velo-ok').disabled = true;
        toast('Sto leggendo il file…');

        fetch('api.php?azione=importa', { method: 'POST', body: fd })
          .then(function (r) { return r.json().catch(function () { return { ok: false, errore: 'Risposta non leggibile.' }; }); })
          .then(function (d) {
            $('#velo-ok').disabled = false;
            if (!d.ok) { toast(d.errore, 'male'); return; }
            chiudiPannello();
            toast(d.nuovi + ' articoli importati' + (d.saltati ? ', ' + d.saltati + ' già presenti saltati' : '') + '.', 'ok');
            carica();
          })
          .catch(function () { $('#velo-ok').disabled = false; toast('Server non raggiungibile.', 'male'); });
      }, 'Importa');
  }

  // ---------------------------------------------------------- eventi

  document.addEventListener('click', function (e) {
    var f = e.target.closest('img.mini[data-foto]');
    if (f && !f.closest('.foto-scheda')) {
      apriLente(f.getAttribute('src'), f.getAttribute('data-titolo'));
      return;
    }

    var t = e.target.closest('[data-vai],[data-chiudi],[data-carico],[data-scarico],[data-modifica],[data-chiudi-prestito],[data-espandi],[data-elimina-prestito],[data-elimina-utente],[data-reset-utente],[data-articolo],[data-approva-socio],[data-rifiuta-socio],[data-reset-socio],[data-disabilita-socio],[data-riabilita-socio],[data-elimina-socio]');
    if (!t) return;

    if (t.hasAttribute('data-vai')) {
      var vai = t.getAttribute('data-vai');
      $$('.tab').forEach(function (b) { b.classList.toggle('att', b === t); });
      $$('.sezione').forEach(function (s) { s.classList.toggle('att', s.id === 'sez-' + vai); });
      vaiA(t);
      return;
    }
    if (t.hasAttribute('data-chiudi'))          { chiudiPannello(); return; }
    if (t.hasAttribute('data-carico'))          { pannelloGiacenza(t.getAttribute('data-carico'), 'acquisto'); return; }
    if (t.hasAttribute('data-scarico'))         { pannelloGiacenza(t.getAttribute('data-scarico'), 'scarto'); return; }
    if (t.hasAttribute('data-modifica'))        { pannelloScheda(t.getAttribute('data-modifica')); return; }
    if (t.hasAttribute('data-chiudi-prestito')) { pannelloChiusura(t.getAttribute('data-chiudi-prestito')); return; }
    if (t.hasAttribute('data-espandi')) {
      var r = $('#det-' + t.getAttribute('data-espandi'));
      if (r) { r.hidden = !r.hidden; t.textContent = r.hidden ? 'Dettagli' : 'Nascondi'; }
      return;
    }
    if (t.hasAttribute('data-elimina-prestito')) {
      var idP = t.getAttribute('data-elimina-prestito');
      var pre = D.storico.filter(function (x) { return x.id === idP; })[0];
      if (!confirm('Elimino il prelievo' + (pre ? ' di ' + pre.persona : '') + '?\n\n' +
                   'Il file sparisce per sempre. Le giacenze non cambiano: il materiale e\' gia\' rientrato.')) return;
      scrivi('prestito_elimina', { id: idP }, 'Prelievo eliminato.');
      return;
    }
    if (t.hasAttribute('data-elimina-utente')) {
      if (!confirm('Revochi l\'accesso a questo amministratore?')) return;
      scrivi('utente_elimina', { id: t.getAttribute('data-elimina-utente') }, 'Accesso revocato.');
      return;
    }
    if (t.hasAttribute('data-reset-utente')) {
      pannelloReset(t.getAttribute('data-reset-utente'));
      return;
    }
    if (t.hasAttribute('data-approva-socio')) {
      scrivi('socio_approva', { id: t.getAttribute('data-approva-socio') }, 'Account approvato.');
      return;
    }
    if (t.hasAttribute('data-rifiuta-socio')) {
      if (!confirm('Rifiuti questa registrazione?')) return;
      scrivi('socio_rifiuta', { id: t.getAttribute('data-rifiuta-socio') }, 'Registrazione rifiutata.');
      return;
    }
    if (t.hasAttribute('data-reset-socio')) {
      pannelloResetSocio(t.getAttribute('data-reset-socio'));
      return;
    }
    if (t.hasAttribute('data-disabilita-socio')) {
      if (!confirm('Disabiliti l\'accesso di questo socio?')) return;
      scrivi('socio_disabilita', { id: t.getAttribute('data-disabilita-socio') }, 'Socio disabilitato.');
      return;
    }
    if (t.hasAttribute('data-riabilita-socio')) {
      scrivi('socio_riabilita', { id: t.getAttribute('data-riabilita-socio') }, 'Socio riabilitato.');
      return;
    }
    if (t.hasAttribute('data-elimina-socio')) {
      if (!confirm('Elimini definitivamente questo account socio?')) return;
      scrivi('socio_elimina', { id: t.getAttribute('data-elimina-socio') }, 'Account eliminato.');
      return;
    }
    if (t.hasAttribute('data-articolo')) {
      var tabInv = $('.tab[data-vai="inventario"]');
      $$('.tab').forEach(function (b) { b.classList.toggle('att', b === tabInv); });
      $$('.sezione').forEach(function (s) { s.classList.toggle('att', s.id === 'sez-inventario'); });
      vaiA(tabInv);
      $('#inv-cerca').value = '';
      $('#inv-cat').value = '';
      disegnaInventario();
      var riga = $('#art-' + t.getAttribute('data-articolo'));
      if (riga) {
        riga.scrollIntoView({ block: 'center', behavior: 'smooth' });
        riga.style.background = 'var(--lampada-sf)';
        setTimeout(function () { riga.style.background = ''; }, 1800);
      }
    }
  });

  $('#btn-nuovo').addEventListener('click', function () { pannelloScheda(null); });
  $('#btn-importa').addEventListener('click', pannelloImporta);

  if (SUPER) {
    $('#btn-salva-impostazioni').addEventListener('click', salvaImpostazioni);
    ['#i-nome', '#i-sotto'].forEach(function (s) { $(s).addEventListener('input', anteprimaTestata); });
    $('#i-accesso-soci').addEventListener('change', mostraBloccoAccessoSoci);

    var controlloPass = window.ControlloPassword.collega({
      pass:     $('#u-pass'),
      conferma: $('#u-pass2'),
      esito:    $('#u-pass-esito'),
      bottone:  $('#btn-nuovo-utente')
    });

    $('#btn-nuovo-utente').addEventListener('click', function () {
      if (!controlloPass.valida()) { toast('La password non rispetta ancora le regole.', 'male'); return; }
      scrivi('utente_nuovo', {
        nome: $('#u-nome').value, user: $('#u-user').value, password: $('#u-pass').value
      }, 'Amministratore aggiunto.').then(function (ok) {
        if (ok) {
          $('#u-nome').value = ''; $('#u-user').value = '';
          $('#u-pass').value = ''; $('#u-pass2').value = '';
          controlloPass.aggiorna();
        }
      });
    });

    $('#btn-passa-ruolo').addEventListener('click', passaRuolo);
  }

  var controlloMiaPass = window.ControlloPassword.collega({
    pass:     $('#mp-nuova'),
    conferma: $('#mp-nuova2'),
    esito:    $('#mp-esito'),
    bottone:  $('#btn-mia-password')
  });

  $('#btn-mia-password').addEventListener('click', function () {
    if (!$('#mp-attuale').value) { toast('Scrivi la password attuale.', 'male'); return; }
    if (!controlloMiaPass.valida()) { toast('La nuova password non rispetta ancora le regole.', 'male'); return; }
    scrivi('utente_mia_password', {
      attuale: $('#mp-attuale').value, nuova: $('#mp-nuova').value
    }, 'Password cambiata.').then(function (ok) {
      if (ok) {
        $('#mp-attuale').value = ''; $('#mp-nuova').value = ''; $('#mp-nuova2').value = '';
        controlloMiaPass.aggiorna();
      }
    });
  });

  ['#inv-cerca', '#inv-cat', '#inv-ordine'].forEach(function (s) { $(s).addEventListener('input', disegnaInventario); });
  ['#fuori-cerca', '#fuori-solo-ritardo'].forEach(function (s) { $(s).addEventListener('input', disegnaFuori); });
  $('#btn-pulisci-chiusi').addEventListener('click', pannelloPulizia);

  ['#st-cerca', '#st-stato', '#st-dal', '#st-al'].forEach(function (s) { $(s).addEventListener('input', disegnaStorico); });
  ['#st-dal', '#st-al'].forEach(function (s) { $(s).addEventListener('input', aggiornaLinkExport); });
  aggiornaLinkExport();
  $('#mv-cerca').addEventListener('input', disegnaMovimenti);

  // ---------------------------------------------------------- aggiornamenti
  //
  // Si guarda soltanto se e' uscita una versione nuova. Il programma
  // non si scarica e non si installa da solo: i file li carica una
  // persona via FTP, quando decide lei.

  function agganciaAggiornamenti() {
    $('#i-col-luce').addEventListener('input', anteprimaColori);
    $('#i-col-luce-testo').addEventListener('input', anteprimaColori);
    $('#i-col-fondo').addEventListener('input', anteprimaColori);
    $('#i-col-ink').addEventListener('input', anteprimaColori);
    $('#i-raggio').addEventListener('input', anteprimaColori);
    $('#btn-colori-serie').addEventListener('click', coloriDiSerie);

    $('#btn-agg-controlla').addEventListener('click', function () {
      controllaAggiornamenti(true);
    });

    // la pagina si disegna subito; la rete arriva dopo, con calma
    controllaAggiornamenti(false);
    statoFile();
  }

  function controllaAggiornamenti(forza) {
    var esito = $('#agg-esito');
    var btn   = $('#btn-agg-controlla');
    esito.textContent = 'Sto guardando…';
    btn.disabled = true;

    api('aggiornamenti_controlla' + (forza ? '&forza=1' : '')).then(function (d) {
      btn.disabled = false;
      $('#agg-novita').innerHTML = '';
      $('#agg-link-zip').innerHTML = '';
      $('#badge-agg').hidden = true;

      if (d.attivo === false) {
        esito.textContent = 'Il controllo delle nuove versioni è spento.';
        return;
      }
      if (!d.ok) {
        // non sapere non è la stessa cosa che essere a posto: si dice com'è
        esito.textContent = d.errore || 'Non sono riuscito a controllare.';
        return;
      }
      if (!d.disponibile) {
        esito.textContent = 'È l\'ultima versione pubblicata.' + (d.dalla_memoria ? ' (controllato di recente)' : '');
        return;
      }

      esito.textContent = '';
      $('#badge-agg').hidden = false;

      // questa scheda la vede solo il Superadmin: chi legge e' gia'
      // quello che deve muoversi, non c'e' nessun altro da nominare
      var testa = '<p class="guida" style="margin-bottom:10px"><strong>È uscita la versione '
                + esc(d.versione_remota) + '.</strong> Te ne occupi tu.</p>';

      if (d.php_insufficiente) {
        testa += '<p class="guida" style="color:var(--rosso)"><strong>Attenzione:</strong> questa versione '
               + 'richiede PHP ' + esc(d.php_minimo) + ', mentre qui c\'è il ' + esc(d.php) + '. '
               + 'Prima di aggiornare, chiedi all\'hosting di alzare la versione di PHP.</p>';
      }

      var voci = (d.novita || []).map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('');
      $('#agg-novita').innerHTML = testa + (voci ? '<ul class="guida-passi">' + voci + '</ul>' : '');

      if (d.zip) {
        $('#agg-link-zip').innerHTML = '<a class="bottone chiaro" style="margin-left:8px" href="'
          + esc(d.zip) + '" rel="noopener">Scarica la ' + esc(d.versione_remota) + '</a>';
      }
    }).catch(function () {
      btn.disabled = false;
      esito.textContent = 'Non sono riuscito a controllare.';
    });
  }

  function statoFile() {
    api('stato_file').then(function (d) {
      var box = $('#agg-file');
      if (!d.ok) { box.innerHTML = ''; return; }

      if (!d.noto) {
        box.innerHTML = '<p class="guida" style="margin:0">Questa versione non porta con sé la mappa '
          + 'dei file, quindi non posso dirti cosa hai modificato. Dalla prossima potrò.</p>';
        return;
      }
      if (!d.file.length) {
        box.innerHTML = '<p class="guida" style="margin:0">Nessun file di programma è stato modificato '
          + 'a mano: puoi sovrascrivere tutto senza pensieri.</p>';
        return;
      }

      box.innerHTML = '<p class="guida" style="margin:0 0 10px">Questi file li hai modificati tu. '
        + 'Sovrascrivendoli perderesti le modifiche:</p><ul class="guida-passi">'
        + d.file.map(function (f) {
            return '<li><code>' + esc(f.file) + '</code>'
                 + (f.come === 'mancante' ? ' — non c\'è più' : '')
                 + '<br><span class="meta" style="text-transform:none;letter-spacing:0">'
                 + esc(f.consiglio) + '</span></li>';
          }).join('')
        + '</ul>';
    }).catch(function () {});
  }

  // Dopo aver salvato le impostazioni la pagina si ricarica intera: senza
  // questo, il ricaricamento riparte sempre dalla Panoramica invece di
  // restare su Impostazioni.
  var sezioneDopoSalvataggio = sessionStorage.getItem('db-sezione-dopo-salvataggio');
  if (sezioneDopoSalvataggio) {
    sessionStorage.removeItem('db-sezione-dopo-salvataggio');
    var tabDopoSalvataggio = $('.tab[data-vai="' + sezioneDopoSalvataggio + '"]');
    if (tabDopoSalvataggio) {
      $$('.tab').forEach(function (b) { b.classList.toggle('att', b === tabDopoSalvataggio); });
      $$('.sezione').forEach(function (s) { s.classList.toggle('att', s.id === 'sez-' + sezioneDopoSalvataggio); });
      vaiA(tabDopoSalvataggio);
    }
  }

  if (SUPER) { agganciaAggiornamenti(); }

  carica();
  setInterval(carica, 120000);
})();
