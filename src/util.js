// Zajedničke pomoćne funkcije: datumi (Europe/Zagreb), novac, escaping.

export const ZONA = 'Europe/Zagreb';
export const DATUM_SIDRENJA = '2026-09-10';
const DAN_MS = 86400000;

const dijeloviFmt = new Intl.DateTimeFormat('en-GB', {
  timeZone: ZONA,
  year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', second: '2-digit',
  hourCycle: 'h23',
});

/** Rastavi trenutak na lokalne (zagrebačke) dijelove. */
export function dijelovi(datum) {
  const d = {};
  for (const p of dijeloviFmt.formatToParts(new Date(datum))) d[p.type] = p.value;
  return d;
}

/** ISO 8601 s lokalnim pomakom, npr. 2026-10-01T07:45:00+02:00 */
export function isoLokalno(datum) {
  const t = new Date(datum);
  const d = dijelovi(t);
  const lokalnoKaoUtc = Date.UTC(+d.year, +d.month - 1, +d.day, +d.hour, +d.minute, +d.second);
  const pomakMin = Math.round((lokalnoKaoUtc - Math.floor(t.getTime() / 1000) * 1000) / 60000);
  const znak = pomakMin >= 0 ? '+' : '-';
  const a = Math.abs(pomakMin);
  const pom = `${znak}${String(Math.floor(a / 60)).padStart(2, '0')}:${String(a % 60).padStart(2, '0')}`;
  return `${d.year}-${d.month}-${d.day}T${d.hour}:${d.minute}:${d.second}${pom}`;
}

/** Lokalni kalendarski dan kao 'YYYY-MM-DD'. */
export function danLokalno(datum) {
  const d = dijelovi(datum);
  return `${d.year}-${d.month}-${d.day}`;
}

/** Razlika u kalendarskim danima između dva 'YYYY-MM-DD'. */
export function razlikaDana(odDana, doDana) {
  return Math.round((Date.parse(doDana + 'T00:00:00Z') - Date.parse(odDana + 'T00:00:00Z')) / DAN_MS);
}

/** 'YYYY-MM-DD' -> '10.9.2026.' (preporuka ministarstva) */
export function datumKratko(ymd) {
  if (!ymd) return '';
  const [y, m, d] = ymd.split('-');
  return `${+d}.${+m}.${y}.`;
}

/** Datum i vrijeme za prikaz: '01.10.2026. u 07:45' */
export function datumVrijemePrikaz(datum) {
  const d = dijelovi(datum);
  return `${d.day}.${d.month}.${d.year}. u ${d.hour}:${d.minute}`;
}

const novacFmt = new Intl.NumberFormat('hr-HR', { style: 'currency', currency: 'EUR' });
export const novac = (n) => novacFmt.format(n);

/** Iznos za strojno čitanje: točka kao decimalni separator, 2 decimale. */
export const iznos = (n) => Number(n).toFixed(2);

/** Pretvori unos ('45', '45,5', '1.234,50') u broj ili NaN. */
export function parsirajIznos(v) {
  if (typeof v === 'number') return v;
  let s = String(v ?? '').trim().replace(/\s|€|eur/gi, '');
  if (!s) return NaN;
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  return /^\d+(\.\d+)?$/.test(s) ? Math.round(parseFloat(s) * 100) / 100 : NaN;
}

const HTML_ZAMJENE = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => HTML_ZAMJENE[c]);
