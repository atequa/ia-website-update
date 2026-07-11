#!/bin/bash
# Provisionne le back-office IA sur un site DÉJÀ migré (o2switch-deploy).
# Génère le bo_config.php du site depuis la source centrale, puis déploie.
# usage :
#   O2_ENV=/chemin/<site>/.env.o2switch.local \
#   SITE_NAME="Panda Restaurant" SITE_ID=pandarestaurant \
#   CLIENT_EMAIL=client@domaine.fr MAIL_FROM=prenom@domaine.fr [MODE=self|managed] \
#   ./add_site.sh /chemin/<site>
set -eu
CEN="$(cd "$(dirname "$0")" && pwd)"   # = bo-package/
SITE="${1:?chemin du dossier projet du site requis}"
ENVF="${O2_ENV:?O2_ENV requis (le .env du site)}"
: "${SITE_NAME:?}"; : "${SITE_ID:?}"; : "${CLIENT_EMAIL:?}"; : "${MAIL_FROM:?}"
MODE="${MODE:-self}"
getval(){ grep -m1 "^$1=" "$ENVF" | cut -d= -f2-; }
PRIVDIR=$(getval O2_PRIVATE_DIR); HOME_DIR="${PRIVDIR%/private}"
PUB=$(grep -m1 '^BO_UPDATE_PUBKEY=' "$CEN/../.env.backoffice" | cut -d= -f2-)
SESSION_SECRET=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 48)
# Passerelle IA centrale : jeton bearer PAR SITE (brut dans bo_config du site, hash dans ai_sites.json d'atequa-web).
GATEWAY_URL="${GATEWAY_URL:-https://www.atequa-web.com/ia}"
SITE_TOKEN=$(openssl rand -hex 32)
TOKEN_HASH=$(printf %s "$SITE_TOKEN" | shasum -a 256 | cut -d' ' -f1)
AI_SITES_FILE="${AI_SITES_FILE:-$CEN/../../atequa-web.com/private/ai_sites.json}"
[ -n "$HOME_DIR" ] || { echo "O2_PRIVATE_DIR manquant dans $ENVF"; exit 1; }
# Docroot ABSOLU : dérivé de O2_REMOTE_LIVE (relatif au home FTP = /home/<user>).
# Nomenclatures possibles : public_html (anciens sites) ou clients/<site>/www (récents).
CPUSER=$(getval O2_CPANEL_USER); REMOTE_LIVE=$(getval O2_REMOTE_LIVE)
if [ -n "$CPUSER" ] && [ -n "$REMOTE_LIVE" ]; then DOCROOT="/home/$CPUSER/$REMOTE_LIVE"; else DOCROOT="$HOME_DIR/public_html"; fi
mkdir -p "$SITE/private"

EDITABLE="["
for f in $(cd "$SITE/dist-static" && ls *.html styles.css main.js robots.txt sitemap.xml 2>/dev/null); do EDITABLE="$EDITABLE'$f',"; done
EDITABLE="${EDITABLE%,}]"

sed -e "s#__SITE_NAME__#$SITE_NAME#g" -e "s#__MAIL_FROM__#$MAIL_FROM#g" -e "s#__SITE_ID__#$SITE_ID#g" \
    -e "s#__CLIENT_EMAIL__#$CLIENT_EMAIL#g" -e "s#__SESSION_SECRET__#$SESSION_SECRET#g" \
    -e "s#__HOME__#$HOME_DIR#g" -e "s#__DOCROOT__#$DOCROOT#g" -e "s#__PUBKEY__#$PUB#g" \
    -e "s#__GATEWAY_URL__#$GATEWAY_URL#g" -e "s#__SITE_TOKEN__#$SITE_TOKEN#g" \
    "$CEN/install/private/bo_config.template.php" > "$SITE/private/bo_config.php"
# Enregistre le hash + budget par défaut dans le registre de la passerelle (à téléverser ensuite sur atequa-web).
if [ -f "$AI_SITES_FILE" ]; then
  php -r '$f=$argv[1];$d=json_decode(file_get_contents($f),true)?:["sites"=>[]];
    $d["sites"][$argv[2]]=["token_hash"=>$argv[3],"model"=>null,"budget_eur"=>5.0,"credit_eur"=>0,"enabled"=>true];
    file_put_contents($f,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));' "$AI_SITES_FILE" "$SITE_ID" "$TOKEN_HASH"
  echo "✓ $SITE_ID enregistré dans $AI_SITES_FILE (budget 5 €/mois)"
else
  echo "⚠ $AI_SITES_FILE introuvable — ajouter manuellement : \"$SITE_ID\": {\"token_hash\":\"$TOKEN_HASH\",\"model\":null,\"budget_eur\":5.0,\"credit_eur\":0,\"enabled\":true}"
fi
php -r '$p=$argv[1];$s=file_get_contents($p);$s=preg_replace("/const BO_EDITABLE = \[[^\]]*\];/","const BO_EDITABLE = ".$argv[2].";",$s);file_put_contents($p,$s);' "$SITE/private/bo_config.php" "$EDITABLE"
php -l "$SITE/private/bo_config.php" >/dev/null
# bo_path.php : indique aux fichiers admin (docroot, payload partagé) où trouver le privé (chemin ABSOLU par site)
mkdir -p "$SITE/admin"
printf "<?php define('BO_PRIVATE_DIR', '%s');\n" "$PRIVDIR" > "$SITE/admin/bo_path.php"
php -l "$SITE/admin/bo_path.php" >/dev/null
echo "✓ bo_config.php + bo_path.php générés ($SITE_ID — privé $PRIVDIR — mode $MODE — éditables $EDITABLE)"

O2_ENV="$ENVF" "$CEN/deploy_bo.sh" "$SITE"
echo ""
echo "RESTE :"
echo "  1) Créer la boîte $MAIL_FROM (envoi des magic links) sur le compte o2switch du site."
echo "  2) control.sites.json → ajouter  \"$SITE_ID\": {\"enabled\":true,\"mode\":\"$MODE\"}  puis build_control.php + git push."
echo "  3) Téléverser ai_sites.json mis à jour dans le private/ d'atequa-web (FTP sc3dm88) — sinon la passerelle renverra 401."
