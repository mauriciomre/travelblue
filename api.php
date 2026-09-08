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
    if (!isAdminAuth($data)) {
        http_response_code(401);
        die(json_encode(['error' => 'No autorizado']));
    }
}

// Variante de checkAuth() que no corta la ejecución — usada para decidir qué
// devolver (ej. productos con mostrar=0) en vez de exigir login.
function isAdminAuth($data) {
    global $db;
    $u = $data['_user'] ?? '';
    $p = $data['_pass'] ?? '';
    if ($u === '' || $p === '') return false;
    $r = $db->query("SELECT valor FROM config WHERE clave='admin_pass' LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    $validPass = $row ? $row['valor'] : ADMIN_PASS;
    return $u === ADMIN_USER && hash_equals($validPass, $p);
}

// Interpreta el valor de una celda de Excel para el campo MOSTRAR (booleano) —
// mismo criterio permisivo que ya usa el proyecto hermano (cindy_preventa) para INGRESO.
function parse_mostrar_valor($v) {
    $v = strtoupper(trim(strval($v)));
    return in_array($v, ['SI', 'SÍ', 'S', 'YES', 'Y', '1', 'TRUE', 'X'], true) ? 1 : 0;
}

// El campo "foto" queda NULL en muchísimos productos reales (los que entraron
// por Excel o por Manager nunca lo tocan) aunque el archivo exista de verdad
// en imgs/<codigo>.jpeg (subido por otra vía) — mismo fallback que ya usa
// getImgUrl() en el frontend para decidir qué mostrar. Esta función replica
// ese mismo criterio para poder filtrar "con/sin foto" contra la realidad,
// no solo contra el campo de la base.
function producto_tiene_foto($codigo, $foto) {
    if (!empty($foto)) return true;
    $guessPath = __DIR__ . '/imgs/' . str_replace('/', '_', $codigo) . '.jpeg';
    return file_exists($guessPath);
}

// ── Sync con Manager2Max ─────────────────────────────────────────────────────
// Reglas de negocio confirmadas por Mauricio (Mi-Cerebro/proyectos/travelblue-catalogo-backlog.md):
define('MANAGER_MARCAS', ['TRAVEL BLUE', 'ANOMEO', 'SLOOTH']);
define('MANAGER_LISTA_MAYORISTA', 11); // "Travel Blue S"
define('MANAGER_LISTA_PVP', 1);        // "Público General" (Mauricio dijo "Público" — es la única lista con ese nombre base)

function manager_login() {
    $ch = curl_init(MANAGER_API_URL . '/Api/Login/LoginUsuarioEmpresa');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'CodigoUsuario' => MANAGER_API_USER,
        'Contraseña' => MANAGER_API_PASS,
        'IDEmpresa' => MANAGER_IDEMPRESA,
    ], JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new Exception("Login Manager falló: $err");
    $data = json_decode($resp, true);
    if (empty($data['Token'])) throw new Exception("Login Manager sin token: " . ($data['ErrMessage'] ?? 'desconocido'));
    return $data['Token'];
}

function manager_call_raw($token, $endpoint, $body) {
    $ch = curl_init(MANAGER_API_URL . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8', 'Authorization: Bearer ' . $token]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new Exception("Manager $endpoint falló: $err");
    $data = json_decode($resp, true);
    if (($data['ErrCode'] ?? null) !== 200) throw new Exception("Manager $endpoint error: " . ($data['ErrMessage'] ?? 'desconocido'));
    return $data['Data'] ?? [];
}

// GetDTxxx (listados con paginación) — la mayoría de los endpoints de Manager.
function manager_call($token, $endpoint, $body) {
    $data = manager_call_raw($token, $endpoint, $body);
    return $data['DT']['data'] ?? [];
}

function manager_filtro_texto($valor) {
    return [
        '$type' => 'UpSoft.Framework.Data.Filters.FilterText, UpSoft.Framework.Data',
        'Criteria' => 0,
        'Value' => $valor,
        'ArrayValue' => ['$type' => 'System.Collections.Generic.List`1[[System.String, mscorlib]], mscorlib', '$values' => ['']],
        'FieldName' => '',
        'ActiveFilter' => true,
    ];
}
function manager_filtro_numero($valor) {
    return [
        '$type' => 'UpSoft.Framework.Data.Filters.FilterNumber, UpSoft.Framework.Data',
        'Criteria' => 0, 'Value1' => $valor, 'Value2' => 0.0, 'FieldName' => '', 'ActiveFilter' => true,
    ];
}
function manager_dict_filtros($filtros) {
    return array_merge([
        '$type' => 'System.Collections.Generic.Dictionary`2[[System.String, mscorlib],[UpSoft.Framework.Data.Filters.BaseFilter, UpSoft.Framework.Data]], mscorlib',
    ], $filtros);
}

// Categoría del catálogo a partir de Marca/Rubro de Manager — regla confirmada 27/08/2026
function manager_categoria($marca, $rubro) {
    if (strtoupper(trim($marca)) !== 'TRAVEL BLUE') return strtoupper(trim($marca));
    $rubro = strtoupper(trim($rubro));
    if ($rubro === 'MOCHILAS') return 'MOCHILAS';
    if ($rubro === 'EQUIPAJES') return 'VALIJAS';
    return 'ACCESORIOS';
}

// Saca solo la mención literal de la marca de la descripción — regla confirmada 27/08/2026
function manager_limpiar_descripcion($desc, $marca) {
    $desc = trim($desc);
    $desc = preg_replace('/\s*' . preg_quote($marca, '/') . '\s*/i', ' ', $desc);
    return trim(preg_replace('/\s+/', ' ', $desc));
}

// Trae y transforma los artículos de una marca: descripción, categoría, estado
// (desde Sube) + precio mayorista/PVP de las 2 listas relevantes. Devuelve
// codigo => datos ya listos para comparar/aplicar contra la tabla productos.
// Trae la foto principal (Orden=1) de un artículo desde Manager, la procesa
// con el mismo pipeline que upload.php (800x800, fondo blanco, JPEG 85%) y la
// guarda en imgs/<codigo>.jpeg. Devuelve la ruta relativa o null si no hay
// foto / falla — nunca debe frenar la creación del producto por esto.
// Solo se usa para productos NUEVOS — nunca pisa la foto de uno ya existente.
function manager_fetch_foto($token, $codigo) {
    try {
        $imgs = manager_call($token, '/Api/ECommerce/GetDTArticulosImagenes', [
            'DTRequest' => ['draw' => 1, 'order' => [], 'start' => 0, 'length' => 50],
            'DefinicionTablaFiltros' => false,
            'CalculaTotales' => false,
            'ListFilters' => manager_dict_filtros(['CodigoArticulo' => manager_filtro_texto($codigo)]),
        ]);
        $principal = null;
        foreach ($imgs as $img) {
            if (intval($img['Orden'] ?? 0) === 1) { $principal = $img; break; }
        }
        if (!$principal || empty($principal['PasoImagen'])) return null;

        $imgData = manager_call_raw($token, '/Api/Image/GetImage', ['ImageFullPath' => $principal['PasoImagen']]);
        if (empty($imgData['ImageContent'])) return null;

        $binario = base64_decode($imgData['ImageContent']);
        $src = @imagecreatefromstring($binario);
        if (!$src) return null;

        $ow = imagesx($src); $oh = imagesy($src);
        $ratio = min(800 / $ow, 800 / $oh);
        $nw = intval($ow * $ratio); $nh = intval($oh * $ratio);
        $ox = intval((800 - $nw) / 2); $oy = intval((800 - $nh) / 2);
        $dst = imagecreatetruecolor(800, 800);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, $ox, $oy, 0, 0, $nw, $nh, $ow, $oh);

        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $codigo) . '.jpeg';
        $dir = __DIR__ . '/imgs/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $guardado = imagejpeg($dst, $dir . $filename, 85);
        imagedestroy($src);
        imagedestroy($dst);

        return $guardado ? 'imgs/' . $filename : null;
    } catch (Exception $e) {
        return null;
    }
}

function manager_fetch_marca($token, $marca) {
    $articulos = manager_call($token, '/Api/articulo/GetDTArticulos', [
        'DTRequest' => ['draw' => 1, 'order' => [], 'start' => 0, 'length' => 5000],
        'DefinicionTablaFiltros' => false,
        'CalculaTotales' => false,
        'ListFilters' => manager_dict_filtros(['Marca' => manager_filtro_texto($marca)]),
    ]);

    $items = [];
    foreach ($articulos as $a) {
        $codigo = trim($a['CodigoArticulo'] ?? '');
        if ($codigo === '') continue;
        $items[$codigo] = [
            'codigo' => $codigo,
            'descripcion' => manager_limpiar_descripcion($a['Descripcion'] ?? '', $marca),
            'categoria' => manager_categoria($marca, $a['Rubro'] ?? ''),
            'estado' => !empty($a['Sube']) ? 'DISPONIBLE' : 'AGOTADO',
            'codigo_barras' => trim($a['CodigoAuxiliar'] ?? '') ?: null,
            'marca_manager' => $marca,
            'rubro_manager' => $a['Rubro'] ?? '',
            'precio_mayorista' => null,
            'pvp' => null,
        ];
    }

    $listas = [MANAGER_LISTA_MAYORISTA => 'precio_mayorista', MANAGER_LISTA_PVP => 'pvp'];
    foreach ($listas as $idLista => $campo) {
        $precios = manager_call($token, '/Api/articulo/GetDTArticulosPrecioExistencia', [
            'DTRequest' => ['draw' => 1, 'order' => [], 'start' => 0, 'length' => 5000],
            'DefinicionTablaFiltros' => false,
            'CalculaTotales' => false,
            'ListFilters' => manager_dict_filtros([
                'IDListaPrecio' => manager_filtro_numero($idLista),
                'IDDeposito' => manager_filtro_numero(0),
                'IDCliente' => manager_filtro_numero(0),
                'IDProveedor' => manager_filtro_numero(0),
                'IDMonedaComprobante' => manager_filtro_numero(1),
                'FactorCotizacionMonCompMonLP' => manager_filtro_numero(1.0),
                'Marca' => manager_filtro_texto($marca),
            ]),
        ]);
        foreach ($precios as $p) {
            $codigo = trim($p['CodigoArticulo'] ?? '');
            if (isset($items[$codigo]) && isset($p['PrecioFinalLP'])) {
                $items[$codigo][$campo] = round(floatval($p['PrecioFinalLP']), 2);
            }
        }
    }

    return array_values($items);
}

