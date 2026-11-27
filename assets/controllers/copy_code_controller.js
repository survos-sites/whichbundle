import { Controller } from '@hotwired/stimulus';

/**
 * Adds a Copy button to every <pre> inside the element, for markup this app does not own
 * (the MCP connect page comes from survos/ai-chat-bundle).
 *
 * @value label  Button text. Defaults to "Copy".
 */
export default class extends Controller {
    static values = { label: { type: String, default: 'Copy' } };

    connect() {
        this.element.querySelectorAll('pre').forEach((pre) => {
            if (pre.dataset.copyCode) {
                return;
            }
            pre.dataset.copyCode = 'ready';
            pre.classList.add('position-relative', 'pe-5');

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-light position-absolute top-0 end-0 m-2';
            button.textContent = this.labelValue;
            button.setAttribute('aria-label', 'Copy to clipboard');
            button.addEventListener('click', () => this.#copy(pre, button));
            pre.append(button);
        });
    }

    async #copy(pre, button) {
        const text = (pre.querySelector('code') ?? pre).textContent.trim();
        try {
            await navigator.clipboard.writeText(text);
            button.textContent = 'Copied';
        } catch {
            button.textContent = 'Press ⌘C';
            window.getSelection().selectAllChildren(pre.querySelector('code') ?? pre);
        }
        setTimeout(() => { button.textContent = this.labelValue; }, 2000);
    }
}
