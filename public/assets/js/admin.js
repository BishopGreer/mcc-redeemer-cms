// OurSaintFrancis CMS — Admin JS

// Confirm delete forms globally
document.addEventListener('DOMContentLoaded', function() {
  // Mobile sidebar toggle
  const sidebar = document.getElementById('sidebar');
  const toggle  = document.createElement('button');
  toggle.textContent = '☰';
  toggle.style.cssText = 'position:fixed;top:12px;left:12px;z-index:200;background:var(--brown-dark);color:#fff;border:none;border-radius:4px;padding:6px 10px;font-size:18px;cursor:pointer;display:none;';
  toggle.id = 'sidebar-toggle';
  document.body.appendChild(toggle);

  function checkMobile() {
    toggle.style.display = window.innerWidth <= 860 ? 'block' : 'none';
  }

  toggle.addEventListener('click', function() {
    sidebar && sidebar.classList.toggle('open');
  });

  checkMobile();
  window.addEventListener('resize', checkMobile);
});

// Media picker modal (shared by page-edit and post-edit)
function openMediaPicker(fieldId, previewId) {
  window._mpField   = fieldId;
  window._mpPreview = previewId;
  window._mpTinyCb  = null;
  _openMpModal();
}

// Jodit integration — opens the media library and inserts the chosen image
// into the given Jodit editor instance.
function openMediaPickerForJodit(editor) {
  window._mpField   = null;
  window._mpPreview = null;
  window._mpTinyCb  = function(url, info) {
    var alt = (info && info.alt) ? info.alt : '';
    editor.focus();
    editor.selection.insertHTML('<img src="' + url + '" alt="' + alt + '" style="max-width:100%;">');
  };
  _openMpModal();
}

function _openMpModal() {
  let modal = document.getElementById('media-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'media-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;display:flex;';
    modal.innerHTML = `
      <div style="background:#fff;border-radius:8px;width:860px;max-width:95vw;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #e8d9c4;">
          <strong>Media Library</strong>
          <button onclick="document.getElementById('media-modal').style.display='none'" style="background:none;border:none;font-size:22px;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:10px 16px;border-bottom:1px solid #e8d9c4;display:flex;gap:10px;align-items:center;">
          <label class="btn btn-primary btn-sm" style="cursor:pointer;">
            Upload New
            <input type="file" accept="image/*,application/pdf" style="display:none;" onchange="mpUpload(this)">
          </label>
          <input type="text" placeholder="Search…" class="form-control" style="width:180px;" oninput="mpFilter(this.value)">
        </div>
        <div style="flex:1;overflow-y:auto;padding:16px;">
          <div class="media-grid" id="mp-items">Loading…</div>
        </div>
        <div style="padding:12px 18px;border-top:1px solid #e8d9c4;text-align:right;">
          <button class="btn btn-primary" id="mp-insert-btn" disabled onclick="mpInsert()">Insert Selected</button>
        </div>
      </div>`;
    document.body.appendChild(modal);
    mpLoad();
  } else {
    modal.style.display = 'flex';
  }
  window._mpSelected = null;
  document.getElementById('mp-insert-btn').disabled = true;
}

let _mpAll = [];

function mpLoad() {
  fetch('/api/media?action=list', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => mpRender(data));
}

function mpRender(items) {
  _mpAll = items;
  const grid = document.getElementById('mp-items');
  if (!grid) return;
  grid.innerHTML = items.map(m => {
    const isImg = m.mime_type && m.mime_type.startsWith('image/');
    const inner = isImg
      ? `<img src="${m.thumb_url || m.url}" style="width:100%;height:100%;object-fit:cover;" loading="lazy">`
      : `<div class="media-icon">&#128196;</div>`;
    return `<div class="media-thumb" data-id="${m.id}" data-url="${m.url}" data-alt="${m.alt_text||''}" onclick="mpSelect(this)">
      ${inner}
      <div class="media-name">${m.original_name}</div>
    </div>`;
  }).join('') || '<p style="color:#aaa;font-family:sans-serif;">No media yet. Upload something!</p>';
}

function mpFilter(q) {
  mpRender(_mpAll.filter(m => m.original_name.toLowerCase().includes(q.toLowerCase())));
}

function mpSelect(el) {
  document.querySelectorAll('#mp-items .media-thumb.selected').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
  window._mpSelected = { id: el.dataset.id, url: el.dataset.url, alt: el.dataset.alt };
  const btn = document.getElementById('mp-insert-btn');
  if (btn) btn.disabled = false;
}

function mpInsert() {
  if (!window._mpSelected) return;
  const m = window._mpSelected;
  if (window._mpTinyCb) {
    window._mpTinyCb(m.url, { alt: m.alt });
  } else if (window._mpField) {
    const field = document.getElementById(window._mpField);
    if (field) field.value = m.id;
    const prev = document.getElementById(window._mpPreview);
    if (prev) prev.innerHTML = `<img src="${m.url}" style="width:100%;border-radius:4px;margin-bottom:8px;">`;
  }
  const modal = document.getElementById('media-modal');
  if (modal) modal.style.display = 'none';
}

function mpUpload(input) {
  const file = input.files[0];
  if (!file) return;
  const csrf = document.querySelector('input[name="_csrf"]');
  const fd = new FormData();
  fd.append('file', file);
  if (csrf) fd.append('_csrf', csrf.value);
  fetch('/api/media', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(r => r.json())
    .then(() => mpLoad())
    .catch(() => alert('Upload failed.'));
}
