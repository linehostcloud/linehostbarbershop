@extends('layouts.tenant-panel')

@section('title', 'Governança WhatsApp')

@section('content')
    @php
        $automationStatusLabels = [
            'active' => 'Ativa',
            'inactive' => 'Inativa',
        ];
        $runStatusLabels = [
            'completed' => 'Concluído',
            'failed' => 'Falhou',
            'running' => 'Em andamento',
            'pending' => 'Pendente',
        ];
        $insightSeverityLabels = [
            'high' => 'Alta',
            'medium' => 'Média',
            'low' => 'Baixa',
        ];
        $insightStatusLabels = [
            'active' => 'Ativo',
            'resolved' => 'Resolvido',
            'ignored' => 'Ignorado',
            'executed' => 'Executado',
        ];
        $insightTypeLabels = [
            'provider_health_alert' => 'Alerta de saúde do provider',
            'automation_opportunity_reactivation' => 'Oportunidade de reativação',
            'automation_opportunity_reminder' => 'Oportunidade de lembrete',
            'duplicate_risk_alert' => 'Alerta de risco de duplicidade',
            'delivery_instability_alert' => 'Alerta de instabilidade de entrega',
        ];
    @endphp
    <div class="space-y-4">
        <header class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="space-y-2">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-amber-700">Governança Operacional</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Automações e Agente WhatsApp</h1>
                        <span class="rounded-full border border-stone-200 bg-stone-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                            {{ $tenant->trade_name }}
                        </span>
                    </div>
                    <p class="max-w-3xl text-sm leading-6 text-slate-600">
                        Superfície administrativa mínima para ajustar automações, operar insights do agente e inspecionar runs recentes com trilha auditável.
                    </p>
                </div>

                <form method="POST" action="{{ route('tenant.panel.whatsapp.operations.logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex h-[42px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
                    >
                        Sair
                    </button>
                </form>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-stone-200 pt-3 text-xs text-slate-500">
                <span>Usuário: <span class="font-medium text-slate-700">{{ $user->name }}</span></span>
                <span class="hidden text-stone-300 sm:inline">•</span>
                <span>Papel: <span class="font-medium text-slate-700">{{ $membership->role }}</span></span>
                @if (($latestAgentRun['completed_at'] ?? null) !== null)
                    <span class="hidden text-stone-300 sm:inline">•</span>
                    <span>Último run do agente: <span class="font-medium text-slate-700">{{ \Illuminate\Support\Carbon::parse($latestAgentRun['completed_at'])->format('d/m H:i') }}</span></span>
                @endif
            </div>
        </header>

        @include('tenant.panel.whatsapp.partials.navigation', ['navigation' => $navigation])

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <p class="font-semibold">Não foi possível concluir a alteração.</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (($permissions['automations']['read'] ?? false) === true)
            <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Automações WhatsApp</h2>
                        <p class="mt-1 text-sm text-slate-600">Configuração prática por tenant, com métricas, último run e edição dos parâmetros essenciais.</p>
                    </div>
                    <p class="max-w-md text-xs leading-5 text-slate-500">As alterações continuam usando a modelagem existente de `conditions_json`, `action_payload_json` e `cooldown_hours`.</p>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    @forelse ($automations as $automation)
                        <article id="automation-{{ $automation['type'] }}" class="rounded-3xl border border-stone-200 bg-stone-50 px-4 py-4">
                            <div class="flex flex-col gap-3 border-b border-stone-200 pb-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold text-slate-900">{{ $automation['name'] }}</h3>
                                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ $automation['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-200 text-slate-600' }}">
                                            {{ $automationStatusLabels[$automation['status']] ?? $automation['status'] }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ $automation['description'] }}</p>
                                </div>

                                @if (($permissions['automations']['write'] ?? false) === true)
                                    <form method="POST" action="{{ route('tenant.panel.whatsapp.governance.automations.update', $automation['type']) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $automation['status'] === 'active' ? 'inactive' : 'active' }}">
                                        <button
                                            type="submit"
                                            class="inline-flex h-[40px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-stone-100"
                                        >
                                            {{ $automation['status'] === 'active' ? 'Desabilitar' : 'Habilitar' }}
                                        </button>
                                    </form>
                                @endif
                            </div>

                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                                <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Execuções</p>
                                    <p class="mt-2 text-xl font-semibold text-slate-950">{{ $automation['metrics']['runs_total'] }}</p>
                                </div>
                                <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Enfileiradas</p>
                                    <p class="mt-2 text-xl font-semibold text-slate-950">{{ $automation['metrics']['messages_queued_total'] }}</p>
                                </div>
                                <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Ignoradas</p>
                                    <p class="mt-2 text-xl font-semibold text-slate-950">{{ $automation['metrics']['skipped_total'] }}</p>
                                </div>
                                <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Falhas</p>
                                    <p class="mt-2 text-xl font-semibold text-slate-950">{{ $automation['metrics']['failed_total'] }}</p>
                                </div>
                                <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Acionamentos de Cooldown</p>
                                    <p class="mt-2 text-xl font-semibold text-slate-950">{{ $automation['metrics']['cooldown_hits_total'] }}</p>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
                                <div class="rounded-2xl border border-stone-200 bg-white px-4 py-4">
                                    <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Configuração Atual</h4>
                                    @if (($permissions['automations']['write'] ?? false) === true)
                                        <form method="POST" action="{{ route('tenant.panel.whatsapp.governance.automations.update', $automation['type']) }}" class="mt-3 space-y-3">
                                            @csrf
                                            @method('PATCH')

                                            <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                Status
                                                <select name="status" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                                    <option value="active" @selected($automation['status'] === 'active')>Ativa</option>
                                                    <option value="inactive" @selected($automation['status'] === 'inactive')>Inativa</option>
                                                </select>
                                            </label>

                                            @if ($automation['type'] === 'appointment_reminder')
                                                <div class="grid gap-3 md:grid-cols-2">
                                                    <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                        Antecedência em Minutos
                                                        <input name="conditions[lead_time_minutes]" type="number" min="1" max="43200" value="{{ $automation['conditions']['lead_time_minutes'] ?? 1440 }}" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                                    </label>
                                                    <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                        Tolerância de Seleção
                                                        <input name="conditions[selection_tolerance_minutes]" type="number" min="1" max="720" value="{{ $automation['conditions']['selection_tolerance_minutes'] ?? 30 }}" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                                    </label>
                                                </div>
                                            @endif

                                            @if ($automation['type'] === 'inactive_client_reactivation')
                                                <div class="grid gap-3 md:grid-cols-2">
                                                    <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                        Dias de Inatividade
                                                        <input name="conditions[inactivity_days]" type="number" min="1" max="3650" value="{{ $automation['conditions']['inactivity_days'] ?? 45 }}" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                                    </label>
                                                    <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                        Mínimo de Visitas Concluídas
                                                        <input name="conditions[minimum_completed_visits]" type="number" min="1" max="1000" value="{{ $automation['conditions']['minimum_completed_visits'] ?? 1 }}" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                                    </label>
                                                </div>
                                            @endif

                                            <div class="grid gap-3 md:grid-cols-2">
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                    Cooldown em Horas
                                                    <input name="cooldown_hours" type="number" min="1" max="8760" value="{{ $automation['cooldown_hours'] }}" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                                </label>
                                                <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                    Prioridade
                                                    <input name="priority" type="number" min="1" max="1000" value="{{ $automation['priority'] }}" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                                </label>
                                            </div>

                                            <label class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                Mensagem Base
                                                <textarea name="message[body_text]" rows="4" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm leading-6 text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">{{ $automation['message']['body_text'] ?? '' }}</textarea>
                                            </label>

                                            <button type="submit" class="inline-flex h-[42px] items-center justify-center rounded-2xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800">
                                                Salvar automação
                                            </button>
                                        </form>
                                    @else
                                        <div class="mt-3 space-y-2 text-sm text-slate-600">
                                            <p>Status: <span class="font-medium text-slate-900">{{ $automationStatusLabels[$automation['status']] ?? $automation['status'] }}</span></p>
                                            <p>Cooldown: <span class="font-medium text-slate-900">{{ $automation['cooldown_hours'] }}h</span></p>
                                            <p>Mensagem: <span class="font-medium text-slate-900">{{ $automation['message']['body_text'] ?? 'Não configurada.' }}</span></p>
                                        </div>
                                    @endif
                                </div>

                                <div class="space-y-4">
                                    <div class="rounded-2xl border border-stone-200 bg-white px-4 py-4">
                                        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Último Run</h4>
                                        @if ($automation['latest_run'] !== null)
                                            <div class="mt-3 space-y-2 text-sm text-slate-600">
                                                <p>Status: <span class="font-medium text-slate-900">{{ $runStatusLabels[$automation['latest_run']['status']] ?? $automation['latest_run']['status'] }}</span></p>
                                                <p>Candidatos: <span class="font-medium text-slate-900">{{ $automation['latest_run']['candidates_found'] }}</span></p>
                                                <p>Enfileiradas: <span class="font-medium text-slate-900">{{ $automation['latest_run']['messages_queued'] }}</span></p>
                                                <p>Ignoradas: <span class="font-medium text-slate-900">{{ $automation['latest_run']['skipped_total'] }}</span></p>
                                                <p>Falhas: <span class="font-medium text-slate-900">{{ $automation['latest_run']['failed_total'] }}</span></p>
                                                <p>Quando: <span class="font-medium text-slate-900">{{ \Illuminate\Support\Carbon::parse($automation['latest_run']['started_at'])->format('d/m/Y H:i') }}</span></p>
                                            </div>
                                        @else
                                            <p class="mt-3 text-sm text-slate-500">Nenhum run registrado ainda para esta automação.</p>
                                        @endif
                                    </div>

                                    <div class="rounded-2xl border border-stone-200 bg-white px-4 py-4">
                                        <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Motivos de Skip Relevantes</h4>
                                        @if (! empty($automation['metrics']['skip_reason_totals']))
                                            <ul class="mt-3 space-y-2 text-sm text-slate-600">
                                                @foreach ($automation['metrics']['skip_reason_totals'] as $reason)
                                                    <li class="flex items-center justify-between gap-3 rounded-2xl border border-stone-200 px-3 py-2">
                                                        <span>{{ $reason['reason'] }}</span>
                                                        <span class="font-semibold text-slate-900">{{ $reason['total'] }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="mt-3 text-sm text-slate-500">Nenhum motivo de skip relevante registrado até agora.</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-slate-500">
                            Nenhuma automação WhatsApp disponível para este tenant.
                        </div>
                    @endforelse
                </div>
            </section>
        @endif

        @if (($permissions['agent']['read'] ?? false) === true)
            <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                <div class="mb-4 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Insights e Recomendações do Agente</h2>
                        <p class="mt-1 text-sm text-slate-600">Leitura humana das evidências atuais, com filtros simples e ações seguras restritas.</p>
                    </div>

                    <form method="GET" class="grid gap-2 sm:grid-cols-3">
                        <label class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                            Status
                            <select name="insight_status" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                <option value="">Todos</option>
                                @foreach (['active', 'resolved', 'ignored', 'executed'] as $status)
                                    <option value="{{ $status }}" @selected($filters['insight_status'] === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                            Severidade
                            <select name="insight_severity" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                <option value="">Todas</option>
                                @foreach (['high', 'medium', 'low'] as $severity)
                                    <option value="{{ $severity }}" @selected($filters['insight_severity'] === $severity)>{{ $insightSeverityLabels[$severity] ?? $severity }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                            Tipo
                            <select name="insight_type" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                <option value="">Todos</option>
                                @foreach (['provider_health_alert', 'automation_opportunity_reactivation', 'automation_opportunity_reminder', 'duplicate_risk_alert', 'delivery_instability_alert'] as $type)
                                    <option value="{{ $type }}" @selected($filters['insight_type'] === $type)>{{ $insightTypeLabels[$type] ?? $type }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="sm:col-span-3 flex items-center gap-2">
                            <button type="submit" class="inline-flex h-[42px] items-center justify-center rounded-2xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800">Aplicar filtros</button>
                            <a href="{{ route('tenant.panel.whatsapp.governance') }}" class="inline-flex h-[42px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-stone-50">Limpar</a>
                        </div>
                    </form>
                </div>

                <div class="space-y-3">
                    @forelse ($agentInsights as $insight)
                        <article class="rounded-3xl border border-stone-200 bg-stone-50 px-4 py-4">
                            <div class="flex flex-col gap-3 border-b border-stone-200 pb-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold text-slate-950">{{ $insight['title'] }}</h3>
                                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ $insight['severity'] === 'high' ? 'bg-rose-100 text-rose-700' : ($insight['severity'] === 'medium' ? 'bg-amber-100 text-amber-700' : 'bg-sky-100 text-sky-700') }}">
                                            {{ $insightSeverityLabels[$insight['severity']] ?? $insight['severity'] }}
                                        </span>
                                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ $insight['status'] === 'active' ? 'bg-slate-900 text-white' : 'bg-stone-200 text-slate-600' }}">
                                            {{ $insightStatusLabels[$insight['status']] ?? $insight['status'] }}
                                        </span>
                                    </div>
                                    <p class="text-sm leading-6 text-slate-600">{{ $insight['summary'] }}</p>
                                    <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                                        <span>Tipo: <span class="font-medium text-slate-700">{{ $insightTypeLabels[$insight['type']] ?? $insight['type'] }}</span></span>
                                        <span>Recomendação: <span class="font-medium text-slate-700">{{ $insight['recommendation_type'] }}</span></span>
                                        @if ($insight['target_label'])
                                            <span>Alvo: <span class="font-medium text-slate-700">{{ $insight['target_label'] }}</span></span>
                                        @endif
                                        @if ($insight['last_detected_at'])
                                            <span>Última detecção: <span class="font-medium text-slate-700">{{ \Illuminate\Support\Carbon::parse($insight['last_detected_at'])->format('d/m/Y H:i') }}</span></span>
                                        @endif
                                    </div>
                                </div>

                                @if (($permissions['agent']['write'] ?? false) === true)
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($insight['status'] === 'active')
                                            <form method="POST" action="{{ route('tenant.panel.whatsapp.governance.agent.resolve', $insight['id']) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex h-[40px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-stone-100">Resolver</button>
                                            </form>
                                            <form method="POST" action="{{ route('tenant.panel.whatsapp.governance.agent.ignore', $insight['id']) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex h-[40px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-stone-100">Ignorar</button>
                                            </form>
                                        @endif
                                        @if ($insight['can_execute'] === true)
                                            <form method="POST" action="{{ route('tenant.panel.whatsapp.governance.agent.execute', $insight['id']) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex h-[40px] items-center justify-center rounded-2xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800">Executar ação segura</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="mt-4 grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
                                <div class="rounded-2xl border border-stone-200 bg-white px-4 py-4">
                                    <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Evidência</h4>
                                    @if (! empty($insight['evidence']))
                                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                                            @foreach (collect($insight['evidence'])->take(6) as $key => $value)
                                                <div class="rounded-2xl border border-stone-200 px-3 py-2 text-sm text-slate-600">
                                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ str_replace('_', ' ', (string) $key) }}</p>
                                                    <p class="mt-1 break-words font-medium text-slate-900">{{ is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="mt-3 text-sm text-slate-500">Sem evidência adicional serializada para este insight.</p>
                                    @endif
                                </div>

                                <div class="rounded-2xl border border-stone-200 bg-white px-4 py-4">
                                    <h4 class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Ação Sugerida</h4>
                                    <div class="mt-3 space-y-2 text-sm text-slate-600">
                                        <p>Ação: <span class="font-medium text-slate-900">{{ $insight['suggested_action'] ?? 'sem ação explícita' }}</span></p>
                                        <p>Modo: <span class="font-medium text-slate-900">{{ $insight['execution_mode'] }}</span></p>
                                        @if ($insight['executed_at'])
                                            <p>Executado em: <span class="font-medium text-slate-900">{{ \Illuminate\Support\Carbon::parse($insight['executed_at'])->format('d/m/Y H:i') }}</span></p>
                                        @endif
                                        @if (! empty($insight['execution_result']))
                                            <p>Resultado: <span class="font-medium text-slate-900">{{ json_encode($insight['execution_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</span></p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-slate-500">
                            Nenhum insight do agente encontrado para os filtros atuais.
                        </div>
                    @endforelse
                </div>

                @if ($agentInsights instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <div class="mt-4">
                        {{ $agentInsights->links() }}
                    </div>
                @endif
            </section>
        @endif

        <section class="grid gap-4 xl:grid-cols-2">
            @if (($permissions['automations']['read'] ?? false) === true)
                <div class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                    <div class="mb-3">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Histórico Recente de Execuções de Automação</h2>
                        <p class="mt-1 text-sm text-slate-600">Leitura curta de candidatos, enfileiradas, ignoradas e falhas por execução.</p>
                    </div>

                    <div class="space-y-3">
                        @forelse ($automationRuns as $run)
                            <article class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">{{ $run['automation_type'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $run['started_at'] ? \Illuminate\Support\Carbon::parse($run['started_at'])->format('d/m/Y H:i') : 'sem timestamp' }}</p>
                                    </div>
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ $run['status'] === 'completed' ? 'bg-emerald-100 text-emerald-700' : ($run['status'] === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">
                                        {{ $runStatusLabels[$run['status']] ?? $run['status'] }}
                                    </span>
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4 text-sm text-slate-600">
                                    <div>Candidatos: <span class="font-medium text-slate-900">{{ $run['candidates_found'] }}</span></div>
                                    <div>Enfileiradas: <span class="font-medium text-slate-900">{{ $run['messages_queued'] }}</span></div>
                                    <div>Ignoradas: <span class="font-medium text-slate-900">{{ $run['skipped_total'] }}</span></div>
                                    <div>Falhas: <span class="font-medium text-slate-900">{{ $run['failed_total'] }}</span></div>
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-slate-500">
                                Nenhuma execução de automação registrada ainda.
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif

            @if (($permissions['agent']['read'] ?? false) === true)
                <div class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                    <div class="mb-3">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Histórico Recente de Execuções do Agente</h2>
                        <p class="mt-1 text-sm text-slate-600">Resumo objetivo dos ciclos de análise do agente operacional.</p>
                    </div>

                    <div class="space-y-3">
                        @forelse ($agentRuns as $run)
                            <article class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">Run {{ $run['id'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $run['started_at'] ? \Illuminate\Support\Carbon::parse($run['started_at'])->format('d/m/Y H:i') : 'sem timestamp' }}</p>
                                    </div>
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ $run['status'] === 'completed' ? 'bg-emerald-100 text-emerald-700' : ($run['status'] === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">
                                        {{ $runStatusLabels[$run['status']] ?? $run['status'] }}
                                    </span>
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4 text-sm text-slate-600">
                                    <div>Criados: <span class="font-medium text-slate-900">{{ $run['insights_created'] }}</span></div>
                                    <div>Atualizados: <span class="font-medium text-slate-900">{{ $run['insights_refreshed'] }}</span></div>
                                    <div>Resolvidos: <span class="font-medium text-slate-900">{{ $run['insights_resolved'] }}</span></div>
                                    <div>Ações seguras: <span class="font-medium text-slate-900">{{ $run['safe_actions_executed'] }}</span></div>
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-slate-500">
                                Nenhuma execução do agente registrada ainda.
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection
