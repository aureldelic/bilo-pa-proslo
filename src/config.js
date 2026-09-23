// Učitavanje, spremanje i provjera projektne datoteke cjenik.json.

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { DATUM_SIDRENJA, parsirajIznos } from './util.js';

export const DATOTEKA = 'cjenik.json';

export function zadano() {
  return {
    obveznik: { naziv: '', oib: '' },
    objekt: { oblik: 'servis', adresa: '', oznaka: '01' },
    izlaz: 'cjenik',
    formati: ['xml'],
    danaArhive: 30,
    separatorVremena: process.platform === 'win32' ? '-' : ':',
    umetni: [],
    naslovStranice: 'Cjenik usluga',
    napomena: 'Cijene su iskazane u eurima (EUR).',
    nakonObjave: '',
    stavke: [],
    brojPohrane: 0,
    objave: [],
  };
}

export const noviId = () => crypto.randomBytes(5).toString('hex');

export function novaStavka(v = {}) {
  return {
    id: v.id || noviId(),
    kategorija: v.kategorija ?? '',
    naziv: v.naziv ?? '',
    jedinica: v.jedinica ?? '',
    cijena: v.cijena ?? null,
    sidrenaCijena: v.sidrenaCijena ?? null,
    datumSidrenja: v.datumSidrenja || DATUM_SIDRENJA,
    akcija: {
      aktivna: !!v.akcija?.aktivna,
      naziv: v.akcija?.naziv ?? '',
      najniza30: v.akcija?.najniza30 ?? null,
    },
  };
}

export function putanja(dir) {
  return path.join(dir, DATOTEKA);
}

export function ucitaj(dir) {
  const p = putanja(dir);
  if (!fs.existsSync(p)) {
    throw new Error(`Nema datoteke ${DATOTEKA} u ${dir}. Pokreni "cjenik init".`);
  }
  const podaci = { ...zadano(), ...JSON.parse(fs.readFileSync(p, 'utf8')) };
  podaci.stavke = podaci.stavke.map(novaStavka);
  return podaci;
}

export function spremi(dir, podaci) {
  const p = putanja(dir);
  const tmp = p + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(podaci, null, 2) + '\n');
  fs.renameSync(tmp, p);
}

/** Normaliziraj unos iz sučelja (stringovi -> brojevi, obrezivanje). */
export function normaliziraj(podaci) {
  const n = (v) => (v === null || v === '' || v === undefined ? null : parsirajIznos(v));
  return {
    ...podaci,
    obveznik: {
      naziv: String(podaci.obveznik?.naziv ?? '').trim(),
      oib: String(podaci.obveznik?.oib ?? '').trim(),
    },
    objekt: {
      oblik: String(podaci.objekt?.oblik ?? '').trim(),
      adresa: String(podaci.objekt?.adresa ?? '').trim(),
      oznaka: String(podaci.objekt?.oznaka ?? '').trim(),
    },
    danaArhive: Math.max(30, parseInt(podaci.danaArhive, 10) || 30),
    stavke: (podaci.stavke ?? []).map((s) => {
      const st = novaStavka(s);
      st.kategorija = String(st.kategorija).trim();
      st.naziv = String(st.naziv).trim();
      st.jedinica = String(st.jedinica).trim();
      st.cijena = n(st.cijena);
      st.sidrenaCijena = n(st.sidrenaCijena);
      st.akcija.naziv = String(st.akcija.naziv).trim();
      st.akcija.najniza30 = n(st.akcija.najniza30);
      return st;
    }),
  };
}

/** Vrati popis grešaka koje sprječavaju objavu (prazan popis = sve u redu). */
export function provjeri(podaci) {
  const g = [];
  const { objekt, obveznik, stavke } = podaci;
  if (!obveznik.naziv) g.push('Upiši naziv obrta/tvrtke.');
  if (obveznik.oib && !/^\d{11}$/.test(obveznik.oib)) g.push('OIB mora imati 11 znamenki.');
  if (!objekt.oblik) g.push('Upiši oblik/vrstu objekta (npr. servis, salon, ured).');
  if (!objekt.adresa) g.push('Upiši adresu objekta.');
  if (!objekt.oznaka) g.push('Upiši oznaku objekta (npr. 01 ili U-01).');
  if (!stavke.length) g.push('Dodaj barem jednu uslugu.');
  stavke.forEach((s, i) => {
    const r = `Red ${i + 1}${s.naziv ? ` (${s.naziv})` : ''}`;
    if (!s.naziv) g.push(`${r}: nedostaje opis usluge.`);
    if (s.cijena === null || Number.isNaN(s.cijena)) g.push(`${r}: neispravna trenutna cijena.`);
    if (s.sidrenaCijena === null || Number.isNaN(s.sidrenaCijena)) g.push(`${r}: neispravna cijena na datum sidrenja.`);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s.datumSidrenja)) g.push(`${r}: neispravan datum sidrenja.`);
    if (s.akcija.aktivna && !s.akcija.naziv) g.push(`${r}: upiši naziv posebnog oblika prodaje (npr. "Jesenska akcija").`);
    if (s.akcija.najniza30 !== null && Number.isNaN(s.akcija.najniza30)) g.push(`${r}: neispravna najniža cijena u 30 dana.`);
  });
  return g;
}

/** Otisak sadržaja koji se objavljuje — ako se promijeni, nastaje nova datoteka. */
export function otisak(podaci) {
  const sadrzaj = {
    obveznik: podaci.obveznik,
    objekt: podaci.objekt,
    stavke: podaci.stavke.map((s) => [
      s.kategorija, s.naziv, s.jedinica, s.cijena, s.sidrenaCijena, s.datumSidrenja,
      s.akcija.aktivna, s.akcija.aktivna ? s.akcija.naziv : '', s.akcija.aktivna ? s.akcija.najniza30 : null,
    ]),
  };
  return crypto.createHash('sha256').update(JSON.stringify(sadrzaj)).digest('hex').slice(0, 16);
}
