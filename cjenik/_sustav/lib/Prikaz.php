<?php
// HTML prikaz cjenika: samostalna stranica, fragment tablice i umetanje u postojeće stranice.

namespace Cjenik;

final class Prikaz
{
    const OZNAKA_POCETAK = '<!-- cjenik:pocetak -->';
    const OZNAKA_KRAJ = '<!-- cjenik:kraj -->';

    /** Stil tablice. Boje se nasljeđuju od stranice, a mogu se prilagoditi varijablama --cjenik-*. */
    const CSS = '
.cjenik{--cjenik-akcent:#b4232a;--cjenik-rub:rgba(128,128,128,.28);--cjenik-blago:rgba(128,128,128,.75);--cjenik-pozadina-zaglavlja:rgba(128,128,128,.08);font-variant-numeric:tabular-nums;color:inherit;max-width:100%}
.cjenik *{box-sizing:border-box}
.cjenik-tablica{width:100%;border-collapse:collapse;font-size:1rem;line-height:1.4}
.cjenik-tablica .cjenik-kategorija th{font-size:1.02em;font-weight:700;padding:1.3em .75em .45em;border-bottom:1px solid var(--cjenik-rub)}
.cjenik-tablica tbody:first-of-type .cjenik-kategorija th{padding-top:.9em}
.cjenik-tablica th,.cjenik-tablica td{padding:.7em .75em;border-bottom:1px solid var(--cjenik-rub);vertical-align:top;text-align:left}
.cjenik-tablica thead th{font-size:.78em;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--cjenik-blago);background:var(--cjenik-pozadina-zaglavlja);border-bottom-width:2px}
.cjenik-tablica .cjenik-iznos{text-align:right;white-space:nowrap;width:1%}
.cjenik-tablica tbody:last-child tr:last-child td{border-bottom:0}
.cjenik-tablica .cjenik-iznos .cjenik-mala{white-space:normal;min-width:11em}
.cjenik-naziv{font-weight:500}
.cjenik-jedinica,.cjenik-mala{display:block;font-size:.82em;color:var(--cjenik-blago);font-weight:400}
.cjenik-cijena{font-weight:700}
.cjenik-akcija .cjenik-cijena{color:var(--cjenik-akcent)}
.cjenik-oznaka{display:inline-block;margin-top:.3em;padding:.1em .5em;border-radius:999px;font-size:.72em;font-weight:600;background:var(--cjenik-akcent);color:#fff;white-space:nowrap}
.cjenik-podnozje{margin-top:.9em;font-size:.85em;color:var(--cjenik-blago)}
.cjenik-podnozje a{color:inherit}
@media (max-width:560px){
.cjenik-tablica,.cjenik-tablica tbody{display:block}
.cjenik-tablica thead{display:none}
.cjenik-tablica tr{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;padding:.85em .25em;border-bottom:1px solid var(--cjenik-rub)}
.cjenik-tablica tbody:last-child tr:last-child{border-bottom:0}
.cjenik-tablica td,.cjenik-tablica th{display:block;padding:0!important;border:0!important;width:auto}
.cjenik-tablica td:first-child{grid-column:1/-1}
.cjenik-tablica td.cjenik-iznos{text-align:left;white-space:normal;width:auto}
.cjenik-tablica td.cjenik-iznos+td.cjenik-iznos{text-align:right}
.cjenik-tablica td.cjenik-iznos::before{content:attr(data-oznaka);display:block;font-size:.7em;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--cjenik-blago);margin-bottom:1px}
.cjenik-tablica .cjenik-iznos .cjenik-mala{min-width:0}
.cjenik-tablica tr.cjenik-kategorija{display:block;padding:1.3em .25em .4em}
}
';

    /** Najčešći datum bazne cijene — ide u zaglavlje stupca; iznimke se označe u retku. */
    private static function glavniDatum(array $stavke): string
    {
        $broj = [];
        foreach ($stavke as $s) $broj[$s['datumSidrenja']] = ($broj[$s['datumSidrenja']] ?? 0) + 1;
        if (!$broj) return Util::DATUM_SIDRENJA;
        arsort($broj);
        return (string) array_key_first($broj);
    }

