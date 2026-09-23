#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { exec } from 'node:child_process';
import { DATOTEKA, putanja, spremi, ucitaj, zadano } from '../src/config.js';
import { GreskaProvjere, objavi } from '../src/objava.js';
import { pokreni } from '../src/server.js';

const POMOC = `Cjenik sa sidrenim cijenama za statične web stranice

Upotreba:
  cjenik init   [--dir mapa]              napravi ${DATOTEKA} u mapi projekta
  cjenik admin  [--dir mapa] [--port 4321] [--bez-preglednika]
                                           otvori sučelje za unos usluga i cijena
  cjenik objavi [--dir mapa]              objavi iz ${DATOTEKA} (za skripte i cron)

Mapa projekta je korijen web stranice (ili bilo koja mapa s ${DATOTEKA}).
`;

const argumenti = process.argv.slice(2);
const naredba = argumenti[0];
const opcija = (ime, zadana) => {
  const i = argumenti.indexOf(ime);
  return i === -1 ? zadana : argumenti[i + 1];
};
const dir = path.resolve(opcija('--dir', process.cwd()));

try {
  if (naredba === 'init') {
    if (fs.existsSync(putanja(dir))) {
      console.log(`${DATOTEKA} već postoji u ${dir}`);
    } else {
      spremi(dir, zadano());
      console.log(`Napravljen ${putanja(dir)}\nSljedeći korak: cjenik admin`);
    }
  } else if (naredba === 'admin') {
    if (!fs.existsSync(putanja(dir))) spremi(dir, zadano());
    const port = Number(opcija('--port', 4321));
    await pokreni(dir, port);
    const url = `http://localhost:${port}/`;
    console.log(`Sučelje za cjenik: ${url}\nProjekt: ${dir}\nZa izlaz pritisni Ctrl+C.`);
    if (!argumenti.includes('--bez-preglednika')) {
      const otvori = process.platform === 'darwin' ? 'open' : process.platform === 'win32' ? 'start ""' : 'xdg-open';
      exec(`${otvori} ${url}`);
    }
  } else if (naredba === 'objavi') {
    const r = objavi(dir, ucitaj(dir));
    console.log(r.nova ? `Nova objava br. ${r.nova.broj}: ${r.nova.datoteke.join(', ')}` : 'Nema promjena, XML nije mijenjan.');
    for (const d of r.obrisano) console.log(`Obrisano iz arhive: ${d}`);
    for (const d of r.umetnuto) console.log(`Tablica umetnuta u: ${d}`);
    for (const u of r.upozorenja) console.warn(`Upozorenje: ${u}`);
    if (r.naredba && !r.naredba.ok) {
      console.error(`Naredba nakon objave nije uspjela:\n${r.naredba.izlaz}`);
      process.exitCode = 1;
    }
  } else {
    console.log(POMOC);
    if (naredba && naredba !== 'pomoc' && naredba !== '--help') process.exitCode = 1;
  }
} catch (e) {
  if (e instanceof GreskaProvjere) {
    console.error('Cjenik nije objavljen:\n- ' + e.greske.join('\n- '));
  } else {
    console.error(e.message);
  }
  process.exitCode = 1;
}
