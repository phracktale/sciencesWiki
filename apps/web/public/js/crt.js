/* ============================================================================
   SciencesWiki — Comportements CRT (surface marketing).
   Servi en 'self' (CSP script-src 'self') : aucun nonce requis.
   - Allumage du tube (une fois par session)
   - Machine à écrire ([data-typewriter]) avec curseur à persistance
   - Horloge du bandeau terminal
   - Scroll « saccadé » par paliers sur les ancres internes
   - Prompt interactif (help, ls, cd, about, clear, sudo…)
   - Soumission du formulaire de contact (style transmission DOS) → /api/contact
   Respecte prefers-reduced-motion : tout effet animé est désactivé.
   ========================================================================== */
(function () {
    'use strict';

    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var body = document.body;
    var root = document.documentElement;

    /* Lit une variable CSS numérique (--x) avec repli. */
    function cssNum(name, fallback) {
        var n = parseFloat(getComputedStyle(root).getPropertyValue(name));
        return isNaN(n) ? fallback : n;
    }

    /* ----------------------------- Allumage tube ------------------------- */
    function powerOn() {
        if (REDUCED) return;
        try { if (sessionStorage.getItem('crt-booted')) return; } catch (e) {}
        body.classList.add('crt-boot');
        try { sessionStorage.setItem('crt-booted', '1'); } catch (e) {}
        body.addEventListener('animationend', function h(ev) {
            if (ev.animationName === 'crt-power') { body.classList.remove('crt-boot'); body.removeEventListener('animationend', h); }
        });
    }

    /* ------------------------------- Horloge ----------------------------- */
    function tickClock() {
        var el = document.querySelector('.term-bar .clock');
        if (!el) return;
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function upd() {
            var d = new Date();
            el.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }
        upd(); setInterval(upd, 1000);
    }

    /* ----------------------- Machine à écrire + curseur ------------------ */
    /* Chaque [data-typewriter] : on révèle son texte caractère par caractère.
       data-tw-delay = ms avant démarrage ; data-tw-speed = ms/caractère.
       Un curseur bloc suit la frappe et reste à la fin (clignotant). */
    function typewriters() {
        var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-typewriter]'));
        if (!nodes.length) return;

        if (REDUCED) {
            nodes.forEach(function (n) {
                n.textContent = n.getAttribute('data-text') || n.textContent;
                if (n.hasAttribute('data-tw-cursor')) { n.appendChild(makeCursor()); }
            });
            return;
        }

        var queue = nodes.map(function (n) {
            var full = n.getAttribute('data-text') || n.textContent;
            n.textContent = '';
            return { el: n, text: full, i: 0,
                speed: parseInt(n.getAttribute('data-tw-speed') || String(cssNum('--tw-speed', 24)), 10),
                delay: parseInt(n.getAttribute('data-tw-delay') || '0', 10) };
        });

        var qi = 0;
        function runOne() {
            if (qi >= queue.length) return;
            var job = queue[qi];
            var cur = makeCursor();
            job.el.appendChild(cur);
            setTimeout(function step() {
                if (job.i < job.text.length) {
                    var span = document.createElement('span');
                    span.className = 'persist';
                    span.textContent = job.text.charAt(job.i++);
                    job.el.insertBefore(span, cur);
                    setTimeout(step, job.speed + (Math.random() < 0.08 ? 90 : 0)); // micro-hésitations
                } else {
                    if (!job.el.hasAttribute('data-tw-cursor')) job.el.removeChild(cur);
                    qi++; runOne();
                }
            }, job.delay);
        }
        runOne();
    }
    function makeCursor() { var c = document.createElement('span'); c.className = 'cursor'; return c; }

    /* --------------------- Scroll saccadé (ancres internes) -------------- */
    /* On ne détourne PAS la molette (mauvaise UX) : on rend seulement les
       sauts d'ancre « par paliers » de quelques lignes, façon vieux terminal. */
    function steppedAnchors() {
        if (REDUCED) return;
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[href^="#"]');
            if (!a) return;
            var id = a.getAttribute('href').slice(1);
            if (!id) return;
            var target = document.getElementById(id);
            if (!target) return;
            e.preventDefault();
            var start = window.pageYOffset;
            var end = target.getBoundingClientRect().top + start - 24;
            var dist = end - start;
            var steps = Math.max(6, Math.min(22, Math.round(Math.abs(dist) / 40)));
            var n = 0;
            (function jump() {
                n++;
                window.scrollTo(0, start + dist * (n / steps));
                if (n < steps) setTimeout(jump, 18);
                else history.replaceState(null, '', '#' + id);
            })();
        });
    }

    /* --------------------------- Prompt interactif ----------------------- */
    function shell() {
        var input = document.getElementById('shell-cmd');
        var log = document.getElementById('shell-log');
        if (!input || !log) return;

        var ROUTES = window.CRT_ROUTES || {};
        function println(s) { log.textContent += (log.textContent ? '\n' : '') + s; }
        var COMMANDS = {
            help: function () {
                println('commandes : ls · cd <page> · open <page> · about · whoami · date · clear · sudo · donate');
            },
            ls: function () {
                var keys = Object.keys(ROUTES);
                println(keys.length ? keys.join('   ') : 'aucune page indexée');
            },
            about: function () { println('SciencesWiki — encyclopédie scientifique libre, sourcée, vulgarisée. Tout gratuit.'); },
            whoami: function () { println('invité@scienceswiki  (rôle : lecteur · curieux · esprit critique)'); },
            date: function () { println(new Date().toString()); },
            clear: function () { log.textContent = ''; },
            donate: function () { go('soutenir', 'redirection vers la cagnotte…'); },
            sudo: function () { println('sudo: la science n\'a pas besoin de privilèges root — elle a besoin de sources. Essayez : ls'); },
            cd: nav, open: nav
        };
        function nav(arg) {
            if (!arg) { println('usage : cd <page>   (cf. ls)'); return; }
            go(arg, null);
        }
        function go(arg, msg) {
            var key = String(arg).replace(/^\.?\//, '').toLowerCase();
            if (ROUTES[key]) { println(msg || ('cd ' + key + ' …')); setTimeout(function () { window.location.href = ROUTES[key]; }, 220); }
            else println('cd: ' + arg + ': aucune page de ce nom. Tapez « ls ».');
        }

        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var raw = input.value.trim();
            input.value = '';
            println('invité@scienceswiki:~$ ' + raw);
            if (!raw) return;
            var parts = raw.split(/\s+/);
            var fn = COMMANDS[parts[0].toLowerCase()];
            if (fn) fn(parts.slice(1).join(' '));
            else println(parts[0] + ': commande introuvable. Tapez « help ».');
            log.scrollTop = log.scrollHeight;
        });
    }

    /* ------------------------ Formulaire de contact ---------------------- */
    function contactForm() {
        var form = document.getElementById('contact-form');
        if (!form) return;
        var status = document.getElementById('contact-status');
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var honey = form.querySelector('input.hp');
            if (honey && honey.value) return; // pot de miel rempli → bot, on ignore
            var btn = form.querySelector('button[type=submit]');
            var data = {};
            form.querySelectorAll('input, textarea, select').forEach(function (el) {
                if (el.name && !el.classList.contains('hp')) data[el.name] = el.value;
            });
            if (!data.email || !data.message) {
                status.className = 'err'; status.textContent = '! ERREUR : e-mail et message sont requis.'; return;
            }
            btn.disabled = true;
            status.className = ''; status.textContent = '';
            var frames = ['TRANSMISSION', 'TRANSMISSION.', 'TRANSMISSION..', 'TRANSMISSION...'], fi = 0;
            var anim = REDUCED ? null : setInterval(function () { status.textContent = '> ' + frames[fi++ % frames.length]; }, 220);

            fetch('/api/contact', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                    if (anim) clearInterval(anim);
                    btn.disabled = false;
                    if (res.ok) {
                        status.className = 'ok';
                        status.textContent = '> TRANSMISSION OK — ' + (res.d.message || 'message reçu, 5/5.') + '\n> [ CONNEXION FERMÉE ]';
                        form.reset();
                    } else {
                        status.className = 'err';
                        status.textContent = '! ÉCHEC : ' + (res.d.error || 'erreur serveur') + '. Réessayez.';
                    }
                })
                .catch(function () {
                    if (anim) clearInterval(anim);
                    btn.disabled = false;
                    status.className = 'err'; status.textContent = '! ERREUR RÉSEAU — porteuse perdue. Réessayez.';
                });
        });
    }

    /* ----------------- Réglages en direct des effets (⚙) ----------------- */
    /* Chaque manette correspond à une variable CSS du bloc :root de crt.css.
       Les valeurs sont persistées dans localStorage ; « copier le CSS » génère
       le bloc à coller dans crt.css pour les rendre permanentes. */
    var STORE = 'crt-cfg';
    var KNOBS = [
        { v: '--scan-opacity',   label: 'Scanlines',          min: 0,  max: 0.6,  step: 0.01,  unit: '' },
        { v: '--rgb-opacity',    label: 'Masque RVB',         min: 0,  max: 0.08, step: 0.005, unit: '' },
        { v: '--vignette',       label: 'Vignette',           min: 0,  max: 1,    step: 0.02,  unit: '' },
        { v: '--curve',          label: 'Bombage du verre',   min: 0,  max: 14,   step: 1,     unit: '%' },
        { v: '--flicker',        label: 'Scintillement',      min: 0,  max: 0.05, step: 0.002, unit: '' },
        { v: '--roll',           label: 'Barre de balayage',  min: 0,  max: 0.12, step: 0.005, unit: '' },
        { v: '--roll-speed',     label: 'Vitesse balayage',   min: 2,  max: 20,   step: 0.5,   unit: 's' },
        { v: '--glow-blur',      label: 'Halo — flou',        min: 0,  max: 16,   step: 1,     unit: 'px' },
        { v: '--glow-strength',  label: 'Halo — intensité',   min: 0,  max: 0.6,  step: 0.02,  unit: '' },
        { v: '--cursor-speed',   label: 'Curseur — clignote', min: 0.2, max: 2,   step: 0.05,  unit: 's' },
        { v: '--tw-speed',       label: 'Frappe (ms/car)',    min: 0,  max: 80,   step: 1,     unit: '' }
    ];

    function readCfg() { try { return JSON.parse(localStorage.getItem(STORE) || '{}'); } catch (e) { return {}; } }
    function writeCfg(c) { try { localStorage.setItem(STORE, JSON.stringify(c)); } catch (e) {} }
    function applyVar(k, num) { root.style.setProperty(k.v, num + k.unit); }

    function loadCfg() {
        var c = readCfg();
        if (c.__off) body.classList.add('no-crt');
        KNOBS.forEach(function (k) { if (typeof c[k.v] === 'number') applyVar(k, c[k.v]); });
    }

    function buildPanel() {
        var bar = document.querySelector('.term-bar');
        if (!bar || document.getElementById('crt-panel')) return;

        var gear = document.createElement('button');
        gear.className = 'crt-gear'; gear.type = 'button';
        gear.setAttribute('aria-label', 'Réglages des effets CRT'); gear.textContent = '⚙ réglages';
        bar.appendChild(gear);

        var cfg = readCfg();
        var panel = document.createElement('div');
        panel.id = 'crt-panel'; panel.setAttribute('aria-label', 'Réglages des effets CRT');

        var html = '<button class="pclose" type="button" aria-label="Fermer">✕</button><h2>Réglages des effets</h2>';
        html += '<div class="crt-knob toggle"><label for="crt-master">Effets CRT activés</label>'
              + '<input type="checkbox" id="crt-master"' + (cfg.__off ? '' : ' checked') + '></div>';
        KNOBS.forEach(function (k, i) {
            var cur = (typeof cfg[k.v] === 'number') ? cfg[k.v] : cssNum(k.v, k.min);
            html += '<div class="crt-knob"><label for="crt-k' + i + '">' + k.label
                  + ' <b id="crt-v' + i + '">' + cur + k.unit + '</b></label>'
                  + '<input type="range" id="crt-k' + i + '" min="' + k.min + '" max="' + k.max
                  + '" step="' + k.step + '" value="' + cur + '"></div>';
        });
        html += '<div class="pactions">'
              + '<button class="btn" id="crt-copy" type="button">Copier le CSS</button>'
              + '<button class="btn" id="crt-reset" type="button">Réinitialiser</button></div>'
              + '<textarea id="crt-css-out" readonly aria-label="CSS à coller dans crt.css"></textarea>'
              + '<p class="phint">Astuce : « Frappe » et l\'allumage du tube s\'appliquent au prochain chargement. '
              + 'Réglages mémorisés dans ce navigateur ; collez le CSS dans le bloc :root de crt.css pour les figer pour tous.</p>';
        panel.innerHTML = html;
        body.appendChild(panel);

        function toggleOpen() { panel.classList.toggle('open'); }
        gear.addEventListener('click', toggleOpen);
        panel.querySelector('.pclose').addEventListener('click', function () { panel.classList.remove('open'); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') panel.classList.remove('open'); });

        KNOBS.forEach(function (k, i) {
            var slider = document.getElementById('crt-k' + i);
            var out = document.getElementById('crt-v' + i);
            slider.addEventListener('input', function () {
                var num = parseFloat(slider.value);
                applyVar(k, num); out.textContent = num + k.unit;
                var c = readCfg(); c[k.v] = num; writeCfg(c);
            });
        });

        var master = document.getElementById('crt-master');
        master.addEventListener('change', function () {
            var c = readCfg();
            if (master.checked) { body.classList.remove('no-crt'); delete c.__off; }
            else { body.classList.add('no-crt'); c.__off = true; }
            writeCfg(c);
        });

        document.getElementById('crt-copy').addEventListener('click', function () {
            var css = ':root {\n' + KNOBS.map(function (k, i) {
                return '    ' + k.v + ': ' + document.getElementById('crt-k' + i).value + k.unit + ';';
            }).join('\n') + '\n}';
            var ta = document.getElementById('crt-css-out');
            ta.style.display = 'block'; ta.value = css; ta.focus(); ta.select();
            if (navigator.clipboard) { navigator.clipboard.writeText(css).catch(function () {}); }
        });

        document.getElementById('crt-reset').addEventListener('click', function () {
            try { localStorage.removeItem(STORE); } catch (e) {}
            KNOBS.forEach(function (k) { root.style.removeProperty(k.v); });
            body.classList.remove('no-crt');
            KNOBS.forEach(function (k, i) {
                var def = cssNum(k.v, k.min);
                document.getElementById('crt-k' + i).value = def;
                document.getElementById('crt-v' + i).textContent = def + k.unit;
            });
            master.checked = true;
            document.getElementById('crt-css-out').style.display = 'none';
        });
    }

    /* --------------------------------- Init ------------------------------ */
    function init() {
        loadCfg(); powerOn(); tickClock(); typewriters(); steppedAnchors(); shell(); contactForm(); buildPanel();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
