
setup-test-db: ## Create and migrate test database (run once after fresh docker compose up)
	docker compose exec mysql mysql -u root -proot_pass_change_me -e \
		"CREATE DATABASE IF NOT EXISTS fund_transfer_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
		 GRANT ALL PRIVILEGES ON fund_transfer_test.* TO 'app'@'%'; \
		 FLUSH PRIVILEGES;"
	docker compose exec \
		-e DATABASE_URL="mysql://app:app_pass@mysql:3306/fund_transfer_test?serverVersion=8.0&charset=utf8mb4" \
		-e APP_ENV=test \
		php php bin/console doctrine:migrations:migrate --no-interaction
