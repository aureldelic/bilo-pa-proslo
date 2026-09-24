<?php
// Ulazna točka sučelja: instalacija, prijava, API i stranica za uređivanje.

namespace Cjenik;

// Pokreće se samo preko admin/index.php, ne izravno iz _sustav/.
if (!defined('CJENIK_ADMIN')) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/ucitaj.php';

date_default_timezone_set(Util::ZONA);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");

$s = new Sustav(dirname(__DIR__));
zastitiMape($s);
$auth = new Auth($s);
$api = isset($_GET['api']) ? (string) $_GET['api'] : null;
$metoda = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_odgovor(int $status, array $podaci): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($podaci, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function tijelo(): array
{
    $j = json_decode((string) file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

/** Nakon ažuriranja: novi kod osvježi javne datoteke (CSS, widget). */
function osvjeziNakonAzuriranja(Sustav $s): void
{
    if (!is_file($s->podaci('osvjezi'))) return;
    @unlink($s->podaci('osvjezi'));
    $p = $s->objavljeno();
    if ($p['objave']) (new Objava($s))->generiraj(Podaci::normaliziraj($p));
}

/* ---------- Daljinsko ažuriranje (bez prijave, s ključem) ---------- */
if ($api === 'daljinsko-azuriranje') {
    if ($metoda !== 'POST') json_odgovor(405, ['greska' => 'Samo POST']);
    $kljuc = (string) ($_POST['kljuc'] ?? tijelo()['kljuc'] ?? '');
    $ocekivan = (string) ($s->postavke()['kljucAzuriranja'] ?? '');
    if ($ocekivan === '' || !hash_equals($ocekivan, $kljuc)) {
        usleep(500000);
        json_odgovor(403, ['greska' => 'Neispravan ključ']);
    }
    try {
        osvjeziNakonAzuriranja($s);
        $prije = $s->verzija();
        $nova = (new Azuriranje($s))->instaliraj();
        json_odgovor(200, ['prije' => $prije, 'sada' => $nova, 'azurirano' => $nova !== $prije]);
    } catch (\Throwable $e) {
        json_odgovor(500, ['greska' => $e->getMessage()]);
    }
}

$auth->pokreniSesiju();

/* ---------- Slanje e-maila (SMTP) ---------- */

/** Spremi SMTP podatke iz obrasca/API-ja. Prazan poslužitelj briše SMTP. Lozinka se mijenja samo ako je upisana. */
function spremiSmtp(Sustav $s, array $b): ?string
{
    $post = $s->postavke();
    $host = trim((string) ($b['host'] ?? ''));
    if ($host === '') {
        unset($post['smtp']);
        $s->spremiPostavke($post);
        return null;
    }
    $sifriranje = in_array($b['sifriranje'] ?? '', ['ssl', 'tls', 'nema'], true) ? $b['sifriranje'] : 'ssl';
    $port = (int) ($b['port'] ?? 0) ?: ($sifriranje === 'ssl' ? 465 : 587);
    $posiljatelj = Auth::normalizirajEmail((string) ($b['posiljatelj'] ?? ''));
    $korisnik = trim((string) ($b['korisnik'] ?? ''));
    if (!preg_match('/^[a-z0-9.-]+$/i', $host)) return 'Poslužitelj smije sadržavati samo slova, brojke, točke i crtice (npr. mail.domena.hr).';
    if ($port < 1 || $port > 65535) return 'Neispravan port.';
    if ($posiljatelj !== '' && !Auth::ispravanEmail($posiljatelj)) return 'Adresa pošiljatelja nije ispravan e-mail.';
    if ($posiljatelj === '' && !Auth::ispravanEmail($korisnik)) return 'Upiši adresu pošiljatelja (korisničko ime nije e-mail adresa).';
    $stari = $post['smtp'] ?? [];
    $lozinka = (string) ($b['lozinka'] ?? '');
    $post['smtp'] = [
        'host' => $host,
        'port' => $port,
        'sifriranje' => $sifriranje,
        'korisnik' => $korisnik,
        'lozinka' => $lozinka !== '' ? $lozinka : (($stari['host'] ?? '') !== '' ? ($stari['lozinka'] ?? '') : ''),
        'posiljatelj' => $posiljatelj,
        'ime' => Util::NAZIV,
    ];
    $s->spremiPostavke($post);
    return null;
}

/** Testni mail na e-mail korisnika. Sa SMTP-om ide samo preko SMTP-a, da se vidi točna greška. */
function testniMail(Sustav $s): ?string
{
    $post = $s->postavke();
    $prima = (string) ($post['email'] ?? '');
    if ($prima === '') return 'Prvo upiši svoj e-mail (Postavke → Prijava).';
    $domena = Posta::domena($post);
    $tekst = "Ovo je testna poruka s cjenika na $domena.\n\nAko je čitaš, slanje e-maila radi i poveznica za zaboravljenu lozinku stići će na ovu adresu.\n";
    try {
        if ($smtp = Posta::smtp($post)) {
            if (Posta::$prijevoz) {
                Posta::posalji($post, $prima, "Testni e-mail s cjenika ($domena)", $tekst);
            } else {
                Posta::posaljiSmtp($smtp, ['od' => Posta::posiljatelj($post), 'ime' => Util::NAZIV, 'prima' => $prima,
                    'naslov' => "Testni e-mail s cjenika ($domena)", 'tekst' => $tekst, 'domena' => $domena]);
            }
        } else {
            Posta::posalji($post, $prima, "Testni e-mail s cjenika ($domena)", $tekst);
        }
        return null;
    } catch (\RuntimeException $e) {
        return 'Slanje nije uspjelo: ' . $e->getMessage();
    }
}

/** SMTP podaci za prikaz (bez lozinke). */
function podaciPoste(Sustav $s): array
{
    $post = $s->postavke();
    $smtp = $post['smtp'] ?? [];
    return [
        'host' => $smtp['host'] ?? '',
        'port' => $smtp['port'] ?? '',
        'sifriranje' => $smtp['sifriranje'] ?? 'ssl',
        'korisnik' => $smtp['korisnik'] ?? '',
        'posiljatelj' => $smtp['posiljatelj'] ?? '',
        'imaLozinku' => !empty($smtp['lozinka']),
        'mail' => Posta::mailDostupan(),
        'domena' => Posta::domena($post),
    ];
}

/** Potpis autora: logo i poveznica na block.hr. */
function autor(): string
{
    $logo = file_get_contents(__DIR__ . '/block-logo.svg');
    return '<a class="autor" href="https://block.hr" target="_blank" rel="noopener" title="BLOCK: web stranice po mjeri i privatni hosting">'
        . '<span>Izradio</span>' . $logo . '<span>block.hr</span></a>';
}

/** HTML upozorenje ako su podaci javno dostupni (ili provjera nije moguća). */
function upozorenjeZastite(array $r): string
{
    if ($r['stanje'] === 'ok') return '';
    $url = Util::esc($r['url']);
    if ($r['stanje'] === 'nepoznato') {
        return '<p class="opis">' . Util::esc($r['poruka']) . " <a href=\"$url\" target=\"_blank\" rel=\"noopener\">Otvori provjeru</a></p>";
    }
    return '<div class="greska"><b>Upozorenje:</b> ' . Util::esc($r['poruka'])
        . '<br><small>Apache: dopusti <code>.htaccess</code> (AllowOverride). nginx: dodaj <code>location ~ /cjenik/(_podaci|_sustav)/ { deny all; }</code>.'
        . " Provjera: <a href=\"$url\" target=\"_blank\" rel=\"noopener\">$url</a></small></div>";
}

/* ---------- Stranice bez prijave: instalacija i prijava ---------- */
function stranica_obrasca(string $naslov, string $sadrzaj): void
{
    header('Content-Type: text/html; charset=utf-8');
    $css = file_get_contents(__DIR__ . '/admin.css');
    $tema = (new Sustav(dirname(__DIR__)))->objavljeno()['tema'] === 'tamna' ? 'tamna' : 'svijetla';
    echo "<!doctype html><html lang=\"hr\" data-tema=\"$tema\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . '<meta name="robots" content="noindex"><title>' . Util::esc($naslov) . ' · ' . Util::NAZIV . '</title>'
        . '<link rel="icon" type="image/png" href="favicon.png">' . "<style>$css</style></head>"
        . '<body class="obrazac-tijelo"><div class="obrazac-okvir"><img class="logo-veliki" src="logo.webp" width="220" height="220" alt="' . Util::NAZIV . '"><main class="obrazac karta"><h1>' . Util::esc($naslov) . "</h1>$sadrzaj</main>"
        . '<footer class="podnozje">' . autor() . '</footer></div></body></html>';
    exit;
}

$csrfPolje = function () use ($auth) {
    return '<input type="hidden" name="csrf" value="' . $auth->csrf() . '">';
};
$poruke = function (array $greske, string $uspjeh = '') {
    $h = '';
    foreach ($greske as $g) if ($g) $h .= '<p class="greska">' . Util::esc($g) . '</p>';
    if ($uspjeh !== '') $h .= '<p class="uspjeh">' . Util::esc($uspjeh) . '</p>';
    return $h;
};
$poljeEmail = function (string $vrijednost = '', bool $fokus = true) {
    return '<label class="polje">E-mail<input type="email" name="email" autocomplete="username" required value="'
        . Util::esc($vrijednost) . '"' . ($fokus ? ' autofocus' : '') . '></label>';
};

/* Instalacija: e-mail (korisničko ime) i lozinka. */
if (!$auth->instalirano()) {
    $greska = '';
    $dozvole = $s->provjeraDozvola();
    $email = (string) ($_POST['email'] ?? '');
    if ($metoda === 'POST' && !$dozvole) {
        $l = (string) ($_POST['lozinka'] ?? '');
        if (!$auth->provjeriCsrf($_POST['csrf'] ?? null)) $greska = 'Istekla sesija, pokušaj ponovno.';
        elseif ($l !== ($_POST['lozinka2'] ?? '')) $greska = 'Lozinke se ne podudaraju.';
        elseif (($greska = $auth->instaliraj($email, $l)) === null) {
            header('Location: ./');
            exit;
        }
    }
    $zastita = $dozvole ? ['stanje' => 'ok'] : Sigurnost::provjeri($s, Auth::adresaIzZahtjeva());
    stranica_obrasca('Instalacija',
        '<p class="korak">Korak 1 od 2</p>' . upozorenjeZastite($zastita)
        . '<p class="opis">Upiši e-mail i lozinku za uređivanje cjenika. E-mail je korisničko ime i na njega stiže poveznica ako zaboraviš lozinku.</p>'
        . $poruke(array_merge($dozvole, [$greska]))
        . '<form method="post">' . $csrfPolje() . $poljeEmail($email)
        . '<label class="polje">Lozinka (barem 10 znakova)<input type="password" name="lozinka" autocomplete="new-password" required minlength="10"></label>'
        . '<label class="polje">Ponovi lozinku<input type="password" name="lozinka2" autocomplete="new-password" required minlength="10"></label>'
        . '<button class="gumb glavni" type="submit"' . ($dozvole ? ' disabled' : '') . '>Spremi i nastavi</button></form>');
}

/* Postavljanje nove lozinke preko poveznice iz e-maila. */
if (isset($_GET['reset']) && !$api) {
    $token = preg_replace('/[^a-f0-9]/', '', (string) $_GET['reset']);
    $greska = '';
    if ($metoda === 'POST') {
        $l = (string) ($_POST['lozinka'] ?? '');
        if (!$auth->provjeriCsrf($_POST['csrf'] ?? null)) $greska = 'Istekla sesija, pokušaj ponovno.';
        elseif ($l !== ($_POST['lozinka2'] ?? '')) $greska = 'Lozinke se ne podudaraju.';
        elseif (($greska = $auth->postaviNovuLozinku($token, $l)) === null) {
            header('Location: ./');
            exit;
        }
    }
    if (!$auth->ispravanReset($token)) {
        stranica_obrasca('Nova lozinka', $poruke(['Poveznica je istekla ili je već iskorištena.'])
            . '<p><a class="gumb" href="./?zaboravljena">Zatraži novu poveznicu</a></p>');
    }
    stranica_obrasca('Nova lozinka', $poruke([$greska])
        . '<form method="post">' . $csrfPolje()
        . '<input type="email" name="email" autocomplete="username" value="' . Util::esc($auth->email()) . '" hidden>'
        . '<label class="polje">Nova lozinka (barem 10 znakova)<input type="password" name="lozinka" autocomplete="new-password" required minlength="10" autofocus></label>'
        . '<label class="polje">Ponovi lozinku<input type="password" name="lozinka2" autocomplete="new-password" required minlength="10"></label>'
        . '<button class="gumb glavni" type="submit">Spremi novu lozinku</button></form>');
}

/* Zaboravljena lozinka: zahtjev za poveznicu. */
if (isset($_GET['zaboravljena']) && !$api) {
    $greska = '';
    $uspjeh = '';
    if ($metoda === 'POST') {
        if (!$auth->provjeriCsrf($_POST['csrf'] ?? null)) $greska = 'Istekla sesija, pokušaj ponovno.';
        elseif (($greska = $auth->zatraziReset((string) ($_POST['email'] ?? ''))) === null) {
            $uspjeh = 'Ako je e-mail ispravan, na njega je poslana poveznica za novu lozinku. Provjeri i neželjenu poštu (spam).';
        }
    }
    stranica_obrasca('Zaboravljena lozinka', $poruke([$greska], $uspjeh)
        . ($uspjeh ? '' : '<p class="opis">Upiši e-mail s kojim se prijavljuješ. Poslat ćemo poveznicu za novu lozinku.</p>'
            . '<form method="post">' . $csrfPolje() . $poljeEmail((string) ($_POST['email'] ?? ''))
            . '<button class="gumb glavni" type="submit">Pošalji poveznicu</button></form>')
        . '<p class="donja-poveznica"><a href="./">← Natrag na prijavu</a></p>');
}

/* Prijava. */
if (!$auth->prijavljen()) {
    if ($api) json_odgovor(401, ['greska' => 'Prijava je istekla. Osvježi stranicu.']);
    $greska = '';
    $email = (string) ($_POST['email'] ?? '');
    $imaEmail = $auth->email() !== '';
    if ($metoda === 'POST') {
        if (!$auth->provjeriCsrf($_POST['csrf'] ?? null)) $greska = 'Istekla sesija, pokušaj ponovno.';
        elseif (($greska = $auth->prijava($email, (string) ($_POST['lozinka'] ?? ''))) === null) {
            header('Location: ./');
            exit;
        }
    }
    stranica_obrasca('Prijava', $poruke([$greska])
        . '<form method="post">' . $csrfPolje() . ($imaEmail ? $poljeEmail($email) : '')
        . '<label class="polje">Lozinka<input type="password" name="lozinka" autocomplete="current-password" required' . ($imaEmail ? '' : ' autofocus') . '></label>'
        . '<button class="gumb glavni" type="submit">Prijava</button></form>'
        . ($imaEmail ? '<p class="donja-poveznica"><a href="./?zaboravljena">Zaboravljena lozinka?</a></p>' : ''));
}

/* ---------- Korak 2 instalacije: slanje e-maila ---------- */
if (!$api && !empty($s->postavke()['korakPosta'])) {
    $greska = '';
    $uspjeh = '';
    $unos = $metoda === 'POST' ? $_POST : podaciPoste($s);
    if ($metoda === 'POST') {
        if (!$auth->provjeriCsrf($_POST['csrf'] ?? null)) {
            $greska = 'Istekla sesija, pokušaj ponovno.';
        } elseif (isset($_POST['preskoci']) || isset($_POST['nastavi'])) {
            $post = $s->postavke();
            unset($post['korakPosta']);
            $s->spremiPostavke($post);
            header('Location: ./');
            exit;
        } elseif (trim((string) ($_POST['host'] ?? '')) === '') {
            $greska = 'Upiši poslužitelj (npr. mail.' . Posta::domena($s->postavke()) . ') ili klikni Preskoči.';
        } elseif (($greska = spremiSmtp($s, $_POST)) === null && ($greska = testniMail($s)) === null) {
            $uspjeh = 'Testni e-mail je poslan na ' . $auth->email() . '. Provjeri je li stigao (i u neželjenoj pošti).';
        }
    }
    $pp = podaciPoste($s);
    $v = function ($k) use ($unos) { return Util::esc((string) ($unos[$k] ?? '')); };
    $sif = (string) ($unos['sifriranje'] ?? 'ssl');
    $opcija = function ($vr, $tekst) use ($sif) { return "<option value=\"$vr\"" . ($sif === $vr ? ' selected' : '') . ">$tekst</option>"; };
    $domena = Util::esc($pp['domena']);

    $status = $pp['mail'] === 'ne'
        ? '<p class="greska"><b>Ovaj hosting ne podržava slanje preko PHP mail().</b> Dok ne upišeš SMTP podatke, slanje e-maila neće raditi, pa ni poveznica za zaboravljenu lozinku.</p>'
        : '<p class="uspjeh">Hosting podržava slanje preko PHP mail(), pa će slanje raditi i bez SMTP-a. Takvi mailovi ipak često završe u neželjenoj pošti, pa je SMTP pouzdaniji.</p>';

    if ($uspjeh) {
        stranica_obrasca('Slanje e-maila radi', '<p class="korak">Korak 2 od 2</p><p class="uspjeh">' . Util::esc($uspjeh) . '</p>'
            . '<form method="post">' . $csrfPolje() . '<button class="gumb glavni" name="nastavi" value="1">Nastavi na cjenik</button></form>'
            . '<p class="donja-poveznica">Nije stigao? Podatke možeš promijeniti u <i>Postavke → Slanje e-maila</i>.</p>');
    }

    stranica_obrasca('Slanje e-maila', '<p class="korak">Korak 2 od 2</p>'
        . '<p class="opis">Plugin šalje e-mail samo kad netko zaboravi lozinku: na <b>' . Util::esc($auth->email()) . '</b> stiže poveznica za novu. '
        . 'Za pouzdano slanje upiši SMTP podatke nekog e-mail sandučića, npr. <i>noreply@' . $domena . '</i>.</p>'
        . $status . $poruke([$greska])
        . '<details class="upute"><summary>Gdje naći SMTP podatke?</summary>'
        . '<p><b>E-mail na hostingu (cPanel, Plesk…)</b>: poslužitelj je obično <code>mail.' . $domena . '</code>, port 465 sa SSL-om. Korisničko ime je cijela e-mail adresa, a lozinka je lozinka tog sandučića. U cPanelu: <i>Email Accounts → Connect Devices</i>.</p>'
        . '<p><b>Gmail / Google Workspace</b>: <code>smtp.gmail.com</code>, port 587, TLS. Umjesto obične lozinke treba <i>lozinka aplikacije</i> (Google račun → Sigurnost → Potvrda u 2 koraka → Lozinke aplikacija).</p>'
        . '<p><b>Microsoft 365 / Outlook</b>: <code>smtp.office365.com</code>, port 587, TLS. Administrator mora za taj sandučić dopustiti SMTP AUTH.</p>'
        . '</details>'
        . '<div class="brzi" id="brzi"><span>Brzi odabir:</span>'
        . '<button type="button" class="gumb mali" data-host="mail.' . $domena . '" data-port="465" data-sif="ssl">Hosting</button>'
        . '<button type="button" class="gumb mali" data-host="smtp.gmail.com" data-port="587" data-sif="tls">Gmail</button>'
        . '<button type="button" class="gumb mali" data-host="smtp.office365.com" data-port="587" data-sif="tls">Microsoft 365</button></div>'
        . '<form method="post" id="smtp">' . $csrfPolje()
        . '<label class="polje">SMTP poslužitelj<input type="text" name="host" value="' . $v('host') . '" placeholder="mail.' . $domena . '" autocomplete="off"></label>'
        . '<div class="dva"><label class="polje">Port<input type="number" name="port" value="' . $v('port') . '" placeholder="465"></label>'
        . '<label class="polje">Šifriranje<select name="sifriranje">' . $opcija('ssl', 'SSL (465)') . $opcija('tls', 'STARTTLS (587)') . $opcija('nema', 'Bez šifriranja') . '</select></label></div>'
        . '<label class="polje">Korisničko ime (obično cijela e-mail adresa)<input type="text" name="korisnik" value="' . $v('korisnik') . '" placeholder="noreply@' . $domena . '" autocomplete="off"></label>'
        . '<label class="polje">Lozinka sandučića<input type="password" name="lozinka" autocomplete="new-password"' . ($pp['imaLozinku'] ? ' placeholder="spremljena — upiši samo za promjenu"' : '') . '></label>'
        . '<label class="polje">Adresa pošiljatelja (neobavezno, zadano je korisničko ime)<input type="email" name="posiljatelj" value="' . $v('posiljatelj') . '"></label>'
        . '<button class="gumb glavni" type="submit">Spremi i pošalji testni e-mail</button>'
        . '<button class="gumb" type="submit" name="preskoci" value="1" formnovalidate style="margin-top:8px">'
        . ($pp['mail'] === 'ne' ? 'Preskoči (reset lozinke neće raditi)' : 'Preskoči i koristi PHP mail()') . '</button>'
        . '</form>'
        . "<script>document.getElementById('brzi').addEventListener('click',function(e){var b=e.target.closest('[data-host]');if(!b)return;var f=document.getElementById('smtp');f.host.value=b.dataset.host;f.port.value=b.dataset.port;f.sifriranje.value=b.dataset.sif;f.korisnik.focus();});</script>");
}

/* ---------- API (prijavljen korisnik) ---------- */
function stanje(Sustav $s): array
{
    $p = $s->nacrt();
    $objava = new Objava($s);
    $sada = Util::datum();
    $podjela = Arhiva::podijeli($p['objave'], $sada, (int) $p['danaArhive']);
    $zaBrisanje = array_column($podjela['obrisi'], 'broj');
    $post = $s->postavke();
    return [
        'podaci' => $p,
        'promjene' => $objava->imaPromjena($p),
        'greske' => Podaci::provjeri(Podaci::normaliziraj($p)),
        'arhiva' => array_reverse(array_map(function ($o) use ($sada, $zaBrisanje) {
            return ['broj' => $o['broj'], 'datum' => $o['datum'], 'datoteke' => $o['datoteke'],
                'starost' => Arhiva::starost($o, $sada), 'brisanje' => in_array($o['broj'], $zaBrisanje, true)];
        }, $p['objave'])),
        'sustav' => [
            'verzija' => $s->verzija(),
            'automatskoAzuriranje' => !empty($post['automatskoAzuriranje']),
            'kljucAzuriranja' => $post['kljucAzuriranja'] ?? '',
            'email' => $post['email'] ?? '',
            'posta' => podaciPoste($s),
            'php' => PHP_VERSION,
        ],
    ];
}

if ($api) {
    if ($metoda !== 'GET' && !$auth->provjeriCsrf($_SERVER['HTTP_X_CSRF'] ?? null)) {
        json_odgovor(403, ['greska' => 'Istekla sesija. Osvježi stranicu.']);
    }
    try {
        switch ($api) {
            case 'stanje':
                json_odgovor(200, stanje($s));

            case 'nacrt':
                $s->spremiNacrt(tijelo());
                json_odgovor(200, stanje($s));

            case 'uvoz':
                if (empty($_FILES['datoteka']) || !is_array($_FILES['datoteka'])) {
                    json_odgovor(422, ['greska' => 'Odaberi .xlsx ili .csv datoteku.']);
                }
                $f = $_FILES['datoteka'];
                $kod = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($kod !== UPLOAD_ERR_OK) {
                    $poruke = [UPLOAD_ERR_INI_SIZE => 'Datoteka je veća od ograničenja poslužitelja.', UPLOAD_ERR_FORM_SIZE => 'Datoteka je prevelika.',
                        UPLOAD_ERR_PARTIAL => 'Datoteka je prenesena samo djelomično.', UPLOAD_ERR_NO_FILE => 'Datoteka nije odabrana.'];
                    json_odgovor(422, ['greska' => $poruke[$kod] ?? 'Upload datoteke nije uspio.']);
                }
                if ((int) ($f['size'] ?? 0) > Uvoz::MAKS_BAJTOVA) json_odgovor(413, ['greska' => 'Datoteka je veća od dopuštenih 2 MB.']);
                $privremena = (string) ($f['tmp_name'] ?? '');
                if (!is_uploaded_file($privremena)) json_odgovor(422, ['greska' => 'Upload datoteke nije moguće potvrditi.']);
                try {
                    $rezultat = Uvoz::datoteka($privremena, basename((string) $f['name']), $s->nacrt()['stavke']);
                } catch (\RuntimeException $e) {
                    json_odgovor(422, ['greska' => $e->getMessage()]);
                }
                json_odgovor(200, $rezultat);

            case 'pregled':
                $p = $s->objavljeno();
                $b = tijelo();
                foreach (Sustav::UREDIVO as $k) if (array_key_exists($k, $b)) $p[$k] = $b[$k];
                $p = Podaci::normaliziraj($p);
                $p['stavke'] = array_values(array_filter($p['stavke'], function ($x) {
                    return $x['naziv'] !== '' && is_float($x['cijena']) && !is_nan($x['cijena'])
                        && is_float($x['sidrenaCijena']) && !is_nan($x['sidrenaCijena']);
                }));
                json_odgovor(200, ['html' => '<style>' . Prikaz::CSS . '</style>' . Prikaz::tablica($p)]);

            case 'objavi':
                $p = $s->objavljeno();
                $b = tijelo();
                foreach (Sustav::UREDIVO as $k) if (array_key_exists($k, $b)) $p[$k] = $b[$k];
                try {
                    $r = (new Objava($s))->objavi($p);
                } catch (GreskaProvjere $e) {
                    $s->spremiNacrt($b);
                    json_odgovor(422, ['greske' => $e->greske]);
                }
                $s->obrisiNacrt();
                json_odgovor(200, stanje($s) + ['rezultat' => [
                    'nova' => $r['nova'], 'obrisano' => $r['obrisano'], 'umetnuto' => $r['umetnuto'], 'upozorenja' => $r['upozorenja'],
                ]]);

            case 'postavke':
                $b = tijelo();
                $r = $s->zakljucano(function () use ($s, $b) {
                    $p = $s->objavljeno();
                    if (isset($b['umetni'])) {
                        $p['umetni'] = array_values(array_unique(array_filter(array_map(function ($x) {
                            return ltrim(trim(str_replace('\\', '/', (string) $x)), '/');
                        }, (array) $b['umetni']), 'strlen')));
                    }
                    if (isset($b['separatorVremena']) && in_array($b['separatorVremena'], [':', '-', '.'], true)) $p['separatorVremena'] = $b['separatorVremena'];
                    if (isset($b['danaArhive'])) $p['danaArhive'] = max(30, min(365, (int) $b['danaArhive']));
                    Podaci::pisiJson($s->datotekaPodataka(), $p);
                    $post = $s->postavke();
                    if (isset($b['automatskoAzuriranje'])) $post['automatskoAzuriranje'] = (bool) $b['automatskoAzuriranje'];
                    if (!empty($b['noviKljuc'])) $post['kljucAzuriranja'] = bin2hex(random_bytes(20));
                    $s->spremiPostavke($post);
                    $upozorenja = [];
                    foreach ($p['umetni'] as $rel) {
                        if ($s->datotekaStranice($rel) === null) $upozorenja[] = "Ne postoji ili nije dopuštena datoteka: $rel";
                    }
                    return $upozorenja;
                });
                $p = $s->objavljeno();
                $gen = $p['objave'] ? (new Objava($s))->generiraj(Podaci::normaliziraj($p)) : ['upozorenja' => []];
                json_odgovor(200, stanje($s) + ['upozorenja' => array_values(array_unique(array_merge($r, $gen['upozorenja'])))]);

            case 'lozinka':
                $b = tijelo();
                $g = $auth->promijeniLozinku((string) ($b['stara'] ?? ''), (string) ($b['nova'] ?? ''));
                json_odgovor($g ? 422 : 200, $g ? ['greska' => $g] : ['ok' => true]);

            case 'posta':
                $g = spremiSmtp($s, tijelo());
                json_odgovor($g ? 422 : 200, $g ? ['greska' => $g] : stanje($s));

            case 'posta-test':
                $g = testniMail($s);
                json_odgovor($g ? 422 : 200, $g ? ['greska' => $g] : ['ok' => true, 'prima' => $auth->email()]);

            case 'tema':
                $tema = (tijelo()['tema'] ?? '') === 'tamna' ? 'tamna' : 'svijetla';
                $s->zakljucano(function () use ($s, $tema) {
                    $p = $s->objavljeno();
                    $p['tema'] = $tema;
                    Podaci::pisiJson($s->datotekaPodataka(), $p);
                });
                // Javna stranica cjenika odmah dobiva novu temu (XML se ne mijenja).
                $p = $s->objavljeno();
                if ($p['objave']) (new Objava($s))->generiraj(Podaci::normaliziraj($p));
                json_odgovor(200, ['tema' => $tema]);

            case 'sigurnost':
                json_odgovor(200, Sigurnost::provjeri($s, (string) ($s->postavke()['adresa'] ?? Auth::adresaIzZahtjeva())));

            case 'email':
                $b = tijelo();
                $g = $auth->promijeniEmail((string) ($b['lozinka'] ?? ''), (string) ($b['email'] ?? ''));
                json_odgovor($g ? 422 : 200, $g ? ['greska' => $g] : stanje($s));

            case 'odjava':
                $auth->odjava();
                json_odgovor(200, ['ok' => true]);

            case 'azuriranje':
                // Provjera nove verzije; uz uključeno automatsko ažuriranje odmah i instalira.
                $az = new Azuriranje($s);
                $info = $az->provjeri(($_GET['prisilno'] ?? '') === '1');
                if ($info['dostupna'] && !empty($s->postavke()['automatskoAzuriranje']) && $metoda === 'POST') {
                    $info['instalirano'] = $az->instaliraj();
                }
                json_odgovor(200, $info);

            case 'azuriraj':
                $prije = $s->verzija();
                $nova = (new Azuriranje($s))->instaliraj();
                json_odgovor(200, ['prije' => $prije, 'instalirano' => $nova]);

            default:
                json_odgovor(404, ['greska' => 'Nepoznata radnja']);
        }
    } catch (\Throwable $e) {
        json_odgovor(500, ['greska' => $e->getMessage()]);
    }
}

/* ---------- Stranica za uređivanje ---------- */
osvjeziNakonAzuriranja($s);
(new Objava($s))->pocisti();

header('Content-Type: text/html; charset=utf-8');
echo strtr(file_get_contents(__DIR__ . '/admin.html'), [
    '{{CSS}}' => file_get_contents(__DIR__ . '/admin.css'),
    '{{CSRF}}' => $auth->csrf(),
    '{{VERZIJA}}' => Util::esc($s->verzija()),
    '{{AUTOR}}' => autor(),
    '{{TEMA}}' => $s->objavljeno()['tema'] === 'tamna' ? 'tamna' : 'svijetla',
]);
