var API_URL = 'api.php';
var WA_NUM = '5493535697188';
var products = [], cart = {}, activeCat = 'TODOS', query = '', viewMode = 'grid';

// Cargar número WA desde config
fetch(API_URL + '?action=config_get')
  .then(function(r) { return r.json(); })
  .then(function(cfg) { if (cfg.whatsapp) WA_NUM = cfg.whatsapp; })
  .catch(function() {});

// Lazy loading
var lazyObs = null;
if (window.IntersectionObserver) {
  lazyObs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) {
        var img = e.target, src = img.getAttribute('data-src');
        if (src) { img.src = src; img.removeAttribute('data-src'); }
        lazyObs.unobserve(img);
      }
    });
  }, { rootMargin: '200px' });
}

function activateLazy() {
  if (!lazyObs) {
    document.querySelectorAll('img[data-src]').forEach(function(i) { i.src = i.getAttribute('data-src') || ''; });
    return;
  }
  document.querySelectorAll('img[data-src]').forEach(function(i) { lazyObs.observe(i); });
}

function getImgSrc(p) {
  if (p.FOTO && p.FOTO.indexOf('http') === 0) return p.FOTO;
  if (p.FOTO) return p.FOTO;
  return 'imgs/' + (p.CODIGO || '').replace(/\//g, '_') + '.jpeg';
}

function setView(v) {
  viewMode = v;
  document.getElementById('btnGrid').classList.toggle('on', v === 'grid');
  document.getElementById('btnList').classList.toggle('on', v === 'list');
  renderProds();
}

function start() {
  fetch(API_URL + '?action=productos&t=' + Date.now())
    .then(function(r) { if (!r.ok) throw new Error(); return r.json(); })
    .then(function(data) {
      products = data.map(function(p) {
        return { CODIGO: p.codigo, DESCRIPCION: p.descripcion, CATEGORIA: p.categoria, PRECIO_MAYORISTA: p.precio_mayorista, PVP: p.pvp, FOTO: p.foto, ESTADO: p.estado, ORDEN: p.orden };
      });
      render();
    })
    .catch(function() {
      document.getElementById('prods').innerHTML = '<div class="loading">Error al cargar. Intentá recargar la página.</div>';
    });
}

function getCats() {
  var seen = {}, list = ['TODOS'];
  products.forEach(function(p) { if (p.CATEGORIA && !seen[p.CATEGORIA]) { seen[p.CATEGORIA] = 1; list.push(p.CATEGORIA); } });
  return list;
}

function render() { renderTabs(); renderProds(); }

function renderTabs() {
  document.getElementById('tabs').innerHTML = getCats().map(function(c) {
    return '<button class="tab' + (c === activeCat ? ' on' : '') + '" onclick="setTab(\'' + c + '\')">' + c + '</button>';
  }).join('');
}

function setTab(c) { activeCat = c; renderTabs(); renderProds(); }
function doSearch() { query = document.getElementById('srch').value.toLowerCase().trim(); renderProds(); }

function getVisible() {
  return products.filter(function(p) {
    var catOk = activeCat === 'TODOS' || p.CATEGORIA === activeCat;
    var srchOk = !query || (p.DESCRIPCION || '').toLowerCase().indexOf(query) >= 0 || (p.CODIGO || '').toLowerCase().indexOf(query) >= 0;
    return catOk && srchOk;
  });
}

function fmt(v) { return '$ ' + (Math.round(parseFloat(v) || 0)).toLocaleString('es-AR'); }
function sid(code) { return 'p' + code.replace(/[^a-zA-Z0-9]/g, '_'); }
function getQty(code) { return cart[code] ? cart[code].qty : 1; }

function renderProds() {
  var list = getVisible();
  var el = document.getElementById('prods');
  if (!list.length) { el.innerHTML = '<div class="loading">No hay productos que coincidan.</div>'; return; }
  if (viewMode === 'grid') renderGrid(list, el);
  else renderList(list, el);
}

function renderGrid(list, el) {
  var bycat = {}, order = [];
  list.forEach(function(p) { if (!bycat[p.CATEGORIA]) { bycat[p.CATEGORIA] = []; order.push(p.CATEGORIA); } bycat[p.CATEGORIA].push(p); });
  var html = '';
  order.forEach(function(cat) {
    html += '<div class="cat-title">' + cat + '</div><div class="grid">';
    bycat[cat].forEach(function(p) {
      var sold = (p.ESTADO || '').toUpperCase() === 'AGOTADO';
      var inCart = !!cart[p.CODIGO];
      var qty = getQty(p.CODIGO);
      var id = sid(p.CODIGO);
      var src = getImgSrc(p);
      html += '<div class="card' + (sold ? ' sold' : '') + (inCart ? ' picked' : '') + '" id="' + id + '">';
      html += '<div class="card-img"><img data-src="' + src + '" alt="' + p.DESCRIPCION + '" onerror="this.style.display=\'none\'"></div>';
      html += '<div class="card-body">';
      html += '<div class="c-top"><span class="code">' + p.CODIGO + '</span>' + (sold ? '<span class="badge">AGOTADO</span>' : '') + '</div>';
      html += '<div class="name">' + p.DESCRIPCION + '</div>';
      html += '<div class="prices"><div class="price">' + fmt(p.PRECIO_MAYORISTA) + '</div>';
      if (p.PVP) html += '<div class="pvp">PVP sugerido<br><strong>' + fmt(p.PVP) + '</strong></div>';
      html += '</div>';
      if (sold) {
        html += '<div class="na">No disponible por ahora</div>';
      } else {
        html += '<div class="foot"><div class="qty">';
        html += '<button class="qb" onclick="chgQty(\'' + p.CODIGO + '\',-1)">−</button>';
        html += '<span class="qn" id="qn_' + id + '">' + qty + '</span>';
        html += '<button class="qb" onclick="chgQty(\'' + p.CODIGO + '\',1)">+</button></div>';
        html += '<button class="add' + (inCart ? ' on' : '') + '" id="ab_' + id + '" onclick="addOrUpdate(\'' + p.CODIGO + '\')">' + (inCart ? '✓ En pedido' : '+ Agregar') + '</button></div>';
      }
      html += '</div></div>';
    });
    html += '</div>';
  });
  el.innerHTML = html;
  setTimeout(activateLazy, 30);
}

function renderList(list, el) {
  var bycat = {}, order = [];
  list.forEach(function(p) { if (!bycat[p.CATEGORIA]) { bycat[p.CATEGORIA] = []; order.push(p.CATEGORIA); } bycat[p.CATEGORIA].push(p); });
  var html = '';
  order.forEach(function(cat) {
    html += '<div class="cat-title">' + cat + '</div><div class="list-wrap"><table class="list-table">';
    html += '<thead><tr><th>Img</th><th>Código</th><th>Descripción</th><th>Precio May.</th><th>PVP</th><th>Cantidad</th><th></th></tr></thead><tbody>';
    bycat[cat].forEach(function(p) {
      var sold = (p.ESTADO || '').toUpperCase() === 'AGOTADO';
      var inCart = !!cart[p.CODIGO];
      var qty = getQty(p.CODIGO);
      var id = sid(p.CODIGO);
      var src = getImgSrc(p);
      html += '<tr class="' + (sold ? 'sold-row' : '') + (inCart ? ' picked-row' : '') + '" id="lr_' + id + '">';
      html += '<td><img class="list-thumb" data-src="' + src + '" alt="" onerror="this.style.display=\'none\'"></td>';
      html += '<td><span class="code">' + p.CODIGO + '</span>' + (sold ? ' <span class="badge">AGOTADO</span>' : '') + '</td>';
      html += '<td style="font-weight:600">' + p.DESCRIPCION + '</td>';
      html += '<td style="font-weight:800;color:var(--blue)">' + fmt(p.PRECIO_MAYORISTA) + '</td>';
      html += '<td style="color:var(--muted)">' + (p.PVP ? fmt(p.PVP) : '—') + '</td>';
      if (sold) {
        html += '<td colspan="2"><span style="color:#aaa;font-size:12px">No disponible</span></td>';
      } else {
        html += '<td><div class="list-qty"><button class="qb" onclick="chgQty(\'' + p.CODIGO + '\',-1)">−</button><span class="qn" id="qn_' + id + '">' + qty + '</span><button class="qb" onclick="chgQty(\'' + p.CODIGO + '\',1)">+</button></div></td>';
        html += '<td><button class="list-add' + (inCart ? ' on' : '') + '" id="ab_' + id + '" onclick="addOrUpdate(\'' + p.CODIGO + '\')">' + (inCart ? '✓ En pedido' : '+ Agregar') + '</button></td>';
      }
      html += '</tr>';
    });
    html += '</tbody></table></div>';
  });
  el.innerHTML = html;
  setTimeout(activateLazy, 30);
}

function chgQty(code, delta) {
  var id = sid(code);
  var el = document.getElementById('qn_' + id);
  if (!el) return;
  var next = Math.max(1, (parseInt(el.textContent) || 1) + delta);
  el.textContent = next;
  if (cart[code]) { cart[code].qty = next; updateCart(); }
}

function addOrUpdate(code) {
  var p = products.find(function(x) { return x.CODIGO === code; });
  if (!p) return;
  var id = sid(code);
  var qEl = document.getElementById('qn_' + id);
  var qty = qEl ? parseInt(qEl.textContent) || 1 : 1;
  cart[code] = { p: p, qty: qty };
  var card = document.getElementById(id);
  if (card) { card.classList.add('picked'); var btn = document.getElementById('ab_' + id); if (btn) { btn.textContent = '✓ En pedido'; btn.classList.add('on'); } }
  var row = document.getElementById('lr_' + id);
  if (row) { row.classList.add('picked-row'); var lbtn = document.getElementById('ab_' + id); if (lbtn) { lbtn.textContent = '✓ En pedido'; lbtn.classList.add('on'); } }
  updateCart();
}

function rmCart(code) {
  delete cart[code];
  var id = sid(code);
  var qEl = document.getElementById('qn_' + id); if (qEl) qEl.textContent = '1';
  var card = document.getElementById(id); if (card) { card.classList.remove('picked'); var btn = document.getElementById('ab_' + id); if (btn) { btn.textContent = '+ Agregar'; btn.classList.remove('on'); } }
  var row = document.getElementById('lr_' + id); if (row) { row.classList.remove('picked-row'); var lbtn = document.getElementById('ab_' + id); if (lbtn) { lbtn.textContent = '+ Agregar'; lbtn.classList.remove('on'); } }
  updateCart();
}

function setCartQty(code, qty) {
  if (qty < 1) { rmCart(code); return; }
  if (cart[code]) {
    cart[code].qty = qty;
    var id = sid(code);
    var qEl = document.getElementById('qn_' + id); if (qEl) qEl.textContent = qty;
    updateCart();
  }
}

function updateCart() {
  var keys = Object.keys(cart);
  document.getElementById('cartN').textContent = keys.length;
  var el = document.getElementById('pitems');
  if (!keys.length) { el.innerHTML = '<div class="empty">Todavía no agregaste productos.</div>'; document.getElementById('ptotal').textContent = '$ 0'; return; }
  var total = 0, html = '';
  keys.forEach(function(code) {
    var item = cart[code];
    var sub = Math.round((parseFloat(item.p.PRECIO_MAYORISTA) || 0) * item.qty);
    total += sub;
    html += '<div class="ci"><div class="ci-name">' + item.p.DESCRIPCION + '</div><div class="ci-code">Cód: ' + item.p.CODIGO + '</div>';
    html += '<div class="ci-row"><div class="cq"><button class="cqb" onclick="setCartQty(\'' + code + '\',' + (item.qty - 1) + ')">−</button><span class="cqn">' + item.qty + '</span><button class="cqb" onclick="setCartQty(\'' + code + '\',' + (item.qty + 1) + ')">+</button></div>';
    html += '<span class="ci-sub">' + fmt(sub) + '</span><button class="rm" onclick="rmCart(\'' + code + '\')">🗑</button></div></div>';
  });
  el.innerHTML = html;
  document.getElementById('ptotal').textContent = fmt(total);
}

function openCart() { document.getElementById('overlay').classList.add('open'); }
function closeCart() { document.getElementById('overlay').classList.remove('open'); }
function bgClose(e) { if (e.target === document.getElementById('overlay')) closeCart(); }

function sendWA() {
  var keys = Object.keys(cart);
  if (!keys.length) { alert('Agregá al menos un producto.'); return; }
  var nombre = document.getElementById('cname').value.trim();
  var tel = document.getElementById('cphone').value.trim();
  var fecha = new Date().toLocaleDateString('es-AR');
  var msg = '🧳 *PEDIDO TRAVEL BLUE ARGENTINA*\n━━━━━━━━━━━━━━━━━━━━━━\n';
  if (nombre) msg += '👤 *Cliente:* ' + nombre + '\n';
  if (tel) msg += '📞 *Tel:* ' + tel + '\n';
  msg += '📅 *Fecha:* ' + fecha + '\n━━━━━━━━━━━━━━━━━━━━━━\n\n';
  var total = 0;
  keys.forEach(function(code) {
    var item = cart[code];
    var sub = Math.round((parseFloat(item.p.PRECIO_MAYORISTA) || 0) * item.qty);
    total += sub;
    msg += '• *' + item.p.DESCRIPCION + '*\n  Cód: ' + item.p.CODIGO + '  |  Cant: ' + item.qty + '  |  ' + fmt(sub) + '\n\n';
  });
  msg += '━━━━━━━━━━━━━━━━━━━━━━\n*TOTAL MAYORISTA: ' + fmt(total) + '*\n━━━━━━━━━━━━━━━━━━━━━━\n_Pedido generado desde el catálogo online Travel Blue Argentina_';
  window.open('https://wa.me/' + WA_NUM + '?text=' + encodeURIComponent(msg), '_blank');
}

start();
