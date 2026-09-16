.PHONY: help test coverage scan all up down bootstrap \
	infra-init infra-plan infra-apply infra-destroy \
	k8s-up k8s-down k8s-urls k8s-status eks-kubeconfig

-include .env
export

COMPOSE      = docker compose
COMPOSE_TEST = docker compose -f docker-compose.test.yml

TF_DIR            = infra
NAMESPACE         = postech
IMAGE_TAG        ?= latest
AWS_REGION       ?= us-east-1
EKS_CLUSTER_NAME ?= techchallenge-eks
TF_STATE_BUCKET  ?=
TF_LOCK_TABLE    ?=

## Exibe esta ajuda
help:
	@grep -E '^[a-zA-Z_-]+:.*?##' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

## ── Ambiente de desenvolvimento ──────────────────────────────────────────────

bootstrap: ## Setup completo do zero (env, build, deps, chaves e migrate)
	@if [ ! -f .env ]; then cp .env.example .env; fi
	$(COMPOSE) up -d --build
	$(COMPOSE) exec app-php composer install
	$(COMPOSE) exec app-php php artisan key:generate --force
	$(COMPOSE) exec app-php php artisan jwt:secret --force
	@i=0; until $(COMPOSE) exec app-php php artisan migrate --force; do \
		i=$$((i+1)); \
		if [ $$i -ge 20 ]; then \
			echo "Erro: banco indisponivel apos varias tentativas."; \
			exit 1; \
		fi; \
		echo "Aguardando banco iniciar..."; \
		sleep 3; \
	done

up: ## Sobe os containers de desenvolvimento (app, db, swagger, sonar)
	@if [ ! -f .env ]; then cp .env.example .env; fi
	$(COMPOSE) up -d
	@if [ -z "$(JWT_SECRET)" ] || [ "$(JWT_SECRET)" = "generate_random" ]; then \
		echo ""; \
		echo "[jwt] JWT_SECRET ausente ou invalido. Aguardando container e gerando chave..."; \
		sleep 5; \
		$(COMPOSE) exec app-php php artisan jwt:secret --force; \
		$(COMPOSE) up -d --force-recreate app-php; \
		echo "[jwt] Chave gerada e container reiniciado."; \
	fi

down: ## Para e remove os containers de desenvolvimento
	$(COMPOSE) down

migrate:
	$(COMPOSE) exec app-php php artisan migrate --force

seed: ## Roda as seeders no ambiente de desenvolvimento
	$(COMPOSE) exec app-php php artisan db:seed --force

seed-dev: ## Roda apenas o DevDataSeeder (dados de desenvolvimento)
	$(COMPOSE) exec app-php php artisan db:seed --class=DevDataSeeder --force

migrate-seed: ## Roda as migrations e em seguida as seeders
	$(COMPOSE) exec app-php php artisan migrate --force
	$(COMPOSE) exec app-php php artisan db:seed --force

## ── Testes ───────────────────────────────────────────────────────────────────

test: ## Roda a suíte de testes sem gerar coverage (mais rápido)
	$(COMPOSE_TEST) run --rm app-test sh -c "\
		php artisan config:clear && \
		php artisan migrate --force && \
		vendor/bin/phpunit --no-coverage"
	$(COMPOSE_TEST) down

coverage: ## Roda os testes e gera coverage.xml (PCOV)
	$(COMPOSE_TEST) up --build --abort-on-container-exit app-test
	$(COMPOSE_TEST) down

## ── Qualidade de código ──────────────────────────────────────────────────────

lint: ## Formata o código com Laravel Pint
	$(COMPOSE) exec app-php ./vendor/bin/pint

## ── SonarQube ────────────────────────────────────────────────────────────────

scan: ## Roda o sonar-scanner (requer SONAR_TOKEN e SonarQube em pé)
	@if [ -z "$$SONAR_TOKEN" ]; then \
		echo "\033[31mErro: variável SONAR_TOKEN não definida.\033[0m"; \
		echo "  Exemplo: SONAR_TOKEN=sqa_xxx make scan"; \
		exit 1; \
	fi
	$(COMPOSE) run --rm sonar-scanner

## ── ZAP DAST ─────────────────────────────────────────────────────────────────

zap-scan: ## Roda o ZAP passive scan autenticado (requer ZAP_EMAIL e ZAP_PASSWORD)
	@if [ -z "$$ZAP_EMAIL" ] || [ -z "$$ZAP_PASSWORD" ]; then \
		echo "\033[31mErro: ZAP_EMAIL e ZAP_PASSWORD são obrigatórios.\033[0m"; \
		echo "  Exemplo: ZAP_EMAIL=dev@example.com ZAP_PASSWORD=password make zap-scan"; \
		exit 1; \
	fi
	@mkdir -p zap/reports
	@chmod 777 zap/reports
	@echo "Subindo API e aguardando healthcheck..."
	$(COMPOSE) up -d --wait app-php db
	@echo "API disponivel. Iniciando ZAP scan autenticado..."
	$(COMPOSE) --profile zap run --rm \
		-e ZAP_EMAIL="$$ZAP_EMAIL" \
		-e ZAP_PASSWORD="$$ZAP_PASSWORD" \
		-e ZAP_ACTIVE_SCAN="$$ZAP_ACTIVE_SCAN" \
		zap
	@echo "\033[32mRelatorio gerado em zap/reports/report.html\033[0m"

