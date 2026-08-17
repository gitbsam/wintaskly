-- ============================================================================
-- Wintaskly — PILIER 4 : "Sécuriser son compte et ses gains"
-- ============================================================================
-- Quatrième article pilier (~1600 mots). Point d'ancrage pour les satellites
-- 16 à 20 de l'architecture éditoriale.
--
-- Vérifié contre le code : double authentification par application (TOTP),
-- mot de passe haché avec l'algorithme par défaut de PHP, longueur minimale
-- exigée à l'inscription, vérification d'e-mail requise au retrait.
--
-- ⚠️ Point traité honnêtement dans l'article : la 2FA actuelle ne génère PAS
-- de codes de secours. L'article le dit et recommande de sauvegarder la clé
-- au moment de l'activation, plutôt que de laisser croire à un filet de
-- sécurité inexistant.
--
-- INSERT IGNORE : idempotent.
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'securiser-son-compte-et-ses-gains',
 (SELECT id FROM blog_categories WHERE slug='guides'),
 'Sécuriser son compte et ses gains : le guide complet',
 'Mot de passe, double authentification, reconnaissance du phishing, protection du portefeuille : les gestes concrets pour éviter de perdre ce que vous avez patiemment accumulé.',
 '🔐',
 'Équipe Wintaskly',
 'Sécuriser son compte et ses gains : le guide complet',
 'Protéger son compte de micro-gains : mot de passe solide, double authentification, reconnaissance des tentatives de phishing et sécurité du portefeuille crypto.',
 'published', 8, UTC_TIMESTAMP(),
 '<p>Un compte de micro-gains devient une cible dès qu''il contient de la valeur. Pas parce qu''il représente une fortune — mais parce qu''il est facile à revendre, difficile à récupérer, et que son propriétaire s''en aperçoit souvent trop tard.</p>
<p>La bonne nouvelle : la sécurité d''un compte tient à quelques gestes simples, faits une fois pour toutes. Ce guide les détaille, du plus élémentaire au plus technique, sans jargon inutile.</p>

<h2>Le mot de passe : ce qui compte vraiment</h2>

<h3>La longueur prime sur la complexité</h3>
<p>On a longtemps recommandé des mots de passe courts et tordus, du type <code>P@ssw0rd!</code>. C''est une mauvaise idée : ces substitutions sont exactement ce que testent les outils d''attaque en premier.</p>
<p>Ce qui protège réellement, c''est la <strong>longueur</strong>. Une suite de plusieurs mots sans rapport entre eux résiste bien mieux qu''une courte chaîne pleine de symboles — et se retient sans effort.</p>
<p>La plateforme impose une longueur minimale à l''inscription. Considérez-la comme un plancher, pas comme un objectif.</p>

<h3>L''erreur qui annule tout le reste</h3>
<p>Réutiliser un mot de passe est le plus grand risque, et de loin. Chaque année, des bases de données de sites divers sont dérobées et diffusées. Les attaquants prennent ensuite ces couples adresse/mot de passe et les essaient automatiquement partout ailleurs.</p>
<p>Autrement dit : si votre mot de passe ici est le même que sur un forum piraté il y a trois ans, votre compte est déjà exposé — sans qu''aucune faille n''existe de notre côté.</p>
<p>Un gestionnaire de mots de passe résout ce problème définitivement : un seul mot de passe à retenir, un mot de passe unique et long par site. C''est le meilleur investissement de temps en matière de sécurité personnelle.</p>

<h3>Ce que devient votre mot de passe chez nous</h3>
<p>Il n''est jamais stocké tel quel. Il est transformé par une fonction de hachage, un procédé à sens unique : on peut vérifier qu''un mot de passe correspond, mais on ne peut pas retrouver l''original à partir de ce qui est enregistré.</p>
<p>C''est pour cette raison qu''aucun support sérieux — le nôtre compris — ne peut vous « rappeler » votre mot de passe. Il ne le connaît pas. Il ne peut que vous permettre d''en définir un nouveau.</p>

<h2>La double authentification : la protection la plus efficace</h2>

<h3>Comment ça marche</h3>
<p>La double authentification ajoute une seconde preuve à la connexion. Après le mot de passe, il faut saisir un code temporaire généré par une application dédiée sur votre téléphone.</p>
<p>Ce code change toutes les trente secondes et se calcule hors ligne, à partir d''une clé partagée au moment de l''activation. Il ne transite ni par SMS ni par e-mail — deux canaux nettement moins sûrs.</p>
<p>Concrètement : même si quelqu''un obtient votre mot de passe, il ne peut pas entrer sans votre téléphone.</p>

<h3>Comment l''activer</h3>
<ol>
<li>Installez une application d''authentification sur votre téléphone (plusieurs sont gratuites et fonctionnent avec tous les sites).</li>
<li>Rendez-vous dans les réglages de sécurité de votre compte.</li>
<li>Scannez le code affiché avec l''application.</li>
<li>Saisissez le code généré pour confirmer l''activation.</li>
</ol>

<h3>Le point à ne pas négliger</h3>
<p>Au moment de l''activation, la clé secrète vous est présentée. <strong>Conservez-la en lieu sûr, hors de votre téléphone</strong> — dans un gestionnaire de mots de passe, ou notée dans un endroit protégé.</p>
<p>Pourquoi ? Parce que si vous perdez ou réinitialisez votre téléphone sans cette clé, vous perdez l''accès à votre compte. Le rétablissement passe alors par le support, avec vérification d''identité : c''est long, et ce n''est pas garanti. Deux minutes de précaution au moment de l''activation évitent cette situation.</p>

