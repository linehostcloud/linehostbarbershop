@extends('layouts.tenant-panel')

@section('title', 'Relacionamento WhatsApp')

@section('content')
    <div class="space-y-4">
        <header class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="space-y-2">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-amber-700">Relacionamento com Cliente</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">WhatsApp na agenda e na carteira de clientes</h1>
                        <span class="rounded-full border border-stone-200 bg-stone-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                            {{ $tenant->trade_name }}
                        </span>
                    </div>
                    <p class="max-w-3xl text-sm leading-6 text-slate-600">
                        Superfície simples para o barbeiro ou gestor acompanhar lembretes, confirmações e oportunidades de reativação sem entrar no painel técnico da mensageria.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <form method="GET" action="{{ route('tenant.panel.whatsapp.relationship') }}" class="flex items-center gap-2">
                        <label class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                            Data inicial da agenda
                            <input
                                type="date"
                                name="date"
                                value="{{ $panel['filters']['date'] }}"
                                class="mt-1 rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                            >
                        </label>
                        <label class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                            Período dos indicadores
                            <select
                                name="period"
                                class="mt-1 rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                            >
                                @foreach ($panel['metrics']['period']['options'] as $option)
                                    <option value="{{ $option['value'] }}" @selected($option['value'] === $panel['metrics']['period']['selected'])>
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <button
                            type="submit"
                            class="mt-5 inline-flex h-[42px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-stone-50"
                        >
                            Atualizar
                        </button>
                    </form>

                    <form method="POST" action="{{ route('tenant.panel.whatsapp.operations.logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="mt-5 inline-flex h-[42px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
                        >
                            Sair
                        </button>
                    </form>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-stone-200 pt-3 text-xs text-slate-500">
                <span>Usuário: <span class="font-medium text-slate-700">{{ $user->name }}</span></span>
                <span class="hidden text-stone-300 sm:inline">•</span>
                <span>Papel: <span class="font-medium text-slate-700">{{ $membership->role }}</span></span>
                @if ($panel['sections']['appointments'])
                    <span class="hidden text-stone-300 sm:inline">•</span>
                    <span>Lembrete automático: <span class="font-medium text-slate-700">{{ $panel['automations']['appointment_reminder']['status'] === 'active' ? 'ativo' : 'inativo' }}</span></span>
                @endif
                @if ($panel['sections']['reactivation'])
                    <span class="hidden text-stone-300 sm:inline">•</span>
                    <span>Reativação automática: <span class="font-medium text-slate-700">{{ $panel['automations']['inactive_client_reactivation']['status'] === 'active' ? 'ativa' : 'inativa' }}</span></span>
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
                <p class="font-semibold">Não foi possível concluir a ação.</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="space-y-3">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Indicadores do WhatsApp</h2>
                    <p class="mt-1 text-sm text-slate-600">Leitura resumida de {{ $panel['metrics']['period']['label'] }} para lembretes, confirmações e reativações.</p>
                </div>
                <div class="text-xs leading-5 text-slate-500">
                    <p>{{ $panel['metrics']['period']['help'] }}</p>
                    <p>Janela aplicada: {{ $panel['metrics']['period']['from'] }} até {{ $panel['metrics']['period']['to'] }}.</p>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($panel['metrics']['cards'] as $card)
                <article class="rounded-3xl border border-stone-200 bg-white/95 px-4 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                    <div data-metric-key="{{ $card['key'] }}" data-metric-value="{{ $card['value'] }}" data-metric-source="{{ $card['source'] }}"></div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $card['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-950">{{ $card['value'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $card['help'] }}</p>
                </article>
            @endforeach
            </div>

            @if ($panel['metrics']['has_inferred_cards'])
                <p class="text-xs leading-5 text-slate-500">
                    Conversões mostradas acima são leituras inferidas a partir do que já foi registrado na base do produto.
                </p>
            @endif
        </section>

        @if ($panel['sections']['appointments'])
        <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Agendamentos do Período</h2>
                    <p class="mt-1 text-sm text-slate-600">Leitura simples de lembrete e confirmação a partir de {{ $panel['filters']['date_label'] }}, considerando os próximos 3 dias.</p>
                </div>
                <p class="max-w-md text-xs leading-5 text-slate-500">
                    O botão manual usa o mesmo pipeline oficial de mensageria, com deduplicação, smart routing e fallback já existentes.
                </p>
            </div>

            @if ($panel['appointments'] === [])
                <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-slate-600">
                    Nenhum agendamento relevante para acompanhar nesse período.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-[11px] uppercase tracking-[0.18em] text-slate-500">
                                <th class="px-3 py-3 font-semibold">Cliente</th>
                                <th class="px-3 py-3 font-semibold">Horário</th>
                                <th class="px-3 py-3 font-semibold">Profissional</th>
                                <th class="px-3 py-3 font-semibold">Lembrete</th>
                                <th class="px-3 py-3 font-semibold">Confirmação</th>
                                <th class="px-3 py-3 font-semibold">Último lembrete</th>
                                <th class="px-3 py-3 font-semibold">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @foreach ($panel['appointments'] as $item)
                                <tr class="align-top">
                                    <td class="px-3 py-3">
                                        <p class="font-semibold text-slate-900">{{ $item['client_name'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $item['service_name'] ?? 'Serviço não informado' }}</p>
                                        @if (! $item['whatsapp_eligible'])
                                            <p class="mt-2 inline-flex rounded-full bg-rose-100 px-2 py-1 text-[11px] font-semibold text-rose-700">Sem WhatsApp elegível</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-slate-700">
                                        <p class="font-medium">{{ $item['starts_at_local'] ?? 'Horário não informado' }}</p>
                                        <p class="mt-1 text-xs text-slate-500">Status do agendamento: {{ $item['appointment_status'] }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-slate-700">{{ $item['professional_name'] ?? 'Não informado' }}</td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $item['reminder']['tone'] === 'positive' ? 'bg-emerald-100 text-emerald-700' : ($item['reminder']['tone'] === 'warning' ? 'bg-amber-100 text-amber-700' : ($item['reminder']['tone'] === 'danger' ? 'bg-rose-100 text-rose-700' : 'bg-stone-200 text-slate-700')) }}">
                                            {{ $item['reminder']['label'] }}
                                        </span>
                                        <p class="mt-2 max-w-xs text-xs leading-5 text-slate-500">{{ $item['reminder']['help'] }}</p>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $item['confirmation']['tone'] === 'positive' ? 'bg-emerald-100 text-emerald-700' : ($item['confirmation']['tone'] === 'warning' ? 'bg-amber-100 text-amber-700' : ($item['confirmation']['tone'] === 'danger' ? 'bg-rose-100 text-rose-700' : 'bg-stone-200 text-slate-700')) }}">
                                            {{ $item['confirmation']['label'] }}
                                        </span>
                                        <p class="mt-2 max-w-xs text-xs leading-5 text-slate-500">{{ $item['confirmation']['help'] }}</p>
                                        @if ($item['confirmation']['latest_message']['at_local'])
                                            <p class="mt-1 text-xs text-slate-500">{{ $item['confirmation']['latest_message']['at_local'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-slate-700">
                                        <p class="font-medium">{{ $item['latest_message']['label'] }}</p>
                                        @if ($item['latest_message']['at_local'])
                                            <p class="mt-1 text-xs text-slate-500">{{ $item['latest_message']['at_local'] }}</p>
                                        @endif
                                        @if ($item['latest_message']['body'])
                                            <p class="mt-1 max-w-xs text-xs leading-5 text-slate-500">{{ \Illuminate\Support\Str::limit($item['latest_message']['body'], 96) }}</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="space-y-3">
                                            <div class="space-y-1">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">Lembrete</p>
                                                @if (($permissions['appointments']['write'] ?? false) === true && ($item['manual_actions']['reminder']['can_send_reminder'] ?? false) === true)
                                                    <form method="POST" action="{{ route('tenant.panel.whatsapp.relationship.appointments.reminder', $item['id']) }}">
                                                        @csrf
                                                        <button
                                                            type="submit"
                                                            class="inline-flex h-[38px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:bg-stone-50"
                                                        >
                                                            Enviar lembrete
                                                        </button>
                                                    </form>
                                                @elseif (($permissions['appointments']['write'] ?? false) === true)
                                                    <span class="text-xs text-slate-500">{{ $item['manual_actions']['reminder']['hint'] ?? 'A ação manual fica disponível quando o agendamento estiver apto.' }}</span>
                                                @else
                                                    <span class="text-xs text-slate-500">Seu perfil pode acompanhar, mas não disparar mensagens.</span>
                                                @endif
                                            </div>

                                            <div class="space-y-1 border-t border-stone-200 pt-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">Confirmação</p>
                                                @if (($permissions['appointments']['write'] ?? false) === true && ($item['manual_actions']['confirmation']['can_send_confirmation'] ?? false) === true)
                                                    <form method="POST" action="{{ route('tenant.panel.whatsapp.relationship.appointments.confirmation', $item['id']) }}">
                                                        @csrf
                                                        <button
                                                            type="submit"
                                                            class="inline-flex h-[38px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:bg-stone-50"
                                                        >
                                                            Pedir confirmação
                                                        </button>
                                                    </form>
                                                @elseif (($permissions['appointments']['write'] ?? false) === true)
                                                    <span class="text-xs text-slate-500">{{ $item['manual_actions']['confirmation']['hint'] ?? 'A confirmação manual fica disponível quando fizer sentido para esse agendamento.' }}</span>
                                                @else
                                                    <span class="text-xs text-slate-500">Seu perfil pode acompanhar, mas não disparar mensagens.</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        @endif

        @if ($panel['sections']['reactivation'])
        <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Clientes para Reativação</h2>
                    <p class="mt-1 text-sm text-slate-600">Visão enxuta dos clientes com potencial de retorno, sem expor o gestor ao painel técnico do agente.</p>
                </div>
                <p class="max-w-md text-xs leading-5 text-slate-500">
                    A elegibilidade segue a mesma regra do motor automático: histórico real, inatividade mínima, contato elegível e respeito ao cooldown.
                </p>
            </div>

            @if ($panel['reactivation_clients'] === [])
                <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-sm text-slate-600">
                    Nenhum cliente relevante para reativação nesta leitura.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-[11px] uppercase tracking-[0.18em] text-slate-500">
                                <th class="px-3 py-3 font-semibold">Cliente</th>
                                <th class="px-3 py-3 font-semibold">Última visita</th>
                                <th class="px-3 py-3 font-semibold">Inatividade</th>
                                <th class="px-3 py-3 font-semibold">Situação</th>
                                <th class="px-3 py-3 font-semibold">Última reativação</th>
                                <th class="px-3 py-3 font-semibold">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            @foreach ($panel['reactivation_clients'] as $item)
                                <tr class="align-top">
                                    <td class="px-3 py-3">
                                        <p class="font-semibold text-slate-900">{{ $item['client_name'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $item['client_phone'] ?? 'Telefone não informado' }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-slate-700">{{ $item['last_visit_at_local'] ?? 'Sem histórico recente' }}</td>
                                    <td class="px-3 py-3 text-slate-700">
                                        @if ($item['inactive_days'] !== null)
                                            {{ $item['inactive_days'] }} dias
                                        @else
                                            Não calculado
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $item['status']['tone'] === 'default' ? 'bg-amber-100 text-amber-700' : ($item['status']['tone'] === 'danger' ? 'bg-rose-100 text-rose-700' : ($item['status']['tone'] === 'warning' ? 'bg-orange-100 text-orange-700' : 'bg-stone-200 text-slate-700')) }}">
                                            {{ $item['status']['label'] }}
                                        </span>
                                        @if (! empty($item['status']['help']))
                                            <p class="mt-2 text-xs text-slate-500">{{ $item['status']['help'] }}</p>
                                        @endif
                                        <p class="mt-2 text-xs text-slate-500">
                                            Automação {{ $item['automation_enabled'] ? 'ativa' : 'inativa' }} • {{ $item['completed_visits'] ?? 0 }} visita(s) concluída(s)
                                        </p>
                                    </td>
                                    <td class="px-3 py-3 text-slate-700">
                                        <p class="font-medium">{{ $item['latest_message']['label'] }}</p>
                                        @if ($item['latest_message']['at_local'])
                                            <p class="mt-1 text-xs text-slate-500">{{ $item['latest_message']['at_local'] }}</p>
                                        @endif
                                        @if ($item['latest_message']['body'])
                                            <p class="mt-1 max-w-xs text-xs leading-5 text-slate-500">{{ \Illuminate\Support\Str::limit($item['latest_message']['body'], 96) }}</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        @if (($permissions['clients']['write'] ?? false) === true && ($item['manual_actions']['can_trigger_reactivation'] ?? false) === true)
                                            <div class="space-y-3">
                                                <form method="POST" action="{{ route('tenant.panel.whatsapp.relationship.clients.reactivation', $item['id']) }}">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="inline-flex h-[40px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-stone-50"
                                                    >
                                                        Acionar reativação
                                                    </button>
                                                </form>

                                                @if ($item['manual_actions']['can_snooze_reactivation'] ?? false)
                                                    <form method="POST" action="{{ route('tenant.panel.whatsapp.relationship.clients.reactivation.snooze', $item['id']) }}">
                                                        @csrf
                                                        <button
                                                            type="submit"
                                                            class="inline-flex h-[36px] items-center justify-center rounded-2xl border border-stone-300 bg-stone-50 px-3 text-xs font-semibold text-slate-600 transition hover:bg-stone-100"
                                                        >
                                                            Ignorar por 7 dias
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @elseif (($permissions['clients']['write'] ?? false) === true)
                                            <span class="text-xs text-slate-500">{{ $item['status']['help'] ?? 'Aguardando a próxima condição elegível.' }}</span>
                                        @else
                                            <span class="text-xs text-slate-500">Seu perfil pode acompanhar, mas não disparar mensagens.</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        @endif
    </div>
@endsection
