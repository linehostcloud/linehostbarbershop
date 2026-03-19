@extends('layouts.tenant-panel')

@section('title', 'Operacoes WhatsApp')

@section('content')
    <div
        data-whatsapp-operations-panel
        class="space-y-4"
    >
        <header class="rounded-3xl border border-stone-200 bg-white/92 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-1.5">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-amber-700">Painel Operacional</p>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Mensageria WhatsApp</h1>
                        <span class="rounded-full border border-stone-200 bg-stone-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                            {{ $tenant->trade_name }}
                        </span>
                    </div>
                    <p class="text-sm leading-6 text-slate-600">
                        Visibilidade consolidada do pipeline operacional do tenant, sem duplicar agregacoes no frontend.
                    </p>
                </div>

                <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                    <label class="flex min-w-32 flex-col gap-1 text-xs font-medium uppercase tracking-[0.18em] text-slate-500">
                        Janela
                        <select
                            data-control="window"
                            class="rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm font-medium normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                        >
                            <option value="24h" @selected(($boot['filters']['window'] ?? null) === '24h')>24h</option>
                            <option value="7d" @selected(($boot['filters']['window'] ?? null) === '7d')>7d</option>
                            <option value="30d" @selected(($boot['filters']['window'] ?? null) === '30d')>30d</option>
                        </select>
                    </label>

                    <button
                        type="button"
                        data-action="refresh-all"
                        class="inline-flex items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-stone-50"
                    >
                        Recarregar
                    </button>

                    <form method="POST" action="{{ route('tenant.panel.whatsapp.operations.logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
                        >
                            Sair
                        </button>
                    </form>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-stone-200 pt-3 text-xs text-slate-500">
                <span>Usuario: <span class="font-medium text-slate-700">{{ $user->name }}</span></span>
                <span class="hidden text-stone-300 sm:inline">•</span>
                <span>Papel: <span class="font-medium text-slate-700">{{ $membership->role }}</span></span>
                <span class="hidden text-stone-300 sm:inline">•</span>
                <span>Ultima atualizacao: <span data-last-updated class="font-medium text-slate-700">ainda nao carregado</span></span>
            </div>
        </header>

        <div data-global-error class="hidden rounded-3xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"></div>

        <section class="rounded-3xl border border-stone-200 bg-white/92 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Resumo Geral</h2>
                    <p class="mt-1 text-sm text-slate-600">Mensagens, outbox, tentativas e rejeicoes em leitura operacional compacta.</p>
                </div>
            </div>
            <div data-section="summary" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando resumo...</div>
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white/92 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Saude por Provider</h2>
                    <p class="mt-1 text-sm text-slate-600">Slots configurados, healthcheck, capacidade e taxa de sucesso/falha.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <div data-section="providers" class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando providers...</div>
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white/92 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Fila Operacional</h2>
                    <p class="mt-1 text-sm text-slate-600">Itens que exigem atencao com filtros alinhados aos parametros reais da API.</p>
                </div>

                <form data-form="queue-filters" class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="text-xs font-medium uppercase tracking-[0.18em] text-slate-500">
                        Provider
                        <select data-control="queue-provider" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                            <option value="">Todos</option>
                        </select>
                    </label>
                    <label class="text-xs font-medium uppercase tracking-[0.18em] text-slate-500">
                        Status
                        <select data-control="queue-status" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                            <option value="">Todos</option>
                            <option value="failed">failed</option>
                            <option value="retry_scheduled">retry_scheduled</option>
                        </select>
                    </label>
                    <label class="text-xs font-medium uppercase tracking-[0.18em] text-slate-500">
                        Codigo
                        <select data-control="queue-error-code" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                            <option value="">Todos</option>
                            <option value="provider_unavailable">provider_unavailable</option>
                            <option value="rate_limit">rate_limit</option>
                            <option value="unsupported_feature">unsupported_feature</option>
                            <option value="timeout_error">timeout_error</option>
                        </select>
                    </label>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="inline-flex h-[42px] flex-1 items-center justify-center rounded-2xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800">Aplicar</button>
                        <button type="button" data-action="queue-reset" class="inline-flex h-[42px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-stone-50">Limpar</button>
                    </div>
                </form>
            </div>

            <div class="overflow-x-auto">
                <div data-section="queue" class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando fila operacional...</div>
            </div>
            <div data-pagination="queue" class="mt-3"></div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[0.95fr_1.35fr]">
            <div class="rounded-3xl border border-stone-200 bg-white/92 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                <div class="mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Boundary Rejections</h2>
                    <p class="mt-1 text-sm text-slate-600">Resumo rapido por codigo sem exibir payload bruto.</p>
                </div>
                <div data-section="boundary-summary" class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando rejeicoes...</div>
            </div>

            <div class="rounded-3xl border border-stone-200 bg-white/92 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                <div class="mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Rejeicoes Recentes</h2>
                    <p class="mt-1 text-sm text-slate-600">Lista compacta paginada com endpoint, direcao, codigo e horario.</p>
                </div>
                <div class="overflow-x-auto">
                    <div data-section="boundary-list" class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando lista de rejeicoes...</div>
                </div>
                <div data-pagination="boundary" class="mt-3"></div>
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white/92 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Feed Recente</h2>
                <p class="mt-1 text-sm text-slate-600">Timeline consolidada com source explicito e contexto minimo por evento.</p>
            </div>
            <div data-section="feed" class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando feed...</div>
            <div data-pagination="feed" class="mt-3"></div>
        </section>
    </div>

    <script type="application/json" data-whatsapp-operations-boot>
        {!! json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}
    </script>
@endsection
