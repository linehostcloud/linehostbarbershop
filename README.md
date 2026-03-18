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
- Testes de financeiro continuam isolados em SQLite e nao dependem do MariaDB compartilhado.
