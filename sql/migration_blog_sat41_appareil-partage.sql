-- ============================================================================
-- Wintaskly — SATELLITE 41 (pilier 4) : "Appareil ou connexion partagée"
-- ============================================================================
-- ~800 mots, catégorie Astuces.
--
-- Sujet directement lié au support : une connexion partagée (foyer,
-- résidence étudiante, cybercafé) fait monter le score de risque et peut
-- déclencher une mise sous revue. C'est une situation légitime et fréquente,
-- rarement expliquée — les utilisateurs concernés concluent à une injustice.
--
-- CALENDRIER : published_at = 2026-10-30.
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'appareil-ou-connexion-partagee-precautions',
 (SELECT id FROM blog_categories WHERE slug='astuces'),
 'Appareil ou connexion partagée : les précautions à prendre',
 'Foyer, résidence étudiante, ordinateur familial : des situations parfaitement légitimes qui déclenchent pourtant des contrôles. Comment les traverser sans blocage.',
 '🏠',
 'Équipe Wintaskly',
 'Appareil ou connexion partagée : précautions',
 'Protéger son compte sur un appareil ou une connexion partagés, et comprendre pourquoi cette situation peut déclencher un contrôle anti-fraude.',
 'published', 5, '2026-10-30 14:18:00',
 '<p>Partager une connexion ou un ordinateur est banal : famille, colocation, résidence étudiante, bibliothèque, lieu de travail. C''est aussi une des situations qui déclenchent le plus de contrôles automatiques — et donc d''incompréhension.</p>
<p>Voici pourquoi, et comment s''en accommoder.</p>

<h2>Pourquoi le partage attire l''attention</h2>
<p>Les systèmes anti-fraude cherchent les comptes multiples créés par une même personne : c''est le principal détournement de ce type de plateforme, et celui que les régies publicitaires refusent de payer.</p>
<p>Or, vu de l''extérieur, deux frères qui jouent depuis le même salon et un utilisateur ayant créé deux comptes produisent <strong>exactement le même signal technique</strong> : même adresse réseau, parfois même appareil.</p>
<p>Le système ne peut pas faire la différence tout seul. C''est pourquoi ce signal ne déclenche pas une sanction automatique, mais un examen — et c''est là que votre explication compte.</p>

<h2>Ce qui est autorisé, et ce qui ne l''est pas</h2>
<p>La règle est simple, et elle porte sur les personnes, pas sur les machines :</p>
<ul>
<li><strong>Autorisé :</strong> plusieurs personnes réelles, chacune avec son compte, depuis la même connexion ou le même appareil.</li>
<li><strong>Interdit :</strong> une même personne détenant plusieurs comptes, quel que soit le nombre d''appareils utilisés.</li>
</ul>
<p>Le second cas reste détectable même en changeant d''appareil ou en utilisant un VPN — d''autres signaux entrent en jeu. Tenter de contourner aggrave d''ailleurs la situation, parce que la dissimulation est elle-même un signal.</p>

<h2>Le cas particulier du parrainage</h2>
<p>C''est le point le plus sensible, et celui qui provoque le plus de suspensions.</p>
<p>Parrainer une personne réelle de votre foyer est légitime. Mais si le compte parrainé montre une activité qui ressemble à la vôtre — mêmes horaires, mêmes tâches, même rythme — la combinaison « même connexion + lien de parrainage + activité jumelle » devient difficile à distinguer d''un auto-parrainage.</p>
<p>Si vous êtes dans ce cas, mieux vaut <strong>le signaler au support avant</strong> qu''un contrôle ne se déclenche. Une explication donnée à l''avance est bien plus simple à traiter qu''une contestation après suspension.</p>

<h2>Protéger son compte sur un appareil partagé</h2>
<p>Au-delà des contrôles, le partage pose une question de sécurité élémentaire.</p>
<ul>
<li><strong>Déconnectez-vous à chaque fin de session.</strong> Fermer l''onglet ne suffit pas : la session reste ouverte.</li>
<li><strong>N''enregistrez pas le mot de passe</strong> dans un navigateur partagé. Toute personne y ayant accès peut le consulter en clair en quelques clics.</li>
<li><strong>Activez la double authentification</strong> avec une méthode qui vous est propre — une application sur <em>votre</em> téléphone, pas une boîte e-mail ouverte sur l''ordinateur commun.</li>
<li><strong>Utilisez une fenêtre privée</strong> si vous ne pouvez pas contrôler l''appareil. Rien n''y est conservé après fermeture.</li>
<li><strong>Vérifiez votre adresse e-mail de contact</strong> de temps en temps : la modifier est la première action de quiconque prend le contrôle d''un compte.</li>
</ul>

<h2>Sur un ordinateur public</h2>
<p>Bibliothèque, cybercafé, espace de coworking : la prudence doit être maximale, parce que vous ne savez pas ce qui est installé sur la machine.</p>
<p>Un enregistreur de frappe capte tout ce que vous tapez, mot de passe compris, sans laisser de trace visible. Dans ce contexte :</p>
<ul>
<li>évitez de vous connecter si vous pouvez attendre ;</li>
<li>si vous devez le faire, changez votre mot de passe ensuite depuis un appareil sûr ;</li>
<li>ne consultez jamais un portefeuille de cryptomonnaie, et n''y saisissez jamais de phrase de récupération.</li>
</ul>

<h2>Si votre compte est mis sous revue</h2>
<p>Contactez le support en expliquant votre situation concrètement : combien de personnes utilisent la connexion, quel est votre lien avec elles, depuis quand. Une connexion partagée est une explication <strong>parfaitement recevable et courante</strong>.</p>
<p>Chaque contestation est examinée par une personne. Ce qui aide : des faits précis. Ce qui n''aide pas : un message indigné sans détails.</p>
<p>Notre <a href="/help/antifraud.php">politique anti-fraude</a> décrit la procédure et ce qui est vérifié.</p>

<h2>En résumé</h2>
<p>Plusieurs personnes réelles depuis une même connexion, c''est autorisé. Une personne avec plusieurs comptes, non — et c''est détectable.</p>
<p>Sur un appareil partagé, déconnectez-vous, n''enregistrez pas le mot de passe, et utilisez une double authentification qui vous appartient. Et si un contrôle se déclenche, expliquez : la situation est courante et se règle.</p>
<p>Pour l''ensemble des protections, consultez <a href="/blog/securiser-son-compte-et-ses-gains">Sécuriser son compte et ses gains</a>, et pour la marche à suivre en cas de problème, <a href="/blog/que-faire-si-votre-compte-est-compromis">Compte compromis : que faire</a>.</p>'
);
