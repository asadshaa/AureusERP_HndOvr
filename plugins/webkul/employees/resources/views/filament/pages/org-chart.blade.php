@php
    $payload = [
        'nodes'   => (object) $chart['nodes'],
        'roots'   => $chart['roots'],
        'order'   => $chart['order'],
        'self'    => $chart['self_id'],
        'viewUrl' => $viewUrl,
        'scopes'  => (object) collect($chart['departments'])->mapWithKeys(fn (array $department): array => [$department['id'] => $department['ids']])->all(),
    ];
@endphp

<x-filament-panels::page>
    <style>
        .hr-oc-toolbar { display: flex; flex-wrap: wrap; gap: .75rem 1rem; align-items: flex-end; }
        .hr-oc-field { display: flex; flex-direction: column; gap: .25rem; font-size: .8125rem; font-weight: 500; }
        .hr-oc-search { min-width: 16rem; }
        .hr-oc-hits { font-size: .8125rem; opacity: .75; align-self: center; }
        .hr-oc-buttons { display: flex; flex-wrap: wrap; gap: .5rem; margin-left: auto; }
        .hr-oc-summary { margin-top: .75rem; font-size: .8125rem; opacity: .75; }
        .hr-oc-tree { display: flex; flex-direction: column; gap: .375rem; }
        .hr-oc-row { position: relative; display: flex; align-items: center; gap: .625rem; padding: .5rem .75rem; border: 1px solid rgba(127, 127, 127, .22); border-radius: .625rem; background: rgba(127, 127, 127, .04); max-width: 46rem; }
        .hr-oc-child::before { content: ''; position: absolute; left: -1.1rem; top: 50%; width: 1.1rem; border-top: 1px solid rgba(127, 127, 127, .35); }
        .hr-oc-child::after { content: ''; position: absolute; left: -1.1rem; top: -.45rem; height: calc(50% + .45rem); border-left: 1px solid rgba(127, 127, 127, .35); }
        .hr-oc-self { border-color: var(--primary-500); }
        .hr-oc-hit { background: rgba(250, 204, 21, .16); border-color: rgba(202, 138, 4, .55); }
        .hr-oc-current { outline: 2px solid var(--primary-500); outline-offset: 1px; }
        .hr-oc-toggle { flex: none; width: 1.5rem; height: 1.5rem; border-radius: 9999px; border: 1px solid rgba(127, 127, 127, .4); font-weight: 600; line-height: 1; cursor: pointer; background: transparent; color: inherit; }
        .hr-oc-toggle:hover { border-color: var(--primary-500); color: var(--primary-600); }
        .hr-oc-spacer { flex: none; width: 1.5rem; }
        .hr-oc-avatar { flex: none; width: 2.5rem; height: 2.5rem; border-radius: 9999px; object-fit: cover; }
        .hr-oc-initials { display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: .8125rem; font-weight: 600; letter-spacing: .02em; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .hr-oc-text { min-width: 0; flex: 1; }
        .hr-oc-name { font-weight: 600; }
        a.hr-oc-name { color: var(--primary-600); }
        a.hr-oc-name:hover { text-decoration: underline; }
        .dark a.hr-oc-name { color: var(--primary-400); }
        .hr-oc-you { margin-left: .375rem; padding: 0 .375rem; border-radius: .375rem; font-size: .6875rem; font-weight: 600; background: var(--primary-500); color: #fff; }
        .hr-oc-meta { display: flex; flex-wrap: wrap; gap: .25rem .5rem; font-size: .8125rem; opacity: .8; }
        .hr-oc-dept { padding: 0 .375rem; border-radius: .375rem; background: rgba(127, 127, 127, .14); }
        .hr-oc-manager { font-size: .75rem; opacity: .7; }
        .hr-oc-count { flex: none; font-size: .75rem; opacity: .7; white-space: nowrap; }
        .hr-oc-empty { padding: 1rem 0; opacity: .7; }
        @media print {
            .fi-topbar, .fi-sidebar, .fi-header-actions, .hr-oc-toolbar, .hr-oc-toggle, .hr-oc-spacer { display: none !important; }
            .hr-oc-row { break-inside: avoid; background: none; max-width: none; }
            .hr-oc-hit, .hr-oc-current { background: none; outline: none; }
            .fi-main, .fi-page, .fi-section { box-shadow: none !important; }
        }
    </style>

    <x-filament::section>
        @if ($chart['total'] === 0)
            <p class="hr-oc-empty">
                @if ($chart['scope'] === 'company')
                    There are no current employees in this company yet.
                @else
                    There is no one to show: your login is not linked to a current employee of this company.
                @endif
            </p>
        @else
            <div
                x-data="{
                    nodes: {},
                    roots: [],
                    order: [],
                    text: {},
                    scopes: {},
                    self: null,
                    viewUrl: '',
                    open: {},
                    dept: '',
                    scope: null,
                    q: '',
                    hits: [],
                    hitSet: {},
                    current: 0,
                    boot() {
                        const data = JSON.parse(this.$el.querySelector('script[data-org-chart]').textContent)
                        const text = {}
                        for (const id of data.order) {
                            const n = data.nodes[id]
                            text[id] = (n.name + ' ' + (n.title || '')).toLowerCase()
                        }
                        this.nodes = Object.freeze(data.nodes)
                        this.roots = Object.freeze(data.roots)
                        this.order = Object.freeze(data.order)
                        this.scopes = Object.freeze(data.scopes)
                        this.text = Object.freeze(text)
                        this.self = data.self
                        this.viewUrl = data.viewUrl
                        this.reset()
                        if (this.self) {
                            this.$nextTick(() => this.scrollTo(this.self))
                        }
                    },
                    inScope(id) {
                        return this.scope === null || this.scope[this.nodes[id].dept_id] === true
                    },
                    kidsOf(id) {
                        const kids = this.nodes[id].kids
                        return this.scope === null ? kids : kids.filter((kid) => this.inScope(kid))
                    },
                    tops() {
                        if (this.scope === null) {
                            return this.roots
                        }
                        return this.order.filter((id) => this.inScope(id) && ! (this.nodes[id].parent && this.inScope(this.nodes[id].parent)))
                    },
                    rows() {
                        const out = []
                        const open = this.open
                        const walk = (ids, depth) => {
                            for (const id of ids) {
                                const kids = this.kidsOf(id)
                                out.push({ id: id, depth: depth, n: this.nodes[id], kids: kids.length })
                                if (kids.length && open[id]) {
                                    walk(kids, depth + 1)
                                }
                            }
                        }
                        walk(this.tops(), 0)
                        return out
                    },
                    toggle(id) {
                        this.open = Object.assign({}, this.open, { [id]: ! this.open[id] })
                    },
                    reset() {
                        const open = {}
                        const walk = (ids, depth) => {
                            for (const id of ids) {
                                if (depth < 2 && this.nodes[id].kids.length) {
                                    open[id] = true
                                    walk(this.nodes[id].kids, depth + 1)
                                }
                            }
                        }
                        walk(this.roots, 0)
                        if (this.self) {
                            this.withAncestors(this.self, open)
                            if (this.nodes[this.self].kids.length) {
                                open[this.self] = true
                            }
                        }
                        this.open = open
                    },
                    expandAll() {
                        const open = {}
                        for (const id of this.order) {
                            if (this.nodes[id].kids.length) {
                                open[id] = true
                            }
                        }
                        this.open = open
                    },
                    collapseAll() {
                        this.open = {}
                    },
                    withAncestors(id, open) {
                        let parent = this.nodes[id].parent
                        while (parent && this.inScope(parent) && ! open[parent]) {
                            open[parent] = true
                            parent = this.nodes[parent].parent
                        }
                        return open
                    },
                    setDept() {
                        this.scope = this.dept === '' ? null : Object.freeze(Object.fromEntries((this.scopes[this.dept] || []).map((id) => [id, true])))
                        this.search()
                    },
                    search() {
                        const q = this.q.trim().toLowerCase()
                        this.current = 0
                        if (q.length < 2) {
                            this.hits = []
                            this.hitSet = {}
                            return
                        }
                        const hits = this.order.filter((id) => this.inScope(id) && this.text[id].includes(q))
                        const hitSet = {}
                        const open = Object.assign({}, this.open)
                        hits.forEach((id, index) => {
                            hitSet[id] = true
                            if (index < 50) {
                                this.withAncestors(id, open)
                            }
                        })
                        this.hits = Object.freeze(hits)
                        this.hitSet = Object.freeze(hitSet)
                        this.open = open
                        this.focus()
                    },
                    focus() {
                        const id = this.hits[this.current]
                        if (! id) {
                            return
                        }
                        this.open = this.withAncestors(id, Object.assign({}, this.open))
                        this.$nextTick(() => this.scrollTo(id))
                    },
                    next(step) {
                        if (! this.hits.length) {
                            return
                        }
                        this.current = (this.current + step + this.hits.length) % this.hits.length
                        this.focus()
                    },
                    scrollTo(id) {
                        const element = document.getElementById('hr-oc-' + id)
                        if (element) {
                            element.scrollIntoView({ block: 'center', behavior: 'smooth' })
                        }
                    },
                    printChart() {
                        this.expandAll()
                        this.$nextTick(() => setTimeout(() => window.print(), 150))
                    },
                }"
                x-init="boot()"
            >
                <script type="application/json" data-org-chart>@json($payload)</script>

                <div class="hr-oc-toolbar">
                    <label class="hr-oc-field">
                        <span>Search</span>
                        <x-filament::input.wrapper class="hr-oc-search">
                            <x-filament::input
                                type="search"
                                placeholder="Name or job title"
                                x-model="q"
                                x-on:input.debounce.250ms="search()"
                                x-on:keydown.enter.prevent="next(1)"
                                aria-label="Search the org chart"
                            />
                        </x-filament::input.wrapper>
                    </label>

                    <span class="hr-oc-hits" x-show="q.trim().length >= 2" x-cloak>
                        <span x-text="hits.length ? (current + 1) + ' of ' + hits.length : 'No one found'"></span>
                    </span>
                    <span x-show="hits.length > 1" x-cloak style="display: flex; gap: .25rem;">
                        <x-filament::icon-button color="gray" icon="heroicon-m-chevron-up" label="Previous match" x-on:click="next(-1)" />
                        <x-filament::icon-button color="gray" icon="heroicon-m-chevron-down" label="Next match" x-on:click="next(1)" />
                    </span>

                    <label class="hr-oc-field">
                        <span>Department</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select x-model="dept" x-on:change="setDept()" aria-label="Department">
                                <option value="">All departments</option>
                                @foreach ($chart['departments'] as $department)
                                    <option value="{{ $department['id'] }}">{{ $department['name'] }} ({{ $department['count'] }})</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>

                    <div class="hr-oc-buttons">
                        <x-filament::button size="sm" color="gray" icon="heroicon-m-arrows-pointing-out" x-on:click="expandAll()">Expand all</x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-m-arrows-pointing-in" x-on:click="collapseAll()">Collapse all</x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-m-printer" x-on:click="printChart()">Print</x-filament::button>
                    </div>
                </div>

                <p class="hr-oc-summary">
                    {{ $chart['total'] }} {{ \Illuminate\Support\Str::plural('person', $chart['total']) }}
                    · {{ $chart['scope'] === 'company' ? 'the whole company' : 'your reporting line, your peers and the people you look after' }}.
                    Click a name to open the employee.
                </p>

                <div class="hr-oc-tree" role="tree" aria-label="Org chart" style="margin-top: 1rem;">
                    <template x-for="row in rows()" :key="row.id">
                        <div
                            class="hr-oc-row"
                            role="treeitem"
                            :id="'hr-oc-' + row.id"
                            :aria-expanded="row.kids ? (open[row.id] ? 'true' : 'false') : null"
                            :style="'margin-left: ' + (row.depth * 2.25) + 'rem'"
                            :class="{ 'hr-oc-child': row.depth > 0, 'hr-oc-hit': hitSet[row.id], 'hr-oc-current': hits[current] === row.id, 'hr-oc-self': row.n.self }"
                        >
                            <template x-if="row.kids">
                                <button
                                    type="button"
                                    class="hr-oc-toggle"
                                    x-on:click="toggle(row.id)"
                                    :aria-label="(open[row.id] ? 'Hide the team of ' : 'Show the team of ') + row.n.name"
                                    x-text="open[row.id] ? '−' : '+'"
                                ></button>
                            </template>
                            <template x-if="! row.kids">
                                <span class="hr-oc-spacer"></span>
                            </template>

                            <template x-if="row.n.avatar">
                                <img class="hr-oc-avatar" :src="row.n.avatar" alt="" loading="lazy">
                            </template>
                            <template x-if="! row.n.avatar">
                                <span class="hr-oc-avatar hr-oc-initials" :style="'background: ' + row.n.color" x-text="row.n.initials" aria-hidden="true"></span>
                            </template>

                            <div class="hr-oc-text">
                                <div>
                                    <template x-if="row.n.open">
                                        <a class="hr-oc-name" :href="viewUrl.replace('__id__', row.id)" x-text="row.n.name"></a>
                                    </template>
                                    <template x-if="! row.n.open">
                                        <span class="hr-oc-name" x-text="row.n.name"></span>
                                    </template>
                                    <span class="hr-oc-you" x-show="row.n.self">You</span>
                                </div>
                                <div class="hr-oc-meta">
                                    <span x-text="row.n.title || 'No job title'"></span>
                                    <span class="hr-oc-dept" x-show="row.n.dept" x-text="row.n.dept"></span>
                                </div>
                                <div class="hr-oc-manager" x-show="row.depth === 0 && row.n.parent" x-text="row.n.parent ? 'Reports to ' + nodes[row.n.parent].name : ''"></div>
                            </div>

                            <span
                                class="hr-oc-count"
                                x-show="row.n.kids.length"
                                x-text="row.n.kids.length + ' direct' + (row.n.size > row.n.kids.length ? ' · ' + row.n.size + ' in total' : '')"
                            ></span>
                        </div>
                    </template>
                    <p class="hr-oc-empty" x-show="scope !== null && ! tops().length" x-cloak>No one in this department.</p>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
