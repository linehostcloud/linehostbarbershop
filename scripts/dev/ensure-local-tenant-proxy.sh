#!/usr/bin/env bash

set -euo pipefail

host_base="${LOCAL_TENANT_HOST_BASE:-sistema-barbearia.localhost}"
proxy_host_dir="${NPM_PROXY_HOST_DIR:-/home/linehost/DOCKER/nginx-proxy/data/nginx/proxy_host}"
target_file="${proxy_host_dir}/99-sistema-barbearia-tenants-wildcard.conf"
container_name="${NPM_CONTAINER_NAME:-nginx-proxy-manager}"

mkdir -p "${proxy_host_dir}"

cat > "${target_file}" <<EOF
# ------------------------------------------------------------
# local central and tenant hosts for sistema-barbearia
# ------------------------------------------------------------

map \$scheme \$hsts_header {
    https   "max-age=63072000; preload";
}

server {
  set \$forward_scheme http;
  set \$server         "sistema-barbearia";
  set \$port           80;

  listen 80;
  listen [::]:80;

  server_name ${host_base} *.${host_base};
  http2 off;

  access_log /data/logs/proxy-host-sistema-barbearia-tenants_access.log proxy;
  error_log /data/logs/proxy-host-sistema-barbearia-tenants_error.log warn;

  location / {
    include conf.d/include/proxy.conf;
  }

  include /data/nginx/custom/server_proxy[.]conf;
}
EOF

docker exec "${container_name}" sh -lc "nginx -t && nginx -s reload"

printf 'Proxy local ativo para %s e *.%s\n' "${host_base}" "${host_base}"
