<?php
// Testovi: php tests/testovi.php

namespace Cjenik;

require __DIR__ . '/../cjenik/_sustav/ucitaj.php';
date_default_timezone_set(Util::ZONA);

$prolaz = 0;
$pad = 0;
function test(string $naziv, callable $fn): void
{
    global $prolaz, $pad;
    try {
        $fn();
        $prolaz++;
        echo "✔ $naziv\n";
    } catch (\Throwable $e) {
        $pad++;
        echo "✖ $naziv\n    " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
function jednako($stvarno, $ocekivano, string $poruka = ''): void
{
    if ($stvarno !== $ocekivano) {
        throw new \Exception(($poruka ? "$poruka: " : '') . 'očekivano ' . var_export($ocekivano, true) . ', dobiveno ' . var_export($stvarno, true));
    }
}
function sadrzi(string $tekst, string $dio): void
{
    if (strpos($tekst, $dio) === false) throw new \Exception("nema '$dio'");
}

const DAN = 86400;
$pocetak = strtotime('2026-10-01T05:45:00Z'); // 07:45 po zagrebačkom vremenu

/** Privremena instalacija: stranica/cjenik s kopijom _sustav. */
function instalacija(): Sustav
{
    $stranica = sys_get_temp_dir() . '/cjenik-test-' . bin2hex(random_bytes(4));
    mkdir("$stranica/cjenik/_sustav", 0755, true);
    kopiraj(__DIR__ . '/../cjenik/_sustav', "$stranica/cjenik/_sustav");
    $s = new Sustav("$stranica/cjenik");
    zastitiMape($s);
    $p = Podaci::zadano();
    $p['obveznik'] = ['naziv' => 'Servis Test', 'oib' => ''];
    $p['objekt'] = ['oblik' => 'servis', 'adresa' => 'Vukovarska 20 Osijek', 'oznaka' => 'U-03'];
    $p['stavke'] = [Podaci::novaStavka(['id' => 'a1', 'naziv' => 'Servis bicikla', 'cijena' => 40, 'sidrenaCijena' => 40])];
    Podaci::pisiJson($s->datotekaPodataka(), $p);
    return $s;
}
function kopiraj(string $iz, string $u): void
{
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($iz, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
        $cilj = $u . substr($f->getPathname(), strlen($iz));
        $f->isDir() ? @mkdir($cilj, 0755, true) : copy($f->getPathname(), $cilj);
    }
}
function arhiva(Sustav $s): array
{
    $d = array_values(array_diff(scandir($s->korijen() . '/arhiva'), ['.', '..']));
    sort($d);
    return $d;
}

test('naziv datoteke: primjer ministarstva, bez razmaka', function () use ($pocetak) {
    $p = Podaci::normaliziraj(['objekt' => ['oblik' => 'servis', 'adresa' => 'Vukovarska 20 Osijek', 'oznaka' => 'U-03']]);
    jednako(Formati::nazivDatoteke($p, 15, $pocetak, 'xml'), 'servis_Vukovarska_20_Osijek_U-03_015_01.10.2026_07:45.xml');
});

test('naziv datoteke: nedopušteni znakovi postaju crtica', function () use ($pocetak) {
    $p = Podaci::normaliziraj(['separatorVremena' => '-', 'objekt' => ['oblik' => 'salon', 'adresa' => 'Ilica 150/2_Zagreb', 'oznaka' => 'P_01']]);
    jednako(Formati::nazivDatoteke($p, 1, $pocetak, 'xml'), 'salon_Ilica_150-2_Zagreb_P_01_001_01.10.2026_07-45.xml');
});

test('ISO datum s lokalnim pomakom (ljetno i zimsko vrijeme)', function () {
    jednako(Util::iso('2026-10-01T05:45:00Z'), '2026-10-01T07:45:00+02:00');
    jednako(Util::iso('2026-12-01T06:45:00Z'), '2026-12-01T07:45:00+01:00');
});

test('parsiranje iznosa', function () {
    jednako(Util::parsirajIznos('45'), 45.0);
    jednako(Util::parsirajIznos('45,5'), 45.5);
    jednako(Util::parsirajIznos('1.234,50 €'), 1234.5);
    jednako(Util::parsirajIznos(''), null);
    jednako(is_nan(Util::parsirajIznos('abc')), true);
});

test('prikaz iznosa i datuma', function () {
    jednako(Util::novac(1234.5), "1.234,50\u{00A0}€");
    jednako(Util::datumKratko('2026-09-10'), '10.9.2026.');
});

test('arhiva: jedna stara objava bez promjena ostaje', function () use ($pocetak) {
    $r = Arhiva::podijeli([['broj' => 1, 'datum' => Util::iso($pocetak - 90 * DAN)]], $pocetak, 30);
    jednako(count($r['zadrzi']), 1);
    jednako(count($r['obrisi']), 0);
});

test('arhiva: promjena svaki dan 40 dana -> zadnjih 30 + ona s granice', function () use ($pocetak) {
    $o = [];
    for ($i = 0; $i < 40; $i++) $o[] = ['broj' => $i + 1, 'datum' => Util::iso($pocetak - (39 - $i) * DAN)];
    $r = Arhiva::podijeli($o, $pocetak, 30);
    jednako(count($r['zadrzi']), 31);
    jednako(count($r['obrisi']), 9);
    jednako($r['zadrzi'][0]['broj'], 10);
});

test('arhiva: stara objava ostaje dok je bila važeća na granici', function () use ($pocetak) {
    $r = Arhiva::podijeli([
        ['broj' => 1, 'datum' => Util::iso($pocetak - 100 * DAN)],
        ['broj' => 2, 'datum' => Util::iso($pocetak - 60 * DAN)],
        ['broj' => 3, 'datum' => Util::iso($pocetak - 5 * DAN)],
    ], $pocetak, 30);
    jednako(array_column($r['zadrzi'], 'broj'), [2, 3]);
});

test('objava: nova datoteka samo kad se cijena promijeni', function () use ($pocetak) {
    $s = instalacija();
    $o = new Objava($s);
    $r1 = $o->objavi($s->objavljeno(), $pocetak);
    jednako($r1['nova']['broj'], 1);
    jednako(arhiva($s), ['servis_Vukovarska_20_Osijek_U-03_001_01.10.2026_07:45.xml']);

    $r2 = $o->objavi($s->objavljeno(), $pocetak + DAN);
    jednako($r2['nova'], null);
    jednako(count(arhiva($s)), 1);

    $p = $s->objavljeno();
    $p['stavke'][0]['cijena'] = '45,00';
    $r3 = $o->objavi($p, $pocetak + 2 * DAN);
    jednako($r3['nova']['broj'], 2);
    jednako(count(arhiva($s)), 2);

    $xml = file_get_contents($s->korijen() . '/cjenik.xml');
    sadrzi($xml, '<brojPohrane>2</brojPohrane>');
    sadrzi($xml, '<maloprodajnaCijena valuta="EUR">45.00</maloprodajnaCijena>');
    sadrzi($xml, '<dodatnaCijena valuta="EUR" datum="2026-09-10">40.00</dodatnaCijena>');
    jednako(simplexml_load_string($xml) !== false, true, 'XML je ispravan');
    sadrzi(file_get_contents($s->korijen() . '/index.html'), 'Servis bicikla');
    jednako(is_file($s->korijen() . '/cjenik-widget.js'), true);
});

test('objava: 40 dnevnih promjena briše datoteke izvan arhive', function () use ($pocetak) {
    $s = instalacija();
    $o = new Objava($s);
    for ($i = 0; $i < 40; $i++) {
        $p = $s->objavljeno();
        $p['stavke'][0]['cijena'] = 40 + $i;
        $o->objavi($p, $pocetak + $i * DAN);
    }
    $d = arhiva($s);
    jednako(count($d), 31);
    jednako(count($s->objavljeno()['objave']), 31);
    jednako(strpos($d[0], '_010_') !== false, true, 'najstarija je br. 10');
    jednako($s->objavljeno()['brojPohrane'], 40);
});

test('čišćenje arhive bez nove objave (cijene se dugo ne mijenjaju)', function () use ($pocetak) {
    $s = instalacija();
    $o = new Objava($s);
    $o->objavi($s->objavljeno(), $pocetak);
    $p = $s->objavljeno();
    $p['stavke'][0]['cijena'] = 50;
    $o->objavi($p, $pocetak + DAN);
    $p = $s->objavljeno();
    $p['stavke'][0]['cijena'] = 55;
    $o->objavi($p, $pocetak + 2 * DAN);
    jednako(count(arhiva($s)), 3);
    // 60 dana kasnije: ostaje samo važeća (ona je i granična).
    $obrisano = $o->pocisti($pocetak + 62 * DAN);
    jednako(count($obrisano), 2);
    jednako(count(arhiva($s)), 1);
    jednako(strpos(arhiva($s)[0], '_003_') !== false, true);
});

test('početak akcije pamti najnižu cijenu iz prethodnih 30 dana', function () use ($pocetak) {
    $s = instalacija();
    $o = new Objava($s);
    $o->objavi($s->objavljeno(), $pocetak);
    foreach ([[5, 38], [10, 42]] as [$dan, $cijena]) {
        $p = $s->objavljeno();
        $p['stavke'][0]['cijena'] = $cijena;
        $o->objavi($p, $pocetak + $dan * DAN);
    }
    $p = $s->objavljeno();
    $p['stavke'][0]['cijena'] = 35;
    $p['stavke'][0]['akcija'] = ['aktivna' => true, 'naziv' => 'Jesenska akcija', 'najniza30' => null];
    $o->objavi($p, $pocetak + 12 * DAN);
    jednako($s->objavljeno()['stavke'][0]['akcija']['najniza30'], 38.0);
    $xml = file_get_contents($s->korijen() . '/cjenik.xml');
    sadrzi($xml, '<posebanOblikProdaje primijenjen="da">Jesenska akcija</posebanOblikProdaje>');
    sadrzi($xml, '<najnizaCijena30Dana valuta="EUR">38.00</najnizaCijena30Dana>');
});

test('objava odbija nepotpune podatke', function () use ($pocetak) {
    $s = instalacija();
    $p = $s->objavljeno();
    $p['stavke'][] = Podaci::novaStavka(['naziv' => '', 'cijena' => 'x']);
    try {
        (new Objava($s))->objavi($p, $pocetak);
        throw new \Exception('nije bacio grešku');
    } catch (GreskaProvjere $e) {
        jednako(count($e->greske) >= 2, true);
    }
});

test('nacrt se ne objavljuje dok se ne klikne Objavi', function () use ($pocetak) {
    $s = instalacija();
    $o = new Objava($s);
    $o->objavi($s->objavljeno(), $pocetak);
    $n = $s->nacrt();
    $n['stavke'][0]['cijena'] = 99;
    $s->spremiNacrt($n);
    jednako($s->nacrt()['stavke'][0]['cijena'], 99);
    jednako($s->objavljeno()['stavke'][0]['cijena'], 40.0);
    $o->pocisti($pocetak + 40 * DAN);
    jednako(strpos(file_get_contents($s->korijen() . '/cjenik.xml'), '99.00'), false);
    jednako($o->imaPromjena($s->nacrt()), true);
});

test('XML escaping', function () use ($pocetak) {
    $p = Podaci::normaliziraj(['obveznik' => ['naziv' => 'A & B <d.o.o.>'], 'objekt' => ['oblik' => 's', 'adresa' => 'a', 'oznaka' => '1'],
        'stavke' => [['naziv' => 'Pranje "Premium"', 'cijena' => 1, 'sidrenaCijena' => 1]]]);
    $xml = Formati::xml($p, 1, $pocetak);
    sadrzi($xml, 'A &amp; B &lt;d.o.o.&gt;');
    sadrzi($xml, 'Pranje &quot;Premium&quot;');
    jednako(simplexml_load_string($xml) !== false, true);
});

test('umetanje u stranicu i zaštita putanja', function () use ($pocetak) {
    $s = instalacija();
    $stranica = $s->stranica();
    mkdir("$stranica/usluge");
    file_put_contents("$stranica/usluge/index.html", "<p>a</p>\n" . Prikaz::OZNAKA_POCETAK . 'staro' . Prikaz::OZNAKA_KRAJ . "\n<p>b</p>");
    $p = $s->objavljeno();
    $p['umetni'] = ['usluge/index.html', '../izvan.html', 'cjenik/index.html', 'nema.html'];
    Podaci::pisiJson($s->datotekaPodataka(), $p);
    $r = (new Objava($s))->objavi($s->objavljeno(), $pocetak);
    jednako($r['umetnuto'], ['usluge/index.html']);
    jednako(count($r['upozorenja']), 3);
    $html = file_get_contents("$stranica/usluge/index.html");
    sadrzi($html, 'Servis bicikla');
    sadrzi($html, 'href="../cjenik/cjenik.xml"');
    jednako(strpos($html, 'staro'), false);
});

test('podaci nisu čitljivi kao obični tekst (PHP zaštita)', function () {
    $s = instalacija();
    jednako(strncmp(file_get_contents($s->datotekaPodataka()), '<?php', 5), 0);
    jednako(is_file($s->podaci('.htaccess')), true);
});

test('ažuriranje iz zipa mijenja _sustav, a podatke ne dira', function () use ($pocetak) {
    $s = instalacija();
    (new Objava($s))->objavi($s->objavljeno(), $pocetak);
    $zipPut = sys_get_temp_dir() . '/cjenik-azur-' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPut, \ZipArchive::CREATE);
    $izvor = realpath(__DIR__ . '/../cjenik/_sustav');
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($izvor, \FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr($f->getPathname(), strlen($izvor) + 1);
        $zip->addFile($f->getPathname(), "cjenik/_sustav/$rel");
    }
    $zip->addFromString('cjenik/_sustav/VERZIJA', "9.9.9\n");
    $zip->addFromString('cjenik/_sustav/../../zlo.php', 'x');
    $zip->addFromString('cjenik/admin/index.php', "<?php // nova\n");
    $zip->close();

    $prije = file_get_contents($s->datotekaPodataka());
    $v = (new Azuriranje($s))->instalirajZip($zipPut);
    jednako($v, '9.9.9');
    jednako($s->verzija(), '9.9.9');
    jednako(file_get_contents($s->datotekaPodataka()), $prije, 'podaci netaknuti');
    jednako(count(arhiva($s)), 1, 'arhiva netaknuta');
    jednako(is_dir($s->korijen() . '/_sustav.stari'), false);
    jednako(is_file(dirname($s->korijen()) . '/zlo.php'), false, 'zip-slip');
    sadrzi(file_get_contents($s->korijen() . '/admin/index.php'), 'nova');
    jednako(is_file($s->podaci('osvjezi')), true);
});

test('ažuriranje odbija zip bez plugina', function () {
    $s = instalacija();
    $zipPut = sys_get_temp_dir() . '/cjenik-krivi-' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new \ZipArchive();
    $zip->open($zipPut, \ZipArchive::CREATE);
    $zip->addFromString('nesto/drugo.txt', 'x');
    $zip->close();
    try {
        (new Azuriranje($s))->instalirajZip($zipPut);
        throw new \Exception('nije bacio grešku');
    } catch (\RuntimeException $e) {
        sadrzi($e->getMessage(), 'VERZIJA');
    }
    jednako(is_file($s->sustav('lib/Sustav.php')), true, 'stari kod ostaje');
});

/* ---------- Prijava i reset lozinke ---------- */

function zahtjev(string $host = 'salonana.hr', string $ip = '10.0.0.1'): void
{
    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['SCRIPT_NAME'] = '/cjenik/admin/index.php';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_SESSION = [];
}

test('instalacija traži ispravan e-mail i lozinku; e-mail je korisničko ime', function () {
    $s = instalacija();
    zahtjev();
    $a = new Auth($s);
    jednako($a->instaliraj('nije-email', 'dugacka-lozinka'), 'Upiši ispravnu e-mail adresu.');
    jednako($a->instaliraj('ana@salonana.hr', 'kratka') !== null, true);
    jednako($a->instaliraj(' Ana@SalonAna.hr ', 'dugacka-lozinka'), null);
    jednako($a->email(), 'ana@salonana.hr');
    jednako($s->postavke()['adresa'], 'https://salonana.hr/cjenik/admin/');
    jednako($a->instaliraj('drugi@x.hr', 'dugacka-lozinka'), 'Plugin je već instaliran.');

    zahtjev();
    jednako($a->prijava('drugi@salonana.hr', 'dugacka-lozinka'), 'Pogrešan e-mail ili lozinka.');
    jednako($a->prijava('ana@salonana.hr', 'kriva-lozinka'), 'Pogrešan e-mail ili lozinka.');
    jednako($a->prijava('ANA@salonana.hr', 'dugacka-lozinka'), null);
    jednako($a->prijavljen(), true);
});

test('previše pokušaja prijave blokira IP', function () {
    $s = instalacija();
    zahtjev();
    $a = new Auth($s);
    $a->instaliraj('ana@salonana.hr', 'dugacka-lozinka');
    for ($i = 0; $i < Auth::MAKS_POKUSAJA; $i++) $a->prijava('ana@salonana.hr', 'krivo');
    sadrzi((string) $a->prijava('ana@salonana.hr', 'dugacka-lozinka'), 'Previše');
    zahtjev('salonana.hr', '10.0.0.2');
    jednako($a->prijava('ana@salonana.hr', 'dugacka-lozinka'), null, 'drugi IP nije blokiran');
});

test('reset lozinke e-mailom: poveznica koristi spremljenu adresu, jednokratna je', function () {
    $s = instalacija();
    zahtjev();
    $a = new Auth($s);
    $a->instaliraj('ana@salonana.hr', 'stara-lozinka-1');
    $poslano = [];
    Posta::$prijevoz = function ($poruka) use (&$poslano) { $poslano[] = $poruka; };

    // Nepoznat e-mail: isti odgovor, ništa se ne šalje.
    zahtjev('napadac.com');
    jednako($a->zatraziReset('netko@drugi.hr'), null);
    jednako(count($poslano), 0);

    // Podmetnut Host ne završava u poveznici.
    jednako($a->zatraziReset('Ana@salonana.hr'), null);
    jednako(count($poslano), 1);
    jednako($poslano[0]['prima'], 'ana@salonana.hr');
    sadrzi($poslano[0]['tekst'], 'https://salonana.hr/cjenik/admin/?reset=');
    jednako(strpos($poslano[0]['tekst'], 'napadac'), false);
    jednako($poslano[0]['od'], 'noreply@salonana.hr');

    // Ponovni zahtjev odmah ne šalje novi mail.
    $a->zatraziReset('ana@salonana.hr');
    jednako(count($poslano), 1);

    preg_match('/reset=([a-f0-9]+)/', $poslano[0]['tekst'], $m);
    $token = $m[1];
    jednako($a->ispravanReset($token), true);
    jednako($a->ispravanReset(str_repeat('0', strlen($token))), false);
    jednako($a->postaviNovuLozinku($token, 'kratka') !== null, true);
    jednako($a->postaviNovuLozinku($token, 'nova-lozinka-22'), null);
    jednako($a->ispravanReset($token), false, 'jednokratna');
    jednako($a->postaviNovuLozinku($token, 'treca-lozinka-33') !== null, true);

    zahtjev();
    jednako($a->prijava('ana@salonana.hr', 'stara-lozinka-1'), 'Pogrešan e-mail ili lozinka.');
    jednako($a->prijava('ana@salonana.hr', 'nova-lozinka-22'), null);
    Posta::$prijevoz = null;
});

test('reset poveznica istječe nakon sat vremena', function () {
    $s = instalacija();
    zahtjev();
    $a = new Auth($s);
    $a->instaliraj('ana@salonana.hr', 'stara-lozinka-1');
    $tekst = '';
    Posta::$prijevoz = function ($poruka) use (&$tekst) { $tekst = $poruka['tekst']; };
    $a->zatraziReset('ana@salonana.hr');
    Posta::$prijevoz = null;
    preg_match('/reset=([a-f0-9]+)/', $tekst, $m);
    $p = $s->postavke();
    $p['reset']['istjece'] = time() - 1;
    $s->spremiPostavke($p);
    jednako($a->ispravanReset($m[1]), false);
    sadrzi((string) $a->postaviNovuLozinku($m[1], 'nova-lozinka-22'), 'istekla');
});

test('neuspjelo slanje e-maila javlja grešku i poništava poveznicu', function () {
    $s = instalacija();
    zahtjev();
    $a = new Auth($s);
    $a->instaliraj('ana@salonana.hr', 'stara-lozinka-1');
    Posta::$prijevoz = function () { throw new \RuntimeException('ne radi'); };
    sadrzi((string) $a->zatraziReset('ana@salonana.hr'), 'nije uspjelo');
    Posta::$prijevoz = null;
    jednako(isset($s->postavke()['reset']), false);
});

test('promjena e-maila traži lozinku; stara instalacija bez e-maila radi', function () {
    $s = instalacija();
    zahtjev();
    $a = new Auth($s);
    $a->instaliraj('ana@salonana.hr', 'stara-lozinka-1');
    jednako($a->promijeniEmail('kriva', 'nova@salonana.hr'), 'Lozinka nije točna.');
    jednako($a->promijeniEmail('stara-lozinka-1', 'Nova@SalonAna.hr'), null);
    jednako($a->email(), 'nova@salonana.hr');

    $p = $s->postavke();
    unset($p['email']);
    $s->spremiPostavke($p);
    jednako($a->prijava('', 'stara-lozinka-1'), null, 'v1.0.0 instalacija');
});

/* ---------- Slanje e-maila ---------- */

/** Pokreni lažni SMTP poslužitelj; vraća [port, izlazna datoteka, proces]. */
function lazniSmtp(): array
{
    $port = random_int(20000, 40000);
    $izlaz = sys_get_temp_dir() . '/smtp-' . bin2hex(random_bytes(4)) . '.json';
    $proces = proc_open([PHP_BINARY, __DIR__ . '/lazni-smtp.php', (string) $port, $izlaz], [1 => ['pipe', 'w']], $cijevi);
    fgets($cijevi[1]);
    return [$port, $izlaz, $proces];
}

test('SMTP: prijava, pošiljatelj, primatelj i UTF-8 poruka', function () {
    [$port, $izlaz, $proces] = lazniSmtp();
    Posta::posaljiSmtp(
        ['host' => '127.0.0.1', 'port' => $port, 'sifriranje' => 'nema', 'korisnik' => 'noreply@salonana.hr', 'lozinka' => 'tajna'],
        ['od' => 'noreply@salonana.hr', 'ime' => 'Cjenik', 'prima' => "ana@salonana.hr\r\nBcc: zlo@x.hr", 'naslov' => 'Nova lozinka – čćžšđ',
         'tekst' => "Pozdrav\n.\nčćžšđ", 'domena' => 'salonana.hr']
    );
    proc_close($proces);
    $z = json_decode(file_get_contents($izlaz), true);
    jednako($z['naredbe'][0], 'EHLO salonana.hr');
    jednako(in_array('MAIL FROM:<noreply@salonana.hr>', $z['naredbe'], true), true);
    jednako(in_array('RCPT TO:<ana@salonana.hr  Bcc: zlo@x.hr>', $z['naredbe'], true), true, 'CRLF uklonjen, nema injekcije');
    jednako(strpos($z['data'], "\nBcc:"), false);
    sadrzi($z['data'], 'Subject: =?UTF-8?B?' . base64_encode('Nova lozinka – čćžšđ') . '?=');
    [$zaglavlja, $tijelo] = explode("\r\n\r\n", $z['data'], 2);
    jednako(base64_decode($tijelo), "Pozdrav\n.\nčćžšđ");
});

test('SMTP: kriva lozinka daje razumljivu grešku', function () {
    [$port, $izlaz, $proces] = lazniSmtp();
    try {
        Posta::posaljiSmtp(
            ['host' => '127.0.0.1', 'port' => $port, 'sifriranje' => 'nema', 'korisnik' => 'noreply@salonana.hr', 'lozinka' => 'kriva'],
            ['od' => 'noreply@salonana.hr', 'ime' => 'Cjenik', 'prima' => 'ana@salonana.hr', 'naslov' => 'x', 'tekst' => 'x', 'domena' => 'salonana.hr']
        );
        throw new \Exception('nije bacio grešku');
    } catch (\RuntimeException $e) {
        sadrzi($e->getMessage(), 'Provjeri korisničko ime i lozinku');
        sadrzi($e->getMessage(), '535');
    }
    proc_terminate($proces);
});

test('SMTP: nedostupan poslužitelj', function () {
    try {
        Posta::posaljiSmtp(['host' => '127.0.0.1', 'port' => 1, 'sifriranje' => 'nema'],
            ['od' => 'a@b.hr', 'ime' => 'C', 'prima' => 'c@d.hr', 'naslov' => 'x', 'tekst' => 'x', 'domena' => 'b.hr']);
        throw new \Exception('nije bacio grešku');
    } catch (\RuntimeException $e) {
        sadrzi($e->getMessage(), 'ne mogu se spojiti na 127.0.0.1:1');
    }
});

test('reset lozinke ide preko podešenog SMTP-a', function () {
    $s = instalacija();
    zahtjev();
    $a = new Auth($s);
    $a->instaliraj('ana@salonana.hr', 'stara-lozinka-1');
    jednako(!empty($s->postavke()['korakPosta']), true, 'nakon instalacije slijedi korak slanja e-maila');
    [$port, $izlaz, $proces] = lazniSmtp();
    $p = $s->postavke();
    $p['smtp'] = ['host' => '127.0.0.1', 'port' => $port, 'sifriranje' => 'nema', 'korisnik' => 'noreply@salonana.hr', 'lozinka' => 'tajna', 'posiljatelj' => ''];
    $s->spremiPostavke($p);
    jednako($a->zatraziReset('ana@salonana.hr'), null);
    proc_close($proces);
    $z = json_decode(file_get_contents($izlaz), true);
    jednako(in_array('RCPT TO:<ana@salonana.hr>', $z['naredbe'], true), true);
    [, $tijelo] = explode("\r\n\r\n", $z['data'], 2);
    sadrzi(base64_decode($tijelo), 'https://salonana.hr/cjenik/admin/?reset=');
});

test('provjera dostupnosti PHP mail()', function () {
    jednako(in_array(Posta::mailDostupan(), ['da', 'ne'], true), true);
    jednako(Posta::posiljatelj(['adresa' => 'https://www.salonana.hr/cjenik/admin/']), 'noreply@salonana.hr');
    jednako(Posta::posiljatelj(['smtp' => ['host' => 'x', 'korisnik' => 'k@a.hr', 'posiljatelj' => '']]), 'k@a.hr');
    jednako(Posta::posiljatelj(['smtp' => ['host' => 'x', 'korisnik' => 'k@a.hr', 'posiljatelj' => 'p@a.hr']]), 'p@a.hr');
});

/* ---------- Provjera zaštite podataka ---------- */

/** Pokreni poslužitelj nad korijenom stranice; vraća [proces, port]. */
function posluzitelj(array $naredba, string $korijen): array
{
    $port = random_int(40001, 50000);
    $naredba = array_map(function ($d) use ($port) { return str_replace('{port}', (string) $port, $d); }, $naredba);
    $proces = proc_open($naredba, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $c, $korijen);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    return [$proces, $port];
}

test('provjera zaštite: PHP poslužitelj ne izlaže podatke', function () {
    $s = instalacija();
    [$proces, $port] = posluzitelj([PHP_BINARY, '-S', '127.0.0.1:{port}'], $s->stranica());
    $r = Sigurnost::provjeri($s, "http://127.0.0.1:$port/cjenik/admin/");
    proc_terminate($proces);
    jednako($r['stanje'], 'ok', $r['poruka']);
    jednako($r['url'], "http://127.0.0.1:$port/cjenik/_podaci/provjera.php");
});

test('provjera zaštite: poslužitelj koji ne izvršava PHP je otkriven', function () {
    $s = instalacija();
    [$proces, $port] = posluzitelj(['python3', '-m', 'http.server', '{port}', '--bind', '127.0.0.1'], $s->stranica());
    $r = Sigurnost::provjeri($s, "http://127.0.0.1:$port/cjenik/admin/");
    proc_terminate($proces);
    jednako($r['stanje'], 'izlozeno');
});

test('provjera zaštite: nedostupan poslužitelj daje "nepoznato"', function () {
    $s = instalacija();
    jednako(Sigurnost::provjeri($s, 'http://127.0.0.1:1/cjenik/admin/')['stanje'], 'nepoznato');
});

test('provjera zaštite uvijek ide na vlastiti poslužitelj (podmetnut Host)', function () {
    $s = instalacija();
    [$proces, $port] = posluzitelj([PHP_BINARY, '-S', '127.0.0.1:{port}'], $s->stranica());
    // Ime domene ne postoji, ali veza ide na IP poslužitelja.
    $r = Sigurnost::provjeri($s, "http://napadac.invalid:$port/cjenik/admin/", '127.0.0.1');
    proc_terminate($proces);
    jednako($r['stanje'], 'ok', $r['poruka']);
});

echo "\n$prolaz prošlo, $pad palo\n";
exit($pad ? 1 : 0);
