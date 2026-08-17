-- ============================================================================
-- Wintaskly — PILIER 6 : "La crypto expliquée sans jargon"
-- ============================================================================
-- Sixième article pilier (~1700 mots), catégorie Crypto. Point d'ancrage pour
-- les satellites 27 à 32 de l'architecture éditoriale.
--
-- ⚠️ SUJET YMYL. Règles appliquées :
--   • aucune incitation à investir, aucun conseil d'achat ou de revente ;
--   • aucun cours, prévision, rendement ou objectif de prix ;
--   • aucune recommandation de plateforme d'échange ou de portefeuille ;
--   • le propos reste explicatif : comprendre ce qu'on reçoit, pas spéculer ;
--   • mention explicite de la volatilité et de la fiscalité variable.
--
-- L'article existant "Cryptomonnaie pour débutants" garde son angle (premiers
-- pas) : ce pilier couvre le fonctionnement d'ensemble et le sert de socle.
--
-- INSERT IGNORE : idempotent.
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- CALENDRIER DE PUBLICATION
-- published_at = 2026-09-09 11:48:00 (et non UTC_TIMESTAMP()).
-- Publier 27 articles le même jour signale une production en masse : c'est
-- exactement ce qu'un évaluateur qualité cherche à détecter. Les dates sont
-- donc échelonnées sur jours ouvrés, à des heures variables.
-- Le code n'affiche un article que si published_at <= maintenant : appliquer
-- toutes les migrations d'un coup est donc sans risque, chaque article
-- apparaîtra à sa date.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'la-crypto-expliquee-sans-jargon',
 (SELECT id FROM blog_categories WHERE slug='crypto'),
 'La crypto expliquée sans jargon : le guide complet',
 'Blockchain, portefeuilles, frais de réseau, volatilité : ce qu''il faut vraiment comprendre quand on reçoit ses premiers paiements en cryptomonnaie. Sans promesse, sans jargon.',
 '🔗',
 'Équipe Wintaskly',
 'La crypto expliquée sans jargon : le guide complet',
 'Comprendre les cryptomonnaies simplement : blockchain, portefeuilles, adresses, frais de réseau, volatilité et sécurité, pour ceux qui en reçoivent sans vouloir spéculer.',
 'published', 9, '2026-09-09 11:48:00',
 '<p>La plupart des explications sur la crypto s''adressent à des gens qui veulent investir. Ce guide s''adresse à quelqu''un de différent : une personne qui <strong>reçoit</strong> des cryptomonnaies — parce que c''est le moyen de paiement d''une plateforme, d''un client ou d''un service — et qui aimerait comprendre ce qu''elle a entre les mains.</p>
<p>Pas de promesse de gains, pas de prévision de cours. Juste ce qu''il faut savoir pour ne pas perdre son argent par méconnaissance.</p>

<h2>Ce qu''est une cryptomonnaie, sans métaphore compliquée</h2>
<p>Une cryptomonnaie, c''est une écriture dans un registre partagé. Ce registre — la blockchain — n''est détenu par personne en particulier : il est copié sur des milliers d''ordinateurs qui vérifient en permanence qu''ils ont tous la même version.</p>
<p>Quand vous recevez un paiement, aucune pièce ne vous est envoyée. Une ligne est ajoutée au registre : « telle adresse détient désormais tel montant ». Votre solde n''est que la somme de ces lignes.</p>
<p>Cette structure a deux conséquences directes, et ce sont les seules qui comptent au quotidien :</p>
<ul>
<li><strong>Personne ne peut annuler une transaction.</strong> Ni la plateforme, ni un support, ni un tribunal. Une écriture validée est définitive.</li>
<li><strong>Personne ne peut bloquer votre accès</strong> si vous détenez vos clés. C''est la contrepartie du point précédent : liberté totale, responsabilité totale.</li>
</ul>

