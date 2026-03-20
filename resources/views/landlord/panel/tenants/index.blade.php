@extends('layouts.landlord-panel')

@section('title', 'Tenants SaaS')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        <header class="flex flex-col gap-4 rounded-3xl border border-slate-800 bg-slate-900/80 p-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Painel SaaS</p>
                <h1 class="text-3xl font-semibold text-white">Tenants</h1>
                <p class="text-sm text-slate-300">
                    Listagem central de tenants com status básico de provisionamento e criação.
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

        <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-4 shadow-xl shadow-slate-950/20">
            @if ($tenants->isEmpty())
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
