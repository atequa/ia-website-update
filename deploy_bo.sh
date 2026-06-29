#!/bin/bash
# Déploie le back-office sur un site : code partagé depuis LA SOURCE CENTRALE (ce repo)
# + le bo_config.php propre au site. NE TOUCHE JAMAIS au contenu du site. Pas de --delete.
#   - auto-updatable : bo-package/dist/payload/{admin/*.php, bo_llm.php, bo_providers.json}
#   - trust-root     : bo-package/install/{admin/.htaccess, private/bo_auth|bo_control|bo_updater}
#   - par site       : <site>/private/bo_config.php
# usage : O2_ENV=/chemin/<site>/.env.o2switch.local ./deploy_bo.sh /chemin/<site>
set -u
CEN="$(cd "$(dirname "$0")" && pwd)"   # = bo-package/
SITE="${1:?chemin du projet du site requis}"
ENVF="${O2_ENV:?O2_ENV requis (le .env du site)}"
getval(){ grep -m1 "^$1=" "$ENVF" | cut -d= -f2-; }
U=$(getval O2_CPANEL_USER); P=$(getval O2_CPANEL_PASSWORD)
H=$(getval O2_FTP_HOST); [ -z "$H" ] && H=$(getval O2_CPANEL_HOST)
RLIVE="${O2_DOCROOT_REMOTE:-$(getval O2_REMOTE_LIVE)}"; RPRIV=$(getval O2_PRIVATE_REMOTE)
# O2_DOCROOT_REMOTE permet de cibler la preprod (ex: clients/x_fr/sd/preprod) au lieu du live.

up(){ # <dir local> <dir distant> <fichiers...>
  local d="$1" r="$2"; shift 2
  ( cd "$d" 2>/dev/null && lftp -u "$U,$P" "ftp://$H" -e \
     "set ssl:verify-certificate no; set ftp:ssl-protect-data true; mkdir -p $r; cd $r; mput $*; bye" ) \
     2>&1 | grep -iE 'error|denied|fatal' || true
}

up "$CEN/dist/payload/admin" "$RLIVE/admin" '*.php'
up "$SITE/admin" "$RLIVE/admin" 'bo_path.php'      # par site : localise le privé (hors payload)
( cd "$CEN/install/admin" && lftp -u "$U,$P" "ftp://$H" -e \
   "set ssl:verify-certificate no; cd $RLIVE/admin; put .htaccess -o .htaccess; bye" ) 2>&1 | grep -iE 'error|denied' || true
# Durcissement du dossier des téléversements (aucun script exécutable) — fichier d'infra, pas du contenu.
( cd "$CEN/install/docroot/assets" && lftp -u "$U,$P" "ftp://$H" -e \
   "set ssl:verify-certificate no; mkdir -p $RLIVE/assets; cd $RLIVE/assets; put .htaccess -o .htaccess; bye" ) 2>&1 | grep -iE 'error|denied' || true
up "$CEN/dist/payload" "$RPRIV" 'bo_llm.php bo_providers.json'
up "$CEN/install/private" "$RPRIV" 'bo_auth.php bo_control.php bo_updater.php'
up "$SITE/private" "$RPRIV" 'bo_config.php'
echo "== Back-office déployé (code central + config du site) — contenu du site NON touché =="
