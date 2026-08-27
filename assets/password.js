/* Buio Verticale — controllo della password mentre si digita.
   Usato dalla dashboard (tab Accessi) e dall'installazione (passo 3).

   Le regole sono le stesse di password_regole() in inc/auth.php:
   se cambi qui, cambia anche la'. */
(function () {
  'use strict';

  var REGOLE = [
    { etichetta: 'almeno 8 caratteri',    prova: /.{8,}/ },
    { etichetta: 'una lettera minuscola', prova: /[a-z]/ },
    { etichetta: 'una lettera maiuscola', prova: /[A-Z]/ },
    { etichetta: 'un numero',             prova: /[0-9]/ }
  ];

  function voce(ok, testo, spenta) {
    return '<li class="' + (spenta ? 'attesa' : (ok ? 'si' : 'no')) + '">' +
           '<span aria-hidden="true">' + (spenta ? '·' : (ok ? '✓' : '×')) + '</span>' + testo + '</li>';
  }

  /**
   * Collega il controllo a una coppia di campi password.
   * opzioni: { pass, conferma, esito, bottone }  (elementi del DOM)
   * Restituisce { valida: function () {...}, aggiorna: function () {...} }.
   */
  function collega(opzioni) {
    var pass     = opzioni.pass;
    var conferma = opzioni.conferma;
    var esito    = opzioni.esito;
    var bottone  = opzioni.bottone || null;

    if (!pass || !conferma || !esito) { return { valida: function () { return true; }, aggiorna: function () {} }; }

    function stato() {
      var p = pass.value;
      var c = conferma.value;
      var mancanti = REGOLE.filter(function (r) { return !r.prova.test(p); }).length;
      return {
        vuota:     p === '',
        regoleOk:  p !== '' && mancanti === 0,
        coincide:  p !== '' && p === c,
        confermata: c !== ''
      };
    }

    function aggiorna() {
      var p = pass.value;
      var c = conferma.value;
      var s = stato();

      esito.innerHTML =
        REGOLE.map(function (r) { return voce(r.prova.test(p), r.etichetta, s.vuota); }).join('') +
        voce(s.coincide, s.coincide ? 'le due password coincidono' : 'le due password devono coincidere',
             s.vuota || !s.confermata);

      if (bottone) { bottone.disabled = !(s.regoleOk && s.coincide); }
    }

    function valida() {
      var s = stato();
      return s.regoleOk && s.coincide;
    }

    [pass, conferma].forEach(function (campo) {
      campo.addEventListener('input', aggiorna);
      campo.addEventListener('blur', aggiorna);
    });

    esito.setAttribute('aria-live', 'polite');
    aggiorna();

    return { valida: valida, aggiorna: aggiorna };
  }

  window.ControlloPassword = { collega: collega, regole: REGOLE };
})();
