# Sistema de Asistencia - Backend API

Sistema backend para gestión de asistencias de docentes en instituciones educativas. Desarrollado con Laravel 11, proporciona una API REST completa para la aplicación móvil Flutter y el panel web administrativo Vue.js.

## 🚀 Características

- ✅ **API REST** completa con autenticación Laravel Sanctum
- ✅ **Gestión de Usuarios**: Super Admin, Administradores, Supervisores y Docentes
- ✅ **Control de Asistencias**: Registro con geolocalización GPS, fotos y validación de geofencing
- ✅ **Asistencias Diarias Materializadas**: Sistema eficiente de cálculo diario de estados
- ✅ **Múltiples Horarios**: Soporte para hasta 3 horarios por día
- ✅ **Justificaciones**: Sistema de creación, aprobación y rechazo de ausencias
- ✅ **Feriados**: Gestión de feriados nacionales e institucionales
- ✅ **Importación Masiva**: Excel para docentes e instituciones con validación robusta
- ✅ **Exportación**: Reportes detallados en Excel con múltiples hojas
- ✅ **Auditoría Completa**: Registro de todos los cambios críticos del sistema
- ✅ **Rate Limiting**: Protección contra abuso de API
- ✅ **Provisioning de Supervisores**: Conversión de usuarios app a supervisores
- ✅ **Queue Jobs**: Procesamiento asíncrono de importaciones

## 📋 Requisitos

- PHP >= 8.2
- Composer 2.x
- MySQL >= 8.0 o MariaDB >= 10.3
- Extensiones PHP: PDO, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, gd, fileinfo

## 🔧 Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/jean-franco660/asistencia-backend.git
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
APP_TIMEZONE=America/Lima

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=asistencia_db
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password

# Queue (Background Jobs)
QUEUE_CONNECTION=database

# Almacenamiento de fotos
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

### 6. Iniciar queue worker (Background Jobs)

```bash
php artisan queue:work --tries=3 --timeout=600
```

### 7. Iniciar servidor (desarrollo)

```bash
php artisan serve --host=0.0.0.0 --port=8000
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
  "codigo_modular": "DOC123456",
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

**App Móvil**: `/api/v1/app/*`
- `/login` - Autenticación
- `/perfil` - Datos del usuario
- `/asistencia` - Marcar asistencia
- `/asistencia/{usuarioId}` - Historial
- `/justificaciones` - CRUD de justificaciones

**Panel Web**: `/api/v1/web/*`
- `/login` - Autenticación
- `/me` - Datos del usuario logueado
- `/usuarios-app` - Gestión de docentes
- `/instituciones` - Gestión de instituciones
- `/horarios` - Gestión de horarios
- `/feriados` - Gestión de feriados
- `/asistencias` - Visualización y reportes
- `/justificaciones` - Aprobación/rechazo

Ver documentación completa en: `routes/api.php`

## 🗄️ Estructura de Base de Datos

### Tablas Principales

- `usuarios_web` - Administradores y Supervisores
- `usuarios_app` - Docentes
- `instituciones` - Instituciones educativas
- `usuario_app_institucion` - Asignaciones de docentes a instituciones
- `asistencias` - Registro de marcaciones (entrada/salida)
- `asistencias_diarias` - Estados diarios materializados
- `horarios_institucion` - Horarios laborales por institución
- `feriados` - Feriados nacionales e institucionales
- `justificaciones` - Justificaciones de ausencias
- `importacion_logs` - Seguimiento de importaciones masivas
- `audit_logs` - Auditoría de cambios críticos

## 🔐 Roles y Permisos

### Super Admin
- Acceso completo al sistema
- Gestión de administradores
- Acceso a logs de auditoría
- Gestión de todos los usuarios

### Administrador
- Gestión de supervisores y docentes
- Gestión de instituciones
- Configuración de horarios y feriados
- Importación/Exportación masiva
- Visualización de reportes globales

### Supervisor (Director)
- Visualización de datos de sus instituciones asignadas
- Gestión de docentes de sus instituciones
- Aprobación/rechazo de justificaciones
- Reportes de asistencias de sus instituciones
- Vista de perfil personal

### Docente (App Móvil)
- Registro de asistencias con GPS y foto
- Visualización de historial personal
- Creación de justificaciones
- Sincronización offline de marcaciones

## 📊 Rate Limiting

El sistema implementa rate limiting para proteger la API:

- **Login**: 5 intentos por minuto
- **API General**: 60 peticiones por minuto
- **Acciones Críticas**: 30 por minuto
- **Importaciones**: 3 por minuto

## 🧪 Tests

Ejecutar la suíte de tests:

```bash
php artisan test
```

Ejecutar tests específicos:

```bash
php artisan test --filter=AsistenciaTest
php artisan test --filter=UsuarioAppTest
```

## 🔧 Comandos Artisan Personalizados

```bash
# Materializar asistencias diarias
php artisan asistencias:materializar

# Corregir estados de asignaciones
php artisan asignaciones:corregir-estados
```

## 🚀 Despliegue a Producción

### 1. Optimizar aplicación

```bash
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 2. Configurar permisos

```bash
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 3. Configurar Supervisor (Queue Worker)

Crear archivo `/etc/supervisor/conf.d/asistencia-worker.conf`:

```ini
[program:asistencia-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/asistencia-backend/artisan queue:work --sleep=3 --tries=3 --timeout=600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/asistencia-backend/storage/logs/worker.log
```

Luego ejecutar:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start asistencia-worker:*
```

## 🔧 Mantenimiento

### Limpiar caché

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Reiniciar queue workers

```bash
php artisan queue:restart
```

### Logs

Los logs se encuentran en `storage/logs/laravel.log`

## 📝 Changelog

### v1.2.0 (2025-12-23)
- ✅ Sistema de asistencias diarias materializadas
- ✅ Provisioning de supervisores desde usuarios app
- ✅ Mejoras en validación de geofencing
- ✅ Filtro de instituciones para supervisores
- ✅ Vista de perfil para supervisores
- ✅ Exclusión de supervisor logueado de lista de docentes
- ✅ Optimización de consultas y rendimiento

### v1.1.0 (2025-12-20)
- ✅ Sistema de revisión de asistencias (Fase 6)
- ✅ Navegación entre marcaciones
- ✅ Observadores para automatización
- ✅ Mejoras en importación masiva

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

**Versión:** 1.2.0  
**Última actualización:** 23 de diciembre de 2025  
**Desarrollado con:** Laravel 11 + MySQL
