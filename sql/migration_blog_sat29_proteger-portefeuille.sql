-- ============================================================================
-- Wintaskly — SATELLITE 29 (pilier 4 + 6) : "Protéger son portefeuille"
-- ============================================================================
-- ~800 mots, catégorie Crypto.
--
-- ⚠️ YMYL. Aucune marque de portefeuille, aucun service nommé, aucun conseil
-- d'investissement. L'article porte sur la protection de ce qu'on détient
-- déjà — pas sur ce qu'il faudrait détenir.
--
-- Angle distinct du satellite "portefeuille chaud/froid" (qui traite du
-- CHOIX du type) : celui-ci traite des erreurs concrètes qui font perdre
-- les fonds.
--
-- CALENDRIER : published_at = 2026-10-14.
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'proteger-son-portefeuille-crypto-erreurs-couteuses',
 (SELECT id FROM blog_categories WHERE slug='crypto'),
 'Protéger son portefeuille crypto : les erreurs qui coûtent cher',
 'Ici, une erreur ne se corrige pas. Les six situations qui font perdre des fonds définitivement, et les réflexes qui les évitent.',
 '🔐',
 'Équipe Wintaskly',
 'Protéger son portefeuille crypto : erreurs à éviter',
 'Les erreurs irréversibles avec un portefeuille de cryptomonnaie : phrase de récupération, adresse, réseau, presse-papiers. Et les réflexes qui les préviennent.',
 'published', 5, '2026-10-14 16:05:00',
 '<p>Avec un compte bancaire, une erreur se rattrape : opposition, rappel de virement, service client. Avec un portefeuille de cryptomonnaie, non. Une transaction validée est définitive, et aucun recours n''existe — ni auprès de la plateforme, ni auprès du réseau, ni auprès de quiconque.</p>
<p>Cette différence justifie quelques précautions. Voici les six situations qui font réellement perdre des fonds, par ordre de fréquence.</p>

<h2>1. Perdre la phrase de récupération</h2>
<p>C''est la première cause de pertes, loin devant le piratage.</p>
<p>La phrase de récupération — cette suite de mots générée à la création du portefeuille — <strong>est</strong> le portefeuille. Sans elle et sans accès à l''appareil, les fonds deviennent inaccessibles pour toujours. Aucune procédure de récupération n''existe : c''est un choix de conception, pas une lacune.</p>
<h3>Ce qu''il faut faire</h3>
<ul>
<li><strong>La noter physiquement</strong> à la création, pas plus tard. « Je la sauvegarderai ce week-end » est l''origine de bien des pertes.</li>
<li><strong>En conserver deux copies</strong>, à deux endroits distincts — contre le vol comme contre l''incendie ou le dégât des eaux.</li>
<li><strong>Ne jamais la stocker en ligne</strong> : ni photo dans le cloud, ni fichier dans la messagerie, ni note synchronisée. Ces emplacements sont exactement ce que vise une compromission de compte.</li>
</ul>

<h2>2. La communiquer</h2>
<p>Une règle sans aucune exception : <strong>personne de légitime ne demandera jamais votre phrase de récupération.</strong> Ni un support, ni un développeur, ni un service de « vérification », ni une mise à jour.</p>
<p>Toute demande en ce sens est une tentative de vol, quelle que soit sa présentation — même parfaitement imitée, même urgente, même venant d''un compte qui semble officiel.</p>
<p>Le même principe vaut pour les sites demandant de « connecter » ou « valider » un portefeuille en saisissant la phrase. Un portefeuille légitime ne se connecte jamais ainsi.</p>

<h2>3. Le détournement du presse-papiers</h2>
<p>Une attaque discrète et redoutablement efficace, encore peu connue.</p>
<p>Un logiciel malveillant installé sur l''appareil surveille le presse-papiers. Quand il détecte une adresse de cryptomonnaie copiée, il la <strong>remplace silencieusement</strong> par celle de l''attaquant. Vous collez, tout semble normal — les adresses se ressemblent toutes — et les fonds partent ailleurs.</p>
<h3>Le réflexe qui l''annule</h3>
<p>Après avoir collé une adresse, <strong>vérifiez les premiers et les derniers caractères</strong> par rapport à la source. Cette vérification prend trois secondes et neutralise complètement cette attaque.</p>

<h2>4. Se tromper de réseau</h2>
<p>Une même cryptomonnaie peut circuler sur plusieurs réseaux, avec des adresses de format parfois identique. Envoyer vers une adresse d''un autre réseau que celui attendu entraîne généralement une perte définitive.</p>
<p>Vérifiez toujours <strong>deux choses</strong> côté destinataire : la monnaie ET le réseau. La première seule ne suffit pas.</p>

<h2>5. Confondre adresse et clé privée</h2>
<p>Confusion fréquente chez les débutants, aux conséquences opposées :</p>
<ul>
<li><strong>L''adresse est publique.</strong> La partager pour recevoir des fonds ne présente aucun risque — c''est son rôle.</li>
<li><strong>La clé privée et la phrase de récupération sont secrètes.</strong> Quiconque les détient possède les fonds, immédiatement.</li>
</ul>
<p>En cas de doute : si on vous demande quelque chose pour <em>vous envoyer</em> de l''argent, c''est l''adresse. Si on vous demande quelque chose pour « vérifier », « débloquer » ou « sécuriser », c''est une arnaque.</p>

<h2>6. Négliger la sécurité de l''appareil</h2>
<p>Un portefeuille logiciel n''est jamais plus sûr que le téléphone ou l''ordinateur qui l''héberge.</p>
<ul>
<li><strong>N''installez d''applications que depuis les magasins officiels</strong>, et vérifiez l''éditeur : les portefeuilles contrefaits sont nombreux et convaincants.</li>
<li><strong>Maintenez le système à jour.</strong> Beaucoup d''attaques exploitent des failles corrigées depuis longtemps.</li>
<li><strong>Verrouillez l''appareil</strong> par code ou biométrie.</li>
<li><strong>Méfiez-vous des extensions de navigateur</strong> : certaines lisent tout ce qui s''affiche à l''écran.</li>
</ul>

<h2>Adapter l''effort au montant</h2>
<p>Toutes ces précautions ne se justifient pas pour quelques euros en transit. Le bon principe est la proportionnalité :</p>
<ul>
<li><strong>Petits montants destinés à être convertis rapidement</strong> — un portefeuille simple suffit, avec une double authentification.</li>
<li><strong>Sommes conservées sans échéance</strong> — phrase sauvegardée hors ligne, en double.</li>
<li><strong>Montant dont la perte serait un vrai problème</strong> — protection renforcée, et transferts vérifiés deux fois.</li>
</ul>

<h2>En résumé</h2>
<p>Les pertes viennent rarement d''un piratage sophistiqué. Elles viennent d''une phrase mal sauvegardée, d''une adresse mal vérifiée ou d''un réseau confondu.</p>
<p>Trois réflexes couvrent l''essentiel : noter la phrase hors ligne dès la création, ne jamais la communiquer, et vérifier le début et la fin de chaque adresse après l''avoir collée.</p>
<p>Pour choisir le type de portefeuille adapté à votre situation, consultez <a href="/blog/portefeuille-chaud-froid-lequel-choisir">Portefeuille chaud ou froid</a>, et pour l''ensemble des protections d''un compte, <a href="/blog/securiser-son-compte-et-ses-gains">Sécuriser son compte et ses gains</a>.</p>
<p class="wt-article__disclaimer"><em>Cet article est pédagogique et ne recommande aucun portefeuille, service ou marque. Il ne constitue pas un conseil en investissement.</em></p>'
);
