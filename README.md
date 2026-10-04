# Organic To Go — MVP local

Application d’administration française pour un restaurant, Symfony 8.1 / PHP 8.4, Twig, Bootstrap 5.3.8, Doctrine ORM et PostgreSQL 17. Développement local uniquement. Aucun déploiement. Lecture OpenAI et envoi Google Sheets facultatifs, déclenchés uniquement par l’utilisateur. Aucun document privé fourni dans le dépôt.

## Démarrer

Prérequis : Docker Desktop, Docker Compose et `rtk`. PHP, Composer, dépendances et PostgreSQL restent dans Docker.

```sh
rtk proxy sh -c 'lsof -nP -iTCP:8097 -sTCP:LISTEN || true'
rtk proxy sh -c 'test -f .env.local || printf "APP_SECRET=%s\n" "$(openssl rand -hex 32)" > .env.local'
rtk docker compose --env-file .env.local build
rtk docker compose --env-file .env.local up -d
rtk docker compose --env-file .env.local exec -T web composer install --no-scripts --no-interaction
rtk docker compose --env-file .env.local exec -T web mkdir -p var
rtk docker compose --env-file .env.local exec -T web chown -R www-data:www-data var
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console doctrine:migrations:migrate --no-interaction
rtk docker compose --env-file .env.local exec --user www-data web php bin/console app:admin admin@example.test
```

