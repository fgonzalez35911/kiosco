<?php
// generar_tramite_afip.php
// HERRAMIENTA PARA GENERAR LA PRIVATE KEY Y EL CSR FÁCILMENTE
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $dn = [
        "countryName" => "AR",
        "stateOrProvinceName" => "Buenos Aires",
        "localityName" => "CABA",
        "organizationName" => $_POST['nombre'],
        "organizationalUnitName" => "Ventas",
        "commonName" => "AFIP " . $_POST['nombre'],
        "emailAddress" => "email@dominio.com"
    ];

    // 1. Generar Clave Privada
    $privkey = openssl_pkey_new([
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    ]);

    // 2. Generar CSR
    $csr = openssl_csr_new($dn, $privkey);

    // 3. Exportar
    openssl_pkey_export($privkey, $pkeyout);
    openssl_csr_export($csr, $csrout);

    // Forzar descarga de la KEY (Importante: El usuario se la queda)
    file_put_contents('privada.key', $pkeyout);
    
    $mensaje = "¡Archivos Generados!<br>1. Se guardó <b>privada.key</b> en tu carpeta (descargala si estás en hosting).<br>2. Copiá el texto de abajo y guardalo como <b>pedido.csr</b> para subir a AFIP.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Generador AFIP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-5">
    <div class="card shadow mx-auto" style="max-width: 600px;">
        <div class="card-header bg-primary text-white">Generador de Trámite AFIP</div>
        <div class="card-body">
            <?php if(isset($mensaje)): ?>
                <div class="alert alert-success"><?php echo $mensaje; ?></div>
                <label>Este es tu CSR (Copiá todo el bloque):</label>
                <textarea class="form-control mb-3" rows="10"><?php echo $csrout; ?></textarea>
                <a href="privada.key" class="btn btn-danger w-100" download>DESCARGAR LLAVE PRIVADA (.KEY)</a>
                <p class="small text-muted mt-2">Guardá este archivo .key como oro. Es el que vas a subir después al sistema.</p>
            <?php else: ?>
                <form method="POST">
                    <div class="mb-3">
                        <label>Nombre de tu Negocio / Razón Social</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Juan Perez" required>
                    </div>
                    <button type="submit" class="btn btn-success w-100">GENERAR ARCHIVOS</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>