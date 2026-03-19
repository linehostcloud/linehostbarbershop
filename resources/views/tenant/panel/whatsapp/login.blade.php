@extends('layouts.tenant-panel')

@php($tenant = $tenant ?? app(\App\Infrastructure\Tenancy\TenantContext::class)->current())

@section('title', 'Painel Operacional WhatsApp')

@section('content')
    <div class="mx-auto flex min-h-[80vh] max-w-md items-center">
        <div class="w-full rounded-3xl border border-stone-200 bg-white/92 p-7 shadow-[0_20px_60px_-30px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-6 space-y-2">
                <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-amber-700">Painel Operacional</p>
                <h1 class="text-2xl font-semibold text-slate-950">Mensageria WhatsApp</h1>
                <p class="text-sm leading-6 text-slate-600">
                    Acesse o painel do tenant
                    <span class="font-medium text-slate-900">{{ $tenant?->trade_name ?? 'Tenant' }}</span>
                    com o mesmo login tenant da API.
                </p>
            </div>

            @if ($errors->any())
                <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('tenant.panel.whatsapp.operations.login.submit') }}" class="space-y-4">
                @csrf

                <div class="space-y-1.5">
                    <label for="email" class="text-sm font-medium text-slate-700">E-mail</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        autocomplete="username"
                        value="{{ old('email') }}"
                        required
                        class="w-full rounded-2xl border border-stone-300 bg-stone-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                    >
                </div>

                <div class="space-y-1.5">
                    <label for="password" class="text-sm font-medium text-slate-700">Senha</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-2xl border border-stone-300 bg-stone-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                    >
                </div>

                <button
                    type="submit"
                    class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-200"
                >
                    Entrar no painel
                </button>
            </form>

            <p class="mt-5 text-xs leading-5 text-slate-500">
                Esta interface consome apenas os endpoints operacionais existentes. Nenhum payload sensível é exibido na camada visual.
            </p>
        </div>
    </div>
@endsection