<h2>Reconnaître une tentative de phishing</h2>
<p>Le phishing consiste à vous faire saisir vos identifiants sur une fausse page. C''est de loin la méthode la plus utilisée, parce qu''elle ne demande aucune compétence technique : il suffit d''imiter une page et d''envoyer un message crédible.</p>

<h3>Les signaux qui doivent alerter</h3>
<ul>
<li><strong>L''urgence.</strong> « Votre compte sera suspendu sous 24 heures », « action requise immédiatement ». La panique fait cliquer sans réfléchir : c''est tout l''objectif.</li>
<li><strong>L''adresse d''expédition.</strong> Regardez ce qui suit l''arobase, pas le nom affiché — qui est trivial à falsifier.</li>
<li><strong>L''adresse du lien.</strong> Survolez avant de cliquer. Les imitations utilisent des variantes proches : une lettre changée, un tiret ajouté, une extension différente.</li>
<li><strong>Une demande d''information que le site connaît déjà.</strong> Nous n''avons aucune raison de vous demander votre mot de passe : nous ne le connaissons pas.</li>
<li><strong>Une pièce jointe inattendue.</strong> Un service en ligne ne vous envoie pas de fichier à ouvrir pour « vérifier votre compte ».</li>
</ul>

<h3>La règle qui rend le phishing inefficace</h3>
<p><strong>Ne vous connectez jamais depuis un lien reçu par message.</strong> Ouvrez votre navigateur, saisissez l''adresse du site vous-même, ou utilisez votre favori.</p>
<p>Cette seule habitude neutralise l''essentiel des tentatives, quelle que soit leur sophistication. Si le message était légitime, l''information sera de toute façon visible une fois connecté.</p>
<p>Et pour être parfaitement clair : <strong>l''équipe Wintaskly ne vous demandera jamais votre mot de passe ni vos codes de double authentification</strong>. Toute demande en ce sens est une tentative d''arnaque, quelle que soit sa présentation.</p>

<h2>Protéger vos gains, pas seulement votre compte</h2>

<h3>L''adresse de retrait</h3>
<p>Une transaction en cryptomonnaie est irréversible. Une adresse erronée signifie une perte définitive, sans recours possible — ni de notre côté, ni de celui du réseau.</p>
<ul>
<li><strong>Copiez-collez toujours</strong>, ne saisissez jamais une adresse à la main.</li>
<li><strong>Vérifiez les premiers et derniers caractères</strong> après le collage. Certains logiciels malveillants remplacent le contenu du presse-papiers par l''adresse de l''attaquant : c''est une attaque courante, et discrète.</li>
<li><strong>Vérifiez le réseau.</strong> Une même cryptomonnaie peut circuler sur plusieurs réseaux ; se tromper entraîne généralement une perte.</li>
</ul>

<h3>Le portefeuille de destination</h3>
<p>Vos gains ne sont réellement à vous qu''une fois sur un portefeuille que vous contrôlez. Deux principes :</p>
<ul>
<li><strong>Ne partagez jamais votre phrase de récupération.</strong> Quiconque la détient possède le portefeuille. Aucun service légitime ne la demande, jamais.</li>
<li><strong>N''utilisez pas l''adresse de quelqu''un d''autre.</strong> Outre le risque évident, une adresse partagée entre plusieurs comptes est un signal de fraude classique qui peut entraîner un blocage.</li>
</ul>

<h2>Les réflexes de vérification</h2>
<p>La sécurité ne se limite pas à la prévention : encore faut-il repérer un problème rapidement.</p>
<ul>
<li><strong>Consultez votre historique régulièrement.</strong> Une activité que vous ne reconnaissez pas — une tâche que vous n''avez pas faite, un retrait que vous n''avez pas demandé — doit être signalée immédiatement.</li>
<li><strong>Surveillez votre boîte e-mail.</strong> Une tentative de changement de mot de passe que vous n''avez pas initiée est un signal d''alerte.</li>
<li><strong>Méfiez-vous des messages privés promettant des gains.</strong> Les propositions de « méthode secrète » ou de « bot automatique » se terminent invariablement par un vol de compte, quand ce n''est pas pire.</li>
</ul>

<h2>Si vous pensez être compromis</h2>
<ol>
<li><strong>Changez immédiatement votre mot de passe</strong>, depuis un appareil dont vous êtes sûr.</li>
<li><strong>Activez la double authentification</strong> si ce n''était pas déjà fait.</li>
<li><strong>Vérifiez votre adresse e-mail de contact</strong> : une modification est souvent la première action d''un attaquant.</li>
<li><strong>Contactez le support</strong> avec les détails : ce que vous avez constaté, quand, et ce que vous avez déjà fait.</li>
<li><strong>Changez ce mot de passe partout ailleurs</strong> s''il était réutilisé.</li>
</ol>

<h2>En résumé</h2>
<p>Trois gestes couvrent l''essentiel du risque : un mot de passe long et unique, la double authentification activée avec sa clé sauvegardée, et le réflexe de ne jamais se connecter depuis un lien reçu.</p>
<p>Ces protections prennent dix minutes à mettre en place et valent pour tous vos comptes, pas seulement celui-ci. C''est probablement le meilleur rapport effort/bénéfice de tout ce que vous ferez en ligne cette année.</p>
<p>Pour comprendre comment la plateforme détecte les comportements frauduleux et comment contester une décision, consultez notre <a href="/help/antifraud.php">politique anti-fraude</a>. Et si vous débutez, le <a href="/blog/guide-complet-micro-gains-en-ligne">guide complet des micro-gains</a> reprend l''ensemble du fonctionnement.</p>
<p class="wt-article__disclaimer"><em>Cet article donne des recommandations générales de sécurité. Il ne remplace pas les consignes propres à chaque service que vous utilisez.</em></p>'
);
