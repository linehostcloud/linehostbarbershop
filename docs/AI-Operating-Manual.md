# AI Operating Manual

Versao: 1.0  
Status: documento de referencia arquitetural e funcional  
Escopo: produto, dominio, dados, arquitetura, automacao, integracoes, testes e roadmap  

Este documento e a fonte unica de verdade do sistema. Toda decisao de produto, modelagem, arquitetura, padrao de codigo e estrategia de integracao deve partir daqui. Se houver divergencia entre implementacao e este manual, a implementacao deve ser corrigida ou o manual deve ser revisado formalmente.

Decisao central de arquitetura:

- O sistema sera um modular monolith em Laravel 12.
- O sistema sera API-first.
- O modelo de multi-tenancy adotado sera `landlord database + database por tenant`.
- O sistema sera orientado a eventos internamente, com filas e outbox para efeitos assincronos.
- WhatsApp sera um canal de primeira classe, nao um anexo da aplicacao.

## 1. VISAO GERAL DO PRODUTO

### Objetivo do sistema

Construir um SaaS de gestao para barbearias que una operacao, financeiro, relacionamento com clientes e automacao de atendimento em uma plataforma unica. O sistema deve aumentar recorrencia, reduzir no-show, acelerar resposta comercial e transformar comportamento de clientes em acoes automaticas de retencao.

### Problemas que resolve

- Agenda desorganizada entre varios profissionais.
- Atendimento dependente demais de WhatsApp manual.
- Falta de historico confiavel de clientes, servicos e comandas.
- Falta de visibilidade de faturamento, ticket medio e comissao.
- Dificuldade de reativar clientes que pararam de frequentar.
- Pouca padronizacao no processo de confirmacao, lembrete e pos-venda.
- Ausencia de inteligencia operacional para aumentar ocupacao e recorrencia.

### Publico-alvo

- Barbearias com 1 a 50 profissionais.
- Donos-operadores que acumulam agenda, atendimento e financeiro.
- Gerentes que precisam acompanhar ocupacao, comissao e caixa.
- Redes pequenas ou franquias que exigem padrao e escala.
- Futuramente, nichos adjacentes de servicos recorrentes com agenda e relacionamento: saloes, estudios de estetica, clinicas de bem-estar e studios especializados.

### Proposta de valor

O produto organiza o dia a dia da barbearia e transforma dados de atendimento em faturamento recorrente. A plataforma nao apenas registra agenda e pagamentos; ela identifica clientes em risco, dispara acoes automaticas, conversa por WhatsApp e cria rotinas de retencao de forma previsivel.

### Diferencial competitivo

- Reativacao automatica baseada em comportamento real de consumo.
- Agente de atendimento via WhatsApp com foco em agendamento e reconversao.
- Automacoes orientadas a eventos, em vez de lembretes genericos.
- Modelo multi-tenant preparado para crescimento operacional e comercial.
- Arquitetura que suporta evolucao para outros nichos sem perder o foco inicial em barbearias.

## 2. ESCOPO FUNCIONAL COMPLETO

### 2.1 Nucleo (MVP)

#### Clientes

- Cadastro completo com nome, telefone, email, observacoes e consentimentos.
- Historico de visitas, cancelamentos, no-show e ticket acumulado.
- Tags, origem de aquisicao e profissional preferido.
- Perfil unico por tenant, deduplicado por telefone.

#### Agenda (multi-profissional)

- Agenda diaria, semanal e por profissional.
- Bloqueios de horario, pausas e indisponibilidade.
- Agendamento manual, por link publico ou por WhatsApp.
- Confirmacao, cancelamento, remarcacao e check-in.
- Controle de conflito por horario e profissional.

#### Servicos

- Cadastro de servicos com duracao, preco, custo e comissao padrao.
- Agrupamento por categoria.
- Ativacao e inativacao sem perder historico.
- Suporte a servico principal e itens adicionais.

#### Comandas

- Abertura por agendamento ou atendimento avulso.
- Inclusao de servicos, produtos, descontos e ajustes.
- Fechamento parcial ou total.
- Associacao a cliente, profissional e assinatura.

#### Caixa basico

- Abertura e fechamento de caixa.
- Registro de entradas, saidas, reforco e sangria.
- Conciliacao simples por forma de pagamento.
- Extrato operacional do dia.
- O ledger operacional do caixa usa `transactions` com `cash_register_session_id`, `category` e `balance_direction` para refletir suprimento, sangria, despesas e entradas manuais sem criar tabelas paralelas.

### 2.2 Financeiro

#### Controle de caixa

- Sessoes de caixa por unidade e operador.
- Saldo inicial, movimentacoes e saldo final.
- Auditoria de divergencia entre valor esperado e valor contado.

#### Pagamentos

- Dinheiro, PIX, cartao, link de pagamento e registro manual.
- Pagamento vinculado a comanda ou assinatura.
- Status de pagamento: pendente, pago, falho, estornado.
- Suporte a pagamento parcial, multipla forma e estorno.

#### Assinaturas (recorrencia)

- Planos mensais ou customizados para clientes finais da barbearia.
- Controle de creditos/sessoes inclusas.
- Renovacao manual ou automatica.
- Bloqueio ou suspensao por inadimplencia.

#### Comissao de profissionais

- Comissao por servico, por percentual fixo ou modelo misto.
- Base de calculo por item de comanda.
- Relatorio de comissao provisionada e paga.
- O saldo pendente do profissional e calculado por `professional_commission - commission_payout`, permitindo repasse parcial ou total sem perder rastreabilidade.

### 2.3 Inteligencia de retencao

#### Calculo de frequencia de clientes

- Baseado em visitas concluidas.
- Considera intervalo medio ou mediano entre atendimentos.
- Usa janela minima de 2 ou 3 visitas para inferir frequencia esperada.

#### Identificacao de clientes inativos

- Segmentacao por status: novo, regular, em risco, inativo, perdido.
- Inatividade dinamica por comportamento individual, nao apenas por regra fixa global.
- Classificacao alimenta campanhas e automacoes.

#### Campanhas automaticas

- Reativacao de inativos.
- Lembrete de retorno apos periodo esperado.
- Oferta de upgrade para assinatura.
- Recuperacao de no-show e de cancelamento.

### 2.4 Automacao e comunicacao

#### Sistema de notificacoes

- Canal interno.
- WhatsApp.
- Email e SMS como canais secundarios futuros.
- Templates, cooldown, janela de envio e observabilidade por entrega.

