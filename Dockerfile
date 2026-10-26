FROM php:8.4-cli-bookworm

# Só o necessário pra rodar o site: mysqli (banco) e opcache (guarda o PHP já
# compilado na memória — sem ele, cada clique recompila arquivos de 100 KB+).
RUN docker-php-ext-install mysqli opcache

RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.memory_consumption=64'; \
      echo 'opcache.interned_strings_buffer=8'; \
      echo 'opcache.max_accelerated_files=4000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/zz-desempenho.ini

WORKDIR /var/www/html
COPY . .

# O servidor embutido do PHP atende 1 requisição por vez; com 4 workers,
# 4 cliques (ou 4 usuários) são atendidos ao mesmo tempo.
ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000}"]
