const root = document.querySelector('[data-whatsapp-operations-panel]');

if (root) {
    const bootNode = document.querySelector('[data-whatsapp-operations-boot]');

    if (bootNode) {
        bootstrapWhatsappOperationsPanel(root, bootNode);
    }
}

function bootstrapWhatsappOperationsPanel(rootElement, bootNode) {
    const boot = JSON.parse(bootNode.textContent || '{}');
    const state = {
        window: boot.filters?.window || '24h',
        provider: boot.filters?.provider || '',
        queueStatus: boot.filters?.queue_status || '',
        queueErrorCode: boot.filters?.queue_error_code || '',
        feedType: boot.filters?.feed_type || '',
        feedSource: boot.filters?.feed_source || '',
        queuePage: normalizePositiveInteger(boot.filters?.queue_page, 1),
        boundaryPage: normalizePositiveInteger(boot.filters?.boundary_page, 1),
        feedPage: normalizePositiveInteger(boot.filters?.feed_page, 1),
        autoRefresh: Boolean(boot.filters?.auto_refresh),
        providerOptions: [],
        autoRefreshTimer: null,
    };

    const elements = {
        globalError: rootElement.querySelector('[data-global-error]'),
        lastUpdated: rootElement.querySelector('[data-last-updated]'),
        autoRefreshState: rootElement.querySelector('[data-auto-refresh-state]'),
        summary: rootElement.querySelector('[data-section="summary"]'),
        schedulerRuns: rootElement.querySelector('[data-section="scheduler-runs"]'),
        agent: rootElement.querySelector('[data-section="agent"]'),
        providers: rootElement.querySelector('[data-section="providers"]'),
        attention: rootElement.querySelector('[data-section="attention"]'),
        queue: rootElement.querySelector('[data-section="queue"]'),
        boundarySummary: rootElement.querySelector('[data-section="boundary-summary"]'),
        boundaryList: rootElement.querySelector('[data-section="boundary-list"]'),
        feed: rootElement.querySelector('[data-section="feed"]'),
        queuePagination: rootElement.querySelector('[data-pagination="queue"]'),
        boundaryPagination: rootElement.querySelector('[data-pagination="boundary"]'),
        feedPagination: rootElement.querySelector('[data-pagination="feed"]'),
        window: rootElement.querySelector('[data-control="window"]'),
        provider: rootElement.querySelector('[data-control="provider"]'),
        autoRefresh: rootElement.querySelector('[data-control="auto-refresh"]'),
        queueStatus: rootElement.querySelector('[data-control="queue-status"]'),
        queueErrorCode: rootElement.querySelector('[data-control="queue-error-code"]'),
        queueForm: rootElement.querySelector('[data-form="queue-filters"]'),
        feedSource: rootElement.querySelector('[data-control="feed-source"]'),
        feedType: rootElement.querySelector('[data-control="feed-type"]'),
        feedForm: rootElement.querySelector('[data-form="feed-filters"]'),
    };

    syncControls();
    bindEvents();
    configureAutoRefresh();
    loadAllSections();

    window.addEventListener('beforeunload', () => {
        stopAutoRefresh();
    });

    function bindEvents() {
        elements.window?.addEventListener('change', (event) => {
            state.window = event.target.value || '24h';
            resetPaginatedSections();
            syncUrl();
            loadAllSections();
        });

        elements.provider?.addEventListener('change', (event) => {
            state.provider = event.target.value || '';
            resetPaginatedSections();
            syncUrl();
            loadAllSections();
        });

        elements.autoRefresh?.addEventListener('change', (event) => {
            state.autoRefresh = Boolean(event.target.checked);
            syncUrl();
            configureAutoRefresh();
        });

        elements.queueForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            state.queueStatus = elements.queueStatus?.value || '';
            state.queueErrorCode = elements.queueErrorCode?.value || '';
            state.queuePage = 1;
            syncUrl();
            loadQueue();
        });

        elements.feedForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            state.feedSource = elements.feedSource?.value || '';
            state.feedType = elements.feedType?.value || '';
            state.feedPage = 1;
            syncUrl();
            loadFeed();
        });

        rootElement.addEventListener('click', (event) => {
            const actionElement = event.target.closest('[data-action]');

            if (!actionElement) {
                return;
            }

            const action = actionElement.getAttribute('data-action');

            if (action === 'refresh-all') {
                loadAllSections();
                return;
            }

            if (action === 'queue-reset') {
                state.queueStatus = '';
                state.queueErrorCode = '';
                state.queuePage = 1;
                syncControls();
                syncUrl();
                loadQueue();
                return;
            }

            if (action === 'feed-reset') {
                state.feedSource = '';
                state.feedType = '';
                state.feedPage = 1;
                syncControls();
                syncUrl();
                loadFeed();
                return;
            }

            if (action === 'retry-section') {
                const section = actionElement.getAttribute('data-section-target');

                if (section === 'summary') {
                    loadSummary();
                } else if (section === 'agent') {
                    loadAgent();
                } else if (section === 'providers') {
                    loadProviders();
                } else if (section === 'attention') {
                    loadAttention();
                } else if (section === 'queue') {
                    loadQueue();
                } else if (section === 'boundary') {
                    loadBoundary();
                } else if (section === 'feed') {
                    loadFeed();
                }

                return;
            }

            if (action === 'paginate') {
                const target = actionElement.getAttribute('data-pagination-target');
                const page = normalizePositiveInteger(actionElement.getAttribute('data-page'), 1);

                if (target === 'queue') {
                    state.queuePage = page;
                    syncUrl();
                    loadQueue();
                } else if (target === 'boundary') {
                    state.boundaryPage = page;
                    syncUrl();
                    loadBoundaryList();
                } else if (target === 'feed') {
                    state.feedPage = page;
                    syncUrl();
                    loadFeed();
                }
            }
        });
    }

    function resetPaginatedSections() {
        state.queuePage = 1;
        state.boundaryPage = 1;
        state.feedPage = 1;
    }

    function syncControls() {
        if (elements.window) {
            elements.window.value = state.window;
        }

        renderProviderOptions();

        if (elements.autoRefresh) {
            elements.autoRefresh.checked = state.autoRefresh;
        }

        if (elements.queueStatus) {
            elements.queueStatus.value = state.queueStatus;
        }

        if (elements.queueErrorCode) {
            elements.queueErrorCode.value = state.queueErrorCode;
        }

        if (elements.feedSource) {
            elements.feedSource.value = state.feedSource;
        }

        if (elements.feedType) {
            elements.feedType.value = state.feedType;
        }
    }

    function syncUrl() {
        const url = new URL(window.location.href);

        applyUrlParam(url, 'window', state.window, '24h');
        applyUrlParam(url, 'provider', state.provider);
        applyUrlParam(url, 'queue_status', state.queueStatus);
        applyUrlParam(url, 'queue_error_code', state.queueErrorCode);
        applyUrlParam(url, 'feed_source', state.feedSource);
        applyUrlParam(url, 'feed_type', state.feedType);
        applyUrlParam(url, 'queue_page', state.queuePage, 1);
        applyUrlParam(url, 'boundary_page', state.boundaryPage, 1);
        applyUrlParam(url, 'feed_page', state.feedPage, 1);
        applyUrlParam(url, 'auto_refresh', state.autoRefresh ? '1' : '');
        url.searchParams.delete('queue_provider');

        window.history.replaceState({}, '', `${url.pathname}${url.search}`);
    }

    function configureAutoRefresh() {
        stopAutoRefresh();

        if (state.autoRefresh) {
            state.autoRefreshTimer = window.setInterval(() => {
                loadAllSections();
            }, 60000);
        }

        updateAutoRefreshState();
    }

    function stopAutoRefresh() {
        if (state.autoRefreshTimer !== null) {
            window.clearInterval(state.autoRefreshTimer);
            state.autoRefreshTimer = null;
        }
    }

    async function loadAllSections() {
        clearGlobalError();

        const results = await Promise.allSettled([
            loadSummary(),
            loadAgent(),
            loadProviders(),
            loadAttention(),
            loadQueue(),
            loadBoundary(),
            loadFeed(),
        ]);

        if (results.some((result) => result.status === 'fulfilled')) {
            updateLastUpdated();
        }

        if (results.every((result) => result.status === 'rejected')) {
            showGlobalError('Não foi possível carregar os dados operacionais desta tela. Revise a sessão do tenant e tente atualizar manualmente.');
        }
    }

    async function loadSummary() {
        renderLoading(elements.summary, 'Carregando indicadores operacionais...');
        renderLoading(elements.schedulerRuns, 'Carregando estado do scheduler...');

        try {
            const response = await getJson(boot.urls.summary, compactParams({
                window: state.window,
                provider: state.provider || undefined,
            }));

            renderSummary(response.data);
            return response;
        } catch (error) {
            renderSectionError(elements.summary, friendlyError(error, 'Não foi possível carregar o resumo operacional.'), 'summary');
            renderSectionError(elements.schedulerRuns, friendlyError(error, 'Não foi possível carregar o estado do scheduler.'), 'summary');
            throw error;
        }
    }

    async function loadAgent() {
        renderLoading(elements.agent, 'Carregando insights do agente operacional...');

        try {
            const response = await getJson(boot.urls.agent, compactParams({
                window: state.window,
                provider: state.provider || undefined,
                per_page: 4,
            }));

            renderAgent(response.data);
            return response;
        } catch (error) {
            renderSectionError(elements.agent, friendlyError(error, 'Não foi possível carregar os insights do agente operacional.'), 'agent');
            throw error;
        }
    }

    async function loadProviders() {
        renderLoading(elements.providers, 'Carregando saúde dos providers...');

        try {
            const response = await getJson(boot.urls.providers, compactParams({
                window: state.window,
            }));
            const items = Array.isArray(response.data) ? response.data : [];

            state.providerOptions = items
                .map((item) => item.provider)
                .filter(Boolean)
                .filter(uniqueOnly)
                .sort((left, right) => String(left).localeCompare(String(right)));

            syncControls();
            renderProviders(items);
            return response;
        } catch (error) {
            renderSectionError(elements.providers, friendlyError(error, 'Não foi possível carregar a saúde dos providers.'), 'providers');
            throw error;
        }
    }

    async function loadAttention() {
        renderLoading(elements.attention, 'Carregando itens que exigem atenção...');

        try {
            const response = await getJson(boot.urls.queue, compactParams({
                window: state.window,
                provider: state.provider || undefined,
                page: 1,
                per_page: 4,
            }));

            renderAttention(response.data || []);
            return response;
        } catch (error) {
            renderSectionError(elements.attention, friendlyError(error, 'Não foi possível carregar o recorte de atenção imediata.'), 'attention');
            throw error;
        }
    }

    async function loadQueue() {
        renderLoading(elements.queue, 'Carregando fila operacional...');

        if (elements.queuePagination) {
            elements.queuePagination.innerHTML = '';
        }

        try {
            const response = await getJson(boot.urls.queue, queueParams());

            renderQueue(response.data || []);
            renderPagination(elements.queuePagination, 'queue', response.meta);
            return response;
        } catch (error) {
            renderSectionError(elements.queue, friendlyError(error, 'Não foi possível carregar a fila operacional.'), 'queue');
            throw error;
        }
    }

    async function loadBoundary() {
        const results = await Promise.allSettled([
            loadBoundarySummary(),
            loadBoundaryList(),
        ]);

        if (results.every((result) => result.status === 'rejected')) {
            throw new Error('boundary_failed');
        }

        return results;
    }

    async function loadBoundarySummary() {
        renderLoading(elements.boundarySummary, 'Carregando resumo de rejeições de boundary...');

        try {
            const response = await getJson(boot.urls.boundary_summary, compactParams({
                window: state.window,
                provider: state.provider || undefined,
            }));

            renderBoundarySummary(response.data);
            return response;
        } catch (error) {
            renderSectionError(elements.boundarySummary, friendlyError(error, 'Não foi possível carregar o resumo de rejeições de boundary.'), 'boundary');
            throw error;
        }
    }

    async function loadBoundaryList() {
        renderLoading(elements.boundaryList, 'Carregando rejeições recentes...');

        if (elements.boundaryPagination) {
            elements.boundaryPagination.innerHTML = '';
        }

        try {
            const response = await getJson(boot.urls.boundary_rejections, compactParams({
                window: state.window,
                provider: state.provider || undefined,
                page: state.boundaryPage,
                per_page: 6,
            }));

            renderBoundaryList(response.data || []);
            renderPagination(elements.boundaryPagination, 'boundary', response.meta);
            return response;
        } catch (error) {
            renderSectionError(elements.boundaryList, friendlyError(error, 'Não foi possível carregar a lista de rejeições.'), 'boundary');
            throw error;
        }
    }

    async function loadFeed() {
        renderLoading(elements.feed, 'Carregando feed operacional...');

        if (elements.feedPagination) {
            elements.feedPagination.innerHTML = '';
        }

        try {
            const response = await getJson(boot.urls.feed, compactParams({
                window: state.window,
                provider: state.provider || undefined,
                source: state.feedSource || undefined,
                type: state.feedType || undefined,
                page: state.feedPage,
                per_page: 10,
            }));

            renderFeed(response.data || []);
            renderPagination(elements.feedPagination, 'feed', response.meta);
            return response;
        } catch (error) {
            renderSectionError(elements.feed, friendlyError(error, 'Não foi possível carregar o feed operacional.'), 'feed');
            throw error;
        }
    }

    function queueParams() {
        return compactParams({
            window: state.window,
            provider: state.provider || undefined,
            status: state.queueStatus || undefined,
            error_code: state.queueErrorCode || undefined,
            page: state.queuePage,
            per_page: 8,
        });
    }

    async function getJson(url, params = {}) {
        try {
            const response = await window.axios.get(url, {
                params,
                headers: {
                    Accept: 'application/json',
                },
            });

            return response.data;
        } catch (error) {
            if (error?.response?.status === 401 || error?.response?.status === 403) {
                showGlobalError('A sessão do painel expirou ou não possui mais permissão para esta operação. Atualize a página ou faça login novamente.');
            }

            throw error;
        }
    }

    function renderSummary(payload) {
        const cards = payload?.operational_cards || {};
        const sections = [
            {
                title: 'Mensagens recentes',
                value: cards.messages_recent_total || 0,
                tone: 'slate',
                caption: 'Mensagens WhatsApp observadas na janela.',
                rows: summarizeRows(payload?.messages?.status_totals || [], 'status', 4),
            },
            {
                title: 'Tentativas recentes',
                value: cards.attempts_recent_total || 0,
                tone: 'slate',
                caption: 'Tentativas de envio e integração no período.',
                rows: summarizeRows(payload?.integration_attempts?.status_totals || [], 'status', 4),
            },
            {
                title: 'Falhas operacionais',
                value: cards.operational_failures_total || 0,
                tone: Number(cards.operational_failures_total || 0) > 0 ? 'rose' : 'emerald',
                caption: `${formatPercent(cards.operational_failure_rate)} da janela monitorada.`,
                rows: [],
            },
            {
                title: 'Retries agendados',
                value: cards.retry_scheduled_total || 0,
                tone: Number(cards.retry_scheduled_total || 0) > 0 ? 'amber' : 'emerald',
                caption: 'Tentativas que seguiram para nova rodada controlada.',
                rows: [],
            },
            {
                title: 'Fallbacks agendados',
                value: cards.fallback_scheduled_total || 0,
                tone: Number(cards.fallback_scheduled_total || 0) > 0 ? 'amber' : 'emerald',
                caption: 'Troca controlada para provider secundário.',
                rows: [],
            },
            {
                title: 'Fallbacks executados',
                value: cards.fallback_executed_total || 0,
                tone: Number(cards.fallback_executed_total || 0) > 0 ? 'amber' : 'emerald',
                caption: 'Execuções efetivas com secundário na janela.',
                rows: [],
            },
            {
                title: 'Duplicados bloqueados',
                value: cards.duplicate_prevented_total || 0,
                tone: Number(cards.duplicate_prevented_total || 0) > 0 ? 'slate' : 'emerald',
                caption: 'Deduplicação bloqueou reenvios lógicos com sucesso conhecido.',
                rows: [],
            },
            {
                title: 'Risco de duplicidade',
                value: cards.duplicate_risk_total || 0,
                tone: Number(cards.duplicate_risk_total || 0) > 0 ? 'amber' : 'emerald',
                caption: 'Timeout ou erro transiente com risco real de duplo envio.',
                rows: [],
            },
            {
                title: 'Automações executadas',
                value: cards.automation_runs_total || 0,
                tone: Number(cards.automation_runs_total || 0) > 0 ? 'slate' : 'stone',
                caption: 'Execuções de automação processadas na janela.',
                rows: summarizeRows(payload?.automations?.type_totals || [], 'type', 3),
            },
            {
                title: 'Mensagens por automação',
                value: cards.automation_messages_queued_total || 0,
                tone: Number(cards.automation_messages_queued_total || 0) > 0 ? 'slate' : 'stone',
                caption: 'Mensagens enfileiradas pelo motor de automação.',
                rows: [],
            },
            {
                title: 'Skips de automação',
                value: cards.automation_skipped_total || 0,
                tone: Number(cards.automation_skipped_total || 0) > 0 ? 'amber' : 'emerald',
                caption: Number(cards.automation_failed_total || 0) > 0
                    ? `${cards.automation_failed_total || 0} falhas de automação exigem revisão.`
                    : 'Execuções puladas por cooldown, elegibilidade ou contato.',
                rows: summarizeRows(payload?.automations?.skip_reason_totals || [], 'reason', 3),
            },
            {
                title: 'Insights ativos do agente',
                value: cards.agent_active_insights_total || 0,
                tone: Number(cards.agent_high_severity_total || 0) > 0 ? 'rose' : (Number(cards.agent_active_insights_total || 0) > 0 ? 'amber' : 'emerald'),
                caption: Number(cards.agent_high_severity_total || 0) > 0
                    ? `${cards.agent_high_severity_total || 0} alertas de alta severidade aguardam revisão.`
                    : 'Estado atual do agente operacional prudente.',
                rows: [],
            },
            {
                title: 'Alertas altos do agente',
                value: cards.agent_high_severity_total || 0,
                tone: Number(cards.agent_high_severity_total || 0) > 0 ? 'rose' : 'emerald',
                caption: 'Insights de alta severidade ativos no momento.',
                rows: [],
            },
            {
                title: 'Boundary rejections',
                value: cards.boundary_rejections_total || 0,
                tone: Number(cards.boundary_rejections_total || 0) > 0 ? 'rose' : 'emerald',
                caption: 'Rejeições de boundary em endpoint, payload ou assinatura.',
                rows: summarizeRows(payload?.boundary_rejections?.code_totals || [], 'code', 3),
            },
            {
                title: 'Fila pendente',
                value: cards.pending_queue_total || 0,
                tone: Number(cards.pending_queue_total || 0) > 0 ? 'amber' : 'emerald',
                caption: 'Outbox pendente, em processamento ou aguardando retry.',
                rows: summarizeRows(filterRows(payload?.outbox_events?.status_totals || [], ['pending', 'processing', 'retry_scheduled']), 'status', 3),
            },
        ];

        elements.summary.innerHTML = sections.map((section) => `
            <article class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">${e(section.title)}</p>
                        <p class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">${e(String(section.value))}</p>
                    </div>
                    ${badge(state.window, 'stone')}
                </div>
                <p class="mt-3 text-sm text-slate-600">${e(section.caption)}</p>
                <div class="mt-4 flex flex-wrap gap-1.5">
                    ${section.rows.length > 0 ? section.rows.map((row) => badge(`${row.label} · ${row.total}`, section.tone)).join('') : '<span class="text-xs text-slate-500">Sem recorte adicional.</span>'}
                </div>
            </article>
        `).join('');

        renderSchedulerRuns(payload?.scheduler_runs || {});
    }

    function renderSchedulerRuns(runs) {
        if (!elements.schedulerRuns) {
            return;
        }

        const items = [
            schedulerRunTile('Automações', runs?.automations),
            schedulerRunTile('Agente', runs?.agent),
            schedulerRunTile('Housekeeping', runs?.housekeeping),
        ];

        elements.schedulerRuns.innerHTML = items.join('');
    }

    function renderAgent(payload) {
        const summary = payload?.summary || {};
        const latestRun = payload?.latest_run || null;
        const insights = Array.isArray(payload?.insights) ? payload.insights : [];

        const summaryTiles = [
            metricTile(
                'Insights Ativos',
                summary.active_total || 0,
                'Recomendações e alertas ainda abertos.',
                Number(summary.high_severity_total || 0) > 0 ? 'amber' : 'emerald',
            ),
            metricTile(
                'Alta Severidade',
                summary.high_severity_total || 0,
                'Alertas que merecem triagem humana rápida.',
                Number(summary.high_severity_total || 0) > 0 ? 'rose' : 'emerald',
            ),
            metricTile(
                'Último Run',
                latestRun?.status || 'sem run',
                latestRun?.completed_at
                    ? `Concluído em ${formatDateTime(latestRun.completed_at)}`
                    : 'Sem execução recente registrada.',
                latestRun?.status === 'failed' ? 'rose' : 'slate',
            ),
        ];

        elements.agent.innerHTML = `
            <div class="space-y-4">
                <div class="grid gap-3 lg:grid-cols-3">
                    ${summaryTiles.join('')}
                </div>

                <div>
                    <div class="mb-2 flex flex-wrap gap-1.5">
                        ${(summary.type_totals || []).length > 0
                            ? summary.type_totals.map((item) => badge(`${item.type} · ${item.total}`, item.total > 0 ? 'stone' : 'emerald')).join('')
                            : '<span class="text-sm text-slate-500">Sem distribuição de insights ativa no momento.</span>'}
                    </div>
                </div>

                <div class="space-y-3">
                    ${insights.length > 0
                        ? insights.map((item) => `
                            <article class="rounded-2xl border ${item.severity === 'high' ? 'border-rose-200 bg-rose-50/40' : 'border-stone-200 bg-white'} px-4 py-3">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap gap-1.5">
                                            ${badge(item.type || 'insight', typeTone(item.type))}
                                            ${severityBadge(item.severity)}
                                            ${badge(item.status || 'active', statusTone(item.status))}
                                            ${item.provider ? badge(item.provider, 'slate') : ''}
                                            ${item.slot ? badge(item.slot, 'stone') : ''}
                                            ${item.execution_mode ? badge(item.execution_mode, item.execution_mode === 'manual_safe_action' ? 'emerald' : 'stone') : ''}
                                        </div>
                                        <p class="mt-3 text-sm font-medium leading-6 text-slate-950">${e(item.title || 'Insight operacional')}</p>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">${e(item.summary || 'Sem resumo adicional.')}</p>
                                        <div class="mt-3 flex flex-wrap gap-1.5">
                                            ${item.target_label ? badge(item.target_label, 'stone') : ''}
                                            ${item.suggested_action ? badge(`ação ${item.suggested_action}`, item.execution_mode === 'manual_safe_action' ? 'emerald' : 'amber') : ''}
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">
                                            ${agentEvidenceLine(item)}
                                        </p>
                                    </div>
                                    <div class="shrink-0 text-xs font-medium text-slate-500">${formatDateTime(item.last_detected_at || item.first_detected_at)}</div>
                                </div>
                            </article>
                        `).join('')
                        : '<div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-5 text-sm text-slate-500">Nenhum insight recente do agente nesta janela.</div>'}
                </div>
            </div>
        `;
    }

    function renderProviders(items) {
        const filteredItems = filterProviderItems(items, state.provider);

        if (!Array.isArray(filteredItems) || filteredItems.length === 0) {
            renderEmpty(elements.providers, state.provider
                ? 'Nenhuma configuração operacional encontrada para o provider filtrado.'
                : 'Nenhum provider configurado para esta operação.');
            return;
        }

        elements.providers.innerHTML = filteredItems.map((item) => {
            const operationalState = item.operational_state || {
                label: 'unknown',
                tone: 'stone',
                reason: 'Sem sinais suficientes para classificar o provider.',
            };

            return `
                <article class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold text-slate-950">${e(item.provider || 'n/d')}</h3>
                                ${badge(item.slot || 'sem slot', 'slate')}
                                ${badge(item.enabled ? 'habilitado' : 'desabilitado', item.enabled ? 'emerald' : 'stone')}
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-600">${e(operationalState.reason || 'Sem explicação operacional disponível.')}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            ${badge(providerStateLabel(operationalState.label || 'unknown'), stateTone(operationalState.tone))}
                            ${renderInlineHealthBadge(item.last_healthcheck)}
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        ${metricTile('Sucessos', item.success_attempts || 0, `${formatPercent(item.success_rate)} de sucesso`, 'emerald')}
                        ${metricTile('Falhas', item.failure_attempts || 0, `${formatPercent(item.failure_rate)} de falha`, Number(item.failure_attempts || 0) > 0 ? 'rose' : 'stone')}
                        ${metricTile('Retries', item.retry_scheduled_total || 0, 'Retries recentes na janela', Number(item.retry_scheduled_total || 0) > 0 ? 'amber' : 'stone')}
                        ${metricTile('Fallbacks', Number(item.fallback_scheduled_total || 0) + Number(item.fallback_executed_total || 0), `${item.fallback_scheduled_total || 0} agendados · ${item.fallback_executed_total || 0} executados`, Number(item.fallback_scheduled_total || 0) + Number(item.fallback_executed_total || 0) > 0 ? 'amber' : 'stone')}
                        ${metricTile('Volume', item.send_attempts_total || 0, 'Tentativas totais do provider', 'slate')}
                    </div>

                    <div class="mt-4 grid gap-3 xl:grid-cols-[1.06fr_0.94fr]">
                        <div class="space-y-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Capabilities</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    ${(item.enabled_capabilities || []).length > 0
                                        ? item.enabled_capabilities.map((capability) => badge(capability, 'stone')).join('')
                                        : '<span class="text-sm text-slate-500">Sem capabilities habilitadas.</span>'}
                                </div>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Sinais Operacionais</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    ${(item.signal_totals || []).length > 0
                                        ? item.signal_totals.map((signal) => badge(`${signal.code} · ${signal.total}`, errorTone(signal.code))).join('')
                                        : '<span class="text-sm text-slate-500">Nenhum sinal crítico agregado.</span>'}
                                </div>
                            </div>

                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Principais Erros</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    ${(item.top_error_codes || []).length > 0
                                        ? item.top_error_codes.map((entry) => badge(`${entry.code} · ${entry.total}`, errorTone(entry.code))).join('')
                                        : '<span class="text-sm text-slate-500">Sem erros relevantes na janela.</span>'}
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-stone-200 bg-white px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Últimos Sinais</p>
                            <dl class="mt-3 space-y-2 text-sm">
                                ${providerDetailRow('Janela de saúde', item.health_window?.label || state.window)}
                                ${providerDetailRow('Healthcheck', healthcheckText(item.last_healthcheck))}
                                ${providerDetailRow('Última atividade', formatDateTime(item.last_activity_at))}
                                ${providerDetailRow('Última validação', formatDateTime(item.last_validated_at))}
                                ${providerDetailRow('Atualizado em', formatDateTime(item.updated_at))}
                            </dl>
                        </div>
                    </div>
                </article>
            `;
        }).join('');
    }

    function schedulerRunTile(label, run) {
        if (!run) {
            return `
                <article class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">${e(label)}</p>
                    <p class="mt-2 text-base font-semibold text-slate-950">Sem execução registrada</p>
                    <p class="mt-2 text-sm text-slate-600">Ainda não houve evento recente de scheduler para esta rotina.</p>
                </article>
            `;
        }

        const tone = run.status === 'failed' ? 'rose' : (run.status === 'running' ? 'amber' : (run.skipped_due_to_lock ? 'stone' : 'emerald'));
        const caption = run.error_message
            ? run.error_message
            : (run.skipped_due_to_lock
                ? 'Execução ignorada porque outra rotina equivalente já estava ativa.'
                : (run.completed_at
                    ? `Última finalização em ${formatDateTime(run.completed_at)}`
                    : `Iniciado em ${formatDateTime(run.started_at || run.occurred_at)}`));

        const chips = [
            badge(run.status || 'unknown', tone),
        ];

        if (run.duration_ms) {
            chips.push(badge(`${formatDuration(run.duration_ms)}`, 'stone'));
        }

        if (run.lock_key && run.skipped_due_to_lock) {
            chips.push(badge('lock ativo', 'stone'));
        }

        return `
            <article class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">${e(label)}</p>
                        <p class="mt-2 text-lg font-semibold text-slate-950">${e(statusLabel(run.status || 'unknown'))}</p>
                    </div>
                    <div class="flex flex-wrap justify-end gap-1.5">
                        ${chips.join('')}
                    </div>
                </div>
                <p class="mt-3 text-sm leading-6 text-slate-600">${e(caption)}</p>
                <p class="mt-2 text-xs text-slate-500">Run ${e(run.scheduler_run_id || 'n/d')} · ${e(formatDateTime(run.occurred_at || run.started_at))}</p>
            </article>
        `;
    }

    function renderAttention(items) {
        if (!Array.isArray(items) || items.length === 0) {
            renderEmpty(elements.attention, 'Nenhum item critico apareceu na janela atual para este recorte.');
            return;
        }

        const attentionItems = [...items]
            .sort((left, right) => compareAttentionItems(left, right))
            .slice(0, 4);

        elements.attention.innerHTML = attentionItems.map((item) => `
            <article class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        ${badge(attentionLabel(item.attention_type), attentionTone(item))}
                        ${item.status ? badge(item.status, statusTone(item.status)) : ''}
                        ${item.error_code ? badge(item.error_code, errorTone(item.error_code)) : ''}
                    </div>
                    <div class="text-xs font-medium text-slate-500">${formatDateTime(item.occurred_at)}</div>
                </div>

                <p class="mt-3 text-sm font-medium leading-6 text-slate-950">${e(item.summary || 'Sem resumo operacional.')}</p>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    ${item.provider ? badge(item.provider, 'slate') : ''}
                    ${item.slot ? badge(item.slot, 'stone') : ''}
                    ${item.decision_source ? badge(`rota ${item.decision_source}`, 'stone') : ''}
                    ${renderFallbackPill(item.fallback)}
                </div>

                <div class="mt-3 space-y-1 text-xs leading-5 text-slate-500">
                    <div>${referenceSummary(item)}</div>
                    ${queueOperationalNote(item) ? `<div>${e(queueOperationalNote(item))}</div>` : ''}
                </div>
            </article>
        `).join('');
    }

    function renderQueue(items) {
        if (!Array.isArray(items) || items.length === 0) {
            renderEmpty(elements.queue, 'Nenhum item exige atenção com os filtros atuais.');
            return;
        }

        elements.queue.innerHTML = `
            <table class="min-w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                    <tr class="text-[11px] uppercase tracking-[0.18em] text-slate-500">
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Atenção</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Provider</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Estado Atual</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Tentativa</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Fallback</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Referencia</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Resumo</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Horário</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr class="align-top ${queueRowClass(item)}">
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    ${badge(attentionLabel(item.attention_type), attentionTone(item))}
                                    ${severityBadge(item.severity)}
                                </div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="font-medium text-slate-900">${e(item.provider || 'n/d')}</div>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    ${item.slot ? badge(item.slot, 'stone') : ''}
                                    ${item.decision_source ? badge(`rota ${item.decision_source}`, 'stone') : ''}
                                </div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    ${item.status ? badge(item.status, statusTone(item.status)) : '<span class="text-slate-400">—</span>'}
                                    ${item.error_code ? badge(item.error_code, errorTone(item.error_code)) : ''}
                                </div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-700">${e(queueAttemptLabel(item))}</td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-700">${renderFallbackCell(item.fallback)}</td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-700">${referenceCell(item)}</td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="max-w-md text-slate-700">${e(item.summary || 'Sem resumo operacional.')}</div>
                                ${queueOperationalNote(item) ? `<div class="mt-1 text-xs text-slate-500">${e(queueOperationalNote(item))}</div>` : ''}
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-600">${formatDateTime(item.occurred_at)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    function renderBoundarySummary(payload) {
        const codeTotals = payload?.code_totals || [];
        const directionTotals = payload?.direction_totals || [];
        const endpointTotals = payload?.endpoint_totals || [];
        const latest = payload?.latest || [];

        elements.boundarySummary.innerHTML = `
            <div class="space-y-4">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Total</p>
                        <p class="text-3xl font-semibold tracking-tight text-slate-950">${e(String(payload?.total || 0))}</p>
                    </div>
                    ${badge(payload?.window?.label || state.window, 'stone')}
                </div>

                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Codigos principais</p>
                    <div class="flex flex-wrap gap-1.5">
                        ${codeTotals.length > 0
                            ? codeTotals.slice(0, 8).map((row) => badge(`${row.code} · ${row.total}`, errorTone(row.code))).join('')
                            : '<span class="text-sm text-slate-500">Nenhuma rejeição no período.</span>'}
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Direção</p>
                        <ul class="space-y-1.5">
                            ${directionTotals.length > 0
                                ? directionTotals.map((row) => `<li class="flex justify-between gap-3 text-sm"><span class="text-slate-600">${e(row.direction)}</span><span class="font-semibold text-slate-900">${e(String(row.total))}</span></li>`).join('')
                                : '<li class="text-sm text-slate-500">Sem distribuição.</li>'}
                        </ul>
                    </div>
                    <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Endpoints</p>
                        <ul class="space-y-1.5">
                            ${endpointTotals.length > 0
                                ? endpointTotals.slice(0, 4).map((row) => `<li class="flex justify-between gap-3 text-sm"><span class="truncate text-slate-600">${e(row.endpoint)}</span><span class="font-semibold text-slate-900">${e(String(row.total))}</span></li>`).join('')
                                : '<li class="text-sm text-slate-500">Sem endpoints relevantes.</li>'}
                        </ul>
                    </div>
                </div>

                <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Últimas rejeições</p>
                    <ul class="space-y-2">
                        ${latest.length > 0
                            ? latest.slice(0, 3).map((item) => `
                                <li class="rounded-2xl border border-stone-200 bg-stone-50 px-3 py-2">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-sm font-medium text-slate-900">${e(item.code || 'n/d')}</span>
                                        <span class="text-xs text-slate-500">${formatDateTime(item.occurred_at)}</span>
                                    </div>
                                    <div class="mt-1 text-xs leading-5 text-slate-500">${e(item.endpoint || 'Sem endpoint')} · ${e(item.direction || 'n/d')}</div>
                                </li>
                            `).join('')
                            : '<li class="text-sm text-slate-500">Nenhuma rejeição recente.</li>'}
                    </ul>
                </div>
            </div>
        `;
    }

    function renderBoundaryList(items) {
        if (!Array.isArray(items) || items.length === 0) {
            renderEmpty(elements.boundaryList, 'Nenhuma rejeição recente na janela selecionada.');
            return;
        }

        elements.boundaryList.innerHTML = `
            <table class="min-w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                    <tr class="text-[11px] uppercase tracking-[0.18em] text-slate-500">
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Código</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Direção</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Endpoint</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Provider</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Horario</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr class="align-top">
                            <td class="border-b border-stone-100 px-3 py-3">${badge(item.code || 'n/d', errorTone(item.code))}</td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-700">${e(item.direction || 'n/d')}</td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="max-w-md truncate font-medium text-slate-900">${e(item.endpoint || 'n/d')}</div>
                                <div class="mt-1 text-xs text-slate-500">${e(item.message || '')}</div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="font-medium text-slate-900">${e(item.provider || 'n/d')}</div>
                                <div class="mt-1 text-xs text-slate-500">${e(item.slot || 'sem slot')}</div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-600">${formatDateTime(item.occurred_at)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    function renderFeed(items) {
        if (!Array.isArray(items) || items.length === 0) {
            renderEmpty(elements.feed, 'Nenhum evento recente na janela selecionada.');
            return;
        }

        elements.feed.innerHTML = `
            <div class="space-y-3">
                ${items.map((item) => `
                    <article class="rounded-2xl border ${feedCardBorder(item)} bg-white px-4 py-3">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap gap-1.5">
                                    ${badge(item.source || 'origem', sourceTone(item.source))}
                                    ${item.type ? badge(item.type, typeTone(item.type)) : ''}
                                    ${severityBadge(item.severity)}
                                    ${item.status ? badge(item.status, statusTone(item.status)) : ''}
                                    ${item.error_code ? badge(item.error_code, errorTone(item.error_code)) : ''}
                                    ${item.provider ? badge(item.provider, 'slate') : ''}
                                    ${item.slot ? badge(item.slot, 'stone') : ''}
                                    ${item.decision_source ? badge(`rota ${item.decision_source}`, 'stone') : ''}
                                </div>

                                <p class="mt-3 text-sm font-medium leading-6 text-slate-950">${e(item.message || 'Evento operacional')}</p>

                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    ${feedReferenceBadges(item)}
                                </div>

                                ${feedSecondaryLine(item) ? `<p class="mt-2 text-xs leading-5 text-slate-500">${e(feedSecondaryLine(item))}</p>` : ''}
                            </div>
                            <div class="shrink-0 text-xs font-medium text-slate-500">${formatDateTime(item.occurred_at)}</div>
                        </div>
                    </article>
                `).join('')}
            </div>
        `;
    }

    function renderProviderOptions() {
        if (!elements.provider) {
            return;
        }

        const options = ['', ...state.providerOptions];

        if (state.provider && !options.includes(state.provider)) {
            options.push(state.provider);
        }

        elements.provider.innerHTML = options
            .filter(uniqueOnly)
            .map((provider) => `
                <option value="${e(provider)}" ${provider === state.provider ? 'selected' : ''}>
                    ${provider === '' ? 'Todos os providers' : e(provider)}
                </option>
            `)
            .join('');
    }

    function renderPagination(container, target, meta) {
        if (!container || !meta || normalizePositiveInteger(meta.last_page, 1) <= 1) {
            if (container) {
                container.innerHTML = '';
            }

            return;
        }

        const currentPage = normalizePositiveInteger(meta.current_page, 1);
        const lastPage = normalizePositiveInteger(meta.last_page, 1);
        const previousPage = Math.max(1, currentPage - 1);
        const nextPage = Math.min(lastPage, currentPage + 1);

        container.innerHTML = `
            <div class="flex flex-col gap-2 border-t border-stone-200 pt-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
                <div>Página ${e(String(currentPage))} de ${e(String(lastPage))} · ${e(String(meta.total || 0))} registros</div>
                <div class="flex gap-2">
                    <button
                        type="button"
                        data-action="paginate"
                        data-pagination-target="${e(target)}"
                        data-page="${e(String(previousPage))}"
                        ${currentPage <= 1 ? 'disabled' : ''}
                        class="inline-flex items-center justify-center rounded-2xl border border-stone-300 bg-white px-3 py-2 font-medium text-slate-700 transition hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-45"
                    >
                        Anterior
                    </button>
                    <button
                        type="button"
                        data-action="paginate"
                        data-pagination-target="${e(target)}"
                        data-page="${e(String(nextPage))}"
                        ${currentPage >= lastPage ? 'disabled' : ''}
                        class="inline-flex items-center justify-center rounded-2xl border border-stone-300 bg-white px-3 py-2 font-medium text-slate-700 transition hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-45"
                    >
                        Próxima
                    </button>
                </div>
            </div>
        `;
    }

    function renderLoading(element, message) {
        if (!element) {
            return;
        }

        element.innerHTML = `
            <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-5 text-sm text-slate-500">
                ${e(message)}
            </div>
        `;
    }

    function renderEmpty(element, message) {
        if (!element) {
            return;
        }

        element.innerHTML = `
            <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-5 text-sm text-slate-500">
                ${e(message)}
            </div>
        `;
    }

    function renderSectionError(element, message, section) {
        if (!element) {
            return;
        }

        element.innerHTML = `
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-700">
                <div>${e(message)}</div>
                <button
                    type="button"
                    data-action="retry-section"
                    data-section-target="${e(section)}"
                    class="mt-3 inline-flex items-center justify-center rounded-2xl border border-rose-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-rose-700 transition hover:bg-rose-100"
                >
                    Tentar novamente
                </button>
            </div>
        `;
    }

    function updateLastUpdated() {
        if (!elements.lastUpdated) {
            return;
        }

        elements.lastUpdated.textContent = formatDateTime(new Date().toISOString());
    }

    function updateAutoRefreshState() {
        if (!elements.autoRefreshState) {
            return;
        }

        elements.autoRefreshState.textContent = state.autoRefresh
            ? 'Atualização automática a cada 60s'
            : 'Atualização automática desligada';
    }

    function showGlobalError(message) {
        if (!elements.globalError) {
            return;
        }

        elements.globalError.textContent = message;
        elements.globalError.classList.remove('hidden');
    }

    function clearGlobalError() {
        if (!elements.globalError) {
            return;
        }

        elements.globalError.textContent = '';
        elements.globalError.classList.add('hidden');
    }
}

