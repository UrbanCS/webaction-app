# Passation Codex - Webaction PWA

Dernière mise à jour vérifiée: 14 août 2026, fuseau America/Toronto.

Ce document est le point d'entrée d'un nouveau chat Codex. Avant toute modification, lire ce fichier, puis inspecter `git status`, `README.md`, `docs/`, les fichiers concernés et l'état live. Ne jamais recopier les secrets de `backend/config/config.php` dans une réponse, un commit ou un document.

## 1. Objectif du projet

Construire une PWA légère et mobile-first pour Webaction, installable depuis le navigateur à `https://webaction.ca/app/`, sans App Store ni Google Play.

La PWA ne reconstruit pas le site Joomla. Elle affiche uniquement:

- les plus récentes réalisations de la page d'accueil;
- les informations de la section « À surveiller »;
- une vue détail;
- des liens vers « Nous joindre » et le site complet;
- un bouton d'installation avec aide iPhone, Android et Windows;
- un bouton pour activer ou désactiver les notifications Web Push.

La production n'utilise pas Node.js. React/Vite sert uniquement au build local; cPanel sert des fichiers statiques et PHP/MySQL.

## 2. Architecture retenue

Frontend:

- React 18 + TypeScript + Vite;
- Tailwind CSS;
- service worker manuel;
- build statique dans `frontend/dist`.

Backend:

- PHP compatible avec le serveur cPanel PHP 7.4.33;
- MariaDB/MySQL;
- endpoints PHP simples dans `backend/api`;
- scraping ciblé et ordonné dans `backend/lib/content.php`;
- cron dans `backend/cron/check-updates.php`;
- Web Push avec `minishlink/web-push` v7 installé dans `backend/vendor`.

Source Joomla:

- l'API Joomla publique n'était pas exploitable;
- les réalisations sont extraites du bloc YOOtheme `#portfolio` sur `https://webaction.ca/fr/`;
- « À surveiller » est extrait de `https://webaction.ca/fr/apropos/offres-d-emploi`;
- la PWA ne surveille aucune autre section du site.

## 3. Hébergement et chemins

URL publique:

```text
https://webaction.ca/app/
```

Racine cPanel/RapidNet:

```text
/home/webactio/domains/webaction.ca/public_html/app
```

Structure de production:

```text
app/
  index.html
  assets/
  sw.js
  manifest.webmanifest
  icons et logos
  api/
  config/
  cron/
  lib/
  vendor/
```

Le fichier live `app/config/config.php` contient les accès MySQL, le secret de test et les clés VAPID. Ne jamais l'écraser pendant un déploiement frontend/backend courant.

## 4. Base de données

Le schéma est dans `backend/sql/schema.sql`.

Tables:

- `push_subscriptions`: abonnements Push, clés navigateur et statut actif;
- `detected_contents`: contenu détecté, hash, position source et statut actif;
- `notification_logs`: envois réussis, échecs et éléments ignorés.

Le cron ajoute automatiquement `source_position` et `active` à une ancienne table `detected_contents` si nécessaire. L'utilisateur MySQL cPanel doit conserver le droit `ALTER`.

Ne pas réimporter `schema.sql` sur la base live pour une simple mise à jour.

## 5. Synchronisation et règles fonctionnelles

Le cron cPanel roule toutes les 10 minutes:

```bash
/usr/bin/php /home/webactio/domains/webaction.ca/public_html/app/cron/check-updates.php >/dev/null 2>&1
```

Commande manuelle de diagnostic:

```bash
php /home/webactio/domains/webaction.ca/public_html/app/cron/check-updates.php
```

Comportement:

- le fetch Joomla utilise un cache-buster et des en-têtes anti-cache;
- les réalisations et articles sont conservés dans l'ordre exact de leur source avec `source_position`;
- les items présents sont `active = 1`;
- les items retirés du site deviennent `active = 0` et disparaissent de la PWA;
- si une source retourne zéro item, le cron échoue sans désactiver le contenu existant;
- un item revenu peut être réactivé;
- le premier seed n'envoie aucune notification;
- un nouvel item après le seed déclenche une notification;
- une modification du titre, texte, lien ou image met la PWA à jour sans notification;
- plusieurs nouveautés du même type dans un scan donnent une seule notification récapitulative;
- le délai normal de propagation est d'environ 10 minutes.

