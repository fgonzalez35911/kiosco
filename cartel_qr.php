<?php
// cartel_qr.php - VERSIÓN FINAL "LLUVIA DE FESTEJO" (Distribución uniforme en toda la hoja)
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php', '../includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

// 2. URL
$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$path = dirname($_SERVER['PHP_SELF']);
$url_registro = $protocolo . "://" . $host . $path . "/registro_cliente.php";
$url_registro = str_replace('//registro', '/registro', $url_registro);

// 3. DATOS
$logo = $conf['logo_url'] ?? 'img/logo_default.png';
$direccion = $conf['direccion_local'] ?? '';
$whatsapp = $conf['telefono_whatsapp'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cartel Club del 10</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    @page { size: auto; margin: 0; }
    :root { --azul-10: #102A57; --rojo-oferta: #dc3545; --celeste-arg: #75AADB; }
    * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; }
    body { background: #555; font-family: 'Arial', sans-serif; }
    
    /* CONTENEDOR PRINCIPAL */
    .hoja-a4 {
        background: white;
        width: 210mm;
        height: 270mm; /* Altura segura */
        margin: 0 auto;
        padding: 5mm 15mm; 
        position: relative;
        overflow: hidden;
    }

    /* --- CAPA 0: BANDERA FLAMEANDO DE FONDO --- */
    .bg-flag-layer {
        position: absolute;
        top: 50%; left: 50%;
        width: 140%; height: auto;
        transform: translate(-50%, -50%) rotate(-10deg);
        z-index: 0; opacity: 0.10; pointer-events: none;
    }

    /* --- CAPA 1: DECORACIÓN DE ICONOS (DENSIDAD ALTA Y DISTRIBUIDA) --- */
    .bg-decorations {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%; 
        z-index: 1; pointer-events: none; overflow: hidden;
    }
    .deco-icon { position: absolute; color: var(--azul-10); opacity: 0.08; } 

    /* --- CAPA 2: WRAPPER DEL CONTENIDO --- */
    .content-wrapper {
        position: relative; z-index: 2;
        width: 100%; height: 100%;
        display: flex; flex-direction: column; justify-content: space-between; align-items: center;
    }

    /* ESTILOS DEL CONTENIDO */
    .logo-box { text-align: center; width: 100%; padding-top: 10px; }
    .logo-img { max-height: 220px; object-fit: contain; } 

    .promo-text { text-align: center; margin: 0; position: relative; }
    .titulo-xxl { 
        font-size: 3.2rem; font-weight: 1000; color: #222; line-height: 1; text-transform: uppercase; 
        text-shadow: 2px 2px 0px rgba(0,0,0,0.1);
    } 
    .titulo-xxl span { color: var(--azul-10); }
    .sub-text { font-size: 1.5rem; color: #555; font-weight: 700; margin-top: 5px; }

    .arrow-box { color: var(--rojo-oferta); font-size: 3rem; margin-bottom: -15px; animation: bounce 2s infinite; }

    .qr-container-relative { position: relative; display: inline-block; }
    .qr-frame {
        border: 8px solid var(--azul-10); padding: 10px; border-radius: 25px; background: white; 
        box-shadow: 0 10px 20px rgba(0,0,0,0.15);
    }
    .qr-img { width: 230px; height: 230px; display: block; }

    .badge-free {
        position: absolute; top: -15px; right: -25px;
        background: var(--rojo-oferta); color: white;
        font-weight: 900; font-size: 1.2rem;
        padding: 5px 15px; transform: rotate(15deg);
        border-radius: 5px; box-shadow: 2px 2px 5px rgba(0,0,0,0.3);
        border: 2px solid white;
    }

    .footer-cta {
        background: var(--azul-10); color: white; font-size: 1.8rem; font-weight: 900;
        padding: 8px 50px; border-radius: 100px; margin-top: 15px; display: inline-block;
        box-shadow: 0 5px 15px rgba(16, 42, 87, 0.3);
    }

    .footer-address { 
        margin-top: 5px; border-top: 2px solid #eee; width: 100%; padding-top: 10px; color: #444; font-size: 1.1rem; 
        background: rgba(255,255,255,0.95);
        display: flex; justify-content: center; align-items: center; gap: 20px; flex-wrap: wrap;
    }
    .footer-item { display: flex; align-items: center; gap: 8px; font-weight: 700; }

    @media print {
        body { background: white; margin: 0; padding: 0; }
        .hoja-a4 {
            margin: 0 !important; padding: 5mm 15mm !important; border: none !important;
            width: 100% !important; height: 270mm !important;
            page-break-after: avoid !important; page-break-before: avoid !important;
        }
        .no-print { display: none !important; }
        .arrow-box { animation: none; }
    }
</style>
</head>
<body>

    <div class="text-center mt-3 mb-3 no-print">
        <button onclick="window.print()" class="btn btn-warning fw-bold px-5 shadow fs-4">
            <i class="bi bi-printer-fill"></i> IMPRIMIR
        </button>
        <a href="clientes.php" class="btn btn-dark ms-2 btn-lg">Volver</a>
    </div>

    <div class="hoja-a4">
        
        <img class="bg-flag-layer" src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA5MDAgNjAwIj4KICA8ZGVmcz4KICAgIDxsaW5lYXJHcmFkaWVudCBpZD0iYSIgeDE9IjAlIiB5MT0iMCUiIHgyPSIwJSIgeTI9IjEwMCUiPgogICAgICA8c3RvcCBvZmZzZXQ9IjAlIiBzdG9wLWNvbG9yPSIjNzVBQURCIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iMzMuMyUiIHN0b3AtY29sb3I9IiM3NUFBREIiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIzMy4zJSIgc3RvcC1jb2xvcj0iI2ZmZiIvPgogICAgICA8c3RvcCBvZmZzZXQ9IjY2LjclIiBzdG9wLWNvbG9yPSIjZmZmIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iNjYuNyUiIHN0b3AtY29sb3I9IiM3NUFBREIiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxMDAlIiBzdG9wLWNvbG9yPSIjNzVBQURCIi8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogICAgPGZpbHRlciBpZD0idyI+CiAgICAgIDxmZVR1cmJ1bGVuY2UgdHlwZT0iZnJhY3RhbE5vaXNlIiBiYXNlRnJlcXVlbmN5PSIuMDE1IiBudW1PY3RhdmVzPSIzIiByZXN1bHQ9Im4iLz4KICAgICAgPGZlRGlzcGxhY2VtZW50TWFwIGluPSJTb3VyY2VHcmFwaGljIiBpbjI9Im4iIHNjYWxlPSI1MCIgeENoYW5uZWxTZWxlY3Rvcj0iUiIgeUNoYW5uZWxTZWxlY3Rvcj0iRyIvPgogICAgPC9maWx0ZXI+CiAgPC9kZWZzPgogIDxyZWN0IHdpZHRoPSI5MDAiIGhlaWdodD0iNjAwIiBmaWxsPSJ1cmwoI2EpIiBmaWx0ZXI9InVybCgjdykiLz4KICA8Y2lyY2xlIGN4PSI0NTAiIGN5PSIzMDAiIHI9IjYwIiBmaWxsPSIjRjZCNDBFIiBmaWx0ZXI9InVybCgjdykiLz4KPC9zdmc+" alt="Bandera Argentina">

        <div class="bg-decorations">
            <i class="bi bi-trophy-fill deco-icon" style="top: 2%; left: 5%; font-size: 6rem; transform: rotate(-10deg);"></i>
            <i class="bi bi-star-fill deco-icon" style="top: 3%; right: 5%; font-size: 5rem; transform: rotate(15deg);"></i>
            <i class="bi bi-wine deco-icon" style="top: 5%; left: 45%; font-size: 4rem; opacity: 0.05;"></i>
            
            <i class="bi bi-cup-straw deco-icon" style="top: 15%; left: -2%; font-size: 7rem; transform: rotate(20deg);"></i>
            <i class="bi bi-dribbble deco-icon" style="top: 18%; right: 15%; font-size: 4rem; opacity: 0.06;"></i>
            <i class="bi bi-shop deco-icon" style="top: 20%; left: 25%; font-size: 5rem; opacity: 0.04;"></i>
            <i class="bi bi-award-fill deco-icon" style="top: 22%; right: -2%; font-size: 6rem;"></i>

            <i class="bi bi-cup-straw deco-icon" style="top: 32%; left: 10%; font-size: 5rem;"></i>
            <i class="bi bi-wine deco-icon" style="top: 35%; right: 35%; font-size: 4rem; transform: rotate(-10deg);"></i>
            <i class="bi bi-basket2-fill deco-icon" style="top: 38%; left: 60%; font-size: 5rem; opacity: 0.05;"></i>
            <i class="bi bi-star-half deco-icon" style="top: 42%; right: 5%; font-size: 3rem;"></i>

            <i class="bi bi-dribbble deco-icon" style="top: 48%; left: -5%; font-size: 8rem; opacity: 0.06;"></i>
            <i class="bi bi-cup-hot-fill deco-icon" style="top: 50%; right: 20%; font-size: 4rem;"></i>
            <i class="bi bi-wine deco-icon" style="top: 55%; left: 30%; font-size: 5rem; transform: rotate(15deg);"></i>
            <i class="bi bi-tag-fill deco-icon" style="top: 58%; right: -2%; font-size: 4rem; transform: rotate(45deg);"></i>

            <i class="bi bi-cup-straw deco-icon" style="top: 65%; left: 5%; font-size: 6rem;"></i>
            <i class="bi bi-gift-fill deco-icon" style="top: 68%; right: 40%; font-size: 5rem;"></i>
            <i class="bi bi-trophy-fill deco-icon" style="top: 70%; right: 5%; font-size: 4rem; transform: rotate(-5deg);"></i>
            <i class="bi bi-bag-heart-fill deco-icon" style="top: 72%; left: 45%; font-size: 4rem; opacity: 0.05;"></i>

            <i class="bi bi-cart-fill deco-icon" style="bottom: 15%; left: -2%; font-size: 7rem;"></i>
            <i class="bi bi-wine deco-icon" style="bottom: 12%; right: 15%; font-size: 5rem; transform: rotate(-10deg);"></i>
            <i class="bi bi-star-fill deco-icon" style="bottom: 8%; left: 25%; font-size: 4rem;"></i>
            <i class="bi bi-cup-straw deco-icon" style="bottom: 5%; right: -2%; font-size: 8rem; transform: rotate(10deg);"></i>
            <i class="bi bi-upc-scan deco-icon" style="bottom: 2%; left: 50%; font-size: 5rem; opacity: 0.04;"></i>
        </div>

        <div class="content-wrapper">
            <div class="logo-box">
                <?php if(!empty($conf['logo_url'])): ?>
                    <img src="<?php echo htmlspecialchars($logo); ?>" class="logo-img" alt="Logo">
                <?php endif; ?>
            </div>

            <div class="promo-text">
                <div class="titulo-xxl">¡UNITE AL<br><span>CLUB DEL 10!</span></div>
                <div class="sub-text">
                    <i class="bi bi-gift-fill text-danger"></i> Y empezá a recibir premios exclusivos
                </div>
            </div>

            <div class="text-center" style="margin-top: 20px;">
                <div class="arrow-box"><i class="bi bi-arrow-down-circle-fill"></i></div>
                
                <div class="qr-container-relative">
                    <div class="qr-frame">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?php echo urlencode($url_registro); ?>" class="qr-img" alt="QR Registro">
                    </div>
                    <div class="badge-free">¡ES GRATIS!</div>
                </div>

                <div>
                    <div class="footer-cta">ESCANEÁ AQUÍ</div>
                </div>
            </div>

            <div class="footer-address">
                <?php if(!empty($direccion)): ?>
                    <div class="footer-item"><i class="bi bi-geo-alt-fill text-danger fs-4"></i> <?php echo htmlspecialchars($direccion); ?></div>
                <?php endif; ?>
                
                <?php if(!empty($whatsapp)): ?>
                    <div class="footer-item"><i class="bi bi-whatsapp text-success fs-4"></i> <?php echo htmlspecialchars($whatsapp); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