    private static function redak(array $s, string $datumZaglavlja): string
    {
        $a = $s['akcija'];
        $cijena = '<span class="cjenik-cijena">' . Util::esc(Util::novac($s['cijena'])) . '</span>'
            . ($a['aktivna'] ? '<br><span class="cjenik-oznaka">' . Util::esc($a['naziv']) . '</span>' : '')
            . ($a['aktivna'] && $a['najniza30'] !== null
                ? '<span class="cjenik-mala">Najniža cijena u zadnjih 30 dana: ' . Util::esc(Util::novac($a['najniza30'])) . '</span>' : '');
        $sidrena = '<span class="cjenik-sidrena">' . Util::esc(Util::novac($s['sidrenaCijena'])) . '</span>'
            . ($s['datumSidrenja'] !== $datumZaglavlja
                ? '<span class="cjenik-mala">cijena na ' . Util::esc(Util::datumKratko($s['datumSidrenja'])) . '</span>' : '');
        return '<tr' . ($a['aktivna'] ? ' class="cjenik-akcija"' : '') . '>'
            . '<td><span class="cjenik-naziv">' . Util::esc($s['naziv']) . '</span>'
            . ($s['jedinica'] !== '' ? '<span class="cjenik-jedinica">' . Util::esc($s['jedinica']) . '</span>' : '') . '</td>'
            . '<td class="cjenik-iznos" data-oznaka="Cijena">' . $cijena . '</td>'
            . '<td class="cjenik-iznos" data-oznaka="Cijena na ' . Util::esc(Util::datumKratko($datumZaglavlja)) . '">' . $sidrena . '</td></tr>';
    }

    /** Tablica cijena (bez <style>). $opcije: datum, xmlPoveznica */
    public static function tablica(array $p, array $opcije = []): string
    {
        $datumZaglavlja = self::glavniDatum($p['stavke']);
        $zaglavlje = '<thead><tr><th scope="col">Usluga</th><th scope="col" class="cjenik-iznos">Cijena</th>'
            . '<th scope="col" class="cjenik-iznos">Cijena na ' . Util::esc(Util::datumKratko($datumZaglavlja)) . '</th></tr></thead>';

        $grupe = [];
        foreach ($p['stavke'] as $s) $grupe[$s['kategorija']][] = $s;
        $tijela = '';
        foreach ($grupe as $kategorija => $stavke) {
            $tijela .= '<tbody>'
                . ($kategorija !== '' ? '<tr class="cjenik-kategorija"><th colspan="3" scope="colgroup">' . Util::esc($kategorija) . '</th></tr>' : '')
                . implode('', array_map(function ($s) use ($datumZaglavlja) { return self::redak($s, $datumZaglavlja); }, $stavke))
                . '</tbody>';
        }

        $podnozje = [];
        if ($p['napomena'] !== '') $podnozje[] = Util::esc($p['napomena']);
        if (!empty($opcije['datum'])) $podnozje[] = 'Cjenik vrijedi od ' . Util::esc(Util::datumVrijemePrikaz($opcije['datum'])) . '.';
        if (!empty($opcije['xmlPoveznica'])) $podnozje[] = '<a href="' . Util::esc($opcije['xmlPoveznica']) . '">Strojno čitljivi cjenik (XML)</a>';

        return "<div class=\"cjenik\">\n<table class=\"cjenik-tablica\">$zaglavlje$tijela</table>\n"
            . ($podnozje ? '<p class="cjenik-podnozje">' . implode(' ', $podnozje) . "</p>\n" : '')
            . '</div>';
    }

    public static function blokZaUmetanje(array $p, array $opcije): string
    {
        return self::OZNAKA_POCETAK . "\n<style>" . self::CSS . "</style>\n" . self::tablica($p, $opcije) . "\n" . self::OZNAKA_KRAJ;
    }

    /** Zamijeni sadržaj između oznaka. Vraća null ako oznake ne postoje. */
    public static function umetni(string $html, string $blok): ?string
    {
        $p = strpos($html, self::OZNAKA_POCETAK);
        if ($p === false) return null;
        $k = strpos($html, self::OZNAKA_KRAJ, $p);
        if ($k === false) return null;
        return substr($html, 0, $p) . $blok . substr($html, $k + strlen(self::OZNAKA_KRAJ));
    }

    private static function poveznica(string $datoteka): string
    {
        return './' . implode('/', array_map('rawurlencode', explode('/', $datoteka)));
    }

