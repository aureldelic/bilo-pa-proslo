<?php
// Lažni SMTP poslužitelj za testove: php lazni-smtp.php <port> <izlazna-datoteka>
// Prihvaća jednu vezu, traži korisnika "noreply@salonana.hr" s lozinkom "tajna", poruku sprema u datoteku.

[, $port, $izlaz] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$server) exit(1);
fwrite(STDOUT, "spreman\n");
$v = stream_socket_accept($server, 20);
if (!$v) exit(1);

$zapis = ['naredbe' => [], 'data' => ''];
$posalji = function ($s) use ($v) { fwrite($v, $s . "\r\n"); };
$posalji('220 lazni.smtp ESMTP');
while (($red = fgets($v)) !== false) {
    $red = rtrim($red, "\r\n");
    $zapis['naredbe'][] = $red;
    if (stripos($red, 'EHLO') === 0) {
        fwrite($v, "250-lazni.smtp\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
    } elseif (stripos($red, 'AUTH PLAIN ') === 0) {
        $ok = base64_decode(substr($red, 11)) === "\0noreply@salonana.hr\0tajna";
        $posalji($ok ? '235 OK' : '535 5.7.8 Authentication failed');
    } elseif (stripos($red, 'MAIL FROM:') === 0 || stripos($red, 'RCPT TO:') === 0) {
        $posalji('250 OK');
    } elseif ($red === 'DATA') {
        $posalji('354 Kreni');
        while (($d = fgets($v)) !== false && rtrim($d, "\r\n") !== '.') $zapis['data'] .= $d;
        $posalji('250 Primljeno');
    } elseif ($red === 'QUIT') {
        $posalji('221 Bok');
        break;
    } else {
        $posalji('500 Nepoznato');
    }
}
file_put_contents($izlaz, json_encode($zapis));
