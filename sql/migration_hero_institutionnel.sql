-- ============================================================================
-- Wintaskly — Hero institutionnel et bloc « Comment ça marche » en 4 étapes
-- ============================================================================
-- Les textes du hero et du bloc « how » sont stockés en base (homepage_blocks)
-- et priment sur les traductions : les modifier dans includes/lang/ ne suffit
-- donc pas. Cette migration met la base au même niveau.
--
-- CE QUI CHANGE, ET POURQUOI
-- --------------------------
-- Ancien hero :
--   « Transformez votre temps en récompenses »
--   « Réclamez des Coins toutes les 3 heures, complétez des shortlinks,
--     faites grimper votre XP et invitez vos amis pour gagner 10% […] »
--
-- Deux problèmes. D'abord, c'est une promesse de gain en première ligne —
-- exactement le registre qui fait juger un site comme purement transactionnel.
-- Ensuite, le sous-titre empile du vocabulaire interne (Coins, shortlinks, XP)
-- que personne ne comprend avant de s'être inscrit.
--
-- Le nouveau hero explique d'abord D'OÙ VIENT L'ARGENT, ce qui est à la fois
-- la question qu'un visiteur méfiant se pose en premier et l'information qui
-- rassure le plus. La promesse d'inscription est reportée aux boutons.
--
-- Le bloc « how » passe de 3 à 4 étapes : l'étape de VALIDATION manquait,
-- alors que c'est elle qui explique pourquoi certaines actions ne sont pas
-- créditées immédiatement — la première source de questions au support.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

UPDATE `homepage_blocks`
   SET `title`   = 'Une plateforme qui rémunère la participation à des campagnes d''annonceurs',
       `content` = 'Des annonceurs financent des campagnes — affichage publicitaire, sondages, offres partenaires. Wintaskly vous permet d''y participer et vous reverse une part de ce qu''ils paient. Les règles sont écrites, les gains traçables dans votre historique, et aucun investissement n''est demandé.'
 WHERE `k` = 'hero';

UPDATE `homepage_blocks`
   SET `title`   = 'Comment ça fonctionne',
       `content` = 'Quatre étapes : 1) Créez votre compte. 2) Participez aux campagnes disponibles. 3) L''action est validée, immédiatement ou par le partenaire. 4) Convertissez vos Coins dès le seuil atteint.'
 WHERE `k` = 'how';
