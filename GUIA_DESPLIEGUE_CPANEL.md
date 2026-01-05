# 📘 Guía de Despliegue en cPanel
## Sistema de Control de Asistencias

> **Para**: Personal administrativo y técnico  
> **Nivel**: Principiante - No se requieren conocimientos de programación

---

## 📋 Tabla de Contenidos

1. [Requisitos Previos](#-requisitos-previos)
2. [Despliegue del Backend (API)](#-despliegue-del-backend-api)
3. [Despliegue del Frontend (Dashboard)](#-despliegue-del-frontend-dashboard)
4. [Distribución de la App Móvil](#-distribución-de-la-app-móvil)
5. [Verificación](#-verificación)
6. [Solución de Problemas](#-solución-de-problemas)

---

## 🔑 Requisitos Previos

### Para el Servidor (cPanel)
- [ ] Hosting con **cPanel** (GoDaddy, Hostinger, etc.)
- [ ] **PHP 8.2 o superior**
- [ ] **MySQL 8.0 o superior**
- [ ] **500 MB de espacio** mínimo
- [ ] **SSL/HTTPS** configurado

### Información a tener a mano
- [ ] Usuario y contraseña de cPanel
- [ ] Dominio o subdominio
- [ ] Acceso FTP

### Credenciales por defecto
- **Super Admin**: `admin@asistencia.com` / `SuperAdmin123`
- ⚠️ **Cambiar inmediatamente** después del primer acceso

---

## 🚀 Despliegue del Backend (API)

### Paso 1: Crear Base de Datos

1. cPanel → **MySQL® Databases**
2. Crear base de datos: `asistencia_db`
3. Crear usuario: `asistencia_user`
4. Asignar usuario a base de datos con **TODOS LOS PRIVILEGIOS**

📝 **Anote**: nombre_bd, usuario_bd, contraseña_bd

### Paso 2: Subir Archivos

1. Comprimir carpeta `asistencia-backend` en `backend.zip`
2. cPanel → **Administrador de Archivos**
3. Crear carpeta: `public_html/api`
4. Subir y extraer `backend.zip`

### Paso 3: Configurar .env

1. Copiar `.env.example` a `.env`
2. Editar con sus datos:

```env
APP_NAME="Sistema de Asistencia"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sudominio.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=su_nombre_bd
DB_USERNAME=su_usuario_bd
DB_PASSWORD=su_contraseña_bd

CORS_ALLOWED_ORIGINS="https://admin.sudominio.com"
```

### Paso 4: Ejecutar Comandos

En cPanel → **Terminal**, ejecutar:

```bash
cd public_html/api
composer install --optimize-autoloader --no-dev
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
chmod -R 755 storage bootstrap/cache
php artisan config:cache
php artisan route:cache
```

### Paso 5: Configurar .htaccess

Crear en `public_html/api/public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>
    RewriteEngine On
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

php_value upload_max_filesize 20M
php_value post_max_size 20M
```

### Paso 6: Crear Subdominio

1. cPanel → **Subdominios**
2. Crear: `api.sudominio.com`
3. Raíz: `public_html/api/public`

✅ Verificar: `https://api.sudominio.com/api/status`

---

## 🎨 Despliegue del Frontend (Dashboard)

### Paso 1: Configurar Variables

Editar `.env.production`:

```env
VITE_API_BASE_URL=https://api.sudominio.com/api/v1/web
```

### Paso 2: Generar Build

```bash
cd asistencia-frontend
npm install
npm run build
```

### Paso 3: Subir al Servidor

1. Comprimir contenido de `dist/` en `frontend.zip`
2. cPanel → Crear carpeta: `public_html/admin`
3. Subir y extraer `frontend.zip`

### Paso 4: Configurar .htaccess

Crear en `public_html/admin/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteRule ^index\.html$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.html [L]
</IfModule>
```

### Paso 5: Crear Subdominio

1. cPanel → **Subdominios**
2. Crear: `admin.sudominio.com`
3. Raíz: `public_html/admin`

✅ Verificar: `https://admin.sudominio.com`

---

## 📱 Distribución de la App Móvil

### Configurar Servidor

Editar `lib/config/environment.dart`:

```dart
case Environment.production:
  return 'https://api.sudominio.com/api/v1/app';
```

### Generar APK

```bash
cd control_asistencias
flutter clean
flutter pub get
flutter build apk --flavor prod --release
```

APK generado en: `build/app/outputs/flutter-apk/app-prod-release.apk`

### Distribuir

**Opción A - Google Drive:**
1. Subir APK a Google Drive
2. Compartir enlace público

**Opción B - Servidor:**
1. Subir APK a `public_html/descargas/`
2. Compartir: `https://sudominio.com/descargas/app.apk`

---

## ✅ Verificación

### Backend
- [ ] `https://api.sudominio.com/api/status` → `{"ok":true}`
- [ ] Base de datos conectada

### Frontend
- [ ] `https://admin.sudominio.com` carga
- [ ] Login funciona con credenciales admin
- [ ] Sin errores en consola (F12)

### App Móvil
- [ ] APK se instala correctamente
- [ ] Login funciona
- [ ] Permisos de cámara/GPS funcionan

---

## 🔧 Solución de Problemas

### Error 500 (Backend)
```bash
chmod -R 755 storage bootstrap/cache
cat storage/logs/laravel.log
```

### Error CORS (Frontend)
Agregar dominio frontend en `.env`:
```env
CORS_ALLOWED_ORIGINS="https://admin.sudominio.com"
```

Limpiar caché:
```bash
php artisan config:clear
php artisan config:cache
```

### Pantalla Blanca (Frontend)
- Verificar `.htaccess` existe
- Verificar `index.html` en raíz
- Revisar consola del navegador (F12)

### App No Conecta
- Verificar URL en `environment.dart`
- Probar `https://api.sudominio.com/api/status` desde el móvil
- Verificar SSL válido

---

## 🔄 Actualizaciones

### Backend
```bash
cd public_html/api
php artisan down
# Subir nuevos archivos
composer install --no-dev
php artisan migrate --force
php artisan config:clear
php artisan config:cache
php artisan up
```

### Frontend
```bash
npm run build
# Subir contenido de dist/
```

### App Móvil
1. Incrementar versión en `pubspec.yaml`
2. Regenerar APK
3. Redistribuir a usuarios

---

## 📞 Checklist Final

### Backend ✓
- [ ] Base de datos creada y configurada
- [ ] Archivos subidos y extraídos
- [ ] `.env` configurado
- [ ] Migraciones y seeders ejecutados
- [ ] Subdominio `api.` apuntando a `/public`
- [ ] `.htaccess` configurado
- [ ] Endpoint `/api/status` responde

### Frontend ✓
- [ ] Build generado
- [ ] Archivos subidos
- [ ] `.htaccess` configurado
- [ ] Subdominio `admin.` configurado
- [ ] Login funciona

### App Móvil ✓
- [ ] URL de producción configurada
- [ ] APK generado
- [ ] Instalación exitosa
- [ ] Login y marcación funcionan

---

**Sistema de Control de Asistencias © 2026**

> 💡 **Tip**: Guarde este manual para futuras actualizaciones y mantenimiento.
