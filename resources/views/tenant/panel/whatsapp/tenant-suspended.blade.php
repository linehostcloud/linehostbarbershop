@extends('layouts.tenant-panel')

@section('title', 'Tenant Suspenso')

@section('content')
    <div class="mx-auto flex min-h-[80vh] max-w-xl items-center">
        <div class="w-full rounded-3xl border border-amber-200 bg-white/94 p-8 shadow-[0_20px_60px_-30px_rgba(15,23,42,0.35)] backdrop-blur">
            <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-amber-700">Operacao Bloqueada</p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-950">Tenant suspenso para operacao</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">
                O tenant
                <span class="font-medium text-slate-900">{{ $tenant?->trade_name ?? $tenant?->slug ?? 'Tenant' }}</span>
                esta suspenso no momento. O acesso ao painel e as operacoes do tenant permanecem bloqueados ate reativacao administrativa.
            </p>

            <p class="mt-4 text-xs leading-5 text-slate-500">
                Se esta suspensao for inesperada, valide o status no painel landlord antes de retomar a operacao.
            </p>
        </div>
    </div>
@endsection
