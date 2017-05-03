FROM php:5.6-apache

RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get -y install apt-utils && \
    DEBIAN_FRONTEND=noninteractive apt-get -y install php5-pgsql sqlite3 php5-sqlite
RUN a2enmod rewrite

ENV APACHE_RUN_USER   www-data
ENV APACHE_RUN_GROUP  www-data
ENV APACHE_PID_FILE   /var/run/apache2.pid
ENV APACHE_RUN_DIR    /var/run/apache2
ENV APACHE_LOCK_DIR   /var/lock/apache2
ENV APACHE_LOG_DIR    /var/log/apache2
ENV APACHE_CI_ENV     development

COPY . /var/www/html/
COPY websystem/index.php /var/www/html/
COPY tests/apache_conf/general.ini /etc/myemsl/general.ini
COPY tests/apache_conf/modules /etc/apache2/conf-enabled/
COPY tests/apache_conf/sites/myemsl-status.conf /etc/apache2/sites-available/
RUN ln -s /etc/apache2/sites-available/myemsl-status.conf /etc/apache2/sites-enabled/
RUN ln -s /var/www/html/application/resources /var/www/html/project_resources
RUN cp -f /usr/share/php5/php.ini-development /usr/local/etc/php/php.ini
RUN touch /usr/local/etc/php/conf.d/raw_data.ini \
    && echo "always_populate_raw_post_data = -1;" >> /usr/local/etc/php/conf.d/raw_data.ini
RUN ln -s /usr/share/php5/pgsql/* /usr/local/etc/php/conf.d/
RUN ln -s /usr/lib/php5/20131226/p* /usr/local/lib/php/extensions/no-debug-non-zts-20131226/
RUN rm -rf /etc/apache2/sites-enabled/000-default.conf
RUN echo 'date.timezone = America/Los_Angeles' | tee "/usr/local/etc/php/conf.d/timezone.ini"
RUN ["/bin/bash", "-c", "source /etc/apache2/envvars"]
RUN chown -R "$APACHE_RUN_USER:$APACHE_RUN_GROUP" /var/www/html

CMD ["apache2-foreground"]
