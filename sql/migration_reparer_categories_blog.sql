-- =====================================================================
-- Wintaskly — reparation du classement des articles
--
-- Les fichiers de contenu livres avant la 9.59.2 codaient en dur
-- l'identifiant de categorie, resolu depuis une autre base. Sur la votre,
-- les identifiants different : 26 articles ont ete ranges dans la
-- mauvaise categorie, et 2 ont ete rejetes par la cle etrangere.
--
-- Ce fichier ne touche QUE les articles nommes ci-dessous, et uniquement
-- leur categorie. Aucun contenu, aucune date, aucun statut n'est modifie.
-- Il est rejouable sans effet supplementaire.
--
-- Les 2 articles manquants ne sont PAS crees ici : reimportez
-- seed_blog_actualites_brouillon.sql (version 9.59.2 ou superieure)
-- apres ce fichier.
-- =====================================================================

-- guides (14 article(s))
UPDATE `blog_posts`
   SET `category_id` = (SELECT `id` FROM `blog_categories` WHERE `slug` = 'guides' LIMIT 1)
 WHERE `slug` IN ('comment-fonctionne-plateforme-micro-taches',
       'premiers-1000-coins-par-ou-commencer',
       'quelle-tache-pour-quel-profil',
       'bloqueur-de-publicite-malentendu',
       'pourquoi-une-tache-a-ete-refusee',
       'erreur-adresse-de-retrait',
       'reconnaitre-un-email-de-phishing',
       'double-authentification-en-5-minutes',
       'comprendre-son-solde-coins-euros',
       'retrait-bloque-8-causes-reelles',
       'choisir-sa-methode-de-retrait',
       'faucetpay-explique-creer-et-relier',
       'seuil-de-retrait-minimum',
       'verifier-une-adresse-de-paiement')
   AND `category_id` <> (SELECT `id` FROM `blog_categories` WHERE `slug` = 'guides' LIMIT 1);

-- astuces (10 article(s))
UPDATE `blog_posts`
   SET `category_id` = (SELECT `id` FROM `blog_categories` WHERE `slug` = 'astuces' LIMIT 1)
 WHERE `slug` IN ('session-20-minutes-organisation',
       'meilleures-heures-pour-les-taches',
       'ne-plus-perdre-une-tache-onglet-ferme',
       'trois-erreurs-de-debutant',
       'routine-quotidienne-qui-tient',
       'bonus-quotidien-ne-pas-casser-la-serie',
       'reperer-une-tache-qui-ne-paiera-pas',
       'batterie-session-micro-taches',
       'economiser-donnees-mobiles',
       'raccourci-ecran-accueil')
   AND `category_id` <> (SELECT `id` FROM `blog_categories` WHERE `slug` = 'astuces' LIMIT 1);

-- crypto (8 article(s))
UPDATE `blog_posts`
   SET `category_id` = (SELECT `id` FROM `blog_categories` WHERE `slug` = 'crypto' LIMIT 1)
 WHERE `slug` IN ('bitcoin-litecoin-tron-differences',
       'satoshi-gwei-sun-petites-unites',
       'pourquoi-retrait-btc-si-petit',
       'frais-de-reseau-trx-vs-btc',
       'microwallet-pourquoi-passer-par-la',
       'creer-un-vrai-portefeuille-crypto',
       'adresse-publique-et-cle-privee',
       'verifier-transaction-explorateur')
   AND `category_id` <> (SELECT `id` FROM `blog_categories` WHERE `slug` = 'crypto' LIMIT 1);

-- finance (6 article(s))
UPDATE `blog_posts`
   SET `category_id` = (SELECT `id` FROM `blog_categories` WHERE `slug` = 'finance' LIMIT 1)
 WHERE `slug` IN ('declarer-ses-gains-en-ligne-impots',
       'formulaire-2086-explique',
       'seuil-305-euros-crypto',
       'formulaire-3916-bis-compte-etranger',
       'flat-tax-ou-bareme-progressif',
       'calendrier-fiscal-outre-mer')
   AND `category_id` <> (SELECT `id` FROM `blog_categories` WHERE `slug` = 'finance' LIMIT 1);

-- actualites (2 article(s))
UPDATE `blog_posts`
   SET `category_id` = (SELECT `id` FROM `blog_categories` WHERE `slug` = 'actualites' LIMIT 1)
 WHERE `slug` IN ('ce-qui-a-change-ce-mois-ci',
       'nouvelles-methodes-de-retrait')
   AND `category_id` <> (SELECT `id` FROM `blog_categories` WHERE `slug` = 'actualites' LIMIT 1);

