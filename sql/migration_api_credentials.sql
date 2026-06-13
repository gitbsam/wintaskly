-- =====================================================================
-- Wintaskly — Migration : credentials API pour paiements automatiques
-- =====================================================================
--
-- Ajoute le support des paiements automatiques par provider :
--   - `api_credentials` : JSON nullable (api_key, merchant_id, secret, etc.)
--     Format flexible selon le provider :
--       FaucetPay : {"api_key": "xxxxx"}
--       Payeer    : {"account": "P12345", "api_id": "xx", "api_key": "yyy"}
--       Binance   : {"api_key": "xxxxx", "api_secret": "yyyyy"}
--   - `auto_payout` : si 1, l'admin peut déclencher le paiement automatique
--     via l'API du provider. Si 0 ou clé manquante, paiement manuel
--     uniquement (l'admin doit envoyer le paiement à la main et marquer
--     "completed" dans /admin/withdrawals.php).
--
-- Idempotent : MariaDB 10.5+ supporte ADD COLUMN IF NOT EXISTS.
--
-- IMPORTANT : les credentials stockées ici sont sensibles. Ne JAMAIS
-- les exposer côté client. La page admin masque la valeur existante
-- ("••••••••") et ne la transmet pas dans le HTML.
-- =====================================================================

ALTER TABLE `withdrawal_methods`
    ADD COLUMN IF NOT EXISTS `api_credentials` TEXT NULL
        COMMENT 'JSON: api_key, merchant_id, secret, etc. (chiffré ou en clair selon politique)'
        AFTER `address_placeholder`;

ALTER TABLE `withdrawal_methods`
    ADD COLUMN IF NOT EXISTS `auto_payout` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Si 1 : paiement auto via API. Si 0 : paiement manuel.'
        AFTER `api_credentials`;
