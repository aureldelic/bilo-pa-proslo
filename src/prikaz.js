// HTML prikaz cjenika: samostalna stranica, fragment tablice i umetanje u postojeće stranice.

import { DATUM_SIDRENJA, datumKratko, datumVrijemePrikaz, esc, novac } from './util.js';

export const OZNAKA_POCETAK = '<!-- cjenik:pocetak -->';
export const OZNAKA_KRAJ = '<!-- cjenik:kraj -->';

/** Stil tablice. Boje se nasljeđuju od stranice, a mogu se prilagoditi varijablama --cjenik-*. */
export const CSS_TABLICE = `
.cjenik{--cjenik-akcent:#b4232a;--cjenik-rub:rgba(128,128,128,.28);--cjenik-blago:rgba(128,128,128,.75);--cjenik-pozadina-zaglavlja:rgba(128,128,128,.08);font-variant-numeric:tabular-nums;color:inherit;max-width:100%}
.cjenik *{box-sizing:border-box}
.cjenik-tablica{width:100%;border-collapse:collapse;font-size:1rem;line-height:1.4}
.cjenik-tablica .cjenik-kategorija th{font-size:1.02em;font-weight:700;padding:1.3em .75em .45em;border-bottom:1px solid var(--cjenik-rub)}
.cjenik-tablica tbody:first-of-type .cjenik-kategorija th{padding-top:.9em}
.cjenik-tablica th,.cjenik-tablica td{padding:.7em .75em;border-bottom:1px solid var(--cjenik-rub);vertical-align:top;text-align:left}
.cjenik-tablica thead th{font-size:.78em;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--cjenik-blago);background:var(--cjenik-pozadina-zaglavlja);border-bottom-width:2px}
.cjenik-tablica .cjenik-iznos{text-align:right;white-space:nowrap;width:1%}
.cjenik-tablica tbody:last-child tr:last-child td{border-bottom:0}
.cjenik-tablica .cjenik-iznos .cjenik-mala{white-space:normal;min-width:8.5em}
.cjenik-naziv{font-weight:500}
.cjenik-jedinica,.cjenik-mala{display:block;font-size:.82em;color:var(--cjenik-blago);font-weight:400}
.cjenik-cijena{font-weight:700}
.cjenik-akcija .cjenik-cijena{color:var(--cjenik-akcent)}
.cjenik-oznaka{display:inline-block;margin-top:.3em;padding:.1em .5em;border-radius:999px;font-size:.72em;font-weight:600;background:var(--cjenik-akcent);color:#fff;white-space:nowrap}
.cjenik-sidrena{color:inherit}
.cjenik-podnozje{margin-top:.9em;font-size:.85em;color:var(--cjenik-blago)}
.cjenik-podnozje a{color:inherit}
@media (max-width:520px){.cjenik-tablica{font-size:.92rem}.cjenik-tablica thead .cjenik-iznos{white-space:normal;min-width:5.5em}.cjenik-tablica th,.cjenik-tablica td{padding:.6em .45em}}
`;

function grupiraj(stavke) {
  const grupe = new Map();
  for (const s of stavke) {
    const k = s.kategorija || '';
    if (!grupe.has(k)) grupe.set(k, []);
    grupe.get(k).push(s);
  }
  return [...grupe];
}

/** Najčešći datum sidrenja — ide u zaglavlje stupca; iznimke se označe u retku. */
function glavniDatum(stavke) {
  const broj = {};
  for (const s of stavke) broj[s.datumSidrenja] = (broj[s.datumSidrenja] || 0) + 1;
  return Object.entries(broj).sort((a, b) => b[1] - a[1])[0]?.[0] || DATUM_SIDRENJA;
}

function redak(s, datumZaglavlja) {
  const a = s.akcija;
  const akcija = a.aktivna;
  const cijena = `<span class="cjenik-cijena">${esc(novac(s.cijena))}</span>`
    + (akcija ? `<br><span class="cjenik-oznaka">${esc(a.naziv)}</span>` : '')
    + (akcija && a.najniza30 !== null
      ? `<span class="cjenik-mala">Najniža cijena u zadnjih 30 dana: ${esc(novac(a.najniza30))}</span>` : '');
  const sidrena = `<span class="cjenik-sidrena">${esc(novac(s.sidrenaCijena))}</span>`
    + (s.datumSidrenja !== datumZaglavlja
      ? `<span class="cjenik-mala">cijena na ${esc(datumKratko(s.datumSidrenja))}</span>` : '');
  return `<tr${akcija ? ' class="cjenik-akcija"' : ''}>`
    + `<td><span class="cjenik-naziv">${esc(s.naziv)}</span>`
    + (s.jedinica ? `<span class="cjenik-jedinica">${esc(s.jedinica)}</span>` : '') + `</td>`
    + `<td class="cjenik-iznos">${cijena}</td>`
    + `<td class="cjenik-iznos">${sidrena}</td></tr>`;
}

/**
 * Tablica cijena (bez <style>).
 * @param {object} podaci
 * @param {{xmlPoveznica?: string, datum?: string}} opcije
 */
