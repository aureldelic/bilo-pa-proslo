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

/* ---------- Stranice bez prijave: instalacija i prijava ---------- */
function stranica_obrasca(string $naslov, string $sadrzaj): void
{
    header('Content-Type: text/html; charset=utf-8');
    $css = file_get_contents(__DIR__ . '/admin.css');
    echo "<!doctype html><html lang=\"hr\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . '<meta name="robots" content="noindex"><title>' . Util::esc($naslov) . "</title><style>$css</style></head>"
        . '<body class="obrazac-tijelo"><main class="obrazac karta"><h1>' . Util::esc($naslov) . "</h1>$sadrzaj</main></body></html>";
    exit;
}

if (!$auth->instalirano()) {
    $greska = '';
    $dozvole = $s->provjeraDozvola();
    if ($metoda === 'POST' && !$dozvole) {
        $l = (string) ($_POST['lozinka'] ?? '');
        if (!$auth->provjeriCsrf($_POST['csrf'] ?? null)) $greska = 'Istekla sesija, pokušaj ponovno.';
        elseif (strlen($l) < 10) $greska = 'Lozinka mora imati barem 10 znakova.';
        elseif ($l !== ($_POST['lozinka2'] ?? '')) $greska = 'Lozinke se ne podudaraju.';
        else {
            $auth->instaliraj($l);
            header('Location: ./');
            exit;
        }
    }
    $html = '<p class="opis">Postavi lozinku za uređivanje cjenika. Nakon toga samo osoba s lozinkom može mijenjati cijene.</p>';
    foreach ($dozvole as $d) $html .= '<p class="greska">' . Util::esc($d) . '</p>';
    if ($greska) $html .= '<p class="greska">' . Util::esc($greska) . '</p>';
    $html .= '<form method="post"><input type="hidden" name="csrf" value="' . $auth->csrf() . '">'
        . '<label class="polje">Nova lozinka (barem 10 znakova)<input type="password" name="lozinka" autocomplete="new-password" required minlength="10" autofocus></label>'
        . '<label class="polje">Ponovi lozinku<input type="password" name="lozinka2" autocomplete="new-password" required minlength="10"></label>'
        . '<button class="gumb glavni" type="submit"' . ($dozvole ? ' disabled' : '') . '>Postavi lozinku</button></form>';
    stranica_obrasca('Instalacija cjenika', $html);
}

if (!$auth->prijavljen()) {
    if ($api) json_odgovor(401, ['greska' => 'Prijava je istekla. Osvježi stranicu.']);
    $greska = '';
    if ($metoda === 'POST') {
        if (!$auth->provjeriCsrf($_POST['csrf'] ?? null)) $greska = 'Istekla sesija, pokušaj ponovno.';
        else {
            $greska = $auth->prijava((string) ($_POST['lozinka'] ?? ''));
            if ($greska === null) {
                header('Location: ./');
                exit;
            }
        }
    }
    $html = ($greska ? '<p class="greska">' . Util::esc($greska) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . $auth->csrf() . '">'
        . '<label class="polje">Lozinka<input type="password" name="lozinka" autocomplete="current-password" required autofocus></label>'
        . '<button class="gumb glavni" type="submit">Prijava</button></form>';
    stranica_obrasca('Cjenik: prijava', $html);
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
]);
