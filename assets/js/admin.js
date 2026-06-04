var API = "../api.php";
var UPLOAD = "../upload.php";
var authUser = "",
    authPass = "";
var allProducts = [],
    allCats = [],
    allColores = [];
var pendingFile = null,
    codigoOk = true,
    checkTimeout = null;
var editMode = false,
    dragSrc = null;
var sortedProducts = null;

// Columnas visibles — persistidas en localStorage
var COLS = [
    { key: "handle", label: "Orden", default: true },
    { key: "img", label: "Imagen", default: true },
    { key: "codigo", label: "Código", default: true },
    { key: "desc", label: "Descripción", default: true },
    { key: "cat", label: "Categoría", default: true },
    { key: "may", label: "Mayorista", default: true },
    { key: "pvp", label: "PVP", default: true },
    { key: "estado", label: "Estado", default: true },
    { key: "multiplo", label: "Múltiplo", default: true },
    { key: "colores", label: "Colores", default: true },
    { key: "acciones", label: "Acciones", default: true },
];
var visibleCols = {};
function loadColPrefs() {
    try {
        var saved = JSON.parse(localStorage.getItem("tb_cols") || "{}");
        COLS.forEach(function (c) {
            visibleCols[c.key] =
                saved[c.key] !== undefined ? saved[c.key] : c.default;
        });
    } catch (e) {
        COLS.forEach(function (c) {
            visibleCols[c.key] = c.default;
        });
    }
}
function saveColPrefs() {
    localStorage.setItem("tb_cols", JSON.stringify(visibleCols));
}
function openColModal() {
    var html = "";
    COLS.forEach(function (c) {
        html +=
            '<label style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);cursor:pointer">';
        html +=
            '<input type="checkbox" ' +
            (visibleCols[c.key] ? "checked" : "") +
            " onchange=\"visibleCols['" +
            c.key +
            "']=this.checked\"> " +
            c.label;
        html += "</label>";
    });
    document.getElementById("colModalBody").innerHTML = html;
    document.getElementById("colModalBg").classList.add("open");
}
function closeColModal() {
    document.getElementById("colModalBg").classList.remove("open");
}
function applyColModal() {
    saveColPrefs();
    closeColModal();
    renderTable(getFiltered());
}
loadColPrefs();

// ── AUTH ──────────────────────────────────────────────────────────────────────
async function doLogin() {
    var u = document.getElementById("luser").value.trim();
    var p = document.getElementById("lpass").value.trim();
    if (!u || !p) {
        document.getElementById("lerr").textContent = "Completá los campos";
        return;
    }
    var btn = document.querySelector("#loginWrap button");
    btn.disabled = true;
    btn.textContent = "Ingresando...";
    document.getElementById("lerr").textContent = "";
    try {
        var res = await fetch(API + "?action=login", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ user: u, pass: p }),
        });
        if (res.ok) {
            authUser = u;
            authPass = p;
            localStorage.setItem("tb_admin_user", u);
            localStorage.setItem("tb_admin_pass", p);
            btn.textContent = "Cargando datos...";
            await loadCats();
            await loadColores();
            await loadProducts();
            loadConfig();
            loadTransportes();
            document.getElementById("loginWrap").style.display = "none";
            document.getElementById("appWrap").style.display = "block";
        } else {
            document.getElementById("lerr").textContent =
                "Usuario o contraseña incorrectos";
            btn.disabled = false;
            btn.textContent = "Ingresar";
        }
    } catch (e) {
        document.getElementById("lerr").textContent = "Error de conexión";
        btn.disabled = false;
        btn.textContent = "Ingresar";
    }
}
function doLogout() {
    localStorage.removeItem("tb_admin_user");
    localStorage.removeItem("tb_admin_pass");
    location.reload();
}
async function tryAutoLogin() {
    var u = localStorage.getItem("tb_admin_user");
    var p = localStorage.getItem("tb_admin_pass");
    if (!u || !p) return;
    try {
        var res = await fetch(API + "?action=login", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ user: u, pass: p }),
        });
        if (res.ok) {
            authUser = u;
            authPass = p;
            await loadCats();
            await loadColores();
            await loadProducts();
            loadConfig();
            loadTransportes();
            document.getElementById("loginWrap").style.display = "none";
            document.getElementById("appWrap").style.display = "block";
        } else {
            localStorage.removeItem("tb_admin_user");
            localStorage.removeItem("tb_admin_pass");
        }
    } catch (e) {}
}
document.addEventListener("DOMContentLoaded", function () {
    tryAutoLogin();
});

// ── NAVEGACIÓN ────────────────────────────────────────────────────────────────
function showSection(s, btn) {
    document
        .querySelectorAll(".section")
        .forEach((el) => el.classList.remove("on"));
    document
        .querySelectorAll(".nav-btn")
        .forEach((el) => el.classList.remove("on"));
    document
        .getElementById("sec" + s.charAt(0).toUpperCase() + s.slice(1))
        .classList.add("on");
    btn.classList.add("on");
    if (s === "categorias") renderCatTable();
    if (s === "colores") renderColoresTable();
    if (s === "pedidos") loadPedidos();
    if (s === "clientes") loadClientes();
}

// ── CONFIGURACIÓN ─────────────────────────────────────────────────────────────
async function loadConfig() {
    var res = await fetch(API + "?action=config_get");
    var cfg = await res.json();
    if (cfg.whatsapp) document.getElementById("cfgWA").value = cfg.whatsapp;
}
async function saveWA() {
    var val = document.getElementById("cfgWA").value.trim();
    if (!val) {
        toast("Ingresá un número", "#c62828");
        return;
    }
    var res = await fetch(API + "?action=config_set", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            _user: authUser,
            _pass: authPass,
            clave: "whatsapp",
            valor: val,
        }),
    });
    var json = await res.json();
    if (json.ok) toast("Número de WhatsApp actualizado");
    else toast("Error al guardar", "#c62828");
}
async function savePassword() {
    var actual = document.getElementById("cfgPassActual").value.trim();
    var nueva = document.getElementById("cfgPassNueva").value.trim();
    var confirma = document.getElementById("cfgPassConfirma").value.trim();
    if (!actual || !nueva || !confirma) {
        toast("Completá todos los campos", "#c62828");
        return;
    }
    if (actual !== authPass) {
        toast("La contraseña actual es incorrecta", "#c62828");
        return;
    }
    if (nueva !== confirma) {
        toast("Las contraseñas nuevas no coinciden", "#c62828");
        return;
    }
    if (nueva.length < 6) {
        toast("La contraseña debe tener al menos 6 caracteres", "#c62828");
        return;
    }
    var res = await fetch(API + "?action=cambiar_password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass, nueva }),
    });
    var json = await res.json();
    if (json.ok) {
        authPass = nueva;
        toast("Contraseña actualizada");
        document.getElementById("cfgPassActual").value = "";
        document.getElementById("cfgPassNueva").value = "";
        document.getElementById("cfgPassConfirma").value = "";
    } else toast("Error al cambiar contraseña", "#c62828");
}

