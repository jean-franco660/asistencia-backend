# 📘 Manual de Despliegue a Producción - Backend
## Sistema de Control de Asistencias

> Este README contiene las instrucciones completas para desplegar el backend Laravel en producción, especialmente en servidores con cPanel.

---

## 🚀 Despliegue Rápido en cPanel

### Paso 1: Crear Base de Datos MySQL

1. En cPanel → **MySQL® Databases**
2. Crear base de datos: `asistencia_db`
3. Crear usuario: `asistencia_user` con contraseña segura
4. Asignar usuario a la base de datos con **todos los privilegios**

### Paso 2: Subir Archivos

1. Comprimir todo el contenido de esta carpeta en `backend.zip`
2. En cPanel → **Administrador de Archivos**
3. Crear carpeta `public_html/api`
4. Subir y extraer `backend.zip` en esa carpeta

### Paso 3: Configurar .env

1. Copiar `.env.example` a `.env`
2. Editar `.env` con estos valores:

```env
APP_NAME="Sistema de Asistencia"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sudominio.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=asistencia_db
DB_USERNAME=asistencia_user
DB_PASSWORD=tu_contraseña_segura

CORS_ALLOWED_ORIGINS="https://admin.sudominio.com,https://api.sudominio.com"
```

### Paso 4: Ejecutar Comandos de Instalación

En Terminal de cPanel o SSH:

```bash
cd public_html/api

# Instalar dependencias
composer install --optimize-autoloader --no-dev

# Generar clave de aplicación
php artisan key:generate

# Ejecutar migraciones
php artisan migrate --force

# Cargar datos iniciales
php artisan db:seed --force

# Crear enlace de almacenamiento
php artisan storage:link

# Configurar permisos
chmod -R 755 storage bootstrap/cache

# Optimizar
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Paso 5: Configurar Subdominio

1. En cPanel → **Subdominios**
2. Crear subdominio `api`
3. Raíz del documento: `public_html/api/public`

### Paso 6: Configurar .htaccess

En `public_html/api/public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Redirect Trailing Slashes
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Front Controller
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

# Upload size para fotos de asistencia
php_value upload_max_filesize 20M
php_value post_max_size 20M
```

### Paso 7: Verificar

Visite: `https://api.sudominio.com/api/health`

Debe mostrar: `{"status":"ok"}`

---

## 🔧 Actualización

```bash
cd public_html/api
php artisan down
git pull origin main  # si usa git
composer install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan up
```

---

## 📝 Requisitos del Servidor

- **PHP**: 8.2+
- **MySQL**: 8.0+
- **Composer**: 2.x
- **Extensiones PHP**: cli, fpm, mysql, xml, mbstring, curl, zip, gd, bcmath, intl

---

## 🔐 Credenciales Predeterminadas

Después de ejecutar `php artisan db:seed`, se crea automáticamente el Super Administrador:

**Super Admin:**
- Email: `admin@asistencia.com`
- Password: `SuperAdmin123`

> ⚠️ **MUY IMPORTANTE**: 
> - Cambie estas credenciales inmediatamente después del primer acceso
> - Use una contraseña segura con al menos 8 caracteres
> - El super admin puede crear otros usuarios desde el dashboard web

### Cómo Cambiar la Contraseña del Super Admin

**Opción 1 - Desde el Dashboard Web (Recomendado):**
1. Inicie sesión con las credenciales predeterminadas
2. Vaya a su perfil
3. Cambie la contraseña

**Opción 2 - Por Línea de Comandos:**
```bash
php artisan tinker
```
Luego ejecute:
```php
$admin = App\Models\UsuarioWeb::where('email', 'admin@asistencia.com')->first();
$admin->password = 'SuNuevaContraseñaSegura123';
$admin->save();
exit;
```

### Crear Usuarios Adicionales

Una vez que el super admin ha iniciado sesión en el dashboard web, puede:
- Crear otros administradores
- Crear supervisores (directores)
- Crear instituciones educativas
- Importar usuarios app (docentes) mediante Excel

### Gestión de Perfil de Usuarios

Todos los usuarios web (super_admin, administrador, supervisor) pueden acceder a su perfil desde el menú de usuario en el navbar y:

**Cambiar su contraseña:**
1. Clic en avatar → "Mi Perfil"
2. Botón "Cambiar Contraseña"
3. Ingresar contraseña actual, nueva y confirmación
4. La nueva contraseña debe tener al menos 8 caracteres

**Cambiar su email:**
1. Clic en avatar → "Mi Perfil"
2. Botón "Cambiar Email"
3. Ingresar nuevo email y contraseña actual para confirmar
4. El email debe ser único en el sistema

> 💡 **Endpoints disponibles:**
> - `POST /api/v1/web/perfil/cambiar-password`
> - `POST /api/v1/web/perfil/cambiar-email`

---

## 📚 Documentación Completa

Para instrucciones detalladas, consulte:
- [DEPLOYMENT.md](DEPLOYMENT.md) - Guía técnica completa
- [README.md](../README.md) - Manual general del sistema

---

## 🐛 Solución de Problemas

**Error 500:**
```bash
chmod -R 755 storage bootstrap/cache
php artisan config:clear
```

**CORS Error:**  
Actualice `CORS_ALLOWED_ORIGINS` en `.env` y ejecute `php artisan config:cache`

**Logs:**  
`storage/logs/laravel.log`

---

**Sistema de Control de Asistencias © 2026**
