{{-- Shared look of the HR report pages (Employees -> Reportings); tables use the attendance report's inline styles. --}}
<style>
    .hr-report-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: 1rem; }
    .hr-report-stat { padding: 1rem 1.25rem; border: 1px solid rgba(127, 127, 127, .2); border-radius: .75rem; background: rgba(127, 127, 127, .04); }
    .hr-report-stat-label { font-size: .8125rem; opacity: .7; }
    .hr-report-stat-value { margin-top: .25rem; font-size: 1.5rem; font-weight: 600; font-variant-numeric: tabular-nums; }
    .hr-report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1.5rem; }
    .hr-report-note { font-size: .8125rem; opacity: .7; }
    .hr-report-scroll { overflow-x: auto; }
    @media print {
        .fi-topbar, .fi-header-actions, .fi-page-sub-navigation-sidebar-ctn, .fi-page-sub-navigation-tabs, .fi-sc-form { display: none !important; }
        .hr-report-scroll { overflow: visible; }
    }
</style>
