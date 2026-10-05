.DEFAULT_GOAL := help

COMPOSE = rtk proxy docker compose -p organic --env-file .env.local

.PHONY: help up down build stop restart ps logs shell install migrate cache-clear cs-fix quality test

help:
	@rtk proxy printf '%s\n' \
	  'Commandes locales Organic (après la préparation décrite dans le README) :' \
	  '  make up           Démarrer les services en arrière-plan' \
	  '  make down         Arrêter et supprimer les conteneurs, conserver la base' \
	  '  make build        Construire les images' \
	  '  make stop         Arrêter les services sans supprimer les conteneurs' \
	  '  make restart      Redémarrer les services' \
	  '  make ps           Afficher les services' \
	  '  make logs         Suivre les journaux (Ctrl+C pour quitter)' \
	  '  make shell        Ouvrir un shell dans le conteneur web' \
	  '  make install      Installer les dépendances Composer' \
	  '  make migrate      Appliquer les migrations' \
	  '  make cache-clear  Vider le cache Symfony' \
	  '  make cs-fix       Formater le PHP' \
	  '  make quality      Vérifier le format et PHPStan' \
	  '  make test         Exécuter les tests dans organic_test'

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

build:
	$(COMPOSE) build

stop:
	$(COMPOSE) stop

restart:
	$(COMPOSE) restart

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=100

shell:
	$(COMPOSE) exec web sh

install:
	$(COMPOSE) exec -T web composer install --no-scripts --no-interaction

migrate:
	$(COMPOSE) exec -T --user www-data web php bin/console doctrine:migrations:migrate --no-interaction

cache-clear:
	$(COMPOSE) exec -T --user www-data web php bin/console cache:clear

cs-fix:
	$(COMPOSE) exec -T web composer cs:fix

quality:
	$(COMPOSE) exec -T web composer quality

test:
	rtk proxy ./bin/test