// ── COLORES ───────────────────────────────────────────────────────────────────
async function loadColores() {
    var res = await fetch(API + "?action=colores");
    allColores = await res.json();
    renderColoresTable();
    renderColorSelector();
}
function renderColoresTable(filter) {
    var el = document.getElementById("coloresTbody");
    if (!el) return;
    var list = allColores;
    if (filter)
        list = list.filter(function (c) {
            return c.nombre.toLowerCase().includes(filter.toLowerCase());
        });
    var html = "";
    list.forEach(function (c) {
        html += "<tr>";
        html +=
            '<td><span style="display:inline-block;width:24px;height:24px;border-radius:50%;background:' +
            c.hex +
            ';border:1.5px solid rgba(0,0,0,.15);vertical-align:middle"></span></td>';
        html += "<td><strong>" + c.nombre + "</strong></td>";
        html += "<td><code>" + c.hex + "</code></td>";
        html +=
            '<td><div class="actions"><button class="btn btn-edit" onclick="openColorModal(' +
            c.id +
            ')">✏ Editar</button>';
        html +=
            '<button class="btn btn-danger" onclick="eliminarColor(' +
            c.id +
            ",'" +
            c.nombre +
            "')\">🗑</button></div></td>";
        html += "</tr>";
    });
    el.innerHTML =
        html ||
        '<tr><td colspan="4" style="text-align:center;color:#aaa;padding:20px">No hay colores</td></tr>';
}
function renderColorSelector(filter) {
    var el = document.getElementById("fColores");
    if (!el) return;
    el.innerHTML = "";
    var list = allColores;
    if (filter)
        list = list.filter(function (c) {
            return c.nombre.toLowerCase().includes(filter.toLowerCase());
        });
    list.forEach(function (c) {
        var item = document.createElement("label");
        item.className = "color-option";
        item.innerHTML =
            '<input type="checkbox" value="' +
            c.id +
            '"> <span class="color-dot-admin" style="background:' +
            c.hex +
            '"></span> <span>' +
            c.nombre +
            "</span>";
        el.appendChild(item);
    });
}
async function crearColorDesdeModal() {
    var nombre = document
        .getElementById("quickColorNombre")
        .value.trim()
        .toUpperCase();
    var hex = document.getElementById("quickColorHex").value.trim();
    if (!nombre || !hex) {
        toast("Ingresá nombre y color", "#c62828");
        return;
    }
    // Guardar checks actuales
    var checked = [];
    document
        .querySelectorAll("#fColores input[type=checkbox]:checked")
        .forEach(function (cb) {
            checked.push(parseInt(cb.value));
        });
    var res = await fetch(API + "?action=color_crear", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass, nombre, hex }),
    });
    var json = await res.json();
    if (json.ok) {
        document.getElementById("quickColorNombre").value = "";
        toast("Color creado");
        await loadColores();
        // Restaurar checks + seleccionar el nuevo
        checked.push(json.id);
        document
            .querySelectorAll("#fColores input[type=checkbox]")
            .forEach(function (cb) {
                cb.checked = checked.indexOf(parseInt(cb.value)) >= 0;
            });
    } else toast("Error: " + (json.error || "ya existe"), "#c62828");
}
async function crearColor() {
    var nombre = document
        .getElementById("newColorNombre")
        .value.trim()
        .toUpperCase();
    var hex = document.getElementById("newColorHex").value.trim();
    if (!nombre || !hex) {
        toast("Ingresá nombre y color", "#c62828");
        return;
    }
    var res = await fetch(API + "?action=color_crear", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass, nombre, hex }),
    });
    var json = await res.json();
    if (json.ok) {
        document.getElementById("newColorNombre").value = "";
        toast("Color creado");
        await loadColores();
    } else toast("Error: " + (json.error || "ya existe"), "#c62828");
}
function openColorModal(id) {
    var c = allColores.find((x) => parseInt(x.id) === parseInt(id));
    if (!c) return;
    document.getElementById("colorEditId").value = c.id;
    document.getElementById("colorEditNombre").value = c.nombre;
    document.getElementById("colorEditHex").value = c.hex;
    document.getElementById("colorModalBg").classList.add("open");
}
function closeColorModal() {
    document.getElementById("colorModalBg").classList.remove("open");
}
async function guardarColor() {
    var id = document.getElementById("colorEditId").value;
    var nombre = document.getElementById("colorEditNombre").value.trim();
    var hex = document.getElementById("colorEditHex").value.trim();
    var res = await fetch(API + "?action=color_editar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass, nombre, hex }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Color actualizado");
        closeColorModal();
        await loadColores();
    } else toast("Error", "#c62828");
}
async function eliminarColor(id, nombre) {
    if (!confirm('¿Eliminar "' + nombre + '"?')) return;
    var res = await fetch(API + "?action=color_eliminar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Color eliminado");
        await loadColores();
    }
}

// ── MODO EDICIÓN ──────────────────────────────────────────────────────────────
function toggleEditMode() {
    editMode = !editMode;
    sortedProducts = null;
    document.getElementById("btnEditMode").classList.toggle("on", editMode);
    document.getElementById("editModeBar").classList.toggle("on", editMode);
    document.getElementById("sortToolbar").style.display = editMode
        ? "flex"
        : "none";
    renderTable(getFiltered());
}

// ── ORDENAMIENTO AUTOMÁTICO DE PRODUCTOS ──────────────────────────────────────
function autoSort(by) {
    var list = (sortedProducts || allProducts).slice();
    if (by === "codigo")
        list.sort(function (a, b) {
            return String(a.codigo).localeCompare(String(b.codigo), undefined, {
                numeric: true,
            });
        });
    else if (by === "precio")
        list.sort(function (a, b) {
            return (
                parseFloat(a.precio_mayorista) - parseFloat(b.precio_mayorista)
            );
        });
    else if (by === "categoria")
        list.sort(function (a, b) {
            return (
                a.categoria.localeCompare(b.categoria) ||
                String(a.codigo).localeCompare(String(b.codigo), undefined, {
                    numeric: true,
                })
            );
        });
    sortedProducts = list;
    renderTableFromList(list);
    document.getElementById("sortSaveBtn").style.display = "inline-block";
    document.getElementById("sortCancelBtn").style.display = "inline-block";
    toast("Vista previa del nuevo orden — guardá para confirmar");
}

async function confirmSortSave() {
    if (!sortedProducts) return;
    var order = sortedProducts.map(function (p, i) {
        return { id: p.id, orden: i };
    });
    var res = await fetch(API + "?action=reordenar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            _user: authUser,
            _pass: authPass,
            orden: order,
        }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Orden guardado");
        sortedProducts = null;
        document.getElementById("sortSaveBtn").style.display = "none";
        document.getElementById("sortCancelBtn").style.display = "none";
        await loadProducts();
    } else toast("Error al guardar", "#c62828");
}

function cancelSort() {
    sortedProducts = null;
    document.getElementById("sortSaveBtn").style.display = "none";
    document.getElementById("sortCancelBtn").style.display = "none";
    renderTable(getFiltered());
    toast("Ordenamiento descartado");
}

// ── CATEGORÍAS ────────────────────────────────────────────────────────────────
async function loadCats() {
    var res = await fetch(API + "?action=categorias");
    allCats = await res.json();
    renderCatSelector();
    renderCatFilter();
}
function renderCatSelector() {
    var sel = document.getElementById("fCategoria");
    var cur = sel.value;
    sel.innerHTML = '<option value="">— Seleccioná —</option>';
    allCats.forEach(function (c) {
        var o = document.createElement("option");
        o.value = c.nombre;
        o.textContent = c.nombre;
        sel.appendChild(o);
    });
    if (cur) sel.value = cur;
}
function renderCatFilter() {
    var sel = document.getElementById("filtCat");
    var cur = sel.value;
    sel.innerHTML = '<option value="">Todas las categorías</option>';
    allCats.forEach(function (c) {
        var o = document.createElement("option");
        o.value = c.nombre;
        o.textContent = c.nombre;
        sel.appendChild(o);
    });
    if (cur) sel.value = cur;
}

