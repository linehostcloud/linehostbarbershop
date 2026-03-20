# Métricas da Frente `/painel/gestao/whatsapp`

Esta frente traduz a operação de WhatsApp para linguagem de produto. Os cards do topo não são um BI completo; eles resumem o que o gestor consegue acompanhar com segurança hoje.

## Contagens diretas

### Lembretes enfileirados
- Conta mensagens de lembrete criadas na fila dentro do período selecionado.
- Fonte de verdade: `messages.created_at` do fluxo `appointment_reminder`.

### Lembretes enviados
- Conta lembretes que saíram do estado enfileirado e tiveram envio aceito pelo provider.
- Fonte de verdade: `messages.sent_at` do fluxo `appointment_reminder`.

### Confirmações manuais enviadas
- Conta solicitações manuais de confirmação que saíram da fila e tiveram envio aceito pelo provider.
- Fonte de verdade: `messages.sent_at` das mensagens marcadas como confirmação manual.

### Falhas de envio
- Conta falhas registradas nas mensagens mostradas nesta frente.
- Fonte de verdade: `messages.failed_at`.

### Reativações acionadas
- Conta mensagens de reativação colocadas na fila no período.
- Fonte de verdade: `messages.created_at` do fluxo `inactive_client_reactivation`.

### Clientes ignorados no período
- Conta ações de “Ignorar por 7 dias” feitas pelo gestor no período selecionado.
- Fonte de verdade: `audit_logs.created_at` com ação `whatsapp_product.client_reactivation.snoozed`.

## Leituras inferidas

### Lembretes com confirmação registrada
- Leitura inferida.
- Conta agendamentos que tiveram lembrete enviado no período e hoje estão com `confirmation_status = confirmed`.
- Não representa causalidade perfeita. O sistema ainda não possui atribuição completa do tipo “este lembrete gerou esta confirmação”.

### Reativações com novo agendamento
- Leitura inferida.
- Conta clientes com reativação acionada no período e com novo agendamento criado depois dessa mensagem, ainda dentro da janela selecionada.
- Não representa causalidade perfeita. O sistema ainda não comprova que o agendamento ocorreu exclusivamente por causa da reativação.

## Semântica operacional usada na frente

### Lembrete enfileirado
- A mensagem entrou na fila, mas ainda não foi aceita pelo provider.

### Lembrete enviado
- O provider já aceitou o envio.
- Isso é diferente de “entregue” ou “lido”.

### Confirmação manual
- É um pedido manual de confirmação disparado pelo gestor para um agendamento.
- Usa o mesmo pipeline oficial de mensageria, com deduplicação, smart routing e fallback.

### Cliente ignorado temporariamente
- O gestor ocultou a elegibilidade de reativação por 7 dias.
- Enquanto o snooze estiver ativo, o cliente não aparece como apto para disparo manual ou automático de reativação.

## Timezone e período

- O período da UI (`Hoje`, `Últimos 7 dias`, `Últimos 30 dias`) é calculado no fuso configurado do tenant.
- As consultas ao banco usam essa mesma janela convertida para o timezone de persistência da aplicação.

## Limitações semânticas atuais

- A frente não mede conversão com atribuição perfeita.
- Ela também não é uma visão analítica histórica completa.
- Os cards foram desenhados para leitura operacional simples e honesta, não para BI avançado.
