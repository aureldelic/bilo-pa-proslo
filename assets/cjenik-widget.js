/*
 * Cjenik widget za statične stranice.
 * Ubaci na stranicu:
 *   <div data-cjenik></div>
 *   <script src="/cjenik/cjenik-widget.js" defer></script>
 * Tablica se učitava iz tablica.html koja je u istoj mapi kao ova skripta.
 */
(function () {
  var skripta = document.currentScript;
  var baza = skripta ? skripta.src.replace(/[^/]*$/, '') : '/cjenik/';

  function ucitaj() {
    var mjesta = document.querySelectorAll('[data-cjenik]');
    if (!mjesta.length) return;
    fetch(baza + 'tablica.html', { cache: 'no-cache' })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status);
        return r.text();
      })
      .then(function (html) {
        // Poveznice u fragmentu su relativne na mapu cjenika.
        html = html.replace(/href="(?!https?:|\/|#)([^"]+)"/g, 'href="' + baza + '$1"');
        mjesta.forEach(function (el) { el.innerHTML = html; });
      })
      .catch(function () {
        mjesta.forEach(function (el) {
          el.innerHTML = '<p><a href="' + baza + '">Pogledajte cjenik</a></p>';
        });
      });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ucitaj);
  else ucitaj();
})();
