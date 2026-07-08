<?php
/**
 * Boutique publique — commandes.meyrinfc.ch/shop
 * Page volontairement indépendante de index.php/guard.php : accessible sans compte ni SSO ERP.
 * Consomme uniquement les 3 actions publiques de api.php (shop_catalog, shop_request, shop_cancel).
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Boutique — Meyrin FC</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
<script>
tailwind.config = {
  theme: { extend: {
    fontFamily: { sans:['Inter','system-ui','sans-serif'], display:['Sora','Inter','sans-serif'] },
    colors: {
      ink:{DEFAULT:'#15140F', soft:'#6E6C61'}, mute:'#9A988C', line:'#E9E6DC', canvas:'#F6F4ED',
      brand:{50:'#FFF9D9',100:'#FFF3C4',500:'#ffdd00',600:'#c5a900',700:'#a88d00',800:'#8a7300'},
      rose2:{50:'#FEF1F2',600:'#DC2626'}, green2:{50:'#E4F4EA',600:'#1F9D5B'},
    },
    boxShadow: { card:'0 1px 2px rgba(12,18,32,.04), 0 1px 3px rgba(12,18,32,.06)', pop:'0 4px 6px -1px rgba(12,18,32,.07), 0 12px 24px -8px rgba(12,18,32,.12)' },
    borderRadius:{ xl2:'14px' },
  }}
}
</script>
<style>
  html { -webkit-font-smoothing: antialiased; background:#F6F4ED; }
  .tnum { font-variant-numeric: tabular-nums; }
  .field { width:100%; height:38px; padding:0 12px; border:1px solid #E9E6DC; border-radius:10px; background:#fff;
           font-size:13px; outline:none; transition:border .15s, box-shadow .15s; }
  .field:focus { border-color:#ffdd00; box-shadow:0 0 0 4px rgba(255,221,0,.25); }
  .lbl { font-size:11.5px; font-weight:600; color:#6E6C61; display:block; margin-bottom:4px; }
  .modal-bg { animation:fadeIn .2s both; }
  .modal-card { animation:modalIn .25s cubic-bezier(.21,.61,.35,1) both; }
  @keyframes fadeIn { from{opacity:0;} to{opacity:1;} }
  @keyframes modalIn { from{opacity:0; transform:translateY(14px) scale(.98);} to{opacity:1; transform:none;} }
  .toast { animation:toastIn .3s cubic-bezier(.21,.61,.35,1) both; }
  @keyframes toastIn { from{opacity:0; transform:translateY(12px) scale(.97);} to{opacity:1; transform:none;} }
</style>
</head>
<body class="min-h-screen text-ink font-sans">

<header class="bg-white border-b border-line">
  <div class="max-w-6xl mx-auto px-4 lg:px-6 py-4 flex items-center justify-between">
    <div>
      <h1 class="font-display text-[19px] font-bold tracking-tight">Boutique — Meyrin FC</h1>
      <p class="text-mute text-[12.5px] mt-0.5">Demande d'articles club — aucun compte requis</p>
    </div>
    <button onclick="openMyRequests()" class="h-9 px-3.5 rounded-lg border border-line bg-white text-[12.5px] font-medium hover:bg-canvas transition">Mes demandes</button>
  </div>
</header>

<main class="max-w-6xl mx-auto px-4 lg:px-6 py-6">
  <div class="grid lg:grid-cols-3 gap-3.5">
    <div class="lg:col-span-2 bg-white border border-line rounded-xl2 shadow-card p-4">
      <input id="shopSearch" type="text" placeholder="Rechercher un article…" class="field mb-3" oninput="renderShopGrid()">
      <div id="shopGrid" class="grid sm:grid-cols-2 gap-3"></div>
    </div>
    <div class="bg-white border border-line rounded-xl2 shadow-card p-4 h-fit lg:sticky lg:top-4">
      <h2 class="font-semibold text-[14.5px] mb-2">Mon panier</h2>
      <p class="text-mute text-[12px] mb-2" id="cartEmpty">Aucun article ajouté</p>
      <div id="cartLines" class="space-y-2 mb-3 max-h-[280px] overflow-y-auto"></div>

      <div class="border-t border-line pt-2.5 pb-1">
        <div class="flex gap-1.5">
          <input id="promoInput" type="text" placeholder="Code promo" class="field !h-8 uppercase" oninput="this.value=this.value.toUpperCase()">
          <button onclick="applyPromo()" class="h-8 px-3 rounded-lg border border-line bg-white text-[11.5px] font-semibold hover:bg-canvas transition shrink-0">Appliquer</button>
        </div>
        <p id="promoMsg" class="text-[11.5px] mt-1.5"></p>
      </div>

      <div class="text-[13px] border-t border-line pt-2 mb-3 space-y-0.5">
        <div class="flex items-center justify-between text-mute" id="promoSubtotalRow" style="display:none;">
          <span>Sous-total</span><span id="cartSubtotal" class="tnum">0.00 CHF</span>
        </div>
        <div class="flex items-center justify-between text-green2-600" id="promoDiscountRow" style="display:none;">
          <span id="promoDiscountLabel">Rabais</span><span id="cartDiscount" class="tnum">-0.00 CHF</span>
        </div>
        <div class="flex items-center justify-between font-semibold">
          <span>Total</span><span id="cartTotal" class="tnum">0.00 CHF</span>
        </div>
      </div>

      <div class="border-t border-line pt-3 space-y-2.5">
        <p class="text-[11.5px] font-semibold text-ink-soft">Tes coordonnées</p>
        <div><label class="lbl">Prénom *</label><input id="gFirst" class="field" autocomplete="given-name"></div>
        <div><label class="lbl">Nom *</label><input id="gLast" class="field" autocomplete="family-name"></div>
        <div><label class="lbl">E-mail *</label><input id="gEmail" type="email" class="field" autocomplete="email"></div>
        <div><label class="lbl">Téléphone *</label><input id="gPhone" type="tel" class="field" autocomplete="tel"></div>
        <div><label class="lbl">Motif (optionnel)</label><textarea id="gMotive" class="field" rows="2" style="height:auto; padding-top:8px;"></textarea></div>
      </div>

      <button onclick="submitShopRequest()" id="cartSubmitBtn" disabled
              class="w-full h-9 mt-3 rounded-lg bg-brand-600 text-[#15140F] text-[12.5px] font-semibold hover:bg-brand-700 shadow-card transition disabled:opacity-40 disabled:cursor-not-allowed">Envoyer la demande</button>
    </div>
  </div>
</main>

<div id="modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4 z-50 modal-bg">
  <div class="bg-white rounded-xl2 shadow-pop max-w-md w-full p-5 modal-card">
    <div class="flex items-center justify-between mb-3">
      <h3 id="modalTitle" class="font-display font-bold text-[16px]"></h3>
      <button onclick="closeModal()" class="text-mute hover:text-ink">✕</button>
    </div>
    <div id="modalBody"></div>
    <div class="flex justify-end gap-2 mt-4">
      <button onclick="closeModal()" class="h-9 px-3.5 rounded-lg border border-line bg-white text-[12.5px] font-medium hover:bg-canvas transition">Annuler</button>
      <button id="modalSubmit" class="h-9 px-3.5 rounded-lg bg-brand-600 text-[#15140F] text-[12.5px] font-semibold hover:bg-brand-700 shadow-card transition"></button>
    </div>
  </div>
</div>

<div id="toastHost" class="fixed bottom-4 right-4 z-[60] space-y-2"></div>

<script>
const API = '../api.php';
const LS_KEY = 'mfc_shop_requests';
let S = { articles: [], cart: [], promo: null };

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const chf = v => new Intl.NumberFormat('fr-CH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(+v || 0).replace(/ /g, "'");
const dateCH = d => d ? String(d).slice(0,10).split('-').reverse().join('.') : '—';

async function api(action, method = 'GET', body = null) {
  const opt = { method, headers: {} };
  if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const url = API + '?action=' + action;
  const r = await fetch(url, method === 'GET' ? {} : opt);
  let data = {};
  try { data = await r.json(); } catch (e) {}
  if (!r.ok) throw new Error(data.error || ('Erreur serveur (HTTP ' + r.status + ')'));
  return data;
}
function toast(msg, isError) {
  const el = document.createElement('div');
  el.className = 'toast px-4 py-2.5 rounded-lg shadow-pop text-[12.5px] font-medium ' + (isError ? 'bg-rose2-600 text-white' : 'bg-ink text-white');
  el.textContent = msg;
  document.getElementById('toastHost').appendChild(el);
  setTimeout(() => el.remove(), 3200);
}
function openModal(title, bodyHtml, onSubmit) {
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalBody').innerHTML = bodyHtml;
  const btn = document.getElementById('modalSubmit');
  btn.onclick = async () => { try { await onSubmit(); } catch (e) { toast(e.message, true); } };
  document.getElementById('modal').classList.remove('hidden');
  document.getElementById('modal').classList.add('flex');
}
function closeModal() { document.getElementById('modal').classList.add('hidden'); document.getElementById('modal').classList.remove('flex'); }

/* ---- Catalogue ---- */
async function loadCatalog() {
  S.articles = await api('shop_catalog');
  renderShopGrid();
}
function renderShopGrid() {
  const q = (document.getElementById('shopSearch').value || '').toLowerCase();
  const arts = S.articles.filter(a => !q || a.name.toLowerCase().includes(q) || (a.article_number||'').toLowerCase().includes(q));
  document.getElementById('shopGrid').innerHTML = arts.map(a => `
    <div class="border border-line rounded-xl p-3 flex gap-3">
      ${a.photo ? `<img src="../uploads/${a.photo}" class="w-16 h-16 rounded-lg object-cover border border-line shrink-0">` : `<div class="w-16 h-16 rounded-lg bg-canvas border border-line grid place-items-center text-mute text-[10px] shrink-0">—</div>`}
      <div class="flex-1 min-w-0 flex flex-col">
        <p class="font-medium text-[13px] truncate" title="${esc(a.name)}">${esc(a.name)}</p>
        <p class="text-[12px] text-mute mb-1.5">${chf(a.sale_price_ttc)} CHF</p>
        <button onclick="openPicker(${a.id})" class="h-8 px-2.5 rounded-lg bg-brand-600 text-[#15140F] text-[11.5px] font-semibold hover:bg-brand-700 mt-auto">Choisir</button>
      </div>
    </div>`).join('') || '<p class="text-mute text-[12.5px] py-8 col-span-2 text-center">Aucun article disponible</p>';
}

