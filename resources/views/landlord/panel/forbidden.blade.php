@extends('layouts.landlord-panel')

@section('title', 'Acesso negado')

@section('content')
    <div class="mx-auto flex min-h-[calc(100vh-3rem)] max-w-lg items-center">
        <div class="w-full rounded-3xl border border-rose-500/30 bg-slate-900/90 p-8 shadow-2xl shadow-slate-950/30">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-rose-300">Acesso negado</p>
            <h1 class="mt-3 text-3xl font-semibold text-white">Seu usuário não tem acesso ao painel SaaS</h1>
            <p class="mt-3 text-sm text-slate-300">
                Use um usuário landlord autorizado ou revise a configuração de administradores do painel.
            </p>

            <form method="POST" action="{{ route('landlord.logout') }}" class="mt-6">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center justify-center rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-800"
                >
                    Voltar ao login
                </button>
            </form>
        </div>
    </div>
@endsection
