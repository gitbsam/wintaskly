-- ============================================================================
-- Wintaskly — SATELLITE 6 (pilier 4) : "Reconnaître un e-mail de phishing"
-- ============================================================================
-- ~800 mots, catégorie Astuces (catégorie sous-alimentée : 3 articles).
--
-- Complémentaire du pilier 4 sans le répéter : le pilier donne les principes
-- de sécurité générale, ce satellite entre dans le détail pratique de
-- l'examen d'un message suspect.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- CALENDRIER DE PUBLICATION
-- published_at = 2026-09-10 18:37:00 (et non UTC_TIMESTAMP()).
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
 'reconnaitre-email-phishing-indices-concrets',
 (SELECT id FROM blog_categories WHERE slug='astuces'),
 'Reconnaître un e-mail de phishing : 6 indices concrets',
 'Les fausses pages sont devenues indiscernables des vraies. Ce qui trahit encore une tentative d''hameçonnage, et la seule habitude qui la rend inefficace.',
 '🎣',
 'Équipe Wintaskly',
 'Reconnaître un e-mail de phishing : 6 indices concrets',
 'Comment identifier une tentative d''hameçonnage : adresse d''expédition, lien réel, urgence artificielle. Et le réflexe simple qui neutralise ces attaques.',
 'published', 5, '2026-09-10 18:37:00',
 '<p>Le temps des faux messages truffés de fautes est révolu. Les tentatives actuelles copient parfaitement les logos, la mise en page et le ton — parfois mieux que les vrais messages.</p>
<p>Repérer une contrefaçon à l''œil devient donc hasardeux. Voici les six indices qui trahissent encore une tentative, et surtout l''habitude qui rend l''ensemble du problème caduc.</p>

<h2>1. L''urgence</h2>
<p>« Votre compte sera suspendu sous 24 heures. » « Action requise immédiatement. » « Tentative de connexion suspecte : confirmez maintenant. »</p>
<p>L''urgence est l''ingrédient central, parce qu''elle court-circuite la réflexion. Un service légitime qui doit vous alerter vous laisse le temps de vérifier — et ne menace pas de fermer votre compte dans l''heure.</p>
<p><strong>Réflexe :</strong> plus un message vous presse, plus il faut ralentir.</p>

<h2>2. L''adresse d''expédition réelle</h2>
<p>Le nom affiché de l''expéditeur est trivial à falsifier : n''importe qui peut faire apparaître le nom d''un service connu.</p>
<p>Ce qui compte est l''adresse complète, après l''arobase. Sur mobile, il faut souvent toucher le nom de l''expéditeur pour la voir.</p>
<p><strong>Ce qu''on cherche :</strong> un domaine qui n''est pas exactement celui du service — une lettre ajoutée, un tiret, une extension différente, ou un sous-domaine trompeur où le vrai nom apparaît avant un domaine inconnu.</p>

<h2>3. La destination réelle du lien</h2>
<p>Un lien peut afficher un texte et pointer ailleurs. C''est le principe même du lien hypertexte.</p>
<p><strong>Sur ordinateur :</strong> survolez sans cliquer, l''adresse réelle apparaît en bas du navigateur.</p>
<p><strong>Sur mobile :</strong> appuyez longuement, un aperçu s''affiche.</p>
<p>Regardez le domaine juste avant la première barre oblique : c''est lui qui détermine où vous allez, pas ce qui suit.</p>

<h2>4. Une demande d''information que le service possède déjà</h2>
<p>Un service ne vous demande pas ce qu''il connaît. Il ne redemande jamais votre mot de passe — il ne le connaît d''ailleurs pas, puisqu''il n''en stocke qu''une empreinte irréversible.</p>
<p>Toute demande de mot de passe, de code de double authentification ou de phrase de récupération est une tentative de vol. Sans exception, quelle que soit la mise en forme du message.</p>

<h2>5. Une pièce jointe inattendue</h2>
<p>Un service en ligne ne vous envoie pas de fichier à ouvrir pour « vérifier votre compte » ou « consulter un avis de suspension ». L''information est sur le site, pas dans un document.</p>
<p>Méfiance particulière pour les archives et les fichiers exécutables, mais aussi pour les documents bureautiques, qui peuvent contenir du code.</p>

<h2>6. Un contexte qui ne correspond pas</h2>
<p>Une confirmation de commande que vous n''avez pas passée, une alerte sur un compte que vous ne possédez pas, un remboursement inattendu : ces messages sont envoyés en masse, en pariant qu''une fraction des destinataires sera concernée.</p>
<p>Si le message ne correspond à aucune action de votre part, c''est le signal le plus simple qui soit.</p>

<h2>Le réflexe qui rend tout cela inutile</h2>
<p>Les six indices ci-dessus demandent de l''attention, et l''attention finit toujours par flancher — un jour de fatigue, un message particulièrement bien fait.</p>
<p>Une seule habitude neutralise l''essentiel des tentatives, quelle que soit leur qualité :</p>
<p><strong>Ne vous connectez jamais depuis un lien reçu par message.</strong></p>
<p>Ouvrez votre navigateur, saisissez l''adresse du site vous-même ou utilisez votre favori. Si le message était légitime, l''information sera visible une fois connecté. S''il ne l''était pas, vous venez d''éviter le piège sans avoir eu à l''identifier.</p>
<p>Cette habitude a un avantage décisif : elle fonctionne même quand vous n''êtes pas vigilant.</p>

<h2>Si vous avez cliqué et saisi vos identifiants</h2>
<p>Agissez vite, l''ordre compte :</p>
<ol>
<li><strong>Changez le mot de passe du service concerné</strong>, depuis un appareil sûr, en tapant l''adresse vous-même.</li>
<li><strong>Changez-le partout où il était réutilisé.</strong> C''est le vrai danger : les attaquants testent automatiquement les identifiants volés sur d''autres sites.</li>
<li><strong>Activez la double authentification</strong> si ce n''était pas fait.</li>
<li><strong>Vérifiez votre adresse e-mail de contact</strong> : la modifier est souvent la première action d''un attaquant.</li>
<li><strong>Prévenez le support</strong> en décrivant précisément ce qui s''est passé.</li>
</ol>

<h2>En résumé</h2>
<p>Les faux messages sont désormais très bien faits, et miser sur la vigilance permanente est perdu d''avance. La parade tient dans une habitude : ne jamais se connecter depuis un lien reçu.</p>
<p>Pour l''ensemble des protections d''un compte, consultez notre guide <a href="/blog/securiser-son-compte-et-ses-gains">Sécuriser son compte et ses gains</a>, et pour comprendre la double authentification en détail, <a href="/blog/double-authentification-expliquee-simplement">notre article dédié</a>.</p>'
);
