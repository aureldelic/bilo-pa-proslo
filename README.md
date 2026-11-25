<p align="center"><img src="docs/logo.webp" alt="Bilo pa prošlo" width="220"></p>

# Bilo pa prošlo

**Cjenik usluga s baznim cijenama (10.9.2026.). PHP plugin za web stranice.**

<a href="https://block.hr"><img src="docs/block-logo.svg" alt="BLOCK" width="40" align="right"></a>

Autor: **[BLOCK](https://block.hr)**, web stranice po mjeri i privatni hosting.

Plugin za objavu cjenika usluga prema pravilnicima Ministarstva gospodarstva koji vrijede od 17. studenoga 2026. ([NN 132/2026](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_11_132_1584.html)):

- **bazna cijena** (cijena na dan 10. rujna 2026.) uz trenutnu cijenu, u istoj tablici ([Pravilnik o isticanju bazne cijene proizvoda](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_11_132_1583.html)),
- **strojno čitljivi cjenik** (XML i/ili CSV) s propisanim nazivom datoteke i redoslijedom podataka ([Pravilnik o načinu objave cjenika proizvoda](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_11_132_1584.html)),
- **arhiva cjenika**: prethodni cjenik ostaje dostupan nakon zamjene (propis: najmanje 15 dana), a stariji se **briše sam**.

Sve radi na hostingu stranice. Klijent se prijavi u preglednik, upiše usluge i cijene i klikne *Objavi*. Ništa ne mora uploadati ni instalirati. Plugin se ažurira sam s GitHuba.

**Zahtjevi:** PHP 7.4 ili noviji (radi na običnom shared hostingu) i PHP modul `zip` (za ažuriranje). Baza nije potrebna. Stranica može biti potpuno statična (HTML); PHP treba samo mapi `cjenik/`.

## Kako izgleda

### Javni cjenik

<p align="center"><img src="docs/snimke/javni-cjenik.webp" alt="Javna stranica cjenika" width="820"></p>

Javna stranica `/cjenik/` široka je do 1200 px. U zaglavlju su naziv obrta s OIB-om i adresa, a desno datum zadnjeg ažuriranja. Tablica ide preko cijele širine, grupirana po kategorijama, s trenutnom i baznom cijenom (cijena na 10.9.2026.) te oznakom akcije. Ispod su preuzimanje strojno čitljivog cjenika (XML/CSV) i arhiva prethodnih cjenika.

### Sučelje za uređivanje

<p align="center"><img src="docs/snimke/sucelje.webp" alt="Sučelje za uređivanje cjenika" width="820"></p>

Redom: podaci o obrtu i prodajnom mjestu (s prikazom naziva iduće datoteke), postavke (zatvorene dok ih ne otvoriš), usluge i cijene, pregled tablice preko cijele širine i na kraju popis objavljenih cjenika.

<table>
<tr>
<td width="50%"><img src="docs/snimke/pregled.webp" alt="Pregled tablice"><br><sub>Pregled tablice dok se piše</sub></td>
<td width="50%"><img src="docs/snimke/postavke.webp" alt="Postavke"><br><sub>Postavke: umetanje u stranice, arhiva, ažuriranje, e-mail, sigurnost, prijava</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/snimke/prijava.webp" alt="Prijava"><br><sub>Prijava e-mailom i lozinkom, uz zaboravljenu lozinku</sub></td>
<td width="50%"><img src="docs/snimke/tamna.webp" alt="Tamna tema"><br><sub>Tamna tema (prekidač u zaglavlju)</sub></td>
</tr>
</table>

### Na mobitelu

<p align="center">
<img src="docs/snimke/javni-mobitel.webp" alt="Javni cjenik na mobitelu" width="260">
&nbsp;&nbsp;
<img src="docs/snimke/mobitel.webp" alt="Sučelje na mobitelu" width="260">
<br><sub>Na mobitelu je svaka usluga kartica s označenim cijenama; isto vrijedi i za tablicu umetnutu u stranice weba i widget.</sub>
</p>

## Preuzimanje

**[⬇ Preuzmi Bilo pa prošlo (zadnja verzija)](https://github.com/aureldelic/bilo-pa-proslo/releases/latest/download/bilo-pa-proslo.zip)**

```
https://github.com/aureldelic/bilo-pa-proslo/releases/latest/download/bilo-pa-proslo.zip
```

Adresa uvijek vodi na zadnju verziju. Za instalaciju se koristi samo `bilo-pa-proslo.zip`, a ne automatski paketi *Source code* sa stranice izdanja.

## Instalacija (jednom po stranici)

1. Prenesi `bilo-pa-proslo.zip` u korijen web stranice (`public_html`, `www`…) i raspakiraj ga, tako da nastane mapa `cjenik/` i postoji `https://stranica.hr/cjenik/admin/`.
   - **cPanel / Plesk:** u File Manageru uploadaj zip, pa desni klik → *Extract*.
   - **FTP:** raspakiraj na računalu i prenesi cijelu mapu `cjenik/`.
   - **SSH:**
     ```bash
     cd public_html && curl -L -o bilo-pa-proslo.zip https://github.com/aureldelic/bilo-pa-proslo/releases/latest/download/bilo-pa-proslo.zip && unzip bilo-pa-proslo.zip && rm bilo-pa-proslo.zip
     ```
2. **Odmah** otvori `https://stranica.hr/cjenik/admin/` i upiši **e-mail i lozinku klijenta**. Dok to nije napravljeno, pristup može preuzeti bilo tko tko otvori tu adresu. E-mail je korisničko ime, a na njega stiže poveznica za novu lozinku.
3. **Slanje e-maila.** Plugin provjeri može li hosting slati mail preko PHP `mail()` i upozori ako ne može. Upiši SMTP podatke nekog sandučića (npr. `noreply@domena.hr`) i klikni *Spremi i pošalji testni e-mail*. Korak se može preskočiti. Ako hosting nema `mail()`, reset lozinke tada neće raditi dok se SMTP ne podesi.
4. Pristupne podatke daj klijentu. Klijent može sam promijeniti i e-mail i lozinku u *Postavke → Prijava*.
5. Po želji u *Postavke → Tablica na stranicama weba* upiši HTML stranice u koje se tablica umeće (vidi niže).

Na instalacijskom ekranu plugin javlja ako ne može pisati u svoje mape ili ako su podaci javno dostupni.

## Kako klijent radi

Na adresi `https://stranica.hr/cjenik/admin/` se prijavi e-mailom i lozinkom i uredi (redom kako je u sučelju):

- **Obrt i prodajno mjesto:** naziv, OIB ili matični broj obrta, prodajno mjesto (salon, servis, ured…), adresa i oznaka, ili kvačica *Jedan cjenik za sva prodajna mjesta*. Ti podaci ulaze u naziv datoteke.
- **Usluge:** opis, trenutna cijena i bazna cijena (cijena na 10.9.2026.). Kad se upiše trenutna cijena, bazna se predloži sama. Neobavezno se dodaju kategorija, jedinica i akcija.
- **Postavke** (ispod podataka o obrtu, zatvorene dok se ne otvore): tablica na stranicama weba, arhiva, ažuriranje plugina, slanje e-maila, sigurnost i prijava.
- **Uvoz:** standardizirani Excel (`.xlsx`) ili CSV može zamijeniti sve stavke u nacrtu. Datoteka se obrađuje iz privremene upload putanje i ne sprema se na web. Uvoz ne objavljuje cjenik dok se ne klikne *Objavi*.
- **Objavi cjenik:** nastaje novi XML/CSV, osvježi se javna stranica, umetne se tablica u stranice weba i obrišu se stari cjenici. Izmijenjeni cjenik objavljuje se **najkasnije do 11:00 na dan izmjene**.

Izmjene se automatski spremaju kao nacrt. Javno se ništa ne mijenja dok se ne klikne *Objavi*.

**Tema:** prekidač svijetla/tamna u zaglavlju mijenja izgled sučelja i javne stranice `/cjenik/` (zadano je svijetla). Tablica umetnuta u stranice weba preuzima boje same stranice.

## Što nastaje na stranici

```
cjenik/
├── index.html            javna stranica: tablica cijena, preuzimanje i arhiva
├── cjenik.xml            stalna poveznica na važeći cjenik
├── cjenik.csv            (ako je uključen CSV)
├── tablica.html          fragment tablice za widget
├── cjenik-widget.js      widget za ugradnju u stranice
├── arhiva/
│   ├── 12345678901_salon_Vukovarska_20_Osijek_01_USLUGE_001_20261117_0745.xml
│   └── 12345678901_salon_Vukovarska_20_Osijek_01_USLUGE_002_20261124_0730.xml
├── admin/                sučelje (prijava lozinkom)
├── _sustav/              kod plugina: mijenja ga ažuriranje
└── _podaci/              podaci, lozinka i nacrt: ažuriranje ga nikad ne dira
```

### Naziv datoteke

Prema čl. 6. Pravilnika o načinu objave cjenika proizvoda:

```
OIB_prodajno mjesto_adresa_oznaka_USLUGE_broj pohrane_GGGGMMDD_HHMM
12345678901_salon_Vukovarska_20_Osijek_01_USLUGE_004_20261124_0745.xml
```

Ako se isti cjenik koristi na svim prodajnim mjestima:

```
OIB_ZA_SVA_PRODAJNA_MJESTA_USLUGE_broj pohrane_GGGGMMDD_HHMM
```

- Umjesto OIB-a obrt može upisati matični broj.
- Razmaci postaju `_`, a znakovi `/ \ : * ? " < > |` unutar podataka postaju `-`.
- Broj pohrane je redni broj objave.

### Kada nastaje nova datoteka

Nova datoteka nastaje **samo kad se nešto promijeni**: cijena, bazna cijena, akcija, opis ili podaci o prodajnom mjestu. Objava bez promjena ne dira datoteke.

Važeći cjenik uvijek je na istom mjestu (`/cjenik/`, stalne poveznice `cjenik.xml` i `cjenik.csv`), a prethodni se premještaju u `/cjenik/arhiva/`.

### Arhiva i automatsko brisanje

Pravilnik traži da arhivirani cjenik bude dostupan **15 dana nakon što ga zamijeni važeći**. Plugin čuva:

- uvijek važeći cjenik,
- svaki prethodni cjenik dok od njegove zamjene ne prođe zadani broj dana (zadano 30, najmanje 15).

| Situacija (zadano 30 dana) | Što ostaje |
|---|---|
| Jedna objava prije 90 dana, bez promjena | ta jedna (važeća) |
| Cjenik zamijenjen prije 10 dana | ostaje |
| Cjenik zamijenjen prije 40 dana | briše se |

Starije datoteke plugin briše sam na serveru: pri svakoj objavi i pri svakom otvaranju sučelja. Razdoblje se mijenja u postavkama (15–365 dana).

## Sadržaj cjenika usluga

Prema čl. 3. st. 3. Pravilnika, podaci idu ovim redom:

| Polje | U sučelju | CSV stupac | XML |
|---|---|---|---|
| Naziv usluge | Opis usluge | `naziv_usluge` | `<naziv>` |
| Maloprodajna cijena usluge | Trenutna € | `maloprodajna_cijena_usluge` | `<maloprodajnaCijena>` |
| Je li primijenjen poseban oblik prodaje | Akcija / popust | `poseban_oblik_prodaje` (`DA`/`NE`) | `<posebanOblikProdaje primijenjen="da">` |
| Bazna cijena usluge | Bazna € + datum | `bazna_cijena_usluge` | `<baznaCijena datum="2026-09-10">` |

CSV je UTF-8, sa stupcima odvojenima točkom sa zarezom i iznosima s decimalnom točkom (`25.00`). Sadrži samo ova četiri stupca. U XML-u iza propisanih podataka dolaze i kategorija, jedinica i najniža cijena u 30 dana prije akcije.

- **Bazna cijena** je cijena koja je vrijedila 10.9.2026. Ako je tada bila akcija, upisuje se redovna cijena prije akcije.
- **Nove usluge uvedene nakon 10.9.2026.:** bazna cijena je cijena s kojom je usluga uvedena, uz datum uvođenja.
- **Usluge bez fiksne cijene:** objavljuju se elementi od kojih se cijena formira (npr. *Rad servisera, po satu*).
- **Akcija:** najniža cijena u 30 dana prije akcije izračuna se sama iz prethodnih objava i prikazuje se u tablici na stranici.

Struktura XML-a nije propisana. Ako ministarstvo objavi shemu, mijenja se `cjenik/_sustav/lib/Formati.php`, a nova verzija dođe na sve stranice kroz ažuriranje.

### Format za uvoz

Prvi radni list (po mogućnosti nazvan `Import`) mora u prvom retku imati ova zaglavlja:

`naziv_usluge`, `kategorija`, `jedinica_mjere`, `maloprodajna_cijena`, `naziv_posebnog_oblika_prodaje`, `najniza_cijena_30_dana`, `bazna_cijena`, `datum_bazne_cijene`, `sifra`.

Stari nazivi `dodatna_cijena` i `datum_dodatne_cijene` i dalje se prihvaćaju, kao i stupci CSV-a koji plugin objavljuje (`maloprodajna_cijena_usluge`, `bazna_cijena_usluge`). Ako stupca s datumom nema, bazna cijena vrijedi za 10.9.2026.

Obavezni su naziv, maloprodajna cijena i bazna cijena. `sifra` je stabilan identifikator; ako je prazna, plugin zadržava postojeću šifru stavke s istom kategorijom, nazivom i jedinicom ili generira novu.

Poseban oblik prodaje određuje se automatski:

- Prazan `naziv_posebnog_oblika_prodaje` znači redovnu prodaju bez akcije ili popusta.
- Popunjen naziv automatski uključuje posebni oblik prodaje. Naziv treba jasno opisati ponudu, npr. `Ljetna akcija`, `Popust 20%` ili `Paket 3 za 2`. `maloprodajna_cijena` je cijena koju kupac tada plaća.
- Za aktivnu ponudu u `najniza_cijena_30_dana` upisuje se najniža cijena koja je vrijedila u 30 dana prije početka ponude. Polje može ostati prazno samo ako je plugin može izračunati iz prethodnih objava iste stavke.

Stariji CSV izvoz sadrži i stupac `poseban_oblik_prodaje` (`DA`/`NE`). Importer ga i dalje prihvaća, ali kad je naziv ponude popunjen, naziv ima prednost i ponuda se automatski smatra aktivnom.

Uvoz prima najviše 2 MB i 2000 stavki. XLSX se čita bez raspakiravanja u javnu mapu, uz ograničenje broja i ukupne raspakirane veličine ZIP dijelova. Privremenu upload datoteku PHP uklanja nakon zahtjeva.

## Tablica na stranicama weba

**1. Umetanje pri objavi (preporučeno: bez JavaScripta i vidljivo tražilicama).** U HTML stranice dodaj oznake:

```html
<!-- cjenik:pocetak -->
<!-- cjenik:kraj -->
```

U sučelju *Postavke → Tablica na stranicama weba* upiši putanje od korijena weba, na primjer `index.html` ili `usluge/index.html`. Pri svakoj objavi sadržaj između oznaka zamijeni se svježom tablicom. PHP mora imati dozvolu pisanja u te datoteke.

**2. Widget.**

```html
<div data-cjenik></div>
<script src="/cjenik/cjenik-widget.js" defer></script>
```

**3. Poveznica** na `/cjenik/`.

Tablica preuzima font i boju teksta od stranice. Boje se mijenjaju CSS varijablama:

```css
.cjenik {
  --cjenik-akcent: #a0522d;        /* akcijska cijena i oznaka akcije */
  --cjenik-rub: #e5e0d8;
  --cjenik-blago: #7a746b;
  --cjenik-pozadina-zaglavlja: #f4f1ec;
}
```

## Sigurnost

- **Lozinka za prijavu** sprema se samo kao hash (`password_hash`). Poveznica za novu lozinku čuva se samo kao SHA-256 hash.
- **`_podaci/` i `_sustav/`** zaštićeni su preko `.htaccess` (Apache). Podaci su k tome spremljeni kao `.php` datoteke koje počinju s `<?php exit;`, pa nisu čitljivi preko weba ni na nginxu.
- **Provjera zaštite:** pri instalaciji i pri svakom otvaranju sučelja plugin preko vlastite javne adrese pokuša pročitati kontrolnu datoteku `_podaci/provjera.php`. Ako je njezin sadržaj vidljiv, prikaže se crveno upozorenje s uputom za popravak.
- **SMTP lozinka** mora ostati čitljiva da bi se plugin mogao prijaviti na mail server. Nikad se ne šalje u preglednik. Preporuka je koristiti poseban sandučić (`noreply@…`) ili SMTP ključ servisa za slanje, a ne glavni mailbox.
- Prijava je zaštićena od pogađanja (5 pokušaja, pa blokada 15 minuta), a sve radnje u sučelju od CSRF-a.

## Zaboravljena lozinka

Na ekranu za prijavu je poveznica **Zaboravljena lozinka?**. Klijent upiše svoj e-mail i dobije poveznicu za novu lozinku.

- Poveznica vrijedi **1 sat**, može se iskoristiti samo jednom i odjavljuje sve ostale prijave.
- Odgovor na ekranu je uvijek isti, pa se ne može provjeriti koji je e-mail registriran.
- Nova poveznica može se zatražiti najviše jednom u 5 minuta.
- Adresa u poveznici je ona spremljena pri instalaciji i prijavi, a ne ona iz zahtjeva. Tako je nitko ne može preusmjeriti na svoju domenu.

**Bez pristupa e-mailu:** preko FTP-a obriši `cjenik/_podaci/postavke.php` i ponovno otvori `/cjenik/admin/` za novu instalaciju. Cijene i arhiva ostaju, a generira se i novi ključ za daljinsko ažuriranje.

## Slanje e-maila

Mail ide preko **SMTP-a** ako je podešen (pri instalaciji ili u *Postavke → Slanje e-maila*). Inače ide preko PHP `mail()` s adrese `noreply@domena-stranice`. Ako ne radi ništa, klijent dobije poruku da se javi webmasteru, a točan razlog zapiše se u PHP error log.

SMTP klijent je ugrađen, bez vanjskih biblioteka, i podržava SSL (port 465), STARTTLS (587) i vezu bez šifriranja. Česti podaci:

| Sandučić | Poslužitelj | Port / šifriranje | Napomena |
|---|---|---|---|
| Hosting (cPanel, Plesk…) | `mail.domena.hr` | 465 / SSL | korisnik je cijela e-mail adresa |
| Gmail / Google Workspace | `smtp.gmail.com` | 587 / STARTTLS | treba *lozinka aplikacije* |
| Microsoft 365 | `smtp.office365.com` | 587 / STARTTLS | SMTP AUTH mora biti dopušten |

Gumb *Pošalji testni e-mail* šalje poruku na e-mail korisnika i prikaže točnu grešku poslužitelja ako slanje ne uspije.

## Ažuriranje plugina

Instalacije preuzimaju nove verzije na tri načina:

- **Automatski:** kad klijent otvori sučelje, a u postavkama je uključeno *Automatski instaliraj nove verzije* (zadano). Provjera se radi najviše svakih 6 sati.
- **Ručno:** gumb *Ažuriraj sada* u sučelju.
- **Daljinski, za sve stranice odjednom:** svaka instalacija ima svoj ključ (*Postavke → Ažuriranje plugina*). Popis stranica upiši u `stranice.txt`:

  ```
  https://salonana.hr/cjenik/admin/ 3f9c2a7e5b1d8046c3e9a2f7b5d1c8e4a6f0b2d9
  https://servis-ivo.hr/cjenik/admin/ 4f0c...
  ```

  pa pokreni:

  ```bash
  alati/azuriraj-sve.sh stranice.txt
  ```

Ažuriranje mijenja samo `_sustav/` i `admin/`. Nova verzija se raspakira pokraj stare i zamijeni tek kad je potpuna. Podaci, lozinka i objavljeni cjenici ostaju netaknuti.

Za ažuriranje repozitorij mora biti **javan**. Kod nije tajan, a podaci klijenata nikad nisu u repozitoriju. Za privatni repozitorij upiši GitHub token u `_podaci/postavke.php` (ključ `githubToken`).

## Razvoj

Nova verzija objavljuje se tagom:

```bash
nova_verzija="1.1.0"
printf '%s\n' "$nova_verzija" > cjenik/_sustav/VERZIJA
git commit -am "Verzija $nova_verzija"
git tag "v$nova_verzija"
git push origin main "v$nova_verzija"
```

GitHub Action pokrene testove na PHP 7.4 i 8.3, napravi `bilo-pa-proslo.zip` i objavi izdanje. Ako testovi ne prođu, izdanje se ne objavljuje.

Lokalno:

```bash
php tests/testovi.php        # testovi

# isprobavanje: mapa primjer-stranica/ nije u repozitoriju
mkdir -p primjer-stranica && echo '<h1>Primjer</h1><!-- cjenik:pocetak --><!-- cjenik:kraj -->' > primjer-stranica/index.html
rsync -a --exclude _podaci --exclude arhiva cjenik/ primjer-stranica/cjenik/
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8089 -t primjer-stranica   # http://127.0.0.1:8089/cjenik/admin/
```

`PHP_CLI_SERVER_WORKERS` je potreban jer provjera zaštite šalje zahtjev istom serveru, a ugrađeni PHP server inače obrađuje jedan zahtjev odjednom.
