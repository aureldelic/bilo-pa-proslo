// Strojno čitljivi cjenik: naziv datoteke, XML i CSV.
//
// Struktura XML-a nije propisana, pa su nazivi elemenata hrvatski i opisni.
// Iznosi su s točkom kao decimalnim separatorom, datumi u ISO 8601.

import { dijelovi, isoLokalno, iznos } from './util.js';

const cistiDio = (s) => String(s).replace(/[\\/_:*?"<>|]+/g, '-').replace(/\s+/g, ' ').trim();

/**
 * Naziv prema pojašnjenju ministarstva:
 * oblik_adresa_oznaka_broj pohrane_DD.MM.GGGG_HH:MM
 * npr. servis_Vukovarska 20 Osijek_U-03_015_01.10.2026_07:45
 */
export function nazivDatoteke(podaci, broj, datum, nastavak) {
  const d = dijelovi(datum);
  const sep = podaci.separatorVremena || ':';
  return [
    cistiDio(podaci.objekt.oblik),
    cistiDio(podaci.objekt.adresa),
    cistiDio(podaci.objekt.oznaka),
    String(broj).padStart(3, '0'),
    `${d.day}.${d.month}.${d.year}`,
    `${d.hour}${sep}${d.minute}`,
  ].join('_') + '.' + nastavak;
}

const xmlEsc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;',
}[c]));

export function uXml(podaci, broj, datum) {
  const { obveznik, objekt } = podaci;
  const e = (tag, v, ind = '      ') => (v === '' || v === null || v === undefined ? '' : `${ind}<${tag}>${xmlEsc(v)}</${tag}>\n`);

  const stavke = podaci.stavke.map((s, i) => {
    const a = s.akcija;
    const poseban = a.aktivna
      ? `      <posebanOblikProdaje primijenjen="da">${xmlEsc(a.naziv)}</posebanOblikProdaje>\n`
      : `      <posebanOblikProdaje primijenjen="ne"/>\n`;
    const najniza = a.aktivna && a.najniza30 !== null
      ? `      <najnizaCijena30Dana valuta="EUR">${iznos(a.najniza30)}</najnizaCijena30Dana>\n` : '';
    return `    <usluga rb="${i + 1}" sifra="${xmlEsc(s.id)}">\n`
      + e('naziv', s.naziv)
      + e('kategorija', s.kategorija)
      + e('jedinicaMjere', s.jedinica)
      + `      <maloprodajnaCijena valuta="EUR">${iznos(s.cijena)}</maloprodajnaCijena>\n`
      + poseban
      + najniza
      + `      <dodatnaCijena valuta="EUR" datum="${s.datumSidrenja}">${iznos(s.sidrenaCijena)}</dodatnaCijena>\n`
      + `    </usluga>\n`;
  }).join('');

  return `<?xml version="1.0" encoding="UTF-8"?>\n`
    + `<cjenik vrsta="usluge" verzija="1.0">\n`
    + `  <zaglavlje>\n`
    + `    <obveznik>\n`
    + e('naziv', obveznik.naziv)
    + e('oib', obveznik.oib)
    + `    </obveznik>\n`
    + `    <objekt>\n`
    + e('oblik', objekt.oblik)
    + e('adresa', objekt.adresa)
    + e('oznaka', objekt.oznaka)
    + `    </objekt>\n`
    + `    <brojPohrane>${broj}</brojPohrane>\n`
    + `    <datumObjave>${isoLokalno(datum)}</datumObjave>\n`
    + `    <valuta>EUR</valuta>\n`
    + `  </zaglavlje>\n`
    + `  <usluge>\n${stavke}  </usluge>\n`
    + `</cjenik>\n`;
}

const STUPCI_CSV = [
  'naziv_usluge', 'kategorija', 'jedinica_mjere', 'maloprodajna_cijena',
  'poseban_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'najniza_cijena_30_dana',
  'dodatna_cijena', 'datum_dodatne_cijene', 'sifra',
];

export function uCsv(podaci) {
  const polje = (v) => {
    const s = String(v ?? '');
    return /[;"\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const redovi = podaci.stavke.map((s) => [
    s.naziv, s.kategorija, s.jedinica, iznos(s.cijena),
    s.akcija.aktivna ? 'DA' : 'NE', s.akcija.aktivna ? s.akcija.naziv : '',
    s.akcija.aktivna && s.akcija.najniza30 !== null ? iznos(s.akcija.najniza30) : '',
    iznos(s.sidrenaCijena), s.datumSidrenja, s.id,
  ].map(polje).join(';'));
  // BOM da Excel ispravno prepozna UTF-8 (č, ć, ž, š, đ).
  return '﻿' + [STUPCI_CSV.join(';'), ...redovi].join('\r\n') + '\r\n';
}
