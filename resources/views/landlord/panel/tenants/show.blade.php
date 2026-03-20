@extends('layouts.landlord-panel')

@section('title', 'Detalhe do tenant')

@section('content')
    @php($tenantBasicsErrors = $errors->getBag('tenantBasics'))
    @php($tenantDomainErrors = $errors->getBag('tenantDomains'))

    <div class="mx-auto max-w-7xl space-y-6">
        <header class="flex flex-col gap-4 rounded-3xl border border-slate-800 bg-slate-900/80 p-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Painel SaaS</p>
                <h1 class="text-3xl font-semibold text-white">{{ $tenant['trade_name'] }}</h1>
                <p class="text-sm text-slate-300">
                    Acompanhamento operacional do tenant, com visibilidade de provisionamento e ações administrativas seguras.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <a
                    href="{{ route('landlord.tenants.index') }}"
                    class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-800"
                >
                    Voltar para tenants
                </a>
                <a
                    href="{{ route('landlord.tenants.create') }}"
                    class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-800"
                >
                    Criar tenant
                </a>
            </div>
        </header>

        @if (session('status'))
            @php($status = session('status'))
            <section class="rounded-3xl border p-5 text-sm {{ data_get($status, 'type') === 'error' ? 'border-rose-500/30 bg-rose-500/10 text-rose-50' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-50' }}">
                <p class="font-semibold">{{ data_get($status, 'message') }}</p>
            </section>
        @endif

        <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <section class="space-y-6">
                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-slate-950/20">
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                            {{ $tenant['status']['label'] }}
                        </span>
                        <span class="rounded-full border border-slate-700 px-2.5 py-1 text-[11px] font-semibold text-slate-200">
                            {{ $tenant['onboarding_stage']['label'] }}
                        </span>
                        <span class="rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $tenant['provisioning']['code'] === 'provisioned' ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200' : 'border-amber-500/40 bg-amber-500/10 text-amber-200' }}">
                            {{ $tenant['provisioning']['label'] }}
                        </span>
                    </div>

                    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase tracking-[0.18em] text-slate-500">Razão social</dt>
                            <dd class="mt-1 text-sm text-slate-100">{{ $tenant['legal_name'] ?: 'Não informada' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.18em] text-slate-500">Slug</dt>
                            <dd class="mt-1 text-sm text-slate-100">{{ $tenant['slug'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.18em] text-slate-500">Timezone</dt>
                            <dd class="mt-1 text-sm text-slate-100">{{ $tenant['timezone'] ?: 'Não configurado' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.18em] text-slate-500">Moeda</dt>
                            <dd class="mt-1 text-sm text-slate-100">{{ $tenant['currency'] ?: 'Não configurada' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.18em] text-slate-500">Plano</dt>
                            <dd class="mt-1 text-sm text-slate-100">{{ $tenant['plan_code'] ?: 'Não configurado' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-[0.18em] text-slate-500">Criado em</dt>
                            <dd class="mt-1 text-sm text-slate-100">{{ $tenant['created_at'] ?: '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-[0.18em] text-slate-500">Banco do tenant</dt>
                            <dd class="mt-1 text-sm text-slate-100">{{ $tenant['database_name'] ?: 'Não definido' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-slate-950/20">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Saúde operacional</h2>
                            <p class="mt-1 text-sm text-slate-400">{{ $tenant['provisioning']['detail'] }}</p>
                        </div>
                        <span class="rounded-full border border-slate-700 px-3 py-1 text-[11px] font-semibold text-slate-200">
                            {{ $tenant['operational']['summary']['ok_count'] }}/{{ $tenant['operational']['summary']['total_count'] }} itens OK
                        </span>
                    </div>

                    <div class="mt-5 grid gap-3 md:grid-cols-2">
                        @foreach ($tenant['operational']['checks'] as $check)
                            <div class="rounded-2xl border {{ $check['ok'] ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-amber-500/30 bg-amber-500/10' }} p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold {{ $check['ok'] ? 'text-emerald-100' : 'text-amber-100' }}">{{ $check['label'] }}</h3>
                                    <span class="rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $check['ok'] ? 'border-emerald-400/40 text-emerald-200' : 'border-amber-400/40 text-amber-200' }}">
                                        {{ $check['ok'] ? 'OK' : 'Pendente' }}
                                    </span>
                                </div>
                                <p class="mt-2 text-xs leading-5 {{ $check['ok'] ? 'text-emerald-50/90' : 'text-amber-50/90' }}">{{ $check['detail'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($tenant['operational']['schema_missing_tables'] !== [])
                        <div class="mt-4 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-100">
                            <p class="font-semibold">Tabelas mínimas ausentes</p>
                            <p class="mt-1">{{ implode(', ', $tenant['operational']['schema_missing_tables']) }}</p>
                        </div>
                    @endif
                </div>

                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-slate-950/20">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Atividade recente</h2>
                            <p class="mt-1 text-sm text-slate-400">
                                Últimos eventos administrativos registrados para este tenant.
                            </p>
                        </div>
                        <span class="rounded-full border border-slate-700 px-3 py-1 text-[11px] font-semibold text-slate-200">
                            {{ count($tenant['recent_activity']) }} recentes
                        </span>
                    </div>

                    <div class="mt-5 space-y-3">
                        @forelse ($tenant['recent_activity'] as $activity)
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-100">{{ $activity['label'] }}</p>
                                        <p class="mt-2 text-xs leading-5 text-slate-400">{{ $activity['detail'] }}</p>
                                    </div>
                                    <span class="rounded-full border border-slate-700 px-2 py-0.5 text-[11px] font-semibold text-slate-300">
                                        {{ $activity['occurred_at'] ?: 'agora' }}
                                    </span>
                                </div>
                                <p class="mt-3 text-xs text-slate-500">
                                    Operado por {{ $activity['actor']['label'] }}
                                    @if ($activity['actor']['email'])
                                        · {{ $activity['actor']['email'] }}
                                    @endif
                                </p>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4 text-sm text-slate-400">
                                Nenhuma atividade administrativa recente foi registrada para este tenant.
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-slate-950/20">
                    <div>
                        <h2 class="text-lg font-semibold text-white">Dados básicos</h2>
                        <p class="mt-1 text-sm text-slate-400">
                            Ajustes operacionais seguros do cadastro do tenant, sem alterar owner, slug ou provisionamento.
                        </p>
                    </div>

                    @if ($tenantBasicsErrors->any())
                        <div class="mt-5 rounded-2xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                            <p class="font-semibold">Não foi possível atualizar os dados básicos.</p>
                            <p class="mt-1">{{ $tenantBasicsErrors->first() }}</p>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('landlord.tenants.update-basics', $tenant['id']) }}" class="mt-5 space-y-5">
                        @csrf
                        @method('PATCH')

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block space-y-2">
                                <span class="text-sm font-medium text-slate-200">Nome fantasia</span>
                                <input
                                    type="text"
                                    name="trade_name"
                                    value="{{ old('trade_name', $tenant['trade_name']) }}"
                                    required
                                    class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                                >
                                @if ($tenantBasicsErrors->has('trade_name'))
                                    <p class="text-xs text-rose-300">{{ $tenantBasicsErrors->first('trade_name') }}</p>
                                @endif
                            </label>

                            <label class="block space-y-2">
                                <span class="text-sm font-medium text-slate-200">Razão social</span>
                                <input
                                    type="text"
                                    name="legal_name"
                                    value="{{ old('legal_name', $tenant['legal_name']) }}"
                                    placeholder="Se vazio, usa o nome fantasia"
                                    class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                                >
                                @if ($tenantBasicsErrors->has('legal_name'))
                                    <p class="text-xs text-rose-300">{{ $tenantBasicsErrors->first('legal_name') }}</p>
                                @endif
                            </label>

                            <label class="block space-y-2">
                                <span class="text-sm font-medium text-slate-200">Timezone</span>
                                <input
                                    type="text"
                                    name="timezone"
                                    value="{{ old('timezone', $tenant['timezone']) }}"
                                    required
                                    placeholder="America/Sao_Paulo"
                                    class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                                >
                                @if ($tenantBasicsErrors->has('timezone'))
                                    <p class="text-xs text-rose-300">{{ $tenantBasicsErrors->first('timezone') }}</p>
                                @endif
                            </label>

                            <label class="block space-y-2">
                                <span class="text-sm font-medium text-slate-200">Moeda</span>
                                <input
                                    type="text"
                                    name="currency"
                                    value="{{ old('currency', $tenant['currency']) }}"
                                    required
                                    maxlength="3"
                                    placeholder="BRL"
                                    class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm uppercase text-white outline-none transition focus:border-cyan-400"
                                >
                                @if ($tenantBasicsErrors->has('currency'))
                                    <p class="text-xs text-rose-300">{{ $tenantBasicsErrors->first('currency') }}</p>
                                @endif
                            </label>
                        </div>

                        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-4 text-xs leading-6 text-slate-300">
                            <p class="font-semibold text-white">Bloqueado nesta fase</p>
                            <p>Slug, plano, status, onboarding, owner e campos de provisionamento seguem somente leitura no painel.</p>
                        </div>

                        <div class="flex items-center justify-end">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-2xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                            >
                                Salvar dados básicos
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <aside class="space-y-6">
                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-slate-950/20">
                    <h2 class="text-lg font-semibold text-white">Ações administrativas seguras</h2>
                    <p class="mt-1 text-sm text-slate-400">
                        Ações não destrutivas que reaproveitam os fluxos reais já existentes de tenancy.
                    </p>

                    <div class="mt-5 space-y-3">
                        <form method="POST" action="{{ route('landlord.tenants.sync-schema', $tenant['id']) }}">
                            @csrf
                            <button
                                type="submit"
                                class="flex w-full items-center justify-between rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-left text-sm font-semibold text-slate-100 transition hover:border-cyan-500/60 hover:bg-slate-950"
                            >
                                <span>Sincronizar schema do tenant</span>
                                <span class="text-xs text-slate-400">Migrations + defaults</span>
                            </button>
                        </form>

                        <form method="POST" action="{{ route('landlord.tenants.ensure-default-automations', $tenant['id']) }}">
                            @csrf
                            <button
                                type="submit"
                                class="flex w-full items-center justify-between rounded-2xl border border-slate-700 bg-slate-950/80 px-4 py-3 text-left text-sm font-semibold text-slate-100 transition hover:border-cyan-500/60 hover:bg-slate-950"
                            >
                                <span>Garantir automações default</span>
                                <span class="text-xs text-slate-400">WhatsApp padrão</span>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-slate-950/20">
                    <h2 class="text-lg font-semibold text-white">Domínios</h2>
                    <p class="mt-1 text-sm text-slate-400">
                        Gestão básica de domínios com adição controlada e troca explícita do principal.
                    </p>

                    @if ($tenantDomainErrors->any())
                        <div class="mt-5 rounded-2xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                            <p class="font-semibold">Não foi possível atualizar os domínios.</p>
                            <p class="mt-1">{{ $tenantDomainErrors->first() }}</p>
                        </div>
                    @endif

                    <div class="mt-4 space-y-3">
                        @forelse ($tenant['domains'] as $domain)
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/60 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-100">{{ $domain['domain'] }}</p>
                                        <p class="mt-2 text-xs text-slate-400">Tipo {{ $domain['type'] }} · SSL {{ mb_strtoupper((string) $domain['ssl_status']) }}</p>
                                        <p class="mt-1 text-xs text-slate-500">Verificado em {{ $domain['verified_at'] ?: 'pendente' }}</p>
                                    </div>

                                    @if ($domain['is_primary'])
                                        <span class="rounded-full border border-cyan-400/40 bg-cyan-400/10 px-2 py-0.5 text-[11px] font-semibold text-cyan-200">Principal</span>
                                    @else
                                        <form method="POST" action="{{ route('landlord.tenants.domains.set-primary', [$tenant['id'], $domain['id']]) }}">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-100 transition hover:border-cyan-500/60 hover:bg-slate-900"
                                            >
                                                Tornar principal
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-100">
                                Nenhum domínio cadastrado para este tenant.
                            </div>
                        @endforelse
                    </div>

                    <form method="POST" action="{{ route('landlord.tenants.domains.store', $tenant['id']) }}" class="mt-5 space-y-4">
                        @csrf

                        <label class="block space-y-2">
                            <span class="text-sm font-medium text-slate-200">Novo domínio</span>
                            <input
                                type="text"
                                name="domain"
                                value="{{ old('domain') }}"
                                required
                                placeholder="agenda.exemplo.com"
                                class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                            >
                            @if ($tenantDomainErrors->has('domain'))
                                <p class="text-xs text-rose-300">{{ $tenantDomainErrors->first('domain') }}</p>
                            @endif
                        </label>

                        <label class="flex items-start gap-3 rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-4 text-sm text-slate-300">
                            <input
                                type="checkbox"
                                name="make_primary"
                                value="1"
                                @checked(old('make_primary'))
                                class="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-950 text-cyan-400 focus:ring-cyan-400"
                            >
                            <span>Definir como principal ao adicionar este domínio.</span>
                        </label>

                        <div class="rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-4 text-xs leading-6 text-slate-300">
                            <p class="font-semibold text-white">Regras desta fase</p>
                            <p>Novos domínios entram como tipo <span class="font-semibold text-slate-100">admin</span>, com SSL pendente e sem exclusão pelo painel.</p>
                        </div>

                        <div class="flex items-center justify-end">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-2xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                            >
                                Adicionar domínio
                            </button>
                        </div>
                    </form>
                </div>

                <div class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-slate-950/20">
                    <h2 class="text-lg font-semibold text-white">Owner principal</h2>
                    <p class="mt-4 text-sm text-slate-100">{{ $tenant['owner']['name'] ?: 'Sem owner ativo' }}</p>
                    <p class="mt-1 text-sm text-slate-400">{{ $tenant['owner']['email'] ?: 'Não configurado' }}</p>
                    <p class="mt-3 text-xs text-slate-500">Aceito em {{ $tenant['owner']['accepted_at'] ?: 'pendente' }}</p>
                </div>
            </aside>
        </div>
    </div>
@endsection