#### Integracao com WhatsApp

- Envio e recebimento.
- Confirmacao de agendamento.
- Lembrete automatico.
- Reativacao e reconquista.

#### Agente automatico de atendimento

- Intencao principal: agendar, remarcar, confirmar, cancelar e responder duvidas basicas.
- Capacidade de consultar disponibilidade real.
- Handoff para humano quando houver baixa confianca, conflito ou excecao operacional.

### 2.5 Relatorios

#### Faturamento

- Diario, semanal, mensal e por periodo customizado.
- Por profissional, servico, canal e unidade futura.

#### Ticket medio

- Geral.
- Por cliente.
- Por profissional.
- Por servico.

#### Ranking de profissionais

- Faturamento.
- Atendimentos concluidos.
- Ticket medio.
- Retencao da carteira.

#### Retencao de clientes

- Clientes novos vs recorrentes.
- Taxa de retorno.
- Clientes em risco.
- Clientes reativados por automacao.

## 3. MODELAGEM DE DADOS (DETALHADA)

### 3.1 Estrategia geral de dados

O sistema usa dois niveis de banco:

- `Landlord database`: banco central da plataforma. Guarda tenants, usuarios globais, dominios, credenciais de conexao e metadados de plataforma.
- `Tenant database`: um banco por tenant. Guarda operacao da barbearia: clientes, agenda, comandas, financeiro, mensagens e automacoes.

Justificativa da escolha `database por tenant`:

- Isolamento forte de dados.
- Backup e restore por tenant.
- Menor risco de vazamento por query mal filtrada.
- Melhor caminho para tenants premium, franquias e requisitos de compliance.
- Simplifica exportacao e migracao de tenant entre clusters.

Trade-off aceito:

- Maior custo operacional de provisionamento e migrations.
- Consultas globais exigem pipeline de agregacao no landlord ou data mart.

### 3.2 Convencoes de modelagem

- Chaves primarias: `ulid`.
- Valores monetarios: `unsignedBigInteger` em centavos.
- Datas de negocio: UTC no banco; exibicao convertida para timezone do tenant.
- Soft delete apenas quando o historico precisa ser preservado.
- Campos `metadata_json` e `conditions_json` aceitos apenas quando a estrutura for extensivel e versionada.

### 3.3 Landlord database

#### Tabela: `tenants`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| legal_name | string(160) | Razao social |
| trade_name | string(160) | Nome fantasia |
| slug | string(80) unique | Identificador de tenant |
| niche | string(50) | `barbershop` por padrao |
| timezone | string(64) | Ex.: `America/Sao_Paulo` |
| currency | char(3) | Ex.: `BRL` |
| status | enum | `trial`, `active`, `suspended`, `canceled` |
| onboarding_stage | string(50) | Controle de implantacao |
| database_name | string(128) unique | Banco dedicado do tenant |
| database_host | string(128) | Host do banco do tenant |
| database_port | unsignedSmallInteger | Porta do banco do tenant |
| database_username | string(128) | Usuario do banco do tenant |
| database_password_encrypted | text | Segredo criptografado |
| plan_code | string(50) nullable | Plano da plataforma |
| trial_ends_at | timestamp nullable | Fim do trial |
| activated_at | timestamp nullable | Ativacao comercial |
| suspended_at | timestamp nullable | Suspensao |
| last_seen_at | timestamp nullable | Ultima atividade do tenant |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- `tenants` hasMany `tenant_domains`
- `tenants` hasMany `tenant_memberships`
- `tenants` hasMany `users` through `tenant_memberships`

#### Tabela: `users`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| name | string(120) | Nome completo |
| email | string(190) unique | Login principal |
| phone_e164 | string(20) nullable | Telefone global |
| password | string(255) | Senha hash |
| locale | string(10) | Ex.: `pt_BR` |
| status | enum | `invited`, `active`, `blocked` |
| email_verified_at | timestamp nullable | Confirmacao de email |
| last_login_at | timestamp nullable | Ultimo acesso |
| two_factor_secret | text nullable | 2FA futuro |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- `users` hasMany `tenant_memberships`

#### Tabela: `user_access_tokens`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| user_id | ulid | FK para `users.id` |
| tenant_id | ulid | FK para `tenants.id` |
| name | string(80) | Device/app emissor |
| token_hash | string(64) unique | Hash SHA-256 do segredo |
| abilities_json | json nullable | Escopos do token |
| ip_address | string(45) nullable | Origem |
| user_agent | text nullable | Origem |
| last_used_at | timestamp nullable | Ultimo uso |
| expires_at | timestamp nullable | Expiracao |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `user`
- belongsTo `tenant`

#### Tabela: `tenant_memberships`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| tenant_id | ulid | FK para `tenants.id` |
| user_id | ulid | FK para `users.id` |
| role | enum | `owner`, `manager`, `receptionist`, `professional`, `finance`, `automation_admin` |
| is_primary | boolean | Membership principal do usuario |
| permissions_json | json nullable | Overrides finos, se necessario |
| invited_at | timestamp nullable | Convite criado |
| accepted_at | timestamp nullable | Convite aceito |
| revoked_at | timestamp nullable | Revogacao |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `tenant`
- belongsTo `user`

#### Tabela: `tenant_user_invitations`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| tenant_id | ulid | FK para `tenants.id` |
| user_id | ulid | FK para `users.id` |
| tenant_membership_id | ulid | FK para `tenant_memberships.id` |
| invited_by_user_id | ulid nullable | Quem convidou |
| token_hash | string(64) unique | Hash SHA-256 do segredo |
| expires_at | timestamp nullable | Expiracao do convite |
| accepted_at | timestamp nullable | Aceite do convite |
| metadata_json | json nullable | Metadados de entrega/canal |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `tenant`
- belongsTo `user`
- belongsTo `tenant_membership`

#### Tabela: `audit_logs`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| tenant_id | ulid | FK para `tenants.id` |
| actor_user_id | ulid nullable | Usuario que executou a acao |
| auditable_type | string(160) nullable | Classe alvo |
| auditable_id | ulid nullable | Registro alvo |
| action | string(80) | Ex.: `tenant_user.invited` |
| before_json | json nullable | Estado anterior |
| after_json | json nullable | Estado posterior |
| metadata_json | json nullable | Contexto adicional |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `tenant`
- belongsTo `actor`

