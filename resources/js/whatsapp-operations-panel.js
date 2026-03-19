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
        queueProvider: boot.filters?.queue_provider || '',
        queueStatus: boot.filters?.queue_status || '',
        queueErrorCode: boot.filters?.queue_error_code || '',
        queuePage: normalizePositiveInteger(boot.filters?.queue_page, 1),
        boundaryPage: normalizePositiveInteger(boot.filters?.boundary_page, 1),
        feedPage: normalizePositiveInteger(boot.filters?.feed_page, 1),
        providerOptions: [],
    };

    const elements = {
        globalError: rootElement.querySelector('[data-global-error]'),
        lastUpdated: rootElement.querySelector('[data-last-updated]'),
        summary: rootElement.querySelector('[data-section="summary"]'),
        providers: rootElement.querySelector('[data-section="providers"]'),
        queue: rootElement.querySelector('[data-section="queue"]'),
        boundarySummary: rootElement.querySelector('[data-section="boundary-summary"]'),
        boundaryList: rootElement.querySelector('[data-section="boundary-list"]'),
        feed: rootElement.querySelector('[data-section="feed"]'),
        queuePagination: rootElement.querySelector('[data-pagination="queue"]'),
        boundaryPagination: rootElement.querySelector('[data-pagination="boundary"]'),
        feedPagination: rootElement.querySelector('[data-pagination="feed"]'),
        window: rootElement.querySelector('[data-control="window"]'),
        queueProvider: rootElement.querySelector('[data-control="queue-provider"]'),
        queueStatus: rootElement.querySelector('[data-control="queue-status"]'),
        queueErrorCode: rootElement.querySelector('[data-control="queue-error-code"]'),
        queueForm: rootElement.querySelector('[data-form="queue-filters"]'),
    };

    syncControls();
    bindEvents();
    loadAllSections();

    function bindEvents() {
        elements.window?.addEventListener('change', (event) => {
            state.window = event.target.value || '24h';
            state.queuePage = 1;
            state.boundaryPage = 1;
            state.feedPage = 1;
            syncUrl();
            loadAllSections();
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
                state.queueProvider = '';
                state.queueStatus = '';
                state.queueErrorCode = '';
                state.queuePage = 1;
                syncControls();
                syncUrl();
                loadQueue();
                return;
            }

            if (action === 'retry-section') {
                const section = actionElement.getAttribute('data-section-target');

                if (section === 'summary') {
                    loadSummary();
                } else if (section === 'providers') {
                    loadProviders();
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

        elements.queueForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            state.queueProvider = elements.queueProvider?.value || '';
            state.queueStatus = elements.queueStatus?.value || '';
            state.queueErrorCode = elements.queueErrorCode?.value || '';
            state.queuePage = 1;
            syncUrl();
            loadQueue();
        });
    }

    function syncControls() {
        if (elements.window) {
            elements.window.value = state.window;
        }

        renderQueueProviderOptions();

        if (elements.queueStatus) {
            elements.queueStatus.value = state.queueStatus;
        }

        if (elements.queueErrorCode) {
            elements.queueErrorCode.value = state.queueErrorCode;
        }
    }

    function syncUrl() {
        const url = new URL(window.location.href);

        applyUrlParam(url, 'window', state.window, '24h');
        applyUrlParam(url, 'queue_provider', state.queueProvider);
        applyUrlParam(url, 'queue_status', state.queueStatus);
        applyUrlParam(url, 'queue_error_code', state.queueErrorCode);
        applyUrlParam(url, 'queue_page', state.queuePage, 1);
        applyUrlParam(url, 'boundary_page', state.boundaryPage, 1);
        applyUrlParam(url, 'feed_page', state.feedPage, 1);

        window.history.replaceState({}, '', `${url.pathname}${url.search}`);
    }

    async function loadAllSections() {
        clearGlobalError();

        const results = await Promise.allSettled([
            loadSummary(),
            loadProviders(),
            loadQueue(),
            loadBoundary(),
            loadFeed(),
        ]);

        if (results.some((result) => result.status === 'fulfilled')) {
            updateLastUpdated();
        }

        if (results.every((result) => result.status === 'rejected')) {
            showGlobalError('Nao foi possivel carregar os dados operacionais desta tela. Use o botao de recarregar para tentar novamente.');
        }
    }

    async function loadSummary() {
        renderLoading(elements.summary, 'Carregando resumo operacional...');

        try {
            const response = await getJson(boot.urls.summary, {
                window: state.window,
            });

            renderSummary(response.data);
            return response;
        } catch (error) {
            renderSectionError(elements.summary, friendlyError(error, 'Nao foi possivel carregar o resumo operacional.'), 'summary');
            throw error;
        }
    }

    async function loadProviders() {
        renderLoading(elements.providers, 'Carregando saude dos providers...');

        try {
            const response = await getJson(boot.urls.providers, {
                window: state.window,
            });

            state.providerOptions = Array.isArray(response.data)
                ? response.data.map((item) => item.provider).filter(Boolean).filter(uniqueOnly)
                : [];

            syncControls();
            renderProviders(response.data || []);
            return response;
        } catch (error) {
            renderSectionError(elements.providers, friendlyError(error, 'Nao foi possivel carregar a saude dos providers.'), 'providers');
            throw error;
        }
    }

    async function loadQueue() {
        renderLoading(elements.queue, 'Carregando fila operacional...');
        elements.queuePagination.innerHTML = '';

        try {
            const response = await getJson(boot.urls.queue, queueParams());

            renderQueue(response.data || []);
            renderPagination(elements.queuePagination, 'queue', response.meta);
            return response;
        } catch (error) {
            renderSectionError(elements.queue, friendlyError(error, 'Nao foi possivel carregar a fila operacional.'), 'queue');
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
    }

    async function loadBoundarySummary() {
        renderLoading(elements.boundarySummary, 'Carregando resumo de rejeicoes...');

        try {
            const response = await getJson(boot.urls.boundary_summary, {
                window: state.window,
            });

            renderBoundarySummary(response.data);
            return response;
        } catch (error) {
            renderSectionError(elements.boundarySummary, friendlyError(error, 'Nao foi possivel carregar o resumo de boundary rejections.'), 'boundary');
            throw error;
        }
    }

    async function loadBoundaryList() {
        renderLoading(elements.boundaryList, 'Carregando rejeicoes recentes...');
        elements.boundaryPagination.innerHTML = '';

        try {
            const response = await getJson(boot.urls.boundary_rejections, {
                window: state.window,
                page: state.boundaryPage,
                per_page: 6,
            });

            renderBoundaryList(response.data || []);
            renderPagination(elements.boundaryPagination, 'boundary', response.meta);
            return response;
        } catch (error) {
            renderSectionError(elements.boundaryList, friendlyError(error, 'Nao foi possivel carregar a lista de rejeicoes.'), 'boundary');
            throw error;
        }
    }

    async function loadFeed() {
        renderLoading(elements.feed, 'Carregando feed recente...');
        elements.feedPagination.innerHTML = '';

        try {
            const response = await getJson(boot.urls.feed, {
                window: state.window,
                page: state.feedPage,
                per_page: 10,
            });

            renderFeed(response.data || []);
            renderPagination(elements.feedPagination, 'feed', response.meta);
            return response;
        } catch (error) {
            renderSectionError(elements.feed, friendlyError(error, 'Nao foi possivel carregar o feed recente.'), 'feed');
            throw error;
        }
    }

    function queueParams() {
        return compactParams({
            window: state.window,
            page: state.queuePage,
            per_page: 8,
            provider: state.queueProvider || undefined,
            status: state.queueStatus || undefined,
            error_code: state.queueErrorCode || undefined,
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
                showGlobalError('A sessao do painel expirou ou nao possui mais permissao para consultar esta operacao. Atualize a pagina ou faca login novamente.');
            }

            throw error;
        }
    }

    function renderSummary(payload) {
        const sections = [
            {
                title: 'Mensagens',
                total: payload?.messages?.total || 0,
                rows: summarizeRows(payload?.messages?.status_totals || [], 'status'),
            },
            {
                title: 'Outbox',
                total: payload?.outbox_events?.total || 0,
                rows: summarizeRows(payload?.outbox_events?.status_totals || [], 'status'),
            },
            {
                title: 'Tentativas',
                total: payload?.integration_attempts?.total || 0,
                rows: summarizeRows(payload?.integration_attempts?.error_code_totals?.length
                    ? payload?.integration_attempts?.error_code_totals
                    : payload?.integration_attempts?.status_totals || [], payload?.integration_attempts?.error_code_totals?.length ? 'error_code' : 'status'),
            },
            {
                title: 'Boundary',
                total: payload?.boundary_rejections?.total || 0,
                rows: summarizeRows(payload?.boundary_rejections?.code_totals || [], 'code'),
            },
        ];

        elements.summary.innerHTML = sections.map((section) => `
            <article class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">${e(section.title)}</h3>
                        <p class="mt-1 text-2xl font-semibold tracking-tight text-slate-950">${e(String(section.total))}</p>
                    </div>
                    <span class="rounded-full border border-stone-200 bg-white px-2.5 py-1 text-[11px] font-medium uppercase tracking-[0.18em] text-slate-500">Janela</span>
                </div>
                <ul class="mt-4 space-y-2">
                    ${section.rows.length > 0 ? section.rows.map((row) => `
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="truncate text-slate-600">${e(row.label)}</span>
                            <span class="font-semibold text-slate-900">${e(String(row.total))}</span>
                        </li>
                    `).join('') : `
                        <li class="text-sm text-slate-500">Nenhum registro na janela selecionada.</li>
                    `}
                </ul>
            </article>
        `).join('');
    }

    function renderProviders(items) {
        if (!Array.isArray(items) || items.length === 0) {
            renderEmpty(elements.providers, 'Nenhum provider configurado para esta janela operacional.');
            return;
        }

        elements.providers.innerHTML = `
            <table class="min-w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                    <tr class="text-[11px] uppercase tracking-[0.18em] text-slate-500">
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Provider</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Slot</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Estado</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Capabilities</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Healthcheck</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Sucesso / Falha</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Principais erros</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Ultima atividade</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr class="align-top">
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="font-semibold text-slate-900">${e(item.provider)}</div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-600">${e(item.slot)}</td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                ${badge(item.enabled ? 'ativo' : 'inativo', item.enabled ? 'emerald' : 'stone')}
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    ${(item.enabled_capabilities || []).length > 0 ? item.enabled_capabilities.map((capability) => badge(capability, 'stone')).join('') : '<span class="text-slate-500">Sem capabilities.</span>'}
                                </div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                ${renderHealthcheck(item.last_healthcheck)}
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="space-y-1">
                                    <div class="font-medium text-slate-900">${formatPercent(item.success_rate)} / ${formatPercent(item.failure_rate)}</div>
                                    <div class="text-xs text-slate-500">${e(String(item.success_attempts || 0))} ok · ${e(String(item.failure_attempts || 0))} falhas</div>
                                </div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    ${(item.top_error_codes || []).length > 0 ? item.top_error_codes.map((entry) => badge(`${entry.code} · ${entry.total}`, 'amber')).join('') : '<span class="text-slate-500">Sem erros relevantes.</span>'}
                                </div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-600">${formatDateTime(item.last_activity_at)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    function renderQueue(items) {
        if (!Array.isArray(items) || items.length === 0) {
            renderEmpty(elements.queue, 'Nenhum item exige atencao com os filtros atuais.');
            return;
        }

        elements.queue.innerHTML = `
            <table class="min-w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                    <tr class="text-[11px] uppercase tracking-[0.18em] text-slate-500">
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Atencao</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Provider</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Status</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Codigo</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Resumo</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Horario</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr class="align-top ${queueRowClass(item)}">
                            <td class="border-b border-stone-100 px-3 py-3">
                                ${badge(attentionLabel(item.attention_type), attentionTone(item))}
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="font-medium text-slate-900">${e(item.provider || 'n/d')}</div>
                                <div class="mt-1 text-xs text-slate-500">${e(item.slot || 'sem slot')}</div>
                            </td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-700">${e(item.status || 'n/d')}</td>
                            <td class="border-b border-stone-100 px-3 py-3">${item.error_code ? badge(item.error_code, item.error_code === 'timeout_error' ? 'amber' : 'rose') : '<span class="text-slate-400">—</span>'}</td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="max-w-md text-slate-700">${e(item.summary || 'Sem resumo operacional.')}</div>
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

        elements.boundarySummary.innerHTML = `
            <div class="space-y-4">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Total</p>
                        <p class="text-3xl font-semibold tracking-tight text-slate-950">${e(String(payload?.total || 0))}</p>
                    </div>
                    <div class="text-right text-xs text-slate-500">
                        <div>Window ${e(payload?.window?.label || state.window)}</div>
                    </div>
                </div>
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Codigos principais</p>
                    <div class="flex flex-wrap gap-1.5">
                        ${codeTotals.length > 0 ? codeTotals.slice(0, 8).map((row) => badge(`${row.code} · ${row.total}`, 'rose')).join('') : '<span class="text-sm text-slate-500">Nenhuma rejeicao no periodo.</span>'}
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Direcao</p>
                        <ul class="space-y-1.5">
                            ${directionTotals.length > 0 ? directionTotals.map((row) => `<li class="flex justify-between gap-3 text-sm"><span class="text-slate-600">${e(row.direction)}</span><span class="font-semibold text-slate-900">${e(String(row.total))}</span></li>`).join('') : '<li class="text-sm text-slate-500">Sem distribuicao.</li>'}
                        </ul>
                    </div>
                    <div class="rounded-2xl border border-stone-200 bg-white px-3 py-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Endpoints</p>
                        <ul class="space-y-1.5">
                            ${endpointTotals.length > 0 ? endpointTotals.slice(0, 4).map((row) => `<li class="flex justify-between gap-3 text-sm"><span class="truncate text-slate-600">${e(row.endpoint)}</span><span class="font-semibold text-slate-900">${e(String(row.total))}</span></li>`).join('') : '<li class="text-sm text-slate-500">Sem endpoints relevantes.</li>'}
                        </ul>
                    </div>
                </div>
            </div>
        `;
    }

    function renderBoundaryList(items) {
        if (!Array.isArray(items) || items.length === 0) {
            renderEmpty(elements.boundaryList, 'Nenhuma rejeicao recente na janela selecionada.');
            return;
        }

        elements.boundaryList.innerHTML = `
            <table class="min-w-full border-separate border-spacing-0 text-left text-sm">
                <thead>
                    <tr class="text-[11px] uppercase tracking-[0.18em] text-slate-500">
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Codigo</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Direcao</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Endpoint</th>
                        <th class="border-b border-stone-200 px-3 py-2 font-semibold">Horario</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item) => `
                        <tr class="align-top">
                            <td class="border-b border-stone-100 px-3 py-3">${badge(item.code, item.code === 'webhook_signature_invalid' ? 'rose' : 'amber')}</td>
                            <td class="border-b border-stone-100 px-3 py-3 text-slate-700">${e(item.direction || 'n/d')}</td>
                            <td class="border-b border-stone-100 px-3 py-3">
                                <div class="max-w-md truncate font-medium text-slate-900">${e(item.endpoint || 'n/d')}</div>
                                <div class="mt-1 text-xs text-slate-500">${e(item.message || '')}</div>
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
                    <article class="rounded-2xl border border-stone-200 bg-white px-4 py-3">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    ${badge(item.source, sourceTone(item.source))}
                                    ${item.type ? badge(item.type, 'stone') : ''}
                                </div>
                                <p class="mt-2 text-sm font-medium text-slate-900">${e(item.message || 'Evento operacional')}</p>
                                <p class="mt-1 text-sm text-slate-600">
                                    ${e(composeFeedContext(item))}
                                </p>
                            </div>
                            <div class="shrink-0 text-xs font-medium text-slate-500">${formatDateTime(item.occurred_at)}</div>
                        </div>
                    </article>
                `).join('')}
            </div>
        `;
    }

    function renderHealthcheck(lastHealthcheck) {
        if (!lastHealthcheck) {
            return '<span class="text-slate-500">Sem healthcheck conhecido.</span>';
        }

        return `
            <div class="space-y-1">
                <div>${badge(lastHealthcheck.healthy ? 'healthy' : 'unhealthy', lastHealthcheck.healthy ? 'emerald' : 'rose')}</div>
                <div class="text-xs text-slate-500">${formatDateTime(lastHealthcheck.checked_at)}</div>
            </div>
        `;
    }

    function renderQueueProviderOptions() {
        if (!elements.queueProvider) {
            return;
        }

        const options = ['', ...state.providerOptions];
        const selected = state.queueProvider;

        if (selected && !options.includes(selected)) {
            options.push(selected);
        }

        elements.queueProvider.innerHTML = options
            .filter(uniqueOnly)
            .map((provider) => `
                <option value="${e(provider)}" ${provider === selected ? 'selected' : ''}>
                    ${provider === '' ? 'Todos' : e(provider)}
                </option>
            `)
            .join('');
    }

    function renderPagination(container, target, meta) {
        if (!container || !meta || (meta.last_page || 1) <= 1) {
            if (container) {
                container.innerHTML = '';
            }

            return;
        }

        const currentPage = normalizePositiveInteger(meta.current_page, 1);
        const previousPage = Math.max(1, currentPage - 1);
        const nextPage = Math.min(normalizePositiveInteger(meta.last_page, 1), currentPage + 1);

        container.innerHTML = `
            <div class="flex flex-col gap-2 border-t border-stone-200 pt-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
                <div>Pagina ${e(String(currentPage))} de ${e(String(meta.last_page || 1))} · ${e(String(meta.total || 0))} registros</div>
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
                        ${currentPage >= (meta.last_page || 1) ? 'disabled' : ''}
                        class="inline-flex items-center justify-center rounded-2xl border border-stone-300 bg-white px-3 py-2 font-medium text-slate-700 transition hover:bg-stone-50 disabled:cursor-not-allowed disabled:opacity-45"
                    >
                        Proxima
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

function attentionLabel(type) {
    switch (type) {
        case 'outbox_failed':
            return 'failed';
        case 'outbox_reclaimed_recently':
            return 'reclaim recente';
        case 'outbox_manual_review_required':
            return 'revisao manual';
        case 'message_terminal_failure':
            return 'falha terminal';
        case 'integration_attempt_issue':
            return 'tentativa com erro';
        default:
            return type || 'atencao';
    }
}

function attentionTone(item) {
    if (item.attention_type === 'outbox_manual_review_required' || item.error_code === 'provider_unavailable') {
        return 'rose';
    }

    if (item.error_code === 'timeout_error' || item.error_code === 'rate_limit') {
        return 'amber';
    }

    return 'stone';
}

function queueRowClass(item) {
    if (item.attention_type === 'outbox_manual_review_required' || item.error_code === 'provider_unavailable') {
        return 'bg-rose-50/40';
    }

    if (item.error_code === 'timeout_error' || item.error_code === 'rate_limit' || item.attention_type === 'outbox_reclaimed_recently') {
        return 'bg-amber-50/35';
    }

    return '';
}

function sourceTone(source) {
    switch (source) {
        case 'admin_audit':
            return 'stone';
        case 'event_log':
            return 'amber';
        case 'boundary_rejection_audit':
            return 'rose';
        case 'integration_attempt':
            return 'slate';
        default:
            return 'stone';
    }
}

function summarizeRows(rows, key) {
    if (!Array.isArray(rows)) {
        return [];
    }

    return rows.slice(0, 5).map((row) => ({
        label: row?.[key] || 'n/d',
        total: row?.total || 0,
    }));
}

function composeFeedContext(item) {
    const parts = [];

    if (item.provider) {
        parts.push(`provider ${item.provider}`);
    }

    if (item.slot) {
        parts.push(`slot ${item.slot}`);
    }

    if (item.direction) {
        parts.push(`direcao ${item.direction}`);
    }

    if (item.error_code) {
        parts.push(`codigo ${item.error_code}`);
    }

    return parts.length > 0 ? parts.join(' · ') : 'Sem contexto adicional.';
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