function filterProviderItems(items, provider) {
    if (!Array.isArray(items)) {
        return [];
    }

    if (!provider) {
        return items;
    }

    return items.filter((item) => item?.provider === provider);
}

function filterRows(rows, allowed) {
    if (!Array.isArray(rows)) {
        return [];
    }

    return rows.filter((row) => allowed.includes(row?.status));
}

function summarizeRows(rows, key, limit = 5) {
    if (!Array.isArray(rows)) {
        return [];
    }

    return rows.slice(0, limit).map((row) => ({
        label: row?.[key] || 'n/d',
        total: row?.total || 0,
    }));
}

function metricTile(label, value, caption, tone) {
    return `
        <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">${e(label)}</p>
            <p class="mt-2 text-2xl font-semibold tracking-tight text-slate-950">${e(String(value))}</p>
            <p class="mt-1 text-xs leading-5 ${metricCaptionClass(tone)}">${e(caption)}</p>
        </div>
    `;
}

function metricCaptionClass(tone) {
    switch (tone) {
        case 'emerald':
            return 'text-emerald-700';
        case 'rose':
            return 'text-rose-700';
        case 'amber':
            return 'text-amber-700';
        default:
            return 'text-slate-500';
    }
}

function providerDetailRow(label, value) {
    return `
        <div class="flex items-start justify-between gap-3">
            <dt class="text-slate-500">${e(label)}</dt>
            <dd class="max-w-[16rem] text-right font-medium text-slate-900">${e(value)}</dd>
        </div>
    `;
}