<h2>Adresse, clé privée, portefeuille : ne pas confondre</h2>
<p>C''est la source de confusion la plus fréquente, et celle qui coûte le plus cher.</p>
<h3>L''adresse</h3>
<p>Une suite de caractères que vous communiquez pour recevoir des fonds. Elle est <strong>publique</strong> : la partager ne présente aucun risque. C''est l''équivalent d''un numéro de compte.</p>
<h3>La clé privée (ou phrase de récupération)</h3>
<p>La preuve que vous êtes propriétaire de l''adresse. Elle est <strong>secrète</strong>, et quiconque la détient peut tout transférer, immédiatement et sans recours.</p>
<p>Une règle simple, sans exception : <strong>aucun service légitime ne demandera jamais votre clé privée ni votre phrase de récupération.</strong> Aucun. Toute demande en ce sens, quelle que soit sa présentation, est une tentative de vol.</p>
<h3>Le portefeuille</h3>
<p>Un logiciel ou un appareil qui conserve vos clés et vous permet de signer des transactions. Il ne « contient » pas vos cryptos — celles-ci restent sur la blockchain. Il détient la capacité d''en disposer.</p>
<p>D''où l''expression qui résume tout : <em>si vous ne détenez pas les clés, vous ne détenez pas les fonds</em>. Un solde affiché sur un service tiers est une créance sur ce service, pas une possession directe.</p>

<h2>Les différents types de portefeuilles</h2>
<p>Le choix dépend de ce que vous en faites, pas d''une hiérarchie de qualité.</p>
<ul>
<li><strong>Portefeuille hébergé par un service.</strong> Le service détient les clés pour vous. Pratique, aucune gestion technique, mais vous dépendez entièrement de lui — de sa sécurité comme de sa pérennité. Adapté à de petits montants en transit.</li>
<li><strong>Portefeuille logiciel.</strong> Une application sur votre téléphone ou ordinateur ; vous détenez les clés. Bon équilibre pour un usage courant, à condition de sauvegarder la phrase de récupération.</li>
<li><strong>Portefeuille matériel.</strong> Un appareil dédié qui garde les clés hors ligne. Le plus sûr, mais avec un coût d''achat — pertinent au-delà d''un certain montant, pas pour quelques euros.</li>
</ul>
<p>Le bon réflexe : faire correspondre le niveau de protection au montant réellement en jeu. Un portefeuille matériel pour de petits paiements est disproportionné ; un portefeuille hébergé pour une épargne conséquente l''est tout autant, dans l''autre sens.</p>

<h2>Les frais de réseau : pourquoi ils varient autant</h2>
<p>Chaque transaction demande un travail de validation aux ordinateurs du réseau, qui sont rémunérés pour cela. Ces frais ne dépendent ni de la plateforme qui envoie, ni du destinataire.</p>
<p>Deux facteurs les font varier :</p>
<ul>
<li><strong>Le réseau utilisé.</strong> Les blockchains n''ont pas les mêmes coûts de fonctionnement : certaines facturent une fraction de centime, d''autres des sommes qui peuvent dépasser un petit paiement.</li>
<li><strong>L''affluence du moment.</strong> Quand beaucoup de monde transacte, la place devient rare et les frais montent. Le même transfert peut coûter très différemment selon l''heure.</li>
</ul>
<p>Conséquence pratique pour de petits montants : <strong>le choix du réseau compte davantage que tout le reste</strong>. Recevoir l''équivalent de quelques euros sur un réseau à frais élevés peut en absorber une part importante.</p>

<h2>La volatilité, expliquée honnêtement</h2>
<p>Le prix d''une cryptomonnaie varie en permanence, parfois fortement sur quelques heures. Ce n''est ni une anomalie ni un signe de dysfonctionnement : c''est la nature d''un actif dont le prix résulte uniquement de l''offre et de la demande, sans banque centrale pour l''amortir.</p>
<p>Pour quelqu''un qui reçoit de petits paiements, cela signifie concrètement :</p>
<ul>
<li>La valeur de ce que vous recevez peut changer entre le moment du paiement et celui où vous l''utilisez, <strong>à la hausse comme à la baisse</strong>.</li>
<li>Convertir rapidement en monnaie locale supprime cette incertitude, mais implique généralement des frais de conversion.</li>
<li>Conserver expose à la variation. Ce n''est ni bien ni mal — c''est un choix qui vous appartient, et qui doit être conscient.</li>
</ul>
<p>Une catégorie particulière existe : les <strong>stablecoins</strong>, conçus pour rester adossés à une monnaie classique. Ils réduisent la variation de prix, mais introduisent une autre question — celle de la solidité de l''organisme qui garantit cet adossement. Aucun outil n''élimine tous les risques ; ils les déplacent.</p>

