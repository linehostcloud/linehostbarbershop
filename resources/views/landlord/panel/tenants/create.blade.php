@extends('layouts.landlord-panel')

@section('title', 'Criar tenant SaaS')

@section('content')
    <div class="w-full space-y-6">
        <header class="flex flex-col gap-4 rounded-3xl border border-slate-800 bg-slate-900/80 p-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Painel SaaS</p>
                <h1 class="text-3xl font-semibold text-white">Criar tenant</h1>
                <p class="text-sm text-slate-300">
                    Provisiona tenant, domínio principal, schema e owner inicial pela mesma rotina usada no CLI.
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
            </div>
        </header>

        <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-xl shadow-slate-950/20">
            @if ($errors->any())
                <div class="mb-6 rounded-2xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                    <p class="font-semibold">Não foi possível provisionar o tenant.</p>
                    <p class="mt-1">{{ $errors->first() }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('landlord.tenants.store') }}" class="space-y-6">
                @csrf

                <div class="grid gap-6 lg:grid-cols-2">
                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-200">Nome fantasia</span>
                        <input
                            type="text"
                            name="trade_name"
                            value="{{ old('trade_name') }}"
                            required
                            class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                        >
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-200">Slug</span>
                        <input
                            type="text"
                            name="slug"
                            value="{{ old('slug') }}"
                            required
                            placeholder="barbearia-centro"
                            class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                        >
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-200">Razão social</span>
                        <input
                            type="text"
                            name="legal_name"
                            value="{{ old('legal_name') }}"
                            placeholder="Se vazio, usa o nome fantasia"
                            class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                        >
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-200">Domínio principal</span>
                        <input
                            type="text"
                            name="domain"
                            value="{{ old('domain') }}"
                            placeholder="Se vazio, usa slug.{{ $defaults['domain_suffix'] }}"
                            class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                        >
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-200">Nome do owner inicial</span>
                        <input
                            type="text"
                            name="owner_name"
                            value="{{ old('owner_name') }}"
                            required
                            class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                        >
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm font-medium text-slate-200">E-mail do owner inicial</span>
                        <input
                            type="email"
                            name="owner_email"
                            value="{{ old('owner_email') }}"
                            required
                            class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                        >
                    </label>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-4 text-sm text-slate-300">
                    <p class="font-semibold text-white">Defaults desta fase</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                        <p><span class="font-semibold">Plano:</span> {{ $defaults['plan_code'] }}</p>
                        <p><span class="font-semibold">Timezone:</span> {{ $defaults['timezone'] }}</p>
                        <p><span class="font-semibold">Moeda:</span> {{ $defaults['currency'] }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <a
                        href="{{ route('landlord.tenants.index') }}"
                        class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-800"
                    >
                        Cancelar
                    </a>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-2xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                    >
                        Provisionar tenant
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
