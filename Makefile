UID := $(shell id -u)
GID := $(shell id -g)

build:
	docker compose build

install:
	UID=$(UID) GID=$(GID) docker compose run --rm test composer install

test:
	UID=$(UID) GID=$(GID) docker compose run --rm test vendor/bin/phpunit

pint:
	UID=$(UID) GID=$(GID) docker compose run --rm test vendor/bin/pint --test

phpstan:
	UID=$(UID) GID=$(GID) docker compose run --rm test vendor/bin/phpstan analyse --memory-limit=512M
