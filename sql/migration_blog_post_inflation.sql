-- ============================================================================
-- Wintaskly — Migration : article de blog "C'est quoi l'inflation ?"
-- ============================================================================
-- Deuxième article du nouveau rythme éditorial (2/semaine). Catégorie
-- "finance" (créée par migration_blog_finance_category.sql — appliquer
-- celle-ci AVANT). Éducation financière générale, volontairement ouverte
-- à un lectorat plus large que les seuls utilisateurs inscrits.
-- INSERT IGNORE : idempotent, ne recrée pas l'article si le slug existe déjà.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'inflation-expliquee-pourquoi-epargne-perd-valeur',
 (SELECT id FROM blog_categories WHERE slug='finance'),
 'C''est quoi l''inflation, et pourquoi votre épargne en pâtit',
 'Votre argent dort sur un compte et vous avez l''impression qu''il achète moins qu''avant ? Ce n''est pas une impression. Explication simple de l''inflation et de ses effets concrets.',
 '📉',
 'Équipe Wintaskly',
 'L''inflation expliquée simplement : effets sur votre épargne (2026)',
 'Comprendre l''inflation sans jargon : ce que c''est, pourquoi les prix montent, et comment elle grignote la valeur de votre épargne année après année.',
 'published', 6, UTC_TIMESTAMP(),
 '<p>Vous avez sûrement remarqué que votre panier de courses coûte plus cher qu''il y a quelques années, à contenu identique. Ou que le café du coin a discrètement augmenté. Ce phénomène porte un nom que tout le monde connaît sans forcément le comprendre en détail : <strong>l''inflation</strong>.</p>
<p>Ce n''est pas juste un mot d''économiste à la télévision. L''inflation a un effet direct et mesurable sur l''argent que vous mettez de côté. Voici comment elle fonctionne, expliquée sans jargon.</p>

<h2>L''inflation, c''est quoi exactement ?</h2>
<p>L''inflation, c''est la hausse générale et durable des prix. Le mot important ici est <strong>générale</strong> : si le prix des tomates monte à cause d''une mauvaise récolte, ce n''est pas de l''inflation, c''est une variation ponctuelle sur un produit. L''inflation, c''est quand l''ensemble des prix d''une économie augmente sur la durée.</p>
<p>On la mesure en pourcentage annuel. Une inflation de 3 % signifie qu''en moyenne, ce qui coûtait 100 € l''an dernier coûte environ 103 € cette année. Rien d''alarmant sur un an, mais l''effet s''accumule.</p>

<h2>L''autre face du miroir : votre argent perd du pouvoir d''achat</h2>
<p>Voici le point que beaucoup de gens saisissent de travers. L''inflation ne fait pas seulement monter les prix : elle fait <strong>baisser la valeur réelle de l''argent que vous possédez</strong>. Ce sont les deux faces de la même pièce.</p>
<p>Prenons un exemple concret. Vous mettez 1 000 € de côté sur un compte qui ne rapporte rien. Un an plus tard, vous avez toujours 1 000 € sur votre relevé — le chiffre n''a pas bougé. Mais si les prix ont augmenté de 3 % pendant ce temps, ces 1 000 € achètent désormais l''équivalent de ce que 970 € achetaient un an plus tôt. Vous n''as rien perdu sur le papier, et pourtant vous avez bel et bien perdu.</p>
<p>C''est ce qu''on appelle la différence entre la valeur <em>nominale</em> (le chiffre affiché) et la valeur <em>réelle</em> (ce que ce chiffre permet réellement d''acheter).</p>

<h2>L''effet cumulé : le vrai piège</h2>
<p>Sur un an, 3 % passent presque inaperçus. Le problème, c''est que l''inflation se cumule année après année, un peu comme des intérêts — mais à l''envers, contre vous.</p>
<p>À 3 % par an, une somme laissée dormante perd environ un quart de son pouvoir d''achat en une dizaine d''années. Ce n''est plus un détail : c''est un vrai transfert de valeur, silencieux, qui ne vous envoie aucune notification.</p>
<p>C''est pour cette raison que les conseillers financiers répètent qu''un compte courant n''est pas un outil d''épargne. Il est fait pour faire transiter de l''argent, pas pour le conserver longtemps.</p>

<h2>Pourquoi les prix montent-ils ?</h2>
<p>Plusieurs mécanismes peuvent alimenter l''inflation, souvent en même temps :</p>
<ul>
<li><strong>La demande dépasse l''offre.</strong> Quand tout le monde veut acheter la même chose et qu''il n''y en a pas assez, les prix montent naturellement.</li>
<li><strong>Les coûts de production augmentent.</strong> Si l''énergie, les matières premières ou les salaires coûtent plus cher, les entreprises répercutent une partie de cette hausse sur leurs prix.</li>
<li><strong>La quantité de monnaie en circulation augmente.</strong> Schématiquement, plus il y a de monnaie disponible pour un volume de biens comparable, moins chaque unité de monnaie vaut cher.</li>
</ul>
<p>Les banques centrales tentent de piloter tout cela, notamment via les taux d''intérêt : les monter freine l''activité économique et donc la hausse des prix, les baisser fait l''inverse. C''est un exercice d''équilibriste permanent.</p>

<h2>Une inflation nulle, ce serait mieux ?</h2>
<p>Pas nécessairement, et c''est contre-intuitif. La plupart des banques centrales visent une inflation faible mais positive — souvent autour de 2 %. Pourquoi ne pas viser zéro ?</p>
<p>Parce que le scénario inverse, la <strong>déflation</strong> (baisse générale des prix), est considéré comme plus dangereux. Si les consommateurs anticipent que tout coûtera moins cher dans six mois, ils reportent leurs achats. La consommation ralentit, les entreprises vendent moins, réduisent la voilure, et l''économie s''enfonce dans une spirale difficile à enrayer.</p>
<p>Une inflation modérée est donc vue comme un lubrifiant de l''économie. C''est son emballement — ou son effondrement — qui pose problème.</p>

<h2>Ce que ça change pour vous, concrètement</h2>
<p>Comprendre l''inflation change surtout la façon dont vous regardez votre épargne :</p>
<ul>
<li><strong>Un rendement doit se comparer à l''inflation.</strong> Un placement qui rapporte 2 % pendant que l''inflation est à 3 % vous fait perdre du pouvoir d''achat, même s''il "rapporte" quelque chose.</li>
<li><strong>Laisser de grosses sommes dormir a un coût invisible.</strong> Ce coût ne s''affiche nulle part sur votre relevé bancaire, mais il est bien réel.</li>
<li><strong>Garder une réserve de sécurité liquide reste indispensable.</strong> L''inflation n''annule pas ce principe : quelques mois de dépenses accessibles immédiatement valent mieux que d''être contraint d''emprunter au premier imprévu.</li>
</ul>

<h2>En résumé</h2>
<p>L''inflation, c''est la hausse générale des prix — et donc, mécaniquement, la baisse de la valeur réelle de l''argent que vous détiens. Son effet est discret sur un an, mais significatif sur dix. Ce n''est pas une raison de paniquer : c''est une raison de savoir où dort votre argent, et à quoi il sert.</p>
<p><em>Cet article est une explication générale à visée pédagogique. Il ne constitue pas un conseil en investissement. Pour des décisions engageant votre patrimoine, l''avis d''un professionnel qualifié reste la meilleure option.</em></p>'
);
