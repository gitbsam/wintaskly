# Raccourcisseur Wintaskly — schéma de conception

Document de validation. Rien n'est écrit tant que vous n'avez pas
tranché les points marqués **À CONFIRMER**.

Version visée : 9.42.0 — révisé après vos retours des étapes 1 à 2.3

---

## 1. Le parcours, tel que vous l'avez décrit

1. L'administration crée un shortlink dans `/admin/shortlinks.php` et
   génère sa clé API. Tant que la clé n'est pas désactivée, le
   shortlink apparaît aux utilisateurs dans `/tasks/shortlinks/`.
2. L'utilisateur clique sur le bouton du shortlink.
3. Le système génère un code de 10 caractères alphanumériques, crée le
   parcours et redirige vers `https://www.wintaskly.com/<code>`.
4. L'utilisateur enchaîne les étapes : article visible, publicités de
   la régie, chrono, bouton « Continuer — étape N/M ».
5. Dernière étape : écran de validation, chrono de 20 s, publicité,
   puis bouton de retour.
6. Retour à la page de récompenses. **Le parcours est supprimé.**
7. Retour sur `/<code>` → page d'erreur.

Le lien est donc **individuel et à usage unique**. Il n'existe aucun
lien public partagé : rien à diffuser sur Telegram, rien à cliquer par
quelqu'un qui n'est pas votre utilisateur.

---

## 2. Modèle de données

Principe retenu : **Wintaskly devient son propre prestataire.** Du point
de vue de `/tasks/shortlinks/`, le raccourcisseur local est un
fournisseur comme `shrinkme.io` ou `exe.io`. Aucune page de tâche à
modifier.

### 2.1 `shortlinks_local` — la configuration locale (nouvelle table)

Renseignée par le formulaire de génération de clé API, dans
`/admin/shortlinks.php`.

| Colonne | Type | Note |
|---|---|---|
| `id` | INT UNSIGNED | caché, auto |
| `title` | VARCHAR(120) | saisi en premier |
| `is_local` | TINYINT(1) | 1 = Wintaskly, 0 = prestataire externe |
| `api_key` | CHAR(32) | généré, avec bouton de copie |
| `api_active` | TINYINT(1) | désactivée → le lien disparaît des tâches |
| `steps_count` | SMALLINT UNSIGNED | défaut 3, jusqu'à 1000 |
| `step_seconds` | SMALLINT UNSIGNED | défaut 30 |
| `final_seconds` | SMALLINT UNSIGNED | défaut 20 |
| `content_type` | ENUM('blog','url') | auto |
| `content_ref` | VARCHAR(255) | auto |

`steps_count` en SMALLINT et non TINYINT : 1000 ne tient pas dans 255.

`reward_coins`, `cooldown_hours` et `provider_rate_*` **ne sont pas
ici** — ils restent dans `shortlinks`, comme vous l'avez demandé.

### 2.2 `shortlinks` — inchangée

L'administration y crée une ligne ordinaire :

```
mode          = api
api_endpoint  = https://www.wintaskly.com/api
api_token     = la clé générée dans shortlinks_local
reward_coins, cooldown_hours, provider_rate_*  = comme d'habitude
```

Rien à changer dans cette table ni dans `/tasks/shortlinks/`.

### 2.3 `shortlink_local_runs` — un parcours (nouvelle table)

Un utilisateur, un code, à usage unique. Séparée de
`shortlinks_local` parce qu'une campagne a un seul jeu de réglages
mais des dizaines de parcours simultanés : dans une seule table, deux
utilisateurs qui lancent le même lien écraseraient mutuellement leur
`code` et leur `step`.

| Colonne | Type | Note |
|---|---|---|
| `code` | VARBINARY(10) | UNIQUE. Binaire = sensible à la casse |
| `local_id` | INT UNSIGNED | vers `shortlinks_local` |
| `destination` | TEXT | l'URL de rappel reçue de notre propre système |
| `step` | SMALLINT UNSIGNED | étape courante, 0 au départ |
| `step_token` | CHAR(32) | jeton d'étape, à usage unique |
| `step_started_at` | DATETIME | départ du chrono, côté serveur |
| `step_expires_at` | DATETIME | abandon au-delà |
| `status` | ENUM | `en_cours`, `termine`, `expire`, `rejete` |

`step_token` et `step_started_at` sont les deux seules choses qui
empêchent un script d'enchaîner les étapes en une seconde. Le chrono du
navigateur n'est qu'un affichage.

### 2.4 `shortlink_country_rates` — tarifs par pays (nouvelle table)

| Colonne | Type |
|---|---|
| `country` | CHAR(2), clé primaire — `ZZ` = défaut |
| `rate_eur_per_1000` | DECIMAL(10,4) |
| `active` | TINYINT(1) |

## 3. Le code court

Alphabet `a-z A-Z 0-9`, **10 caractères**, tiré avec `random_int()` —
jamais `rand()` ni `uniqid()`, qui sont prévisibles.

