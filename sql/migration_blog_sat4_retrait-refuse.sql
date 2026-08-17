-- ============================================================================
-- Wintaskly — SATELLITE 4 (pilier 3) : "Pourquoi un retrait est refusé"
-- ============================================================================
-- ~850 mots, catégorie Guides. Satellite du pilier "retraits".
--
-- Vérifié contre le code : wt_fraud_check_withdraw() renvoie exactement trois
-- motifs — email_not_verified, account_too_young, under_review — et les Coins
-- sont recrédités en cas de refus. Aucun seuil ni délai n'est écrit en dur :
-- ce sont des réglages administrables.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- CALENDRIER DE PUBLICATION
-- published_at = 2026-09-04 11:12:00 (et non UTC_TIMESTAMP()).
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
 'pourquoi-un-retrait-peut-etre-refuse',
 (SELECT id FROM blog_categories WHERE slug='guides'),
 'Pourquoi un retrait peut être refusé (et comment l''éviter)',
 'Trois contrôles automatiques peuvent bloquer une demande de retrait. Ce qu''ils vérifient, pourquoi ils existent, et comment s''assurer de les passer avant d''atteindre le seuil.',
 '🔍',
 'Équipe Wintaskly',
 'Retrait refusé : les 3 causes et comment les éviter',
 'Les motifs réels de refus d''un retrait : e-mail non vérifié, compte trop récent, compte sous revue. Comment les anticiper et que faire en cas de blocage.',
 'published', 5, '2026-09-04 11:12:00',
 '<p>Atteindre le seuil de retrait puis voir sa demande refusée est particulièrement frustrant. La bonne nouvelle : les motifs de refus sont peu nombreux, tous prévisibles, et tous évitables si on les connaît à l''avance.</p>
<p>Autre point important : <strong>un refus ne fait pas perdre vos Coins</strong>. Ils sont recrédités sur votre solde, et vous pouvez redemander une fois le problème réglé.</p>

<h2>Motif 1 : l''adresse e-mail n''est pas vérifiée</h2>
<p>C''est de loin la cause la plus fréquente, et la plus facile à éviter.</p>
<p>La vérification n''est pas exigée pour gagner — vous pouvez pratiquer et accumuler sans elle. Elle l''est en revanche pour <strong>encaisser</strong>. Beaucoup d''utilisateurs découvrent donc cette étape au pire moment : après des semaines d''activité.</p>
<h3>Pourquoi cette règle</h3>
<p>Ce placement est délibéré. Exiger la vérification dès l''inscription découragerait les nouveaux venus ; l''exiger avant un paiement bloque les comptes créés en masse, qui ne franchissent jamais cette étape. C''est aussi ce qui protège le programme de parrainage : un multi-compte ne peut pas encaisser.</p>
<h3>Que faire</h3>
<p>Vérifiez votre e-mail dès l''inscription, sans attendre. Si vous ne trouvez pas le message, regardez le dossier des indésirables avant de demander un nouvel envoi — c''est là qu''il se trouve neuf fois sur dix.</p>

<h2>Motif 2 : le compte est trop récent</h2>
<p>Un délai minimal s''applique entre la création du compte et le premier retrait.</p>
<h3>Pourquoi cette règle</h3>
<p>Elle vise les comptes jetables : créés, exploités rapidement, vidés, abandonnés. Ce schéma est la signature d''une fraude industrialisée, et sans ce délai, une plateforme serait vidée par des comptes automatisés en quelques jours.</p>
<h3>Que faire</h3>
<p>Rien, sinon attendre. Ce délai ne concerne que le tout premier retrait : une fois passé, il ne s''applique plus jamais. Autant en profiter pour vérifier son e-mail et configurer sa méthode de paiement.</p>

<h2>Motif 3 : le compte est sous revue</h2>
<p>C''est le motif le plus rare, et le plus mal compris.</p>
<p>Un score de risque est calculé automatiquement à partir de signaux techniques. Au-delà d''un certain niveau, les retraits sont suspendus le temps d''un examen par une personne.</p>
<h3>Ce qui fait monter ce score</h3>
<p>Pas une activité normale, même intensive. Ce sont des <strong>combinaisons</strong> de signaux :</p>
<ul>
<li>géolocalisation masquée ou incohérente (VPN, proxy) ;</li>
<li>indices de comptes multiples depuis un même appareil ou une même connexion ;</li>
<li>rythme d''actions non humain, évoquant un automate ;</li>
<li>adresse de retrait partagée avec d''autres comptes.</li>
</ul>
<p>Un seul de ces éléments ne déclenche généralement rien. C''est leur accumulation qui compte.</p>
<h3>Que faire</h3>
<p>Contactez le support en expliquant votre situation concrètement. Une connexion partagée — foyer, résidence étudiante, cybercafé — est une explication parfaitement recevable, et courante. Chaque contestation est examinée par un humain.</p>
<p>Notre <a href="/help/antifraud.php">politique anti-fraude</a> détaille les principes appliqués et la procédure de recours.</p>

<h2>Les erreurs qui ne sont pas des refus</h2>
<p>Certaines situations ressemblent à un refus sans en être un — et le remède est différent.</p>
<h3>Le paiement est parti mais n''est pas arrivé</h3>
<p>Une demande marquée comme traitée signifie que le paiement a été <strong>envoyé</strong>, pas reçu. Il dépend ensuite du réseau ou du prestataire. Vérifiez l''identifiant de transaction s''il est fourni, et le nombre de confirmations exigé par votre portefeuille.</p>
<h3>L''adresse était erronée</h3>
<p>Le cas le plus grave, parce qu''il est irréversible. Une transaction en cryptomonnaie envoyée à une mauvaise adresse ne se récupère pas, par personne. D''où la règle : copiez-collez toujours, et vérifiez les premiers et derniers caractères après le collage.</p>
<h3>Le mauvais réseau</h3>
<p>Une même cryptomonnaie peut circuler sur plusieurs réseaux. Envoyer vers une adresse d''un autre réseau que celui attendu entraîne généralement une perte définitive.</p>

<h2>La check-list avant de demander</h2>
<ol>
<li>Adresse e-mail vérifiée ✔</li>
<li>Délai d''ancienneté écoulé ✔</li>
<li>Adresse de retrait copiée-collée, jamais saisie à la main ✔</li>
<li>Réseau correspondant à celui attendu par le destinataire ✔</li>
<li>Adresse appartenant bien à un portefeuille dont vous êtes titulaire ✔</li>
</ol>
<p>Et un conseil qui vaut pour tout le reste : <strong>faites un premier retrait au montant minimum</strong>. Cela valide toute la chaîne sans risquer une somme importante sur une adresse mal copiée.</p>

<h2>En résumé</h2>
<p>Les trois motifs de refus sont connus, prévisibles et réversibles — vos Coins vous sont rendus dans tous les cas. Les vraies pertes ne viennent pas des refus, mais des erreurs d''adresse et de réseau, qui, elles, sont définitives.</p>
<p>Pour le fonctionnement complet des retraits, de la conversion à la réception, consultez notre guide <a href="/blog/retraits-conversion-moyens-de-paiement">Retraits, conversion et moyens de paiement</a>.</p>'
);
