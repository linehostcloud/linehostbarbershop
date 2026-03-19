@extends('layouts.tenant-panel')

@section('title', 'Operacoes WhatsApp')

@section('content')
    @php
        $windows = (array) config('observability.whatsapp_operations.allowed_windows', ['24h', '7d', '30d']);
        $futureSignals = [
            ['label' => 'Deduplicacao', 'description' => 'duplicate risk, duplicate prevented e deduplication key agora expostos no feed e na fila.'],
            ['label' => 'Health Window', 'description' => 'janela de saude consolidada por provider, calculada no backend operacional.'],
            ['label' => 'Smart Routing', 'description' => 'motivo da decisao operacional e caminho escolhido antes do dispatch.'],
            ['label' => 'Decision Source', 'description' => 'primary_default, health_based_secondary, fallback_pinned ou manual_override.'],
        ];
    @endphp

    <div
        data-whatsapp-operations-panel
        class="space-y-4"
    >
        <header class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="space-y-2">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-amber-700">Painel Operacional</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight text-slate-950">Mensageria WhatsApp</h1>
                        <span class="rounded-full border border-stone-200 bg-stone-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                            {{ $tenant->trade_name }}
                        </span>
                    </div>
                    <p class="max-w-3xl text-sm leading-6 text-slate-600">
                        Operacao quase em tempo real para enxergar saude, retries, fallbacks, rejeicoes e itens que exigem acao humana.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[10rem_13rem_10rem_auto_auto]">
                    <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        Janela
                        <select
                            data-control="window"
                            class="rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm font-medium normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                        >
                            @foreach ($windows as $window)
                                <option value="{{ $window }}" @selected(($boot['filters']['window'] ?? null) === $window)>{{ $window }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="flex flex-col gap-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        Provider Global
                        <select
                            data-control="provider"
                            class="rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm font-medium normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100"
                        >
                            <option value="">Todos os providers</option>
                        </select>
                    </label>

                    <label class="flex items-center gap-2 rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm font-medium text-slate-700">
                        <input
                            type="checkbox"
                            data-control="auto-refresh"
                            @checked(($boot['filters']['auto_refresh'] ?? false) === true)
                            class="h-4 w-4 rounded border-stone-300 text-amber-600 focus:ring-amber-500"
                        >
                        <span>Auto 60s</span>
                    </label>

                    <button
                        type="button"
                        data-action="refresh-all"
                        class="inline-flex h-[42px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-800 transition hover:border-slate-400 hover:bg-stone-50"
                    >
                        Atualizar
                    </button>

                    <form method="POST" action="{{ route('tenant.panel.whatsapp.operations.logout') }}">
                        @csrf
                        <button
                            type="submit"
                            class="inline-flex h-[42px] w-full items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
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
                <span class="hidden text-stone-300 sm:inline">•</span>
                <span data-auto-refresh-state>Auto refresh desligado</span>
            </div>
        </header>

        <div data-global-error class="hidden rounded-3xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"></div>

        <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Resumo Operacional</h2>
                    <p class="mt-1 text-sm text-slate-600">Leitura imediata da janela observada, com foco em falhas, retries, fallback, rejeicoes e pendencias de fila.</p>
                </div>
            </div>
            <div data-section="summary" class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando indicadores operacionais...</div>
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3 flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Saude por Provider</h2>
                    <p class="mt-1 text-sm text-slate-600">Estado operacional, healthcheck, sinais de erro, retries e fallback por slot/provider, sem esconder degradacao real.</p>
                </div>
                <p class="max-w-md text-xs leading-5 text-slate-500">
                    Esta camada continua consumindo apenas os endpoints operacionais existentes. A evolucao para deduplicacao, health window e smart routing fica preparada, sem reimplementar regra no frontend.
                </p>
            </div>
            <div data-section="providers" class="grid gap-3 xl:grid-cols-2">
                <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando providers...</div>
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Camada Deterministica</h2>
                <p class="mt-1 text-sm text-slate-600">Sinais de deduplicacao, health e roteamento que ja saem do backend prontos para leitura operacional, sem heuristica escondida no frontend.</p>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($futureSignals as $signal)
                    <article class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $signal['label'] }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $signal['description'] }}</p>
                        <p class="mt-3 text-xs font-medium text-slate-500">Leitura alimentada pela API operacional consolidada.</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Exige Atencao Agora</h2>
                <p class="mt-1 text-sm text-slate-600">Recorte rapido dos itens mais graves e recentes para apoiar acao humana imediata.</p>
            </div>
            <div data-section="attention" class="grid gap-3 lg:grid-cols-2">
                <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando itens criticos...</div>
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Fila Operacional</h2>
                    <p class="mt-1 text-sm text-slate-600">Itens com falha, retry, fallback ou bloqueio manual, com filtros aderentes aos parametros reais da API operacional.</p>
                </div>

                <form data-form="queue-filters" class="grid gap-2 sm:grid-cols-2 xl:grid-cols-[12rem_14rem_auto]">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        Status
                        <select data-control="queue-status" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                            <option value="">Todos</option>
                            <option value="failed">failed</option>
                            <option value="retry_scheduled">retry_scheduled</option>
                            <option value="fallback_scheduled">fallback_scheduled</option>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        Codigo
                        <select data-control="queue-error-code" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                            <option value="">Todos</option>
                            <option value="provider_unavailable">provider_unavailable</option>
                            <option value="rate_limit">rate_limit</option>
                            <option value="unsupported_feature">unsupported_feature</option>
                            <option value="timeout_error">timeout_error</option>
                            <option value="transient_network_error">transient_network_error</option>
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

        <section class="grid gap-4 xl:grid-cols-[0.92fr_1.38fr]">
            <div class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                <div class="mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Boundary Rejections</h2>
                    <p class="mt-1 text-sm text-slate-600">Volume por codigo, direcao e endpoint, sempre com serializacao segura.</p>
                </div>
                <div data-section="boundary-summary" class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando rejeicoes...</div>
            </div>

            <div class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
                <div class="mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Rejeicoes Recentes</h2>
                    <p class="mt-1 text-sm text-slate-600">Consulta operacional curta, paginada e sem payload bruto.</p>
                </div>
                <div class="overflow-x-auto">
                    <div data-section="boundary-list" class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando lista de rejeicoes...</div>
                </div>
                <div data-pagination="boundary" class="mt-3"></div>
            </div>
        </section>

        <section class="rounded-3xl border border-stone-200 bg-white/95 px-5 py-4 shadow-[0_16px_44px_-28px_rgba(15,23,42,0.35)] backdrop-blur">
            <div class="mb-3 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Feed Operacional</h2>
                    <p class="mt-1 text-sm text-slate-600">Cronologia consolidada de retries, fallback, boundary, healthcheck e eventos relevantes do pipeline.</p>
                </div>

                <form data-form="feed-filters" class="grid gap-2 sm:grid-cols-2 xl:grid-cols-[12rem_14rem_auto]">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        Source
                        <select data-control="feed-source" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                            <option value="">Todos</option>
                            <option value="event_log">event_log</option>
                            <option value="integration_attempt">integration_attempt</option>
                            <option value="boundary_rejection_audit">boundary_rejection_audit</option>
                            <option value="admin_audit">admin_audit</option>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        Tipo
                        <select data-control="feed-type" class="mt-1 w-full rounded-2xl border border-stone-300 bg-stone-50 px-3 py-2 text-sm normal-case tracking-normal text-slate-900 outline-none transition focus:border-amber-500 focus:bg-white focus:ring-4 focus:ring-amber-100">
                            <option value="">Todos</option>
                            <option value="provider_fallback_scheduled">provider_fallback_scheduled</option>
                            <option value="provider_fallback_executed">provider_fallback_executed</option>
                            <option value="duplicate_prevented">duplicate_prevented</option>
                            <option value="duplicate_risk_detected">duplicate_risk_detected</option>
                            <option value="terminal_failure">terminal_failure</option>
                            <option value="boundary_rejection">boundary_rejection</option>
                            <option value="manual_review_required">manual_review_required</option>
                            <option value="outbox_reclaimed">outbox_reclaimed</option>
                            <option value="provider_healthcheck">provider_healthcheck</option>
                            <option value="provider_config_activated">provider_config_activated</option>
                            <option value="provider_config_deactivated">provider_config_deactivated</option>
                        </select>
                    </label>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="inline-flex h-[42px] flex-1 items-center justify-center rounded-2xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800">Aplicar</button>
                        <button type="button" data-action="feed-reset" class="inline-flex h-[42px] items-center justify-center rounded-2xl border border-stone-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-stone-50">Limpar</button>
                    </div>
                </form>
            </div>

            <div data-section="feed" class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm text-slate-500">Carregando feed...</div>
            <div data-pagination="feed" class="mt-3"></div>
        </section>
    </div>

    <script type="application/json" data-whatsapp-operations-boot>
        {!! json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}
    </script>
@endsection
