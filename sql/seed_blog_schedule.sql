-- ============================================================================
-- Wintaskly — Programmation des dates de publication (8 articles / 2 mois)
-- ============================================================================
-- Échelonne la publication des 8 articles : 1 par semaine pendant ~2 mois.
-- Le premier est publié immédiatement, les suivants apparaîtront
-- automatiquement à leur date (le blog filtre published_at <= maintenant).
--
-- AUCUN CRON NÉCESSAIRE : c'est le serveur qui révèle chaque article à sa
-- date. Il suffit que les 8 articles aient le statut 'published' avec une
-- date future ; ils restent invisibles jusqu'au jour J.
--
-- À exécuter APRÈS avoir chargé les 8 articles (seed_blog_articles.sql +
-- seed_blog_articles_extra.sql).
--
-- NOTE : les dates sont calculées à partir du moment de l'exécution.
-- Relancer ce script réinitialise le calendrier à partir d'aujourd'hui.
-- ============================================================================

-- Semaine 0 (maintenant) — déjà visible
UPDATE `blog_posts` SET `status`='published', `published_at` = UTC_TIMESTAMP()
 WHERE `slug` = 'guide-debutant-gagner-coins-wintaskly';

-- Semaine 1 (+7 jours)
UPDATE `blog_posts` SET `status`='published', `published_at` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)
 WHERE `slug` = 'cryptomonnaie-debutant-comprendre-bases';

-- Semaine 2 (+14 jours)
UPDATE `blog_posts` SET `status`='published', `published_at` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 14 DAY)
 WHERE `slug` = 'astuces-maximiser-gains-plateforme-gpt';

-- Semaine 3 (+21 jours)
UPDATE `blog_posts` SET `status`='published', `published_at` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 21 DAY)
 WHERE `slug` = 'securite-ligne-proteger-compte-arnaques';

-- Semaine 4 (+28 jours)
UPDATE `blog_posts` SET `status`='published', `published_at` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 28 DAY)
 WHERE `slug` = 'parrainage-revenus-passifs-comment-ca-marche';

-- Semaine 5 (+35 jours)
UPDATE `blog_posts` SET `status`='published', `published_at` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 35 DAY)
 WHERE `slug` = 'sondages-remuneres-guide-complet-gagner-argent';

-- Semaine 6 (+42 jours)
UPDATE `blog_posts` SET `status`='published', `published_at` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 42 DAY)
 WHERE `slug` = 'gerer-budget-revenus-complementaires-conseils';

-- Semaine 7 (+49 jours)
UPDATE `blog_posts` SET `status`='published', `published_at` = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 49 DAY)
 WHERE `slug` = 'avenir-micro-taches-economie-numerique';
