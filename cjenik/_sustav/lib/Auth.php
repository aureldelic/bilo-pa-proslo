<?php
// Prijava e-mailom i lozinkom, reset lozinke e-mailom, zaštita od pogađanja i CSRF.

namespace Cjenik;

final class Auth
{
    const MAKS_POKUSAJA = 5;
    const BLOKADA = 15 * 60;
    const RESET_TRAJE = 3600;
    const RESET_RAZMAK = 5 * 60;
    const MIN_LOZINKA = 10;

    /** @var Sustav */
    private $s;

    /**
     * Slanje e-maila; testovi ga zamijene.
     * @var callable|null fn(string $prima, string $naslov, string $tekst, string $zaglavlja): bool
     */
    public static $posalji = null;

    public function __construct(Sustav $s)
    {
        $this->s = $s;
    }

    public function pokreniSesiju(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('cjenik_sesija');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
            'secure' => self::https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    private static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** Adresa sučelja iz trenutnog zahtjeva (sprema se samo pri instalaciji i uspješnoj prijavi). */
    private static function adresaIzZahtjeva(): string
    {
        $put = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
        return (self::https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $put;
    }

    public static function normalizirajEmail(string $e): string
    {
        return strtolower(trim($e));
    }

    public static function ispravanEmail(string $e): bool
    {
        return filter_var($e, FILTER_VALIDATE_EMAIL) !== false && strlen($e) <= 190;
    }

    public function instalirano(): bool
    {
        return !empty($this->s->postavke()['lozinka']);
    }

    public function email(): string
    {
        return (string) ($this->s->postavke()['email'] ?? '');
    }

    public function prijavljen(): bool
    {
        $p = $this->s->postavke();
        return !empty($_SESSION['cjenik_prijava'])
            // Promjena lozinke odjavljuje sve ostale sesije.
            && hash_equals((string) ($p['lozinkaVerzija'] ?? ''), (string) ($_SESSION['cjenik_verzija'] ?? ''));
    }

    public function csrf(): string
    {
        if (empty($_SESSION['cjenik_csrf'])) $_SESSION['cjenik_csrf'] = bin2hex(random_bytes(16));
        return $_SESSION['cjenik_csrf'];
    }

    public function provjeriCsrf(?string $token): bool
    {
        return !empty($_SESSION['cjenik_csrf']) && is_string($token) && hash_equals($_SESSION['cjenik_csrf'], $token);
    }

    private function oznaciPrijavu(array $p): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['cjenik_prijava'] = true;
        $_SESSION['cjenik_verzija'] = $p['lozinkaVerzija'];
    }

    private static function provjeriLozinku(string $l): ?string
    {
        return strlen($l) < self::MIN_LOZINKA ? 'Lozinka mora imati barem ' . self::MIN_LOZINKA . ' znakova.' : null;
    }

    /** Prvo pokretanje: e-mail (korisničko ime) i lozinka. */
    public function instaliraj(string $email, string $lozinka): ?string
    {
        $email = self::normalizirajEmail($email);
        if (!self::ispravanEmail($email)) return 'Upiši ispravnu e-mail adresu.';
        if ($g = self::provjeriLozinku($lozinka)) return $g;
        return $this->s->zakljucano(function () use ($email, $lozinka) {
            if ($this->instalirano()) return 'Plugin je već instaliran.';
            $p = $this->s->postavke();
            $p['email'] = $email;
            $p['lozinka'] = password_hash($lozinka, PASSWORD_DEFAULT);
            $p['lozinkaVerzija'] = bin2hex(random_bytes(8));
            $p['adresa'] = self::adresaIzZahtjeva();
            $p['kljucAzuriranja'] = $p['kljucAzuriranja'] ?? bin2hex(random_bytes(20));
            $p['automatskoAzuriranje'] = $p['automatskoAzuriranje'] ?? true;
            $this->s->spremiPostavke($p);
            $this->oznaciPrijavu($p);
            return null;
        });
    }

    /** Ograničenje pokušaja po IP-u (prijava i zahtjevi za reset). */
    private function ogranicenje(string $vrsta, int $maks, int $prozor, bool $zabiljezi)
    {
        $datoteka = $this->s->podaci('pokusaji.php');
        $pokusaji = Podaci::citajJson($datoteka) ?? [];
        $sada = time();
        foreach ($pokusaji as $k => $v) if ($sada - ($v['zadnji'] ?? 0) > max(self::BLOKADA, self::RESET_RAZMAK)) unset($pokusaji[$k]);
        $kljuc = $vrsta . ':' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '?');
        $zapis = $pokusaji[$kljuc] ?? ['broj' => 0, 'zadnji' => 0];
        if ($sada - $zapis['zadnji'] > $prozor) $zapis = ['broj' => 0, 'zadnji' => 0];
        if ($zapis['broj'] >= $maks) return (int) ceil(($prozor - ($sada - $zapis['zadnji'])) / 60);
        if ($zabiljezi) {
            $pokusaji[$kljuc] = ['broj' => $zapis['broj'] + 1, 'zadnji' => $sada];
            Podaci::pisiJson($datoteka, $pokusaji);
        }
        return null;
    }

