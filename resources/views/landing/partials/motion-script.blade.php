<script>
    (function () {
        var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        var revealTargets = document.querySelectorAll('.landing-ta-heading, .landing-ta-shell, .landing-section .landing-heading, .landing-feature, .landing-role, .landing-mode, .landing-slider, .landing-final-cta');
        if ('IntersectionObserver' in window && !motion.matches) {
            var revealObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.remove('is-reveal-pending');
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: .08 });
            revealTargets.forEach(function (target) {
                target.classList.add('landing-reveal');
                // Never conceal content already visible or containing keyboard focus.
                if (target.getBoundingClientRect().top > window.innerHeight && !target.contains(document.activeElement)) {
                    target.classList.add('is-reveal-pending');
                    if (target.matches('.landing-feature, .landing-role, .landing-mode')) {
                        var siblings = Array.from(target.parentElement.children);
                        target.style.setProperty('--reveal-delay', (siblings.indexOf(target) % 3 * 80) + 'ms');
                    }
                    revealObserver.observe(target);
                }
            });
            document.addEventListener('focusin', function (event) {
                var target = event.target.closest('.is-reveal-pending');
                if (target) { target.classList.remove('is-reveal-pending'); revealObserver.unobserve(target); }
            });
            motion.addEventListener('change', function () {
                if (motion.matches) {
                    revealObserver.disconnect();
                    revealTargets.forEach(function (target) { target.classList.remove('is-reveal-pending'); });
                }
            });
        }

        var slider = document.querySelector('[data-dashboard-slider]');
        if (!slider) return;
        var track = slider.querySelector('[data-slider-track]');
        var slides = Array.from(slider.querySelectorAll('[data-dashboard-slide]'));
        var choices = Array.from(slider.querySelectorAll('[data-slide-select]'));
        var play = slider.querySelector('[data-slide-play]');
        var playIcon = play.querySelector('.material-symbols-outlined');
        var status = slider.querySelector('[data-slide-status]');
        var index = 0;
        var playing = !motion.matches;
        var visible = false;
        var hovering = false;
        var timer = null;
        var pointerStart = null;
        slider.classList.add('is-enhanced');
        slider.querySelectorAll('[data-slider-controls]').forEach(function (control) { control.hidden = false; });

        function selectSlide(next, manual) {
            index = (next + slides.length) % slides.length;
            if (manual) playing = false;
            track.style.transform = 'translateX(-' + index * 100 + '%)';
            slides.forEach(function (slide, i) {
                slide.setAttribute('aria-hidden', String(i !== index));
                slide.inert = i !== index;
            });
            choices.forEach(function (choice, i) { choice.setAttribute('aria-pressed', String(i === index)); });
            status.setAttribute('aria-live', manual ? 'polite' : 'off');
            status.textContent = '0' + (index + 1) + ' / 02';
            syncSlider();
        }
        function syncSlider() {
            window.clearInterval(timer);
            timer = null;
            var isPlaying = playing && !motion.matches;
            if (playIcon) playIcon.textContent = isPlaying ? 'pause' : 'play_arrow';
            play.setAttribute('aria-label', isPlaying ? 'Jeda otomatis' : 'Putar otomatis');
            play.setAttribute('aria-pressed', String(isPlaying));
            play.disabled = motion.matches;
            if (playing && visible && !hovering && !document.hidden && !motion.matches && !slider.contains(document.activeElement)) {
                timer = window.setInterval(function () { selectSlide(index + 1, false); }, 7000);
            }
        }
        choices.forEach(function (choice, i) { choice.addEventListener('click', function () { selectSlide(i, true); }); });
        slider.querySelector('[data-slide-prev]').addEventListener('click', function () { selectSlide(index - 1, true); });
        slider.querySelector('[data-slide-next]').addEventListener('click', function () { selectSlide(index + 1, true); });
        play.addEventListener('click', function () { playing = !playing; syncSlider(); });
        slider.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                selectSlide(index + (event.key === 'ArrowRight' ? 1 : -1), true);
            }
        });
        var viewport = slider.querySelector('[data-slider-viewport]');
        viewport.addEventListener('pointerdown', function (event) {
            if (event.isPrimary) pointerStart = { x: event.clientX, y: event.clientY };
        });
        viewport.addEventListener('pointerup', function (event) {
            if (!pointerStart) return;
            var dx = event.clientX - pointerStart.x;
            var dy = event.clientY - pointerStart.y;
            pointerStart = null;
            if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) selectSlide(index + (dx < 0 ? 1 : -1), true);
        });
        viewport.addEventListener('pointercancel', function () { pointerStart = null; });
        slider.addEventListener('mouseenter', function () { hovering = true; syncSlider(); });
        slider.addEventListener('mouseleave', function () { hovering = false; syncSlider(); });
        slider.addEventListener('focusin', syncSlider);
        slider.addEventListener('focusout', function () { window.setTimeout(syncSlider, 0); });
        document.addEventListener('visibilitychange', syncSlider);
        motion.addEventListener('change', function () { if (motion.matches) playing = false; syncSlider(); });
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                visible = entries[0].isIntersecting;
                syncSlider();
            }, { threshold: .15 }).observe(slider);
        } else visible = true;
        selectSlide(0, false);
    })();
</script>