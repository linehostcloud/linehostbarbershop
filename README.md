# Sistema Barbearia

SaaS multi-tenant para gestão de barbearias com foco em agenda, comandas, financeiro, automação de atendimento, recorrência e retenção de clientes.

## Fonte oficial

O documento principal do projeto está em:

- `docs/AI-Operating-Manual.md`

Esse arquivo define produto, modelagem de dados, arquitetura, automações, integrações, estratégia de testes e roadmap.

Complementos técnicos vivos:

- `docs/tenant-aware-operacao-e-auditoria.md`

Esse guia operacional documenta os contratos internos de enforcement tenant-aware e quando usar `audit_logs`, `tenant_operational_block_audits` e `boundary_rejection_audits`.

## Stack

- Laravel 12
- PHP 8.3
- MariaDB
- Redis
- Docker
- Nginx
- Mailpit
- Integração WhatsApp por provider adapter

## Como rodar localmente

Premissas:

- a rede Docker externa usada na infraestrutura local é `linehost-network`
- `mariadb`, `mailpit` e o proxy HTTP já existem nessa rede
- o host local configurado para a aplicação é `sistema-barbearia.localhost`
- tenants locais devem ser acessados por `http://<slug>.sistema-barbearia.localhost`

Passos:

1. Copie o ambiente:

   ```bash
   cp .env.example .env
   ```

2. Revise no `.env`:

   - `DOCKER_SHARED_NETWORK=linehost-network`
   - `DB_DEFAULT_CONNECTION=landlord`
   - `LANDLORD_DB_HOST=mariadb`
   - `TENANT_DB_HOST=mariadb`
   - `MAIL_HOST=mailpit`
   - `APP_URL=http://sistema-barbearia.localhost`
   - `VIRTUAL_HOST=sistema-barbearia.localhost`
   - `TENANT_DEFAULT_DOMAIN_SUFFIX=sistema-barbearia.localhost`
   - `TENANT_LOCAL_BROWSER_DOMAIN_SUFFIX=sistema-barbearia.localhost`
   - `CENTRAL_DOMAINS=sistema-barbearia.localhost,localhost,127.0.0.1`
   - `WHATSAPP_DEFAULT_PROVIDER=fake`
   - `OUTBOX_DEFAULT_MAX_ATTEMPTS=5`
   - `OUTBOX_DEFAULT_RETRY_BACKOFF_SECONDS=60`

3. Instale a base do Laravel:

   ```bash
   docker compose run --rm setup
   ```

4. Suba o ambiente:

   ```bash
   docker compose up -d --build
   ```

4.1. Instale a regra wildcard do proxy local para tenants uma única vez:

   ```bash
   sh scripts/dev/ensure-local-tenant-proxy.sh
   ```

5. Gere a chave:

   ```bash
   docker compose run --rm app php artisan key:generate
   ```

6. Rode as migrations globais do landlord:

   ```bash
   docker compose run --rm app php artisan tenancy:migrate-landlord
   ```

7. Provisione um tenant completo:

   ```bash
   docker compose run --rm app php artisan tenancy:provision-tenant barbearia-demo "Barbearia Demo" --owner-email=owner@barbearia-demo.local --owner-name="Owner Demo"
   ```

   Depois disso, o login do tenant fica disponível em:

   ```bash
   http://barbearia-demo.sistema-barbearia.localhost/painel/operacoes/whatsapp/login
   ```

8. Se precisar rerodar apenas as migrations de um tenant existente, use o slug ou ULID:

   ```bash
   docker compose run --rm app php artisan tenancy:migrate-tenant barbearia-demo
   ```

9. Rode os testes:

   ```bash
   docker compose run --rm test
   ```

## API tenant inicial

Base:

```bash
/api/v1
```

Endpoints disponíveis:

- `POST /auth/login`
- `GET /auth/me`
- `POST /auth/logout`
- `GET /tenant-users`
- `GET /tenant-users/audits`
- `POST /tenant-users/invitations`
- `POST /tenant-users/invitations/accept`
- `PATCH /tenant-users/{membership}`
- `POST /tenant-users/{membership}/reset-password`
- `GET|POST /clients`
- `GET /clients/{id}`
- `GET|POST /professionals`
- `GET /professionals/{id}`
- `GET|POST /services`
- `GET /services/{id}`
- `GET|POST /appointments`
- `GET /appointments/{id}`
- `GET|POST /orders`
- `GET /orders/{id}`
- `POST /orders/{id}/close`
- `GET /payments`
- `GET /payments/{id}`
- `GET /transactions`
- `GET /transactions/{id}`
- `GET|POST /cash-register-sessions`
- `GET /cash-register-sessions/{id}`
- `POST /cash-register-sessions/{id}/movements`
- `POST /cash-register-sessions/{id}/close`
- `GET /professionals/{id}/commission-summary`
- `POST /professionals/{id}/commission-payouts`
- `GET /finance/summary`
- `GET /messages`
- `POST /messages/whatsapp`
- `GET /messages/{id}`
- `GET /event-logs`
- `GET /outbox-events`
- `GET /integration-attempts`
- `GET /boundary-rejection-audits`

