#!/bin/sh
set -e

# Sem NEWRELIC_LICENSE_KEY (caso padrão hoje, enquanto não existe conta New
# Relic), o agente fica instalado mas inerte: nada é escrito no ini e o PHP
# sobe normalmente sem reportar nada.
if [ -n "$NEWRELIC_LICENSE_KEY" ]; then
  ini_file=$(find /etc /usr/local/etc -iname "newrelic.ini" 2>/dev/null | head -n1)
  if [ -n "$ini_file" ]; then
    sed -i "s/^newrelic.license = .*/newrelic.license = \"${NEWRELIC_LICENSE_KEY}\"/" "$ini_file"
    sed -i "s/^newrelic.appname = .*/newrelic.appname = \"${NEWRELIC_APPNAME:-POS Tech}\"/" "$ini_file"
  fi
  # Não precisa subir o newrelic-daemon manualmente — a extensão faz isso
  # sozinha (spawna o daemon companion na primeira requisição/execução,
  # apontando pro socket em newrelic.daemon.address).
fi

exec "$@"
