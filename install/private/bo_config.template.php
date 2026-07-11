<?php
/**
 * Back-office — configuration LOCALE d'un site (HORS docroot).
 * MODÈLE : copier en bo_config.php sur le serveur du site et remplir les __PLACEHOLDERS__.
 * Ne contient AUCUN secret maître (la clé privée de signature reste dans .env.backoffice).
 */

// --- Identité ---
const BO_SITE_NAME = '__SITE_NAME__';            // ex: Educ Care
const BO_MAIL_FROM = '__MAIL_FROM__';            // ex: mehdi@educ-care.com
const BO_SITE_ID   = '__SITE_ID__';              // identifiant dans control.json, ex: educ-care

// --- Qui peut se connecter (client + accès d'urgence Manu) ---
const BO_AUTHORIZED_EMAILS = ['__CLIENT_EMAIL__', 'delgoffe@gmail.com'];

// --- Secret de session (généré par site : openssl rand -base64 36) ---
const BO_SIGNING_SECRET = '__SESSION_SECRET__';

// --- Chemins serveur (adapter au home du compte) ---
const BO_DOCROOT     = '__DOCROOT__';
const BO_PRIVATE     = '__HOME__/private';
const BO_SECRET_FILE = '__HOME__/private/bo_secret.json';
const BO_BACKUPS     = '__HOME__/private/bo_backups';
const BO_SPENDLOG    = '__HOME__/private/bo_spend.json';
const BO_PROPOSALS   = '__HOME__/private/bo_proposals';
const BO_TOKENS_FILE = '__HOME__/private/bo_tokens.json';
const BO_MAGIC_FILE  = '__HOME__/private/bo_magic.json';
const BO_PROVIDERS_FILE = '__HOME__/private/bo_providers.json';
const BO_VERSION_FILE   = '__HOME__/private/bo_version.json';
const BO_CONTROL_CACHE  = '__HOME__/private/bo_control_cache.json';
const BO_HISTORY        = '__HOME__/private/bo_history';
const BO_HISTORY_FILE   = '__HOME__/private/bo_history.json';
const BO_HISTORY_KEEP   = 30;

// --- Limites ---
const BO_MAGIC_TTL = 900; const BO_SESSION_TTL = 5184000;
const BO_DAILY_CALLS = 80; const BO_MAX_TOKENS = 16000; const BO_CONTROL_TTL = 600;
const BO_DEFAULT_MODE = 'self';

// --- Canal central (clé publique commune — voir .env.backoffice ; PAS secrète) ---
const BO_UPDATE_PUBKEY  = '__PUBKEY__';
const BO_UPDATE_BASEURL = 'https://raw.githubusercontent.com/atequa/ia-website-update/main/dist/';
const BO_CONTROL_URL    = 'https://raw.githubusercontent.com/atequa/ia-website-update/main/dist/control.json';

// --- Dév : afficher le magic link à l'écran tant que SPF/DKIM pas actifs. false en prod. ---
const BO_DEV_SHOW_LINK = true;

// --- Fichiers éditables (adapter à la liste réelle des pages du site) ---
const BO_EDITABLE = ['index.html','styles.css','main.js','robots.txt','sitemap.xml','404.html'];