function renderInlineHealthBadge(lastHealthcheck) {
    if (!lastHealthcheck) {
        return badge('sem healthcheck', 'stone');
    }

    return badge(
        lastHealthcheck.healthy ? 'healthcheck ok' : 'healthcheck falhou',
        lastHealthcheck.healthy ? 'emerald' : 'rose',
    );
}

function healthcheckText(lastHealthcheck) {
    if (!lastHealthcheck) {
        return 'Sem healthcheck conhecido.';
    }

    const notes = [
        lastHealthcheck.healthy ? 'saudável' : 'com falha',
    ];

    if (lastHealthcheck.http_status) {
        notes.push(`HTTP ${lastHealthcheck.http_status}`);
    }

    if (lastHealthcheck.latency_ms) {
        notes.push(`${lastHealthcheck.latency_ms}ms`);
    }

    if (lastHealthcheck.error_code) {
        notes.push(lastHealthcheck.error_code);
    }

    const checkedAt = formatDateTime(lastHealthcheck.checked_at);

    return `${notes.join(' · ')} · ${checkedAt}`;
}

function compareAttentionItems(left, right) {
    const severityDelta = attentionRank(right?.severity) - attentionRank(left?.severity);

    if (severityDelta !== 0) {
        return severityDelta;
    }

    return String(right?.occurred_at || '').localeCompare(String(left?.occurred_at || ''));
}

