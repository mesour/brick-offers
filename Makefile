info:
	@make ports
	@echo "For more information run \`make help\`\n"

help:
	@make ports

	@echo ""
	@echo "### PhpStorm Debug Setup ###"
	@echo ""
	@echo "1. Open PhpStorm settings and go to 'PHP -> Debug'."
	@awk '/services:/ {in_services=1} in_services && /php:/ {in_php=1} in_php && /ports:/ {sub(/.*ports:[ ]*/, ""); gsub(/"/, ""); if(length($$0)) {split($$0,a,":"); print a[1]; exit} while(getline line && line ~ /^[ \t]*-[ \t]*/) {sub(/^[ \t]*-[ \t]*/, "", line); gsub(/"/, "", line); split(line,a,":"); print "   - Add port " a[1] " for Xdebug."; exit} exit}' docker-compose.yml
	@echo ""
	@echo "2. Go to 'PHP -> Servers' and add a new server:"
	@echo "   - Name: MyServer"
	@echo "   - Host: localhost"
	@echo "   - Check 'Use path mappings'"
	@echo "   - Set 'Absolute path on server' for project root to '/var/www/html'"
	@echo ""
	@echo "3. Add a PHP CLI Interpreter in 'PHP' settings:"
	@echo "   - Click '...' to add a new interpreter."
	@echo "   - Choose 'From Docker, Vagrant, VM, ...' and select 'Docker Compose'."
	@echo "   - Select the service running Xdebug (usually 'php')."
	@echo "   - Click 'OK'."
	@echo ""
	@echo "4. In interpreter details, enable:"
	@echo "   - 'Connect to existing container (docker compose exec)'"
	@echo "   - Click 'OK' to finish setup."
	@echo ""
	@echo "5. Start listening for PHP debug connections in PhpStorm:"
	@echo "   - Click the bug icon either at the top-right or bottom status bar."

ports:
	@awk '/services:/ {in_services=1} in_services && /nginx:/ {in_nginx=1} in_nginx && /ports:/ {sub(/.*ports:[ ]*/, ""); gsub(/"/, ""); if(length($$0)) {split($$0,a,":"); print a[1]; exit} while(getline line && line ~ /^[ \t]*-[ \t]*/) {sub(/^[ \t]*-[ \t]*/, "", line); gsub(/"/, "", line); split(line,a,":"); print "\nNginx on http://localhost:"a[1]; exit} exit}' docker-compose.yml
	@awk '/services:/ {in_services=1} in_services && /php:/ {in_php=1} in_php && /ports:/ {sub(/.*ports:[ ]*/, ""); gsub(/"/, ""); if(length($$0)) {split($$0,a,":"); print a[1]; exit} while(getline line && line ~ /^[ \t]*-[ \t]*/) {sub(/^[ \t]*-[ \t]*/, "", line); gsub(/"/, "", line); split(line,a,":"); print "XDebug on http://localhost:"a[1] "\n"; exit} exit}' docker-compose.yml
	@awk '/services:/ {in_services=1} in_services && /adminer:/ {in_adminer=1} in_adminer && /ports:/ {sub(/.*ports:[ ]*/, ""); gsub(/"/, ""); if(length($$0)) {split($$0,a,":"); print a[1]; exit} while(getline line && line ~ /^[ \t]*-[ \t]*/) {sub(/^[ \t]*-[ \t]*/, "", line); gsub(/"/, "", line); split(line,a,":"); print "Adminer on http://localhost:"a[1] "\n"; exit} exit}' docker-compose.yml

dependencies:
	@if [ ! -f .env ]; then \
		echo "The .env file does not exist. Create it as a copy of .env.template and fill in your information if necessary."; \
		exit 1; \
	fi

init:
	@make dependencies
	docker compose up -d
	make info

docker:
	make info
	docker compose exec -it php /bin/bash

claude:
	docker compose exec -it claude /bin/bash

rebuild:
	@make dependencies
	docker compose up -d --build
	make info

composer:
	docker compose exec -it php sh -c "composer install"

cs:
	docker compose exec -it php sh -c "composer run cs"

cs-fix:
	docker compose exec -it php sh -c "composer run cs-fix"

phpstan:
	docker compose exec -it php sh -c "composer run phpstan"

phpunit:
	docker compose exec -it php sh -c "composer run tests"
