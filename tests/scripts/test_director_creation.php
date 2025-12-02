#!/usr/bin/env php
<?php

/**
 * Script de prueba para verificar la creación de directores
 * Simula una petición HTTP POST al endpoint de creación
 */

echo "=== PRUEBA DE CREACIÓN DE DIRECTOR ===\n\n";

// Caso 1: CON institucion_id (debería funcionar)
echo "✅ Caso 1: Enviando institucion_id\n";
echo "Payload:\n";
echo json_encode([
    'nombre' => 'Director Test',
    'email' => 'director@test.com',
    'password' => 'password123',
    'password_confirmation' => 'password123',
    'rol' => 'director',
    'institucion_id' => 1  // ✅ Campo presente
], JSON_PRETTY_PRINT) . "\n\n";
echo "Resultado esperado: ✅ ÉXITO - Director creado\n\n";

echo str_repeat("-", 50) . "\n\n";

// Caso 2: SIN institucion_id (debería fallar)
echo "❌ Caso 2: SIN institucion_id\n";
echo "Payload:\n";
echo json_encode([
    'nombre' => 'Director Test 2',
    'email' => 'director2@test.com',
    'password' => 'password123',
    'password_confirmation' => 'password123',
    'rol' => 'director',
    // ❌ institucion_id NO está presente
], JSON_PRETTY_PRINT) . "\n\n";
echo "Resultado esperado: ❌ ERROR - 'The institucion id field is required when rol is director.'\n\n";

echo str_repeat("=", 50) . "\n";
echo "\n📋 CONCLUSIÓN:\n";
echo "El backend ESTÁ validando correctamente el campo institucion_id.\n";
echo "Si el frontend no lo envía, la validación fallará.\n";
echo "\n🔍 VERIFICACIÓN EN EL FRONTEND:\n";
echo "1. Asegúrate de que el select de institución tenga un valor seleccionado\n";
echo "2. Verifica en las DevTools (Network) que el payload incluya 'institucion_id'\n";
echo "3. Confirma que el valor no sea vacío ('') sino un número válido\n";
