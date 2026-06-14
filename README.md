# Wintaskly

Plateforme Get-Paid-To (GPT) en PHP natif, MySQLi, Vanilla JS et Tailwind.
Faucet anti-fraude (3 h cooldown · fenêtre stricte 5 min · captcha visuel),
Shortlinks avec passerelle interne, système de parrainage 10 % à vie,
back-office complet et interface bilingue (FR/EN, thèmes clair / sombre).

## Stack

| Couche       | Choix techniques                                                  |
|--------------|-------------------------------------------------------------------|
| Serveur      | PHP 8.1+ natif (sans framework), MySQLi (préparé partout)         |
| Base         | MySQL 5.7+ / MariaDB 10.4+, InnoDB, utf8mb4, **datetime UTC strict** |
| Front        | Tailwind v3 (compilé) + CSS maison (variables, thèmes)            |
| JS           | ES6+ vanilla, deux fichiers (`wintaskly-ui.js`, `wintaskly.js`)   |
| Fontes       | Bricolage Grotesque · Manrope · JetBrains Mono                    |
| Sécurité     | bcrypt, CSRF tokens, prepared statements, session HttpOnly/SameSite, security headers |

## Arborescence

```
wintaskly/
├── index.php              Page d'accueil (hero + stats + feed + how)
├── header.php / footer.php
├── config.example.php     Modèle de configuration (à copier en config.php)
├── input.css              Source Tailwind
├── tailwind.config.js
├── package.json
├── .htaccess              Apache : sécurité + cache assets
│
├── api/                   Endpoints Ajax / callbacks
│   ├── faucet_start.php       (POST  · démarre une session 5 min)
│   ├── faucet_validate.php    (POST  · valide le captcha + crédite)
│   ├── set_timezone.php       (POST  · stocke le TZ client)
│   └── shortlink_callback.php (GET   · postback provider)
│
├── auth/
│   ├── login.php · register.php · logout.php
│
├── dashboard/
│   └── index.php          Solde, XP, lien parrainage, historique
│
├── tasks/
│   ├── faucet/
│   │   ├── index.php          Étape 1 — carrefour + cooldown 3 h
│   │   ├── transition.php     Étape 2 — pubs + décompte
│   │   └── verify.php         Étape 3 — captcha visuel + claim
│   └── shortlinks/
│       ├── index.php          Liste + cooldown par utilisateur
│       └── gateway.php        Passerelle interne 10 s + ad
│
├── admin/                 Back-office (require_admin)
│   ├── index.php          KPIs 7 jours
│   ├── faucet.php         Récompense / cooldown / TTL
│   ├── shortlinks.php     CRUD complet
│   └── homepage.php       Blocs + stats affichées
│
├── legal/
│   ├── cgu.php · privacy.php
│
├── includes/              Cœur applicatif
│   ├── init.php           Bootstrap (config, session, sécurité, i18n)
│   ├── db.php             mysqli + cfg()
│   ├── auth.php           current_user, require_auth/admin, CSRF, helpers
│   ├── functions.php      award_user (+ commission 10 %), flag_cheat, xp_progress
│   ├── i18n.php           t(), wt_detect_lang()
│   └── lang/fr.php · en.php
│
├── media/
│   ├── tailwind/css/tailwind.css     ← compilé
│   └── wintaskly/
│       ├── css/wintaskly.css         Design system, thèmes
│       ├── css/wintaskly-animations.css
│       └── js/wintaskly-ui.js · wintaskly.js
│
└── sql/schema.sql         Schéma complet + données de seed
```

## Installation

### 1. Base de données

Crée la base puis importe le schéma :

```bash
mysql -u root -p < sql/schema.sql
```

Le script crée la base `wintaskly`, toutes les tables, les valeurs de
configuration par défaut, les icônes du captcha, les emplacements pub et
un **compte admin** :

| Champ        | Valeur                          |
|--------------|---------------------------------|
| Utilisateur  | `admin`                         |
| E-mail       | `admin@wintaskly.local`         |
| Mot de passe | `ChangeMeNow!2026` *(à changer dès la première connexion)* |

### 2. Configuration

```bash
cp config.example.php config.php
```

Édite ensuite `config.php` :

```php
return [
    'db' => [
        'host' => 'localhost',
        'user' => 'wintaskly',
        'pass' => '••••••••',
        'name' => 'wintaskly',
        'port' => 3306,
    ],
    'app_secret'    => 'CHANGE_ME_64_HEX_CHARS',
    'base_url'      => 'https://wintaskly.example.com',
    'languages'     => ['fr', 'en'],
    'default_lang'  => 'fr',
    'default_theme' => 'dark',
    'cookie_secure' => true,   // false en local
    'debug'         => false,
];
```

### 3. Tailwind

Le projet livre un `tailwind.css` déjà compilé dans
`media/tailwind/css/`. Pour le régénérer (après modification du design) :

```bash
npm install
npm run build:css        # compile + minify
npm run watch:css        # mode développement
```

### 4. Apache / Nginx

Pour Apache, le `.htaccess` est prêt : il bloque l'accès aux dossiers
`includes/`, `sql/`, `node_modules/`, désactive PHP dans `media/`,
ajoute le cache et la compression.

Pour Nginx, équivalents :

```nginx
location ~ ^/(includes|sql|node_modules)(/|$) { return 403; }
location ~ /\.(env|git|md|sql)$              { return 403; }
location /media/ { try_files $uri =404; }     # pas de PHP ici
```

## Architecture du Faucet (anti-cheat)

