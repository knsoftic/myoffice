/**
 * Sidebar state: the lg+ icon rail and the sub-lg off-canvas drawer.
 *
 * The rail is expressed as a single `.is-rail` class on <html> (see resources/css/app.css).
 * The inline head script sets it before first paint, so a collapsed sidebar never flashes
 * open; this store just keeps toggling the same class.
 */

export const RAIL_KEY = 'sidebar-rail';

export function readRail() {
    try {
        return window.localStorage.getItem(RAIL_KEY) === '1';
    } catch (e) {
        return false;
    }
}

function writeRail(collapsed) {
    try {
        window.localStorage.setItem(RAIL_KEY, collapsed ? '1' : '0');
    } catch (e) {
        /* storage disabled */
    }
}

export function applyRail(collapsed) {
    document.documentElement.classList.toggle('is-rail', !!collapsed);
}

export function registerSidebar(Alpine) {
    Alpine.store('sidebar', {
        collapsed: readRail(),
        drawer: false,

        init() {
            applyRail(this.collapsed);

            window.addEventListener('storage', (event) => {
                if (event.key !== RAIL_KEY) {
                    return;
                }

                this.collapsed = readRail();
                applyRail(this.collapsed);
            });

            // Leaving mobile widths should never leave the drawer "open" underneath.
            if (window.matchMedia) {
                const desktop = window.matchMedia('(min-width: 1024px)');
                const close = () => {
                    if (desktop.matches) {
                        this.closeDrawer();
                    }
                };

                if (desktop.addEventListener) {
                    desktop.addEventListener('change', close);
                } else if (desktop.addListener) {
                    desktop.addListener(close);
                }
            }
        },

        toggle() {
            this.collapsed = !this.collapsed;
            writeRail(this.collapsed);
            applyRail(this.collapsed);
        },

        expand() {
            this.collapsed = false;
            writeRail(false);
            applyRail(false);
        },

        openDrawer() {
            this.drawer = true;
            document.documentElement.classList.add('overflow-hidden');
        },

        closeDrawer() {
            this.drawer = false;
            document.documentElement.classList.remove('overflow-hidden');
        },

        toggleDrawer() {
            this.drawer ? this.closeDrawer() : this.openDrawer();
        },
    });
}

export default { RAIL_KEY, readRail, applyRail, registerSidebar };