Limite importante: « n'importe quel changement du site » n'est pas couvert. Seules les sections Réalisations et À surveiller le sont.

## 6. Notifications Web Push

Flux:

1. L'utilisateur clique « Activer les notifications ».
2. Le navigateur crée un abonnement avec la clé VAPID publique.
3. `api/subscribe.php` enregistre ou réactive l'abonnement.
4. Le cron appelle `send_push_to_all()` lors d'une nouveauté.
5. Le service worker affiche la notification.

Au démarrage, la PWA resynchronise aussi un abonnement navigateur existant avec `subscribe.php`. Le bouton devient « Désactiver les notifications » quand un abonnement valide est présent.

iPhone/iPad:

- iOS 16.4 ou plus récent;
- la PWA doit être ajoutée à l'écran d'accueil;
- la permission doit être demandée depuis la PWA installée après une action utilisateur.

Test serveur, en utilisant le secret live sans le recopier dans le chat:

```bash
curl -X POST "https://webaction.ca/app/api/notify-test.php?secret=VALEUR_DU_CONFIG_LIVE"
```

Une validation précédente a retourné des envois réussis et une notification a bien été reçue sur téléphone.

Diagnostic protégé sans envoi:

```text
https://webaction.ca/app/api/health.php?secret=VALEUR_DU_CONFIG_LIVE
```

Il retourne notamment le nombre d'abonnements actifs, les contenus actifs par type et les dix derniers résultats d'envoi.

## 7. Cache et rafraîchissement

Le service worker actuel est `webaction-pwa-v7`.

Corrections déjà en place:

- l'API n'est pas interceptée par le cache du service worker;
- les navigations sont network-first avec fallback offline;
- les images de contenu sont revalidées;
- les URLs d'images reçoivent `pwa_v` basé sur le hash du contenu;
- l'API est rechargée quand l'app revient au premier plan (`focus` et `visibilitychange`);
- les abonnements Push existants sont resynchronisés au lancement;
- les notifications utilisent `icon-192.png` plutôt que le SVG.

Pour une ancienne version très persistante sur iPhone/Android, fermer et rouvrir la PWA. Pour une icône installée mise à jour, il peut être nécessaire de supprimer puis réinstaller la PWA et d'effacer les données du site.

## 8. UI et actifs visuels

L'interface actuelle contient deux onglets:

- Réalisations;
- À surveiller.

L'ancien onglet Accueil a été retiré parce qu'il dupliquait Réalisations.

Le header contient:

- le titre « Réalisations et nouvelles »;
- `logo-webaction.png` à droite;
- « Nous joindre »;
- « Site complet »;
- « Installer l'app » ou l'aide d'installation Apple.

Actifs importants:

- `frontend/public/logo-webaction.png`: logo horizontal du header;
- `frontend/public/icon.svg`: symbole transparent;
- `frontend/public/icon-192.png` et `icon-512.png`: icônes installables;
- `frontend/public/apple-touch-icon.png`: icône iOS;
- `frontend/public/manifest.webmanifest`: manifeste PWA.

Le SVG doit rester transparent. Le noir visible dans l'interface vient du fond du header, pas du SVG.

## 9. Détails de contenu

`backend/api/latest.php` retourne les listes Réalisations et À surveiller.

`backend/api/detail.php` récupère et assainit le contenu détaillé d'une page Webaction autorisée. La vue React affiche ce contenu dans la PWA et conserve un bouton vers la source.

Endpoints principaux:

```text
GET  /app/api/latest.php
GET  /app/api/detail.php?url=...
GET  /app/api/config-public.php
POST /app/api/subscribe.php
POST /app/api/unsubscribe.php
POST /app/api/notify-test.php?secret=...
GET  /app/api/health.php?secret=...
```

`notify-test.php` exige POST. Un appel direct dans la barre d'adresse retourne donc « Method not allowed ».

## 10. Historique des problèmes résolus

