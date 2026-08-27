/* Buio Verticale — bottone per mostrare/nascondere il contenuto dei campi password.
   Si applica da solo a ogni <input type="password"> presente nella pagina,
   comprese quelle aggiunte in seguito (es. le finestre della dashboard). */
(function () {
  'use strict';

  function icona(visibile) {
    return visibile
      ? '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/><line x1="3" y1="21" x2="21" y2="3"/></svg>'
      : '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
  }

  function avvolgi(campo) {
    if (campo.dataset.mostraPass) { return; }
    campo.dataset.mostraPass = '1';

    var contenitore = document.createElement('div');
    contenitore.className = 'campo-pass';
    campo.parentNode.insertBefore(contenitore, campo);
    contenitore.appendChild(campo);

    var bottone = document.createElement('button');
    bottone.type = 'button';
    bottone.className = 'campo-pass-bottone';
    bottone.tabIndex = -1;
    bottone.setAttribute('aria-label', 'Mostra la password');
    bottone.innerHTML = icona(false);
    contenitore.appendChild(bottone);

    bottone.addEventListener('click', function () {
      var visibile = campo.type === 'text';
      campo.type = visibile ? 'password' : 'text';
      bottone.innerHTML = icona(!visibile);
      bottone.setAttribute('aria-label', visibile ? 'Mostra la password' : 'Nascondi la password');
    });
  }

  function cerca(radice) {
    if (radice.querySelectorAll) {
      radice.querySelectorAll('input[type=password]').forEach(avvolgi);
    }
  }

  cerca(document);

  new MutationObserver(function (mutazioni) {
    mutazioni.forEach(function (m) {
      m.addedNodes.forEach(function (nodo) {
        if (nodo.nodeType !== 1) { return; }
        if (nodo.matches && nodo.matches('input[type=password]')) { avvolgi(nodo); }
        cerca(nodo);
      });
    });
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
