# Makefile del módulo Omeka-S «OERManager»
# Calidad de código, tests, i18n y empaquetado.
# Docker se gestiona fuera del módulo (el contenedor mapea el dir de trabajo del host).

# Define SED_INPLACE según el sistema operativo
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# ---------------------------------------------------------------------------
# Calidad de código
# ---------------------------------------------------------------------------

# Linter de estilo PHP (PSR-12). Limitado a .php; ignora vendor/assets/tests JS.
lint:
	"vendor/bin/phpcs" . --standard=PSR12 --ignore=vendor/,assets/,node_modules/,test/js/,test/ --colors --extensions=php

# Corrección automática de estilo PHP
fix:
	"vendor/bin/phpcbf" . --standard=PSR12 --ignore=vendor/,assets/,node_modules/,test/js/,test/ --colors --extensions=php

# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

# Tests unitarios (requiere test/phpunit.xml — se crea en fases posteriores)
.PHONY: test
test:
	@echo "Running unit tests..."
	"vendor/bin/phpunit" -c test/phpunit.xml --colors=always --testdox

# Tests del núcleo JS (TASK-028). Runner integrado de Node: sin dependencias
# ni node_modules. Solo cubre asset/js/core/, que es puro por contrato.
# Glob recursivo entre comillas simples para que lo resuelva el propio Node
# (no el shell): en Node 26 pasar un directorio a secas ya no recorre su
# contenido, y esta forma es compatible con versiones anteriores.
.PHONY: test-js
test-js:
	@echo "Running JS core tests..."
	node --test 'test/js/**/*.test.js'

# ---------------------------------------------------------------------------
# Empaquetado
# ---------------------------------------------------------------------------

# Genera el paquete OERManager-X.X.X.zip
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: VERSION not specified. Use 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	@echo "Updating version to $(VERSION) in module.ini..."
	$(SED_INPLACE) 's/^\([[:space:]]*version[[:space:]]*=[[:space:]]*\).*$$/\1"$(VERSION)"/' config/module.ini
	@echo "Creating ZIP archive: OERManager-$(VERSION).zip..."
	composer archive --format=zip --file="OERManager-$(VERSION)-raw"
	@echo "Repacking into proper structure..."
	mkdir -p tmpzip/OERManager && unzip -q OERManager-$(VERSION)-raw.zip -d tmpzip/OERManager && \
	cd tmpzip && zip -qr ../OERManager-$(VERSION).zip OERManager && cd .. && rm -rf tmpzip OERManager-$(VERSION)-raw.zip
	@echo "Restoring version to 0.0.0 in module.ini..."
	$(SED_INPLACE) 's/^\([[:space:]]*version[[:space:]]*=[[:space:]]*\).*$$/\1"0.0.0"/' config/module.ini

# ---------------------------------------------------------------------------
# Traducciones (i18n)
# ---------------------------------------------------------------------------

# Genera template.pot desde translate() y // @translate
generate-pot:
	@echo "Extracting strings using xgettext..."
	find . \( -path ./vendor -o -path ./test/stubs \) -prune -o \( -name '*.php' -o -name '*.phtml' \) -print \
	| xargs xgettext \
	    --language=PHP \
	    --from-code=utf-8 \
	    --keyword=translate \
	    --keyword=translatePlural:1,2 \
	    --output=language/xgettext.pot
	@echo "Extracting strings marked with // @translate..."
	vendor/zerocrates/extract-tagged-strings/extract-tagged-strings.php > language/tagged.pot
	@echo "Merging xgettext.pot and tagged.pot into template.pot..."
	msgcat language/xgettext.pot language/tagged.pot --use-first -o language/template.pot
	@rm -f language/xgettext.pot language/tagged.pot
	@echo "Generated language/template.pot"

# Actualiza todos los .po desde el template .pot
update-po:
	@echo "Updating translation files..."
	@find language -name "*.po" | while read po; do \
		echo "Updating $$po..."; \
		msgmerge --update --backup=off "$$po" language/template.pot; \
	done

# Comprueba cadenas sin traducir
check-untranslated:
	@echo "Checking untranslated strings..."
	@find language -name "*.po" | while read po; do \
		echo "\n$$po:"; \
		msgattrib --untranslated "$$po" | if grep -q msgid; then \
			echo "Warning: Untranslated strings found!"; exit 1; \
		else \
			echo "All strings translated!"; \
		fi \
	done

# Compila todos los .po a .mo
compile-mo:
	@echo "Compiling .po files into .mo..."
	@find language -name '*.po' | while read po; do \
		mo=$${po%.po}.mo; \
		msgfmt "$$po" -o "$$mo"; \
		echo "Compiled $$po -> $$mo"; \
	done

# Flujo i18n completo: pot -> po -> mo
i18n: generate-pot update-po check-untranslated compile-mo

# ---------------------------------------------------------------------------
# Ayuda
# ---------------------------------------------------------------------------

help:
	@echo ""
	@echo "Usage: make <command>"
	@echo ""
	@echo "Code quality:"
	@echo "  lint              - Run PHP linter (PHP_CodeSniffer, PSR-12)"
	@echo "  fix               - Automatically fix PHP code style issues"
	@echo ""
	@echo "Testing:"
	@echo "  test              - Run unit tests with PHPUnit"
	@echo "  test-js           - Run JS core unit tests (node --test)"
	@echo ""
	@echo "Packaging:"
	@echo "  package           - Generate a .zip package of the module with version tag"
	@echo ""
	@echo "Translations (i18n):"
	@echo "  generate-pot      - Extract translatable strings to template.pot"
	@echo "  update-po         - Update .po files from template.pot"
	@echo "  check-untranslated- Check for untranslated strings in .po files"
	@echo "  compile-mo        - Compile .mo files from .po files"
	@echo "  i18n              - Run full translation workflow (generate, update, check, compile)"
	@echo ""
	@echo "Other:"
	@echo "  help              - Show this help message"
	@echo ""

.DEFAULT_GOAL := help