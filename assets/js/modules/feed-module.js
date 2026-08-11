let feedCatFilter = 'all';
let postCatSelected = 'general';
let feedRealtimeChannel = null;
let myLikedPosts = new Set(JSON.parse(localStorage.getItem('tnsvt_liked_posts') || '[]'));
let postPhotoData = null;
let signalPhotoData = null;
let commentPhotoData = {};

function filterFeed(cat, btn) {
  feedCatFilter = cat;
  document.querySelectorAll('.feed-cat').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderFeed();
}

function selPostCat(cat, btn) {
  postCatSelected = cat;
  document.querySelectorAll('.create-cat-opt').forEach(b => b.classList.remove('sel'));
  btn.classList.add('sel');
  const sf = document.getElementById('signalForm');
  if (sf) {
    if (cat === 'señales') {
      sf.classList.add('vis');
      requestAnimationFrame(() => {
        const rect = sf.getBoundingClientRect();
        const fullyVisible = rect.top >= 0 && rect.bottom <= window.innerHeight;
        if (!fullyVisible) {
          sf.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
    } else {
      sf.classList.remove('vis');
    }
  }
}

function selectSigAsset(btn) {
  document.querySelectorAll('.asset-chip-v36').forEach(c => c.classList.remove('selected'));
  btn.classList.add('selected');
  const hidden = document.getElementById('sig-asset');
  if (hidden) hidden.value = btn.getAttribute('data-asset');
  const custom = document.querySelector('.asset-input-v36');
  if (custom) custom.value = '';
}
window.selectSigAsset = selectSigAsset;

function onSigAssetCustom(input) {
  const val = (input.value || '').trim().toUpperCase();
  if (val) {
    document.querySelectorAll('.asset-chip-v36').forEach(c => c.classList.remove('selected'));
    const hidden = document.getElementById('sig-asset');
    if (hidden) hidden.value = val;
  }
}
window.onSigAssetCustom = onSigAssetCustom;

function selectSigDir(btn) {
  document.querySelectorAll('.dir-btn-v36').forEach(c => c.classList.remove('selected'));
  btn.classList.add('selected');
  const sel = document.getElementById('sig-dir');
  if (sel) sel.value = btn.getAttribute('data-dir');
  calcSigRR();
}
window.selectSigDir = selectSigDir;

function calcSigRR() {
  const dir = document.getElementById('sig-dir')?.value || 'BUY';
  const entry = parseFloat(document.getElementById('sig-entry')?.value);
  const sl = parseFloat(document.getElementById('sig-sl')?.value);
  const tp1 = parseFloat(document.getElementById('sig-tp1')?.value);
  const tp2 = parseFloat(document.getElementById('sig-tp2')?.value);
  const risk = isFinite(entry) && isFinite(sl) ? Math.abs(entry - sl) : null;
  const reward1 = isFinite(entry) && isFinite(tp1) ? Math.abs(tp1 - entry) : null;
  const reward2 = isFinite(entry) && isFinite(tp2) ? Math.abs(tp2 - entry) : null;
  const fmtPts = v => v == null ? '\u2014' : (v < 10 ? v.toFixed(4) : v.toFixed(2)) + ' pts';
  const fmtRR  = (rew) => (risk && rew && risk > 0) ? ('1 : ' + (rew/risk).toFixed(2)) : '\u2014';
  const setEl = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };
  setEl('sig-rr-risk', risk == null ? '\u2014' : fmtPts(risk));
  setEl('sig-rr-tp1', fmtRR(reward1));
  setEl('sig-rr-tp2', fmtRR(reward2));
}
window.calcSigRR = calcSigRR;

function toggleSignalForm() {
  const sf = document.getElementById('signalForm');
  if (!sf) return;
  sf.classList.toggle('vis');
  if (sf.classList.contains('vis')) {
    requestAnimationFrame(() => {
      const rect = sf.getBoundingClientRect();
      const fullyVisible = rect.top >= 0 && rect.bottom <= window.innerHeight;
      if (!fullyVisible) {
        sf.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  }
}
window.toggleSignalForm = toggleSignalForm;

function closeSignalForm() {
  const sf = document.getElementById('signalForm');
  if (!sf) return;
  sf.classList.remove('vis');
  const ha = document.getElementById('sig-asset');
  if (ha) ha.value = 'XAUUSD';
  document.querySelectorAll('.asset-chip-v36').forEach(c => c.classList.remove('selected'));
  document.querySelectorAll('.asset-chip-v36[data-asset="XAUUSD"]').forEach(c => c.classList.add('selected'));
  const cust = document.querySelector('.asset-input-v36');
  if (cust) cust.value = '';
  ['sig-entry','sig-sl','sig-tp1','sig-tp2'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const dir = document.getElementById('sig-dir');
  if (dir) dir.value = 'BUY';
  document.querySelectorAll('.dir-btn-v36').forEach(c => c.classList.remove('selected'));
  document.querySelectorAll('.dir-btn-v36.buy').forEach(c => c.classList.add('selected'));
  ['sig-rr-risk','sig-rr-tp1','sig-rr-tp2'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = '\u2014';
  });
  if (typeof window.removeSignalPhoto === 'function') window.removeSignalPhoto();
}
window.closeSignalForm = closeSignalForm;

function attachPostPhoto(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    postPhotoData = e.target.result;
    const prev = document.getElementById('postPhotoPreview');
    const img = document.getElementById('postPhotoImg');
    const badge = document.getElementById('postPhotoBadge');
    if (prev && img) { img.src = postPhotoData; prev.style.display = 'block'; }
    if (badge) badge.style.display = 'inline-block';
  };
  reader.readAsDataURL(input.files[0]);
}
window.attachPostPhoto = attachPostPhoto;

function removePostPhoto() {
  postPhotoData = null;
  const prev = document.getElementById('postPhotoPreview');
  const badge = document.getElementById('postPhotoBadge');
  if (prev) prev.style.display = 'none';
  if (badge) badge.style.display = 'none';
}
window.removePostPhoto = removePostPhoto;

function attachSignalPhoto(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    signalPhotoData = e.target.result;
    const prev = document.getElementById('sigPhotoPreview');
    const img = document.getElementById('sigPhotoImg');
    const badge = document.getElementById('sigPhotoBadge');
    if (prev && img) { img.src = signalPhotoData; prev.style.display = 'block'; }
    if (badge) badge.style.display = 'inline-block';
  };
  reader.readAsDataURL(input.files[0]);
}
window.attachSignalPhoto = attachSignalPhoto;

function removeSignalPhoto() {
  signalPhotoData = null;
  const prev = document.getElementById('sigPhotoPreview');
  const badge = document.getElementById('sigPhotoBadge');
  if (prev) prev.style.display = 'none';
  if (badge) badge.style.display = 'none';
}
window.removeSignalPhoto = removeSignalPhoto;

function attachCommentPhoto(input, postId) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    commentPhotoData[postId] = e.target.result;
    const prev = document.getElementById('comment-photo-preview-' + postId);
    if (prev) { prev.src = commentPhotoData[postId]; prev.style.display = 'block'; }
    input.value = '';
  };
  reader.readAsDataURL(input.files[0]);
}
window.attachCommentPhoto = attachCommentPhoto;

function removeCommentPhoto(postId) {
  commentPhotoData[postId] = null;
  const prev = document.getElementById('comment-photo-preview-' + postId);
  if (prev) { prev.src = ''; prev.style.display = 'none'; }
}
window.removeCommentPhoto = removeCommentPhoto;

async function createNewPost() {
  const text = document.getElementById('newPostText')?.value.trim();
  if (!text) return;
  if (!window.sb) { window.showToast('❌ Sin conexión'); return; }
  if (!window.TNSVT_USER) { window.showToast('⚠️ Iniciá sesión primero'); return; }

  const btn = document.querySelector('.post-creator .post-btn') || document.querySelector('.post-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Publicando...'; }

  const post = {
    author_code: window.TNSVT_USER.code,
    author_name: window.TNSVT_USER.name,
    cat: postCatSelected,
    text: text,
    signal: null,
    photo: postPhotoData || signalPhotoData || null
  };

  const sf = document.getElementById('signalForm');
  if (sf && sf.classList.contains('vis')) {
    const asset = document.getElementById('sig-asset')?.value.trim();
    if (asset) {
      post.signal = JSON.stringify({
        asset: asset.toUpperCase(),
        dir: document.getElementById('sig-dir')?.value,
        entry: document.getElementById('sig-entry')?.value,
        sl: document.getElementById('sig-sl')?.value,
        tp1: document.getElementById('sig-tp1')?.value,
        tp2: document.getElementById('sig-tp2')?.value,
        status: 'Abierta'
      });
      post.cat = 'señales';
      if (signalPhotoData) post.photo = signalPhotoData;
    }
    sf.classList.remove('vis');
    ['sig-asset','sig-entry','sig-sl','sig-tp1','sig-tp2'].forEach(id => {
      const el = document.getElementById(id); if (el) el.value = '';
    });
    window.removeSignalPhoto();
  }

  try {
    await window.sb.createPost(post);
    document.getElementById('newPostText').value = '';
    window.removePostPhoto();
    window.showToast('✅ Post publicado');
    await renderFeed();
  } catch(e) {
    console.error('Error publicando post:', e);
    window.showToast('❌ Error publicando: ' + (e.message || 'Sin conexión'));
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Publicar'; }
  }
}
window.createNewPost = createNewPost;

async function likeFeedPost(postId) {
  if (!window.sb) return;
  if (!window.TNSVT_USER) { window.showToast('⚠️ Iniciá sesión para dar like'); return; }

  const btn = document.querySelector(`[data-like-id="${postId}"]`);
  if (!btn) return;

  const action = myLikedPosts.has(postId) ? 'unlike' : 'like';

  try {
    const result = await window.sb.likePost(postId, window.TNSVT_USER.code, action);
    const countEl = btn.querySelector('.act-count');
    if (countEl) countEl.textContent = result.likes;

    if (action === 'like') {
      myLikedPosts.add(postId);
      btn.classList.add('liked');
    } else {
      myLikedPosts.delete(postId);
      btn.classList.remove('liked');
    }
    localStorage.setItem('tnsvt_liked_posts', JSON.stringify([...myLikedPosts]));
  } catch(e) {
    console.error('Error:', e);
    window.showToast('❌ Error al actualizar like');
  }
}
window.likeFeedPost = likeFeedPost;

async function toggleComments(postId) {
  const box = document.getElementById('comments-'+postId);
  if(box) box.classList.toggle('vis');
}
window.toggleComments = toggleComments;

async function submitComment(postId) {
  const input = document.getElementById('comment-input-'+postId);
  const photoPreview = document.getElementById('comment-photo-preview-'+postId);
  if(!input) return;
  const text = input.value.trim();
  const photo = commentPhotoData[postId] || null;
  if(!text && !photo) { window.showToast('⚠️ Escribí un comentario o adjuntá una foto.'); return; }
  if(!window.TNSVT_USER){ window.showToast('⚠️ Iniciá sesión primero'); return; }
  try {
    await window.sb.commentPost(postId, window.TNSVT_USER.name || 'Trader', text, photo);
    input.value = '';
    commentPhotoData[postId] = null;
    if (photoPreview) { photoPreview.src = ''; photoPreview.style.display = 'none'; }
    const listEl = document.getElementById('comment-list-'+postId);
    if(listEl){
      const div = document.createElement('div');
      div.className = 'comment-item';
      const safeText = sanitizePostText(text);
      const photoHtml = photo ? '<div class="comment-photo-wrap"><img class="comment-photo" src="'+photo+'" onclick="window.open(this.src)"></div>' : '';
      div.innerHTML = '<div class="comment-avatar">'+window.TNSVT_USER.name.charAt(0)+'</div><div class="comment-body"><div class="comment-text"><span class="comment-author">'+window.TNSVT_USER.name+': </span>'+safeText+'</div>'+photoHtml+'</div>';
      listEl.appendChild(div);
    }
    const box = document.getElementById('comments-'+postId);
    if(box){
      const btn = box.previousElementSibling?.querySelector?.('.signal-action:last-child .act-count');
      if(btn) btn.textContent = parseInt(btn.textContent||0)+1;
    }
    window.showToast('💬 Comentario enviado');
  } catch(e) {
    window.showToast('❌ Error al comentar: ' + (e.message||''));
  }
}
window.submitComment = submitComment;

function renderCommentsList(comments) {
  if(!comments || !comments.length) return '';
  return comments.map(function(c) {
    var author = window.escapeHtml(c.author || 'Trader');
    var initial = author.charAt(0).toUpperCase();
    var text = c.text || '';
    var photo = c.photo || '';
    var safeText = sanitizePostText(text);
    var photoHtml = photo ? '<div class="comment-photo-wrap"><img class="comment-photo" src="'+window.escapeHtml(photo)+'" onclick="window.open(this.src)"></div>' : '';
    return '<div class="comment-item">'
      + '<div class="comment-avatar">' + initial + '</div>'
      + '<div class="comment-body"><div class="comment-text"><span class="comment-author">' + author + ': </span>' + safeText + '</div>' + photoHtml + '</div>'
      + '</div>';
  }).join('');
}

function sanitizePostText(text) {
  if(!text) return '';
  return text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}
window.sanitizePostText = sanitizePostText;

function renderLinkPreviews(previews) {
  if (!previews || !previews.length) return '';
  return '<div class="lp-stack">' + previews.map(function(lp) {
    var href = window.escapeHtml(lp.url || '#');
    var domain = window.escapeHtml(lp.domain || '');
    var title = window.escapeHtml(lp.title || domain);
    var desc = window.escapeHtml((lp.description || '').substring(0, 160));
    var img = lp.image_external || '';
    var favicon = lp.favicon_external || '';
    var kind = lp.enriched && lp.enriched.kind || 'generic';
    var extraCls = 'lp-card-' + window.escapeHtml(kind);
    var hasImage = img ? ' lp-has-img' : '';
    var imageHtml = img ? '<div class="lp-thumb"><img src="' + window.escapeHtml(img) + '" alt="" loading="lazy"></div>' : '';
    var faviconHtml = favicon ? '<img src="' + window.escapeHtml(favicon) + '" alt="" class="lp-favicon" onerror="this.style.display=\'none\'">' : '<div class="lp-favicon lp-favicon-fallback">' + domain.charAt(0).toUpperCase() + '</div>';
    var tickerHtml = '';
    if (kind === 'tradingview' && lp.enriched && lp.enriched.ticker) {
      var ticker = window.escapeHtml(lp.enriched.ticker);
      tickerHtml = '<div class="lp-ticker-badge" onclick="window.open(\'' + window.escapeHtml(href) + '\',\'_blank\')">' + ticker + ' ↗</div>';
    }
    return '<div class="lp-card ' + extraCls + hasImage + '" onclick="window.open(\'' + window.escapeHtml(href) + '\',\'_blank\')">'
      + imageHtml
      + '<div class="lp-body">'
      + '<div class="lp-header">' + faviconHtml + '<span class="lp-domain">' + domain + '</span>' + tickerHtml + '</div>'
      + '<div class="lp-title">' + (title || domain) + '</div>'
      + (desc ? '<div class="lp-desc">' + desc + '</div>' : '')
      + '</div>'
      + '</div>';
  }).join('') + '</div>';
}
window.renderLinkPreviews = renderLinkPreviews;

async function renderFeed() {
  const container = document.getElementById('postsFeed');
  if (!container) return;
  if (!window.sb) {
    container.innerHTML = '<div style="text-align:center;color:#645a78;padding:40px;">⚠️ Sin conexión</div>';
    return;
  }
  const scrollY = window.scrollY;
  const feedTop = container.offsetTop;
  const isFirstLoad = !container.dataset.loaded;
  if (isFirstLoad) {
    container.innerHTML = '<div style="text-align:center;color:#645a78;padding:30px;font-size:0.8rem;">⏳ Cargando feed...</div>';
  } else {
    container.style.opacity = '0.55';
    container.style.transition = 'opacity 0.2s';
  }
  try {
    const posts = await window.sb.getFeed(feedCatFilter);
    if (!posts || !posts.length) {
      container.innerHTML = '<div class="feed-empty-state">No hay posts aún. ¡Sé el primero!</div>';
      return;
    }
    container.style.opacity = '';
    container.dataset.loaded = '1';
    container.innerHTML = posts.map(p => {
      const d = new Date(p.created_at);
      const timeAgo = Math.floor((Date.now() - d.getTime()) / 3600000);
      const timeStr = timeAgo < 1 ? 'hace momentos' : (timeAgo < 24 ? 'hace ' + timeAgo + 'h' : 'hace ' + Math.floor(timeAgo / 24) + 'd');
      const catCls = 'signal-cat-' + window.escapeHtml(p.cat || 'general');
      const catLabel = (p.cat || 'general').charAt(0).toUpperCase() + (p.cat || 'general').slice(1);
      const rawAuthorName = p.author_name || p.author || 'Trader';
      const authorName = window.escapeHtml(rawAuthorName);
      const initial = rawAuthorName.charAt(0).toUpperCase();
      const isMyPost = window.TNSVT_USER && p.author_code === window.TNSVT_USER.code;
      const iLiked = myLikedPosts.has(p.id);

      const safeId = String(p.id).replace(/[^0-9]/g, '');

      let photoHtml = '';
      if (p.photo) {
        const safePhoto = window.escapeHtml(p.photo);
        photoHtml = `<div style="margin:10px 0;border-radius:8px;overflow:hidden;cursor:pointer;" onclick="this.querySelector('img').requestFullscreen?.()">
          <img src="${safePhoto}" style="width:100%;max-height:280px;object-fit:cover;border-radius:8px;border:1px solid rgba(212,175,55,0.15);">
        </div>`;
      }
      let signalHtml = '';
      if (p.signal) {
        const s = typeof p.signal === 'string' ? JSON.parse(p.signal) : p.signal;
        const dirCls = s.dir === 'BUY' ? 'signal-buy' : 'signal-sell';
        const safeDir = window.escapeHtml(s.dir || '');
        const safeAsset = window.escapeHtml(s.asset || '');
        const safeStatus = window.escapeHtml(s.status || 'Abierta');
        const safeEntry = window.escapeHtml(s.entry || '—');
        const safeSl = window.escapeHtml(s.sl || '—');
        const safeTp1 = window.escapeHtml(s.tp1 || '—');
        const safeTp2 = window.escapeHtml(s.tp2 || '');
        const tp2Row = safeTp2 ? `<div class="signal-lvl"><div class="signal-lvl-label">TP2</div><div class="signal-lvl-val lvl-tp">${safeTp2}</div></div>` : '';
        signalHtml = `
          <div class="signal-trade">
            <div class="signal-trade-hdr">
              <span class="signal-dir ${dirCls}">${safeDir}</span>
              <span class="signal-asset">${safeAsset}</span>
              <span class="signal-status">• ${safeStatus}</span>
              ${isMyPost ? `<button onclick="deletePost('${safeId}')" style="margin-left:auto;background:rgba(255,59,48,0.1);border:1px solid rgba(255,59,48,0.3);border-radius:4px;color:#ff3b30;font-size:0.6rem;padding:2px 7px;cursor:pointer;">Eliminar</button>` : ''}
            </div>
            <div class="signal-levels">
              <div class="signal-lvl"><div class="signal-lvl-label">Entry</div><div class="signal-lvl-val lvl-entry">${safeEntry}</div></div>
              <div class="signal-lvl"><div class="signal-lvl-label">Stop</div><div class="signal-lvl-val lvl-sl">${safeSl}</div></div>
              <div class="signal-lvl"><div class="signal-lvl-label">TP1</div><div class="signal-lvl-val lvl-tp">${safeTp1}</div></div>
              ${tp2Row}
            </div>
          </div>`;
      }

      const deleteBtn = (!p.signal && isMyPost) ? `<button onclick="deletePost('${safeId}')" style="margin-left:auto;background:rgba(255,59,48,0.1);border:1px solid rgba(255,59,48,0.3);border-radius:4px;color:#ff3b30;font-size:0.6rem;padding:2px 7px;cursor:pointer;">✕</button>` : '';

      return `
        <div class="signal-card-wrap" id="post-${safeId}">
          <div class="signal-hdr">
            <div class="signal-user">
              <div class="signal-avatar" style="background:${isMyPost ? 'var(--gold)' : 'var(--violet)'};color:${isMyPost ? '#000' : '#fff'}">${initial}</div>
              <div>
                <div class="signal-name">${authorName}${isMyPost ? ' <span style="font-size:0.55rem;color:var(--gold);opacity:0.8;">TÚ</span>' : ''}</div>
                <div class="signal-time">${timeStr}</div>
              </div>
            </div>
            <span class="signal-cat-badge ${catCls}">${catLabel}</span>
            ${deleteBtn}
          </div>
          <div class="signal-body">${sanitizePostText(p.text)}</div>
          ${photoHtml}${signalHtml}${renderLinkPreviews(p.link_previews)}
          <div class="signal-actions">
            <div class="signal-action ${iLiked ? 'liked' : ''}" data-like-id="${safeId}" onclick="likeFeedPost('${safeId}')" style="${iLiked ? 'color:var(--gold-bright);' : ''}">
              ${iLiked ? '♥' : '♡'} <span class="act-count">${p.likes || 0}</span>
            </div>
            <div class="signal-action" onclick="toggleComments('${safeId}')" style="cursor:pointer;">💬 <span class="act-count">${(p.comments||[]).length||0}</span></div>
          </div>
          <div class="comment-box" id="comments-${safeId}">
            <div class="comment-list" id="comment-list-${safeId}">${renderCommentsList(p.comments)}</div>
            <div class="comment-photo-preview-row">
              <img id="comment-photo-preview-${safeId}" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" style="display:none;max-height:80px;border-radius:6px;margin-bottom:4px;cursor:pointer;" onclick="removeCommentPhoto('${safeId}')" title="Click para quitar">
            </div>
            <div class="comment-input-row">
              <button class="comment-photo-btn" onclick="document.getElementById('comment-photo-input-${safeId}').click()" title="Adjuntar foto">📷</button>
              <input type="file" id="comment-photo-input-${safeId}" accept="image/*" style="display:none" onchange="attachCommentPhoto(this, '${safeId}')">
              <input type="text" id="comment-input-${safeId}" placeholder="Escribí un comentario..." onkeydown="if(event.key==='Enter')submitComment('${safeId}')">
              <button class="comment-submit" onclick="submitComment('${safeId}')">Enviar</button>
            </div>
          </div>
        </div>`;
    }).join('');
  } catch(e) {
    console.error('Error cargando feed:', e);
    container.innerHTML = `<div style="text-align:center;color:#ff3b30;padding:30px;font-size:0.82rem;">❌ Error cargando el feed.<br><button onclick="renderFeed()" style="margin-top:10px;padding:6px 16px;background:rgba(138,60,255,0.2);border:1px solid var(--violet);border-radius:6px;color:#fff;cursor:pointer;">Reintentar</button></div>`;
  } finally {
    if (!isFirstLoad && scrollY > feedTop) {
      requestAnimationFrame(() => window.scrollTo({ top: scrollY, behavior: 'instant' }));
    }
  }
}
window.renderFeed = renderFeed;

async function deletePost(postId) {
  if (!window.sb || !window.TNSVT_USER) return;
  if (!confirm('¿Eliminar este post?')) return;
  try {
    await window.sb.deletePost(postId, window.TNSVT_USER.code);
    document.getElementById('post-' + postId)?.remove();
    window.showToast('🗑️ Post eliminado');
  } catch(e) { window.showToast('❌ Error eliminando'); }
}
window.deletePost = deletePost;

function initFeedRealtime() {
  // Realtime eliminado — usar refresh manual

}
window.initFeedRealtime = initFeedRealtime;

// Exportar funciones globales para onclick handlers
window.filterFeed = filterFeed;
window.selPostCat = selPostCat;
window.renderFeed = renderFeed;
window.renderLinkPreviews = renderLinkPreviews;
window.initFeedRealtime = initFeedRealtime;
window.createNewPost = createNewPost;
window.likeFeedPost = likeFeedPost;
window.deletePost = deletePost;
// ⛧ Fix logout 2026-07-27: helper de reset para limpiar postPhotoData/signalPhotoData
// (module-scope post A.2). Llamado desde app.js#logout() via guard typeof.
window.feedPhotoReset = function() {
  try {
    postPhotoData = null;
    signalPhotoData = null;
    commentPhotoData = {};
  } catch (_) { /* noop */ }
};
