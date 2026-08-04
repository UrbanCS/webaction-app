# Notifications Push

## Fonctionnement

Le navigateur crée un abonnement Push après une action utilisateur sur le bouton "Activer les notifications". L'abonnement est envoyé à `api/subscribe.php` et stocké dans MySQL.

Quand `cron/check-updates.php` détecte un nouveau contenu, il appelle `send_push_to_all()`, qui utilise `minishlink/web-push` et les clés VAPID privées côté serveur.

Au démarrage, la PWA renvoie aussi son abonnement existant à `api/subscribe.php`. Cela réactive automatiquement un abonnement valide qui aurait été désactivé côté serveur.

## Générer les clés VAPID

Avec Composer:

```bash
cd backend
composer install
php tools/generate-vapid-keys.php
```

Si PHP/OpenSSL local refuse de créer la clé, utiliser l'outil Node:

```bash
npx web-push generate-vapid-keys
```

Copier la clé publique et la clé privée dans `backend/config/config.php`.

Sur un serveur PHP 7.4, garder `minishlink/web-push` en `v7.x`. Le fichier `backend/composer.json` fixe une plateforme PHP 7.4 pour éviter d'installer une version PHP 8 seulement.

## Tester une notification

Après avoir activé les notifications dans la PWA:

```bash
curl -X POST https://webaction.ca/app/api/notify-test.php \
  -H "Content-Type: application/json" \
  -H "X-Notify-Secret: CHANGE_ME_LONG_RANDOM_SECRET" \
  -d '{"title":"Webaction","body":"Test de notification","url":"https://webaction.ca/app/"}'
```

## Notes iPhone/iOS

Les notifications Web Push sur iPhone exigent iOS 16.4 ou plus récent et la PWA doit être ajoutée à l'écran d'accueil avant de demander les notifications.

## Diagnostic protégé

`api/health.php?secret=NOTIFY_SECRET` retourne le nombre d'abonnements actifs, le nombre de contenus actifs par type et les dix derniers résultats d'envoi. Cet endpoint ne déclenche aucune notification.

## Sécurité

La clé privée VAPID reste uniquement dans `backend/config/config.php`. Ne jamais la placer dans `frontend/src`, `public`, `manifest.webmanifest` ou une variable JavaScript visible publiquement.
