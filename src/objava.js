// Objava cjenika: nova verzija XML/CSV-a samo kad se sadržaj promijeni,
// čišćenje arhive, izrada javne stranice i umetanje tablice u postojeće stranice.

import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { normaliziraj, otisak, provjeri, spremi } from './config.js';
import { podijeliArhivu } from './arhiva.js';
import { nazivDatoteke, uCsv, uXml } from './formati.js';
import { CSS_TABLICE, blokZaUmetanje, stranica, tablica, umetni } from './prikaz.js';
import { isoLokalno } from './util.js';

const ASSETS = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'assets');

export class GreskaProvjere extends Error {
  constructor(greske) {
    super(greske.join('\n'));
    this.greske = greske;
  }
}

/** Ima li nacrt promjena koje još nisu objavljene? */
export function imaPromjena(podaci) {
  return otisak(normaliziraj(podaci)) !== podaci.objave.at(-1)?.otisak;
}

/**
 * Najniža cijena u 30 dana prije početka akcije, iz zadržanih objava.
 * Zadržane objave uključuju i onu s granice razdoblja, pa je pokriveno cijelih 30 dana.
 */
function najnizaPrijeAkcije(id, objave) {
  const cijene = objave.flatMap((o) => o.stavke.filter((s) => s.id === id).map((s) => s.cijena));
  return cijene.length ? Math.min(...cijene) : null;
}

function upisi(datoteka, sadrzaj) {
  fs.mkdirSync(path.dirname(datoteka), { recursive: true });
  fs.writeFileSync(datoteka, sadrzaj);
}

/**
 * @param {string} dir  mapa projekta (u njoj je cjenik.json)
 * @param {object} unos podaci iz cjenik.json ili iz sučelja
 * @param {{sada?: Date, tiho?: boolean}} opcije
 */
export function objavi(dir, unos, opcije = {}) {
  const sada = opcije.sada ? new Date(opcije.sada) : new Date();
  const podaci = normaliziraj(unos);
  const greske = provjeri(podaci);
  if (greske.length) throw new GreskaProvjere(greske);

  const izlaz = path.resolve(dir, podaci.izlaz || 'cjenik');
  const mapaArhive = path.join(izlaz, 'arhiva');
  const formati = podaci.formati?.length ? podaci.formati : ['xml'];
  const prethodna = podaci.objave.at(-1);

  // Početak akcije: zapamti najnižu cijenu iz prethodnih 30 dana.
  const { zadrzi: dosadasnje } = podijeliArhivu(podaci.objave, sada, podaci.danaArhive);
  for (const s of podaci.stavke) {
    const bilaAktivna = prethodna?.stavke.find((p) => p.id === s.id)?.akcija;
    if (s.akcija.aktivna && !bilaAktivna && s.akcija.najniza30 === null) {
      s.akcija.najniza30 = najnizaPrijeAkcije(s.id, dosadasnje);
    }
    if (!s.akcija.aktivna) s.akcija.najniza30 = null;
  }

  const noviOtisak = otisak(podaci);
  let nova = null;
  if (noviOtisak !== prethodna?.otisak) {
    const broj = (podaci.brojPohrane || 0) + 1;
    const datoteke = formati.map((f) => nazivDatoteke(podaci, broj, sada, f));
    for (const d of datoteke) {
      const sadrzaj = d.endsWith('.csv') ? uCsv(podaci) : uXml(podaci, broj, sada);
      upisi(path.join(mapaArhive, d), sadrzaj);
    }
    nova = {
      broj,
      datum: isoLokalno(sada),
      otisak: noviOtisak,
      datoteke,
      stavke: podaci.stavke.map((s) => ({ id: s.id, cijena: s.cijena, akcija: s.akcija.aktivna })),
    };
    podaci.brojPohrane = broj;
    podaci.objave = [...podaci.objave, nova];
  }

  // Arhiva: obriši ono što je izvan pravila čuvanja.
  const { zadrzi, obrisi } = podijeliArhivu(podaci.objave, sada, podaci.danaArhive);
  const obrisano = [];
  for (const o of obrisi) {
    for (const d of o.datoteke) {
      const p = path.join(mapaArhive, d);
      if (fs.existsSync(p)) {
        fs.unlinkSync(p);
        obrisano.push(d);
      }
    }
  }
  podaci.objave = zadrzi;

  // Stalne poveznice na važeći cjenik, javna stranica i fragment za widget.
  const vazeca = podaci.objave.at(-1);
  if (formati.includes('xml')) upisi(path.join(izlaz, 'cjenik.xml'), uXml(podaci, vazeca.broj, vazeca.datum));
  if (formati.includes('csv')) upisi(path.join(izlaz, 'cjenik.csv'), uCsv(podaci));
  upisi(path.join(izlaz, 'index.html'), stranica(podaci, podaci.objave));
  upisi(path.join(izlaz, 'tablica.html'),
    `<style>${CSS_TABLICE}</style>\n${tablica(podaci, { datum: vazeca.datum, xmlPoveznica: 'cjenik.xml' })}\n`);
  fs.copyFileSync(path.join(ASSETS, 'cjenik-widget.js'), path.join(izlaz, 'cjenik-widget.js'));

  // Umetanje tablice u postojeće HTML stranice (između oznaka).
  const umetnuto = [];
  const upozorenja = [];
  for (const rel of podaci.umetni || []) {
    const p = path.resolve(dir, rel);
    if (!fs.existsSync(p)) {
      upozorenja.push(`Ne postoji datoteka za umetanje: ${rel}`);
      continue;
    }
    const doXml = path.relative(path.dirname(p), path.join(izlaz, 'cjenik.xml')).split(path.sep).join('/');
    const blok = blokZaUmetanje(podaci, { datum: vazeca.datum, xmlPoveznica: doXml });
    const html = umetni(fs.readFileSync(p, 'utf8'), blok);
    if (html === null) {
      upozorenja.push(`${rel}: nema oznaka <!-- cjenik:pocetak --> i <!-- cjenik:kraj -->`);
      continue;
    }
    fs.writeFileSync(p, html);
    umetnuto.push(rel);
  }

  spremi(dir, podaci);

  let naredba = null;
  if (podaci.nakonObjave) {
    try {
      naredba = { ok: true, izlaz: execSync(podaci.nakonObjave, { cwd: dir, encoding: 'utf8', stdio: 'pipe' }) };
    } catch (e) {
      naredba = { ok: false, izlaz: String(e.stderr || e.message) };
    }
  }

  return { podaci, nova, obrisano, umetnuto, upozorenja, naredba, izlaz };
}
