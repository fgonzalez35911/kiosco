-- =================================================================
-- SCRIPT DE SIMULACIÓN "EL 10" - PARTE 2 (VENTAS, CAJAS, SORTEOS)
-- =================================================================

SET FOREIGN_KEY_CHECKS=0;

-- 1. HISTORIAL DE CAJAS (Aperturas y Cierres pasados)
INSERT INTO cajas_sesion (id, id_usuario, fecha_apertura, fecha_cierre, monto_inicial, monto_final, total_ventas, diferencia, estado) VALUES 
(10, 1, '2026-01-05 08:00:00', '2026-01-05 22:00:00', 5000.00, 215000.00, 210000.00, 0.00, 'cerrada'),
(11, 2, '2026-01-15 08:30:00', '2026-01-15 21:00:00', 10000.00, 350000.00, 345000.00, -5000.00, 'cerrada'),
(12, 1, '2026-02-10 07:45:00', '2026-02-10 22:30:00', 8000.00, 508000.00, 500000.00, 0.00, 'cerrada'),
(13, 2, '2026-02-20 08:00:00', '2026-02-20 20:00:00', 10000.00, 185000.00, 175000.00, 0.00, 'cerrada');

-- 2. HISTORIAL DE VENTAS
INSERT INTO ventas (id, codigo_ticket, id_caja_sesion, id_usuario, id_cliente, fecha, total, descuento_manual, metodo_pago, estado) VALUES 
(101, 'TK-000101', 10, 1, 1, '2026-01-05 10:15:00', 36800.00, 0.00, 'Efectivo', 'completada'),
(102, 'TK-000102', 10, 1, 20, '2026-01-05 18:30:00', 49500.00, 0.00, 'MP', 'completada'),
(103, 'TK-000103', 11, 2, 21, '2026-01-15 12:45:00', 66000.00, 0.00, 'CtaCorriente', 'completada'),
(104, 'TK-000104', 11, 2, 1, '2026-01-15 19:20:00', 18500.00, 0.00, 'Debito', 'completada'),
(105, 'TK-000105', 12, 1, 22, '2026-02-10 14:10:00', 150000.00, 5000.00, 'Mixto', 'completada'),
(106, 'TK-000106', 12, 1, 1, '2026-02-10 20:05:00', 13500.00, 0.00, 'Efectivo', 'completada'),
(107, 'TK-000107', 13, 2, 24, '2026-02-20 11:30:00', 82500.00, 0.00, 'Credito', 'completada'),
(108, 'TK-000108', 13, 2, 1, '2026-02-20 16:45:00', 21000.00, 0.00, 'Efectivo', 'completada'),
(109, 'TK-000109', 13, 1, 20, '2026-02-25 10:00:00', 41500.00, 0.00, 'MP', 'completada'),
(110, 'TK-000110', 13, 1, 1, '2026-02-26 09:15:00', 33000.00, 0.00, 'Efectivo', 'completada');

-- 3. DETALLE DE LOS TICKETS (Acá se cruza el costo vs ganancia para la calculadora)
-- Venta 101: 1 Fernet, 2 Coca Cola, 1.5kg Queso Tybo
INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal) VALUES 
(101, 4, 1.000, 16500.00, 11500.00, 16500.00),
(101, 2, 2.000, 3800.00, 2500.00, 7600.00),
(101, 101, 1.295, 9800.00, 6500.00, 12691.00);

-- Venta 102: 3 Fernet
INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal) VALUES 
(102, 4, 3.000, 16500.00, 11500.00, 49500.00);

-- Venta 103: 20 Cervezas
INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal) VALUES 
(103, 5, 20.000, 2100.00, 1300.00, 42000.00),
(103, 18, 5.000, 5500.00, 4200.00, 27500.00);

-- Venta 105 (Mixto grande): Fiambres y bebidas
INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal) VALUES 
(105, 102, 2.500, 13500.00, 8200.00, 33750.00),
(105, 103, 1.500, 15000.00, 9500.00, 22500.00),
(105, 4, 5.000, 16500.00, 11500.00, 82500.00),
(105, 2, 3.000, 3800.00, 2500.00, 11400.00);

