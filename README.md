# Webaction PWA MVP

## 1. Audit de faisabilité

- `https://webaction.ca` redirige vers `https://webaction.ca/fr/`.
- L'endpoint Joomla API testé `https://webaction.ca/fr/api/index.php/v1/content/articles` retourne 404. Il n'y a donc pas d'API publique Joomla exploitable pour ce MVP.
- Une URL JSON Joomla classique avec `format=json` retourne 403 côté serveur. Elle n'est pas fiable pour une intégration.
- Les réalisations sont rendues directement dans la page d'accueil dans le bloc `#portfolio` de YOOtheme. Elles ressemblent davantage à du contenu de page/module qu'à une API d'articles proprement exposée.
- La section "À surveiller" est accessible à `https://webaction.ca/fr/apropos/offres-d-emploi`. Elle contient des articles Joomla (`article-41`, `article-35`, etc.) et expose aussi des flux RSS/Atom.
- Un plugin Joomla serait possible plus tard, mais il faut connaître les catégories, champs et modules exacts dans l'administration Joomla. Pour un MVP livrable rapidement, ce n'est pas le chemin le plus simple.
- Le projet peut être déployé sur cPanel sans Node en production: React/Vite sert seulement à générer des fichiers statiques.

## 2. Architecture choisie

Architecture finale: PWA statique React + TypeScript + Tailwind dans `public_html/app`, backend PHP 8 + MySQL dans `public_html/app/api`, et cron PHP cPanel pour détecter les nouveautés.

Pourquoi c'est le plus simple:

- Aucun serveur Node en production.
- Aucune dépendance Joomla interne obligatoire.
- Aucune modification du site Joomla requise.
- Les pages publiques existantes suffisent pour alimenter l'app.
- Le cron évite les notifications en double avec `source_id` et `content_hash`.

Notifications:

- `subscribe.php` stocke les abonnements Push dans MySQL.
- `notify-test.php` permet un test protégé par secret.
- `cron/check-updates.php` envoie une notification quand un nouvel item est détecté. Une modification d'un item existant met l'app à jour sans notification.
- L'envoi utilise `minishlink/web-push`, installé via Composer ou uploadé avec `vendor/`.
- Les clés VAPID se génèrent avec `php backend/tools/generate-vapid-keys.php`, ou avec `npx web-push generate-vapid-keys` si OpenSSL bloque sur Windows.

Limites:

- Le scraping dépend de la structure HTML publique. Si YOOtheme change fortement le markup, il faudra ajuster `backend/lib/content.php`.
- La première exécution du cron initialise la base sans notification pour éviter une rafale initiale.
- iOS exige que la PWA soit installée sur l'écran d'accueil avant l'activation Push.

## 3. Plan MVP

- PWA installable avec `manifest.webmanifest`, icône et service worker.
- Interface mobile-first avec Accueil, Réalisations, À surveiller et Détail.
- Boutons "Nous joindre" et "Site complet".
- États loading, vide et erreur.
- API PHP: `subscribe.php`, `unsubscribe.php`, `latest.php`, `notify-test.php`, `config-public.php`.
- MySQL: abonnements, contenus détectés, logs de notifications.
- Cron cPanel: extraction des réalisations et articles "À surveiller", comparaison hash, envoi Push.
- Documentation cPanel et notifications.

## 4. Risques techniques

- HTML source modifié: corriger les sélecteurs DOM dans `backend/lib/content.php`.
- Composer indisponible sur cPanel: installer les dépendances localement et uploader `backend/vendor`.
- Serveur PHP 7.4: `backend/composer.json` force `minishlink/web-push` en `v7.x`, compatible PHP 7.4.
- Service worker et Push exigent HTTPS.
- Les navigateurs ne permettent pas de demander les notifications sans action utilisateur.

## 5. Arborescence

```text
frontend/
  public/
    icon.svg
    manifest.webmanifest
    sw.js
  src/
    index.css
    main.tsx
  index.html
  package.json
  vite.config.ts
backend/
  api/
    config-public.php
    latest.php
    notify-test.php
    subscribe.php
    unsubscribe.php
  config/
    config.example.php
  cron/
    check-updates.php
  lib/
    bootstrap.php
    content.php
    push.php
  sql/
    schema.sql
  tools/
    generate-vapid-keys.php
  composer.json
joomla-plugin/
  README.md
docs/
  deployment-cpanel.md
  notifications.md
README.md
```

## 6. Code complet

Le code complet est dans les dossiers ci-dessus. Les points d'entrée principaux sont:

- Frontend: `frontend/src/main.tsx`
- Service worker: `frontend/public/sw.js`
- API latest: `backend/api/latest.php`
- Abonnement Push: `backend/api/subscribe.php`
- Envoi Push: `backend/lib/push.php`
- Scraping/détection: `backend/lib/content.php`
- Cron: `backend/cron/check-updates.php`
- SQL: `backend/sql/schema.sql`

## 7. Instructions cPanel

Voir `docs/deployment-cpanel.md`.

Résumé:

1. Créer la base MySQL et importer `backend/sql/schema.sql`.
2. Copier `backend/config/config.example.php` en `backend/config/config.php`.
3. Remplir DB, `notify_secret`, `app_url` et clés VAPID.
4. Installer Composer localement ou uploader `backend/vendor`.
5. Builder `frontend` avec `npm run build`.
6. Uploader `frontend/dist` et le backend dans `public_html/app`.
7. Lancer le cron une première fois.
8. Ajouter le Cron Job cPanel toutes les 10 ou 15 minutes.

## 8. Configuration notifications

Voir `docs/notifications.md`.

Le frontend ne reçoit que la clé publique VAPID via `api/config-public.php`. La clé privée reste dans `backend/config/config.php`.

## 9. Checklist de test

- Ouvrir `https://webaction.ca/app/` sous HTTPS.
- Vérifier que l'app affiche Accueil, Réalisations et À surveiller.
- Dans DevTools > Application, vérifier le manifest et le service worker.
- Installer l'app avec "Ajouter à l'écran d'accueil".
- Activer les notifications avec le bouton.
- Vérifier que `push_subscriptions` contient un abonnement actif.
- Appeler `notify-test.php` avec `X-Notify-Secret`.
- Lancer `cron/check-updates.php` en CLI.
- Vérifier que `detected_contents` est alimentée.
- Vérifier que `notification_logs` journalise les envois, erreurs ou skips.
