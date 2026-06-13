-- ============================================================================
-- Wintaskly — Migration : Shortlinks Provider Rate Tracking
-- ============================================================================
-- Ajoute 3 colonnes à `shortlinks` pour tracker la rentabilité côté admin :
--   - provider_rate_amount    : ce que le provider paie (ex: 12.00)
--   - provider_rate_currency  : devise (USD, EUR, etc.)
--   - provider_rate_per_views : pour combien de vues (ex: 1000)
--
-- Ces données sont INFORMATIONNELLES seulement. Elles ne changent pas
-- la logique de récompense (reward_coins reste la source de vérité).
-- L'admin peut voir en temps réel sur /admin/shortlinks.php :
--   - Combien il gagne par vue
--   - Combien va à l'utilisateur (en coins → converti en €/$)
--   - Sa marge nette
--
-- Applique cette migration UNE SEULE FOIS via phpMyAdmin → SQL.
-- ============================================================================

ALTER TABLE `shortlinks`
  ADD COLUMN `provider_rate_amount`    DECIMAL(10,4) NOT NULL DEFAULT 0
    AFTER `gateway_seconds`,
  ADD COLUMN `provider_rate_currency`  CHAR(3)       NOT NULL DEFAULT 'USD'
    AFTER `provider_rate_amount`,
  ADD COLUMN `provider_rate_per_views` INT UNSIGNED  NOT NULL DEFAULT 1000
    AFTER `provider_rate_currency`;
