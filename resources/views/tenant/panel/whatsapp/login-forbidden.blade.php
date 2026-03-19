@extends('layouts.tenant-panel')

@section('title', 'Acesso Negado')

@section('content')
    <div class="mx-auto flex min-h-[80vh] max-w-xl items-center">
        <div class="w-full rounded-3xl border border-rose-200 bg-white/94 p-8 shadow-[0_20px_60px_-30px_rgba(15,23,42,0.35)] backdrop-blur">
            <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-rose-700">Acesso Negado</p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-950">Sem permissao para o painel operacional</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">
                O usuario autenticado nao possui a ability
                <span class="font-medium text-slate-900">whatsapp.operations.read</span>
                neste tenant.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a
                    href="{{ route('tenant.panel.whatsapp.operations.login') }}"
                    class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800"
                >
                    Voltar para o login
                </a>
            </div>
        </div>
    </div>
@endsection