```
[ /tasks/faucet/ ]  ────POST /api/faucet_start.php────►  insertion faucet_sessions
   3h cooldown                                            (token SHA-256, exp +300s)
        │                                                       │
        ▼                                                       │
[ /tasks/faucet/transition.php?t=… ]  ◄────────── token vérifié ┘
   pubs + décompte 10-15s
        │
        ▼
[ /tasks/faucet/verify.php?t=… ]
   ad 300×250 + checkbox + captcha 5 icônes + honeypot
        │
        ▼  POST /api/faucet_validate.php  (12 contrôles atomiques)
        ├─ CSRF, auth, token+user
        ├─ session FOR UPDATE (anti-replay)
        ├─ honeypot rempli → flag_cheat + 403
        ├─ checkbox cochée
        ├─ slug captcha == target (hash_equals)
        ├─ NOW ≤ expires_at (5 min strict)
        ├─ délai ≥ 8 s (anti-bot rapide)
        ├─ marque status='consumed' atomique
        ├─ award_user(coins, xp)  → +10 % au parrain
        └─ insert faucet_claims (next_claim_at = NOW + 3h)
```

Datetime serveur **toujours UTC** (`date_default_timezone_set('UTC')`
+ `SET time_zone = '+00:00'` MySQL). Le fuseau client est stocké dans
le cookie `wt_tz` et appliqué uniquement à l'affichage (composant
`[data-fmt-time]`).

## Parrainage

- Chaque utilisateur a un `referral_code` unique de 16 caractères.
- Le lien `/auth/register.php?ref=CODE` stocke le code dans un cookie
  pendant 30 jours.
- Au crédit (`award_user`), si l'utilisateur a un `referrer_id`, une
  ligne `referral_earnings` est créée + une transaction de type
  `referral` au parrain : **10 % du gain, sans réduire le filleul**.

## Back-office

- `/admin/` (auth + role=admin requis)
- KPIs sur 7 jours (utilisateurs, claims, shortlinks complétés, coins).
- Édition des paramètres Faucet, CRUD shortlinks, blocs homepage,
  statistiques publiques affichées.

## Sécurité — résumé

- `password_hash` bcrypt, `password_verify` avec `hash_equals` côté tokens.
- CSRF systématique via `csrf_token()` / `csrf_check()` (session-scoped).
- Sessions : `HttpOnly`, `SameSite=Lax`, regen à la connexion / inscription.
- IPs stockées en `VARBINARY(16)` (`inet_pton`).
- Préparations partout (placeholders `?`, `bind_param`).
- Headers : `X-Content-Type-Options`, `Referrer-Policy`,
  `Permissions-Policy`, `X-Frame-Options=SAMEORIGIN`.
- Bans : enregistrés en table `bans`, vérifiés à chaque init.

## Légal

Pages `legal/cgu.php` et `legal/privacy.php` rédigées pour un contexte
**France / UE** : RGPD, durée de conservation 13 mois CNIL, mention des
règles AdSense, statut clair des Coins (pas une monnaie électronique
au sens de la directive 2009/110/CE).

## V2 — Modules étendus

La version 2 ajoute quatre modules majeurs. Pour passer d'une install
existante : `mysql -u root -p wintaskly < sql/migration_v2.sql` (les
fresh installs prennent tout via `schema.sql`).

### PTC (Paid To Click) — `/tasks/ptc/`

L'utilisateur clique sur "Visiter l'annonce" → on appelle
`/api/ptc_start.php` qui pose un **verrou applicatif** : tant qu'une
ligne `ptc_sessions` est en `status='active'` pour cet utilisateur,
toute nouvelle demande est refusée (`SELECT … FOR UPDATE`). En parallèle
le front pose `sessionStorage.wt_ptc_running='1'`, ce qui grise toutes
les autres cartes PTC dans tous les onglets ouverts.

Le JS ouvre alors la cible avec `window.open(url, '_blank')` et lance
deux timers :

```
setInterval(tick,    1000ms) → décrémente le chrono, met à jour <title>
setInterval(watch,    500ms) → si partnerWin.closed && !ended → annule
```

`window.closed` reste lisible même après une redirection cross-origin :
c'est l'astuce qui rend la détection « onglet fermé trop tôt » possible.

À la fin du décompte, une modale (`backdrop-filter:blur`) propose un
mini-captcha 3-icônes. Validation via `/api/ptc_validate.php` :

```
1. CSRF + auth
2. SELECT FOR UPDATE sur ptc_sessions (token + user_id, status='active')
3. Vérifie expires_at ≥ NOW, sinon marque 'expired'
4. Vérifie elapsed ≥ duration-2s, sinon flag_cheat + 'rejected'
5. hash_equals(target, slug_choisi) — captcha
6. UPDATE status='consumed' atomique (anti-replay)
7. INSERT ptc_views (avec next_view_at = NOW + cooldown_hours)
8. award_user($id, coins, xp, 'ptc', …) → +10% au parrain
```

Si l'onglet est fermé trop tôt, le JS appelle `/api/ptc_cancel.php`
qui passe la session en `status='cancelled', reject_reason='tab_closed'`,
ce qui libère immédiatement le verrou côté serveur **et** côté client.

### Offerwalls — `/tasks/offerwalls/`

Grille de partenaires. Chaque partenaire a en base :

- `k` — clé interne identifiant le provider dans le postback
- `iframe_url` (ouverture intégrée, placeholders `{USER_ID}` / `{USERNAME}`)
  **ou** `redirect_url` (nouvel onglet)
- `callback_secret` — clé HMAC, **jamais** exposée au front

Quand l'utilisateur complète une offre, le provider envoie un
postback S2S vers `/api/callback_offerwall.php` :

```
GET /api/callback_offerwall.php?offerwall=wannads&user=1234&tx=AB99&amount=250.5&sig=…
```

avec `sig = hash_hmac('sha256', "wannads|1234|AB99|250.5", $callback_secret)`.
Vérification en temps constant via `hash_equals`. La table
`offerwall_transactions` a un index UNIQUE `(offerwall_id, external_tx_id)`
qui garantit l'**idempotence** : un même postback rejoué renvoie
`DUPLICATE` sans recréditer. Réponses normalisées en texte brut :
`OK | DUPLICATE | BAD_SIGNATURE | BAD_REQUEST | OFFERWALL_NOT_FOUND`.

