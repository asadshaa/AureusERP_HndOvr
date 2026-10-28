{{-- Home dashboard styles. Filament ships pre-built CSS without Tailwind utilities for app views, so the home cards use these small scoped classes on Filament's own colour variables (light and dark). --}}
<style>
    .ah-stack { display: flex; flex-direction: column; gap: 1rem; }
    .ah-row { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
    .ah-between { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
    .ah-grow { flex: 1 1 auto; min-width: 0; }
    .ah-muted { color: var(--gray-500); font-size: .8125rem; line-height: 1.25rem; }
    .ah-small { font-size: .75rem; line-height: 1rem; color: var(--gray-500); }
    .ah-strong { font-weight: 600; color: var(--gray-950); }
    :where(.dark) .ah-strong { color: #fff; }
    :where(.dark) .ah-muted, :where(.dark) .ah-small { color: var(--gray-400); }
    .ah-link { color: var(--primary-600); font-weight: 600; font-size: .8125rem; text-decoration: none; }
    .ah-link:hover { text-decoration: underline; }
    :where(.dark) .ah-link { color: var(--primary-400); }

    .ah-grid { display: grid; gap: .875rem; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 640px) { .ah-grid-2, .ah-grid-3, .ah-grid-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (min-width: 1024px) { .ah-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } .ah-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

    .ah-hero { position: relative; overflow: hidden; border-radius: 1rem; padding: 1.5rem; border: 1px solid var(--gray-200);
        background: linear-gradient(135deg, var(--primary-50) 0%, #fff 60%); }
    :where(.dark) .ah-hero { border-color: rgba(255, 255, 255, .1); background: linear-gradient(135deg, color-mix(in oklab, var(--primary-950) 70%, transparent) 0%, var(--gray-900) 65%); }
    .ah-hero-title { margin: 0; font-size: 1.5rem; line-height: 2rem; font-weight: 700; letter-spacing: -.01em; color: var(--gray-950); }
    :where(.dark) .ah-hero-title { color: #fff; }
    @media (min-width: 640px) { .ah-hero-title { font-size: 1.75rem; line-height: 2.25rem; } }
    .ah-hero-sub { margin: .25rem 0 0; font-size: .9375rem; color: var(--gray-600); }
    :where(.dark) .ah-hero-sub { color: var(--gray-300); }
    .ah-hint { display: inline-flex; align-items: center; gap: .375rem; margin-top: .75rem; font-size: .8125rem; color: var(--gray-600);
        background: rgba(255, 255, 255, .7); border: 1px dashed var(--gray-300); border-radius: .5rem; padding: .375rem .625rem; }
    :where(.dark) .ah-hint { color: var(--gray-300); background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .15); }

    .ah-actions { display: grid; gap: .75rem; margin-top: 1.25rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 11.5rem), 1fr)); }
    .ah-action { display: flex; align-items: center; gap: .75rem; min-height: 4rem; padding: .75rem .875rem; border-radius: .75rem; text-decoration: none;
        border: 1px solid var(--gray-200); background: #fff; transition: border-color .15s, box-shadow .15s, transform .15s; }
    .ah-action:hover, .ah-action:focus-visible { border-color: var(--primary-400); box-shadow: 0 4px 12px -4px rgba(0, 0, 0, .12); transform: translateY(-1px); outline: none; }
    :where(.dark) .ah-action { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .1); }
    :where(.dark) .ah-action:hover, :where(.dark) .ah-action:focus-visible { border-color: var(--primary-500); }
    .ah-action-primary { background: var(--primary-600); border-color: var(--primary-600); }
    .ah-action-primary .ah-action-label, .ah-action-primary .ah-action-hint { color: #fff !important; }
    .ah-action-primary .ah-chip { background: rgba(255, 255, 255, .2) !important; color: #fff !important; }
    :where(.dark) .ah-action-primary { background: var(--primary-600); border-color: var(--primary-500); }
    .ah-action-label { display: block; font-weight: 600; font-size: .875rem; color: var(--gray-950); }
    :where(.dark) .ah-action-label { color: #fff; }
    .ah-action-hint { display: block; font-size: .75rem; color: var(--gray-500); }
    :where(.dark) .ah-action-hint { color: var(--gray-400); }

    .ah-chip { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: .625rem; }
    .ah-chip svg { width: 1.25rem; height: 1.25rem; }
    .ah-chip-lg { width: 3rem; height: 3rem; border-radius: .875rem; }
    .ah-chip-lg svg { width: 1.5rem; height: 1.5rem; }
    .ah-tone-primary { background: var(--primary-50); color: var(--primary-600); }
    .ah-tone-success { background: var(--success-50); color: var(--success-600); }
    .ah-tone-warning { background: var(--warning-50); color: var(--warning-600); }
    .ah-tone-danger { background: var(--danger-50); color: var(--danger-600); }
    .ah-tone-info { background: var(--info-50); color: var(--info-600); }
    .ah-tone-gray { background: var(--gray-100); color: var(--gray-600); }
    :where(.dark) .ah-tone-primary { background: color-mix(in oklab, var(--primary-500) 18%, transparent); color: var(--primary-400); }
    :where(.dark) .ah-tone-success { background: color-mix(in oklab, var(--success-500) 18%, transparent); color: var(--success-400); }
    :where(.dark) .ah-tone-warning { background: color-mix(in oklab, var(--warning-500) 18%, transparent); color: var(--warning-400); }
    :where(.dark) .ah-tone-danger { background: color-mix(in oklab, var(--danger-500) 18%, transparent); color: var(--danger-400); }
    :where(.dark) .ah-tone-info { background: color-mix(in oklab, var(--info-500) 18%, transparent); color: var(--info-400); }
    :where(.dark) .ah-tone-gray { background: rgba(255, 255, 255, .08); color: var(--gray-300); }

    .ah-tile { display: flex; flex-direction: column; gap: .5rem; padding: 1rem; border-radius: .75rem; text-decoration: none;
        border: 1px solid var(--gray-200); background: #fff; min-width: 0; }
    a.ah-tile:hover { border-color: var(--primary-400); }
    :where(.dark) .ah-tile { background: rgba(255, 255, 255, .03); border-color: rgba(255, 255, 255, .1); }
    :where(.dark) a.ah-tile:hover { border-color: var(--primary-500); }
    .ah-tile-active { border-color: var(--primary-500) !important; box-shadow: 0 0 0 1px var(--primary-500); }
    .ah-value { font-size: 1.75rem; line-height: 2rem; font-weight: 700; letter-spacing: -.02em; color: var(--gray-950); font-variant-numeric: tabular-nums; }
    :where(.dark) .ah-value { color: #fff; }
    .ah-value-sm { font-size: 1.25rem; line-height: 1.75rem; }
    .ah-label { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--gray-500); }
    :where(.dark) .ah-label { color: var(--gray-400); }
    .ah-text-success { color: var(--success-600); } :where(.dark) .ah-text-success { color: var(--success-400); }
    .ah-text-warning { color: var(--warning-600); } :where(.dark) .ah-text-warning { color: var(--warning-400); }
    .ah-text-danger { color: var(--danger-600); } :where(.dark) .ah-text-danger { color: var(--danger-400); }
    .ah-text-info { color: var(--info-600); } :where(.dark) .ah-text-info { color: var(--info-400); }

    .ah-bar { height: .5rem; border-radius: 999px; background: var(--gray-100); overflow: hidden; }
    :where(.dark) .ah-bar { background: rgba(255, 255, 255, .08); }
    .ah-bar > span { display: block; height: 100%; border-radius: 999px; background: var(--primary-500); }
    .ah-bar-success > span { background: var(--success-500); }
    .ah-bar-warning > span { background: var(--warning-500); }
    .ah-bar-danger > span { background: var(--danger-500); }

    .ah-list { list-style: none; margin: 0; padding: 0; }
    .ah-list > li { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .625rem 0; border-top: 1px solid var(--gray-100); min-width: 0; }
    .ah-list > li:first-child { border-top: 0; padding-top: 0; }
    :where(.dark) .ah-list > li { border-color: rgba(255, 255, 255, .06); }
    .ah-list-wrap > li { flex-wrap: wrap; }
    .ah-ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ah-names { margin: 0; font-size: .8125rem; color: var(--gray-700); line-height: 1.25rem; overflow-wrap: anywhere; }
    :where(.dark) .ah-names { color: var(--gray-300); }

    .ah-empty { display: flex; align-items: center; gap: .75rem; padding: 1rem; border-radius: .75rem; border: 1px dashed var(--gray-300); color: var(--gray-600); font-size: .875rem; }
    :where(.dark) .ah-empty { border-color: rgba(255, 255, 255, .15); color: var(--gray-400); }
    .ah-empty svg { width: 1.5rem; height: 1.5rem; flex: none; color: var(--gray-400); }

    .ah-heading { margin: 0 0 .75rem; font-size: .875rem; font-weight: 600; color: var(--gray-950); display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
    :where(.dark) .ah-heading { color: #fff; }
    .ah-button-reset { appearance: none; background: none; border: 0; padding: 0; margin: 0; width: 100%; text-align: inherit; font: inherit; color: inherit; cursor: pointer; }
</style>
