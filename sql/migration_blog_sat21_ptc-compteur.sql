-- ============================================================================
-- Wintaskly — SATELLITE 21 (pilier 2) : "Le compteur PTC"
-- ============================================================================
-- ~800 mots, catégorie Guides. Satellite du pilier "types de tâches".
--
-- Vérifié contre le schéma : ptc_ads possède duration_seconds et
-- cooldown_hours, tous deux paramétrables. Aucune durée n'est écrite en dur.
--
-- CALENDRIER DE PUBLICATION
-- published_at = 2026-10-02 (jour ouvré suivant le dernier article programmé).
-- Les articles sont volontairement échelonnés : une publication groupée
-- signalerait une production en masse.
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'ptc-ce-qui-se-passe-pendant-le-compteur',
 (SELECT id FROM blog_categories WHERE slug='guides'),
 'PTC : ce qui se passe pendant que le compteur tourne',
 'La tâche la plus passive du site, et la plus mal comprise. Pourquoi changer d''onglet peut tout annuler, et pourquoi le nombre d''annonces varie autant.',
 '📺',
 'Équipe Wintaskly',
 'PTC : ce qui se passe pendant le compteur',
 'Comprendre le fonctionnement des annonces rémunérées : validation de la présence, variation du nombre d''annonces disponibles et erreurs qui annulent le gain.',
 'published', 5, '2026-10-02 10:12:00',
 '<p>Les annonces rémunérées sont la tâche la plus simple en apparence : on ouvre, on attend, on est crédité. C''est aussi celle qui produit le plus de « pourquoi ça n''a pas marché ? » — parce que l''essentiel se joue hors de la vue.</p>

<h2>Ce que vérifie le compteur</h2>
<p>Pendant que le temps s''écoule, la page ne se contente pas de compter. Elle vérifie que la fenêtre reste <strong>réellement active</strong>.</p>
<p>La raison est économique : l''annonceur paie pour de l''attention, pas pour un onglet ouvert en arrière-plan et oublié. Un système qui créditerait sans cette vérification livrerait un trafic sans valeur, et l''annonceur cesserait de payer — donc il n''y aurait plus rien à distribuer.</p>
<p>Concrètement, plusieurs comportements peuvent interrompre la validation :</p>
<ul>
<li><strong>Passer sur un autre onglet</strong> ou une autre application pendant le décompte.</li>
<li><strong>Réduire la fenêtre</strong> ou verrouiller l''écran du téléphone.</li>
<li><strong>Fermer la page</strong> avant la fin, même de quelques secondes.</li>
</ul>
<p>Ce n''est pas une sanction : de son point de vue, le système constate que personne ne regardait.</p>

<h2>Pourquoi le nombre d''annonces varie</h2>
<p>Certains jours, plusieurs annonces sont disponibles ; d''autres, aucune. Cette variation ne dépend pas de la plateforme.</p>
<p>Un annonceur achète un <strong>volume défini</strong> de vues. Quand ce volume est épuisé, l''annonce disparaît jusqu''à la campagne suivante. Les périodes creuses correspondent simplement à des périodes sans campagne active.</p>
<p>À cela s''ajoute un délai propre à chaque annonce avant de pouvoir être revue. Là encore, c''est l''annonceur qui fixe la règle : il paie pour toucher des personnes différentes, pas la même dix fois dans la journée.</p>
<p>Conséquence pratique : quand des annonces sont disponibles, autant les traiter — elles peuvent avoir disparu à votre prochaine visite.</p>

<h2>Les erreurs qui annulent le gain</h2>
<ul>
<li><strong>Ouvrir plusieurs annonces en parallèle.</strong> Les sessions se télescopent et aucune n''aboutit correctement.</li>
<li><strong>Un bloqueur de publicité actif.</strong> Sans affichage de l''annonce, il n''y a pas de revenu à partager — et souvent pas de validation du tout.</li>
<li><strong>Un VPN.</strong> La géolocalisation masquée est refusée par la plupart des annonceurs, qui ciblent des marchés précis.</li>
<li><strong>Rafraîchir la page</strong> pendant le décompte : le compteur repart de zéro.</li>
</ul>

<h2>Comment en tirer le meilleur</h2>
<h3>L''associer à autre chose</h3>
<p>C''est la tâche la plus compatible avec une activité parallèle <em>hors écran</em> : préparer un repas, plier du linge, passer un appel. La seule contrainte est de laisser la fenêtre au premier plan.</p>
<p>Attention à la nuance : « passif » ne signifie pas « en arrière-plan ». Vous pouvez faire autre chose, mais pas sur le même appareil dans une autre fenêtre.</p>
<h3>Vérifier en début de session</h3>
<p>Prenez l''habitude de consulter la liste dès votre arrivée. Si des annonces sont là, traitez-les d''abord : le faucet et les liens courts, eux, restent disponibles.</p>
<h3>Ne pas s''acharner les jours creux</h3>
<p>Une liste vide n''est pas un dysfonctionnement. Mieux vaut basculer sur une autre tâche que rafraîchir en boucle.</p>

<h2>Une question fréquente : peut-on couper le son ?</h2>
<p>Oui, sauf mention contraire dans l''annonce. La validation porte sur la présence active de la fenêtre, pas sur le son. Certaines annonces vidéo peuvent toutefois exiger explicitement que le son soit actif — c''est alors indiqué avant le lancement.</p>

<h2>Rentabilité comparée</h2>
<p>Le PTC se situe entre le faucet et les offres partenaires : plus rémunérateur qu''une réclamation, moins qu''un sondage abouti, mais avec une contrainte d''attention bien plus faible que ce dernier.</p>
<p>Son vrai avantage n''est pas le montant : c''est le <strong>rapport effort mental / récompense</strong>. Aucune décision à prendre, aucun formulaire, aucun risque d''être écarté en cours de route comme sur un sondage.</p>
<p>C''est donc la tâche idéale des moments où l''on n''a pas la disponibilité mentale pour autre chose.</p>

<h2>En résumé</h2>
<p>Le compteur vérifie une présence réelle, pas un simple écoulement du temps : gardez la fenêtre au premier plan jusqu''au bout. Le nombre d''annonces dépend des campagnes en cours, pas de la plateforme. Et quand elles sont là, mieux vaut ne pas attendre.</p>
<p>Pour le fonctionnement détaillé de chaque tâche, consultez notre guide <a href="/blog/comprendre-optimiser-chaque-type-de-tache">Comprendre et optimiser chaque type de tâche</a>, et si vos gains ne se valident pas, <a href="/blog/bloqueur-publicite-pourquoi-gains-bloques">le problème vient souvent d''un bloqueur</a>.</p>
<p class="wt-article__disclaimer"><em>Les durées, délais et récompenses évoqués sont paramétrables et peuvent évoluer : reportez-vous aux valeurs affichées sur la page de la tâche.</em></p>'
);
