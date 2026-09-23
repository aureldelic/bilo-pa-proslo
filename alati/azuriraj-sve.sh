#!/usr/bin/env bash
# Daljinsko ažuriranje svih instalacija odjednom.
# Datoteka stranice.txt: jedan redak po stranici, "URL-admina ključ", npr.
#   https://salonana.hr/cjenik/admin/ bb115c8cd788be15f0af72df4db2ea74d5d0baf8
# Ključ se vidi u sučelju: Postavke → Ažuriranje plugina.
set -u
popis="${1:-stranice.txt}"
while read -r url kljuc; do
  [[ -z "$url" || "$url" == \#* ]] && continue
  printf '%s  ' "$url"
  # Drugi poziv osvježi javne datoteke (CSS, widget) novim kodom.
  curl -fsS -X POST --data-urlencode "kljuc=$kljuc" "${url%/}/?api=daljinsko-azuriranje" || printf '(greška)'
  curl -fsS -o /dev/null -X POST --data-urlencode "kljuc=$kljuc" "${url%/}/?api=daljinsko-azuriranje"
  echo
done < "$popis"