#### Tabela: `tenant_domains`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| tenant_id | ulid | FK para `tenants.id` |
| domain | string(190) unique | Dominio associado ao tenant |
| type | enum | `admin`, `api`, `public` |
| is_primary | boolean | Dominio principal |
| ssl_status | enum | `pending`, `active`, `failed` |
| verified_at | timestamp nullable | Confirmacao do dominio |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `tenant`

### 3.4 Tenant database

Observacao importante:

- Nas tabelas operacionais do tenant, `tenant_id` nao e armazenado porque o isolamento e fisico por banco.
- Relacoes com `users.id` do landlord sao logicas e nao por foreign key fisica, pois atravessam bancos distintos.

#### Tabela: `clients`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| external_code | string(40) nullable | Codigo legado/importacao |
| full_name | string(160) | Nome do cliente |
| phone_e164 | string(20) | Unico por tenant quando informado |
| email | string(190) nullable | Email |
| birth_date | date nullable | Data de nascimento |
| preferred_professional_id | ulid nullable | Preferencia atual |
| acquisition_channel | string(50) nullable | `manual`, `instagram`, `whatsapp`, etc. |
| notes | text nullable | Observacoes gerais |
| marketing_opt_in | boolean | Consentimento comercial |
| whatsapp_opt_in | boolean | Consentimento do canal |
| visit_count | unsignedInteger | Total de visitas concluidas |
| average_visit_interval_days | unsignedSmallInteger nullable | Frequencia calculada |
| retention_status | enum | `new`, `regular`, `at_risk`, `inactive`, `lost` |
| last_visit_at | timestamp nullable | Ultima visita concluida |
| inactive_since | timestamp nullable | Marco de inatividade |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |
| deleted_at | timestamp nullable | Soft delete |

Relacionamentos:

- belongsTo `preferred_professional`
- hasMany `appointments`
- hasMany `orders`
- hasMany `subscriptions`
- hasMany `payments`
- hasMany `messages`

#### Tabela: `professionals`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| user_id | ulid nullable | Referencia logica ao `users.id` global |
| display_name | string(120) | Nome exibido na agenda |
| role | enum | `barber`, `assistant`, `manager`, `receptionist` |
| commission_model | enum | `fixed_percent`, `service_percent`, `mixed` |
| commission_percent | decimal(5,2) nullable | Percentual padrao |
| color_hex | char(7) nullable | Cor na agenda |
| workday_calendar_json | json nullable | Grade padrao de trabalho |
| active | boolean | Profissional ativo |
| hired_at | date nullable | Contratacao |
| terminated_at | date nullable | Encerramento |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |
| deleted_at | timestamp nullable | Soft delete |

Relacionamentos:

- hasMany `appointments`
- hasMany `order_items`
- hasMany `transactions`

#### Tabela: `services`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| category | string(80) | Categoria funcional |
| name | string(120) | Nome comercial |
| description | text nullable | Detalhes |
| duration_minutes | unsignedSmallInteger | Duracao prevista |
| price_cents | unsignedBigInteger | Preco base |
| cost_cents | unsignedBigInteger nullable | Custo interno |
| commissionable | boolean | Participa de comissao |
| default_commission_percent | decimal(5,2) nullable | Percentual padrao |
| requires_subscription | boolean | Exige assinatura/credito |
| active | boolean | Disponivel para venda |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |
| deleted_at | timestamp nullable | Soft delete |

Relacionamentos:

- hasMany `appointments`
- hasMany `order_items`

#### Tabela: `appointments`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| client_id | ulid | FK para `clients.id` |
| professional_id | ulid | FK para `professionals.id` |
| primary_service_id | ulid nullable | FK para `services.id` |
| subscription_id | ulid nullable | Assinatura usada para o atendimento |
| booked_by_user_id | ulid nullable | Usuario que criou |
| source | enum | `dashboard`, `whatsapp`, `public_link`, `import` |
| status | enum | `pending`, `confirmed`, `checked_in`, `in_service`, `completed`, `canceled`, `no_show` |
| starts_at | dateTime | Inicio agendado |
| ends_at | dateTime | Fim esperado |
| duration_minutes | unsignedSmallInteger | Duracao consolidada |
| confirmation_status | enum | `not_sent`, `pending`, `confirmed`, `rejected` |
| reminder_sent_at | timestamp nullable | Ultimo lembrete |
| notes | text nullable | Observacoes do atendimento |
| cancel_reason | string(255) nullable | Motivo do cancelamento |
| canceled_at | timestamp nullable | Timestamp de cancelamento |
| completed_at | timestamp nullable | Timestamp de conclusao |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `client`
- belongsTo `professional`
- belongsTo `primary_service`
- belongsTo `subscription`
- hasOne `order` via `orders.appointment_id`

#### Tabela: `orders`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| client_id | ulid nullable | FK para `clients.id` |
| appointment_id | ulid nullable | FK para `appointments.id` |
| primary_professional_id | ulid nullable | Dono principal da comanda |
| opened_by_user_id | ulid nullable | Usuario que abriu |
| closed_by_user_id | ulid nullable | Usuario que fechou |
| origin | enum | `appointment`, `walk_in`, `subscription` |
| status | enum | `open`, `closed`, `canceled` |
| subtotal_cents | unsignedBigInteger | Soma dos itens |
| discount_cents | unsignedBigInteger | Desconto aplicado |
| fee_cents | unsignedBigInteger | Taxas adicionais |
| total_cents | unsignedBigInteger | Total final |
| amount_paid_cents | unsignedBigInteger | Total pago |
| opened_at | dateTime | Inicio da comanda |
| closed_at | dateTime nullable | Fechamento |
| notes | text nullable | Observacoes |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `client`
- belongsTo `appointment`
- hasMany `order_items`
- hasMany `payments` via `payable`

#### Tabela: `order_items`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| order_id | ulid | FK para `orders.id` |
| service_id | ulid nullable | FK para `services.id` |
| professional_id | ulid nullable | FK para `professionals.id` |
| subscription_id | ulid nullable | Credito/assinatura usada |
| type | enum | `service`, `product`, `adjustment`, `subscription_credit` |
| description | string(190) | Snapshot do item vendido |
| quantity | decimal(10,3) | Quantidade |
| unit_price_cents | unsignedBigInteger | Valor unitario |
| total_price_cents | unsignedBigInteger | Valor total |
| commission_percent | decimal(5,2) nullable | Percentual efetivo |
| metadata_json | json nullable | Payload extensivel |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `order`
- belongsTo `service`
- belongsTo `professional`
- belongsTo `subscription`