var catDragSrc = null;

function renderCatTable() {
    var html = "";
    allCats.forEach(function (c, i) {
        var count = allProducts.filter((p) => p.categoria === c.nombre).length;
        html += '<tr draggable="true" data-cat-id="' + c.id + '">';
        html += '<td><span class="drag-handle">⠿</span></td>';
        html += "<td><strong>" + c.nombre + "</strong></td>";
        html +=
            "<td>" + count + " producto" + (count !== 1 ? "s" : "") + "</td>";
        html +=
            '<td><div class="actions"><button class="btn btn-edit" onclick="openCatModal(' +
            c.id +
            ')">✏ Editar</button>';
        html +=
            '<button class="btn btn-danger" onclick="eliminarCategoria(' +
            c.id +
            ",'" +
            c.nombre +
            "'," +
            count +
            ')">🗑</button></div></td></tr>';
    });
    document.getElementById("catTbody").innerHTML =
        html ||
        '<tr><td colspan="4" style="text-align:center;color:#aaa;padding:20px">No hay categorías</td></tr>';
    initCatDragDrop();
}

function initCatDragDrop() {
    var rows = document.querySelectorAll('#catTbody tr[draggable="true"]');
    rows.forEach(function (row) {
        row.addEventListener("dragstart", function (e) {
            catDragSrc = row;
            row.classList.add("dragging");
            e.dataTransfer.effectAllowed = "move";
        });
        row.addEventListener("dragend", function () {
            row.classList.remove("dragging");
            document
                .querySelectorAll("#catTbody tr")
                .forEach((r) => r.classList.remove("drag-over"));
        });
        row.addEventListener("dragover", function (e) {
            e.preventDefault();
            document
                .querySelectorAll("#catTbody tr")
                .forEach((r) => r.classList.remove("drag-over"));
            if (row !== catDragSrc) row.classList.add("drag-over");
        });
        row.addEventListener("drop", function (e) {
            e.preventDefault();
            if (catDragSrc && catDragSrc !== row) {
                var tbody = document.getElementById("catTbody");
                var rows = Array.from(tbody.querySelectorAll("tr"));
                var si = rows.indexOf(catDragSrc),
                    di = rows.indexOf(row);
                if (si < di) tbody.insertBefore(catDragSrc, row.nextSibling);
                else tbody.insertBefore(catDragSrc, row);
                saveCatOrder();
            }
            row.classList.remove("drag-over");
        });
    });
}

async function saveCatOrder() {
    var rows = document.querySelectorAll("#catTbody tr[data-cat-id]");
    var order = [];
    rows.forEach(function (r, i) {
        order.push({ id: parseInt(r.dataset.catId), orden: i });
    });
    order.forEach(function (o) {
        var c = allCats.find((c) => c.id === o.id);
        if (c) c.orden = o.orden;
    });
    var res = await fetch(API + "?action=reordenar_categorias", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            _user: authUser,
            _pass: authPass,
            orden: order,
        }),
    });
    var json = await res.json();
    if (json.ok) toast("Orden de categorías guardado");
}

async function crearCategoria() {
    var nombre = document
        .getElementById("newCatNombre")
        .value.trim()
        .toUpperCase();
    if (!nombre) {
        toast("Ingresá un nombre", "#c62828");
        return;
    }
    var res = await fetch(API + "?action=categoria_crear", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            _user: authUser,
            _pass: authPass,
            nombre,
            orden: allCats.length,
        }),
    });
    var json = await res.json();
    if (json.ok) {
        document.getElementById("newCatNombre").value = "";
        toast("Categoría creada");
        await loadCats();
        renderCatTable();
    } else toast("Error: " + (json.error || "ya existe"), "#c62828");
}
function openCatModal(id) {
    var cat = allCats.find((c) => parseInt(c.id) === parseInt(id));
    if (!cat) return;
    document.getElementById("catEditId").value = cat.id;
    document.getElementById("catEditNombre").value = cat.nombre;
    document.getElementById("catModalBg").classList.add("open");
}
function closeCatModal() {
    document.getElementById("catModalBg").classList.remove("open");
}
async function guardarCategoria() {
    var id = document.getElementById("catEditId").value;
    var nombre = document
        .getElementById("catEditNombre")
        .value.trim()
        .toUpperCase();
    var res = await fetch(API + "?action=categoria_editar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            _user: authUser,
            _pass: authPass,
            nombre,
            orden: 0,
        }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Categoría actualizada");
        closeCatModal();
        await loadCats();
        await loadProducts();
        renderCatTable();
    } else toast("Error: " + (json.error || "desconocido"), "#c62828");
}
async function eliminarCategoria(id, nombre, count) {
    if (count > 0) {
        toast("No se puede eliminar: tiene " + count + " productos", "#c62828");
        return;
    }
    if (!confirm('¿Eliminar "' + nombre + '"?')) return;
    var res = await fetch(API + "?action=categoria_eliminar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Categoría eliminada");
        await loadCats();
        renderCatTable();
    }
}