### Parrainage — `/dashboard/referrals.php`

Outil marketing : lien copiable, boutons de partage X / Facebook /
Telegram / WhatsApp, et tableau des filleuls avec pseudo masqué
partiellement (`marie_dupont` → `ma••••ont`) + total des commissions
générées (somme `referral_earnings` toutes sources confondues —
Faucet, Shortlinks, PTC, Offerwalls).

### Retraits — `/dashboard/withdraw.php`

Méthodes seed par défaut : **FaucetPay, Payeer, BTC, LTC**. Chaque
méthode définit son ratio (`coins_per_unit`), son minimum, son label
d'adresse et sa devise. Le JS recalcule le payout en temps réel à
chaque saisie ou changement de méthode.

`/api/withdraw_submit.php` opère en transaction atomique :

```
1. SELECT FOR UPDATE sur la méthode et sur l'utilisateur
2. Bornes min/max + solde + statut compte vérifiés
3. UPDATE users.coins -= amount
4. INSERT withdrawals (status='pending')
5. INSERT transactions (type='withdraw', coins négatif)
6. COMMIT
```

Modération admin via `/admin/withdrawals.php` :

- **Complète** → simple update `status='completed'` (l'admin a déjà
  exécuté le paiement manuellement ou via API externe).
- **Refuse** → motif obligatoire (≤240 char), `status='refused'`,
  **re-crédite atomiquement** `users.coins += coins_amount` et insère
  une transaction `type='admin'` de remboursement. Le motif s'affiche
  ensuite dans l'historique de l'utilisateur.

### Admin étendu

Trois nouveaux écrans, accessibles depuis la sidebar `_nav.php` :

- `/admin/ptc.php` — CRUD des annonces PTC (titre, URL, durée,
  récompense, limite quotidienne, cooldown).
- `/admin/offerwalls.php` — CRUD des partenaires, secrets, modes
  d'ouverture, plus une bannière d'aide indiquant l'URL et le format
  exact du postback.
- `/admin/withdrawals.php` — file de modération avec filtres par
  statut (pending / completed / refused / all), actions inline et
  formulaire de refus à motif obligatoire.

## V3 — Hub d'authentification

La version 3 introduit un module d'authentification complet avec
vérification d'e-mail, réinitialisation de mot de passe, « remember-me »
sécurisé, 2FA TOTP, rate-limiting et moteur d'expédition de courriels
adaptatif (Gmail en dev, PHPMailer en prod).

### Migration

```bash
mysql -u root -p wintaskly < sql/migration_v3.sql
```

La migration :
- étend `users.status` pour accepter `'pending'` ;
- ajoute `users.email_verified_at`, `users.totp_secret`, `users.totp_enabled` ;
- crée `auth_tokens` (vérification e-mail, reset, remember-me) ;
- crée `auth_attempts` (rate-limit IP + compte) ;
- marque les utilisateurs déjà actifs comme « déjà vérifiés ».

### Routage `/auth/`

| Route                            | Rôle                                              |
| -------------------------------- | ------------------------------------------------- |
| `/auth/`                         | 301 → `/auth/login.php`                           |
| `/auth/login.php`                | Connexion Ajax + remember-me + bascule 2FA       |
| `/auth/signup.php`               | Inscription Ajax avec jauge de force + honeypot   |
| `/auth/register.php`             | 301 → `/auth/signup.php` (rétro-compat)           |
| `/auth/verify-email.php`         | Validation token ou page d'attente avec resend    |
| `/auth/forgot-password.php`      | Demande de réinitialisation                       |
| `/auth/reset-password.php`       | Saisie du nouveau mot de passe                    |
| `/auth/verify-2fa.php`           | Saisie du code TOTP à 6 chiffres                  |
| `/auth/logout.php`               | Déconnexion + révocation remember-me              |

### Modèle de jetons

Tous les jetons (verify-email, reset-password, remember-me) suivent le
même format `<selector>.<verifier>` :

```
selector = 16 caractères hex (clé publique de lookup, indexée UNIQUE)
verifier = 64 caractères hex (secret)
```

Seul `sha256(verifier)` est stocké en base (`auth_tokens.token_hash`).
Le jeton brut n'existe que dans l'URL (verify-email, reset) ou le
cookie HttpOnly+Secure (remember-me). Vérification en temps constant
via `hash_equals`. Le jeton remember-me est **rotaté** à chaque
auto-connexion réussie pour limiter la fenêtre d'exploitation d'un
cookie volé.

### Rate-limit anti-brute-force

Stocké dans `auth_attempts` (identifier + IP + succès/échec). Seuils
configurables dans `config.php` (`auth` block) :

```php
'max_attempts_per_account' => 5,
'max_attempts_per_ip'      => 15,
'lockout_minutes'          => 15,
```

Le compteur côté compte est borné aux échecs **depuis la dernière
réussite** : une connexion correcte remet le compteur à zéro.

### 2FA TOTP

Implémentation RFC 6238 compatible Google Authenticator :
- secret stocké en Base32 (`users.totp_secret`, VARCHAR(32))
- période 30 s, code 6 chiffres, HMAC-SHA1
- tolérance ±1 fenêtre pour amortir la dérive d'horloge
- vérification en temps constant via `hash_equals`

L'enrôlement (génération du secret + QR code) n'est pas fourni dans
cette release : tu peux poser `totp_secret` manuellement en base et
basculer `totp_enabled = 1` pour activer la 2FA sur un compte test.

### Moteur d'expédition (includes/mailer.php)

