<?php
// Putanje, zaključavanje i postavke instalacije.
//
// Struktura na poslužitelju (mapa "cjenik" u korijenu web stranice):
//   cjenik/admin/        sučelje (prijava lozinkom)
//   cjenik/_sustav/      kod plugina — mijenja ga ažuriranje
//   cjenik/_podaci/      podaci i postavke — ažuriranje ga nikad ne dira
//   cjenik/arhiva/       objavljeni XML/CSV cjenici
//   cjenik/index.html    javna stranica, cjenik.xml, tablica.html, cjenik-widget.js

namespace Cjenik;

final class Sustav
{
    const UREDIVO = ['obveznik', 'objekt', 'stavke', 'naslovStranice', 'napomena', 'formati'];

    /** @var string */
    private $korijen;

    public function __construct(string $korijen)
    {
        $this->korijen = rtrim($korijen, '/\\');
    }

    public function korijen(): string { return $this->korijen; }
    public function stranica(): string { return dirname($this->korijen); }
    public function podaci(string $d = ''): string { return $this->korijen . '/_podaci' . ($d !== '' ? "/$d" : ''); }
    public function sustav(string $d = ''): string { return $this->korijen . '/_sustav' . ($d !== '' ? "/$d" : ''); }
    public function datotekaPodataka(): string { return $this->podaci('cjenik.php'); }
    public function datotekaNacrta(): string { return $this->podaci('nacrt.php'); }

    public function arhiva(string $ime): string
    {
        if ($ime === '' || strpos($ime, '/') !== false || strpos($ime, '\\') !== false || $ime[0] === '.') {
            throw new \InvalidArgumentException('Neispravan naziv datoteke');
        }
        return $this->korijen . '/arhiva/' . $ime;
    }

    public function verzija(): string
    {
        return trim((string) @file_get_contents($this->sustav('VERZIJA'))) ?: '0.0.0';
    }

    /** Objavljeno stanje (ono što je javno). */
    public function objavljeno(): array
    {
        return Podaci::ucitaj($this->datotekaPodataka());
    }

    /** Nacrt: objavljeno stanje preko kojeg su neobjavljene izmjene iz sučelja. */
    public function nacrt(): array
    {
        $p = $this->objavljeno();
        $n = Podaci::citajJson($this->datotekaNacrta());
        if ($n) foreach (self::UREDIVO as $k) if (array_key_exists($k, $n)) $p[$k] = $n[$k];
        $p['stavke'] = array_map([Podaci::class, 'novaStavka'], (array) $p['stavke']);
        return $p;
    }

    public function spremiNacrt(array $unos): void
    {
        $n = [];
        foreach (self::UREDIVO as $k) if (array_key_exists($k, $unos)) $n[$k] = $unos[$k];
        Podaci::pisiJson($this->datotekaNacrta(), $n);
    }

    public function obrisiNacrt(): void
    {
        @unlink($this->datotekaNacrta());
    }

    /** Postavke instalacije (lozinka, ključ za daljinsko ažuriranje…). */
    public function postavke(): array
    {
        return Podaci::citajJson($this->podaci('postavke.php')) ?? [];
    }

    public function spremiPostavke(array $p): void
    {
        Podaci::pisiJson($this->podaci('postavke.php'), $p);
    }

    /** Izvrši funkciju pod ekskluzivnim zaključavanjem (dvije objave u isto vrijeme). */
    public function zakljucano(callable $fn)
    {
        if (!is_dir($this->podaci())) mkdir($this->podaci(), 0755, true);
        $f = fopen($this->podaci('.zakljucano'), 'c');
        flock($f, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }

    /**
     * Datoteka stranice za umetanje tablice — mora postojati, biti .html/.htm
     * i nalaziti se unutar korijena stranice (ne u mapi cjenik).
     */
    public function datotekaStranice(string $rel): ?string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (!preg_match('/\.html?$/i', $rel)) return null;
        $put = realpath($this->stranica() . '/' . $rel);
        $korijenStranice = realpath($this->stranica());
        $korijenCjenika = realpath($this->korijen);
        if ($put === false || !is_file($put)) return null;
        if (strpos($put, $korijenStranice . DIRECTORY_SEPARATOR) !== 0) return null;
        if (strpos($put, $korijenCjenika . DIRECTORY_SEPARATOR) === 0) return null;
        return $put;
    }

    /** Relativna URL putanja od mape $od do datoteke $do. */
    public function relativno(string $od, string $do): string
    {
        $a = array_values(array_filter(explode('/', str_replace('\\', '/', realpath($od) ?: $od)), 'strlen'));
        $b = array_values(array_filter(explode('/', str_replace('\\', '/', (realpath(dirname($do)) ?: dirname($do)) . '/' . basename($do))), 'strlen'));
        $i = 0;
        while ($i < count($a) && $i < count($b) && $a[$i] === $b[$i]) $i++;
        return str_repeat('../', count($a) - $i) . implode('/', array_slice($b, $i));
    }

    /** Provjera može li PHP pisati tamo gdje treba (za prikaz pri instalaciji). */
    public function provjeraDozvola(): array
    {
        $g = [];
        foreach ([$this->korijen, $this->podaci(), $this->korijen . '/arhiva', $this->sustav()] as $d) {
            if (!is_dir($d)) @mkdir($d, 0755, true);
            if (!is_dir($d) || !is_writable($d)) $g[] = 'PHP ne može pisati u mapu ' . basename(dirname($d)) . '/' . basename($d);
        }
        if (version_compare(PHP_VERSION, '7.4', '<')) $g[] = 'Potreban je PHP 7.4 ili noviji (sad je ' . PHP_VERSION . ').';
        return $g;
    }
}
