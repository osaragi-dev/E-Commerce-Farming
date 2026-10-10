import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../resources/js/app.js', import.meta.url), 'utf8');

function events(properties = {}) {
    const listeners = new Map();

    return Object.assign(properties, {
        addEventListener(name, listener) {
            if (!listeners.has(name)) listeners.set(name, new Set());
            listeners.get(name).add(listener);
        },
        removeEventListener(name, listener) { listeners.get(name)?.delete(listener); },
        emit(name, event = {}) { listeners.get(name)?.forEach((listener) => listener(event)); },
        listenerCount() { return [...listeners.values()].reduce((count, group) => count + group.size, 0); },
    });
}

function browser({ reduced = false, saveData = false, supportsIntersectionObserver = true } = {}) {
    const components = new Map();
    const observers = [];
    const document = events({ hidden: false });
    const motion = events({ matches: reduced });
    const connection = events({ saveData });
    const Alpine = { data: (name, factory) => components.set(name, factory) };

    class Observer {
        constructor(callback) { this.callback = callback; this.disconnected = false; this.observed = new Set(); observers.push(this); }
        observe(element) { this.element = element; this.observed.add(element); }
        unobserve(element) { this.observed.delete(element); }
        disconnect() { this.disconnected = true; this.observed.clear(); }
    }

    runInNewContext(source, {
        Alpine,
        window: { Alpine },
        document,
        navigator: { connection },
        matchMedia: () => motion,
        getComputedStyle: () => ({ columnGap: '24px', getPropertyValue: () => 'cubic-bezier(0.23, 1, 0.32, 1)' }),
        ResizeObserver: Observer,
        MutationObserver: Observer,
        IntersectionObserver: supportsIntersectionObserver ? Observer : undefined,
    });

    return {
        document, motion, connection, observers,
        mount(name, refs, element = {}) {
            const component = Object.assign(components.get(name)(), {
                $refs: refs,
                $el: element,
                $nextTick: (callback) => callback(),
            });
            component.init();
            return component;
        },
    };
}

function productTrack() {
    const track = events({
        scrollLeft: 0,
        scrollWidth: 1098,
        clientWidth: 350,
        children: [350, 350, 350].map((width) => ({ getBoundingClientRect: () => ({ width }) })),
        lastScroll: null,
    });

    track.scrollTo = (options) => {
        track.lastScroll = options;
        track.scrollLeft = Math.max(0, Math.min(options.left, track.scrollWidth - track.clientWidth));
        track.emit('scroll');
    };
    track.scrollBy = (options) => track.scrollTo({ ...options, left: track.scrollLeft + options.left });

    return track;
}

function heroVideo() {
    const video = events({ paused: true, dataset: { src: '/farm.mp4' }, playCalls: 0, src: '' });
    video.getAttribute = (name) => name === 'src' ? video.src : null;
    video.play = () => {
        video.playCalls++;
        video.paused = false;
        video.emit('play');
        return Promise.resolve();
    };
    video.pause = () => { video.paused = true; video.emit('pause'); };
    return video;
}

function revealElement(children = []) {
    const element = events({ children, animations: [] });
    element.querySelectorAll = () => element.children;
    element.contains = (target) => target === element || element.children.some((child) => child.contains(target));
    element.animate = (keyframes, options) => {
        let reject;
        const animation = {
            keyframes, options, cancelled: false,
            finished: new Promise((resolve, fail) => { reject = fail; }),
            cancel() { this.cancelled = true; reject(new Error('Animation cancelled')); },
        };
        element.animations.push(animation);
        return animation;
    };
    return element;
}