Fluxo financeiro operacional:

- autenticar no tenant com `POST /auth/login` e usar `Authorization: Bearer <token>`
- gerenciar equipe com `GET /tenant-users`, `POST /tenant-users/invitations`, `PATCH /tenant-users/{membership}` e `POST /tenant-users/{membership}/reset-password`
- aceitar convite sem sessão prévia com `POST /tenant-users/invitations/accept`
- abrir uma sessão de caixa com `POST /cash-register-sessions`
- registrar suprimento, sangria, entrada ou saída manual com `POST /cash-register-sessions/{id}/movements`
- fechar uma comanda com `POST /orders/{id}/close`
- informar `payments[]` para split payment e vincular pagamentos em dinheiro a `cash_register_session_id`
- consultar recebimentos em `GET /payments`
- consultar lançamentos financeiros e comissões provisionadas em `GET /transactions`
- consultar saldo de comissão por profissional em `GET /professionals/{id}/commission-summary`
- registrar repasse de comissão em `POST /professionals/{id}/commission-payouts`
- consultar consolidado financeiro em `GET /finance/summary?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- fechar o caixa com `POST /cash-register-sessions/{id}/close`, informando `counted_cash_cents`

Resolução de tenant:

- por domínio do tenant
- ou pelo header `X-Tenant-Slug` quando estiver em domínio central
- o bearer token emitido em `POST /auth/login` fica vinculado ao `tenant_id` e não funciona em outro tenant

## Observabilidade e WhatsApp

Fluxo implementado:

- eventos de domínio relevantes, como `appointment.created` e `order.closed`, viram registros em `event_logs` e `outbox_events` dentro do banco do tenant
- envio outbound de WhatsApp cria `messages`, um `event_log` de auditoria e um `outbox_event` com processamento assíncrono
- webhooks inbound são validados, deduplicados por hash de payload e processados via outbox
- tentativas de integração ficam rastreadas em `integration_attempts` com `provider_message_id`, `provider_status`, `provider_request_id`, `http_status`, `latency_ms`, `normalized_status` e `normalized_error_code`
- configuração inválida do provider falha antes do enqueue, responde `422 validation_error` e não cria `message`, `outbox_event` nem `integration_attempt`
- rejeições no boundary, antes de `message`, `outbox_event` e `integration_attempt`, ficam persistidas em `boundary_rejection_audits` no banco `landlord`, com payload e headers sanitizados
- `outbox_events` presos em `processing` podem ser recuperados com reclaim seguro baseado em `reserved_at`, trilha operacional e evidências internas de dispatch

Comando operacional:

```bash
docker compose run --rm app php artisan tenancy:process-outbox --tenant=barbearia-demo --limit=50
docker compose run --rm app php artisan tenancy:reclaim-stale-outbox --tenant=barbearia-demo --limit=50
```

Configurar provider por tenant:

```bash
docker compose run --rm app php artisan tenancy:configure-whatsapp-provider barbearia-demo whatsapp_cloud \
  --slot=primary \
  --base-url=https://graph.facebook.com \
  --api-version=v22.0 \
  --access-token=SEU_TOKEN \
  --phone-number-id=SEU_PHONE_NUMBER_ID \
  --capability=text \
  --capability=template \
  --capability=media \
  --capability=inbound_webhook \
  --capability=delivery_status \
  --capability=read_receipt \
  --retry-max=5 \
  --retry-backoff=60
```

Health check do provider ativo:

```bash
docker compose run --rm app php artisan tenancy:whatsapp-healthcheck barbearia-demo --slot=primary
```

## Runbook operacional do WhatsApp

Comandos principais:

```bash
# subir landlord + tenants já existentes
docker compose run --rm app php artisan tenancy:migrate-landlord
docker compose run --rm app php artisan tenancy:migrate-tenants

# migrar apenas um tenant existente
docker compose run --rm app php artisan tenancy:migrate-tenant barbearia-demo

# scheduler manual
docker compose run --rm app php artisan schedule:run

# processar pipeline/outbox
docker compose run --rm app php artisan tenancy:process-outbox --tenant=barbearia-demo --limit=50

