import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { podijeliArhivu } from '../src/arhiva.js';
import { nazivDatoteke, uXml } from '../src/formati.js';
import { normaliziraj, novaStavka, ucitaj, spremi, zadano } from '../src/config.js';
import { objavi, GreskaProvjere } from '../src/objava.js';
import { umetni, OZNAKA_POCETAK, OZNAKA_KRAJ } from '../src/prikaz.js';
import { isoLokalno, parsirajIznos } from '../src/util.js';

const DAN = 86400000;
const pocetak = new Date('2026-10-01T05:45:00Z'); // 07:45 po zagrebačkom vremenu

function projekt() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cjenik-'));
  const p = zadano();
  p.separatorVremena = ':';
  p.obveznik = { naziv: 'Servis Test', oib: '' };
  p.objekt = { oblik: 'servis', adresa: 'Vukovarska 20 Osijek', oznaka: 'U-03' };
  p.stavke = [novaStavka({ naziv: 'Servis bicikla', cijena: 40, sidrenaCijena: 40 })];
  spremi(dir, p);
  return dir;
}
const arhiva = (dir) => fs.readdirSync(path.join(dir, 'cjenik', 'arhiva')).sort();

test('naziv datoteke prati primjer ministarstva', () => {
  const p = normaliziraj({ ...zadano(), separatorVremena: ':', objekt: { oblik: 'servis', adresa: 'Vukovarska 20 Osijek', oznaka: 'U-03' } });
  assert.equal(nazivDatoteke(p, 15, pocetak, 'xml'), 'servis_Vukovarska 20 Osijek_U-03_015_01.10.2026_07:45.xml');
});

test('naziv datoteke čisti znakove koji bi pokvarili strukturu', () => {
  const p = normaliziraj({ ...zadano(), separatorVremena: '-', objekt: { oblik: 'salon', adresa: 'Ilica 150/2_Zagreb', oznaka: 'P_01' } });
  assert.equal(nazivDatoteke(p, 1, pocetak, 'xml'), 'salon_Ilica 150-2-Zagreb_P-01_001_01.10.2026_07-45.xml');
});

test('ISO datum s lokalnim pomakom (ljetno i zimsko vrijeme)', () => {
  assert.equal(isoLokalno('2026-10-01T05:45:00Z'), '2026-10-01T07:45:00+02:00');
  assert.equal(isoLokalno('2026-12-01T06:45:00Z'), '2026-12-01T07:45:00+01:00');
});

test('parsiranje iznosa', () => {
  assert.equal(parsirajIznos('45'), 45);
  assert.equal(parsirajIznos('45,5'), 45.5);
  assert.equal(parsirajIznos('1.234,50 €'), 1234.5);
  assert.ok(Number.isNaN(parsirajIznos('abc')));
});

test('arhiva: jedna stara objava bez promjena ostaje', () => {
  const o = [{ broj: 1, datum: new Date(pocetak - 90 * DAN).toISOString() }];
  const { zadrzi, obrisi } = podijeliArhivu(o, pocetak, 30);
  assert.equal(zadrzi.length, 1);
  assert.equal(obrisi.length, 0);
});

test('arhiva: promjena svaki dan 40 dana -> zadnjih 30 + ona s granice', () => {
  const o = Array.from({ length: 40 }, (_, i) => ({ broj: i + 1, datum: new Date(pocetak - (39 - i) * DAN).toISOString() }));
  const { zadrzi, obrisi } = podijeliArhivu(o, pocetak, 30);
  assert.equal(zadrzi.length, 31);
  assert.equal(obrisi.length, 9);
  assert.equal(zadrzi[0].broj, 10); // stara točno 30 dana
});

test('arhiva: stara objava ostaje dok je bila važeća na granici', () => {
  const o = [
    { broj: 1, datum: new Date(pocetak - 100 * DAN).toISOString() },
    { broj: 2, datum: new Date(pocetak - 60 * DAN).toISOString() },
    { broj: 3, datum: new Date(pocetak - 5 * DAN).toISOString() },
  ];
  const { zadrzi } = podijeliArhivu(o, pocetak, 30);
  assert.deepEqual(zadrzi.map((x) => x.broj), [2, 3]);
});

