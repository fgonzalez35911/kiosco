<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
include_once '../includes/db.php'; 

if (isset($conexion) && !isset($conn)) {
    $host = "localhost"; $user = "u415354546_kiosco"; $pass = "Brg13abr"; $db = "u415354546_kiosco";
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
}

if (isset($_POST['imagenes_base64']) || isset($_POST['imagen_base64'])) {
    $fotos = isset($_POST['imagenes_base64']) ? $_POST['imagenes_base64'] : [$_POST['imagen_base64']];
    $monto_esperado = isset($_POST['monto_esperado']) ? floatval($_POST['monto_esperado']) : 0;
    
    $texto_crudo = "";
    
    foreach($fotos as $base64) {
        $ch = curl_init('https://api.ocr.space/parse/image');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'apikey' => 'helloworld', 
            'language' => 'spa', 
            'base64Image' => $base64, 
            'OCREngine' => '2',
            'scale' => 'true'
        ]);
        $res = curl_exec($ch); 
        curl_close($ch);
        $json = json_decode($res, true);
        
        if (isset($json['ParsedResults'][0]['ParsedText'])) {
            $texto_crudo .= " " . $json['ParsedResults'][0]['ParsedText'];
        }
    }
    
    if (trim($texto_crudo) !== "") {
        // --- 1. SUPER DICCIONARIO DE OCR ---
        $errores_comunes = [
            'Dtra transferent' => '', 'Otra transferencia' => '', 'Compartir comprobante' => '', 'Ir al inicio' => '',
            'Hacer otra transferencia' => '', 'Volver al inicio' => '', 'Agendar' => '', 'Finalizar' => '', 'Comprobante de transferencia' => '',
            'Numera' => 'Número', 'numera' => 'número', 'operecio' => 'operación', 'operacion' => 'operación',
            'Manios' => 'Varios', 'mertudo' => 'mercado', 'fago' => 'pago', 'mercedo' => 'mercado',
            'Comprabante' => 'Comprobante', 'Ccmprobante' => 'Comprobante', 'Transferencía' => 'transferencia',
            'referencía' => 'referencia', 'referencla' => 'referencia',
            'CVL:' => 'CVU:', 'CBL:' => 'CBU:', 'CBUU' => 'CBU', 'CVUU' => 'CVU', 
            'GUIL' => 'CUIL', 'CUIU' => 'CUIL', 'CUITÍCUIL' => 'CUIT/CUIL', 'CUlT' => 'CUIT',
            'nro' => 'Número', 'Nro' => 'Número', 'rnonto' => 'monto', 'Manta' => 'Monto',
            'Banca' => 'Banco', 'Bancc' => 'Banco', 'Ganco' => 'Banco',
            'Galicía' => 'Galicia', 'Gelicia' => 'Galicia', 'Calicia' => 'Galicia',
            'Santan' => 'Santander', 'Sentander' => 'Santander', 'Nacion' => 'Nación', 
            'Macroc' => 'Macro', 'Brubank' => 'Brubank', 'Bruank' => 'Brubank',
            'Paga' => 'Pago', 'Exítosa' => 'Exitosa', 'Exilosa' => 'Exitosa', 'Exilasa' => 'Exitosa',
            'reallzada' => 'realizada', 'reaIizada' => 'realizada', 
            '0peracion' => 'Operación', 'Qperacion' => 'Operación'
        ];
        $t = str_ireplace(array_keys($errores_comunes), array_values($errores_comunes), $texto_crudo);
        $t = str_replace(["\r", "\n"], "  ", $t); 

        // --- 2. EXTRACCIÓN GLOBAL Y FILTROS ---
        preg_match('/\$\s?([0-9]+(?:\.[0-9]{3})*(?:,[0-9]{1,2})?|\d+)/', $t, $m_gen);
        $monto_str_gen = $m_gen[1] ?? "0";
        
        $monto = "0.00";
        $banco_detectado = 'Otro Banco';
        $nom_e = 'No detectado';
        $nom_r = 'No detectado';
        $nro_op = 'S/N';

        // --- NUEVA LÓGICA INTELIGENTE DE CBU/CVU ---
        preg_match_all('/\b\d{22}\b/', $t, $cbus);
        $cbus_unicos = array_values(array_unique($cbus[0])); 
        $cbu_e = '---'; $cbu_r = '---';
        
        if (count($cbus_unicos) >= 2) {
            $cbu_e = $cbus_unicos[0];
            $cbu_r = $cbus_unicos[1];
        } elseif (count($cbus_unicos) == 1) {
            $cbu_r = $cbus_unicos[0];
        }

        if (preg_match('/(?:origen|desde|remitente)[^\d]*(\d{22})/i', $t, $m)) $cbu_e = $m[1];
        if (preg_match('/(?:destino|para|destinatario|a su cuenta)[^\d]*(\d{22})/i', $t, $m)) $cbu_r = $m[1];


        // --- NUEVA LÓGICA INTELIGENTE DE CUIT/DNI ---
        preg_match_all('/\b\d{7,11}\b/', $t, $docs);
        $docs_unicos = array_values(array_unique($docs[0])); 
        $doc_e = '---'; $doc_r = '---';
        
        if (count($docs_unicos) >= 2) {
            $doc_e = $docs_unicos[0];
            $doc_r = $docs_unicos[1];
        } elseif (count($docs_unicos) == 1) {
            $doc_r = $docs_unicos[0];
        }

        if (preg_match('/(?:origen|desde|remitente)[^\d]*(\d{7,11})/i', $t, $m)) $doc_e = $m[1];
        if (preg_match('/(?:destino|para|destinatario)[^\d]*(\d{7,11})/i', $t, $m)) $doc_r = $m[1];


        // --- 3. REGLAS BLINDADAS POR BANCO ---
        if (stripos($t, 'Mercado Pago') !== false || stripos($t, 'MercadoPago') !== false) {
            $banco_detectado = 'Mercado Pago';
            if (preg_match('/\$\s*([\d\.\,]+)\s*Motivo/i', $t, $m)) $monto_str_gen = trim($m[1]);
            if (preg_match('/De\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CUIT|CVU|Mercado)/i', $t, $match)) $nom_e = trim($match[1]);
            if (preg_match('/Para\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CUIT|CVU|Mercado)/i', $t, $match)) $nom_r = trim($match[1]);
            if (preg_match('/operaci[oó]n.*?\s+(\d{9,})/i', $t, $match)) $nro_op = trim($match[1]);
        } 
        elseif (stripos($t, 'BNA+') !== false || (stripos($t, 'Transferencia exitosa') !== false && stripos($t, 'Cuenta origen') !== false)) {
            $banco_detectado = 'Banco Nación';
            if (preg_match('/Monto\s*\$\s*([\d\.\,]+)/i', $t, $m)) $monto_str_gen = trim($m[1]);
            if (preg_match('/Destinatario\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*CUIT/i', $t, $match)) { $nom_r = trim($match[1]); } elseif (preg_match('/Para\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:Alias|CUIT|Banco)/i', $t, $match)) { $nom_r = trim($match[1]); }
            if (preg_match('/transacci[oó]n\s*([A-Z0-9]+)/i', $t, $match)) $nro_op = trim($match[1]);
            $nom_e = 'Cliente BNA'; 
        }
        elseif (stripos($t, 'Supervielle') !== false) {
            $banco_detectado = 'Supervielle';
            if (preg_match('/enviado\s*\$\s*([0-9\.\s]+)/i', $t, $m)) {
                $m_sup = preg_replace('/[^\d]/', '', $m[1]);
                if (strlen($m_sup) >= 3) { $monto_str_gen = substr($m_sup, 0, -2) . '.' . substr($m_sup, -2); } else { $monto_str_gen = $m_sup . '.00'; }
            }
            if (preg_match('/origen\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CUIT|Supervielle)/i', $t, $match)) $nom_e = trim($match[1]);
            if (preg_match('/destino\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CBU|CUIT|Naci[oó]n|Banco|Provincia)/i', $t, $match)) $nom_r = trim($match[1]);
            if (preg_match('/control[^\d]*(\d+)/i', $t, $match)) $nro_op = trim($match[1]); 
        }
        elseif (stripos($t, 'Uala') !== false || stripos($t, 'Ualá') !== false) {
            $banco_detectado = 'Ualá';
            if (preg_match('/remitente\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:Concepto|Id Op)/i', $t, $match)) $nom_e = trim($match[1]);
            if (preg_match('/destino\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CBU|CUIT)/i', $t, $match)) $nom_r = trim($match[1]);
            if (preg_match('/Id Op\.?\s*([A-Z0-9]+)/i', $t, $match)) $nro_op = trim($match[1]);
        }
        elseif (stripos($t, 'Macro') !== false) {
            $banco_detectado = 'Macro';
            if (preg_match('/Beneficiario:\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CUIT|Banco)/i', $t, $match)) $nom_r = trim($match[1]);
            $nom_e = 'Cliente Macro';
        }
        elseif (stripos($t, 'MODO') !== false) {
            $banco_detectado = 'MODO';
            if (preg_match('/Transferencia de\s*([A-ZÁÉÍÓÚÑa-z\,\s]+?)\s*Desde/i', $t, $match)) $nom_e = trim($match[1]);
            if (preg_match('/Para\s*([A-ZÁÉÍÓÚÑa-z\,\s]+?)\s*A su cuenta/i', $t, $match)) $nom_r = trim($match[1]);
            if (preg_match('/Ref\.?\s*([a-zA-Z0-9\-]+)/i', $t, $match)) $nro_op = trim($match[1]);
        }
        // --- REGLA DEFINITIVA PARA CUENTA DNI ---
        elseif (stripos($t, 'Cuenta DNI') !== false || stripos($t, 'Le transferiste a:') !== false || preg_match('/de\s+referencia/i', $t)) {
            $banco_detectado = 'Cuenta DNI / Pcia';
            
            if (preg_match('/Importe\s*\$\s*([\d\.\,]+)/i', $t, $m)) $monto_str_gen = trim($m[1]);
            
            if (preg_match('/Le transferiste a:?\s*([A-ZÁÉÍÓÚÑa-z\,\s]+?)\s*(?:CUIL|CUIT|Importe|Agendar)/i', $t, $match)) {
                $nom_r = trim($match[1]); 
            } elseif (preg_match('/Para\s+([A-ZÁÉÍÓÚÑa-z\,\s]+?)\s+(?:Alias|CUIL|CUIT|Motivo)/i', $t, $match)) {
                $nom_r = trim($match[1]); 
            }
            
            if (preg_match('/Origen\s+([A-ZÁÉÍÓÚÑa-z\,\s]+?)\s*\d/i', $t, $match)) {
                $nom_e = trim($match[1]);
            } else {
                $nom_e = 'Cliente Cuenta DNI'; 
            }
            
            // Atrapa el Número de Operación buscando estrictamente "de referencia" para saltarse el "Referencia Varios" de arriba.
            if (preg_match('/de\s+referencia.*?([0-9oO]{6,15})/i', $t, $match)) {
                $nro_op = str_ireplace(['O', 'o'], '0', trim($match[1]));
            }
        }

        // --- 4. CORRECCIÓN MATEMÁTICA DEL MONTO ---
        if (strpos($monto_str_gen, ',') !== false) {
            $monto_str_gen = str_replace('.', '', $monto_str_gen); 
            $monto_str_gen = str_replace(',', '.', $monto_str_gen); 
        } else {
            if (preg_match('/\.(\d{3})$/', $monto_str_gen)) {
                $monto_str_gen = str_replace('.', '', $monto_str_gen); 
            }
        }
        $monto = is_numeric($monto_str_gen) ? floatval($monto_str_gen) : 0;

        // --- 5. COMODÍN EXTREMO PARA NÚMERO DE OPERACIÓN ---
        if ($nro_op === 'S/N') {
            // Ahora atrapa números desde 6 dígitos (antes era mínimo 10)
            preg_match_all('/\b[0-9oO]{6,22}\b/i', $t, $ops);
            foreach($ops[0] as $posible_op) {
                $posible_op = str_ireplace('O', '0', strtoupper($posible_op));
                
                if ($posible_op != $doc_e && $posible_op != $doc_r && $posible_op != $cbu_e && $posible_op != $cbu_r) {
                    // Ignoramos si parece una fecha de 8 dígitos (ej: 18032026)
                    if (strlen($posible_op) == 8 && (strpos($posible_op, '202') === 4 || strpos($posible_op, '202') === 0)) continue;
                    
                    $nro_op = $posible_op; break;
                }
            }
        }

        $nom_e = trim(preg_replace('/\s{2,}.*/', '', $nom_e));
        $nom_r = trim(preg_replace('/\s{2,}.*/', '', $nom_r));

        if ($nom_e === 'No detectado' || $nom_r === 'No detectado' || $nom_e === '' || $nom_r === '') {
            preg_match_all('/\b[A-ZÁÉÍÓÚÑa-z]{3,}\s[A-ZÁÉÍÓÚÑa-z]{3,}(?:\s[A-ZÁÉÍÓÚÑa-z]{3,})?\b/', $t, $posibles);
            $candidatos = [];
            $blacklist_nombres = '/TRANSFERENCIA|EXITOSA|COMPROBANTE|DETALLE|FECHA|HORA|MONTO|PESOS|BANCO|LUNES|MARTES|MIERCOLES|JUEVES|VIERNES|SABADO|DOMINGO|ORIGEN|DESTINO|CUENTA|TITULAR|CBU|CVU|CUIT|CUIL|DOCUMENTO|ESTADO|OPERACION|MERCADO|PAGO/i';
            
            foreach ($posibles[0] as $p) {
                if (!preg_match($blacklist_nombres, $p)) $candidatos[] = trim($p);
            }
            if ($nom_e === 'No detectado' || $nom_e === '') $nom_e = $candidatos[0] ?? 'No detectado';
            if ($nom_r === 'No detectado' || $nom_r === '') $nom_r = $candidatos[1] ?? 'No detectado';
        }

        // --- CONTROL DE LISTA NEGRA (ANTIFRAUDE) ---
        if ($cbu_e !== '---' && $cbu_e !== '') {
            $stmt_black = $conn->prepare("SELECT id FROM lista_negra_cbu WHERE cbu = ?");
            $stmt_black->bind_param("s", $cbu_e);
            $stmt_black->execute();
            if ($stmt_black->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'msg' => "BLOQUEADO: El CBU detectado ($cbu_e) está en la Lista Negra por fraude."]);
                exit;
            }
        }

        // --- 6. GUARDAR LA PRIMER FOTO EN FÍSICO ---
        $image_parts = explode(";base64,", $fotos[0]);
        $image_type_aux = explode("image/", $image_parts[0]);
        $image_type = $image_type_aux[1] ?? 'jpg';
        $image_base64_decode = base64_decode($image_parts[1] ?? $image_parts[0]);
        
        $directorio_destino = '../uploads/comprobantes/';
        if (!file_exists($directorio_destino)) mkdir($directorio_destino, 0777, true);
        
        $nombre_archivo = 'comprobante_' . time() . '_' . rand(1000, 9999) . '.' . $image_type;
        $ruta_fisica = $directorio_destino . $nombre_archivo;
        file_put_contents($ruta_fisica, $image_base64_decode);
        $ruta_db = 'uploads/comprobantes/' . $nombre_archivo;

        // --- 7. GUARDADO FINAL ---
        $datos_excel = json_encode([
            'op' => $nro_op, 'nom_e' => $nom_e, 'doc_e' => $doc_e, 'cbu_e' => $cbu_e, 'banco_e' => $banco_detectado,
            'nom_r' => $nom_r, 'doc_r' => $doc_r, 'cbu_r' => $cbu_r
        ], JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO transferencias (monto, datos_json, texto_completo, imagen_base64) 
                VALUES ('$monto', '$datos_excel', '".$conn->real_escape_string($t)."', '".$conn->real_escape_string($ruta_db)."')";
        
        if ($conn->query($sql)) {
            $id_insertado = $conn->insert_id;
            
            // LÓGICA DE AUDITORÍA: Cruzar montos
            if ($monto_esperado > 0 && abs($monto - $monto_esperado) > 0.99) {
                echo json_encode([
                    'status' => 'warning',
                    'id_transferencia' => $id_insertado,
                    'monto_leido' => number_format($monto, 2, ',', '.'),
                    'monto_esperado' => number_format($monto_esperado, 2, ',', '.'),
                    'msg' => 'Discrepancia detectada'
                ]);
            } else {
                echo json_encode(['status' => 'success', 'id_transferencia' => $id_insertado, 'msg' => 'Validación OK']);
            }
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Error SQL al guardar.']);
        }
    } else { 
        echo json_encode(['status' => 'error', 'msg' => 'Error IA: No se pudo extraer texto del comprobante. Asegúrese que la imagen sea nítida.']); 
    }
} else {
    echo json_encode(['status' => 'error', 'msg' => 'No se enviaron imágenes.']);
}
?>