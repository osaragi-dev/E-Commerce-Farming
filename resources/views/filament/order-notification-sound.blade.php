<script>
    (() => {
        let audioContext = null;
        let lastUnreadCount = null;
        let checkScheduled = false;

        const getUnreadCount = () => {
            const trigger = document.querySelector(
                '.fi-topbar-database-notifications-btn, .fi-sidebar-database-notifications-btn',
            );

            if (! trigger) {
                return null;
            }

            const badge = trigger.querySelector('.fi-badge');

            return badge ? Number.parseInt(badge.textContent, 10) || 0 : 0;
        };

        const playNotificationSound = async () => {
            const AudioContext = window.AudioContext || window.webkitAudioContext;

            if (! AudioContext) {
                return;
            }

            audioContext ??= new AudioContext();

            try {
                if (audioContext.state === 'suspended') {
                    await audioContext.resume();
                }
            } catch {
                return;
            }

            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            const now = audioContext.currentTime;

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(740, now);
            oscillator.frequency.exponentialRampToValueAtTime(520, now + 0.16);
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.12, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
            oscillator.connect(gain).connect(audioContext.destination);
            oscillator.start(now);
            oscillator.stop(now + 0.18);
        };

        const checkUnreadCount = () => {
            checkScheduled = false;
            const unreadCount = getUnreadCount();

            if (unreadCount === null) {
                return;
            }

            if (lastUnreadCount !== null && unreadCount > lastUnreadCount) {
                playNotificationSound();
            }

            lastUnreadCount = unreadCount;
        };

        const scheduleCheck = () => {
            if (checkScheduled) {
                return;
            }

            checkScheduled = true;
            window.setTimeout(checkUnreadCount, 40);
        };

        document.addEventListener('pointerdown', () => {
            const AudioContext = window.AudioContext || window.webkitAudioContext;

            if (AudioContext && ! audioContext) {
                audioContext = new AudioContext();
            }
        }, { once: true });

        checkUnreadCount();
        new MutationObserver(scheduleCheck).observe(document.body, {
            childList: true,
            characterData: true,
            subtree: true,
        });
    })();
</script>
