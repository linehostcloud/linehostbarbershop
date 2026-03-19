# Sistema Barbearia

SaaS multi-tenant para gestao de barbearias com foco em agenda, comandas, financeiro, automacao de atendimento, recorrencia e retencao de clientes.

## Fonte oficial

O documento principal do projeto esta em:

- `docs/AI-Operating-Manual.md`

Esse arquivo define produto, modelagem de dados, arquitetura, automacoes, integracoes, estrategia de testes e roadmap.

## Stack

- Laravel 12
- PHP 8.3
- MariaDB
- Redis
- Docker
- Nginx
- Mailpit
- Integracao WhatsApp por provider adapter

## Como rodar localmente

Premissas:

- a rede Docker externa usada na infraestrutura local e `linehost-network`
- `mariadb`, `mailpit` e o proxy HTTP ja existem nessa rede
- o dominio local configurado para a aplicacao e `sistemabarbearia.local`

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
   - `VIRTUAL_HOST=sistemabarbearia.local`
   - `CENTRAL_DOMAINS=sistemabarbearia.local,localhost,127.0.0.1`
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

Endpoints disponiveis:

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
- aceitar convite sem sessao previa com `POST /tenant-users/invitations/accept`
- abrir uma sessao de caixa com `POST /cash-register-sessions`
- registrar suprimento, sangria, entrada ou saida manual com `POST /cash-register-sessions/{id}/movements`
- fechar uma comanda com `POST /orders/{id}/close`
- informar `payments[]` para split payment e vincular pagamentos em dinheiro a `cash_register_session_id`
- consultar recebimentos em `GET /payments`
- consultar lancamentos financeiros e comissoes provisionadas em `GET /transactions`
- consultar saldo de comissao por profissional em `GET /professionals/{id}/commission-summary`
- registrar repasse de comissao em `POST /professionals/{id}/commission-payouts`
- consultar consolidado financeiro em `GET /finance/summary?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- fechar o caixa com `POST /cash-register-sessions/{id}/close`, informando `counted_cash_cents`

Resolucao de tenant:

- por dominio do tenant
- ou pelo header `X-Tenant-Slug` quando estiver em dominio central
- o bearer token emitido em `POST /auth/login` fica vinculado ao `tenant_id` e nao funciona em outro tenant

## Observabilidade e WhatsApp

Fluxo implementado:

- eventos de dominio relevantes, como `appointment.created` e `order.closed`, viram registros em `event_logs` e `outbox_events` dentro do banco do tenant
- envio outbound de WhatsApp cria `messages`, um `event_log` de auditoria e um `outbox_event` com processamento assincrono
- webhooks inbound sao validados, deduplicados por hash de payload e processados via outbox
- tentativas de integracao ficam rastreadas em `integration_attempts` com `provider_message_id`, `provider_status`, `provider_request_id`, `http_status`, `latency_ms`, `normalized_status` e `normalized_error_code`
- configuracao invalida do provider falha antes do enqueue, responde `422 validation_error` e nao cria `message`, `outbox_event` nem `integration_attempt`
- rejeicoes no boundary, antes de `message`, `outbox_event` e `integration_attempt`, ficam persistidas em `boundary_rejection_audits` no banco `landlord`, com payload e headers sanitizados
- `outbox_events` presos em `processing` podem ser recuperados com reclaim seguro baseado em `reserved_at`, trilha operacional e evidencias internas de dispatch

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

Esse comando:

- conecta no banco de cada tenant informado
- busca eventos com `status in (pending, retry_scheduled)` e `available_at <= now()`
- processa envio de WhatsApp, webhook e eventos de auditoria
- reaplica retry apenas para falhas classificadas como retryable
- preserva idempotencia de webhook e evita claim duplo no outbox
- quando `OUTBOX_RECLAIM_AUTO_RUN_ON_PROCESS=1`, faz reclaim previo de itens stale em `processing`

Politica de reclaim stale:

- so considera stale eventos com `status=processing`, `reserved_at` antigo e acima do threshold configurado
- se o dispatch ja tem evidencia de sucesso (`message.external_message_id`/status avancado ou `integration_attempt` sucedido), o item e reconciliado como `processed`
- se o dispatch tem evidencia de falha retryable ja persistida, o item volta para `retry_scheduled`
- se o dispatch tem tentativa `processing` sem evidencia final, o item nao e reaberto automaticamente; ele vai para `failed` com motivo de revisao manual para evitar segundo envio
- se o limite de reclaim for excedido, o item e encerrado em `failed`
- toda decisao gera `event_log` operacional: `outbox.event.reclaimed`, `outbox.event.reconciled` ou `outbox.event.reclaim.blocked`

Configuracao de reclaim:

- `OUTBOX_RECLAIM_ENABLED`
- `OUTBOX_RECLAIM_AUTO_RUN_ON_PROCESS`
- `OUTBOX_RECLAIM_STALE_AFTER_SECONDS`
- `OUTBOX_RECLAIM_MAX_ATTEMPTS`
- `OUTBOX_RECLAIM_BACKOFF_SECONDS`

Providers disponiveis hoje:

- `fake`: sucesso imediato, indicado para desenvolvimento local
- `fake-transient-failure`: falha na primeira tentativa e sucesso na seguinte, indicado para validar retry
- `whatsapp_cloud`: provider prioritario e referencia arquitetural
- `evolution_api`: provider self-hosted principal para flexibilidade operacional
- `gowa`: provider alternativo com autenticacao Basic Auth e webhook normalizado

Capacidades atuais:

- `fake`: implementadas `text`, `template`, `media`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`
- `fake-transient-failure`: implementadas `text`, `template`, `media`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`
- `whatsapp_cloud`: `text`, `template`, `media`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`, `official_templates`
- `evolution_api`: implementadas `text`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`; preparadas mas nao operacionais `instance_management`, `qr_bootstrap`
- `gowa`: `text`, `inbound_webhook`, `delivery_status`, `read_receipt`, `healthcheck`

Limitacoes atuais:

- fallback entre provider primario e secundario ainda nao esta ativo; a estrutura existe apenas para evolucao futura
- `gowa` esta pronto para envio de texto e normalizacao de webhook, mas recursos alem de texto dependem de contrato oficial adicional do provider
- capability nao implementada ou nao habilitada pelo tenant falha no boundary com `unsupported_feature`, sem criar artefatos do pipeline operacional, e deixa trilha em `boundary_rejection_audits`
- o projeto nao mantem `z-api` nem `360dialog` na camada ativa; referencias legadas persistidas em historico nao sao reativadas automaticamente

Webhook WhatsApp:

- rota: `POST /webhooks/whatsapp/{provider}`
- exige resolucao de tenant por dominio ou `X-Tenant-Slug`
- rejeicoes como `provider_invalid`, `tenant_unresolved`, `provider_config_invalid`, `capability_not_supported`, `capability_not_enabled` e `webhook_signature_invalid` ficam consultaveis em `GET /api/v1/boundary-rejection-audits`
- valida assinatura ou secret quando o provider suportar
- persiste `event_logs` e `outbox_events` antes de qualquer processamento de dominio
- bloqueia duplicidade por `event_logs.idempotency_key`
- atualiza `messages.status` usando linguagem interna unica: `queued`, `dispatched`, `sent`, `delivered`, `read`, `failed`, `inbound_received`, `inbound_processed`
- status desconhecido do provider e ignorado com trilha operacional; nao e convertido artificialmente em `failed`
- webhook regressivo ou duplicado nao rebaixa `messages.status`

Maquina de estados aplicada centralmente:

- outbound permitido: `queued -> dispatched -> sent -> delivered -> read`
- outbound tambem aceita salto monotono para frente quando o provider nao reporta todos os degraus intermediarios
- `failed` outbound terminal so se o erro for permanente; falha transitoria preserva estado e agenda retry
- inbound permitido: `inbound_received -> inbound_processed`
- espacos de estado inbound e outbound sao separados; um webhook nao pode cruzar essa fronteira

Seguranca da configuracao por tenant:

- `base_url` de provider real e validada e bloqueia `localhost`, loopback, hosts internos e URL com credenciais embutidas, salvo opt-in explicito de `WHATSAPP_ALLOW_PRIVATE_NETWORK_TARGETS=1`
- `whatsapp_cloud` exige `https://`
- campos sensiveis cifrados em repouso: `api_key`, `access_token`, `webhook_secret`, `verify_token`, `settings_json`
- payloads e headers sensiveis sao mascarados em logs, `event_logs` e `integration_attempts`

