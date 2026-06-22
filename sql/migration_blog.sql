-- ============================================================================
-- Wintaskly — Migration : Système de blog (contenu éditorial)
-- ============================================================================
-- Blog public indexable par les moteurs de recherche, géré via /admin.
-- Objectif : fournir du contenu original et substantiel (requis AdSense).
--
-- Crée :
--   1. Table `blog_categories` : catégories d'articles
--   2. Table `blog_posts`      : articles (avec SEO, statut, vues)
--
-- À exécuter UNE FOIS dans phpMyAdmin pour une install existante.
-- ============================================================================

-- 1) Catégories
CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(80)  NOT NULL COMMENT 'URL : /blog/categorie/<slug>',
  `name`        VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) NULL,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Articles
CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`            VARCHAR(160) NOT NULL COMMENT 'URL : /blog/<slug>',
  `category_id`     INT UNSIGNED NULL,
  `title`           VARCHAR(200) NOT NULL,
  `excerpt`         VARCHAR(320) NULL COMMENT 'Résumé court (listing + meta description)',
  `body`            MEDIUMTEXT   NOT NULL COMMENT 'Contenu HTML de l''article',
  `cover_emoji`     VARCHAR(16)  NULL COMMENT 'Emoji d''illustration (léger, pas d''image)',
  `author_name`     VARCHAR(120) NULL DEFAULT 'Équipe Wintaskly',
  `meta_title`      VARCHAR(200) NULL COMMENT 'Balise <title> SEO (sinon = title)',
  `meta_description` VARCHAR(320) NULL COMMENT 'Meta description SEO (sinon = excerpt)',
  `status`          ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `views`           INT UNSIGNED NOT NULL DEFAULT 0,
  `reading_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `published_at`    DATETIME NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_post_slug` (`slug`),
  KEY `idx_status_pub` (`status`, `published_at`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `fk_post_category`
    FOREIGN KEY (`category_id`) REFERENCES `blog_categories`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Catégories par défaut
INSERT IGNORE INTO `blog_categories` (`slug`, `name`, `description`, `sort_order`) VALUES
 ('guides',      'Guides',          'Tutoriels pas à pas pour bien démarrer',          10),
 ('crypto',      'Crypto',          'Comprendre les cryptomonnaies et les paiements',  20),
 ('astuces',     'Astuces',         'Conseils pour optimiser tes gains',               30),
 ('actualites',  'Actualités',      'Nouveautés et mises à jour de la plateforme',     40);

-- 4) Config blog
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
 ('blog.enabled',      '1'),
 ('blog.title',        'Le Blog Wintaskly'),
 ('blog.description',  'Guides, astuces et actualités sur les micro-gains et la crypto.');

-- 5) Zones publicitaires du blog
INSERT IGNORE INTO `ad_zones` (`k`, `label`, `code`, `active`) VALUES
 ('blog_article_top',    'Blog — Haut d''article', '<!-- Insérer ici le code AdSense responsive -->', 1),
 ('blog_article_bottom', 'Blog — Bas d''article',  '<!-- Insérer ici le code AdSense responsive -->', 1);

-- Enregistre la migration
INSERT IGNORE INTO `applied_migrations` (`filename`, `version`, `notes`) VALUES
 ('migration_blog.sql', '8.11.0', 'Système de blog éditorial (contenu AdSense)');
