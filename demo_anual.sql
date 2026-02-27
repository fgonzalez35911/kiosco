-- =================================================================
-- SCRIPT DE SIMULACIÓN "EL 10" - 1 AÑO COMPLETO DE HISTORIAL
-- =================================================================
SET FOREIGN_KEY_CHECKS=0;

-- 1. ACTUALIZAR COMBOS
UPDATE productos SET precio_costo = 15000, precio_venta = 21000, precio_oferta = 18000 WHERE id = 46; 

-- 2. ACTIVOS Y BIENES DE USO DEL LOCAL
INSERT INTO bienes_uso (nombre, marca, modelo, fecha_compra, costo_compra, estado, ubicacion) VALUES 
('Heladera Exhibidora 3 Puertas', 'Inelro', 'MT-430', '2024-11-10', 850000.00, 'bueno', 'Salón Principal'),
('Computadora POS Táctil', 'HP', 'ProDesk', '2025-01-15', 450000.00, 'nuevo', 'Caja 1'),
('Cortadora de Fiambre', 'Trinidad', '330mm', '2025-03-20', 320000.00, 'mantenimiento', 'Fiambrería'),
('Balanza Etiquetadora', 'Systel', 'Cuora Max', '2025-05-10', 180000.00, 'bueno', 'Fiambrería');

-- 3. CAJAS HISTÓRICAS ESPARCIDAS POR 2025 Y 2026
INSERT INTO cajas_sesion (id, id_usuario, fecha_apertura, fecha_cierre, monto_inicial, monto_final, total_ventas, diferencia, estado) VALUES 
(20, 1, '2025-03-15 08:00:00', '2025-03-15 22:00:00', 5000, 155000, 150000, 0, 'cerrada'),
(21, 2, '2025-05-20 08:00:00', '2025-05-20 22:00:00', 5000, 210000, 215000, -10000, 'cerrada'),
(22, 1, '2025-08-10 08:00:00', '2025-08-10 22:00:00', 10000, 310000, 300000, 0, 'cerrada'),
(23, 2, '2025-10-05 08:00:00', '2025-10-05 22:00:00', 10000, 430000, 420000, 0, 'cerrada'),
(24, 1, '2025-12-24 08:00:00', '2025-12-24 20:00:00', 20000, 850000, 830000, 0, 'cerrada'),
(25, 2, '2026-01-20 08:00:00', '2026-01-20 22:00:00', 15000, 415000, 400000, 0, 'cerrada');

-- 4. VENTAS DISTRIBUIDAS EN EL AÑO
INSERT INTO ventas (id, codigo_ticket, id_caja_sesion, id_usuario, id_cliente, fecha, total, metodo_pago, estado) VALUES 
(201, 'TK-250315', 20, 1, 1, '2025-03-15 14:30:00', 150000.00, 'Efectivo', 'completada'),
(202, 'TK-250520', 21, 2, 20, '2025-05-20 18:45:00', 215000.00, 'MP', 'completada'),
(203, 'TK-250810', 22, 1, 21, '2025-08-10 20:15:00', 300000.00, 'Debito', 'completada'),
(204, 'TK-251005', 23, 2, 1, '2025-10-05 11:10:00', 420000.00, 'Mixto', 'completada'),
(205, 'TK-251224', 24, 1, 24, '2025-12-24 16:00:00', 830000.00, 'Credito', 'completada'),
(206, 'TK-260120', 25, 2, 1, '2026-01-20 19:30:00', 400000.00, 'Efectivo', 'completada');

-- DETALLES DE LAS VENTAS
INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal) VALUES 
(201, 4, 10, 15000, 10000, 150000),
(202, 5, 100, 2150, 1200, 215000),
(203, 4, 20, 15000, 10000, 300000),
(204, 102, 20, 13000, 8000, 260000),
(204, 103, 10, 16000, 9000, 160000),
(205, 4, 55, 15090, 10000, 830000),
(206, 5, 186, 2150, 1200, 400000);

-- PAGOS DE ESAS VENTAS
INSERT INTO pagos_ventas (id_venta, metodo_pago, monto) VALUES 
(201, 'Efectivo', 150000), (202, 'MP', 215000), (203, 'Debito', 300000),
(204, 'Efectivo', 200000), (204, 'MP', 220000), (205, 'Credito', 830000), (206, 'Efectivo', 400000);

-- 5. DEVOLUCIONES (Conectadas a ventas reales)
INSERT INTO devoluciones (id_venta_original, id_producto, cantidad, monto_devuelto, motivo, fecha, id_usuario) VALUES 
(201, 4, 1.000, 15000.00, 'Botella rajada al entregar', '2025-03-16 10:00:00', 1),
(204, 103, 0.500, 8000.00, 'Cliente se arrepintió del peso', '2025-10-05 11:30:00', 2);

-- 6. GASTOS E INFLACIÓN DISTRIBUIDOS
INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES 
('Mantenimiento Cortadora Fiambre', 45000.00, 'Mantenimiento', '2025-04-10 10:00:00', 1, 20),
('Pago Contador', 35000.00, 'Honorarios', '2025-07-05 10:00:00', 1, 21),
('Publicidad Redes Sociales', 20000.00, 'Marketing', '2025-11-15 10:00:00', 1, 22);

INSERT INTO historial_inflacion (fecha, porcentaje, accion, grupo_afectado, cantidad_productos, id_usuario) VALUES 
('2025-04-01 10:00:00', 15.00, 'Aumento', 'General', 85, 1),
('2025-08-15 10:00:00', 10.00, 'Aumento', 'Bebidas', 40, 1),
('2025-12-01 10:00:00', 25.00, 'Aumento', 'Fiambres', 20, 1);

INSERT INTO mermas (id_producto, cantidad, motivo, fecha, id_usuario) VALUES 
(101, 1.500, 'Queso en mal estado', '2025-06-20 10:00:00', 1),
(8, 15.000, 'Caja de alfajores aplastada', '2025-09-10 10:00:00', 2);

SET FOREIGN_KEY_CHECKS=1;