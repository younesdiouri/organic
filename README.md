# Organic To Go

Application d’administration française pour un restaurant, Symfony 8.1 / PHP 8.4, Twig, Bootstrap 5.3.8, Doctrine ORM et PostgreSQL 17. Développement local et déploiement Fly autorisé sur `organic-to-go`. Lecture OpenAI et envoi Google Sheets facultatifs, déclenchés uniquement par l’utilisateur. Aucun document privé fourni dans le dépôt.

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

Composer est installé depuis l’image officielle `composer:2.10.3`. Le lockfile fixe les dépendances. Les scripts Composer sont désactivés et seul le plugin officiel `symfony/runtime`, qui génère le démarrage du runtime, est explicitement autorisé. Le build de production autorise explicitement Composer sous root avec `COMPOSER_ALLOW_SUPERUSER=1` pour ce plugin de confiance et vérifie la présence de `vendor/autoload_runtime.php` avant de terminer ([Composer](https://getcomposer.org/doc/faqs/how-to-install-untrusted-packages-safely.md), [Runtime Symfony 8.1](https://symfony.com/doc/8.1/components/runtime.html)). Aucun Flex, script tiers ou installation JavaScript. Bootstrap est servi localement, sans CDN au chargement des pages.

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


## Catalogue, recettes et import local

L’accueil affiche les trois plats ou boissons actifs et vendables au coût matière HT le plus élevé par pièce ou portion (PC / PORTION), uniquement lorsque le coût de recette est complet ; le nombre de coûts incomplets exclus reste visible. Le second graphique classe les produits par quantités livrées brutes sur les 30 derniers jours, aujourd’hui inclus, sans déduire les retours et en conservant les articles archivés dans l’historique.

Le catalogue réunit **matières premières, préparations, plats et emballages**. Rechercher par nom, référence ou alias et filtrer par type ou disponibilité en livraison. Chaque article possède une fiche accessible par « Recette / achats » : composition éditable avec liens vers les composants, quantité finale du lot, instructions, tarifs et fournisseurs, conditionnements et rendements utilisables. « Utilisé dans » permet de remonter aux recettes consommatrices. Les préparations peuvent réutiliser d’autres préparations ; les cycles sont refusés transactionnellement. Archiver un article ou retirer sa disponibilité le retire des nouvelles livraisons sans changer leur historique.

**Articles à compléter**, depuis le catalogue ou l’accueil, ouvre `/catalogue/a-completer`. La liste des articles actifs se recalcule à partir des champs actuels, avec recherche par nom/référence/alias et filtre par type ; les matières premières et emballages à corriger précèdent les recettes bloquées par leurs composants. « Compléter » ouvre les formulaires de saisie avec les points à résoudre : composition, quantité finale, vérification, tarif préféré, unité ou fournisseur. Les liens ciblent le tarif ou composant existant pour le corriger sans doublon. Après enregistrement, la fiche affiche les points restants ou « Article complet » ; revenir à la liste pour poursuivre. Un prix de vente nul ou une ressource explicitement gratuite n’est pas une donnée manquante. Les notes anciennes de l’import ne déterminent pas le statut de complétion.

Un tarif préféré par matière première sert au calcul. Les montants d’achat sont des centimes entiers ; les quantités sont des décimaux positifs avec six décimales maximum. Le calcul conserve des fractions exactes jusqu’à l’arrondi final : coût du lot et coût par unité produite. Une quantité brute utilise le prix avant pertes ; une quantité utilisable applique le rendement d’achat. Les grammes et millilitres sont normalisés en kg et litres à l’import ; une conversion masse/volume ou pièce/poids non documentée conserve un coût incomplet. Un prix manquant ne vaut jamais zéro ; une ressource gratuite confirmée possède une case explicite. Les recettes avec composition ou rendement incertain restent des brouillons tant que « Composition et rendement vérifiés » n’est pas cochée.

Les deux XLSX fournis sont lus une fois par une commande dédiée à leur structure. Le classeur de ventes fournit les noms distincts et tarifs de livraison, sans importer ses mouvements de ventes. Le classeur technique fournit matières, préparations, recettes et emballages. Aucun téléchargement ni appel Google automatique. Conserver les fichiers dans le répertoire privé ignoré `var/import-analysis/` : les sources, données de fournisseurs, manifestes et sauvegardes ne doivent jamais entrer dans Git ni dans l’image Docker.

Après les migrations locales :

```sh
# Placer les deux fichiers privés dans var/import-analysis/ avant ces commandes.
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console app:catalogue:prepare var/import-analysis/sales.xlsx var/import-analysis/recipes.xlsx --output=var/import-analysis/catalogue.json
# Simulation par défaut : aucune écriture.
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console app:catalogue:import var/import-analysis/catalogue.json
# Après revue du manifeste et du rapport, application locale atomique.
rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console app:catalogue:import var/import-analysis/catalogue.json --apply
```

Le manifeste porte les empreintes des sources et leurs lignes de provenance. Les rapprochements passent par noms officiels, alias et références, jamais par des IDs copiés entre environnements. Les anciens noms confirmés sont des alias du même article/fournisseur ; Organic Kitchen / Organic To Go est exclu des fournisseurs. Le tarif générique des smoothies reste distinct des recettes du bar, dont les formats de livraison ne sont pas précisés. L’affectation des emballages à chaque plat reste à renseigner. Les divergences de prix, conversions et corrections proposées sont conservées dans les notes.

L’import valide toutes les relations et les cycles avant écriture, puis utilise la même transaction/verrou que les éditions du catalogue. Une réexécution identique ne crée rien. Si un article importé a été modifié manuellement, ou si la source a changé, l’import signale un conflit et n’écrase rien : revoir les modifications dans les fiches avant de préparer une nouvelle opération. Les tests utilisent des classeurs et données fictifs ; les documents privés ne sont pas nécessaires pour exécuter la suite.

**Passage en production autorisé après validation locale** : sauvegarder la base dédiée, déployer le code et ses migrations, transférer les deux sources dans un emplacement privé de la Machine Organic, puis préparer un manifeste avec le registre fournisseurs de production, simuler, contrôler le rapport et appliquer. Le manifeste local ne présuppose aucun ID de production, mais une préparation sur l’environnement cible tient compte de ses alias existants. Réexécuter la simulation pour vérifier l’idempotence et contrôler les fiches et tarifs de livraison. Les sources, manifestes et sauvegardes restent privés, hors Git et des couches de l’image Docker ; les données sont importées par la commande dédiée, jamais par une migration de données.

## Assistant interne en lecture seule

La rubrique **Assistant** (`/assistant`) propose une question et une réponse en français après connexion. Chaque question est indépendante, sans historique enregistré ni appel API lors d’un GET. Exemples : « Quels sont les 3 plats au coût matière le plus élevé ? », « Quels ingrédients entrent dans le wrap tuna ? », « Quels produits avons-nous le plus livrés du 1er au 30 septembre 2026 ? » ou « Quels clients ont un solde restant dû aujourd’hui ? ». Pour « coûte le plus cher », l’assistant doit demander de préciser coût matière ou prix de vente ; pour une activité sans période, il demande les dates.

Il utilise la clé serveur `OPENAI_API_KEY` déjà partagée avec la lecture de documents, et `OPENAI_ASSISTANT_MODEL=gpt-6-luna` par défaut (facultatif dans `.env.local` ou les secrets Fly). En local, après une modification de configuration, recréer uniquement le service web et vider le cache avec les commandes de la section OpenAI ci-dessous. Sans clé, la page indique que l’assistant n’est pas configuré.

L’API Responses choisit parmi huit fonctions autorisées : recherche et fiche du catalogue, classement des plats, comparaison des ratios coût/prix, livraisons brutes et retours datés, recherche des clients par nom, récapitulatif par client et soldes dus cumulés. « Quel est le wrap qui a le meilleur ratio coût / prix ? » utilise directement une comparaison de tous les plats actifs vendables en PC/PORTION dont le nom, la référence ou un alias contient « wrap ». Le plus faible coût matière HT unitaire / prix de vente courant arrive en premier ; le classement compare les centimes entiers par produits croisés BCMath avant d’arrondir le pourcentage affiché. Coûts incomplets et prix nuls sont exclus avec des compteurs distincts (coût incomplet prioritaire si les deux manquent). Ce ratio ne mesure pas la rentabilité complète. Aucun SQL libre, fonction d’écriture, accès aux fichiers/brouillons/factures ou outil réseau n’est exposé au modèle. Les paramètres sont vérifiés côté serveur même avec les schémas stricts ; chaque consultation démarre sa propre transaction PostgreSQL `READ ONLY`, limitée à 3 secondes par requête SQL, avant de rendre la connexion à l’application. Aucune transaction n’est tenue pendant les appels OpenAI.

Les coûts réutilisent `RecipeCost` et son calcul exact, les récapitulatifs réutilisent `Ledger::report`. Coût du lot et coût par unité produite restent distincts. Le classement matière porte sur les plats/boissons actifs vendables en PC/PORTION et exclut explicitement les coûts incomplets ; il ne mesure pas la rentabilité complète. Les livraisons sont brutes, les retours conservés à leur propre date et les soldes cumulés ne dépendent pas d’une fenêtre de paiement arbitraire. Les réponses et noms sont affichés comme texte échappé ; les liens sont générés exclusivement par l’application depuis les fiches effectivement consultées (30 liens maximum). Une composition imbriquée partiellement consultée affiche un avertissement généré par le serveur, même si le modèle omet de le mentionner.

Seules la question et les données sélectionnées utiles sont envoyées à OpenAI : noms/alias, catégories, compositions, tarifs d’achat et noms fournisseurs, activités agrégées et noms/soldes clients. Coordonnées clients, notes libres, manifests/sources privées, comptes et secrets sont exclus. Les noms et alias restent des données non fiables. `store:false` évite la conservation demandée via Responses, sans garantir une rétention nulle chez le fournisseur API. N’ajoutez pas d’informations confidentielles inutiles dans la question. L’application ne journalise ni questions, réponses, données métier ou corps d’erreur API.

Limites : 1 500 caractères par question, 12 questions par 10 minutes et par administrateur (limiteur Symfony sur le cache fichiers de l’unique Machine), 4 échanges HTTP, 6 fonctions au maximum, 1 800 tokens de sortie par échange et 60 secondes de budget global HTTP. Résultats limités à 20 articles/clients par liste ; détail imbriqué limité à 30 articles, 100 composants et 10 tarifs par article, avec troncature signalée ; maximum 24 Ko par résultat et 140 Ko d’entrée cumulée. Le calcul charge le petit graphe du catalogue (plafond 2 000 articles) ; le récapitulatif canonique est refusé au-delà de 10 000 événements historiques pour ce client. Un dépassement, refus du modèle ou incident réseau/quota produit une erreur sûre, sans modifier les données. Une réponse IA peut être erronée : vérifier les fiches avant une décision.

Les tests utilisent uniquement des fixtures fictives en `organic_test` et des réponses API simulées : coûts imbriqués, alias, données privées omises, retours et soldes, paramètres malveillants/outils inconnus, budgets, refus et erreurs, authentification, CSRF, échappement et limitation. Aucun appel OpenAI réel dans la suite. Sources : [function calling Responses](https://developers.openai.com/api/docs/guides/function-calling), [formulaires Symfony 8.1](https://symfony.com/doc/8.1/forms.html), [limiteur Symfony](https://symfony.com/doc/current/rate_limiter.html).

## Factures fournisseurs

La rubrique **Factures fournisseurs** est séparée du registre clients. Ajouter les fournisseurs avec leur raison sociale, leurs enseignes/alias (un par ligne) et un libellé interne libre. Les alias sont comparés après normalisation des accents, majuscules et espaces ; une correspondance ambiguë impose un choix manuel. Lors de la lecture IA, seuls les noms officiels, alias et IDs de ce catalogue sont transmis avec les photos. La réponse peut choisir un ID existant si les indices du vendeur correspondent, ou laisser le fournisseur non établi ; aucun rapprochement approximatif ni création automatique. Le formulaire affiche le nom officiel enregistré, tout en conservant le nom littéral lu dans les données d’extraction.

La rubrique **Fournisseurs** permet de rechercher par nom, alias ou libellé interne et d’ouvrir chaque fournisseur pour voir tous ses articles liés aux tarifs d’achat, archivés inclus. Chaque article apparaît une fois avec tous les tarifs de ce fournisseur et des liens vers sa fiche et la modification du tarif.

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

## Déploiement Fly

`fly.toml` définit une seule Machine web toujours active à Paris (`cdg`), CPU partagé et 512 Mo, sans worker. La base PostgreSQL dédiée existante reste externe à cette application. Le volume `organic_data` monté sur `/data` conserve photos privées, sessions et cache des limitations de connexion ; le cache du conteneur Symfony reste éphémère. Les déploiements ne changent ni la base locale ni les autres applications Fly.

L’image `prod` contient uniquement le code et les dépendances de production verrouillées. `.dockerignore` exclut secrets, photos, sessions, documents privés et dépendances locales. Le démarrage exige `APP_SECRET` et `DATABASE_URL`, prépare seulement les trois répertoires connus du volume et chauffe le cache comme `www-data`. Les migrations sont exécutées dans la Machine de release sans volume avant le démarrage web. Apache accepte deux requêtes simultanées pour rester dans les 512 Mo ; une lecture IA longue peut occuper l’une de ces places.

```sh
rtk proxy fly volumes create organic_data --app organic-to-go --region cdg --size 1
# Importer APP_SECRET, DATABASE_URL et éventuellement OPENAI_API_KEY via stdin sécurisé.
rtk proxy fly secrets import --app organic-to-go
rtk proxy fly deploy --app organic-to-go --ha=false
rtk proxy fly ssh console --app organic-to-go --pty -C "su -s /bin/sh www-data -c \"php bin/console app:admin votre-adresse@example.com\""
```

Ne pas utiliser le compte de démonstration en production. Le volume unique implique une seule Machine ; un redéploiement peut occasionner une brève interruption. Sauvegarder séparément PostgreSQL et le volume Fly. Les brouillons expirés sont nettoyés lors de l’utilisation ou par `app:invoice-drafts:cleanup`, sans tâche automatique. Google Sheets reste désactivé tant que ses credentials dédiés ne sont pas configurés.

Avec Supabase, les tables métier restent accessibles uniquement au backend Symfony : la migration `Version20261004000300` active RLS sans politique publique et retire tous les droits de `PUBLIC`, `anon` et `authenticated`, y compris sur leurs séquences. La migration `Version20261004000400` protège de la même manière les tables de composition et de tarifs d’achat. Utiliser une connexion serveur propriétaire des tables ; ce propriétaire conserve son accès sans `FORCE ROW LEVEL SECURITY`. Aucune clé publique Supabase n’est utilisée par l’application. La migration ne touche pas les autres tables et son retour arrière est bloqué pour éviter de rouvrir les données. Voir la [documentation Supabase RLS](https://supabase.com/docs/guides/database/postgres/row-level-security).

HTTPS est imposé par Fly. Symfony fait confiance aux adresses privées du proxy pour `X-Forwarded-Proto` uniquement ; les en-têtes de host et port transmis ne sont pas acceptés. En local `TRUSTED_PROXIES` est vide. La limitation de connexion utilise l’adresse du proxy : son quota global peut donc être partagé entre utilisateurs, choix conservateur pour cette petite application administrative. Sources : [proxies Symfony 8.1](https://symfony.com/doc/8.1/deployment/proxies.html), [en-têtes Fly](https://fly.io/docs/networking/request-headers/) et [configuration Fly](https://fly.io/docs/reference/configuration/).
