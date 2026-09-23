<?php
// Strojno čitljivi cjenik: naziv datoteke, XML i CSV.
// Struktura XML-a nije propisana, pa su nazivi elemenata hrvatski i opisni.
// Iznosi su s točkom kao decimalnim separatorom, datumi u ISO 8601.

namespace Cjenik;

final class Formati
{
    /** Razmaci postaju '_', a znakovi koji ne smiju u naziv datoteke postaju '-'. */
    public static function cistiDio(string $s): string
    {
        $s = preg_replace('/[\\\\\/:*?"<>|]+/u', '-', trim($s));
        return preg_replace('/[\s_]+/u', '_', $s);
    }

    /**
     * Naziv prema pojašnjenju ministarstva:
     * oblik_adresa_oznaka_broj pohrane_DD.MM.GGGG_HH:MM
     * npr. servis_Vukovarska_20_Osijek_U-03_015_01.10.2026_07:45.xml
     */
    public static function nazivDatoteke(array $p, int $broj, $datum, string $nastavak): string
    {
        $d = Util::datum($datum);
        $sep = $p['separatorVremena'] ?? ':';
        return implode('_', [
            self::cistiDio($p['objekt']['oblik']),
            self::cistiDio($p['objekt']['adresa']),
            self::cistiDio($p['objekt']['oznaka']),
            str_pad((string) $broj, 3, '0', STR_PAD_LEFT),
            $d->format('d.m.Y'),
            $d->format('H') . $sep . $d->format('i'),
        ]) . '.' . $nastavak;
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
                . $e('kategorija', $s['kategorija'])
                . $e('jedinicaMjere', $s['jedinica'])
                . '      <maloprodajnaCijena valuta="EUR">' . Util::iznos($s['cijena']) . "</maloprodajnaCijena>\n"
                . ($a['aktivna']
                    ? '      <posebanOblikProdaje primijenjen="da">' . Util::xml($a['naziv']) . "</posebanOblikProdaje>\n"
                    : "      <posebanOblikProdaje primijenjen=\"ne\"/>\n")
                . ($a['aktivna'] && $a['najniza30'] !== null
                    ? '      <najnizaCijena30Dana valuta="EUR">' . Util::iznos($a['najniza30']) . "</najnizaCijena30Dana>\n" : '')
                . '      <dodatnaCijena valuta="EUR" datum="' . Util::xml($s['datumSidrenja']) . '">' . Util::iznos($s['sidrenaCijena']) . "</dodatnaCijena>\n"
                . "    </usluga>\n";
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<cjenik vrsta=\"usluge\" verzija=\"1.0\">\n"
            . "  <zaglavlje>\n"
            . "    <obveznik>\n" . $e('naziv', $p['obveznik']['naziv']) . $e('oib', $p['obveznik']['oib']) . "    </obveznik>\n"
            . "    <objekt>\n" . $e('oblik', $p['objekt']['oblik']) . $e('adresa', $p['objekt']['adresa']) . $e('oznaka', $p['objekt']['oznaka']) . "    </objekt>\n"
            . "    <brojPohrane>$broj</brojPohrane>\n"
            . '    <datumObjave>' . Util::iso($datum) . "</datumObjave>\n"
            . "    <valuta>EUR</valuta>\n"
            . "  </zaglavlje>\n"
            . "  <usluge>\n$stavke  </usluge>\n"
            . "</cjenik>\n";
    }

    public static function csv(array $p): string
    {
        $polje = function ($v) {
            $s = (string) $v;
            return preg_match('/[;"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        $redovi = [implode(';', ['naziv_usluge', 'kategorija', 'jedinica_mjere', 'maloprodajna_cijena',
            'poseban_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'najniza_cijena_30_dana',
            'dodatna_cijena', 'datum_dodatne_cijene', 'sifra'])];
        foreach ($p['stavke'] as $s) {
            $a = $s['akcija'];
            $redovi[] = implode(';', array_map($polje, [
                $s['naziv'], $s['kategorija'], $s['jedinica'], Util::iznos($s['cijena']),
                $a['aktivna'] ? 'DA' : 'NE', $a['aktivna'] ? $a['naziv'] : '',
                $a['aktivna'] && $a['najniza30'] !== null ? Util::iznos($a['najniza30']) : '',
                Util::iznos($s['sidrenaCijena']), $s['datumSidrenja'], $s['id'],
            ]));
        }
        // BOM da Excel ispravno prepozna UTF-8 (č, ć, ž, š, đ).
        return "\u{FEFF}" . implode("\r\n", $redovi) . "\r\n";
    }
}
