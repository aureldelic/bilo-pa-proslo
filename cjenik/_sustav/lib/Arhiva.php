<?php
// Pravilo čuvanja arhive cjenika.
//
// Odluka: prethodno važeći cjenici moraju ostati javno dostupni najmanje 30 dana
// od dana objave. Čuvamo:
//   1. sve objave iz zadnjih N dana (N = danaArhive, najmanje 30),
//   2. zadnju objavu PRIJE te granice — ona je vrijedila na dan granice, pa je
//      najstarija datoteka u arhivi uvijek stara barem N dana (ako postoji),
//   3. uvijek trenutno važeću (najnoviju) objavu.
// Primjeri:
//   - jedna objava prije 90 dana, bez promjena -> ostaje (ona je i zadnja),
//   - promjena svaki dan 40 dana -> ostaje zadnjih 30 + ona s granice.

namespace Cjenik;

final class Arhiva
{
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
        $zadrzi = [];
        $granicna = null;
        foreach ($objave as $i => $o) {
            if (Util::razlikaDana(Util::dan($o['datum']), $danas) < $dana) {
                $zadrzi[$i] = true;
            } else {
                $granicna = $i;
            }
        }
        if ($granicna !== null) $zadrzi[$granicna] = true;
        if ($objave) $zadrzi[count($objave) - 1] = true;

        $r = ['zadrzi' => [], 'obrisi' => []];
        foreach ($objave as $i => $o) $r[isset($zadrzi[$i]) ? 'zadrzi' : 'obrisi'][] = $o;
        return $r;
    }

    public static function starost(array $objava, $sada): int
    {
        return Util::razlikaDana(Util::dan($objava['datum']), Util::dan($sada));
    }
}