export function tablica(podaci, opcije = {}) {
  const datumZaglavlja = glavniDatum(podaci.stavke);
  const zaglavlje = `<thead><tr><th scope="col">Usluga</th><th scope="col" class="cjenik-iznos">Cijena</th>`
    + `<th scope="col" class="cjenik-iznos">Cijena na ${esc(datumKratko(datumZaglavlja))}</th></tr></thead>`;

  const grupe = grupiraj(podaci.stavke);
  const tijela = grupe.map(([kategorija, stavke]) =>
    `<tbody>`
    + (kategorija ? `<tr class="cjenik-kategorija"><th colspan="3" scope="colgroup">${esc(kategorija)}</th></tr>` : '')
    + stavke.map((s) => redak(s, datumZaglavlja)).join('')
    + `</tbody>`,
  ).join('');

  const podnozje = [];
  if (podaci.napomena) podnozje.push(esc(podaci.napomena));
  if (opcije.datum) podnozje.push(`Cjenik vrijedi od ${esc(datumVrijemePrikaz(opcije.datum))}.`);
  if (opcije.xmlPoveznica) podnozje.push(`<a href="${esc(opcije.xmlPoveznica)}">Strojno čitljivi cjenik (XML)</a>`);

  return `<div class="cjenik">\n<table class="cjenik-tablica">${zaglavlje}${tijela}</table>\n`
    + (podnozje.length ? `<p class="cjenik-podnozje">${podnozje.join(' ')}</p>\n` : '')
    + `</div>`;
}

/** Blok koji se umeće u postojeću HTML stranicu između oznaka. */
export function blokZaUmetanje(podaci, opcije) {
  return `${OZNAKA_POCETAK}\n<style>${CSS_TABLICE}</style>\n${tablica(podaci, opcije)}\n${OZNAKA_KRAJ}`;
}

/** Zamijeni sadržaj između oznaka. Vraća null ako oznake ne postoje. */
export function umetni(html, blok) {
  const p = html.indexOf(OZNAKA_POCETAK);
  const k = html.indexOf(OZNAKA_KRAJ, p);
  if (p === -1 || k === -1) return null;
  return html.slice(0, p) + blok + html.slice(k + OZNAKA_KRAJ.length);
}

const poveznica = (datoteka) => './' + datoteka.split('/').map(encodeURIComponent).join('/');

/**
 * Samostalna javna stranica cjenika (index.html u izlaznoj mapi).
 * @param {object} podaci
 * @param {{broj:number, datum:string, datoteke:string[]}[]} arhiva  zadržane objave, najnovija zadnja
 */
export function stranica(podaci, arhiva) {
  const aktualna = arhiva.at(-1);
  const { obveznik, objekt } = podaci;

  const redoviArhive = [...arhiva].reverse().map((o, i) => {
    const linkovi = o.datoteke.map((d) =>
      `<a href="${esc(poveznica('arhiva/' + d))}" download>${esc(d.split('.').pop().toUpperCase())}</a>`).join(' · ');
    return `<li><span>${esc(datumVrijemePrikaz(o.datum))}${i === 0 ? ' <strong>(važeći)</strong>' : ''}</span>`
      + `<span class="br">br. ${o.broj}</span><span class="dl">${linkovi}</span></li>`;
  }).join('\n');

  const stalne = (podaci.formati || ['xml']).map((f) =>
    `<a href="./cjenik.${f}">cjenik.${f}</a>`).join(' · ');

  return `<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(podaci.naslovStranice)} – ${esc(obveznik.naziv)}</title>
<meta name="description" content="${esc(podaci.naslovStranice)}: ${esc(obveznik.naziv)}, ${esc(objekt.adresa)}">
<link rel="alternate" type="application/xml" href="./cjenik.xml" title="Strojno čitljivi cjenik">
<style>
:root{color-scheme:light dark;--pozadina:#faf9f7;--povrsina:#fff;--tekst:#1c1b19;--blago:#6b675f;--rub:#e6e2da}
@media (prefers-color-scheme:dark){:root{--pozadina:#141413;--povrsina:#1d1d1b;--tekst:#eeece6;--blago:#a19d94;--rub:#34322e}body .cjenik{--cjenik-akcent:#e06058}}
*{box-sizing:border-box}
body{margin:0;background:var(--pozadina);color:var(--tekst);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:860px;margin:0 auto;padding:40px 16px 64px}
header p{margin:.2em 0;color:var(--blago)}
h1{font-size:clamp(1.6rem,4vw,2.2rem);margin:0 0 .3em;letter-spacing:-.01em}
.karta{background:var(--povrsina);border:1px solid var(--rub);border-radius:14px;padding:8px 20px 20px;margin-top:28px}
.cjenik{--cjenik-rub:var(--rub);--cjenik-blago:var(--blago)}
h2{font-size:1.05rem;margin:0;padding:18px 0 6px}
.arhiva{list-style:none;margin:0;padding:0;font-size:.92rem}
.arhiva li{display:flex;flex-wrap:wrap;gap:4px 16px;padding:10px 0;border-bottom:1px solid var(--rub)}
.arhiva li:last-child{border-bottom:0}
.arhiva span:first-child{flex:1 1 220px}
.arhiva .br{color:var(--blago)}
.arhiva a,.opis a{color:inherit}
.opis{color:var(--blago);font-size:.9rem;margin:.4em 0 1em}
${CSS_TABLICE}
</style>
</head>
<body>
<main>
<header>
<h1>${esc(podaci.naslovStranice)}</h1>
<p><strong>${esc(obveznik.naziv)}</strong>${obveznik.oib ? ` · OIB ${esc(obveznik.oib)}` : ''}</p>
<p>${esc(objekt.adresa)}</p>
</header>
<section class="karta">
${tablica(podaci, { datum: aktualna?.datum })}
</section>
<section class="karta">
<h2>Strojno čitljivi cjenik</h2>
<p class="opis">Stalna poveznica na važeći cjenik: ${stalne}. Prethodni cjenici dostupni su najmanje ${podaci.danaArhive} dana od objave.</p>
<ul class="arhiva">
${redoviArhive}
</ul>
</section>
</main>
</body>
</html>
`;
}
