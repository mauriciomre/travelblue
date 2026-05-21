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
    $u = $data['_user'] ?? '';
    $p = $data['_pass'] ?? '';
    if ($u !== ADMIN_USER || $p !== ADMIN_PASS) {
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

    // Agregar columna multiplo si no existe (para BDs existentes)
    $db->query("ALTER TABLE productos ADD COLUMN IF NOT EXISTS multiplo INT DEFAULT 1");

    $db->query("CREATE TABLE IF NOT EXISTS config (
        clave VARCHAR(50) PRIMARY KEY,
        valor VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("INSERT IGNORE INTO config (clave, valor) VALUES ('whatsapp', '5493535697188')");
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
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        break;

    case 'check_codigo':
        $codigo = $_GET['codigo'] ?? '';
        $excludeId = intval($_GET['exclude_id'] ?? 0);
        $stmt = $db->prepare("SELECT id FROM productos WHERE codigo = ? AND id != ?");
        $stmt->bind_param('si', $codigo, $excludeId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        echo json_encode(['exists' => $exists]);
        break;

    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $u = $data['user'] ?? '';
        $p = $data['pass'] ?? '';
        if ($u === ADMIN_USER && $p === ADMIN_PASS) echo json_encode(['ok' => true]);
        else { http_response_code(401); echo json_encode(['error' => 'Credenciales inválidas']); }
        break;

    case 'producto':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $pvp = isset($data['pvp']) && $data['pvp'] !== '' ? floatval($data['pvp']) : null;
        $orden = intval($data['orden'] ?? 0);
        $multiplo = max(1, intval($data['multiplo'] ?? 1));
        $stmt = $db->prepare("INSERT INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,foto,estado,orden,multiplo) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssddssi i', $data['codigo'], $data['descripcion'], $data['categoria'], $data['precio_mayorista'], $pvp, $data['foto'], $data['estado'], $orden, $multiplo);
        if ($stmt->execute()) echo json_encode(['ok' => true, 'id' => $db->insert_id]);
        else { http_response_code(400); echo json_encode(['error' => $db->error]); }
        break;

    case 'editar':
        $id = intval($_GET['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $pvp = isset($data['pvp']) && $data['pvp'] !== '' ? floatval($data['pvp']) : null;
        $orden = intval($data['orden'] ?? 0);
        $multiplo = max(1, intval($data['multiplo'] ?? 1));
        $stmt = $db->prepare("UPDATE productos SET codigo=?,descripcion=?,categoria=?,precio_mayorista=?,pvp=?,foto=?,estado=?,orden=?,multiplo=? WHERE id=?");
        $stmt->bind_param('sssddssii i', $data['codigo'], $data['descripcion'], $data['categoria'], $data['precio_mayorista'], $pvp, $data['foto'], $data['estado'], $orden, $multiplo, $id);
        if ($stmt->execute()) echo json_encode(['ok' => true]);
        else { http_response_code(400); echo json_encode(['error' => $db->error]); }
        break;

    case 'eliminar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        // Obtener foto antes de eliminar
        $stmtFoto = $db->prepare("SELECT foto, codigo FROM productos WHERE id=?");
        $stmtFoto->bind_param('i', $id);
        $stmtFoto->execute();
        $prod = $stmtFoto->get_result()->fetch_assoc();
        // Eliminar producto
        $stmt = $db->prepare("DELETE FROM productos WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        // Eliminar imagen si existe
        $deleted_img = false;
        if ($prod) {
            $imgPath = null;
            if (!empty($prod['foto']) && strpos($prod['foto'], 'http') === false) {
                // Ruta relativa guardada en BD (ej: imgs/33020.jpeg)
                $imgPath = __DIR__ . '/' . $prod['foto'];
            } else {
                // Fallback: ruta por código
                $codigo = str_replace('/', '_', $prod['codigo'] ?? '');
                $imgPath = __DIR__ . '/imgs/' . $codigo . '.jpeg';
            }
            if ($imgPath && file_exists($imgPath)) {
                unlink($imgPath);
                $deleted_img = true;
            }
        }
        echo json_encode(['ok' => true, 'affected' => $stmt->affected_rows, 'deleted_img' => $deleted_img]);
        break;

    case 'reordenar_categorias':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $orden = $data['orden'] ?? [];
        foreach ($orden as $item) {
            $id = intval($item['id']);
            $o  = intval($item['orden']);
            $stmt = $db->prepare("UPDATE categorias SET orden=? WHERE id=?");
            $stmt->bind_param('ii', $o, $id);
            $stmt->execute();
        }
        echo json_encode(['ok' => true]);
        break;

    case 'reordenar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $orden = $data['orden'] ?? [];
        foreach ($orden as $item) {
            $id = intval($item['id']);
            $o  = intval($item['orden']);
            $stmt = $db->prepare("UPDATE productos SET orden=? WHERE id=?");
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
        foreach ($cats as $cat) {
            $o = 0;
            $stmt = $db->prepare("INSERT IGNORE INTO categorias (nombre, orden) VALUES (?, ?)");
            $stmt->bind_param('si', $cat, $o);
            $stmt->execute();
        }
        foreach ($productos as $p) {
            $pvp = isset($p['PVP']) && $p['PVP'] !== '' ? floatval($p['PVP']) : null;
            $foto = $p['FOTO'] ?? null;
            $o = 0;
            $estado = strtoupper($p['ESTADO'] ?? 'DISPONIBLE');
            $stmt = $db->prepare("INSERT IGNORE INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,foto,estado,orden) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssddssi', $p['CODIGO'], $p['DESCRIPCION'], $p['CATEGORIA'], $p['PRECIO_MAYORISTA'], $pvp, $foto, $estado, $o);
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
        $oldStmt->bind_param('i', $id);
        $oldStmt->execute();
        $old = $oldStmt->get_result()->fetch_assoc();
        if ($old) {
            $stmt = $db->prepare("UPDATE categorias SET nombre=?, orden=? WHERE id=?");
            $stmt->bind_param('sii', $nombre, $orden, $id);
            $stmt->execute();
            $stmt2 = $db->prepare("UPDATE productos SET categoria=? WHERE categoria=?");
            $stmt2->bind_param('ss', $nombre, $old['nombre']);
            $stmt2->execute();
        }
        echo json_encode(['ok' => true]);
        break;

    case 'categoria_eliminar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $check = $db->prepare("SELECT COUNT(*) as n FROM productos p JOIN categorias c ON p.categoria=c.nombre WHERE c.id=?");
        $check->bind_param('i', $id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        if ($row['n'] > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'No se puede eliminar: tiene ' . $row['n'] . ' producto(s) asociados']);
            break;
        }
        $stmt = $db->prepare("DELETE FROM categorias WHERE id=?");
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
        $clave = $data['clave'] ?? '';
        $valor = $data['valor'] ?? '';
        if (!$clave) { http_response_code(400); die(json_encode(['error' => 'Clave requerida'])); }
        $stmt = $db->prepare("INSERT INTO config (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?");
        $stmt->bind_param('sss', $clave, $valor, $valor);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Acción no encontrada']);
}
$db->close();
?>