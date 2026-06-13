-- ============================================================================
-- Wintaskly — Migration : Shortlinks Mode API
-- ============================================================================
-- Ajoute 3 colonnes à la table `shortlinks` pour permettre 2 modes :
--   - 'manual' : l'admin colle une URL pré-créée sur le provider (mode actuel)
--   - 'api'    : Wintaskly appelle l'API du provider pour générer l'URL courte
--                à chaque clic utilisateur (auto-création)
--
-- Compatible exe.io, shrinkme.io, shortest, et tout provider qui expose une
-- API REST de type :  GET https://provider.com/api?api=TOKEN&url=URL_ENCODED
-- → réponse JSON  { "status": "success", "shortenedUrl": "https://..." }
--
-- Applique cette migration UNE SEULE FOIS sur les installs existantes via :
--   mysql -u USER -p BDD_NAME < sql/migration_shortlinks_api.sql
-- ou via phpMyAdmin → onglet SQL → copie-colle ce contenu.
-- ============================================================================

ALTER TABLE `shortlinks`
  ADD COLUMN `mode` ENUM('manual','api') NOT NULL DEFAULT 'manual'
    AFTER `provider`,
  ADD COLUMN `api_endpoint` VARCHAR(255) NULL
    AFTER `destination_url`,
  ADD COLUMN `api_token` VARCHAR(255) NULL
    AFTER `api_endpoint`;

-- Note : les liens existants restent en mode 'manual' par défaut.
-- L'admin peut basculer en mode 'api' depuis /admin/shortlinks.php pour
-- les nouveaux liens, sans casser ceux existants.
