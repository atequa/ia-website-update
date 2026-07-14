#!/bin/bash
# Ajoute borisalataille au plan de contrôle central signé (kill-switch) puis publie.
# La clé privée de signature n'est JAMAIS écrite : tu la passes en variable au lancement.
#
# USAGE (à lancer dans TON terminal, pas dans le chat) :
#   cd "$HOME/Documents/Claude Code/ia-website-update/bo-package"
#   BO_SIGN_PRIVKEY_B64='<colle ici la clé privée depuis Proton Pass>' bash sign_control_boris.sh
#
# C'est idempotent : relançable sans risque.
set -eu
cd "$(dirname "$0")"

: "${BO_SIGN_PRIVKEY_B64:?Manque la clé privée : relance avec BO_SIGN_PRIVKEY_B64='...' devant la commande}"

echo "1/4 · synchronisation du repo partagé…"
git fetch -q origin
git reset -q --hard origin/main

echo "2/4 · ajout de borisalataille à control.sites.json (si absent)…"
php -r '
$f="control.sites.json";
$d=json_decode(file_get_contents($f),true);
$d["sites"]["borisalataille"]=["enabled"=>true,"mode"=>"managed"];
file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
echo "   sites: ".implode(", ", array_keys($d["sites"]))."\n";
'

echo "3/4 · signature du contrôle (build_control.php)…"
php build_control.php
echo "   -> dist/control.json régénéré et signé"

echo "4/4 · commit + push…"
git add -A
git -c user.email=bot@atequa.net -c user.name="Atequa Bot" commit -q -m "control: ajout borisalataille (kill-switch)"
git push -q origin main
echo "✓ Terminé. Boris est maintenant dans le plan de contrôle signé (kill-switch actif)."
