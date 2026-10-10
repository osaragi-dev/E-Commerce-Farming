const registerPublicComponents = () => {
    Alpine.data('publicReveal', () => {
        const observed = new Set();
        const revealed = new WeakSet();
        const animations = new Map();
        let visibilityObserver;
        let mutationObserver;
        let reducedMotion;
        let connection;
        let onPreferenceChange;
        let onFocus;
        let destroyed = false;

        return {
            init() {
                if (typeof IntersectionObserver === 'undefined') return;

                reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
                connection = navigator.connection;
                onPreferenceChange = () => this.refresh();
                onFocus = (event) => {
                    for (const element of observed) {
                        if (element.contains(event.target)) {
                            revealed.add(element);
                            visibilityObserver.unobserve(element);
                            observed.delete(element);
                        }
                    }
                    for (const [element, animation] of animations) {
                        if (element.contains(event.target)) animation.cancel();
                    }
                };
                visibilityObserver = new IntersectionObserver((entries) => {
                    if (destroyed) return;

                    let stagger = 0;

                    for (const { target: element, isIntersecting } of entries) {
                        if (!isIntersecting || revealed.has(element)) continue;

                        revealed.add(element);
                        observed.delete(element);
                        visibilityObserver.unobserve(element);
                        if (!this.motionAllowed() || !element.animate) continue;

                        const animation = element.animate([
                            { opacity: 0, transform: 'translateY(12px)' },
                            { opacity: 1, transform: 'translateY(0)' },
                        ], {
                            duration: 280,
                            delay: Math.min(stagger++ * 45, 180),
                            easing: getComputedStyle(element).getPropertyValue('--ease').trim() || 'cubic-bezier(0.23, 1, 0.32, 1)',
                            fill: 'backwards',
                        });
                        animations.set(element, animation);
                        animation.finished.then(() => animations.delete(element), () => animations.delete(element));
                    }
                }, { threshold: 0.08 });
                mutationObserver = new MutationObserver(() => this.refresh());
                mutationObserver.observe(this.$el, { childList: true, subtree: true });
                reducedMotion.addEventListener('change', onPreferenceChange);
                connection?.addEventListener('change', onPreferenceChange);
                this.$el.addEventListener('focusin', onFocus);
                this.refresh();
            },

            motionAllowed() {
                return !reducedMotion.matches && !connection?.saveData;
            },

            refresh() {
                if (destroyed) return;

                const children = [...this.$el.querySelectorAll('[data-reveal]')];
                const targets = children.length ? children : [this.$el];

                for (const element of observed) {
                    if (!this.$el.contains(element)) {
                        visibilityObserver.unobserve(element);
                        observed.delete(element);
                    }
                }

                for (const [element, animation] of animations) {
                    if (!this.motionAllowed() || !this.$el.contains(element)) {
                        animation.cancel();
                        animations.delete(element);
                    }
                }

                for (const element of targets) {
                    if (!this.motionAllowed()) {
                        revealed.add(element);
                        visibilityObserver.unobserve(element);
                        observed.delete(element);
                    } else if (!revealed.has(element) && !observed.has(element)) {
                        observed.add(element);
                        visibilityObserver.observe(element);
                    }
                }
            },

            destroy() {
                destroyed = true;
                visibilityObserver?.disconnect();
                mutationObserver?.disconnect();
                reducedMotion?.removeEventListener('change', onPreferenceChange);
                connection?.removeEventListener('change', onPreferenceChange);
                this.$el.removeEventListener('focusin', onFocus);
                for (const animation of animations.values()) animation.cancel();
                animations.clear();
                observed.clear();
            },
        };
    });

    Alpine.data('publicCarousel', () => ({
        atStart: true,
        atEnd: false,
        resizeObserver: null,
        mutationObserver: null,
        onScroll: null,
        onKeydown: null,

        init() {
            this.onScroll = () => this.update();
            this.onKeydown = (event) => {
                if (event.target !== this.$refs.track) return;

                if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    this.move(event.key === 'ArrowRight' ? 1 : -1);
                } else if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    this.$refs.track.scrollTo({
                        left: event.key === 'Home' ? 0 : this.$refs.track.scrollWidth,
                        behavior: this.scrollBehavior(),
                    });
                }
            };

            this.$refs.track.addEventListener('scroll', this.onScroll, { passive: true });
            this.$refs.track.addEventListener('keydown', this.onKeydown);
            this.resizeObserver = new ResizeObserver(() => this.update());
            this.resizeObserver.observe(this.$refs.track);
            this.mutationObserver = new MutationObserver(() => this.update());
            this.mutationObserver.observe(this.$refs.track, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'style'] });
            this.$nextTick(() => this.update());
        },

        update() {
            const track = this.$refs.track;
            this.atStart = track.scrollLeft <= 2;
            this.atEnd = track.scrollWidth - track.clientWidth - track.scrollLeft <= 2;
        },

        scrollBehavior() {
            return matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';
        },

        move(direction) {
            const track = this.$refs.track;
            const card = [...track.children].find((child) => child.getBoundingClientRect().width > 0);
            if (!card) return;

            const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
            track.scrollBy({ left: direction * (card.getBoundingClientRect().width + gap), behavior: this.scrollBehavior() });
        },

        previous() { this.move(-1); },
        next() { this.move(1); },

        destroy() {
            this.resizeObserver?.disconnect();
            this.mutationObserver?.disconnect();
            this.$refs.track.removeEventListener('scroll', this.onScroll);
            this.$refs.track.removeEventListener('keydown', this.onKeydown);
        },
    }));

    Alpine.data('farmHero', () => ({
        playing: false,
        unavailable: false,
        intent: 'auto',
        visible: true,
        observer: null,
        reducedMotion: null,
        connection: null,
        onPreferenceChange: null,
        onPlaybackChange: null,
        onError: null,

        init() {
            const video = this.$refs.video;
            this.reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
            this.connection = navigator.connection;
            this.onPreferenceChange = () => this.sync();
            this.onPlaybackChange = () => { this.playing = !video.paused; };
            this.onError = () => { this.unavailable = true; this.playing = false; };
            this.reducedMotion.addEventListener('change', this.onPreferenceChange);
            this.connection?.addEventListener('change', this.onPreferenceChange);
            document.addEventListener('visibilitychange', this.onPreferenceChange);
            video.addEventListener('play', this.onPlaybackChange);
            video.addEventListener('pause', this.onPlaybackChange);
            video.addEventListener('error', this.onError);
            this.observer = new IntersectionObserver(([entry]) => {
                this.visible = entry.isIntersecting;
                this.sync();
            }, { threshold: 0.05 });
            this.observer.observe(this.$el);
        },

        sync() {
            const video = this.$refs.video;
            const allowAutoplay = !this.reducedMotion.matches && !this.connection?.saveData;
            const shouldPlay = !this.unavailable && this.visible && !document.hidden
                && (this.intent === 'play' || (this.intent === 'auto' && allowAutoplay));

            if (!shouldPlay) {
                video.pause();
                return;
            }

            if (!video.getAttribute('src')) video.src = video.dataset.src;
            video.play().catch(() => { this.playing = false; });
        },

        toggle() {
            this.intent = this.playing ? 'pause' : 'play';
            this.sync();
        },

        destroy() {
            this.observer?.disconnect();
            this.reducedMotion?.removeEventListener('change', this.onPreferenceChange);
            this.connection?.removeEventListener('change', this.onPreferenceChange);
            document.removeEventListener('visibilitychange', this.onPreferenceChange);
            this.$refs.video.removeEventListener('play', this.onPlaybackChange);
            this.$refs.video.removeEventListener('pause', this.onPlaybackChange);
            this.$refs.video.removeEventListener('error', this.onError);
            this.$refs.video.pause();
        },
    }));
};

if (window.Alpine) {
    registerPublicComponents();
} else {
    document.addEventListener('alpine:init', registerPublicComponents, { once: true });
}