test('viewport reveals happen once with bounded stagger and newly filtered cards are observed', () => {
    const environment = browser();
    const cards = Array.from({ length: 6 }, () => revealElement());
    const collection = revealElement(cards);
    const reveal = environment.mount('publicReveal', {}, collection);
    const [visibility, mutations] = environment.observers;

    assert.ok(cards.every((card) => card.animations.length === 0));
    visibility.callback(cards.map((target) => ({ target, isIntersecting: true })));
    assert.deepEqual(cards.map((card) => card.animations[0].options.delay), [0, 45, 90, 135, 180, 180]);
    assert.equal(visibility.observed.size, 0);
    visibility.callback([{ target: cards[0], isIntersecting: true }]);
    assert.equal(cards[0].animations.length, 1);

    const newCard = revealElement();
    collection.children.push(newCard);
    mutations.callback();
    assert.deepEqual([...visibility.observed], [newCard]);
    visibility.callback([{ target: newCard, isIntersecting: true }]);
    assert.equal(newCard.animations.length, 1);
    reveal.destroy();
});

for (const preference of [{ reduced: true }, { saveData: true }]) {
    test(`viewport reveal leaves current and newly filtered content visible when ${Object.keys(preference)[0]} is enabled`, () => {
        const environment = browser(preference);
        const card = revealElement();
        const collection = revealElement([card]);
        const reveal = environment.mount('publicReveal', {}, collection);
        const newCard = revealElement();
        collection.children.push(newCard);
        environment.observers[1].callback();

        assert.equal(environment.observers[0].observed.size, 0);
        assert.equal(card.animations.length, 0);
        assert.equal(newCard.animations.length, 0);
        reveal.destroy();
    });
}

test('reveal cancels immediately for keyboard focus, removed cards, and reduced motion changes', () => {
    const environment = browser();
    const cards = [revealElement(), revealElement(), revealElement()];
    const collection = revealElement(cards);
    const reveal = environment.mount('publicReveal', {}, collection);
    environment.observers[0].callback(cards.map((target) => ({ target, isIntersecting: true })));

    collection.emit('focusin', { target: cards[0] });
    assert.equal(cards[0].animations[0].cancelled, true);
    collection.children = cards.slice(0, 2);
    environment.observers[1].callback();
    assert.equal(cards[2].animations[0].cancelled, true);
    environment.motion.matches = true;
    environment.motion.emit('change');
    assert.equal(cards[1].animations[0].cancelled, true);
    reveal.destroy();
});

test('reveal cleanup releases animation, observers, and listeners and ignores queued observations', () => {
    const environment = browser();
    const section = revealElement();
    const reveal = environment.mount('publicReveal', {}, section);
    const visibility = environment.observers[0];
    visibility.callback([{ target: section, isIntersecting: true }]);
    reveal.destroy();

    assert.equal(section.animations[0].cancelled, true);
    assert.equal(section.listenerCount(), 0);
    assert.equal(environment.motion.listenerCount(), 0);
    assert.equal(environment.connection.listenerCount(), 0);
    assert.ok(environment.observers.every((observer) => observer.disconnected));
    const newCard = revealElement();
    visibility.callback([{ target: newCard, isIntersecting: true }]);
    assert.equal(newCard.animations.length, 0);
});

test('reveal is progressive enhancement when observation or animation APIs are unavailable', () => {
    const unsupported = browser({ supportsIntersectionObserver: false });
    const plainSection = revealElement();
    const plainReveal = unsupported.mount('publicReveal', {}, plainSection);
    assert.equal(unsupported.observers.length, 0);
    assert.equal(plainSection.animations.length, 0);
    plainReveal.destroy();

    const environment = browser();
    const section = revealElement();
    section.animate = undefined;
    const reveal = environment.mount('publicReveal', {}, section);
    environment.observers[0].callback([{ target: section, isIntersecting: true }]);
    assert.equal(section.animations.length, 0);
    reveal.destroy();
});

test('keyboard focus before an intersection keeps the focused product immediately visible', () => {
    const environment = browser();
    const card = revealElement();
    const collection = revealElement([card]);
    const reveal = environment.mount('publicReveal', {}, collection);
    collection.emit('focusin', { target: card });
    environment.observers[0].callback([{ target: card, isIntersecting: true }]);

    assert.equal(card.animations.length, 0);
    assert.equal(environment.observers[0].observed.size, 0);
    reveal.destroy();
});