// ── PRODUCTOS ─────────────────────────────────────────────────────────────────
async function loadProducts() {
    var res = await fetch(API + "?action=productos");
    allProducts = await res.json();
    renderStats();
    renderTable(getFiltered());
}
function renderStats() {
    document.getElementById("stTotal").textContent = allProducts.length;
    document.getElementById("stDisp").textContent = allProducts.filter(
        (p) => p.estado === "DISPONIBLE",
    ).length;
    document.getElementById("stAgot").textContent = allProducts.filter(
        (p) => p.estado === "AGOTADO",
    ).length;
    document.getElementById("stCats").textContent = allCats.length;
}
function getFiltered() {
    var q = document.getElementById("srch").value.toLowerCase();
    var cat = document.getElementById("filtCat").value;
    var est = document.getElementById("filtEst").value;
    var base = sortedProducts || allProducts;
    return base.filter(
        (p) =>
            (!q ||
                p.descripcion.toLowerCase().includes(q) ||
                p.codigo.toLowerCase().includes(q)) &&
            (!cat || p.categoria === cat) &&
            (!est || p.estado === est),
    );
}
function filterTable() {
    renderTable(getFiltered());
}
function fmt(v) {
    return v ? "$ " + Math.round(parseFloat(v)).toLocaleString("es-AR") : "—";
}
function fmtInput(v) {
    return v ? Math.round(parseFloat(v)) : "";
}
function getImgUrl(p) {
    if (p.foto && p.foto.startsWith("http")) return p.foto;
    if (p.foto) return "../" + p.foto;
    return "../imgs/" + p.codigo.replace(/\//g, "_") + ".jpeg";
}
function esc(s) {
    return String(s || "")
        .replace(/"/g, "&quot;")
        .replace(/</g, "&lt;");
}

function renderTable(list) {
    renderTableFromList(list);
}

function col(key) {
    return visibleCols[key] !== false;
}

function renderTableHeader() {
    var h = "<thead><tr>";
    if (col("handle")) h += "<th></th>";
    if (col("img")) h += "<th>Img</th>";
    if (col("codigo")) h += "<th>Código</th>";
    if (col("desc")) h += "<th>Descripción</th>";
    if (col("cat")) h += "<th>Categoría</th>";
    if (col("may")) h += "<th>Mayorista</th>";
    if (col("pvp")) h += "<th>PVP</th>";
    if (col("estado")) h += "<th>Estado</th>";
    if (col("multiplo")) h += "<th>Múltiplo</th>";
    if (col("colores")) h += "<th>Colores</th>";
    if (col("acciones")) h += "<th>Acciones</th>";
    h += "</tr></thead>";
    document.querySelector("#mainTable thead") &&
        (document.querySelector("#mainTable thead").outerHTML = h);
}

function renderTableFromList(list) {
    renderTableHeader();
    var html = "";
    list.forEach(function (p) {
        var imgUrl = getImgUrl(p);
        var multiplo = p.multiplo || 1;
        var colores = p.colores || [];
        var colspan = COLS.filter(function (c) {
            return visibleCols[c.key] !== false;
        }).length;
        html +=
            '<tr draggable="' +
            (editMode ? "true" : "false") +
            '" data-id="' +
            p.id +
            '" data-orden="' +
            (p.orden || 0) +
            '">';
        if (col("handle"))
            html +=
                "<td>" +
                (editMode ? '<span class="drag-handle">⠿</span>' : "") +
                "</td>";
        if (col("img"))
            html +=
                '<td><img class="thumb" src="' +
                imgUrl +
                '" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'"><div class="thumb-ph" style="display:none">📦</div></td>';
        if (editMode) {
            if (col("codigo"))
                html +=
                    '<td class="editing"><input class="inline-input" value="' +
                    esc(p.codigo) +
                    '" data-field="codigo" data-id="' +
                    p.id +
                    '" style="width:90px"></td>';
            if (col("desc"))
                html +=
                    '<td class="editing"><input class="inline-input" value="' +
                    esc(p.descripcion) +
                    '" data-field="descripcion" data-id="' +
                    p.id +
                    '" style="width:180px"></td>';
            if (col("cat"))
                html +=
                    '<td class="editing"><select class="inline-select" data-field="categoria" data-id="' +
                    p.id +
                    '">' +
                    allCats
                        .map(
                            (c) =>
                                '<option value="' +
                                c.nombre +
                                '"' +
                                (c.nombre === p.categoria ? " selected" : "") +
                                ">" +
                                c.nombre +
                                "</option>",
                        )
                        .join("") +
                    "</select></td>";
            if (col("may"))
                html +=
                    '<td class="editing"><input class="inline-input" type="number" value="' +
                    fmtInput(p.precio_mayorista) +
                    '" data-field="precio_mayorista" data-id="' +
                    p.id +
                    '" style="width:90px"></td>';
            if (col("pvp"))
                html +=
                    '<td class="editing"><input class="inline-input" type="number" value="' +
                    fmtInput(p.pvp) +
                    '" data-field="pvp" data-id="' +
                    p.id +
                    '" style="width:90px"></td>';
            if (col("estado"))
                html +=
                    '<td class="editing"><select class="inline-select" data-field="estado" data-id="' +
                    p.id +
                    '"><option' +
                    (p.estado === "DISPONIBLE" ? " selected" : "") +
                    ">DISPONIBLE</option><option" +
                    (p.estado === "AGOTADO" ? " selected" : "") +
                    ">AGOTADO</option></select></td>";
            if (col("multiplo"))
                html +=
                    '<td class="editing"><input class="inline-input" type="number" value="' +
                    multiplo +
                    '" data-field="multiplo" data-id="' +
                    p.id +
                    '" style="width:60px" min="1"></td>';
            if (col("colores"))
                html +=
                    '<td style="color:var(--muted);font-size:11px">' +
                    (colores.length
                        ? colores
                              .map(function (c) {
                                  return (
                                      '<span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:' +
                                      c.hex +
                                      ';border:1px solid rgba(0,0,0,.15);margin-right:2px" title="' +
                                      c.nombre +
                                      '"></span>'
                                  );
                              })
                              .join("")
                        : "—") +
                    "</td>";
            if (col("acciones"))
                html +=
                    '<td><button class="inline-save" onclick="saveInline(' +
                    p.id +
                    ')">💾</button></td>';
        } else {
            if (col("codigo")) html += "<td><code>" + p.codigo + "</code></td>";
            if (col("desc")) html += "<td>" + p.descripcion + "</td>";
            if (col("cat")) html += "<td>" + p.categoria + "</td>";
            if (col("may"))
                html +=
                    '<td style="font-weight:800;color:var(--blue)">' +
                    fmt(p.precio_mayorista) +
                    "</td>";
            if (col("pvp")) html += "<td>" + fmt(p.pvp) + "</td>";
            if (col("estado"))
                html +=
                    '<td><span class="badge-' +
                    (p.estado === "DISPONIBLE" ? "disp" : "agot") +
                    '">' +
                    p.estado +
                    "</span></td>";
            if (col("multiplo"))
                html +=
                    '<td style="color:var(--muted);font-size:12px">×' +
                    multiplo +
                    "</td>";
            if (col("colores"))
                html +=
                    "<td>" +
                    (colores.length
                        ? colores
                              .map(function (c) {
                                  return (
                                      '<span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:' +
                                      c.hex +
                                      ';border:1px solid rgba(0,0,0,.15);margin-right:2px" title="' +
                                      c.nombre +
                                      '"></span>'
                                  );
                              })
                              .join("")
                        : '<span style="color:#ccc">—</span>') +
                    "</td>";
            if (col("acciones")) {
                html +=
                    '<td><div class="actions"><button class="btn btn-edit" onclick="editProduct(' +
                    p.id +
                    ')">✏ Editar</button>';
                html +=
                    '<button class="btn btn-danger" onclick="deleteProduct(' +
                    p.id +
                    ",'" +
                    p.descripcion.replace(/'/g, "") +
                    "')\">🗑</button></div></td>";
            }
        }
        html += "</tr>";
    });
    document.getElementById("tbody").innerHTML =
        html ||
        '<tr><td colspan="11" style="text-align:center;color:#aaa;padding:30px">No hay productos</td></tr>';
    if (editMode) initDragDrop();
}

// ── GUARDAR TODO ──────────────────────────────────────────────────────────────
async function saveAllInline() {
    var rows = document.querySelectorAll("#tbody tr[data-id]");
    if (!rows.length) return;
    var btn = document.querySelector("#editModeBar .btn-primary");
    btn.disabled = true;
    btn.textContent = "Guardando...";
    var errors = 0;
    for (var row of rows) {
        var id = parseInt(row.dataset.id);
        var p = allProducts.find((p) => p.id === id);
        if (!p) continue;
        var data = { _user: authUser, _pass: authPass, orden: p.orden || 0 };
        row.querySelectorAll("[data-field]").forEach(function (el) {
            data[el.dataset.field] = el.value;
        });
        if (
            !data.codigo ||
            !data.descripcion ||
            !data.categoria ||
            !data.precio_mayorista
        ) {
            errors++;
            continue;
        }
        var res = await fetch(API + "?action=editar&id=" + id, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data),
        });
        var json = await res.json();
        if (!json.ok) errors++;
    }
    btn.disabled = false;
    btn.textContent = "💾 Guardar todo";
    if (errors === 0) {
        toast("Todos los cambios guardados");
        await loadProducts();
    } else toast(errors + " error(s) al guardar", "#c62828");
}

// ── INLINE SAVE ───────────────────────────────────────────────────────────────
async function saveInline(id) {
    var p = allProducts.find((p) => p.id === id);
    if (!p) return;
    var row = document.querySelector('tr[data-id="' + id + '"]');
    if (!row) return;
    var data = { _user: authUser, _pass: authPass, orden: p.orden || 0 };
    row.querySelectorAll("[data-field]").forEach(function (el) {
        data[el.dataset.field] = el.value;
    });
    if (
        !data.codigo ||
        !data.descripcion ||
        !data.categoria ||
        !data.precio_mayorista
    ) {
        toast("Completá todos los campos", "#c62828");
        return;
    }
    var res = await fetch(API + "?action=editar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Guardado");
        await loadProducts();
    } else toast("Error: " + (json.error || "desconocido"), "#c62828");
}

