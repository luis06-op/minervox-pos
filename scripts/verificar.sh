#!/usr/bin/env bash
# Verificación rápida antes de hacer commit:
#   - sintaxis de todos los PHP
#   - sintaxis de los JavaScript (si Node está instalado)
set -euo pipefail

cd "$(dirname "$0")/.."

echo "→ Revisando PHP..."
while IFS= read -r archivo; do
    php -l "$archivo" > /dev/null
done < <(find . -name '*.php' -not -path './.git/*')
echo "  PHP OK"

if command -v node > /dev/null 2>&1; then
    echo "→ Revisando JavaScript..."
    node --check assets/app.js
    node --check assets/print_templates.js
    echo "  JavaScript OK"
else
    echo "  Node no está instalado; se omite la revisión de JavaScript."
fi

echo "✓ Verificación completada."
