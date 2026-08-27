/* Buio Verticale — magazzino, gestione */
(function () {
  'use strict';

  var CSRF = document.body.getAttribute('data-csrf');
  var D = { inventario: [], aperti: [], storico: [], movimenti: [], utenti: [], kpi: {}, giorni: 14 };

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

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
      D.utenti     = d.utenti;
      D.kpi        = d.kpi;
      D.giorni     = d.giorni_ritardo;
      FOTO         = d.foto || {};
      D.impostazioni = d.impostazioni || {};
      riempiCategorie();
      disegnaProfilo();
      disegnaKpi();
      disegnaRitardi();
      disegnaComprare();
      disegnaInventario();
      disegnaFuori();
      disegnaStorico();
      disegnaMovimenti();
      disegnaUtenti();
      disegnaImpostazioni();
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

    if (ord === 'scorta') lista.sort(function (x, y) { return x.disponibile - y.disponibile; });
    if (ord === 'fuori')  lista.sort(function (x, y) { return y.in_prestito - x.in_prestito; });

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
            (a.foto ? '<button class="bottone chiaro mini" type="button" id="s-foto-togli" style="margin-top:10px">Togli la foto</button>' : '') +
          '</div>' +
        '</div>'
      : '<div class="avviso">La foto si aggiunge dalla scheda, dopo aver creato l\'articolo.</div>';

    apriPannello(a ? 'Scheda articolo' : 'Nuovo articolo',
      bloccoFoto +
      '<label class="campo"><span>Categoria</span><input type="text" id="s-cat" list="lista-cat" value="' + esc(a ? a.categoria : '') + '">' +
      '<datalist id="lista-cat">' + Object.keys(cats).map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') + '</datalist></label>' +
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
          categoria: $('#s-cat').value,
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
        '<td style="text-align:right"><button class="bottone chiaro mini" data-espandi="' + esc(p.id) + '">Dettagli</button></td>' +
      '</tr>' + dettaglio;
    }).join('') : '<tr><td colspan="8" class="vuoto" style="padding:22px 12px">Nessun prelievo con questi filtri.</td></tr>';
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
      return '<tr><td class="nome-art">' + esc(u.nome) + '</td><td class="meta">' + esc(u.user) + '</td>' +
        '<td style="text-align:right"><button class="bottone chiaro mini" data-elimina-utente="' + esc(u.id) + '">Revoca</button></td></tr>';
    }).join('');
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

    anteprimaTestata();
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
    var btn = $('#btn-salva-impostazioni');
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
        setTimeout(function () { location.reload(); }, 900);
      })
      .catch(function () { btn.disabled = false; toast('Server non raggiungibile.', 'male'); });
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

    var t = e.target.closest('[data-vai],[data-chiudi],[data-carico],[data-scarico],[data-modifica],[data-chiudi-prestito],[data-espandi],[data-elimina-utente],[data-articolo]');
    if (!t) return;

    if (t.hasAttribute('data-vai')) {
      var vai = t.getAttribute('data-vai');
      $$('.tab').forEach(function (b) { b.classList.toggle('att', b === t); });
      $$('.sezione').forEach(function (s) { s.classList.toggle('att', s.id === 'sez-' + vai); });
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
    if (t.hasAttribute('data-elimina-utente')) {
      if (!confirm('Revochi l\'accesso a questo amministratore?')) return;
      scrivi('utente_elimina', { id: t.getAttribute('data-elimina-utente') }, 'Accesso revocato.');
      return;
    }
    if (t.hasAttribute('data-articolo')) {
      $$('.tab').forEach(function (b) { b.classList.toggle('att', b.getAttribute('data-vai') === 'inventario'); });
      $$('.sezione').forEach(function (s) { s.classList.toggle('att', s.id === 'sez-inventario'); });
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
  $('#btn-salva-impostazioni').addEventListener('click', salvaImpostazioni);
  ['#i-nome', '#i-sotto'].forEach(function (s) { $(s).addEventListener('input', anteprimaTestata); });

  $('#btn-nuovo-utente').addEventListener('click', function () {
    scrivi('utente_nuovo', {
      nome: $('#u-nome').value, user: $('#u-user').value, password: $('#u-pass').value
    }, 'Amministratore aggiunto.').then(function (ok) {
      if (ok) { $('#u-nome').value = ''; $('#u-user').value = ''; $('#u-pass').value = ''; }
    });
  });

  ['#inv-cerca', '#inv-cat', '#inv-ordine'].forEach(function (s) { $(s).addEventListener('input', disegnaInventario); });
  ['#fuori-cerca', '#fuori-solo-ritardo'].forEach(function (s) { $(s).addEventListener('input', disegnaFuori); });
  ['#st-cerca', '#st-stato', '#st-dal', '#st-al'].forEach(function (s) { $(s).addEventListener('input', disegnaStorico); });
  $('#mv-cerca').addEventListener('input', disegnaMovimenti);

  carica();
  setInterval(carica, 120000);
})();
