@extends('layouts.landlord-panel')

@section('title', 'Snapshots dos tenants')

@php
    use Illuminate\Support\Str;
@endphp

@section('content')
    @php
        $batchSelectedIds = collect(old('selected_ids', []))
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        $dashboardQuery = static fn (array $overrides = []): array => array_filter(
            array_merge($filters ?? [], ['page' => null], $overrides),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
        $dashboardUrl = static fn (array $overrides = []): string => route('landlord.tenants.snapshots', $dashboardQuery($overrides));
        $isSnapshotFilterActive = static fn (string $value): bool => ($filters['snapshot_status'] ?? '') === $value;
        $snapshotToneClasses = static fn (string $tone): string => match ($tone) {
            'emerald' => 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200',
            'sky' => 'border-sky-500/40 bg-sky-500/10 text-sky-200',
            'amber' => 'border-amber-500/40 bg-amber-500/10 text-amber-200',
            default => 'border-rose-500/40 bg-rose-500/10 text-rose-200',
        };
        $priorityToneClasses = static fn (string $tone): string => match ($tone) {
            'emerald' => 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200',
            'sky' => 'border-sky-500/40 bg-sky-500/10 text-sky-200',
            default => 'border-amber-500/40 bg-amber-500/10 text-amber-200',
        };
    @endphp

    <div class="w-full space-y-6">
        <header class="flex flex-col gap-4 rounded-3xl border border-slate-800 bg-slate-900/80 p-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Painel SaaS</p>
                <h1 class="text-3xl font-semibold text-white">Saúde dos snapshots</h1>
                <p class="text-sm text-slate-300">
                    Monitoramento landlord rápido para stale, missing, failed, refreshing e fallback conservador.
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
            @php($statusType = data_get($status, 'type'))
            <section class="rounded-3xl border p-5 text-sm {{ $statusType === 'error' ? 'border-rose-500/30 bg-rose-500/10 text-rose-50' : ($statusType === 'warning' ? 'border-amber-500/30 bg-amber-500/10 text-amber-50' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-50') }}">
                <p class="font-semibold">{{ data_get($status, 'message') }}</p>

                @if (data_get($status, 'batch.id'))
                    <p class="mt-2 text-xs {{ $statusType === 'error' ? 'text-rose-100' : ($statusType === 'warning' ? 'text-amber-100' : 'text-emerald-100') }}">
                        Lote {{ data_get($status, 'batch.id') }}. Escopo: {{ data_get($status, 'batch.mode_label') }}.
                    </p>
                @endif

                @if (is_array(data_get($status, 'summary')))
                    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <p><span class="font-semibold">Correspondentes:</span> {{ data_get($status, 'summary.matched_count') }}</p>
                        <p><span class="font-semibold">Elegíveis:</span> {{ data_get($status, 'summary.eligible_count') }}</p>
                        <p><span class="font-semibold">Enfileirados:</span> {{ data_get($status, 'summary.dispatched_count') }}</p>
                        <p><span class="font-semibold">Ignorados por lock:</span> {{ data_get($status, 'summary.skipped_locked_count') }}</p>
                        <p><span class="font-semibold">Ignorados por refreshing:</span> {{ data_get($status, 'summary.skipped_refreshing_count') }}</p>
                        <p><span class="font-semibold">Ignorados por saudáveis:</span> {{ data_get($status, 'summary.skipped_healthy_count') }}</p>
                        <p><span class="font-semibold">Ignorados por cooldown:</span> {{ data_get($status, 'summary.skipped_cooldown_count') }}</p>
                        <p><span class="font-semibold">Falhas no disparo:</span> {{ data_get($status, 'summary.dispatch_failed_count') }}</p>
                    </div>
                @endif
            </section>
        @endif

        @if ($errors->any())
            <section class="rounded-3xl border border-rose-500/30 bg-rose-500/10 p-5 text-sm text-rose-50">
                <p class="font-semibold">Não foi possível disparar o refresh em lote.</p>
                <ul class="mt-3 space-y-1 text-rose-100">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <a
                href="{{ $dashboardUrl(['snapshot_status' => null]) }}"
                class="group rounded-3xl border bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition {{ ($filters['snapshot_status'] ?? '') === '' ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700 hover:bg-slate-900' }}"
            >
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Tenants monitorados</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $headline['total_monitored'] }}</p>
                <p class="mt-2 text-sm text-slate-400">Escopo atual do monitoramento landlord rápido.</p>
                <p class="mt-4 text-xs font-semibold {{ ($filters['snapshot_status'] ?? '') === '' ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Abrir listagem completa</p>
            </a>

            <a
                href="{{ $dashboardUrl(['snapshot_status' => 'healthy']) }}"
                class="group rounded-3xl border bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition {{ $isSnapshotFilterActive('healthy') ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700 hover:bg-slate-900' }}"
            >
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Healthy</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $headline['healthy_count'] }}</p>
                <p class="mt-2 text-sm text-slate-400">Snapshot pronto e sem fallback conservador.</p>
                <p class="mt-4 text-xs font-semibold {{ $isSnapshotFilterActive('healthy') ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Filtrar healthy</p>
            </a>

            <a
                href="{{ $dashboardUrl(['snapshot_status' => 'stale']) }}"
                class="group rounded-3xl border bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition {{ $isSnapshotFilterActive('stale') ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700 hover:bg-slate-900' }}"
            >
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Stale</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $headline['stale_count'] }}</p>
                <p class="mt-2 text-sm text-slate-400">Captura antiga aguardando refresh controlado.</p>
                <p class="mt-4 text-xs font-semibold {{ $isSnapshotFilterActive('stale') ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Filtrar stale</p>
            </a>

            <a
                href="{{ $dashboardUrl(['snapshot_status' => 'missing']) }}"
                class="group rounded-3xl border bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition {{ $isSnapshotFilterActive('missing') ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700 hover:bg-slate-900' }}"
            >
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Missing</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $headline['missing_count'] }}</p>
                <p class="mt-2 text-sm text-slate-400">Tenant sem snapshot utilizável persistido.</p>
                <p class="mt-4 text-xs font-semibold {{ $isSnapshotFilterActive('missing') ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Filtrar missing</p>
            </a>

            <a
                href="{{ $dashboardUrl(['snapshot_status' => 'failed']) }}"
                class="group rounded-3xl border bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition {{ $isSnapshotFilterActive('failed') ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700 hover:bg-slate-900' }}"
            >
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Failed</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $headline['failed_count'] }}</p>
                <p class="mt-2 text-sm text-slate-400">Falha recente de refresh registrada no snapshot.</p>
                <p class="mt-4 text-xs font-semibold {{ $isSnapshotFilterActive('failed') ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Filtrar failed</p>
            </a>

            <a
                href="{{ $dashboardUrl(['snapshot_status' => 'refreshing']) }}"
                class="group rounded-3xl border bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition {{ $isSnapshotFilterActive('refreshing') ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700 hover:bg-slate-900' }}"
            >
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Refreshing</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $headline['refreshing_count'] }}</p>
                <p class="mt-2 text-sm text-slate-400">Refresh em andamento com lock operacional.</p>
                <p class="mt-4 text-xs font-semibold {{ $isSnapshotFilterActive('refreshing') ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Filtrar refreshing</p>
            </a>

            <a
                href="{{ $dashboardUrl(['snapshot_status' => 'fallback']) }}"
                class="group rounded-3xl border bg-slate-900/80 p-5 shadow-xl shadow-slate-950/20 transition {{ $isSnapshotFilterActive('fallback') ? 'border-cyan-400/60 ring-1 ring-cyan-400/30' : 'border-slate-800 hover:border-slate-700 hover:bg-slate-900' }}"
            >
                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Fallback conservador</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $headline['fallback_count'] }}</p>
                <p class="mt-2 text-sm text-slate-400">Detalhe do tenant ainda depende de fallback leve.</p>
                <p class="mt-4 text-xs font-semibold {{ $isSnapshotFilterActive('fallback') ? 'text-cyan-200' : 'text-cyan-300 transition group-hover:text-cyan-200' }}">Filtrar fallback</p>
            </a>
        </section>

        <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-4 shadow-xl shadow-slate-950/20">
            <div class="mb-4 flex flex-col gap-4 px-1 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-white">Listagem operacional dos snapshots</h2>
                    <p class="mt-1 text-sm text-slate-400">Ordene por prioridade, idade ou tenant e ataque primeiro o que está mais frágil.</p>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-950/60 px-4 py-3 text-sm text-slate-300">
                    {{ $tenants->total() }} tenant(s) na listagem atual
                </div>
            </div>

            <form method="GET" action="{{ route('landlord.tenants.snapshots') }}" class="mb-4 rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                <div class="grid gap-3 xl:grid-cols-[1.3fr_repeat(4,minmax(0,1fr))_auto_auto]">
                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Busca</span>
                        <input
                            type="text"
                            name="search"
                            value="{{ $filters['search'] ?? '' }}"
                            placeholder="Nome fantasia ou slug"
                            class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none"
                        >
                    </label>

                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Snapshot</span>
                        <select name="snapshot_status" class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none">
                            <option value="">Todos</option>
                            @foreach ($filterOptions['snapshot_status'] as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['snapshot_status'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status do tenant</span>
                        <select name="tenant_status" class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none">
                            <option value="">Todos</option>
                            @foreach ($filterOptions['tenant_status'] as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['tenant_status'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Ordenar por</span>
                        <select name="sort" class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none">
                            @foreach ($filterOptions['sort'] as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['sort'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-2 text-sm text-slate-300">
                        <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Direção</span>
                        <select name="direction" class="w-full rounded-2xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-sm text-slate-100 focus:border-cyan-400 focus:outline-none">
                            @foreach ($filterOptions['direction'] as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['direction'] ?? '') === $code)>{{ $label }}</option>
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
                        href="{{ route('landlord.tenants.snapshots') }}"
                        class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-200 transition hover:border-slate-500 hover:bg-slate-900"
                    >
                        Limpar
                    </a>
                </div>

                @if ($hasActiveFilters)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if (($filters['search'] ?? '') !== '')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Busca: {{ $filters['search'] }}
                            </span>
                        @endif
                        @if (($filters['snapshot_status'] ?? '') !== '')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Snapshot: {{ $filterOptions['snapshot_status'][$filters['snapshot_status']] ?? $filters['snapshot_status'] }}
                            </span>
                        @endif
                        @if (($filters['tenant_status'] ?? '') !== '')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Status do tenant: {{ $filterOptions['tenant_status'][$filters['tenant_status']] ?? $filters['tenant_status'] }}
                            </span>
                        @endif
                        @if (($filters['sort'] ?? 'priority') !== 'priority')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Ordenação: {{ $filterOptions['sort'][$filters['sort']] ?? $filters['sort'] }}
                            </span>
                        @endif
                        @if (($filters['direction'] ?? 'desc') !== 'desc')
                            <span class="rounded-full border border-cyan-500/40 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold text-cyan-100">
                                Direção: {{ $filterOptions['direction'][$filters['direction']] ?? $filters['direction'] }}
                            </span>
                        @endif
                    </div>
                @endif
            </form>

            @if ($tenants->isEmpty())
                @if ($headline['total_monitored'] === 0)
                    <div class="rounded-2xl border border-dashed border-slate-700 px-6 py-12 text-center">
                        <h2 class="text-lg font-semibold text-white">Nenhum tenant monitorado</h2>
                        <p class="mt-2 text-sm text-slate-300">Ainda não existem tenants landlord neste escopo.</p>
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
                        <p class="mt-2 text-sm text-slate-300">Os filtros atuais não retornaram tenants para esta fila operacional.</p>
                        <a
                            href="{{ route('landlord.tenants.snapshots') }}"
                            class="mt-5 inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-800"
                        >
                            Limpar filtros
                        </a>
                    </div>
                @endif
            @else
                <form method="POST" action="{{ route('landlord.tenants.snapshots.queue-refresh') }}" class="space-y-4">
                    @csrf

                    <input type="hidden" name="search" value="{{ $filters['search'] ?? '' }}">
                    <input type="hidden" name="snapshot_status" value="{{ $filters['snapshot_status'] ?? '' }}">
                    <input type="hidden" name="tenant_status" value="{{ $filters['tenant_status'] ?? '' }}">
                    <input type="hidden" name="sort" value="{{ $filters['sort'] ?? '' }}">
                    <input type="hidden" name="direction" value="{{ $filters['direction'] ?? '' }}">

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Ações em lote</p>
                                <p class="mt-2 text-sm text-slate-300">
                                    O dashboard apenas enfileira refreshes. A inspeção pesada continua fora da request, com lock por tenant e cooldown operacional.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" name="mode" value="selected" class="inline-flex items-center justify-center rounded-2xl border border-cyan-400 bg-cyan-400 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                                    Atualizar selecionados
                                </button>
                                <button type="submit" name="mode" value="filtered" class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-900">
                                    Atualizar filtro atual
                                </button>
                                <button type="submit" name="mode" value="critical" class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-900">
                                    Só críticos
                                </button>
                                <button type="submit" name="mode" value="missing" class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-900">
                                    Só missing
                                </button>
                                <button type="submit" name="mode" value="stale" class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-900">
                                    Só stale
                                </button>
                                <button type="submit" name="mode" value="failed" class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-900">
                                    Só failed
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-800 text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-400">
                                    <th class="px-4 py-3 font-medium">Selecionar</th>
                                    <th class="px-4 py-3 font-medium">Tenant</th>
                                    <th class="px-4 py-3 font-medium">Snapshot</th>
                                    <th class="px-4 py-3 font-medium">Última falha</th>
                                    <th class="px-4 py-3 font-medium">Prioridade</th>
                                    <th class="px-4 py-3 font-medium">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800 text-slate-100">
                                @foreach ($tenants as $tenant)
                                    <tr class="align-top">
                                        <td class="px-4 py-4">
                                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-300">
                                                <input
                                                    type="checkbox"
                                                    name="selected_ids[]"
                                                    value="{{ $tenant['id'] }}"
                                                    @checked(in_array($tenant['id'], $batchSelectedIds, true))
                                                    class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-cyan-400 focus:ring-cyan-400"
                                                >
                                                <span>Selecionar</span>
                                            </label>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="space-y-1">
                                                <p class="font-semibold text-white">{{ $tenant['tenant']['trade_name'] }}</p>
                                                <p class="text-xs text-slate-400">{{ $tenant['tenant']['slug'] }}</p>
                                                <div class="flex flex-wrap gap-2 pt-1">
                                                    <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                                                        {{ $tenant['status']['label'] }}
                                                    </span>
                                                    @if ($tenant['fallback_conservative'])
                                                        <span class="rounded-full border border-rose-500/40 bg-rose-500/10 px-2.5 py-1 text-[11px] font-semibold text-rose-200">
                                                            Fallback
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="space-y-2">
                                                <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $snapshotToneClasses($tenant['snapshot_status']['tone']) }}">
                                                    {{ $tenant['snapshot_status']['label'] }}
                                                </span>
                                                <p class="max-w-xs text-xs leading-5 text-slate-400">{{ $tenant['snapshot_status']['detail'] }}</p>
                                                <p class="text-xs text-slate-500">
                                                    Última geração: {{ $tenant['snapshot_generated_at'] ?: 'não gerada' }}.
                                                    @if ($tenant['snapshot_age_label'])
                                                        Idade aproximada: {{ $tenant['snapshot_age_label'] }}.
                                                    @endif
                                                </p>
                                                @if ($tenant['snapshot_is_stale'])
                                                    <p class="text-xs font-semibold text-amber-300">Snapshot stale.</p>
                                                @endif
                                                @if ($tenant['refresh_in_progress'])
                                                    <p class="text-xs font-semibold text-sky-300">
                                                        Refresh em andamento desde {{ $tenant['refresh_started_at'] ?: 'agora' }}.
                                                    </p>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-slate-300">
                                            @if ($tenant['last_failure']['at'] || $tenant['last_failure']['error'])
                                                <div class="space-y-2">
                                                    <p>{{ $tenant['last_failure']['at'] ?: 'Falha sem horário registrado' }}</p>
                                                    <p class="max-w-xs text-xs leading-5 text-slate-500">{{ $tenant['last_failure']['error'] ?: 'Sem mensagem de erro persistida.' }}</p>
                                                </div>
                                            @else
                                                <p class="text-slate-500">Sem falha recente registrada.</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="space-y-2">
                                                <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $priorityToneClasses($tenant['priority']['tone']) }}">
                                                    {{ $tenant['priority']['label'] }}
                                                </span>
                                                <p class="max-w-xs text-xs leading-5 text-slate-400">{{ $tenant['priority']['detail'] }}</p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4">
                                            <a
                                                href="{{ route('landlord.tenants.show', $tenant['id']) }}"
                                                class="inline-flex text-xs font-semibold text-cyan-300 transition hover:text-cyan-200"
                                            >
                                                Ver detalhe
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>

                <div class="mt-4">
                    {{ $tenants->links() }}
                </div>
            @endif
        </section>
        @if (isset($batchHistory) && $batchHistory->isNotEmpty())
            <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-4 shadow-xl shadow-slate-950/20">
                <div class="mb-4 px-1">
                    <h2 class="text-lg font-semibold text-white">Histórico de execuções em lote</h2>
                    <p class="mt-1 text-sm text-slate-400">Últimas {{ $batchHistory->count() }} execuções de refresh em lote com rastreamento de resultado por job.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-800 text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-400">
                                <th class="px-4 py-3 font-medium">Lote</th>
                                <th class="px-4 py-3 font-medium">Modo</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Progresso</th>
                                <th class="px-4 py-3 font-medium">Duração</th>
                                <th class="px-4 py-3 font-medium">Operador</th>
                                <th class="px-4 py-3 font-medium">Início</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800 text-slate-100">
                            @foreach ($batchHistory as $batch)
                                @php
                                    $batchToneClasses = match ($batch['status_tone']) {
                                        'emerald' => 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200',
                                        'sky' => 'border-sky-500/40 bg-sky-500/10 text-sky-200',
                                        'amber' => 'border-amber-500/40 bg-amber-500/10 text-amber-200',
                                        default => 'border-rose-500/40 bg-rose-500/10 text-rose-200',
                                    };
                                    $progressBarColor = match (true) {
                                        $batch['is_stuck'] => 'bg-rose-400',
                                        $batch['status'] === 'completed' => 'bg-emerald-400',
                                        $batch['status'] === 'failed' => 'bg-rose-400',
                                        $batch['status'] === 'partial' => 'bg-amber-400',
                                        default => 'bg-sky-400',
                                    };
                                @endphp
                                <tr class="align-top {{ $batch['is_stuck'] ? 'bg-rose-500/5' : '' }}">
                                    <td class="px-4 py-4">
                                        <p class="font-mono text-xs text-slate-400" title="{{ $batch['id'] }}">{{ Str::limit($batch['id'], 12, '…') }}</p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                                            {{ $batch['type_label'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $batchToneClasses }}">
                                            {{ $batch['status_label'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="space-y-2">
                                            <div class="flex items-center gap-2">
                                                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-700">
                                                    <div class="h-full rounded-full {{ $progressBarColor }}" style="width: {{ $batch['progress_percentage'] }}%"></div>
                                                </div>
                                                <span class="text-xs font-semibold {{ $batch['is_stuck'] ? 'text-rose-300' : 'text-slate-300' }}">{{ $batch['progress_percentage'] }}%</span>
                                            </div>
                                            <div class="space-y-0.5 text-xs">
                                                <p><span class="text-emerald-300">{{ $batch['total_succeeded'] }}</span> ok</p>
                                                @if ($batch['total_failed'] > 0)
                                                    <p><span class="text-rose-300">{{ $batch['total_failed'] }}</span> falha(s)</p>
                                                @endif
                                                @if ($batch['total_skipped'] > 0)
                                                    <p><span class="text-amber-300">{{ $batch['total_skipped'] }}</span> ignorado(s)</p>
                                                @endif
                                                <p class="text-slate-500">{{ $batch['total_queued'] }} enfileirado(s)</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-xs text-slate-300">
                                        {{ $batch['duration_label'] ?? 'Em andamento' }}
                                    </td>
                                    <td class="px-4 py-4">
                                        <p class="text-xs text-slate-300">{{ $batch['actor_name'] }}</p>
                                        <p class="text-[11px] text-slate-500">{{ $batch['actor_email'] }}</p>
                                    </td>
                                    <td class="px-4 py-4 text-xs text-slate-400">
                                        {{ $batch['started_at'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
