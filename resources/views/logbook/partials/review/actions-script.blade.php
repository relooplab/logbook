<script>
    // Action items: pemilik boleh toggle + tambah; reviewer boleh tambah/hapus.
    (function () {
        var actionList = document.getElementById('action-items-list');
        if (!actionList) return;
        var actionAddForm = document.getElementById('action-item-add-form');
        var logbookId = {{ $logbook->id }};
        var ownerCanToggle = {{ auth()->user()->can('update', $logbook) ? 'true' : 'false' }};

        function actionItemRow(item, interactive) {
            interactive = interactive !== undefined ? interactive : true;
            var row = document.createElement('div');
            row.className = 'flex items-start gap-2 action-item-row';
            row.dataset.itemId = item.id;
            if (interactive) {
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'action-item-toggle rounded bg-bg-surface mt-1';
                checkbox.setAttribute('aria-label', 'Tandai selesai: ' + item.text);
                checkbox.checked = !!item.is_done;
                row.appendChild(checkbox);
            } else {
                var icon = document.createElement('span');
                icon.className = 'material-symbols-outlined icon-sm ' + (item.is_done ? '' : 'text-text-secondary');
                icon.textContent = item.is_done ? 'check_circle' : 'radio_button_unchecked';
                row.appendChild(icon);
            }
            var text = document.createElement('span');
            text.className = 'flex-1 min-w-0 text-sm' + (item.is_done ? ' line-through text-text-secondary' : '');
            text.textContent = item.text;
            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'action-item-delete text-status-danger hover:underline text-xs shrink-0';
            del.textContent = 'Hapus';
            row.appendChild(text);
            row.appendChild(del);
            return row;
        }

        actionList.addEventListener('change', function (e) {
            if (e.target.classList.contains('action-item-toggle')) {
                var row = e.target.closest('.action-item-row');
                var id = row.dataset.itemId;
                var previous = !e.target.checked;
                fetch('/logbook/' + logbookId + '/action-items/' + id + '/toggle', {
                    method: 'POST',
                    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json'},
                }).then(r => { if (!r.ok) throw new Error('Gagal menyimpan.'); return r.json(); }).then(data => {
                    e.target.checked = data.is_done;
                    var textEl = row.querySelector('span');
                    textEl.classList.toggle('line-through', data.is_done);
                    textEl.classList.toggle('text-text-secondary', data.is_done);
                }).catch(() => { e.target.checked = previous; window.alert('Action item belum tersimpan. Coba lagi.'); });
            }
        });

        actionList.addEventListener('click', function (e) {
            if (e.target.classList.contains('action-item-delete')) {
                var row = e.target.closest('.action-item-row');
                var id = row.dataset.itemId;
                if (!confirm('Hapus ini?')) return;
                fetch('/logbook/' + logbookId + '/action-items/' + id, {
                    method: 'DELETE',
                    headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json'},
                }).then(r => { if (!r.ok) throw new Error('Gagal menghapus.'); return r.json(); }).then(() => {
                    row.remove();
                    if (!actionList.querySelector('.action-item-row')) {
                        actionList.innerHTML = '<p class="text-sm text-text-secondary">Belum ada.</p>';
                    }
                }).catch(() => window.alert('Action item belum terhapus. Coba lagi.'));
            }
        });

        if (!actionAddForm) return;
        var actionToggle = document.getElementById('action-item-add-toggle');
        function setActionFormOpen(open) {
            actionAddForm.classList.toggle('hidden', !open);
            actionToggle.setAttribute('aria-expanded', String(open));
            if (open) actionAddForm.querySelector('input[name="text"]').focus();
            else actionToggle.focus();
        }
        actionToggle.addEventListener('click', function () { setActionFormOpen(actionAddForm.classList.contains('hidden')); });
        document.getElementById('action-item-add-cancel').addEventListener('click', function () { setActionFormOpen(false); });
        actionAddForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = actionAddForm.querySelector('input[name="text"]');
            var val = input.value.trim();
            if (!val) return;
            var submitButton = actionAddForm.querySelector('button[type="submit"]');
            var error = document.getElementById('action-item-error');
            submitButton.disabled = true;
            error.classList.add('hidden');
            fetch('/logbook/' + logbookId + '/action-items', {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({text: val}),
            }).then(r => { if (!r.ok) throw new Error('Gagal menambah.'); return r.json(); }).then(item => {
                input.value = '';
                var empty = actionList.querySelector('p');
                if (empty) empty.remove();
                actionList.appendChild(actionItemRow(item, ownerCanToggle));
                setActionFormOpen(false);
            }).catch(() => {
                error.textContent = 'Action item belum tersimpan. Coba lagi.';
                error.classList.remove('hidden');
            }).finally(() => { submitButton.disabled = false; });
        });
    })();
</script>
