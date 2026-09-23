<?php
// Učitavanje plugina.

namespace Cjenik;

foreach (['Util', 'Podaci', 'Arhiva', 'Formati', 'Prikaz', 'Sustav', 'Objava', 'Auth', 'Azuriranje'] as $klasa) {
    require_once __DIR__ . "/lib/$klasa.php";
}

/** Zaštiti mape s podacima i kodom i na Apacheu (na ostalim poslužiteljima štiti <?php exit ?>). */
function zastitiMape(Sustav $s): void
{
    $htaccess = "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
    foreach ([$s->podaci(), $s->sustav()] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
        if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", $htaccess);
        if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
    }
}
