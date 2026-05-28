# 🔗 Guía: Cómo se Conecta el Backend a la BD en Railway

## 📌 El Flujo Completo (Paso a Paso)

```
┌─────────────────────────────────────────────────────────────────────┐
│  1. TÚ CREAS LA BASE DE DATOS EN RAILWAY DASHBOARD                 │
│     └─ Click "New" → "Database" → "MySQL"                         │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│  2. RAILWAY INYECTA AUTOMÁTICAMENTE VARIABLES DE ENTORNO            │
│     En el contenedor Docker, se crean:                             │
│     - MYSQLHOST     = "mysql.railway.internal"                     │
│     - MYSQLPORT     = "3306"                                       │
│     - MYSQLDATABASE = "railway" (nombre por defecto)               │
│     - MYSQLUSER     = "root" (usuario por defecto)                 │
│     - MYSQLPASSWORD = "<password aleatorio generado>"              │
│                                                                     │
│     💡 NO LAS ESCRIBES TÚ - RAILWAY LAS GENERA AUTOMÁTICAMENTE     │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│  3. RAILWAY EJECUTA EL BUILD COMMAND                                │
│     $ composer install --optimize-autoloader --no-dev               │
│     $ php artisan key:generate --force                              │
│     $ php artisan config:cache                                      │
│     $ php artisan migrate --force  ← ⭐ AQUÍ SE CREA LA BD          │
│     $ php artisan storage:link || true                              │
│     $ php artisan route:cache                                       │
│     $ php artisan view:cache                                        │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│  4. DURANTE LA MIGRACIÓN (php artisan migrate --force)              │
│     ✅ Se crea la estructura de la BD:                              │
│        - usuarios_web                                               │
│        - usuarios_app                                               │
│        - instituciones                                              │
│        - horarios_institucion                                       │
│        - asistencias                                                │
│        - ... (todas las tablas)                                     │
│                                                                     │
│     ✅ Se ejecutan los SEEDERS (SuperAdminSeeder):                  │
│        - Crea usuario: admin@asistencia.com                         │
│        - Contraseña: SuperAdmin123                                  │
│        - Rol: SUPER_ADMIN                                           │
│                                                                     │
│     ⚠️  ESTO OCURRE AUTOMÁTICAMENTE PORQUE ESTÁ EN .env.example:   │
│        DB_HOST=${MYSQLHOST}    ← Railway inyecta el valor          │
│        DB_PORT=${MYSQLPORT}    ← Railway inyecta el valor          │
│        DB_DATABASE=${MYSQLDATABASE}  ← Railway inyecta el valor     │
│        DB_USERNAME=${MYSQLUSER}      ← Railway inyecta el valor     │
│        DB_PASSWORD=${MYSQLPASSWORD}  ← Railway inyecta el valor     │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│  5. RAILWAY EJECUTA EL START COMMAND                                │
│     $ php -S 0.0.0.0:8000 -t public                                 │
│     ✅ El servidor web inicia y escucha en puerto 8000              │
│     ✅ Usa las mismas variables para conectarse a la BD             │
│     ✅ ¡YA PUEDES USAR EL BACKEND!                                  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 🔑 Cómo Laravel Obtiene las Credenciales de la BD

### Archivo `.env` en Production (Railway)

```env
DB_CONNECTION=mysql
DB_HOST=${MYSQLHOST}              ← Variable de Railway
DB_PORT=${MYSQLPORT}              ← Variable de Railway
DB_DATABASE=${MYSQLDATABASE}       ← Variable de Railway
DB_USERNAME=${MYSQLUSER}           ← Variable de Railway
DB_PASSWORD=${MYSQLPASSWORD}       ← Variable de Railway
```

### Archivo `config/database.php`

```php
'mysql' => [
    'driver'      => 'mysql',
    'host'        => env('DB_HOST'),              // Obtiene de .env
    'port'        => env('DB_PORT'),              // Obtiene de .env
    'database'    => env('DB_DATABASE'),          // Obtiene de .env
    'username'    => env('DB_USERNAME'),          // Obtiene de .env
    'password'    => env('DB_PASSWORD'),          // Obtiene de .env
],
```

### El Flujo

```
Railway MySQL Plugin activo
    ↓
Genera automáticamente 5 variables de entorno
    ↓
Las inyecta en el contenedor Docker donde corre tu app
    ↓
Laravel lee el .env (que tiene ${MYSQLHOST}, etc.)
    ↓
PHP reemplaza ${MYSQLHOST} con el valor real
    ↓
config/database.php obtiene: host='mysql.railway.internal'
    ↓
PDO crea conexión: mysql://root:password@mysql.railway.internal:3306/railway
    ↓
