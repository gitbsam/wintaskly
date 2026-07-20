-- ============================================================================
-- Wintaskly — Migration : expansion des emplacements publicitaires
-- ============================================================================
-- Ajoute 12 nouvelles zones pub sur vitrine/blog/tasks/dashboard (hors
-- admin/help, et hors pages sensibles : retrait, paramètres, 2FA, légal, auth).
-- Toutes désactivées à toi de configurer le code AdSense/Adsterra dans
-- /admin/ads.php avant activation (les zones sont "active=1" mais le code
-- est un placeholder tant que tu n'as rien collé — aucune pub ne s'affiche).
-- ============================================================================

INSERT IGNORE INTO `ad_zones` (`k`,`label`,`code`,`active`) VALUES
 ('home_footer',              'Accueil — Avant le pied de page',  '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('leaderboard_bottom',       'Classement — Bas de page',         '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('testimonials_bottom',      'Témoignages — Bas de page',        '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('blog_index_bottom',        'Blog — Bas de la liste',           '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('bingo_top',                'Bingo — Bandeau haut',             '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('faucet_index_top',         'Faucet — Accueil (étape 1)',       '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('shortlinks_index_top',     'Shortlinks — Hub bandeau haut',    '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('dashboard_account_bottom',       'Dashboard — Compte, bas de page',        '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('dashboard_referrals_bottom',     'Dashboard — Parrainage, bas de page',    '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('dashboard_messages_bottom',      'Dashboard — Messages, bas de page',      '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('dashboard_notifications_bottom', 'Dashboard — Notifications, bas de page', '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('achievements_bottom',      'Succès — Bas de page',             '<!-- Insérer ici le code AdSense responsive -->', 1);
