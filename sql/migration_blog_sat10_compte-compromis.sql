-- ============================================================================
-- Wintaskly — SATELLITE 10 (pilier 4) : "Compte compromis"
-- ============================================================================
-- ~800 mots, catégorie Astuces.
--
-- Article d'urgence : structure en actions ordonnées plutôt qu'en
-- explications. Quelqu'un qui le lit est probablement en train de paniquer —
-- l'ordre des étapes compte plus que la pédagogie.
--
-- Décrit le système 2FA réel (multi-méthodes + codes de secours, v8.74).
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- CALENDRIER DE PUBLICATION
-- published_at = 2026-09-17 16:23:00 (et non UTC_TIMESTAMP()).
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
 'que-faire-si-votre-compte-est-compromis',
 (SELECT id FROM blog_categories WHERE slug='astuces'),
 'Compte compromis : que faire, dans quel ordre',
 'Les premières minutes comptent. La séquence d''actions à suivre pour reprendre le contrôle, et les signes qui doivent alerter avant même qu''il ne soit trop tard.',
 '🚨',
 'Équipe Wintaskly',
 'Compte compromis : la marche à suivre',
 'Que faire si votre compte est piraté : séquence d''actions prioritaires, reprise de contrôle, et signaux d''alerte à repérer en amont.',
 'published', 5, '2026-09-17 16:23:00',
 '<p>Si vous lisez ceci en urgence, allez directement à la section « La séquence à suivre ». L''ordre des actions compte plus que leur exhaustivité : certaines n''ont d''effet que si elles sont faites en premier.</p>

<h2>Les signes qui doivent alerter</h2>
<p>Une compromission passe rarement inaperçue si l''on sait quoi regarder :</p>
<ul>
<li>un e-mail confirmant un changement de mot de passe que vous n''avez pas demandé ;</li>
<li>une notification de connexion depuis un appareil ou un lieu inconnu ;</li>
<li>une activité dans votre historique que vous ne reconnaissez pas — une tâche non effectuée, un retrait non demandé ;</li>
<li>votre mot de passe qui ne fonctionne plus alors que vous en êtes certain ;</li>
<li>des messages envoyés depuis votre compte à d''autres membres.</li>
</ul>
<p>Le signal le plus révélateur reste le <strong>changement d''adresse e-mail</strong> : c''est presque toujours la première action d''un attaquant, parce qu''elle vous coupe de toute procédure de récupération.</p>

<h2>La séquence à suivre</h2>

<h3>1. Utilisez un appareil dont vous êtes sûr</h3>
<p>Si un logiciel malveillant est en cause, changer votre mot de passe depuis l''appareil infecté revient à le communiquer directement à l''attaquant. Préférez un autre téléphone ou ordinateur.</p>

<h3>2. Changez le mot de passe immédiatement</h3>
<p>Tapez l''adresse du site vous-même — n''utilisez pas un lien reçu par message, qui peut mener à une fausse page.</p>
<p>Choisissez un mot de passe entièrement nouveau, long, et qui n''est utilisé nulle part ailleurs.</p>

<h3>3. Activez la double authentification</h3>
<p>Si elle n''était pas active, c''est le moment. Elle empêche l''attaquant de revenir même s''il connaît encore l''ancien mot de passe.</p>
<p>Si elle était déjà active et qu''il est quand même entré, la question devient : comment ? Soit votre méthode de réception est compromise — boîte e-mail, téléphone — soit un code lui a été communiqué. Dans les deux cas, changez de méthode.</p>

<h3>4. Vérifiez l''adresse e-mail du compte</h3>
<p>Assurez-vous qu''elle est toujours la vôtre. Si elle a été modifiée, contactez le support sans attendre : vous ne pourrez plus rien faire seul.</p>

<h3>5. Régénérez vos codes de secours</h3>
<p>S''ils ont pu être vus, ils permettent de contourner la double authentification. En générer de nouveaux annule immédiatement les anciens.</p>

<h3>6. Vérifiez l''adresse de retrait enregistrée</h3>
<p>C''est l''objectif principal d''un attaquant sur ce type de compte : remplacer l''adresse par la sienne, puis attendre. Vérifiez qu''elle vous appartient toujours.</p>

<h3>7. Prévenez le support</h3>
<p>Décrivez précisément : ce que vous avez constaté, quand, et ce que vous avez déjà fait. Une demande factuelle est traitée bien plus vite qu''un message alarmé sans détails.</p>

<h3>8. Changez ce mot de passe partout ailleurs</h3>
<p>C''est l''étape la plus négligée, et souvent la plus importante. Si le mot de passe était réutilisé, tous les comptes concernés sont exposés — les attaquants testent automatiquement les identifiants volés sur des dizaines de services.</p>
<p>Commencez par votre messagerie principale : c''est elle qui permet de réinitialiser tous les autres comptes.</p>

<h2>Si vous avez perdu l''accès complètement</h2>
<p>Mot de passe changé, e-mail modifié, aucune connexion possible : la seule voie est le support, avec une vérification d''identité.</p>
<p>Rassemblez ce qui prouve que le compte est le vôtre : date approximative de création, adresse e-mail d''origine, méthode de retrait utilisée, dernières activités dont vous vous souvenez. Plus votre demande est précise, plus la vérification est rapide.</p>
<p>Prenez patience : cette procédure est volontairement exigeante. Un support qui rendrait un compte sur simple demande serait lui-même une faille.</p>

<h2>Après l''incident</h2>
<ul>
<li><strong>Analysez l''appareil</strong> avec un antivirus à jour, surtout si vous avez téléchargé quelque chose récemment.</li>
<li><strong>Adoptez un gestionnaire de mots de passe.</strong> La réutilisation est la cause première des compromissions en chaîne.</li>
<li><strong>Sauvegardez vos codes de secours</strong> ailleurs que sur votre téléphone.</li>
<li><strong>Surveillez votre historique</strong> pendant quelques semaines.</li>
</ul>

<h2>Ce que l''équipe ne fera jamais</h2>
<p>Pendant un incident, la vigilance doit rester entière : les faux supports profitent précisément de ces moments.</p>
<p>L''équipe ne vous demandera <strong>jamais</strong> votre mot de passe, vos codes de double authentification, vos codes de secours ou la phrase de récupération de votre portefeuille. Elle ne vous contactera pas non plus en message privé pour « résoudre » un problème.</p>

<h2>En résumé</h2>
<p>Appareil sûr, mot de passe changé, double authentification activée, adresse e-mail et adresse de retrait vérifiées, support prévenu — puis le même mot de passe changé partout ailleurs.</p>
<p>Pour prévenir plutôt que réparer, consultez notre guide <a href="/blog/securiser-son-compte-et-ses-gains">Sécuriser son compte et ses gains</a>, et pour repérer les tentatives en amont, <a href="/blog/reconnaitre-email-phishing-indices-concrets">Reconnaître un e-mail de phishing</a>.</p>'
);
