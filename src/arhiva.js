// Pravilo čuvanja arhive cjenika.
//
// Odluka: prethodno važeći cjenici moraju ostati javno dostupni najmanje 30 dana
// od dana objave. Čuvamo:
//   1. sve objave iz zadnjih N dana (N = danaArhive, najmanje 30),
//   2. zadnju objavu PRIJE te granice — ona je vrijedila na dan granice, pa je
//      najstarija datoteka u arhivi uvijek stara barem N dana (ako postoji),
//   3. uvijek trenutno važeću (najnoviju) objavu.
// Primjeri:
//   - jedna objava prije 90 dana, bez promjena -> ostaje (ona je i zadnja),
//   - promjena svaki dan 40 dana -> ostaje zadnjih 30 + ona s granice.

import { danLokalno, razlikaDana } from './util.js';

/**
 * @param {{broj:number, datum:string}[]} objave
 * @param {Date|number|string} sada
 * @param {number} dana
 * @returns {{zadrzi: object[], obrisi: object[]}}
 */
export function podijeliArhivu(objave, sada, dana = 30) {
  const sortirane = [...objave].sort((a, b) => Date.parse(a.datum) - Date.parse(b.datum));
  const danas = danLokalno(sada);
  const starost = (o) => razlikaDana(danLokalno(o.datum), danas);

  const unutar = sortirane.filter((o) => starost(o) < dana);
  const prije = sortirane.filter((o) => starost(o) >= dana);
  const granicna = prije.at(-1);

  const zadrzi = new Set(unutar);
  if (granicna) zadrzi.add(granicna);
  if (sortirane.length) zadrzi.add(sortirane.at(-1));

  return {
    zadrzi: sortirane.filter((o) => zadrzi.has(o)),
    obrisi: sortirane.filter((o) => !zadrzi.has(o)),
  };
}

/** Starost objave u danima (za prikaz u sučelju). */
export function starostDana(objava, sada) {
  return razlikaDana(danLokalno(objava.datum), danLokalno(sada));
}
