#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-https://staging.example.com}"

printf "==> Verificando healthcheck...\n"
curl -fsS "${BASE_URL}/visibility2/app/diagnostics/healthcheck.php" | jq .

printf "==> Verificando login app...\n"
curl -I "${BASE_URL}/visibility2/app/login.php" | head -n 5

printf "==> Verificando login portal...\n"
curl -I "${BASE_URL}/visibility2/portal/index.php" | head -n 5

printf "==> Verificando sesión guard (espera 302/401)...\n"
curl -I "${BASE_URL}/visibility2/app/index.php" | head -n 5
