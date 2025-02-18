sudo chown -R www-data:www-data /var/www/html/webhdd

sudo chmod -R 755 /var/www/html/webhdd


/etc/php/8.1/apache2/php.ini

upload_max_filesize = 100M

post_max_size = 120M

max_execution_time = 300

max_input_time = 300


/etc/apache2/apache2.conf

DirectoryIndex  index.html  index.php

LimitRequestBody 104857600 
