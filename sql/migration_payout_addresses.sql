-- ============================================================================
-- Wintaskly — Adresses de paiement de confiance
-- ============================================================================
-- LE PROBLÈME
-- -----------
-- L'adresse de retrait était saisie librement dans le formulaire, à chaque
-- demande. Conséquence : quiconque obtient l'accès à une session ouverte peut
-- saisir sa propre adresse et vider le solde en une seule opération — sans
-- mot de passe, sans code, et sans laisser à la victime le temps de réagir.
--
-- C'est le scénario le plus coûteux pour un utilisateur, et le plus simple
-- pour un attaquant.
--
-- LE PRINCIPE
-- -----------
-- L'adresse n'est plus saisie au moment du retrait. Elle est déclarée à
-- l'avance, dans un espace dédié, puis CONFIRMÉE par une vérification
-- renforcée (application, SMS, code de secours, ou e-mail à défaut).
--
-- Au moment du retrait, l'utilisateur ne fait que CHOISIR parmi ses adresses
-- confirmées. Une adresse non confirmée n'est pas proposée, et une demande
-- vers une adresse inconnue est refusée côté serveur — pas seulement masquée
-- dans l'interface.
--
-- Un attaquant doit donc franchir deux barrières distinctes : la session, et
-- une preuve indépendante d'elle. Et l'ajout d'une adresse déclenche une
-- notification, ce qui laisse une chance de réagir.
--
-- LA CONTRAINTE D'UNICITÉ
-- -----------------------
-- (user_id, method_id, address) empêche les doublons, qui rendraient la liste
-- confuse et l'audit difficile. Elle n'empêche PAS deux utilisateurs
-- d'utiliser la même adresse : c'est légitime (foyer partagé), et le contrôle
-- anti-fraude s'en charge séparément.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_payout_addresses` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED   NOT NULL,
  `method_id`    INT UNSIGNED   NOT NULL,
  `label`        VARCHAR(60)    NULL,
  `address`      VARCHAR(255)   NOT NULL,
  `confirmed_at` DATETIME       NULL,
  `last_used_at` DATETIME       NULL,
  `created_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_method_addr` (`user_id`, `method_id`, `address`),
  KEY `idx_user` (`user_id`),
  KEY `idx_confirmed` (`user_id`, `confirmed_at`),
  CONSTRAINT `fk_upa_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Réglage
-- ---------------------------------------------------------------------
-- payout.require_saved_address : quand la valeur vaut 1, un retrait n'est
-- possible que vers une adresse enregistrée ET confirmée. Mettre 0 rétablit
-- la saisie libre — utile pendant une transition, à ne pas laisser ainsi.
INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  ('payout.require_saved_address', '1');