// ── DRAG & DROP PRODUCTOS ─────────────────────────────────────────────────────
function initDragDrop() {
    var rows = document.querySelectorAll('#tbody tr[draggable="true"]');
    rows.forEach(function (row) {
        // Solo arrastrar desde el handle (primera celda)
        var handle = row.querySelector(".drag-handle");
        if (handle) {
            handle.addEventListener("mousedown", function () {
                row.draggable = true;
            });
            row.addEventListener("dragend", function () {
                row.draggable = false;
            });
        }
        row.draggable = false; // deshabilitado por defecto, se activa solo desde el handle

        row.addEventListener("dragstart", function (e) {
            dragSrc = row;
            row.classList.add("dragging");
            e.dataTransfer.effectAllowed = "move";
        });
        row.addEventListener("dragend", function () {
            row.classList.remove("dragging");
            document
                .querySelectorAll("#tbody tr")
                .forEach((r) => r.classList.remove("drag-over"));
        });
        row.addEventListener("dragover", function (e) {
            e.preventDefault();
            document
                .querySelectorAll("#tbody tr")
                .forEach((r) => r.classList.remove("drag-over"));
            if (row !== dragSrc) row.classList.add("drag-over");
        });
        row.addEventListener("drop", function (e) {
            e.preventDefault();
            if (dragSrc && dragSrc !== row) {
                var tbody = document.getElementById("tbody");
                var rows = Array.from(tbody.querySelectorAll("tr"));
                var si = rows.indexOf(dragSrc),
                    di = rows.indexOf(row);
                if (si < di) tbody.insertBefore(dragSrc, row.nextSibling);
                else tbody.insertBefore(dragSrc, row);
                saveOrder();
            }
            row.classList.remove("drag-over");
        });
    });

    // Deshabilitar scroll del mouse en inputs numéricos
    document
        .querySelectorAll('#tbody input[type="number"]')
        .forEach(function (inp) {
            inp.addEventListener(
                "wheel",
                function (e) {
                    e.preventDefault();
                },
                { passive: false },
            );
        });
}
async function saveOrder() {
    var rows = document.querySelectorAll("#tbody tr[data-id]");
    var order = [];
    rows.forEach(function (r, i) {
        order.push({ id: parseInt(r.dataset.id), orden: i });
    });
    order.forEach(function (o) {
        var p = allProducts.find((p) => p.id === o.id);
        if (p) p.orden = o.orden;
    });
    var res = await fetch(API + "?action=reordenar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            _user: authUser,
            _pass: authPass,
            orden: order,
        }),
    });
    var json = await res.json();
    if (json.ok) toast("Orden guardado");
}

// ── MODAL PRODUCTO ────────────────────────────────────────────────────────────
function openModal(p) {
    pendingFile = null;
    codigoOk = !!p;
    document.getElementById("modalTitle").textContent = p
        ? "Editar producto"
        : "Nuevo producto";
    document.getElementById("fId").value = p ? p.id : "";
    document.getElementById("fFotoActual").value = p ? p.foto || "" : "";
    document.getElementById("fCodigo").value = p ? p.codigo : "";
    document.getElementById("fCodigo").className = "";
    document.getElementById("codigoHint").textContent = p
        ? "✓ Código existente"
        : "";
    document.getElementById("codigoHint").className = p
        ? "field-hint ok"
        : "field-hint";
    renderCatSelector();
    setTimeout(function () {
        document.getElementById("fCategoria").value = p ? p.categoria : "";
    }, 0);
    document.getElementById("fDesc").value = p ? p.descripcion : "";
    document.getElementById("fMay").value = p
        ? Math.round(p.precio_mayorista)
        : "";
    document.getElementById("fPvp").value = p
        ? p.pvp
            ? Math.round(p.pvp)
            : ""
        : "";
    document.getElementById("fEstado").value = p ? p.estado : "DISPONIBLE";
    document.getElementById("fMultiplo").value = p ? p.multiplo || 1 : 1;
    // Colores
    renderColorSelector();
    var productColores = p
        ? (p.colores || []).map(function (c) {
              return c.id;
          })
        : [];
    setTimeout(function () {
        document
            .querySelectorAll("#fColores input[type=checkbox]")
            .forEach(function (cb) {
                cb.checked = productColores.indexOf(parseInt(cb.value)) >= 0;
            });
    }, 0);
    document.getElementById("fImagen").value = "";
    document.getElementById("imgPreview").classList.remove("show");
    var cur = document.getElementById("imgCurrent");
    if (p) {
        cur.src = getImgUrl(p);
        cur.style.display = "block";
        document.getElementById("imgLabelText").innerHTML =
            "📷 Cambiar imagen<br><small>JPG, PNG o WebP — máx. 5MB</small>";
    } else {
        cur.style.display = "none";
        document.getElementById("imgLabelText").innerHTML =
            "📷 Hacé clic o arrastrá una imagen<br><small>JPG, PNG o WebP — máx. 5MB</small>";
    }
    document.getElementById("modalBg").classList.add("open");
}
function closeModal() {
    document.getElementById("modalBg").classList.remove("open");
}
function editProduct(id) {
    openModal(allProducts.find((p) => p.id === id));
}

function previewImg(input) {
    if (!input.files || !input.files[0]) return;
    pendingFile = input.files[0];
    var reader = new FileReader();
    reader.onload = function (e) {
        var prev = document.getElementById("imgPreview");
        prev.src = e.target.result;
        prev.classList.add("show");
        document.getElementById("imgLabelText").textContent =
            "✓ " + pendingFile.name;
        document.getElementById("imgCurrent").style.display = "none";
    };
    reader.readAsDataURL(pendingFile);
}

function checkCodigo(input) {
    var codigo = input.value.trim();
    var hint = document.getElementById("codigoHint");
    var currentId = document.getElementById("fId").value;
    var editingProduct = currentId
        ? allProducts.find((p) => p.id == currentId)
        : null;
    if (editingProduct && editingProduct.codigo === codigo) {
        hint.textContent = "✓ Código existente";
        hint.className = "field-hint ok";
        input.className = "ok";
        codigoOk = true;
        return;
    }
    clearTimeout(checkTimeout);
    if (!codigo) {
        hint.textContent = "";
        hint.className = "field-hint";
        input.className = "";
        codigoOk = false;
        return;
    }
    hint.textContent = "Verificando...";
    hint.className = "field-hint";
    checkTimeout = setTimeout(async function () {
        var res = await fetch(
            API +
                "?action=check_codigo&codigo=" +
                encodeURIComponent(codigo) +
                "&exclude_id=" +
                (currentId || 0),
        );
        var json = await res.json();
        if (json.exists) {
            hint.textContent = "✗ Este código ya existe";
            hint.className = "field-hint err";
            input.className = "err";
            codigoOk = false;
        } else {
            hint.textContent = "✓ Código disponible";
            hint.className = "field-hint ok";
            input.className = "ok";
            codigoOk = true;
        }
    }, 400);
}

