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
            document.getElementById("loginWrap").style.display = "none";
            document.getElementById("appWrap").style.display = "block";
        } else {
            localStorage.removeItem("tb_admin_user");
            localStorage.removeItem("tb_admin_pass");
        }
    } catch (e) {}
}
tryAutoLogin();

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
            '"></span> ' +
            c.nombre;
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
        orden: 0,
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
