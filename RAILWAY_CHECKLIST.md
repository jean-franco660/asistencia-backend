# ✅ Checklist de Configuración Railway - Backend

## Estado: ✅ LISTO PARA DESPLEGAR

Fecha de verificación: 26/05/2026

---

## ✅ Cambios Realizados

### 1. **APP_KEY - RESUELTO**
- ✅ APP_KEY generada: `base64:fL5xup1vNKEBWvxay3F7PD+Jq6poE0sN/m7JOFKssnw=`
- Archivo: `.env` (línea 3)
- Comando ejecutado: `php artisan key:generate`

### 2. **railway.json - ACTUALIZADO**
Cambios:
- ✅ Removido comando `php artisan db:create` (no existe en Laravel 12)
- ✅ Removido `php artisan db:seed` de build (evita resetear datos en producción)
- ✅ Cambiado startCommand de `php artisan serve` a `php -S 0.0.0.0:$PORT -t public`
- ✅ Agregados healthchecks para monitoreo

**Antes:**
```json
"startCommand": "php artisan serve --host=0.0.0.0 --port=$PORT"
```

**Después:**
```json
"startCommand": "php -S 0.0.0.0:$PORT -t public"
```

### 3. **Procfile - ACTUALIZADO**
Cambios:
- ✅ Cambiado de `serve` a servidor built-in de PHP
- ✅ Agregado comando `release` para ejecutar migraciones al desplegar

```
web: php -S 0.0.0.0:$PORT -t public
release: php artisan migrate --force
```

---

## ✅ Verificación de Configuración

### Archivo .env.example
- ✅ Variables de BD usan sintaxis Railway: `${MYSQLHOST}`, `${MYSQLPORT}`, etc.
- ✅ APP_URL configurado para Railway: `https://your-backend.up.railway.app`
- ✅ CORS_ALLOWED_ORIGINS incluye frontend y localhost

### Dependencias (composer.json)
- ✅ PHP ^8.3 compatible con Railway
- ✅ Laravel/framework ^12.0 (última versión)
- ✅ Dependencias de producción incluyen: Sanctum, Excel, Flysystem S3, Redis

### Base de Datos
- ✅ Configurada para MySQL en Railway
- ✅ Variables de entorno correctamente definidas
- ✅ Las migraciones se ejecutarán automáticamente

---

## 🚀 Pasos Finales Antes de Desplegar

### 1. **Verificar Variables de Entorno en Railway Dashboard**

Asegúrate de que Railroad tenga estas variables configuradas:

```
MYSQLHOST=<generado automáticamente por Railway MySQL plugin>
MYSQLPORT=3306
MYSQLDATABASE=<tu db>
MYSQLUSER=<tu user>
MYSQLPASSWORD=<tu password>
```

### 2. **Verificar CORS_ALLOWED_ORIGINS**

Actualiza en Railway Dashboard o en el archivo `.env`:
```
CORS_ALLOWED_ORIGINS="https://tu-frontend.up.railway.app,http://localhost:5173"
```

### 3. **Verificar APP_URL**

Actualizar con la URL real del backend en Railway:
```
APP_URL=https://tu-backend.up.railway.app
```

### 4. **Opcional: Agregar Plugin de MySQL en Railway**

Si aún no tienes MySQL, en Railway Dashboard:
1. Click en "New" → "Database"
2. Seleccionar "MySQL"
3. Conectar al proyecto actual

---

## 📋 Checklist de Despliegue Final

- [ ] APP_KEY generado ✅ (ya realizado)
- [ ] railway.json actualizado ✅ (ya realizado)
- [ ] Procfile actualizado ✅ (ya realizado)
- [ ] Variables de BD de Railway configuradas
- [ ] CORS_ALLOWED_ORIGINS actualizado
- [ ] APP_URL actualizado
- [ ] Base de datos MySQL agregada en Railway (si es necesario)
- [ ] Conectar repositorio a Railway
- [ ] Trigger deployment o presionar "Deploy"
- [ ] Verificar logs en Railway: `railway logs`

---

## 🔍 Comando para Verificar Localmente (Opcional)

```bash
# Simular entorno de Railway localmente
cp .env .env.local
php artisan migrate --force
php -S 0.0.0.0:8000 -t public
```

---

## 📞 Notas Importantes

1. **Servidor Web**: Se usa el servidor built-in de PHP (`php -S`). Para mayor rendimiento en producción, considera usar Railway con Nixpacks que puede instalar Nginx automáticamente.

2. **Storage Link**: Ya está configurado en el buildCommand con `php artisan storage:link || true`

3. **Caché**: Las rutas y vistas se cachean automáticamente para mejor rendimiento.

4. **Logs**: Los logs van a `LOG_LEVEL=error` - cambiar a `debug` si necesitas diagnosticar problemas.

5. **Seeders**: Ya no se ejecutan en cada deployment (removido de buildCommand).

---

**Estado Final**: ✅ Backend listo para desplegar en Railway