function providerStateLabel(label) {
    switch (label) {
        case 'healthy':
            return 'saudável';
        case 'degraded':
            return 'degradado';
        case 'unstable':
            return 'instável';
        case 'unavailable':
            return 'indisponível';
        default:
            return 'desconhecido';
    }
}

function statusLabel(status) {
    switch (status) {
        case 'running':
            return 'em execução';
        case 'completed':
            return 'concluído';
        case 'skipped_due_to_lock':
            return 'aguardando lock';
        case 'failed':
            return 'falhou';
        default:
            return status || 'desconhecido';
    }
}

function attentionRank(severity) {
    switch (severity) {
        case 'high':
            return 3;
        case 'medium':
            return 2;
        default:
            return 1;
    }
}

function agentEvidenceLine(item) {
    const evidence = item?.evidence || {};
    const notes = [];

    if (evidence.operational_state) {
        notes.push(`estado ${evidence.operational_state}`);
    }

    if (evidence.eligible_candidates_at_least !== undefined && evidence.eligible_candidates_at_least !== null) {
        notes.push(`elegíveis >= ${evidence.eligible_candidates_at_least}`);
    }

    if (evidence.total !== undefined && evidence.total !== null) {
        notes.push(`total ${evidence.total}`);
    }

    if (evidence.issue_total !== undefined && evidence.issue_total !== null) {
        notes.push(`issues ${evidence.issue_total}`);
    }

    if (evidence.failure_rate !== undefined && evidence.failure_rate !== null) {
        notes.push(`falha ${formatPercent(evidence.failure_rate)}`);
    }

    if (evidence.last_executed_at) {
        notes.push(`último run ${formatDateTime(evidence.last_executed_at)}`);
    }

    return notes.length > 0 ? notes.join(' · ') : 'Sem evidência adicional agregada.';
}

