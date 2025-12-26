#!/bin/bash

# Script de Deployment para Sistema de Asistencia - Backend
# Este script debe ejecutarse en el servidor de producción

echo "🚀 Iniciando deployment del backend..."

# Verificar que estamos en el directorio correcto
if [ ! -f "artisan" ]; then
    echo "❌ Error: No se encontró el archivo 'artisan'. Asegúrate de estar en el directorio raíz del proyecto Laravel."
    exit 1
fi

# 1. Poner la aplicación en modo mantenimiento
echo "📋 Activando modo mantenimiento..."
php artisan down --retry=60

# 2. Actualizar código desde git (si aplica)
# echo "⬇️  Actualizando código desde git..."
# git pull origin main

# 3. Instalar/actualizar dependencias de Composer
echo "📦 Instalando dependencias de Composer..."
composer install --optimize-autoloader --no-dev

# 4. Limpiar cachés existentes
echo "🧹 Limpiando cachés..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 5. Ejecutar migraciones de base de datos
echo "🗄️  Ejecutando migraciones de base de datos..."
php artisan migrate --force

# 6. Crear caché optimizado
echo "⚡ Creando cachés optimizados..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Crear symlink de storage (si no existe)
echo "🔗 Verificando symlink de storage..."
php artisan storage:link 2>/dev/null || true

# 8. Configurar permisos
echo "🔐 Configurando permisos..."
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# 9. Optimizar autoloader de Composer
echo "🎯 Optimizando autoloader..."
composer dump-autoload -o

# 10. Desactivar modo mantenimiento
echo "✅ Desactivando modo mantenimiento..."
php artisan up

echo "🎉 ¡Deployment completado exitosamente!"
echo ""
echo "📝 Siguientes pasos recomendados:"
echo "   - Verificar que el sitio funcione correctamente"
echo "   - Revisar logs: tail -f storage/logs/laravel.log"
echo "   - Verificar queue workers: php artisan queue:work"
