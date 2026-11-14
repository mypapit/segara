// Segara block ordering. Progressive enhancement: drag a row by its handle to
// reorder it, then save. The no-JS fallback (up/down buttons and the Save order
// submit) continues to work when JavaScript is unavailable.
(function () {
    'use strict';

    // The sortable list is a container and the save control is a sibling form:
    // dragging never rewrites the payload of an arrow-button submit.
    var containers = document.querySelectorAll('.block-order');
    Array.prototype.forEach.call(containers, function (container) {
        var list = container.querySelector('.block-order-list');
        var saveForm = container.querySelector('form.block-order-save');
        var orderInput = saveForm ? saveForm.querySelector('.js-block-order') : null;
        if (!list || !saveForm || !orderInput) {
            return;
        }
        var dragging = null;

        function syncOrder() {
            var ids = [];
            Array.prototype.forEach.call(list.querySelectorAll('.block-order-item'), function (item) {
                var id = item.getAttribute('data-id');
                if (id) {
                    ids.push(id);
                }
            });
            orderInput.value = JSON.stringify(ids);
        }

        function insertAfterDragging(item, event) {
            if (!dragging || dragging === item) {
                return;
            }
            var rect = item.getBoundingClientRect();
            var after = (event.clientY - rect.top) > (rect.height / 2);
            list.insertBefore(dragging, after ? item.nextSibling : item);
        }

        Array.prototype.forEach.call(list.querySelectorAll('.block-order-item'), function (item) {
            item.addEventListener('dragstart', function (event) {
                dragging = item;
                item.classList.add('dragging');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                }
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('dragging');
                dragging = null;
            });
            item.addEventListener('dragover', function (event) {
                event.preventDefault();
                insertAfterDragging(item, event);
            });
            item.addEventListener('drop', function (event) {
                event.preventDefault();
                insertAfterDragging(item, event);
                syncOrder();
            });
        });

        saveForm.addEventListener('submit', syncOrder);
    });
})();
