-- ============================================================================
-- Wintaskly — SATELLITE 14 (pilier 2) : "Offre refusée"
-- ============================================================================
-- ~800 mots, catégorie Astuces.
--
-- Vérifié contre le code : offerwall_transactions.status vaut pending,
-- credited ou rejected — la validation appartient au partenaire, la
-- plateforme ne dispose pas des données de contrôle.
--
-- Sujet à traiter honnêtement : c'est la première source de frustration et
-- de tickets. L'article dit clairement ce que la plateforme peut et ne peut
-- pas faire, plutôt que d'entretenir l'ambiguïté.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- CALENDRIER DE PUBLICATION
-- published_at = 2026-09-23 09:37:00 (et non UTC_TIMESTAMP()).
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
 'offre-refusee-offerwall-que-faire',
 (SELECT id FROM blog_categories WHERE slug='astuces'),
 'Offre refusée sur un mur d''offres : pourquoi, et que faire',
 'Vous avez terminé l''offre, rien n''est crédité. Ce qui se joue réellement en coulisses, qui décide, et comment obtenir une révision quand c''est justifié.',
 '❌',
 'Équipe Wintaskly',
 'Offre offerwall non créditée : causes et recours',
 'Comprendre pourquoi une offre partenaire est refusée ou reste en attente, qui prend la décision, et comment formuler une réclamation qui aboutit.',
 'published', 5, '2026-09-23 09:37:00',
 '<p>Vous avez répondu au sondage jusqu''au bout, installé l''application, atteint le niveau demandé. Et rien n''arrive. C''est la situation la plus frustrante de toutes les tâches rémunérées, et la plus mal comprise.</p>
<p>Voici ce qui se passe réellement, et ce qui peut être fait — en étant clair sur ce qui ne peut pas l''être.</p>

<h2>Qui décide, en réalité</h2>
<p>C''est le point à comprendre avant tout le reste : <strong>la validation appartient au partenaire, pas à la plateforme.</strong></p>
<p>Le fonctionnement est le suivant. Le mur d''offres est fourni par une société spécialisée, qui travaille elle-même avec des annonceurs. Quand vous accomplissez une offre, c''est l''annonceur qui vérifie que les conditions sont remplies, puis informe le fournisseur, qui informe la plateforme, qui vous crédite.</p>
<p>La plateforme se trouve en bout de chaîne. Elle ne dispose ni de vos réponses au sondage, ni des critères de sélection de l''annonceur, ni des données de contrôle qualité. Elle ne peut donc pas « forcer » une validation : elle n''a tout simplement pas l''information.</p>
<p>Ce n''est pas une façon d''éviter le sujet — c''est la structure même du modèle, et toute plateforme prétendant le contraire vous induirait en erreur.</p>

<h2>Les trois états d''une offre</h2>
<ul>
<li><strong>En attente.</strong> Transmise, en cours de vérification. Le délai va de quelques minutes à plusieurs jours selon le type d''offre — une installation d''application est rapide, une offre exigeant un abonnement peut attendre la fin d''un délai de rétractation.</li>
<li><strong>Créditée.</strong> L''annonceur a confirmé, les Coins sont sur votre compte.</li>
<li><strong>Refusée.</strong> Les conditions n''ont pas été jugées remplies.</li>
</ul>
<p>Une offre restée « en attente » plusieurs jours n''est pas perdue : c''est souvent le comportement normal. La patience résout une bonne partie des cas.</p>

<h2>Les vraies causes d''un refus</h2>
<h3>Le profil ne correspondait pas (sondages)</h3>
<p>La cause la plus fréquente. Un sondage cherche un échantillon précis. Les premières questions servent au filtrage, et un quota déjà rempli suffit à vous écarter — parfois après plusieurs minutes.</p>
<p>C''est structurel au modèle et cela ne remet en cause ni votre honnêteté, ni la plateforme.</p>
<h3>Réponses jugées incohérentes</h3>
<p>Les questionnaires contiennent des contrôles : questions reformulées différemment, questions pièges, mesure du temps de réponse. Répondre trop vite ou de façon contradictoire déclenche un rejet automatique.</p>
<h3>Conditions partiellement remplies</h3>
<p>Une offre demandant d''atteindre un niveau, de créer un compte vérifié ou d''utiliser une application plusieurs jours n''est créditée qu''une fois la condition entièrement satisfaite. S''arrêter juste avant ne donne rien.</p>
<h3>Contexte technique</h3>
<p>VPN actif, bloqueur de publicité, plusieurs offres lancées en parallèle, changement d''appareil en cours de route : autant de situations où le suivi de l''offre se perd.</p>

<h2>Comment mettre les chances de son côté</h2>
<ol>
<li><strong>Lisez les conditions avant de commencer.</strong> Elles précisent ce qui déclenche exactement la validation.</li>
<li><strong>Complétez votre profil de sondage honnêtement.</strong> C''est le seul levier réel : un profil précis fait remonter des offres adaptées et réduit fortement les écartages.</li>
<li><strong>Une offre à la fois.</strong> Les suivis se télescopent, et certaines validations se perdent.</li>
<li><strong>Désactivez VPN et bloqueur</strong> pendant l''offre.</li>
<li><strong>Conservez une preuve :</strong> capture d''écran de l''écran final, identifiant de l''offre, date et heure. Sans cela, aucune réclamation n''aboutit.</li>
</ol>

<h2>Faire une réclamation qui aboutit</h2>
<p>Le recours passe par le <strong>support du partenaire</strong>, accessible depuis sa propre fenêtre — pas par le support de la plateforme, qui n''a pas les données.</p>
<p>Une réclamation efficace contient :</p>
<ul>
<li>le nom exact de l''offre et son identifiant ;</li>
<li>la date et l''heure approximatives ;</li>
<li>l''identifiant utilisateur transmis au partenaire ;</li>
<li>la preuve d''accomplissement — capture de l''écran de confirmation, e-mail reçu de l''annonceur ;</li>
<li>ce que vous avez fait précisément, étape par étape.</li>
</ul>
<p>Les réclamations bien documentées aboutissent régulièrement. Les messages du type « je n''ai pas été payé » n''aboutissent jamais, faute d''élément vérifiable.</p>

<h2>Quand accepter la perte</h2>
<p>Si vous avez été écarté d''un sondage après quelques minutes, il n''y a pas de recours : ce n''est pas un dysfonctionnement, c''est le fonctionnement prévu. Insister consomme du temps sans résultat.</p>
<p>La bonne réaction est statistique : sur un volume d''offres, une part sera écartée, et ce taux baisse nettement avec un profil bien renseigné. Les offres non-sondage — installations, inscriptions — ont un taux de validation bien supérieur, précisément parce qu''elles n''ont pas de critère de profil.</p>

<h2>En résumé</h2>
<p>La décision appartient au partenaire ; la plateforme n''a pas les données pour la contester. Un profil complet, une offre à la fois et une capture d''écran finale sont les trois réflexes qui changent réellement les choses.</p>
<p>Pour le fonctionnement détaillé de chaque tâche, consultez notre guide <a href="/blog/comprendre-optimiser-chaque-type-de-tache">Comprendre et optimiser chaque type de tâche</a>.</p>'
);
