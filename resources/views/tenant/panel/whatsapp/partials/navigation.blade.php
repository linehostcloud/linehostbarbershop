<nav class="rounded-2xl border border-stone-200 bg-white/95 px-4 py-3 shadow-[0_12px_30px_-24px_rgba(15,23,42,0.35)] backdrop-blur">
    <div class="flex flex-wrap items-center gap-2">
        @if (($navigation['can_view_operations'] ?? false) === true)
            <a
                href="{{ $navigation['operations_url'] }}"
                class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold transition {{ ($navigation['active'] ?? null) === 'operations' ? 'bg-slate-950 text-white' : 'border border-stone-300 bg-white text-slate-700 hover:bg-stone-50' }}"
            >
                Operação
            </a>
        @endif

        @if (($navigation['can_view_governance'] ?? false) === true)
            <a
                href="{{ $navigation['governance_url'] }}"
                class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold transition {{ ($navigation['active'] ?? null) === 'governance' ? 'bg-slate-950 text-white' : 'border border-stone-300 bg-white text-slate-700 hover:bg-stone-50' }}"
            >
                Governança
            </a>
        @endif
    </div>
</nav>
