SHELL = /bin/bash
.DEFAULT_GOAL := help
HERE := $(dir $(realpath $(firstword $(MAKEFILE_LIST))))

# https://mwop.net/blog/2023-12-11-advent-makefile.html
##@ Help
help:  ## Display this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[0-9a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

.PHONY: all
all: style analyze tests compile ## Do everything

# ### #

.PHONY: style
style: ## Fix any style issues
	@echo
	@echo "--> Style: php-cs-fixer"
	export PHP_CS_FIXER_IGNORE_ENV=1 && vendor/bin/php-cs-fixer fix -v
	@echo

.PHONY: analyze
analyze: ## Static analysis, catch problems in code
	@echo
	@echo "--> Analyze: phpstan"
	vendor/bin/phpstan
	@echo

.PHONY: tests
tests: server-stop server-start ## Pest tests
	@echo
	@echo "--> Tests: Pest"
	# Always stop the test server, even if tests fail
	bash -c "./vendor/bin/pest || php server.php stop"
	@echo
	@echo "--> Blogstream Server: stopping"
	@echo
	php server.php stop

.PHONY: server-start
server-start: ## Start the blogstream server
	@echo
	@echo "--> Blogstream Server: starting"
	@echo
	php server.php start -d

.PHONY: server-stop
server-stop: ## Stop the blogstream server
	@echo
	@echo "--> Blogstream Server: stopping"
	@echo
	php server.php stop

.PHONY: compile
compile: ## Compile the Phar file as bin/blogstream.phar
	@echo
	@echo "--> Compiling bin/blogstream.phar"
	@echo
	box compile
