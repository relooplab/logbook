{{--
    Autosave draf ke server (dipakai wizard create/create-revisi & halaman edit).
    - Klik tombol Simpan Draf: simpan via AJAX tanpa meninggalkan halaman.
    - Setelah sukses: autosave otomatis tiap 2 menit (tanpa klik lagi).
    - FormData memuat file hanya bila user memilih file baru; `_method`
      mengikuti hidden field form (edit memakai @method('PUT')).
    Presentasi status minimal: satu <p data-draft-status> di dekat tombol.
--}}
<script>
    (function () {
        window.LbDraft = {
            init: function (opts) {
                var form = opts && opts.form;
                if (!form) return null;
                var button = opts.button || null;
                var statusEl = opts.statusEl || null;
                var entryInput = opts.entryInput || null;
                var intervalMs = opts.intervalMs || 120000;
                var minFields = typeof opts.minFields === 'function' ? opts.minFields : function () { return true; };
                var url = opts.url || form.getAttribute('action');
                var timer = null;
                var saving = false;

                function timeLabel() {
                    return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                }

                function setStatus(text, isError) {
                    if (!statusEl) return;
                    if (!text) { statusEl.textContent = ''; statusEl.classList.add('hidden'); return; }
                    statusEl.textContent = text;
                    statusEl.classList.remove('hidden', 'text-status-danger', 'text-text-secondary');
                    statusEl.classList.add(isError ? 'text-status-danger' : 'text-text-secondary');
                }

                // Stempel simpanan server di bawah tombol (terpisah dari
                // status transient di atas; autosave browser tidak menyentuh ini).
                function setSavedAt(text) {
                    var el = document.querySelector('[data-draf-saved-at]');
                    if (!el) return;
                    el.textContent = text;
                    el.classList.toggle('hidden', !text);
                }

                // Kalimat status gabungan aside.
                function updateCombined() {
                    var el = document.querySelector('[data-status-combined]');
                    if (!el) return;
                    el.textContent = (el.dataset.workflow || 'Draf') + ' · tersimpan baru saja · belum dikirim';
                }

                function saveNow() {
                    if (saving) return Promise.resolve(false);
                    if (!minFields()) return Promise.resolve(false);

                    var fd = new FormData(form);
                    // Kirim file hanya bila user memilih file baru agar tidak
                    // upload berulang (orphan file + kuota).
                    var fileEl = form.querySelector('input[type="file"][name="lampiran"]');
                    if (fileEl && (!fileEl.files || fileEl.files.length === 0)) fd.delete('lampiran');
                    fd.delete('submit');

                    var tokenEl = form.querySelector('input[name="_token"]');
                    saving = true;
                    if (button) button.classList.add('opacity-60');

                    return fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': tokenEl ? tokenEl.value : '',
                            'Accept': 'application/json'
                        },
                        body: fd,
                        credentials: 'same-origin'
                    }).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (d) { return { ok: res.ok, d: d }; });
                    }).then(function (out) {
                        if (!out.ok) {
                            var msg = out.d && out.d.message ? out.d.message : null;
                            setStatus(msg ? 'Gagal: ' + msg : 'Gagal menyimpan draf — dicoba lagi', true);
                            return false;
                        }
                        if (out.d && out.d.entry_id && entryInput && !entryInput.value) {
                            entryInput.value = out.d.entry_id;
                        }
                        // Sukses diwakili stempel persisten; status transient
                        // dikosongkan agar tidak dobel dengan stempel.
                        setStatus('', false);
                        setSavedAt('Terakhir disimpan ' + timeLabel());
                        updateCombined();
                        start();
                        return true;
                    }).catch(function () {
                        setStatus('Gagal menyimpan draf — dicoba lagi', true);
                        return false;
                    }).finally(function () {
                        saving = false;
                        if (button) button.classList.remove('opacity-60');
                    });
                }

                function start() {
                    if (timer) return;
                    timer = setInterval(function () {
                        if (document.hidden) return;
                        saveNow();
                    }, intervalMs);
                }

                if (button) {
                    button.addEventListener('click', function (e) {
                        e.preventDefault();
                        saveNow();
                    });
                }

                // Kirim final: hentikan autosave (draf localStorage dibuang handler existing).
                form.addEventListener('submit', function () {
                    if (timer) { clearInterval(timer); timer = null; }
                });

                return { save: saveNow, start: start };
            }
        };
    })();
</script>
