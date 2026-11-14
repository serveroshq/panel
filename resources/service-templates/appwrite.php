<?php

return [
    'name' => 'Appwrite',
    'category' => 'Self-hosted',
    'description' => 'Auth, databases, storage, functions and sites behind one API, with the Appwrite console.',
    'icon' => 'appwrite',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/v1/health/version',
    'health_retries' => 40,
    'notes' => 'Open the address for the console and make the first account there. The project it makes has an API endpoint of the same address and an API key to copy. Functions and sites build and run in Docker on this machine. The containers Appwrite starts for them are not part of this stack: the panel lists them on their own, and removing Appwrite leaves them running. Idle it uses about 2 GB of memory, and the first start runs the database migrations, so give it a few minutes. The executor mounts the machine\'s /tmp, which Appwrite needs to hand code to the containers it starts; it only writes its own folders there.',
    'env' => [
        ['key' => '_APP_DOMAIN', 'label' => 'Domain', 'default' => '{host}', 'hint' => 'The address the console and API answer on, from the domain you add.'],
        ['key' => '_APP_OPENSSL_KEY_V1', 'label' => 'Encryption key', 'default' => '{random:32}', 'secret' => true],
        ['key' => '_APP_DB_PASS', 'label' => 'Database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => '_APP_DB_ROOT_PASS', 'label' => 'Database root password', 'default' => '{random:24}', 'secret' => true],
        ['key' => '_APP_EXECUTOR_SECRET', 'label' => 'Executor secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => '_APP_JOBS_SECRET', 'label' => 'Jobs secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => '_APP_GEO_SECRET', 'label' => 'Geo secret', 'default' => '{random:48}', 'secret' => true],
        ['key' => '_APP_USAGE_PASS', 'label' => 'Usage database password', 'default' => '{random:24}', 'secret' => true],
        ['key' => '_APP_SMTP_HOST', 'label' => 'SMTP host', 'default' => '', 'hint' => 'Optional. Without one, sign-in emails and invitations are not sent.'],
        ['key' => '_APP_SMTP_PORT', 'label' => 'SMTP port', 'default' => '587'],
        ['key' => '_APP_SMTP_USERNAME', 'label' => 'SMTP user', 'default' => ''],
        ['key' => '_APP_SMTP_PASSWORD', 'label' => 'SMTP password', 'default' => '', 'secret' => true],
        ['key' => '_APP_SYSTEM_EMAIL_ADDRESS', 'label' => 'Sender email', 'default' => 'hello@example.com'],
        ['key' => '_APP_SYSTEM_EMAIL_NAME', 'label' => 'Sender name', 'default' => 'Appwrite'],
    ],
    'files' => [
        'compose.yaml' => <<<'APPWRITE_COMPOSE_YAML'
# The services on the appwrite image run the API, realtime, the worker and the
# scheduler from one image with different entrypoints. Traefik is the one port
# the panel publishes: it sends paths to the service that answers them.
x-app-env: &app-env
  _APP_ENV: production
  _APP_EDITION: self-hosted
  _APP_LOCALE: en
  _APP_OPENSSL_KEY_V1: ${_APP_OPENSSL_KEY_V1}
  _APP_DOMAIN: ${_APP_DOMAIN}
  _APP_CONSOLE_DOMAIN: ${_APP_DOMAIN}
  _APP_CONSOLE_HOSTNAMES: ${_APP_DOMAIN}
  _APP_OPTIONS_FORCE_HTTPS: disabled
  _APP_OPTIONS_ROUTER_FORCE_HTTPS: disabled
  _APP_OPTIONS_ROUTER_PROTECTION: disabled
  _APP_OPTIONS_ABUSE: enabled
  _APP_TRUSTED_HEADERS: x-forwarded-for
  _APP_TRUSTED_PROXIES: 127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
  _APP_MEMORY_LIMIT: 512M
  _APP_WORKER_PER_CORE: 6
  _APP_WORKER_MAX_COROUTINES: 8
  _APP_LOGGING_FORMAT: json
  _APP_MIGRATION_HOST: appwrite
  _APP_DB_ADAPTER: postgresql
  _APP_DB_HOST: postgresql
  _APP_DB_PORT: 5432
  _APP_DB_SCHEMA: appwrite
  _APP_DB_USER: appwrite
  _APP_DB_PASS: ${_APP_DB_PASS}
  _APP_DB_ROOT_PASS: ${_APP_DB_ROOT_PASS}
  _APP_REDIS_HOST: redis
  _APP_REDIS_PORT: 6379
  _APP_STORAGE_DEVICE: Local
  _APP_STORAGE_LIMIT: 30000000
  _APP_STORAGE_PREVIEW_LIMIT: 20000000
  _APP_AUTOGRAVITY_HOST: http://appwrite-autogravity:8080
  _APP_EXIFTOOL_HOST: http://appwrite-exiftool:8080
  _APP_EXECUTOR_HOST: http://${COMPOSE_PROJECT_NAME}-exc1/v1
  _APP_EXECUTOR_SECRET: ${_APP_EXECUTOR_SECRET}
  _APP_EXECUTOR_IMAGES: openruntimes/node:v5-22,openruntimes/static:v5-1
  _APP_EXECUTOR_CONNECTION_STORAGE: local://localhost
  _APP_FUNCTIONS_RUNTIMES: node-22
  _APP_FUNCTIONS_TIMEOUT: 900
  _APP_SITES_RUNTIMES: static-1,node-22
  _APP_SITES_TIMEOUT: 60
  _APP_DOMAIN_FUNCTIONS: functions.${_APP_DOMAIN}
  _APP_DOMAIN_SITES: sites.${_APP_DOMAIN}
  # The console reads this to build domain instructions; without it the console
  # answers 500 and cannot create sites or functions.
  _APP_DOMAIN_TARGET_CNAME: ${_APP_DOMAIN}
  _APP_COMPUTE_CPUS: 4
  _APP_COMPUTE_MEMORY: 4096
  _APP_COMPUTE_SIZE_LIMIT: 30000000
  _APP_COMPUTE_BUILD_SIZE_LIMIT: 2000000000
  _APP_COMPUTE_BUILD_TIMEOUT: 900
  _APP_COMPUTE_INACTIVE_THRESHOLD: 600
  _APP_COMPUTE_MAINTENANCE_INTERVAL: 600
  _APP_COMPUTE_RUNTIMES_NETWORK: ${COMPOSE_PROJECT_NAME}_runtimes
  _APP_BUILDS_VOLUME: ${COMPOSE_PROJECT_NAME}_builds
  _APP_JOBS_HOST: http://orchestrator:8080
  _APP_JOBS_SECRET: ${_APP_JOBS_SECRET}
  _APP_JOBS_ENDPOINT: http://appwrite
  _APP_BROWSER_HOST: http://appwrite-browser:3000/v1
  _APP_GEO_ENDPOINT: http://appwrite-geo/v1
  _APP_GEO_SECRET: ${_APP_GEO_SECRET}
  _APP_USAGE_STATS: enabled
  _APP_USAGE_PASS: ${_APP_USAGE_PASS}
  _APP_CONNECTIONS_DB_USAGE: http://appwrite:${_APP_USAGE_PASS}@clickhouse:8123/appwrite
  _APP_CONNECTIONS_DB_EXECUTIONS: http://appwrite:${_APP_USAGE_PASS}@clickhouse:8123/appwrite
  _APP_POOL_SIZE_USAGE: 2
  _APP_POOL_SIZE_EXECUTIONS: 2
  _APP_GRAPHQL_INTROSPECTION: enabled
  _APP_SMTP_HOST: ${_APP_SMTP_HOST}
  _APP_SMTP_PORT: ${_APP_SMTP_PORT}
  _APP_SMTP_USERNAME: ${_APP_SMTP_USERNAME}
  _APP_SMTP_PASSWORD: ${_APP_SMTP_PASSWORD}
  _APP_SYSTEM_EMAIL_ADDRESS: ${_APP_SYSTEM_EMAIL_ADDRESS}
  _APP_SYSTEM_EMAIL_NAME: ${_APP_SYSTEM_EMAIL_NAME}

services:
  traefik:
    image: traefik:3.7
    restart: unless-stopped
    command:
      - --providers.file.directory=/storage/config
      - --providers.file.watch=true
      - --providers.docker=true
      - --providers.docker.exposedByDefault=false
      - --providers.docker.constraints=Label(`traefik.constraint-label-stack`,`${COMPOSE_PROJECT_NAME}`)
      - --entrypoints.appwrite_web.address=:80
    ports:
      - "127.0.0.1:${PORT}:80"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - appwrite-config:/storage/config:ro
      - appwrite-certificates:/storage/certificates:ro
    networks:
      - gateway
      - appwrite
    depends_on:
      - appwrite

  appwrite:
    image: appwrite/appwrite:2.7.2
    restart: unless-stopped
    # The marker is written once the database schema exists, so traffic waits for it.
    healthcheck:
      test: ["CMD-SHELL", "test -f /tmp/.appwrite-ready && curl -fsS -o /dev/null http://localhost/v1/health/version"]
      interval: 5s
      timeout: 5s
      retries: 12
      start_period: 120s
    labels:
      - traefik.enable=true
      - traefik.constraint-label-stack=${COMPOSE_PROJECT_NAME}
      - traefik.docker.network=${COMPOSE_PROJECT_NAME}_appwrite
      - traefik.http.services.appwrite_api.loadbalancer.server.port=80
      - traefik.http.routers.appwrite_api.entrypoints=appwrite_web
      - traefik.http.routers.appwrite_api.rule=PathPrefix(`/`)
      - traefik.http.routers.appwrite_api.service=appwrite_api
      # Functions and sites are served from their own host names, so they need a
      # router that wins over the console's catch-all path rule.
      - traefik.http.routers.appwrite_public.entrypoints=appwrite_web
      - traefik.http.routers.appwrite_public.rule=Host(`*.functions.${_APP_DOMAIN}`) || Host(`*.sites.${_APP_DOMAIN}`)
      - traefik.http.routers.appwrite_public.service=appwrite_api
      - traefik.http.routers.appwrite_public.priority=1000
    volumes:
      - appwrite-uploads:/storage/uploads:rw
      - appwrite-imports:/storage/imports:rw
      - appwrite-cache:/storage/cache:rw
      - appwrite-config:/storage/config:rw
      - appwrite-certificates:/storage/certificates:rw
      - appwrite-functions:/storage/functions:rw
      - appwrite-sites:/storage/sites:rw
      - appwrite-builds:/storage/builds:rw
    environment: *app-env
    networks:
      - appwrite
      - browser
      # Builds run on the runtimes network and download their source from this
      # container, so it has to answer to its name there too.
      - runtimes
    depends_on:
      - redis
      - postgresql
      - appwrite-geo
      - appwrite-autogravity
      - appwrite-exiftool

  appwrite-console:
    image: appwrite/new:1.2.108-self-hosted
    restart: unless-stopped
    environment:
      - VITE_CONSOLE_PROFILE=self-hosted
      - APPWRITE_ENDPOINT_SAME_ORIGIN=true
    labels:
      - traefik.enable=true
      - traefik.constraint-label-stack=${COMPOSE_PROJECT_NAME}
      - traefik.docker.network=${COMPOSE_PROJECT_NAME}_appwrite
      - traefik.http.services.appwrite_console.loadbalancer.server.port=3000
      - traefik.http.routers.appwrite_console.entrypoints=appwrite_web
      # The API, realtime and the console share one domain, so the console takes
      # everything that is not an API path.
      - traefik.http.routers.appwrite_console.rule=!PathPrefix(`/v1`) && !PathPrefix(`/.well-known`)
      - traefik.http.routers.appwrite_console.service=appwrite_console
    networks:
      - appwrite

  appwrite-realtime:
    entrypoint: realtime
    image: appwrite/appwrite:2.7.2
    restart: unless-stopped
    labels:
      - traefik.enable=true
      - traefik.constraint-label-stack=${COMPOSE_PROJECT_NAME}
      - traefik.docker.network=${COMPOSE_PROJECT_NAME}_appwrite
      - traefik.http.services.appwrite_realtime.loadbalancer.server.port=80
      - traefik.http.routers.appwrite_realtime.entrypoints=appwrite_web
      - traefik.http.routers.appwrite_realtime.rule=PathPrefix(`/v1/realtime`)
      - traefik.http.routers.appwrite_realtime.service=appwrite_realtime
    environment: *app-env
    networks:
      - appwrite
    depends_on:
      - postgresql
      - redis

  appwrite-worker:
    entrypoint: worker
    image: appwrite/appwrite:2.7.2
    restart: unless-stopped
    environment: *app-env
    volumes:
      - appwrite-uploads:/storage/uploads:rw
      - appwrite-cache:/storage/cache:rw
      - appwrite-functions:/storage/functions:rw
      - appwrite-sites:/storage/sites:rw
      - appwrite-builds:/storage/builds:rw
      - appwrite-certificates:/storage/certificates:rw
    networks:
      - appwrite
      - browser
    depends_on:
      - postgresql
      - redis

  appwrite-task-scheduler:
    entrypoint: schedule
    image: appwrite/appwrite:2.7.2
    restart: unless-stopped
    environment: *app-env
    networks:
      - appwrite
    depends_on:
      - postgresql
      - redis

  appwrite-browser:
    image: appwrite/browser:0.3.8
    restart: unless-stopped
    environment:
      - ALLOWED_HOSTS=appwrite
    # Public resolvers only, so a URL the panel renders cannot look up an internal service.
    dns:
      - 1.1.1.1
      - 8.8.8.8
    networks:
      - browser

  appwrite-autogravity:
    image: ghcr.io/appwrite/autogravity:0.0.11
    restart: unless-stopped
    environment:
      - MODEL_BACKEND=focalnet
    networks:
      - appwrite

  appwrite-exiftool:
    image: ghcr.io/appwrite/exiftool:0.1.1
    restart: unless-stopped
    networks:
      - appwrite

  appwrite-geo:
    image: appwrite/geo:0.3.2
    restart: unless-stopped
    environment:
      - GEO_SECRET=${_APP_GEO_SECRET}
    networks:
      - appwrite

  clickhouse:
    image: clickhouse/clickhouse-server:26.4.3-alpine
    restart: unless-stopped
    environment:
      - CLICKHOUSE_DB=appwrite
      - CLICKHOUSE_USER=appwrite
      - CLICKHOUSE_PASSWORD=${_APP_USAGE_PASS}
      - CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1
      - CLICKHOUSE_ALWAYS_RUN_INITDB_SCRIPTS=1
    volumes:
      - appwrite-clickhouse:/var/lib/clickhouse:rw
    ulimits:
      nofile:
        soft: 262144
        hard: 262144
    healthcheck:
      test: ["CMD-SHELL", "clickhouse-client --user \"$$CLICKHOUSE_USER\" --password \"$$CLICKHOUSE_PASSWORD\" --query \"SELECT 1\" >/dev/null"]
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 30s
    networks:
      - appwrite

  openruntimes-executor:
    image: openruntimes/executor:1.1.2
    restart: unless-stopped
    # Appwrite reaches the executor by this name, and the executor finds itself by
    # it (it looks up a container whose name is its hostname), so both carry the
    # stack's project name.
    container_name: ${COMPOSE_PROJECT_NAME}-exc1
    hostname: ${COMPOSE_PROJECT_NAME}-exc1
    stop_signal: SIGINT
    environment:
      - OPR_EXECUTOR_IMAGES=openruntimes/node:v5-22,openruntimes/static:v5-1
      - OPR_EXECUTOR_RUNTIMES=node-22,static-1
      - OPR_EXECUTOR_SECRET=${_APP_EXECUTOR_SECRET}
      - OPR_EXECUTOR_ENV=production
      - OPR_EXECUTOR_NETWORK=${COMPOSE_PROJECT_NAME}_runtimes
      - OPR_EXECUTOR_RUNTIME_VERSIONS=v5
      - OPR_EXECUTOR_INACTIVE_THRESHOLD=600
      - OPR_EXECUTOR_MAINTENANCE_INTERVAL=600
      - OPR_EXECUTOR_CONNECTION_STORAGE=local://localhost
      - OPR_EXECUTOR_CONNECTION_BUILD_CACHE_STORAGE=
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - appwrite-builds:/storage/builds:rw
      - appwrite-functions:/storage/functions:rw
      - appwrite-sites:/storage/sites:rw
      # Runtime containers mount /tmp/<runtime>/src from the host for their code,
      # so the executor has to see the host's /tmp at the same path.
      - /tmp:/tmp:rw
    healthcheck:
      test: ["CMD-SHELL", "curl -fsS -H \"Authorization: Bearer $$OPR_EXECUTOR_SECRET\" http://localhost/v1/health >/dev/null"]
      interval: 5s
      timeout: 3s
      retries: 20
      start_period: 5s
    networks:
      - appwrite
    depends_on:
      - redis

  orchestrator:
    image: ghcr.io/open-runtimes/orchestrator/orchestrator:2.2.4
    restart: unless-stopped
    user: root
    hostname: orchestrator
    environment:
      - ORCHESTRATOR_BACKEND=docker
      - ORCHESTRATOR_NETWORK=${COMPOSE_PROJECT_NAME}_runtimes
      - DOCKER_NETWORK=${COMPOSE_PROJECT_NAME}_runtimes
      - ARTIFACT_ENDPOINT=http://orchestrator:8080
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    networks:
      - appwrite
      # Build sidecars run on the runtimes network and upload their artifacts
      # here, so this name has to resolve there too.
      - runtimes

  postgresql:
    image: appwrite/postgres:0.1.0
    restart: unless-stopped
    init: true
    shm_size: 1g
    command:
      - postgres
    environment:
      - POSTGRES_DB=appwrite
      - POSTGRES_USER=appwrite
      - POSTGRES_PASSWORD=${_APP_DB_PASS}
    volumes:
      - appwrite-postgresql:/var/lib/postgresql:rw
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U appwrite -d appwrite"]
      interval: 5s
      timeout: 5s
      retries: 10
      start_period: 10s
    networks:
      - appwrite

  redis:
    image: redis:7.4.7-alpine
    restart: unless-stopped
    command:
      - redis-server
      - --maxmemory
      - 512mb
      - --maxmemory-policy
      - allkeys-lru
      - --maxmemory-samples
      - "5"
    volumes:
      - appwrite-redis:/data:rw
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 12
    networks:
      - appwrite

networks:
  gateway:
  appwrite:
  runtimes:
    name: ${COMPOSE_PROJECT_NAME}_runtimes
  browser:

volumes:
  appwrite-postgresql:
  appwrite-redis:
  appwrite-clickhouse:
  appwrite-uploads:
  appwrite-imports:
  appwrite-cache:
  appwrite-config:
  appwrite-certificates:
  appwrite-functions:
  appwrite-sites:
  # Named after the stack, so the executor and the orchestrator attach it by the same name.
  appwrite-builds:
    name: ${COMPOSE_PROJECT_NAME}_builds
APPWRITE_COMPOSE_YAML,
    ],
];
