import { Controller } from '@hotwired/stimulus';

/**
 * Multifractal checklist — recalcula el estado del setup
 * cada vez que el usuario marca/desmarca una condición.
 */
export default class extends Controller {
    static targets = ['result'];

    connect() {
        this.recalc();
    }

    recalc() {
        if (!this.hasResultTarget) return;
        const checks = this.element.querySelectorAll('.mf-check');
        const total = checks.length;
        const done = Array.from(checks).filter((c) => c.checked).length;

        if (done === 0) {
            this.resultTarget.innerHTML = '<p class="text-sm text-[var(--outline-elev)]">Marca las 4 condiciones para validar el setup</p>';
            this.resultTarget.classList.remove('mf-valid', 'mf-partial');
            return;
        }
        if (done === total) {
            this.resultTarget.innerHTML = `
                <p class="text-lg font-bold text-[var(--gold-elev)] mb-1">✓ SETUP VALIDADO</p>
                <p class="text-sm text-[var(--success-elev)]">4/4 condiciones — probabilidad máxima. Ejecuta con disciplina.</p>`;
            this.resultTarget.classList.add('mf-valid');
            this.resultTarget.classList.remove('mf-partial');
        } else {
            this.resultTarget.innerHTML = `
                <p class="text-base font-semibold text-[var(--outline-elev)] mb-1">${done}/${total} condiciones</p>
                <p class="text-xs text-[var(--outline-elev)]">Setup parcial — espera la confirmación antes de ejecutar.</p>`;
            this.resultTarget.classList.add('mf-partial');
            this.resultTarget.classList.remove('mf-valid');
        }
    }
}
