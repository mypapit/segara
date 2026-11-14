// Segara administration enhancements. No-JS fallback: the Markdown snippet is
// visible in a readonly input and can be copied manually.
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button) {
            return;
        }
        var text = button.getAttribute('data-copy') || '';
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                button.textContent = 'Copied';
                window.setTimeout(function () { button.textContent = 'Copy'; }, 1500);
            });
        } else {
            var input = button.parentElement.querySelector('input');
            if (input) {
                input.focus();
                input.select();
                document.execCommand('copy');
                button.textContent = 'Copied';
                window.setTimeout(function () { button.textContent = 'Copy'; }, 1500);
            }
        }
    });
})();

// Media insert: place the snippet at the caret as plain text. Never innerHTML —
// the snippet carries alt text an author typed, and that is data, not markup.
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-media-insert]');
        if (!button) {
            return;
        }
        var textarea = document.getElementById('body_markdown');
        if (!textarea) {
            return;
        }
        var snippet = button.getAttribute('data-snippet') || '';
        var start = textarea.selectionStart || 0;
        var end = textarea.selectionEnd || 0;
        var before = textarea.value.slice(0, start);
        var after = textarea.value.slice(end);
        var lead = before === '' || before.slice(-1) === '\n' ? '' : '\n\n';
        var trail = after === '' || after.slice(0, 1) === '\n' ? '' : '\n\n';
        var inserted = lead + snippet + trail;

        textarea.focus();
        if (typeof textarea.setRangeText === 'function') {
            // setRangeText keeps the native undo stack; assigning .value wipes it.
            textarea.setRangeText(inserted, start, end, 'end');
        } else {
            textarea.value = before + inserted + after;
            var caret = start + inserted.length;
            textarea.setSelectionRange(caret, caret);
        }
        // Assigning .value and setRangeText both bypass the input event, so the
        // unsaved-changes guard would never see a picker-only edit.
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    });
})();

// Unsaved-changes guard. The submit handler clears the flag before the browser
// navigates, so a successful save never warns; a failed validation re-render is
// a fresh page, so the flag starts clean there too.
(function () {
    'use strict';

    var form = document.querySelector('form[data-page-form]');
    if (!form) {
        return;
    }
    var dirty = false;
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (event) {
        if (!dirty) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });
})();