test('objava: nova datoteka samo kad se cijena promijeni', () => {
  const dir = projekt();
  const r1 = objavi(dir, ucitaj(dir), { sada: pocetak });
  assert.equal(r1.nova.broj, 1);
  assert.deepEqual(arhiva(dir), ['servis_Vukovarska 20 Osijek_U-03_001_01.10.2026_07:45.xml']);

  const r2 = objavi(dir, ucitaj(dir), { sada: new Date(+pocetak + DAN) });
  assert.equal(r2.nova, null);
  assert.equal(arhiva(dir).length, 1);

  const p = ucitaj(dir);
  p.stavke[0].cijena = 45;
  const r3 = objavi(dir, p, { sada: new Date(+pocetak + 2 * DAN) });
  assert.equal(r3.nova.broj, 2);
  assert.equal(arhiva(dir).length, 2);

  const xml = fs.readFileSync(path.join(dir, 'cjenik', 'cjenik.xml'), 'utf8');
  assert.match(xml, /<brojPohrane>2<\/brojPohrane>/);
  assert.match(xml, /<maloprodajnaCijena valuta="EUR">45.00<\/maloprodajnaCijena>/);
  assert.match(xml, /<dodatnaCijena valuta="EUR" datum="2026-09-10">40.00<\/dodatnaCijena>/);
  assert.ok(fs.existsSync(path.join(dir, 'cjenik', 'index.html')));
});

test('objava: 40 dnevnih promjena briše datoteke izvan arhive', () => {
  const dir = projekt();
  for (let i = 0; i < 40; i++) {
    const p = ucitaj(dir);
    p.stavke[0].cijena = 40 + i;
    objavi(dir, p, { sada: new Date(+pocetak + i * DAN) });
  }
  const datoteke = arhiva(dir);
  assert.equal(datoteke.length, 31);
  assert.equal(ucitaj(dir).objave.length, 31);
  assert.match(datoteke[0], /_010_/);
  assert.equal(ucitaj(dir).brojPohrane, 40);
});

test('objava: početak akcije pamti najnižu cijenu iz prethodnih 30 dana', () => {
  const dir = projekt();
  objavi(dir, ucitaj(dir), { sada: pocetak }); // 40
  let p = ucitaj(dir);
  p.stavke[0].cijena = 38;
  objavi(dir, p, { sada: new Date(+pocetak + 5 * DAN) });
  p = ucitaj(dir);
  p.stavke[0].cijena = 42;
  objavi(dir, p, { sada: new Date(+pocetak + 10 * DAN) });
  p = ucitaj(dir);
  p.stavke[0].cijena = 35;
  p.stavke[0].akcija = { aktivna: true, naziv: 'Jesenska akcija', najniza30: null };
  objavi(dir, p, { sada: new Date(+pocetak + 12 * DAN) });
  assert.equal(ucitaj(dir).stavke[0].akcija.najniza30, 38);
  const xml = fs.readFileSync(path.join(dir, 'cjenik', 'cjenik.xml'), 'utf8');
  assert.match(xml, /<posebanOblikProdaje primijenjen="da">Jesenska akcija<\/posebanOblikProdaje>/);
  assert.match(xml, /<najnizaCijena30Dana valuta="EUR">38.00<\/najnizaCijena30Dana>/);
});

test('objava odbija nepotpune podatke', () => {
  const dir = projekt();
  const p = ucitaj(dir);
  p.stavke.push(novaStavka({ naziv: '', cijena: 'x' }));
  assert.throws(() => objavi(dir, p, { sada: pocetak }), GreskaProvjere);
});

test('XML escaping', () => {
  const p = normaliziraj({ ...zadano(), obveznik: { naziv: 'A & B <d.o.o.>' }, objekt: { oblik: 's', adresa: 'a', oznaka: '1' },
    stavke: [novaStavka({ naziv: 'Pranje "Premium"', cijena: 1, sidrenaCijena: 1 })] });
  const xml = uXml(p, 1, pocetak);
  assert.match(xml, /A &amp; B &lt;d.o.o.&gt;/);
  assert.match(xml, /Pranje &quot;Premium&quot;/);
});

test('umetanje u postojeću stranicu', () => {
  const html = `<p>a</p>\n${OZNAKA_POCETAK}staro${OZNAKA_KRAJ}\n<p>b</p>`;
  assert.equal(umetni(html, `${OZNAKA_POCETAK}novo${OZNAKA_KRAJ}`), `<p>a</p>\n${OZNAKA_POCETAK}novo${OZNAKA_KRAJ}\n<p>b</p>`);
  assert.equal(umetni('<p>bez oznaka</p>', 'x'), null);
});
