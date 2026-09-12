import './bootstrap';

import Alpine from 'alpinejs';

import { registerTheme } from './theme';
import { registerSidebar } from './sidebar';
import { registerToasts } from './toasts';
import { registerFocusTrap } from './focus-trap';
import { registerComponents } from './components';

/*
|--------------------------------------------------------------------------
| Alpine wiring
|--------------------------------------------------------------------------
|
| Stores and directives are registered BEFORE Alpine.start() so the first render
| already sees $store.theme / $store.sidebar / $store.toasts and x-trap.
|
*/
registerTheme(Alpine);
registerSidebar(Alpine);
registerToasts(Alpine);
registerFocusTrap(Alpine);
registerComponents(Alpine);

window.Alpine = Alpine;

Alpine.start();

/*
|--------------------------------------------------------------------------
| Global shortcuts
|--------------------------------------------------------------------------
*/

// Ctrl/Cmd+K focuses the global search field in the topbar.
document.addEventListener('keydown', (event) => {
    const isSearch = (event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey);

    if (!isSearch) {
        return;
    }

    const field = document.querySelector('[data-global-search]');

    if (field) {
        event.preventDefault();
        field.focus();
        field.select?.();
    }
});

// Escape closes the mobile drawer from anywhere on the page.
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && Alpine.store('sidebar')?.drawer) {
        Alpine.store('sidebar').closeDrawer();
    }
});
