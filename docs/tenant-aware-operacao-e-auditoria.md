# Tenant-Aware: Operacao, Enforcement e Trilhas de Auditoria

Status: documento vivo de contrato interno  
Escopo: entrypoints tenant-aware, enforcement operacional e escolha correta da trilha de auditoria/observabilidade

Este guia complementa o [AI Operating Manual](./AI-Operating-Manual.md). Ele nao redefine a arquitetura; ele documenta como os trilhos atuais devem ser usados no codigo real do projeto.

## 1. Contrato geral

Toda entrada tenant-aware precisa responder tres perguntas antes de entrar em producao:

1. Onde o tenant e resolvido?
2. Onde o gate operacional e aplicado?
3. Em qual trilha a tentativa, bloqueio ou mudanca precisa ser registrada?

Se uma nova feature nao responder isso explicitamente, ela provavelmente esta fora do trilho correto.

## 2. Qual trilha usar

### `audit_logs`

Use `audit_logs` para trilha administrativa landlord e mudancas controladas de estado.

Use quando houver:

- acao administrativa iniciada por operador landlord
- alteracao controlada de tenant ou de recurso landlord-managed
- necessidade de `actor_user_id`, `before_json`, `after_json` e `metadata_json`

Exemplos corretos:

- criacao de tenant
- mudanca de `status`
- transicao de `onboarding_stage`
- atualizacao de dados basicos
- adicao de dominio
- troca de dominio principal
- sync de schema
- ensure default automations

Nao use `audit_logs` para:

- bloqueio operacional de request web/api
- comando ignorado porque o tenant esta suspenso
- rejeicao na borda WhatsApp

### `tenant_operational_block_audits`

Use `tenant_operational_block_audits` para bloqueios operacionais transversais causados pelo enforcement de status do tenant fora da borda WhatsApp.

Hoje os canais previstos sao:

- `web`
- `api`
- `command`
- `credential_issue`

Use quando houver:

- rota tenant web bloqueada por `status`
- rota tenant API bloqueada por `status`
- comando tenant-aware ignorado por `status`
- tentativa de emissao de token/credencial bloqueada por `status`

Nao use `tenant_operational_block_audits` para:

- alteracoes administrativas bem-sucedidas
- rejeicao de webhook/outbound na borda WhatsApp
- observabilidade de sucesso normal da operacao

### `boundary_rejection_audits`

Use `boundary_rejection_audits` para rejeicoes ou ignorados na borda WhatsApp, antes de efeitos colaterais do pipeline tenant.

Use quando houver:

- webhook rejeitado por assinatura invalida
- provider invalido
- autenticacao/autorizacao falha na borda
- tenant nao resolvido na borda
- outbound/webhook ignorado por politica operacional ou de seguranca

Essa trilha existe para responder "o que foi barrado na entrada/saida da borda WhatsApp?" com payload e headers sanitizados.

Nao use `boundary_rejection_audits` para:

- alteracoes administrativas do landlord
- bloqueio generico de rota tenant web/api
- comandos internos do runtime

## 3. Enforcement HTTP tenant-aware

Fluxo oficial atual:

1. a rota tenant-aware entra em grupo com `tenant.resolve`
2. `ResolveTenant` resolve o tenant
3. `TenantContext` recebe o tenant atual
4. `EnsureTenantOperationalAccessAction` valida se o runtime esta liberado
5. se o bloqueio for web/api fora da borda, grava `tenant_operational_block_audits`
6. so depois disso o banco do tenant e conectado
7. excecoes/renderizacao ficam em `bootstrap/app.php`

Implicacoes:

- controller tenant nao deve resolver tenant manualmente
- controller tenant nao deve decidir sozinho se `status` bloqueia ou nao
- nao conecte banco tenant antes do gate operacional

Faca assim:

```php
Route::middleware(['tenant.resolve', 'tenant.auth'])->group(function () {
    Route::get('/api/v1/example', ExampleController::class);
});
```

Nao faca assim:

```php
Route::get('/api/v1/example', function (Request $request) {
    $tenant = Tenant::query()->where('slug', $request->header('X-Tenant-Slug'))->first();

    // bypass de tenant.resolve e do gate operacional
});
```

## 4. Enforcement runtime/comandos tenant-aware

Fluxo oficial atual:

1. selecione tenants com `resolveTenantCommandTargets()`
2. para cada tenant, chame `GuardTenantOperationalCommandAction`
3. so conecte o banco tenant se o guard liberar
4. se o tenant estiver suspenso, o comando deve ser ignorado com auditoria `command`

Use esse padrao para comandos que:

- processam outbox
- executam automacoes
- executam agent/runtime
- processam workload tenant-aware recorrente

Excecoes tecnicas intencionais podem existir, mas precisam ser explicitas. Exemplo atual: comandos de manutencao/provisionamento podem operar fora desse guard quando isso e parte do proprio ciclo de reativacao ou housekeeping.