/* ---- Sélecteur de variante (attribut principal + secondaire, ex. Couleur / Taille) ---- */
let pickerState = null;
function attrName(a, primary) {
  for (const v of (a.variants || [])) { const x = (v.attributes || []).find(y => !!y.is_primary === primary); if (x) return x.attribute_name; }
  return '';
}
function sortAttrValueEntries(entries) {
  const byValue = new Map();
  entries.forEach(x => { if (!byValue.has(x.value)) byValue.set(x.value, x.sort_order || 0); });
  return [...byValue.entries()].sort((a, b) => a[1] - b[1] || a[0].localeCompare(b[0])).map(([v]) => v);
}
function primaryValues(a) { return sortAttrValueEntries((a.variants || []).flatMap(v => (v.attributes || []).filter(x => x.is_primary))); }
function secondaryValues(a, pv) {
  return sortAttrValueEntries((a.variants || [])
    .filter(v => (v.attributes || []).some(x => x.is_primary && x.value === pv))
    .flatMap(v => (v.attributes || []).filter(x => !x.is_primary)));
}
function findVariant() {
  const { article: a, primaryValue, secondaryValue, hasPrimary, hasSecondary } = pickerState;
  return (a.variants || []).find(v => {
    const attrs = v.attributes || [];
    if (hasPrimary) { const p = attrs.find(x => x.is_primary); if (!p || p.value !== primaryValue) return false; }
    if (hasSecondary) { const s = attrs.find(x => !x.is_primary); if (!s || s.value !== secondaryValue) return false; }
    return true;
  });
}
function openPicker(articleId) {
  const a = S.articles.find(x => x.id === articleId);
  if (!a) return;
  const variants = a.variants || [];
  const hasPrimary = variants.some(v => (v.attributes || []).some(x => x.is_primary));
  const hasSecondary = variants.some(v => (v.attributes || []).some(x => !x.is_primary));
  pickerState = { article: a, primaryValue: null, secondaryValue: null, qty: 1, hasPrimary, hasSecondary };
  if (hasPrimary) { const values = primaryValues(a); if (values.length === 1) pickerState.primaryValue = values[0]; }
  openModal(a.name, pickerBody(), submitPicker);
  document.getElementById('modalSubmit').textContent = 'Ajouter au panier';
}
function pickerBody() {
  const { article: a, primaryValue, secondaryValue, hasPrimary, hasSecondary, qty } = pickerState;
  let html = '';
  if (hasPrimary) {
    const values = primaryValues(a);
    html += `<div class="mb-3"><label class="lbl">${esc(attrName(a, true) || 'Couleur')}</label><div class="flex flex-wrap gap-2 mt-1">${values.map(v => {
      const cp = (a.color_photos || []).find(p => p.color_value === v);
      const variantWithAttr = (a.variants || []).find(x => (x.attributes || []).some(y => y.is_primary && y.value === v));
      const attr = variantWithAttr ? (variantWithAttr.attributes || []).find(y => y.is_primary && y.value === v) : null;
      const code = attr && attr.color_code ? attr.color_code : '';
      const active = primaryValue === v;
      const ring = active ? 'border-brand-600 border-2' : 'border-line';
      let visual;
      if (cp) visual = `<img src="../uploads/${cp.photo}" class="w-9 h-9 rounded-lg object-cover border ${ring}">`;
      else if (code) visual = `<span class="w-9 h-9 rounded-lg border ${ring} block" style="background:${esc(code)}"></span>`;
      else visual = `<span class="w-9 h-9 rounded-lg border ${ring} bg-canvas grid place-items-center text-[9px] text-mute">${esc(v).slice(0,3)}</span>`;
      return `<button type="button" onclick="pickPrimary('${esc(v).replace(/'/g,"\\'")}')" class="flex flex-col items-center gap-1" title="${esc(v)}">${visual}<span class="text-[10.5px] ${active ? 'font-semibold text-brand-700' : 'text-mute'}">${esc(v)}</span></button>`;
    }).join('')}</div></div>`;
  }
  if (hasSecondary) {
    if (hasPrimary && !primaryValue) {
      html += `<p class="text-mute text-[12px] mb-3">Choisis d'abord ${esc(attrName(a, true) || 'un attribut')}</p>`;
    } else {
      const values = secondaryValues(a, primaryValue);
      html += `<div class="mb-3"><label class="lbl">${esc(attrName(a, false) || 'Taille')}</label>
        <select class="field" onchange="pickSecondary(this.value)">
          <option value="">Choisir…</option>
          ${values.map(v => `<option value="${esc(v)}" ${secondaryValue === v ? 'selected' : ''}>${esc(v)}</option>`).join('')}
        </select></div>`;
    }
  }
  const variant = findVariant();
  html += `<p class="text-[13px] font-semibold mb-3">Prix : ${chf(a.sale_price_ttc)} CHF${variant ? ` <span class="text-mute font-normal">— stock dispo : ${variant.total_stock}</span>` : ''}</p>`;
  html += `<div><label class="lbl">Quantité</label><input type="number" min="1" value="${qty}" id="pickerQty" class="field w-24" onchange="pickerState.qty=(+this.value||1)"></div>`;
  return html;
}
function pickPrimary(v) { pickerState.primaryValue = v; pickerState.secondaryValue = null; document.getElementById('modalBody').innerHTML = pickerBody(); }
function pickSecondary(v) { pickerState.secondaryValue = v; document.getElementById('modalBody').innerHTML = pickerBody(); }
function variantLabel(v) { const attrs = (v.attributes || []).map(x => x.value).join(' / '); return attrs || (v.label && v.label !== 'Standard' ? v.label : 'Standard'); }
async function submitPicker() {
  const qtyInput = document.getElementById('pickerQty');
  const qty = qtyInput ? (+qtyInput.value || 1) : (pickerState.qty || 1);
  if (qty <= 0) throw new Error('Quantité invalide');
  if (pickerState.hasPrimary && !pickerState.primaryValue) throw new Error('Choisis ' + (attrName(pickerState.article, true) || 'un attribut'));
  if (pickerState.hasSecondary && !pickerState.secondaryValue) throw new Error('Choisis ' + (attrName(pickerState.article, false) || 'un attribut'));
  const variant = findVariant();
  if (!variant) throw new Error("Cette combinaison n'existe pas pour cet article");
  const a = pickerState.article;
  const existing = S.cart.find(l => l.variant_id === variant.id);
  if (existing) existing.quantity += qty;
  else S.cart.push({ variant_id: variant.id, quantity: qty, article_name: a.name, variant_label: variantLabel(variant), sale_price_ttc: a.sale_price_ttc });
  renderCart();
  closeModal();
  toast('Ajouté au panier');
}

