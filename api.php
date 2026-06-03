<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db.php';

define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'travelblue2025');

function checkAuth($data) {
    global $db;
    $u = $data['_user'] ?? '';
    $p = $data['_pass'] ?? '';
    $r = $db->query("SELECT valor FROM config WHERE clave='admin_pass' LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    $validPass = $row ? $row['valor'] : ADMIN_PASS;
    if ($u !== ADMIN_USER || $p !== $validPass) {
        http_response_code(401);
        die(json_encode(['error' => 'No autorizado']));
    }
}

function setupDB($db) {
    $db->query("CREATE TABLE IF NOT EXISTS categorias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL UNIQUE,
        orden INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(50) NOT NULL UNIQUE,
        descripcion VARCHAR(255) NOT NULL,
        categoria VARCHAR(100) NOT NULL,
        precio_mayorista DECIMAL(12,2) NOT NULL DEFAULT 0,
        pvp DECIMAL(12,2) DEFAULT NULL,
        foto VARCHAR(500) DEFAULT NULL,
        estado ENUM('DISPONIBLE','AGOTADO') NOT NULL DEFAULT 'DISPONIBLE',
        orden INT DEFAULT 0,
        multiplo INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $colCheck = $db->query("SHOW COLUMNS FROM productos LIKE 'multiplo'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $db->query("ALTER TABLE productos ADD COLUMN multiplo INT DEFAULT 1");
    }

    $db->query("CREATE TABLE IF NOT EXISTS config (
        clave VARCHAR(50) PRIMARY KEY,
        valor VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS colores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL UNIQUE,
        hex VARCHAR(7) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS producto_colores (
        producto_id INT NOT NULL,
        color_id INT NOT NULL,
        PRIMARY KEY (producto_id, color_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("INSERT IGNORE INTO config (clave, valor) VALUES ('whatsapp', '5493535697188')");

    $db->query("CREATE TABLE IF NOT EXISTS transportes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL UNIQUE,
        orden INT DEFAULT 0,
        activo TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telefono VARCHAR(20) NOT NULL UNIQUE,
        nombre VARCHAR(255) NOT NULL,
        cuit_dni VARCHAR(20) DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        domicilio VARCHAR(255) DEFAULT NULL,
        localidad VARCHAR(100) DEFAULT NULL,
        cp VARCHAR(10) DEFAULT NULL,
        provincia VARCHAR(100) DEFAULT NULL,
        transporte VARCHAR(100) DEFAULT NULL,
        notas TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS pedidos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        estado ENUM('PENDIENTE','EN_PREPARACION','FACTURADO','ENVIADO') NOT NULL DEFAULT 'PENDIENTE',
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        observaciones TEXT DEFAULT NULL,
        facturas VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS pedido_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id INT NOT NULL,
        codigo VARCHAR(50) NOT NULL,
        descripcion VARCHAR(255) NOT NULL,
        cantidad INT NOT NULL DEFAULT 1,
        precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS pedido_estados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id INT NOT NULL,
        estado VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function normalizarTel($tel) {
    // Eliminar todo excepto dígitos
    $tel = preg_replace('/[^0-9]/', '', $tel);
    // Quitar prefijo 54 si ya está
    if (substr($tel, 0, 2) === '54') $tel = substr($tel, 2);
    // Quitar 0 inicial (característica con 0)
    if (substr($tel, 0, 1) === '0') $tel = substr($tel, 1);
    // Quitar 15 después de la característica (3 dígitos)
    if (strlen($tel) > 10 && substr($tel, 3, 2) === '15') $tel = substr($tel, 0, 3) . substr($tel, 5);
    // Guardar siempre con prefijo 54
    return '54' . $tel;
}

$action = $_GET['action'] ?? '';
$db = getDB();
setupDB($db);

switch ($action) {

    case 'productos':
        $cat = $_GET['categoria'] ?? '';
        $q   = $_GET['q'] ?? '';
        $sql = "SELECT p.*, COALESCE(c.orden, 0) as cat_orden FROM productos p LEFT JOIN categorias c ON p.categoria = c.nombre WHERE 1=1";
        $params = []; $types = '';
        if ($cat) { $sql .= " AND p.categoria = ?"; $params[] = $cat; $types .= 's'; }
        if ($q)   { $sql .= " AND (p.descripcion LIKE ? OR p.codigo LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $types .= 'ss'; }
        $sql .= " ORDER BY COALESCE(c.orden, 0), p.orden, p.codigo";
        $stmt = $db->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        // Agregar colores a cada producto
        foreach ($productos as &$prod) {
            $cstmt = $db->prepare("SELECT c.id, c.nombre, c.hex FROM colores c JOIN producto_colores pc ON c.id = pc.color_id WHERE pc.producto_id = ? ORDER BY c.nombre");
            $cstmt->bind_param('i', $prod['id']);
            $cstmt->execute();
            $prod['colores'] = $cstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        echo json_encode($productos);
        break;

    case 'check_codigo':
        $codigo = $_GET['codigo'] ?? '';
        $excludeId = intval($_GET['exclude_id'] ?? 0);
        $stmt = $db->prepare("SELECT id FROM productos WHERE codigo = ? AND id != ?");
        $stmt->bind_param('si', $codigo, $excludeId);
        $stmt->execute();
        echo json_encode(['exists' => $stmt->get_result()->num_rows > 0]);
        break;

    case 'cambiar_password':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $nueva = trim($data['nueva'] ?? '');
        if (strlen($nueva) < 6) { http_response_code(400); die(json_encode(['error' => 'Contraseña muy corta'])); }
        $stmt = $db->prepare("INSERT INTO config (clave, valor) VALUES ('admin_pass', ?) ON DUPLICATE KEY UPDATE valor=?");
        $stmt->bind_param('ss', $nueva, $nueva);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $u = $data['user'] ?? '';
        $p = $data['pass'] ?? '';
        $r = $db->query("SELECT valor FROM config WHERE clave='admin_pass' LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        $validPass = $row ? $row['valor'] : ADMIN_PASS;
        if ($u === ADMIN_USER && $p === $validPass) echo json_encode(['ok' => true]);
        else { http_response_code(401); echo json_encode(['error' => 'Credenciales inválidas']); }
        break;

    case 'producto':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $pvp = isset($data['pvp']) && $data['pvp'] !== '' ? floatval($data['pvp']) : null;
        $orden = intval($data['orden'] ?? 0);
        $multiplo = max(1, intval($data['multiplo'] ?? 1));
        $stmt = $db->prepare("INSERT INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,foto,estado,orden,multiplo) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssddssii', $data['codigo'], $data['descripcion'], $data['categoria'], $data['precio_mayorista'], $pvp, $data['foto'], $data['estado'], $orden, $multiplo);
        if ($stmt->execute()) {
            $newId = $db->insert_id;
            // Guardar colores
            $colores = $data['colores'] ?? [];
            foreach ($colores as $cid) {
                $cid = intval($cid);
                $cs = $db->prepare("INSERT IGNORE INTO producto_colores (producto_id, color_id) VALUES (?,?)");
                $cs->bind_param('ii', $newId, $cid);
                $cs->execute();
            }
            echo json_encode(['ok' => true, 'id' => $newId]);
        } else { http_response_code(400); echo json_encode(['error' => $db->error]); }
        break;

    case 'editar':
        $id = intval($_GET['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $pvp = isset($data['pvp']) && $data['pvp'] !== '' ? floatval($data['pvp']) : null;
        $multiplo = max(1, intval($data['multiplo'] ?? 1));
        // Mantener el orden actual si no se pasa uno
        $ordenActual = $db->query("SELECT orden FROM productos WHERE id=$id")->fetch_assoc();
        $orden = isset($data['orden']) && $data['orden'] !== '' ? intval($data['orden']) : ($ordenActual['orden'] ?? 0);
        $stmt = $db->prepare("UPDATE productos SET codigo=?,descripcion=?,categoria=?,precio_mayorista=?,pvp=?,foto=?,estado=?,orden=?,multiplo=? WHERE id=?");
        $stmt->bind_param('sssddssiii', $data['codigo'], $data['descripcion'], $data['categoria'], $data['precio_mayorista'], $pvp, $data['foto'], $data['estado'], $orden, $multiplo, $id);
        if ($stmt->execute()) {
            // Solo actualizar colores si el campo viene en el request
            if (isset($data['colores'])) {
                $delStmt = $db->prepare("DELETE FROM producto_colores WHERE producto_id=?");
                $delStmt->bind_param('i', $id);
                $delStmt->execute();
                foreach ($data['colores'] as $cid) {
                    $cid = intval($cid);
                    $cs = $db->prepare("INSERT IGNORE INTO producto_colores (producto_id, color_id) VALUES (?,?)");
                    $cs->bind_param('ii', $id, $cid);
                    $cs->execute();
                }
            }
            echo json_encode(['ok' => true]);
        } else { http_response_code(400); echo json_encode(['error' => $db->error]); }
        break;

    case 'eliminar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $stmtFoto = $db->prepare("SELECT foto, codigo FROM productos WHERE id=?");
        $stmtFoto->bind_param('i', $id);
        $stmtFoto->execute();
        $prod = $stmtFoto->get_result()->fetch_assoc();
        $stmt = $db->prepare("DELETE FROM productos WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted_img = false;
        if ($prod) {
            $imgPath = null;
            if (!empty($prod['foto']) && strpos($prod['foto'], 'http') === false) {
                $imgPath = __DIR__ . '/' . $prod['foto'];
            } else {
                $codigo = str_replace('/', '_', $prod['codigo'] ?? '');
                $imgPath = __DIR__ . '/imgs/' . $codigo . '.jpeg';
            }
            if ($imgPath && file_exists($imgPath)) { unlink($imgPath); $deleted_img = true; }
        }
        echo json_encode(['ok' => true, 'affected' => $stmt->affected_rows, 'deleted_img' => $deleted_img]);
        break;

    case 'reordenar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        foreach ($data['orden'] ?? [] as $item) {
            $id = intval($item['id']); $o = intval($item['orden']);
            $stmt = $db->prepare("UPDATE productos SET orden=? WHERE id=?");
            $stmt->bind_param('ii', $o, $id);
            $stmt->execute();
        }
        echo json_encode(['ok' => true]);
        break;

    case 'reordenar_categorias':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        foreach ($data['orden'] ?? [] as $item) {
            $id = intval($item['id']); $o = intval($item['orden']);
            $stmt = $db->prepare("UPDATE categorias SET orden=? WHERE id=?");
            $stmt->bind_param('ii', $o, $id);
            $stmt->execute();
        }
        echo json_encode(['ok' => true]);
        break;

    case 'importar':
        $data = json_decode(file_get_contents('php://input'), true);
        $creds = $data['creds'] ?? [];
        if (($creds['user'] ?? '') !== ADMIN_USER || ($creds['pass'] ?? '') !== ADMIN_PASS) {
            http_response_code(401); die(json_encode(['error' => 'No autorizado']));
        }
        $productos = $data['productos'] ?? [];
        $imported = 0; $errors = [];
        $cats = array_unique(array_column($productos, 'CATEGORIA'));
        foreach ($cats as $i => $cat) {
            $stmt = $db->prepare("INSERT IGNORE INTO categorias (nombre, orden) VALUES (?, ?)");
            $stmt->bind_param('si', $cat, $i);
            $stmt->execute();
        }
        foreach ($productos as $p) {
            $pvp = isset($p['PVP']) && $p['PVP'] !== '' ? floatval($p['PVP']) : null;
            $foto = $p['FOTO'] ?? null; $o = 0; $multiplo = 1;
            $estado = strtoupper($p['ESTADO'] ?? 'DISPONIBLE');
            $stmt = $db->prepare("INSERT IGNORE INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,foto,estado,orden,multiplo) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssddssii', $p['CODIGO'], $p['DESCRIPCION'], $p['CATEGORIA'], $p['PRECIO_MAYORISTA'], $pvp, $foto, $estado, $o, $multiplo);
            if ($stmt->execute()) $imported++;
            else $errors[] = $p['CODIGO'];
        }
        echo json_encode(['ok' => true, 'imported' => $imported, 'errors' => $errors]);
        break;

    case 'categorias':
        $r = $db->query("SELECT * FROM categorias ORDER BY orden, nombre");
        echo json_encode($r->fetch_all(MYSQLI_ASSOC));
        break;

    case 'categoria_crear':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $nombre = strtoupper(trim($data['nombre'] ?? ''));
        $orden = intval($data['orden'] ?? 0);
        if (!$nombre) { http_response_code(400); die(json_encode(['error' => 'Nombre requerido'])); }
        $stmt = $db->prepare("INSERT INTO categorias (nombre, orden) VALUES (?, ?)");
        $stmt->bind_param('si', $nombre, $orden);
        if ($stmt->execute()) echo json_encode(['ok' => true, 'id' => $db->insert_id]);
        else { http_response_code(400); echo json_encode(['error' => 'Ya existe esa categoría']); }
        break;

    case 'categoria_editar':
        $id = intval($_GET['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $nombre = strtoupper(trim($data['nombre'] ?? ''));
        $orden = intval($data['orden'] ?? 0);
        $oldStmt = $db->prepare("SELECT nombre FROM categorias WHERE id=?");
        $oldStmt->bind_param('i', $id); $oldStmt->execute();
        $old = $oldStmt->get_result()->fetch_assoc();
        if ($old) {
            $stmt = $db->prepare("UPDATE categorias SET nombre=?, orden=? WHERE id=?");
            $stmt->bind_param('sii', $nombre, $orden, $id); $stmt->execute();
            $stmt2 = $db->prepare("UPDATE productos SET categoria=? WHERE categoria=?");
            $stmt2->bind_param('ss', $nombre, $old['nombre']); $stmt2->execute();
        }
        echo json_encode(['ok' => true]);
        break;

    case 'categoria_eliminar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $check = $db->prepare("SELECT COUNT(*) as n FROM productos p JOIN categorias c ON p.categoria=c.nombre WHERE c.id=?");
        $check->bind_param('i', $id); $check->execute();
        $row = $check->get_result()->fetch_assoc();
        if ($row['n'] > 0) { http_response_code(400); echo json_encode(['error' => 'No se puede eliminar: tiene ' . $row['n'] . ' producto(s)']); break; }
        $stmt = $db->prepare("DELETE FROM categorias WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    // ── COLORES ───────────────────────────────────────────────────────────────
    case 'colores':
        $r = $db->query("SELECT * FROM colores ORDER BY nombre");
        echo json_encode($r->fetch_all(MYSQLI_ASSOC));
        break;

    case 'color_crear':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $nombre = trim($data['nombre'] ?? '');
        $hex = trim($data['hex'] ?? '');
        if (!$nombre || !$hex) { http_response_code(400); die(json_encode(['error' => 'Nombre y hex requeridos'])); }
        $stmt = $db->prepare("INSERT INTO colores (nombre, hex) VALUES (?, ?)");
        $stmt->bind_param('ss', $nombre, $hex);
        if ($stmt->execute()) echo json_encode(['ok' => true, 'id' => $db->insert_id]);
        else { http_response_code(400); echo json_encode(['error' => 'Ya existe ese color']); }
        break;

    case 'color_editar':
        $id = intval($_GET['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $nombre = trim($data['nombre'] ?? '');
        $hex = trim($data['hex'] ?? '');
        $stmt = $db->prepare("UPDATE colores SET nombre=?, hex=? WHERE id=?");
        $stmt->bind_param('ssi', $nombre, $hex, $id);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    case 'color_eliminar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM colores WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    case 'config_get':
        $r = $db->query("SELECT clave, valor FROM config");
        $cfg = [];
        while ($row = $r->fetch_assoc()) $cfg[$row['clave']] = $row['valor'];
        echo json_encode($cfg);
        break;

    case 'config_set':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $clave = $data['clave'] ?? ''; $valor = $data['valor'] ?? '';
        if (!$clave) { http_response_code(400); die(json_encode(['error' => 'Clave requerida'])); }
        $stmt = $db->prepare("INSERT INTO config (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?");
        $stmt->bind_param('sss', $clave, $valor, $valor); $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    // ── TRANSPORTES ───────────────────────────────────────────────────────────
    case 'transportes':
        $r = $db->query("SELECT * FROM transportes WHERE activo=1 ORDER BY orden, nombre");
        echo json_encode($r->fetch_all(MYSQLI_ASSOC));
        break;

    case 'transporte_crear':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $nombre = strtoupper(trim($data['nombre'] ?? ''));
        $orden = intval($data['orden'] ?? 0);
        if (!$nombre) { http_response_code(400); die(json_encode(['error' => 'Nombre requerido'])); }
        $stmt = $db->prepare("INSERT INTO transportes (nombre, orden) VALUES (?, ?)");
        $stmt->bind_param('si', $nombre, $orden);
        if ($stmt->execute()) echo json_encode(['ok' => true, 'id' => $db->insert_id]);
        else { http_response_code(400); echo json_encode(['error' => 'Ya existe']); }
        break;

    case 'transporte_editar':
        $id = intval($_GET['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $nombre = strtoupper(trim($data['nombre'] ?? ''));
        $stmt = $db->prepare("UPDATE transportes SET nombre=? WHERE id=?");
        $stmt->bind_param('si', $nombre, $id);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    case 'transporte_eliminar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("UPDATE transportes SET activo=0 WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    // ── CLIENTES ──────────────────────────────────────────────────────────────
    case 'cliente_buscar':
        $tel = trim($_GET['telefono'] ?? '');
        $tel = normalizarTel($tel);
        $stmt = $db->prepare("SELECT * FROM clientes WHERE telefono=?");
        $stmt->bind_param('s', $tel);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) echo json_encode(['found' => true, 'cliente' => $row]);
        else echo json_encode(['found' => false]);
        break;

    case 'cliente_guardar':
        $data = json_decode(file_get_contents('php://input'), true);
        $tel = normalizarTel($data['telefono'] ?? '');
        if (!$tel) { http_response_code(400); die(json_encode(['error' => 'Teléfono requerido'])); }
        $nombre = trim($data['nombre'] ?? '');
        if (!$nombre) { http_response_code(400); die(json_encode(['error' => 'Nombre requerido'])); }
        $stmt = $db->prepare("INSERT INTO clientes (telefono,nombre,cuit_dni,email,domicilio,localidad,cp,provincia,transporte,notas)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),cuit_dni=VALUES(cuit_dni),email=VALUES(email),
            domicilio=VALUES(domicilio),localidad=VALUES(localidad),cp=VALUES(cp),
            provincia=VALUES(provincia),transporte=VALUES(transporte),notas=VALUES(notas)");
        $stmt->bind_param('ssssssssss',
            $tel, $nombre,
            $data['cuit_dni'] ?? null, $data['email'] ?? null,
            $data['domicilio'] ?? null, $data['localidad'] ?? null,
            $data['cp'] ?? null, $data['provincia'] ?? null,
            $data['transporte'] ?? null, $data['notas'] ?? null
        );
        if ($stmt->execute()) {
            $idCliente = $db->insert_id ?: $db->query("SELECT id FROM clientes WHERE telefono='$tel'")->fetch_assoc()['id'];
            echo json_encode(['ok' => true, 'id' => $idCliente, 'telefono' => $tel]);
        } else { http_response_code(400); echo json_encode(['error' => $db->error]); }
        break;

    case 'clientes':
        $data = $_GET;
        $q = $data['q'] ?? '';
        $sql = "SELECT c.*, COUNT(p.id) as total_pedidos FROM clientes c LEFT JOIN pedidos p ON p.cliente_id=c.id WHERE 1=1";
        if ($q) $sql .= " AND (c.nombre LIKE '%" . $db->real_escape_string($q) . "%' OR c.telefono LIKE '%" . $db->real_escape_string($q) . "%')";
        $sql .= " GROUP BY c.id ORDER BY c.nombre";
        echo json_encode($db->query($sql)->fetch_all(MYSQLI_ASSOC));
        break;

    case 'cliente_editar':
        $id = intval($_GET['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $stmt = $db->prepare("UPDATE clientes SET nombre=?,cuit_dni=?,email=?,domicilio=?,localidad=?,cp=?,provincia=?,transporte=?,notas=? WHERE id=?");
        $stmt->bind_param('sssssssssi',
            $data['nombre'], $data['cuit_dni'], $data['email'],
            $data['domicilio'], $data['localidad'], $data['cp'],
            $data['provincia'], $data['transporte'], $data['notas'], $id
        );
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    // ── PEDIDOS ───────────────────────────────────────────────────────────────
    case 'pedido_crear':
        $data = json_decode(file_get_contents('php://input'), true);
        $cliente_id = intval($data['cliente_id'] ?? 0);
        $total = floatval($data['total'] ?? 0);
        $items = $data['items'] ?? [];
        $obs = $data['observaciones'] ?? '';
        if (!$cliente_id || !$items) { http_response_code(400); die(json_encode(['error' => 'Datos incompletos'])); }
        $stmt = $db->prepare("INSERT INTO pedidos (cliente_id,estado,total,observaciones) VALUES (?,?,?,?)");
        $estado = 'PENDIENTE';
        $stmt->bind_param('isds', $cliente_id, $estado, $total, $obs);
        if ($stmt->execute()) {
            $pedido_id = $db->insert_id;
            foreach ($items as $item) {
                $is = $db->prepare("INSERT INTO pedido_items (pedido_id,codigo,descripcion,cantidad,precio_unitario,subtotal) VALUES (?,?,?,?,?,?)");
                $is->bind_param('issidd', $pedido_id, $item['codigo'], $item['descripcion'], $item['cantidad'], $item['precio_unitario'], $item['subtotal']);
                $is->execute();
            }
            // Registrar estado inicial
            $es = $db->prepare("INSERT INTO pedido_estados (pedido_id,estado) VALUES (?,?)");
            $es->bind_param('is', $pedido_id, $estado);
            $es->execute();
            echo json_encode(['ok' => true, 'id' => $pedido_id]);
        } else { http_response_code(400); echo json_encode(['error' => $db->error]); }
        break;

    case 'pedidos':
        $q = $_GET['q'] ?? '';
        $est = $_GET['estado'] ?? '';
        $sql = "SELECT p.*, c.nombre as cliente_nombre, c.telefono as cliente_tel
                FROM pedidos p JOIN clientes c ON p.cliente_id=c.id WHERE 1=1";
        if ($q) $sql .= " AND (c.nombre LIKE '%" . $db->real_escape_string($q) . "%' OR c.telefono LIKE '%" . $db->real_escape_string($q) . "%')";
        if ($est) $sql .= " AND p.estado='" . $db->real_escape_string($est) . "'";
        $sql .= " ORDER BY p.created_at DESC";
        echo json_encode($db->query($sql)->fetch_all(MYSQLI_ASSOC));
        break;

    case 'pedido_detalle':
        $id = intval($_GET['id'] ?? 0);
        $pedido = $db->query("SELECT p.*, c.nombre as cliente_nombre, c.telefono as cliente_tel,
            c.cuit_dni, c.email, c.domicilio, c.localidad, c.cp, c.provincia, c.transporte
            FROM pedidos p JOIN clientes c ON p.cliente_id=c.id WHERE p.id=$id")->fetch_assoc();
        if (!$pedido) { http_response_code(404); die(json_encode(['error' => 'No encontrado'])); }
        $pedido['items'] = $db->query("SELECT * FROM pedido_items WHERE pedido_id=$id")->fetch_all(MYSQLI_ASSOC);
        $pedido['historial'] = $db->query("SELECT * FROM pedido_estados WHERE pedido_id=$id ORDER BY created_at ASC")->fetch_all(MYSQLI_ASSOC);
        echo json_encode($pedido);
        break;

    case 'pedido_estado':
        $id = intval($_GET['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $estado = $data['estado'] ?? '';
        $stmt = $db->prepare("UPDATE pedidos SET estado=? WHERE id=?");
        $stmt->bind_param('si', $estado, $id);
        $stmt->execute();
        $es = $db->prepare("INSERT INTO pedido_estados (pedido_id,estado) VALUES (?,?)");
        $es->bind_param('is', $id, $estado);
        $es->execute();
        echo json_encode(['ok' => true]);
        break;

    case 'pedido_actualizar':
        $id = intval($_GET['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $obs = $data['observaciones'] ?? '';
        $facturas = $data['facturas'] ?? '';
        $stmt = $db->prepare("UPDATE pedidos SET observaciones=?,facturas=? WHERE id=?");
        $stmt->bind_param('ssi', $obs, $facturas, $id);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Acción no encontrada']);
}
$db->close();
?>