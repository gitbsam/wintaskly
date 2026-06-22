-- ============================================================================
-- Wintaskly — Article teaser : lancement du Bingo
-- ============================================================================
-- Article d'annonce du nouveau jeu Bingo, avec compte à rebours intégré.
-- Le placeholder {{BINGO_COUNTDOWN}} est remplacé à l'affichage par un widget
-- de compte à rebours live qui lit la config bingo.launch_at (source unique).
--
-- IMPORTANT : avant d'exécuter, ajuste la date de publication ci-dessous
-- (published_at) pour qu'elle tombe quelques jours AVANT ton lancement.
-- Ici, on programme l'article pour dans 3 jours par défaut.
--
-- À exécuter APRÈS migration_blog.sql.
-- ============================================================================

INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'nouveau-jeu-bingo-arrive-bientot-wintaskly',
 (SELECT id FROM blog_categories WHERE slug='actualites'),
 'Le Bingo arrive sur Wintaskly : carton gratuit et jackpot évolutif !',
 'Un tout nouveau jeu débarque bientôt sur Wintaskly ! Découvre le Bingo : un carton gratuit à chaque partie, des tirages quotidiens et un jackpot qui grandit. Compte à rebours avant le lancement !',
 '🎰',
 'Équipe Wintaskly',
 'Nouveau jeu Bingo sur Wintaskly — Carton gratuit & jackpot évolutif',
 'Le Bingo arrive sur Wintaskly ! Carton gratuit, tirages quotidiens, jackpot évolutif sur 7 jours. Découvre les règles et le compte à rebours avant le lancement.',
 'published', 4, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 DAY),
 '<p>On a une grande nouvelle pour toute la communauté Wintaskly : un tout nouveau jeu arrive très bientôt sur la plateforme ! Préparez-vous à vivre l''excitation du <strong>Bingo</strong>, avec une mécanique pensée pour être accessible à tous et récompenser les joueurs réguliers.</p>

<h2>⏳ Lancement dans...</h2>
<p>Le compte à rebours est lancé ! Voici le temps qu''il reste avant que le Bingo n''ouvre ses portes :</p>

{{BINGO_COUNTDOWN}}

<p style="text-align:center;opacity:.8">Reviens à cette date pour jouer ta première partie !</p>

<h2>🎁 Un carton gratuit à chaque partie</h2>
<p>Pas besoin de dépenser quoi que ce soit pour participer : à chaque nouvelle partie, tu reçois automatiquement <strong>un carton gratuit</strong>. Tu actives ton carton, et tu es prêt à jouer. C''est notre façon de rendre le jeu accessible à tout le monde, sans barrière à l''entrée.</p>
<p>Tu veux multiplier tes chances ? Tu pourras acquérir des cartons supplémentaires avec tes coins. Plus tu as de cartons, plus tu as de combinaisons en jeu, et plus tu participes à faire grandir le jackpot commun.</p>

<h2>📅 Des tirages chaque jour</h2>
<p>Le Bingo Wintaskly fonctionne par cycles. Pendant toute la durée d''une partie, un tirage de numéros a lieu <strong>chaque jour</strong>. Les numéros s''accumulent au fil des jours : ta mission est de valider sur tes cartons les numéros qui sortent.</p>
<p>Connecte-toi régulièrement pour ne manquer aucun tirage et cocher tes numéros au fur et à mesure. La régularité est la clé : un joueur assidu a toujours une longueur d''avance.</p>

<h2>🎰 Un jackpot qui grandit</h2>
<p>Voici ce qui rend le Bingo Wintaskly vraiment palpitant : le <strong>jackpot évolutif</strong>. La cagnotte de départ grandit à chaque carton acheté par la communauté. Autrement dit, plus il y a de joueurs et de cartons en jeu, plus le jackpot devient gros. Vous jouez ensemble pour gonfler la récompense !</p>
<p>Et si personne ne remporte le jackpot à la fin d''une partie ? Pas de panique : la cagnotte est <strong>reportée</strong> sur la partie suivante, la rendant encore plus alléchante.</p>

<h2>🏆 Comment gagner ?</h2>
<p>Le principe est simple : sois le premier à compléter ton carton ! Tu dois valider manuellement les 25 numéros de ton carton (tous tirés depuis le début de la partie), puis cliquer sur « Réclamer » avant la fin de la journée. Le premier carton complet réclamé déclenche la fin de la partie.</p>
<p>À la distribution, le jackpot est partagé entre les gagnants. Tes gains arrivent directement sur ton solde Wintaskly, prêts à être retirés comme d''habitude.</p>

<h2>Prêt à jouer ?</h2>
<p>Le Bingo sera accessible directement depuis la section <strong>Tâches</strong> de ton compte, dès l''ouverture. En attendant, surveille le compte à rebours ci-dessus et prépare ta stratégie. Carton gratuit, tirages quotidiens, jackpot collectif : tous les ingrédients sont réunis pour des parties pleines de suspense.</p>
<p>Rendez-vous très bientôt pour le grand lancement. Bonne chance à tous, et que les meilleurs numéros soient avec vous ! 🍀</p>'
);
