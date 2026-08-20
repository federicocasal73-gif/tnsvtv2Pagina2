// ⛧ Refactor A.2: SISTEMA DE NOTIFICACIONES extraído de app.js
let notifList = [];
let notifChannel = null;
let pushPermGranted = false;
let _fcmTokenRegistered = null;
let toastQueue = [];
let toastShowing = false;

// ---- Panel toggle ----
function toggleNotifPanel() {
  const panel = document.getElementById('notifPanel');
  panel.classList.toggle('open');
  if (panel.classList.contains('open')) {
    renderNotifPanel();
    // Cerrar al click fuera
    setTimeout(() => document.addEventListener('click', closeNotifOutside), 10);
  }
}
function closeNotifOutside(e) {
  const wrap = document.getElementById('notifBellWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('notifPanel')?.classList.remove('open');
    document.removeEventListener('click', closeNotifOutside);
  }
}

// ---- Render panel: trae del BACKEND, no del localStorage ----
async function renderNotifPanel() {
  const list = document.getElementById('notifList');
  if (!list) return;

  // Spinner mientras carga
  list.innerHTML = '<div class="notif-empty" style="opacity:.6">⏳ Cargando...</div>';

  if (!window.TNSVT_USER || !window.TNSVT_USER.code) {
    list.innerHTML = '<div class="notif-empty">🔔<br>Iniciá sesión para ver notificaciones.</div>';
    return;
  }

  try {
    const res = await fetch(`/api/notifications?user_code=${encodeURIComponent(window.TNSVT_USER.code)}`, {
      credentials: 'include'
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const items = await res.json();

    // Sincronizar cache local con backend (no se borra, se mergea)
    if (Array.isArray(items)) {
      notifList = items.map(n => ({
        id: String(n.id),
        type: n.type || 'dm',
        text: n.text || '',
        ts: n.ts || new Date().toISOString(),
        read: !!n.read,
        related_url: n.related_url || 'feed',
        link: n.link || '',
        _fromBackend: true,
      }));
      saveNotifs();
      updateBadge();
    }

    if (!notifList.length) {
      list.innerHTML = '<div class="notif-empty">🔔<br>Sin notificaciones aún.<br>Las señales y actividad<br>aparecerán aquí.</div>';
      return;
    }
    const showAll = window._notifShowHistory === true;
    const toRender = showAll ? notifList : notifList.filter(n => !n.read);
    if (!toRender.length) {
      list.innerHTML = '<div class="notif-empty">📋<br>Todo leído.<br>Activá historial para ver notificaciones pasadas.</div>';
      return;
    }
    list.innerHTML = toRender.slice(0, showAll ? 50 : 30).map(n => renderNotifItem(n)).join('');
  } catch (e) {
    console.warn('[notif] failed to load from backend, using cache:', e);
    // Fallback a cache local si el backend no responde
    if (!notifList.length) {
      list.innerHTML = '<div class="notif-empty">🔔<br>Sin notificaciones aún.<br>Las señales y actividad<br>aparecerán aquí.</div>';
      return;
    }
    const showAll = window._notifShowHistory === true;
    const toRender = showAll ? notifList : notifList.filter(n => !n.read);
    list.innerHTML = toRender.slice(0, showAll ? 50 : 30).map(n => renderNotifItem(n)).join('');
  }
}

// ---- Render de un item: muestra tipo, de quién y preview ----
function renderNotifItem(n) {
  const iconMap = {
    signal: '📊', like: '♥', comment: '💬', post: '✨',
    dm: '💬', mention: '📢', task: '✅', academia: '🎓',
    access_request: '🔗', access_accepted: '✅', access_rejected: '❌',
    connection_removed: '✂️', permissions_changed: '🔑',
    generic: '🔔'
  };
  const timeStr = timeAgoStr(n.ts);
  const idEscaped = String(n.id).replace(/'/g, "\\'");
  const type = n.type || 'generic';
  const title = n.title || ({ dm: 'Mensaje directo', signal: 'Nueva señal', academia: 'Academia', task: 'Tarea', comment: 'Comentario', mention: 'Mención', like: 'Reacción', post: 'Publicación', access_request: 'Solicitud de Acceso', access_accepted: 'Acceso Aceptado', access_rejected: 'Acceso Rechazado', connection_removed: 'Conexión Eliminada', permissions_changed: 'Permisos Actualizados' }[type] || 'Notificación');
  const sender = n.sender_name ? `<span class="notif-from">de <strong>${escapeHtml(n.sender_name)}</strong></span>` : '';
  const avatar = n.sender_avatar || iconMap[type] || '🔔';
  const preview = n.preview || n.text || '';
  const hasNumericId = /^\d+$/.test(String(n.id));
  const link = n.link || '';
  const socialLink = (type === 'access_request' || type === 'access_accepted' || type === 'access_rejected' || type === 'connection_removed' || type === 'permissions_changed');
  const actionHtml = socialLink
    ? `<button class="notif-action-btn" onclick="event.stopPropagation();deleteNotif('${idEscaped}');window.showSocialSection('requests')">Ver</button>`
    : '';

  return `
    <div class="notif-item ${n.read ? '' : 'unread'} type-${type} ${n.read ? 'history' : ''}" data-type="${type}" data-related-url="${escapeHtml(n.related_url || 'feed')}" data-link="${escapeHtml(link)}" onclick="markOneRead('${idEscaped}')">
      <div class="notif-icon ${type}">${avatar}</div>
      <div class="notif-body">
        <div class="notif-title">${escapeHtml(title)} ${sender}</div>
        <div class="notif-text">${escapeHtml(preview)}</div>
        <div class="notif-time">${timeStr} ${actionHtml}</div>
      </div>
      ${hasNumericId ? `<button class="notif-delete-btn" onclick="event.stopPropagation();deleteNotif('${idEscaped}')" title="Borrar notificacion" aria-label="Borrar">✕</button>` : ''}
    </div>`;
}

// ---- Borrar una notificacion ----
async function deleteNotif(id) {
  if (!window.TNSVT_USER?.code) return;
  if (!/^\d+$/.test(String(id))) return;
  if (!confirm('¿Borrar esta notificación?')) return;
  try {
    await window.API.deleteNotification(id, window.TNSVT_USER.code);
    // Quitar del array local
    notifList = notifList.filter(x => String(x.id) !== String(id));
    saveNotifs();
    updateBadge();
    renderNotifPanel();
    window.showToast('🗑️ Notificación borrada', 1800);
  } catch (e) {
    console.warn('[notif] delete:', e);
    window.showToast('❌ No pude borrar: ' + (e.message || 'error'));
  }
}

function timeAgoStr(ts) {
  const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 60000);
  if (diff < 1) return 'ahora mismo';
  if (diff < 60) return 'hace ' + diff + ' min';
  if (diff < 1440) return 'hace ' + Math.floor(diff/60) + 'h';
  return 'hace ' + Math.floor(diff/1440) + 'd';
}

// ---- Sonido de notificación (Web Audio API) ----
// ⛧ FIX: Usa CF._audioContext (el mismo del chat) — un solo contexto desbloqueado por user gesture.
async function playNotifSound() {
  const muted = localStorage.getItem('tnsvt_notif_sound_muted') === 'true';
  if (muted) return;
  // Get or create AudioContext via CF (shared with chat sound engine)
  let ctx = null;
  try {
    if (typeof window.CF !== 'undefined' && window.CF._audioContext) {
      ctx = window.CF._audioContext;
    } else {
      // Fallback: create a shared context if CF not loaded yet
      ctx = new (window.AudioContext || window.webkitAudioContext)();
      if (typeof window.CF !== 'undefined') window.CF._audioContext = ctx;
    }
  } catch(_) { return; }
  if (ctx.state === 'suspended') {
    try { await ctx.resume(); } catch(_) { return; }
  }
  try {
    const osc1 = ctx.createOscillator();
    const gain1 = ctx.createGain();
    osc1.type = 'sine'; osc1.frequency.value = 523;
    gain1.gain.setValueAtTime(0.15, ctx.currentTime);
    gain1.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.12);
    osc1.connect(gain1); gain1.connect(ctx.destination);
    osc1.start(ctx.currentTime); osc1.stop(ctx.currentTime + 0.12);

    const osc2 = ctx.createOscillator();
    const gain2 = ctx.createGain();
    osc2.type = 'sine'; osc2.frequency.value = 659;
    gain2.gain.setValueAtTime(0.12, ctx.currentTime + 0.1);
    gain2.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
    osc2.connect(gain2); gain2.connect(ctx.destination);
    osc2.start(ctx.currentTime + 0.1); osc2.stop(ctx.currentTime + 0.25);
  } catch(_) {}
}

// ⛧ CRITICAL FIX: Desbloquear AudioContext en el primer user gesture del navegador.
(function _unlockAudioOnGesture() {
  const unlock = async () => {
    if (typeof window.CF !== 'undefined' && window.CF._audioContext && window.CF._audioContext.state === 'suspended') {
      try { await window.CF._audioContext.resume(); } catch(_) {}
    }
  };
  for (const evt of ['click', 'touchstart', 'keydown']) {
    document.addEventListener(evt, unlock, { once: true, capture: true });
  }
})();

window.toggleNotifSound = function() {
  const muted = localStorage.getItem('tnsvt_notif_sound_muted') === 'true';
  localStorage.setItem('tnsvt_notif_sound_muted', muted ? 'false' : 'true');
  const btn = document.getElementById('notifSoundToggle');
  if (btn) btn.textContent = muted ? '🔇' : '🔔';
  window.showToast(muted ? '🔔 Sonido activado' : '🔇 Sonido desactivado');
};

window.toggleNotifHistory = function() {
  window._notifShowHistory = !window._notifShowHistory;
  const btn = document.getElementById('notifHistoryToggle');
  if (btn) btn.style.opacity = window._notifShowHistory ? '1' : '0.5';
  renderNotifPanel();
};

// ---- Agregar notificación ----
function addNotif(type, text) {
  const n = { id: Date.now() + '_' + Math.random().toString(36).slice(2), type, text, ts: new Date().toISOString(), read: false };
  notifList.unshift(n);
  if (notifList.length > 100) notifList = notifList.slice(0, 100);
  saveNotifs();
  updateBadge();
  // ⛧ FIX: playNotifSound() is already called inside processToastQueue — don't play twice
  showPushToast(type, text);
  if (pushPermGranted) fireBrowserNotif(type, text);
}

function saveNotifs() {
  localStorage.setItem('tnsvt_notifs', JSON.stringify(notifList));
}

function updateBadge() {
  const unread = notifList.filter(n => !n.read).length;
  const badge = document.getElementById('notifBadge');
  const bell = document.getElementById('notifBellBtn');
  if (!badge || !bell) return;
  if (unread > 0) {
    badge.textContent = unread > 9 ? '9+' : unread;
    badge.classList.add('show');
    bell.classList.add('has-notifs');
  } else {
    badge.classList.remove('show');
    bell.classList.remove('has-notifs');
  }
  // Social badge for pending access requests
  const socialBadge = document.getElementById('social-notif-badge');
  if (socialBadge) {
    const socialTypes = ['access_request', 'access_accepted', 'access_rejected', 'connection_removed', 'permissions_changed'];
    const prevSocialCount = parseInt(socialBadge.textContent) || 0;
    const socialCount = notifList.filter(n => socialTypes.includes(n.type) && !n.read).length;
    if (socialCount > 0) {
      socialBadge.textContent = socialCount > 9 ? '9+' : socialCount;
      socialBadge.style.display = 'inline';
      // ⛧ FIX BUG-12: animación pulse cuando llega nueva notif social
      if (socialCount > prevSocialCount) {
        socialBadge.classList.remove('social-badge-pulse');
        void socialBadge.offsetWidth;
        socialBadge.classList.add('social-badge-pulse');
      }
    } else {
      socialBadge.style.display = 'none';
    }
  }
}

function markAllRead() {
  notifList.forEach(n => n.read = true);
  saveNotifs(); updateBadge(); renderNotifPanel();
  // Persistir en backend
  if (window.TNSVT_USER?.code) {
    fetch(`/api/notifications/read-all?user_code=${encodeURIComponent(window.TNSVT_USER.code)}`, {
      method: 'PUT',
      credentials: 'include'
    }).catch(e => console.warn('[notif] markAllRead backend:', e));
  }
}

function markOneRead(id) {
  const n = notifList.find(x => x.id === id);
  if (!n) return;
  n.read = true;
  saveNotifs();
  updateBadge();

  // Fade-out animation on the notification item
  const itemEl = document.querySelector(`.notif-item[onclick*="'${id}'"]`);
  if (itemEl) {
    itemEl.classList.add('fade-out');
    setTimeout(() => { itemEl.remove(); }, 350);
  }

  // Persistir en backend si el id es numérico (id real de DB)
  if (window.TNSVT_USER?.code && /^\d+$/.test(String(id))) {
    fetch(`/api/notifications/${id}/read?user_code=${encodeURIComponent(window.TNSVT_USER.code)}`, {
      method: 'PUT',
      credentials: 'include'
    }).catch(e => console.warn('[notif] markOneRead backend:', e));
  }
  // Cerrar panel
  document.getElementById('notifPanel')?.classList.remove('open');

  // === DEEP-LINKING: navegar a la sección correspondiente ===
  const relatedUrl = n.related_url || 'feed';
  const link = n.link || '';
  const tabMap = {
    'feed': 'tab-posts',
    'chat': 'tab-chat',
    'signals': 'tab-posts',
    'academia': 'tab-academia',
    'tasks': 'tab-tasks',
    'journal': 'tab-journal',
    'calendar': 'tab-calendar',
    'social': 'tab-social',
  };
  const tabId = tabMap[relatedUrl] || 'tab-posts';

  if (typeof window.switchTab === 'function') {
    window.switchTab(tabId);
  } else {
    document.querySelectorAll('.sidebar-btn').forEach(b => b.classList.remove('active'));
    const btn = document.querySelector(`[onclick*="${tabId}"]`);
    if (btn) btn.classList.add('active');
  }

  // Si es DM, abrir la conversación específica en el CF widget
  if (relatedUrl === 'chat' && n.type === 'dm') {
    const convId = link ? (link.startsWith('chat:') ? parseInt(link.slice(5), 10) : parseInt(link, 10)) : null;
    if (convId && window.CF && typeof window.CF.openConv === 'function') {
      setTimeout(() => { window.CF.openConv(convId); }, 200);
    }
  }

  // Si es feed con categoría señales, filtrar
  if (relatedUrl === 'signals' && typeof window.filterFeed === 'function') {
    const btnSenales = document.querySelector('[onclick*="filterFeed"][onclick*="señales"]');
    if (btnSenales) btnSenales.click();
  }

  // Social: deep-link al sub-panel correcto
  if (relatedUrl === 'social') {
    // ⛧ FIX BUG-37: Recargar connections/badge cuando la notif afecta el count
    const socialEffectTypes = ['access_accepted', 'access_rejected', 'connection_removed'];
    if (socialEffectTypes.includes(n.type)) {
      // Forzar reload de la lista si está cargada
      if (typeof window._socialLoaded !== 'undefined' && window._socialLoaded) {
        window._socialLoaded = false;
        if (typeof window.loadAllUsers === 'function') window.loadAllUsers();
      } else if (typeof window._updateConnectionCount === 'function') {
        window._updateConnectionCount();
      }
    }
    setTimeout(() => {
      // ⛧ FIX BUG-28: Diferenciar sub-tabs por tipo de notificación
      if (n.type === 'access_request') {
        window.showSocialSection('requests');
      } else if (n.type === 'permissions_changed') {
        window.showSocialSection('users');
      } else if (n.type === 'access_accepted' || n.type === 'access_rejected' || n.type === 'connection_removed') {
        window.showSocialSection('users');
        // Refresh solicitudes recibidas/enviadas también
        if (typeof window.loadAccessRequests === 'function') window.loadAccessRequests();
      }
    }, 300);
  }
}

// ---- Push toast flotante ----

function showPushToast(type, text) {
  toastQueue.push({ type, text });
  if (!toastShowing) processToastQueue();
}

function processToastQueue() {
  if (!toastQueue.length) { toastShowing = false; return; }
  toastShowing = true;
  const { type, text } = toastQueue.shift();
  const iconMap = { signal: '📊', like: '♥', comment: '💬', post: '✨', dm: '💬', task: '✅', academia: '🎓', access_request: '🔗', access_accepted: '✅', access_rejected: '❌', connection_removed: '✂️', permissions_changed: '🔑' };
  const titleMap = { signal: 'Nueva Señal', like: 'Nuevo Like', comment: 'Nuevo Comentario', post: 'Nuevo Post', dm: 'Mensaje Directo', task: 'Nueva Tarea', academia: 'Academia', access_request: 'Solicitud de Acceso', access_accepted: 'Acceso Aceptado', access_rejected: 'Acceso Rechazado', connection_removed: 'Conexion Eliminada', permissions_changed: 'Permisos Actualizados' };
  const relatedUrls = { signal: 'signals', dm: 'chat', task: 'tasks', academia: 'academia', access_request: 'social', access_accepted: 'social', access_rejected: 'social', connection_removed: 'social', permissions_changed: 'social' };
  const actionLabels = { access_request: 'Ver solicitud', access_accepted: 'Ver conexiones', access_rejected: 'Ver', connection_removed: 'Ver conexiones', permissions_changed: 'Ver permisos', dm: 'Responder', signal: 'Ver senal', task: 'Ver tarea' };
  const el = document.createElement('div');
  el.className = 'push-toast';
  const toastRelated = relatedUrls[type] || 'feed';
  const actionLabel = actionLabels[type] || 'Ver';
  el.innerHTML = `
    <div class="push-toast-icon">${iconMap[type] || '🔔'}</div>
    <div class="push-toast-body">
      <div class="push-toast-title">${titleMap[type] || 'Notificacion'}</div>
      <div class="push-toast-msg">${text}</div>
      <button class="push-toast-action" onclick="event.stopPropagation();this.closest('.push-toast').dispatchEvent(new CustomEvent('toast-action'))">${actionLabel}</button>
    </div>
    <button class="push-toast-close" onclick="event.stopPropagation();this.closest('.push-toast').remove()">✕</button>`;
  el.dataset.relatedUrl = toastRelated;
  el.addEventListener('toast-action', () => {
    const relatedUrl = el.dataset.relatedUrl || 'feed';
    const tabMap = { 'feed':'tab-posts', 'chat':'tab-chat', 'signals':'tab-posts', 'academia':'tab-academia', 'tasks':'tab-tasks', 'journal':'tab-journal', 'calendar':'tab-calendar', 'social':'tab-social' };
    const tabId = tabMap[relatedUrl] || 'tab-posts';
    if (typeof window.switchTab === 'function') window.switchTab(tabId);
    if (relatedUrl === 'social') {
      setTimeout(() => {
        if (type === 'access_request') window.showSocialSection('requests');
        else window.showSocialSection('users');
      }, 300);
    }
    el.remove();
  });
  el.onclick = (e) => {
    if (e.target.classList.contains('push-toast-close') || e.target.classList.contains('push-toast-action')) return;
    el.dispatchEvent(new CustomEvent('toast-action'));
  };
  playNotifSound();
  document.body.appendChild(el);
  setTimeout(() => {
    if (el.parentNode) {
      el.classList.add('hiding');
      setTimeout(() => { el.remove(); setTimeout(processToastQueue, 200); }, 300);
    } else { setTimeout(processToastQueue, 200); }
  }, 6000);
}

// ---- Notificaciones del navegador (Web Push API) ----
function checkPushPermission() {
  if (!('Notification' in window)) return;
  if (Notification.permission === 'granted') {
    pushPermGranted = true;
    // Auto-inicializar Firebase si ya tenemos permiso
    if (window.TNSVT_USER && window.TNSVT_USER.code) {
      initFirebasePush().then(ok => {
        if (ok) console.log('[FCM] Auto-inicializado (permiso ya granted)');
      });
    }
    return;
  }
  if (Notification.permission === 'denied') return;
  const dismissed = localStorage.getItem('tnsvt_push_dismissed');
  if (!dismissed) {
    setTimeout(() => document.getElementById('pushPermBar')?.classList.add('show'), 3000);
  }
}

function requestPushPermission() {
  if (!('Notification' in window)) { window.showToast('Tu navegador no soporta notificaciones push'); return; }
  Notification.requestPermission().then(result => {
    if (result === 'granted') {
      document.getElementById('pushPermBar')?.classList.remove('show');
      initFirebasePush().then(ok => {
        if (ok) {
          pushPermGranted = true;
          window.showToast('✅ Notificaciones activadas');
          try {
            new Notification('T.N.S.V.T', {
              body: '🔔 Vas a recibir alertas de señales, comentarios y actividad del Reino.',
              icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">⛧</text></svg>'
            });
          } catch (e) { /* algunos navegadores bloquean la primer notif */ }
          if (typeof updateAvatarNotifBtn === 'function') updateAvatarNotifBtn();
        } else {
          window.showToast('⚠️ Permiso OK pero Firebase no se inicializó. Revisá la consola.');
        }
      });
    } else {
      window.showToast('Permisos denegados. Activá desde el navegador manualmente.');
      dismissPushBar();
      if (typeof updateAvatarNotifBtn === 'function') updateAvatarNotifBtn();
    }
  });
}

// Inicializa Firebase Web Push: carga el SDK, registra el SW, obtiene el FCM token
// y lo guarda en el backend. Re-entrante y tolerante a fallos.
async function initFirebasePush() {
  if (_fcmTokenRegistered) return _fcmTokenRegistered;
  _fcmTokenRegistered = (async () => {
    try {
      if (!('serviceWorker' in navigator) || !('Notification' in window)) {
        console.warn('[FCM] SW o Notification no soportados');
        return false;
      }
      // 0) Registrar sw.js PWA principal (offline + cache), si no existe
      try {
        const existing = await navigator.serviceWorker.getRegistration('/sw.js');
        if (!existing) {
          await navigator.serviceWorker.register('/sw.js', { scope: '/' });
          console.log('[PWA] sw.js registrado');
        }
      } catch (e) { console.warn('[PWA] sw.js no se pudo registrar:', e); }
      // 1) Cargar SDK de Firebase (compat v10) si no está
      if (typeof firebase === 'undefined') {
        await loadScript('https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js');
        await loadScript('https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js');
      }
      // 2) Obtener config pública del backend
      const config = await window.API.get('/api/firebase/config');
      if (!config || !config.configured) {
        console.warn('[FCM] Backend no configurado:', config && config.error);
        return false;
      }
      // 3) Inicializar Firebase (solo una vez)
      if (!firebase.apps.length) {
        firebase.initializeApp({
          apiKey: config.apiKey,
          authDomain: config.authDomain,
          projectId: config.projectId,
          storageBucket: config.storageBucket,
          messagingSenderId: config.messagingSenderId,
          appId: config.appId,
        });
      }
      const messaging = firebase.messaging();
      // 4) Registrar el service worker
      const swReg = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
      console.log('[FCM] SW registrado, scope:', swReg.scope);
      // 5) Obtener el FCM token (requiere permiso granted)
      let fcmToken;
      try {
        const tokenOptions = { serviceWorkerRegistration: swReg };
        if (config.vapidKey && /^[A-Za-z0-9_-]+$/.test(config.vapidKey)) {
          tokenOptions.vapidKey = config.vapidKey;
        } else {
          console.log('[FCM] VAPID no configurada, saltando push nativo (in-app sí funciona)');
        }
        fcmToken = await messaging.getToken(tokenOptions);
      } catch (e) {
        // Una sola vez: mostrar la razón exacta para diagnóstico
        if (!window._fcmDiagnosed) {
          window._fcmDiagnosed = true;
          console.warn('[FCM] Push nativo no disponible. Razón:', e.message || e.name);
          console.warn('[FCM] Diagnóstico: GET /api/firebase/diagnose');
          try {
            const diag = await window.API.get('/api/firebase/diagnose');
            console.warn('[FCM] Backend dice:', diag);
            if (diag.has_vapid && diag.vapid_length > 50) {
              console.warn('[FCM] La VAPID en .env está seteada, pero el navegador la rechaza.');
              console.warn('[FCM] Causa probable: la VAPID es de OTRO proyecto Firebase que project_id.');
              console.warn('[FCM] Solución: regenerar la VAPID en Firebase Console del MISMO proyecto que project_id=' + diag.project_id);
            }
          } catch(e2) { console.warn('[FCM] No pude obtener diagnóstico:', e2.message); }
        }
        return false;
      }
      if (!fcmToken) {
        console.log('[FCM] getToken() vacío, polling in-app activo');
        return false;
      }
      console.log('[FCM] Token obtenido:', fcmToken.substring(0, 20) + '...');
      // 6) Guardar el token en el backend
      if (window.TNSVT_USER && window.TNSVT_USER.code) {
        await window.API.post('/api/devices/register', {
          user_code: window.TNSVT_USER.code,
          fcm_token: fcmToken,
          platform: 'web',
          device_model: navigator.userAgent.substring(0, 200),
        });
        console.log('[FCM] Token registrado en backend para', window.TNSVT_USER.code);
      } else {
        console.warn('[FCM] No hay usuario logueado, token NO guardado');
        return false;
      }
      // 7) Escuchar mensajes en foreground (cuando el tab está activo)
      messaging.onMessage((payload) => {
        console.log('[FCM] Foreground message:', payload);
        const title = (payload.notification && payload.notification.title) || 'T.N.S.V.T';
        const body = (payload.notification && payload.notification.body) || (payload.data && payload.data.text) || '';
        const notifType = (payload.data && payload.data.type) || 'generic';
        playNotifSound();
        window.showToast('🔔 ' + title + (body ? ': ' + body : ''));
        // ⛧ FIX BUG-29: usar addNotif para sincronizar socialBadge y notifList
        addNotif(notifType, body);
        if (pushPermGranted) fireBrowserNotif(notifType, body);
      });
      // 8) Manejar el caso de token refrescado (puede no existir en Firebase v10+)
      if (typeof messaging.onTokenRefresh === 'function') {
        messaging.onTokenRefresh(async () => {
          try {
            const newToken = await messaging.getToken(tokenOptions);
            if (newToken && window.TNSVT_USER && window.TNSVT_USER.code) {
              await window.API.post('/api/devices/register', {
                user_code: window.TNSVT_USER.code,
                fcm_token: newToken,
                platform: 'web',
                device_model: navigator.userAgent.substring(0, 200),
              });
            }
          } catch (e) { console.warn('[FCM] Token refresh error:', e); }
        });
      }
      return true;
    } catch (e) {
      // En Capacitor/APK no hay SW de firebase, asi que el register falla con AbortError.
      // Silenciar ese caso especifico para no ensuciar la consola.
      const isAbort = e && (e.name === 'AbortError' || /ServiceWorker/.test(String(e.message || '')));
      if (!isAbort) console.error('[FCM] initFirebasePush error:', e);
      return false;
    }
  })();
  return _fcmTokenRegistered;
}

function loadScript(src) {
  return new Promise((resolve, reject) => {
    if (document.querySelector('script[src="' + src + '"]')) return resolve();
    const s = document.createElement('script');
    s.src = src; s.async = false;
    s.onload = resolve;
    s.onerror = () => reject(new Error('No se pudo cargar ' + src));
    document.head.appendChild(s);
  });
}

function dismissPushBar() {
  document.getElementById('pushPermBar')?.classList.remove('show');
  localStorage.setItem('tnsvt_push_dismissed', '1');
}

// Actualizar el botón del menú del avatar según el estado de notificaciones
function updateAvatarNotifBtn() {
  const btn = document.getElementById('avatarNotifBtn');
  const icon = document.getElementById('avatarNotifIcon');
  const label = document.getElementById('avatarNotifLabel');
  if (!btn || !icon || !label) return;
  const perm = (typeof Notification !== 'undefined') ? Notification.permission : 'default';
  if (perm === 'granted' && pushPermGranted) {
    icon.textContent = '🔔';
    label.textContent = 'Notificaciones activas';
    btn.style.color = '#34c759';
  } else if (perm === 'denied') {
    icon.textContent = '🔕';
    label.textContent = 'Notificaciones bloqueadas';
    btn.style.color = '#f87171';
  } else {
    icon.textContent = '🔔';
    label.textContent = 'Activar notificaciones';
    btn.style.color = '#e2dcf0';
  }
}

function toggleNotificationsFromMenu() {
  const perm = (typeof Notification !== 'undefined') ? Notification.permission : 'default';
  if (perm === 'denied') {
    window.showToast('🔕 Permiso bloqueado desde el navegador. Cambialo en los ajustes del sitio.');
    return;
  }
  if (perm === 'granted' && pushPermGranted) {
    window.showToast('✓ Notificaciones ya activas. Para desactivarlas usá los ajustes del navegador.');
    return;
  }
  requestPushPermission();
}

function fireBrowserNotif(type, text) {
  if (!pushPermGranted || Notification.permission !== 'granted') return;
  const titleMap = { signal: '📊 Nueva Señal — T.N.S.V.T', like: '♥ Nuevo Like', comment: '💬 Nuevo Comentario', post: '✨ Nuevo Post' };
  new Notification(titleMap[type] || '🔔 T.N.S.V.T', {
    body: text,
    icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">⛧</text></svg>',
    tag: 'tnsvt-' + type,
    renotify: true
  });
}

// Notificaciones — polling cada 15s con detección de nuevas notifs
function initNotifRealtime() {
  if (!window.TNSVT_USER) return;
  const updateBadge = (count) => {
    const badge = document.getElementById('notifBadge');
    const bell = document.getElementById('notifBellBtn');
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 9 ? '9+' : count;
      badge.classList.add('show');
      if (bell) bell.classList.add('has-notifs');
    } else {
      badge.textContent = '';
      badge.classList.remove('show');
      if (bell) bell.classList.remove('has-notifs');
    }
  };
  // Inicial: setear en 0
  updateBadge(0);
  let _lastNotifCount = 0;
  const _seenNotifIds = new Set();
  // Polling cada 15s
  let pollTimer = setInterval(async () => {
    if (!window.TNSVT_USER || !window.TNSVT_USER.code) return;
    try {
      const result = await window.sb.getNotifCount(window.TNSVT_USER.code);
      const count = (result && typeof result.count === 'number') ? result.count : 0;
      updateBadge(count);
      // Detect new notifications arrived
      if (count > _lastNotifCount && _lastNotifCount > 0) {
        // Fetch the actual notifs to get type/text for toast
        try {
          const notifsData = await window.sb.getNotifications(window.TNSVT_USER.code);
          const notifs = Array.isArray(notifsData) ? notifsData : (notifsData?.notifications || []);
          // Show toast + sound for each unseen notif
          for (const n of notifs) {
            const nid = n.id || n.type + '_' + n.createdAt;
            if (!_seenNotifIds.has(nid)) {
              _seenNotifIds.add(nid);
              if (n.isRead === false || n.isRead === 0) {
                playNotifSound();
                showPushToast(n.type || 'generic', n.text || n.message || 'Nueva notificación');
              }
            }
          }
        } catch(_) { /* badge already updated, sound can wait */ }
      }
      _lastNotifCount = count;
    } catch(e) {
      console.warn('notif poll error:', e);
    }
  }, 15000);
  // Si en algun momento se hace logout, parar el polling
  const stopWatch = setInterval(() => {
    if (!window.TNSVT_USER) {
      clearInterval(pollTimer);
      clearInterval(stopWatch);
    }
  }, 5000);
  // Tambien refrescar al abrir el panel
  const origToggle = window.toggleNotifPanel;
  window.toggleNotifPanel = async function() {
    if (typeof origToggle === 'function') origToggle.apply(this, arguments);
    if (!window.TNSVT_USER || !window.TNSVT_USER.code) return;
    try {
      const result = await window.sb.getNotifCount(window.TNSVT_USER.code);
      updateBadge((result && typeof result.count === 'number') ? result.count : 0);
    } catch(e) {}
  };
}

// Clear notifList (called from logout)
function clearNotifs() {
  notifList = [];
}

// ---- Window exports ----
window.toggleNotifPanel = toggleNotifPanel;
window.openNotifications = function() {
  if (typeof window.toggleNotifPanel === 'function') {
    window.toggleNotifPanel();
  }
};
window.markAllRead = markAllRead;
window.markOneRead = markOneRead;
window.addNotif = addNotif;
window.deleteNotif = deleteNotif;
window.initNotifRealtime = initNotifRealtime;
window.requestPushPermission = requestPushPermission;
window.dismissPushBar = dismissPushBar;
window.checkPushPermission = checkPushPermission;
window.initFirebasePush = initFirebasePush;
window.loadScript = loadScript;
window.updateBadge = updateBadge;
window.clearNotifs = clearNotifs;
window.updateAvatarNotifBtn = updateAvatarNotifBtn;
window.toggleNotificationsFromMenu = toggleNotificationsFromMenu;
window.clearNotifs = clearNotifs;