function attentionLabel(type) {
    switch (type) {
        case 'outbox_failed':
            return 'failed';
        case 'outbox_reclaimed_recently':
            return 'reclaim recente';
        case 'outbox_manual_review_required':
            return 'revisão manual';
        case 'message_terminal_failure':
            return 'falha terminal';
        case 'integration_attempt_issue':
            return 'tentativa com erro';
        default:
            return type || 'atenção';
    }
}

function attentionTone(item) {
    if (item.attention_type === 'outbox_manual_review_required' || item.attention_type === 'message_terminal_failure') {
        return 'rose';
    }

    if (item.error_code === 'provider_unavailable' || item.error_code === 'unsupported_feature') {
        return 'rose';
    }

    if (
        item.error_code === 'timeout_error'
        || item.error_code === 'rate_limit'
        || item.error_code === 'transient_network_error'
        || item.attention_type === 'outbox_reclaimed_recently'
    ) {
        return 'amber';
    }

    return 'stone';
}

function severityBadge(severity) {
    if (!severity) {
        return '';
    }

    return badge(severity, severityTone(severity));
}

function severityTone(severity) {
    switch (severity) {
        case 'high':
            return 'rose';
        case 'medium':
            return 'amber';
        case 'info':
            return 'slate';
        default:
            return 'stone';
    }
}

