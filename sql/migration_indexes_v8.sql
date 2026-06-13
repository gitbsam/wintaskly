-- =====================================================================
-- Wintaskly — Migration : indexes de performance pour le dashboard admin
-- =====================================================================
--
-- Idempotent : utilise IF NOT EXISTS (MariaDB 10.5+ / MySQL 8.0+).
--
-- Ces indexes sont nécessaires pour les queries globales (sans user_id)
-- du dashboard admin :
--   - SELECT COUNT(*) FROM users WHERE status = 'active'
--   - SELECT COUNT(*) FROM transactions WHERE created_at >= ...
--   - SELECT COUNT(*) FROM faucet_claims WHERE claimed_at >= ...
--   - SELECT COUNT(*) FROM shortlink_attempts WHERE status = 'valide' AND completed_at >= ...
--
-- Les indexes composites existants (idx_user_created, idx_user_link…)
-- ne couvrent QUE les queries filtrées par user_id. Pour les agrégations
-- globales sur des plages temporelles, on a besoin d'indexes
-- monocolonnes ou composites différents.
--
-- Impact estimé : sur 100k rows, une query 7d passe de ~50ms (full scan)
-- à <1ms (range scan via index).
-- =====================================================================

-- users : filtres admin globaux (WHERE status='active')
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_status_created` (`status`, `created_at`);

-- transactions : agrégations temporelles globales (WHERE created_at >= ...)
ALTER TABLE `transactions` ADD INDEX IF NOT EXISTS `idx_created_type` (`created_at`, `type`);

-- faucet_claims : filtre 7d global du dashboard admin
ALTER TABLE `faucet_claims` ADD INDEX IF NOT EXISTS `idx_claimed_at` (`claimed_at`);

-- shortlink_attempts : filtre 7d + status (idx existant ne couvre pas completed_at)
ALTER TABLE `shortlink_attempts` ADD INDEX IF NOT EXISTS `idx_status_completed` (`status`, `completed_at`);

-- ptc_views : filtre par jour pour le daily_view_limit (utilisé dans ptc_start.php)
ALTER TABLE `ptc_views` ADD INDEX IF NOT EXISTS `idx_view_date` (`viewed_at`);
