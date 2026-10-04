---
name: symfony-docs
description: Consulte la documentation officielle Symfony sur symfony.com/doc avant d'écrire du code qui touche à un composant Symfony. À utiliser dès qu'il est question de sécurité/firewall/authenticator, validation, sérialisation, Doctrine/DoctrineBundle, Messenger, HttpClient, formulaires, cache, console, DI/autowiring, routing, mailer, rate limiter, workflow, uid, clock, ou d'un bundle tiers de l'écosystème (Lexik JWT, KnpU OAuth2, MakerBundle) — et systématiquement avant de coder soi-même quelque chose que Symfony fournit déjà.
---

# Documentation Symfony

Ce projet a une règle : **on utilise ce que Symfony fournit, on ne le réécrit pas.** Ce skill
existe pour que cette règle s'appuie sur la doc courante plutôt que sur des souvenirs.

## Version de référence

Organic tourne sur **Symfony 8.1** et PHP 8.4, dans Docker uniquement. Lire d’abord
`composer.json` et `composer.lock`, puis la documentation de la version installée :
`https://symfony.com/doc/8.1/…`. Vérifier la version stable sur `https://symfony.com/releases`
avant une mise à niveau. `current` peut changer ; contrôler la version affichée et adapter
l’URL. Ne pas reprendre une configuration d’une autre version sans la vérifier.

## Quand déclencher

Avant d'écrire la première ligne, dès que la tâche touche :

- **Sécurité** — firewall, authenticator, provider, voter, hachage de mot de passe, rôles,
  `#[IsGranted]`, `access_control`, login programmatique, impersonation.
- **Validation** — contraintes, groupes, validateurs sur mesure, `#[MapRequestPayload]`.
- **Doctrine** — mapping, types personnalisés, verrous, migrations, lazy/eager, filtres.
- **Sérialisation** — normalizers, contextes, groupes, `#[Groups]`, `#[SerializedName]`.
- **Le reste du framework** — Messenger, HttpClient, Console, DI, Routing, Cache, Mailer,
  RateLimiter, Workflow, Uid, Clock, Lock, Notifier.
- **Un bundle de l'écosystème** — LexikJWTAuthenticationBundle, KnpUOAuth2ClientBundle,
  MakerBundle, DoctrineMigrationsBundle.
- **Toute intention de coder à la main** un mécanisme qui ressemble à un service Symfony.

## Comment procéder

1. **Aller à la page canonique.** Les entrées les plus utiles ici :

   | Sujet | URL |
   |---|---|
   | Sécurité (page maîtresse) | `https://symfony.com/doc/8.1/security.html` |
   | Authenticators sur mesure | `https://symfony.com/doc/8.1/security/custom_authenticator.html` |
   | User provider | `https://symfony.com/doc/8.1/security/user_providers.html` |
   | Voters | `https://symfony.com/doc/8.1/security/voters.html` |
   | Access token / API | `https://symfony.com/doc/8.1/security/access_token.html` |
   | Validation | `https://symfony.com/doc/8.1/validation.html` |
   | Contraintes disponibles | `https://symfony.com/doc/8.1/reference/constraints.html` |
   | Serializer | `https://symfony.com/doc/8.1/serializer.html` |
   | Doctrine | `https://symfony.com/doc/8.1/doctrine.html` |
   | Types Doctrine personnalisés | `https://symfony.com/doc/8.1/doctrine/custom_dbal_type.html` |
   | Messenger | `https://symfony.com/doc/8.1/messenger.html` |
   | Injection de dépendances | `https://symfony.com/doc/8.1/service_container.html` |
   | Contrôleurs et arguments | `https://symfony.com/doc/8.1/controller.html` |
   | Value resolvers | `https://symfony.com/doc/8.1/controller/value_resolver.html` |
   | Configuration / secrets | `https://symfony.com/doc/8.1/configuration/secrets.html` |
   | Rate limiter | `https://symfony.com/doc/8.1/rate_limiter.html` |
   | MakerBundle | `https://symfony.com/doc/8.1/bundles/SymfonyMakerBundle/index.html` |
   | Lexik JWT | `https://github.com/lexik/LexikJWTAuthenticationBundle/blob/3.x/README.md` |
   | KnpU OAuth2 Client | `https://github.com/knpuniversity/oauth2-client-bundle/blob/main/README.md` |

   Si le sujet n'est pas dans cette table, chercher d'abord sur
   `https://symfony.com/search?q='terme'`, puis se rabattre sur une recherche web.

2. **Lire, ne pas deviner.** Récupérer la page (outil web) et en extraire la configuration et
   les noms de classes/services réels. Les noms de services Symfony changent entre versions
   majeures ; les inventer produit un échec silencieux à l'exécution.

3. **Vérifier ce qui est déjà installé** avant de conclure qu'il faut écrire du code :

   ```bash
   rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console debug:container 'terme'      # un service existe-t-il déjà ?
   rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console debug:autowiring 'terme'     # quel type injecter ?
   rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console debug:config 'bundle'        # config effective d'un bundle
   rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console debug:router                 # routes réellement exposées
   rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console debug:firewall main           # authenticators actifs sur un firewall
   rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console debug:event-dispatcher       # à quel événement se brancher
   rtk docker compose --env-file .env.local exec -T --user www-data web php bin/console lint:container
   ```

4. **Réutiliser les composants déjà installés.** MakerBundle n’est pas installé dans
   Organic. Ne pas ajouter un bundle ou un outil pour produire quelques lignes. S’il est
   déjà disponible dans le projet, ses générateurs peuvent servir de référence ; toujours
   adapter leur sortie aux conventions locales.

5. **Citer sa source.** Quand une décision découle de la doc, mentionner l'URL dans le message
   de commit ou le commentaire. La prochaine session saura d'où vient le choix.

## Arbitrage

Choisir le mécanisme Symfony natif le plus simple qui respecte la demande et le code
existant. Organic utilise `form_login`, les formulaires et la validation Symfony ; aucun
portail API, JWT ou OAuth n’est nécessaire. Demander une précision seulement si le choix
affecte réellement le périmètre ou le comportement attendu. Les instructions de l’utilisateur
et `AGENTS.md` prévalent. Ne pas modifier le projet source grrind.

## Anti-exemples issus du projet source grrind

- Un VO `Email` avec `filter_var` là où `#[Assert\Email]` faisait le travail.
- Un port `PasswordHasher` maison enveloppant `UserPasswordHasherInterface`.
- Un `LogInHandler` vérifiant le mot de passe à la main au lieu de `json_login`.
- `getRoles()` retournant `['ROLE_USER']` en dur au lieu d'une colonne `roles`.
- Un `#[AsEventListener(event: JWTNotFoundEvent::class)]` qui ne se déclenche jamais : Lexik
  dispatche sous un **nom**, pas sous la classe. Lire le README du bundle, pas l'inférer.
