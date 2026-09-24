<?php
// Siguran uvoz standardiziranog CSV/XLSX cjenika u nacrt.
// Datoteka se čita iz PHP-ove privremene upload putanje i nikad se ne kopira u web mapu.

namespace Cjenik;

final class Uvoz
{
    const MAKS_BAJTOVA = 2097152; // 2 MiB
    const MAKS_REDOVA = 2000;
    const MAKS_ZIP_STAVKI = 1000;
    const MAKS_RASPAKIRANO = 10485760; // 10 MiB

    const ZAGLAVLJA = [
        'naziv_usluge', 'kategorija', 'jedinica_mjere', 'maloprodajna_cijena',
        'poseban_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'najniza_cijena_30_dana',
        'dodatna_cijena', 'datum_dodatne_cijene', 'sifra',
    ];

    /** @return array{stavke:array,upozorenja:array,redaka:int} */
    public static function datoteka(string $putanja, string $naziv, array $postojeci = []): array
    {
        if (!is_file($putanja) || !is_readable($putanja)) throw new \RuntimeException('Učitana datoteka nije dostupna.');
        $velicina = filesize($putanja);
        if ($velicina === false || $velicina < 1) throw new \RuntimeException('Datoteka je prazna.');
        if ($velicina > self::MAKS_BAJTOVA) throw new \RuntimeException('Datoteka je veća od dopuštenih 2 MB.');

        $nastavak = strtolower(pathinfo($naziv, PATHINFO_EXTENSION));
        if ($nastavak === 'csv') $redovi = self::csv($putanja);
        elseif ($nastavak === 'xlsx') $redovi = self::xlsx($putanja);
        else throw new \RuntimeException('Dopuštene su samo .xlsx i .csv datoteke.');

        return self::redovi($redovi, $postojeci);
    }

    /** @return array<int,array<int,mixed>> */
    private static function csv(string $putanja): array
    {
        $f = fopen($putanja, 'rb');
        if (!$f) throw new \RuntimeException('CSV nije moguće otvoriti.');
        $prvi = fgets($f);
        if ($prvi === false) { fclose($f); return []; }
        $brojaci = [';' => substr_count($prvi, ';'), ',' => substr_count($prvi, ','), "\t" => substr_count($prvi, "\t")];
        arsort($brojaci);
        $razdjelnik = (string) key($brojaci);
        if (reset($brojaci) < 1) { fclose($f); throw new \RuntimeException('CSV nema prepoznatljivo zaglavlje.'); }
        rewind($f);
        $redovi = [];
        while (($red = fgetcsv($f, 0, $razdjelnik, '"', '\\')) !== false) {
            if (!$redovi && isset($red[0])) $red[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $red[0]);
            $redovi[] = $red;
            if (count($redovi) > self::MAKS_REDOVA + 1) { fclose($f); throw new \RuntimeException('Datoteka ima više od 2000 redaka.'); }
        }
        fclose($f);
        return $redovi;
    }