function statusTone(status) {
    switch (status) {
        case 'succeeded':
        case 'processed':
        case 'delivered':
        case 'healthy':
        case 'completed':
            return 'emerald';
        case 'failed':
        case 'unhealthy':
            return 'rose';
        case 'retry_scheduled':
        case 'fallback_scheduled':
        case 'queued':
        case 'processing':
        case 'running':
            return 'amber';
        case 'resolved':
        case 'executed':
            return 'emerald';
        case 'ignored':
        case 'skipped_due_to_lock':
            return 'stone';
        default:
            return 'stone';
    }
}

function stateTone(tone) {
    return tone || 'stone';
}

function sourceTone(source) {
    switch (source) {
        case 'event_log':
            return 'amber';
        case 'boundary_rejection_audit':
            return 'rose';
        case 'integration_attempt':
            return 'slate';
        case 'admin_audit':
            return 'stone';
        default:
            return 'stone';
    }
}

function typeTone(type) {
    switch (type) {
        case 'provider_fallback_scheduled':
        case 'provider_fallback_executed':
        case 'outbox_reclaimed':
        case 'duplicate_risk_detected':
        case 'automation_opportunity_reactivation':
        case 'automation_opportunity_reminder':
        case 'duplicate_risk_alert':
            return 'amber';
        case 'terminal_failure':
        case 'boundary_rejection':
        case 'manual_review_required':
        case 'provider_config_deactivated':
        case 'provider_health_alert':
        case 'delivery_instability_alert':
            return 'rose';
        case 'duplicate_prevented':
        case 'provider_healthcheck':
        case 'provider_config_activated':
        case 'automation_scheduler_run_started':
        case 'automation_scheduler_run_completed':
        case 'agent_scheduler_run_started':
        case 'agent_scheduler_run_completed':
        case 'housekeeping_run_started':
        case 'housekeeping_run_completed':
        case 'automation_run_completed':
        case 'agent_run_completed':
            return 'slate';
        case 'agent_recommendation_executed':
        case 'agent_insight_resolved':
            return 'emerald';
        case 'automation_scheduler_run_failed':
        case 'agent_scheduler_run_failed':
        case 'housekeeping_run_failed':
        case 'automation_run_failed':
        case 'agent_run_failed':
            return 'rose';
        case 'agent_insight_created':
            return 'amber';
        case 'agent_insight_ignored':
            return 'stone';
        default:
            return 'stone';
    }
}

