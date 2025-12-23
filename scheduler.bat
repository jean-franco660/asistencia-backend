@echo off
cd /d D:\practicas\asistencia-backend
php artisan schedule:run >> NUL 2>&1