    private function ponistiOgranicenje(string $vrsta): void
    {
        $datoteka = $this->s->podaci('pokusaji.php');
        $pokusaji = Podaci::citajJson($datoteka) ?? [];
        unset($pokusaji[$vrsta . ':' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '?')]);
        Podaci::pisiJson($datoteka, $pokusaji);
    }

    /** @return string|null greška ili null ako je prijava uspjela */
    public function prijava(string $email, string $lozinka): ?string
    {
        $min = $this->ogranicenje('prijava', self::MAKS_POKUSAJA, self::BLOKADA, false);
        if ($min !== null) return "Previše neuspjelih pokušaja. Pokušaj ponovno za $min min.";

        $p = $this->s->postavke();
        // Instalacije iz verzije 1.0.0 nemaju e-mail — tamo se prijavljuje samo lozinkom.
        $emailOk = empty($p['email']) || hash_equals((string) $p['email'], self::normalizirajEmail($email));
        if ($emailOk && !empty($p['lozinka']) && password_verify($lozinka, $p['lozinka'])) {
            $this->ponistiOgranicenje('prijava');
            if (password_needs_rehash($p['lozinka'], PASSWORD_DEFAULT)) $p['lozinka'] = password_hash($lozinka, PASSWORD_DEFAULT);
            // Adresa za poveznicu u e-mailu osvježava se samo nakon uspješne prijave (npr. nova domena).
            $p['adresa'] = self::adresaIzZahtjeva();
            $this->s->spremiPostavke($p);
            $this->oznaciPrijavu($p);
            return null;
        }
        $this->ogranicenje('prijava', self::MAKS_POKUSAJA, self::BLOKADA, true);
        usleep(400000);
        return 'Pogrešan e-mail ili lozinka.';
    }

    public function promijeniLozinku(string $stara, string $nova): ?string
    {
        $p = $this->s->postavke();
        if (!password_verify($stara, $p['lozinka'] ?? '')) return 'Trenutna lozinka nije točna.';
        if ($g = self::provjeriLozinku($nova)) return $g;
        $p['lozinka'] = password_hash($nova, PASSWORD_DEFAULT);
        $p['lozinkaVerzija'] = bin2hex(random_bytes(8));
        unset($p['reset']);
        $this->s->spremiPostavke($p);
        $this->oznaciPrijavu($p);
        return null;
    }

    public function promijeniEmail(string $lozinka, string $email): ?string
    {
        $p = $this->s->postavke();
        if (!password_verify($lozinka, $p['lozinka'] ?? '')) return 'Lozinka nije točna.';
        $email = self::normalizirajEmail($email);
        if (!self::ispravanEmail($email)) return 'Upiši ispravnu e-mail adresu.';
        $p['email'] = $email;
        unset($p['reset']);
        $this->s->spremiPostavke($p);
        return null;
    }

    /* ---------- Zaboravljena lozinka ---------- */

    /**
     * Pošalji poveznicu za reset ako e-mail odgovara. Odgovor je uvijek isti
     * (ne otkriva postoji li e-mail), osim kod ograničenja ili kvara slanja.
     * @return string|null greška za prikaz ili null
     */
    public function zatraziReset(string $email): ?string
    {
        $min = $this->ogranicenje('reset', 3, self::RESET_RAZMAK * 3, true);
        if ($min !== null) return "Previše zahtjeva. Pokušaj ponovno za $min min.";

        $p = $this->s->postavke();
        $email = self::normalizirajEmail($email);
        if (empty($p['email']) || !hash_equals((string) $p['email'], $email)) return null;
        if (!empty($p['reset']['poslano']) && time() - $p['reset']['poslano'] < self::RESET_RAZMAK) return null;

        $token = bin2hex(random_bytes(24));
        $p['reset'] = ['hash' => hash('sha256', $token), 'istjece' => time() + self::RESET_TRAJE, 'poslano' => time()];
        $this->s->spremiPostavke($p);

        // Adresa spremljena pri instalaciji, NE iz zahtjeva (zaštita od podmetanja Host zaglavlja).
        $poveznica = ($p['adresa'] ?? '') . '?reset=' . $token;
        $domena = parse_url((string) ($p['adresa'] ?? ''), PHP_URL_HOST) ?: 'localhost';
        $domena = preg_replace('/^www\./', '', $domena);
        $posiljatelj = $p['posiljatelj'] ?? ('noreply@' . $domena);
        $tekst = "Pozdrav,\n\nzatražena je nova lozinka za uređivanje cjenika na $domena.\n\n"
            . "Novu lozinku postavi preko ove poveznice (vrijedi 1 sat):\n$poveznica\n\n"
            . "Ako nisi ti zatražio/la novu lozinku, zanemari ovu poruku. Stara lozinka i dalje vrijedi.\n";
        $zaglavlja = implode("\r\n", [
            'From: Cjenik <' . $posiljatelj . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ]);
        $naslov = '=?UTF-8?B?' . base64_encode("Nova lozinka za cjenik ($domena)") . '?=';

        $posalji = self::$posalji ?? function ($prima, $naslov, $tekst, $zaglavlja) use ($posiljatelj) {
            // Neki hostinzi ne dopuštaju -f; tada pokušaj bez njega.
            return @mail($prima, $naslov, $tekst, $zaglavlja, '-f' . $posiljatelj) || @mail($prima, $naslov, $tekst, $zaglavlja);
        };
        if (!$posalji($email, $naslov, $tekst, $zaglavlja)) {
            unset($p['reset']);
            $this->s->spremiPostavke($p);
            return 'Slanje e-maila nije uspjelo. Javi se webmasteru.';
        }
        return null;
    }

    public function ispravanReset(string $token): bool
    {
        $r = $this->s->postavke()['reset'] ?? null;
        return $r && $token !== '' && time() < $r['istjece'] && hash_equals($r['hash'], hash('sha256', $token));
    }

    public function postaviNovuLozinku(string $token, string $lozinka): ?string
    {
        if (!$this->ispravanReset($token)) return 'Poveznica je istekla ili je već iskorištena. Zatraži novu.';
        if ($g = self::provjeriLozinku($lozinka)) return $g;
        return $this->s->zakljucano(function () use ($token, $lozinka) {
            if (!$this->ispravanReset($token)) return 'Poveznica je istekla ili je već iskorištena. Zatraži novu.';
            $p = $this->s->postavke();
            $p['lozinka'] = password_hash($lozinka, PASSWORD_DEFAULT);
            $p['lozinkaVerzija'] = bin2hex(random_bytes(8));
            unset($p['reset']);
            $this->s->spremiPostavke($p);
            $this->ponistiOgranicenje('prijava');
            $this->oznaciPrijavu($p);
            return null;
        });
    }

    public function odjava(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }
}
