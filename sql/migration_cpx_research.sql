-- ============================================================================
-- Wintaskly — Intégration CPX Research
-- ============================================================================
-- CE QUE CETTE MIGRATION FAIT
--   1. Enregistre les réglages CPX (identifiant d'application, hachage).
--   2. Crée le mur d'offres correspondant, désactivé par défaut.
--
-- ⚠️ LE HACHAGE DE SÉCURITÉ EST UN SECRET
-- Il authentifie les postbacks : quiconque le connaît peut se créditer des
-- Coins par une simple requête. Il est donc stocké en configuration, jamais
-- écrit dans le code, et ne doit pas être partagé. S'il a circulé (capture,
-- fichier, message), demandez sa régénération à CPX.
--
-- La valeur ci-dessous est volontairement laissée VIDE : renseignez-la
-- depuis l'administration après import.
--
-- ============================================================================
-- APRÈS L'IMPORT, TROIS ACTIONS DANS L'INTERFACE CPX
-- ============================================================================
--
-- 1. Onglet « Paramètres de publication » → URL de publication principale :
--
--      https://VOTRE-DOMAINE/api/postback_cpx.php?status={status}
--        &trans_id={trans_id}&user_id={user_id}&subid_1={subid_1}
--        &subid_2={subid_2}&amount_local={amount_local}
--        &amount_usd={amount_usd}&type={type}&offer_id={offer_id}
--        &ip_click={ip_click}&hash={secure_hash}
--
--    Cette URL est OBLIGATOIRE : sans elle, aucun sondage complété ne
--    remonte, et vos membres ne sont jamais crédités.
--
-- 2. Onglet « Paramètres de redirection » → URL de redirection :
--
--      https://VOTRE-DOMAINE/tasks/offerwalls/?message_id={message_id}
--
--    Sans cette URL, l'onglet se ferme après un sondage et l'utilisateur ne
--    voit jamais le message de réussite ou d'échec.
--
-- 3. Onglet « Réglages généraux » → activer le hachage de sécurité.
--    D'après leur documentation, cela nécessite de contacter leur support.
--    Sans lui, n'importe qui peut consulter le profil d'un autre membre en
--    changeant l'identifiant dans l'URL du mur.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  ('cpx.app_id',        ''),
  ('cpx.secure_hash',   ''),
  ('cpx.offerwall_key', 'cpx'),
  ('cpx.check_ip',      '1'),
  -- Adresses de publication documentées par CPX. À ne modifier que s'ils
  -- annoncent un changement. Désactivez plutôt cpx.check_ip si votre
  -- hébergement masque l'adresse réelle derrière un proxy.
  ('cpx.allowed_ips',   '188.40.3.73,2a01:4f8:d0a:30ff::2,157.90.97.92');

-- ---------------------------------------------------------------------
-- Mur d'offres
-- ---------------------------------------------------------------------
-- iframe_url est laissée vide : elle est construite dynamiquement par la
-- page des murs d'offres, car elle doit contenir l'identifiant de
-- l'utilisateur connecté et un hachage calculé à partir de celui-ci.
-- Une URL figée en base ne pourrait pas faire cela.
--
-- active = 0 : le mur reste masqué tant que vous n'avez pas renseigné vos
-- identifiants et vérifié le postback en mode test.
INSERT IGNORE INTO `offerwalls` (`k`, `name`, `description`, `active`, `sort_order`)
VALUES (
  'cpx',
  'CPX Research',
  'Sondages rémunérés. Les gains dépendent de votre profil : un questionnaire peut vous écarter en cours de route si le quota recherché est déjà atteint.',
  0,
  10
);
