.PHONY: help build up down restart logs shell tinker migrate fresh seed test queue-monitor redis-cli clean

# Variables
DOCKER_COMPOSE = docker compose -f docker-compose.prod.yml
APP_CONTAINER = app
QUEUE_CONTAINER = queue

help: ## Muestra esta ayuda
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Construye las imágenes Docker
	$(DOCKER_COMPOSE) build --no-cache

up: ## Inicia todos los contenedores
	$(DOCKER_COMPOSE) up -d
	@echo "✅ Todos los servicios iniciados"
	@echo "🌐 Aplicación: http://localhost"
	@echo "🗄️  PostgreSQL: localhost:5434"
	@echo "🔴 Redis: localhost:6379"

down: ## Detiene todos los contenedores
	$(DOCKER_COMPOSE) down

restart: ## Reinicia todos los contenedores
	$(DOCKER_COMPOSE) restart

restart-queue: ## Reinicia solo el queue worker (importante después de cambios en Jobs)
	$(DOCKER_COMPOSE) restart $(QUEUE_CONTAINER)
	@echo "✅ Queue worker reiniciado"

logs: ## Muestra logs de todos los servicios
	$(DOCKER_COMPOSE) logs -f

logs-app: ## Muestra logs del contenedor app
	$(DOCKER_COMPOSE) logs -f $(APP_CONTAINER)

logs-queue: ## Muestra logs del queue worker
	$(DOCKER_COMPOSE) logs -f $(QUEUE_CONTAINER)

shell: ## Accede al shell del contenedor app
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) bash

tinker: ## Abre Laravel Tinker
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan tinker

migrate: ## Ejecuta las migraciones
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan migrate --force

fresh: ## Resetea la base de datos y ejecuta migraciones
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan migrate:fresh --force

seed: ## Ejecuta los seeders
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan db:seed --force

test: ## Ejecuta los tests
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan test

optimize: ## Optimiza Laravel (cache config, routes, views)
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan config:cache
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan route:cache
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan view:cache
	@echo "✅ Laravel optimizado"

clear-cache: ## Limpia todos los caches
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan cache:clear
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan config:clear
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan route:clear
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan view:clear
	@echo "✅ Caches limpiados"

queue-monitor: ## Monitorea el estado de la queue
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan flujos:verificar-pendientes

queue-work: ## Ejecuta manualmente el queue worker (para debugging)
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan queue:work --verbose

redis-cli: ## Accede a Redis CLI
	$(DOCKER_COMPOSE) exec redis redis-cli

redis-ping: ## Verifica conexión a Redis
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php artisan tinker --execute="echo Redis::ping() ? 'Redis: CONECTADO' : 'Redis: NO CONECTADO'"

redis-flush: ## Limpia toda la data de Redis (¡CUIDADO!)
	$(DOCKER_COMPOSE) exec redis redis-cli FLUSHALL
	@echo "⚠️  Redis completamente limpiado"

status: ## Muestra el estado de todos los contenedores
	$(DOCKER_COMPOSE) ps

clean: ## Detiene y elimina todos los contenedores, volúmenes y redes
	$(DOCKER_COMPOSE) down -v --remove-orphans
	@echo "🧹 Todo limpiado (incluye volúmenes de base de datos)"

setup: build up migrate seed ## Setup completo (build + up + migrate + seed)
	@echo "✅ Sistema completamente configurado y listo"

ps: status ## Alias para status
