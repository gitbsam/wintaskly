-- =====================================================================
-- Wintaskly — Migration : traçabilité des paiements automatiques
-- =====================================================================
--
-- Ajoute 2 colonnes à `withdrawals` :
--   - `payout_txid` : ID de transaction retourné par le provider quand
--     un paiement automatique a réussi (ex: FaucetPay payout_id).
--     NULL pour les paiements manuels.
--   - `payout_mode` : ENUM('manual','auto','auto_failed') pour distinguer
--     visuellement dans l'admin si l'envoi a été manuel ou automatique.
--
-- Idempotent (IF NOT EXISTS).
-- =====================================================================

ALTER TABLE `withdrawals`
    ADD COLUMN IF NOT EXISTS `payout_txid` VARCHAR(120) NULL
        COMMENT 'ID de transaction retourné par le provider (paiement auto réussi)'
        AFTER `processed_at`;

ALTER TABLE `withdrawals`
    ADD COLUMN IF NOT EXISTS `payout_mode` ENUM('manual','auto','auto_failed') NOT NULL DEFAULT 'manual'
        COMMENT 'manual = envoi à la main / auto = envoi via API réussi / auto_failed = tentative auto échouée'
        AFTER `payout_txid`;