| Longueur | Combinaisons | Collision à 1 M de liens | à 10 M |
|---|---|---|---|
| 9 | 13 537 086 546 263 552 | 3,7 × 10⁻⁵ | 3,7 × 10⁻³ |
| **10** | **839 299 365 868 340 224** | **6,0 × 10⁻⁷** | **6,0 × 10⁻⁵** |

Dix caractères divisent le risque par 62. Index unique et reprise sur
collision malgré tout : une collision silencieuse paierait le mauvais
utilisateur, et le coût du contrôle est nul.

**Colonne en `VARBINARY(10)`.** En collation classique, MySQL
confondrait `phe735tge1` et `PHE735TGE1`, ce qui gaspillerait
l'essentiel de ce que la casse mixte apporte.

### Mots réservés : le problème disparaît

À 9 caractères, `dashboard` était un tirage possible. **Aucun dossier
racine ne fait 10 caractères**, et les fichiers racine portent tous une
extension, donc le point les exclut du motif. Plus de liste de mots
réservés à maintenir.

Les deux conditions `!-f` et `!-d` restent quand même dans la règle :
elles coûtent une vérification et protègent de tout dossier que vous
ajouteriez plus tard.

### Réécriture

```apache
# Code court : /<10 caractères> → gateway locale
# Le motif accepte 9 ou 10 : le générateur émet 10, mais si vous
# changez un jour de longueur, les liens déjà distribués continuent
# de fonctionner. Un lien mort est un utilisateur qui ne finit pas
# sa tâche et ne comprend pas pourquoi.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([A-Za-z0-9]{9,10})$ tasks/shortlinks/run.php?c=$1 [QSA,L]
```

À placer **après** les règles du blog et des campagnes, pour ne pas
capturer leurs URL.

---

## 4. Machine à états

```
        [clic sur le bouton]
                 │
                 ▼
   en_attente  step=0  code généré  expires_at = +30 min
                 │
                 ├── étape validée ──► step+1, step_token renouvelé
                 │      (contrôle serveur : temps écoulé ≥ step_seconds)
                 │
                 ├── step atteint steps_count ──► écran final (20 s)
                 │                                      │
                 │                                      ▼
                 │                              valide + crédit
                 │                              step_token vidé
                 │
                 ├── expires_at dépassé ──────► expire
                 │
                 └── jeton invalide / rejoué ─► rejete
```

Toute demande sur un code dont le statut n'est pas `en_attente`
renvoie la page d'erreur.

### Contrôles anti-automatisation

Ils sont le cœur du système, pas une finition. Une passerelle
multi-étapes qui paie est une cible.

1. `step_token` à usage unique, renouvelé à chaque étape. Rejouer un
   jeton consommé passe le parcours en `rejete`.
2. Temps écoulé vérifié **côté serveur** avec `step_started_at`. Le
   chrono du navigateur n'est qu'un affichage.
3. Code lié à `user_id` : le code d'un autre utilisateur renvoie la
   page d'erreur, jamais le contenu.
4. Étapes strictement séquentielles : `step` ne peut avancer que de 1.
5. Un seul parcours ouvert par utilisateur et par shortlink.
6. Plafonds : gain maximal par jour et par utilisateur,
   participations maximales par shortlink et par jour.

---

## 5. Le point d'entrée API

`/api/` répond au protocole que votre système utilise déjà pour
`exe.io` et `shrinkme.io` — `wt_shortlink_create_via_api()` dans
`includes/functions.php` :

```
GET https://www.wintaskly.com/api?api=<cle>&url=<destination>&format=json
→  { "status": "success", "shortenedUrl": "https://www.wintaskly.com/aB3xY9kL2p" }
```

Aucun code d'appel à écrire : la fonction existe et fonctionne.

### Le crédit est déjà écrit

`get_gateway_link.php` passe comme `url` sa propre adresse de rappel :

```
https://www.wintaskly.com/api/shortlink_callback.php?token=…&key=…
```

Notre API la stocke telle quelle comme destination du parcours. À la
fin de la dernière étape, on y redirige — et `shortlink_callback.php`
crédite, applique le délai d'attente et journalise, exactement comme
pour un prestataire externe. La récompense, le cooldown et
l'anti-rejeu n'ont pas à être réécrits.

### Le piège de l'appel en boucle locale

Notre serveur appellerait sa propre API en HTTP via cURL. Sur un
hébergement mutualisé, cet aller-retour échoue souvent : pare-feu
sortant, résolution DNS interne, ou simplement HTTP bloqué vers soi-même.

`wt_shortlink_create_via_api()` détectera donc que l'hôte de
`api_endpoint` est celui de `base_url`, et appellera la fonction de
création **directement**, sans passer par le réseau. Plus rapide, et
insensible à la configuration de LWS.

## 6. Économie

Le calculateur de `/admin/shortlinks.php` est déjà en place et
corrigé. Rappel du calcul, base 10 000 coins = 1 €&nbsp;:

```
recette 3,00 € / 1000 participations
  → 0,003 € par participation
  → 30 coins  ← seuil d'équilibre
```