#### Tabela: `subscriptions`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| client_id | ulid | FK para `clients.id` |
| name | string(120) | Nome comercial do plano |
| plan_type | enum | `monthly`, `weekly`, `custom` |
| billing_cycle_days | unsignedSmallInteger | Ciclo de cobranca |
| price_cents | unsignedBigInteger | Valor por ciclo |
| included_sessions | unsignedSmallInteger nullable | Sessoes inclusas |
| included_services_json | json nullable | Servicos cobertos |
| remaining_sessions | unsignedSmallInteger nullable | Creditos restantes |
| renewal_mode | enum | `manual`, `automatic` |
| payment_method_token | string(191) nullable | Token do gateway |
| status | enum | `trial`, `active`, `past_due`, `paused`, `canceled`, `expired` |
| started_at | dateTime | Inicio |
| renews_at | dateTime nullable | Proxima renovacao |
| last_billed_at | dateTime nullable | Ultima cobranca |
| canceled_at | dateTime nullable | Cancelamento |
| notes | text nullable | Observacoes |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `client`
- hasMany `payments` via `payable`
- hasMany `appointments`

#### Tabela: `payments`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| payable_type | string(80) | `order` ou `subscription` |
| payable_id | ulid | ID do agregado pago |
| client_id | ulid nullable | FK para `clients.id` |
| provider | enum | `cash`, `pix`, `card`, `link`, `manual`, `gateway` |
| gateway | string(50) nullable | Nome do gateway |
| external_reference | string(191) nullable | ID externo do provedor |
| amount_cents | unsignedBigInteger | Valor |
| currency | char(3) | `BRL` |
| installment_count | unsignedTinyInteger | Parcelas |
| status | enum | `pending`, `authorized`, `paid`, `failed`, `refunded`, `canceled`, `partially_refunded` |
| paid_at | dateTime nullable | Liquidacao |
| due_at | dateTime nullable | Vencimento |
| failure_reason | string(255) nullable | Erro de pagamento |
| metadata_json | json nullable | Payload do gateway |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- morphTo `payable`
- belongsTo `client`
- hasMany `transactions`

#### Tabela: `transactions`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| payment_id | ulid nullable | FK para `payments.id` |
| professional_id | ulid nullable | Profissional vinculado, se houver |
| source_type | string(80) nullable | Origem do lancamento |
| source_id | ulid nullable | Origem do lancamento |
| occurred_on | date | Data contabil |
| type | enum | `income`, `expense`, `transfer`, `adjustment`, `commission`, `refund` |
| category | enum | `service_sale`, `subscription_sale`, `manual_expense`, `cash_opening`, `cash_closing`, `commission`, `refund` |
| description | string(190) | Historico legivel |
| amount_cents | bigint | Sinal representa efeito financeiro |
| balance_direction | enum | `debit`, `credit` |
| reconciled | boolean | Ja conciliado |
| metadata_json | json nullable | Dados adicionais |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `payment`
- belongsTo `professional`

#### Tabela: `campaigns`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| automation_id | ulid nullable | FK para `automations.id` |
| created_by_user_id | ulid nullable | Usuario criador |
| name | string(120) | Nome interno |
| channel | enum | `whatsapp`, `sms`, `email`, `internal` |
| objective | enum | `reactivation`, `reminder`, `upsell`, `birthday`, `no_show_recovery` |
| audience_definition_json | json | Segmento alvo |
| template_key | string(80) | Template ou estrategia de mensagem |
| status | enum | `draft`, `scheduled`, `running`, `paused`, `completed`, `canceled` |
| scheduled_at | dateTime nullable | Agendamento |
| started_at | dateTime nullable | Inicio |
| finished_at | dateTime nullable | Fim |
| metrics_json | json nullable | Totais de envio, leitura, conversao |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `automation`
- hasMany `messages`

#### Tabela: `messages`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| client_id | ulid nullable | FK para `clients.id` |
| campaign_id | ulid nullable | FK para `campaigns.id` |
| appointment_id | ulid nullable | FK para `appointments.id` |
| automation_id | ulid nullable | FK para `automations.id` |
| direction | enum | `outbound`, `inbound` |
| channel | enum | `whatsapp`, `sms`, `email`, `internal` |
| provider | string(50) nullable | Provedor de transporte |
| external_message_id | string(191) nullable | ID externo |
| thread_key | string(120) | Chave da conversa |
| type | enum | `text`, `template`, `interactive`, `media`, `system` |
| status | enum | `queued`, `sent`, `delivered`, `read`, `failed`, `received`, `processed` |
| body_text | text nullable | Corpo normalizado |
| payload_json | json | Payload bruto/normalizado |
| sent_at | dateTime nullable | Envio |
| delivered_at | dateTime nullable | Entrega |
| read_at | dateTime nullable | Leitura |
| failed_at | dateTime nullable | Falha |
| failure_reason | string(255) nullable | Motivo da falha |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- belongsTo `client`
- belongsTo `campaign`
- belongsTo `appointment`
- belongsTo `automation`

#### Tabela: `automations`

| Campo | Tipo | Observacoes |
| --- | --- | --- |
| id | ulid | PK |
| created_by_user_id | ulid nullable | Usuario criador |
| name | string(120) | Nome interno |
| description | text nullable | Objetivo da automacao |
| trigger_type | enum | `domain_event`, `schedule`, `behavior` |
| trigger_event | string(120) nullable | Nome do evento quando aplicavel |
| status | enum | `draft`, `active`, `paused`, `archived` |
| channel | enum | `whatsapp`, `sms`, `email`, `internal`, `none` |
| conditions_json | json | Condicoes e filtros |
| action_type | enum | `send_message`, `create_task`, `tag_client`, `create_campaign`, `notify_team`, `score_client` |
| action_payload_json | json | Parametros da acao |
| delay_minutes | unsignedInteger | Delay apos o trigger |
| cooldown_hours | unsignedInteger | Anti-spam |
| stop_on_response | boolean | Interrompe se cliente responder |
| priority | unsignedTinyInteger | Ordenacao de execucao |
| last_executed_at | dateTime nullable | Ultima execucao |
| created_at | timestamp | Auditoria |
| updated_at | timestamp | Auditoria |

Relacionamentos:

- hasMany `campaigns`
- hasMany `messages`

### 3.5 Tabelas complementares recomendadas

Estas tabelas nao estavam na lista minima solicitada, mas sao recomendadas para a implementacao real:

