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
[ -n "$HOME_DIR" ] || { echo "O2_PRIVATE_DIR manquant dans $ENVF"; exit 1; }
mkdir -p "$SITE/private"

EDITABLE="["
for f in $(cd "$SITE/dist-static" && ls *.html styles.css main.js robots.txt sitemap.xml 2>/dev/null); do EDITABLE="$EDITABLE'$f',"; done
EDITABLE="${EDITABLE%,}]"

sed -e "s#__SITE_NAME__#$SITE_NAME#g" -e "s#__MAIL_FROM__#$MAIL_FROM#g" -e "s#__SITE_ID__#$SITE_ID#g" \
    -e "s#__CLIENT_EMAIL__#$CLIENT_EMAIL#g" -e "s#__SESSION_SECRET__#$SESSION_SECRET#g" \
    -e "s#__HOME__#$HOME_DIR#g" -e "s#__PUBKEY__#$PUB#g" \
    "$CEN/install/private/bo_config.template.php" > "$SITE/private/bo_config.php"
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
echo "  3) (mode managed) pré-remplir la clé API dans <home>/private/bo_secret.json."
