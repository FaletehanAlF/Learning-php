@echo off
REM start.bat — jalankan Sistem Kas dengan ekstensi PDO aktif
cd /d "%~dp0"
php -d extension_dir=C:\php-8.5.9\ext -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=mysqli -d extension=sqlite3 -d extension=mbstring -d extension=fileinfo -d extension=openssl install.php
php -d extension_dir=C:\php-8.5.9\ext -d extension=pdo_sqlite -d extension=pdo_mysql -d extension=mysqli -d extension=sqlite3 -d extension=mbstring -d extension=fileinfo -d extension=openssl -S localhost:8000
