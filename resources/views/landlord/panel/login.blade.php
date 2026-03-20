@extends('layouts.landlord-panel')

@section('title', 'Painel SaaS')

@section('content')
    <div class="mx-auto flex min-h-[calc(100vh-3rem)] max-w-md items-center">
        <div class="w-full rounded-3xl border border-slate-800 bg-slate-900/85 p-8 shadow-2xl shadow-slate-950/30">
            <div class="space-y-2">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Painel landlord</p>
                <h1 class="text-3xl font-semibold text-white">Acesso do operador SaaS</h1>
                <p class="text-sm text-slate-300">
                    Use um usuário global autorizado para gerenciar tenants e provisionamento.
                </p>
            </div>

            @if ($errors->any())
                <div class="mt-6 rounded-2xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('landlord.login.store') }}" class="mt-6 space-y-4">
                @csrf

                <label class="block space-y-2">
                    <span class="text-sm font-medium text-slate-200">E-mail</span>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                    >
                </label>

                <label class="block space-y-2">
                    <span class="text-sm font-medium text-slate-200">Senha</span>
                    <input
                        type="password"
                        name="password"
                        required
                        class="w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-400"
                    >
                </label>

                <button
                    type="submit"
                    class="inline-flex w-full items-center justify-center rounded-2xl bg-cyan-400 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                >
                    Entrar no painel SaaS
                </button>
            </form>
        </div>
    </div>
@endsection