<h2>Les erreurs qui coûtent définitivement</h2>
<p>Contrairement à un virement bancaire, il n''existe aucun mécanisme de rappel. Voici ce qui provoque des pertes irréversibles.</p>
<ul>
<li><strong>Envoyer sur le mauvais réseau.</strong> Une même cryptomonnaie peut circuler sur plusieurs réseaux. Envoyer vers une adresse d''un réseau différent aboutit généralement à une perte totale.</li>
<li><strong>Se tromper d''adresse.</strong> Copiez-collez toujours, et vérifiez les premiers et derniers caractères après le collage : certains logiciels malveillants remplacent le presse-papiers par l''adresse d''un attaquant.</li>
<li><strong>Perdre sa phrase de récupération.</strong> Sans elle et sans accès à l''appareil, les fonds sont inaccessibles à jamais. Aucune procédure de récupération n''existe, par conception.</li>
<li><strong>La stocker en ligne.</strong> Une photo dans le cloud ou un fichier dans la messagerie est à la portée de quiconque accède au compte concerné.</li>
</ul>

<h2>Reconnaître les arnaques courantes</h2>
<p>Le secteur en concentre beaucoup, parce que les transactions sont irréversibles. Les schémas se répètent :</p>
<ul>
<li><strong>Le rendement garanti.</strong> Aucun placement en crypto ne peut garantir un rendement. La promesse elle-même est le signal.</li>
<li><strong>Le « doublement » de fonds.</strong> Envoyez, recevez le double : c''est un vol pur, systématiquement.</li>
<li><strong>Le faux support.</strong> Quelqu''un vous contacte en privé pour « résoudre un problème » et demande votre phrase ou un accès à distance.</li>
<li><strong>La relation de confiance construite dans la durée.</strong> Des semaines d''échanges cordiaux avant une « opportunité » sur une plateforme contrôlée par l''escroc. Les premiers retraits fonctionnent, puis tout se bloque.</li>
<li><strong>Le jeton qu''on ne peut plus revendre.</strong> Acheter est possible, vendre ne l''est jamais.</li>
</ul>
<p>Le point commun de toutes ces arnaques : elles exigent une action de votre part que personne de légitime ne demanderait — communiquer une phrase secrète, envoyer des fonds à un inconnu, installer un outil d''accès à distance.</p>

<h2>En résumé</h2>
<p>Recevoir des cryptomonnaies ne demande pas de devenir spécialiste. Quatre notions suffisent : l''adresse se partage, la clé jamais ; le réseau détermine les frais ; la valeur varie ; et une erreur ne se corrige pas.</p>
<p>Tout le reste — trading, placements, projets à fort rendement — relève d''une autre activité, avec d''autres risques, qui n''a rien d''obligatoire pour utiliser la crypto comme simple moyen de paiement.</p>
<p>Pour vos premiers pas concrets, notre article <a href="/blog/cryptomonnaie-debutant-comprendre-bases">Cryptomonnaie pour débutants</a> détaille la mise en place. Et pour comprendre pourquoi ces paiements arrivent souvent plus vite qu''un virement, voyez <a href="/blog/paiements-crypto-vs-virement-bancaire-delais">la comparaison des deux circuits</a>.</p>
<p class="wt-article__disclaimer"><em>Cet article est pédagogique et ne constitue ni un conseil en investissement, ni une incitation à acheter ou détenir des cryptomonnaies. Leur valeur peut varier fortement, y compris à la baisse. La réglementation et la fiscalité applicables varient selon les pays et évoluent : renseignez-vous auprès des autorités compétentes de votre pays de résidence.</em></p>'
);