- `appointment_services`: varios servicos por agendamento.
- `cash_register_sessions`: abertura, fechamento e conciliacao de caixa.
- `automation_runs`: rastreamento idempotente das execucoes.
- `campaign_recipients`: auditoria de publico e status por cliente.
- `outbox_messages`: padrao outbox para publicacao confiavel de eventos.
- `inbox_webhooks`: idempotencia e auditoria de webhooks externos.
- `message_templates`: catalogo versionado de templates por canal.
- `audit_logs`: trilha de auditoria de operacoes sensiveis.

## 4. ARQUITETURA DO SISTEMA

### 4.1 Stack principal

- Laravel 12
- PHP 8.3
- MariaDB
- Redis para filas, cache, locks e rate limiting
- Docker para desenvolvimento local
- Nginx para HTTP interno
- Horizon para operacao de filas em ambientes nao locais

### 4.2 Estilo arquitetural

O sistema sera um modular monolith com fronteiras explicitas entre dominios. Nao ha justificativa inicial para microservicos. O foco e:

- baixo acoplamento entre modulos;
- transacoes locais fortes;
- eventos internos claros;
- extraibilidade futura de componentes de integracao, mensageria e analytics.

### 4.3 API-first

Toda regra de negocio deve ser exposta primeiro por API. O painel administrativo, eventual app mobile e integrações externas devem consumir contratos HTTP claros.

Diretrizes:

- Endpoints versionados em `/api/v1`.
- Controllers finos.
- Requests para validacao.
- Resources para serializacao.
- Acoes de negocio em `Actions`.

Rotas previstas:

- `/api/v1/auth/*`
- `/api/v1/clients`
- `/api/v1/professionals`
- `/api/v1/services`
- `/api/v1/appointments`
- `/api/v1/orders`
- `/api/v1/payments`
- `/api/v1/reports/*`
- `/api/v1/campaigns`
- `/api/v1/automations`
- `/api/v1/messages`
- `/webhooks/whatsapp/{provider}`
- `/webhooks/payments/{provider}`

### 4.4 Multi-tenant

#### Estrategia

Modelo adotado: `landlord database + database por tenant`.

#### Resolucao de tenant

Ordem recomendada:

1. Dominio customizado do tenant.
2. Subdominio administrativo.
3. Cabecalho interno assinado para jobs, workers e processos internos.
4. Contexto explicito em comandos batch.

#### Fluxo de resolucao por request

1. Middleware consulta `tenant_domains` no landlord.
2. Recupera configuracao de conexao do tenant.
3. Reconfigura a conexao `tenant` dinamicamente.
4. Registra `tenant_id`, `tenant_slug` e `request_id` no contexto de log.
5. Prefixa cache e locks com `tenant:{tenant_id}:`.

#### Fluxo de resolucao por job

1. Todo job tenant-aware serializa `tenant_id`.
2. Ao iniciar, o job reabre o contexto no landlord.
3. Reconfigura a conexao do tenant.
4. Executa a logica dentro desse contexto.

#### Regras nao negociaveis

- Nenhum dado operacional vive no landlord.
- Nenhum job executa sem contexto de tenant quando a operacao e tenant-scoped.
- Nenhuma query operacional pode depender apenas de filtros de aplicacao sem conexao dedicada.

### 4.5 Uso de Redis

Redis e mandatorio em homologacao e producao. Em desenvolvimento local, drivers simplificados podem ser usados provisoriamente, mas a arquitetura alvo assume Redis.

Usos previstos:

- `queue`: execucao de jobs.
- `cache`: dashboards, disponibilidade calculada, configuracoes temporarias.
- `locks`: prevencao de double booking e concorrencia de pagamento.
- `rate limiting`: protecao de API e limites de envio por canal.
- `horizon`: observabilidade operacional de filas.

Filas recomendadas:

- `critical`
- `default`
- `notifications`
- `integrations`
- `automation`
- `reports`

### 4.6 Workers e filas

Padrao operacional:

- Jobs curtos e idempotentes.
- Separacao por prioridade.
- Retry controlado por tipo de erro.
- Dead-letter logica via tabela de falhas e alertas.

Exemplos:

- `SendAppointmentReminderJob` em `notifications`
- `SyncWhatsappDeliveryStatusJob` em `integrations`
- `RunAutomationRuleJob` em `automation`
- `GenerateDailyMetricsJob` em `reports`

### 4.7 Eventos e listeners

Eventos internos devem representar fatos de dominio, nao comandos imperativos.

Exemplos:

- `AppointmentCreated`
- `AppointmentConfirmed`
- `AppointmentCanceled`
- `OrderClosed`
- `PaymentCaptured`
- `ClientMarkedAtRisk`
- `ClientMarkedInactive`
- `WhatsappMessageReceived`

Listeners se dividem em tres grupos:

- projeções e atualizacao de estado derivado;
- automacoes;
- integracoes externas.

### 4.8 Estrutura de pastas proposta

```text
app/
  Domain/
    Tenant/
    Client/
    Professional/
    Service/
    Appointment/
    Order/
    Finance/
    Subscription/
    Retention/
    Messaging/
    Automation/
  Application/
    Actions/
    DTOs/
    Queries/
    Services/
  Infrastructure/
    Tenancy/
    Persistence/
    Cache/
    Queue/
    Whatsapp/
    Payments/
    Observability/
  Http/
    Controllers/
      Api/
      Webhooks/
    Middleware/
    Requests/
    Resources/
  Support/
    Enums/
    Exceptions/
    ValueObjects/
bootstrap/
config/
database/
  migrations/
    landlord/
    tenant/
docs/
routes/
  api.php
  webhooks.php
tests/
  Unit/
  Feature/
  Integration/
  E2E/
```

### 4.9 Padroes de codigo

- Regra de negocio principal vive em `Actions` e `Domain`.
- `Services` orquestram cenarios multi-agregado ou integracoes externas.
- `Jobs` executam efeitos assincronos e devem ser idempotentes.
- `DTOs` formam contratos internos claros entre camadas.
- `Policies` governam autorizacao.
- `Form Requests` fazem apenas validacao e normalizacao leve.
- `Controllers` nao abrem transacao nem manipulam regras centrais.

## 5. SISTEMA DE AUTOMACAO (CRITICO)

### 5.1 Engine de eventos

#### Principio

A automacao e orientada a eventos de dominio e a regras temporais. Toda automacao nasce de um fato ou de uma condicao mensuravel.

#### Eventos de dominio prioritarios

