-- ============================================================================
-- Wintaskly — Clé de callback pour les liens en mode manuel
-- ============================================================================
-- POURQUOI
-- --------
-- Le callback des liens courts authentifie le retour avec `callback_key`.
-- Les liens créés en mode manuel avant cette correction pouvaient être
-- enregistrés sans clé : le retour était alors refusé, et aucun gain
-- crédité — sans message visible pour le membre, qui concluait simplement
-- que la tâche ne fonctionnait pas.
--
-- Cette migration attribue une clé aléatoire à tout lien qui n'en a pas.
--
-- ⚠️ La clé générée ici n'est PAS chiffrée, contrairement à celles saisies
-- en administration. Après import, ouvrez chaque lien concerné dans
-- l'administration et enregistrez-le : la clé sera alors re-chiffrée selon
-- le mécanisme habituel. Une clé en clair reste préférable à pas de clé du
-- tout — sans elle, la tâche ne rapporte rien.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

UPDATE `shortlinks`
   SET `callback_key` = SUBSTRING(MD5(CONCAT(RAND(), id, UNIX_TIMESTAMP())), 1, 32)
 WHERE `callback_key` IS NULL OR `callback_key` = '';

-- Contrôle : cette requête doit renvoyer 0.
SELECT COUNT(*) AS liens_sans_cle
  FROM `shortlinks`
 WHERE `callback_key` IS NULL OR `callback_key` = '';

-- ---------------------------------------------------------------------
-- Délai minimal ajouté au temps de passerelle avant validation.
-- Écarte les appels directs au callback : un parcours réel impose
-- l'attente de la passerelle PUIS celle du raccourcisseur.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  ('shortlinks.min_provider_seconds', '5');
