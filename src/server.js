// Lokalno sučelje za uređivanje cjenika (sluša samo na 127.0.0.1).

import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { normaliziraj, provjeri, spremi, ucitaj } from './config.js';
import { podijeliArhivu, starostDana } from './arhiva.js';
import { GreskaProvjere, imaPromjena, objavi } from './objava.js';
import { CSS_TABLICE, tablica } from './prikaz.js';

const ADMIN = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'admin', 'index.html');
const UREDIVO = ['obveznik', 'objekt', 'stavke', 'naslovStranice', 'napomena', 'formati', 'danaArhive'];

const TIPOVI = {
  '.html': 'text/html; charset=utf-8', '.xml': 'application/xml; charset=utf-8',
  '.csv': 'text/csv; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.css': 'text/css; charset=utf-8',
};

function spoji(dir, nacrt) {
  const podaci = ucitaj(dir);
  for (const k of UREDIVO) if (nacrt[k] !== undefined) podaci[k] = nacrt[k];
  return podaci;
}

function stanje(dir) {
  const podaci = ucitaj(dir);
  const sada = new Date();
  const { obrisi } = podijeliArhivu(podaci.objave, sada, podaci.danaArhive);
  const zaBrisanje = new Set(obrisi.map((o) => o.broj));
  return {
    podaci,
    promjene: imaPromjena(podaci),
    greske: provjeri(normaliziraj(podaci)),
    arhiva: podaci.objave.map((o) => ({
      broj: o.broj, datum: o.datum, datoteke: o.datoteke,
      starost: starostDana(o, sada), brisanje: zaBrisanje.has(o.broj),
    })).reverse(),
  };
}

async function tijelo(req) {
  let s = '';
  for await (const dio of req) {
    s += dio;
    if (s.length > 5e6) throw new Error('Prevelik zahtjev');
  }
  return s ? JSON.parse(s) : {};
}

function json(res, status, podaci) {
  res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
  res.end(JSON.stringify(podaci));
}

export function pokreni(dir, port = 4321) {
  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    try {
      // Zaštita od zahtjeva s drugih stranica (DNS rebinding / CSRF).
      const host = (req.headers.host || '').split(':')[0];
      if (!['localhost', '127.0.0.1'].includes(host)) return json(res, 403, { greska: 'Zabranjeno' });
      if (req.method !== 'GET' && req.headers['x-cjenik'] !== '1') return json(res, 403, { greska: 'Zabranjeno' });

      if (req.method === 'GET' && url.pathname === '/') {
        res.writeHead(200, { 'content-type': TIPOVI['.html'], 'cache-control': 'no-store' });
        return res.end(fs.readFileSync(ADMIN));
      }
      if (req.method === 'GET' && url.pathname === '/api/stanje') return json(res, 200, stanje(dir));

      if (req.method === 'PUT' && url.pathname === '/api/nacrt') {
        spremi(dir, spoji(dir, await tijelo(req)));
        return json(res, 200, stanje(dir));
      }
      if (req.method === 'POST' && url.pathname === '/api/pregled') {
        const podaci = normaliziraj(spoji(dir, await tijelo(req)));
        podaci.stavke = podaci.stavke.filter((s) => s.naziv && Number.isFinite(s.cijena) && Number.isFinite(s.sidrenaCijena));
        return json(res, 200, { html: `<style>${CSS_TABLICE}</style>${tablica(podaci)}` });
      }
      if (req.method === 'POST' && url.pathname === '/api/objavi') {
        try {
          const r = objavi(dir, spoji(dir, await tijelo(req)));
          return json(res, 200, {
            ...stanje(dir),
            rezultat: { nova: r.nova, obrisano: r.obrisano, umetnuto: r.umetnuto, upozorenja: r.upozorenja, naredba: r.naredba },
          });
        } catch (e) {
          if (e instanceof GreskaProvjere) return json(res, 422, { greske: e.greske });
          throw e;
        }
      }

      if (req.method === 'GET' && url.pathname.startsWith('/pregled/')) {
        const podaci = ucitaj(dir);
        const izlaz = path.resolve(dir, podaci.izlaz || 'cjenik');
        let p = path.resolve(izlaz, '.' + decodeURIComponent(url.pathname.slice('/pregled'.length)));
        if (!p.startsWith(izlaz)) return json(res, 403, { greska: 'Zabranjeno' });
        if (fs.existsSync(p) && fs.statSync(p).isDirectory()) p = path.join(p, 'index.html');
        if (!fs.existsSync(p)) {
          res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
          return res.end('Cjenik još nije objavljen.');
        }
        res.writeHead(200, { 'content-type': TIPOVI[path.extname(p)] || 'application/octet-stream', 'cache-control': 'no-store' });
        return res.end(fs.readFileSync(p));
      }

      json(res, 404, { greska: 'Nije pronađeno' });
    } catch (e) {
      json(res, 500, { greska: e.message });
    }
  });

  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', () => resolve(server));
  });
}