async function uploadImage(codigo) {
    if (!pendingFile) return null;
    var fd = new FormData();
    fd.append("imagen", pendingFile);
    fd.append("codigo", codigo);
    fd.append("_user", authUser);
    fd.append("_pass", authPass);
    document.getElementById("uploadProgress").style.display = "block";
    document.getElementById("uploadBar").style.width = "40%";
    try {
        var res = await fetch(UPLOAD, { method: "POST", body: fd });
        document.getElementById("uploadBar").style.width = "100%";
        var json = await res.json();
        setTimeout(() => {
            document.getElementById("uploadProgress").style.display = "none";
            document.getElementById("uploadBar").style.width = "0";
        }, 400);
        if (json.ok) return json.url;
        toast("Error al subir imagen: " + json.error, "#c62828");
        return null;
    } catch (e) {
        toast("Error al subir imagen", "#c62828");
        return null;
    }
}

async function saveProduct() {
    if (!codigoOk) {
        toast("Verificá el código del producto", "#c62828");
        return;
    }
    var id = document.getElementById("fId").value;
    var codigo = document.getElementById("fCodigo").value.trim();
    var descripcion = document.getElementById("fDesc").value.trim();
    var categoria = document.getElementById("fCategoria").value;
    var may = document.getElementById("fMay").value;
    var pvp = document.getElementById("fPvp").value;
    var multiplo = Math.max(
        1,
        parseInt(document.getElementById("fMultiplo").value) || 1,
    );
    if (!codigo || !descripcion || !categoria || !may || !pvp) {
        toast("Todos los campos son obligatorios", "#c62828");
        return;
    }
    // Colores seleccionados
    var colores = [];
    document
        .querySelectorAll("#fColores input[type=checkbox]:checked")
        .forEach(function (cb) {
            colores.push(parseInt(cb.value));
        });
    var btn = document.getElementById("btnGuardar");
    btn.disabled = true;
    btn.textContent = "Guardando...";
    var fotoUrl = document.getElementById("fFotoActual").value || null;
    if (pendingFile) {
        var up = await uploadImage(codigo);
        if (up) fotoUrl = up;
    }
    if (!fotoUrl && !id) {
        fotoUrl = "imgs/" + codigo.replace(/\//g, "_") + ".jpeg";
    }
    var orden = id
        ? (
              allProducts.find(function (p) {
                  return p.id == id;
              }) || {}
          ).orden || 0
        : 0;
    var data = {
        _user: authUser,
        _pass: authPass,
        codigo,
        descripcion,
        categoria,
        precio_mayorista: parseFloat(may) || 0,
        pvp: parseFloat(pvp) || null,
        foto: fotoUrl,
        estado: document.getElementById("fEstado").value,
        orden: orden,
        multiplo,
        colores,
    };
    var res = await fetch(
        API + "?action=" + (id ? "editar&id=" + id : "producto"),
        {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data),
        },
    );
    var json = await res.json();
    btn.disabled = false;
    btn.textContent = "Guardar";
    if (json.ok) {
        toast(id ? "Producto actualizado" : "Producto creado");
        closeModal();
        await loadProducts();
    } else toast("Error: " + (json.error || "desconocido"), "#c62828");
}

async function deleteProduct(id, name) {
    if (!confirm('¿Eliminar "' + name + '"?')) return;
    var res = await fetch(API + "?action=eliminar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Producto eliminado");
        await loadProducts();
    }
}

async function importarData() {
    if (!confirm("Importar los productos del catálogo original.\n¿Continuar?"))
        return;
    var res = await fetch(API + "?action=importar", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            creds: { user: authUser, pass: authPass },
            productos: DATA_INICIAL,
        }),
    });
    var json = await res.json();
    toast("Importados: " + json.imported + " productos");
    await loadCats();
    await loadProducts();
}

function toast(msg, bg) {
    var t = document.getElementById("toast");
    t.textContent = msg;
    t.style.background = bg || "#2e7d32";
    t.classList.add("show");
    setTimeout(() => t.classList.remove("show"), 3000);
}

// ── TRANSPORTES ───────────────────────────────────────────────────────────────
var allTransportes = [];
async function loadTransportes() {
    var res = await fetch(API + "?action=transportes");
    allTransportes = await res.json();
    renderTransTable();
}
function renderTransTable() {
    var el = document.getElementById("transTbody");
    if (!el) return;
    var html = "";
    allTransportes.forEach(function (t) {
        html += "<tr><td><strong>" + t.nombre + "</strong></td>";
        html +=
            '<td><div class="actions"><button class="btn btn-edit" onclick="openTransModal(' +
            t.id +
            ')">✏ Editar</button>';
        html +=
            '<button class="btn btn-danger" onclick="eliminarTransporte(' +
            t.id +
            ",'" +
            t.nombre +
            "')\">🗑</button></div></td></tr>";
    });
    el.innerHTML =
        html ||
        '<tr><td colspan="2" style="text-align:center;color:#aaa;padding:20px">No hay transportes</td></tr>';
}
async function crearTransporte() {
    var nombre = document
        .getElementById("newTransNombre")
        .value.trim()
        .toUpperCase();
    if (!nombre) {
        toast("Ingresá un nombre", "#c62828");
        return;
    }
    var res = await fetch(API + "?action=transporte_crear", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            _user: authUser,
            _pass: authPass,
            nombre,
            orden: allTransportes.length,
        }),
    });
    var json = await res.json();
    if (json.ok) {
        document.getElementById("newTransNombre").value = "";
        toast("Transporte creado");
        await loadTransportes();
    } else toast("Error: " + (json.error || "ya existe"), "#c62828");
}
function openTransModal(id) {
    var t = allTransportes.find((x) => parseInt(x.id) === parseInt(id));
    if (!t) return;
    document.getElementById("transEditId").value = t.id;
    document.getElementById("transEditNombre").value = t.nombre;
    document.getElementById("transModalBg").classList.add("open");
}
function closeTransModal() {
    document.getElementById("transModalBg").classList.remove("open");
}
async function guardarTransporte() {
    var id = document.getElementById("transEditId").value;
    var nombre = document
        .getElementById("transEditNombre")
        .value.trim()
        .toUpperCase();
    var res = await fetch(API + "?action=transporte_editar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass, nombre }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Transporte actualizado");
        closeTransModal();
        await loadTransportes();
    } else toast("Error", "#c62828");
}
async function eliminarTransporte(id, nombre) {
    if (!confirm('¿Eliminar "' + nombre + '"?')) return;
    var res = await fetch(API + "?action=transporte_eliminar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Transporte eliminado");
        await loadTransportes();
    }
}

// ── PEDIDOS ───────────────────────────────────────────────────────────────────
var allPedidos = [];
var pedidoActual = null;

async function loadPedidos() {
    var q = document.getElementById("pedidoSrch").value;
    var est = document.getElementById("pedidoFiltEst").value;
    var url = API + "?action=pedidos";
    if (q) url += "&q=" + encodeURIComponent(q);
    if (est) url += "&estado=" + encodeURIComponent(est);
    var res = await fetch(url);
    allPedidos = await res.json();
    renderPedidosTable();
}
function filterPedidos() {
    loadPedidos();
}

