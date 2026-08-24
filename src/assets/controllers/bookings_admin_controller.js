import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.status = 'pending';

        document.querySelectorAll('#bookings-tabs .bookings-tab').forEach(t => {
            t.addEventListener('click', () => {
                document.querySelectorAll('#bookings-tabs .bookings-tab').forEach(x => x.classList.toggle('active', x === t));
                this.status = t.dataset.status;
                this.loadBookings();
            });
        });

        this.loadBookings();
    }

    esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    fmtLong(iso) {
        try {
            return new Date(iso).toLocaleString('es-AR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        } catch (e) { return iso; }
    }

    statusLabel(s) {
        return ({ pending: 'Pendiente', accepted: 'Aceptada', declined: 'Rechazada', proposed: 'Propuesto', canceled: 'Cancelada' })[s] || s;
    }

    async loadBookings() {
        const list = document.getElementById('bookings-list');
        if (!list) return;

        list.innerHTML = '<p class="bookings-loading">Cargando...</p>';
        try {
            const r = await fetch(`/api/academic/bookings?status=${encodeURIComponent(this.status)}`);
            const data = await r.json();

            if (!data.ok || !data.data) {
                list.innerHTML = '<div class="bookings-empty"><span class="material-symbols-elev bookings-empty-icon">event_busy</span><p>Sin reservas registradas</p></div>';
                return;
            }

            const items = Array.isArray(data.data) ? data.data : (data.data.bookings || []);
            const filtered = items.filter(b => !this.status || b.status === this.status);

            if (filtered.length === 0) {
                list.innerHTML = `
                    <div class="bookings-empty">
                        <span class="material-symbols-elev bookings-empty-icon">event_busy</span>
                        <p>Sin reservas ${this.status ? this.statusLabel(this.status).toLowerCase() + 's' : ''}.</p>
                    </div>
                `;
                return;
            }
            this.renderBookings(filtered);
        } catch (e) {
            list.innerHTML = '<p class="bookings-empty"><span class="material-symbols-elev bookings-empty-icon">cloud_off</span>Sin conexión.</p>';
        }
    }

    renderBookings(items) {
        const list = document.getElementById('bookings-list');
        if (!list) return;

        list.innerHTML = items.map(b => {
            const showActions = b.status === 'pending';
            const showCancel = ['pending', 'proposed', 'accepted'].includes(b.status);
            return `
                <article class="booking-card status-${this.esc(b.status)}" data-id="${this.esc(b.id)}">
                    <header class="booking-card-head">
                        <span class="booking-card-topic">${this.esc(b.topic || 'Clase 1:1')}</span>
                        <span class="booking-card-status ${this.esc(b.status)}">${this.esc(this.statusLabel(b.status))}</span>
                        ${showCancel ? `<button type="button" class="booking-btn cancel" data-action="cancel" data-id="${this.esc(b.id)}">Cancelar</button>` : ''}
                    </header>
                    <div class="booking-card-meta">
                        <strong>Alumno:</strong> ${this.esc(b.student_name || b.student_code || '—')}
                        &nbsp;·&nbsp;
                        <strong>Inicio:</strong> ${this.esc(this.fmtLong(b.start_at))}
                        &nbsp;·&nbsp;
                        <strong>Duración:</strong> ${b.duration_minutes || 30} min
                    </div>
                    ${b.notes ? `<div class="booking-card-notes">${this.esc(b.notes)}</div>` : ''}
                    ${showActions ? `
                        <div class="booking-card-actions">
                            <button type="button" class="booking-btn accept" data-action="accept" data-id="${this.esc(b.id)}">
                                <span class="material-symbols-elev" style="font-size:1rem;">check_circle</span>
                                Aceptar
                            </button>
                            <button type="button" class="booking-btn decline" data-action="decline" data-id="${this.esc(b.id)}">
                                <span class="material-symbols-elev" style="font-size:1rem;">block</span>
                                Rechazar
                            </button>
                            <button type="button" class="booking-btn propose" data-action="propose" data-id="${this.esc(b.id)}">
                                <span class="material-symbols-elev" style="font-size:1rem;">schedule</span>
                                Proponer horarios
                            </button>
                        </div>
                        <div class="proposed-form" data-id="${this.esc(b.id)}" hidden>
                            <textarea placeholder="Lista de horarios propuestos (uno por línea, formato: YYYY-MM-DD HH:MM)"></textarea>
                            <div class="proposed-form-actions">
                                <button type="button" class="booking-btn propose" data-action="submit-propose" data-id="${this.esc(b.id)}">Enviar propuesta</button>
                            </div>
                        </div>
                    ` : ''}
                </article>
            `;
        }).join('');

        list.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', () => this.handleAction(btn));
        });
    }

    async handleAction(btn) {
        const id = btn.dataset.id;
        const act = btn.dataset.action;

        if (act === 'cancel') {
            if (!confirm('¿Cancelar esta reserva?')) return;
            await this.patchBooking(`/api/academic/bookings/${id}/cancel`, {});
            this.loadBookings();
            return;
        }
        if (act === 'accept') {
            btn.disabled = true;
            await this.patchBooking(`/api/academic/bookings/${id}/accept`, {});
            this.loadBookings();
            return;
        }
        if (act === 'decline') {
            if (!confirm('¿Rechazar esta reserva?')) return;
            btn.disabled = true;
            await this.patchBooking(`/api/academic/bookings/${id}/decline`, {});
            this.loadBookings();
            return;
        }
        if (act === 'propose') {
            const form = document.querySelector(`.proposed-form[data-id="${id}"]`);
            form.hidden = !form.hidden;
            return;
        }
        if (act === 'submit-propose') {
            const form = document.querySelector(`.proposed-form[data-id="${id}"]`);
            const ta = form.querySelector('textarea');
            const lines = ta.value.split('\n').map(l => l.trim()).filter(Boolean);
            if (lines.length === 0) {
                if (window.apiToast) window.apiToast('Ingresá al menos un horario propuesto.', 'warning');
                return;
            }
            btn.disabled = true;
            await this.patchBooking(`/api/academic/bookings/${id}/propose`, {
                proposed_times: lines
            });
            this.loadBookings();
        }
    }

    async patchBooking(url, body) {
        try {
            const r = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const data = await r.json();
            if (!data.ok && window.apiToast) {
                window.apiToast(data.data && data.data.error ? data.data.error : 'Error', 'error');
            }
        } catch (e) { console.error(e); }
    }
}
