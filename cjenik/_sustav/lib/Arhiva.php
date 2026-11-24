<?php
// Pravilo čuvanja arhive cjenika.
//
// Pravilnik o načinu objave cjenika proizvoda (NN 132/2026), čl. 3. st. 6.:
// arhivirani cjenik mora biti dostupan 15 dana nakon što ga zamijeni važeći.
// Čuvamo:
//   1. važeći (najnoviji) cjenik — uvijek,
//   2. svaki prethodni cjenik dok od njegove zamjene (objave sljedećeg) nije prošlo
//      više od N dana (N = danaArhive, najmanje 15).
// Primjeri (N = 15):
//   - jedna objava prije 90 dana, bez promjena -> ostaje (važeća je),
//   - objava A zamijenjena prije 10 dana -> ostaje; zamijenjena prije 20 dana -> briše se.

namespace Cjenik;

final class Arhiva
{
    const MIN_DANA = 15;

    /**
     * @param array $objave [['broj'=>..,'datum'=>ISO,..], ...]
     * @return array ['zadrzi' => [...], 'obrisi' => [...]] (oba sortirana od najstarije)
     */
    public static function podijeli(array $objave, $sada, int $dana = 30): array
    {
        usort($objave, function ($a, $b) {
            return strtotime($a['datum']) <=> strtotime($b['datum']);
        });
        $danas = Util::dan($sada);
        $r = ['zadrzi' => [], 'obrisi' => []];
        $zadnji = count($objave) - 1;
        foreach ($objave as $i => $o) {
            if ($i === $zadnji) {
                $r['zadrzi'][] = $o;
                continue;
            }
            $zamijenjen = Util::dan($objave[$i + 1]['datum']);
            $r[Util::razlikaDana($zamijenjen, $danas) <= $dana ? 'zadrzi' : 'obrisi'][] = $o;
        }
        return $r;
    }

    public static function starost(array $objava, $sada): int
    {
        return Util::razlikaDana(Util::dan($objava['datum']), Util::dan($sada));
    }
}
