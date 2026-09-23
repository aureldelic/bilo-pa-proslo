# Cjenik sa sidrenim cijenama za statične web stranice

Alat za objavu cjenika usluga prema odlukama iz NN 101/26 koje vrijede od 1. listopada 2026.:

- **isticanje dodatne (sidrene) cijene** na dan 10. rujna 2026. uz trenutnu cijenu, u istoj tablici,
- **strojno čitljivi cjenik** (XML, po želji i CSV) s propisanim nazivom datoteke,
- **arhiva cjenika** koja čuva prethodne cjenike najmanje 30 dana.

Radi s bilo kojom statičnom stranicom (običan HTML, Hugo, Jekyll, Eleventy, Astro…). Ne treba baza ni PHP. Treba samo Node.js 18+ na računalu na kojem se cjenik uređuje. Nema vanjskih paketa.

## Brzi početak

```bash
cd moja-web-stranica
node /putanja/do/cjenik-sidrene-cijene/bin/cjenik.js admin
```

Ako alat instaliraš globalno (`npm link` u mapi alata), možeš pisati samo `cjenik admin`.

U pregledniku se otvara sučelje (`http://localhost:4321`). U njemu:

1. Upiši podatke o obrtu i objektu: naziv, oblik objekta (npr. *salon*, *servis*), adresu i oznaku.
2. Za svaku uslugu upiši **opis**, **trenutnu cijenu** i **cijenu na 10.9.2026.** Ako se cijena nije mijenjala, obje su iste; kad upišeš trenutnu cijenu, sidrena se sama predloži.
3. Klikni **Objavi cjenik**.
4. Mapu `cjenik/` prenesi na server, kao i ostatak stranice (FTP, rsync, git…).

Sve se sprema u `cjenik.json` u mapi projekta.

Za skripte i cron postoji i objava bez sučelja:

```bash
cjenik objavi --dir moja-web-stranica
```

## Što nastaje

```
cjenik/
├── index.html            javna stranica: tablica cijena i popis XML-ova
├── cjenik.xml            stalna poveznica na važeći cjenik
├── cjenik.csv            (ako je uključen CSV)
├── tablica.html          fragment tablice za widget
├── cjenik-widget.js      widget za ugradnju u postojeće stranice
└── arhiva/
    ├── salon_Vukovarska 20 Osijek_01_001_01.10.2026_07:45.xml
    └── salon_Vukovarska 20 Osijek_01_002_15.10.2026_07:30.xml
```

### Naziv datoteke

Prema pojašnjenju Ministarstva gospodarstva:
`oblik_adresa_oznaka_broj pohrane_DD.MM.GGGG_HH:MM`

Broj pohrane je redni broj objave. Znakovi `_ / \ :` unutar adrese ili oznake zamjenjuju se crticom, da ne pokvare strukturu naziva. Na Windowsu `:` nije dopušten u nazivu datoteke, pa je ondje zadani separator vremena `-`. Mijenja se u `cjenik.json` (`"separatorVremena"`).

### Kada nastaje nova datoteka

Nova XML datoteka nastaje **samo kad se nešto promijeni**: cijena, sidrena cijena, akcija, opis usluge ili podaci o objektu. Ako se klikne *Objavi* bez promjena, XML se ne dira; stranica se samo osvježi.

### Arhiva (30 dana)

Pri svakoj objavi zadržava se:

- sve objavljeno u zadnjih 30 dana,
- **zadnja objava prije te granice**, jer je ona vrijedila na početku razdoblja, pa je najstariji XML uvijek star barem 30 dana,
- uvijek važeći cjenik.

| Situacija | Što ostaje |
|---|---|
| Jedna objava prije 90 dana, bez promjena | ta jedna (ona je važeća) |
| Promjena svaki dan 40 dana | zadnjih 30 + jedna s granice (31 datoteka) |
| Objave prije 100, 60 i 5 dana | ona od prije 60 i ona od prije 5 dana |

Starije datoteke brišu se iz `cjenik/arhiva/` pri sljedećoj objavi. Ako se stranica prenosi alatom koji ne briše datoteke na serveru, stare ostaju i na serveru. To ne krši pravila, samo zauzima mjesto.

Razdoblje se može produljiti (`"danaArhive": 60`), ali ne i skratiti ispod 30.

## Ugradnja tablice u postojeću stranicu

**1. Umetanje pri objavi (preporučeno: bez JavaScripta, vidljivo tražilicama).** U HTML stranice dodaj oznake:

```html
<!-- cjenik:pocetak -->
<!-- cjenik:kraj -->
```