- L'API Joomla n'était pas disponible: choix du scraping ciblé compatible cPanel.
- PHP live était 7.4.33: downgrade de `minishlink/web-push` vers v7 et plateforme Composer PHP 7.4.
- La génération VAPID PHP/OpenSSL échouait sous Windows: génération réussie avec `npx web-push generate-vapid-keys`.
- Le build TypeScript manquait `@types/react` et `@types/react-dom`: dépendances ajoutées.
- `health.php` avait été uploadé par erreur à la racine au lieu de `api/`: chemin corrigé.
- Joomla pouvait ajouter `/fr/` à certaines URL mal routées: endpoints utilisés directement sous `/app/api/`.
- Le premier parsing des réalisations ne ciblait pas correctement les liens YOOtheme: extraction corrigée sur `#portfolio a.el-item`.
- L'ordre était inversé et, après une limite, les nouveaux items disparaissaient: `source_position`, `active` et ordre source ajoutés.
- Les images restaient figées à cause du cache: service worker v7, revalidation et version d'URL par hash.
- Les données restaient figées quand l'app demeurait ouverte: refresh au retour au premier plan.
- Des abonnements pouvaient rester désactivés côté serveur: resynchronisation au lancement.
- Une découverte multiple pouvait produire une rafale: notifications regroupées par type.
- Le SVG avait un carré noir: fond retiré et PNG transparent intégré dans le SVG.
- L'icône installée restait ancienne à cause du cache iOS/Android: fichiers PNG/Apple dédiés et procédure de réinstallation documentée.

## 11. Build et déploiement

Build frontend:

```bash
cd frontend
npm install
npm run build
```

Pour une modification frontend, déployer au minimum:

```text
frontend/dist/index.html -> app/index.html
frontend/dist/assets/    -> app/assets/
frontend/dist/sw.js      -> app/sw.js, si modifié
```

Ajouter les fichiers publics concernés si le manifeste, une icône ou un logo change.

Pour les dernières corrections de synchronisation, les fichiers déployés étaient:

```text
backend/lib/content.php          -> app/lib/content.php
backend/cron/check-updates.php   -> app/cron/check-updates.php
backend/api/health.php           -> app/api/health.php
frontend/dist/index.html         -> app/index.html
frontend/dist/assets/            -> app/assets/
frontend/dist/sw.js              -> app/sw.js
```

Ne jamais remplacer `app/config/config.php` par un exemple ou une copie locale sans vérifier tous les secrets et identifiants.

## 12. État vérifié au 14 août 2026

Vérification live effectuée:

- `https://webaction.ca/app/sw.js` expose `webaction-pwa-v7`;
- `https://webaction.ca/app/api/latest.php` répond `ok: true`;
- le cron avait mis les contenus à jour à 00:30 le 14 août 2026;
- les premières réalisations retournées étaient « CUISINE ESSENTIELLE KITCHEN », « Canton de Champlain Township » et « Maison funéraire McConnery » dans le bon ordre;
- le commit courant est `c865eb3 Update notification and content flows`;
- seul `.vscode/settings.json` est actuellement modifié dans le worktree et cette modification appartient à l'utilisateur.

## 13. Sécurité et actions à ne pas oublier

Des captures et commandes d'une ancienne conversation ont exposé un secret de notification et des clés VAPID. Ils ne sont volontairement pas reproduits ici.

Action de sécurité recommandée:

- faire tourner le `notify_secret` live;
- considérer une rotation VAPID seulement avec précaution, car elle invaliderait les abonnements existants et obligerait les utilisateurs à réactiver les notifications.

Ne jamais afficher le contenu de `backend/config/config.php` dans une réponse ou un log partagé.

## 14. Première action du prochain chat

Dans un nouveau chat Codex, utiliser ce message:

```text
Travaille dans /mnt/c/Users/marca/OneDrive/Desktop/webaction-app.
Lis CODEX_HANDOFF.md, puis vérifie git status et les endpoints live avant de modifier quoi que ce soit.
Poursuis le projet Webaction PWA en respectant l'état et les contraintes décrits dans la passation.
```

