/**
 * TNSVT — Macro Academy Module
 * Puerto de las funciones JS de V1 (app.js:505-696)
 *
 * - mcNav2(panelId, btn)         → switch entre paneles
 * - mcShowNode(id)               → highlight flow node + mostrar info
 * - mcCalcInterest()             → slider Carlitos (live calc)
 * - mcCycle(idx)                 → switch entre 4 capítulos
 * - mcToggleAcc(id)              → accordion open/close
 * - mcScen(id, ev)               → FOMC scenario tabs (hawkish/dovish/neutral)
 * - mcAnswer(qId, btn, correct) → macro quiz (10 preguntas)
 * - mcNextQ(current)             → advance quiz
 * - mcResetQuiz()                → reset quiz
 * - geoQ(qId, choice, ok, fbId)  → inline quizzes (geo/divergencia/carry/curva/cicloeco)
 */

(function() {
    'use strict';

    // ══════ Switch panel (12 tabs) ══════
    function mcNav2(panelId, btn) {
        document.querySelectorAll('.macro-academy-nav .macro-navbtn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.mpanel').forEach(p => p.classList.remove('active'));
        const panel = document.getElementById(panelId);
        if (panel) panel.classList.add('active');
        if (btn) btn.classList.add('active');
        // Persistir en URL sin recargar
        try {
            const url = new URL(location.href);
            if (panelId === 'mc-bancos') url.searchParams.delete('p');
            else url.searchParams.set('p', panelId);
            history.replaceState(null, '', url);
        } catch (e) { /* noop */ }
        // Scroll suave al panel
        if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ══════ Flow node highlight (mc-bancos) ══════
    const mcNodeData = {
        fed:        { label: '🏛 Banco Central (FED)', text: 'El principal market maker del mercado Forex. Su función es inyectar liquidez o encarecer el crédito.' },
        bonos:      { label: '📜 Compra de Bonos',     text: 'El Estado emite un bono al Banco Central. Así se financia y se inyecta liquidez.' },
        tasas:      { label: '📈 Tasas de Interés',     text: 'El precio del dinero. Tasas bajas → economía se calienta, Dólar débil. Tasas altas → economía se enfría, Dólar fuerte.' },
        ciudadanos: { label: '👥 Economía Real',        text: 'El destinatario final de la política monetaria. Crédito barato estimula consumo e inversión.' }
    };

    function mcShowNode(id) {
        document.querySelectorAll('.mc-flow-node').forEach(n => n.classList.remove('highlighted'));
        const nodeBtn = document.querySelector(`.mc-flow-node[data-node="${id}"]`);
        if (nodeBtn) nodeBtn.classList.add('highlighted');
        const box = document.getElementById('mc-node-info');
        if (box && mcNodeData[id]) {
            document.getElementById('mc-node-label').innerHTML = mcNodeData[id].label;
            document.getElementById('mc-node-text').innerHTML = mcNodeData[id].text;
            box.classList.add('show');
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // ══════ Slider Carlitos (live calc) ══════
    function mcCalcInterest() {
        const rate = parseInt(document.getElementById('mc-rate-slider')?.value || 1);
        const total = 1000 + (1000 * rate / 100);
        const rateEl   = document.getElementById('mc-rate-val');
        const totalEl  = document.getElementById('mc-total-val');
        const acceptEl = document.getElementById('mc-accept-val');
        const econEl   = document.getElementById('mc-econ-val');
        const dollarEl = document.getElementById('mc-dollar-val');
        if (rateEl)   rateEl.textContent   = rate + '%';
        if (totalEl)  totalEl.textContent  = '$' + total.toLocaleString('es');
        if (rate <= 3) {
            if (acceptEl) { acceptEl.textContent = '😊 Sí, es barato'; acceptEl.className = 'mc-data-val up'; }
            if (econEl)   { econEl.textContent   = '📈 Se estimula';  econEl.className = 'mc-data-val up'; }
            if (dollarEl) { dollarEl.textContent = '📉 Se debilita';  dollarEl.className = 'mc-data-val down'; }
        } else if (rate <= 8) {
            if (acceptEl) { acceptEl.textContent = '😐 Tal vez...';   acceptEl.className = 'mc-data-val lateral'; }
            if (econEl)   { econEl.textContent   = '↔️ Neutral';       econEl.className = 'mc-data-val lateral'; }
            if (dollarEl) { dollarEl.textContent = '↔️ Neutral';       dollarEl.className = 'mc-data-val lateral'; }
        } else {
            if (acceptEl) { acceptEl.textContent = '😤 No, demasiado caro'; acceptEl.className = 'mc-data-val down'; }
            if (econEl)   { econEl.textContent   = '📉 Se enfría';           econEl.className = 'mc-data-val down'; }
            if (dollarEl) { dollarEl.textContent = '📈 Se fortalece';        dollarEl.className = 'mc-data-val up'; }
        }
    }
    window.mcCalcInterest = mcCalcInterest;

    // ══════ Cycle tabs (mc-ciclo) ══════
    function mcCycle(idx) {
        document.querySelectorAll('.mc-cycle-tab').forEach((t, i) => t.classList.toggle('active', i === idx));
        document.querySelectorAll('.mc-cycle-body').forEach((c, i) => c.classList.toggle('active', i === idx));
    }

    // ══════ Accordion (mc-gigantes, mc-geo) ══════
    function mcToggleAcc(id) {
        const acc = document.getElementById('mca-' + id);
        if (!acc) return;
        const wasOpen = acc.classList.contains('open');
        // Cerrar todos los demás accordions (comportamiento V1)
        document.querySelectorAll('.mc-accordion').forEach(a => {
            if (a.id !== 'mca-' + id) a.classList.remove('open');
        });
        if (!wasOpen) acc.classList.add('open');
    }

    // ══════ FOMC scenario tabs ══════
    function mcScen(id, ev) {
        if (ev) ev.stopPropagation();
        const btn = ev ? ev.currentTarget : document.querySelector(`[data-scen="${id}"]`);
        if (!btn) return;
        const container = btn.closest('.mc-acc-inner');
        if (!container) return;
        container.querySelectorAll('.mc-scen-tab').forEach(t => t.classList.remove('active'));
        container.querySelectorAll('.mc-scen-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const targetPanel = container.querySelector('#mc-scen-' + id);
        if (targetPanel) targetPanel.classList.add('active');
    }

    // ══════ Macro Quiz (10 preguntas) ══════
    let mcScore = 0;
    const mcExplanations = {
        q1:  '✅ Los Bancos Centrales son los únicos actores que pueden influir directamente en el valor de una divisa.',
        q2:  '✅ Si los empleos suben pero los salarios bajan, no hay presión inflacionaria → Dólar cae.',
        q3:  '✅ El CPI sale entre los días 10-15 del mes: Capítulo 3 (La Tendencia).',
        q4:  '✅ El mercado anticipa el futuro. Si el Dot Plot promete recortes, se vende Dólar HOY.',
        q5:  '✅ El Core CPI es el dato "puro". Si sube, inflación subyacente viva → FED agresiva → Dólar fuerte.',
        q6:  '✅ El NFP barre ambos lados. Esperar a que el caos se asiente es el protocolo correcto.',
        q7:  '✅ Conflicto en Europa → Risk Off: EUR cae, refugios (USD, CHF, JPY, Oro) suben.',
        q8:  '✅ Divergencia máxima: USA hawkish + crecimiento vs Europa dovish + contracción → EUR/USD bajista.',
        q9:  '✅ Petróleo sube → CAD se fortalece → USD/CAD baja. Correlación confiable.',
        q10: '✅ Convergencia perfecta = lateralización. Buscar otro par con divergencia clara.'
    };

    function mcAnswer(qId, btn, isCorrect) {
        const fb = document.getElementById('mcfb-' + qId);
        if (!fb) return;
        const opts = btn.parentElement.querySelectorAll('.mc-quiz-opt');
        opts.forEach(o => o.classList.add('disabled'));
        if (isCorrect) {
            btn.classList.add('correct');
            mcScore++;
            fb.className = 'mc-quiz-fb show correct';
            fb.textContent = mcExplanations[qId] || '✅ Correcto.';
        } else {
            btn.classList.add('wrong');
            fb.className = 'mc-quiz-fb show wrong';
            fb.textContent = '❌ Incorrecto. ' + (mcExplanations[qId] || '');
        }
        setTimeout(() => mcNextQ(qId), 2400);
    }

    function mcNextQ(current) {
        const num = parseInt(current.replace('q', ''));
        const cur = document.getElementById('mcq' + num);
        const nxt = document.getElementById('mcq' + (num + 1));
        if (nxt) {
            if (cur) cur.style.display = 'none';
            nxt.style.display = 'block';
            nxt.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            if (cur) cur.style.display = 'none';
            const res = document.getElementById('mc-quiz-result');
            if (res) {
                res.style.display = 'block';
                res.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const tiers = [
                    ['Seguí estudiando 💪',     'La macroeconomía se aprende con repetición.'],
                    ['Vas por buen camino 📈',  'Tenés las bases sólidas.'],
                    ['Nivel Avanzado ⭐',        'Entendés el ciclo macro y la geopolítica.'],
                    ['Dominio Estructural 🏆',   'Excelente. Combiná con análisis técnico.']
                ];
                const t = mcScore < 4 ? 0 : mcScore < 7 ? 1 : mcScore < 9 ? 2 : 3;
                document.getElementById('mc-result-title').textContent = tiers[t][0];
                document.getElementById('mc-result-desc').textContent  = tiers[t][1];
                document.getElementById('mc-result-score').textContent = mcScore + '/10';
            }
        }
    }

    function mcResetQuiz() {
        mcScore = 0;
        for (let i = 1; i <= 10; i++) {
            const qb = document.getElementById('mcq' + i);
            if (qb) {
                qb.style.display = i === 1 ? 'block' : 'none';
                qb.querySelectorAll('.mc-quiz-opt').forEach(o => {
                    o.className = 'mc-quiz-opt';
                    o.classList.remove('correct', 'wrong', 'disabled');
                });
            }
            const fb = document.getElementById('mcfb-q' + i);
            if (fb) fb.className = 'mc-quiz-fb';
        }
        const resDiv = document.getElementById('mc-quiz-result');
        if (resDiv) resDiv.style.display = 'none';
        const firstQ = document.getElementById('mcq1');
        if (firstQ) firstQ.scrollIntoView({ behavior: 'smooth' });
    }

    // ══════ Inline mini-quizzes (geo / divergencia / carry / curva / cicloeco) ══════
    const geoAnswers = {
        'geo-q1':   { correct: 'B', ex: '✅ EUR/CHF vendiendo EUR es el par ideal en conflicto europeo.' },
        'geo-q2':   { correct: 'B', ex: '✅ El mercado anticipa incertidumbre y huida de capitales → BRL cae.' },
        'geo-q3':   { correct: 'B', ex: '✅ Petróleo sube → inflación → FED hawkish → USD sube.' },
        'geo-q4':   { correct: 'B', ex: '✅ El rendimiento del bono sube por desconfianza → EUR bajo presión.' },
        'geo-q5':   { correct: 'B', ex: '✅ Petróleo sube → CAD fuerte → USD/CAD baja.' },
        'div-q1':   { correct: 'A', ex: '✅ Diferencial de tasas FED vs BoJ → USD/JPY alcista estructural.' },
        'div-esc1': { correct: 'B', ex: '✅ Diferencial a favor del USD + economía USA fuerte → EUR/USD bajista.' },
        'div-esc2': { correct: 'A', ex: '✅ Australia sube tasas mientras USA las baja → AUD/USD alcista.' },
        'div-esc3': { correct: 'C', ex: '✅ Convergencia perfecta → lateralización. Buscar otro par.' },
        'ct-q1':    { correct: 'B', ex: '✅ Carry unwinding: todos venden AUD y compran JPY → AUD/JPY cae.' },
        'ct-q2':    { correct: 'C', ex: '✅ VIX alto = pánico → carry unwinding → JPY sube.' },
        'cy-q1':    { correct: 'B', ex: '✅ Curva invertida (2Y > 10Y) → señal de recesión.' },
        'cy-q2':    { correct: 'B', ex: '✅ La recesión suele empezar cuando la curva se desinvierte.' },
        'ce-q1':    { correct: 'B', ex: '✅ Dos trimestres de PIB negativo + PMI bajo = recesión.' },
        'ce-q2':    { correct: 'B', ex: '✅ El mercado anticipa la recuperación → fase 4.' },
        'ce-q3':    { correct: 'B', ex: '✅ Stagflación: dilema imposible para el banco central.' }
    };

    function geoQ(qId, choice, isCorrect, fbId) {
        const fb = document.getElementById(fbId);
        const optsContainer = document.getElementById(qId + '-opts');
        if (optsContainer) optsContainer.querySelectorAll('.mc-quiz-opt').forEach(o => o.classList.add('disabled'));
        const btn = window.event?.currentTarget || document.activeElement;
        if (isCorrect) {
            if (btn && btn.classList) btn.classList.add('correct');
            if (fb) {
                fb.className = 'mc-quiz-fb show correct';
                fb.textContent = geoAnswers[qId] ? geoAnswers[qId].ex : '✅ Correcto.';
            }
        } else {
            if (btn && btn.classList) btn.classList.add('wrong');
            if (fb) {
                fb.className = 'mc-quiz-fb show wrong';
                fb.textContent = geoAnswers[qId] ? '❌ ' + geoAnswers[qId].ex : '❌ Incorrecto.';
            }
        }
    }

    // ══════ Boot ══════
    function bootMacroAcademy() {
        // 1. Init Carlitos slider
        mcCalcInterest();

        // 2. Show first banco node (FED)
        mcShowNode('fed');

        // 3. Restore tab from URL
        const urlTab = new URLSearchParams(location.search).get('p');
        const validTabs = ['mc-bancos','mc-datos','mc-ciclo','mc-gigantes','mc-dotplot','mc-geo','mc-divergencia','mc-carry','mc-curva','mc-cicloeco','mc-herramientas','mc-quiz'];
        if (urlTab && validTabs.includes(urlTab)) {
            const btn = document.querySelector(`.macro-navbtn[onclick*="${urlTab}"]`);
            if (btn) mcNav2(urlTab, btn);
        }

        // 3b. Wire onclick handlers on navbtns (fallback if inline onclick not firing)
        document.querySelectorAll('.macro-navbtn[data-panel]').forEach(btn => {
            btn.addEventListener('click', () => mcNav2(btn.dataset.panel, btn));
        });

        // 4. Wire flow-node data attributes (fallback)
        document.querySelectorAll('.mc-flow-node[data-node]').forEach(node => {
            node.addEventListener('click', () => mcShowNode(node.dataset.node));
        });

        // 5. Wire scen buttons (data-scen)
        document.querySelectorAll('[data-scen]').forEach(btn => {
            btn.addEventListener('click', e => mcScen(btn.dataset.scen, e));
        });

        // 6. Wire quiz opts in mc-quiz (delegation)
        document.querySelectorAll('.mc-quiz-box .mc-quiz-opt').forEach(opt => {
            opt.addEventListener('click', () => {
                const qMatch = opt.closest('.mc-quiz-box')?.id?.match(/mcq(\d+)/);
                if (!qMatch) return;
                const qId = 'q' + qMatch[1];
                const isCorrect = opt.classList.contains('mc-quiz-correct');
                mcAnswer(qId, opt, isCorrect);
            });
        });
    }

    // Expose globals (V1-style, in case inline onclick handlers reference them)
    window.mcNav2 = mcNav2;
    window.mcShowNode = mcShowNode;
    window.mcCycle = mcCycle;
    window.mcToggleAcc = mcToggleAcc;
    window.mcScen = mcScen;
    window.mcAnswer = mcAnswer;
    window.mcNextQ = mcNextQ;
    window.mcResetQuiz = mcResetQuiz;
    window.geoQ = geoQ;

    // Boot
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootMacroAcademy);
    } else {
        bootMacroAcademy();
    }
})();
