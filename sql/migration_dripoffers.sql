-- ============================================================================
-- Wintaskly — Intégration Drip Offers
-- ============================================================================
-- CE QUE CETTE MIGRATION FAIT
--   1. Enregistre les réglages Drip Offers (API Key, Secret Key, Filtrage IP).
--   2. Crée le mur d'offres correspondant, désactivé par défaut.
--
-- ⚠️ LA CLÉ SECRÈTE (SECRET KEY) EST UN SECRET
-- Elle authentifie les postbacks : quiconque la connaît peut se créditer des
-- Coins par une simple requête MD5. Elle est donc stockée en configuration,
-- jamais écrite en clair dans le code, et ne doit pas être partagée.
-- S'elle a circulé, régénérez-la depuis le panel Drip Offers.
--
-- Les valeurs ci-dessous sont volontairement laissées VIDES : renseignez-les
-- depuis l'administration de Wintaskly après import.
--
-- ============================================================================
-- APRÈS L'IMPORT, DEUX ACTIONS DANS L'INTERFACE DRIP OFFERS (My Apps)
-- ============================================================================
--
-- 1. Réglage de l'URL de Postback :
--
--      https://VOTRE-DOMAINE/api/dripoffers_postback.php
--
--    Cette URL est OBLIGATOIRE : Drip Offers y envoie des requêtes HTTP POST 
--    contenant : subId, transId, reward, status, signature, etc.
--    Sans cette URL, aucune offre complétée ne remonte.
--
-- 2. Récupération des clés API :
--    Récupérez votre "API Key" et votre "Secret Key" dans l'onglet "My Apps"
--    et saisissez-les dans l'administration de Wintaskly.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

INSERT IGNORE INTO `config` (`k`, `v`) VALUES
  ('dripoffers.api_key',      ''),
  ('dripoffers.secret_key',   ''),
  ('dripoffers.offerwall_key', 'dripoffers'),
  ('dripoffers.check_ip',      '1'),
  -- Adresse IP de publication officielle documentée par Drip Offers (89.116.149.103).
  -- À ne modifier que si leur support annonce un changement. Désactivez plutôt
  -- dripoffers.check_ip si votre hébergement se trouve derrière un reverse-proxy.
  ('dripoffers.allowed_ips',  '89.116.149.103');

-- ---------------------------------------------------------------------
-- Mur d'offres
-- ---------------------------------------------------------------------
-- iframe_url est laissée vide : elle est construite dynamiquement par la
-- page des murs d'offres (https://dripoffers.com/offerwall/[API_KEY]/[USER_ID])
-- car elle doit inclure l'API Key et l'ID de l'utilisateur connecté.
--
-- active = 0 : le mur reste masqué tant que vous n'avez pas renseigné vos
-- identifiants et vérifié le postback.
INSERT IGNORE INTO `offerwalls` (`k`, `name`, `description`, `active`, `sort_order`)
VALUES (
  'dripoffers',
  'Drip Offers',
  'Complétez des offres, sondages, applications et tâches rémunérées pour gagner des Coins.',
  0,
  20
);