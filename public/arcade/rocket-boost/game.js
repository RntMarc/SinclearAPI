/* Rocket Boost — JS-Schale.
 *
 * Verantwortung bewusst klein gehalten:
 *   - Session-Token aus dem URL-Fragment lesen und erneuern
 *   - WASM-Modul laden (mit Fallback für falsche MIME-Types)
 *   - Score an die Arcade-API senden
 *   - HTML-Overlays und HUD steuern
 *
 * Die komplette Simulation und das Rendering liegen in Go (rocket.wasm).
 */
(function () {
    'use strict';

    var API_BASE = '/api/v2/arcade';
    var RENEW_EVERY_MS = 4 * 60 * 1000;
    var RENEW_BEFORE_SCORE_MS = 8 * 60 * 1000;
    var RENEW_TICK_MS = 30 * 1000;

    var ui = {
        overlay: document.getElementById('overlay'),
        title: document.getElementById('overlayTitle'),
        text: document.getElementById('overlayText'),
        result: document.getElementById('overlayScore'),
        button: document.getElementById('overlayBtn'),
        hint: document.getElementById('overlayHint'),
        hud: document.getElementById('hud'),
        hudScore: document.getElementById('hudScore'),
        debug: document.getElementById('debug')
    };

    var state = 'boot';
    var api = null;
    var token = readToken();
    var lastRenewAt = Date.now();
    var renewing = null;
    var activeSubmits = 0;
    var runSeq = 0;
    var loadMs = 0;
    var debugEnabled = /(?:^|[?&])debug=1(?:&|$)/.test(location.search);

    function readToken() {
        try {
            var hash = location.hash.replace(/^#/, '');
            if (!hash) {
                return '';
            }
            return new URLSearchParams(hash).get('arcade_token') || '';
        } catch (err) {
            return '';
        }
    }

    function writeTokenToHash(value) {
        try {
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#arcade_token=' + encodeURIComponent(value));
            }
        } catch (err) {
            /* Hash-Update ist Komfort, kein Fehlerfall. */
        }
    }

    function showOverlay(opts) {
        ui.overlay.classList.remove('is-hidden');
        ui.hud.classList.add('is-hidden');
        ui.title.textContent = opts.title || 'Rocket Boost';
        ui.text.textContent = opts.text || '';
        ui.text.hidden = !opts.text;

        if (opts.result === null || opts.result === undefined) {
            ui.result.hidden = true;
            ui.result.textContent = '';
        } else {
            ui.result.hidden = false;
            ui.result.textContent = String(opts.result);
        }

        ui.button.hidden = !opts.button;
        ui.button.textContent = opts.button || '';
        ui.hint.textContent = opts.hint || '';
        state = opts.state;
    }

    function hideOverlay() {
        ui.overlay.classList.add('is-hidden');
        ui.hud.classList.remove('is-hidden');
    }

    function showBoot() {
        showOverlay({ state: 'boot', title: 'Rocket Boost', text: 'Lädt …' });
    }

    function showIdle() {
        var hint = token ? '' : 'Ohne Spiel-Sitzung wird der Score nicht gespeichert.';
        showOverlay({
            state: 'idle',
            title: 'Rocket Boost',
            text: 'Tippen für Schub. Flieg durch die Korridore der Raumstation.',
            button: 'Start',
            hint: hint
        });
    }

    function showPaused() {
        showOverlay({
            state: 'paused',
            title: 'Pausiert',
            text: 'Weiter, wenn du bereit bist.',
            button: 'Weiter'
        });
    }

    function showDead(score) {
        showOverlay({
            state: 'dead',
            title: 'Mission beendet',
            result: score,
            button: 'Nochmal',
            hint: token ? 'Score wird gespeichert …' : 'Score nicht gespeichert (keine Sitzung).'
        });
    }

    function showError(message) {
        showOverlay({
            state: 'error',
            title: 'Spiel konnte nicht starten',
            text: message,
            button: 'Neu laden'
        });
    }

    function startGame() {
        if (!api) {
            return;
        }
        hideOverlay();
        state = 'running';
        ui.hudScore.textContent = '0';
        if (debugEnabled) {
            api.setDebug(true);
        }
        api.start();
        // Der Start-Tap gibt direkt Schub, damit die Rakete nicht sofort
        // unkontrolliert fällt, während der Finger noch auf dem Display liegt.
        api.tap();
    }

    function resumeGame() {
        if (!api) {
            return;
        }
        hideOverlay();
        state = 'running';
        api.pause(false);
        // Der selbe Tap, der fortsetzt, gibt direkt Schub – sonst kann die
        // Rakete beim Zurückkehren aus dem Hintergrund sofort abstürzen.
        api.tap();
    }

    function pauseGame() {
        if (api) {
            api.pause(true);
        }
        showPaused();
    }

    function primaryAction() {
        if (state === 'error') {
            location.reload();
            return;
        }
        if (!api) {
            return;
        }
        if (state === 'running') {
            api.tap();
            return;
        }
        if (state === 'paused') {
            resumeGame();
            return;
        }
        startGame();
    }

    function renewSession() {
        if (!token) {
            return Promise.resolve();
        }
        if (renewing) {
            return renewing;
        }

        renewing = fetch(API_BASE + '/session/renew', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token }
        }).then(function (response) {
            if (response.status === 401) {
                token = '';
                return null;
            }
            if (!response.ok) {
                return null;
            }
            return response.json().then(function (data) {
                if (data && typeof data.token === 'string' && data.token !== '') {
                    token = data.token;
                    lastRenewAt = Date.now();
                    writeTokenToHash(token);
                }
                return null;
            });
        }).catch(function () {
            // Netzwerkfehler: Token bleibt gültig, der nächste Tick versucht es erneut.
            return null;
        }).then(function () {
            renewing = null;
        }, function () {
            renewing = null;
        });

        return renewing;
    }

    function submitScore(score, durationMs, seq) {
        if (!token) {
            return;
        }

        activeSubmits++;

        var prepare = Promise.resolve();
        if (Date.now() - lastRenewAt > RENEW_BEFORE_SCORE_MS) {
            prepare = renewSession();
        }

        prepare.then(function () {
            if (!token) {
                if (seq === runSeq) {
                    ui.hint.textContent = 'Sitzung abgelaufen – bitte Spiel neu aus Beyond starten.';
                }
                return null;
            }
            return fetch(API_BASE + '/scores', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    score: score,
                    meta: { durationMs: durationMs }
                })
            }).then(function (response) {
                if (response.status === 401) {
                    token = '';
                    if (seq === runSeq) {
                        ui.hint.textContent = 'Sitzung abgelaufen – bitte Spiel neu aus Beyond starten.';
                    }
                    return null;
                }
                if (!response.ok) {
                    if (seq === runSeq) {
                        ui.hint.textContent = 'Score konnte nicht gespeichert werden (' + response.status + ').';
                    }
                    return null;
                }
                return response.json().then(function (data) {
                    if (seq !== runSeq) {
                        return null;
                    }
                    if (data && data.isPersonalBest) {
                        ui.hint.textContent = 'Neuer Rekord!';
                    } else if (data && typeof data.bestScore === 'number') {
                        ui.hint.textContent = 'Rekord: ' + data.bestScore;
                    } else {
                        ui.hint.textContent = 'Score gespeichert.';
                    }
                    return null;
                });
            });
        }).catch(function () {
            if (seq === runSeq) {
                ui.hint.textContent = 'Keine Verbindung – Score nicht gespeichert.';
            }
        }).then(function () {
            activeSubmits--;
        });
    }

    function handleRunEnd(score, durationMs) {
        runSeq++;
        var seq = runSeq;
        var safeScore = Math.max(0, Math.round(Number(score) || 0));
        var safeDuration = Math.max(0, Math.round(Number(durationMs) || 0));

        state = 'dead';
        if (!token) {
            showOverlay({
                state: 'dead',
                title: 'Mission beendet',
                result: safeScore,
                button: 'Nochmal',
                hint: 'Score nicht gespeichert (keine Sitzung).'
            });
            return;
        }

        showDead(safeScore);
        submitScore(safeScore, safeDuration, seq);
    }

    function instantiateFromBytes(go) {
        return fetch('rocket.wasm', { cache: 'no-cache' }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.arrayBuffer().then(function (bytes) {
                return WebAssembly.instantiate(bytes, go.importObject).then(function (result) {
                    return result.instance;
                });
            });
        });
    }

    function loadWasm(go) {
        return fetch('rocket.wasm', { cache: 'no-cache' }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            if (typeof WebAssembly.instantiateStreaming !== 'function') {
                return instantiateFromBytes(go);
            }

            var streaming;
            try {
                streaming = WebAssembly.instantiateStreaming(response, go.importObject);
            } catch (err) {
                // Manche Engines werfen synchron statt zu rejecten.
                return instantiateFromBytes(go);
            }

            return streaming
                .then(function (result) {
                    return result.instance;
                })
                .catch(function () {
                    // Falscher MIME-Type (z. B. application/octet-stream):
                    // zweiter Versuch über arrayBuffer.
                    return instantiateFromBytes(go);
                });
        });
    }

    function waitForRocket() {
        return new Promise(function (resolve, reject) {
            var started = performance.now();
            (function check() {
                if (window.rocket) {
                    resolve(window.rocket);
                    return;
                }
                if (performance.now() - started > 4000) {
                    reject(new Error('Go-Runtime hat sich nicht initialisiert.'));
                    return;
                }
                setTimeout(check, 16);
            })();
        });
    }

    function boot() {
        if (typeof Go === 'undefined') {
            showError('Die Go-Laufzeit (wasm_exec.js) fehlt.');
            return;
        }
        if (typeof WebAssembly === 'undefined') {
            showError('WebAssembly wird von diesem Browser nicht unterstützt.');
            return;
        }

        var startedAt = performance.now();
        var go;
        try {
            go = new Go();
        } catch (err) {
            console.error(err);
            showError('Die Go-Laufzeit konnte nicht initialisiert werden.');
            return;
        }

        loadWasm(go).then(function (instance) {
            loadMs = performance.now() - startedAt;

            // go.run() kehrt absichtlich nie zurück: main() blockiert und wird
            // ausschließlich über requestAnimationFrame weitergetrieben.
            var run = go.run(instance);
            if (run && typeof run.catch === 'function') {
                run.catch(function (err) {
                    console.error('go.run:', err);
                });
            }
            return waitForRocket();
        }).then(function (runtime) {
            api = runtime;
            if (debugEnabled) {
                api.setDebug(true);
                ui.debug.hidden = false;
            }
            showIdle();
        }).catch(function (err) {
            console.error(err);
            showError('Das Spiel konnte nicht geladen werden. Bitte Verbindung prüfen und neu laden.');
        });
    }

    window.rocketShell = {
        setScore: function (value) {
            ui.hudScore.textContent = String(Math.max(0, Math.round(Number(value) || 0)));
        },
        onState: function (name) {
            if (name === 'running') {
                state = 'running';
                hideOverlay();
            } else if (name === 'dead') {
                state = 'dead';
            }
        },
        onRunEnd: function (score, durationMs) {
            handleRunEnd(score, durationMs);
        },
        debug: function (fps, ms, walls) {
            if (!debugEnabled) {
                return;
            }
            ui.debug.hidden = false;
            ui.debug.textContent = 'FPS ' + fps + ' · ' + ms + ' ms · Walls ' + walls + ' · Load ' + Math.round(loadMs) + ' ms';
        }
    };

    document.addEventListener('pointerdown', function (event) {
        if (event.target === ui.button || (ui.button.contains && ui.button.contains(event.target))) {
            return;
        }
        event.preventDefault();
        primaryAction();
    }, { passive: false });

    ui.button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        primaryAction();
    });

    document.addEventListener('keydown', function (event) {
        if (event.code !== 'Space' && event.code !== 'ArrowUp' && event.key !== ' ') {
            return;
        }
        if (event.repeat) {
            return;
        }
        event.preventDefault();
        primaryAction();
    });

    document.addEventListener('contextmenu', function (event) {
        event.preventDefault();
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (state === 'running') {
                pauseGame();
            }
            return;
        }
        if (token && Date.now() - lastRenewAt >= RENEW_BEFORE_SCORE_MS) {
            renewSession();
        }
    });

    window.addEventListener('pagehide', function () {
        if (state === 'running') {
            pauseGame();
        }
    });

    setInterval(function () {
        if (document.hidden || !token || activeSubmits > 0) {
            return;
        }
        if (Date.now() - lastRenewAt >= RENEW_EVERY_MS) {
            renewSession();
        }
    }, RENEW_TICK_MS);

    showBoot();
    boot();
})();
