<?php
// Prijava lozinkom, zaštita od pogađanja i CSRF.

namespace Cjenik;

final class Auth
{
    const MAKS_POKUSAJA = 5;
    const BLOKADA = 15 * 60;

    /** @var Sustav */
    private $s;

    public function __construct(Sustav $s)
    {
        $this->s = $s;
    }

    public function pokreniSesiju(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('cjenik_sesija');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public function instalirano(): bool
    {
        return !empty($this->s->postavke()['lozinka']);
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
        session_regenerate_id(true);
        $_SESSION['cjenik_prijava'] = true;
        $_SESSION['cjenik_verzija'] = $p['lozinkaVerzija'];
    }

    /** Prvo pokretanje: postavljanje lozinke. */
    public function instaliraj(string $lozinka): void
    {
        $this->s->zakljucano(function () use ($lozinka) {
            if ($this->instalirano()) throw new \RuntimeException('Plugin je već instaliran.');
            $p = $this->s->postavke();
            $p['lozinka'] = password_hash($lozinka, PASSWORD_DEFAULT);
            $p['lozinkaVerzija'] = bin2hex(random_bytes(8));
            $p['kljucAzuriranja'] = $p['kljucAzuriranja'] ?? bin2hex(random_bytes(20));
            $p['automatskoAzuriranje'] = $p['automatskoAzuriranje'] ?? true;
            $this->s->spremiPostavke($p);
            $this->oznaciPrijavu($p);
        });
    }

    /** @return string|null greška ili null ako je prijava uspjela */
    public function prijava(string $lozinka): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $datoteka = $this->s->podaci('pokusaji.php');
        $pokusaji = Podaci::citajJson($datoteka) ?? [];
        $sada = time();
        foreach ($pokusaji as $k => $v) if ($sada - $v['zadnji'] > self::BLOKADA) unset($pokusaji[$k]);
        $kljuc = hash('sha256', $ip);

        if (($pokusaji[$kljuc]['broj'] ?? 0) >= self::MAKS_POKUSAJA) {
            $min = (int) ceil((self::BLOKADA - ($sada - $pokusaji[$kljuc]['zadnji'])) / 60);
            return "Previše neuspjelih pokušaja. Pokušaj ponovno za $min min.";
        }
        $p = $this->s->postavke();
        if (!empty($p['lozinka']) && password_verify($lozinka, $p['lozinka'])) {
            unset($pokusaji[$kljuc]);
            Podaci::pisiJson($datoteka, $pokusaji);
            if (password_needs_rehash($p['lozinka'], PASSWORD_DEFAULT)) {
                $p['lozinka'] = password_hash($lozinka, PASSWORD_DEFAULT);
                $this->s->spremiPostavke($p);
            }
            $this->oznaciPrijavu($p);
            return null;
        }
        $pokusaji[$kljuc] = ['broj' => ($pokusaji[$kljuc]['broj'] ?? 0) + 1, 'zadnji' => $sada];
        Podaci::pisiJson($datoteka, $pokusaji);
        usleep(400000);
        return 'Pogrešna lozinka.';
    }

    public function promijeniLozinku(string $stara, string $nova): ?string
    {
        $p = $this->s->postavke();
        if (!password_verify($stara, $p['lozinka'] ?? '')) return 'Trenutna lozinka nije točna.';
        if (strlen($nova) < 10) return 'Nova lozinka mora imati barem 10 znakova.';
        $p['lozinka'] = password_hash($nova, PASSWORD_DEFAULT);
        $p['lozinkaVerzija'] = bin2hex(random_bytes(8));
        $this->s->spremiPostavke($p);
        $this->oznaciPrijavu($p);
        return null;
    }

    public function odjava(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