var ESTADO_LABELS = {
    PENDIENTE: { label: "Pendiente", color: "#e65100", bg: "#fff3e0" },
    EN_PREPARACION: {
        label: "En preparación",
        color: "#1565c0",
        bg: "#e3f2fd",
    },
    FACTURADO: { label: "Facturado", color: "#2e7d32", bg: "#e8f5e9" },
    ENVIADO: { label: "Enviado", color: "#6a1b9a", bg: "#f3e5f5" },
};

function estadoBadge(est) {
    var e = ESTADO_LABELS[est] || { label: est, color: "#666", bg: "#f5f5f5" };
    return (
        '<span style="background:' +
        e.bg +
        ";color:" +
        e.color +
        ';padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700">' +
        e.label +
        "</span>"
    );
}

function renderPedidosTable() {
    var html = "";
    allPedidos.forEach(function (p) {
        var fecha = new Date(p.created_at).toLocaleString("es-AR", {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        });
        html += "<tr>";
        html += "<td><strong>#" + p.id + "</strong></td>";
        html +=
            '<td style="font-size:12px;white-space:nowrap">' + fecha + "</td>";
        html += "<td><strong>" + p.cliente_nombre + "</strong></td>";
        html +=
            '<td><a href="https://wa.me/' +
            p.cliente_tel +
            '" target="_blank" style="color:var(--blue);text-decoration:none">+' +
            p.cliente_tel +
            "</a></td>";
        html +=
            '<td style="font-weight:800;color:var(--blue)">' +
            fmt(p.total) +
            "</td>";
        html += "<td>" + estadoBadge(p.estado) + "</td>";
        html +=
            '<td><div class="actions"><button class="btn btn-edit" onclick="openPedidoModal(' +
            p.id +
            ')">Ver</button></div></td>';
        html += "</tr>";
    });
    document.getElementById("pedidosTbody").innerHTML =
        html ||
        '<tr><td colspan="7" style="text-align:center;color:#aaa;padding:30px">No hay pedidos</td></tr>';
}

async function openPedidoModal(id) {
    var res = await fetch(API + "?action=pedido_detalle&id=" + id);
    pedidoActual = await res.json();
    document.getElementById("pedidoModalTitle").textContent = "Pedido #" + id;
    var p = pedidoActual;
    var fecha = new Date(p.created_at).toLocaleString("es-AR");
    var html =
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">';
    html +=
        '<div><div style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-bottom:4px">Cliente</div>';
    html += "<strong>" + p.cliente_nombre + "</strong><br>";
    html +=
        '<a href="https://wa.me/' +
        p.cliente_tel +
        '" target="_blank">+' +
        p.cliente_tel +
        "</a>";
    if (p.cuit_dni) html += "<br>" + p.cuit_dni;
    if (p.email) html += "<br>" + p.email;
    html += "</div>";
    html +=
        '<div><div style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-bottom:4px">Envío</div>';
    if (p.domicilio) html += p.domicilio + "<br>";
    if (p.localidad) html += p.localidad + " (" + (p.cp || "") + ")<br>";
    if (p.provincia) html += p.provincia + "<br>";
    if (p.transporte) html += "🚚 " + p.transporte;
    html += "</div></div>";
    // Estado
    html +=
        '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:12px;background:#f8f9fb;border-radius:8px">';
    html += '<span style="font-weight:700;font-size:13px">Estado:</span>';
    html +=
        '<select id="pedidoEstadoSel" style="padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px">';
    ["PENDIENTE", "EN_PREPARACION", "FACTURADO", "ENVIADO"].forEach(
        function (est) {
            html +=
                '<option value="' +
                est +
                '"' +
                (p.estado === est ? " selected" : "") +
                ">" +
                (ESTADO_LABELS[est] ? ESTADO_LABELS[est].label : est) +
                "</option>";
        },
    );
    html += "</select>";
    html +=
        '<button class="btn btn-primary" style="padding:6px 14px;font-size:12px" onclick="cambiarEstadoPedido()">Actualizar</button>';
    html +=
        '<span style="font-size:12px;color:var(--muted)">' + fecha + "</span>";
    html += "</div>";
    // Historial estados
    if (p.historial && p.historial.length) {
        html +=
            '<div style="margin-bottom:16px"><div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Historial de estados</div>';
        p.historial.forEach(function (h) {
            var fh = new Date(h.created_at).toLocaleString("es-AR");
            html +=
                '<div style="font-size:12px;padding:4px 0;border-bottom:1px solid var(--border)">' +
                estadoBadge(h.estado) +
                ' <span style="color:var(--muted);margin-left:8px">' +
                fh +
                "</span></div>";
        });
        html += "</div>";
    }
    // Items
    html +=
        '<table style="width:100%;margin-bottom:16px"><thead><tr><th>Código</th><th>Descripción</th><th>Cant.</th><th>Precio</th><th>Subtotal</th></tr></thead><tbody>';
    p.items.forEach(function (item) {
        html +=
            "<tr><td><code>" +
            item.codigo +
            "</code></td><td>" +
            item.descripcion +
            '</td><td style="text-align:center">' +
            item.cantidad +
            "</td><td>" +
            fmt(item.precio_unitario) +
            '</td><td style="font-weight:700">' +
            fmt(item.subtotal) +
            "</td></tr>";
    });
    html += "</tbody></table>";
    html +=
        '<div style="text-align:right;font-size:18px;font-weight:800;color:var(--blue);margin-bottom:16px">TOTAL: ' +
        fmt(p.total) +
        "</div>";
    // Facturas y observaciones
    html +=
        '<div class="field"><label>Números de factura (separados por coma)</label><input id="pedidoFacturas" value="' +
        (p.facturas || "") +
        '" placeholder="FA-0001, FB-0002..."></div>';
    html +=
        '<div class="field"><label>Observaciones internas</label><textarea id="pedidoObs" rows="3" style="width:100%;padding:9px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit">' +
        (p.observaciones || "") +
        "</textarea></div>";
    document.getElementById("pedidoModalBody").innerHTML = html;
    document.getElementById("pedidoModalBg").classList.add("open");
}
function closePedidoModal() {
    document.getElementById("pedidoModalBg").classList.remove("open");
    pedidoActual = null;
}

async function cambiarEstadoPedido() {
    if (!pedidoActual) return;
    var estado = document.getElementById("pedidoEstadoSel").value;
    var res = await fetch(API + "?action=pedido_estado&id=" + pedidoActual.id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ _user: authUser, _pass: authPass, estado }),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Estado actualizado");
        pedidoActual.estado = estado;
        loadPedidos();
        openPedidoModal(pedidoActual.id);
    } else toast("Error", "#c62828");
}

async function guardarPedidoObs() {
    if (!pedidoActual) return;
    var obs = document.getElementById("pedidoObs").value;
    var facturas = document.getElementById("pedidoFacturas").value;
    var res = await fetch(
        API + "?action=pedido_actualizar&id=" + pedidoActual.id,
        {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                _user: authUser,
                _pass: authPass,
                observaciones: obs,
                facturas,
            }),
        },
    );
    var json = await res.json();
    if (json.ok) toast("Guardado");
    else toast("Error", "#c62828");
}

