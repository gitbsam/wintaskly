-- ============================================================================
-- Wintaskly — Migration : passage du contenu en base au vouvoiement
-- ============================================================================
-- Le site est passé du tutoiement au vouvoiement (fichiers de langue et
-- templates traités dans le code). Mais une partie du texte affiché vit en
-- base et n'est donc pas couverte par la mise à jour du code :
--   - homepage_blocks : blocs éditoriaux de la page d'accueil
--   - blog_posts      : titres, extraits et corps des articles
--   - blog_categories : descriptions
--
-- Cette migration applique les mêmes remplacements à ces contenus.
--
-- ⚠️ IMPORTANT — À LIRE AVANT D'APPLIQUER
--   1. Ces REPLACE sont mécaniques : ils traitent les pronoms et possessifs,
--      pas la conjugaison de tous les verbes. Après application, relisez vos
--      articles : une tournure comme « tu gagnes » deviendra « vous gagnes »
--      s'il en reste une non couverte ci-dessous.
--   2. Faites une sauvegarde de la base avant. Un REPLACE ne se défait pas.
--   3. Importez avec --default-character-set=utf8mb4, sinon les accents
--      seront corrompus.
--
-- Idempotent : relancer la migration ne change rien (les motifs ont déjà
-- disparu au premier passage).
-- ============================================================================

-- ---------- Blocs de la page d'accueil ----------
UPDATE `homepage_blocks` SET
  `title`   = REPLACE(REPLACE(REPLACE(REPLACE(`title`,   'ton ', 'votre '), 'ta ', 'votre '), 'tes ', 'vos '), 'Ton ', 'Votre '),
  `content` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`content`,
                'fais grimper ton XP et invite tes amis', 'faites grimper votre XP et invitez vos amis'),
                'complète des shortlinks', 'complétez des shortlinks'),
                'ton ', 'votre '), 'Ton ', 'Votre '), 'tes ', 'vos '), 'Tes ', 'Vos ')
WHERE `title` LIKE '%to%' OR `content` LIKE '%t%';

-- ---------- Articles de blog ----------
-- Verbes d'abord (sinon « tu gagnes » deviendrait « vous gagnes »)
UPDATE `blog_posts` SET
  `body` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`body`,
             'tu as ', 'vous avez '), 'tu es ', 'vous êtes '), 'tu peux ', 'vous pouvez '),
             'tu veux ', 'vous voulez '), 'tu dois ', 'vous devez '), 'tu sais ', 'vous savez '),
             'Tu as ', 'Vous avez '), 'Tu peux ', 'Vous pouvez ');

UPDATE `blog_posts` SET
  `title`            = REPLACE(REPLACE(REPLACE(`title`,   'ton ', 'votre '), 'tes ', 'vos '), 'ta ', 'votre '),
  `excerpt`          = REPLACE(REPLACE(REPLACE(REPLACE(`excerpt`, 'ton ', 'votre '), 'tes ', 'vos '), 'ta ', 'votre '), 'Ton ', 'Votre '),
  `meta_description` = REPLACE(REPLACE(REPLACE(`meta_description`, 'ton ', 'votre '), 'tes ', 'vos '), 'ta ', 'votre '),
  `body`             = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`body`,
                         'ton ', 'votre '), 'Ton ', 'Votre '), 'tes ', 'vos '), 'Tes ', 'Vos '),
                         'toi', 'vous'), 'Toi', 'Vous');

-- ---------- Descriptions de catégories ----------
UPDATE `blog_categories` SET
  `description` = REPLACE(REPLACE(REPLACE(`description`, 'ton ', 'votre '), 'tes ', 'vos '), 'ta ', 'votre ');
