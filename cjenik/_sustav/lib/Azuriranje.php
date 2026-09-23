<?php
// Ažuriranje plugina s GitHuba.
//
// Provjerava zadnje izdanje (release) u repozitoriju, preuzima cjenik.zip i
// zamjenjuje mapu _sustav (i datoteke u admin/). Podaci u _podaci/ i objavljeni
// cjenici se ne diraju. Zamjena je atomska: nova verzija se raspakira pokraj stare
// i tek kad je potpuna, mape se zamijene preimenovanjem.

namespace Cjenik;

final class Azuriranje
{
    const PROVJERA_SVAKIH = 6 * 3600;

    /** @var Sustav */
    private $s;
    /** @var array */
    private $konf;

    public function __construct(Sustav $s)
    {
        $this->s = $s;
        $konf = require $s->sustav('konfiguracija.php');
        $post = $s->postavke();
        // Postavke instalacije mogu nadjačati izvor (npr. fork) i dodati token za privatni repozitorij.
        $this->konf = [
            'repozitorij' => $post['repozitorij'] ?? $konf['repozitorij'],
            'token' => $post['githubToken'] ?? '',
        ];
    }

    private function http(string $url, array $zaglavlja = [], ?string $uDatoteku = null): string
    {
        $zaglavlja[] = 'User-Agent: cjenik-sidrene-cijene/' . $this->s->verzija();
        if ($this->konf['token'] !== '') $zaglavlja[] = 'Authorization: Bearer ' . $this->konf['token'];

        if (function_exists('curl_init')) {
            $c = curl_init($url);
            $f = $uDatoteku ? fopen($uDatoteku, 'wb') : null;
            curl_setopt_array($c, [
                CURLOPT_HTTPHEADER => $zaglavlja,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ] + ($f ? [CURLOPT_FILE => $f] : [CURLOPT_RETURNTRANSFER => true]));
            $odgovor = curl_exec($c);
            $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            $greska = curl_error($c);
            if (PHP_VERSION_ID < 80000) curl_close($c);
            if ($f) fclose($f);
            if ($odgovor === false || $status >= 400) {
                throw new \RuntimeException("Preuzimanje nije uspjelo ($status) $greska");
            }
            return $f ? '' : (string) $odgovor;
        }

        $kontekst = stream_context_create(['http' => [
            'header' => implode("\r\n", $zaglavlja), 'timeout' => 60, 'follow_location' => 1, 'ignore_errors' => false,
        ]]);
        $odgovor = @file_get_contents($url, false, $kontekst);
        if ($odgovor === false) throw new \RuntimeException('Preuzimanje nije uspjelo (uključi cURL ili allow_url_fopen)');
        if ($uDatoteku) {
            file_put_contents($uDatoteku, $odgovor);
            return '';
        }
        return $odgovor;
    }

    /**
     * Najnovije izdanje: ['verzija','opis','dostupna','zip'].
     * Rezultat se pamti nekoliko sati da se ne troši GitHub API.
     */
    public function provjeri(bool $prisilno = false): array
    {
        $cache = $this->s->podaci('azuriranje.php');
        $c = Podaci::citajJson($cache);
        if (!$prisilno && $c && time() - ($c['vrijeme'] ?? 0) < self::PROVJERA_SVAKIH && ($c['izvor'] ?? '') === $this->konf['repozitorij']) {
            return $this->rezultat($c);
        }
        $j = json_decode($this->http(
            'https://api.github.com/repos/' . $this->konf['repozitorij'] . '/releases/latest',
            ['Accept: application/vnd.github+json']
        ), true);
        if (!is_array($j) || empty($j['tag_name'])) throw new \RuntimeException('Neispravan odgovor GitHuba');

        $zip = null;
        foreach ($j['assets'] ?? [] as $a) if (($a['name'] ?? '') === 'cjenik.zip') $zip = $a['url'];
        $c = [
            'vrijeme' => time(),
            'izvor' => $this->konf['repozitorij'],
            'verzija' => ltrim($j['tag_name'], 'vV'),
            'opis' => (string) ($j['body'] ?? ''),
            'zip' => $zip,
        ];
        Podaci::pisiJson($cache, $c);
        return $this->rezultat($c);
    }

    private function rezultat(array $c): array
    {
        return [
            'trenutna' => $this->s->verzija(),
            'verzija' => $c['verzija'],
            'opis' => $c['opis'],
            'dostupna' => !empty($c['zip']) && version_compare($c['verzija'], $this->s->verzija(), '>'),
            'zip' => $c['zip'],
        ];
    }

    /** Preuzmi i instaliraj najnovije izdanje. Vraća novu verziju. */
    public function instaliraj(): string
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Na poslužitelju nije dostupan PHP zip modul.');
        $info = $this->provjeri(true);
        if (!$info['dostupna']) return $info['trenutna'];

        return $this->s->zakljucano(function () use ($info) {
            $zipPut = $this->s->podaci('azuriranje.zip');
            $this->http($info['zip'], ['Accept: application/octet-stream'], $zipPut);
            try {
                return $this->instalirajZip($zipPut);
            } finally {
                @unlink($zipPut);
            }
        });
    }

    /** Instalacija iz zip datoteke (koristi se i za testove). */
    public function instalirajZip(string $zipPut): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPut) !== true) throw new \RuntimeException('Preuzeta datoteka nije ispravan zip.');

        $k = $this->s->korijen();
        $novi = "$k/_sustav.novi";
        $stari = "$k/_sustav.stari";
        self::obrisiMapu($novi);
        self::obrisiMapu($stari);
        $admin = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $ime = str_replace('\\', '/', $zip->getNameIndex($i));
            if (!preg_match('#(?:^|/)cjenik/(_sustav|admin)/(.+)$#', $ime, $m)) continue;
            [, $mapa, $rel] = $m;
            if (substr($rel, -1) === '/' || strpos("/$rel/", '/../') !== false) continue;
            $sadrzaj = $zip->getFromIndex($i);
            if ($mapa === '_sustav') {
                if (!is_dir(dirname("$novi/$rel"))) mkdir(dirname("$novi/$rel"), 0755, true);
                file_put_contents("$novi/$rel", $sadrzaj);
            } else {
                $admin[$rel] = $sadrzaj;
            }
        }
        $zip->close();

        if (!is_file("$novi/VERZIJA") || !is_file("$novi/lib/Sustav.php")) {
            self::obrisiMapu($novi);
            throw new \RuntimeException('Zip ne sadrži ispravan plugin (nema _sustav/VERZIJA).');
        }
        $verzija = trim(file_get_contents("$novi/VERZIJA"));

        if (!rename($this->s->sustav(), $stari)) throw new \RuntimeException('Ne mogu zamijeniti mapu _sustav (dozvole).');
        if (!rename($novi, $this->s->sustav())) {
            rename($stari, $this->s->sustav());
            throw new \RuntimeException('Ne mogu zamijeniti mapu _sustav (dozvole).');
        }
        foreach ($admin as $rel => $sadrzaj) Util::upisi("$k/admin/$rel", $sadrzaj);
        self::obrisiMapu($stari);
        if (function_exists('opcache_reset')) @opcache_reset();

        // Javne datoteke (CSS, widget) osvježit će novi kod pri sljedećem zahtjevu,
        // jer su u ovom zahtjevu još učitane stare klase.
        touch($this->s->podaci('osvjezi'));
        return $verzija;
    }

    public static function obrisiMapu(string $d): void
    {
        if (!is_dir($d)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($d);
    }
}