Si le port est occupé, ne pas arrêter les autres projets. Le dernier appel demande un mot de passe masqué de 12 caractères minimum. Il crée un nouvel administrateur, sans modifier les comptes existants. Ouvrir [http://127.0.0.1:8097](http://127.0.0.1:8097).

`.env.local` est ignoré par Git ; `--env-file .env.local` est nécessaire à chaque commande Compose. Ne pas recréer ce fichier à chaque démarrage. Le mot de passe PostgreSQL dans Compose est un identifiant de développement, jamais de production. Le réseau et le volume portent le préfixe `organic` ; la base n’a aucun port publié. Aucune interaction avec les services phalcon-user ou grrind.

Composer est installé depuis l’image officielle `composer:2.10.3`. Le lockfile fixe les dépendances. Les scripts Composer sont désactivés et seul le plugin officiel `symfony/runtime`, qui génère le démarrage du runtime, est explicitement autorisé. Aucun Flex, script tiers ou installation JavaScript. Bootstrap est servi localement, sans CDN au chargement des pages.

## Démonstration fictive

```sh
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console app:demo
```

Compte **de démonstration locale uniquement** : `demo@organic.test` / `Demo-local-2026!`. Ne jamais le réutiliser ailleurs. Cette commande ajoute « Restaurant FICTIF — Démonstration » et « Salade FICTIVE ». Elle ne duplique pas les données si le compte existe déjà.

Scénario : livraison de 10 unités à 110 MAD il y a deux jours ; retour de 2 unités hier ; paiement de 500 MAD aujourd’hui. Le récapitulatif d’hier à aujourd’hui montre un solde d’ouverture de 1 100 MAD, des retours de −220 MAD, des paiements de −500 MAD et un solde cumulé de 380 MAD. Les données ajoutées lors de la vérification manuelle sont également fictives.

## Utilisation et règles comptables

1. Ajouter/modifier les clients et les prix par défaut du catalogue.
2. Enregistrer une livraison, normalement le jour même. La date proposée est aujourd’hui ; une date passée permet la saisie historique. Ajouter plusieurs produits, quantités et prix unitaires facultatifs. Le prix du catalogue s’applique si le champ est vide. Le total estimé se calcule immédiatement ; le serveur reste autoritaire.
3. Ouvrir une livraison et enregistrer un retour à sa propre date. Le retour ne peut pas précéder la livraison ni dépasser la quantité livrée restante. Chaque écriture verrouille la ligne d’origine dans une transaction PostgreSQL avant de lire la somme des retours.
4. Enregistrer les paiements datés par client. Ils ne sont pas attribués arbitrairement à une livraison ou à une fenêtre de rapport.
5. Consulter le récapitulatif client pour une période inclusive et exporter son CSV.

Les prix, montants et calculs persistés utilisent uniquement des centimes entiers. Les quantités sont entières de 1 à 100 000 ; prix et paiements ont un plafond de 1 000 000 MAD, avec deux décimales au maximum. Les prix peuvent être nuls ; les paiements doivent être positifs. Les dates de saisie sont comprises entre le 01/01/2000 et aujourd’hui. Le fuseau métier local est **Europe/Paris**, à confirmer pour le restaurant avant tout usage réel.

Une livraison conserve le nom et le prix unitaire du produit lors de la saisie. Modifier le catalogue ne recalcule pas l’historique. Livraisons, retours et paiements sont ajoutés sans écrans de modification/suppression de l’historique. Une correction comptable de l’historique n’est pas encore proposée.

Le rapport distingue activité de la période et solde cumulé à la date de fin. Solde positif = dette du client ; négatif = crédit. Les retours valorisent le prix de la livraison d’origine. Le CSV UTF-8 utilise `;` et une virgule décimale ; seuls les textes externes sont protégés contre les formules de tableur, les montants négatifs restent des nombres.

Toutes les routes métier exigent `ROLE_ADMIN`. Login, logout et formulaires de mutation utilisent CSRF ; mots de passe hachés par Symfony, cookies HttpOnly/SameSite et limitation du login à 5 échecs par 15 minutes. HTTP local exige un cookie sans Secure ; Symfony l’active automatiquement sous HTTPS. Les erreurs de production n’exposent pas de traces.

## Vérifier

```sh
rtk proxy ./bin/test
rtk docker compose --env-file .env.local exec -T web composer validate --strict
rtk docker compose --env-file .env.local exec -T web composer audit
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console lint:container
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console lint:twig templates
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console doctrine:schema:validate
```

`bin/test` crée/migre **organic_test**, une base distincte dans le seul PostgreSQL Organic. Les tests vérifient le nom de base avant de vider leurs tables. Ils ne modifient pas `organic` ni d’autres projets. Ils couvrent login/CSRF, saisie et prix conservés, rejets de quantités/montants/dates, retours bornés, dates et soldes cumulés, export CSV sécurisé et deux connexions concurrentes sur la même ligne. La contention est vérifiée réellement dans `pg_stat_activity` : le second retour attend le verrou puis est refusé.

Après modification du code/configuration, reconstruire le cache :

```sh
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console cache:clear
```

Arrêter uniquement Organic sans effacer la base :

```sh
rtk docker compose --env-file .env.local stop
```

Les listes et le rapport restent simples pour un seul restaurant : pas de pagination et solde d’ouverture calculé en mémoire. Ajouter pagination et agrégats SQL lorsque le volume le justifie. Aucun moteur de tarification, inventaire global, portail client, moteur de taxe ni synchronisation automatique.

Configuration vérifiée avec la documentation officielle [Symfony 8.1 setup](https://symfony.com/doc/8.1/setup.html), [security](https://symfony.com/doc/8.1/security.html) et [forms](https://symfony.com/doc/8.1/forms.html). Symfony 8.1 est une version stable à support court, maintenue jusqu’en janvier 2027 ; prévoir sa prochaine mise à jour avant cette échéance. DoctrineBundle 3 utilise les objets paresseux natifs de PHP 8.4 ; les anciennes options de génération de proxies ont été supprimées.


## Factures fournisseurs

La rubrique **Factures fournisseurs** est séparée du registre clients. Ajouter les fournisseurs avec leur raison sociale, leurs enseignes/alias (un par ligne) et un libellé interne libre. Les alias sont comparés après normalisation des accents, majuscules et espaces ; une correspondance ambiguë impose un choix manuel. Lors de la lecture IA, seuls les noms officiels, alias et IDs de ce catalogue sont transmis avec les photos. La réponse peut choisir un ID existant si les indices du vendeur correspondent, ou laisser le fournisseur non établi ; aucun rapprochement approximatif ni création automatique. Le formulaire affiche le nom officiel enregistré, tout en conservant le nom littéral lu dans les données d’extraction.

- **Saisie manuelle** disponible sans clé API. Choisir le fournisseur et vérifier les champs ; le changement de fournisseur renseigne son nom officiel et son libellé par défaut. Ces deux textes restent modifiables pour le document ; s’ils sont laissés vides, les valeurs du fournisseur enregistré s’appliquent aussi côté serveur.
- **Photos** : caméra du téléphone ou plusieurs fichiers JPEG/PNG/WebP, maximum 6 fichiers, 8 Mo chacun et 20 Mo au total. Choisir documents distincts (un brouillon par photo) ou pages d’un même document (un seul brouillon). La lecture du premier document intervient pendant l’envoi si OpenAI est configuré ; ouvrir ensuite chaque brouillon et utiliser « Lire avec l’IA ». Aucun appel sur un simple GET, aucune tâche en arrière-plan.
- **Validation humaine obligatoire** : contrôler raison sociale, date du document (pas l’échéance), référence (pas une référence BL sur une facture), type et TTC. Recopier soi-même le TTC dans un champ toujours vide à l’ouverture, puis cocher la confirmation. Les totaux proposés ne sont jamais autorisés à enregistrer une écriture seuls. Le formulaire conserve les autres champs et les photos lors de la création d’un fournisseur ; le TTC doit être recopié à nouveau.
- Chaque écriture conserve les noms/libellés, le TTC en centimes entiers, le type, la date, la référence, l’utilisateur et l’instant de validation UTC. Le statut `manual`, `extracted` ou `corrected` conserve l’origine ; une correction du TTC lu est traçable avec sa preuve originale dans le JSON d’extraction, sans image en base. Aucun écran de modification/suppression de l’historique.
- La référence est facultative. Lorsqu’elle est renseignée, une contrainte PostgreSQL rejette les doublons fournisseur + référence normalisée + type. Sans référence, plusieurs documents sont possibles et les doublons entre eux ne peuvent pas être détectés : vérifier la liste avant validation. Aucun numéro fictif n’est généré. Le jeton unique de brouillon rend la confirmation répétée idempotente. Une erreur conserve le brouillon. Un bon de livraison est affiché/exporté séparément et n’est jamais additionné au total des factures.
- Le CSV contient uniquement les documents validés, avec filtres de dates inclusifs facultatifs. Les textes sont protégés contre les formules ; la colonne « Centimes MAD » conserve aussi les entiers exacts.

### Lecture facultative OpenAI

Ajouter dans `.env.local` (fichier ignoré) :

```dotenv
OPENAI_API_KEY=votre-cle-personnelle
OPENAI_INVOICE_MODEL=gpt-6-luna
```

Puis appliquer la configuration en recréant **uniquement le service web Organic** :

```sh
rtk docker compose --env-file .env.local up -d --build web
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console cache:clear
```

Les photos partent vers le point de terminaison fixe OpenAI Responses, avec `store:false`, un schéma JSON strict, un effort de raisonnement `medium`, sans outils et sans conservation demandée via l’API. Les conditions de rétention de l’API restent celles du compte OpenAI : `store:false` n’est pas une garantie de rétention nulle par le fournisseur. Les instructions imprimées sont traitées comme des données. Montants absents/coupés/partiels ou devise inconnue donnent un TTC non établi ; aucune multiplication ni reconstruction de TTC. Les preuves TTC/HT/TVA visibles et les avertissements restent consultables. Une différence HT + TVA / TTC est signalée sans corriger un montant. La validation humaine reste indispensable même si la réponse semble nette. Délai borné à 90 secondes, erreurs réseau/quota/réponse invalide : message générique et saisie manuelle disponible, photos conservées. Ne pas envoyer de document sans être autorisé à le transmettre au fournisseur API.

### Photos privées et nettoyage

Les brouillons vivent uniquement sous `var/invoice-drafts/` (ignoré par Git), répertoires opaques, liés à l’utilisateur **et** à sa session. Les photos sont servies par une route authentifiée contrôlant ces deux liens, jamais sous `public/`. La validation réussie ou « Abandonner » supprime les photos. Les brouillons expirent après 24 h et sont supprimés opportunément lors de l’ouverture de la rubrique ou d’un nouvel envoi.

Pour supprimer aussi les brouillons abandonnés sans nouvelle activité, exécuter régulièrement cette commande (par exemple chaque heure via le planificateur local) :

```sh
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console app:invoice-drafts:cleanup
```

Sans activité ni cette commande, les fichiers expirés restent sur disque mais ne sont plus accessibles dans l’application. Le nettoyage ne touche que les dossiers de brouillons reconnus ; aucun autre fichier `var/` n’est supprimé.

### Envoi manuel Google Sheets

Créer un compte de service Google avec l’API **Google Sheets activée**. Télécharger son JSON de credentials dans `var/google-service-account.json` (ignoré par Git ; ne jamais le committer), puis partager **le classeur choisi** à l’adresse `client_email` de ce compte avec le rôle Éditeur. Le compte de service ne reçoit pas de délégation à votre compte personnel et l’application ne lit pas votre Drive.

```dotenv
GOOGLE_SHEETS_CREDENTIALS_FILE=/app/var/google-service-account.json
GOOGLE_SHEETS_SPREADSHEET_ID=identifiant-dans-l-url-du-classeur
GOOGLE_SHEETS_TAB="Factures fournisseurs"
```

Le chemin désigne le fichier **dans le conteneur** ; le montage existant `.:/app` suffit. Restreindre localement l’accès au JSON tout en permettant sa lecture par `www-data`, puis recréer le service web avec les commandes précédentes.

Le bouton **Envoyer les documents manquants dans Google Sheets** transmet tous les documents validés ; il ne reprend pas le filtre visuel et n’effectue aucune synchronisation automatique. Le service signe un JWT RS256 avec OpenSSL natif, obtient un token OAuth limité à Sheets et utilise des URLs Google fixes. Il crée l’onglet dédié s’il manque, vérifie ses en-têtes et lit les IDs de la première colonne avant d’ajouter uniquement les entrées absentes. Les écritures utilisent `RAW` : les textes ressemblant à des formules restent du texte. La colonne « Centimes MAD » est un entier numérique exact pour les sommes. Un verrou PostgreSQL local sérialise les envois concurrents. Un nouvel envoi après une réponse perdue relit les IDs, évitant les doublons. Ne pas modifier/supprimer manuellement ces IDs dans cet onglet. Les données locales restent la référence ; aucune importation depuis Sheets. Sans credentials valides, le CSV reste utilisable.

Les tests couvrent l’extraction avec API simulée (TTC littéral, coupure, HT/TVA incohérents, réponse invalide/quota/refus), les contrôles photo, les propriétaires/sessions/CSRF, la confirmation TTC obligatoire, les snapshots, doublons et répétitions, le nettoyage, le CSV sécurisé et Google Sheets `RAW`/idempotent. Un test réel OpenAI ou Google Sheets exige les credentials facultatifs et n’est pas simulé dans l’interface.