En dessous de 30 coins de récompense, la marge s'affiche en vert ;
au-dessus, en rouge avec « perdant ».

Chaque étape est une page vue de plus, donc une impression de plus :
`steps_count` multiplie vos recettes. C'est pour ça qu'il est réglable
par shortlink — mais l'abandon croît avec le nombre d'étapes, et
au-delà de 3 ou 4 vous perdez plus d'utilisateurs que vous ne gagnez
d'impressions.

---

## 7. Revue de cohérence contre le code existant

Six hypothèses du schéma confrontées aux fichiers réels.

### Ce qui tient

| Vérifié | Résultat |
|---|---|
| `shortlink_callback.php` crédite, pose le cooldown, est idempotent | oui, lignes 158-190 |
| La tentative et son jeton existent avant l'appel API | oui, `gateway.php` ligne 65 |
| Le protocole `?api=&url=&format=json` est bien celui attendu | oui |
| La réponse attendue est `{status, shortenedUrl}` | oui |

### Fossé 1 — `callback_key` est obligatoire, sinon échec silencieux

`get_gateway_link.php` ligne 96 ne prend la voie API que si les
**trois** champs sont remplis :

```php
$row['mode'] === 'api' && $row['api_endpoint'] && $row['api_token']
    && !empty($row['callback_key'])
```

Sans `callback_key`, le lien retombe en mode manuel et utilise
`destination_url` — donc n'importe quoi sauf le parcours local. Aucun
message d'erreur : ça « marche », mais pas comme prévu.

**Conséquence :** le formulaire de clé API doit générer `api_key`
**et** `callback_key`, et créer la ligne `shortlinks` avec les deux.
Un contrôle bloquant à l'enregistrement, pas un avertissement.

### Fossé 2 — deux chronos qui s'additionnent

`gateway.php` impose déjà `gateway_seconds` avant même de produire le
lien court. Pour un lien local à 3 étapes :

```
passerelle      10 s
étape 1         30 s
étape 2         30 s
étape 3         30 s
écran final     20 s
                ─────
total          120 s
```

Les 10 secondes de passerelle n'apportent rien ici : la publicité est
déjà dans le parcours. **Régler `gateway_seconds` à 3** (le minimum
imposé par le code) sur les liens locaux, sinon vous ajoutez de
l'attente sans impression supplémentaire — et l'attente fait
abandonner.

### Règle — le formulaire ne porte jamais l'étape

Le `<form>` de chaque page contiendra des champs cachés. Un seul est
autorisé à décider quoi que ce soit : `step_token`. Le numéro d'étape,
lui, n'est jamais lu depuis le formulaire — il est lu en base.

La différence, sur le même utilisateur qui édite le formulaire avant
de l'envoyer :

| Envoi | Étape dans un champ caché | Étape en base + jeton |
|---|---|---|
| `step=3` d'emblée | crédité, 0 publicité vue | jeton invalide → rejeté |
| bon jeton | — | étape 1 validée |
| rejeu du même jeton | — | jeton invalide → rejeté |
| jeton suivant, immédiat | — | trop rapide → refusé |

Le formulaire peut afficher `2/3` pour l'utilisateur ; c'est de
l'affichage. Ce que le serveur accepte ne dépend que de trois choses
qu'il détient lui-même : la ligne du parcours, le jeton en cours, et
l'horodatage de début d'étape.

Un jeton consommé est immédiatement remplacé. Rejouer l'ancien passe
le parcours en `rejete`, ce qui rend la tentative visible plutôt que
silencieuse.

### Point de vigilance — ne jamais émettre la destination trop tôt

Chez un prestataire externe, l'URL de rappel est détenue par le
prestataire : l'utilisateur ne l'obtient qu'à la fin. Chez nous, elle
est dans notre propre table dès la première étape.

Elle ne doit apparaître dans aucune page, aucun attribut, aucune
réponse JSON avant validation serveur de la dernière étape. Sinon
l'utilisateur l'ouvre directement et se fait créditer sans avoir vu
une seule publicité — exactement ce que le parcours doit empêcher.

### Détail — `destination_url` est NOT NULL

Pour une ligne locale il n'y a pas d'URL finale connue à l'avance. On
y stockera l'adresse de l'API (`https://www.wintaskly.com/api`), qui
n'est jamais utilisée en mode API mais satisfait la contrainte.

### Détail — la clé existe en double

`shortlinks.api_token` est chiffré (`wt_decrypt` au moment de
l'usage), alors que `shortlinks_local.api_key` est en clair pour
pouvoir être affiché et copié. C'est la même valeur sous deux formes.
Acceptable, mais toute rotation de clé doit toucher les deux lignes
dans la même transaction.

---

## 8. Reste à trancher

1. **Expiration d'un parcours abandonné.** Proposition : 30 minutes,
   réglable par shortlink. Sans elle, un utilisateur peut revenir des
   semaines plus tard terminer un parcours dont le tarif a changé.

2. **Tarifs par pays.** Quatre ou cinq paliers plus un défaut, ou un
   tarif unique pour démarrer ?
