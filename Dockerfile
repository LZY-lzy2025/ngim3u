# 使用官方 PHP 8.2 Apache 轻量级镜像
FROM php:8.2-apache

# 开启 Apache 的 Rewrite 模块（备用）
RUN a2enmod rewrite

# 将 PHP 脚本复制到容器的网站根目录
COPY index.php /var/www/html/index.php

# 暴露 80 端口
EXPOSE 80
