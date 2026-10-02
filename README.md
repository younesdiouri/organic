# Organic To Go — MVP local

Application d’administration française pour un restaurant, Symfony 7.4 LTS / PHP 8.4, Twig, Bootstrap 5.3.8, Doctrine ORM et PostgreSQL 17. Développement local uniquement. Aucun déploiement, service externe ou donnée privée importée.

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

Composer est installé depuis l’image officielle `composer:2.8`. Le lockfile fixe les dépendances. Les scripts Composer sont désactivés et seul le plugin officiel `symfony/runtime`, qui génère le démarrage du runtime, est explicitement autorisé. Aucun Flex, script tiers ou installation JavaScript. Bootstrap est servi localement, sans CDN au chargement des pages.

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

Les listes et le rapport restent simples pour un seul restaurant : pas de pagination et solde d’ouverture calculé en mémoire. Ajouter pagination et agrégats SQL lorsque le volume le justifie. Aucun moteur de tarification, inventaire global, portail client, facture/taxe, synchronisation Sheets ou architecture supplémentaire.

Configuration vérifiée avec la documentation officielle [Symfony 7.4 setup](https://symfony.com/doc/7.4/setup.html), [security](https://symfony.com/doc/7.4/security.html) et [forms](https://symfony.com/doc/7.4/forms.html).
