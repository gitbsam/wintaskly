-- ============================================================================
-- Wintaskly — Couverture d'article : image téléversée et prompt de génération
-- ============================================================================
-- POURQUOI
-- --------
-- Les couvertures produites par scripts/generate_covers.py composent du texte
-- sur un fond coloré. C'est uniforme et gratuit, mais cela ne remplace pas une
-- vraie illustration : le script ne sait pas dessiner un graphique, un badge
-- ou une scène. Pour des couvertures riches, il faut pouvoir téléverser une
-- image produite ailleurs.
--
-- DEUX COLONNES
-- -------------
--   cover_image  — nom du fichier téléversé, dans media/wintaskly/img/blog/.
--                  Prioritaire sur la couverture générée : dès qu'une image
--                  est fournie, c'est elle qui s'affiche.
--
--   cover_prompt — le prompt utilisé pour générer l'illustration, conservé
--                  avec l'article. Visible uniquement en administration.
--
-- Pourquoi conserver le prompt ? Parce que l'uniformité d'une identité
-- visuelle ne tient pas si chaque couverture est demandée avec une
-- formulation différente. Garder le prompt permet de le réutiliser, de
-- l'ajuster, et de régénérer une image cohérente des mois plus tard — y
-- compris avec un autre outil. C'est la mémoire de votre charte graphique.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

ALTER TABLE `blog_posts`
     ADD COLUMN IF NOT EXISTS `cover_image`  VARCHAR(190) NULL AFTER `cover_emoji`,
     ADD COLUMN IF NOT EXISTS `cover_prompt` TEXT         NULL AFTER `cover_image`;
