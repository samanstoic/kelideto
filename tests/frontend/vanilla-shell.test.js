import { afterEach, describe, expect, it } from 'vitest';
import { renderShell } from '../../resources/js/vanilla/ui/shell.js';

describe('vanilla responsive shell', () => {
    let root;

    function mount() {
        root = document.createElement('div');
        root.id = 'vanilla-app';
        document.body.replaceChildren(root);
        const content = document.createElement('main');
        renderShell(root, 'landing', content);
    }

    afterEach(() => {
        document.body.classList.remove('kp-la-lock-scroll');
        document.body.replaceChildren();
    });

    it('exposes the mobile navigation as a dialog only while it is open', () => {
        mount();

        const drawer = root.querySelector('#kp-la-drawer');
        const openButton = root.querySelector('[aria-controls="kp-la-drawer"]');
        expect(drawer.hidden).toBe(true);
        expect(drawer.getAttribute('role')).toBeNull();
        expect(drawer.getAttribute('aria-modal')).toBeNull();

        openButton.click();

        expect(drawer.hidden).toBe(false);
        expect(drawer.getAttribute('role')).toBe('dialog');
        expect(drawer.getAttribute('aria-modal')).toBe('true');
        expect(document.body.classList.contains('kp-la-lock-scroll')).toBe(true);

        drawer.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(drawer.hidden).toBe(true);
        expect(drawer.getAttribute('role')).toBeNull();
        expect(drawer.getAttribute('aria-modal')).toBeNull();
        expect(document.body.classList.contains('kp-la-lock-scroll')).toBe(false);
        expect(document.activeElement).toBe(openButton);
    });

    it('keeps mobile footer disclosure state synchronized with its expanded state', () => {
        mount();

        const toggle = root.querySelector('.kp-la-footer-toggle');
        const list = root.querySelector(`#${toggle.getAttribute('aria-controls')}`);

        toggle.click();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(list.classList.contains('is-open')).toBe(true);

        toggle.click();
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(list.classList.contains('is-open')).toBe(false);
    });
});
