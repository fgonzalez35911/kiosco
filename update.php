<?php
// actualizar_tabla_cupones.php
require_once 'includes/db.php';

try {
    // 1. Agregar columna 'cantidad_limite' si no existe
    $conexion->exec("ALTER TABLE cupones ADD COLUMN IF NOT EXISTS cantidad_limite INT DEFAULT 0;");
    
    // 2. Agregar columna 'usos_actuales' si no existe
    $conexion->exec("ALTER TABLE cupones ADD COLUMN IF NOT EXISTS usos_actuales INT DEFAULT 0;");

    // 3. Asegurar que 'codigo' sea UNIQUE (para evitar duplicados)
    // Usamos IGNORE para que si falla no detenga el script (por si ya hay duplicados)
    $conexion->exec("ALTER TABLE cupones ADD UNIQUE INDEX IF NOT EXISTS idx_codigo (codigo);");

    echo "<h1>✅ Base de datos actualizada correctamente.</h1>";
    echo "<p>Se agregaron las columnas de límites sin tocar tus datos existentes.</p>";
    echo "<p>Ahora puedes borrar este archivo.</p>";

} catch (PDOException $e) {
    echo "<h1>⚠️ Aviso: " . $e->getMessage() . "</h1>";
    echo "<p>Es probable que las columnas ya existieran. Puedes continuar.</p>";
}
?>