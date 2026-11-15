/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.0.0
 * @created   2026-11-15
 *
 * Button "Copy the text into a followup" at the attachments in the timeline of a ticket, change
 * or problem, next to GLPI's own buttons (edit, delete, import exclusion list), which appear when
 * the mouse is over the attachment.
 *
 * GLPI offers no hook for these buttons, so they are added here. The settings come from the meta
 * tag "pdf2text-attachments" (AttachmentButton::headerTag()); without it (other pages, no right to
 * add a followup) nothing happens. A click calls the plugin (AttachmentController): "added" reloads
 * the page with the new followup, "done" asks whether to add the text again.
 */
(function () {
    'use strict';

    const meta = document.querySelector('meta[name="pdf2text-attachments"]');
    if (!meta) {
        return;
    }
    let settings;
    try {
        settings = JSON.parse(meta.content);
    } catch (e) {
        return;
    }
    const extensions = (settings.extensions || []).map((ext) => ext.toLowerCase());

    /** Escapes text for HTML (the dialogs and toasts of GLPI show HTML). */
    function escape(text) {
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    /** Headers of GLPI's AJAX requests: GLPI 11 checks the CSRF token of the page (GLPI 12 the origin). */
    function ajaxHeaders() {
        const headers = {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'};
        const token = document.querySelector('meta[property="glpi:csrf_token"]');
        if (token && token.content) {
            headers['X-Glpi-Csrf-Token'] = token.content;
        }
        return headers;
    }

    /** Message in a toast of GLPI (or an alert where GLPI's toasts are missing). */
    function notify(message, isError) {
        const toast = isError
            ? (typeof glpi_toast_error === 'function' ? glpi_toast_error : null)
            : (typeof glpi_toast_info === 'function' ? glpi_toast_info : null);
        if (toast) {
            toast(escape(message), settings.texts.title);
        } else {
            window.alert(message);
        }
    }

    /** Question with GLPI's dialog (or the browser's where it is missing); onYes runs on "yes". */
    function ask(message, onYes) {
        if (typeof glpi_confirm === 'function') {
            glpi_confirm({
                title: settings.texts.title,
                message: '<p>' + escape(message) + '</p>',
                confirm_label: settings.texts.again,
                confirm_callback: onYes,
            });
        } else if (window.confirm(message)) {
            onYes();
        }
    }

    /** Sends the click to the plugin; force = add the text again although it was added before. */
    function convert(button, documentsId, force) {
        if (button.dataset.pdf2textBusy === '1') {
            return;
        }
        button.dataset.pdf2textBusy = '1';
        const icon = button.querySelector('i');
        icon.className = 'spinner-border spinner-border-sm';

        const body = new URLSearchParams({
            itemtype: settings.itemtype,
            items_id: String(settings.items_id),
            documents_id: String(documentsId),
            force: force ? '1' : '0',
        });
        // Button usable again (before a question, whose "yes" sends the next request)
        const ready = () => {
            icon.className = 'ti ti-file-text';
            delete button.dataset.pdf2textBusy;
        };
        window.fetch(settings.url, {method: 'POST', credentials: 'same-origin', headers: ajaxHeaders(), body: body})
            .then((response) => response.json())
            .then((answer) => {
                if (answer.status === 'added') {
                    // The new followup is in the timeline after the reload; GLPI shows the message
                    window.location.reload();
                    return;
                }
                ready();
                if (answer.status === 'done') {
                    ask(answer.message, () => convert(button, documentsId, true));
                } else {
                    notify(answer.message, answer.status !== 'empty' && answer.status !== 'ignored');
                }
            })
            .catch(() => {
                ready();
                notify(settings.texts.error, true);
            });
    }

    /**
     * Adds the button to every attachment of a file type that is read. GLPI lists the files of
     * the timeline in ".sub-documents" (images and videos are shown as previews, without these
     * buttons); each has a link to front/document.send.php?docid=<id>.
     */
    function addButtons(root) {
        root.querySelectorAll('.sub-documents .list-group-item-actions').forEach((actions) => {
            if (actions.querySelector('.pdf2text-convert')) {
                return;
            }
            const row = actions.closest('.row');
            const link = row ? row.querySelector('a[href*="document.send.php"]') : null;
            if (!link) {
                return;
            }
            const documentsId = new URL(link.href, window.location.href).searchParams.get('docid');
            // File name = text of the link; its extension after the last dot, e.g. "report.PDF" -> "pdf"
            const filename = link.textContent.trim();
            const extension = filename.includes('.') ? filename.split('.').pop().toLowerCase() : '';
            if (!documentsId || !extensions.includes(extension)) {
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-ghost-secondary pdf2text-convert';
            button.title = settings.texts.button;
            button.setAttribute('aria-label', settings.texts.button);
            button.setAttribute('data-bs-toggle', 'tooltip');
            button.setAttribute('data-bs-placement', 'top');
            const icon = document.createElement('i');
            icon.className = 'ti ti-file-text';
            icon.setAttribute('aria-hidden', 'true');
            button.appendChild(icon);
            button.addEventListener('click', (event) => {
                event.preventDefault();
                convert(button, documentsId, false);
            });
            // First of the buttons, so it is at the same place for every attachment
            actions.insertBefore(button, actions.firstChild);
            if (window.bootstrap && window.bootstrap.Tooltip) {
                window.bootstrap.Tooltip.getOrCreateInstance(button);
            }
        });
    }

    addButtons(document);
    // GLPI reloads parts of the timeline without a new page (e.g. after an answer): add the
    // buttons to the new attachments as well
    // (at most once per frame, however many elements change at once)
    let scheduled = false;
    new MutationObserver((mutations) => {
        if (scheduled || !mutations.some((mutation) => mutation.addedNodes.length > 0)) {
            return;
        }
        scheduled = true;
        window.requestAnimationFrame(() => {
            scheduled = false;
            addButtons(document);
        });
    }).observe(document.body, {childList: true, subtree: true});
})();