// Compara los artículos ya transformados de las 3 marcas contra la tabla
// productos (por código) y arma el diff, sin escribir nada. $porMarca sale
// con ok/error por marca para no perder fallas parciales en silencio.
function manager_sync_diff($db, $token) {
    $porMarca = [];
    $actualiza = [];
    $nuevos = [];
    $sinCambios = [];
    foreach (MANAGER_MARCAS as $marca) {
        try {
            $items = manager_fetch_marca($token, $marca);
            $porMarca[$marca] = ['ok' => true, 'mensaje' => null, 'cantidad' => count($items)];
            foreach ($items as $it) {
                $chk = $db->prepare("SELECT id,descripcion,categoria,precio_mayorista,pvp,estado,codigo_barras FROM productos WHERE codigo=?");
                $chk->bind_param('s', $it['codigo']);
                $chk->execute();
                $existing = $chk->get_result()->fetch_assoc();
                if ($existing) {
                    $cambia = round(floatval($existing['precio_mayorista']), 2) !== $it['precio_mayorista']
                        || round(floatval($existing['pvp']), 2) !== $it['pvp']
                        || $existing['estado'] !== $it['estado']
                        || trim($existing['descripcion']) !== $it['descripcion']
                        || trim($existing['categoria']) !== $it['categoria'];
                    if ($cambia) {
                        $actualiza[] = array_merge($it, [
                            'id' => $existing['id'],
                            '_anterior' => [
                                'descripcion' => trim($existing['descripcion']),
                                'categoria' => trim($existing['categoria']),
                                'precio_mayorista' => round(floatval($existing['precio_mayorista']), 2),
                                'pvp' => round(floatval($existing['pvp']), 2),
                                'estado' => $existing['estado'],
                            ],
                        ]);
                    } else {
                        $sinCambios[] = array_merge($it, ['id' => $existing['id']]);
                    }
                } else {
                    $nuevos[] = $it;
                }
            }
        } catch (Exception $e) {
            $porMarca[$marca] = ['ok' => false, 'mensaje' => $e->getMessage(), 'cantidad' => 0];
        }
    }
    return ['por_marca' => $porMarca, 'actualiza' => $actualiza, 'nuevos' => $nuevos, 'sin_cambios' => $sinCambios];
}

// Aplica un diff ya calculado. $modo determina qué pasa con los productos
// nuevos: 'automatico' los crea directo, 'semiautomatico'/'manual' los deja
// en manager_sync_pendientes para aprobación.
function manager_sync_aplicar($db, $diff, $modo, $runId, $token) {
    $actualizados = 0; $nuevosCreados = 0; $nuevosPendientes = 0;

    foreach ($diff['actualiza'] as $it) {
        $prevStmt = $db->prepare("SELECT codigo,descripcion,categoria,precio_mayorista,pvp,estado,codigo_barras,mostrar FROM productos WHERE id=?");
        $prevStmt->bind_param('i', $it['id']);
        $prevStmt->execute();
        $prev = $prevStmt->get_result()->fetch_assoc();
        if ($prev) {
            $prevJson = json_encode($prev, JSON_UNESCAPED_UNICODE);
            $snap = $db->prepare("INSERT INTO import_snapshots (import_id, codigo, accion, datos_anteriores) VALUES (?,?,'updated',?)");
            $snap->bind_param('sss', $runId, $it['codigo'], $prevJson);
            $snap->execute();
        }
        $stmt = $db->prepare("UPDATE productos SET descripcion=?,categoria=?,precio_mayorista=?,pvp=?,estado=? WHERE id=?");
        $stmt->bind_param('ssddsi', $it['descripcion'], $it['categoria'], $it['precio_mayorista'], $it['pvp'], $it['estado'], $it['id']);
        if ($stmt->execute()) $actualizados++;
    }

    foreach ($diff['nuevos'] as $it) {
        // Solo semiautomático encola para aprobación — en manual, esta función
        // se llama recién DESPUÉS de que el admin ya revisó y confirmó el
        // preview (que incluye los nuevos), así que ahí ya está "aprobado".
        if ($modo !== 'semiautomatico') {
            $catStmt = $db->prepare("INSERT IGNORE INTO categorias (nombre) VALUES (?)");
            $catStmt->bind_param('s', $it['categoria']);
            $catStmt->execute();

            $snap = $db->prepare("INSERT INTO import_snapshots (import_id, codigo, accion, datos_anteriores) VALUES (?,?,'inserted',NULL)");
            $snap->bind_param('ss', $runId, $it['codigo']);
            $snap->execute();

            $foto = manager_fetch_foto($token, $it['codigo']);
            $o = 0; $multiplo = 1;
            $stmt = $db->prepare("INSERT INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,estado,orden,multiplo,codigo_barras,foto) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssddsiiss', $it['codigo'], $it['descripcion'], $it['categoria'], $it['precio_mayorista'], $it['pvp'], $it['estado'], $o, $multiplo, $it['codigo_barras'], $foto);
            if ($stmt->execute()) $nuevosCreados++;
        } else {
            $stmt = $db->prepare("INSERT INTO manager_sync_pendientes (codigo,descripcion,categoria,precio_mayorista,pvp,estado,codigo_barras,marca_manager,rubro_manager) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssddssss', $it['codigo'], $it['descripcion'], $it['categoria'], $it['precio_mayorista'], $it['pvp'], $it['estado'], $it['codigo_barras'], $it['marca_manager'], $it['rubro_manager']);
            if ($stmt->execute()) $nuevosPendientes++;
        }
    }

    foreach ($diff['por_marca'] as $marca => $r) {
        $nuevosMarca = 0;
        foreach ($diff['nuevos'] as $it) if ($it['marca_manager'] === $marca) $nuevosMarca++;
        $actualizaMarca = 0;
        foreach ($diff['actualiza'] as $it) if ($it['marca_manager'] === $marca) $actualizaMarca++;
        $log = $db->prepare("INSERT INTO manager_sync_log (run_id, marca, ok, mensaje, actualizados, nuevos) VALUES (?,?,?,?,?,?)");
        $ok = $r['ok'] ? 1 : 0;
        $log->bind_param('ssisii', $runId, $marca, $ok, $r['mensaje'], $actualizaMarca, $nuevosMarca);
        $log->execute();
    }

    return ['actualizados' => $actualizados, 'nuevos_creados' => $nuevosCreados, 'nuevos_pendientes' => $nuevosPendientes];
}

// ── Sync minorista con WooCommerce (travelblue.com.ar) ──────────────────────
// Solo marca Travel Blue (Anomeo/Slooth son exclusivos del catálogo mayorista,
// aclarado por Mauricio 07/09/2026). Reglas ya validadas hoy contra producción
// real por el tool Python travelblue_woocommerce_sync/ (mismo repo de scripts,
// carpeta hermana) antes de portarlas acá.
define('WOO_MARCA', 'TRAVEL BLUE');
define('WOO_LISTA_PRECIO', 1); // Público General
define('WOO_RUBROS_EXCLUIDOS', ['EXHIBIDORES', 'CONCEPTOS']); // mobiliario de punto de venta / conceptos de facturación, no productos vendibles

function woo_call($method, $path, $body = null, $query = null) {
    $url = WOO_STORE_URL . '/wp-json/wc/v3' . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_USERPWD, WOO_CONSUMER_KEY . ':' . WOO_CONSUMER_SECRET);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) throw new Exception("WooCommerce $method $path falló: $err");
    $data = json_decode($resp, true);
    if ($httpCode >= 400) throw new Exception("WooCommerce $method $path error $httpCode: " . ($data['message'] ?? $resp));
    return $data;
}

// Sube un binario a la biblioteca de medios de WordPress (wp/v2/media) --
// distinto de woo_call: la API key de WooCommerce (wc/v3) NO sirve para esto,
// hace falta un WP Application Password (usuario real de WordPress). Devuelve
// el ID de medio de WordPress, para referenciar en `images: [{id: ...}]` del
// producto. Requirió desactivar el bloqueo de Application Passwords que
// Wordfence trae activado por defecto (decisión explícita de Mauricio,
// 07/09/2026, ver Wordfence -> Todas las opciones -> Protección contra
// ataques de fuerza bruta -> "Desactivar las contraseñas de aplicación").
function wp_upload_media($binario, $filename) {
    $ch = curl_init(WOO_STORE_URL . '/wp-json/wp/v2/media');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERPWD, WP_APP_USER . ':' . WP_APP_PASSWORD);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: image/jpeg',
        'Content-Disposition: attachment; filename="' . $filename . '"',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $binario);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) throw new Exception("wp/v2/media falló: $err");
    $data = json_decode($resp, true);
    if ($httpCode >= 400 || empty($data['id'])) throw new Exception("wp/v2/media error $httpCode: " . ($data['message'] ?? $resp));
    return $data['id'];
}

