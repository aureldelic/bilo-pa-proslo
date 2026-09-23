<?php
// Slanje e-maila: SMTP (ako je podešen), a PHP mail() kao rezerva.
// SMTP klijent je ugrađen (bez vanjskih biblioteka): SSL (465), STARTTLS (587) ili bez šifriranja.

namespace Cjenik;

final class Posta
{
    const VRIJEME = 15;

    /**
     * Zamjena za slanje u testovima: fn(array $poruka): void (baca iznimku za neuspjeh).
     * @var callable|null
     */
    public static $prijevoz = null;

    /**
     * Može li hosting slati preko PHP mail()?
     * 'da'  — funkcija postoji i sendmail je na mjestu,
     * 'ne'  — funkcija je isključena ili nema programa za slanje.
     * Napomena: 'da' znači da slanje postoji, ne jamči da mail neće završiti u spamu.
     */
    public static function mailDostupan(): string
    {
        if (!function_exists('mail')) return 'ne';
        $iskljucene = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('mail', $iskljucene, true)) return 'ne';
        if (stripos(PHP_OS, 'WIN') === 0) return ini_get('SMTP') ? 'da' : 'ne';
        $sendmail = trim((string) ini_get('sendmail_path'));
        if ($sendmail === '') return 'ne';
        $program = strtok($sendmail, ' ');
        // open_basedir može sakriti program; tada vjerujemo postavci.
        if (ini_get('open_basedir')) return 'da';
        return @is_executable($program) ? 'da' : 'ne';
    }

    /** Podešeni SMTP ili null. */
    public static function smtp(array $postavke): ?array
    {
        $s = $postavke['smtp'] ?? null;
        return is_array($s) && !empty($s['host']) ? $s : null;
    }

    /** Domena stranice iz spremljene adrese (za pošiljatelja i EHLO). */
    public static function domena(array $postavke): string
    {
        $d = parse_url((string) ($postavke['adresa'] ?? ''), PHP_URL_HOST) ?: 'localhost';
        return preg_replace('/^www\./', '', $d);
    }

    public static function posiljatelj(array $postavke): string
    {
        $smtp = self::smtp($postavke);
        if ($smtp) return $smtp['posiljatelj'] ?: $smtp['korisnik'];
        return $postavke['posiljatelj'] ?? ('noreply@' . self::domena($postavke));
    }

    /**
     * Pošalji e-mail: prvo SMTP, onda mail(). Baca \RuntimeException s objašnjenjem ako ništa ne uspije.
     */
    public static function posalji(array $postavke, string $prima, string $naslov, string $tekst): void
    {
        $poruka = [
            'od' => self::posiljatelj($postavke),
            'ime' => $postavke['smtp']['ime'] ?? 'Cjenik',
            'prima' => $prima,
            'naslov' => $naslov,
            'tekst' => $tekst,
            'domena' => self::domena($postavke),
        ];
        if (self::$prijevoz) {
            (self::$prijevoz)($poruka);
            return;
        }
        $greske = [];
        if ($smtp = self::smtp($postavke)) {
            try {
                self::posaljiSmtp($smtp, $poruka);
                return;
            } catch (\RuntimeException $e) {
                $greske[] = 'SMTP: ' . $e->getMessage();
            }
        }
        if (self::mailDostupan() === 'ne') {
            $greske[] = 'hosting ne podržava PHP mail(), a SMTP nije podešen';
        } elseif (self::posaljiMail($poruka)) {
            return;
        } else {
            $greske[] = 'PHP mail() nije uspio';
        }
        throw new \RuntimeException(implode('; ', $greske));
    }

    private static function bezPrijeloma(string $s): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $s));
    }

    private static function kodiraj(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    /** Zaglavlja i tijelo poruke (tijelo u base64, pa nema problema s točkama i dugim recima). */
    private static function sastavi(array $p): array
    {
        $od = self::bezPrijeloma($p['od']);
        $zaglavlja = [
            'Date: ' . date('r'),
            'From: ' . self::kodiraj(self::bezPrijeloma($p['ime'])) . " <$od>",
            'To: <' . self::bezPrijeloma($p['prima']) . '>',
            'Subject: ' . self::kodiraj(self::bezPrijeloma($p['naslov'])),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::bezPrijeloma($p['domena']) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return [$zaglavlja, rtrim(chunk_split(base64_encode($p['tekst']), 76, "\r\n"))];
    }

    private static function posaljiMail(array $p): bool
    {
        if (!function_exists('mail')) return false;
        [$zaglavlja, $tijelo] = self::sastavi($p);
        // mail() sam dodaje To i Subject.
        $zaglavlja = array_values(array_filter($zaglavlja, function ($z) {
            return strncmp($z, 'To:', 3) !== 0 && strncmp($z, 'Subject:', 8) !== 0;
        }));
        $naslov = self::kodiraj(self::bezPrijeloma($p['naslov']));
        $prima = self::bezPrijeloma($p['prima']);
        $od = self::bezPrijeloma($p['od']);
        // Neki hostinzi ne dopuštaju -f; tada pokušaj bez njega.
        return @mail($prima, $naslov, $tijelo, implode("\r\n", $zaglavlja), '-f' . $od)
            || @mail($prima, $naslov, $tijelo, implode("\r\n", $zaglavlja));
    }

    /* ---------- SMTP ---------- */

    /** @param array $smtp host, port, sifriranje (ssl|tls|nema), korisnik, lozinka */
    public static function posaljiSmtp(array $smtp, array $p): void
    {
        $sifriranje = $smtp['sifriranje'] ?? 'ssl';
        $port = (int) ($smtp['port'] ?? 0) ?: ($sifriranje === 'ssl' ? 465 : 587);
        $host = trim((string) $smtp['host']);
        $kontekst = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $veza = @stream_socket_client(
            ($sifriranje === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port",
            $brojGreske, $opisGreske, self::VRIJEME, STREAM_CLIENT_CONNECT, $kontekst
        );
        if (!$veza) {
            throw new \RuntimeException("ne mogu se spojiti na $host:$port ($opisGreske). Provjeri poslužitelj, port i šifriranje.");
        }
        stream_set_timeout($veza, self::VRIJEME);

        try {
            self::ocekuj($veza, [220]);
            $ehlo = 'EHLO ' . (self::bezPrijeloma($p['domena']) ?: 'localhost');
            $mogucnosti = self::naredba($veza, $ehlo, [250]);

            if ($sifriranje === 'tls') {
                if (stripos($mogucnosti, 'STARTTLS') === false) throw new \RuntimeException('poslužitelj ne podržava STARTTLS. Probaj SSL na portu 465.');
                self::naredba($veza, 'STARTTLS', [220]);
                $metoda = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $metoda |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $metoda |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                if (!@stream_socket_enable_crypto($veza, true, $metoda)) throw new \RuntimeException('TLS nije uspio (certifikat poslužitelja?).');
                $mogucnosti = self::naredba($veza, $ehlo, [250]);
            }

            $korisnik = (string) ($smtp['korisnik'] ?? '');
            if ($korisnik !== '') {
                $lozinka = (string) ($smtp['lozinka'] ?? '');
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $mogucnosti)) {
                    self::naredba($veza, 'AUTH PLAIN ' . base64_encode("\0$korisnik\0$lozinka"), [235], 'prijava nije uspjela. Provjeri korisničko ime i lozinku.');
                } else {
                    self::naredba($veza, 'AUTH LOGIN', [334]);
                    self::naredba($veza, base64_encode($korisnik), [334], 'prijava nije uspjela. Provjeri korisničko ime.');
                    self::naredba($veza, base64_encode($lozinka), [235], 'prijava nije uspjela. Provjeri korisničko ime i lozinku.');
                }
            }

            [$zaglavlja, $tijelo] = self::sastavi($p);
            self::naredba($veza, 'MAIL FROM:<' . self::bezPrijeloma($p['od']) . '>', [250], 'poslužitelj ne dopušta tu adresu pošiljatelja. Pošiljatelj mora biti isti kao SMTP korisnik.');
            self::naredba($veza, 'RCPT TO:<' . self::bezPrijeloma($p['prima']) . '>', [250, 251], 'poslužitelj je odbio primatelja.');
            self::naredba($veza, 'DATA', [354]);
            self::naredba($veza, implode("\r\n", $zaglavlja) . "\r\n\r\n" . $tijelo . "\r\n.", [250], 'poslužitelj je odbio poruku.');
            @fwrite($veza, "QUIT\r\n");
        } finally {
            fclose($veza);
        }
    }

    /** @return string cijeli odgovor */
    private static function naredba($veza, string $naredba, array $kodovi, string $objasnjenje = ''): string
    {
        if (@fwrite($veza, $naredba . "\r\n") === false) throw new \RuntimeException('veza je prekinuta.');
        return self::ocekuj($veza, $kodovi, $objasnjenje);
    }

    private static function ocekuj($veza, array $kodovi, string $objasnjenje = ''): string
    {
        $odgovor = '';
        while (($red = fgets($veza, 1024)) !== false) {
            $odgovor .= $red;
            if (strlen($red) < 4 || $red[3] === ' ') break;
        }
        if ($odgovor === '') {
            $meta = stream_get_meta_data($veza);
            throw new \RuntimeException($meta['timed_out'] ? 'poslužitelj ne odgovara (istek vremena).' : 'veza je prekinuta.');
        }
        $kod = (int) substr($odgovor, 0, 3);
        if (!in_array($kod, $kodovi, true)) {
            $poruka = trim(preg_replace('/\s+/', ' ', $odgovor));
            throw new \RuntimeException(($objasnjenje ? "$objasnjenje " : '') . "(odgovor: $poruka)");
        }
        return $odgovor;
    }
}
