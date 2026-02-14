# ===========================================
# Dubber - Deployment Makefile
# ===========================================
# Local dev:     make dev
# cPanel:        make cpanel-deploy
# RunPod GPU:    make runpod-deploy
# ===========================================

.PHONY: help dev dev-down dev-logs build \
        cpanel-deploy cpanel-push cpanel-migrate cpanel-cache cpanel-logs \
        runpod-deploy runpod-start runpod-stop runpod-logs runpod-status \
        fresh test

# Default
help:
	@echo ""
	@echo "  Dubber Makefile"
	@echo "  ==============="
	@echo ""
	@echo "  Local Development (Docker):"
	@echo "    make dev            - Start full local stack (Docker Compose)"
	@echo "    make dev-runpod     - Start local stack + RunPod GPU (no local AI)"
	@echo "    make dev-down       - Stop local stack"
	@echo "    make dev-logs       - Tail all container logs"
	@echo "    make build          - Build frontend assets"
	@echo "    make fresh          - Fresh migrate + seed"
	@echo ""
	@echo "  cPanel Hosting (Laravel):"
	@echo "    make cpanel-deploy  - Full deploy: build, push, setup"
	@echo "    make cpanel-push    - Git push to origin"
	@echo "    make cpanel-migrate - Run migrations on cPanel (via SSH)"
	@echo "    make cpanel-cache   - Clear and rebuild caches (via SSH)"
	@echo "    make cpanel-logs    - Tail Laravel log (via SSH)"
	@echo ""
	@echo "  RunPod GPU Services:"
	@echo "    make runpod-deploy  - Install deps + start all GPU services"
	@echo "    make runpod-start   - Start GPU services (XTTS + WhisperX)"
	@echo "    make runpod-stop    - Stop GPU services"
	@echo "    make runpod-status  - Check GPU service health"
	@echo "    make runpod-logs    - Tail GPU service logs"
	@echo ""

# ===========================================
# Configuration
# ===========================================

# cPanel SSH (override via: make cpanel-deploy CPANEL_SSH=user@host CPANEL_PATH=~/public_html)
CPANEL_SSH   ?= $(shell grep CPANEL_SSH .env 2>/dev/null | cut -d= -f2)
CPANEL_PATH  ?= $(shell grep CPANEL_PATH .env 2>/dev/null | cut -d= -f2)

# RunPod SSH
RUNPOD_SSH   ?= $(shell grep RUNPOD_SSH_HOST .env 2>/dev/null | cut -d= -f2)
RUNPOD_KEY   ?= $(shell grep RUNPOD_SSH_KEY .env 2>/dev/null | cut -d= -f2)
RUNPOD_SSH_CMD = ssh -i $(RUNPOD_KEY) -o StrictHostKeyChecking=no $(RUNPOD_SSH)

# ===========================================
# Local Development
# ===========================================

dev:
	docker compose up -d --build
	@echo ""
	@echo "App running at http://localhost:8080"

dev-runpod:
	docker compose -f docker-compose.runpod.yml up -d --build
	@echo ""
	@echo "App running at http://localhost:8080 (using RunPod for XTTS/WhisperX)"

dev-down:
	docker compose down
	docker compose -f docker-compose.runpod.yml down 2>/dev/null || true

dev-logs:
	docker compose logs -f --tail=50

build:
	npm install
	npm run build
	@echo "Frontend assets built in public/build/"

fresh:
	docker compose exec app php artisan migrate:fresh --seed --force

test:
	docker compose exec app php artisan test

# ===========================================
# cPanel Deployment
# ===========================================

cpanel-deploy: build cpanel-push
	@if [ -z "$(CPANEL_SSH)" ]; then \
		echo "ERROR: Set CPANEL_SSH in .env (e.g. CPANEL_SSH=user@yourdomain.com)"; \
		echo "       Also set CPANEL_PATH (e.g. CPANEL_PATH=~/public_html)"; \
		exit 1; \
	fi
	ssh $(CPANEL_SSH) "cd $(CPANEL_PATH) && git pull && bash deploy/cpanel-setup.sh"
	@echo ""
	@echo "Deployment complete!"

cpanel-push:
	git push origin main

cpanel-migrate:
	@if [ -z "$(CPANEL_SSH)" ]; then echo "ERROR: Set CPANEL_SSH in .env"; exit 1; fi
	ssh $(CPANEL_SSH) "cd $(CPANEL_PATH) && php artisan migrate --force"

cpanel-cache:
	@if [ -z "$(CPANEL_SSH)" ]; then echo "ERROR: Set CPANEL_SSH in .env"; exit 1; fi
	ssh $(CPANEL_SSH) "cd $(CPANEL_PATH) && php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache"
	@echo "Caches rebuilt."

cpanel-logs:
	@if [ -z "$(CPANEL_SSH)" ]; then echo "ERROR: Set CPANEL_SSH in .env"; exit 1; fi
	ssh $(CPANEL_SSH) "tail -f $(CPANEL_PATH)/storage/logs/laravel.log"

# ===========================================
# RunPod GPU Services
# ===========================================

runpod-deploy:
	@if [ -z "$(RUNPOD_SSH)" ]; then \
		echo "ERROR: Set RUNPOD_SSH_HOST and RUNPOD_SSH_KEY in .env"; \
		exit 1; \
	fi
	php artisan runpod:start --install

runpod-start:
	@if [ -z "$(RUNPOD_SSH)" ]; then \
		echo "ERROR: Set RUNPOD_SSH_HOST and RUNPOD_SSH_KEY in .env"; \
		exit 1; \
	fi
	php artisan runpod:start

runpod-stop:
	@if [ -z "$(RUNPOD_SSH)" ]; then echo "ERROR: Set RUNPOD_SSH_HOST in .env"; exit 1; fi
	$(RUNPOD_SSH_CMD) "pkill -f 'uvicorn.*xtts' || true; pkill -f 'uvicorn.*whisperx' || true"
	@echo "GPU services stopped."

runpod-status:
	@if [ -z "$(RUNPOD_SSH)" ]; then echo "ERROR: Set RUNPOD_SSH_HOST in .env"; exit 1; fi
	@echo "=== XTTS (port 8004) ==="
	@$(RUNPOD_SSH_CMD) "curl -sf http://localhost:8004/health 2>/dev/null | python3 -m json.tool || echo 'NOT RUNNING'"
	@echo ""
	@echo "=== WhisperX (port 8002) ==="
	@$(RUNPOD_SSH_CMD) "curl -sf http://localhost:8002/health 2>/dev/null | python3 -m json.tool || echo 'NOT RUNNING'"
	@echo ""
	@echo "=== Demucs (port 8000) ==="
	@$(RUNPOD_SSH_CMD) "curl -sf http://localhost:8000/health 2>/dev/null | python3 -m json.tool || echo 'NOT RUNNING'"
	@echo ""
	@echo "=== GPU ==="
	@$(RUNPOD_SSH_CMD) "nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader 2>/dev/null || echo 'No GPU'"

runpod-logs:
	@if [ -z "$(RUNPOD_SSH)" ]; then echo "ERROR: Set RUNPOD_SSH_HOST in .env"; exit 1; fi
	$(RUNPOD_SSH_CMD) "tail -f /tmp/xtts.log /tmp/whisperx.log"
