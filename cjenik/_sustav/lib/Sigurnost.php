<?php
// Provjera da podaci (lozinke, SMTP) nisu javno dostupni preko weba.
//
// U _podaci/ se drži kontrolna datoteka zaštićena jednako kao i prave (<?php exit;).
// Plugin je dohvati preko vlastite javne adrese: ako se u odgovoru vidi kontrolna
// oznaka, poslužitelj izlaže datoteke iz _podaci/ i treba ga popraviti.
// Veza ide uvijek na vlastiti poslužitelj (SERVER_ADDR), bez obzira na Host zaglavlje.

namespace Cjenik;

final class Sigurnost
{
    const OZNAKA = 'cjenik-kontrolna-oznaka-3f9a1c';
    const DATOTEKA = 'provjera.php';

    public static function pripremi(Sustav $s): void
    {
        $p = $s->podaci(self::DATOTEKA);
        if (!is_file($p)) Podaci::pisiJson($p, ['oznaka' => self::OZNAKA]);
    }

    /** Javna adresa kontrolne datoteke iz adrese sučelja (…/cjenik/admin/). */
    public static function adresaProvjere(string $adresaAdmina): string
    {
        return preg_replace('#admin/?$#', '', $adresaAdmina) . '_podaci/' . self::DATOTEKA;
    }

    /**
     * @return array ['stanje' => 'ok'|'izlozeno'|'nepoznato', 'poruka' => string, 'url' => string]
     */
    public static function provjeri(Sustav $s, string $adresaAdmina, ?string $ipPosluzitelja = null): array
    {
        self::pripremi($s);
        $url = self::adresaProvjere($adresaAdmina);
        $odgovor = self::dohvati($url, $ipPosluzitelja ?? ($_SERVER['SERVER_ADDR'] ?? null));
        if ($odgovor === null) {
            return ['stanje' => 'nepoznato', 'url' => $url,
                'poruka' => 'Poslužitelj ne može sam sebi poslati zahtjev, pa zaštitu nije moguće automatski provjeriti. Otvori poveznicu u pregledniku: mora se prikazati prazna stranica ili greška 404.'];
        }
        [$status, $tijelo] = $odgovor;
        if (strpos($tijelo, self::OZNAKA) !== false) {
            return ['stanje' => 'izlozeno', 'url' => $url,
                'poruka' => 'Datoteke s podacima (lozinke, SMTP) javno su dostupne preko weba! Poslužitelj ne izvršava PHP u mapi _podaci ili je poslužuje kao tekst. Ne upisuj lozinke dok webmaster to ne popravi.'];
        }
        return ['stanje' => 'ok', 'url' => $url, 'poruka' => "Podaci nisu javno dostupni (odgovor poslužitelja: $status)."];
    }

    /** @return array{0:int,1:string}|null */
    private static function dohvati(string $url, ?string $ip): ?array
    {
        $dijelovi = parse_url($url);
        if (empty($dijelovi['host']) || !in_array($dijelovi['scheme'] ?? '', ['http', 'https'], true)) return null;

        if (function_exists('curl_init')) {
            $c = curl_init($url);
            $opcije = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                // Čitamo samo vlastitu kontrolnu datoteku i ništa ne šaljemo, pa certifikat nije bitan.
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'cjenik-provjera-zastite',
            ];
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                $port = $dijelovi['port'] ?? ($dijelovi['scheme'] === 'https' ? 443 : 80);
                $ipZaCurl = strpos($ip, ':') !== false ? "[$ip]" : $ip;
                $opcije[CURLOPT_RESOLVE] = [$dijelovi['host'] . ':' . $port . ':' . $ipZaCurl];
            }
            curl_setopt_array($c, $opcije);
            $tijelo = curl_exec($c);
            $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            if (PHP_VERSION_ID < 80000) curl_close($c);
            return $tijelo === false || $status === 0 ? null : [$status, (string) $tijelo];
        }

        // Bez cURL-a veza se ne može usmjeriti na vlastiti IP, pa provjeravamo samo vlastito ime poslužitelja.
        if (isset($_SERVER['SERVER_NAME']) && $dijelovi['host'] !== $_SERVER['SERVER_NAME']) return null;
        $kontekst = stream_context_create([
            'http' => ['timeout' => 8, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => 'cjenik-provjera-zastite'],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $tijelo = @file_get_contents($url, false, $kontekst);
        $zaglavlja = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
        if ($tijelo === false || empty($zaglavlja[0])) return null;
        preg_match('/\s(\d{3})\s/', $zaglavlja[0] . ' ', $m);
        return [(int) ($m[1] ?? 0), $tijelo];
    }
}
