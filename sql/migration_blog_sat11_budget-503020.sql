-- ============================================================================
-- Wintaskly — SATELLITE 11 (pilier 5) : "La règle 50/30/20"
-- ============================================================================
-- ~800 mots, catégorie Finance.
--
-- ⚠️ YMYL. Les pourcentages de la règle sont cités parce qu'ils SONT la règle
-- (impossible d'en parler sans les nommer), mais l'article démontre
-- justement qu'ils ne s'appliquent pas à un budget serré. Aucun montant,
-- aucun produit, aucun taux de rendement. Disclaimer présent.
--
-- Angle distinct du pilier 5 (méthode globale) et du satellite fonds
-- d'urgence (dimensionnement de la réserve) : celui-ci porte sur la
-- répartition du budget courant.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- CALENDRIER DE PUBLICATION
-- published_at = 2026-09-18 14:12:00 (et non UTC_TIMESTAMP()).
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
 'budget-50-30-20-methode-et-limites',
 (SELECT id FROM blog_categories WHERE slug='finance'),
 'La règle 50/30/20 : la méthode, et pourquoi elle ne marche pas pour tout le monde',
 'La répartition budgétaire la plus citée au monde. Ce qu''elle apporte réellement, et pourquoi elle devient inapplicable dès que le logement pèse trop lourd.',
 '📐',
 'Équipe Wintaskly',
 'La règle 50/30/20 : méthode et limites réelles',
 'Comprendre la répartition budgétaire 50/30/20, ce qu''elle apporte, et les situations dans lesquelles elle est inapplicable — avec les alternatives.',
 'published', 5, '2026-09-18 14:12:00',
 '<p>C''est la méthode de budget la plus répandue : répartir ses revenus après impôts en trois parts — la moitié pour les besoins, un peu moins d''un tiers pour les envies, le reste pour l''épargne et le remboursement de dettes.</p>
<p>Elle a des qualités réelles. Elle a aussi une limite majeure, rarement mentionnée par ceux qui la recommandent.</p>

<h2>Ce que dit la méthode</h2>
<ul>
<li><strong>Les besoins</strong> : ce qui est indispensable et non reportable — logement, charges, alimentation, transport nécessaire, assurances, remboursements de crédits en cours.</li>
<li><strong>Les envies</strong> : ce qui améliore la vie sans être vital — loisirs, restaurants, abonnements de confort, achats plaisir.</li>
<li><strong>L''épargne et le désendettement</strong> : réserve de précaution, projets, remboursements anticipés.</li>
</ul>
<p>La force de cette approche tient en un point : elle ne demande pas de suivre chaque dépense à l''euro près. Trois enveloppes suffisent, ce qui la rend tenable dans la durée — là où les méthodes détaillées sont presque toujours abandonnées après quelques semaines.</p>

<h2>Sa vraie utilité : un diagnostic</h2>
<p>Le plus intéressant n''est pas de <em>respecter</em> cette répartition, mais de la calculer une fois pour voir où l''on se situe.</p>
<p>Faites l''exercice : classez vos dépenses des trois derniers mois dans ces trois catégories, et regardez la répartition obtenue. Le résultat est souvent instructif.</p>
<ul>
<li>Si les besoins dépassent largement la moitié, le problème est structurel — pas une question de discipline.</li>
<li>Si les envies débordent, il existe une marge d''ajustement immédiate.</li>
<li>Si la troisième part est à zéro, vous savez ce qu''il faut construire en priorité.</li>
</ul>
<p>Utilisée comme thermomètre plutôt que comme prescription, la méthode devient réellement utile.</p>

<h2>La limite qu''on passe sous silence</h2>
<p>Cette règle a été formulée dans un contexte où le logement représentait une part bien plus modeste des revenus qu''aujourd''hui dans beaucoup de zones tendues.</p>
<p>Quand le loyer ou le crédit absorbe à lui seul près de la moitié des revenus, la moitié théoriquement dédiée à l''ensemble des besoins est déjà consommée par un seul poste. Le reste — nourriture, énergie, transport — déborde mécaniquement.</p>
<p>Dans cette situation, la règle ne dit pas ce qu''il faut faire. Elle dit seulement que la situation est tendue, ce que la personne concernée sait déjà.</p>
<p>C''est là que le conseil budgétaire standard atteint sa limite : <strong>aucune répartition ne crée de la marge là où il n''y en a pas.</strong> Les leviers pertinents sont alors ailleurs — droits sociaux non réclamés, renégociation de charges fixes, accompagnement par un service social ou une association de consommateurs. Ces recours existent et restent largement sous-utilisés.</p>

<h2>Les autres angles morts</h2>
<h3>Les revenus irréguliers</h3>
<p>La méthode suppose un revenu mensuel stable. Pour une activité indépendante, saisonnière ou à contrats courts, appliquer des pourcentages au mois en cours n''a pas de sens : un bon mois gonfle l''enveloppe « envies » juste avant un mois creux.</p>
<p>Une adaptation consiste à raisonner sur le <strong>revenu plancher</strong> — celui qui rentre même dans un mois faible — et à traiter tout dépassement comme du surplus à épargner en priorité.</p>
<h3>La frontière floue entre besoin et envie</h3>
<p>Une connexion internet est-elle un besoin ? Pour quelqu''un qui télétravaille, oui. Un abonnement de transport ? Selon la ville et l''emploi. Cette zone grise fait que deux personnes classent différemment les mêmes dépenses — et obtiennent des diagnostics opposés.</p>
<p>Le critère le plus opérant : <em>que se passe-t-il si je supprime cette dépense demain ?</em> Une conséquence sérieuse et rapide indique un besoin ; une simple gêne indique une envie.</p>
<h3>Les dépenses annuelles</h3>
<p>Assurances, taxes, rentrée scolaire, entretien du véhicule : elles tombent en une fois et déséquilibrent le mois concerné. Les diviser par douze et les provisionner chaque mois évite qu''elles ne détruisent la répartition trois fois par an.</p>

<h2>Comment l''adapter</h2>
<p>Plutôt que de viser des pourcentages qui ne correspondent pas à votre réalité :</p>
<ol>
<li><strong>Mesurez votre répartition actuelle</strong> sur trois mois, sans rien changer.</li>
<li><strong>Fixez-vous un objectif d''écart</strong>, pas un objectif absolu : augmenter légèrement la part d''épargne, réduire légèrement une catégorie identifiée.</li>
<li><strong>Automatisez la part d''épargne</strong> dès la rentrée d''argent, plutôt que d''espérer un reste en fin de mois.</li>
<li><strong>Réévaluez tous les six mois.</strong> Une situation change ; une répartition figée devient vite fausse.</li>
</ol>

<h2>En résumé</h2>
<p>La règle 50/30/20 est un bon outil de diagnostic et un mauvais objectif universel. Sa valeur tient à sa simplicité, pas à l''exactitude de ses proportions.</p>
<p>Si votre répartition en est très éloignée, cela ne signifie pas que vous gérez mal : cela signifie souvent que vos dépenses contraintes sont élevées — une réalité qu''aucune méthode budgétaire ne résout à elle seule.</p>
<p>Pour la construction progressive d''une épargne avec de petits revenus, consultez notre guide <a href="/blog/se-constituer-une-epargne-quand-on-gagne-peu">Se constituer une épargne quand on gagne peu</a>.</p>
<p class="wt-article__disclaimer"><em>Cet article est pédagogique et ne constitue pas un conseil financier personnalisé. Les dispositifs d''aide et d''accompagnement varient selon les pays.</em></p>'
);