    /** Samostalna javna stranica cjenika. $arhiva: zadržane objave, najnovija zadnja. */
    public static function stranica(array $p, array $arhiva): string
    {
        $aktualna = end($arhiva) ?: null;
        $redoviArhive = '';
        foreach (array_reverse($arhiva) as $i => $o) {
            $linkovi = implode('', array_map(function ($d) {
                return '<a class="datoteka" href="' . Util::esc(self::poveznica('arhiva/' . $d)) . '" download title="' . Util::esc($d) . '">'
                    . strtoupper(pathinfo($d, PATHINFO_EXTENSION)) . '</a>';
            }, $o['datoteke']));
            $redoviArhive .= '<li' . ($i === 0 ? ' class="vazeci"' : '') . '><div><span class="kada">' . Util::esc(Util::datumVrijemePrikaz($o['datum'])) . '</span>'
                . '<span class="br">' . ($i === 0 ? 'Važeći · ' : '') . 'br. ' . (int) $o['broj'] . '</span></div>'
                . '<div class="linkovi">' . $linkovi . "</div></li>\n";
        }
        $stalne = implode('', array_map(function ($f) {
            return "<a class=\"gumb\" href=\"./cjenik.$f\" download>Preuzmi " . strtoupper($f) . '</a>';
        }, $p['formati']));

        $naslov = Util::esc($p['naslovStranice']);
        $naziv = Util::esc($p['obveznik']['naziv']);
        $adresa = !empty($p['objekt']['svaMjesta']) ? 'Sva prodajna mjesta' : Util::esc($p['objekt']['adresa']);
        $oib = $p['obveznik']['oib'] !== '' ? ' · ' . (strlen($p['obveznik']['oib']) === 11 ? 'OIB ' : 'MB ') . Util::esc($p['obveznik']['oib']) : '';
        $vrijedi = $aktualna ? '<div class="vrijedi"><span>Ažurirano</span><strong>' . Util::esc(Util::datumVrijemePrikaz($aktualna['datum'])) . '</strong></div>' : '';
        $tablica = self::tablica($p);
        $dana = (int) $p['danaArhive'];
        $css = self::CSS;
        $tema = ($p['tema'] ?? '') === 'tamna' ? 'tamna' : 'svijetla';

        return <<<HTML
<!doctype html>
<html lang="hr" data-tema="$tema">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$naslov – $naziv</title>
<meta name="description" content="$naslov: $naziv, $adresa">
<link rel="alternate" type="application/xml" href="./cjenik.xml" title="Strojno čitljivi cjenik">
<style>
:root{color-scheme:light;--pozadina:#f5f4f0;--povrsina:#fff;--tekst:#191a1c;--blago:#686660;--rub:#e5e2db;--rub-jak:#d3cfc6;--akcent:#1f3a5f;--akcent-blago:#e8eef6;--sjena:0 1px 2px rgba(20,20,20,.04),0 8px 28px rgba(20,20,20,.06)}
html[data-tema="tamna"]{color-scheme:dark;--pozadina:#121212;--povrsina:#1b1b1a;--tekst:#eeece6;--blago:#a19d94;--rub:#2f2d2a;--rub-jak:#45423c;--akcent:#9cb8de;--akcent-blago:#1d2733;--sjena:none}
html[data-tema="tamna"] body .cjenik{--cjenik-akcent:#e06058}
*{box-sizing:border-box}
body{margin:0;background:var(--pozadina);color:var(--tekst);font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
.okvir{max-width:1200px;margin:0 auto;padding:0 24px}
.zaglavlje{padding-top:56px;padding-bottom:32px;display:flex;justify-content:space-between;align-items:flex-end;gap:16px 32px;flex-wrap:wrap}
.vrijedi{display:grid;gap:2px;text-align:right;padding:12px 18px;border-radius:14px;background:var(--akcent-blago);color:var(--akcent)}
.vrijedi span{font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;opacity:.8}
.vrijedi strong{font-size:1.05rem}
.zaglavlje h1{font-size:clamp(2rem,5vw,3rem);line-height:1.1;margin:0 0 14px;letter-spacing:-.025em;font-weight:750}
.obrt{margin:.15em 0;color:var(--blago);font-size:1.05rem}
.obrt strong{font-weight:700}
.znacka{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;background:var(--akcent-blago);color:var(--akcent);font-size:.85rem;font-weight:600}
.raspored{display:grid;gap:24px;padding-bottom:64px}
.karta{background:var(--povrsina);border:1px solid var(--rub);border-radius:18px;box-shadow:var(--sjena)}
.glavna{padding:12px 32px 28px}
.strana{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;align-items:stretch}
.strana .karta{padding:22px 22px 18px}
.strana h2{font-size:1rem;margin:0 0 6px;letter-spacing:-.01em}
.opis{color:var(--blago);font-size:.9rem;margin:0 0 14px}
.gumbi{display:flex;flex-wrap:wrap;gap:8px}
.gumb{display:inline-flex;align-items:center;padding:9px 16px;border-radius:10px;background:var(--akcent);color:#fff;text-decoration:none;font-weight:600;font-size:.9rem}
html[data-tema="tamna"] .gumb{color:#10161f}
.gumb:hover{filter:brightness(1.08)}
.arhiva{list-style:none;margin:0;padding:0;font-size:.9rem;max-height:420px;overflow:auto}
.arhiva li{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--rub)}
.arhiva .kada{display:block;font-weight:500}
.arhiva .br{display:block;color:var(--blago);font-size:.8rem}
.arhiva .vazeci .br{color:var(--akcent);font-weight:600}
.linkovi{display:flex;gap:6px;flex-shrink:0}
.datoteka{font-size:.72rem;font-weight:700;letter-spacing:.04em;padding:4px 8px;border:1px solid var(--rub-jak);border-radius:6px;color:inherit;text-decoration:none}
.datoteka:hover{border-color:var(--tekst)}
.cjenik{--cjenik-rub:var(--rub);--cjenik-blago:var(--blago);--cjenik-pozadina-zaglavlja:transparent}
$css
/* Javna stranica: veća, prozračnija tablica (umetnuta tablica na drugim stranicama ostaje kakva jest). */
.glavna .cjenik-tablica{font-size:1.02rem}
.glavna .cjenik-tablica thead th{background:none;padding-top:1.1em;padding-bottom:.8em;border-bottom:1px solid var(--rub-jak)}
.glavna .cjenik-tablica th,.glavna .cjenik-tablica td{padding:.95em 1em}
.glavna .cjenik-tablica .cjenik-kategorija th{padding:1.6em 1em .55em;font-size:1.05rem;font-weight:750;text-transform:uppercase;letter-spacing:.015em;color:var(--akcent);border-bottom:2px solid var(--akcent)}
.glavna .cjenik-tablica tbody tr:not(.cjenik-kategorija):hover td{background:color-mix(in srgb,var(--akcent-blago) 55%,transparent)}
.glavna .cjenik-tablica .cjenik-cijena{font-size:1.08em}
.glavna .cjenik-podnozje{margin:18px 1em 0}
@media (max-width:760px){
  .strana{grid-template-columns:1fr}
}
@media (max-width:640px){
  .okvir{padding:0 12px}
  .zaglavlje{padding-top:32px;padding-bottom:20px;align-items:flex-start}
  .vrijedi{text-align:left;padding:10px 14px}
  .glavna{padding:4px 16px 16px;border-radius:14px}
}
@media (max-width:560px){
  .glavna .cjenik-tablica tr{padding:1em 0}
  .glavna .cjenik-tablica tr.cjenik-kategorija{padding:1.6em 0 0;border-bottom:0}
  .glavna .cjenik-tablica .cjenik-kategorija th{padding-bottom:.5em!important;border-bottom:2px solid var(--akcent)!important}
  .glavna .cjenik-tablica tbody tr:not(.cjenik-kategorija):hover td{background:none}
  .glavna .cjenik-podnozje{margin:14px 0 0}
}
</style>
</head>
<body>
<header class="zaglavlje okvir">
<div>
<h1>$naslov</h1>
<p class="obrt"><strong>$naziv</strong>$oib</p>
<p class="obrt">$adresa</p>
</div>
$vrijedi
</header>
<div class="raspored okvir">
<main class="karta glavna">
$tablica
</main>
<aside class="strana">
<section class="karta">
<h2>Strojno čitljivi cjenik</h2>
<p class="opis">Važeći cjenik je uvijek na istoj adresi, u obliku pogodnom za automatsku obradu.</p>
<div class="gumbi">$stalne</div>
</section>
<section class="karta">
<h2>Arhiva cjenika</h2>
<p class="opis">Prethodni cjenik ostaje dostupan $dana dana nakon što ga zamijeni novi.</p>
<ul class="arhiva">
$redoviArhive</ul>
</section>
</aside>
</div>
</body>
</html>

HTML;
    }
}
