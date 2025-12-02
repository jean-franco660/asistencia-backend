# Scripts de Prueba y Diagnóstico

Esta carpeta contiene scripts PHP para probar y diagnosticar el sistema de instituciones y directores.

## 📋 Scripts disponibles

### 1. `diagnostico_director.php`
**Propósito:** Diagnosticar qué directores tienen instituciones asignadas.

**Uso:**
```bash
php tests/scripts/diagnostico_director.php
```

**Salida:**
- Lista todos los directores registrados
- Muestra qué instituciones tiene asignadas cada uno
- Identifica directores sin instituciones

---

### 2. `test_director_creation.php`
**Propósito:** Documentar cómo funciona la validación al crear directores.

**Uso:**
```bash
php tests/scripts/test_director_creation.php
```

**Salida:**
- Muestra ejemplos de payloads correctos e incorrectos
- Explica qué campos son obligatorios
- Documenta el comportamiento esperado

---

### 3. `test_endpoint_me.php`
**Propósito:** Verificar que el endpoint `/me` devuelve las instituciones correctamente.

**Uso:**
```bash
php tests/scripts/test_endpoint_me.php
```

**Salida:**
- Simula una petición al endpoint `/me`
- Muestra la respuesta JSON esperada
- Verifica que incluya las instituciones del director

---

### 4. `verificacion_rutas.php`
**Propósito:** Explicar el problema del orden de rutas y su solución.

**Uso:**
```bash
php tests/scripts/verificacion_rutas.php
```

**Salida:**
- Explica por qué el orden de rutas es importante
- Documenta la corrección aplicada
- Proporciona pasos de verificación

---

## 🧪 Ejecutar todos los scripts

```bash
# Desde la raíz del proyecto
php tests/scripts/diagnostico_director.php
php tests/scripts/test_director_creation.php
php tests/scripts/test_endpoint_me.php
php tests/scripts/verificacion_rutas.php
```

---

## 📝 Notas

Estos scripts son **solo para desarrollo y diagnóstico**. No deben ejecutarse en producción.
