#!/bin/bash

# Script para probar autenticación Sanctum SPA
# Este script simula el flujo correcto que debe seguir el frontend

echo "========================================="
echo "Paso 1: Obtener CSRF cookie"
echo "========================================="

# Paso 1: Obtener CSRF cookie
curl -s -X GET http://localhost:8000/sanctum/csrf-cookie \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "Referer: http://localhost:5173" \
  -c /tmp/sanctum-cookies.txt

echo "✅ CSRF cookie obtenida"
echo ""

# Leer el XSRF-TOKEN de las cookies
XSRF_TOKEN=$(grep XSRF-TOKEN /tmp/sanctum-cookies.txt | awk '{print $7}')

# URL decode el token
XSRF_TOKEN=$(echo "$XSRF_TOKEN" | python3 -c "import sys; from urllib.parse import unquote; print(unquote(sys.stdin.read().strip()))")

echo "========================================="
echo "Paso 2: Login con CSRF token"
echo "========================================="

# Paso 2: Hacer login con el CSRF token
LOGIN_RESPONSE=$(curl -s -i -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "Referer: http://localhost:5173" \
  -H "X-XSRF-TOKEN: $XSRF_TOKEN" \
  -b /tmp/sanctum-cookies.txt \
  -c /tmp/sanctum-cookies.txt \
  -d '{"email":"admin@example.com","password":"password"}')

echo "$LOGIN_RESPONSE"
echo ""

# Verificar si el login fue exitoso (código 200)
if echo "$LOGIN_RESPONSE" | grep -q "HTTP/1.1 200"; then
    echo "✅ Login exitoso"
else
    echo "❌ Login falló"
    exit 1
fi

echo ""
echo "========================================="
echo "Paso 3: Obtener usuario autenticado (/me)"
echo "========================================="

# Actualizar el XSRF token después del login
XSRF_TOKEN=$(grep XSRF-TOKEN /tmp/sanctum-cookies.txt | awk '{print $7}')
XSRF_TOKEN=$(echo "$XSRF_TOKEN" | python3 -c "import sys; from urllib.parse import unquote; print(unquote(sys.stdin.read().strip()))")

# Paso 3: Obtener usuario autenticado
ME_RESPONSE=$(curl -s -i -X GET http://localhost:8000/api/me \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "Referer: http://localhost:5173" \
  -H "X-XSRF-TOKEN: $XSRF_TOKEN" \
  -b /tmp/sanctum-cookies.txt)

echo "$ME_RESPONSE"
echo ""

# Verificar si /me fue exitoso
if echo "$ME_RESPONSE" | grep -q "HTTP/1.1 200"; then
    echo "✅ Usuario autenticado correctamente"
else
    echo "❌ Falló la obtención del usuario"
fi

echo ""
echo "========================================="
echo "Cookies guardadas en /tmp/sanctum-cookies.txt:"
echo "========================================="
cat /tmp/sanctum-cookies.txt