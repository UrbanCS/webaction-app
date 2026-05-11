# Plugin Joomla non retenu pour le MVP

L'audit montre que les réalisations de la page d'accueil sont rendues dans un bloc YOOtheme et que la section "À surveiller" est une vue Joomla publiant des articles. Un plugin Joomla serait possible, mais il exigerait de connaître précisément les catégories/champs/modules utilisés dans l'admin Joomla.

Pour livrer vite et rester compatible cPanel, le MVP utilise plutôt `backend/cron/check-updates.php`, qui lit les pages publiques ciblées et détecte les nouveautés par `source_id` + `content_hash`.
