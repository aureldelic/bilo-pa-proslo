<?php
// Zajedničke pomoćne funkcije: datumi (Europe/Zagreb), novac, escaping.

namespace Cjenik;

final class Util
{
    const NAZIV = 'Bilo pa prošlo';
    const ZONA = 'Europe/Zagreb';
    const DATUM_SIDRENJA = '2026-09-10';

    public static function zona(): \DateTimeZone
    {
        return new \DateTimeZone(self::ZONA);
    }

    /** @param string|int|\DateTimeInterface|null $d */
    public static function datum($d = null): \DateTimeImmutable
    {
        if ($d instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromFormat('U', (string) $d->getTimestamp())->setTimezone(self::zona());
        }
        if (is_int($d)) {
            return (new \DateTimeImmutable('@' . $d))->setTimezone(self::zona());
        }
        return (new \DateTimeImmutable($d ?? 'now'))->setTimezone(self::zona());
    }

    /** ISO 8601 s lokalnim pomakom, npr. 2026-10-01T07:45:00+02:00 */
    public static function iso($d): string
    {
        return self::datum($d)->format('c');
    }

    /** Lokalni kalendarski dan 'YYYY-MM-DD'. */
    public static function dan($d): string
    {
        return self::datum($d)->format('Y-m-d');
    }

    /** Razlika u kalendarskim danima između dva 'YYYY-MM-DD'. */
    public static function razlikaDana(string $od, string $do): int
    {
        $a = new \DateTimeImmutable($od . 'T00:00:00Z');
        $b = new \DateTimeImmutable($do . 'T00:00:00Z');
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /** 'YYYY-MM-DD' -> '10.9.2026.' (preporuka ministarstva) */
    public static function datumKratko(string $ymd): string
    {
        if (!$ymd) return '';
        [$y, $m, $d] = explode('-', $ymd);
        return ((int) $d) . '.' . ((int) $m) . '.' . $y . '.';
    }

    /** '01.10.2026. u 07:45' */
    public static function datumVrijemePrikaz($d): string
    {
        return self::datum($d)->format('d.m.Y. \u H:i');
    }

    public static function novac($n): string
    {
        return number_format((float) $n, 2, ',', '.') . "\u{00A0}€";
    }

    /** Iznos za strojno čitanje: točka kao decimalni separator. */
    public static function iznos($n): string
    {
        return number_format((float) $n, 2, '.', '');
    }

    /**
     * '45', '45,5', '1.234,50 €' -> float; prazno -> null; neispravno -> NAN.
     * @param mixed $v
     * @return float|null
     */
    public static function parsirajIznos($v)
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return round((float) $v, 2);
        $s = preg_replace('/\s|€|eur/iu', '', (string) $v);
        if ($s === '') return null;
        if (strpos($s, ',') !== false) $s = str_replace(',', '.', str_replace('.', '', $s));
        return preg_match('/^\d+(\.\d+)?$/', $s) ? round((float) $s, 2) : NAN;
    }

    public static function esc($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function xml($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Atomsko pisanje datoteke. */
    public static function upisi(string $putanja, string $sadrzaj): void
    {
        $dir = dirname($putanja);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Ne mogu napraviti mapu $dir");
        }
        $tmp = $putanja . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $sadrzaj) === false) {
            throw new \RuntimeException("Ne mogu pisati u $dir (provjeri dozvole)");
        }
        if (!rename($tmp, $putanja)) {
            @unlink($tmp);
            throw new \RuntimeException("Ne mogu spremiti $putanja");
        }
    }
}
