# Wintaskly — mise en production 9.30.0 → 9.44.0

Audit complet de la session. Votre production tourne la **9.30.0** ;
rien de ce qui suit n'y est encore.

---

## 1. Ce que l'audit a vérifié

| Contrôle | Résultat |
|---|---|
| Lint PHP (186 fichiers) et JS (11 fichiers) | propre |
| Base neuve depuis `schema.sql`, importée deux fois | 59 tables, aucune erreur |
| Parité des traductions FR / EN | 3 206 clés de chaque côté |
| Tâches cron enregistrées | 7 |
| Non-régression, 20 contrôles | 0 échec |

Vos deux paiements réels servent de référence :
41 581 coins → 0,00007277 BTC et 31 090 coins → 0,00005441 BTC.
Les deux sont reproduits exactement par le code livré.

---

## 2. Le danger que cet audit a écarté

`admin/payment_methods.php` multipliait `coins_per_unit` par le cours
pour les cryptos (10 000 × 57 141 = 571 417 200 pour le bitcoin). Or la
formule de paiement applique déjà le cours :

```
payout = (coins / coins_per_unit) / cours
```

Le cours aurait donc été appliqué **deux fois**. Effet sur un retrait
réel :

| | `coins_per_unit` | 41 581 coins donnent |
|---|---|---|
| aujourd'hui | 10 000 | 0,00007277 BTC |
| après un enregistrement de méthode | 571 417 200 | 0,0000000013 BTC |

Soit 57 140 fois moins. Le bug était **latent** : `$rateInEur` retombe
sur 1.0 quand `rates_cache.json` est absent, ce qui neutralisait la
multiplication. Il se serait déclenché au premier enregistrement d'une
méthode crypto avec un cache présent.

La multiplication est retirée. `coins_per_unit` reste ce que vous
saisissez — 10 000 coins par euro — et le cours est appliqué au
moment du retrait, à partir de `rates_cache.json` que la tâche
`rates_refresh` tient à jour. Vos montants suivent donc le marché sans
que personne ne touche à `coins_per_unit`.

La tâche `payment_methods_rates`, que j'avais écrite pour rafraîchir
`coins_per_unit`, produisait exactement la même destruction. Elle est
supprimée.

---

## 3. Ordre de déploiement

### 3.1 Avant tout

Sauvegardez la base. Toute la suite est réversible sauf ça.

### 3.2 Envoyer les fichiers

Ne téléversez pas `config.php` ni `.installed.lock` — ils ne sont pas
dans l'archive, c'est voulu.

### 3.3 Vérifier `config.php`

```php
$_baseUrl = 'https://www.wintaskly.com';
```

C'est cette ligne qui pilote la redirection du domaine nu vers `www`.
Sans elle sur `www`, la redirection partirait dans le mauvais sens et
casserait votre vérification HilltopAds.

### 3.4 Réimporter `sql/schema.sql`

Idempotent, rejouable. Il apporte :

- `shortlinks_local`, `shortlink_local_runs` (raccourcisseur maison)
- `revenue_entries` (registre des recettes)
- `withdrawal_methods.rate_updated_at`
- la zone publicitaire `tasks_overlay`
- les méthodes PayPal et SEPA (inactives tant que vous ne les activez pas)
- le `size_key` manquant de vos zones publicitaires — c'est lui qui
  débloquera l'accueil et le blog

Vous pouvez aussi passer par `/admin/migrations.php`, nouvelle page.

### 3.5 Vider le cache navigateur

Le CSS et le JS sont versionnés par `WT_VERSION`, mais un cache
agressif peut retenir l'ancienne feuille.

---

## 4. À contrôler juste après

| Où | Quoi |
|---|---|
| `https://wintaskly.com` | doit rediriger vers `www` et afficher le CSS |
| `/admin/diagnostic.php` | section « Droits d'écriture » : `includes/` doit être inscriptible |
| `/admin/ads-check.php` | l'accueil et le blog doivent passer de « Vide » à « Visuel par défaut » |
| `/dashboard/withdraw.php` | avec une adresse enregistrée, la conversion doit se faire en direct |
| Un retrait test | 10 000 coins doivent donner 0,0000175 BTC |

Le dernier point est le plus important. S'il donne autre chose,
arrêtez et dites-le moi.

---

## 5. Réglages qui restent à faire à la main

1. **Ligne HilltopAds** dans `/admin/ad-networks.php` :
   `https://juvenilechoice.com https://*.juvenilechoice.com` dans
   `script_domains`, `connect_domains` et `frame_domains`. Les deux
   formes : le joker seul ne couvre pas le domaine nu.
2. **Zone In-Page Push** à créer chez HilltopAds, puis coller son code
   dans la zone `tasks_overlay`.
3. **`gateway_seconds` à 3** sur les liens locaux : la publicité est
   déjà dans le parcours, les 10 secondes de passerelle n'ajoutent
   aucune impression et font abandonner.
4. **Minimums PayPal et SEPA** : j'ai mis 3 € et 20 €, à ajuster selon
   vos frais réels avant de les activer.

---

## 6. Ce qui n'est pas construit

- Tarifs par pays (`shortlink_country_rates`) — tarif unique pour l'instant
- Choix de l'article du parcours — le dernier publié est pris par défaut
- Nettoyage automatique des parcours abandonnés
- Plafonds anti-fraude : gain maximal par jour, participations par lien

---

## 7. Deux points hors technique

**Votre FAQ** annonce « PayPal, virement bancaire (Wise),
cryptomonnaies (BTC, ETH, USDT) et mobile money » alors que deux
méthodes FaucetPay sont actives. Un utilisateur qui s'inscrit pour
PayPal et ne le trouve pas au retrait, c'est un litige évitable.

**Votre accueil** affiche deux compteurs contradictoires : « 0 membres
actifs » en haut, « 1 260 membres actifs » plus bas. Sur un site dont
l'argument est la transparence, mieux vaut un vrai petit chiffre qu'un
grand chiffre faux.
