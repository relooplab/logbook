{{--
    Helper tampilan bersama untuk halaman form (Entri Logbook & Entri Revisi).
    Hanya presentasi: input file asli dan operasi localStorage tetap milik
    masing-masing halaman; helper ini membaca state yang sudah ada.
--}}
<script>
    (function () {
        'use strict';

        // ---------------------------------------------------------- upload
        function formatBytes(bytes) {
            if (!bytes || bytes <= 0) return '0 B';
            var units = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
        }

        function containerFor(inputId) {
            return document.querySelector('[data-upload="' + inputId + '"]');
        }

        function fileOf(input) {
            return input && input.files && input.files.length ? input.files[0] : null;
        }

        function validationMessage(root, file) {
            if (!root || !file) return '';
            var maxMb = parseFloat(root.dataset.maxMb || '0');
            var allowed = [];
            try { allowed = JSON.parse(root.dataset.types || '[]'); } catch (e) { allowed = []; }
            var ext = (file.name.split('.').pop() || '').toLowerCase();
            var reasons = [];
            if (maxMb && file.size > maxMb * 1024 * 1024) reasons.push('Ukuran melebihi batas ' + maxMb + ' MB');
            if (allowed.length && allowed.indexOf(ext) === -1) {
                reasons.push('Format .' + ext + ' tidak diizinkan (hanya: ' + allowed.join(', ') + ')');
            }
            return reasons.length ? reasons.join('. ') + '.' : '';
        }

        function isUploadValid(inputId) {
            var input = document.getElementById(inputId);
            return !!fileOf(input) && !validationMessage(containerFor(inputId), fileOf(input));
        }

        function refreshMirror(inputId, file, valid) {
            document.querySelectorAll('[data-upload-mirror="' + inputId + '"]').forEach(function (mirror) {
                var badge = mirror.querySelector('[data-mirror-badge]');
                if (badge) {
                    badge.textContent = !file ? 'Belum diunggah' : (valid ? 'Terlampir' : 'File tidak valid');
                    badge.classList.toggle('badge-success', !!file && valid);
                    badge.classList.toggle('badge-neutral', !file);
                    badge.classList.toggle('badge-danger', !!file && !valid);
                }
                var nameEl = mirror.querySelector('[data-mirror-name]');
                if (nameEl) { nameEl.textContent = file ? file.name : ''; nameEl.classList.toggle('hidden', !file); }
                var sizeEl = mirror.querySelector('[data-mirror-size]');
                if (sizeEl) { sizeEl.textContent = file ? formatBytes(file.size) : ''; sizeEl.classList.toggle('hidden', !file); }
                var emptyEl = mirror.querySelector('[data-mirror-empty]');
                if (emptyEl) emptyEl.classList.toggle('hidden', !!file);
                mirror.querySelectorAll('[data-upload-clear]').forEach(function (btn) {
                    btn.classList.toggle('hidden', !file);
                });
            });
        }

        function refreshUpload(inputId) {
            var input = document.getElementById(inputId);
            if (!input) return false;
            var root = containerFor(inputId);
            var file = fileOf(input);
            var message = validationMessage(root, file);
            var usable = !!file && !message;

            if (root) {
                var emptyEl = root.querySelector('[data-upload-empty]');
                var selectedEl = root.querySelector('[data-upload-selected]');
                var errorEl = root.querySelector('[data-upload-error]');
                if (emptyEl) emptyEl.classList.toggle('hidden', usable);
                if (selectedEl) selectedEl.classList.toggle('hidden', !usable);
                if (usable) {
                    var nameEl = root.querySelector('[data-upload-name]');
                    var sizeEl = root.querySelector('[data-upload-size]');
                    if (nameEl) nameEl.textContent = file.name;
                    if (sizeEl) sizeEl.textContent = formatBytes(file.size);
                }
                if (errorEl) { errorEl.textContent = message; errorEl.classList.toggle('hidden', !message); }
                root.classList.toggle('upload-field--filled', usable);
                root.classList.toggle('upload-field--invalid', !!message);
            }

            refreshMirror(inputId, file, usable);
            return usable;
        }

        document.addEventListener('click', function (event) {
            if (!(event.target instanceof Element)) return;
            var pick = event.target.closest('[data-upload-pick]');
            if (pick) {
                event.preventDefault();
                var target = document.getElementById(pick.dataset.uploadPick);
                if (target) target.click();
                return;
            }
            var clear = event.target.closest('[data-upload-clear]');
            if (clear) {
                event.preventDefault();
                var input = document.getElementById(clear.dataset.uploadClear);
                if (input) {
                    input.value = '';
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });

        function bindUploadInputs() {
            document.querySelectorAll('input[type="file"][data-upload-input]').forEach(function (input) {
                input.addEventListener('change', function () { refreshUpload(input.id); });
                refreshUpload(input.id);
            });
        }

        // --------------------------------------------------------- autosave
        var AUTOSAVE_STATES = {
            idle:      { icon: 'save',             tone: 'text-text-secondary', label: 'Tersimpan otomatis di browser ini', mini: 'Di browser ini', variant: 'badge-neutral' },
            pending:   { icon: 'edit_note',        tone: 'text-status-pending', label: 'Mengetik...',                      mini: 'Mengetik...',     variant: 'badge-pending' },
            saving:    { icon: 'progress_activity', tone: 'text-text-secondary', label: 'Menyimpan...',                mini: 'Menyimpan...',          variant: 'badge-info' },
            saved:     { icon: 'cloud_done',       tone: 'text-status-success', label: 'Tersimpan di browser ini',     mini: 'Di browser ini',       variant: 'badge-success' },
            restored:  { icon: 'history',          tone: 'text-status-info',    label: 'Dipulihkan',                   mini: 'Dipulihkan',           variant: 'badge-info' },
            discarded: { icon: 'delete',           tone: 'text-text-secondary', label: 'Dibuang',                      mini: 'Dibuang',              variant: 'badge-neutral' },
            error:     { icon: 'error',            tone: 'text-status-danger',  label: 'Gagal menyimpan',              mini: 'Gagal menyimpan',       variant: 'badge-danger' },
        };
        var TONES = ['text-text-secondary', 'text-status-pending', 'text-status-success', 'text-status-info', 'text-status-danger'];
        var VARIANTS = ['badge-neutral', 'badge-pending', 'badge-info', 'badge-success', 'badge-danger'];
        var autosaveHandlers = {};

        function panelFor(prefix) {
            return document.querySelector('[data-autosave-panel="' + prefix + '"]');
        }

        function repaint(el, tone) {
            if (!el) return;
            TONES.forEach(function (cls) { el.classList.remove(cls); });
            el.classList.add(tone);
        }

        function setAutosave(prefix, state, options) {
            options = options || {};
            var panel = panelFor(prefix);
            if (!panel) return;
            var meta = AUTOSAVE_STATES[state] || AUTOSAVE_STATES.idle;
            var stateEl = panel.querySelector('[data-autosave-state]');
            var timeEl = panel.querySelector('[data-autosave-time]');
            var iconEl = panel.querySelector('[data-autosave-icon]');
            if (stateEl) {
                stateEl.textContent = options.label || meta.label;
                repaint(stateEl, meta.tone);
            }
            if (timeEl) {
                timeEl.textContent = options.time || '';
                timeEl.classList.toggle('hidden', !options.time);
            }
            if (iconEl) {
                iconEl.textContent = meta.icon;
                iconEl.classList.toggle('animate-spin', state === 'saving');
                repaint(iconEl, meta.tone);
            }
            var retry = panel.querySelector('[data-autosave-retry]');
            if (retry) retry.classList.toggle('hidden', state !== 'error');

            // Ringkasan konteks (baris Status Draft) memakai label yang sama.
            document.querySelectorAll('[data-autosave-mini="' + prefix + '"]').forEach(function (mini) {
                mini.textContent = meta.mini;
                VARIANTS.forEach(function (cls) { mini.classList.remove(cls); });
                mini.classList.add(meta.variant);
            });

            if (state === 'saved' || state === 'restored' || state === 'discarded') {
                setDraftAvailable(prefix, false);
            }
        }

        function setDraftAvailable(prefix, available) {
            var panel = panelFor(prefix);
            if (!panel) return;
            panel.querySelectorAll('[data-autosave-restore], [data-autosave-discard]').forEach(function (btn) {
                btn.classList.toggle('hidden', !available);
            });
        }

        function onAutosave(prefix, action, fn) {
            autosaveHandlers[prefix + ':' + action] = fn;
        }

        document.addEventListener('click', function (event) {
            if (!(event.target instanceof Element)) return;
            var panel = event.target.closest('[data-autosave-panel]');
            if (!panel) return;
            var action = event.target.closest('[data-autosave-retry]') ? 'retry'
                : event.target.closest('[data-autosave-restore]') ? 'restore'
                : event.target.closest('[data-autosave-discard]') ? 'discard' : null;
            var fn = action ? autosaveHandlers[panel.dataset.autosavePanel + ':' + action] : null;
            if (fn) fn();
        });

        window.LbUpload = { refresh: refreshUpload, isValid: isUploadValid };
        window.LbAutosave = { set: setAutosave, draftAvailable: setDraftAvailable, on: onAutosave };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindUploadInputs);
        } else {
            bindUploadInputs();
        }
    })();
</script>