test('carousel keyboard navigation reaches both boundaries without intercepting card controls', () => {
    const environment = browser();
    const track = productTrack();
    const carousel = environment.mount('publicCarousel', { track });
    let prevented = 0;
    const key = (value, target = track) => track.emit('keydown', { key: value, target, preventDefault: () => prevented++ });

    assert.equal(carousel.atStart, true);
    assert.equal(carousel.atEnd, false);
    key('ArrowRight', {});
    assert.equal(track.scrollLeft, 0);
    key('ArrowRight');
    assert.equal(track.scrollLeft, 374);
    assert.equal(carousel.atStart, false);
    key('End');
    assert.equal(carousel.atEnd, true);
    carousel.previous();
    assert.equal(carousel.atEnd, false);
    key('Home');
    assert.equal(carousel.atStart, true);
    assert.equal(prevented, 3);
    carousel.destroy();
});

test('carousel updates after filtering or resizing and removes all observers and listeners', () => {
    const environment = browser({ reduced: true });
    const track = productTrack();
    const carousel = environment.mount('publicCarousel', { track });

    carousel.next();
    assert.equal(track.lastScroll.behavior, 'instant');
    track.scrollWidth = 350;
    track.scrollLeft = 0;
    environment.observers[1].callback();
    assert.equal(carousel.atStart, true);
    assert.equal(carousel.atEnd, true);
    track.scrollWidth = 1098;
    environment.observers[0].callback();
    assert.equal(carousel.atEnd, false);

    carousel.destroy();
    assert.equal(track.listenerCount(), 0);
    assert.ok(environment.observers.every((observer) => observer.disconnected));
});

for (const preference of [{ reduced: true }, { saveData: true }]) {
    test(`hero keeps its poster without downloading video when ${Object.keys(preference)[0]} is enabled`, () => {
        const environment = browser(preference);
        const video = heroVideo();
        const hero = environment.mount('farmHero', { video });
        environment.observers[0].callback([{ isIntersecting: true }]);

        assert.equal(video.src, '');
        assert.equal(video.playCalls, 0);
        assert.equal(hero.playing, false);

        hero.toggle();
        assert.equal(video.src, '/farm.mp4');
        assert.equal(hero.playing, true);
        hero.destroy();
    });
}

test('hero pauses offscreen and in hidden tabs, while an explicit pause survives returning', () => {
    const environment = browser();
    const video = heroVideo();
    const hero = environment.mount('farmHero', { video });
    const visibility = (visible) => environment.observers[0].callback([{ isIntersecting: visible }]);

    visibility(true);
    assert.equal(hero.playing, true);
    visibility(false);
    assert.equal(video.paused, true);
    visibility(true);
    assert.equal(hero.playing, true);
    environment.document.hidden = true;
    environment.document.emit('visibilitychange');
    assert.equal(video.paused, true);
    environment.document.hidden = false;
    environment.document.emit('visibilitychange');
    assert.equal(hero.playing, true);

    hero.toggle();
    visibility(false);
    visibility(true);
    assert.equal(video.paused, true);
    hero.destroy();
});

test('hero reacts to reduced motion and removes playback, visibility, and preference listeners', () => {
    const environment = browser();
    const video = heroVideo();
    const hero = environment.mount('farmHero', { video });
    environment.observers[0].callback([{ isIntersecting: true }]);
    environment.motion.matches = true;
    environment.motion.emit('change');
    assert.equal(video.paused, true);

    hero.destroy();
    assert.equal(video.listenerCount(), 0);
    assert.equal(environment.document.listenerCount(), 0);
    assert.equal(environment.motion.listenerCount(), 0);
    assert.equal(environment.connection.listenerCount(), 0);
    assert.ok(environment.observers.every((observer) => observer.disconnected));
});

test('video errors preserve the poster state and an autoplay refusal remains recoverable', async () => {
    const environment = browser();
    const video = heroVideo();
    video.play = () => Promise.reject(new Error('Autoplay is blocked'));
    const hero = environment.mount('farmHero', { video });
    environment.observers[0].callback([{ isIntersecting: true }]);
    await Promise.resolve();
    assert.equal(hero.playing, false);
    assert.equal(hero.unavailable, false);

    video.emit('error');
    assert.equal(hero.unavailable, true);
    hero.destroy();
});
