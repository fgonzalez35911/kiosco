<?php
header('Content-Type: text/html; charset=utf-8');
echo "<h3>Diagnóstico de Conexión AFIP</h3>";

if (!extension_loaded('soap')) {
    echo "<p style='color:red'>❌ ERROR: La extensión SOAP no está habilitada. Sin esto no hay factura electrónica.</p>";
} else {
    echo "<p style='color:green'>✅ La extensión SOAP está activa.</p>";
}

$url = "servicios1.afip.gov.ar";
$fp = @fsockopen($url, 443, $errno, $errstr, 5);
if (!$fp) {
    echo "<p style='color:red'>❌ ERROR de Conexión: $errstr ($errno). Tu servidor bloquea la salida a AFIP.</p>";
} else {
    echo "<p style='color:green'>✅ Conexión exitosa con los servidores de AFIP.</p>";
    fclose($fp);
}
?>