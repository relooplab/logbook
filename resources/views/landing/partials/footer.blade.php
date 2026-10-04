<footer class="landing-footer">
    <div class="landing-container py-6 flex flex-col items-center gap-3 text-center">
        <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm">
            <span class="font-heading font-bold text-text-primary">@if ($appName === 'Logbook')<span class="text-accent-blue">Log</span><span class="text-status-pending">book</span>@else{{ $appName }}@endif</span>
            <span class="text-xs text-text-secondary">v{{ $version }}</span>
        </div>
        @if($adminContactEmail)
            <a href="mailto:{{ $adminContactEmail }}" class="text-xs text-text-secondary hover:text-text-primary">Hubungi admin</a>
        @endif
        <div data-footer-meta class="flex flex-wrap items-center justify-center gap-3 text-xs leading-5 text-text-secondary">
            <p>© <span class="text-accent-teal font-semibold">{{ now()->year }}</span> Made <span class="text-accent-blue font-semibold">with</span> <span aria-hidden="true">❤️</span> by <a href="https://reloop.id" target="_blank" rel="noopener noreferrer" class="hover:underline">ReLoop Lab</a>.</p>
            <nav aria-label="Media sosial" class="flex items-center gap-1">
                <a href="https://github.com/relooplab/logbook" target="_blank" rel="noopener noreferrer" class="landing-social-link" aria-label="GitHub Logbook (tab baru)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .75a11.25 11.25 0 0 0-3.56 21.92c.56.1.77-.24.77-.54v-2.1c-3.13.68-3.79-1.33-3.79-1.33-.51-1.3-1.25-1.65-1.25-1.65-1.02-.7.08-.69.08-.69 1.13.08 1.73 1.16 1.73 1.16 1 1.72 2.64 1.22 3.28.93.1-.72.39-1.22.71-1.5-2.5-.28-5.13-1.25-5.13-5.56 0-1.23.44-2.23 1.16-3.02-.12-.28-.5-1.43.11-2.98 0 0 .95-.3 3.09 1.15a10.77 10.77 0 0 1 5.62 0c2.15-1.45 3.09-1.15 3.09-1.15.61 1.55.23 2.7.11 2.98.72.79 1.16 1.79 1.16 3.02 0 4.32-2.63 5.28-5.14 5.56.4.35.76 1.03.76 2.08v3.1c0 .3.2.65.78.54A11.25 11.25 0 0 0 12 .75Z"/></svg>
                </a>
                <a href="https://www.linkedin.com/company/relooplab" target="_blank" rel="noopener noreferrer" class="landing-social-link" aria-label="LinkedIn Reloop Lab (tab baru)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 2H3.55C2.69 2 2 2.68 2 3.52v16.96C2 21.32 2.69 22 3.55 22h16.9c.86 0 1.55-.68 1.55-1.52V3.52c0-.84-.69-1.52-1.55-1.52ZM7.93 18.75H4.98V9.2h2.95v9.55ZM6.45 7.9a1.71 1.71 0 1 1 0-3.42 1.71 1.71 0 0 1 0 3.42Zm12.3 10.85H15.8v-4.65c0-1.11-.02-2.54-1.55-2.54-1.55 0-1.79 1.21-1.79 2.46v4.73H9.51V9.2h2.83v1.3h.04c.4-.76 1.36-1.55 2.79-1.55 2.99 0 3.58 1.97 3.58 4.53v5.27Z"/></svg>
                </a>
            </nav>
        </div>
    </div>
</footer>