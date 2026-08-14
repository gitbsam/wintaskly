-- ============================================================================
-- Wintaskly — Migration : catégorie de blog "Finance"
-- ============================================================================
-- Distincte de "crypto" : couvre l'éducation financière au sens large
-- (épargne, inflation, budget...) pour élargir le blog à une audience plus
-- large que les seuls utilisateurs déjà inscrits. INSERT IGNORE : idempotent,
-- n'écrase jamais un contenu déjà personnalisé par l'admin.
-- ============================================================================
INSERT IGNORE INTO `blog_categories` (`slug`, `name`, `description`, `sort_order`) VALUES
 ('finance', 'Finance', 'Épargne, budget, inflation : les bases de la finance personnelle', 15);