function errorTone(code) {
    switch (code) {
        case 'provider_unavailable':
        case 'unsupported_feature':
        case 'webhook_signature_invalid':
        case 'payload_validation_failed':
            return 'rose';
        case 'rate_limit':
        case 'timeout_error':
        case 'transient_network_error':
            return 'amber';
        default:
            return 'stone';
    }
}

function queueRowClass(item) {
    if (item.attention_type === 'outbox_manual_review_required' || item.attention_type === 'message_terminal_failure') {
        return 'bg-rose-50/40';
    }

    if (
        item.error_code === 'provider_unavailable'
        || item.error_code === 'unsupported_feature'
        || item.error_code === 'timeout_error'
        || item.error_code === 'rate_limit'
        || item.error_code === 'transient_network_error'
        || item.attention_type === 'outbox_reclaimed_recently'
    ) {
        return 'bg-amber-50/35';
    }

    return '';
}

function queueAttemptLabel(item) {
    const details = item?.details || {};

    if (details.attempt_count && details.max_attempts) {
        return `${details.attempt_count}/${details.max_attempts}`;
    }

    if (details.attempt_count) {
        return String(details.attempt_count);
    }

    if (details.reclaim_count) {
        return `reclaim ${details.reclaim_count}`;
    }

    return '—';
}

function renderFallbackPill(fallback) {
    if (!fallback || typeof fallback !== 'object') {
        return '';
    }

    return badge(`fallback ${fallbackLabel(fallback)}`, 'amber');
}

function renderFallbackCell(fallback) {
    if (!fallback || typeof fallback !== 'object') {
        return '<span class="text-slate-400">—</span>';
    }

    return `
        <div class="space-y-1">
            <div class="font-medium text-slate-900">${e(fallbackLabel(fallback))}</div>
            ${fallback.trigger_error_code ? `<div class="text-xs text-slate-500">${e(fallback.trigger_error_code)}</div>` : ''}
        </div>
    `;
}

function fallbackLabel(fallback) {
    const fromProvider = fallback.from_provider || 'n/d';
    const toProvider = fallback.to_provider || 'n/d';
    const toSlot = fallback.to_slot ? `/${fallback.to_slot}` : '';

    return `${fromProvider} -> ${toProvider}${toSlot}`;
}

function referenceSummary(item) {
    const references = [
        item.message_id ? `mensagem ${shortReference(item.message_id)}` : null,
        item.outbox_event_id ? `outbox ${shortReference(item.outbox_event_id)}` : null,
        item.integration_attempt_id ? `attempt ${shortReference(item.integration_attempt_id)}` : null,
    ].filter(Boolean);

    return references.length > 0 ? references.join(' · ') : 'Sem referencia adicional.';
}

function referenceCell(item) {
    const references = [
        item.message_id ? `mensagem ${shortReference(item.message_id)}` : null,
        item.outbox_event_id ? `outbox ${shortReference(item.outbox_event_id)}` : null,
        item.integration_attempt_id ? `attempt ${shortReference(item.integration_attempt_id)}` : null,
    ].filter(Boolean);

    if (references.length === 0) {
        return '<span class="text-slate-400">—</span>';
    }

    return `
        <div class="space-y-1 text-xs text-slate-600">
            ${references.map((reference) => `<div>${e(reference)}</div>`).join('')}
        </div>
    `;
}

function queueOperationalNote(item) {
    const details = item?.details || {};
    const notes = [];

    if (details.decision_reason) {
        notes.push(`decisão ${details.decision_reason}`);
    }

    if (details.last_reclaim_reason) {
        notes.push(`reclaim ${details.last_reclaim_reason}`);
    }

    if (details.provider_status) {
        notes.push(`provider status ${details.provider_status}`);
    }

    if (details.provider_error_code) {
        notes.push(`provider code ${details.provider_error_code}`);
    }

    if (details.http_status) {
        notes.push(`HTTP ${details.http_status}`);
    }

    if (item?.duplicate_prevented || details.duplicate_prevented) {
        notes.push('duplicado bloqueado');
    }

    if (item?.duplicate_risk || details.duplicate_risk) {
        notes.push('risco de duplicidade');
    }

    if (details.deduplication_key) {
        notes.push(`dedup ${shortReference(details.deduplication_key)}`);
    }

    return notes.join(' · ');
}

