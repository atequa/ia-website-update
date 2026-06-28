# ia-website-update — back-office d'édition IA mutualisé (Atequa)

Code central versionné du back-office installé sur les sites statiques o2switch.
Les sites tirent les mises à jour **signées** depuis `dist/` (vérification RSA-SHA256 / OpenSSL côté site — portable PHP 7.4+).

- `dist/version.json` : manifeste signé (version + fichiers + sha256 + signature).
- `dist/payload/**` : fichiers de code mis à jour à distance.
- `dist/control.json` : plan de contrôle signé (kill switch + mode géré/autonome) par site.

**Aucun secret ici.** La clé privée de signature reste hors-ligne (jamais commitée).
Publier une version : `BO_SIGN_PRIVKEY_B64=<clé privée PEM base64> php build_version.php <version>` puis pousser `dist/**`.
