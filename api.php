<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

// ── Autenticación admin ──────────────────────────────────────────────────────
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'cindy2120'); // cambiá esto

function checkAuth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    if (!$auth) { http_response_code(401); die(json_encode(['error' => 'No autorizado'])); }
    $token = base64_decode(str_replace('Basic ', '', $auth));
    [$u, $p] = explode(':', $token, 2);
    if ($u !== ADMIN_USER || $p !== ADMIN_PASS) {
        http_response_code(401); die(json_encode(['error' => 'Credenciales inválidas']));
    }
}

// ── Setup: crear tabla si no existe ─────────────────────────────────────────
function setupDB($db) {
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Router ───────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$db = getDB();
setupDB($db);

switch ($action) {

    // GET /api.php?action=productos
    case 'productos':
        $cat = $_GET['categoria'] ?? '';
        $q   = $_GET['q'] ?? '';
        $sql = "SELECT * FROM productos WHERE 1=1";
        $params = []; $types = '';
        if ($cat) { $sql .= " AND categoria = ?"; $params[] = $cat; $types .= 's'; }
        if ($q)   { $sql .= " AND (descripcion LIKE ? OR codigo LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $types .= 'ss'; }
        $sql .= " ORDER BY categoria, orden, descripcion";
        $stmt = $db->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        break;

    // GET /api.php?action=categorias
    case 'categorias':
        $r = $db->query("SELECT DISTINCT categoria FROM productos ORDER BY categoria");
        $cats = [];
        while ($row = $r->fetch_assoc()) $cats[] = $row['categoria'];
        echo json_encode($cats);
        break;

    // POST /api.php?action=producto (crear)
    case 'producto':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            checkAuth();
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $db->prepare("INSERT INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,foto,estado,orden) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssddssi',
                $data['codigo'], $data['descripcion'], $data['categoria'],
                $data['precio_mayorista'], $data['pvp'], $data['foto'],
                $data['estado'], $data['orden']
            );
            if ($stmt->execute()) {
                echo json_encode(['ok' => true, 'id' => $db->insert_id]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => $db->error]);
            }
        }
        break;

    // PUT /api.php?action=producto&id=X (editar)
    case 'editar':
        if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'POST') {
            checkAuth();
            $id   = intval($_GET['id'] ?? 0);
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $db->prepare("UPDATE productos SET codigo=?,descripcion=?,categoria=?,precio_mayorista=?,pvp=?,foto=?,estado=?,orden=? WHERE id=?");
            $stmt->bind_param('sssddssi i',
                $data['codigo'], $data['descripcion'], $data['categoria'],
                $data['precio_mayorista'], $data['pvp'], $data['foto'],
                $data['estado'], $data['orden'], $id
            );
            if ($stmt->execute()) {
                echo json_encode(['ok' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => $db->error]);
            }
        }
        break;

    // DELETE /api.php?action=eliminar&id=X
    case 'eliminar':
        checkAuth();
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM productos WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        echo json_encode(['ok' => true, 'affected' => $stmt->affected_rows]);
        break;

    // POST /api.php?action=importar (importa el DATA[] inicial)
    case 'importar':
        checkAuth();
        $data = json_decode(file_get_contents('php://input'), true);
        $imported = 0; $errors = [];
        foreach ($data as $p) {
            $pvp = isset($p['PVP']) && $p['PVP'] !== '' ? floatval($p['PVP']) : null;
            $foto = $p['FOTO'] ?? null;
            $orden = 0;
            $stmt = $db->prepare("INSERT IGNORE INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,foto,estado,orden) VALUES (?,?,?,?,?,?,?,?)");
            $estado = strtoupper($p['ESTADO'] ?? 'DISPONIBLE');
            $stmt->bind_param('sssddssi',
                $p['CODIGO'], $p['DESCRIPCION'], $p['CATEGORIA'],
                $p['PRECIO_MAYORISTA'], $pvp, $foto, $estado, $orden
            );
            if ($stmt->execute()) $imported++;
            else $errors[] = $p['CODIGO'];
        }
        echo json_encode(['ok' => true, 'imported' => $imported, 'errors' => $errors]);
        break;

    // GET /api.php?action=login (verificar credenciales)
    case 'login':
        checkAuth();
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Acción no encontrada']);
}

$db->close();
?>