✅ Conectado a la BD
```

---

## 📊 ¿Cómo se Crea el Super Admin?

### 1. **Archivo: `database/seeders/SuperAdminSeeder.php`**

```php
public function run(): void
{
    UsuarioWeb::updateOrCreate(
        ['email' => 'admin@asistencia.com'],  // Busca si existe este email
        [
            'nombre'   => 'Super Admin',
            'password' => 'SuperAdmin123',     // Se hashea automáticamente
            'rol'      => UsuarioWeb::ROL_SUPER_ADMIN,
        ]
    );
}
```

### 2. **Se Ejecuta en el Build de Railway**

En `railway.json`, el `buildCommand` incluye:

```
php artisan migrate --force
```

El comando `migrate` automáticamente ejecuta los seeders porque están registrados en `database/seeders/DatabaseSeeder.php`:

```php
public function run(): void
{
    $this->call([
        SuperAdminSeeder::class,  // ← Crea el super admin
    ]);
}
```

### 3. **Resultado Final**

Después del deployment, en la BD tendrás:

| Campo | Valor |
|-------|-------|
| email | admin@asistencia.com |
| nombre | Super Admin |
| password (hasheado) | $2y$12$... |
| rol | super_admin |
| estado | autorizado (automático) |

### 4. **Cómo Ingresar**

```
URL: https://tu-frontend.up.railway.app
Email: admin@asistencia.com
Contraseña: SuperAdmin123
```

---

## ⚙️ Paso a Paso: Crear BD en Railway (IMPORTANTE)

### 1. Ir a Railway Dashboard

1. Abre https://railway.app
2. Inicia sesión en tu proyecto

### 2. Agregar Plugin MySQL

1. Click en botón **"New"** (arriba a la derecha)
2. Selecciona **"Database"**
3. Elige **"MySQL"**
4. Railway automáticamente lo conecta al proyecto

### 3. Las Variables se Crean Automáticamente

Railway mostrará en tu proyecto:
- Variables enviadas al contenedor (en la pestaña "Variables")
- Las verás en formato:
  ```
  MYSQLHOST=mysql.railway.internal
  MYSQLPORT=3306
  MYSQLDATABASE=railway
  MYSQLUSER=root
  MYSQLPASSWORD=<random-password>
  ```

### 4. Tu Código Laravel Las Usa Automáticamente

- **NO NECESITAS** ingresarlas manualmente
- **NO NECESITAS** modificar el `.env`
- Laravel las lee de las variables del sistema operativo del contenedor

---

## 🚀 Flujo Completo de Deployment (RESUMIDO)

```
1. Conecta tu GitHub a Railway
2. Railway clona el código
3. Lee railway.json → buildCommand
4. Ejecuta: composer install, migrations, seeders
5. Lee railway.json → startCommand
6. Inicia servidor: php -S 0.0.0.0:8000
7. ✅ APP LISTA, ACCESIBLE EN: https://tu-backend.up.railway.app
```

---

## 📝 Checklist: Antes de hacer Deploy

- [ ] **Base de datos MySQL agregada en Railway** (Dashboard → New → Database → MySQL)
- [ ] **APP_KEY generado** ✅ (ya lo hicimos: `base64:fL5xup1vNKEBWvxay3F7PD+Jq6poE0sN/m7JOFKssnw=`)
- [ ] **CORS_ALLOWED_ORIGINS actualizado** en Railway Dashboard:
  ```
  CORS_ALLOWED_ORIGINS=https://tu-frontend.up.railway.app,http://localhost:5173
  ```
- [ ] **APP_URL actualizado** en Railway Dashboard:
  ```
  APP_URL=https://tu-backend.up.railway.app
  ```
- [ ] **Conectar GitHub a Railway** (si no lo hiciste)
- [ ] **Presionar Deploy** (o hacer push a GitHub para auto-deploy)

---

## 🔍 ¿Qué Pasa si Algo Falla?

### Ver Logs de Railway

```bash
railway logs
```

### Problemas Comunes

| Problema | Causa | Solución |
|----------|-------|----------|
| `Connection refused` | No hay MySQL | Agregar Plugin MySQL en Dashboard |
| `SQLSTATE[HY000]` | Variables no inyectadas | Esperar a que Railway reinicie |
| `Access denied for user` | Contraseña incorrecta | Railway la genera automáticamente |
| `Table doesn't exist` | Migraciones no corrieron | Verificar logs, redeployer |

---

## 💡 Notas Importantes

1. **Las variables NO se escriben en archivos .env en producción**
   - Railway inyecta en tiempo de ejecución del contenedor
   - El .env local (desarrollo) es diferente

2. **SuperAdmin se crea automáticamente**
   - Primera vez: lo crea
   - Deployments posteriores: lo actualiza (updateOrCreate)

3. **La BD persiste entre deployments**
   - Los datos no se pierden
   - Las migraciones solo crean tablas que no existen

4. **No necesitas ejecutar seeders manualmente**
   - `php artisan migrate` ya incluye `php artisan db:seed`

---

## 📚 Resumen Ultra Rápido

```
Railway MySQL + Vars de Entorno + Laravel .env + Migration + Seeders
        ↓                ↓                   ↓           ↓
   Creas BD    Inyecta credenciales   Lee variables    Crea tablas
                                           ↓
                                    Crea Super Admin
                                           ↓
                                      ✅ LISTO PARA USAR
```