## Estrutura basica

- `docs/AI-Operating-Manual.md`: fonte unica de verdade do projeto
- `app/`: codigo da aplicacao
- `config/`: configuracoes Laravel
- `database/`: migrations, seeders e bancos SQLite locais de apoio
- `database/migrations/landlord`: estruturas globais da plataforma
- `database/migrations/tenant`: estruturas isoladas por tenant
- `.docker/`: scripts e configuracoes de runtime
- `docker-compose.yml`: ambiente local
- `tests/`: suites unitarias, de integracao e fluxo

## Observacoes

- O servico HTTP interno do projeto e o container `web`.
- No Nginx Proxy Manager, o backend deve apontar para `sistema-barbearia:80`.
- A suite padrao usa SQLite para evitar dependencia do MariaDB nos testes locais.
- A resolucao de tenant funciona por dominio ou pelo header `X-Tenant-Slug`.
- `php artisan migrate` sozinho nao e o fluxo correto deste projeto; use os comandos `tenancy:migrate-landlord` e `tenancy:migrate-tenant`.
- `tenancy:provision-tenant` cria o banco do tenant, registra `tenants`, `tenant_domains`, owner opcional e executa as migrations automaticamente.
- A autenticacao API usa bearer token proprio salvo no landlord em `user_access_tokens`, com TTL configurado por `AUTH_ACCESS_TOKEN_TTL_MINUTES`.
- A autorizacao tenant usa `tenant_memberships.role` como capacidade base e `tenant_memberships.permissions_json` como override fino.
- Convites, aceite de membership, reset de senha e auditoria de alteracao de papeis/permissoes vivem no landlord e nao dependem do banco tenant.
- O fechamento de comanda agora gera `payments` e `transactions` na mesma transacao do tenant.
- Comissoes de profissionais sao provisionadas automaticamente no fechamento da comanda com base no item, servico e profissional.
- O saldo esperado da sessao de caixa e sincronizado automaticamente a cada venda em dinheiro, movimentacao manual e repasse em dinheiro.
- O ledger em `transactions` agora cobre receita, comissao provisionada, repasse de comissao, suprimento, sangria e ajustes operacionais.
- Observabilidade operacional tenant agora usa `event_logs`, `outbox_events` e `integration_attempts`.
- O retry de integracoes nao depende de estado em memoria; ele fica persistido no banco do tenant e e reprocessado por `tenancy:process-outbox`.
- O canal WhatsApp agora usa contrato interno unico por provider, DTOs tipados, resolver tenant-first, normalizacao obrigatoria de status/erro e sanitizacao de payloads sensiveis.
- Testes de financeiro continuam isolados em SQLite e nao dependem do MariaDB compartilhado.
