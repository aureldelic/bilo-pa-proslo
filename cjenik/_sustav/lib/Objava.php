<?php
// Objava cjenika: nova verzija XML/CSV-a samo kad se sadržaj promijeni,
// čišćenje arhive, izrada javne stranice i umetanje tablice u postojeće stranice.

namespace Cjenik;

final class GreskaProvjere extends \RuntimeException
{
    /** @var string[] */
    public $greske;

    public function __construct(array $greske)
    {
        parent::__construct(implode("\n", $greske));
        $this->greske = $greske;
    }
}

final class Objava
{
    /** @var Sustav */
    private $s;

    public function __construct(Sustav $s)
    {
        $this->s = $s;
    }

    public function imaPromjena(array $p): bool
    {
        $zadnja = end($p['objave']);
        $n = Podaci::normaliziraj($p);
        if (Podaci::provjeri($n)) return true;
        return !$zadnja || Podaci::otisak($n) !== $zadnja['otisak'];
    }

    /** Najniža cijena iz zadržanih objava (pokrivaju cijelih 30 dana, uključujući granicu). */
    private static function najnizaPrijeAkcije(string $id, array $objave)
    {
        $cijene = [];
        foreach ($objave as $o) foreach ($o['stavke'] as $s) if ($s['id'] === $id) $cijene[] = (float) $s['cijena'];
        return $cijene ? min($cijene) : null;
    }

    /**
     * @param array $unos podaci iz sučelja (nacrt)
     * @param mixed $sada za testove
     */
    public function objavi(array $unos, $sada = null): array
    {
        return $this->s->zakljucano(function () use ($unos, $sada) {
            $sada = Util::datum($sada);
            $p = Podaci::normaliziraj($unos);
            $greske = Podaci::provjeri($p);
            if ($greske) throw new GreskaProvjere($greske);

            $prethodna = end($p['objave']) ?: null;

            // Početak akcije: zapamti najnižu cijenu iz prethodnih 30 dana.
            $dosadasnje = Arhiva::podijeli($p['objave'], $sada, $p['danaArhive'])['zadrzi'];
            foreach ($p['stavke'] as &$st) {
                $bila = false;
                if ($prethodna) foreach ($prethodna['stavke'] as $ps) if ($ps['id'] === $st['id']) $bila = !empty($ps['akcija']);
                if ($st['akcija']['aktivna'] && !$bila && $st['akcija']['najniza30'] === null) {
                    $st['akcija']['najniza30'] = self::najnizaPrijeAkcije($st['id'], $dosadasnje);
                }
                if (!$st['akcija']['aktivna']) $st['akcija']['najniza30'] = null;
            }
            unset($st);

            $otisak = Podaci::otisak($p);
            $nova = null;
            if (!$prethodna || $otisak !== $prethodna['otisak']) {
                $broj = (int) $p['brojPohrane'] + 1;
                $datoteke = [];
                foreach ($p['formati'] as $f) {
                    $ime = Formati::nazivDatoteke($p, $broj, $sada, $f);
                    Util::upisi($this->s->arhiva($ime), $f === 'csv' ? Formati::csv($p) : Formati::xml($p, $broj, $sada));
                    $datoteke[] = $ime;
                }
                $nova = [
                    'broj' => $broj,
                    'datum' => Util::iso($sada),
                    'otisak' => $otisak,
                    'datoteke' => $datoteke,
                    'stavke' => array_map(function ($s) {
                        return ['id' => $s['id'], 'cijena' => $s['cijena'], 'akcija' => $s['akcija']['aktivna']];
                    }, $p['stavke']),
                ];
                $p['brojPohrane'] = $broj;
                $p['objave'][] = $nova;
            }

            [$p, $obrisano] = $this->pocistiArhivu($p, $sada);
            $rezultat = $this->generiraj($p);
            Podaci::pisiJson($this->s->datotekaPodataka(), $p);

            return ['podaci' => $p, 'nova' => $nova, 'obrisano' => $obrisano] + $rezultat;
        });
    }

    /** Brisanje datoteka izvan pravila čuvanja. */
    private function pocistiArhivu(array $p, $sada): array
    {
        $podjela = Arhiva::podijeli($p['objave'], $sada, $p['danaArhive']);
        $obrisano = [];
        foreach ($podjela['obrisi'] as $o) {
            foreach ($o['datoteke'] as $d) {
                $putanja = $this->s->arhiva($d);
                if (is_file($putanja) && @unlink($putanja)) $obrisano[] = $d;
            }
        }
        $p['objave'] = $podjela['zadrzi'];
        return [$p, $obrisano];
    }

    /**
     * Čišćenje arhive bez nove objave — poziva se pri svakom otvaranju sučelja,
     * da stare datoteke nestanu i kad se cijene dugo ne mijenjaju.
     */
    public function pocisti($sada = null): array
    {
        return $this->s->zakljucano(function () use ($sada) {
            $p = Podaci::ucitaj($this->s->datotekaPodataka());
            if (!$p['objave']) return [];
            [$novi, $obrisano] = $this->pocistiArhivu($p, Util::datum($sada));
            if (count($novi['objave']) !== count($p['objave'])) {
                $p['objave'] = $novi['objave'];
                Podaci::pisiJson($this->s->datotekaPodataka(), $p);
                $this->generiraj(Podaci::normaliziraj($p));
            }
            return $obrisano;
        });
    }

    /** Javna stranica, stalne poveznice, fragment za widget i umetanje u stranice. */
    public function generiraj(array $p): array
    {
        $vazeca = end($p['objave']);
        if (!$vazeca) return ['umetnuto' => [], 'upozorenja' => []];
        // Objavljuje se stanje važeće objave, a ne nacrt.
        $k = $this->s->korijen();

        if (in_array('xml', $p['formati'], true)) Util::upisi("$k/cjenik.xml", Formati::xml($p, (int) $vazeca['broj'], $vazeca['datum']));
        if (in_array('csv', $p['formati'], true)) Util::upisi("$k/cjenik.csv", Formati::csv($p));
        Util::upisi("$k/index.html", Prikaz::stranica($p, $p['objave']));
        Util::upisi("$k/tablica.html", '<style>' . Prikaz::CSS . "</style>\n"
            . Prikaz::tablica($p, ['datum' => $vazeca['datum'], 'xmlPoveznica' => 'cjenik.xml']) . "\n");
        Util::upisi("$k/cjenik-widget.js", file_get_contents($this->s->sustav('widget.js')));

        $umetnuto = [];
        $upozorenja = [];
        foreach ($p['umetni'] as $rel) {
            $putanja = $this->s->datotekaStranice($rel);
            if ($putanja === null) {
                $upozorenja[] = "Ne postoji ili nije dopuštena datoteka za umetanje: $rel";
                continue;
            }
            $html = Prikaz::umetni(file_get_contents($putanja), Prikaz::blokZaUmetanje($p, [
                'datum' => $vazeca['datum'],
                'xmlPoveznica' => $this->s->relativno(dirname($putanja), "$k/cjenik.xml"),
            ]));
            if ($html === null) {
                $upozorenja[] = "$rel: nema oznaka <!-- cjenik:pocetak --> i <!-- cjenik:kraj -->";
                continue;
            }
            // Izravno pisanje (ne rename) da datoteka stranice zadrži vlasnika i dozvole.
            if (@file_put_contents($putanja, $html, LOCK_EX) === false) {
                $upozorenja[] = "$rel: nema dozvole za pisanje";
                continue;
            }
            $umetnuto[] = $rel;
        }
        return ['umetnuto' => $umetnuto, 'upozorenja' => $upozorenja];
    }
}