- `AppointmentCreated`
- `AppointmentConfirmed`
- `AppointmentCanceled`
- `AppointmentMarkedNoShow`
- `OrderClosed`
- `PaymentCaptured`
- `SubscriptionRenewed`
- `ClientRetentionStatusChanged`
- `WhatsappMessageReceived`

#### Envelope de evento

Todo evento persistido ou enfileirado deve conter:

- `event_id`
- `event_name`
- `tenant_id`
- `aggregate_type`
- `aggregate_id`
- `occurred_at`
- `payload`
- `version`

#### Garantia de entrega

Padrao recomendado:

- transacao do dominio grava estado principal;
- mesma transacao grava um registro em `outbox_messages`;
- job despachante publica para listeners assincronos;
- processamento idempotente por `event_id`.

### 5.2 Regras de automacao

#### Baseado em tempo

Exemplos:

- lembrar agendamento 24 horas antes;
- lembrar novamente 2 horas antes;
- reativar cliente 35 dias apos ultima visita se a frequencia esperada for mensal;
- cobrar assinatura no dia da renovacao.

#### Baseado em comportamento

Exemplos:

- cliente entrou em `at_risk`;
- cliente ficou `inactive`;
- cliente teve `no_show`;
- cliente respondeu positivamente a campanha;
- cliente gastou acima de determinado ticket e ainda nao tem assinatura.

#### DSL de condicoes

As regras devem suportar filtros versionados em JSON, por exemplo:

```json
{
  "all": [
    {"field": "client.retention_status", "operator": "eq", "value": "inactive"},
    {"field": "client.whatsapp_opt_in", "operator": "eq", "value": true},
    {"field": "days_since_last_visit", "operator": "gte", "value": 30}
  ]
}
```

#### Mecanismos de seguranca

- cooldown por cliente e por automacao;
- janela de silencio configuravel;
- stop on response;
- exclusao automatica de clientes sem opt-in;
- bloqueio de campanhas em tenant suspenso.

### 5.3 Execucao

Fluxo padrao:

1. Evento ocorre.
2. Listener de elegibilidade identifica automacoes candidatas.
3. Regra validada gera `automation_run` pendente.
4. Job em fila executa a acao.
5. Resultado atualiza auditoria, mensagem, campanha e metricas.

Filas recomendadas:

- avaliacao de regra: `automation`
- envio de mensagem: `notifications`
- callbacks de status: `integrations`

Falhas:

- erro temporario: retry com backoff exponencial;
- erro permanente: marca como `failed`, grava motivo e alerta operacao;
- webhook duplicado: ignorar por idempotencia.

## 6. WHATSAPP INTEGRATION

### 6.1 Arquitetura de integracao

O sistema deve tratar WhatsApp como um modulo de infraestrutura com adaptadores por provedor.

Camadas:

- `WhatsappProvider` interface
- `ZApiProvider` adapter
- `Dialog360Provider` adapter
- `MetaCloudProvider` futuro
- normalizador de payload inbound/outbound
- controller de webhooks
- servico de conversa e handoff

### 6.2 Estrategia de provedores

#### Z-API

- Rapido para bootstrap operacional.
- Menor barreira inicial.
- Maior risco de acoplamento a payload proprietario.

#### 360Dialog

- Melhor caminho para operacao escalavel com API oficial Meta.
- Melhor para templates e compliance.
- Recomendado para tenants com volume consistente e necessidade de previsibilidade.

Diretriz:

- O dominio nunca fala direto com payload do provedor.
- Todo provedor deve ser adaptado para um contrato interno unico.

### 6.3 Webhooks

Eventos tratados:

- mensagem recebida;
- mensagem entregue;
- mensagem lida;
- erro de envio;
- atualizacao de template ou sessao, se o provedor oferecer.

Fluxo de webhook:

1. Validar assinatura e origem.
2. Persistir payload bruto em `inbox_webhooks`.
3. Normalizar o evento.
4. Identificar tenant pelo numero/conta do canal.
5. Enfileirar processamento.
6. Atualizar `messages` e disparar evento interno.

### 6.4 Envio e recebimento de mensagens

#### Envio

- Template para mensagens ativas de reengajamento e lembrete.
- Texto livre para atendimento em janela permitida.
- Mensagens interativas quando o provedor suportar.

#### Recebimento

- Identificacao do cliente por telefone.
- Criacao de cliente minimo se ainda nao existir.
- Classificacao de intencao.
- Encaminhamento para fluxo deterministico ou humano.

### 6.5 Agente automatico de atendimento

O agente deve operar em modo assistido por regras, com IA opcional para classificacao e redacao. Acoes que alteram estado precisam de confirmacao explicita.

Ferramentas permitidas ao agente:

- buscar cliente por telefone;
- listar servicos;
- consultar disponibilidade;
- reservar slot temporario;
- criar agendamento;
- remarcar;
- cancelar;
- responder FAQ operacional.

Regras de handoff:

- baixa confianca de intencao;
- reclamacao, conflito ou excecao de politica;
- tentativa de desconto fora da alçada;
- erro de sincronizacao de agenda;
- solicitacao de multiplos servicos complexos.

### 6.6 Fluxos principais

#### Agendamento via WhatsApp

1. Cliente inicia conversa.
2. Sistema identifica ou cria cliente.
3. Agente identifica intencao `agendar`.
4. Pergunta servico e preferencia de horario/profissional.
5. Consulta disponibilidade real.
6. Oferece opcoes.
7. Cliente escolhe.
8. Sistema cria agendamento com status `confirmed` ou `pending`, conforme regra.
9. Envia resumo e politicas.

#### Lembrete automatico

1. Job temporal seleciona agendamentos futuros.
2. Envia template de confirmacao.
3. Cliente responde `confirmar`, `cancelar` ou `remarcar`.
4. Inbound atualiza o agendamento e publica evento.

#### Reativacao de cliente

1. Job diario recalcula status de retencao.
2. Cliente entra em `inactive`.
3. Automacao escolhe template/oferta.
4. Mensagem e enviada por WhatsApp.
5. Se houver resposta positiva, agente oferece horarios.
6. Se o cliente agenda, a automacao e marcada como convertida.

## 7. FLUXOS DE NEGOCIO (PASSO A PASSO)

### 7.1 Criacao de agendamento

1. Origem: painel, link publico ou WhatsApp.
2. Sistema resolve tenant.
3. Valida cliente e servico.
4. Verifica disponibilidade do profissional.
5. Aplica lock curto em Redis para evitar dupla reserva.
6. Persiste `appointments`.
7. Publica `AppointmentCreated`.
8. Listener agenda lembretes e atualiza metricas.