    /** @return array<int,array<int,mixed>> */
    private static function xlsx(string $putanja): array
    {
        if (!class_exists('ZipArchive')) throw new \RuntimeException('PHP modul zip nije dostupan.');
        $zip = new \ZipArchive();
        if ($zip->open($putanja) !== true) throw new \RuntimeException('Datoteka nije ispravan XLSX.');
        try {
            if ($zip->numFiles > self::MAKS_ZIP_STAVKI) throw new \RuntimeException('XLSX sadrži previše dijelova.');
            $ukupno = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $ukupno += (int) ($stat['size'] ?? 0);
                if ($ukupno > self::MAKS_RASPAKIRANO) throw new \RuntimeException('XLSX je prevelik nakon raspakiravanja.');
            }

            $zajednicki = [];
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            if ($shared !== false) {
                $xml = self::xml($shared, 'sharedStrings.xml');
                foreach ($xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->si as $si) {
                    $zajednicki[] = self::tekstXml($si);
                }
            }

            $putLista = self::putLista($zip);
            $sadrzaj = $zip->getFromName($putLista);
            if ($sadrzaj === false) throw new \RuntimeException('XLSX nema prvi radni list.');
            $xml = self::xml($sadrzaj, $putLista);
            $ns = $xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $redovi = [];
            foreach ($ns->sheetData->row as $red) {
                $atributiRetka = $red->attributes();
                $broj = (int) ($atributiRetka['r'] ?? 0);
                if ($broj < 1) continue;
                if ($broj > self::MAKS_REDOVA + 1) throw new \RuntimeException('Datoteka ima više od 2000 redaka.');
                $vrijednosti = [];
                foreach ($red->c as $celija) {
                    $atributiCelije = $celija->attributes();
                    $ref = (string) ($atributiCelije['r'] ?? '');
                    if (!preg_match('/^([A-Z]+)\d+$/', $ref, $m)) continue;
                    $stupac = self::stupac($m[1]);
                    if ($stupac > 99) throw new \RuntimeException('XLSX ima previše stupaca.');
                    $tip = (string) ($atributiCelije['t'] ?? '');
                    if ($tip === 's') $vrijednost = $zajednicki[(int) $celija->v] ?? '';
                    elseif ($tip === 'inlineStr') $vrijednost = self::tekstXml($celija->is);
                    elseif ($tip === 'b') $vrijednost = ((string) $celija->v) === '1';
                    elseif ($tip === 'str') $vrijednost = (string) ($celija->v ?? '');
                    else {
                        $s = (string) ($celija->v ?? '');
                        $vrijednost = $s !== '' && is_numeric($s) ? (float) $s : $s;
                    }
                    $vrijednosti[$stupac] = $vrijednost;
                }
                if ($vrijednosti) {
                    $max = max(array_keys($vrijednosti));
                    $redovi[] = array_replace(array_fill(0, $max + 1, null), $vrijednosti);
                }
            }
            return $redovi;
        } finally {
            $zip->close();
        }
    }

    private static function putLista(\ZipArchive $zip): string
    {
        $wbTekst = $zip->getFromName('xl/workbook.xml');
        $relTekst = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbTekst === false || $relTekst === false) return 'xl/worksheets/sheet1.xml';
        $wb = self::xml($wbTekst, 'workbook.xml');
        $rels = self::xml($relTekst, 'workbook.xml.rels');
        $main = $wb->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $odabrani = null;
        foreach ($main->sheets->sheet as $sheet) {
            if ($odabrani === null || strcasecmp((string) $sheet['name'], 'Import') === 0) {
                $atributi = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $odabrani = (string) ($atributi['id'] ?? '');
                if (strcasecmp((string) $sheet['name'], 'Import') === 0) break;
            }
        }
        if (!$odabrani) return 'xl/worksheets/sheet1.xml';
        $pkg = $rels->children('http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($pkg->Relationship as $rel) {
            if ((string) $rel['Id'] !== $odabrani) continue;
            $cilj = ltrim(str_replace('\\', '/', (string) $rel['Target']), '/');
            if (strpos($cilj, '..') !== false) throw new \RuntimeException('XLSX sadrži nedopuštenu putanju.');
            return strpos($cilj, 'xl/') === 0 ? $cilj : 'xl/' . $cilj;
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(string $s, string $naziv): \SimpleXMLElement
    {
        if (stripos($s, '<!DOCTYPE') !== false || stripos($s, '<!ENTITY') !== false) {
            throw new \RuntimeException("$naziv sadrži nedopuštene XML deklaracije.");
        }
        $staro = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($staro);
        if ($xml === false) throw new \RuntimeException("$naziv nije ispravan XML.");
        return $xml;
    }

    private static function tekstXml(\SimpleXMLElement $cvor): string
    {
        $tekst = '';
        foreach ($cvor->xpath('.//*[local-name()="t"]') ?: [] as $t) $tekst .= (string) $t;
        return $tekst;
    }

    private static function stupac(string $slova): int
    {
        $n = 0;
        for ($i = 0; $i < strlen($slova); $i++) $n = $n * 26 + (ord($slova[$i]) - 64);
        return $n - 1;
    }

    /** @param array<int,array<int,mixed>> $redovi */
    private static function redovi(array $redovi, array $postojeci): array
    {
        if (!$redovi) throw new \RuntimeException('Datoteka nema podataka.');
        $zaglavlje = array_map(function ($v) { return strtolower(trim((string) $v)); }, array_shift($redovi));
        $indeksi = [];
        foreach (self::ZAGLAVLJA as $h) {
            $i = array_search($h, $zaglavlje, true);
            if ($i !== false) $indeksi[$h] = $i;
        }
        foreach (['naziv_usluge', 'maloprodajna_cijena', 'dodatna_cijena', 'datum_dodatne_cijene'] as $obavezno) {
            if (!isset($indeksi[$obavezno])) throw new \RuntimeException("Nedostaje obavezni stupac: $obavezno.");
        }

        $stariPoKljucu = [];
        foreach ($postojeci as $s) {
            $kljuc = self::kljuc($s['kategorija'] ?? '', $s['naziv'] ?? '', $s['jedinica'] ?? '');
            if ($kljuc !== '||') $stariPoKljucu[$kljuc] = $s['id'] ?? '';
        }
        $stavke = [];
        $upozorenja = [];
        $greske = [];
        $sifre = [];
        foreach ($redovi as $i => $red) {
            $prazan = true;
            foreach ($red as $v) if (trim((string) $v) !== '') { $prazan = false; break; }
            if ($prazan) continue;
            $br = $i + 2;
            $v = function (string $h) use ($red, $indeksi) { return isset($indeksi[$h]) ? ($red[$indeksi[$h]] ?? null) : null; };
            $naziv = trim((string) $v('naziv_usluge'));
            $kategorija = trim((string) $v('kategorija'));
            $jedinica = trim((string) $v('jedinica_mjere'));
            $cijena = Util::parsirajIznos($v('maloprodajna_cijena'));
            $sidrena = Util::parsirajIznos($v('dodatna_cijena'));
            $datum = self::datum($v('datum_dodatne_cijene'));
            $akcijaTekst = strtoupper(trim((string) $v('poseban_oblik_prodaje')));
            $aktivna = in_array($akcijaTekst, ['DA', 'YES', '1', 'TRUE'], true);
            if ($akcijaTekst !== '' && !in_array($akcijaTekst, ['DA', 'NE', 'YES', 'NO', '1', '0', 'TRUE', 'FALSE'], true)) {
                $greske[] = "Red $br: poseban_oblik_prodaje mora biti DA ili NE.";
            }
            $sifra = trim((string) $v('sifra'));
            if ($sifra === '') $sifra = (string) ($stariPoKljucu[self::kljuc($kategorija, $naziv, $jedinica)] ?? Podaci::noviId());
            if (!preg_match('/^[a-z0-9]{1,40}$/i', $sifra)) $greske[] = "Red $br: šifra smije sadržavati samo slova i brojke (najviše 40).";
            if (isset($sifre[$sifra])) $greske[] = "Red $br: šifra '$sifra' već se koristi u retku {$sifre[$sifra]}.";
            $sifre[$sifra] = $br;
            if ($naziv === '') $greske[] = "Red $br: nedostaje naziv_usluge.";
            if ($cijena === null || (is_float($cijena) && is_nan($cijena))) $greske[] = "Red $br: neispravna maloprodajna_cijena.";
            if ($sidrena === null || (is_float($sidrena) && is_nan($sidrena))) $greske[] = "Red $br: neispravna dodatna_cijena.";
            if ($datum === '') $greske[] = "Red $br: neispravan datum_dodatne_cijene.";
            $nazivAkcije = trim((string) $v('naziv_posebnog_oblika_prodaje'));
            if ($aktivna && $nazivAkcije === '') $greske[] = "Red $br: za aktivnu prodaju nedostaje naziv_posebnog_oblika_prodaje.";
            $najniza = Util::parsirajIznos($v('najniza_cijena_30_dana'));
            if (is_float($najniza) && is_nan($najniza)) $greske[] = "Red $br: neispravna najniza_cijena_30_dana.";
            if (count($greske) >= 30) break;

            $stavke[] = Podaci::novaStavka([
                'id' => $sifra, 'kategorija' => $kategorija, 'naziv' => $naziv, 'jedinica' => $jedinica,
                'cijena' => $cijena, 'sidrenaCijena' => $sidrena, 'datumSidrenja' => $datum,
                'akcija' => ['aktivna' => $aktivna, 'naziv' => $nazivAkcije, 'najniza30' => $najniza],
            ]);
        }
        if ($greske) throw new \RuntimeException(implode("\n", $greske));
        if (!$stavke) throw new \RuntimeException('Datoteka nema nijednu stavku za uvoz.');
        $stavke = Podaci::normaliziraj(['stavke' => $stavke])['stavke'];
        return ['stavke' => $stavke, 'upozorenja' => $upozorenja, 'redaka' => count($stavke)];
    }

    private static function datum($v): string
    {
        if (is_int($v) || is_float($v)) {
            $dani = (int) floor((float) $v);
            if ($dani > 0 && $dani < 100000) return (new \DateTimeImmutable('1899-12-30'))->modify("+$dani days")->format('Y-m-d');
        }
        $d = Podaci::datumIso(trim((string) $v));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }

    private static function kljuc($kategorija, $naziv, $jedinica): string
    {
        return strtolower(trim((string) $kategorija)) . '|' . strtolower(trim((string) $naziv)) . '|' . strtolower(trim((string) $jedinica));
    }
}
