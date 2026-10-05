{{--
    Pemilih multi-file bergaya aplikasi untuk Dokumen Tambahan.
    <input type="file" multiple> asli tetap satu-satunya sumber state —
    hanya tampilan native-nya yang digantikan dropzone + daftar file.
    Validasi sisi server tetap sumber kebenaran; pesan klien hanya hint.
--}}
@props([
    'input' => 'dokumen_tambahan[]',
    'inputId' => 'dokumen_tambahan',
    'accept' => '.pdf,.doc,.docx,.xls,.xlsx',
    'typesLabel' => 'PDF, DOC, Excel',
    'maxFiles' => 3,
    'maxTotalMb' => 10,
    'errorKey' => 'dokumen_tambahan',
])

<div class="upload-field" data-multi-upload="{{ $inputId }}"
    data-max-files="{{ $maxFiles }}" data-max-total-mb="{{ $maxTotalMb }}">
    <input type="file" name="{{ $input }}" id="{{ $inputId }}" multiple accept="{{ $accept }}"
        class="sr-only" data-multi-input aria-label="Pilih file tambahan">

    <div class="rounded-xl border-2 border-dashed border-border bg-bg-panel p-6 text-center transition cursor-pointer hover:border-brand/40"
        data-multi-drop role="button" tabindex="0" aria-label="Pilih file tambahan">
        <p aria-hidden="true"><span class="material-symbols-outlined text-text-secondary" style="font-size:40px">cloud_upload</span></p>
        <p class="mt-2 text-sm font-medium text-text-primary">Klik untuk memilih atau seret file ke sini</p>
        <p class="mt-1 text-xs text-text-secondary">{{ $typesLabel }} · Maks {{ $maxFiles }} file · Total maks {{ $maxTotalMb }} MB</p>
        <span class="btn-secondary mt-3 inline-flex items-center gap-2 px-4 py-2 text-sm">
            <span class="material-symbols-outlined icon-sm" aria-hidden="true">add</span> Tambah File
        </span>
    </div>

    <ul class="mt-3 hidden space-y-2" data-multi-list></ul>
    <p class="mt-2 hidden text-xs text-status-danger" data-multi-error role="alert"></p>

    @error($errorKey)
        <p class="mt-2 text-xs text-status-danger">{{ $message }}</p>
    @enderror
    @error($errorKey.'.*')
        <p class="mt-2 text-xs text-status-danger">{{ $message }}</p>
    @enderror
</div>

<script>
    (function () {
        var root = document.querySelector('[data-multi-upload="{{ $inputId }}"]');
        if (!root || root.dataset.multiReady) return;
        root.dataset.multiReady = '1';

        var input = root.querySelector('[data-multi-input]');
        var drop = root.querySelector('[data-multi-drop]');
        var list = root.querySelector('[data-multi-list]');
        var errorEl = root.querySelector('[data-multi-error]');
        var dt = new DataTransfer();

        function formatBytes(bytes) {
            if (!bytes) return '0 B';
            var units = ['B', 'KB', 'MB', 'GB'];
            var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
            return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
        }

        function totalBytes() {
            var total = 0;
            Array.from(dt.files).forEach(function (f) { total += f.size; });
            return total;
        }

        function clientHint() {
            var maxFiles = parseInt(root.dataset.maxFiles, 10);
            var maxTotalMb = parseInt(root.dataset.maxTotalMb, 10);
            if (dt.files.length > maxFiles) return 'Maksimal ' + maxFiles + ' file.';
            if (totalBytes() > maxTotalMb * 1024 * 1024) return 'Total ukuran file maksimal ' + maxTotalMb + ' MB.';
            return '';
        }

        function render() {
            list.innerHTML = '';
            list.classList.toggle('hidden', dt.files.length === 0);
            Array.from(dt.files).forEach(function (file, index) {
                var row = document.createElement('li');
                row.className = 'flex min-w-0 items-center gap-3 rounded-xl border border-border bg-bg-panel px-3 py-2';
                row.innerHTML = '<span class="material-symbols-outlined icon-md shrink-0 text-brand" aria-hidden="true">description</span>'
                    + '<span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-text-primary"></span>'
                    + '<span class="block text-xs text-text-secondary"></span></span>'
                    + '<button type="button" class="btn-ghost shrink-0 px-2 py-1 text-xs" aria-label="Hapus file">'
                    + '<span class="material-symbols-outlined icon-sm" aria-hidden="true">close</span></button>';
                row.querySelector('.text-sm').textContent = file.name;
                row.querySelector('.text-xs').textContent = formatBytes(file.size);
                row.querySelector('button').addEventListener('click', function () {
                    dt.items.remove(index);
                    input.files = dt.files;
                    render();
                });
                list.appendChild(row);
            });
            var hint = clientHint();
            errorEl.textContent = hint;
            errorEl.classList.toggle('hidden', !hint);
        }

        function addFiles(files) {
            Array.from(files || []).forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
            render();
        }

        drop.addEventListener('click', function () { input.click(); });
        drop.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        ['dragenter', 'dragover'].forEach(function (evt) {
            drop.addEventListener(evt, function (e) { e.preventDefault(); });
        });
        drop.addEventListener('drop', function (e) {
            e.preventDefault();
            addFiles(e.dataTransfer.files);
        });
        input.addEventListener('change', function () {
            dt = new DataTransfer();
            addFiles(input.files);
        });
    })();
</script>