### 7.2 Atendimento do cliente

1. Cliente chega e ocorre check-in.
2. Agendamento vai para `checked_in`.
3. Comanda e aberta automaticamente ou manualmente.
4. Itens adicionais podem ser incluidos.
5. Ao iniciar o servico, status vai para `in_service`.
6. Ao concluir, agendamento vai para `completed`.
7. Publica `AppointmentCompleted`.

### 7.3 Fechamento de comanda

1. Operador revisa itens, descontos e totais.
2. Sistema calcula subtotal, descontos, total e comissoes.
3. Registra `payments`.
4. Gera `transactions` de receita, comissao e eventuais ajustes.
5. Fecha `orders`.
6. Publica `OrderClosed` e `PaymentCaptured` quando aplicavel.
7. Atualiza historico do cliente e metricas do profissional.

### 7.4 Disparo de automacoes

1. Evento de dominio e persistido no outbox.
2. Despachante publica para listeners.
3. Listener identifica automacoes compatveis.
4. Gera execucao pendente.
5. Job executa envio, task ou marcacao de cliente.
6. Resultado retorna para auditoria e metricas.

### 7.5 Reativacao de cliente

1. Job diario recalcula frequencia esperada.
2. Se dias sem visita excedem o limiar, cliente muda para `at_risk` ou `inactive`.
3. Evento `ClientRetentionStatusChanged` e publicado.
4. Automacao de reativacao cria campanha ou envia mensagem direta.
5. Cliente responde.
6. Agente oferece retorno.
7. Agendamento criado fecha o ciclo de reativacao.

### 7.6 Uso de assinatura

1. Barbeira/barbearia vende assinatura ao cliente.
2. `subscriptions` e criada com regras de renovacao e creditos.
3. Cobranca inicial gera `payments` e `transactions`.
4. Ao consumir servico elegivel, o sistema pode:
   - reduzir `remaining_sessions`; ou
   - gerar `order_item` do tipo `subscription_credit`.
5. Renovacao cria novo pagamento e reabastece creditos.
6. Inadimplencia pode pausar consumo e automacoes de renovacao.

## 8. SEGURANCA E MULTI-TENANCY

### 8.1 Isolamento de dados

- Um banco por tenant.
- Credenciais de tenant armazenadas criptografadas no landlord.
- Sem joins operacionais cross-tenant.
- Cache, locks e jobs com contexto explicito de tenant.

### 8.2 Autenticacao

Padrao atual implementado:

- API-first com bearer token proprio salvo no landlord em `user_access_tokens`.
- Cada token pertence simultaneamente a `user_id` e `tenant_id`.
- O formato entregue ao cliente e `token_id|secret`, com `secret` salvo apenas como `sha256`.
- O middleware `tenant.resolve` roda antes da autenticacao e garante contexto de tenant antes de validar o token.
- O middleware de autenticacao rejeita token ausente, expirado, malformado, de outro tenant ou associado a membership inativa.
- O tempo de vida do token e controlado por `AUTH_ACCESS_TOKEN_TTL_MINUTES`.
- Reset de senha e verificacao de email seguem no landlord.
- 2FA para perfis `owner` e `manager` continua previsto para fase posterior.

### 8.3 Autorizacao

Controle por role e policy.

Perfis minimos:

- `owner`
- `manager`
- `receptionist`
- `professional`
- `finance`
- `automation_admin`

Regras:

- Role define capacidade base.
- Middleware `tenant.ability` valida capacidade no contexto ja autenticado.
- Overrides finos vivem em `tenant_memberships.permissions_json`.
- O papel `owner` tem `*`.
- `manager` tem leitura e escrita operacional, financeira e de gestao de usuarios.
- `receptionist` tem escrita operacional e leitura financeira.
- `professional` tem leitura operacional basica, sem permissao financeira por padrao.
- Convites, alteracoes de papel/permissao e reset de senha exigem `tenant.users.write`.
- Leitura da equipe e da trilha de auditoria exige `tenant.users.read`.

### 8.4 Controle por tenant

- Middleware bloqueia acesso sem tenant valido.
- Jobs tenant-aware obrigatorios para qualquer efeito operacional.
- Comandos batch exigem parametro explicito ou iteracao controlada por landlord.
- Logs sempre com `tenant_id`, `request_id` e `user_id`.

### 8.5 Seguranca adicional

- Idempotencia para webhooks e pagamentos.
- Segredos externos com `encrypted` cast ou servico de secrets.
- Rate limit por IP, token e tenant.
- Sanitizacao de logs para PII e segredos.
- Auditoria de operacoes sensiveis: cancelamento, estorno, alteracao de permissao, convite, reset de senha e exclusao.
- Politicas LGPD: consentimento, exportacao e remocao sob demanda.

## 9. ESTRATEGIA DE TESTES

### 9.1 Testes unitarios

Objetivo:

- validar regras puras de dominio;
- calcular frequencia, score, comissao e totais;
- testar DTOs, enums e value objects.

Cobertura prioritaria:

- calculadora de retencao;
- calculadora de comissao;
- regras de elegibilidade de automacao;
- interpretador de DSL de condicoes.

### 9.2 Testes de integracao

Objetivo:

- validar persistencia, troca de conexao tenant, eventos, filas e integracoes.

Cobertura prioritaria:

- resolucao de tenant por dominio;
- migrations landlord e tenant;
- outbox e idempotencia;
- adaptadores WhatsApp;
- fluxo de pagamento e ledger.

### 9.3 Testes de fluxo (E2E)

Objetivo:

- validar o caminho completo por HTTP, fila e efeitos observaveis.

Fluxos obrigatorios:

- criar agendamento;
- confirmar agendamento por WhatsApp;
- fechar comanda e registrar pagamento;
- renovar assinatura;
- disparar automacao de reativacao;
- handoff do agente para humano.

### 9.4 Cobertura especifica por area critica

#### Automacoes

- regra baseada em evento;
- regra baseada em tempo;
- cooldown;
- stop on response;
- retry e falha permanente.

#### WhatsApp

- normalizacao de webhook;
- envio outbound;
- atualizacao de status entregue/lido;
- classificacao de intencao e confirmacao de agendamento.

#### Pagamentos

- pagamento aprovado;
- falha de pagamento;
- estorno;
- assinatura inadimplente;
- conciliacao para caixa.

#### Multi-tenant

- isolamento de dados entre tenants;
- job abrindo conexao correta;
- cache prefixado;
- logs com tenant context;
- tentativa de acesso cross-tenant bloqueada.

