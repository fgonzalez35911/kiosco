<?php
// includes/interfaz_helper.php

/**
 * Muestra alertas basadas en parámetros de la URL usando SweetAlert2.
 */
function mostrarAlertasURL() {
    if (!isset($_GET['msg']) && !isset($_GET['error'])) return '';

    $script = "<script>
        document.addEventListener('DOMContentLoaded', function() {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });";

    if (isset($_GET['msg'])) {
        $msg = $_GET['msg'];
        if ($msg == 'borrado' || $msg == 'eliminado') {
            $script .= "Toast.fire({ icon: 'success', title: 'Registro eliminado correctamente' });";
        } elseif ($msg == 'pass_ok') {
            $script .= "Toast.fire({ icon: 'success', title: 'Operación realizada con éxito' });";
        }
    }

    if (isset($_GET['error'])) {
        $err = $_GET['error'];
        if ($err == 'tiene_datos') {
            $script .= "Swal.fire('Atención', 'No se puede eliminar: tiene registros vinculados.', 'warning');";
        } elseif ($err == 'db') {
            $script .= "Swal.fire('Error', 'Hubo un problema con la base de datos.', 'error');";
        }
    }

    $script .= "});</script>";
    return $script;
}

/**
 * Redondea el total de una venta según la configuración del sistema.
 * @param float $total El monto original.
 * @param bool $activo Si la función está prendida en configuracion.php
 * @return float El monto redondeado.
 */
function redondearVenta($total, $activo) {
    if (!$activo) return $total;
    // Redondeo a favor del comerciante (múltiplos de 5) para evitar monedas de centavos
    return ceil($total / 5) * 5;
}
?>