#!/bin/bash

# Script para probar autenticación con Docker
# Uso: ./test-auth.sh

set -e

echo "======================================"
echo "🧪 Test de Autenticación - Docker"
echo "======================================"
echo ""

BACKEND_URL="http://localhost:8080"
EMAIL="admin@test.com"
PASSWORD="password123"
COOKIES_FILE="/tmp/test-auth-cookies.txt"

# Limpiar cookies anteriores
rm -f "$COOKIES_FILE"

echo "1️⃣  Obteniendo CSRF token..."
CSRF_RESPONSE=$(curl -s -X GET "$BACKEND_URL/sanctum/csrf-cookie" \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "Referer: http://localhost:5173/" \
  -c "$COOKIES_FILE" -w "\n%{http_code}")

HTTP_CODE=$(echo "$CSRF_RESPONSE" | tail -n1)

if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 204 ]; then
  echo "   ✅ CSRF token obtenido (HTTP $HTTP_CODE)"
else
  echo "   ❌ Error obteniendo CSRF token (HTTP $HTTP_CODE)"
  exit 1
fi

echo ""
echo "2️⃣  Haciendo login..."
LOGIN_RESPONSE=$(curl -s -X POST "$BACKEND_URL/api/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "Referer: http://localhost:5173/" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" \
  -b "$COOKIES_FILE" \
  -c "$COOKIES_FILE" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$LOGIN_RESPONSE" | tail -n1)
RESPONSE_BODY=$(echo "$LOGIN_RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 200 ]; then
  echo "   ✅ Login exitoso (HTTP $HTTP_CODE)"
  echo ""
  echo "   Respuesta:"
  echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE_BODY"
else
  echo "   ❌ Error en login (HTTP $HTTP_CODE)"
  echo ""
  echo "   Respuesta:"
  echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE_BODY"
  exit 1
fi

echo ""
echo "3️⃣  Obteniendo usuario actual (/api/me)..."
ME_RESPONSE=$(curl -s -X GET "$BACKEND_URL/api/me" \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "X-Requested-With: XMLHttpRequest" \
  -b "$COOKIES_FILE" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$ME_RESPONSE" | tail -n1)
RESPONSE_BODY=$(echo "$ME_RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 200 ]; then
  echo "   ✅ Usuario obtenido (HTTP $HTTP_CODE)"
  echo ""
  echo "   Respuesta:"
  echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE_BODY"
else
  echo "   ❌ Error obteniendo usuario (HTTP $HTTP_CODE)"
  echo ""
  echo "   Respuesta:"
  echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE_BODY"
  exit 1
fi

echo ""
echo "4️⃣  Haciendo logout..."
LOGOUT_RESPONSE=$(curl -s -X POST "$BACKEND_URL/api/logout" \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "X-Requested-With: XMLHttpRequest" \
  -b "$COOKIES_FILE" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$LOGOUT_RESPONSE" | tail -n1)
RESPONSE_BODY=$(echo "$LOGOUT_RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 200 ]; then
  echo "   ✅ Logout exitoso (HTTP $HTTP_CODE)"
  echo ""
  echo "   Respuesta:"
  echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE_BODY"
else
  echo "   ❌ Error en logout (HTTP $HTTP_CODE)"
  echo ""
  echo "   Respuesta:"
  echo "$RESPONSE_BODY" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE_BODY"
  exit 1
fi

echo ""
echo "======================================"
echo "✅ TODOS LOS TESTS PASARON"
echo "======================================"
echo ""
echo "El backend está configurado correctamente para autenticación."
echo "Ahora puedes configurar el frontend siguiendo CONFIGURACION-FRONTEND.md"
echo ""

# Limpiar cookies
rm -f "$COOKIES_FILE"