Le mailer détecte automatiquement Composer + PHPMailer s'ils sont
installés (`vendor/autoload.php` + classe `PHPMailer\PHPMailer\PHPMailer`).
Sinon il bascule sur un **client SMTP autonome** intégré qui parle
RFC 5321 + STARTTLS + AUTH LOGIN — suffisant pour Gmail et la
plupart des relais pros.

Pour activer PHPMailer (recommandé en prod) :

```bash
composer require phpmailer/phpmailer
```

Bascule dev ↔ prod dans `config.php` :

```php
'environment' => 'development',  // ou 'production'

'mail' => [
    'from'      => 'no-reply@wintaskly.example.com',
    'from_name' => 'Wintaskly',
    'reply_to'  => 'support@wintaskly.example.com',
    'dev'  => ['host'=>'smtp.gmail.com', 'port'=>587, 'user'=>'…@gmail.com',
               'pass'=>'app password 16 chars', 'tls'=>true],
    'prod' => ['host'=>'smtp.mailgun.org', 'port'=>587, 'user'=>'…',
               'pass'=>'…', 'tls'=>true],
],
```

Le rendu HTML utilise un layout table-based 600 px optimisé pour
Outlook/Gmail/Apple Mail, avec un pré-header invisible, un CTA
arrondi par couleur d'accent selon le type de mail (bleu pour
verify, or pour reset, rouge pour alert), un encadré code monospace
pour les OTP éventuels, et un footer avec les liens CGU + Privacy.
Texte brut généré en parallèle (multipart/alternative).

### Cycle de l'inscription

```
1) POST /api/auth_signup.php
   → honeypot check, CGU check, regex validation, unicité
   → INSERT users (status='pending')
   → auth_token_create($uid, 'verify_email', 24h)
   → wt_mail($email, 'verify_email', [link])
   → SESSION['pending_verify_email'] = $email
   → JSON {ok:true, redirect:'/auth/verify-email.php'}

2) L'utilisateur clique sur le lien dans l'e-mail
   → GET /auth/verify-email.php?token=<selector>.<verifier>
   → auth_token_consume() : valide expires_at + hash_equals + used_at
   → UPDATE users SET status='active', email_verified_at=NOW
   → session_regenerate_id + $_SESSION['uid'] = $uid
   → 302 /dashboard/?welcome=1
```

### Cycle du reset password

```
1) POST /api/auth_forgot.php  → réponse TOUJOURS générique
   (anti-énumération : impossible de savoir si l'e-mail existe)
   → si l'utilisateur existe, on génère un token 1h et on envoie

2) GET /auth/reset-password.php?token=…
   → auth_token_peek() valide SANS consommer (sinon F5 = perte)
   → si invalide → redirect /auth/forgot-password.php

3) POST /api/auth_reset.php
   → auth_token_consume() (one-shot)
   → password_hash($pass, PASSWORD_DEFAULT)
   → auth_tokens_revoke($uid, 'remember_me')  ← révoque tous les cookies
   → auto-connexion + 302 /dashboard/
```

### Cycle de la 2FA

```
POST /api/auth_login.php
   → password OK + totp_enabled=1
   → $_SESSION['pending_2fa_uid'] = $uid  (PAS encore 'uid' connecté)
   → JSON {ok:true, two_factor_required:true, redirect:'/auth/verify-2fa.php'}

POST /api/auth_verify_2fa.php
   → auth_totp_verify($secret, $code)  RFC 6238 ±1 window
   → si OK : session_regenerate_id + $_SESSION['uid'] = $pending
   → applique remember-me si demandé au login
```

### Honeypot

La page signup expose 2 champs `website` et `phone_number` masqués
par `.wt-honey { position:absolute; left:-9999px; opacity:0; }`. Un
humain ne les voit jamais ; un bot les remplit. À la réception, si
l'un des deux est non vide → rejet silencieux.

## V4 — Témoignages, support guest-tracking, messagerie & TTL

La V4 ajoute la couche d'engagement social et de support : témoignages
modérés, hub d'aide avec FAQ et contact dual (membres ou visiteurs
anonymes via lien sécurisé), boîte de réception utilisateur,
notifications, et un Header dynamique avec drawer mobile coulissant.

### Migration

```bash
mysql -u root -p wintaskly < sql/migration_v4.sql
```

La migration :
- ajoute `users.avatar_url` (conditionnel via `INFORMATION_SCHEMA` pour
  rester compatible MySQL 5.7) ;
- crée `testimonials`, `support_tickets`, `support_messages`, `messages`,
  `notifications` ;
- seed les paramètres `cfg` : `testimonials.show_on_home`,
  `testimonials.home_limit`, `ttl.*` et `ttl.cleanup_probability`.

### Nouvelles routes publiques

| Route                                | Rôle                                                 |
| ------------------------------------ | ---------------------------------------------------- |
| `/testimonials/`                     | Liste publique + formulaire (membres actifs only)    |
| `/help/`                             | 301 → `/help/faq.php`                                |
| `/help/faq.php`                      | Accordéons FAQ depuis les clés `faq.q_*` / `faq.a_*` |
| `/help/contact.php`                  | Formulaire dual : connecté ou visiteur               |
| `/help/contact-track/<token>`        | Suivi anonyme du ticket via lien personnalisé        |

La pretty URL `/help/contact-track/<token>` repose sur cette règle
ajoutée à `.htaccess` :

```apache
RewriteRule ^help/contact-track/([a-f0-9]{32,64})/?$ \
    help/contact-track/index.php?token=$1 [QSA,L]
```

### Système Guest-Tracking

Quand un visiteur anonyme envoie le formulaire `/help/contact.php` :

1. Le serveur génère un token cryptographique de 48 hex
   (`bin2hex(random_bytes(24))`) stocké en UNIQUE dans
   `support_tickets.guest_token`.
2. Le visiteur reçoit immédiatement la notification :
   _« Merci pour ta préoccupation, l'équipe te répondra très vite.
   Conserve précieusement le lien de suivi ci-dessous… »_
