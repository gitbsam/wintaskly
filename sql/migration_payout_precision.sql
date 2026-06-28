-- ============================================================================
-- Wintaskly — Migration : précision du payout pour les cryptomonnaies
-- ============================================================================
-- Passe withdrawals.payout_amount de DECIMAL(18,4) à DECIMAL(18,8).
--
-- POURQUOI :
--   Le calcul du payout fait déjà round(coins / ratio, 8) côté PHP, mais la
--   colonne ne stockait que 4 décimales → perte de précision au stockage.
--   Les cryptos (BTC, ETH...) nécessitent jusqu'à 8 décimales (1 satoshi =
--   0.00000001 BTC). DECIMAL(18,8) couvre tous les cas (fiat ET crypto).
--
-- SÛR : agrandir la précision ne tronque aucune donnée existante (les
-- valeurs en 4 décimales deviennent simplement des 8 décimales avec des
-- zéros de remplissage). Réversible si besoin.
-- ============================================================================

ALTER TABLE `withdrawals`
  MODIFY COLUMN `payout_amount` DECIMAL(18,8) NOT NULL;
