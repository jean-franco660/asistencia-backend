# Guía de Deployment - Backend Laravel

## Requisitos del Servidor

### Software Requerido
- **PHP**: 8.2 o superior
- **Composer**: 2.x
- **MySQL**: 8.0 o superior
- **Web Server**: Nginx o Apache
- **Node.js**: 18+ (solo para build de assets si aplica)

### Extensiones PHP Requeridas
```bash
sudo apt install php8.2-cli php8.2-fpm php8.2-mysql php8.2-xml php8.2-mbstring \
php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl
```

## Configuración Inicial del Servidor

### 1. Clonar el Repositorio
```bash
cd /var/www
git clone <repository-url> asistencia-backend
cd asistencia-backend
```

### 2. Configurar Archivo .env
```bash
cp .env.example .env
nano .env
```

Configurar las siguientes variables **CRÍTICAS**:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://13.216.216.86

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=asistencia_db
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password_seguro

CORS_ALLOWED_ORIGINS="http://13.216.216.86,http://localhost:5173"
```

### 3. Generar Application Key
```bash
php artisan key:generate
```

### 4. Instalar Dependencias
```bash
composer install --optimize-autoloader --no-dev
```

### 5. Configurar Base de Datos
```bash
# Ejecutar migraciones
php artisan migrate --force

# Ejecutar seeders (solo primera vez)
php artisan db:seed --force
```

### 6. Configurar Permisos
```bash
sudo chown -R www-data:www-data /var/www/asistencia-backend
sudo chmod -R 775 storage bootstrap/cache
```

### 7. Crear Symlink de Storage
```bash
php artisan storage:link
```

## Configuración de Nginx

Crear archivo de configuración: `/etc/nginx/sites-available/asistencia-backend`

```nginx
server {
    listen 80;
    server_name 13.216.216.86;
    root /var/www/asistencia-backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Aumentar límite para uploads de imágenes de asistencia
    client_max_body_size 20M;
}
```

Habilitar el sitio:
```bash
sudo ln -s /etc/nginx/sites-available/asistencia-backend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Deployment y Actualización

### Usando el Script de Deployment
```bash
cd /var/www/asistencia-backend
chmod +x deploy.sh
./deploy.sh
```

### Deployment Manual (paso a paso)
```bash
# 1. Modo mantenimiento
php artisan down

# 2. Actualizar código (si usas git)
git pull origin main

# 3. Actualizar dependencias
composer install --optimize-autoloader --no-dev

# 4. Limpiar cachés
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 5. Migraciones
php artisan migrate --force

# 6. Cachés optimizados
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Salir de mantenimiento
php artisan up
```

## Configuración de Queue Workers

### Crear Servicio Systemd
Crear archivo: `/etc/systemd/system/asistencia-queue.service`

```ini
[Unit]
Description=Asistencia Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/asistencia-backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

Habilitar y ejecutar:
```bash
sudo systemctl enable asistencia-queue
sudo systemctl start asistencia-queue
sudo systemctl status asistencia-queue
```

### Configurar Cron para Scheduled Tasks
```bash
sudo crontab -e -u www-data
```

Agregar:
```
* * * * * cd /var/www/asistencia-backend && php artisan schedule:run >> /dev/null 2>&1
```

## Mantenimiento

### Ver Logs
```bash
# Logs de Laravel
tail -f storage/logs/laravel.log

# Logs de Nginx
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/nginx/access.log

# Logs de PHP-FPM
sudo tail -f /var/log/php8.2-fpm.log
```

### Limpiar Logs Antiguos
```bash
# Limpiar logs de Laravel más antiguos de 7 días
find storage/logs -name "*.log" -mtime +7 -delete
```

### Optimizar Base de Datos
```bash
php artisan optimize:clear
php artisan optimize
```

## Troubleshooting

### Error: "Access denied for user"
- Verificar credenciales en `.env`
- Verificar que el usuario MySQL tenga permisos

### Error: "500 Internal Server Error"
- Revisar permisos de `storage/` y `bootstrap/cache/`
- Verificar logs: `tail -f storage/logs/laravel.log`
- Verificar que `.env` tenga `APP_KEY` generado

### Error: "CORS blocked"
- Verificar `CORS_ALLOWED_ORIGINS` en `.env`
- Limpiar caché de configuración: `php artisan config:clear`

### Queue no procesa trabajos
- Verificar estado del worker: `sudo systemctl status asistencia-queue`
- Reiniciar worker: `sudo systemctl restart asistencia-queue`

## Checklist Pre-Deployment

- [ ] Archivo `.env` configurado con valores de producción
- [ ] `APP_DEBUG=false` en `.env`
- [ ] Base de datos creada y credenciales configuradas
- [ ] `APP_KEY` generado
- [ ] Migraciones ejecutadas
- [ ] Permisos de `storage/` y `bootstrap/cache/` configurados
- [ ] Symlink de storage creado
- [ ] Nginx/Apache configurado y funcionando
- [ ] Queue worker configurado (si se usa)
- [ ] Cron configurado para scheduled tasks
- [ ] Tests ejecutados exitosamente
- [ ] Backup de base de datos realizado (para actualizaciones)

## Seguridad

### SSL/HTTPS (Recomendado para Producción)
```bash
# Instalar Certbot
sudo apt install certbot python3-certbot-nginx

# Obtener certificado SSL (requiere dominio)
sudo certbot --nginx -d tudominio.com
```

### Firewall
```bash
# Permitir solo HTTP, HTTPS, SSH
sudo ufw allow 22
sudo ufw allow 80
sudo ufw allow 443
sudo ufw enable
```

### Actualizar .env para HTTPS
```env
APP_URL=https://tudominio.com
SESSION_SECURE_COOKIE=true
```
