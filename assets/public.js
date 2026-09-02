/* Buio Verticale — magazzino, area soci */
(function () {
  'use strict';

  var stato = { inventario: [], prestiti: [], carrello: {}, giorniRitardo: 14, prestitoAperto: null };

  // Se il gruppo usa gli account personali e c'e' un socio loggato, il
  // suo nome non e' piu' un campo di testo libero: lo decide il
  // server (vedi api.php), qui si mostra solo bloccato e precompilato.
  var SOCIO = document.body.getAttribute('data-accesso-soci') === 'account' && document.body.getAttribute('data-socio-nome')
    ? { nome: document.body.getAttribute('data-socio-nome'), email: document.body.getAttribute('data-socio-email') }
    : null;

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

  function data(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d)) return esc(iso);
    return d.toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' });
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
    if (dati) { opt.method = 'POST'; opt.body = JSON.stringify(dati); }
    return fetch('api.php?azione=' + azione, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, errore: 'Risposta non leggibile dal server.' }; });
    });
  }

  // ---------------------------------------------------------- caricamento

  function carica() {
    return api('catalogo').then(function (d) {
      if (!d.ok) { toast(d.errore || 'Non riesco a leggere il magazzino.', 'male'); return; }
      stato.inventario   = d.inventario;
      stato.prestiti     = d.prestiti;
      FOTO               = d.foto || {};
      stato.giorniRitardo = d.giorni_ritardo;
      riempiCategorie();
      disegnaCatalogo();
      disegnaCarrello();
      disegnaPrestiti();
      $('#pie-aggiornato').textContent = 'Aggiornato ' + new Date().toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    });
  }

  function riempiCategorie() {
    var sel = $('#filtro-cat');
    if (sel.options.length > 1) return;
    var viste = {};
    stato.inventario.forEach(function (a) { viste[a.categoria] = 1; });
    Object.keys(viste).sort().forEach(function (c) {
      var o = document.createElement('option');
      o.value = c; o.textContent = c;
      sel.appendChild(o);
    });
  }

  // ---------------------------------------------------------- catalogo

  function disegnaCatalogo() {
    var q    = $('#cerca').value.trim().toLowerCase();
    var cat  = $('#filtro-cat').value;
    var solo = $('#solo-disp').checked;
    var corpo = $('#tab-catalogo tbody');
    var righe = [];
    var cateCorrente = null;

    stato.inventario.forEach(function (a) {
      if (a.prestabile === false) return;
      if (cat && a.categoria !== cat) return;
      if (solo && a.disponibile <= 0) return;
      var testo = (a.categoria + ' ' + a.nome).toLowerCase();
      if (q && testo.indexOf(q) === -1) return;

      if (a.categoria !== cateCorrente) {
        cateCorrente = a.categoria;
        righe.push('<tr><td colspan="4" style="background:#F5F7F6;padding:6px 12px"><span class="tag">' + esc(a.categoria) + '</span></td></tr>');
      }

      var nel = stato.carrello[a.id] || 0;
      righe.push(
        '<tr data-id="' + esc(a.id) + '">' +
          '<td class="nome-art">' + conMini(a.nome, a.foto, esc(a.nome)) + '</td>' +
          '<td class="num"><strong>' + a.disponibile + '</strong> <span style="color:var(--grigio)">/ ' + a.quantita + '</span></td>' +
          '<td class="num">' + (a.in_prestito ? '<span class="tag fuori">' + a.in_prestito + '</span>' : '<span style="color:var(--linea)">—</span>') + '</td>' +
          '<td style="text-align:right">' + (
            nel
              ? '<span class="stepper"><button type="button" data-meno="' + esc(a.id) + '">&minus;</button>' +
                '<input type="text" readonly value="' + nel + '">' +
                '<button type="button" data-piu="' + esc(a.id) + '"' + (nel >= a.disponibile ? ' disabled' : '') + '>+</button></span>'
              : '<button class="bottone chiaro mini" type="button" data-piu="' + esc(a.id) + '">Aggiungi</button>'
          ) + '</td>' +
        '</tr>'
      );
    });

    corpo.innerHTML = righe.length
      ? righe.join('')
      : '<tr><td colspan="4" class="vuoto" style="padding:22px 12px">Nessun articolo con questi filtri.</td></tr>';
  }

  function cambia(id, delta) {
    var art = stato.inventario.filter(function (a) { return a.id === id; })[0];
    if (!art) return;
    var q = (stato.carrello[id] || 0) + delta;
    if (q > art.disponibile) { toast('Di ' + art.nome + ' ci sono ' + art.disponibile + ' pezzi disponibili.', 'male'); return; }
    if (q <= 0) { delete stato.carrello[id]; } else { stato.carrello[id] = q; }
    disegnaCatalogo();
    disegnaCarrello();
  }

  function disegnaCarrello() {
    var ul = $('#carrello-lista');
    var ids = Object.keys(stato.carrello);
    $('#carrello-vuoto').style.display = ids.length ? 'none' : 'block';
    ul.innerHTML = ids.map(function (id) {
      var a = stato.inventario.filter(function (x) { return x.id === id; })[0] || { nome: id };
      return '<li><span class="q">' + stato.carrello[id] + '&times;</span>' +
             conMini(a.nome, a.foto, esc(a.nome), true) +
             '<button class="x" type="button" data-togli="' + esc(id) + '" aria-label="Togli">&times;</button></li>';
    }).join('');
  }

  function conferma() {
    var righe = Object.keys(stato.carrello).map(function (id) {
      return { id_articolo: id, qta: stato.carrello[id] };
    });
    if (!righe.length) { toast('Aggiungi almeno un articolo.', 'male'); return; }
    if (!SOCIO && $('#p-persona').value.trim().length < 3) { toast('Scrivi nome e cognome.', 'male'); $('#p-persona').focus(); return; }

    var btn = $('#btn-preleva');
    btn.disabled = true;
    api('prelievo', {
      persona:        $('#p-persona').value,
      contatto:       $('#p-contatto').value,
      destinazione:   $('#p-destinazione').value,
      rientro_atteso: $('#p-rientro').value,
      note:           $('#p-note').value,
      righe:          righe
    }).then(function (d) {
      btn.disabled = false;
      if (!d.ok) { toast(d.errore, 'male'); return; }
      stato.carrello = {};
      ['#p-contatto', '#p-destinazione', '#p-note'].forEach(function (s) { $(s).value = ''; });
      toast('Prelievo registrato. Buona grotta.', 'ok');
      carica();
    }).catch(function () { btn.disabled = false; toast('Server non raggiungibile.', 'male'); });
  }

  // ---------------------------------------------------------- prestiti

  function schedaPrestito(p, conBottone) {
    var ritardo = p.giorni >= stato.giorniRitardo;
    var pezzi = p.righe.reduce(function (s, r) { return s + r.residuo; }, 0);
    return '<article class="scheda-prestito' + (ritardo ? ' ritardo' : '') + '">' +
      '<h4>' + esc(p.persona) + '</h4>' +
      '<div class="meta">Uscito il ' + data(p.uscita) + ' &middot; ' + p.giorni + ' giorni fa &middot; ' + pezzi + ' pezzi fuori' +
        (p.destinazione ? ' &middot; ' + esc(p.destinazione) : '') + '</div>' +
      (ritardo ? '<div style="margin-top:8px"><span class="tag male">fuori da oltre ' + stato.giorniRitardo + ' giorni</span></div>' : '') +
      '<ul class="elenco-righe">' + p.righe.map(function (r) {
        return '<li><span class="q">' + r.residuo + '&times;</span>' +
               conMini(r.nome, FOTO[r.id_articolo], esc(r.nome), true) + '</li>';
      }).join('') + '</ul>' +
      (conBottone ? '<div style="margin-top:14px"><button class="bottone chiaro mini" type="button" data-rientro="' + esc(p.id) + '">Riporto questo</button></div>' : '') +
      '</article>';
  }

  function disegnaPrestiti() {
    var q = ($('#cerca-prestito').value || '').trim().toLowerCase();
    var filtrati = stato.prestiti.filter(function (p) {
      if (!q) return true;
      return (p.persona + ' ' + p.destinazione).toLowerCase().indexOf(q) !== -1;
    });

    $('#lista-prestiti-rientro').innerHTML = filtrati.length
      ? filtrati.map(function (p) { return schedaPrestito(p, true); }).join('')
      : '<p class="vuoto">Nessun prelievo aperto con questo nome.</p>';

    $('#lista-fuori').innerHTML = stato.prestiti.length
      ? stato.prestiti.map(function (p) { return schedaPrestito(p, false); }).join('')
      : '<p class="vuoto">Tutta l\'attrezzatura e\' a magazzino.</p>';
  }

  // ---------------------------------------------------------- rientro

  function apriRientro(id) {
    var p = stato.prestiti.filter(function (x) { return x.id === id; })[0];
    if (!p) return;
    stato.prestitoAperto = p;

    $('#corpo-rientro').innerHTML =
      '<div class="avviso luce"><strong>' + esc(p.persona) + '</strong> — uscito il ' + data(p.uscita) +
      (p.destinazione ? ' per ' + esc(p.destinazione) : '') + '.<br>Segna quanti pezzi rientrano e quanti mancano.</div>' +
      '<label class="campo"><span>Chi sta riconsegnando</span><input type="text" id="r-chi" value="' +
        esc(SOCIO ? SOCIO.nome : p.persona) + '"' + (SOCIO ? ' readonly' : '') + '></label>' +
      '<div class="tabella-scroll"><table><thead><tr><th>Articolo</th><th class="num">Fuori</th><th class="num" style="width:86px">Rientrati</th><th class="num" style="width:86px">Persi o rotti</th></tr></thead><tbody>' +
      p.righe.map(function (r) {
        return '<tr data-riga="' + esc(r.id_articolo) + '">' +
          '<td class="nome-art">' + conMini(r.nome, FOTO[r.id_articolo], esc(r.nome)) + '</td>' +
          '<td class="num">' + r.residuo + '</td>' +
          '<td class="num"><input type="number" min="0" max="' + r.residuo + '" value="' + r.residuo + '" data-campo="rientrate"></td>' +
          '<td class="num"><input type="number" min="0" max="' + r.residuo + '" value="0" data-campo="perse"></td>' +
        '</tr>';
      }).join('') + '</tbody></table></div>' +
      '<label class="campo" style="margin-top:16px"><span>Note sul rientro</span><textarea id="r-nota" placeholder="Corda da lavare, croll da controllare, pezzi lasciati in grotta…"></textarea></label>';

    $('#velo-rientro').hidden = false;
  }

  function chiudiRientro() {
    $('#velo-rientro').hidden = true;
    stato.prestitoAperto = null;
  }

  function confermaRientro() {
    var p = stato.prestitoAperto;
    if (!p) return;
    var righe = $$('#corpo-rientro tbody tr').map(function (tr) {
      return {
        id_articolo: tr.getAttribute('data-riga'),
        rientrate:   parseInt($('[data-campo=rientrate]', tr).value, 10) || 0,
        perse:       parseInt($('[data-campo=perse]', tr).value, 10) || 0
      };
    });
    var somma = righe.reduce(function (s, r) { return s + r.rientrate + r.perse; }, 0);
    if (!somma) { toast('Indica almeno un pezzo rientrato o mancante.', 'male'); return; }

    var btn = $('#btn-conferma-rientro');
    btn.disabled = true;
    api('riconsegna', {
      id_prestito:    p.id,
      chi_riconsegna: $('#r-chi').value,
      nota:           $('#r-nota').value,
      righe:          righe
    }).then(function (d) {
      btn.disabled = false;
      if (!d.ok) { toast(d.errore, 'male'); return; }
      chiudiRientro();
      toast(d.residui > 0
        ? 'Rientro registrato. Restano ' + d.residui + ' pezzi fuori.'
        : 'Rientro completo. Prelievo chiuso.', 'ok');
      carica();
    }).catch(function () { btn.disabled = false; toast('Server non raggiungibile.', 'male'); });
  }

  // ---------------------------------------------------------- eventi

  document.addEventListener('click', function (e) {
    var f = e.target.closest('img.mini[data-foto]');
    if (f) { apriLente(f.getAttribute('src'), f.getAttribute('data-titolo')); return; }

    var t = e.target.closest('[data-piu],[data-meno],[data-togli],[data-rientro],[data-vai],[data-chiudi]');
    if (!t) return;
    if (t.hasAttribute('data-piu'))     cambia(t.getAttribute('data-piu'), 1);
    if (t.hasAttribute('data-meno'))    cambia(t.getAttribute('data-meno'), -1);
    if (t.hasAttribute('data-togli'))   { delete stato.carrello[t.getAttribute('data-togli')]; disegnaCatalogo(); disegnaCarrello(); }
    if (t.hasAttribute('data-rientro')) apriRientro(t.getAttribute('data-rientro'));
    if (t.hasAttribute('data-chiudi'))  chiudiRientro();
    if (t.hasAttribute('data-vai')) {
      var vai = t.getAttribute('data-vai');
      $$('.tab').forEach(function (b) { b.classList.toggle('att', b === t); });
      $$('.sezione').forEach(function (s) { s.classList.toggle('att', s.id === 'sez-' + vai); });
    }
  });

  $('#velo-rientro').addEventListener('click', function (e) { if (e.target === this) chiudiRientro(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') chiudiRientro(); });

  // Con l'account personale il nome di chi preleva non si scrive piu':
  // e' quello dell'account, e il server lo impone comunque.
  if (SOCIO) {
    var campoPersona = $('#p-persona');
    campoPersona.value = SOCIO.nome;
    campoPersona.readOnly = true;
    if (SOCIO.email && !$('#p-contatto').value) {
      $('#p-contatto').value = SOCIO.email;
    }
  }

  $('#cerca').addEventListener('input', disegnaCatalogo);
  $('#filtro-cat').addEventListener('change', disegnaCatalogo);
  $('#solo-disp').addEventListener('change', disegnaCatalogo);
  $('#cerca-prestito').addEventListener('input', disegnaPrestiti);
  $('#btn-preleva').addEventListener('click', conferma);
  $('#btn-conferma-rientro').addEventListener('click', confermaRientro);

  carica();
})();
