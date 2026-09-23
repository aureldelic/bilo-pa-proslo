<?php
// Spremanje, normalizacija i provjera podataka cjenika.
// Podaci su u _podaci/*.php s "<?php exit;" na početku, pa nisu čitljivi preko weba
// ni na poslužiteljima koji ne poštuju .htaccess (npr. nginx).

namespace Cjenik;

final class Podaci
{
    const ZASTITA = "<?php http_response_code(404); exit; ?>\n";

    public static function zadano(): array
    {
        return [
            'obveznik' => ['naziv' => '', 'oib' => ''],
            'objekt' => ['oblik' => 'servis', 'adresa' => '', 'oznaka' => '01'],
            'formati' => ['xml'],
            'danaArhive' => 30,
            'separatorVremena' => ':',
            'umetni' => [],
            'naslovStranice' => 'Cjenik usluga',
            'napomena' => 'Cijene su iskazane u eurima (EUR).',
            'stavke' => [],
            'brojPohrane' => 0,
            'objave' => [],
        ];
    }

    public static function noviId(): string
    {
        return bin2hex(random_bytes(5));
    }

    public static function novaStavka(array $v = []): array
    {
        $a = isset($v['akcija']) && is_array($v['akcija']) ? $v['akcija'] : [];
        return [
            'id' => !empty($v['id']) ? preg_replace('/[^a-z0-9]/i', '', (string) $v['id']) : self::noviId(),
            'kategorija' => $v['kategorija'] ?? '',
            'naziv' => $v['naziv'] ?? '',
            'jedinica' => $v['jedinica'] ?? '',
            'cijena' => $v['cijena'] ?? null,
            'sidrenaCijena' => $v['sidrenaCijena'] ?? null,
            'datumSidrenja' => !empty($v['datumSidrenja']) ? $v['datumSidrenja'] : Util::DATUM_SIDRENJA,
            'akcija' => [
                'aktivna' => !empty($a['aktivna']),
                'naziv' => $a['naziv'] ?? '',
                'najniza30' => $a['najniza30'] ?? null,
            ],
        ];
    }

    /* ---------- Datoteke ---------- */

    public static function citajJson(string $putanja): ?array
    {
        if (!is_file($putanja)) return null;
        $s = file_get_contents($putanja);
        if (strncmp($s, '<?php', 5) === 0) $s = substr($s, strpos($s, "\n") + 1);
        $j = json_decode($s, true);
        return is_array($j) ? $j : null;
    }