// Redimensiona un binario de imagen al mismo pipeline que ya usa
// manager_fetch_foto (800x800, fondo blanco, JPEG 85%) pero devuelve el
// binario procesado en vez de guardarlo a un archivo -- reusable para subir
// directo a WooCommerce sin pasar por el filesystem de este servidor
// (Ferozo), que no es el mismo servidor que travelblue.com.ar (SiteGround).
function woo_procesar_imagen_bytes($binarioOriginal) {
    $src = @imagecreatefromstring($binarioOriginal);
    if (!$src) return null;
    $ow = imagesx($src); $oh = imagesy($src);
    $ratio = min(800 / $ow, 800 / $oh);
    $nw = intval($ow * $ratio); $nh = intval($oh * $ratio);
    $ox = intval((800 - $nw) / 2); $oy = intval((800 - $nh) / 2);
    $dst = imagecreatetruecolor(800, 800);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, $ox, $oy, 0, 0, $nw, $nh, $ow, $oh);
    ob_start();
    imagejpeg($dst, null, 85);
    $binarioProcesado = ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    return $binarioProcesado;
}

// Trae TODAS las fotos de un artículo desde Manager (no solo la principal --
// pedido explícito de Mauricio 07/09/2026), ya procesadas (800x800, fondo
// blanco), ordenadas por Orden. Nunca debe frenar la creación del producto
// por esto -- cualquier falla en una foto puntual se saltea, no aborta todo.
function manager_fetch_todas_fotos($token, $codigo) {
    try {
        $imgs = manager_call($token, '/Api/ECommerce/GetDTArticulosImagenes', [
            'DTRequest' => ['draw' => 1, 'order' => [], 'start' => 0, 'length' => 50],
            'DefinicionTablaFiltros' => false,
            'CalculaTotales' => false,
            'ListFilters' => manager_dict_filtros(['CodigoArticulo' => manager_filtro_texto($codigo)]),
        ]);
        usort($imgs, function ($a, $b) { return intval($a['Orden'] ?? 0) <=> intval($b['Orden'] ?? 0); });

        $binarios = [];
        foreach ($imgs as $img) {
            if (empty($img['PasoImagen'])) continue;
            try {
                $imgData = manager_call_raw($token, '/Api/Image/GetImage', ['ImageFullPath' => $img['PasoImagen']]);
                if (empty($imgData['ImageContent'])) continue;
                $procesado = woo_procesar_imagen_bytes(base64_decode($imgData['ImageContent']));
                if ($procesado) $binarios[] = $procesado;
            } catch (Exception $e) { /* se saltea esta foto puntual, sigue con las demas */ }
        }
        return $binarios;
    } catch (Exception $e) {
        return [];
    }
}

// GET /products?sku=... hace match EXACTO — devuelve tanto productos simples
// como VARIACIONES (modelos agrupados por color, ej. "Mochila XPERT" con
// padre de sku inventado "3XPERT" y variaciones reales "33050"/"33051") con
// el mismo shape relevante (regular_price/sale_price/stock_status/type/
// parent_id) — hallazgo real 07/09/2026, ver conocimiento/manager2max.md.
// Queda para casos puntuales (ej. probar un solo código) -- el diff completo
// usa woo_indexar_productos() en su lugar, mucho mas rapido (ver abajo).
function woo_find_by_sku($sku) {
    $productos = woo_call('GET', '/products', null, ['sku' => $sku]);
    return $productos[0] ?? null;
}

// Trae TODO el catálogo de WooCommerce en una sola pasada (paginado) y arma
// un mapa sku -> producto/variación, en vez de consultar articulo por
// articulo. Optimización pedida por Mauricio (07/09/2026): el preview hacia
// 163 consultas individuales (~2-3 min); esto lo baja a un puñado de
// llamadas (2 páginas de productos + 1 por cada producto variable para sus
// variaciones, ~20-25 llamadas en vez de 163 para el catálogo real de hoy).
function woo_indexar_productos() {
    // status=any: sin esto, GET /products excluye productos que no estan en
    // "publish" (ej. "pending" -- ni borrador ni publicado, un estado real
    // que tienen varios productos padre de modelos agrupados en esta tienda).
    //
    // Bug real de la API de WooCommerce encontrado 08/09/2026 (Mauricio lo
    // detecto comparando el resultado optimizado contra el que ya tenia
    // confirmado en el panel): para productos en estado "pending", el campo
    // `type` miente -- dice "simple" aunque el producto SI tenga variaciones
    // reales (confirmado con "Mochila SMITHFIELD": type=simple, pero
    // /products/{id}/variations devuelve 2 variaciones reales). Por eso NO
    // alcanza con mirar `type==='variable'` para decidir si hay que pedir
    // las variaciones -- para cualquier producto que no este 'publish' hay
    // que pedirlas igual, por las dudas (WooCommerce devuelve rapido un
    // array vacio para los que de verdad no tienen).
    $indice = [];
    $page = 1;
    while (true) {
        $lote = woo_call('GET', '/products', null, ['page' => $page, 'per_page' => 100, 'status' => 'any']);
        if (!$lote) break;
        foreach ($lote as $p) {
            if (!empty($p['sku'])) $indice[$p['sku']] = $p;
            $puedeTenerVariaciones = ($p['type'] ?? '') === 'variable' || ($p['status'] ?? '') !== 'publish';
            if ($puedeTenerVariaciones) {
                $vpage = 1;
                while (true) {
                    $variaciones = woo_call('GET', "/products/{$p['id']}/variations", null, ['page' => $vpage, 'per_page' => 100, 'status' => 'any']);
                    if (!$variaciones) break;
                    foreach ($variaciones as $v) {
                        if (!empty($v['sku'])) $indice[$v['sku']] = $v;
                    }
                    if (count($variaciones) < 100) break;
                    $vpage++;
                }
            }
        }
        if (count($lote) < 100) break;
        $page++;
    }
    return $indice;
}

function woo_update_product($id, $payload) { return woo_call('PUT', "/products/$id", $payload); }
function woo_update_variation($parentId, $variationId, $payload) { return woo_call('PUT', "/products/$parentId/variations/$variationId", $payload); }
function woo_create_product($payload) { return woo_call('POST', '/products', $payload); }
function woo_list_categories() {
    $categorias = [];
    $page = 1;
    while (true) {
        $lote = woo_call('GET', '/products/categories', null, ['page' => $page, 'per_page' => 100]);
        if (!$lote) break;
        $categorias = array_merge($categorias, $lote);
        $page++;
    }
    return $categorias;
}
function woo_create_category($nombre) { return woo_call('POST', '/products/categories', ['name' => $nombre]); }

// Categorías reales confirmadas contra travelblue.com.ar (07/09/2026): a
// diferencia del mayorista (que renombra Equipajes -> "VALIJAS"), acá la
// tienda YA usa "Equipajes" tal cual — no renombrar.
function woo_categoria($rubro) {
    $rubro = strtoupper(trim($rubro));
    if ($rubro === 'MOCHILAS') return 'Mochilas';
    if ($rubro === 'EQUIPAJES') return 'Equipajes';
    return 'Accesorios';
}

function woo_es_vendible($rubro) {
    return !in_array(strtoupper(trim($rubro)), WOO_RUBROS_EXCLUIDOS, true);
}

// Si el producto tiene sale_price activo, recalcula el precio promocional
// manteniendo el MISMO % de descuento al cambiar el regular_price — pedido
// explícito de Mauricio (07/09/2026), sin esto un producto en oferta quedaba
// con el precio rebajado viejo (% de descuento roto) al actualizar el precio.
function woo_recalcular_precio_promocional($regularActual, $saleActual, $regularNuevo) {
    if (empty($saleActual)) return null;
    $regularActual = floatval($regularActual);
    $saleActual = floatval($saleActual);
    if ($regularActual <= 0) return null;
    $descuento = 1 - ($saleActual / $regularActual);
    return (string) round(floatval($regularNuevo) * (1 - $descuento));
}

