<script>
    // Form keputusan review unified (show & quick-review). Semua fitur opsional
    // mengikuti elemen yang ada: template, ambil-dari-komentar, pakai-ulang,
    // pengaman navigasi antrean.
    (function () {
        var form = document.getElementById('review-decision-form');
        if (!form) return;
        var feedback = document.getElementById('feedback_dosen');
        var feedbackWrap = feedback ? feedback.parentElement : null;
        var archiveReason = document.getElementById('archive_reason');
        var archiveWrap = document.getElementById('archive-reason-wrap');
        var error = document.getElementById('feedback-error');
        var submit = document.getElementById('review-decision-submit');
        var radios = Array.from(form.querySelectorAll('[name="review_decision"]'));
        var csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        var initialFeedback = feedback ? feedback.value : '';
        var initialArchiveReason = archiveReason ? archiveReason.value : '';
        var initialDecision = form.querySelector('[name="review_decision"]:checked')?.value || '';
        var submitting = false;

        function decision() { return form.querySelector('[name="review_decision"]:checked')?.value || ''; }
        function syncDecision() {
            var revision = decision() === 'revisi';
            var archive = decision() === 'archive';
            if (feedbackWrap) feedbackWrap.classList.toggle('hidden', archive);
            if (feedback) { feedback.disabled = archive; feedback.required = revision; feedback.minLength = revision ? 20 : 0; }
            if (archiveWrap) archiveWrap.classList.toggle('hidden', !archive);
            if (archiveReason) { archiveReason.disabled = !archive; archiveReason.required = false; }
            var req = document.getElementById('feedback-required');
            if (req) req.classList.toggle('hidden', !revision);
            if (submit) submit.disabled = !decision();
            form.action = archive ? form.dataset.archiveUrl : (revision ? form.dataset.revisionUrl : form.dataset.approveUrl);
            if (!revision && error) error.classList.add('hidden');
        }
        radios.forEach(radio => radio.addEventListener('change', syncDecision));
        syncDecision();

        if (feedback) feedback.addEventListener('input', () => error && error.classList.add('hidden'));

        // Pengaman navigasi antrean quick-review.
        document.querySelectorAll('.quick-review-navigation').forEach(link => link.addEventListener('click', event => {
            if (((feedback && feedback.value !== initialFeedback) || (archiveReason && archiveReason.value !== initialArchiveReason) || decision() !== initialDecision) && !window.confirm('Belum disimpan. Pindah tanpa menyimpan?')) event.preventDefault();
        }));

        function fillFeedback(text) {
            if (!feedback || !text) return;
            feedback.value = text;
            feedback.dispatchEvent(new Event('input'));
            if (decision() === 'approve') {
                var revisi = radios.find(r => r.value === 'revisi');
                if (revisi) { revisi.checked = true; syncDecision(); }
            }
            feedback.focus();
        }
        document.querySelectorAll('.use-last-feedback').forEach(btn => btn.addEventListener('click', function () {
            fillFeedback(this.dataset.body || '');
        }));

        var templateSelect = document.getElementById('template-select');
        if (templateSelect) templateSelect.addEventListener('change', function () {
            var option = this.selectedOptions[0];
            if (!option?.value) return;
            fillFeedback(option.dataset.body || '');
        });

        form.addEventListener('submit', function (event) {
            if (submitting) { event.preventDefault(); return; }
            if (decision() === 'revisi' && feedback && feedback.value.trim().length < 20) {
                event.preventDefault(); if (error) error.classList.remove('hidden'); feedback.focus(); return;
            }
            if (form.dataset.pdfOpened !== '1' && !window.confirm('PDF belum dibuka. Tetap ' + (decision() === 'revisi' ? 'minta revisi' : (decision() === 'archive' ? 'arsipkan' : 'setujui')) + '?')) {
                event.preventDefault(); return;
            }
            if (decision() === 'archive' && !window.confirm('Arsipkan entri ini tanpa menyetujui atau meminta revisi?')) {
                event.preventDefault(); return;
            }
            var button = form.querySelector('button[type="submit"]');
            if (button) { button.disabled = true; button.textContent = 'Memproses…'; }
            submitting = true;
        });

        var buildBtn = document.getElementById('build-feedback');
        if (buildBtn && buildBtn.dataset.buildUrl) buildBtn.addEventListener('click', async function () {
            this.disabled = true;
            try {
                const response = await fetch(buildBtn.dataset.buildUrl, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, credentials: 'same-origin'});
                if (!response.ok) throw new Error();
                const result = await response.json();
                if (!result.feedback) { window.alert('Tidak ada anotasi yang belum beres.'); return; }
                fillFeedback(result.feedback);
            } catch (e) { window.alert('Gagal memuat anotasi.'); }
            finally { this.disabled = false; }
        });

        var modal = document.getElementById('tpl-modal');
        if (modal) {
            var title = document.getElementById('tpl-title');
            var body = document.getElementById('tpl-body');
            var templateError = document.getElementById('tpl-error');
            var storeUrl = modal.dataset.storeUrl || '';
            function closeModal() { modal.classList.add('hidden'); document.getElementById('new-tpl')?.focus(); }
            document.getElementById('new-tpl')?.addEventListener('click', () => { if (body && feedback) body.value = feedback.value; templateError?.classList.add('hidden'); modal.classList.remove('hidden'); title?.focus(); });
            document.getElementById('tpl-cancel')?.addEventListener('click', closeModal);
            modal.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
            document.getElementById('tpl-save')?.addEventListener('click', async function () {
                if (!body.value.trim()) { templateError.textContent = 'Isi pesannya dulu.'; templateError.classList.remove('hidden'); body.focus(); return; }
                this.disabled = true;
                try {
                    const response = await fetch(storeUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, credentials: 'same-origin', body: JSON.stringify({title: title.value.trim(), body: body.value.trim()})});
                    if (!response.ok) throw new Error();
                    const template = await response.json();
                    const option = new Option(template.title || template.body.slice(0, 50), template.id);
                    option.dataset.body = template.body;
                    const select = document.getElementById('template-select');
                    select.add(option); select.value = String(template.id);
                    select.options[0].textContent = 'Pilih template...';
                    closeModal();
                } catch (e) { templateError.textContent = 'Gagal menyimpan. Coba lagi.'; templateError.classList.remove('hidden'); }
                finally { this.disabled = false; }
            });
        }
    })();
</script>
