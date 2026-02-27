-- =================================================================
-- SCRIPT DE SIMULACIÓN "EL 10" - PARTE 1 (CATÁLOGO, CLIENTES, GASTOS)
-- =================================================================

SET FOREIGN_KEY_CHECKS=0;

-- 1. CREACIÓN DE CATEGORÍAS PESABLES
INSERT IGNORE INTO categorias (id, nombre, icono_web, activo) VALUES 
(5, 'Fiambrería', 'box', 1), 
(6, 'Verdulería', 'box', 1),
(7, 'Carnicería', 'box', 1);

-- 2. AJUSTE DE PRECIOS REALES A TUS PRODUCTOS ACTUALES (Para márgenes realistas)
UPDATE productos SET precio_costo = 2500, precio_venta = 3800 WHERE id = 2; -- Coca 2.25L
UPDATE productos SET precio_costo = 2600, precio_venta = 3900 WHERE id = 3; -- Coca Zero
UPDATE productos SET precio_costo = 11500, precio_venta = 16500, precio_oferta = 15000 WHERE id = 4; -- Fernet Branca
UPDATE productos SET precio_costo = 1300, precio_venta = 2100 WHERE id = 5; -- Cerveza Quilmes
UPDATE productos SET precio_costo = 400, precio_venta = 750 WHERE id = 8; -- Guaymallén
UPDATE productos SET precio_costo = 4200, precio_venta = 5500 WHERE id = 18; -- Marlboro Box 20
UPDATE productos SET precio_costo = 3800, precio_venta = 4900 WHERE id = 20; -- Camel Box 20

-- 3. INYECCIÓN DE PRODUCTOS PESABLES (Para mostrar el poder de la balanza)
INSERT IGNORE INTO productos (id, codigo_barras, plu, descripcion, id_categoria, id_proveedor, tipo, precio_costo, precio_venta, stock_actual, stock_minimo, tara_defecto, activo) VALUES
(101, '2000101000000', 101, 'Queso Tybo Barra (x Kg)', 5, 3, 'pesable', 6500.00, 9800.00, 15.500, 3.000, 0.015, 1),
(102, '2000102000000', 102, 'Jamón Cocido Paladini (x Kg)', 5, 3, 'pesable', 8200.00, 13500.00, 8.200, 2.000, 0.015, 1),
(103, '2000103000000', 103, 'Salame Milán (x Kg)', 5, 3, 'pesable', 9500.00, 15000.00, 5.000, 1.500, 0.015, 1),
(104, '2000104000000', 104, 'Tomate Perita (x Kg)', 6, 2, 'pesable', 900.00, 1800.00, 45.000, 10.000, 0.000, 1),
(105, '2000105000000', 105, 'Cebolla Blanca (x Kg)', 6, 2, 'pesable', 600.00, 1200.00, 60.000, 15.000, 0.000, 1);

-- 4. NUEVOS CLIENTES FIDELIZADOS (Con cuenta corriente y puntos)
INSERT IGNORE INTO clientes (id, nombre, dni_cuit, email, telefono, limite_credito, saldo_deudor, puntos_acumulados, fecha_registro, es_vip) VALUES 
(20, 'Carlos Menem', '10111222', 'carlos.m@test.com', '1144445555', 50000.00, 15000.00, 150, '2026-01-05 10:00:00', 1),
(21, 'Susana Giménez', '12333444', 'su.gimenez@test.com', '1155556666', 100000.00, 0.00, 320, '2026-01-10 12:30:00', 1),
(22, 'Ricardo Darín', '14555666', 'ricardo.d@test.com', '1166667777', 20000.00, 5000.00, 45, '2026-01-15 16:45:00', 0),
(23, 'Mirtha Legrand', '09888777', 'mirtha.l@test.com', '1177778888', 0.00, 0.00, 890, '2026-01-20 09:15:00', 1),
(24, 'Lionel Messi', '30111222', 'leo.messi@test.com', '1188889999', 500000.00, 0.00, 1000, '2026-02-01 18:20:00', 1);

-- 5. SIMULACIÓN DE GASTOS OPERATIVOS DEL MES
INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES 
('Pago Alquiler Local Febrero', 350000.00, 'Alquiler', '2026-02-05 10:00:00', 1, 1),
('Luz Edenor', 85000.00, 'Servicios', '2026-02-10 11:30:00', 1, 1),
('Internet y Teléfono', 25000.00, 'Servicios', '2026-02-12 09:15:00', 1, 1),
('Insumos: Bolsas Camiseta y Rollos', 18000.00, 'Insumos', '2026-02-15 14:20:00', 1, 1),
('Retiro de Ganancias', 150000.00, 'Retiro', '2026-02-20 18:00:00', 1, 1);

-- 6. MERMAS (Desperdicios y Vencimientos para rellenar reportes)
INSERT INTO mermas (id_producto, cantidad, motivo, fecha, id_usuario) VALUES 
(102, 0.250, 'Vencido (Jamón oscuro)', '2026-02-10 08:30:00', 1),
(5, 2.000, 'Roto (Botellas rotas en depósito)', '2026-02-15 16:45:00', 2),
(8, 5.000, 'Vencido en góndola', '2026-02-22 09:10:00', 2);

-- 7. ENCUESTAS DE SATISFACCIÓN (Data para el gráfico de torta)
INSERT INTO encuestas (nivel, comentario, cliente_nombre, contacto, fecha) VALUES 
(5, 'La fiambrería está impecable y rápida', 'Susana Giménez', '1155556666', '2026-01-12 10:00:00'),
(5, 'Excelente atención del cajero', 'Anónimo', '', '2026-01-25 15:30:00'),
(4, 'Todo muy bien pero faltaba cambio', 'Carlos Menem', '1144445555', '2026-02-08 19:45:00'),
(5, 'Me encanta el sistema de puntos', 'Lionel Messi', '1188889999', '2026-02-20 20:00:00');

-- 8. AUDITORÍA E INFLACIÓN MASIVA (Historial de aumentos)
INSERT INTO historial_inflacion (fecha, porcentaje, accion, grupo_afectado, cantidad_productos, id_usuario) VALUES 
('2026-01-15 10:00:00', 12.00, 'Aumento', 'Categoría: Fiambrería', 15, 1),
('2026-02-10 11:00:00', 8.50, 'Aumento', 'Categoría: Bebidas', 42, 1);
    
INSERT INTO auditoria (fecha, id_usuario, accion, detalles) VALUES 
('2026-01-15 10:00:00', 1, 'INFLACION', 'Aumento masivo del 12.00% a Categoría: Fiambrería'),
('2026-02-10 11:00:00', 1, 'INFLACION', 'Aumento masivo del 8.50% a Categoría: Bebidas'),
('2026-02-15 16:45:00', 2, 'MERMA', 'Baja de stock: 2.000 unid. | Motivo: Roto (Botellas rotas en depósito)'),
('2026-02-20 18:00:00', 1, 'GASTO', 'Ticket de Gasto: Retiro de Ganancias | Monto: $150,000.00');

SET FOREIGN_KEY_CHECKS=1;