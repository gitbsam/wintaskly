-- ============================================================================
-- Wintaskly — Migration : sections "Pourquoi Wintaskly" et "Partenaires"
-- ============================================================================
-- INSERT IGNORE est nativement idempotent grâce à la clé UNIQUE(k) de
-- homepage_blocks — sûr à relancer, n'écrase jamais un contenu déjà
-- personnalisé par l'admin (INSERT IGNORE n'écrit que si la clé n'existe
-- pas encore).
-- ============================================================================
INSERT IGNORE INTO `homepage_blocks` (`k`,`title`,`content`,`visible`,`sort_order`) VALUES
 ('why',
  'Pourquoi Wintaskly ?',
  'Wintaskly a été créé pour rendre la monétisation des micro-tâches simple, transparente et sécurisée : chaque gain est traçable, chaque retrait est vérifiable, et notre système anti-fraude protège aussi bien les utilisateurs honnêtes que la valeur des récompenses.',
  1, 4),
 ('partners',
  'Un écosystème de partenaires vérifiés',
  'Les récompenses distribuées sur Wintaskly sont financées par nos partenaires publicitaires et réseaux d''offres. Les retraits sont traités via des prestataires de paiement reconnus.',
  1, 5);
