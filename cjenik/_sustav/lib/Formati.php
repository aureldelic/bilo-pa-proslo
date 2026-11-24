<?php
// Strojno čitljivi cjenik prema Pravilniku o načinu objave cjenika proizvoda (NN 132/2026):
// naziv datoteke, CSV i XML.
//
// CSV: UTF-8, stupci odvojeni točkom sa zarezom, redoslijed stupaca kao u čl. 3. st. 3.
// (naziv usluge, maloprodajna cijena, poseban oblik prodaje, bazna cijena).
// XML: struktura nije propisana; podaci idu istim redoslijedom, a dodatni podaci
// (kategorija, jedinica, najniža cijena u 30 dana) dolaze iza propisanih.

namespace Cjenik;

final class Formati
{
    const VRSTA = 'USLUGE';
    const SVA_MJESTA = 'ZA_SVA_PRODAJNA_MJESTA';

    /** Razmaci postaju '_', a znakovi koji ne smiju u naziv datoteke postaju '-'. */
    public static function cistiDio(string $s): string
    {
        $s = preg_replace('/[\\\\\/:*?"<>|]+/u', '-', trim($s));
        return preg_replace('/[\s_]+/u', '_', $s);
    }

    /**
     * Naziv prema čl. 6. st. 3. i 4. Pravilnika:
     *   OIB_prodajno mjesto_adresa_oznaka_USLUGE_broj pohrane_GGGGMMDD_HHMM
     *   OIB_ZA_SVA_PRODAJNA_MJESTA_USLUGE_broj pohrane_GGGGMMDD_HHMM
     * npr. 12345678901_salon_Vukovarska_20_Osijek_01_USLUGE_004_20261124_0745.xml
     */
    public static function nazivDatoteke(array $p, int $broj, $datum, string $nastavak): string
    {
        $d = Util::datum($datum);
        $dijelovi = [self::cistiDio($p['obveznik']['oib'])];
        if (!empty($p['objekt']['svaMjesta'])) {
            $dijelovi[] = self::SVA_MJESTA;
        } else {
            $dijelovi[] = self::cistiDio($p['objekt']['oblik']);
            $dijelovi[] = self::cistiDio($p['objekt']['adresa']);
            $dijelovi[] = self::cistiDio($p['objekt']['oznaka']);
        }
        $dijelovi[] = self::VRSTA;
        $dijelovi[] = str_pad((string) $broj, 3, '0', STR_PAD_LEFT);
        $dijelovi[] = $d->format('Ymd_Hi');
        return implode('_', $dijelovi) . '.' . $nastavak;
    }

    public static function xml(array $p, int $broj, $datum): string
    {
        $e = function ($tag, $v, $ind = '      ') {
            return ($v === '' || $v === null) ? '' : "$ind<$tag>" . Util::xml($v) . "</$tag>\n";
        };
        $stavke = '';
        foreach ($p['stavke'] as $i => $s) {
            $a = $s['akcija'];
            $stavke .= '    <usluga rb="' . ($i + 1) . '" sifra="' . Util::xml($s['id']) . "\">\n"
                . $e('naziv', $s['naziv'])
                . '      <maloprodajnaCijena valuta="EUR">' . Util::iznos($s['cijena']) . "</maloprodajnaCijena>\n"
                . ($a['aktivna']
                    ? '      <posebanOblikProdaje primijenjen="da">' . Util::xml($a['naziv']) . "</posebanOblikProdaje>\n"
                    : "      <posebanOblikProdaje primijenjen=\"ne\"/>\n")
                . '      <baznaCijena valuta="EUR" datum="' . Util::xml($s['datumSidrenja']) . '">' . Util::iznos($s['sidrenaCijena']) . "</baznaCijena>\n"
                . $e('kategorija', $s['kategorija'])
                . $e('jedinicaMjere', $s['jedinica'])
                . ($a['aktivna'] && $a['najniza30'] !== null
                    ? '      <najnizaCijena30Dana valuta="EUR">' . Util::iznos($a['najniza30']) . "</najnizaCijena30Dana>\n" : '')
                . "    </usluga>\n";
        }
        $objekt = !empty($p['objekt']['svaMjesta'])
            ? "    <prodajnoMjesto svaProdajnaMjesta=\"da\"/>\n"
            : "    <prodajnoMjesto>\n" . $e('oblik', $p['objekt']['oblik']) . $e('adresa', $p['objekt']['adresa']) . $e('oznaka', $p['objekt']['oznaka']) . "    </prodajnoMjesto>\n";
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<cjenik vrsta=\"usluge\" verzija=\"2.0\">\n"
            . "  <zaglavlje>\n"
            . "    <obveznik>\n" . $e('naziv', $p['obveznik']['naziv']) . $e('oibIliMaticniBroj', $p['obveznik']['oib']) . "    </obveznik>\n"
            . $objekt
            . "    <brojPohrane>$broj</brojPohrane>\n"
            . '    <datumObjave>' . Util::iso($datum) . "</datumObjave>\n"
            . "    <valuta>EUR</valuta>\n"
            . "  </zaglavlje>\n"
            . "  <usluge>\n$stavke  </usluge>\n"
            . "</cjenik>\n";
    }

    /** CSV: točno propisani stupci, propisanim redom. */
    public static function csv(array $p): string
    {
        $polje = function ($v) {
            $s = (string) $v;
            return preg_match('/[;"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        $redovi = [implode(';', ['naziv_usluge', 'maloprodajna_cijena_usluge', 'poseban_oblik_prodaje', 'bazna_cijena_usluge'])];
        foreach ($p['stavke'] as $s) {
            $redovi[] = implode(';', array_map($polje, [
                $s['naziv'],
                Util::iznos($s['cijena']),
                $s['akcija']['aktivna'] ? 'DA' : 'NE',
                Util::iznos($s['sidrenaCijena']),
            ]));
        }
        return implode("\r\n", $redovi) . "\r\n";
    }
}
