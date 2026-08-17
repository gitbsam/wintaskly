-- ============================================================================
-- Wintaskly — SATELLITE 8 (pilier 6) : "Portefeuille chaud ou froid"
-- ============================================================================
-- ~800 mots, catégorie Crypto (catégorie la plus faible : 2 articles).
--
-- ⚠️ SUJET YMYL. Mêmes règles que le pilier 6 :
--   • aucune marque de portefeuille ni de plateforme nommée ;
--   • aucun conseil d'achat, aucun montant, aucun cours ;
--   • le propos reste : protéger ce qu'on reçoit, proportionnellement.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'portefeuille-chaud-froid-lequel-choisir',
 (SELECT id FROM blog_categories WHERE slug='crypto'),
 'Portefeuille chaud ou froid : lequel pour quel usage',
 'Tous les portefeuilles ne servent pas la même chose. Comment adapter la protection au montant réellement en jeu, sans surinvestir ni s''exposer.',
 '🧊',
 'Équipe Wintaskly',
 'Portefeuille chaud ou froid : lequel choisir',
 'Comprendre les différences entre portefeuille hébergé, logiciel et matériel, et adapter le niveau de protection au montant réellement détenu.',
 'published', 5, UTC_TIMESTAMP(),
 '<p>« Portefeuille chaud », « portefeuille froid », « garde autonome » : le vocabulaire décourage, alors que la distinction sous-jacente est simple et se résume à une question — <strong>vos clés sont-elles connectées à internet, et qui les détient ?</strong></p>
<p>Voici de quoi choisir sans surinvestir dans une protection disproportionnée, ni laisser une somme conséquente exposée.</p>

<h2>Chaud, froid : la seule différence qui compte</h2>
<p>Un portefeuille <strong>chaud</strong> est connecté à internet. Pratique, immédiat, mais exposé à ce qui atteint un appareil connecté : logiciels malveillants, hameçonnage, compromission du service.</p>
<p>Un portefeuille <strong>froid</strong> garde les clés hors ligne. Une transaction se signe sur un appareil déconnecté ; la clé ne traverse jamais le réseau. Nettement plus sûr, mais moins immédiat.</p>
<p>Cette distinction se superpose à une autre, souvent plus importante : <strong>qui détient les clés</strong>.</p>

<h2>Les trois configurations</h2>

<h3>Le portefeuille hébergé par un service</h3>
<p>Le service détient les clés pour vous. Vous vous connectez avec un identifiant et un mot de passe, comme sur n''importe quel site.</p>
<p><strong>Avantages :</strong> aucune gestion technique, récupération possible en cas d''oubli de mot de passe, transferts internes instantanés et souvent gratuits.</p>
<p><strong>Limites :</strong> vous ne détenez pas réellement les fonds — vous détenez une créance sur ce service. Sa sécurité, sa solvabilité et sa pérennité deviennent les vôtres. L''histoire du secteur compte suffisamment de services disparus avec les avoirs de leurs utilisateurs pour que ce risque soit pris au sérieux.</p>
<p><strong>Usage adapté :</strong> petits montants en transit, argent destiné à être converti ou dépensé rapidement.</p>

<h3>Le portefeuille logiciel</h3>
<p>Une application sur votre téléphone ou ordinateur. Vous détenez les clés, protégées par une phrase de récupération.</p>
<p><strong>Avantages :</strong> possession réelle, gratuité, autonomie complète vis-à-vis de tout service.</p>
<p><strong>Limites :</strong> la sécurité dépend de celle de votre appareil. Un téléphone compromis met les fonds en danger. Et la perte de la phrase de récupération, sans sauvegarde, signifie une perte définitive — aucune procédure de récupération n''existe, par conception.</p>
<p><strong>Usage adapté :</strong> montants courants qu''on souhaite réellement posséder sans dépendre d''un tiers.</p>

<h3>Le portefeuille matériel</h3>
<p>Un appareil dédié qui conserve les clés hors ligne. Pour signer une transaction, on le connecte, on valide physiquement, la clé ne sort jamais.</p>
<p><strong>Avantages :</strong> le niveau de protection le plus élevé pour un particulier. Même sur un ordinateur infecté, la clé reste isolée.</p>
<p><strong>Limites :</strong> un coût d''achat, une prise en main initiale, et une contrainte à chaque opération. Il faut aussi sauvegarder la phrase : perdre l''appareil sans elle revient à perdre les fonds.</p>
<p><strong>Usage adapté :</strong> montants dont la perte constituerait un problème réel.</p>

<h2>Adapter la protection au montant</h2>
<p>Voilà le principe qui évite les deux erreurs symétriques.</p>
<p>Acheter un appareil dédié pour l''équivalent de quelques euros de paiements est disproportionné : le coût dépasse ce qu''il protège. À l''inverse, laisser une somme conséquente sur un service tiers parce que c''est plus simple revient à confier son argent à quelqu''un dont on ignore la santé financière.</p>
<p>Une approche par paliers fonctionne bien :</p>
<ul>
<li><strong>Argent en transit</strong>, destiné à être converti sous peu → portefeuille hébergé, pour la simplicité.</li>
<li><strong>Argent conservé</strong> sans échéance précise → portefeuille logiciel, avec phrase sauvegardée hors ligne.</li>
<li><strong>Somme dont la perte serait un vrai problème</strong> → portefeuille matériel.</li>
</ul>
<p>Rien n''oblige à choisir une seule solution : beaucoup combinent un portefeuille hébergé pour le quotidien et un stockage plus sûr pour le reste.</p>

<h2>La phrase de récupération : la vraie clé</h2>
<p>Quel que soit le type retenu dès lors que vous détenez vos clés, tout repose sur cette suite de mots.</p>
<ul>
<li><strong>Elle ne se stocke pas en ligne.</strong> Ni photo dans le cloud, ni fichier dans la messagerie, ni note synchronisée.</li>
<li><strong>Elle ne se saisit jamais</strong> ailleurs que dans l''application du portefeuille lui-même, lors d''une restauration. Aucun site ne doit la demander.</li>
<li><strong>Elle se conserve physiquement</strong>, dans un endroit protégé — idéalement en double, à deux emplacements distincts, contre le vol comme contre l''incendie.</li>
</ul>
<p>Quiconque la détient possède les fonds. Immédiatement, définitivement, sans recours.</p>

<h2>En résumé</h2>
<p>La question n''est pas « quel est le meilleur portefeuille » mais « quel niveau de protection correspond à ce que je détiens ». Un service hébergé pour de petits montants en transit est raisonnable ; il ne l''est plus dès que la somme compte.</p>
<p>Et dans tous les cas, la phrase de récupération reste le point unique de défaillance : c''est elle qu''il faut protéger, avant tout le reste.</p>
<p>Pour comprendre le fonctionnement d''ensemble des cryptomonnaies, consultez notre guide <a href="/blog/la-crypto-expliquee-sans-jargon">La crypto expliquée sans jargon</a>.</p>
<p class="wt-article__disclaimer"><em>Cet article est pédagogique et ne recommande aucun produit, service ou marque. Il ne constitue pas un conseil en investissement. La réglementation applicable varie selon les pays.</em></p>'
);
