-- =====================================================================
-- Wintaskly — Données par défaut (seed)
-- =====================================================================
--
-- Exécuté APRÈS schema.sql + migrations par l'installeur.
-- Idempotent : utilise INSERT IGNORE pour ne pas écraser si déjà présent.
--
-- Contenu :
--   - 4 méthodes de retrait FaucetPay (USD/BTC/LTC/DOGE), inactives par
--     défaut. L'admin les active après avoir configuré sa clé API.
--   - 8 captcha_icons indispensables pour le faucet
--   - 5 FAQ par défaut (édit. via /admin/ ensuite)
--   - 6 blocs homepage par défaut
--   - Config minimum (faucet_reward_coins, leaderboard, etc.)
-- =====================================================================

/* ===== Méthodes de retrait (FaucetPay multi-crypto) ===== */

INSERT IGNORE INTO `withdrawal_methods`
    (`k`, `label`, `currency`, `coins_per_unit`, `min_coins`, `address_label`, `address_placeholder`, `auto_payout`, `active`, `sort_order`)
VALUES
    ('faucetpay-usd',  'FaucetPay (USD)',   'USD',  10000, 5000,  'Email FaucetPay',  'alice@faucetpay.io', 0, 0, 10),
    ('faucetpay-btc',  'FaucetPay (BTC)',   'BTC',  5000000, 5000000, 'Email FaucetPay', 'alice@faucetpay.io', 0, 0, 20),
    ('faucetpay-ltc',  'FaucetPay (LTC)',   'LTC',  50000, 50000, 'Email FaucetPay',  'alice@faucetpay.io', 0, 0, 30),
    ('faucetpay-doge', 'FaucetPay (DOGE)',  'DOGE', 5000,  5000,  'Email FaucetPay',  'alice@faucetpay.io', 0, 0, 40);


/* ===== Captcha icons (faucet anti-bot) ===== */
/* IMPORTANT : sans ces icônes, le faucet est cassé.
   Le SVG vide est OK pour démarrer — l'admin peut ajouter de vrais SVG plus tard. */

INSERT IGNORE INTO `captcha_icons` (`name`, `slug`, `svg`, `active`) VALUES
    ('Étoile',   'star',  '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="12,2 15,9 22,9 17,14 19,21 12,17 5,21 7,14 2,9 9,9"/></svg>', 1),
    ('Cœur',     'heart', '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7-4.5-9-9c-1-3 1-6 4-6 2 0 3 1 5 3 2-2 3-3 5-3 3 0 5 3 4 6-2 4.5-9 9-9 9z"/></svg>', 1),
    ('Nuage',    'cloud', '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 18a4 4 0 0 1 0-8 5 5 0 0 1 10 0 4 4 0 0 1 0 8z"/></svg>', 1),
    ('Soleil',   'sun',   '<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4" stroke="none"/><line x1="12" y1="2" x2="12" y2="5" fill="none"/><line x1="12" y1="19" x2="12" y2="22" fill="none"/><line x1="2" y1="12" x2="5" y2="12" fill="none"/><line x1="19" y1="12" x2="22" y2="12" fill="none"/><line x1="5" y1="5" x2="7" y2="7" fill="none"/><line x1="17" y1="17" x2="19" y2="19" fill="none"/><line x1="5" y1="19" x2="7" y2="17" fill="none"/><line x1="17" y1="7" x2="19" y2="5" fill="none"/></svg>', 1),
    ('Lune',     'moon',  '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 14a8 8 0 0 1-10-10 8 8 0 1 0 10 10z"/></svg>', 1),
    ('Feu',      'fire',  '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c0 5-5 5-5 11a5 5 0 0 0 10 0c0-3-2-4-3-6 1 2-2 4-2 4z"/></svg>', 1),
    ('Goutte',   'drop',  '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c-3 5-7 8-7 13a7 7 0 0 0 14 0c0-5-4-8-7-13z"/></svg>', 1),
    ('Feuille',  'leaf',  '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4c-8 0-14 6-14 14 0 0 1-1 2-1 6 0 12-6 12-13z"/></svg>', 1),
    ('Éclair',   'flash', '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="13,2 4,14 11,14 9,22 20,10 13,10"/></svg>', 1),
    ('Cercle',   'circle','<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="9"/></svg>', 1);


/* ===== FAQ par défaut ===== */
/* Format : pages affichant des Q/A simples sur /help/faq.php */

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
    /* Économie */
    ('faucet_reward_coins',    '0.5'),
    ('faucet_reward_xp',       '5'),
    ('faucet_cooldown_seconds', '60'),
    ('faucet_transition_seconds', '5'),
    ('faucet_session_ttl_seconds', '300'),
    ('referral_commission_pct', '10'),
    ('xp_per_level',            '100'),
    ('withdrawal_min_default',  '10000'),

    /* Classement (bonus mensuels) */
    ('leaderboard.rewards_enabled', '1'),
    ('leaderboard.reward_xp_1',  '100'),
    ('leaderboard.reward_xp_2',  '60'),
    ('leaderboard.reward_xp_3',  '40'),
    ('leaderboard.reward_coins_1', '0'),
    ('leaderboard.reward_coins_2', '0'),
    ('leaderboard.reward_coins_3', '0'),

    /* SEO — vide par défaut, l'admin remplit via /admin/settings.php */
    ('seo.meta_description', ''),
    ('seo.meta_keywords',    ''),
    ('seo.og_image_url',     ''),
    ('seo.twitter_handle',   ''),
    ('seo.robots_index',     '1'),

    /* Tracking — vide par défaut (rien injecté tant que non configuré) */
    ('tracking.google_analytics_id',   ''),
    ('tracking.google_adsense_client', ''),
    ('tracking.adsense_auto_ads',      '0'),
    ('tracking.facebook_pixel_id',     ''),
    ('tracking.matomo_url',            ''),
    ('tracking.matomo_site_id',        ''),

    /* Email — l'installeur remplit from_address/contact_to depuis le site_email */
    ('email.from_address',   ''),
    ('email.from_name',      ''),
    ('email.contact_to',     ''),
    ('email.smtp_enabled',   '0'),
    ('email.smtp_host',      ''),
    ('email.smtp_port',      '587'),
    ('email.smtp_user',      ''),
    ('email.smtp_pass',      ''),
    ('email.smtp_encryption','tls'),

    /* Année de lancement (utilisée par le footer pour le copyright dynamique) */
    ('launch_year',          CAST(YEAR(UTC_TIMESTAMP()) AS CHAR)),

    /* Marqueur d'install */
    ('install_completed_at',    UTC_TIMESTAMP());