3. Un e-mail HTML (template `security_alert`) contenant le lien est
   également envoyé pour ne pas perdre l'accès.
4. Le visiteur peut consulter et répondre dans son fil sans jamais
   créer de compte. L'URL contient l'unique secret partagé.

### Messagerie & Notifications éphémères (TTL)

- `messages` : envoyés par l'admin (ou le système) à un utilisateur,
  lecture à l'ouverture de l'accordéon (API `/api/message_read.php`).
- `notifications` : alertes courtes, automatiquement marquées lues
  à l'ouverture de la page `/dashboard/notifications.php`.
- Suppression unitaire et **groupée** (bulk delete) côté utilisateur
  via `/api/message_delete.php` et `/api/notification_delete.php`.
- Politique TTL pilotée dans la table `config` :

| Clé                          | Défaut | Effet                                         |
| ---------------------------- | ------ | --------------------------------------------- |
| `ttl.message_read_days`      | 30     | Messages lus depuis +30 j → supprimés         |
| `ttl.message_unread_days`    | 90     | Messages non lus expirent au bout de 90 j     |
| `ttl.notif_read_days`        | 30     | Idem pour les notifications lues              |
| `ttl.notif_unread_days`      | 90     | Idem pour les notifications non lues          |
| `ttl.cleanup_probability`    | 0.02   | ~2 % des requêtes déclenchent le ménage       |

Le nettoyage est :
- **stochastique** : `wt_ttl_maybe_cleanup()` appelé dans `init.php`
  exécute `wt_ttl_cleanup()` selon la probabilité configurée ;
- **manuel** : bouton "Lancer le nettoyage TTL maintenant" dans
  `/admin/messages.php` ;
- **complet** : il purge aussi les `auth_tokens` expirés ou consommés
  depuis +7 j, et les `auth_attempts` plus vieux que 30 j.

### Refonte du Header (V4)

Le coin supérieur droit s'adapte dynamiquement :

- 🔔 **Cloche notifications** — toujours visible si connecté, avec un
  petit point rouge animé (`.wt-ping`) si au moins une notification
  est non lue.
- ✉️ **Enveloppe messages** — pastille `9+` (`.wt-pill-badge`).
  L'icône **disparaît complètement** si le compteur est à 0 pour
  épurer l'interface.
- 👤 **Avatar dropdown** — image personnalisée ou pastille
  initiale stylisée si `users.avatar_url` est nul. Le dropdown
  expose : Admin (si admin), Dashboard, Messages (avec badge inline),
  Notifications, Référencement, Retrait, Déconnexion.
- En **mobile** (≤768 px), les boutons texte de connexion disparaissent
  au profit d'une icône de clé seule, et un **hamburger** ouvre un
  drawer coulissant droite→gauche avec backdrop flouté. Les barres
  pivotent en croix (`is-active`) pour la fermeture.

JavaScript : 6 nouveaux modules (profil dropdown, drawer mobile, FAQ
accordion, bulk delete générique, mark-read on accordion, polling
léger toutes les 60 s sur `/api/badges.php`).

### Modération admin

- `/admin/testimonials.php` — approve / reject (avec motif) / feature /
  delete, filtrable par statut.
- `/admin/tickets.php` — file complète des tickets, vue détail avec fil
  de discussion, réponse, fermeture/réouverture. Les réponses admin
  déclenchent une notification utilisateur (`support_reply`) ou un
  e-mail au visiteur invité.
- `/admin/messages.php` — diffusion à un utilisateur, à tous les actifs
  ou à tous les admins ; réglage en direct des paramètres TTL et du
  toggle "Témoignages en accueil".

## V5 — Classement mensuel & récompenses

La V5 ajoute un classement mensuel des Coins gagnés, avec podium
émotionnel pour le Top 3, liste élite pour les rangs 4-10, bloc
d'ancrage personnalisé pour l'utilisateur connecté, archive immuable
des mois précédents et attribution automatique de bonus en clôture.

### Migration

```bash
mysql -u root -p wintaskly < sql/migration_v5.sql
```

