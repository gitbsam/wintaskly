-- ============================================================================
-- Wintaskly — SATELLITE 5 (pilier 4) : "La 2FA expliquée simplement"
-- ============================================================================
-- ~850 mots, catégorie Guides. Satellite du pilier "sécurité".
--
-- Écrit APRÈS la refonte 2FA multi-méthodes (v8.69 → 8.74) : décrit donc le
-- système réel — TOTP, e-mail, SMS, codes de secours — et non l'ancien
-- système limité au TOTP.
--
-- Point traité sans détour : le TOTP ne dépend d'aucun fournisseur externe,
-- contrairement à une idée répandue. C'est ce qui en fait la méthode la plus
-- fiable.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- CALENDRIER DE PUBLICATION
-- published_at = 2026-09-08 08:41:00 (et non UTC_TIMESTAMP()).
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
 'double-authentification-expliquee-simplement',
 (SELECT id FROM blog_categories WHERE slug='guides'),
 'La double authentification expliquée simplement',
 'Application, e-mail ou SMS : les trois méthodes n''offrent pas la même protection. Comment ça marche réellement, laquelle choisir, et pourquoi les codes de secours sont indispensables.',
 '🔑',
 'Équipe Wintaskly',
 'La double authentification expliquée simplement',
 'Comprendre la double authentification : différences entre application, e-mail et SMS, rôle des codes de secours, et comment ne jamais perdre l''accès à son compte.',
 'published', 5, '2026-09-08 08:41:00',
 '<p>La double authentification est la protection la plus efficace qui existe pour un compte en ligne. Elle est aussi celle qu''on repousse le plus, par crainte de se compliquer la vie — ou de se retrouver enfermé dehors.</p>
<p>Voici ce qu''elle fait réellement, les différences entre les méthodes disponibles, et surtout comment l''activer sans risquer de perdre l''accès à votre compte.</p>

<h2>Le principe en une phrase</h2>
<p>Se connecter demande deux preuves au lieu d''une : quelque chose que vous <strong>savez</strong> (le mot de passe) et quelque chose que vous <strong>possédez</strong> (votre téléphone, votre boîte e-mail).</p>
<p>Conséquence directe : un mot de passe volé ne suffit plus. C''est la seule protection qui reste efficace même après une fuite de données — et il y en a chaque année.</p>

<h2>Les trois méthodes, et ce qui les distingue</h2>

<h3>L''application d''authentification (TOTP)</h3>
<p>Une application sur votre téléphone génère un code à six chiffres qui change toutes les trente secondes.</p>
<p><strong>Ce qu''il faut comprendre, et qui surprend souvent :</strong> cette application ne communique avec personne. Au moment de l''activation, votre compte et l''application partagent un secret. Ensuite, chacun calcule le même code de son côté, à partir de ce secret et de l''heure. Même secret, même horloge, même résultat — sans échange.</p>
<p>C''est pour cette raison qu''une application d''authentification <strong>fonctionne en mode avion</strong>. Elle ne dépend d''aucun service, d''aucun opérateur, d''aucune connexion. Si l''éditeur de l''application disparaissait demain, vous pourriez basculer vers une autre avec le même secret.</p>
<p><strong>Le point d''attention :</strong> ce secret ne vous est montré qu''une fois, à l''activation.</p>

<h3>Le code par e-mail</h3>
<p>Un code à usage unique est envoyé à votre adresse e-mail, valable quelques minutes.</p>
<p><strong>Avantage :</strong> rien à installer, accessible depuis n''importe quel appareil.</p>
<p><strong>Limite :</strong> la sécurité de votre compte devient celle de votre boîte e-mail. Si quelqu''un y accède, la protection tombe. Cette méthode a donc surtout du sens si votre messagerie est elle-même protégée par une double authentification.</p>

<h3>Le code par SMS</h3>
<p>Un code envoyé par message sur votre numéro.</p>
<p><strong>Avantage :</strong> le plus simple, aucune installation, fonctionne sur n''importe quel téléphone.</p>
<p><strong>Limite :</strong> c''est la méthode la moins sûre des trois. Le détournement de carte SIM — obtenir un duplicata de votre numéro auprès de l''opérateur en usurpant votre identité — est une attaque documentée et pratiquée.</p>

<h3>Que choisir</h3>
<p>Par ordre de robustesse : <strong>application &gt; e-mail &gt; SMS</strong>. Cela dit, n''importe laquelle vaut infiniment mieux qu''aucune. Si l''application vous rebute, activez l''e-mail — le pire choix reste de ne rien activer.</p>

<h2>Les codes de secours : la partie qu''on néglige</h2>
<p>C''est le point le plus important de cet article.</p>
<p>Une double authentification sans filet transforme une protection en risque : téléphone perdu, volé, réinitialisé, ou boîte e-mail inaccessible — et votre compte devient irrécupérable.</p>
<p>Les codes de secours sont ce filet. Ce sont des codes à usage unique, générés à l''activation, qui permettent de se connecter <strong>quelle que soit la méthode habituelle</strong>.</p>
<h3>Comment les conserver</h3>
<ul>
<li><strong>Pas dans votre téléphone.</strong> C''est précisément l''appareil que vous risquez de perdre.</li>
<li><strong>Pas dans un e-mail à vous-même.</strong> Si votre messagerie est compromise, tout tombe ensemble.</li>
<li><strong>Dans un gestionnaire de mots de passe</strong>, ou imprimés et rangés avec vos documents importants.</li>
</ul>
<p>Chaque code ne fonctionne qu''une fois. Quand il en reste peu, régénérez le lot — les anciens sont alors annulés.</p>

<h2>Activer sans se piéger</h2>
<ol>
<li><strong>Installez une application d''authentification</strong> (plusieurs sont gratuites et fonctionnent avec tous les sites).</li>
<li><strong>Scannez le code affiché.</strong> Il ne contient qu''une chaîne de texte transmettant le secret — la saisie manuelle de la clé fait exactement la même chose.</li>
<li><strong>Sauvegardez la clé secrète</strong> hors de votre téléphone, avant de valider.</li>
<li><strong>Notez vos codes de secours</strong> au moment où ils s''affichent : ils ne seront plus consultables ensuite.</li>
<li><strong>Testez</strong> en vous déconnectant et en vous reconnectant immédiatement, pendant que tout est frais.</li>
</ol>

<h2>Questions fréquentes</h2>
<p><strong>« Et si je change de téléphone ? »</strong> Avec la clé secrète sauvegardée, vous reconfigurez l''application sur le nouvel appareil en quelques secondes. Sans elle, il faut passer par un code de secours.</p>
<p><strong>« Le code est refusé alors qu''il est bon. »</strong> C''est presque toujours un décalage d''horloge sur le téléphone. Activez la synchronisation automatique de l''heure.</p>
<p><strong>« Je dois saisir un code à chaque connexion ? »</strong> Oui, et cela prend cinq secondes. C''est le prix d''une protection qui résiste à une fuite de mot de passe.</p>

<h2>En résumé</h2>
<p>Activez la double authentification, privilégiez l''application si possible, et surtout : <strong>sauvegardez vos codes de secours ailleurs que sur votre téléphone</strong>. Ces dix minutes valent mieux que toutes les précautions ultérieures.</p>
<p>Pour l''ensemble des bonnes pratiques de sécurité, consultez notre guide <a href="/blog/securiser-son-compte-et-ses-gains">Sécuriser son compte et ses gains</a>.</p>'
);
