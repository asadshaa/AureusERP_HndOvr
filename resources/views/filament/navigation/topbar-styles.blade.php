<style>
    /*
     * Top bar on desktop: one tidy header row (menu, logo, search, notifications,
     * company, user) and the current section's menu underneath, instead of one
     * long wrapping row that scattered the logo and buttons across several lines.
     */
    @media (min-width: 1024px) {
        .fi-topbar {
            display: grid;
            grid-template-columns: auto auto 1fr auto;
            grid-template-areas:
                "menu start . end"
                "nav  nav   nav nav";
            align-items: center;
            padding-top: .5rem;
            padding-bottom: 0;
        }

        .fi-topbar > .fi-dropdown { grid-area: menu; }

        .fi-topbar > .fi-topbar-start { grid-area: start; margin-inline-end: 0; }

        .fi-topbar > .fi-topbar-end { grid-area: end; }

        .fi-topbar > .fi-topbar-nav-groups {
            grid-area: nav;
            margin: .5rem 0 0;
            padding: .375rem 0 .5rem;
            border-top: 1px solid rgba(127, 127, 127, .2);
            column-gap: .25rem;
            row-gap: .25rem;
        }

        .fi-topbar > .fi-topbar-nav-groups > .fi-topbar-item:first-child { margin-inline-end: .75rem; }
    }
</style>