### 9.5 Estrategia pratica por ambiente

- Suite local rapida: pode usar SQLite para testes puros e basicos.
- Suite de integracao: deve rodar contra MariaDB dedicado de teste.
- Suite de fila/integracao externa: deve rodar com Redis e fakes controlados.
- CI principal deve executar ao menos:
  - unitarios;
  - integracao multi-tenant;
  - fluxo de agendamento/comanda/pagamento;
  - fluxo de automacao.

## 10. ROADMAP DE DESENVOLVIMENTO

### Fase 1: MVP

Funcionalidades:

- tenants e autenticacao
- clientes
- profissionais
- servicos
- agenda multi-profissional
- comandas
- caixa basico
- API v1 base

Entregaveis:

- landlord e tenant databases provisionados
- CRUDs principais
- fluxo de agendamento e fechamento de comanda
- relatorios basicos de faturamento diario

Criterios de validacao:

- um tenant consegue operar agenda e comanda sem vazamento de dados
- fechamento de comanda gera pagamento e transacao corretamente
- agendamento nao permite conflito de horario

### Fase 2: Monetizacao

Funcionalidades:

- pagamentos estruturados
- assinaturas de clientes
- comissao de profissionais
- relatorios financeiros
- conciliacao basica

Entregaveis:

- modulo financeiro consistente
- suporte a multipla forma de pagamento
- assinaturas com renovacao manual e automatica

Criterios de validacao:

- assinatura pode ser vendida, renovada e consumida
- comissao e calculada por item de comanda
- transacoes batem com pagamentos e saldo de caixa

### Fase 3: Inteligencia

Funcionalidades:

- calculo de frequencia
- segmentacao de retencao
- engine de automacao
- campanhas automaticas
- agente de WhatsApp v1

Entregaveis:

- clientes classificados por status de retencao
- automacoes disparadas por evento e por tempo
- reativacao automatica por WhatsApp

Criterios de validacao:

- cliente inativo recebe acao automatica correta
- resposta positiva no WhatsApp pode virar agendamento
- metricas de campanha registram entrega e conversao

### Fase 4: Escala

Funcionalidades:

- Redis e Horizon obrigatorios
- observabilidade operacional
- multi-unidade e franquias
- rotinas de analytics
- extensao para outros nichos

Entregaveis:

- operacao com filas priorizadas
- paineis de saude das integracoes
- suporte a tenants de maior porte

Criterios de validacao:

- filas segregadas por prioridade sem degradar atendimento
- tenants podem ser restaurados individualmente
- integracoes externas possuem auditoria e retry controlado

## 11. PADROES E CONVENCOES

### 11.1 Naming

- Modelos: singular em PascalCase. Ex.: `Appointment`, `Client`.
- Tabelas: plural em snake_case. Ex.: `appointments`, `order_items`.
- Events: passado. Ex.: `OrderClosed`, `ClientMarkedInactive`.
- Jobs: verbo no imperativo. Ex.: `SendAppointmentReminderJob`.
- Actions: verbo + contexto + `Action`. Ex.: `CreateAppointmentAction`.
- DTOs: sufixo `Data`. Ex.: `CreateAppointmentData`.
- Enums: nome de dominio claro. Ex.: `AppointmentStatus`.

### 11.2 Organizacao de codigo

- Nenhum controller deve conter regra de negocio central.
- Nenhum model deve concentrar orquestracao complexa.
- Transacao de banco deve abrir dentro da `Action`.
- Integracoes externas nao devem ser chamadas direto de controller.

### 11.3 Uso de Services

Use `Services` quando:

- houver integracao externa;
- for preciso orquestrar multiplas actions;
- existir logica de infraestrutura;
- houver estrategia selecionavel por provider.

Nao use `Service` como deposito generico de qualquer logica.

### 11.4 Uso de Actions

Cada use case relevante deve ter uma action propria. Exemplo:

- `CreateAppointmentAction`
- `CloseOrderAction`
- `CapturePaymentAction`
- `RunRetentionClassificationAction`

Regras:

- input tipado;
- retorna DTO ou agregado claro;
- controla transacao;
- publica eventos apos commit;
- nao renderiza resposta HTTP.

### 11.5 Uso de DTOs

DTOs sao obrigatorios nos seguintes cenarios:

- entrada de actions;
- saida de adapters externos;
- payloads internos de eventos;
- contratos entre dominio e infraestrutura.

Regras:

- imutaveis sempre que possivel;
- sem dependencia de Request HTTP;
- sem arrays anonimos como contrato de negocio.

### 11.6 Convencoes operacionais

- Dinheiro sempre em centavos.
- Datas sempre em UTC no banco.
- `CarbonImmutable` por padrao em dominio.
- Todo job externo deve ser idempotente.
- Todo webhook deve ser persistido antes de processar.
- Toda automacao deve ser auditavel.

## 12. README.md (RESUMO)

O `README.md` do repositorio deve ser curto, operacional e apontar para este manual como fonte oficial.

Conteudo recomendado:

```md
# Sistema Barbearia

SaaS multi-tenant para gestao de barbearias com foco em recorrencia, automacao de atendimento, operacao financeira e retencao de clientes.

## Fonte oficial

- Manual completo: `docs/AI-Operating-Manual.md`

## Stack

- Laravel 12
- PHP 8.3
- MariaDB
- Redis
- Docker
- Nginx
- WhatsApp via adapters de provider

## Como rodar localmente

1. `cp .env.example .env`
2. Ajuste `DOCKER_SHARED_NETWORK`, `DB_*`, `MAIL_*` e `VIRTUAL_HOST`
3. `docker compose run --rm setup`
4. `docker compose up -d --build`
5. `docker compose run --rm app php artisan migrate`
6. `docker compose run --rm test`

## Estrutura basica

- `docs/AI-Operating-Manual.md`: documento de referencia
- `app/`: codigo da aplicacao
- `config/`: configuracoes
- `database/`: migrations e seeders
- `tests/`: testes
```

## Decisoes finais que guiam o projeto

- O produto deve priorizar retencao, recorrencia e reativacao automatica.
- WhatsApp e canal operacional central.
- Multi-tenancy por banco e obrigatorio para isolamento.
- Event-driven internamente e requisito de arquitetura, nao detalhe opcional.
- Redis e operacao de filas sao obrigatorios antes de considerar escala.
- Todo modulo novo deve provar como respeita tenant context, idempotencia e auditabilidade.