-- Venta 107 (Messi): Muchas Cervezas y Fiambres
INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal) VALUES 
(107, 5, 25.000, 2100.00, 1300.00, 52500.00),
(107, 103, 2.000, 15000.00, 9500.00, 30000.00);

-- Venta 110:
INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal) VALUES 
(110, 4, 2.000, 16500.00, 11500.00, 33000.00);

-- 4. PAGOS (Especialmente para la venta Mixta 105)
INSERT INTO pagos_ventas (id_venta, metodo_pago, monto) VALUES 
(101, 'Efectivo', 36800.00),
(102, 'MP', 49500.00),
(103, 'CtaCorriente', 66000.00),
(104, 'Debito', 18500.00),
(105, 'Efectivo', 50000.00),
(105, 'MP', 100000.00),
(106, 'Efectivo', 13500.00),
(107, 'Credito', 82500.00),
(108, 'Efectivo', 21000.00),
(109, 'MP', 41500.00),
(110, 'Efectivo', 33000.00);

-- 5. MOVIMIENTOS CUENTA CORRIENTE (Fiados)
INSERT INTO movimientos_cc (id_cliente, id_venta, id_usuario, tipo, monto, concepto, fecha) VALUES 
(21, 103, 2, 'debe', 66000.00, 'Compra Ticket #103', '2026-01-15 12:45:00'),
(21, NULL, 1, 'haber', 30000.00, 'Pago parcial deuda en caja', '2026-01-20 10:00:00');

-- 6. SORTEOS Y RIFAS (Módulo Marketing)
-- Sorteo Finalizado (Un microondas)
INSERT INTO sorteos (id, titulo, descripcion, fecha_creacion, fecha_sorteo, precio_ticket, cantidad_tickets, estado, ganadores_json) VALUES 
(1, 'Gran Sorteo Microondas BGH', 'Sorteo por inauguración del nuevo local', '2026-01-01 10:00:00', '2026-01-30 20:00:00', 1000.00, 200, 'finalizado', '[{"posicion":1,"cliente":"Susana Gimenez","telefono":"1155556666","ticket":"084","premio":"Microondas BGH 20L"}]');

INSERT INTO sorteo_premios (id_sorteo, posicion, tipo, descripcion_externa, costo_externo) VALUES 
(1, 1, 'externo', 'Microondas BGH 20L', 120000.00);

-- Sorteo Activo (Combo Fernet)
INSERT INTO sorteos (id, titulo, descripcion, fecha_creacion, fecha_sorteo, precio_ticket, cantidad_tickets, estado) VALUES 
(2, 'Sorteo Fin de Semana', 'Combo Fernet Branca + 2 Cocas + Hielo', '2026-02-15 10:00:00', '2026-03-01 20:00:00', 500.00, 100, 'activo');

INSERT INTO sorteo_premios (id_sorteo, posicion, tipo, id_producto, costo_externo) VALUES 
(2, 1, 'interno', 46, 0.00);

-- Tickets vendidos para el Sorteo Activo
INSERT INTO sorteo_tickets (id_sorteo, id_cliente, numero_ticket) VALUES 
(2, 20, 1), (2, 20, 2), (2, 21, 5), (2, 24, 10), (2, 24, 11), (2, 22, 45);

-- 7. AUDITORÍA EXTRA (Para demostrar uso)
INSERT INTO auditoria (fecha, id_usuario, accion, detalles) VALUES 
('2026-01-30 20:05:00', 1, 'SORTEO_FINALIZADO', 'Se finalizó el sorteo: Gran Sorteo Microondas BGH'),
('2026-02-10 14:15:00', 1, 'VENTA', 'Venta #105 registrada | Total: $150,000.00 | Pago: Mixto');

SET FOREIGN_KEY_CHECKS=1;