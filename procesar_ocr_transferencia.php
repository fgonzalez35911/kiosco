<?php
error_reporting(0);
ini_set('display_errors', 0);
include_once '../includes/db.php'; 

if (isset($conexion) && !isset($conn)) {
    $host = "localhost"; $user = "u415354546_kiosco"; $pass = "Brg13abr"; $db = "u415354546_kiosco";
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset("utf8mb4");
}

if (isset($_POST['imagenes_base64']) || isset($_POST['imagen_base64'])) {
    $fotos = isset($_POST['imagenes_base64']) ? $_POST['imagenes_base64'] : [$_POST['imagen_base64']];
    $texto_crudo = "";
    
    // Leemos todas las fotos que haya mandado el cajero
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
            // Borramos los botones de las apps para que no los confunda con nombres de personas
            'Dtra transferent' => '', 'Otra transferencia' => '', 'Compartir comprobante' => '', 'Ir al inicio' => '',
            'Hacer otra transferencia' => '', 'Volver al inicio' => '', 'Agendar' => '', 'Finalizar' => '', 'Comprobante de transferencia' => '',
            
            // Nombres rotos comunes (El super diccionario de nombres)
            'Fedento' => 'Federico', 'Federlco' => 'Federico', 'Fedenico' => 'Federico',
            'Mancelo' => 'Marcelo', 'Gbnzalez' => 'Gonzalez', 'Gomzalez' => 'Gonzalez',
            'Beatrizcaceres' => 'Beatriz Caceres', 'Jaquellne' => 'Jaqueline',
            
            // Palabras clave rotas
            'Numera' => 'Número', 'numera' => 'número', 'operecio' => 'operación', 'operacion' => 'operación',
            'Manios' => 'Varios', 'mertudo' => 'mercado', 'fago' => 'pago', 'mercedo' => 'mercado',
            'Comprabante' => 'Comprobante', 'Ccmprobante' => 'Comprobante', 'Transferencía' => 'transferencia',
            'CVL:' => 'CVU:', 'CBL:' => 'CBU:', 'CBUU' => 'CBU', 'CVUU' => 'CVU', 
            'GUIL' => 'CUIL', 'CUIU' => 'CUIL', 'CUITÍCUIL' => 'CUIT/CUIL', 'CUlT' => 'CUIT',
            'nro' => 'Número', 'Nro' => 'Número', 'rnonto' => 'monto', 'Manta' => 'Monto',
            '|' => 'I', '[' => 'C', ']' => 'J', '{' => 'C', '}' => 'J',
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

        // Filtramos CBUs y DNIs únicos para que no se dupliquen si scaneas en 2 partes
        preg_match_all('/\b\d{22}\b/', $t, $cbus);
        $cbus_unicos = array_values(array_unique($cbus[0])); 
        $cbu_e = $cbus_unicos[0] ?? '---';
        $cbu_r = $cbus_unicos[1] ?? '---';

        preg_match_all('/\b\d{7,11}\b/', $t, $docs);
        $docs_unicos = array_values(array_unique($docs[0])); 
        $doc_e = $docs_unicos[0] ?? '---';
        $doc_r = $docs_unicos[1] ?? '---';

        // --- 3. REGLAS BLINDADAS POR BANCO ---
        if (stripos($t, 'Mercado Pago') !== false || stripos($t, 'MercadoPago') !== false) {
            $banco_detectado = 'Mercado Pago';
            if (preg_match('/\$\s*([\d\.\,]+)\s*Motivo/i', $t, $m)) $monto_str_gen = trim($m[1]);
            if (preg_match('/De\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CUIT|CVU|Mercado)/i', $t, $match)) $nom_e = trim($match[1]);
            if (preg_match('/Para\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CUIT|CVU|Mercado)/i', $t, $match)) $nom_r = trim($match[1]);
            if (preg_match('/operaci[oó]n.*?\s+(\d{9,})/i', $t, $match)) $nro_op = trim($match[1]);
        } 
        // Regla BNA (Atrapa tanto el PDF que dice "BNA+" como la pantalla verde que dice "Transferencia exitosa")
        elseif (stripos($t, 'BNA+') !== false || (stripos($t, 'Transferencia exitosa') !== false && stripos($t, 'Cuenta origen') !== false)) {
            $banco_detectado = 'Banco Nación';
            if (preg_match('/Monto\s*\$\s*([\d\.\,]+)/i', $t, $m)) $monto_str_gen = trim($m[1]);
            
            if (preg_match('/Destinatario\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*CUIT/i', $t, $match)) {
                $nom_r = trim($match[1]); // Atrapa en PDF
            } elseif (preg_match('/Para\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:Alias|CUIT|Banco)/i', $t, $match)) {
                $nom_r = trim($match[1]); // Atrapa en Pantalla Verde
            }
            if (preg_match('/transacci[oó]n\s*([A-Z0-9]+)/i', $t, $match)) $nro_op = trim($match[1]);
            $nom_e = 'Cliente BNA'; // La pantalla no lo muestra
        }
        elseif (stripos($t, 'Supervielle') !== false) {
            $banco_detectado = 'Supervielle';
            
            // Lector de centavos chiquitos de Supervielle (Ej: 595.00000 lo convierte a 595000.00)
            if (preg_match('/enviado\s*\$\s*([0-9\.\s]+)/i', $t, $m)) {
                $m_sup = preg_replace('/[^\d]/', '', $m[1]);
                if (strlen($m_sup) >= 3) { 
                    $monto_str_gen = substr($m_sup, 0, -2) . '.' . substr($m_sup, -2);
                } else {
                    $monto_str_gen = $m_sup . '.00';
                }
            }

            if (preg_match('/origen\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CUIT|Supervielle)/i', $t, $match)) $nom_e = trim($match[1]);
            if (preg_match('/destino\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CBU|CUIT|Naci[oó]n|Banco|Provincia)/i', $t, $match)) $nom_r = trim($match[1]);
            if (preg_match('/control[^\d]*(\d+)/i', $t, $match)) $nro_op = trim($match[1]); // Atrapa el 0622 aunque esté separado
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
        elseif (stripos($t, 'Cuenta DNI') !== false || stripos($t, 'Provincia') !== false) {
            $banco_detectado = 'Cuenta DNI';
            if (preg_match('/Destinatario\s*([A-ZÁÉÍÓÚÑa-z\s]+?)\s*(?:CUIT|Importe)/i', $t, $match)) $nom_r = trim($match[1]);
            if (preg_match('/operaci[oó]n\s*(\d+)/i', $t, $match)) $nro_op = trim($match[1]);
            $nom_e = 'Cliente Pcia';
        }

        // --- 4. CORRECCIÓN MATEMÁTICA DEL MONTO PARA LA BASE DE DATOS ---
        if (strpos($monto_str_gen, ',') !== false) {
            $monto_str_gen = str_replace('.', '', $monto_str_gen); // Quitamos puntos de miles
            $monto_str_gen = str_replace(',', '.', $monto_str_gen); // Cambiamos coma decimal a punto BD
        } else {
            // Si tiene punto, verificamos si son miles o decimales (Ej: MP $466.000)
            if (preg_match('/\.(\d{3})$/', $monto_str_gen)) {
                $monto_str_gen = str_replace('.', '', $monto_str_gen); // Son miles redondos
            }
        }
        $monto = is_numeric($monto_str_gen) ? $monto_str_gen : "0.00";

        // Comodín Número Operación
        if ($nro_op === 'S/N') {
            preg_match_all('/\b\d{10,18}\b/', $t, $ops);
            foreach($ops[0] as $posible_op) {
                if ($posible_op != $doc_e && $posible_op != $doc_r && $posible_op != $cbu_e && $posible_op != $cbu_r) {
                    $nro_op = $posible_op; break;
                }
            }
        }

        // --- 5. COMODÍN PARA NOMBRES VACÍOS ---
        $nom_e = trim(preg_replace('/\s{2,}.*/', '', $nom_e));
        $nom_r = trim(preg_replace('/\s{2,}.*/', '', $nom_r));

        if ($nom_e === 'No detectado' || $nom_r === 'No detectado' || $nom_e === '' || $nom_r === '') {
            preg_match_all('/\b[A-ZÁÉÍÓÚÑa-z]{3,}\s[A-ZÁÉÍÓÚÑa-z]{3,}(?:\s[A-ZÁÉÍÓÚÑa-z]{3,})?\b/', $t, $posibles);
            $candidatos = [];
            // Prohibimos Mercado y Pago para que no sobreescriba a las personas reales
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
                echo "BLOQUEADO: El CBU detectado ($cbu_e) está en la Lista Negra por fraude.";
                exit;
            }
        }

        // --- 5. GUARDAR LA PRIMER FOTO EN FÍSICO ---
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

        // --- 6. GUARDADO FINAL ---
        $datos_excel = json_encode([
            'op' => $nro_op, 'nom_e' => $nom_e, 'doc_e' => $doc_e, 'cbu_e' => $cbu_e, 'banco_e' => $banco_detectado,
            'nom_r' => $nom_r, 'doc_r' => $doc_r, 'cbu_r' => $cbu_r
        ], JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO transferencias (monto, datos_json, texto_completo, imagen_base64) 
                VALUES ('$monto', '$datos_excel', '".$conn->real_escape_string($t)."', '".$conn->real_escape_string($ruta_db)."')";
        
        echo ($conn->query($sql)) ? "OK" : "Error SQL";
    } else { echo "Error IA: No se pudo leer el comprobante."; }
}
?>
