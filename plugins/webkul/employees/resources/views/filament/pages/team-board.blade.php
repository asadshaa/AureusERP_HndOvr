<x-filament-panels::page>
    <style>
        .tb-toolbar { display: flex; flex-wrap: wrap; gap: .75rem 1rem; align-items: center; }
        .tb-tabs { display: flex; flex-wrap: wrap; gap: .375rem; }
        .tb-tab { padding: .375rem .875rem; border-radius: 9999px; border: 1px solid rgba(127,127,127,.35); font-size: .875rem; font-weight: 500; cursor: pointer; background: transparent; color: inherit; }
        .tb-tab-on { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
        .tb-search { min-width: min(18rem, 100%); padding: .4rem .75rem; border-radius: .5rem; border: 1px solid rgba(127,127,127,.35); background: transparent; color: inherit; font-size: .875rem; }
        .tb-tip { margin-left: auto; font-size: .8125rem; opacity: .75; }
        .tb-banner { margin-top: .75rem; padding: .625rem .875rem; border-radius: .625rem; background: rgba(250, 204, 21, .16); border: 1px solid rgba(202, 138, 4, .5); font-size: .875rem; display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }
        .tb-top { margin-top: .75rem; padding: .625rem .875rem; border: 2px dashed rgba(127,127,127,.4); border-radius: .625rem; font-size: .875rem; display: flex; align-items: center; gap: .75rem; max-width: 46rem; }
        .tb-top.tb-over { border-color: var(--primary-500); background: rgba(34,197,94,.1); }
        .tb-tree, .tb-list { list-style: none; margin: 0; padding: 0; }
        .tb-tree { margin-top: .75rem; display: flex; flex-direction: column; gap: .375rem; }
        .tb-list { margin-left: 2.25rem; margin-top: .375rem; display: flex; flex-direction: column; gap: .375rem; border-left: 1px solid rgba(127,127,127,.3); padding-left: .75rem; }
        .tb-card { display: flex; align-items: center; gap: .625rem; padding: .5rem .75rem; border: 1px solid rgba(127,127,127,.25); border-radius: .625rem; background: rgba(127,127,127,.04); max-width: 46rem; cursor: grab; transition: border-color .12s, background .12s; }
        .tb-card:hover { border-color: rgba(127,127,127,.5); }
        .tb-over { border-color: var(--primary-500); background: rgba(34,197,94,.12); box-shadow: 0 0 0 2px rgba(34,197,94,.25); }
        .tb-bad { border-color: #dc2626; background: rgba(220,38,38,.08); cursor: not-allowed; }
        .tb-dragging { opacity: .4; }
        .tb-hit { background: rgba(250, 204, 21, .16); border-color: rgba(202, 138, 4, .55); }
        .tb-picked { outline: 2px solid var(--primary-500); outline-offset: 1px; }
        .tb-toggle { flex: none; width: 1.5rem; height: 1.5rem; border-radius: 9999px; border: 1px solid rgba(127,127,127,.4); background: transparent; color: inherit; cursor: pointer; line-height: 1; }
        .tb-spacer { flex: none; width: 1.5rem; }
        .tb-grip { flex: none; opacity: .4; font-size: 1.1rem; line-height: 1; }
        .tb-avatar { flex: none; width: 2.25rem; height: 2.25rem; border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; background: var(--primary-600); color: #fff; font-size: .8125rem; font-weight: 600; }
        .tb-text { min-width: 0; flex: 1; display: flex; flex-direction: column; }
        .tb-name { font-weight: 600; }
        .tb-meta { display: flex; flex-wrap: wrap; gap: .25rem .5rem; font-size: .8125rem; opacity: .8; }
        .tb-dept { padding: 0 .375rem; border-radius: .375rem; background: rgba(127,127,127,.14); }
        .tb-count { flex: none; font-size: .75rem; padding: .125rem .5rem; border-radius: 9999px; background: rgba(127,127,127,.15); }
        .tb-btn { padding: .25rem .625rem; border-radius: .5rem; border: 1px solid rgba(127,127,127,.4); background: transparent; color: inherit; font-size: .8125rem; cursor: pointer; }
        .tb-btn:hover { border-color: var(--primary-500); }
        .tb-btn-primary { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
        .tb-empty { margin-top: 1rem; opacity: .7; }
    </style>

    @if ($companies === [])
        <p class="tb-empty">You do not have permission to change reporting lines.</p>
    @else
        <div
            x-data="{
                _raw: null, _parents: {}, _names: {},
                sync() {
                    const raw = this.$el.dataset.tree;
                    if (raw !== this._raw) { this._raw = raw; const t = JSON.parse(raw); this._parents = t.parents; this._names = t.names; }
                },
                get parents() { this.sync(); return this._parents; },
                get names() { this.sync(); return this._names; },
                dragId: null, overId: null, pickedId: null, search: '', open: {},
                get moving() { return this.dragId ?? this.pickedId; },
                isOpen(id, depth) { return this.search.trim() !== '' ? this.pathOpen.has(id) : (this.open[id] ?? depth < 1); },
                toggle(id, depth) { this.open[id] = ! this.isOpen(id, depth); },
                get hits() {
                    const q = this.search.trim().toLowerCase();
                    if (q === '') return new Set();
                    return new Set(Object.entries(this.names).filter(([, n]) => String(n).toLowerCase().includes(q)).map(([id]) => Number(id)));
                },
                get pathOpen() {
                    const open = new Set();
                    this.hits.forEach(id => { let p = this.parents[id]; while (p != null && ! open.has(p)) { open.add(p); p = this.parents[p]; } });
                    return open;
                },
                isHit(id) { return this.hits.has(id); },
                canDrop(target) {
                    const moving = this.moving;
                    if (moving === null || target === moving) return false;
                    if (target === null) return true;
                    let p = this.parents[target];
                    while (p != null) { if (p === moving) return false; p = this.parents[p]; }
                    return true;
                },
                start(e, id) { this.dragId = id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(id)); },
                stop() { this.dragId = null; this.overId = null; },
                over(e, id) { this.overId = id; e.dataTransfer.dropEffect = this.canDrop(id) ? 'move' : 'none'; },
                leave(id) { if (this.overId === id) this.overId = null; },
                pick(id) { this.pickedId = id; },
                cancel() { this.pickedId = null; },
                drop(target) {
                    const moving = this.moving;
                    const ok = this.canDrop(target);
                    this.stop(); this.pickedId = null;
                    if (moving !== null && ok && this.parents[moving] !== target) { $wire.movePerson(moving, target); }
                }
            }"
            wire:key="board-{{ $this->companyId }}"
            data-tree="{{ json_encode(['parents' => $parents, 'names' => $people instanceof \Illuminate\Support\Collection ? $people->pluck('name', 'id') : (object) []]) }}"
        >
            <div class="tb-toolbar">
                @if (count($companies) > 1)
                    <div class="tb-tabs" role="tablist" aria-label="Company">
                        @foreach ($companies as $id => $name)
                            <button type="button" role="tab" wire:click="selectCompany({{ $id }})" class="tb-tab {{ (int) $this->companyId === (int) $id ? 'tb-tab-on' : '' }}">{{ $name }}</button>
                        @endforeach
                    </div>
                @endif
                <input type="search" class="tb-search" x-model="search" placeholder="Find a person…" aria-label="Find a person">
                <span class="tb-tip">Tip: drag a card onto a manager. On a phone, tap Move, then Put under.</span>
            </div>

            <template x-if="pickedId !== null">
                <div class="tb-banner">
                    <span>Moving <strong x-text="names[pickedId]"></strong>. Choose the new manager with <em>Put under</em>.</span>
                    <button type="button" class="tb-btn" x-on:click="drop(null)">Make top of company</button>
                    <button type="button" class="tb-btn" x-on:click="cancel()">Cancel</button>
                </div>
            </template>

            <div class="tb-top" x-bind:class="{ 'tb-over': overId === 0 && moving !== null }"
                 x-on:dragover.prevent="overId = 0; $event.dataTransfer.dropEffect = 'move'"
                 x-on:dragleave="if (overId === 0) overId = null"
                 x-on:drop.prevent="drop(null)">
                Drop here to make someone report to nobody (top of the company).
            </div>

            @if ($roots === [])
                <p class="tb-empty">No active people in this company yet.</p>
            @else
                <ul class="tb-tree">
                    @foreach ($roots as $rootId)
                        @include('employees::filament.pages.team-board-node', ['id' => $rootId, 'depth' => 0])
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</x-filament-panels::page>