# rodar automações manualmente
docker compose run --rm app php artisan tenancy:process-whatsapp-automations --tenant=barbearia-demo --limit=100

# rodar agente manualmente
docker compose run --rm app php artisan tenancy:run-whatsapp-agent --tenant=barbearia-demo

# rodar housekeeping manualmente
docker compose run --rm app php artisan tenancy:whatsapp-housekeeping --tenant=barbearia-demo --limit=200
```

Agendamento recorrente:

- `tenancy:process-outbox`: a cada minuto
- `tenancy:process-whatsapp-automations`: a cada 5 minutos
- `tenancy:run-whatsapp-agent`: a cada 10 minutos
- `tenancy:whatsapp-housekeeping`: a cada hora

Problemas comuns:

- filas travadas / outbox preso:
  - rode `tenancy:process-outbox`
  - se houver muitos itens em `processing`, rode `tenancy:reclaim-stale-outbox` ou `tenancy:whatsapp-housekeeping`
- tenant antigo sem tabelas novas:
  - rode `tenancy:migrate-tenants`
  - para um tenant específico, `tenancy:migrate-tenant <slug>`
- provider instável:
  - verifique o painel operacional em `Saúde por Provider`
  - rode `tenancy:whatsapp-healthcheck <tenant> --slot=primary`
  - veja insights do agente e fallbacks recentes no feed
- duplicidade / retry excessivo:
  - revise `duplicate_risk`, `duplicate_prevented`, `retry_scheduled` e `fallback_scheduled` no painel
  - confira o feed operacional e os `integration_attempts`

Esse comando:

- conecta no banco de cada tenant informado
- busca eventos com `status in (pending, retry_scheduled)` e `available_at <= now()`
- processa envio de WhatsApp, webhook e eventos de auditoria
- reaplica retry apenas para falhas classificadas como retryable
- preserva idempotência de webhook e evita claim duplo no outbox
- quando `OUTBOX_RECLAIM_AUTO_RUN_ON_PROCESS=1`, faz reclaim prévio de itens stale em `processing`

Política de reclaim stale:

- só considera stale eventos com `status=processing`, `reserved_at` antigo e acima do threshold configurado
- se o dispatch já tem evidência de sucesso (`message.external_message_id`/status avançado ou `integration_attempt` sucedido), o item é reconciliado como `processed`
- se o dispatch tem evidência de falha retryable já persistida, o item volta para `retry_scheduled`
- se o dispatch tem tentativa `processing` sem evidência final, o item não é reaberto automaticamente; ele vai para `failed` com motivo de revisão manual para evitar segundo envio
- se o limite de reclaim for excedido, o item é encerrado em `failed`
- toda decisão gera `event_log` operacional: `outbox.event.reclaimed`, `outbox.event.reconciled` ou `outbox.event.reclaim.blocked`

Configuração de reclaim:

- `OUTBOX_RECLAIM_ENABLED`
- `OUTBOX_RECLAIM_AUTO_RUN_ON_PROCESS`
- `OUTBOX_RECLAIM_STALE_AFTER_SECONDS`
- `OUTBOX_RECLAIM_MAX_ATTEMPTS`
- `OUTBOX_RECLAIM_BACKOFF_SECONDS`

Providers disponíveis hoje:

- `fake`: sucesso imediato, indicado para desenvolvimento local
- `fake-transient-failure`: falha na primeira tentativa e sucesso na seguinte, indicado para validar retry
- `whatsapp_cloud`: provider prioritário e referência arquitetural
- `evolution_api`: provider self-hosted principal para flexibilidade operacional
- `gowa`: provider alternativo com autenticação Basic Auth e webhook normalizado

Capacidades atuais:

- `fake`: implementadas `text`, `template`, `media`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`
- `fake-transient-failure`: implementadas `text`, `template`, `media`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`
- `whatsapp_cloud`: `text`, `template`, `media`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`, `official_templates`
- `evolution_api`: implementadas `text`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`; preparadas mas não operacionais `instance_management`, `qr_bootstrap`
- `gowa`: `text`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`

Limitações atuais:

- fallback entre provider primário e secundário ainda não está ativo; a estrutura existe apenas para evolução futura
- `gowa` está pronto para envio de texto e normalização de webhook, mas recursos além de texto dependem de contrato oficial adicional do provider
- capability não implementada ou não habilitada pelo tenant falha no boundary com `unsupported_feature`, sem criar artefatos do pipeline operacional, e deixa trilha em `boundary_rejection_audits`
- o projeto não mantém `z-api` nem `360dialog` na camada ativa; referências legadas persistidas em histórico não são reativadas automaticamente

