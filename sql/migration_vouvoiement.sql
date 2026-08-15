-- ============================================================================
-- Wintaskly — Migration : contenu en base au vouvoiement
-- ============================================================================
-- ⚠️ CETTE MIGRATION REMPLACE UNE VERSION ANTÉRIEURE DÉFECTUEUSE.
--
-- La version précédente utilisait des REPLACE() sur les pronoms. Or REPLACE()
-- ne respecte pas les limites de mots : remplacer 'tes ' par 'vos ' a corrompu
-- des mots contenant cette suite de lettres —  « toutes les 3 heures » est
-- devenu « touvos les 3 heures », « faites » → « faivos », « différentes » →
-- « différenvos ». Elle laissait aussi les verbes au singulier
-- (« Transforme votre temps » au lieu de « Transformez »).
--
-- Cette version écrit directement le texte correct, relu, plutôt que
-- d'appliquer des substitutions aveugles. Si vous aviez appliqué l'ancienne,
-- celle-ci répare les blocs concernés.
--
-- ⚠️ Importez avec --default-character-set=utf8mb4.
-- ============================================================================


UPDATE `homepage_blocks` SET `title` = 'Transformez votre temps en récompenses', `content` = 'Réclamez des Coins toutes les 3 heures, complétez des shortlinks, faites grimper votre XP et invitez vos amis pour gagner 10% sur tous leurs gains.' WHERE `k` = 'hero';

UPDATE `homepage_blocks` SET `title` = 'Une plateforme qui paye' WHERE `k` = 'stats';

UPDATE `homepage_blocks` SET `title` = 'Comment ça marche ?', `content` = 'Trois étapes : 1) Créez votre compte. 2) Réclamez votre Faucet ou complétez un shortlink. 3) Échangez vos Coins.' WHERE `k` = 'how';

UPDATE `homepage_blocks` SET `title` = 'Pourquoi Wintaskly ?', `content` = 'Wintaskly a été créé pour rendre la monétisation des micro-tâches simple, transparente et sécurisée : chaque gain est traçable, chaque retrait est vérifiable, et notre système anti-fraude protège aussi bien les utilisateurs honnêtes que la valeur des récompenses.' WHERE `k` = 'why';

UPDATE `homepage_blocks` SET `title` = 'Un écosystème de partenaires vérifiés', `content` = 'Les récompenses distribuées sur Wintaskly sont financées par nos partenaires publicitaires et réseaux d''offres. Les retraits sont traités via des prestataires de paiement reconnus.' WHERE `k` = 'partners';


-- ---------- Descriptions de catégories du blog ----------
-- Sûr ici : ces valeurs ne contiennent pas de suite de lettres piégeuse.
UPDATE `blog_categories` SET `description` = 'Épargne, budget, inflation : les bases de la finance personnelle'
 WHERE `slug` = 'finance';