zap-scan-full: ## Roda o ZAP scan completo com active scan (~3 GB RAM, 30+ min)
	ZAP_ACTIVE_SCAN=true $(MAKE) zap-scan

## ── Deploy em EKS (Terraform) ────────────────────────────────────────────────
#
# Pré-requisito: os repositórios infra-kubernetes e infra-database já aplicados
# (o EKS e o RDS precisam existir antes deste). Ver os READMEs de cada um.

eks-kubeconfig: ## Configura o kubectl para falar com o cluster EKS (requer credenciais AWS válidas)
	aws eks update-kubeconfig --name $(EKS_CLUSTER_NAME) --region $(AWS_REGION)

infra-init: ## terraform init em infra/ (backend S3 remoto, compartilhado com infra-kubernetes/infra-database)
	@if [ -z "$(TF_STATE_BUCKET)" ] || [ -z "$(TF_LOCK_TABLE)" ]; then \
		echo "\033[31mErro: defina TF_STATE_BUCKET e TF_LOCK_TABLE (os mesmos valores usados nos repositórios infra-kubernetes e infra-database).\033[0m"; \
		echo "  Exemplo: TF_STATE_BUCKET=meu-bucket TF_LOCK_TABLE=minha-tabela make infra-init"; \
		exit 1; \
	fi
	@if [ ! -f $(TF_DIR)/terraform.tfvars ]; then \
		echo "\033[31mErro: $(TF_DIR)/terraform.tfvars não encontrado.\033[0m"; \
		echo "  Crie o arquivo com tf_state_bucket, app_key, db_password, jwt_secret, customer_jwt_secret, ghcr_username, ghcr_token etc."; \
		exit 1; \
	fi
	cd $(TF_DIR) && terraform init \
		-backend-config="bucket=$(TF_STATE_BUCKET)" \
		-backend-config="key=app/terraform.tfstate" \
		-backend-config="region=$(AWS_REGION)" \
		-backend-config="dynamodb_table=$(TF_LOCK_TABLE)"

infra-plan: ## terraform plan (aceita IMAGE_TAG=<tag>, default: latest)
	cd $(TF_DIR) && terraform plan -var="image_tag=$(IMAGE_TAG)"

infra-apply: ## terraform apply (aceita IMAGE_TAG=<tag>, default: latest)
	cd $(TF_DIR) && terraform apply -var="image_tag=$(IMAGE_TAG)"

infra-destroy: ## Remove toda a stack provisionada por este repositório no EKS (não afeta o RDS nem o cluster em si)
	cd $(TF_DIR) && terraform destroy

k8s-up: infra-init infra-apply ## infra-init + infra-apply em sequência
	@$(MAKE) k8s-urls

k8s-down: infra-destroy ## Alias de infra-destroy

k8s-status: ## Mostra pods e services do namespace (requer 'make eks-kubeconfig' antes)
	kubectl get pods -n $(NAMESPACE)
	kubectl get svc -n $(NAMESPACE)

k8s-urls: ## Exibe as URLs de acesso (Network Load Balancer real da AWS — sem tunnel, mas o DNS pode levar alguns minutos para propagar)
	@app_host=$$(kubectl get svc postech-app -n $(NAMESPACE) -o jsonpath='{.status.loadBalancer.ingress[0].hostname}'); \
	if [ -z "$$app_host" ]; then \
		echo "\033[31mEXTERNAL-IP/hostname ainda vazio. O NLB da AWS leva alguns minutos para ficar pronto; tente de novo em breve.\033[0m"; \
		exit 1; \
	fi; \
	echo "App:     http://$$app_host:$$(kubectl get svc postech-app -n $(NAMESPACE) -o jsonpath='{.spec.ports[0].port}')"; \
	echo "Swagger: http://$$(kubectl get svc swagger-ui -n $(NAMESPACE) -o jsonpath='{.status.loadBalancer.ingress[0].hostname}'):$$(kubectl get svc swagger-ui -n $(NAMESPACE) -o jsonpath='{.spec.ports[0].port}')"

## ── Atalhos combinados ───────────────────────────────────────────────────────

ci: coverage scan ## Gera coverage e em seguida roda o scanner (pipeline CI)

all: up coverage scan ## Sobe ambiente, gera coverage e roda o scanner