Faca assim:

```php
foreach ($tenants as $tenant) {
    if (! $guardTenantOperationalCommand->execute($tenant, 'tenancy:example')) {
        continue;
    }

    $databaseManager->connect($tenant);
}
```

Nao faca assim:

```php
foreach ($tenants as $tenant) {
    $databaseManager->connect($tenant);

    // processa runtime sem reaproveitar o guard central
}
```

## 5. Emissao de credenciais tenant-aware

A emissao de token tenant nao e um detalhe local do controller. Ela e um entrypoint operacional do tenant.

Contrato atual:

- toda emissao passa por `IssueTenantAccessTokenAction`
- a action chama `EnsureTenantOperationalAccessAction`
- tenant suspenso nao recebe nova credencial
- tentativa bloqueada gera `tenant_operational_block_audits` com canal `credential_issue`

Faca assim:

```php
$issued = app(IssueTenantAccessTokenAction::class)->execute($user, $tenant, [
    'name' => 'api',
]);
```

Nao faca assim:

```php
UserAccessToken::query()->create([
    'user_id' => $user->id,
    'tenant_id' => $tenant->id,
]);
```

## 6. Politica atual por canal

| Canal | Entry point oficial | Quando bloqueia | Trilha principal |
| --- | --- | --- | --- |
| Web tenant | `tenant.resolve` -> `ResolveTenant` | `status` bloqueia runtime | `tenant_operational_block_audits` |
| API tenant | `tenant.resolve` -> `ResolveTenant` | `status` bloqueia runtime | `tenant_operational_block_audits` |
| Runtime/comandos | `GuardTenantOperationalCommandAction` | `status` bloqueia runtime | `tenant_operational_block_audits` |
| Emissao de token | `IssueTenantAccessTokenAction` | `status` bloqueia emissao | `tenant_operational_block_audits` |
| WhatsApp outbound | boundary + tenant gate | seguranca/politica/boundary | `boundary_rejection_audits` |
| WhatsApp webhook | boundary + tenant gate | ignorado/rejeitado sem processamento | `boundary_rejection_audits` |
| Acao administrativa landlord | actions do landlord | validacao/transicao controlada | `audit_logs` |

## 7. Checklist para novos entrypoints tenant-aware

Antes de mergear um novo entrypoint tenant-aware, confirme:

- a rota ou comando reutiliza o trilho oficial de resolucao do tenant
- o gate `EnsureTenantOperationalAccessAction` e aplicado direta ou indiretamente
- o banco tenant nao e conectado antes do gate
- a trilha correta foi escolhida entre `audit_logs`, `tenant_operational_block_audits` e `boundary_rejection_audits`
- a feature nao grava `UserAccessToken` diretamente
- a feature nao cria `if ($tenant->status === ...)` espalhado em controller
- a excecao, se houver, esta documentada no proprio ponto de bypass
- existe cobertura de teste estrutural ou feature para o novo trilho

## 8. Regra rapida de decisao

Pergunta: "isso e mudanca administrativa com actor/before/after?"

- sim: `audit_logs`
- nao: continue

Pergunta: "isso aconteceu na borda WhatsApp, antes do pipeline tenant?"

- sim: `boundary_rejection_audits`
- nao: continue

Pergunta: "isso foi bloqueio/ignorado por enforcement operacional do tenant?"

- sim: `tenant_operational_block_audits`

## 9. Onde olhar no codigo

- enforcement HTTP tenant-aware: `app/Http/Middleware/ResolveTenant.php`
- gate central: `app/Application/Actions/Tenancy/EnsureTenantOperationalAccessAction.php`
- runtime tenant-aware: `app/Application/Actions/Tenancy/GuardTenantOperationalCommandAction.php`
- emissao de credencial: `app/Application/Actions/Auth/IssueTenantAccessTokenAction.php`
- auditoria administrativa: `app/Application/Actions/Auth/RecordAuditLogAction.php`
- auditoria de bloqueio operacional: `app/Application/Actions/Observability/RecordTenantOperationalBlockAuditAction.php`
- auditoria de boundary: `app/Application/Actions/Observability/RecordBoundaryRejectionAuditAction.php`
- resumo landlord: `app/Application/Actions/Tenancy/BuildLandlordTenantSuspensionObservabilityAction.php`

## 10. Sinais de que o codigo esta fora do trilho

- controller tenant consulta `Tenant::query()` manualmente
- comando conecta banco tenant antes do guard operacional
- token tenant e criado sem `IssueTenantAccessTokenAction`
- bloqueio operacional relevante so aparece em log e nao em trilha persistida
- acao administrativa relevante muda estado sem `audit_logs`
- webhook/outbound rejeitado gera observabilidade generica em vez de `boundary_rejection_audits`
