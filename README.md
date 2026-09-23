<p align="center"><img src="docs/logo.webp" alt="Bilo pa prošlo" width="220"></p>

# Bilo pa prošlo

**Cjenik usluga sa sidrenim cijenama (10.9.2026.). PHP plugin za web stranice.**

<a href="https://block.hr"><img src="docs/block-logo.svg" alt="BLOCK" width="40" align="right"></a>

Autor: **[BLOCK](https://block.hr)**, web stranice po mjeri i privatni hosting.

Plugin za objavu cjenika usluga prema odlukama iz NN 101/26 koje vrijede od 1. listopada 2026.:

- **dodatna (sidrena) cijena** na dan 10. rujna 2026. uz trenutnu cijenu, u istoj tablici,
- **strojno čitljivi cjenik** (XML, po želji i CSV) s nazivom datoteke kakav traži ministarstvo,
- **arhiva cjenika** koja prethodne cjenike čuva najmanje 30 dana, a starije **briše sama**.

Sve radi na hostingu stranice. Klijent se prijavi u preglednik, upiše usluge i cijene i klikne *Objavi*. Ništa ne mora uploadati ni instalirati. Plugin se ažurira sam s GitHuba.

**Zahtjevi:** PHP 7.4 ili noviji (radi na običnom shared hostingu) i PHP modul `zip` (za ažuriranje). Baza nije potrebna. Stranica može biti potpuno statična (HTML); PHP treba samo mapi `cjenik/`.

## Preuzimanje

**[⬇ Preuzmi Bilo pa prošlo (zadnja verzija)](https://github.com/aureldelic/bilo-pa-proslo/releases/latest/download/bilo-pa-proslo.zip)**

```
https://github.com/aureldelic/bilo-pa-proslo/releases/latest/download/bilo-pa-proslo.zip
```

Adresa uvijek vodi na zadnju verziju. Na stranici izdanja uz `bilo-pa-proslo.zip` stoje i automatski paketi *Source code (zip / tar.gz)*. Oni sadrže cijeli repozitorij (testove, primjer) i **nisu za postavljanje na hosting**.

## Instalacija (jednom po stranici)

1. Prenesi `bilo-pa-proslo.zip` u korijen web stranice (`public_html`, `www`…) i raspakiraj ga, tako da nastane mapa `cjenik/` i postoji `https://stranica.hr/cjenik/admin/`.
   - **cPanel / Plesk:** u File Manageru uploadaj zip, pa desni klik → *Extract*.
   - **FTP:** raspakiraj na računalu i prenesi cijelu mapu `cjenik/`.
   - **SSH:**
     ```bash
     cd public_html && curl -L -o bilo-pa-proslo.zip https://github.com/aureldelic/bilo-pa-proslo/releases/latest/download/bilo-pa-proslo.zip && unzip bilo-pa-proslo.zip && rm bilo-pa-proslo.zip
     ```
2. **Odmah** otvori `https://stranica.hr/cjenik/admin/` i upiši **e-mail i lozinku klijenta**. Dok to nije napravljeno, pristup može preuzeti bilo tko tko otvori tu adresu. E-mail je korisničko ime, a na njega stiže poveznica za novu lozinku.
3. **Drugi korak: slanje e-maila.** Plugin provjeri može li hosting slati mail preko PHP `mail()` i upozori ako ne može. Upiši SMTP podatke nekog sandučića (npr. `noreply@domena.hr`) i klikni *Spremi i pošalji testni e-mail*. Korak se može preskočiti. Ako hosting nema `mail()`, reset lozinke tada neće raditi dok se SMTP ne podesi.
4. Pristupne podatke daj klijentu. Klijent može sam promijeniti i e-mail i lozinku u *Postavke → Prijava*.
5. Po želji u *Postavke → Tablica na stranicama weba* upiši HTML stranice u koje se tablica umeće (vidi niže).

Na instalacijskom ekranu plugin javlja ako ne može pisati u svoje mape.

## Kako klijent radi

Na adresi `https://stranica.hr/cjenik/admin/` se prijavi e-mailom i lozinkom i uredi:

- **Obrt i objekt:** naziv, oblik objekta (salon, servis, ured…), adresa i oznaka. Ti podaci ulaze u naziv datoteke.
- **Usluge:** opis, trenutna cijena i cijena na 10.9.2026. Kad se upiše trenutna cijena, sidrena se predloži sama. Neobavezno se dodaju kategorija, jedinica i akcija.
- **Objavi cjenik:** nastaje novi XML, osvježi se javna stranica, umetne se tablica u stranice weba i obrišu se stari XML-ovi.

**Tema:** prekidač svijetla/tamna u zaglavlju sučelja mijenja izgled sučelja i javne stranice `/cjenik/` (zadano je svijetla). Tablica umetnuta u stranice weba preuzima boje same stranice.

Izmjene se automatski spremaju kao nacrt. Javno se ništa ne mijenja dok se ne klikne *Objavi*.

## Što nastaje na stranici

```
cjenik/
├── index.html            javna stranica: tablica cijena i popis XML-ova
├── cjenik.xml            stalna poveznica na važeći cjenik
├── cjenik.csv            (ako je uključen CSV)
├── tablica.html          fragment tablice za widget
├── cjenik-widget.js      widget za ugradnju u stranice
├── arhiva/
│   ├── salon_Vukovarska_20_Osijek_01_001_01.10.2026_07:45.xml
│   └── salon_Vukovarska_20_Osijek_01_002_15.10.2026_07:30.xml
├── admin/                sučelje (prijava lozinkom)
├── _sustav/              kod plugina: mijenja ga ažuriranje
└── _podaci/              podaci, lozinka i nacrt: ažuriranje ga nikad ne dira
```

`_podaci/` i `_sustav/` zaštićeni su preko `.htaccess` (Apache). Podaci su k tome spremljeni kao `.php` datoteke koje počinju s `<?php exit;`, pa nisu čitljivi ni na nginxu.

**Provjera zaštite.** Pri instalaciji i pri svakom otvaranju sučelja plugin preko vlastite javne adrese pokuša pročitati kontrolnu datoteku `_podaci/provjera.php`. Ako je njezin sadržaj vidljiv, poslužitelj izlaže podatke i prikaže se crveno upozorenje s uputom za popravak. Ako poslužitelj ne može poslati zahtjev sam sebi, plugin to napiše i ponudi poveznicu za ručnu provjeru. Kod ugrađenog `php -S` servera za to treba `PHP_CLI_SERVER_WORKERS=4`.

### Naziv datoteke

Prema pojašnjenju Ministarstva gospodarstva:
`oblik_adresa_oznaka_brojpohrane_DD.MM.GGGG_HH:MM`

- Razmaci postaju `_`, a znakovi `/ \ : * ? " < > |` unutar podataka postaju `-`.
- Broj pohrane je redni broj objave.
- Separator vremena (`:`, `-` ili `.`) mijenja se u postavkama.

### Kada nastaje nova datoteka

Nova XML datoteka nastaje **samo kad se nešto promijeni**: cijena, sidrena cijena, akcija, opis ili podaci o objektu. Objava bez promjena ne dira XML.

### Arhiva i automatsko brisanje

Čuva se:

- sve objavljeno u zadnjih 30 dana,
- **zadnja objava prije te granice**, jer je vrijedila na početku razdoblja, pa je najstariji XML uvijek star barem 30 dana,
- uvijek važeći cjenik.

| Situacija | Što ostaje |
|---|---|
| Jedna objava prije 90 dana, bez promjena | ta jedna (važeća) |
| Promjena svaki dan 40 dana | zadnjih 30 + jedna s granice (31) |
| Objave prije 100, 60 i 5 dana | ona od prije 60 i ona od prije 5 dana |

Starije datoteke plugin briše sam na serveru: pri svakoj objavi i pri svakom otvaranju sučelja. Razdoblje se može produljiti u postavkama (30–365 dana).

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

## Ažuriranje plugina

Nova verzija objavljuje se tagom u ovom repozitoriju:

```bash
echo "1.0.2" > cjenik/_sustav/VERZIJA
git commit -am "Verzija 1.0.2" && git tag v1.0.2 && git push origin main v1.0.2
```

GitHub Action pokrene testove na PHP 7.4, napravi `bilo-pa-proslo.zip` i objavi izdanje.

Instalacije ga preuzimaju na tri načina:

- **Automatski:** kad klijent otvori sučelje, a u postavkama je uključeno *Automatski instaliraj nove verzije* (zadano). Provjera se radi najviše svakih 6 sati.
- **Ručno:** gumb *Ažuriraj sada* u sučelju.
- **Daljinski, za sve stranice odjednom:** svaka instalacija ima svoj ključ (*Postavke → Ažuriranje plugina*). Popis stranica upiši u `stranice.txt`:

  ```
  https://salonana.hr/cjenik/admin/ bb115c8cd788be15f0af72df4db2ea74d5d0baf8
  https://servis-ivo.hr/cjenik/admin/ 4f0c...
  ```

  pa pokreni:

  ```bash
  alati/azuriraj-sve.sh stranice.txt
  ```

Ažuriranje mijenja samo `_sustav/` i `admin/`. Nova verzija se raspakira pokraj stare i zamijeni tek kad je potpuna. Podaci, lozinka i objavljeni cjenici ostaju netaknuti.

Za ažuriranje repozitorij mora biti **javan**. Kod nije tajan, a podaci klijenata nikad nisu u repozitoriju. Za privatni repozitorij upiši GitHub token u `_podaci/postavke.php` (ključ `githubToken`).

## Sadržaj cjenika usluga

Prema pojašnjenju ministarstva (točka 2.5.):

| Polje | U sučelju | XML |
|---|---|---|
| Naziv usluge | Opis usluge | `<naziv>` |
| Maloprodajna cijena | Trenutna € | `<maloprodajnaCijena>` |
| Poseban oblik prodaje i naziv | Akcija / popust + naziv | `<posebanOblikProdaje primijenjen="da">` |
| Dodatna cijena na dan sidrenja | Sidrena € + datum | `<dodatnaCijena datum="2026-09-10">` |

**Nove usluge uvedene nakon 10.9.2026.:** kao sidrena cijena upisuje se cijena s kojom je usluga uvedena i datum uvođenja.

**Usluge bez fiksne cijene:** objavljuju se elementi od kojih se cijena formira (npr. *Rad servisera, po satu*).

**Akcija:** najniža cijena u 30 dana prije akcije izračuna se sama iz prethodnih objava, a može se upisati i ručno.

Struktura XML-a zasad nije propisana. Ako ministarstvo objavi shemu, mijenja se `cjenik/_sustav/lib/Formati.php`, a nova verzija dođe na sve stranice kroz ažuriranje.

## Zaboravljena lozinka

Na ekranu za prijavu je poveznica **Zaboravljena lozinka?**. Klijent upiše svoj e-mail i dobije poveznicu za novu lozinku.

- Poveznica vrijedi **1 sat**, može se iskoristiti samo jednom i odjavljuje sve ostale prijave.
- Odgovor na ekranu je uvijek isti, pa se ne može provjeriti koji je e-mail registriran.
- Nova poveznica može se zatražiti najviše jednom u 5 minuta.
- Adresa u poveznici je ona spremljena pri instalaciji i prijavi, a ne ona iz zahtjeva. Tako je nitko ne može preusmjeriti na svoju domenu.
- Mail ide preko **SMTP-a** ako je podešen (u instalaciji ili u *Postavke → Slanje e-maila*). Inače ide preko PHP `mail()` s adrese `noreply@domena-stranice`. Ako ne radi ništa, klijent dobije poruku da se javi webmasteru, a točan razlog zapiše se u PHP error log.

### Slanje e-maila (SMTP)

SMTP klijent je ugrađen, bez vanjskih biblioteka, i podržava SSL (port 465), STARTTLS (587) i vezu bez šifriranja. Česti podaci:

| Sandučić | Poslužitelj | Port / šifriranje | Napomena |
|---|---|---|---|
| Hosting (cPanel, Plesk…) | `mail.domena.hr` | 465 / SSL | korisnik je cijela e-mail adresa |
| Gmail / Google Workspace | `smtp.gmail.com` | 587 / STARTTLS | treba *lozinka aplikacije* |
| Microsoft 365 | `smtp.office365.com` | 587 / STARTTLS | SMTP AUTH mora biti dopušten |

SMTP lozinka sprema se u `_podaci/postavke.php` (zaštićeno kao i ostali podaci) i nikad se ne prikazuje u sučelju. Gumb *Pošalji testni e-mail* šalje poruku na e-mail korisnika i prikaže točnu grešku poslužitelja ako slanje ne uspije.

**Ako ni to ne pomaže**, npr. nema pristupa e-mailu: preko FTP-a obriši `cjenik/_podaci/postavke.php` i ponovno otvori `/cjenik/admin/` za novu instalaciju. Cijene i arhiva ostaju; generira se i novi ključ za daljinsko ažuriranje.

**Instalacije iz verzije 1.0.0** nemaju e-mail: prijava ide samo lozinkom, a sučelje traži da se e-mail upiše u postavke.

## Razvoj

```bash
php tests/testovi.php                          # testovi
rsync -a --exclude _podaci --exclude arhiva cjenik/ primjer-stranica/cjenik/
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8089 -t primjer-stranica   # http://127.0.0.1:8089/cjenik/admin/
```
