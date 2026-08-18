-- ============================================================================
-- Wintaskly — Correction du journal des commissions de parrainage
-- ============================================================================
-- SYMPTÔME
-- --------
-- La page de parrainage affiche « +0 Coins » (ou un total sous-évalué) pour
-- un filleul pourtant actif quotidiennement, alors que le solde du parrain
-- augmente bien.
--
-- CAUSE
-- -----
-- Deux écritures ont lieu quand une commission est versée :
--   1. une transaction de type `referral` — qui crédite réellement le compte ;
--   2. une ligne dans `referral_earnings` — qui alimente l'affichage.
--
-- La colonne `referral_earnings.source` est un ENUM. Sur une base créée avant
-- l'extension de la commission aux tâches PTC et offerwalls, cet ENUM
-- n'accepte que 'faucet' et 'shortlink'. Toute commission issue d'une autre
-- tâche fait donc échouer l'insertion — silencieusement, car l'erreur n'était
-- pas journalisée.
--
-- Résultat : le parrain est payé, mais la page ne le montre pas. C'est le
-- pire des cas, puisque l'utilisateur conclut qu'il n'est pas rémunéré.
--
-- CE QUE FAIT CETTE MIGRATION
-- ---------------------------
--   1. Élargit l'ENUM aux quatre tâches rémunérées.
--   2. RECONSTRUIT les lignes manquantes à partir des transactions déjà
--      enregistrées — l'historique perdu est donc récupéré, pas abandonné.
--
-- La reconstruction s'appuie sur la méta des transactions, au format
-- `from_user:<id>,source:<tâche>`, écrite depuis l'origine. Les commissions
-- déjà journalisées ne sont pas dupliquées.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

-- ---------------------------------------------------------------------
-- 1) Élargir l'ENUM
-- ---------------------------------------------------------------------
ALTER TABLE `referral_earnings`
  MODIFY `source` ENUM('faucet','shortlink','ptc','offerwall') NOT NULL;

-- ---------------------------------------------------------------------
-- 2) Reconstruire les commissions manquantes
-- ---------------------------------------------------------------------
-- Pour chaque transaction `referral` sans ligne correspondante, on recrée
-- l'entrée. `source_amount` est déduit de la commission et du taux configuré
-- (commission = gain × taux), avec un repli à 10 % si le réglage est absent.
INSERT INTO `referral_earnings`
  (`referrer_id`, `referee_id`, `source`, `source_amount`, `commission`, `created_at`)
SELECT
    t.user_id                                              AS referrer_id,
    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(t.meta, 'from_user:', -1), ',', 1) AS UNSIGNED) AS referee_id,
    SUBSTRING_INDEX(t.meta, 'source:', -1)                 AS source,
    /* Le gain d'origine se déduit de la commission et du taux.
       La clé est `referral_rate`, exprimée en FRACTION (0.10 = 10 %),
       et non en pourcentage. IFNULL couvre le cas où elle serait absente. */
    ROUND(t.coins / GREATEST(
        IFNULL((SELECT CAST(NULLIF(v,'') AS DECIMAL(10,4)) FROM config WHERE k = 'referral_rate' LIMIT 1), 0.10),
        0.0001), 4)                                        AS source_amount,
    t.coins                                                AS commission,
    t.created_at
  FROM `transactions` t
 WHERE t.type = 'referral'
   AND t.meta LIKE 'from_user:%,source:%'
   AND SUBSTRING_INDEX(t.meta, 'source:', -1) IN ('faucet','shortlink','ptc','offerwall')
   AND NOT EXISTS (
         /* Anti-doublon : on compare aussi la SOURCE et le filleul.
            Plusieurs commissions peuvent tomber dans la même seconde —
            comparer seulement la date et le montant ferait considérer
            toutes les lignes du lot comme déjà présentes, et la
            reconstruction ne récupérerait rien. */
         SELECT 1 FROM `referral_earnings` re
          WHERE re.referrer_id = t.user_id
            AND re.created_at  = t.created_at
            AND re.commission  = t.coins
            AND re.source      = SUBSTRING_INDEX(t.meta, 'source:', -1)
            AND re.referee_id  = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(t.meta, 'from_user:', -1), ',', 1) AS UNSIGNED)
       );
