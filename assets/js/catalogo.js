var API_URL = "api.php";
var WA_NUM = "5493535697188";
var products = [],
    cart = {},
    activeCat = "TODOS",
    query = "",
    viewMode = "grid",
    sortMode = "default";

fetch(API_URL + "?action=config_get")
    .then(function (r) {
        return r.json();
    })
    .then(function (cfg) {
        if (cfg.whatsapp) WA_NUM = cfg.whatsapp;
    })
    .catch(function () {});

var lazyObs = null;
if (window.IntersectionObserver) {
    lazyObs = new IntersectionObserver(
        function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) {
                    var img = e.target,
                        src = img.getAttribute("data-src");
                    if (src) {
                        img.src = src;
                        img.removeAttribute("data-src");
                    }
                    lazyObs.unobserve(img);
                }
            });
        },
        { rootMargin: "200px" },
    );
}

function activateLazy() {
    if (!lazyObs) {
        document.querySelectorAll("img[data-src]").forEach(function (i) {
            i.src = i.getAttribute("data-src") || "";
        });
        return;
    }
    document.querySelectorAll("img[data-src]").forEach(function (i) {
        lazyObs.observe(i);
    });
}

function getImgSrc(p) {
    var v = p.UPDATED_AT || Date.now();
    if (p.FOTO && p.FOTO.indexOf("http") === 0) return p.FOTO + "?v=" + v;
    if (p.FOTO) return p.FOTO + "?v=" + v;
    return "imgs/" + (p.CODIGO || "").replace(/\//g, "_") + ".jpeg" + "?v=" + v;
}

function setView(v) {
    viewMode = v;
    document.getElementById("btnGrid").classList.toggle("on", v === "grid");
    document.getElementById("btnList").classList.toggle("on", v === "list");
    renderProds();
}

function setSort(v) {
    sortMode = v;
    document.querySelectorAll(".sort-btn").forEach(function (b) {
        b.classList.toggle("on", b.dataset.sort === v);
    });
    renderProds();
}

function start() {
    fetch(API_URL + "?action=productos&t=" + Date.now())
        .then(function (r) {
            if (!r.ok) throw new Error();
            return r.json();
        })
        .then(function (data) {
            products = data.map(function (p) {
                return {
                    CODIGO: p.codigo,
                    DESCRIPCION: p.descripcion,
                    CATEGORIA: p.categoria,
                    PRECIO_MAYORISTA: p.precio_mayorista,
                    PVP: p.pvp,
                    FOTO: p.foto,
                    ESTADO: p.estado,
                    ORDEN: p.orden,
                    MULTIPLO: parseInt(p.multiplo) || 1,
                    CAT_ORDEN: p.cat_orden || 0,
                    UPDATED_AT: p.updated_at
                        ? new Date(p.updated_at).getTime()
                        : Date.now(),
                    COLORES: p.colores || [],
                };
            });
            render();
        })
        .catch(function () {
            document.getElementById("prods").innerHTML =
                '<div class="loading">Error al cargar. Intentá recargar la página.</div>';
        });
}

function getCats() {
    // Orden de categorías según cat_orden
    var seen = {},
        cats = [];
    products.forEach(function (p) {
        if (p.CATEGORIA && !seen[p.CATEGORIA]) {
            seen[p.CATEGORIA] = 1;
            cats.push({ nombre: p.CATEGORIA, orden: p.CAT_ORDEN });
        }
    });
    cats.sort(function (a, b) {
        return a.orden - b.orden;
    });
    return ["TODOS"].concat(
        cats.map(function (c) {
            return c.nombre;
        }),
    );
}

function render() {
    renderTabs();
    renderProds();
}

function renderTabs() {
    var cats = getCats();
    // Tabs desktop
    document.getElementById("tabs").innerHTML = cats
        .map(function (c) {
            return (
                '<button class="tab' +
                (c === activeCat ? " on" : "") +
                '" onclick="setTab(\'' +
                c +
                "')\">" +
                c +
                "</button>"
            );
        })
        .join("");
    // Dropdown mobile
    document.getElementById("catDropdownLabel").textContent =
        "CATEGORÍA: " + activeCat;
    document.getElementById("catDropdownMenu").innerHTML = cats
        .map(function (c) {
            return (
                '<div class="cat-dropdown-item' +
                (c === activeCat ? " on" : "") +
                '" onclick="setTabDropdown(\'' +
                c +
                "')\">" +
                c +
                "</div>"
            );
        })
        .join("");
}

function toggleCatDropdown() {
    document.getElementById("catDropdownMenu").classList.toggle("open");
}

function setTabDropdown(c) {
    document.getElementById("catDropdownMenu").classList.remove("open");
    setTab(c);
}

// Cerrar dropdown al tocar fuera
document.addEventListener("click", function (e) {
    var wrap = document.getElementById("catDropdownWrap");
    if (wrap && !wrap.contains(e.target)) {
        var menu = document.getElementById("catDropdownMenu");
        if (menu) menu.classList.remove("open");
    }
});

function setTab(c) {
    activeCat = c;
    renderTabs();
    renderProds();
}
function doSearch() {
    query = document.getElementById("srch").value.toLowerCase().trim();
    renderProds();
}

function getVisible() {
    var list = products.filter(function (p) {
        var catOk = activeCat === "TODOS" || p.CATEGORIA === activeCat;
        var srchOk =
            !query ||
            (p.DESCRIPCION || "").toLowerCase().indexOf(query) >= 0 ||
            (p.CODIGO || "").toLowerCase().indexOf(query) >= 0;
        return catOk && srchOk;
    });

    // Ordenamiento
    if (sortMode === "alpha") {
        list = list.slice().sort(function (a, b) {
            return a.DESCRIPCION.localeCompare(b.DESCRIPCION);
        });
    } else if (sortMode === "price_asc") {
        list = list.slice().sort(function (a, b) {
            return (
                parseFloat(a.PRECIO_MAYORISTA) - parseFloat(b.PRECIO_MAYORISTA)
            );
        });
    } else if (sortMode === "price_desc") {
        list = list.slice().sort(function (a, b) {
            return (
                parseFloat(b.PRECIO_MAYORISTA) - parseFloat(a.PRECIO_MAYORISTA)
            );
        });
    }
    // default: mantiene el orden de la BD (por orden y categoria)

    return list;
}

function fmt(v) {
    return "$ " + Math.round(parseFloat(v) || 0).toLocaleString("es-AR");
}
function sid(code) {
    return "p" + code.replace(/[^a-zA-Z0-9]/g, "_");
}
function getQty(code) {
    return cart[code] ? cart[code].qty : getMultiplo(code);
}
function getMultiplo(code) {
    var p = products.find(function (x) {
        return x.CODIGO === code;
    });
    return p ? p.MULTIPLO || 1 : 1;
}

// Ajusta cantidad al múltiplo más cercano
function snapToMultiplo(qty, multiplo) {
    if (multiplo <= 1) return Math.max(1, qty);
    return Math.max(multiplo, Math.round(qty / multiplo) * multiplo);
}

function renderProds() {
    var list = getVisible();
    var el = document.getElementById("prods");
    if (!list.length) {
        el.innerHTML =
            '<div class="loading">No hay productos que coincidan.</div>';
        return;
    }

    // Barra de ordenamiento
    var sortBar =
        '<div class="sort-bar">' +
        '<span class="sort-lbl">Ordenar:</span>' +
        '<button class="sort-btn' +
        (sortMode === "default" ? " on" : "") +
        '" data-sort="default" onclick="setSort(\'default\')">Por defecto</button>' +
        '<button class="sort-btn' +
        (sortMode === "alpha" ? " on" : "") +
        '" data-sort="alpha" onclick="setSort(\'alpha\')">A → Z</button>' +
        '<button class="sort-btn' +
        (sortMode === "price_asc" ? " on" : "") +
        '" data-sort="price_asc" onclick="setSort(\'price_asc\')">$ ↑</button>' +
        '<button class="sort-btn' +
        (sortMode === "price_desc" ? " on" : "") +
        '" data-sort="price_desc" onclick="setSort(\'price_desc\')">$ ↓</button>' +
        "</div>";

    if (viewMode === "grid") renderGrid(list, el, sortBar);
    else renderList(list, el, sortBar);
}

function renderGrid(list, el, sortBar) {
    // Si está en TODOS y es orden por defecto, agrupar por categoría
    var useGroups = activeCat === "TODOS" && sortMode === "default";
    var html = sortBar;

    if (useGroups) {
        var bycat = {},
            order = [];
        list.forEach(function (p) {
            if (!bycat[p.CATEGORIA]) {
                bycat[p.CATEGORIA] = [];
                order.push(p.CATEGORIA);
            }
            bycat[p.CATEGORIA].push(p);
        });
        order.forEach(function (cat) {
            html +=
                '<div class="cat-title">' + cat + '</div><div class="grid">';
            bycat[cat].forEach(function (p) {
                html += cardHTML(p);
            });
            html += "</div>";
        });
    } else {
        html += '<div class="grid">';
        list.forEach(function (p) {
            html += cardHTML(p);
        });
        html += "</div>";
    }

    el.innerHTML = html;
    setTimeout(activateLazy, 30);
}

function cardHTML(p) {
    var sold = (p.ESTADO || "").toUpperCase() === "AGOTADO";
    var inCart = !!cart[p.CODIGO];
    var qty = getQty(p.CODIGO);
    var multiplo = p.MULTIPLO || 1;
    var id = sid(p.CODIGO);
    var src = getImgSrc(p);
    var html =
        '<div class="card' +
        (sold ? " sold" : "") +
        (inCart ? " picked" : "") +
        '" id="' +
        id +
        '">';
    html +=
        '<div class="card-img"><img data-src="' +
        src +
        '" alt="' +
        p.DESCRIPCION +
        '" onerror="this.style.display=\'none\'"></div>';
    html += '<div class="card-body">';
    html +=
        '<div class="c-top"><span class="code">' +
        p.CODIGO +
        "</span>" +
        (sold ? '<span class="badge">AGOTADO</span>' : "") +
        "</div>";
    html += '<div class="name">' + p.DESCRIPCION + "</div>";
    // Círculos de colores
    if (p.COLORES && p.COLORES.length > 0) {
        html += '<div class="color-dots"><span class="color-lbl">Color</span>';
        p.COLORES.forEach(function (c) {
            html +=
                '<span class="color-dot" style="background:' +
                c.hex +
                '" title="' +
                c.nombre +
                '"></span>';
        });
        html += "</div>";
    }
    html +=
        '<div class="prices"><div class="price">' +
        fmt(p.PRECIO_MAYORISTA) +
        "</div>";
    if (p.PVP)
        html +=
            '<div class="pvp">PVP sugerido<br><strong>' +
            fmt(p.PVP) +
            "</strong></div>";
    html += "</div>";
    if (sold) {
        html += '<div class="na">No disponible por ahora</div>';
    } else {
        html += '<div class="foot"><div class="qty">';
        html +=
            '<button class="qb" onclick="chgQty(\'' +
            p.CODIGO +
            "',-1)\">−</button>";
        html +=
            '<input class="qn" type="number" id="qn_' +
            id +
            '" value="' +
            qty +
            '" min="' +
            multiplo +
            '" step="' +
            multiplo +
            '" onchange="manualQty(\'' +
            p.CODIGO +
            "',this.value)\" onblur=\"manualQty('" +
            p.CODIGO +
            "',this.value)\">";
        html +=
            '<button class="qb" onclick="chgQty(\'' +
            p.CODIGO +
            "',1)\">+</button></div>";
        html +=
            '<button class="add' +
            (inCart ? " on" : "") +
            '" id="ab_' +
            id +
            '" onclick="addOrUpdate(\'' +
            p.CODIGO +
            "')\">" +
            (inCart ? "✓ En pedido" : "+ Agregar") +
            "</button></div>";
        if (multiplo > 1)
            html +=
                '<div class="multiplo-hint">Múltiplo de ' + multiplo + "</div>";
    }
    html += "</div></div>";
    return html;
}

function renderList(list, el, sortBar) {
    var useGroups = activeCat === "TODOS" && sortMode === "default";
    var html = sortBar + '<div class="list-wrap"><table class="list-table">';
    html +=
        "<thead><tr><th>Img</th><th>Código</th><th>Descripción</th><th>Precio May.</th><th>PVP</th><th>Cantidad</th><th></th></tr></thead><tbody>";
    if (useGroups) {
        var bycat = {},
            order = [];
        list.forEach(function (p) {
            if (!bycat[p.CATEGORIA]) {
                bycat[p.CATEGORIA] = [];
                order.push(p.CATEGORIA);
            }
            bycat[p.CATEGORIA].push(p);
        });
        order.forEach(function (cat) {
            html +=
                '<tr><td colspan="7" style="background:var(--pale);font-weight:800;color:var(--blue);font-size:12px;padding:8px 14px;text-transform:uppercase;letter-spacing:.5px">' +
                cat +
                "</td></tr>";
            bycat[cat].forEach(function (p) {
                html += listRowHTML(p);
            });
        });
    } else {
        list.forEach(function (p) {
            html += listRowHTML(p);
        });
    }
    html += "</tbody></table></div>";
    el.innerHTML = html;
    setTimeout(activateLazy, 30);
}

function listCardHTML(p) {
    var sold = (p.ESTADO || "").toUpperCase() === "AGOTADO";
    var inCart = !!cart[p.CODIGO];
    var qty = getQty(p.CODIGO);
    var multiplo = p.MULTIPLO || 1;
    var id = sid(p.CODIGO);
    var src = getImgSrc(p);
    var html =
        '<div class="lc' +
        (sold ? " sold-row" : "") +
        (inCart ? " picked-row" : "") +
        '" id="lr_' +
        id +
        '">';
    html += '<div class="lc-top">';
    html +=
        '<img class="lc-img" data-src="' +
        src +
        '" alt="" onerror="this.style.display=\'none\'">';
    html +=
        '<div class="lc-info"><div class="lc-name">' +
        p.DESCRIPCION +
        '</div><div class="lc-code">' +
        p.CODIGO +
        (sold ? ' <span class="badge">AGOTADO</span>' : "") +
        "</div></div>";
    html += "</div>";
    html += '<div class="lc-price">' + fmt(p.PRECIO_MAYORISTA) + "</div>";
    if (sold) {
        html += '<div style="color:#aaa;font-size:11px">No disponible</div>';
    } else {
        html += '<div class="lc-foot">';
        html +=
            '<div class="list-qty"><button class="qb" onclick="chgQty(\'' +
            p.CODIGO +
            '\',-1)">−</button><input class="qn" type="number" id="qn_' +
            id +
            '" value="' +
            qty +
            '" min="' +
            multiplo +
            '" step="' +
            multiplo +
            '" onchange="manualQty(\'' +
            p.CODIGO +
            "',this.value)\" onblur=\"manualQty('" +
            p.CODIGO +
            '\',this.value)" style="width:36px"><button class="qb" onclick="chgQty(\'' +
            p.CODIGO +
            "',1)\">+</button></div>";
        html +=
            '<button class="list-add' +
            (inCart ? " on" : "") +
            '" id="ab_' +
            id +
            '" onclick="addOrUpdate(\'' +
            p.CODIGO +
            "')\">" +
            (inCart ? "✓" : "+ Agregar") +
            "</button>";
        html += "</div>";
    }
    html += "</div>";
    return html;
}

function listRowHTML(p) {
    var sold = (p.ESTADO || "").toUpperCase() === "AGOTADO";
    var inCart = !!cart[p.CODIGO];
    var qty = getQty(p.CODIGO);
    var multiplo = p.MULTIPLO || 1;
    var id = sid(p.CODIGO);
    var src = getImgSrc(p);
    var html =
        '<tr class="' +
        (sold ? "sold-row" : "") +
        (inCart ? " picked-row" : "") +
        '" id="lr_' +
        id +
        '">';
    html +=
        '<td><img class="list-thumb" data-src="' +
        src +
        '" alt="" onerror="this.style.display=\'none\'"></td>';
    html +=
        '<td><span class="code">' +
        p.CODIGO +
        "</span>" +
        (sold ? ' <span class="badge">AGOTADO</span>' : "") +
        "</td>";
    html +=
        '<td style="font-weight:600">' +
        p.DESCRIPCION +
        (multiplo > 1
            ? ' <small style="color:var(--muted)">(x' + multiplo + ")</small>"
            : "") +
        "</td>";
    html +=
        '<td style="font-weight:800;color:var(--blue)">' +
        fmt(p.PRECIO_MAYORISTA) +
        "</td>";
    html +=
        '<td style="color:var(--muted)">' +
        (p.PVP ? fmt(p.PVP) : "—") +
        "</td>";
    if (sold) {
        html +=
            '<td colspan="2"><span style="color:#aaa;font-size:12px">No disponible</span></td>';
    } else {
        html +=
            '<td><div class="list-qty"><button class="qb" onclick="chgQty(\'' +
            p.CODIGO +
            '\',-1)">−</button><input class="qn" type="number" id="qn_' +
            id +
            '" value="' +
            qty +
            '" min="' +
            multiplo +
            '" step="' +
            multiplo +
            '" onchange="manualQty(\'' +
            p.CODIGO +
            "',this.value)\" onblur=\"manualQty('" +
            p.CODIGO +
            '\',this.value)" style="width:40px"><button class="qb" onclick="chgQty(\'' +
            p.CODIGO +
            "',1)\">+</button></div></td>";
        html +=
            '<td><button class="list-add' +
            (inCart ? " on" : "") +
            '" id="ab_' +
            id +
            '" onclick="addOrUpdate(\'' +
            p.CODIGO +
            "')\">" +
            (inCart ? "✓ En pedido" : "+ Agregar") +
            "</button></td>";
    }
    html += "</tr>";
    return html;
}

function manualQty(code, val) {
    var multiplo = getMultiplo(code);
    var num = parseInt(val) || multiplo;
    var snapped = snapToMultiplo(num, multiplo);
    var id = sid(code);
    var el = document.getElementById("qn_" + id);
    if (el) el.value = snapped;
    if (cart[code]) {
        cart[code].qty = snapped;
        updateCart();
    }
}

function chgQty(code, delta) {
    var multiplo = getMultiplo(code);
    var id = sid(code);
    var el = document.getElementById("qn_" + id);
    if (!el) return;
    var cur = parseInt(el.value) || multiplo;
    var next = Math.max(multiplo, cur + delta * multiplo);
    el.value = next;
    if (cart[code]) {
        cart[code].qty = next;
        updateCart();
    }
}

function addOrUpdate(code) {
    var p = products.find(function (x) {
        return x.CODIGO === code;
    });
    if (!p) return;
    var multiplo = p.MULTIPLO || 1;
    var id = sid(code);
    var qEl = document.getElementById("qn_" + id);
    var qty = qEl
        ? snapToMultiplo(parseInt(qEl.value) || multiplo, multiplo)
        : multiplo;
    if (qEl) qEl.value = qty;
    cart[code] = { p: p, qty: qty };
    var card = document.getElementById(id);
    if (card) {
        card.classList.add("picked");
        var btn = document.getElementById("ab_" + id);
        if (btn) {
            btn.textContent = "✓ En pedido";
            btn.classList.add("on");
        }
    }
    var row = document.getElementById("lr_" + id);
    if (row) {
        row.classList.add("picked-row");
        var lbtn = document.getElementById("ab_" + id);
        if (lbtn) {
            lbtn.textContent = "✓ En pedido";
            lbtn.classList.add("on");
        }
    }
    updateCart();
}

function rmCart(code) {
    var multiplo = getMultiplo(code);
    delete cart[code];
    var id = sid(code);
    var qEl = document.getElementById("qn_" + id);
    if (qEl) qEl.value = multiplo;
    var card = document.getElementById(id);
    if (card) {
        card.classList.remove("picked");
        var btn = document.getElementById("ab_" + id);
        if (btn) {
            btn.textContent = "+ Agregar";
            btn.classList.remove("on");
        }
    }
    var row = document.getElementById("lr_" + id);
    if (row) {
        row.classList.remove("picked-row");
        var lbtn = document.getElementById("ab_" + id);
        if (lbtn) {
            lbtn.textContent = "+ Agregar";
            lbtn.classList.remove("on");
        }
    }
    updateCart();
}

function setCartQty(code, qty) {
    var multiplo = getMultiplo(code);
    var snapped = snapToMultiplo(qty, multiplo);
    if (snapped < multiplo) {
        rmCart(code);
        return;
    }
    if (cart[code]) {
        cart[code].qty = snapped;
        var id = sid(code);
        var qEl = document.getElementById("qn_" + id);
        if (qEl) qEl.value = snapped;
        updateCart();
    }
}

function updateCart() {
    var keys = Object.keys(cart);
    document.getElementById("cartN").textContent = keys.length;
    var el = document.getElementById("pitems");
    if (!keys.length) {
        el.innerHTML =
            '<div class="empty">Todavía no agregaste productos.</div>';
        document.getElementById("ptotal").textContent = "$ 0";
        return;
    }
    var total = 0,
        html = "";
    keys.forEach(function (code) {
        var item = cart[code];
        var multiplo = item.p.MULTIPLO || 1;
        var sub = Math.round(
            (parseFloat(item.p.PRECIO_MAYORISTA) || 0) * item.qty,
        );
        total += sub;
        html +=
            '<div class="ci"><div class="ci-name">' +
            item.p.DESCRIPCION +
            '</div><div class="ci-code">Cód: ' +
            item.p.CODIGO +
            (multiplo > 1 ? " · Múltiplo: " + multiplo : "") +
            "</div>";
        html += '<div class="ci-row"><div class="cq">';
        html +=
            '<button class="cqb" onclick="setCartQty(\'' +
            code +
            "'," +
            (item.qty - multiplo) +
            ')">−</button>';
        html += '<span class="cqn">' + item.qty + "</span>";
        html +=
            '<button class="cqb" onclick="setCartQty(\'' +
            code +
            "'," +
            (item.qty + multiplo) +
            ')">+</button>';
        html +=
            '</div><span class="ci-sub">' +
            fmt(sub) +
            '</span><button class="rm" onclick="rmCart(\'' +
            code +
            "')\">🗑</button></div></div>";
    });
    el.innerHTML = html;
    document.getElementById("ptotal").textContent = fmt(total);
}

function openCart() {
    document.getElementById("overlay").classList.add("open");
}
function closeCart() {
    document.getElementById("overlay").classList.remove("open");
}
function bgClose(e) {
    if (e.target === document.getElementById("overlay")) closeCart();
}

// ── CLIENTE ───────────────────────────────────────────────────────────────────
var clienteId = null;
var transportes = [];

fetch(API_URL + "?action=transportes")
    .then(function (r) {
        return r.json();
    })
    .then(function (data) {
        transportes = data;
    })
    .catch(function () {});

function normalizarTelJS(caract, num) {
    var c = caract.replace(/\D/g, "").replace(/^0/, "");
    var n = num.replace(/\D/g, "").replace(/^15/, "");
    return "54" + c + n;
}

function telCompleto() {
    var c = document.getElementById("cCaract").value.trim();
    var n = document.getElementById("cNum").value.trim();
    return c.length >= 2 && n.length >= 6;
}

var telTimeout = null;
function onTelChange() {
    clienteId = null;
    document.getElementById("clienteForm").style.display = "none";
    clearTimeout(telTimeout);
    if (!telCompleto()) return;
    telTimeout = setTimeout(buscarCliente, 600);
}

async function buscarCliente() {
    var caract = document.getElementById("cCaract").value.trim();
    var num = document.getElementById("cNum").value.trim();
    var tel = normalizarTelJS(caract, num);
    try {
        var res = await fetch(
            API_URL +
                "?action=cliente_buscar&telefono=" +
                encodeURIComponent(tel),
        );
        var json = await res.json();
        mostrarFormCliente(json.found ? json.cliente : null);
    } catch (e) {
        mostrarFormCliente(null);
    }
}

function mostrarFormCliente(cliente) {
    var form = document.getElementById("clienteForm");
    form.style.display = "block";
    clienteId = cliente ? cliente.id : null;
    document.getElementById("cNombre").value = cliente
        ? cliente.nombre || ""
        : "";
    document.getElementById("cCuitDni").value = cliente
        ? cliente.cuit_dni || ""
        : "";
    document.getElementById("cEmail").value = cliente
        ? cliente.email || ""
        : "";
    document.getElementById("cDomicilio").value = cliente
        ? cliente.domicilio || ""
        : "";
    document.getElementById("cLocalidad").value = cliente
        ? cliente.localidad || ""
        : "";
    document.getElementById("cCP").value = cliente ? cliente.cp || "" : "";
    document.getElementById("cProvincia").value = cliente
        ? cliente.provincia || ""
        : "";
    document.getElementById("cNotas").value = cliente
        ? cliente.notas || ""
        : "";
    // Transporte
    var sel = document.getElementById("cTransporte");
    sel.innerHTML = '<option value="">— Seleccioná —</option>';
    transportes.forEach(function (t) {
        sel.innerHTML +=
            '<option value="' +
            t.nombre +
            '"' +
            (cliente && cliente.transporte === t.nombre ? " selected" : "") +
            ">" +
            t.nombre +
            "</option>";
    });
    sel.innerHTML +=
        '<option value="OTRO"' +
        (cliente && cliente.transporte === "OTRO" ? " selected" : "") +
        ">Otro</option>";
    onTransporteChange();
    if (cliente && cliente.transporte === "OTRO")
        document.getElementById("cTransporteOtro").value =
            cliente.transporte_otro || "";
}

function onTransporteChange() {
    var sel = document.getElementById("cTransporte").value;
    document.getElementById("cTransporteOtroWrap").style.display =
        sel === "OTRO" ? "block" : "none";
}

async function sendWA() {
    var keys = Object.keys(cart);
    if (!keys.length) {
        alert("Agregá al menos un producto.");
        return;
    }
    var nombre = document.getElementById("cNombre").value.trim();
    var caract = document.getElementById("cCaract").value.trim();
    var num = document.getElementById("cNum").value.trim();
    if (!nombre) {
        alert("El nombre es obligatorio.");
        document.getElementById("cNombre").focus();
        return;
    }
    if (!telCompleto()) {
        alert("El teléfono es obligatorio.");
        return;
    }
    var tel = normalizarTelJS(caract, num);
    var transporte = document.getElementById("cTransporte").value;
    if (transporte === "OTRO")
        transporte =
            document.getElementById("cTransporteOtro").value.trim() || "OTRO";
    var clienteData = {
        telefono: tel,
        nombre,
        cuit_dni: document.getElementById("cCuitDni").value.trim(),
        email: document.getElementById("cEmail").value.trim(),
        domicilio: document.getElementById("cDomicilio").value.trim(),
        localidad: document.getElementById("cLocalidad").value.trim(),
        cp: document.getElementById("cCP").value.trim(),
        provincia: document.getElementById("cProvincia").value.trim(),
        transporte,
        notas: document.getElementById("cNotas").value.trim(),
    };
    // Guardar cliente en BD
    var cRes = await fetch(API_URL + "?action=cliente_guardar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(clienteData),
    });
    var cJson = await cRes.json();
    if (!cJson.ok) {
        alert("Error al guardar datos del cliente");
        return;
    }
    clienteId = cJson.id;
    // Armar items
    var items = [];
    var total = 0;
    Object.keys(cart).forEach(function (code) {
        var item = cart[code];
        var precio = parseFloat(item.p.PRECIO_MAYORISTA) || 0;
        var sub = Math.round(precio * item.qty);
        total += sub;
        items.push({
            codigo: item.p.CODIGO,
            descripcion: item.p.DESCRIPCION,
            cantidad: item.qty,
            precio_unitario: precio,
            subtotal: sub,
        });
    });
    // Guardar pedido en BD
    await fetch(API_URL + "?action=pedido_crear", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ cliente_id: clienteId, total, items }),
    });
    // Armar mensaje WhatsApp
    var fecha = new Date().toLocaleDateString("es-AR");
    var msg = "🧳 *PEDIDO TRAVEL BLUE ARGENTINA*\n━━━━━━━━━━━━━━━━━━━━━━\n";
    msg += "👤 *Cliente:* " + nombre + "\n";
    msg += "📞 *Tel:* +" + tel + "\n";
    if (clienteData.cuit_dni)
        msg += "🪪 *CUIT/DNI:* " + clienteData.cuit_dni + "\n";
    if (clienteData.domicilio)
        msg +=
            "📍 *Envío:* " +
            clienteData.domicilio +
            ", " +
            (clienteData.localidad || "") +
            " (" +
            (clienteData.cp || "") +
            ") " +
            (clienteData.provincia || "") +
            "\n";
    if (transporte) msg += "🚚 *Transporte:* " + transporte + "\n";
    if (clienteData.notas) msg += "📝 *Notas:* " + clienteData.notas + "\n";
    msg += "📅 *Fecha:* " + fecha + "\n━━━━━━━━━━━━━━━━━━━━━━\n\n";
    items.forEach(function (item) {
        msg +=
            "• *" +
            item.descripcion +
            "*\n  Cód: " +
            item.codigo +
            "  |  Cant: " +
            item.cantidad +
            "  |  " +
            fmt(item.subtotal) +
            "\n\n";
    });
    msg +=
        "━━━━━━━━━━━━━━━━━━━━━━\n*TOTAL MAYORISTA: " +
        fmt(total) +
        "*\n━━━━━━━━━━━━━━━━━━━━━━\n_Pedido generado desde el catálogo online Travel Blue Argentina_";
    window.open(
        "https://wa.me/" + WA_NUM + "?text=" + encodeURIComponent(msg),
        "_blank",
    );
}

start();
