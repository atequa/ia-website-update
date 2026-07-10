# site-perf-page — mise à jour centralisée du moteur (module perf)

Deuxième module distribué par ce repo, à côté du back-office IA. **Même racine de confiance**
(clé RSA maîtresse d'Atequa), même repo, même clé publique embarquée côté site. Permet de corriger
ou faire évoluer le moteur `perf_gen.php` (la page « Performances du site ») sur TOUS les sites
d'un seul push signé — la config et le template restent locaux à chaque site.

## Fichiers

- `dist-perf/payload/perf_gen.php` : le moteur (code distribué, mis à jour à distance).
- `dist-perf/version.json` : manifeste signé (version + sha256 + `enabled` + signature RSA-SHA256).
- `build_perf_version.php` : régénère + signe le manifeste (clé privée via env, jamais commitée).
- `install/private/perf_updater.php` : updater côté site (clé publique embarquée, périmètre
  d'écriture = **uniquement `perf_gen.php` dans son dossier**). Vérifie signature + sha256 +
  anti-rollback + `php -l` du code proposé, écriture atomique tout-ou-rien.
- `install/private/perf_run.php` : point d'entrée du cron = updater best-effort puis génération.

## Sécurité (pourquoi c'est sûr sur des sites clients)

- Un manifeste, même servi par un GitHub compromis, ne peut RIEN écrire sans **signature valide**
  (clé privée hors-ligne, jamais dans le repo ni sur un site). Un PAT volé = au pire vandaliser le
  repo public, pas injecter du code.
- Le périmètre d'écriture est **un seul fichier** (`perf_gen.php`) : impossible d'écraser le
  contenu du site, `perf_config.php` (donc la clé API), le template, `.htaccess`, quoi que ce soit.
- **Anti-rollback** (refus d'une version antérieure), **`php -l`** sur le code avant de l'appliquer,
  **écriture atomique** (tmp+rename → une panne en cours laisse l'ancien moteur intact).
- **Kill switch** : `"enabled": false` dans le manifeste stoppe l'auto-update partout au prochain cron.

## Publier une nouvelle version — AUTOMATIQUE (CI)

**Aucun push à la main.** On modifie `dist-perf/payload/perf_gen.php` et on commit sur `main` :
le workflow `.github/workflows/sign-perf.yml` régénère et signe `dist-perf/version.json`
(version horodatée monotone `AAAA.MM.JJ-<run>`), recommite, et les sites tirent la nouvelle
version signée à leur cron suivant. Le kill switch reste `"enabled": false` (édition manuelle
ponctuelle du manifeste) pour tout stopper.

- **Clé de signature DÉDIÉE au module perf** (RSA-2048), distincte de la clé maîtresse du
  back-office : privée = secret GitHub `PERF_SIGN_PRIVKEY_B64`, publique embarquée dans
  `perf_updater.php`. Les deux sont aussi consignées dans `.env.backoffice` (hors serveur,
  Proton Pass). Un GitHub compromis ne peut donc signer QUE des updates du moteur perf (périmètre
  d'écriture = `perf_gen.php` seul) — le back-office (réécriture de contenu) n'est pas exposé.
- Build manuel possible en secours : `PERF_SIGN_PRIVKEY_B64="…" php build_perf_version.php <ver>`.

### Mise en service (une seule fois)
1. Secret repo GitHub `PERF_SIGN_PRIVKEY_B64` = valeur de `.env.backoffice`.
2. Déposer `.github/workflows/sign-perf.yml` sur le repo (nécessite le scope *workflow* du PAT).
3. Pousser `dist-perf/**` + `build_perf_version.php` une première fois.

## Installer sur un site (une fois par site)

1. Déposer `install/private/perf_updater.php` et `install/private/perf_run.php` dans le `private/`
   du site (là où vivent déjà `perf_gen.php` / `perf_config.php`).
2. Basculer le cron cPanel de `php …/perf_gen.php` vers `php …/perf_run.php`.
3. (Recommandé) migrer la carte PageSpeed du template vers le placeholder unique `{{PSI_BLOCK}}`
   (mobile + ordinateur), pour que les évolutions de carte arrivent par simple push.
4. Vérifier : `perf_update.log` montre `updated:true version:…`, `perf_version.json` créé,
   `/performances` toujours 200.

Après ça, plus aucune tournée : une évolution = un push signé.
