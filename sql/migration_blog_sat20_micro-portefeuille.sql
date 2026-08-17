-- ============================================================================
-- Wintaskly — SATELLITE 20 (pilier 3) : "Les micro-portefeuilles"
-- ============================================================================
-- ~800 mots, catégorie Guides.
--
-- Sujet directement utile : la plupart des retraits de petits montants
-- passent par ce type de service. Aucun service n'est nommé — l'article
-- explique le mécanisme et les critères, pas les adresses.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================
INSERT IGNORE INTO `blog_posts`
 (`slug`, `category_id`, `title`, `excerpt`, `cover_emoji`, `author_name`,
  `meta_title`, `meta_description`, `status`, `reading_minutes`, `published_at`, `body`)
VALUES (
 'micro-portefeuille-a-quoi-ca-sert',
 (SELECT id FROM blog_categories WHERE slug='guides'),
 'Le micro-portefeuille : à quoi ça sert, et ses limites',
 'Recevoir l''équivalent de quelques centimes en crypto est normalement impossible : les frais dépassent le montant. Voici le mécanisme qui rend ces micro-paiements viables.',
 '🏦',
 'Équipe Wintaskly',
 'Micro-portefeuille crypto : utilité et limites',
 'Comprendre le rôle d''un micro-portefeuille dans les retraits de petits montants, ce qu''il permet, et les précautions à prendre avec ce type de service.',
 'published', 5, UTC_TIMESTAMP(),
 '<p>Un problème arithmétique se pose dès qu''on veut distribuer de très petites sommes en cryptomonnaie : sur certains réseaux, les frais de transaction dépassent largement le montant envoyé. Transférer l''équivalent de quelques centimes coûterait plus cher que ce qu''on transfère.</p>
<p>Les micro-portefeuilles existent pour résoudre exactement ce problème. Voici comment, et ce que cela implique.</p>

<h2>Le mécanisme</h2>
<p>Un micro-portefeuille est un service qui reçoit et conserve de très petits montants <strong>sans effectuer de transaction sur la blockchain</strong>.</p>
<p>Quand une plateforme vous envoie une petite somme, rien ne circule réellement sur le réseau : le service met simplement à jour un solde dans sa propre base de données. Aucun frais de réseau n''est engagé, puisqu''aucune transaction n''a lieu.</p>
<p>Ce n''est qu''au moment où vous sortez les fonds vers un portefeuille personnel qu''une vraie transaction est émise — et que les frais s''appliquent, une seule fois, sur un montant devenu significatif.</p>
<p>C''est ce qu''on appelle un regroupement : mille micro-paiements deviennent une seule transaction, dont le coût est mutualisé.</p>

<h2>Ce que ça permet concrètement</h2>
<ul>
<li><strong>Recevoir des montants autrement impossibles.</strong> Sans ce mécanisme, une plateforme devrait imposer un seuil de retrait très élevé — ou ne pas proposer de crypto du tout.</li>
<li><strong>Encaisser plus souvent.</strong> Le seuil de retrait vers un micro-portefeuille est généralement bien plus bas que vers une adresse personnelle.</li>
<li><strong>Regrouper plusieurs sources.</strong> Si vous utilisez plusieurs plateformes, elles peuvent alimenter le même micro-portefeuille, et vous sortez le total en une fois.</li>
<li><strong>Choisir sa cryptomonnaie de sortie.</strong> La plupart de ces services permettent de convertir en interne avant le retrait final.</li>
</ul>

<h2>La contrepartie : vous ne détenez pas les clés</h2>
<p>C''est le point essentiel, et il vaut pour tous les services de ce type.</p>
<p>Tant que les fonds sont sur un micro-portefeuille, ils sont détenus par le service. Ce que vous possédez est une <strong>créance</strong> sur lui, pas une cryptomonnaie sur une adresse que vous contrôlez.</p>
<p>Concrètement, cela signifie que vous dépendez de :</p>
<ul>
<li><strong>sa sécurité</strong> — une intrusion chez lui affecte votre solde ;</li>
<li><strong>sa solvabilité et sa pérennité</strong> — un service qui ferme emporte les soldes ;</li>
<li><strong>ses décisions</strong> — conditions, seuils et frais peuvent changer, et un compte peut être suspendu.</li>
</ul>
<p>Ce n''est pas une raison pour les éviter : c''est une raison pour les utiliser <strong>comme un lieu de passage, pas de conservation</strong>.</p>

<h2>La règle d''usage</h2>
<p>Un micro-portefeuille est un sas, pas un coffre.</p>
<p>Laissez-y transiter ce que vous accumulez, puis sortez régulièrement vers un portefeuille dont vous détenez les clés dès que le montant justifie les frais de réseau. Le bon rythme dépend du réseau choisi : sur un réseau économique, sortir souvent coûte peu ; sur un réseau coûteux, mieux vaut accumuler davantage.</p>
<p>La question à se poser : <em>si ce service fermait demain sans préavis, quel serait mon manque à gagner ?</em> Si la réponse vous gêne, c''est que le solde y est trop élevé.</p>

<h2>Les précautions à prendre</h2>
<ul>
<li><strong>Activez la double authentification.</strong> Ces comptes contiennent de la valeur et sont des cibles connues.</li>
<li><strong>Utilisez une adresse e-mail dédiée</strong>, distincte de votre messagerie principale.</li>
<li><strong>Vérifiez l''adresse du site à chaque connexion.</strong> Les imitations de ces services sont nombreuses, et une fausse page récupère vos identifiants.</li>
<li><strong>Ne communiquez jamais vos identifiants</strong>, à personne — aucun support légitime ne les demande.</li>
<li><strong>Vérifiez les frais de sortie</strong> avant de retirer : ils varient selon la cryptomonnaie et le réseau choisis.</li>
</ul>

<h2>Comment choisir un service</h2>
<p>Plutôt qu''un nom qui vieillirait, les critères qui comptent :</p>
<ul>
<li><strong>L''ancienneté et l''historique de paiement</strong>, vérifiables sur des discussions datant de plusieurs mois.</li>
<li><strong>Les cryptomonnaies et réseaux proposés</strong>, notamment les réseaux économiques pour les petites sorties.</li>
<li><strong>Le seuil de retrait vers une adresse personnelle</strong>, qui détermine la fréquence à laquelle vous pourrez vraiment récupérer vos fonds.</li>
<li><strong>La double authentification disponible</strong>, condition minimale.</li>
<li><strong>La clarté des frais</strong>, affichés avant validation et non après.</li>
</ul>

<h2>En résumé</h2>
<p>Le micro-portefeuille résout un vrai problème technique : rendre viables des paiements que les frais de réseau rendraient absurdes. C''est ce qui permet aux plateformes de micro-gains de proposer des seuils de retrait bas.</p>
<p>En échange, il introduit une dépendance à un tiers. La règle est donc simple : <strong>y faire transiter, pas y conserver</strong>.</p>
<p>Pour le fonctionnement complet des retraits, consultez notre guide <a href="/blog/retraits-conversion-moyens-de-paiement">Retraits, conversion et moyens de paiement</a>, et pour choisir où conserver vos fonds durablement, <a href="/blog/portefeuille-chaud-froid-lequel-choisir">Portefeuille chaud ou froid</a>.</p>
<p class="wt-article__disclaimer"><em>Cet article est pédagogique et ne recommande aucun service en particulier. Les frais, seuils et conditions évoqués varient selon les prestataires et évoluent.</em></p>'
);