U `cjenik.json` navedi koje datoteke treba ažurirati:

```json
"umetni": ["index.html", "usluge/index.html"]
```

Pri svakoj objavi sadržaj između oznaka zamijeni se svježom tablicom.

Ako stranicu radi generator (Hugo, Jekyll…), oznake stavi u izvorni predložak ili parcijal, ne u generirani `public/` ili `_site/`.

**2. Widget (JavaScript).**

```html
<div data-cjenik></div>
<script src="/cjenik/cjenik-widget.js" defer></script>
```

**3. Poveznica** na samostalnu stranicu `/cjenik/`.

### Izgled

Tablica preuzima font i boju teksta od stranice. Boje se mogu prilagoditi CSS varijablama:

```css
.cjenik {
  --cjenik-akcent: #a0522d;        /* akcijska cijena i oznaka akcije */
  --cjenik-rub: #e5e0d8;           /* crte između redaka */
  --cjenik-blago: #7a746b;         /* sporedni tekst */
  --cjenik-pozadina-zaglavlja: #f4f1ec;
}
```

## Sadržaj cjenika usluga

Prema pojašnjenju ministarstva (točka 2.5.) za svaku uslugu objavljuje se:

| Polje | U sučelju | XML |
|---|---|---|
| Naziv usluge | Opis usluge | `<naziv>` |
| Maloprodajna cijena | Trenutna € | `<maloprodajnaCijena>` |
| Poseban oblik prodaje i njegov naziv | Akcija / popust + naziv | `<posebanOblikProdaje primijenjen="da">` |
| Dodatna cijena na dan sidrenja | Sidrena € + datum | `<dodatnaCijena datum="2026-09-10">` |

Neobavezna polja su kategorija (grupira tablicu), jedinica (npr. *po satu*) i najniža cijena u 30 dana prije akcije. Ako to polje ostane prazno, izračuna se iz prethodnih objava kad akcija počne.

**Nove usluge uvedene nakon 10.9.2026.:** kao sidrena cijena upisuje se cijena s kojom je usluga uvedena i datum uvođenja. Ti se retci u tablici označe svojim datumom.

**Usluge bez fiksne cijene:** objavljuju se elementi od kojih se cijena formira (npr. *Rad servisera, po satu*).

### Primjer XML-a

```xml
<?xml version="1.0" encoding="UTF-8"?>
<cjenik vrsta="usluge" verzija="1.0">
  <zaglavlje>
    <obveznik><naziv>Frizerski salon Ana, vl. Ana Anić</naziv></obveznik>
    <objekt><oblik>salon</oblik><adresa>Vukovarska 20 Osijek</adresa><oznaka>01</oznaka></objekt>
    <brojPohrane>2</brojPohrane>
    <datumObjave>2026-10-01T07:45:00+02:00</datumObjave>
    <valuta>EUR</valuta>
  </zaglavlje>
  <usluge>
    <usluga rb="1" sifra="9b0acda655">
      <naziv>Pramenovi (balayage)</naziv>
      <kategorija>Bojanje</kategorija>
      <maloprodajnaCijena valuta="EUR">68.00</maloprodajnaCijena>
      <posebanOblikProdaje primijenjen="da">Jesenska akcija</posebanOblikProdaje>
      <najnizaCijena30Dana valuta="EUR">80.00</najnizaCijena30Dana>
      <dodatnaCijena valuta="EUR" datum="2026-09-10">80.00</dodatnaCijena>
    </usluga>
  </usluge>
</cjenik>
```

Struktura XML-a zasad nije propisana. Ako ministarstvo objavi shemu, mijenja se samo `src/formati.js`.

## Automatski prijenos nakon objave

U `cjenik.json` možeš upisati naredbu koja se izvrši nakon svake objave, npr.:

```json
"nakonObjave": "rsync -av --delete cjenik/ korisnik@server:/var/www/stranica/cjenik/"
```

ili `"git add -A && git commit -m 'Cjenik' && git push"` za GitHub Pages ili Netlify.

## Rok za objavu

Pružatelji usluga ažuriraju cjenik kod svake promjene cijene, **najkasnije do 8:00 na dan kad promjena stupa na snagu**. Vrijeme objave upisuje se u naziv datoteke i u `<datumObjave>`.

## Razvoj

```bash
npm test          # testovi (node:test)
npm run admin     # sučelje nad primjerom u mapi primjer/
```

Primjer statične stranice s umetnutom tablicom i widgetom nalazi se u `primjer/index.html`.
