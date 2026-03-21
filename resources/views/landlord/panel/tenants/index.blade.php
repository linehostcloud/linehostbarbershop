@extends('layouts.landlord-panel')

@section('title', 'Tenants SaaS')

@section('content')
    @php
        $indexQuery = static fn (array $overrides = []): array => array_filter(
            array_merge($filters ?? [], $overrides),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
        $indexUrl = static fn (array $overrides = []): string => route('landlord.tenants.index', $indexQuery($overrides));
        $isFilterActive = static fn (string $key, string $value): bool => ($filters[$key] ?? '') === $value;
    @endphp

    <div class="w-full space-y-6">
        <header class="flex flex-col gap-4 rounded-3xl border border-slate-800 bg-slate-900/80 p-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Painel SaaS</p>
                <h1 class="text-3xl font-semibold text-white">Resumo landlord</h1>
                <p class="text-sm text-slate-300">
                    Visão operacional e administrativa dos tenants, com lista detalhada logo abaixo.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <a
                    href="{{ route('landlord.tenants.index') }}"
                    class="inline-flex items-center justify-center rounded-2xl border px-4 py-3 text-sm font-semibold transition {{ ($navigation['active'] ?? '') === 'tenants' ? 'border-cyan-400 bg-cyan-400 text-slate-950' : 'border-slate-700 text-slate-100 hover:border-slate-500 hover:bg-slate-800' }}"
                >
                    Tenants
                </a>
                <a
                    href="{{ route('landlord.tenants.snapshots') }}"
                    class="inline-flex items-center justify-center rounded-2xl border px-4 py-3 text-sm font-semibold transition {{ ($navigation['active'] ?? '') === 'snapshots' ? 'border-cyan-400 bg-cyan-400 text-slate-950' : 'border-slate-700 text-slate-100 hover:border-slate-500 hover:bg-slate-800' }}"
                >
                    Snapshots
                </a>
                <a
                    href="{{ route('landlord.tenants.create') }}"
                    class="inline-flex items-center justify-center rounded-2xl border px-4 py-3 text-sm font-semibold transition {{ ($navigation['active'] ?? '') === 'create' ? 'border-cyan-400 bg-cyan-400 text-slate-950' : 'border-slate-700 text-slate-100 hover:border-slate-500 hover:bg-slate-800' }}"
                >
                    Criar tenant
                </a>
                <form method="POST" action="{{ route('landlord.logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-800"
                    >
                        Sair
                    </button>
                </form>
            </div>
        </header>

        @if (session('status'))
            @php($status = session('status'))
            <section class="rounded-3xl border p-5 text-sm {{ data_get($status, 'type') === 'error' ? 'border-rose-500/30 bg-rose-500/10 text-rose-50' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-50' }}">
                <p class="font-semibold">{{ data_get($status, 'message') }}</p>
                <div class="mt-3 grid gap-2 {{ data_get($status, 'type') === 'error' ? 'text-rose-100' : 'text-emerald-100' }} sm:grid-cols-2">
                    <p><span class="font-semibold">Slug:</span> {{ data_get($status, 'tenant.slug') }}</p>
                    <p><span class="font-semibold">Domínio principal:</span> {{ data_get($status, 'tenant.domain') }}</p>
                    <p><span class="font-semibold">Owner:</span> {{ data_get($status, 'tenant.owner_email') ?: 'Não informado' }}</p>
                    @if (data_get($status, 'tenant.temporary_password'))
                        <p><span class="font-semibold">Senha temporária:</span> {{ data_get($status, 'tenant.temporary_password') }}</p>
                    @endif
                </div>
            </section>
        @endif

        <section class="grid gap-4 xl:grid-cols-4">
            <a
                href="{{ route('landlord.tenants.index') }}"
                class="group rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition hover:border-slate-700 hover:bg-slate-900"
            >
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Total de tenants</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $dashboard['headline']['total_tenants'] }}</p>
                <p class="mt-2 text-sm text-slate-400">Base landlord cadastrada e visível pelo painel.</p>
                <p class="mt-4 text-xs font-semibold text-cyan-300 transition group-hover:text-cyan-200">Abrir listagem completa</p>
            </a>

            @foreach ($dashboard['headline']['status_totals'] as $status)
                <a
                    href="{{ $indexUrl(['status' => $status['code']]) }}"
                    class="group rounded-3xl border bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition {{ $isFilterActive('status', $status['code']) ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700 hover:bg-slate-900' }}"
                >
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Status administrativo</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $status['count'] }}</p>
                    <p class="mt-2 text-sm text-slate-300">{{ $status['label'] }}</p>
                    <p class="mt-4 text-xs font-semibold {{ $isFilterActive('status', $status['code']) ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Abrir listagem</p>
                </a>
            @endforeach
        </section>

        <section class="grid gap-4 xl:grid-cols-[1.2fr_1fr_1fr]">
            <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Onboarding</h2>
                        <p class="mt-1 text-sm text-slate-400">
                            Fotografia administrativa dos estágios atuais, separada das pendências operacionais.
                        </p>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    @foreach ($dashboard['headline']['onboarding_totals'] as $stage)
                        <a
                            href="{{ $indexUrl(['onboarding_stage' => $stage['code']]) }}"
                            class="group rounded-2xl border bg-slate-950/60 p-4 transition {{ $isFilterActive('onboarding_stage', $stage['code']) ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700' }}"
                        >
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">{{ $stage['label'] }}</p>
                            <p class="mt-3 text-2xl font-semibold text-white">{{ $stage['count'] }}</p>
                            <p class="mt-3 text-xs font-semibold {{ $isFilterActive('onboarding_stage', $stage['code']) ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Abrir listagem</p>
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20">
                <h2 class="text-lg font-semibold text-white">Pendências operacionais básicas</h2>
                <p class="mt-1 text-sm text-slate-400">
                    Banco, schema, domínio principal e owner ativo ainda não totalmente prontos.
                </p>

                <a
                    href="{{ $indexUrl(['provisioning' => 'pending']) }}"
                    class="mt-5 block rounded-2xl border bg-slate-950/60 p-4 transition {{ $isFilterActive('provisioning', 'pending') ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700' }}"
                >
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Tenants com pendência</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $dashboard['operational']['pending_tenants_count'] }}</p>
                    <p class="mt-3 text-xs font-semibold {{ $isFilterActive('provisioning', 'pending') ? 'text-cyan-200' : 'text-cyan-300' }}">Abrir listagem operacional</p>
                </a>
            </div>

            <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20">
                <h2 class="text-lg font-semibold text-white">Suspensos com pressão recente</h2>
                <p class="mt-1 text-sm text-slate-400">
                    Tentativas bloqueadas por runtime suspenso em {{ $dashboard['operational']['pressure_window_label'] }}.
                </p>

                <a
                    href="{{ $indexUrl(['pressure' => 'suspended_recent']) }}"
                    class="mt-5 block rounded-2xl border bg-slate-950/60 p-4 transition {{ $isFilterActive('pressure', 'suspended_recent') ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700' }}"
                >
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Tenants afetados</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $dashboard['operational']['suspended_with_pressure_count'] }}</p>
                    <p class="mt-3 text-xs font-semibold {{ $isFilterActive('pressure', 'suspended_recent') ? 'text-cyan-200' : 'text-cyan-300' }}">Abrir subconjunto filtrado</p>
                </a>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[1.05fr_1.05fr_1.2fr]">
            <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Pendências operacionais básicas</h2>
                        <p class="mt-1 text-sm text-slate-400">Lista enxuta para atacar readiness real dos tenants.</p>
                    </div>
                    <span class="rounded-full border border-slate-700 px-3 py-1 text-[11px] font-semibold text-slate-300">
                        {{ count($dashboard['pending_tenants']) }} visíveis
                    </span>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse ($dashboard['pending_tenants'] as $tenant)
                        <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-white">{{ $tenant['trade_name'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $tenant['slug'] }}</p>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="rounded-full border border-amber-500/40 bg-amber-500/10 px-2.5 py-1 text-[11px] font-semibold text-amber-200">
                                    {{ $tenant['provisioning']['label'] }}
                                </span>
                                <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                                    {{ $tenant['status']['label'] }}
                                </span>
                                <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                                    {{ $tenant['onboarding_stage']['label'] }}
                                </span>
                            </div>
                            <p class="mt-3 text-xs leading-5 text-slate-400">{{ $tenant['provisioning']['detail'] }}</p>
                            <div class="mt-4 flex items-center gap-3">
                                <a
                                    href="{{ $indexUrl(['provisioning' => $tenant['provisioning']['code']]) }}"
                                    class="text-xs font-semibold text-cyan-300 transition hover:text-cyan-200"
                                >
                                    Abrir na listagem
                                </a>
                                <a
                                    href="{{ route('landlord.tenants.show', $tenant['id']) }}"
                                    class="text-xs font-semibold text-slate-300 transition hover:text-white"
                                >
                                    Ver detalhe
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">
                            Nenhum tenant apresenta pendência operacional básica no momento.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Suspensos com pressão recente</h2>
                        <p class="mt-1 text-sm text-slate-400">Pressão transversal auditada nos canais bloqueados.</p>
                    </div>
                    <span class="rounded-full border border-slate-700 px-3 py-1 text-[11px] font-semibold text-slate-300">
                        {{ $dashboard['operational']['pressure_window_label'] }}
                    </span>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse ($dashboard['suspended_pressure'] as $tenant)
                        <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-white">{{ $tenant['trade_name'] }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $tenant['slug'] }}</p>
                                </div>
                            </div>
                            <p class="mt-3 text-sm text-slate-200">
                                {{ $tenant['total_blocks'] }} bloqueio(s) recente(s) em {{ $tenant['affected_channels_count'] }} canal(is).
                            </p>
                            <p class="mt-2 text-xs text-slate-400">
                                Última ocorrência em {{ $tenant['last_blocked_at'] ?: 'não registrada' }}.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($tenant['channels'] as $channel)
                                    <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                                        {{ $channel }}
                                    </span>
                                @endforeach
                            </div>
                            <div class="mt-4 flex items-center gap-3">
                                <a
                                    href="{{ $indexUrl(['pressure' => 'suspended_recent']) }}"
                                    class="text-xs font-semibold text-cyan-300 transition hover:text-cyan-200"
                                >
                                    Abrir na listagem
                                </a>
                                <a
                                    href="{{ route('landlord.tenants.show', $tenant['id']) }}"
                                    class="text-xs font-semibold text-slate-300 transition hover:text-white"
                                >
                                    Ver detalhe
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">
                            Nenhum tenant suspenso recebeu pressão operacional recente no período.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Atividade administrativa recente</h2>
                            <p class="mt-1 text-sm text-slate-400">Eventos landlord mais recentes across tenants.</p>
                        </div>
                        <span class="rounded-full border border-slate-700 px-3 py-1 text-[11px] font-semibold text-slate-300">
                            {{ count($dashboard['recent_activity']) }} eventos
                        </span>
                    </div>

                    <div class="mt-4 space-y-3">
                        @forelse ($dashboard['recent_activity'] as $activity)
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-white">{{ $activity['label'] }}</p>
                                        <p class="mt-1 text-xs text-cyan-300">{{ $activity['tenant']['label'] }}</p>
                                    </div>
                                    <span class="rounded-full border border-slate-700 px-2 py-0.5 text-[11px] font-semibold text-slate-300">
                                        {{ $activity['occurred_at'] ?: 'agora' }}
                                    </span>
                                </div>
                                <p class="mt-3 text-xs leading-5 text-slate-400">{{ $activity['detail'] }}</p>
                                <p class="mt-2 text-xs text-slate-500">Operado por {{ $activity['actor']['label'] }}</p>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">
                                Nenhuma atividade administrativa recente foi registrada no landlord.
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Atenção prioritária</h2>
                            <p class="mt-1 text-sm text-slate-400">Fila curta para o operador atacar primeiro.</p>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        @forelse ($dashboard['attention_items'] as $item)
                            @php($attentionListQuery = $item['type'] === 'suspension_pressure' ? ['pressure' => 'suspended_recent'] : ['provisioning' => 'pending'])
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-white">{{ $item['tenant']['trade_name'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $item['label'] }}</p>
                                    </div>
                                </div>
                                <p class="mt-3 text-xs leading-5 text-slate-400">{{ $item['detail'] }}</p>
                                <div class="mt-4 flex items-center gap-3">
                                    <a
                                        href="{{ route('landlord.tenants.show', $item['tenant']['id']) }}"
                                        class="text-xs font-semibold text-cyan-300 transition hover:text-cyan-200"
                                    >
                                        Ver detalhe
                                    </a>
                                    <a
                                        href="{{ $indexUrl($attentionListQuery) }}"
                                        class="text-xs font-semibold text-slate-300 transition hover:text-white"
                                    >
                                        Abrir na listagem
                                    </a>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">
                                Nenhum item prioritário aberto no momento.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-4 shadow-xl shadow-slate-950/20">
            <div class="mb-4 flex flex-col gap-4 px-1 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white">Tenants</h2>
                    <p class="mt-1 text-sm text-slate-400">Listagem detalhada com status e provisionamento por tenant.</p>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950/60 px-4 py-3 text-sm text-slate-300">
                    {{ $tenants->total() }} tenant(s) na listagem atual
                </div>
            </div>

            <form method="GET" action="{{ route('landlord.tenants.index') }}" class="mb-4 rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                <div class="grid gap-3 xl:grid-cols-[repeat(4,minmax(0,1fr))_auto_auto]">
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</span>
                        <select name="status" class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none">
                            <option value="">Todos</option>
                            @foreach ($filterOptions['status'] as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['status'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Onboarding</span>
                        <select name="onboarding_stage" class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none">
                            <option value="">Todos</option>
                            @foreach ($filterOptions['onboarding_stage'] as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['onboarding_stage'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Provisionamento</span>
                        <select name="provisioning" class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none">
                            <option value="">Todos</option>
                            @foreach ($filterOptions['provisioning'] as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['provisioning'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Pressão</span>
                        <select name="pressure" class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none">
                            <option value="">Todas</option>
                            @foreach ($filterOptions['pressure'] as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['pressure'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-2xl border border-cyan-400 bg-cyan-400 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                    >
                        Aplicar filtros
                    </button>

                    <a
                        href="{{ route('landlord.tenants.index') }}"
                        class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-200 transition hover:border-slate-500 hover:bg-slate-900"
                    >
                        Limpar
                    </a>
                </div>

                @if ($hasActiveFilters)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if (($filters['status'] ?? '') !== '')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Status: {{ $filterOptions['status'][$filters['status']] ?? $filters['status'] }}
                            </span>
                        @endif
                        @if (($filters['onboarding_stage'] ?? '') !== '')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Onboarding: {{ $filterOptions['onboarding_stage'][$filters['onboarding_stage']] ?? $filters['onboarding_stage'] }}
                            </span>
                        @endif
                        @if (($filters['provisioning'] ?? '') !== '')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Provisionamento: {{ $filterOptions['provisioning'][$filters['provisioning']] ?? $filters['provisioning'] }}
                            </span>
                        @endif
                        @if (($filters['pressure'] ?? '') !== '')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Pressão: {{ $filterOptions['pressure'][$filters['pressure']] ?? $filters['pressure'] }}
                            </span>
                        @endif
                    </div>
                @endif
            </form>

            @if ($tenants->isEmpty())
                @if ($dashboard['headline']['total_tenants'] === 0)
                    <div class="rounded-2xl border border-dashed border-slate-700 px-6 py-12 text-center">
                        <h2 class="text-lg font-semibold text-white">Nenhum tenant cadastrado</h2>
                        <p class="mt-2 text-sm text-slate-300">Crie o primeiro tenant SaaS por esta interface.</p>
                        <a
                            href="{{ route('landlord.tenants.create') }}"
                            class="mt-5 inline-flex items-center justify-center rounded-2xl bg-cyan-400 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                        >
                            Criar tenant
                        </a>
                    </div>
                @else
                    <div class="rounded-2xl border border-dashed border-slate-700 px-6 py-12 text-center">
                        <h2 class="text-lg font-semibold text-white">Nenhum tenant encontrado</h2>
                        <p class="mt-2 text-sm text-slate-300">Os filtros atuais não retornaram tenants na listagem.</p>
                        <a
                            href="{{ route('landlord.tenants.index') }}"
                            class="mt-5 inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-800"
                        >
                            Limpar filtros
                        </a>
                    </div>
                @endif
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800 text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-400">
                                <th class="px-4 py-3 font-medium">Tenant</th>
                                <th class="px-4 py-3 font-medium">Domínio</th>
                                <th class="px-4 py-3 font-medium">Provisionamento</th>
                                <th class="px-4 py-3 font-medium">Owner</th>
                                <th class="px-4 py-3 font-medium">Criado em</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800 text-slate-100">
                            @foreach ($tenants as $tenant)
                                <tr class="align-top">
                                    <td class="px-4 py-4">
                                        <div class="space-y-1">
                                            <p class="font-semibold text-white">{{ $tenant['trade_name'] }}</p>
                                            <p class="text-xs text-slate-400">{{ $tenant['slug'] }}</p>
                                            <a
                                                href="{{ route('landlord.tenants.show', $tenant['id']) }}"
                                                class="inline-flex pt-1 text-xs font-semibold text-cyan-300 transition hover:text-cyan-200"
                                            >
                                                Ver detalhes
                                            </a>
                                            <div class="flex flex-wrap gap-2 pt-1">
                                                <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                                                    {{ $tenant['status']['label'] }}
                                                </span>
                                                <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                                                    {{ $tenant['onboarding_stage']['label'] }}
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-slate-300">
                                        <p>{{ $tenant['primary_domain'] ?: 'Sem domínio principal' }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            SSL {{ $tenant['ssl_status'] ? mb_strtoupper((string) $tenant['ssl_status']) : 'PENDENTE' }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="space-y-2">
                                            <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $tenant['provisioning']['code'] === 'provisioned' ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200' : 'border-amber-500/40 bg-amber-500/10 text-amber-200' }}">
                                                {{ $tenant['provisioning']['label'] }}
                                            </span>
                                            <p class="max-w-xs text-xs leading-5 text-slate-400">{{ $tenant['provisioning']['detail'] }}</p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-slate-300">
                                        <p>{{ $tenant['owner']['name'] ?: 'Sem owner ativo' }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $tenant['owner']['email'] ?: 'Não configurado' }}</p>
                                    </td>
                                    <td class="px-4 py-4 text-slate-300">
                                        {{ $tenant['created_at'] ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $tenants->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