Ajoute deux tables (`leaderboard_cache`, `leaderboard_history`) et
seed 22 clés de config (`leaderboard.*` : durée du cache, toggle
récompenses, barème coins/xp par rang 1-10, marqueur d'idempotence).

### Source canonique des gains

Plutôt que dépendre de tables disjointes (`faucet_logs`,
`shortlink_logs`, …), Wintaskly agrège **directement** depuis
`transactions`, qui centralise déjà tous les crédits validés avec un
champ `type` ENUM précis et l'index `(user_id, created_at)`. Les
types comptabilisés sont :

```
'faucet','shortlink','ptc','offerwall','referral','bonus'
```

Les `withdraw` et `admin` sont exclus de l'agrégation. Filtrage
supplémentaire `coins > 0 AND u.status='active'` pour ignorer les
ajustements négatifs et les comptes suspendus/bannis.

### Performance & cache

Pour éviter de réagréger à chaque page vue, le Top 10 est mis en
cache dans `leaderboard_cache`, régénéré toutes les `cache_minutes`
(15 par défaut, paramétrable dans `/admin/leaderboard.php`). La
fonction `wt_lb_get_top()` est le seul point d'entrée à utiliser : elle
détecte l'expiration via `MAX(refreshed_at)` et appelle
`wt_lb_refresh_cache()` à la volée si nécessaire.

```php
$top = wt_lb_get_top();   // tableau de 0..10 lignes triées par rang
```

Le rang exact d'un utilisateur (utile hors Top 10) est résolu par
`wt_lb_user_rank($userId)` qui compte le nombre d'utilisateurs avec un
gain mensuel strictement supérieur — opération bornée elle aussi sur
l'index `(created_at)` et le filtre `coins > 0`.

### Réinitialisation mensuelle & récompenses automatiques

Aucun job cron n'est strictement requis. À la première requête PHP
survenant après un changement de mois, `wt_lb_maybe_archive_previous()`
détecte que la période archivée stockée dans
`config.leaderboard.last_archived_period` ne correspond plus au mois
précédent, calcule son Top 10 final, **archive** dans
`leaderboard_history` (avec snapshot `username` figé) et **attribue**
les bonus configurés via `award_user($uid, $coins, $xp, 'bonus', …)`
+ notification utilisateur `leaderboard_reward`. L'opération est
idempotente : un second appel dans la même période est no-op.

Un bouton "↻ Forcer archivage" dans le backoffice permet de rejouer
manuellement la clôture du mois précédent (utile en cas de hotfix).

### Page `/leaderboard/`

Trois zones :

1. **Podium** — colonnes de hauteurs distinctes (gold 140 px, silver
   110 px, bronze 88 px) disposées 2-1-3. Couronne dorée pulsante
   sur le 1er, halo `box-shadow:0 0 20px rgba(234,179,8,.5)` sur
   l'avatar, médailles circulaires en sticker bas-droite, confettis
   en arrière-plan canvas (50 particules max, arrêt après 6 s,
   respecte `prefers-reduced-motion`).
2. **Liste élite** — table 4-10 avec hover lift, badge "Challenger"
   pour les rangs 4-5.
3. **Bloc "Vous"** — comportement conditionnel :
   - Si l'utilisateur est dans le Top 10, sa ligne ou sa colonne
     reçoit un outline accent animé + badge `Vous` / `Défendez votre
     place !` qui pulse.
   - Sinon, un bloc isolé à dégradé `bleu→cyan` + halo or affiche
     son rang exact, son gain mensuel, et un **indicateur de proximité**
     calculé via `wt_lb_gap_to_top()` :
     _"🎯 Plus que **1 250 Coins** pour intégrer le Top 10…"_.
     Trois CTA renvoient vers faucet, shortlinks et PTC.

Le sélecteur de période permet aussi de consulter les Top 10 archivés.

### Admin

`/admin/leaderboard.php` regroupe :

- la table **live** du mois courant (avec bouton "⟳ Régénérer le cache"
  et "↻ Forcer archivage") ;
- l'**historique** mois par mois, avec snapshot des usernames et
  affichage des bonus attribués ;
- l'**éditeur du barème** : `cache_minutes`, toggle récompenses, et
  matrice 10 lignes (Coins + XP par rang).

## Audit & corrections post-release

Tests d'intégration runtime exécutés contre une instance MariaDB 10.11 live :
22/22 invariants validés sur le chemin critique (création utilisateur,
award_user avec commission de parrainage, tokens auth round-trip, TOTP,
leaderboard live, archivage idempotent, TTL cleanup, échappement XSS).

Trois bugs réels détectés et corrigés en runtime :

- **`includes/leaderboard.php`** — `bind_param('sissddi',...)` corrigé
  en `'siisddi'`. L'ordre des types ne correspondait pas aux colonnes
  `(period_ym s, rank i, user_id i, username s, coins_month d,
  reward_coins d, reward_xp i)` ; MariaDB acceptait silencieusement
  via coercion mais cela pouvait causer des comportements subtils.

- **`includes/leaderboard.php`** — protection contre le double-crédit
  des bonus lors d'un replay admin. La fonction
  `wt_lb_maybe_archive_previous()` vérifie désormais via la table
  `transactions` qu'aucune ligne `type='bonus'` avec le `meta`
  `'leaderboard:YYYY-MM:rankN'` n'existe avant d'attribuer la récompense.
  Le bouton "Forcer archivage" est désormais sûr à appuyer plusieurs fois.

- **`includes/messaging.php`** + appels dispersés — fallback ajouté
  pour les serveurs PHP sans mbstring. La fonction `wt_avatar_inner()`
  plantait sur `mb_substr` non défini. Nouveaux helpers `wt_strlen()` /
  `wt_substr()` dans `includes/auth.php`, utilisés à la place de
  `mb_strlen` / `mb_substr` / `mb_strimwidth` dans tout le code
  applicatif (admin, api, dashboard).

## V6 — Sidebar dashboard · Hub des tâches · Bannière cookies RGPD

La V6 finalise l'expérience utilisateur en comblant trois manques
identifiés lors d'une revue UX :

### Sidebar dashboard (`dashboard/_nav.php`)

Sidebar persistante (sticky top:80px) sur les 5 pages dashboard, avec
le même style que la sidebar admin. Affiche le bloc profil (avatar,
username, niveau, coins), 7 liens nav avec icônes SVG inline,
indicateur visuel sur le lien actif (`is-active`), badges unread
inline sur Messages et Notifications, lien direct vers `/admin/`
pour les admins. Reflow horizontal sur mobile <900px.

Les 5 pages `/dashboard/*` ont été migrées vers un layout en grid
`260px 1fr` ; chaque page définit sa clé `$dashActive` puis inclut
`_nav.php` au début de `<section class="wt-dash__content">`.

### Hub des tâches (`/tasks/index.php`)

Page d'accueil des activités rémunératrices, accessible aux visiteurs
(CTA inscription) et aux utilisateurs connectés (état dynamique). 4
cartes — Faucet, Shortlinks, PTC, Offerwalls — affichent : icône,
récompense indicative, contrainte (cooldown/durée), nombre d'éléments
actifs en base, et un CTA contextualisé. Pour les connectés, la carte
Faucet montre soit ✅ "Prêt à réclamer" soit ⏳ "Prochain claim à
HH:MM" basé sur `MAX(faucet_claims.next_claim_at)`. Section "Tips" en
fin de page expose la stratégie d'enchaînement optimal des tâches.

Le lien `Tâches` remplace désormais les 4 anciens liens individuels
dans la nav desktop (l'expérience reste accessible via le drawer
mobile et la sidebar dashboard).

### Bannière cookies RGPD/CNIL

Conformité RGPD complète :

- **Bandeau** affiché en bas de page si le cookie `wt_consent` est
  absent (`wt-cookie-banner`, animation slide-up + fade, 3 boutons :
  Tout accepter / Refuser / Préférences).
- **Modal de préférences** avec 3 catégories : Essentiels (toujours
  cochés et désactivés), Mesure d'audience, Publicité. Sauvegarde en
  `wt_consent=all`, `essential`, ou `custom:analytics,ads`.
- **Page `/legal/cookies.php`** liste exhaustivement les 6 cookies
  déposés avec leur durée et finalité, plus un bouton `[data-cookie-reopen]`
  qui ré-affiche le bandeau à la demande.
- **Cookie `wt_consent`** : Max-Age 6 mois, SameSite=Lax, Secure auto
  en HTTPS.
- **Évènement DOM `wt:consent`** dispatché à chaque changement, permet
  aux modules externes (AdSense par exemple) de réagir au consentement
  sans coupler le JS.
- **Bridge i18n** (`window.WT_I18N` + `window.WT_BASE`) injecté dans
  le footer, alimente la bannière JS pour préserver les traductions
  FR/EN.

### Bug corrigé en audit V6

- **`includes/functions.php` → `xp_progress()`** : ajout des clés
  `next_level`, `current_xp`, `xp_for_next` que le dashboard
  référençait depuis longtemps (warnings PHP non visibles en
  production mais valeurs vides dans la barre de progression).

## V7 — Paramètres compte, 2FA méthodes, gestion utilisateurs admin

La V7 introduit toutes les pages de gestion personnelle (côté
utilisateur) et les pages de modération (côté admin), avec une
modal de confirmation générique réutilisable et un toggle œil sur
tous les champs mot de passe.

### Routage `/help/contact-track/<token>`

**Problème initial** : `php -S localhost:8000` ne lit pas `.htaccess`
donc la règle de rewrite n'est jamais appliquée et les liens de
suivi guest renvoyaient un 404.

**Triple fix** :

1. **`router.php`** ajouté à la racine — routeur de développement à
   utiliser via `php -S localhost:8000 router.php`. Il intercepte le
   pattern `/help/contact-track/<token>` et dispatche vers le bon
   index avec `$_GET['token']` rempli.
2. **`help/contact-track/index.php`** : fallback dans le script
   lui-même qui extrait le token depuis `$_SERVER['REQUEST_URI']`
   si `$_GET['token']` est absent. Couvre les déploiements Nginx
   non configurés ou tout serveur sans rewrite actif.
3. **`includes/db.php`** : support socket Unix
   (`$dbc['socket']`) pour les hébergements partagés (OVH,
   Infomaniak, etc.) qui n'exposent pas le port 3306.

Trois scénarios validés : Apache + `.htaccess`, built-in `-S` avec
`router.php`, built-in `-S` sans `router.php`.

### Nouvelles colonnes `users` (migration idempotente)

```sql
tfa_email_enabled    TINYINT(1) DEFAULT 0
tfa_sms_enabled      TINYINT(1) DEFAULT 0
phone_e164           VARCHAR(20)  NULL
bio                  VARCHAR(500) NULL
country              CHAR(2)      NULL
delete_requested_at  DATETIME     NULL
delete_token         CHAR(64)     NULL
```

### Nouvelles clés `config`

```sql
tfa.totp_available        '1'   -- méthode TOTP activable par l'utilisateur
tfa.email_available       '1'   -- méthode email activable
tfa.sms_available         '0'   -- méthode SMS (off par défaut)
tfa.sms_provider          ''    -- nom du provider (twilio, vonage…)
account.delete_grace_days '7'   -- délai avant purge effective
```

Toutes modifiables depuis `/admin/security.php`.

### `/dashboard/settings.php`

Page de gestion des préférences de l'utilisateur, structurée en
trois articles :

- **🔐 Authentification à deux facteurs** : 3 méthodes affichées en
  fonction de leur disponibilité config + prérequis utilisateur :
  - TOTP (Google Authenticator, Authy, 1Password) — bouton
    *Configurer* vers `/dashboard/2fa-setup.php` (à implémenter
    séparément si non encore présent).
  - Email — toggle direct, nécessite `email_verified_at` non null.
  - SMS — toggle direct, nécessite `phone_e164` rempli et
    `tfa.sms_available = 1`.
- **🎨 Apparence** : sélecteurs `theme` (dark/light) et `lang`
  (fr/en), avec cookie + reload pour effet immédiat. Affichage
  passif du fuseau horaire (auto-détecté).
- **🔔 Notifications** : placeholders (toggles désactivés) prêts
  pour la suite.

Chaque interaction est temps réel via `[data-settings-toggle]
[data-key]` → POST `/api/settings_toggle.php` → toast + revert si
échec.

### `/dashboard/account.php`

Page profil avec 5 sections :

- **🪪 Identité publique** — username, country (ISO-2), bio
  (500 chars). Endpoint : `api/account_profile.php`. Vérifie
  l'unicité du username.
- **📧 E-mail** — changement avec mot de passe en confirmation.
  Endpoint : `api/account_email.php`. Reset systématiquement
  `email_verified_at` + `tfa_email_enabled` + envoie un nouveau
  mail de vérification.
- **📞 Téléphone E.164** — pattern strict `^\+\d{8,15}$`. Endpoint :
  `api/account_phone.php`. Vider le champ désactive
  automatiquement `tfa_sms_enabled`.
- **🔑 Mot de passe** — exige l'ancien, rate-limité
  (`pw_change:<uid>`), invalide tous les `remember-me` tokens
  après succès. Endpoint : `api/account_password.php`. Envoie une
  notification de sécurité.
- **⚠️ Danger zone** — bouton *Supprimer mon compte* avec modal
  typed (saisir `SUPPRIMER` pour valider). Endpoint :
  `api/account_delete.php` → positionne `delete_requested_at` et
  un `delete_token`. Pendant la période de grâce
  (`account.delete_grace_days`), l'utilisateur peut annuler via
  `api/account_delete_cancel.php`. À l'expiration, un cron à
  brancher purge avec `DELETE FROM users WHERE
  delete_requested_at < NOW() - 7 DAYS` (les FK ON DELETE CASCADE
  nettoient tous les enfants).

### Modal de confirmation générique (`wintaskly-ui.js`)

Helper `window.WT.confirm({ title, body, ok, cancel, okClass,
typed })` retournant une `Promise<boolean>`. Si `typed` est fourni,
le bouton OK reste désactivé tant que l'utilisateur n'a pas retapé
le mot exact (insensible à la casse).

Hook auto sur tout élément `[data-confirm]` :

```html
<button data-confirm
        data-confirm-title="Supprimer ?"
        data-confirm-body="Action irréversible."
        data-confirm-ok="Supprimer"
        data-confirm-ok-class="wt-btn--danger"
        data-confirm-typed="SUPPRIMER"
        data-confirm-post="/api/account_delete.php"
        data-confirm-data='{"reason":"user_request"}'>
  Supprimer
</button>
```

Trois modes après confirmation :

- `data-confirm-href` → navigation simple
- `data-confirm-post` → POST avec CSRF + champs additionnels
  via `data-confirm-data` (JSON)
- ni l'un ni l'autre → dispatch d'un event `wt:confirm:ok` sur
  le bouton initial pour brancher du JS sur-mesure.

### Toggle œil sur les champs mot de passe

Tous les `<input type="password">` des pages `/auth/` et
`/dashboard/account.php` sont wrappés dans :

```html
<div class="wt-input-wrap wt-input-wrap--password">
  <input class="wt-input" type="password" ...>
  <button class="wt-input-eye" data-toggle-pw>
    <svg class="wt-input-eye__off">...</svg>
    <svg class="wt-input-eye__on is-hidden">...</svg>
  </button>
</div>
```

Click → bascule `type="password"` ↔ `text`, bascule la classe
`.is-hidden` sur les deux SVG. CSS dans `wintaskly.css`
(`.wt-input-wrap--password` + `.wt-input-eye`).

### `/admin/users.php`

Liste paginée (25/page) des utilisateurs avec filtres :

- Recherche full-text sur username/email
- Filtre par statut (`active`, `pending`, `suspended`, `banned`)
- Filtre par rôle (`user`, `admin`)
- Filtre `pending_delete` pour repérer les comptes en attente de
  purge.

Pour chaque ligne, **9 actions** disponibles (sauf sur son propre
compte) via boutons `[data-confirm]` → POST
`/api/admin_user_action.php` :

| Action | Description |
|---|---|
| `activate` | Repasser `status='active'` |
| `suspend` | `status='suspended'` (récupérable) |
| `ban` | `status='banned'` — typed `BAN` |
| `verify_email` | Force `email_verified_at = NOW()` |
| `reset_totp` | Efface `totp_secret` + désactive TOTP |
| `cancel_delete` | Annule une demande de suppression |
| `promote` | `role='admin'` — typed `PROMOTE` |
| `demote` | `role='user'` |
| `hard_delete` | `DELETE FROM users` — typed `DELETE` |

Chaque action déclenche une entrée dans `admin_actions` (table
créée à la volée par l'API) et une notification interne à la
cible (sauf `hard_delete`).

### `/admin/security.php`

Trois sections :

- **Méthodes 2FA disponibles** — toggles globaux pour
  `tfa.totp_available`, `tfa.email_available`,
  `tfa.sms_available` + input texte `tfa.sms_provider`.
- **Suppression compte** — input numérique `account.delete_grace_days` (0-90).
- **Journal des actions admin** — 25 dernières entrées de
  `admin_actions` (admin, action, cible, timestamp).

Toggles temps réel via `[data-admin-config][data-key]` → POST
`/api/admin_config_set.php` (whitelist stricte, validation par
type).

### Endpoints API V7

| Endpoint | Méthode | Description |
|---|---|---|
| `api/settings_toggle.php` | POST | Toggle paramètres utilisateur whitelistés |
| `api/account_profile.php` | POST | Maj username/country/bio |
| `api/account_email.php` | POST | Change e-mail + re-vérification |
| `api/account_phone.php` | POST | Set/clear téléphone E.164 |
| `api/account_password.php` | POST | Change mot de passe + invalide remember-me |
| `api/account_delete.php` | POST | Programme suppression (grace period) |
| `api/account_delete_cancel.php` | POST | Annule la suppression en attente |
| `api/admin_user_action.php` | POST | 9 actions sur un compte |
| `api/admin_config_set.php` | POST | Maj clé `config` whitelistée |

### Helpers ajoutés à `includes/auth.php`

- **`require_role(string $role): array`** — variante générique de
  `require_admin` qui exige un rôle précis. Utile pour les futurs
  rôles (`moderator`, `support`).

### Validation

- Schema : fresh + replay idempotents ✅ (30 tables, 7 nouvelles
  colonnes `users`, 5 nouvelles clés `config`).
- Lint PHP : tous les nouveaux fichiers passent `php -l`.
- Lint JS : `wintaskly-ui.js` + `wintaskly.js` passent `node -c`.
- i18n : **590 clés utilisées / 638 définies / 0 manquante**
  (FR+EN parallèles).

## Licence

Code livré tel quel pour usage interne. À adapter à ton contexte légal
local (notamment PSAN / MiCA si la conversion des Coins en valeur
réelle est envisagée).
