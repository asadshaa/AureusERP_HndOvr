@php($person = $people[$id])
@php($team = $children[$id] ?? [])
<li class="tb-item" data-id="{{ $id }}" wire:key="tb-{{ $id }}">
    <div class="tb-card"
         draggable="true"
         x-on:dragstart="start($event, {{ $id }})"
         x-on:dragend="stop()"
         x-on:dragover.prevent="over($event, {{ $id }})"
         x-on:dragleave="leave({{ $id }})"
         x-on:drop.prevent="drop({{ $id }})"
         x-bind:class="{ 'tb-over': overId === {{ $id }} && canDrop({{ $id }}), 'tb-bad': overId === {{ $id }} && ! canDrop({{ $id }}), 'tb-dragging': dragId === {{ $id }}, 'tb-hit': isHit({{ $id }}), 'tb-picked': pickedId === {{ $id }} }">
        @if (count($team))
            <button type="button" class="tb-toggle" x-on:click="toggle({{ $id }}, {{ $depth }})" x-bind:aria-label="isOpen({{ $id }}, {{ $depth }}) ? 'Hide team' : 'Show team'">
                <span x-text="isOpen({{ $id }}, {{ $depth }}) ? '−' : '+'"></span>
            </button>
        @else
            <span class="tb-spacer"></span>
        @endif
        <span class="tb-grip" title="Drag to move" aria-hidden="true">⠿</span>
        <span class="tb-avatar">{{ \Illuminate\Support\Str::of($person->name)->explode(' ')->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</span>
        <span class="tb-text">
            <span class="tb-name">{{ $person->name }}</span>
            <span class="tb-meta">
                @if ($person->job_title) <span>{{ $person->job_title }}</span> @endif
                @if ($person->department) <span class="tb-dept">{{ $person->department->name }}</span> @endif
            </span>
        </span>
        @if (count($team))
            <span class="tb-count" title="People who report directly to {{ $person->name }}">{{ count($team) }} in team</span>
        @endif
        <span class="tb-actions">
            <template x-if="pickedId === null">
                <button type="button" class="tb-btn" x-on:click="pick({{ $id }})">Move</button>
            </template>
            <template x-if="pickedId !== null && pickedId !== {{ $id }} && canDrop({{ $id }})">
                <button type="button" class="tb-btn tb-btn-primary" x-on:click="drop({{ $id }})">Put under {{ \Illuminate\Support\Str::before($person->name, ' ') }}</button>
            </template>
            <template x-if="pickedId === {{ $id }}">
                <button type="button" class="tb-btn" x-on:click="cancel()">Cancel</button>
            </template>
        </span>
    </div>

    @if (count($team))
        <ul class="tb-list" x-show="isOpen({{ $id }}, {{ $depth }})">
            @foreach ($team as $childId)
                @include('employees::filament.pages.team-board-node', ['id' => $childId, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>
