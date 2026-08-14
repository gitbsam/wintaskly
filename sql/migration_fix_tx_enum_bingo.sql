-- ============================================================================
-- Wintaskly — Migration : réparation de l'ENUM transactions.type (Bingo)
-- ============================================================================
-- BUG CORRIGÉ ICI
-- ---------------
-- La table `transactions` déclare bien 'bingo_buy' et 'bingo_win' dans son
-- CREATE TABLE, mais un ALTER ... MODIFY plus loin dans schema.sql
-- redéfinissait l'ENUM SANS ces deux valeurs. Comme MODIFY remplace la
-- définition entière, les deux types disparaissaient du type de colonne.
--
-- Conséquence observée : toute insertion d'une transaction Bingo était
-- tronquée par MySQL en chaîne vide ('') avec un simple avertissement, sans
-- erreur bloquante. Les coins étaient bien crédités au membre, mais la
-- transaction était enregistrée avec un type INVALIDE. Résultat : les gains
-- du Bingo n'étaient comptés dans AUCUNE statistique filtrée par type
-- (total des coins distribués sur l'accueil, historiques, exports).
--
-- Cette migration :
--   1) rétablit l'ENUM complet ;
--   2) répare les lignes déjà enregistrées avec un type vide, en s'appuyant
--      sur le format de `meta` écrit par includes/bingo.php :
--        - 'bingo_card #<id>' → achat de carton (montant négatif)
--        - 'round #<id>'      → gain de jackpot (montant positif)
--      Les lignes au type vide qui ne correspondent à aucun de ces deux
--      motifs sont laissées telles quelles (aucune supposition hasardeuse).
--
-- Idempotent : relançable sans effet de bord (MODIFY est idempotent, et les
-- UPDATE ne ciblent que les lignes encore au type vide).
-- ============================================================================

ALTER TABLE `transactions`
  MODIFY `type` ENUM('faucet','shortlink','ptc','offerwall','referral','withdraw','admin','bonus','daily_bonus','achievement','bingo_buy','bingo_win') NOT NULL;

-- Réparation des lignes historiques tronquées (achats de carton)
UPDATE `transactions`
   SET `type` = 'bingo_buy'
 WHERE `type` = ''
   AND `meta` LIKE 'bingo\_card #%'
   AND `coins` <= 0;

-- Réparation des lignes historiques tronquées (gains de jackpot)
UPDATE `transactions`
   SET `type` = 'bingo_win'
 WHERE `type` = ''
   AND `meta` LIKE 'round #%'
   AND `coins` > 0;
