-- ============================================================================
-- Wintaskly — Migration : article de blog "Bien utiliser son tableau de bord"
-- ============================================================================
-- Septième article du rythme éditorial (2/semaine). Catégorie "astuces".
-- Angle NAVIGATION / lecture des indicateurs — volontairement distinct de
-- l'article "7 astuces pour maximiser tes gains" déjà en ligne, qui traite
-- de STRATÉGIE de gains (régularité, parrainage, succès, promotions).
-- Aucun recoupement de section entre les deux.
-- Contenu vérifié contre les libellés réels de dashboard/index.php :
-- Solde, Niveau, filleul(s), Mes gains (7 derniers jours), Historique
-- récent, Accès rapide, Succès / Prochains objectifs, Bonus quotidien.
-- INSERT IGNORE : idempotent, ne recrée pas l'article si le slug existe déjà.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'bien-utiliser-tableau-de-bord-wintaskly',
 (SELECT id FROM blog_categories WHERE slug='astuces'),
 'Bien utiliser son tableau de bord : les fonctions que tout le monde rate',
 'La plupart des utilisateurs ne regardent que leur solde. Pourtant, le tableau de bord contient plusieurs indicateurs qui changent vraiment la façon de jouer.',
 '📊',
 'Équipe Wintaskly',
 'Tableau de bord Wintaskly : lire ses indicateurs et gagner du temps',
 'Solde, niveau, graphique des gains, historique, succès : comment lire chaque partie de ton tableau de bord Wintaskly pour piloter ta progression efficacement.',
 'published', 5, UTC_TIMESTAMP(),
 '<p>Le tableau de bord est la première page que tu vois en te connectant, et probablement celle que tu regardes le moins attentivement. Le réflexe classique : jeter un œil au solde, puis filer directement vers les tâches.</p>
<p>C''est dommage, parce que cette page n''est pas un simple affichage décoratif. C''est un outil de pilotage. Voici comment lire chacune de ses parties, et ce que la plupart des utilisateurs ne remarquent jamais.</p>

<h2>Le solde : ce qu''il dit et ce qu''il ne dit pas</h2>
<p>Le <strong>solde</strong> affiche tes coins disponibles. Rien de compliqué. Mais un détail passe souvent inaperçu : ce chiffre est un état à l''instant T, pas une trajectoire. Il ne te dit pas si tu progresses plus vite ou moins vite que la semaine dernière.</p>
<p>Pour cette information, il faut regarder ailleurs sur la page — et c''est justement l''intérêt des autres indicateurs.</p>

<h2>Le niveau : l''indicateur de progression long terme</h2>
<p>Le <strong>niveau</strong> reflète ton activité cumulée sur la plateforme, via l''XP accumulée en accomplissant des tâches. Contrairement au solde, qui baisse quand tu retires, le niveau ne recule jamais : il mesure ton parcours, pas ton portefeuille.</p>
<p>Un indicateur de progression t''indique ce qu''il te reste avant le niveau suivant. C''est une information utile quand tu hésites à faire une dernière tâche avant de fermer : savoir que tu es proche d''un palier change souvent la décision.</p>

<h2>Le graphique des gains : le vrai outil de pilotage</h2>
<p>C''est probablement l''élément le plus sous-utilisé de la page. Le graphique <strong>« Mes gains »</strong> affiche tes gains sur les <strong>sept derniers jours</strong>.</p>
<p>Pourquoi c''est précieux ? Parce qu''il rend visible ce qu''aucun autre chiffre ne montre : ta <strong>régularité</strong>. Un coup d''œil suffit pour repérer les jours creux, ceux où tu as décroché, et ceux où ta routine a bien fonctionné.</p>
<p>Si tu cherches à progresser, c''est le graphique qu''il faut regarder en premier — pas le solde. Un profil avec sept petites barres régulières performe presque toujours mieux sur la durée qu''un profil avec une grosse barre et six jours vides.</p>

<h2>L''historique récent : pour vérifier, pas seulement pour contempler</h2>
<p>L''<strong>historique récent</strong> liste tes dernières transactions. Beaucoup le survolent, alors qu''il a une utilité concrète : <strong>vérifier</strong>.</p>
<p>C''est là que tu confirmes qu''une tâche a bien été créditée. Si tu as un doute sur une validation — une offre partenaire, une annonce PTC — c''est le premier endroit à consulter avant de contacter le support. Souvent, la réponse y est déjà, et cela évite une attente inutile.</p>

<h2>Le bonus quotidien et la série : ne casse pas la chaîne</h2>
<p>Le <strong>bonus quotidien</strong> est affiché directement sur le tableau de bord, avec ta <strong>série de jours consécutifs</strong>. C''est volontaire : c''est l''une des rares mécaniques où l''oubli d''une seule journée a un coût réel.</p>
<p>Le réflexe à prendre est simple : réclamer ce bonus <strong>avant</strong> de commencer quoi que ce soit d''autre. Beaucoup d''utilisateurs se lancent dans les tâches, se laissent absorber, ferment l''onglet — et cassent une série construite sur plusieurs jours.</p>

<h2>Les succès : des objectifs déjà à portée</h2>
<p>La section <strong>Succès</strong> affiche notamment tes <strong>prochains objectifs</strong>. C''est une information que peu de gens exploitent, alors qu''elle est concrètement actionnable.</p>
<p>L''intérêt : tu es souvent bien plus proche d''un succès que tu ne le crois. Voir qu''il te manque quelques actions pour en débloquer un transforme une session sans but en une session avec un objectif précis. C''est un excellent moyen de rendre la routine moins mécanique.</p>

<h2>L''accès rapide : gagner quelques secondes, tous les jours</h2>
<p>La zone d''<strong>accès rapide</strong> propose des raccourcis directs vers les tâches, le classement, le parrainage et les retraits. Rien de spectaculaire, mais sur une utilisation quotidienne, éviter deux ou trois clics à chaque connexion représente un confort réel.</p>

<h2>Une lecture en trente secondes</h2>
<p>Voici une routine de lecture efficace en arrivant sur ton tableau de bord :</p>
<ul>
<li><strong>Réclamer le bonus quotidien</strong> immédiatement, pour ne pas casser la série.</li>
<li><strong>Regarder le graphique des 7 jours</strong> pour repérer un éventuel décrochage.</li>
<li><strong>Jeter un œil aux prochains objectifs</strong> pour choisir sur quoi concentrer la session.</li>
<li><strong>Vérifier l''historique</strong> si une tâche de la veille te semblait douteuse.</li>
</ul>
<p>Trente secondes, et tu commences ta session avec une vision claire plutôt qu''au hasard.</p>

<h2>En résumé</h2>
<p>Le tableau de bord n''est pas qu''un compteur de solde. Le niveau mesure ta progression de fond, le graphique révèle ta régularité, l''historique sert à vérifier, les succès donnent un cap et le bonus quotidien protège ta série. Prends l''habitude de le lire vraiment : c''est le moyen le plus simple de piloter ta progression au lieu de la subir.</p>'
);
