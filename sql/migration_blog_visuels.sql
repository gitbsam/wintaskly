-- ============================================================================
-- Wintaskly — Schémas explicatifs et encadrés dans les articles
-- ============================================================================
-- CONSTAT
-- -------
-- Les 58 articles ne contenaient AUCUNE image, aucun schéma, aucun encadré.
-- Du texte pur, du début à la fin. C'est lisible, mais visuellement pauvre —
-- et sur des explications de mécanismes (circuit de l'argent, suivi de
-- conversion), un schéma transmet en un coup d'œil ce que trois paragraphes
-- peinent à faire passer.
--
-- POURQUOI DU SVG PLUTÔT QUE DES IMAGES
-- -------------------------------------
--   • Aucun fichier à héberger, à compresser ou à sauvegarder.
--   • Le schéma utilise les variables de thème : il suit le mode clair et
--     le mode sombre, au lieu d'être figé sur un fond blanc.
--   • Le texte du schéma reste du texte : lisible par un lecteur d'écran,
--     indexable, et net à toutes les tailles d'écran.
--   • Poids négligeable — quelques kilo-octets, sans requête réseau.
--
-- Cela ne remplace pas les captures d'écran de la plateforme, qui restent
-- à produire côté administrateur : une capture annotée d'une page réelle
-- vaut mieux qu'un schéma pour expliquer une interface.
--
-- ⚠️ Importer avec --default-character-set=utf8mb4.
-- ============================================================================