Webhook WhatsApp:

- rota: `POST /webhooks/whatsapp/{provider}`
- exige resolução de tenant por domínio ou `X-Tenant-Slug`
- rejeições como `provider_invalid`, `tenant_unresolved`, `provider_config_invalid`, `capability_not_supported`, `capability_not_enabled` e `webhook_signature_invalid` ficam consultáveis em `GET /api/v1/boundary-rejection-audits`
- valida assinatura ou secret quando o provider suportar
- persiste `event_logs` e `outbox_events` antes de qualquer processamento de domínio
- bloqueia duplicidade por `event_logs.idempotency_key`
- atualiza `messages.status` usando linguagem interna única: `queued`, `dispatched`, `sent`, `delivered`, `read`, `failed`, `inbound_received`, `inbound_processed`
- status desconhecido do provider é ignorado com trilha operacional; não é convertido artificialmente em `failed`
- webhook regressivo ou duplicado não rebaixa `messages.status`

Máquina de estados aplicada centralmente:

- outbound permitido: `queued -> dispatched -> sent -> delivered -> read`
- outbound também aceita salto monótono para frente quando o provider não reporta todos os degraus intermediários
- `failed` outbound terminal só se o erro for permanente; falha transitória preserva estado e agenda retry
- inbound permitido: `inbound_received -> inbound_processed`
- espaços de estado inbound e outbound são separados; um webhook não pode cruzar essa fronteira

Segurança da configuração por tenant:

- `base_url` de provider real é validada e bloqueia `localhost`, loopback, hosts internos e URL com credenciais embutidas, salvo opt-in explícito de `WHATSAPP_ALLOW_PRIVATE_NETWORK_TARGETS=1`
- `whatsapp_cloud` exige `https://`
- campos sensíveis cifrados em repouso: `api_key`, `access_token`, `webhook_secret`, `verify_token`, `settings_json`
- payloads e headers sensíveis são mascarados em logs, `event_logs` e `integration_attempts`

## Estrutura básica

- `docs/AI-Operating-Manual.md`: fonte única de verdade do projeto
- `app/`: código da aplicação
- `config/`: configurações Laravel
- `database/`: migrations, seeders e bancos SQLite locais de apoio
- `database/migrations/landlord`: estruturas globais da plataforma
- `database/migrations/tenant`: estruturas isoladas por tenant
- `.docker/`: scripts e configurações de runtime
- `docker-compose.yml`: ambiente local
- `tests/`: suítes unitárias, de integração e fluxo

## Observações

- O serviço HTTP interno do projeto é o container `web`.
- No Nginx Proxy Manager, o backend deve apontar para `sistema-barbearia:80`.
- A suíte padrão usa SQLite para evitar dependência do MariaDB nos testes locais.
- A resolução de tenant funciona por domínio ou pelo header `X-Tenant-Slug`.
- `php artisan migrate` sozinho não é o fluxo correto deste projeto; use os comandos `tenancy:migrate-landlord` e `tenancy:migrate-tenant`.
- `tenancy:provision-tenant` cria o banco do tenant, registra `tenants`, `tenant_domains`, owner opcional e executa as migrations automaticamente.
- A autenticação API usa bearer token próprio salvo no landlord em `user_access_tokens`, com TTL configurado por `AUTH_ACCESS_TOKEN_TTL_MINUTES`.
- A autorização tenant usa `tenant_memberships.role` como capacidade base e `tenant_memberships.permissions_json` como override fino.
- Convites, aceite de membership, reset de senha e auditoria de alteração de papéis/permissões vivem no landlord e não dependem do banco tenant.
- O fechamento de comanda agora gera `payments` e `transactions` na mesma transação do tenant.
- Comissões de profissionais são provisionadas automaticamente no fechamento da comanda com base no item, serviço e profissional.
- O saldo esperado da sessão de caixa é sincronizado automaticamente a cada venda em dinheiro, movimentação manual e repasse em dinheiro.
- O ledger em `transactions` agora cobre receita, comissão provisionada, repasse de comissão, suprimento, sangria e ajustes operacionais.
- Observabilidade operacional tenant agora usa `event_logs`, `outbox_events` e `integration_attempts`.
- O retry de integrações não depende de estado em memória; ele fica persistido no banco do tenant e é reprocessado por `tenancy:process-outbox`.
- O canal WhatsApp agora usa contrato interno único por provider, DTOs tipados, resolver tenant-first, normalização obrigatória de status/erro e sanitização de payloads sensíveis.
- Testes de financeiro continuam isolados em SQLite e não dependem do MariaDB compartilhado.