/* ---- Panier ---- */
function removeFromCart(idx) { S.cart.splice(idx, 1); renderCart(); }
function updateCartQty(idx, val) { const q = +val; if (q > 0) S.cart[idx].quantity = q; renderCart(); }
function renderCart() {
  document.getElementById('cartEmpty').classList.toggle('hidden', S.cart.length > 0);
  document.getElementById('cartLines').innerHTML = S.cart.map((l, idx) => {
    const sub = l.quantity * (+l.sale_price_ttc || 0);
    return `<div class="flex items-center gap-2 text-[12px]">
      <div class="flex-1 min-w-0"><p class="font-medium truncate">${esc(l.article_name)}</p><p class="text-mute truncate">${esc(l.variant_label)} · ${chf(l.sale_price_ttc || 0)} CHF</p></div>
      <input type="number" min="1" value="${l.quantity}" class="field !h-7 w-14 text-[11.5px]" onchange="updateCartQty(${idx}, this.value)">
      <span class="tnum w-16 text-right shrink-0 font-semibold">${chf(sub)}</span>
      <button onclick="removeFromCart(${idx})" class="text-mute hover:text-rose2-600 shrink-0">✕</button>
    </div>`;
  }).join('');
  const subtotal = S.cart.reduce((s, l) => s + l.quantity * (+l.sale_price_ttc || 0), 0);
  const discountPct = S.promo ? S.promo.discount_percent : 0;
  const discountAmt = subtotal * discountPct / 100;
  const total = subtotal - discountAmt;
  document.getElementById('promoSubtotalRow').style.display = discountPct > 0 ? 'flex' : 'none';
  document.getElementById('promoDiscountRow').style.display = discountPct > 0 ? 'flex' : 'none';
  document.getElementById('cartSubtotal').textContent = chf(subtotal) + ' CHF';
  document.getElementById('promoDiscountLabel').textContent = `Rabais ${S.promo ? S.promo.code : ''} (-${discountPct}%)`;
  document.getElementById('cartDiscount').textContent = '-' + chf(discountAmt) + ' CHF';
  document.getElementById('cartTotal').textContent = chf(total) + ' CHF';
  refreshSubmitState();
}
function refreshSubmitState() {
  document.getElementById('cartSubmitBtn').disabled = S.cart.length === 0;
}
async function applyPromo() {
  const code = document.getElementById('promoInput').value.trim();
  const msg = document.getElementById('promoMsg');
  if (!code) return;
  try {
    const r = await api('shop_validate_promo&code=' + encodeURIComponent(code));
    S.promo = { code: r.code, discount_percent: r.discount_percent };
    msg.textContent = `Code ${r.code} appliqué : -${r.discount_percent}%`;
    msg.className = 'text-[11.5px] mt-1.5 text-green2-600 font-medium';
    renderCart();
  } catch (e) {
    S.promo = null;
    msg.textContent = e.message;
    msg.className = 'text-[11.5px] mt-1.5 text-rose2-600 font-medium';
    renderCart();
  }
}