-- ---------------------------------------------------------------------
-- 1) Circuit de l'argent — article « Pourquoi ces plateformes peuvent
--    vous payer ». C'est le schéma le plus utile du blog : il répond
--    visuellement à la question que tout visiteur méfiant se pose.
-- ---------------------------------------------------------------------
UPDATE `blog_posts`
   SET `body` = REPLACE(`body`,
'<h2>Ce que la plateforme garde, et pourquoi</h2>',
'<figure>
  <div class="wt-schema">
    <svg viewBox="0 0 700 190" role="img" aria-labelledby="sch-flux-t sch-flux-d">
      <title id="sch-flux-t">Circuit de l''argent, de l''annonceur au membre</title>
      <desc id="sch-flux-d">Un annonceur verse un budget à un intermédiaire, qui reverse une part à la plateforme, laquelle en reverse une part au membre.</desc>
      <defs>
        <marker id="fa" markerWidth="9" markerHeight="7" refX="8" refY="3.5" orient="auto">
          <polygon points="0 0, 9 3.5, 0 7" fill="currentColor" opacity=".45"/>
        </marker>
      </defs>
      <g fill="none" stroke="currentColor" stroke-width="1.6" opacity=".45" marker-end="url(#fa)">
        <line x1="152" y1="70" x2="196" y2="70"/>
        <line x1="332" y1="70" x2="376" y2="70"/>
        <line x1="512" y1="70" x2="556" y2="70"/>
      </g>
      <g font-size="13" text-anchor="middle">
        <rect x="10" y="40" width="142" height="60" rx="10" fill="var(--wt-bg-soft, rgba(127,127,127,.08))" stroke="currentColor" stroke-opacity=".25"/>
        <text x="81" y="66" font-weight="600">Annonceur</text>
        <text x="81" y="86" font-size="11" opacity=".7">verse un budget</text>

        <rect x="196" y="40" width="136" height="60" rx="10" fill="var(--wt-bg-soft, rgba(127,127,127,.08))" stroke="currentColor" stroke-opacity=".25"/>
        <text x="264" y="66" font-weight="600">Intermédiaire</text>
        <text x="264" y="86" font-size="11" opacity=".7">régie, mur d''offres</text>

        <rect x="376" y="40" width="136" height="60" rx="10" fill="var(--wt-bg-soft, rgba(127,127,127,.08))" stroke="var(--wt-accent)" stroke-opacity=".55"/>
        <text x="444" y="66" font-weight="600">Wintaskly</text>
        <text x="444" y="86" font-size="11" opacity=".7">garde une part</text>

        <rect x="556" y="40" width="134" height="60" rx="10" fill="var(--wt-bg-soft, rgba(127,127,127,.08))" stroke="var(--wt-success)" stroke-opacity=".55"/>
        <text x="623" y="66" font-weight="600">Vous</text>
        <text x="623" y="86" font-size="11" opacity=".7">recevez des Coins</text>
      </g>
      <text x="350" y="140" text-anchor="middle" font-size="12" opacity=".65">
        Chaque étape prélève sa part : c''est pourquoi les montants sont faibles.
      </text>
      <text x="350" y="162" text-anchor="middle" font-size="12" opacity=".65">
        Rien ne provient des inscriptions — il n''y a ni dépôt, ni frais.
      </text>
    </svg>
  </div>
  <figcaption>Le budget publicitaire traverse plusieurs intermédiaires avant d''arriver sur votre solde. Chacun conserve une fraction, ce qui explique mécaniquement la taille des montants unitaires.</figcaption>
</figure>

<h2>Ce que la plateforme garde, et pourquoi</h2>')
 WHERE `slug` = 'pourquoi-ces-plateformes-peuvent-vous-payer';

-- ---------------------------------------------------------------------
-- 2) Suivi de conversion — les quatre étapes, et où ça casse.
--    L''article décrit un mécanisme invisible : le schéma le rend concret.
-- ---------------------------------------------------------------------
UPDATE `blog_posts`
   SET `body` = REPLACE(`body`,
'<h2>Pourquoi le suivi échoue</h2>',
'<figure>
  <div class="wt-schema">
    <svg viewBox="0 0 700 210" role="img" aria-labelledby="sch-conv-t sch-conv-d">
      <title id="sch-conv-t">Les quatre étapes du suivi de conversion</title>
      <desc id="sch-conv-d">Un identifiant est généré, transmis au partenaire, qui notifie la plateforme après validation, laquelle crédite le compte.</desc>
      <g font-size="12.5" text-anchor="middle">
        <circle cx="88" cy="52" r="21" fill="none" stroke="currentColor" stroke-opacity=".3"/>
        <text x="88" y="57" font-weight="600">1</text>
        <text x="88" y="96">Identifiant</text>
        <text x="88" y="113" font-size="11" opacity=".7">généré au clic</text>

        <circle cx="262" cy="52" r="21" fill="none" stroke="currentColor" stroke-opacity=".3"/>
        <text x="262" y="57" font-weight="600">2</text>
        <text x="262" y="96">Action</text>
        <text x="262" y="113" font-size="11" opacity=".7">chez le partenaire</text>

        <circle cx="436" cy="52" r="21" fill="none" stroke="currentColor" stroke-opacity=".3"/>
        <text x="436" y="57" font-weight="600">3</text>
        <text x="436" y="96">Notification</text>
        <text x="436" y="113" font-size="11" opacity=".7">serveur à serveur</text>

        <circle cx="610" cy="52" r="21" fill="none" stroke="var(--wt-success)" stroke-opacity=".6"/>
        <text x="610" y="57" font-weight="600">4</text>
        <text x="610" y="96">Crédit</text>
        <text x="610" y="113" font-size="11" opacity=".7">sur votre solde</text>
      </g>
      <g stroke="currentColor" stroke-width="1.5" opacity=".35" stroke-dasharray="4 4">
        <line x1="112" y1="52" x2="238" y2="52"/>
        <line x1="286" y1="52" x2="412" y2="52"/>
        <line x1="460" y1="52" x2="586" y2="52"/>
      </g>
      <text x="350" y="158" text-anchor="middle" font-size="12" opacity=".75" font-weight="600">
        Le maillon fragile est l''étape 1
      </text>
      <text x="350" y="180" text-anchor="middle" font-size="11.5" opacity=".65">
        Tâches en parallèle, changement d''appareil, lien non utilisé, bloqueur actif :
      </text>
      <text x="350" y="197" text-anchor="middle" font-size="11.5" opacity=".65">
        l''identifiant se perd, et plus rien ne relie votre action à votre compte.
      </text>
    </svg>
  </div>
  <figcaption>La notification de l''étape 3 ne passe pas par votre navigateur : elle est invisible pour vous, y compris lorsqu''elle n''arrive jamais.</figcaption>
</figure>

<h2>Pourquoi le suivi échoue</h2>')
 WHERE `slug` = 'comprendre-le-suivi-des-conversions';

-- ---------------------------------------------------------------------
-- 3) Encadrés — premiers exemples, sur les points où une mise en garde
--    mérite d''être détachée du flux de lecture.
-- ---------------------------------------------------------------------
UPDATE `blog_posts`
   SET `body` = REPLACE(`body`,
'<h2>Le test qui résume tout</h2>',
'<div class="wt-callout wt-callout--tip">
  <span class="wt-callout__icon" aria-hidden="true">💡</span>
  <div class="wt-callout__body">
    <strong>Le test le plus rapide</strong>
    <p>Avant d''investir des semaines sur une plateforme, envoyez une question factuelle à son support. Pas de réponse sous quelques jours ? Imaginez ce que ce sera quand un paiement sera en jeu.</p>
  </div>
</div>

<h2>Le test qui résume tout</h2>')
 WHERE `slug` = 'signaux-alerte-plateforme-micro-gains-douteuse';

UPDATE `blog_posts`
   SET `body` = REPLACE(`body`,
'<h2>Les erreurs qui coûtent définitivement</h2>',
'<div class="wt-callout wt-callout--warn">
  <span class="wt-callout__icon" aria-hidden="true">⚠️</span>
  <div class="wt-callout__body">
    <strong>Aucun recours n''existe</strong>
    <p>Contrairement à un virement bancaire, une transaction en cryptomonnaie ne se rappelle pas. Ni la plateforme, ni le réseau, ni un support ne peuvent l''annuler. Les erreurs décrites ci-dessous sont définitives.</p>
  </div>
</div>

<h2>Les erreurs qui coûtent définitivement</h2>')
 WHERE `slug` = 'la-crypto-expliquee-sans-jargon';
