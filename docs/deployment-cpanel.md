# Déploiement cPanel

## 1. Préparer la base de données

1. Dans cPanel, créer une base MySQL/MariaDB.
2. Créer un utilisateur MySQL et lui donner tous les droits sur la base.
3. Importer `backend/sql/schema.sql` avec phpMyAdmin.

## 2. Configurer le backend

1. Copier `backend/config/config.example.php` vers `backend/config/config.php`.
2. Remplir les accès MySQL.
3. Remplir `app_url`, par exemple `https://webaction.ca/app`.
4. Générer et copier les clés VAPID.
5. Mettre une vraie valeur longue dans `notify_secret`.

## 3. Installer la librairie Web Push

Option recommandée si Composer est disponible localement ou sur cPanel:

```bash
cd backend
composer install --no-dev --optimize-autoloader
```

Si Composer n'est pas disponible sur cPanel, exécuter cette commande localement, puis uploader le dossier `backend/vendor` avec le reste du backend.

## 4. Builder le frontend

```bash
cd frontend
npm install
npm run build
```

Uploader le contenu de `frontend/dist` dans `public_html/app`.

## 5. Uploader le backend

Uploader les dossiers `backend/api`, `backend/lib`, `backend/config`, `backend/cron` et `backend/vendor` dans `public_html/app`.

La structure côté serveur doit ressembler à:

```text
public_html/app/
  index.html
  assets/
  sw.js
  manifest.webmanifest
  api/
  lib/
  config/
  cron/
  vendor/
```

## 6. Initialiser les contenus

Lancer une première fois:

```bash
php /home/USER/public_html/app/cron/check-updates.php
```

La première exécution remplit la table `detected_contents` sans envoyer de notifications.

## 7. Configurer le cron cPanel

Dans cPanel > Cron Jobs:

```bash
*/10 * * * * /usr/local/bin/php /home/USER/public_html/app/cron/check-updates.php >/dev/null 2>&1
```

Adapter le chemin PHP si nécessaire. Une fréquence de 10 ou 15 minutes est raisonnable.