function feedReferenceBadges(item) {
    const details = item?.details || {};
    const badgesList = [];

    if (details.message_id) {
        badgesList.push(badge(`mensagem ${shortReference(details.message_id)}`, 'stone'));
    }

    if (details.outbox_event_id) {
        badgesList.push(badge(`outbox ${shortReference(details.outbox_event_id)}`, 'stone'));
    }

    if (details.aggregate_id) {
        badgesList.push(badge(`aggregate ${shortReference(details.aggregate_id)}`, 'stone'));
    }

    if (details.automation_run_id) {
        badgesList.push(badge(`run ${shortReference(details.automation_run_id)}`, 'stone'));
    }

    if (details.agent_run_id) {
        badgesList.push(badge(`agent run ${shortReference(details.agent_run_id)}`, 'stone'));
    }

    if (details.insight_id) {
        badgesList.push(badge(`insight ${shortReference(details.insight_id)}`, 'stone'));
    }

    if (details.automation_target_id) {
        badgesList.push(badge(`target ${shortReference(details.automation_target_id)}`, 'stone'));
    }

    if (details.automation_type) {
        badgesList.push(badge(`automação ${details.automation_type}`, 'stone'));
    }

    if (details.insight_type) {
        badgesList.push(badge(`insight ${details.insight_type}`, 'stone'));
    }

    if (details.request_id) {
        badgesList.push(badge(`request ${shortReference(details.request_id)}`, 'stone'));
    }

    if (details.correlation_id) {
        badgesList.push(badge(`corr ${shortReference(details.correlation_id)}`, 'stone'));
    }

    if (item.direction) {
        badgesList.push(badge(`direção ${item.direction}`, 'stone'));
    }

    if (details.endpoint) {
        badgesList.push(badge(`endpoint ${shortText(details.endpoint, 42)}`, 'stone'));
    }

    return badgesList.length > 0 ? badgesList.join('') : '<span class="text-xs text-slate-500">Sem referencia adicional.</span>';
}

function feedSecondaryLine(item) {
    const details = item?.details || {};
    const notes = [];

    if (details.provider_decision_source || item?.decision_source) {
        notes.push(`rota ${details.provider_decision_source || item.decision_source}`);
    }

    if (details.decision_reason) {
        notes.push(`decisão ${details.decision_reason}`);
    }

    if (details.reason) {
        notes.push(`motivo ${details.reason}`);
    }

    if (details.candidates_found !== undefined && details.candidates_found !== null) {
        notes.push(`candidatos ${details.candidates_found}`);
    }

    if (details.messages_queued !== undefined && details.messages_queued !== null) {
        notes.push(`enfileiradas ${details.messages_queued}`);
    }

    if (details.skipped_total !== undefined && details.skipped_total !== null) {
        notes.push(`skips ${details.skipped_total}`);
    }

    if (details.failed_total !== undefined && details.failed_total !== null) {
        notes.push(`falhas ${details.failed_total}`);
    }

    if (details.insights_created !== undefined && details.insights_created !== null) {
        notes.push(`insights criados ${details.insights_created}`);
    }

    if (details.insights_refreshed !== undefined && details.insights_refreshed !== null) {
        notes.push(`atualizados ${details.insights_refreshed}`);
    }

    if (details.insights_resolved !== undefined && details.insights_resolved !== null) {
        notes.push(`resolvidos ${details.insights_resolved}`);
    }

    if (details.suggested_action) {
        notes.push(`ação ${details.suggested_action}`);
    }

    if (details.http_status) {
        notes.push(`HTTP ${details.http_status}`);
    }

    if (details.provider_error_code) {
        notes.push(`provider code ${details.provider_error_code}`);
    }

    if (details.duplicate_prevented || item?.duplicate_prevented) {
        notes.push('duplicado bloqueado');
    }

    if (details.duplicate_risk || item?.duplicate_risk) {
        notes.push('risco de duplicidade');
    }

    if (details.deduplication_key) {
        notes.push(`dedup ${shortReference(details.deduplication_key)}`);
    }

    if (details.skip_reasons && typeof details.skip_reasons === 'object' && Object.keys(details.skip_reasons).length > 0) {
        notes.push(`skips ${Object.entries(details.skip_reasons).slice(0, 2).map(([reason, total]) => `${reason}:${total}`).join(', ')}`);
    }

    if (details.failed_reasons && typeof details.failed_reasons === 'object' && Object.keys(details.failed_reasons).length > 0) {
        notes.push(`falhas ${Object.entries(details.failed_reasons).slice(0, 2).map(([reason, total]) => `${shortText(reason, 24)}:${total}`).join(', ')}`);
    }

    if (Array.isArray(details.result)) {
        notes.push('resultado sanitizado disponível');
    }

    if (details.result && typeof details.result === 'object') {
        if (Object.prototype.hasOwnProperty.call(details.result, 'healthy')) {
            notes.push(details.result.healthy ? 'healthcheck saudável' : 'healthcheck com falha');
        }

        if (details.result.latency_ms) {
            notes.push(`${details.result.latency_ms}ms`);
        }
    }

    return notes.join(' · ');
}

function feedCardBorder(item) {
    switch (item?.severity) {
        case 'high':
            return 'border-rose-200';
        case 'medium':
            return 'border-amber-200';
        default:
            return 'border-stone-200';
    }
}

function shortReference(value) {
    const stringValue = String(value || '');

    if (stringValue.length <= 16) {
        return stringValue;
    }

    return `${stringValue.slice(0, 8)}…${stringValue.slice(-6)}`;
}

function shortText(value, maxLength) {
    const stringValue = String(value || '');

    if (stringValue.length <= maxLength) {
        return stringValue;
    }

    return `${stringValue.slice(0, maxLength - 1)}…`;
}

function badge(label, tone) {
    return `<span class="${badgeClasses(tone)}">${e(label)}</span>`;
}

function badgeClasses(tone) {
    switch (tone) {
        case 'emerald':
            return 'inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-700';
        case 'rose':
            return 'inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-rose-700';
        case 'amber':
            return 'inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-amber-700';
        case 'slate':
            return 'inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-700';
        default:
            return 'inline-flex items-center rounded-full border border-stone-200 bg-stone-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-600';
    }
}

function formatPercent(value) {
    const numericValue = Number(value || 0);

    return `${numericValue.toFixed(2)}%`;
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

function formatDuration(value) {
    const numericValue = Number(value || 0);

    if (!Number.isFinite(numericValue) || numericValue <= 0) {
        return '0 ms';
    }

    if (numericValue < 1000) {
        return `${Math.round(numericValue)} ms`;
    }

    return `${(numericValue / 1000).toFixed(1)} s`;
}

function friendlyError(error, fallback) {
    const message = error?.response?.data?.message;

    return typeof message === 'string' && message !== '' ? message : fallback;
}

function compactParams(params) {
    return Object.fromEntries(
        Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''),
    );
}

function applyUrlParam(url, key, value, defaultValue = '') {
    if (value === undefined || value === null || value === '' || value === defaultValue) {
        url.searchParams.delete(key);
        return;
    }

    url.searchParams.set(key, String(value));
}

function normalizePositiveInteger(value, fallback) {
    const numericValue = Number.parseInt(String(value || ''), 10);

    return Number.isNaN(numericValue) || numericValue < 1 ? fallback : numericValue;
}

function uniqueOnly(value, index, collection) {
    return collection.indexOf(value) === index;
}

function e(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