    public static function pisiJson(string $putanja, array $podaci): void
    {
        $json = json_encode($podaci, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        Util::upisi($putanja, self::ZASTITA . $json . "\n");
    }

    public static function ucitaj(string $putanja): array
    {
        $p = array_replace(self::zadano(), self::citajJson($putanja) ?? []);
        $p['stavke'] = array_map([self::class, 'novaStavka'], $p['stavke']);
        return $p;
    }

    /* ---------- Normalizacija i provjera ---------- */

    private static function tekst($v, int $max = 300): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v));
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    public static function normaliziraj(array $p): array
    {
        $p = array_replace(self::zadano(), $p);
        $p['obveznik'] = ['naziv' => self::tekst($p['obveznik']['naziv'] ?? ''), 'oib' => self::tekst($p['obveznik']['oib'] ?? '', 11)];
        $p['objekt'] = [
            'oblik' => self::tekst($p['objekt']['oblik'] ?? '', 60),
            'adresa' => self::tekst($p['objekt']['adresa'] ?? '', 120),
            'oznaka' => self::tekst($p['objekt']['oznaka'] ?? '', 30),
        ];
        $p['naslovStranice'] = self::tekst($p['naslovStranice']);
        $p['napomena'] = self::tekst($p['napomena'], 1000);
        $p['danaArhive'] = max(30, (int) $p['danaArhive']);
        $p['formati'] = array_values(array_intersect(['xml', 'csv'], (array) $p['formati'])) ?: ['xml'];
        $p['separatorVremena'] = in_array($p['separatorVremena'], [':', '-', '.'], true) ? $p['separatorVremena'] : ':';
        $p['stavke'] = array_map(function ($s) {
            $s = self::novaStavka(is_array($s) ? $s : []);
            $s['kategorija'] = self::tekst($s['kategorija'], 120);
            $s['naziv'] = self::tekst($s['naziv']);
            $s['jedinica'] = self::tekst($s['jedinica'], 80);
            $s['cijena'] = Util::parsirajIznos($s['cijena']);
            $s['sidrenaCijena'] = Util::parsirajIznos($s['sidrenaCijena']);
            $s['datumSidrenja'] = (string) $s['datumSidrenja'];
            $s['akcija']['naziv'] = self::tekst($s['akcija']['naziv'], 120);
            $s['akcija']['najniza30'] = Util::parsirajIznos($s['akcija']['najniza30']);
            return $s;
        }, array_values((array) $p['stavke']));
        return $p;
    }

    private static function neispravan($n): bool
    {
        return $n === null || (is_float($n) && is_nan($n));
    }

    /** @return string[] greške koje sprječavaju objavu */
    public static function provjeri(array $p): array
    {
        $g = [];
        if ($p['obveznik']['naziv'] === '') $g[] = 'Upiši naziv obrta/tvrtke.';
        if ($p['obveznik']['oib'] !== '' && !preg_match('/^\d{11}$/', $p['obveznik']['oib'])) $g[] = 'OIB mora imati 11 znamenki.';
        if ($p['objekt']['oblik'] === '') $g[] = 'Upiši oblik/vrstu objekta (npr. servis, salon, ured).';
        if ($p['objekt']['adresa'] === '') $g[] = 'Upiši adresu objekta.';
        if ($p['objekt']['oznaka'] === '') $g[] = 'Upiši oznaku objekta (npr. 01 ili U-01).';
        if (!$p['stavke']) $g[] = 'Dodaj barem jednu uslugu.';
        foreach ($p['stavke'] as $i => $s) {
            $r = 'Red ' . ($i + 1) . ($s['naziv'] !== '' ? " ({$s['naziv']})" : '');
            if ($s['naziv'] === '') $g[] = "$r: nedostaje opis usluge.";
            if (self::neispravan($s['cijena'])) $g[] = "$r: neispravna trenutna cijena.";
            if (self::neispravan($s['sidrenaCijena'])) $g[] = "$r: neispravna cijena na datum sidrenja.";
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['datumSidrenja'])) $g[] = "$r: neispravan datum sidrenja.";
            if ($s['akcija']['aktivna'] && $s['akcija']['naziv'] === '') $g[] = "$r: upiši naziv posebnog oblika prodaje (npr. \"Jesenska akcija\").";
            $n = $s['akcija']['najniza30'];
            if (is_float($n) && is_nan($n)) $g[] = "$r: neispravna najniža cijena u 30 dana.";
        }
        return $g;
    }

    /** Otisak objavljenog sadržaja — kad se promijeni, nastaje nova datoteka. */
    public static function otisak(array $p): string
    {
        $f = function ($n) { return $n === null ? null : Util::iznos($n); };
        $sadrzaj = [
            $p['obveznik'], $p['objekt'],
            array_map(function ($s) use ($f) {
                $a = $s['akcija']['aktivna'];
                return [$s['kategorija'], $s['naziv'], $s['jedinica'], $f($s['cijena']), $f($s['sidrenaCijena']),
                    $s['datumSidrenja'], $a, $a ? $s['akcija']['naziv'] : '', $a ? $f($s['akcija']['najniza30']) : null];
            }, $p['stavke']),
        ];
        return substr(hash('sha256', json_encode($sadrzaj, JSON_UNESCAPED_UNICODE)), 0, 16);
    }
}