/* ---- Soumission + suivi local des demandes (localStorage, pas de compte) ---- */
function loadLocalRequests() { try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]'); } catch (e) { return []; } }
function saveLocalRequest(entry) {
  const list = loadLocalRequests(); list.unshift(entry);
  localStorage.setItem(LS_KEY, JSON.stringify(list.slice(0, 20)));
}
async function submitShopRequest() {
  if (!S.cart.length) return;
  const firstName = document.getElementById('gFirst').value.trim();
  const lastName = document.getElementById('gLast').value.trim();
  const email = document.getElementById('gEmail').value.trim();
  const phone = document.getElementById('gPhone').value.trim();
  if (!firstName || !lastName) return toast('Nom et prénom requis', true);
  if (!email) return toast('E-mail requis', true);
  if (!phone) return toast('Téléphone requis', true);
  try {
    const subtotal = S.cart.reduce((s,l)=>s+l.quantity*(+l.sale_price_ttc||0),0);
    const discountPct = S.promo ? S.promo.discount_percent : 0;
    const r = await api('shop_request', 'POST', {
      first_name: firstName, last_name: lastName, email, phone,
      items: S.cart.map(l => ({ variant_id: l.variant_id, quantity: l.quantity })),
      motive: document.getElementById('gMotive').value,
      promo_code: S.promo ? S.promo.code : '',
    });
    saveLocalRequest({ cart_id: r.cart_id, token: r.cancel_token, date: new Date().toISOString(), name: firstName + ' ' + lastName, total: subtotal * (1 - discountPct / 100) });
    S.cart = [];
    S.promo = null;
    document.getElementById('promoInput').value = '';
    document.getElementById('promoMsg').textContent = '';
    renderCart();
    showConfirmation(r.cart_id);
  } catch (e) { toast(e.message, true); }
}
function showConfirmation(cartId) {
  openModal('Demande envoyée', `
    <p class="text-[13px] mb-2">Ta demande <b>DEM-${String(cartId).padStart(6,'0')}</b> a bien été transmise au club.</p>
    <p class="text-mute text-[12.5px]">Tu peux la retrouver et l'annuler tant qu'elle n'a pas été traitée depuis le bouton <b>Mes demandes</b> en haut de page (sur cet appareil).</p>
  `, closeModal);
  document.getElementById('modalSubmit').textContent = 'Fermer';
}
async function cancelLocalRequest(cartId, token) {
  try {
    await api('shop_cancel', 'POST', { cart_id: cartId, token });
    toast('Demande annulée');
    const list = loadLocalRequests().filter(x => x.cart_id !== cartId);
    localStorage.setItem(LS_KEY, JSON.stringify(list));
    openMyRequests();
  } catch (e) { toast(e.message, true); }
}
function openMyRequests() {
  const list = loadLocalRequests();
  const body = list.length ? list.map(x => `
    <div class="flex items-center justify-between gap-2 py-2 border-b border-line last:border-0 text-[12.5px]">
      <div class="min-w-0">
        <p class="font-semibold truncate">DEM-${String(x.cart_id).padStart(6,'0')} <span class="text-mute font-normal">— ${esc(x.name)}</span></p>
        <p class="text-mute text-[11.5px]">${dateCH(x.date)} · ${chf(x.total)} CHF</p>
      </div>
      <button onclick="cancelLocalRequest(${x.cart_id}, '${x.token}')" class="shrink-0 text-[11.5px] font-semibold text-rose2-600 hover:underline">Annuler</button>
    </div>`).join('') : '<p class="text-mute text-[12.5px] py-6 text-center">Aucune demande enregistrée sur cet appareil</p>';
  openModal('Mes demandes', `<div>${body}</div>`, closeModal);
  document.getElementById('modalSubmit').textContent = 'Fermer';
}

loadCatalog();
</script>
</body>
</html>