// Trae todos los artículos vendibles de Travel Blue con precio Público
// General, ya limpios/categorizados — 2 llamadas a Manager en total (todos
// los artículos de la marca + todos los precios de la lista para esa marca),
// mismo patrón eficiente que ya usa manager_fetch_marca (nada de consultar
// precio artículo por artículo).
function woo_fetch_travelblue($token) {
    $articulos = manager_call($token, '/Api/articulo/GetDTArticulos', [
        'DTRequest' => ['draw' => 1, 'order' => [], 'start' => 0, 'length' => 5000],
        'DefinicionTablaFiltros' => false,
        'CalculaTotales' => false,
        'ListFilters' => manager_dict_filtros(['Marca' => manager_filtro_texto(WOO_MARCA)]),
    ]);

    $items = [];
    foreach ($articulos as $a) {
        $codigo = trim($a['CodigoArticulo'] ?? '');
        if ($codigo === '') continue;
        if (!woo_es_vendible($a['Rubro'] ?? '')) continue;
        $items[$codigo] = [
            'codigo' => $codigo,
            'descripcion' => manager_limpiar_descripcion($a['Descripcion'] ?? '', WOO_MARCA),
            'categoria' => woo_categoria($a['Rubro'] ?? ''),
            'stock_status' => !empty($a['Sube']) ? 'instock' : 'outofstock',
            'precio' => null,
        ];
    }

    $precios = manager_call($token, '/Api/articulo/GetDTArticulosPrecioExistencia', [
        'DTRequest' => ['draw' => 1, 'order' => [], 'start' => 0, 'length' => 5000],
        'DefinicionTablaFiltros' => false,
        'CalculaTotales' => false,
        'ListFilters' => manager_dict_filtros([
            'IDListaPrecio' => manager_filtro_numero(WOO_LISTA_PRECIO),
            'IDDeposito' => manager_filtro_numero(0),
            'IDCliente' => manager_filtro_numero(0),
            'IDProveedor' => manager_filtro_numero(0),
            'IDMonedaComprobante' => manager_filtro_numero(1),
            'FactorCotizacionMonCompMonLP' => manager_filtro_numero(1.0),
            'Marca' => manager_filtro_texto(WOO_MARCA),
        ]),
    ]);
    foreach ($precios as $p) {
        $codigo = trim($p['CodigoArticulo'] ?? '');
        if (isset($items[$codigo]) && isset($p['PrecioFinalLP'])) {
            $items[$codigo]['precio'] = (string) round(floatval($p['PrecioFinalLP']));
        }
    }

    return array_values($items);
}

// Decide que pasa con UN articulo ya transformado ($it, salido de
// woo_fetch_travelblue) contra su producto real de WooCommerce (ya
// resuelto via woo_find_by_sku, o null si no existe). Extraido de
// woo_sync_diff para poder reusarlo tanto en la corrida completa de una
// sola vez (woo_sync_diff, usada por apply/cron) como en el preview por
// tandas con progreso (woo_sync_diff_lote, ver mas abajo) sin duplicar la
// logica de comparacion.
function woo_diff_item($it, $producto, $categoriasWoo) {
    if ($producto === null) {
        $idCategoria = null;
        foreach ($categoriasWoo as $c) {
            if (strcasecmp(trim($c['name']), $it['categoria']) === 0) { $idCategoria = $c['id']; break; }
        }
        return ['tipo' => 'nuevo', 'data' => array_merge($it, ['id_categoria' => $idCategoria])];
    }

    $esVariacion = ($producto['type'] ?? '') === 'variation';
    $cambios = [];

    if ($it['precio'] !== null) {
        $precioActual = $producto['regular_price'] ?? '';
        if ($precioActual === '' || abs(floatval($precioActual) - floatval($it['precio'])) >= 1) {
            $cambios['regular_price'] = ['antes' => $precioActual, 'despues' => $it['precio']];
            $salePromo = woo_recalcular_precio_promocional($precioActual, $producto['sale_price'] ?? null, $it['precio']);
            if ($salePromo !== null) {
                $cambios['sale_price'] = ['antes' => $producto['sale_price'], 'despues' => $salePromo];
            }
        }
    }

    if (($producto['stock_status'] ?? '') !== $it['stock_status']) {
        $cambios['stock_status'] = ['antes' => $producto['stock_status'] ?? '', 'despues' => $it['stock_status']];
    }

    // Sube=0 en Manager -> el producto pasa a "pending" (fuera de venta,
    // pero no borrado ni despublicado del todo); Sube=1 -> vuelve a
    // "publish". Pedido explícito de Mauricio (08/09/2026) -- de hecho
    // explica el bug de "pending" que se encontró hoy: ya habia productos
    // en ese estado por este mismo motivo, aplicado a mano hasta ahora.
    // Nunca se toca un "draft" (alta nueva sin revisar todavia por Mauricio)
    // ni se aplica a variaciones (el estado se maneja a nivel del producto
    // padre, no por variacion individual -- fuera de alcance de este cambio).
    if (!$esVariacion && ($producto['status'] ?? '') !== 'draft') {
        $statusDeseado = $it['stock_status'] === 'outofstock' ? 'pending' : 'publish';
        if (($producto['status'] ?? '') !== $statusDeseado) {
            $cambios['status'] = ['antes' => $producto['status'] ?? '', 'despues' => $statusDeseado];
        }
    }

    if (!$cambios) return ['tipo' => 'sin_cambios', 'data' => ['codigo' => $it['codigo']]];

    return ['tipo' => 'actualiza', 'data' => [
        'codigo' => $it['codigo'], 'nombre' => $producto['name'] ?? '',
        'es_variacion' => $esVariacion, 'id' => $producto['id'], 'parent_id' => $producto['parent_id'] ?? null,
        'cambios' => $cambios,
    ]];
}

// Compara Travel Blue (Manager) contra WooCommerce — indexa TODO el catálogo
// de WooCommerce de una sola pasada (woo_indexar_productos, ~20-25 llamadas
// para el catálogo real de hoy) en vez de consultar articulo por articulo
// (~160 llamadas, como se hacía antes de la optimización del 07/09/2026)
// y despues compara todo en memoria, sin mas llamadas de red.
function woo_sync_diff($token) {
    $items = woo_fetch_travelblue($token);
    $categoriasWoo = woo_list_categories();
    $indiceWoo = woo_indexar_productos();

    $actualiza = []; $nuevos = []; $sinCambios = [];
    foreach ($items as $it) {
        $r = woo_diff_item($it, $indiceWoo[$it['codigo']] ?? null, $categoriasWoo);
        if ($r['tipo'] === 'nuevo') $nuevos[] = $r['data'];
        elseif ($r['tipo'] === 'actualiza') $actualiza[] = $r['data'];
        else $sinCambios[] = $r['data'];
    }

    return ['actualiza' => $actualiza, 'nuevos' => $nuevos, 'sin_cambios' => $sinCambios];
}

