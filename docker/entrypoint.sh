#!/bin/sh
# El volumen de imgs/ se crea root:root en un contenedor nuevo (root es dueño
# del punto de montaje) — Apache corre como www-data y necesita poder escribir
# ahí (upload.php, upload_bulk.php, y ahora manager_fetch_foto en api.php).
chown -R www-data:www-data /var/www/html/imgs
exec apache2-foreground
