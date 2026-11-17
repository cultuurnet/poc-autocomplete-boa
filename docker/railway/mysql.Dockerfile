# MySQL for Railway: the image docker-compose.yml runs, with the same my.cnf
# (the full-text settings the query layer depends on), plus the flags Railway's
# own MySQL template uses to keep memory and disk down.
FROM mysql:8.4

COPY docker/mysql/my.cnf /etc/mysql/conf.d/poc.cnf

CMD ["mysqld", "--innodb-use-native-aio=0", "--disable-log-bin", "--performance-schema=0"]