function imprimirPedido() {
    if (!pedidoActual) return;
    var p = pedidoActual;
    var fecha = new Date(p.created_at).toLocaleString("es-AR");
    var html = "<html><head><title>Pedido #" + p.id + "</title><style>";
    html += "body{font-family:Arial,sans-serif;padding:24px;font-size:15px}";
    html += "h1{font-size:22px;margin-bottom:4px}";
    html +=
        ".info{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;padding:14px;background:#f5f5f5;border-radius:8px;font-size:15px}";
    html += "table{width:100%;border-collapse:collapse;margin-bottom:20px}";
    html +=
        "th{background:#003087;color:#fff;padding:10px;text-align:left;font-size:14px}";
    html += "td{padding:10px;border-bottom:1px solid #eee;font-size:15px}";
    html +=
        ".check{width:22px;height:22px;border:2px solid #333;display:inline-block;margin-right:8px;vertical-align:middle}";
    html +=
        ".total{text-align:right;font-size:20px;font-weight:bold;color:#003087;margin-top:8px}";
    html +=
        ".footer{margin-top:28px;padding-top:18px;border-top:1px solid #ccc;display:grid;grid-template-columns:1fr 1fr;gap:16px}";
    html +=
        ".firma{border-top:1px solid #333;margin-top:50px;padding-top:6px;font-size:13px;color:#666}";
    html += "@media print{body{padding:10px}}";
    html +=
        ".deposit-box{display:inline-block;width:36px;height:28px;border:2px solid #333;vertical-align:middle}";
    html += "</style></head><body>";
    html += "<h1>Pedido #" + p.id + " — Travel Blue Argentina</h1>";
    html +=
        '<p style="color:#666;font-size:13px;margin-bottom:16px">Fecha: ' +
        fecha +
        "</p>";
    html += '<div class="info">';
    html +=
        "<div><strong>Cliente:</strong> " +
        p.cliente_nombre +
        "<br><strong>Tel:</strong> +" +
        p.cliente_tel;
    if (p.cuit_dni) html += "<br><strong>CUIT/DNI:</strong> " + p.cuit_dni;
    html += "</div>";
    html += "<div>";
    if (p.domicilio) html += "<strong>Envío:</strong> " + p.domicilio + "<br>";
    if (p.localidad) html += p.localidad + " (" + (p.cp || "") + ")<br>";
    if (p.provincia) html += p.provincia + "<br>";
    if (p.transporte) html += "<strong>Transporte:</strong> " + p.transporte;
    html += "</div></div>";
    html +=
        '<table><thead><tr><th>Código</th><th>Descripción</th><th style="text-align:center">Cant.</th><th style="text-align:center;width:70px">Central</th><th style="text-align:center;width:70px">Seppey</th></tr></thead><tbody>';
    p.items.forEach(function (item) {
        html +=
            "<tr><td>" + item.codigo + "</td><td>" + item.descripcion + "</td>";
        html +=
            '<td style="text-align:center;font-weight:bold">' +
            item.cantidad +
            "</td>";
        html +=
            '<td style="text-align:center"><span class="deposit-box"></span></td>';
        html +=
            '<td style="text-align:center"><span class="deposit-box"></span></td></tr>';
    });
    html += "</tbody></table>";
    html +=
        '<div class="total">TOTAL: ' +
        fmt(p.total) +
        " — " +
        p.items.length +
        " código" +
        (p.items.length !== 1 ? "s" : "") +
        " diferentes</div>";
    if (p.observaciones)
        html +=
            '<div style="margin-top:12px;padding:10px;background:#fffde7;border-radius:6px;font-size:12px"><strong>Observaciones:</strong> ' +
            p.observaciones +
            "</div>";
    html +=
        '<div class="footer"><div><p style="font-size:12px;color:#666">Preparado por:</p><div class="firma">Nombre y firma</div></div></div>';
    html += "</body></html>";
    var w = window.open("", "_blank");
    w.document.write(html);
    w.document.close();
    w.print();
}

// ── CLIENTES ──────────────────────────────────────────────────────────────────
var allClientesAdmin = [];

async function loadClientes() {
    var q = document.getElementById("clienteSrch").value;
    var url = API + "?action=clientes";
    if (q) url += "&q=" + encodeURIComponent(q);
    var res = await fetch(url);
    allClientesAdmin = await res.json();
    renderClientesTable();
}
function filterClientes() {
    loadClientes();
}

function renderClientesTable() {
    var html = "";
    allClientesAdmin.forEach(function (c) {
        html += "<tr>";
        html += "<td><strong>" + c.nombre + "</strong></td>";
        html +=
            '<td><a href="https://wa.me/' +
            c.telefono +
            '" target="_blank" style="color:var(--blue);text-decoration:none">+' +
            c.telefono +
            "</a></td>";
        html += "<td>" + (c.cuit_dni || "—") + "</td>";
        html += "<td>" + (c.localidad || "—") + "</td>";
        html +=
            '<td style="text-align:center">' + (c.total_pedidos || 0) + "</td>";
        html +=
            '<td><div class="actions"><button class="btn btn-edit" onclick="openClienteModal(' +
            c.id +
            ')">✏ Editar</button></div></td>';
        html += "</tr>";
    });
    document.getElementById("clientesTbody").innerHTML =
        html ||
        '<tr><td colspan="6" style="text-align:center;color:#aaa;padding:30px">No hay clientes</td></tr>';
}

function openClienteModal(id) {
    var c = allClientesAdmin.find(function (x) {
        return parseInt(x.id) === parseInt(id);
    });
    if (!c) return;
    document.getElementById("clienteEditId").value = c.id;
    document.getElementById("ceNombre").value = c.nombre || "";
    document.getElementById("ceCuitDni").value = c.cuit_dni || "";
    document.getElementById("ceEmail").value = c.email || "";
    document.getElementById("ceTelefono").value = c.telefono || "";
    document.getElementById("ceDomicilio").value = c.domicilio || "";
    document.getElementById("ceLocalidad").value = c.localidad || "";
    document.getElementById("ceCP").value = c.cp || "";
    document.getElementById("ceProvincia").value = c.provincia || "";
    document.getElementById("ceTransporte").value = c.transporte || "";
    document.getElementById("ceNotas").value = c.notas || "";
    document.getElementById("clienteModalBg").classList.add("open");
}
function closeClienteModal() {
    document.getElementById("clienteModalBg").classList.remove("open");
}

async function guardarClienteEdit() {
    var id = document.getElementById("clienteEditId").value;
    var data = {
        _user: authUser,
        _pass: authPass,
        nombre: document.getElementById("ceNombre").value.trim(),
        cuit_dni: document.getElementById("ceCuitDni").value.trim(),
        email: document.getElementById("ceEmail").value.trim(),
        domicilio: document.getElementById("ceDomicilio").value.trim(),
        localidad: document.getElementById("ceLocalidad").value.trim(),
        cp: document.getElementById("ceCP").value.trim(),
        provincia: document.getElementById("ceProvincia").value.trim(),
        transporte: document.getElementById("ceTransporte").value.trim(),
        notas: document.getElementById("ceNotas").value.trim(),
    };
    var res = await fetch(API + "?action=cliente_editar&id=" + id, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
    });
    var json = await res.json();
    if (json.ok) {
        toast("Cliente actualizado");
        closeClienteModal();
        loadClientes();
    } else toast("Error", "#c62828");
}