// Aplica un diff ya calculado. SIN cola de "pendientes" a diferencia del sync
// de Manager: una alta en WooCommerce nace SIEMPRE como borrador invisible
// (status=draft), así que la revisión humana ya está garantizada por eso — no
// hace falta una aprobación aparte. Por eso solo hay 2 modos (manual/automático,
// confirmado con Mauricio 07/09/2026), no 3.
function woo_sync_aplicar($db, $diff, $modo, $runId, $token) {
    $actualizados = 0; $nuevosCreados = 0; $errores = [];

    foreach ($diff['actualiza'] as $it) {
        $payload = [];
        foreach ($it['cambios'] as $campo => $vals) $payload[$campo] = $vals['despues'];
        try {
            if ($it['es_variacion']) {
                woo_update_variation($it['parent_id'], $it['id'], $payload);
            } else {
                woo_update_product($it['id'], $payload);
            }
            $actualizados++;
        } catch (Exception $e) {
            $errores[] = ['codigo' => $it['codigo'], 'motivo' => $e->getMessage()];
        }
    }

    foreach ($diff['nuevos'] as $it) {
        $idCategoria = $it['id_categoria'];
        if ($idCategoria === null) {
            try { $idCategoria = woo_create_category($it['categoria'])['id']; } catch (Exception $e) { /* sigue sin categoría si falla */ }
        }
        $descripcionHtml = '<h3>Detalles</h3><p>' . htmlspecialchars($it['descripcion']) . '</p><h3>Características</h3><p>(completar antes de publicar)</p>';
        $payload = [
            'name' => $it['descripcion'] ?: $it['codigo'],
            'sku' => $it['codigo'],
            'type' => 'simple',
            'status' => 'draft', // nunca publicar automático
            'description' => $descripcionHtml,
            'stock_status' => $it['stock_status'],
            'manage_stock' => false,
        ];
        if ($it['precio'] !== null) $payload['regular_price'] = $it['precio'];
        if ($idCategoria !== null) $payload['categories'] = [['id' => $idCategoria]];

        // Sube TODAS las fotos del artículo (no solo la principal, pedido de
        // Mauricio 07/09/2026) a la biblioteca de medios de WordPress antes de
        // crear el producto, para poder referenciarlas por ID. Una foto que
        // falla no frena el alta -- se crea igual con las que sí subieron.
        $fotos = manager_fetch_todas_fotos($token, $it['codigo']);
        $imagenes = [];
        foreach ($fotos as $i => $binario) {
            try {
                $mediaId = wp_upload_media($binario, $it['codigo'] . '_' . ($i + 1) . '.jpg');
                $imagenes[] = ['id' => $mediaId, 'position' => $i];
            } catch (Exception $e) { /* se saltea esta foto puntual, sigue con las demas */ }
        }
        if ($imagenes) $payload['images'] = $imagenes;

        try {
            woo_create_product($payload);
            $nuevosCreados++;
        } catch (Exception $e) {
            $errores[] = ['codigo' => $it['codigo'], 'motivo' => $e->getMessage()];
        }
    }

    $ok = empty($errores) ? 1 : 0;
    $mensaje = empty($errores) ? null : json_encode($errores, JSON_UNESCAPED_UNICODE);
    $log = $db->prepare("INSERT INTO woo_sync_log (run_id, ok, mensaje, actualizados, nuevos) VALUES (?,?,?,?,?)");
    $log->bind_param('sisii', $runId, $ok, $mensaje, $actualizados, $nuevosCreados);
    $log->execute();

    return ['actualizados' => $actualizados, 'nuevos_creados' => $nuevosCreados, 'errores' => $errores];
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
        mostrar TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $colCheck = $db->query("SHOW COLUMNS FROM productos LIKE 'multiplo'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $db->query("ALTER TABLE productos ADD COLUMN multiplo INT DEFAULT 1");
    }

    $colCheck = $db->query("SHOW COLUMNS FROM productos LIKE 'codigo_barras'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $db->query("ALTER TABLE productos ADD COLUMN codigo_barras VARCHAR(50) DEFAULT NULL");
        $db->query("ALTER TABLE productos ADD INDEX idx_codigo_barras (codigo_barras)");
    }

    $colCheck = $db->query("SHOW COLUMNS FROM productos LIKE 'mostrar'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $db->query("ALTER TABLE productos ADD COLUMN mostrar TINYINT(1) NOT NULL DEFAULT 1");
    }

    $db->query("CREATE TABLE IF NOT EXISTS import_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        import_id VARCHAR(50) NOT NULL,
        codigo VARCHAR(50) NOT NULL,
        accion ENUM('updated','inserted') NOT NULL,
        datos_anteriores TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import_id (import_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
        eliminado TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS pedidos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        estado ENUM('PENDIENTE','EN_PREPARACION','FACTURADO','ENVIADO','ELIMINADO') NOT NULL DEFAULT 'PENDIENTE',
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        observaciones TEXT DEFAULT NULL,
        facturas VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Agregar columna eliminado en clientes si no existe
    $colCheck = $db->query("SHOW COLUMNS FROM clientes LIKE 'eliminado'");
    if ($colCheck && $colCheck->num_rows === 0) $db->query("ALTER TABLE clientes ADD COLUMN eliminado TINYINT DEFAULT 0");
    // Agregar ELIMINADO al ENUM de pedidos si no existe
    $db->query("ALTER TABLE pedidos MODIFY COLUMN estado ENUM('PENDIENTE','EN_PREPARACION','FACTURADO','ENVIADO','ELIMINADO') NOT NULL DEFAULT 'PENDIENTE'");

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

    // ── Sync con Manager2Max ─────────────────────────────────────────────
    $db->query("INSERT IGNORE INTO config (clave, valor) VALUES ('manager_sync_mode', 'manual')");

    $db->query("CREATE TABLE IF NOT EXISTS manager_sync_pendientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(50) NOT NULL,
        descripcion VARCHAR(255),
        categoria VARCHAR(100),
        precio_mayorista DECIMAL(10,2),
        pvp DECIMAL(10,2),
        estado VARCHAR(20),
        codigo_barras VARCHAR(50),
        marca_manager VARCHAR(100),
        rubro_manager VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS manager_sync_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        run_id VARCHAR(50) NOT NULL,
        marca VARCHAR(50) NOT NULL,
        ok TINYINT NOT NULL,
        mensaje VARCHAR(255),
        actualizados INT DEFAULT 0,
        nuevos INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_run_id (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Sync minorista con WooCommerce (travelblue.com.ar) ───────────────
    $db->query("INSERT IGNORE INTO config (clave, valor) VALUES ('woo_sync_mode', 'manual')");

    $db->query("CREATE TABLE IF NOT EXISTS woo_sync_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        run_id VARCHAR(50) NOT NULL,
        ok TINYINT NOT NULL,
        mensaje TEXT,
        actualizados INT DEFAULT 0,
        nuevos INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_run_id (run_id)
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
        $cat     = $_GET['categoria'] ?? '';
        $q       = $_GET['q'] ?? '';
        $barcode = $_GET['barcode'] ?? '';
        $body    = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
        $isAdmin = isAdminAuth($body);
        $sql = "SELECT p.*, COALESCE(c.orden, 0) as cat_orden FROM productos p LEFT JOIN categorias c ON p.categoria = c.nombre WHERE 1=1";
        $params = []; $types = '';
        if (!$isAdmin) { $sql .= " AND p.mostrar = 1"; }
        if ($cat)     { $sql .= " AND p.categoria = ?"; $params[] = $cat; $types .= 's'; }
        if ($barcode) { $sql .= " AND p.codigo_barras = ?"; $params[] = $barcode; $types .= 's'; }
        elseif ($q)   { $sql .= " AND (p.descripcion LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)"; $like = "%$q%"; $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss'; }
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
            // Solo le sirve al filtro "con/sin foto" del admin — no hace falta
            // pagar el file_exists() en cada visita anónima al catálogo público.
            if ($isAdmin) {
                $prod['tiene_foto'] = producto_tiene_foto($prod['codigo'], $prod['foto']) ? 1 : 0;
            }
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
        $codigoBarras = isset($data['codigo_barras']) && $data['codigo_barras'] !== '' ? trim($data['codigo_barras']) : null;
        $mostrar = isset($data['mostrar']) ? (intval($data['mostrar']) ? 1 : 0) : 1;
        $stmt = $db->prepare("INSERT INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,foto,estado,orden,multiplo,codigo_barras,mostrar) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssddssiisi', $data['codigo'], $data['descripcion'], $data['categoria'], $data['precio_mayorista'], $pvp, $data['foto'], $data['estado'], $orden, $multiplo, $codigoBarras, $mostrar);
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
        $codigoBarras = isset($data['codigo_barras']) && $data['codigo_barras'] !== '' ? trim($data['codigo_barras']) : null;
        $mostrar = isset($data['mostrar']) ? (intval($data['mostrar']) ? 1 : 0) : 1;
        // Mantener el orden actual si no se pasa uno
        $ordenActual = $db->query("SELECT orden FROM productos WHERE id=$id")->fetch_assoc();
        $orden = isset($data['orden']) && $data['orden'] !== '' ? intval($data['orden']) : ($ordenActual['orden'] ?? 0);
        $stmt = $db->prepare("UPDATE productos SET codigo=?,descripcion=?,categoria=?,precio_mayorista=?,pvp=?,foto=?,estado=?,orden=?,multiplo=?,codigo_barras=?,mostrar=?,updated_at=NOW() WHERE id=?");
        $stmt->bind_param('sssddssiisii', $data['codigo'], $data['descripcion'], $data['categoria'], $data['precio_mayorista'], $pvp, $data['foto'], $data['estado'], $orden, $multiplo, $codigoBarras, $mostrar, $id);
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
            $codigoBarras = isset($p['CODIGO_BARRAS']) && $p['CODIGO_BARRAS'] !== '' ? trim($p['CODIGO_BARRAS']) : null;
            $stmt = $db->prepare("INSERT IGNORE INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,foto,estado,orden,multiplo,codigo_barras) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssddssiis', $p['CODIGO'], $p['DESCRIPCION'], $p['CATEGORIA'], $p['PRECIO_MAYORISTA'], $pvp, $foto, $estado, $o, $multiplo, $codigoBarras);
            if ($stmt->execute()) $imported++;
            else $errors[] = $p['CODIGO'];
        }
        echo json_encode(['ok' => true, 'imported' => $imported, 'errors' => $errors]);
        break;

    case 'importar_masivo':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $productos = $data['productos'] ?? [];
        if (empty($productos)) { http_response_code(400); die(json_encode(['error' => 'Sin productos'])); }
        $imported = 0; $updated = 0; $errors = [];

        // ID único para este lote — permite identificar y revertir la importación
        $import_id = 'imp_' . date('Ymd_His') . '_' . substr(uniqid(), -4);

        // Limpiar snapshots con más de 30 días
        $db->query("DELETE FROM import_snapshots WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

        // Asegurar que las categorías nuevas existan
        $cats = array_unique(array_column($productos, 'CATEGORIA'));
        $cats = array_filter($cats);
        foreach (array_values($cats) as $i => $cat) {
            $stmt = $db->prepare("INSERT IGNORE INTO categorias (nombre, orden) VALUES (?, ?)");
            $stmt->bind_param('si', $cat, $i);
            $stmt->execute();
        }

        foreach ($productos as $p) {
            $codigo = trim($p['CODIGO'] ?? '');
            if (!$codigo) { $errors[] = ['codigo' => '(vacío)', 'motivo' => 'CODIGO obligatorio']; continue; }

            // ¿Existe el producto?
            $chk = $db->prepare("SELECT codigo,descripcion,categoria,precio_mayorista,pvp,estado,codigo_barras,mostrar FROM productos WHERE codigo=?");
            $chk->bind_param('s', $codigo); $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();

            if ($existing) {
                // Guardar snapshot ANTES de modificar
                $prevJson = json_encode($existing, JSON_UNESCAPED_UNICODE);
                $snapStmt = $db->prepare("INSERT INTO import_snapshots (import_id, codigo, accion, datos_anteriores) VALUES (?,?,'updated',?)");
                $snapStmt->bind_param('sss', $import_id, $codigo, $prevJson);
                $snapStmt->execute();

                // UPDATE — solo sobreescribir campos no vacíos
                $sets = []; $params = []; $types = '';
                if (isset($p['DESCRIPCION'])    && $p['DESCRIPCION']    !== '') { $sets[] = 'descripcion=?';      $params[] = trim($p['DESCRIPCION']);           $types .= 's'; }
                if (isset($p['CATEGORIA'])      && $p['CATEGORIA']      !== '') { $sets[] = 'categoria=?';        $params[] = trim($p['CATEGORIA']);             $types .= 's'; }
                if (isset($p['PRECIO_MAYORISTA']) && $p['PRECIO_MAYORISTA'] !== '') { $sets[] = 'precio_mayorista=?'; $params[] = floatval($p['PRECIO_MAYORISTA']); $types .= 'd'; }
                if (isset($p['PVP'])            && $p['PVP']            !== '') { $sets[] = 'pvp=?';              $params[] = floatval($p['PVP']);               $types .= 'd'; }
                if (isset($p['ESTADO'])         && $p['ESTADO']         !== '') { $sets[] = 'estado=?';           $params[] = strtoupper(trim($p['ESTADO']));    $types .= 's'; }
                if (isset($p['CODIGO_BARRAS'])  && $p['CODIGO_BARRAS']  !== '') { $sets[] = 'codigo_barras=?';    $params[] = trim($p['CODIGO_BARRAS']);          $types .= 's'; }
                if (isset($p['MOSTRAR'])        && $p['MOSTRAR']        !== '') { $sets[] = 'mostrar=?';          $params[] = parse_mostrar_valor($p['MOSTRAR']); $types .= 'i'; }
                if (empty($sets)) { $updated++; continue; }
                $params[] = $codigo; $types .= 's';
                $stmt = $db->prepare("UPDATE productos SET " . implode(',', $sets) . " WHERE codigo=?");
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) $updated++;
                else $errors[] = ['codigo' => $codigo, 'motivo' => $db->error];
            } else {
                // INSERT — guardar snapshot de tipo 'inserted' (para poder eliminarlo al revertir)
                $snapStmt = $db->prepare("INSERT INTO import_snapshots (import_id, codigo, accion, datos_anteriores) VALUES (?,?,'inserted',NULL)");
                $snapStmt->bind_param('ss', $import_id, $codigo);
                $snapStmt->execute();

                $desc   = trim($p['DESCRIPCION'] ?? '');
                $cat    = trim($p['CATEGORIA']   ?? '');
                $may    = floatval($p['PRECIO_MAYORISTA'] ?? 0);
                $pvp    = isset($p['PVP']) && $p['PVP'] !== '' ? floatval($p['PVP']) : null;
                $estado = strtoupper(trim($p['ESTADO'] ?? 'DISPONIBLE'));
                $cb     = isset($p['CODIGO_BARRAS']) && $p['CODIGO_BARRAS'] !== '' ? trim($p['CODIGO_BARRAS']) : null;
                $mostrar = isset($p['MOSTRAR']) && $p['MOSTRAR'] !== '' ? parse_mostrar_valor($p['MOSTRAR']) : 1;
                if (!$desc || !$cat) { $errors[] = ['codigo' => $codigo, 'motivo' => 'DESCRIPCION y CATEGORIA obligatorias para producto nuevo']; continue; }
                $o = 0; $multiplo = 1;
                $stmt = $db->prepare("INSERT INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,estado,orden,multiplo,codigo_barras,mostrar) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sssddsiisi', $codigo, $desc, $cat, $may, $pvp, $estado, $o, $multiplo, $cb, $mostrar);
                if ($stmt->execute()) $imported++;
                else $errors[] = ['codigo' => $codigo, 'motivo' => $db->error];
            }
        }
        echo json_encode(['ok' => true, 'imported' => $imported, 'updated' => $updated, 'errors' => $errors, 'import_id' => $import_id]);
        break;

    case 'import_last':
        // Devuelve metadatos de la importación manual más reciente (para mostrar el botón Deshacer al cargar la página)
        // Excluye corridas de sync con Manager (import_id con prefijo sync_) — esas no se deshacen desde este banner
        $r = $db->query("SELECT import_id, created_at, COUNT(*) as n FROM import_snapshots WHERE import_id NOT LIKE 'sync\\_%' GROUP BY import_id, created_at ORDER BY created_at DESC LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        echo json_encode($row ? ['ok' => true, 'import_id' => $row['import_id'], 'created_at' => $row['created_at'], 'n' => intval($row['n'])] : ['ok' => true, 'import_id' => null]);
        break;


    case 'check_codigos':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $codigos = array_values(array_filter(array_map('trim', $data['codigos'] ?? []), 'strlen'));
        if (!$codigos) { echo json_encode(['ok' => true, 'productos' => (object)[]]); break; }
        $ph = implode(',', array_fill(0, count($codigos), '?'));
        $types = str_repeat('s', count($codigos));
        $stmt = $db->prepare("SELECT codigo, descripcion, categoria, precio_mayorista, pvp, estado, codigo_barras, mostrar FROM productos WHERE codigo IN ($ph)");
        $stmt->bind_param($types, ...$codigos);
        $stmt->execute();
        $res = $stmt->get_result();
        $productos = [];
        while ($row = $res->fetch_assoc()) { $productos[$row['codigo']] = $row; }
        echo json_encode(['ok' => true, 'productos' => $productos ?: (object)[]]);
        break;

    case 'import_rollback':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $import_id = trim($data['import_id'] ?? '');
        if (!$import_id) { http_response_code(400); die(json_encode(['error' => 'import_id requerido'])); }

        try {
            $r = $db->prepare("SELECT codigo, accion, datos_anteriores FROM import_snapshots WHERE import_id=?");
            if (!$r) throw new Exception("prepare SELECT falló: " . $db->error);
            $r->bind_param('s', $import_id);
            $r->execute();
            $res = $r->get_result();
            if (!$res) throw new Exception("get_result falló: " . $db->error);
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $r->close();

            if (empty($rows)) { http_response_code(404); die(json_encode(['error' => 'No se encontró esa importación'])); }

            $restored = 0; $errors = [];
            foreach ($rows as $row) {
                if ($row['accion'] === 'updated') {
                    $prev = json_decode($row['datos_anteriores'], true);
                    if (!$prev) { $errors[] = ['codigo' => $row['codigo'], 'motivo' => 'snapshot JSON inválido']; continue; }
                    $desc  = $prev['descripcion']     ?? '';
                    $cat   = $prev['categoria']       ?? '';
                    $pmay  = floatval($prev['precio_mayorista'] ?? 0);
                    $pvp   = isset($prev['pvp']) && $prev['pvp'] !== null ? floatval($prev['pvp']) : null;
                    $est   = $prev['estado']          ?? 'DISPONIBLE';
                    $cb    = isset($prev['codigo_barras']) && $prev['codigo_barras'] !== null ? strval($prev['codigo_barras']) : null;
                    $mostrar = isset($prev['mostrar']) ? intval($prev['mostrar']) : 1;
                    $cod   = $row['codigo'];

                    $stmt = $db->prepare("UPDATE productos SET descripcion=?,categoria=?,precio_mayorista=?,pvp=?,estado=?,codigo_barras=?,mostrar=? WHERE codigo=?");
                    if (!$stmt) throw new Exception("prepare UPDATE falló para " . $cod . ": " . $db->error);
                    $stmt->bind_param('ssddssis', $desc, $cat, $pmay, $pvp, $est, $cb, $mostrar, $cod);
                    if ($stmt->execute()) $restored++;
                    else $errors[] = ['codigo' => $cod, 'motivo' => $stmt->error];
                    $stmt->close();
                } elseif ($row['accion'] === 'inserted') {
                    $cod = $row['codigo'];
                    $del = $db->prepare("DELETE FROM productos WHERE codigo=?");
                    if (!$del) throw new Exception("prepare DELETE falló para " . $cod . ": " . $db->error);
                    $del->bind_param('s', $cod);
                    $del->execute();
                    $del->close();
                    $restored++;
                }
            }
            // Eliminar snapshots ya usados
            $delSnap = $db->prepare("DELETE FROM import_snapshots WHERE import_id=?");
            if (!$delSnap) throw new Exception("prepare DELETE snapshots falló: " . $db->error);
            $delSnap->bind_param('s', $import_id);
            $delSnap->execute();
            $delSnap->close();

            echo json_encode(['ok' => true, 'restored' => $restored, 'errors' => $errors]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'import_rollback: ' . $e->getMessage()]);
        }
        break;

    case 'categorias':
        $r = $db->query("SELECT * FROM categorias ORDER BY orden, nombre");
        echo json_encode($r->fetch_all(MYSQLI_ASSOC));
        break;

    case 'categoria_crear':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $nombre = mb_strtoupper(trim($data['nombre'] ?? ''), 'UTF-8');
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
        $nombre = mb_strtoupper(trim($data['nombre'] ?? ''), 'UTF-8');
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
        // Solo claves públicas — nunca exponer admin_pass ni nada sensible sin auth
        $clavesPublicas = ['whatsapp'];
        $ph = implode(',', array_fill(0, count($clavesPublicas), '?'));
        $stmt = $db->prepare("SELECT clave, valor FROM config WHERE clave IN ($ph)");
        $stmt->bind_param(str_repeat('s', count($clavesPublicas)), ...$clavesPublicas);
        $stmt->execute();
        $r = $stmt->get_result();
        $cfg = [];
        while ($row = $r->fetch_assoc()) $cfg[$row['clave']] = $row['valor'];
        echo json_encode($cfg);
        break;

    // ── DIAGNÓSTICO: conectividad saliente hacia Manager2Max ───────────────────
    // Endpoint de solo lectura contra Manager (EchoPing, sin login) para
    // confirmar que este hosting deja salir HTTP hacia la IP de Manager antes
    // de construir el sync completo. Queda como utilidad permanente de diagnóstico.
    case 'manager_test_conexion':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);

        $resultado = [
            'curl_disponible' => extension_loaded('curl'),
            'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
            'max_execution_time' => ini_get('max_execution_time'),
        ];

        $url = 'http://190.123.85.167:2022/Api/MaxWeb/EchoPing';
        $inicio = microtime(true);

        if (extension_loaded('curl')) {
            $resultado['metodo'] = 'curl';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $respuesta = curl_exec($ch);
            $resultado['error_curl'] = curl_error($ch) ?: null;
            $resultado['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $resultado['metodo'] = 'file_get_contents';
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
            $respuesta = @file_get_contents($url, false, $ctx);
            $resultado['http_response_header'] = $http_response_header ?? null;
        }

        $resultado['tiempo_segundos'] = round(microtime(true) - $inicio, 3);
        $resultado['respuesta_cruda'] = $respuesta !== false ? $respuesta : null;
        $resultado['ok'] = !empty($respuesta);
        echo json_encode($resultado);
        break;

    // ── SYNC CON MANAGER2MAX ────────────────────────────────────────────────
    case 'manager_sync_preview':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        try {
            $token = manager_login();
            $diff = manager_sync_diff($db, $token);
            echo json_encode(['ok' => true] + $diff);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'manager_sync_apply':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);

        $lockRow = $db->query("SELECT GET_LOCK('manager_sync_lock', 0) as l")->fetch_assoc();
        if (!$lockRow || $lockRow['l'] != 1) {
            http_response_code(409);
            die(json_encode(['error' => 'Ya hay una sincronización en curso, esperá a que termine']));
        }
        try {
            $modoRow = $db->query("SELECT valor FROM config WHERE clave='manager_sync_mode'")->fetch_assoc();
            $modo = $modoRow ? $modoRow['valor'] : 'manual';
            $token = manager_login();
            $diff = manager_sync_diff($db, $token);

            // codigos_incluir: si viene (desde el preview manual con checks por
            // fila), solo se aplican esos códigos — permite destildar filas
            // puntuales antes de confirmar. Si no viene (automático/semiautomático/
            // cron), se aplica todo el diff como siempre.
            if (isset($data['codigos_incluir']) && is_array($data['codigos_incluir'])) {
                $incluir = array_flip($data['codigos_incluir']);
                $diff['actualiza'] = array_values(array_filter($diff['actualiza'], function ($it) use ($incluir) {
                    return isset($incluir[$it['codigo']]);
                }));
                $diff['nuevos'] = array_values(array_filter($diff['nuevos'], function ($it) use ($incluir) {
                    return isset($incluir[$it['codigo']]);
                }));
            }

            $runId = 'sync_' . date('Ymd_His') . '_' . substr(uniqid(), -4);
            $resumen = manager_sync_aplicar($db, $diff, $modo, $runId, $token);
            echo json_encode(['ok' => true, 'modo' => $modo, 'run_id' => $runId, 'por_marca' => $diff['por_marca']] + $resumen);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        } finally {
            $db->query("SELECT RELEASE_LOCK('manager_sync_lock')");
        }
        break;

    case 'manager_sync_cron':
        // Sin sesión — autenticado por token, para poder dispararse desde un Cron Job de Ferozo
        $token_recibido = $_GET['token'] ?? '';
        if (!hash_equals(MANAGER_SYNC_TOKEN, $token_recibido)) {
            http_response_code(401);
            die(json_encode(['error' => 'Token inválido']));
        }

        $modoRow = $db->query("SELECT valor FROM config WHERE clave='manager_sync_mode'")->fetch_assoc();
        $modo = $modoRow ? $modoRow['valor'] : 'manual';
        if ($modo === 'manual') {
            echo json_encode(['ok' => true, 'modo' => 'manual', 'accion' => 'ninguna — modo manual, el cron no aplica cambios']);
            break;
        }

        $lockRow = $db->query("SELECT GET_LOCK('manager_sync_lock', 0) as l")->fetch_assoc();
        if (!$lockRow || $lockRow['l'] != 1) {
            http_response_code(409);
            die(json_encode(['error' => 'Ya hay una sincronización en curso']));
        }
        try {
            $token = manager_login();
            $diff = manager_sync_diff($db, $token);
            $runId = 'sync_' . date('Ymd_His') . '_' . substr(uniqid(), -4);
            $resumen = manager_sync_aplicar($db, $diff, $modo, $runId, $token);
            echo json_encode(['ok' => true, 'modo' => $modo, 'run_id' => $runId, 'por_marca' => $diff['por_marca']] + $resumen);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        } finally {
            $db->query("SELECT RELEASE_LOCK('manager_sync_lock')");
        }
        break;

    case 'manager_sync_pendientes_list':
        $r = $db->query("SELECT * FROM manager_sync_pendientes ORDER BY created_at DESC");
        echo json_encode($r->fetch_all(MYSQLI_ASSOC));
        break;

    case 'manager_sync_pendientes_aprobar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $ids = array_map('intval', $data['ids'] ?? []);
        if (!$ids) { http_response_code(400); die(json_encode(['error' => 'ids requerido'])); }
        $runId = 'sync_' . date('Ymd_His') . '_' . substr(uniqid(), -4);
        $creados = 0; $errors = [];
        $tokenFoto = null;
        try { $tokenFoto = manager_login(); } catch (Exception $e) { /* sin foto si falla el login, no bloquea la aprobación */ }
        foreach ($ids as $id) {
            $stmt = $db->prepare("SELECT * FROM manager_sync_pendientes WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $p = $stmt->get_result()->fetch_assoc();
            if (!$p) continue;

            $catStmt = $db->prepare("INSERT IGNORE INTO categorias (nombre) VALUES (?)");
            $catStmt->bind_param('s', $p['categoria']);
            $catStmt->execute();

            $snap = $db->prepare("INSERT INTO import_snapshots (import_id, codigo, accion, datos_anteriores) VALUES (?,?,'inserted',NULL)");
            $snap->bind_param('ss', $runId, $p['codigo']);
            $snap->execute();

            $foto = $tokenFoto ? manager_fetch_foto($tokenFoto, $p['codigo']) : null;
            $o = 0; $multiplo = 1;
            $ins = $db->prepare("INSERT INTO productos (codigo,descripcion,categoria,precio_mayorista,pvp,estado,orden,multiplo,codigo_barras,foto) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param('sssddsiiss', $p['codigo'], $p['descripcion'], $p['categoria'], $p['precio_mayorista'], $p['pvp'], $p['estado'], $o, $multiplo, $p['codigo_barras'], $foto);
            if ($ins->execute()) {
                $creados++;
                $del = $db->prepare("DELETE FROM manager_sync_pendientes WHERE id=?");
                $del->bind_param('i', $id);
                $del->execute();
            } else {
                $errors[] = ['codigo' => $p['codigo'], 'motivo' => $db->error];
            }
        }
        echo json_encode(['ok' => true, 'creados' => $creados, 'errors' => $errors]);
        break;

    case 'manager_sync_pendientes_rechazar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $ids = array_map('intval', $data['ids'] ?? []);
        if (!$ids) { http_response_code(400); die(json_encode(['error' => 'ids requerido'])); }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM manager_sync_pendientes WHERE id IN ($ph)");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        echo json_encode(['ok' => true, 'eliminados' => $stmt->affected_rows]);
        break;

    case 'manager_sync_log_ultimo':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $modoRow = $db->query("SELECT valor FROM config WHERE clave='manager_sync_mode'")->fetch_assoc();
        $modo = $modoRow ? $modoRow['valor'] : 'manual';
        $ultimo = $db->query("SELECT run_id FROM manager_sync_log ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
        if (!$ultimo) { echo json_encode(['ok' => true, 'modo' => $modo, 'run_id' => null, 'filas' => []]); break; }
        $stmt = $db->prepare("SELECT marca, ok, mensaje, actualizados, nuevos, created_at FROM manager_sync_log WHERE run_id=?");
        $stmt->bind_param('s', $ultimo['run_id']);
        $stmt->execute();
        echo json_encode(['ok' => true, 'modo' => $modo, 'run_id' => $ultimo['run_id'], 'filas' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        break;

    // ── SYNC MINORISTA CON WOOCOMMERCE (travelblue.com.ar) ──────────────────
    // set_time_limit alto en preview/apply/cron: son corridas de ~160
    // llamadas a WooCommerce (un par de minutos) -- sin esto, un hosting con
    // max_execution_time bajo (ej. 30s default) podria cortar la corrida a
    // mitad de camino sin ningun aviso claro.
    case 'woo_sync_preview':
        set_time_limit(300);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        try {
            $token = manager_login();
            $diff = woo_sync_diff($token);
            echo json_encode(['ok' => true] + $diff);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'woo_sync_apply':
        set_time_limit(300);
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);

        $lockRow = $db->query("SELECT GET_LOCK('woo_sync_lock', 0) as l")->fetch_assoc();
        if (!$lockRow || $lockRow['l'] != 1) {
            http_response_code(409);
            die(json_encode(['error' => 'Ya hay una sincronización minorista en curso, esperá a que termine']));
        }
        try {
            $modoRow = $db->query("SELECT valor FROM config WHERE clave='woo_sync_mode'")->fetch_assoc();
            $modo = $modoRow ? $modoRow['valor'] : 'manual';
            $token = manager_login();
            $diff = woo_sync_diff($token);

            // codigos_incluir: igual que manager_sync_apply, permite destildar
            // filas puntuales del preview antes de confirmar.
            if (isset($data['codigos_incluir']) && is_array($data['codigos_incluir'])) {
                $incluir = array_flip($data['codigos_incluir']);
                $diff['actualiza'] = array_values(array_filter($diff['actualiza'], function ($it) use ($incluir) {
                    return isset($incluir[$it['codigo']]);
                }));
                $diff['nuevos'] = array_values(array_filter($diff['nuevos'], function ($it) use ($incluir) {
                    return isset($incluir[$it['codigo']]);
                }));
            }

            $runId = 'woosync_' . date('Ymd_His') . '_' . substr(uniqid(), -4);
            $resumen = woo_sync_aplicar($db, $diff, $modo, $runId, $token);
            echo json_encode(['ok' => true, 'modo' => $modo, 'run_id' => $runId] + $resumen);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        } finally {
            $db->query("SELECT RELEASE_LOCK('woo_sync_lock')");
        }
        break;

    case 'woo_sync_cron':
        set_time_limit(300);
        // Sin sesión — autenticado por token, para poder dispararse desde un Cron Job de Ferozo
        $token_recibido = $_GET['token'] ?? '';
        if (!hash_equals(WOO_SYNC_TOKEN, $token_recibido)) {
            http_response_code(401);
            die(json_encode(['error' => 'Token inválido']));
        }

        $modoRow = $db->query("SELECT valor FROM config WHERE clave='woo_sync_mode'")->fetch_assoc();
        $modo = $modoRow ? $modoRow['valor'] : 'manual';
        if ($modo === 'manual') {
            echo json_encode(['ok' => true, 'modo' => 'manual', 'accion' => 'ninguna — modo manual, el cron no aplica cambios']);
            break;
        }

        $lockRow = $db->query("SELECT GET_LOCK('woo_sync_lock', 0) as l")->fetch_assoc();
        if (!$lockRow || $lockRow['l'] != 1) {
            http_response_code(409);
            die(json_encode(['error' => 'Ya hay una sincronización minorista en curso']));
        }
        try {
            $token = manager_login();
            $diff = woo_sync_diff($token);
            $runId = 'woosync_' . date('Ymd_His') . '_' . substr(uniqid(), -4);
            $resumen = woo_sync_aplicar($db, $diff, $modo, $runId, $token);
            echo json_encode(['ok' => true, 'modo' => $modo, 'run_id' => $runId] + $resumen);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        } finally {
            $db->query("SELECT RELEASE_LOCK('woo_sync_lock')");
        }
        break;

    case 'woo_sync_log_ultimo':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $modoRow = $db->query("SELECT valor FROM config WHERE clave='woo_sync_mode'")->fetch_assoc();
        $modo = $modoRow ? $modoRow['valor'] : 'manual';
        $ultimo = $db->query("SELECT * FROM woo_sync_log ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
        echo json_encode(['ok' => true, 'modo' => $modo, 'ultimo' => $ultimo ?: null]);
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
        $nombre = mb_strtoupper(trim($data['nombre'] ?? ''), 'UTF-8');
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
        $nombre = mb_strtoupper(trim($data['nombre'] ?? ''), 'UTF-8');
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
        $nombre    = trim($data['nombre'] ?? '');
        if (!$nombre) { http_response_code(400); die(json_encode(['error' => 'Nombre requerido'])); }
        $cuit_dni  = $data['cuit_dni']  ?? null;
        $email     = $data['email']     ?? null;
        $domicilio = $data['domicilio'] ?? null;
        $localidad = $data['localidad'] ?? null;
        $cp        = $data['cp']        ?? null;
        $provincia = $data['provincia'] ?? null;
        $transporte= $data['transporte']?? null;
        $notas     = $data['notas']     ?? null;
        $stmt = $db->prepare("INSERT INTO clientes (telefono,nombre,cuit_dni,email,domicilio,localidad,cp,provincia,transporte,notas)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),cuit_dni=VALUES(cuit_dni),email=VALUES(email),
            domicilio=VALUES(domicilio),localidad=VALUES(localidad),cp=VALUES(cp),
            provincia=VALUES(provincia),transporte=VALUES(transporte),notas=VALUES(notas)");
        $stmt->bind_param('ssssssssss', $tel, $nombre, $cuit_dni, $email, $domicilio, $localidad, $cp, $provincia, $transporte, $notas);
        if ($stmt->execute()) {
            $idCliente = $db->insert_id ?: $db->query("SELECT id FROM clientes WHERE telefono='" . $db->real_escape_string($tel) . "'")->fetch_assoc()['id'];
            echo json_encode(['ok' => true, 'id' => $idCliente, 'telefono' => $tel]);
        } else { http_response_code(400); echo json_encode(['error' => $db->error]); }
        break;

    case 'clientes':
        $q = $_GET['q'] ?? '';
        $vista = $_GET['vista'] ?? 'activos';
        $sql = "SELECT c.*, COUNT(CASE WHEN p.estado != 'ELIMINADO' THEN 1 END) as total_pedidos
                FROM clientes c LEFT JOIN pedidos p ON p.cliente_id=c.id WHERE 1=1";
        if ($vista === 'activos') $sql .= " AND c.eliminado=0";
        elseif ($vista === 'eliminados') $sql .= " AND c.eliminado=1";
        if ($q) $sql .= " AND (c.nombre LIKE '%" . $db->real_escape_string($q) . "%' OR c.telefono LIKE '%" . $db->real_escape_string($q) . "%' OR c.cuit_dni LIKE '%" . $db->real_escape_string($q) . "%')";
        $sql .= " GROUP BY c.id ORDER BY c.nombre";
        echo json_encode($db->query($sql)->fetch_all(MYSQLI_ASSOC));
        break;

    case 'cliente_crear':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $tel = normalizarTel($data['telefono'] ?? '');
        $nombre = trim($data['nombre'] ?? '');
        if (!$tel || !$nombre) { http_response_code(400); die(json_encode(['error' => 'Teléfono y nombre son requeridos'])); }
        $cuit_dni = $data['cuit_dni'] ?? null; $email = $data['email'] ?? null;
        $domicilio = $data['domicilio'] ?? null; $localidad = $data['localidad'] ?? null;
        $cp = $data['cp'] ?? null; $provincia = $data['provincia'] ?? null;
        $transporte = $data['transporte'] ?? null; $notas = $data['notas'] ?? null;
        $stmt = $db->prepare("INSERT INTO clientes (telefono,nombre,cuit_dni,email,domicilio,localidad,cp,provincia,transporte,notas) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssssssssss', $tel, $nombre, $cuit_dni, $email, $domicilio, $localidad, $cp, $provincia, $transporte, $notas);
        if ($stmt->execute()) echo json_encode(['ok' => true, 'id' => $db->insert_id]);
        else { http_response_code(400); echo json_encode(['error' => 'El teléfono ya existe']); }
        break;

    case 'cliente_eliminar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $db->query("UPDATE clientes SET eliminado=1 WHERE id=$id");
        echo json_encode(['ok' => true]);
        break;

    case 'cliente_restaurar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $db->query("UPDATE clientes SET eliminado=0 WHERE id=$id");
        echo json_encode(['ok' => true]);
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
        $vista = $_GET['vista'] ?? 'activos'; // activos | todos | eliminados
        $sql = "SELECT p.*, c.nombre as cliente_nombre, c.telefono as cliente_tel
                FROM pedidos p JOIN clientes c ON p.cliente_id=c.id WHERE 1=1";
        if ($vista === 'activos') $sql .= " AND p.estado != 'ELIMINADO'";
        elseif ($vista === 'eliminados') $sql .= " AND p.estado = 'ELIMINADO'";
        // 'todos' no filtra por estado de eliminado
        if ($q) $sql .= " AND (c.nombre LIKE '%" . $db->real_escape_string($q) . "%' OR c.telefono LIKE '%" . $db->real_escape_string($q) . "%')";
        if ($est && $est !== 'ELIMINADO') $sql .= " AND p.estado='" . $db->real_escape_string($est) . "'";
        elseif ($est === 'ELIMINADO') $sql .= " AND p.estado='ELIMINADO'";
        if (!empty($_GET['cliente_id'])) $sql .= " AND p.cliente_id=" . intval($_GET['cliente_id']);
        $sql .= " ORDER BY p.created_at DESC";
        echo json_encode($db->query($sql)->fetch_all(MYSQLI_ASSOC));
        break;

    case 'pedido_eliminar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $estado = 'ELIMINADO';
        $stmt = $db->prepare("UPDATE pedidos SET estado=? WHERE id=?");
        $stmt->bind_param('si', $estado, $id);
        $stmt->execute();
        // Registrar en historial
        $es = $db->prepare("INSERT INTO pedido_estados (pedido_id,estado) VALUES (?,?)");
        $es->bind_param('is', $id, $estado);
        $es->execute();
        echo json_encode(['ok' => true]);
        break;

    case 'pedido_restaurar':
        $data = json_decode(file_get_contents('php://input'), true);
        checkAuth($data);
        $id = intval($_GET['id'] ?? 0);
        $estado = 'PENDIENTE';
        $stmt = $db->prepare("UPDATE pedidos SET estado=? WHERE id=?");
        $stmt->bind_param('si', $estado, $id);
        $stmt->execute();
        $es = $db->prepare("INSERT INTO pedido_estados (pedido_id,estado) VALUES (?,?)");
        $es->bind_param('is', $id, $estado);
        $es->execute();
        echo json_encode(['ok' => true]);
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