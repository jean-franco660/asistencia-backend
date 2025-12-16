# Sistema de Asistencia - Backend API

Sistema backend para gestión de asistencias de docentes en instituciones educativas. Desarrollado con Laravel 12, proporciona una API REST completa para la aplicación móvil y el panel web administrativo.

## 🚀 Características

- ✅ **API REST** completa con autenticación Sanctum
- ✅ **Gestión de Usuarios**: Administradores, Supervisores y Docentes
- ✅ **Control de Asistencias**: Registro con geolocalización y fotos
- ✅ **Múltiples Horarios**: Soporte para hasta 3 horarios por día
- ✅ **Justificaciones**: Sistema de aprobación de ausencias
- ✅ **Feriados**: Nacionales e institucionales
- ✅ **Importación Masiva**: Excel para docentes e instituciones
- ✅ **Exportación**: Reportes detallados en Excel
- ✅ **Auditoría**: Registro completo de cambios
- ✅ **Rate Limiting**: Protección contra abuso de API

## 📋 Requisitos

- PHP >= 8.2
- Composer
- MySQL >= 8.0 o MariaDB >= 10.3
- Extensiones PHP: PDO, mbstring, openssl, tokenizer, xml, ctype, json, bcmath

## 🔧 Instalación

### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd asistencia-backend
```

### 2. Instalar dependencias

```bash
composer install
```

### 3. Configurar variables de entorno

```bash
cp .env.example .env
php artisan key:generate
```

Editar `.env` con tus configuraciones:

```env
APP_NAME="Sistema de Asistencia"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=asistencia_db
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password

# Almacenamiento de fotos (local por defecto)
FILESYSTEM_DISK=public
```

### 4. Ejecutar migraciones

```bash
php artisan migrate --seed
```

Esto creará:
- Todas las tablas necesarias
- Un super administrador: `superadmin@sistema.com` / `SuperAdmin@123`

### 5. Crear enlace simbólico para almacenamiento

```bash
php artisan storage:link
```

### 6. Iniciar servidor (desarrollo)

```bash
php artisan serve
```

La API estará disponible en `http://localhost:8000`

## 📚 Documentación de API

### Autenticación

Todos los endpoints (excepto login) requieren autenticación con token Bearer.

#### Login App Móvil

```http
POST /api/v1/app/login
Content-Type: application/json

{
  "codigo_modular_docente": "DOC123456",
  "password": "password123"
}
```

#### Login Web (Admin/Supervisor)

```http
POST /api/v1/web/login
Content-Type: application/json

{
  "email": "admin@sistema.com",
  "password": "password123"
}
```

### Endpoints Principales

Ver documentación completa de endpoints en el código fuente: `routes/api.php`

**App Móvil**: `/api/v1/app/*`
**Panel Web**: `/api/v1/web/*`

## 🗄️ Estructura de Base de Datos

### Tablas Principales

- `usuarios_web` - Administradores y Supervisores
- `usuarios_app` - Docentes
- `instituciones` - Instituciones educativas
- `asistencias` - Registro de asistencias
- `horarios_institucion` - Horarios laborales
- `feriados` - Feriados nacionales e institucionales
- `justificaciones` - Justificaciones de ausencias
- `audit_logs` - Auditoría de cambios

## 🔐 Roles y Permisos

### Super Admin
- Acceso completo al sistema
- Gestión de todos los usuarios
- Acceso a logs de auditoría

### Administrador
- Gestión de supervisores y docentes
- Gestión de instituciones
- Configuración de horarios y feriados
- Visualización de reportes

### Supervisor
- Visualización de datos de sus instituciones asignadas
- Aprobación de justificaciones
- Reportes de asistencias

### Docente (App Móvil)
- Registro de asistencias
- Visualización de historial
- Creación de justificaciones

## 📊 Rate Limiting

El sistema implementa rate limiting para proteger la API:

- **Login**: 5 intentos por minuto
- **API General**: 60 peticiones por minuto
- **Acciones Críticas**: 30 por minuto
- **Importaciones**: 3 por minuto

## 🧪 Tests

Ejecutar la suite de tests:

```bash
php artisan test
```

Ejecutar tests específicos:

```bash
php artisan test --filter=UsuarioAppTest
```

## 🚀 Despliegue a Producción

### 1. Optimizar aplicación

```bash
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 2. Configurar permisos

```bash
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 3. Configurar variables de entorno

Asegúrate de configurar correctamente:
- `APP_ENV=production`
- `APP_DEBUG=false`
- Credenciales de base de datos
- URL de la aplicación

## 🔧 Mantenimiento

### Limpiar caché

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Logs

Los logs se encuentran en `storage/logs/laravel.log`

## 📝 Changelog

### v1.0.0 (2025-12-14)
- ✅ Sistema base de asistencias
- ✅ Autenticación con Sanctum
- ✅ Importación/Exportación Excel
- ✅ Sistema de justificaciones
- ✅ Múltiples horarios por día
- ✅ Auditoría completa

## 📄 Licencia

Este proyecto es privado y confidencial.

---

**Versión:** 1.0.0  
**Última actualización:** 14 de diciembre de 2025
