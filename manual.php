<?php
// manual.php - DOCUMENTACIÓN OFICIAL VANGUARD PRO (ORDENADO EMPLEADO -> GERENCIA)
require_once 'includes/layout_header.php';
?>
<style>
    /* Estilos Premium para el Manual y corrección de Scroll en Celulares */
    body { position: relative; } 
    
    .manual-sidebar {
        position: sticky;
        top: 80px;
        max-height: calc(100vh - 100px);
        overflow-y: auto;
        overflow-x: hidden; 
    }
    .manual-sidebar::-webkit-scrollbar { width: 5px; }
    .manual-sidebar::-webkit-scrollbar-thumb { background-color: #ccc; border-radius: 10px; }
    
    .nav-manual .nav-link {
        color: #495057;
        font-weight: 500;
        padding: 8px 15px;
        border-left: 3px solid transparent;
        transition: all 0.2s ease;
        font-size: 0.95rem;
        white-space: normal !important; 
        word-wrap: break-word;
    }
    .nav-manual .nav-link:hover, .nav-manual .nav-link.active {
        color: var(--bs-primary);
        background-color: #f8f9fa;
        border-left-color: var(--bs-primary);
        font-weight: 700;
    }
    .nav-manual .nav-link.sub-link {
        font-size: 0.85rem;
        padding-left: 30px;
    }
    
    .seccion-manual {
        background: #fff;
        border-radius: 12px;
        padding: 30px;
        margin-bottom: 40px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        border: 1px solid #f0f0f0;
    }
    @media (max-width: 768px) {
        .seccion-manual { padding: 20px 15px; }
    }
    
    .seccion-titulo {
        font-weight: 900;
        color: #102A57;
        border-bottom: 3px solid #e9ecef;
        padding-bottom: 10px;
        margin-bottom: 25px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .sub-titulo {
        font-weight: 800;
        color: #343a40;
        margin-top: 35px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        white-space: normal;
        background: #f8f9fa;
        padding: 10px 15px;
        border-radius: 8px;
    }
    .captura-placeholder {
        background: #f8f9fa;
        border: 2px dashed #ced4da;
        border-radius: 8px;
        padding: 40px 20px;
        text-align: center;
        color: #6c757d;
        font-weight: bold;
        margin: 20px 0;
    }
    .paso-a-paso {
        background-color: #f8f9fa;
        border-left: 4px solid var(--bs-primary);
        padding: 20px;
        border-radius: 0 8px 8px 0;
        margin-top: 15px;
        margin-bottom: 15px;
    }
    .paso-a-paso h6 {
        color: var(--bs-primary);
        font-weight: 800;
        text-transform: uppercase;
        font-size: 0.9rem;
        margin-bottom: 15px;
    }
    .paso-a-paso ol { margin-bottom: 0; padding-left: 20px; }
    .paso-a-paso li { margin-bottom: 12px; color: #495057; line-height: 1.6; }
    
    /* CAJA DE EJEMPLO INFANTIL DIDÁCTICA */
    .ejemplo-didactico {
        background-color: #e3f2fd;
        border-left: 5px solid #0d6efd;
        padding: 20px;
        border-radius: 0 10px 10px 0;
        margin: 20px 0;
        font-size: 0.95rem;
        color: #084298;
    }
    .ejemplo-didactico strong { color: #052c65; font-weight: 900; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center mb-4">
        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 50px; height: 50px; flex-shrink: 0;">
            <i class="bi bi-journal-bookmark-fill fs-3"></i>
        </div>
        <div class="ms-3">
            <h2 class="font-cancha m-0 text-dark">Manual Operativo del Sistema</h2>
            <p class="text-muted m-0 small">La guía definitiva, explicada al detalle y con ejemplos simples.</p>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-3 mb-4">
            <div class="d-lg-none mb-3">
                <button class="btn btn-outline-primary w-100 fw-bold shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#indice-manual" aria-expanded="false">
                    <i class="bi bi-list-nested"></i> Ver Índice del Manual
                </button>
            </div>
            
            <nav id="indice-manual" class="manual-sidebar navbar flex-column align-items-stretch p-3 bg-white shadow-sm rounded-4 border collapse d-lg-block">
                
                <span class="text-uppercase text-muted small fw-bold mb-2 px-3 mt-2 text-primary">Operativa Diaria (Cajeros)</span>
                <nav class="nav nav-pills flex-column nav-manual w-100">
                    <a class="nav-link" href="#mod-caja">1. Ventas y Apertura de Caja</a>
                    <a class="nav-link sub-link" href="#caja-apertura">- ¿Cómo abrir la caja?</a>
                    <a class="nav-link sub-link" href="#caja-atajos">- Atajos y Operativa de Venta</a>
                    
                    <a class="nav-link mt-2" href="#mod-cobros">2. Métodos de Cobro</a>
                    <a class="nav-link sub-link" href="#cobro-efectivo">- Efectivo y Vuelto</a>
                    <a class="nav-link sub-link" href="#cobro-mp">- Mercado Pago (QR Interoperable)</a>
                    <a class="nav-link sub-link" href="#cobro-transf">- Transferencias (CBU y OCR)</a>
                    <a class="nav-link sub-link" href="#cobro-mixto">- Pagos Mixtos y Cta. Corriente</a>
                    
                    <a class="nav-link mt-2" href="#mod-clientes">3. Gestión de Clientes</a>
                    <a class="nav-link sub-link" href="#cli-alta">- Alta de Cliente y Puntos</a>
                    <a class="nav-link sub-link" href="#cli-baja">- Suspender o Eliminar</a>
                    
                    <a class="nav-link mt-2" href="#mod-productos">4. Productos y Combos</a>
                    <a class="nav-link sub-link" href="#prod-combos">- Crear un Combo (Escandallos)</a>
                    <a class="nav-link sub-link" href="#prod-pesables">- Vender Pesables y Taras</a>
                    
                    <a class="nav-link mt-2" href="#mod-pedidos">5. Tienda Web y Pedidos</a>
                    <a class="nav-link sub-link" href="#pedidos-aprobar">- Aprobar o Rechazar Pedidos</a>

                    <a class="nav-link mt-2" href="#mod-devoluciones">6. Devoluciones y Mermas</a>
                    <a class="nav-link sub-link" href="#dev-operacion">- Devolver Venta y Stock</a>

                    <a class="nav-link mt-2" href="#mod-proveedores">7. Proveedores y Gastos</a>
                    <a class="nav-link sub-link" href="#prov-ctacte">- Cuentas Corrientes</a>
                    <a class="nav-link sub-link" href="#prov-gastos">- Registrar Gastos (Luz, Agua)</a>

                    <a class="nav-link mt-2" href="#mod-marketing">8. Sorteos y Cupones</a>
                    <a class="nav-link sub-link" href="#mark-sorteos">- Crear un Sorteo Mensual</a>
                    <a class="nav-link sub-link" href="#mark-cupones">- Cupones de Descuento</a>
                </nav>

                <hr class="my-3">

                <span class="text-uppercase text-muted small fw-bold mb-2 px-3 text-danger">Administración (Gerencia)</span>
                <nav class="nav nav-pills flex-column nav-manual w-100">
                    <a class="nav-link" href="#mod-reportes">9. Reportes y Cierres</a>
                    <a class="nav-link sub-link" href="#rep-financiero">- Reporte Financiero Neto</a>

                    <a class="nav-link mt-2" href="#mod-auditoria">10. Auditoría y Seguridad</a>
                    <a class="nav-link sub-link" href="#auditoria-uso">- Cómo leer la Auditoría</a>

                    <a class="nav-link mt-2" href="#mod-importador">11. Importador y Aumentos</a>
                    <a class="nav-link sub-link" href="#imp-excel">- Carga Masiva (Excel)</a>
                    <a class="nav-link sub-link" href="#imp-inflacion">- Aumentos por Inflación</a>

                    <a class="nav-link mt-2" href="#mod-config">12. Usuarios y Configuración</a>
                    <a class="nav-link sub-link" href="#conf-usuarios">- Permisos de Empleados</a>
                    <a class="nav-link sub-link" href="#conf-backup">- Generar Backups de Seguridad</a>
                </nav>
            </nav>
        </div>

        <div class="col-lg-9">
            <div data-bs-spy="scroll" data-bs-target="#indice-manual" data-bs-smooth-scroll="true" tabindex="0">
                
                <section id="mod-caja" class="seccion-manual">
                    <h3 class="seccion-titulo">1. Ventas y Apertura de Caja</h3>
                    <p>El módulo de Terminal POS es el corazón del sistema. Imagina que el sistema es una tienda real: para que puedas vender y meter dinero en el cajón de madera, primero necesitas decirle al sistema que "abriste" el cajón. A esto se le llama tener una <strong>Sesión de Caja Abierta</strong> a tu nombre.</p>
                    
                    <h5 id="caja-apertura" class="sub-titulo"><i class="bi bi-box-arrow-in-right text-success"></i> ¿Cómo abrir y cerrar la caja?</h5>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Llegas a trabajar a las 8 AM. Abres el cajón de madera y cuentas que te dejaron $15.000 en billetes chicos para dar vuelto. Al iniciar sesión en el sistema, pones esos $15.000 como tu "Fondo de Caja inicial". Al final del día, si vendiste $10.000 en efectivo, cuando presiones "Cerrar Caja", el sistema esperará que cuentes con tus manos exactamente $25.000 ($15.000 del inicio + $10.000 de ventas).
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Apertura y Cierre Paso a Paso</h6>
                        <ol>
                            <li><strong>Ingresar al sistema:</strong> Inicia sesión con tu usuario y contraseña.</li>
                            <li><strong>Apertura:</strong> Si es tu primer acceso del día, el sistema te redirigirá a la pantalla de "Apertura de Caja". Ingresa el monto exacto de billetes que tienes para dar vuelto (Fondo de Caja) y presiona "Abrir Caja".</li>
                            <li><strong>Vender:</strong> Ahora tienes acceso total al Punto de Venta (POS) y todo lo que cobres quedará registrado a tu nombre.</li>
                            <li><strong>Cierre:</strong> Al finalizar tu turno, ve al menú principal y selecciona "Cierre de Caja". El sistema te pedirá que cuentes los billetes físicos reales que tienes en la mano y los ingreses. Luego los comparará con la computadora para ver si hay sobrante (te sobra dinero) o faltante (te falta dinero o diste mal un vuelto).</li>
                        </ol>
                    </div>

                    <h5 id="caja-atajos" class="sub-titulo"><i class="bi bi-keyboard text-primary"></i> Atajos de Teclado y Operativa de Venta</h5>
                    <p>La pantalla de ventas está diseñada para usarse sin mouse, como en los supermercados grandes. Cada botón importante tiene asignada una tecla "F" (las teclas que están arriba de los números en tu teclado).</p>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Un cliente trae 5 alfajores iguales. En lugar de escanear el alfajor 5 veces (pip... pip... pip...), puedes escribir en el buscador rápido "5*77912345" y presionar Enter. El sistema entiende: "Mete 5 unidades del código de barras 77912345". 
                        <br><br>¿Y si el cliente se olvida la billetera en el auto? Presionas <strong>F9</strong>. La venta se pausa (es como meter sus compras en una bolsa invisible y dejarla a un costado), atiendes al siguiente cliente, y cuando el primero vuelve, tocas la campanita arriba a la derecha y recuperas su venta intacta.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Los Atajos Mágicos</h6>
                        <ol>
                            <li><strong>F2 (Nueva Venta):</strong> Es la escoba mágica. Limpia la pantalla, vacía el carrito y te deja todo en cero para el siguiente cliente.</li>
                            <li><strong>F4 (Efectivo Rápido):</strong> Salta directo a la pantalla para preguntar con qué billete te paga el cliente.</li>
                            <li><strong>F7 (Mercado Pago):</strong> Dispara automáticamente el código QR gigante en el monitor.</li>
                            <li><strong>F8 (Descuento):</strong> Abre una ventana para rebajarle el precio al cliente (solo funciona si el jefe te dio permisos para hacer descuentos).</li>
                            <li><strong>F9 (Pausar Venta):</strong> Guarda la venta en la memoria temporal para atender a la persona que está atrás en la fila.</li>
                        </ol>
                    </div>
                    
                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo de la Terminal POS ]
                    </div>
                </section>

                <section id="mod-cobros" class="seccion-manual">
                    <h3 class="seccion-titulo">2. Métodos de Cobro Detallados</h3>
                    <p>Cobrar no es solo recibir plata. El sistema necesita saber exactamente POR DÓNDE entró esa plata para que a fin de mes el jefe sepa cuánto dinero hay en el banco, cuánto en Mercado Pago y cuánto en billetes de papel.</p>

                    <h5 id="cobro-efectivo" class="sub-titulo"><i class="bi bi-cash-coin text-success"></i> Efectivo y Calculadora de Vuelto</h5>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> La venta da $3.450. El cliente te da un billete de $10.000. Tú pones "10000" en el sistema. El sistema, en lugar de decirte solo "Vuelto: $6.550", te dice exactamente los papeles que tienes que agarrar: <em>"Entregar: 3 billetes de $2.000, 1 billete de $500 y 1 billete de $50"</em>. Así nunca te equivocas.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Cobrar en Efectivo</h6>
                        <ol>
                            <li>Con productos en el carrito, presiona el botón "Efectivo" o la tecla <strong>F4</strong>.</li>
                            <li>Aparecerá un recuadro preguntando <em>"¿Con cuánto paga?"</em>. Escribe el número sin puntos ni comas (ej: 10000).</li>
                            <li>Mira la pantalla para saber qué billetes sacar del cajón.</li>
                            <li>Entrega el vuelto al cliente y presiona <code>Enter</code> o haz clic en "Confirmar Venta" para imprimir el ticket y cerrar la operación.</li>
                        </ol>
                    </div>

                    <h5 id="cobro-mp" class="sub-titulo"><i class="bi bi-qr-code-scan text-primary"></i> Mercado Pago (QR Interoperable)</h5>
                    <p>Esta es una función de altísima tecnología. El código QR que muestra el sistema es "Interoperable" (gracias a la ley de Transferencias 3.0 de Argentina). Esto significa que <strong>el cliente NO está obligado a tener Mercado Pago</strong>. Puede abrir la app de su Banco Galicia, su app de Cuenta DNI del gobierno, Ualá o MODO, escanear la pantalla de tu compu y el sistema lo va a aceptar igual.</p>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Presionas F7. Sale el QR. El cliente lo escanea con su app de Banco Provincia. Tú te quedas con los brazos cruzados. El sistema, por dentro, está llamando por teléfono a Mercado Pago cada 3 segundos preguntando: "¿Ya pagó? ¿Ya pagó?". En el segundo en que el cliente pone su huella dactilar en su celular, Mercado Pago dice "¡Sí!", y tu pantalla se cierra sola, imprime el ticket y te dice "¡Venta Completada!". No tuviste que tocar ni una tecla.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Cobrar con QR</h6>
                        <ol>
                            <li>Presiona el botón "Mercado Pago" o la tecla <strong>F7</strong>.</li>
                            <li>Aparecerá el Código QR gigante en el monitor.</li>
                            <li>Pídele al cliente que abra su billetera virtual preferida y escanee la pantalla.</li>
                            <li><strong>IMPORTANTE:</strong> ¡Suelta el mouse! No toques nada. Deja que la magia ocurra sola. La ventana se cerrará automáticamente en cuanto el dinero impacte en la cuenta.</li>
                        </ol>
                    </div>

                    <h5 id="cobro-transf" class="sub-titulo"><i class="bi bi-bank text-info"></i> Transferencias (Alias/CBU y validación con IA)</h5>
                    <p>A veces el QR falla porque el internet del cliente es malo, y te dicen "Te transfiero al Alias". Acá hay mucho riesgo de que te muestren un comprobante falso o viejo. Por eso el sistema tiene dos formas de defenderte.</p>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico de IA (OCR):</strong> El cliente te dice "Ya te transferí $5.000". En vez de confiar a ciegas, presionas "Escanear Comprobante". Se prende la camarita de tu computadora. Le dices "Muéstrame la pantalla de tu celular a la cámara". Le sacas una foto al comprobante. El sistema, que sabe leer imágenes (como si fuera un humano), busca dónde está la fecha y dónde está el monto. Si el sistema lee que el comprobante dice "Ayer" o dice "$500", te va a lanzar una sirena roja bloqueando la venta. Si todo está bien, te deja cobrar.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Validar Transferencias</h6>
                        <ol>
                            <li>Selecciona el método de pago "Transferencia".</li>
                            <li>Si tu local usa <strong>Validación Manual</strong> (Tradicional): Abre la app de tu banco o de tu Mercado Pago en el celular del local, revisa que el dinero haya sumado a tu saldo, y recién ahí presiona "Confirmar Venta".</li>
                            <li>Si tu local usa <strong>Validación por IA (OCR)</strong>: Presiona "Escanear Comprobante". Pon el celular del cliente frente a la cámara, toma la foto, espera 2 segundos a que el robot lea el texto, y si te da luz verde, confirma la venta.</li>
                        </ol>
                    </div>

                    <h5 id="cobro-mixto" class="sub-titulo"><i class="bi bi-pie-chart text-warning"></i> Pagos Mixtos y Cuenta Corriente (Fiado)</h5>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico de Pago Mixto:</strong> Un cliente compra cosas por $15.000. Abre su billetera y solo tiene un billete de $5.000 de papel. Te dice: "Te doy estos 5 mil en efectivo, y los otros 10 mil te los paso por Mercado Pago". Para no volver loco al sistema, usas "Pago Mixto". Le dices al sistema: "En billetes entraron $5000", y el sistema te dice "Ok, faltan cobrar $10000". Eliges Mercado Pago para el resto y listo, el arqueo a la noche cuadrará perfecto.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Cobrar Pago Mixto (Dividir cuenta)</h6>
                        <ol>
                            <li>Haz clic en el botón "Pago Mixto".</li>
                            <li>Se abrirá una ventana. En el campo "Efectivo", escribe la cantidad que el cliente entrega de papel (Ej: 5000).</li>
                            <li>El sistema calculará el saldo restante automáticamente. Selecciona el segundo método (Ej: Mercado Pago) para cubrir ese resto y presiona "Agregar Pago".</li>
                            <li>Cuando el saldo a pagar llegue a $0, el botón de "Confirmar Venta" se encenderá de color verde para que la termines.</li>
                        </ol>
                    </div>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico de Fiar (Cuenta Corriente):</strong> Viene tu vecino de toda la vida y lleva mercadería por $2.000. Te dice "Mañana te lo pago". Lo buscas en la caja por su nombre, eliges "Cuenta Corriente" y confirmas. La caja de tu turno NO sumará esos $2.000 (porque no te dio plata real). Pero el sistema anotará en una libreta virtual que tu vecino debe $2.000.
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo de los Métodos de Pago y Código QR ]
                    </div>
                </section>

                <section id="mod-clientes" class="seccion-manual">
                    <h3 class="seccion-titulo">3. Gestión de Clientes y Fidelización</h3>
                    <p>Conocer a tus clientes es la clave de los grandes supermercados. Si sabes quién compra, puedes darle premios, cobrarle deudas o mandarle cupones por WhatsApp.</p>
                    
                    <h5 id="cli-alta" class="sub-titulo"><i class="bi bi-person-plus text-primary"></i> Alta de Cliente y Sistema de Puntos</h5>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico de Puntos:</strong> Imagina que es como un videojuego. Tú configuras que 1 punto equivale a $10 pesos. Si un cliente compra $5.000, el sistema le regala (por ejemplo) 50 puntos invisibles. La próxima vez que venga, el cliente te puede decir: "Quiero usar mis puntos". El sistema restará sus puntos y le descontará dinero real del total de su compra. ¡Así lo obligas a volver siempre a tu local!
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Cómo registrar un cliente nuevo</h6>
                        <ol>
                            <li><strong>Desde el mostrador (Rápido):</strong> Estás apurado y hay fila. En la pantalla de ventas, arriba a la derecha hay un botón "+". Lo tocas, le pides solo el Nombre y el número de WhatsApp, le das a guardar y listo. Ya le puedes vender a su nombre.</li>
                            <li><strong>Desde el módulo Clientes (Completo):</strong> Si estás tranquilo, ve al menú lateral izquierdo -> "Clientes" -> "Nuevo Cliente". Aquí llenas la ficha completa: DNI, Dirección, Correo (para mandarle tickets digitales) y su cumpleaños.</li>
                            <li><strong>Auto-Registro Mágico:</strong> Si el cliente está apurado, véndele como "Consumidor Final". Al pie del ticket impreso saldrá un código de cuadraditos (QR). Dile: "Si escaneas eso con la cámara de tu celular en tu casa, te registras solo y te ganas los puntos de esta compra". El sistema hará el resto.</li>
                        </ol>
                    </div>

                    <h5 id="cli-baja" class="sub-titulo"><i class="bi bi-person-x text-danger"></i> Suspender o Eliminar Cliente</h5>
                    <p><strong>Diferencia vital para no romper la AFIP:</strong> En informática contable, un cliente que ya te compró algo NUNCA se borra de verdad, porque rompería las sumas de dinero de los meses anteriores. Se le hace un "Soft Delete" (es como ponerlo en una caja fuerte y tirarla al mar).</p>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Bloquear a un cliente problemático</h6>
                        <ol>
                            <li>Ve al módulo "Clientes" y busca al deudor o usuario que ya no quieres.</li>
                            <li>Haz clic en el ícono de "Opciones" (tres puntos o el engranaje azul) al final de su fila.</li>
                            <li>Para <strong>Suspender</strong> (Botón Amarillo): Su cuenta sigue viva. Si viene a comprar en efectivo, le puedes vender. Pero si intentas elegir "Cuenta Corriente" (Fiarle), el sistema te gritará con un cartel rojo diciendo que tiene prohibido el crédito.</li>
                            <li>Para <strong>Eliminar</strong> (Botón Rojo): Su nombre desaparece de tu lista. Ya no puede iniciar sesión en la tienda web, pierde todos sus puntos y no le puedes vender a su nombre nunca más. Sus compras viejas seguirán existiendo en los reportes del mes pasado solo para que tu dinero cuadre.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo de la Ficha de Cliente y Puntos ]
                    </div>
                </section>

                <section id="mod-productos" class="seccion-manual">
                    <h3 class="seccion-titulo">4. Productos, Combos y Stock</h3>

                    <h5 id="prod-combos" class="sub-titulo"><i class="bi bi-boxes text-success"></i> Crear Combos Matemáticos (Escandallos)</h5>
                    <p>Esto es lo más inteligente del sistema Vanguard POS. <strong>Los combos NO tienen un stock propio tipeado a mano.</strong> Son como recetas de cocina. El sistema mira tu alacena (góndola física) para ver cuántas tortas puedes hornear.</p>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Armas la "Promo Previa": 1 botella de Fernet + 2 botellas de Coca-Cola por $15.000. 
                        Tú vas al depósito y cuentas que te quedan 10 Fernets y solo 2 Cocas. El sistema usará su cerebro matemático y dirá: <em>"A ver, para armar 1 promo uso el Fernet 1 y las Cocas 1 y 2. Ya no me quedan más Cocas. Por lo tanto, el stock real de la 'Promo Previa' es exactamente: 1."</em>. Si en ese instante entra una abuela y te compra una Coca suelta, el sistema instantáneamente pasará el stock de la promo a CERO y la ocultará de tu tienda de internet para que nadie te la compre (porque no se la podrías entregar).
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Cómo crear una promoción</h6>
                        <ol>
                            <li>Ve al menú lateral -> "Combos" y haz clic en "Crear Combo".</li>
                            <li>Ponle el Nombre ("Promo Previa"), un Código de Barras inventado (ej: 9991) y el Precio Final que le vas a cobrar al cliente.</li>
                            <li>En la pestaña <strong>Ingredientes / Composición</strong>, usa el buscador para llamar a los productos sueltos.</li>
                            <li>Busca "Fernet Branca", selecciónalo, y en cantidad pon "1".</li>
                            <li>Busca "Coca-Cola 1.5L", selecciónalo, y en cantidad pon "2".</li>
                            <li>Guarda. Listo, el sistema mantendrá vigilado el stock de esas botellas por el resto de la eternidad.</li>
                        </ol>
                    </div>

                    <h5 id="prod-pesables" class="sub-titulo"><i class="bi bi-modem text-dark"></i> Vender Pesables por kilo y Usar Taras</h5>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico de Taras:</strong> Imagina que vendes ensalada rusa por kilo. El cliente pide un poco. Agarras un pote de plástico, le pones la ensalada y lo pesas. La balanza dice 500 gramos. Pero el pote vacío pesa 50 gramos. Si le cobras 500 gramos, le estás robando al cliente el precio de 50 gramos de ensalada que en realidad es plástico. 
                        Con la "Tara", le dices al sistema: "Cóbramelo, pero réstale el peso del pote". El sistema le cobrará exactamente 450 gramos de ensalada pura.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Cobrar por kilo exacto</h6>
                        <ol>
                            <li>Al crear el producto en el sistema (Ej: "Queso Dambo"), asegúrate de tildar la casilla verde que dice <strong>"Es Pesable"</strong>.</li>
                            <li>En la pantalla de Ventas, escanea el Queso Dambo. Como es pesable, el sistema se frenará y abrirá una ventanita pidiendo el peso.</li>
                            <li>Ingresa el peso usando un PUNTO. Si son 250 gramos, escribe <code>0.250</code>. Si es 1 kilo y medio, escribe <code>1.5</code>. Si son 3 kilos redondos, escribe <code>3</code>.</li>
                            <li>Si despachaste la comida adentro de un recipiente tuyo, selecciona el menú desplegable que dice <strong>"Tara"</strong> y elige "Bandeja chica" (estas taras se configuran previamente en el menú ajustes). El sistema descontará el plástico y calculará los pesos a cobrar.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo de la creación de Combos o Pesables ]
                    </div>
                </section>

                <section id="mod-pedidos" class="seccion-manual">
                    <h3 class="seccion-titulo">5. Tienda Web y Pedidos de Clientes</h3>
                    <p>Vanguard POS incluye una Tienda Web (o Revista Digital). Es un link (como tu-negocio.com) que le pasas a tus clientes por WhatsApp o Instagram. Todo lo que marques como "Activo" en tu sistema, aparecerá ahí automáticamente con su precio real.</p>
                    
                    <h5 id="pedidos-aprobar" class="sub-titulo"><i class="bi bi-shop text-primary"></i> El Ciclo de Vida: Aprobar, Rechazar y Entregar</h5>
                    <p>Cuando un cliente entra a tu tienda, elige 3 cervezas, pone su nombre y envía el carrito, ocurre lo siguiente en tu panel de "Pedidos WhatsApp":</p>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: El flujo de trabajo</h6>
                        <ol>
                            <li><strong>Recibir (Estado Pendiente):</strong> El pedido entra a tu lista. El sistema te avisa. <strong>Atención:</strong> En este preciso momento, tu stock en las góndolas físicas todavía NO se tocó. Las cervezas siguen estando ahí para vender a cualquiera.</li>
                            <li><strong>Aprobar (Botón Verde):</strong> Al hacer clic, el sistema te pregunta "¿Cuándo lo viene a buscar?". Al confirmar, ocurre la magia: El sistema descuenta las 3 cervezas de tu stock disponible y las mete en la columna de "Stock Reservado". Es como si fueras físicamente a la heladera, agarraras las 3 cervezas y las metieras en una bolsa abajo del mostrador con un papelito que dice el nombre del cliente. El sistema le envía un mail al cliente diciéndole "Tu pedido está listo, ven a buscarlo".</li>
                            <li><strong>Rechazar (Botón Rojo - Tu culpa):</strong> Si vas a buscar las cervezas y te das cuenta de que te las robaron o se rompieron, tocas rechazar. Tienes que elegir un motivo (Ej: "Falta de stock físico"). Se le manda un email de disculpas al cliente y el pedido muere sin afectar a nadie.</li>
                            <li><strong>Entregar (Cobrar en Caja):</strong> El cliente cruza la puerta de tu local y te da los billetes en la mano. Tocas el botón "Entregado". Ese pedido se marca como verde completado, y <strong>los billetes impactan automáticamente en la caja que tienes abierta en ese turno</strong>.</li>
                            <li><strong>Liberar Stock (Botón Negro - Culpa del Cliente):</strong> Pasan dos días y el cliente nunca vino a buscar su bolsa. Tocas el botón "Liberar". El sistema desarma la bolsa virtual, vuelve a sumar las 3 cervezas a tu stock de venta para que otro las pueda comprar, y <strong>le mancha el historial interno al cliente</strong>, poniéndole una falta grave para que la próxima vez sepas que te suele dejar "colgado".</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo de la Tienda Web o panel de Pedidos ]
                    </div>
                </section>

                <section id="mod-devoluciones" class="seccion-manual">
                    <h3 class="seccion-titulo">6. Devoluciones de Clientes y Mermas</h3>
                    
                    <h5 id="dev-operacion" class="sub-titulo"><i class="bi bi-arrow-counterclockwise text-primary"></i> Devolver Ventas Oficiales</h5>
                    <p>Si un cliente trae un pantalón que le quedó chico para que le devuelvas el dinero, no debes simplemente sacar plata del cajón, porque el sistema pensará que te faltan billetes al final del día y además el pantalón seguirá faltando del inventario.</p>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Hacer una devolución contable perfecta</h6>
                        <ol>
                            <li>Ve al módulo "Devoluciones" en el menú.</li>
                            <li>Usa la lectora de código de barras para escanear el ticket viejo del cliente, o búscalo por fecha y hora.</li>
                            <li>Se abrirá el detalle de todo lo que compró. Selecciona únicamente el pantalón que está devolviendo.</li>
                            <li>Haz clic en "Procesar Devolución".</li>
                            <li><strong>¿Qué hace el sistema solo?</strong> Abre el cajón para que saques la plata, resta ese dinero del total de tu caja del turno, suma el pantalón de nuevo a la tabla de stock para que otra persona lo pueda comprar, e imprime un Ticket Comprobante de Devolución para que el cliente lo firme (respaldo legal).</li>
                        </ol>
                    </div>

                    <h5 id="dev-mermas" class="sub-titulo"><i class="bi bi-trash text-danger"></i> Registrar Mermas (Pérdidas, Roturas, Vencimientos)</h5>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Estás reponiendo lácteos y se te cae un sachet de leche al piso y revienta. NO debes ir al módulo de productos y simplemente restarle "-1" a la leche. Si haces eso, el sistema no sabe por qué desapareció y pensará que hubo un error en la auditoría. Tienes que ir a "Mermas".
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Registrar mercadería arruinada</h6>
                        <ol>
                            <li>Ve al módulo "Mermas" en el menú lateral.</li>
                            <li>Haz clic en "Nueva Baja de Stock".</li>
                            <li>Busca "Sachet de Leche", pon cantidad 1.</li>
                            <li>En el motivo, escribe "Se rompió reponiendo la heladera". Y dale a guardar.</li>
                            <li>El sistema descontará la leche del stock, pero ahora en el Reporte Financiero de fin de mes, en la sección "Pérdidas Operativas", verás que perdiste dinero contable por roturas, lo cual te ayuda a tomar decisiones para el futuro.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo del módulo de Devoluciones y Mermas ]
                    </div>
                </section>

                <section id="mod-proveedores" class="seccion-manual">
                    <h3 class="seccion-titulo">7. Gestión de Proveedores y Egresos (Gastos)</h3>
                    
                    <h5 id="prov-ctacte" class="sub-titulo"><i class="bi bi-truck text-secondary"></i> Pagarle a los Proveedores (Cuentas Corrientes)</h5>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Viene el repartidor de Cervezas Quilmes. Te deja 50 cajones de cerveza. Te da una factura por $200.000, pero hoy no tienes plata para pagarle. Le dices: "Te lo pago el martes". Tienes que avisarle al sistema que tu negocio le debe dinero a ese proveedor para que no lo olvides.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Anotar deudas comerciales</h6>
                        <ol>
                            <li>Ve al módulo "Proveedores" y asegúrate de tener a "Cervecería Quilmes" dado de alta.</li>
                            <li>Haz clic en el botón "Cargar Factura de Compra".</li>
                            <li>Selecciona el proveedor e ingresa el monto: $200.000. Ese monto se acumula en su <em>Cuenta Corriente de Proveedor</em> (en rojo oscuro, porque es dinero que tú debes).</li>
                            <li>Llega el martes. Tienes la plata en la mano. Entras a la ficha de Quilmes, haces clic en "Registrar Pago" e ingresas $200.000. El saldo del proveedor vuelve a Cero y el dinero sale de tu Caja Diaria (porque usaste plata de las ventas del día para pagarle).</li>
                        </ol>
                    </div>

                    <h5 id="prov-gastos" class="sub-titulo"><i class="bi bi-plug text-danger"></i> Registro de Gastos Fijos (Luz, Internet, Sueldos)</h5>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Anotar salidas de plata de la caja</h6>
                        <ol>
                            <li>En el menú lateral busca la opción "Gastos".</li>
                            <li>Haz clic en "Registrar Nuevo Gasto".</li>
                            <li>En la descripción escribe detalladamente de qué se trata (Ej: "Boleta de Luz - Edesur Marzo").</li>
                            <li>Ingresa el monto (Ej: $45.000).</li>
                            <li>Al guardar, ese dinero se descontará directamente del cajón de billetes de tu turno. Esto justifica por qué a fin del día faltan $45.000 físicos, y mancha correctamente tu reporte financiero de fin de mes para saber en qué se fue la ganancia.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo de Proveedores o Gastos ]
                    </div>
                </section>

                <section id="mod-marketing" class="seccion-manual">
                    <h3 class="seccion-titulo">8. Sorteos y Cupones (Marketing)</h3>
                    <p>Vanguard POS no solo administra mercadería, también te ayuda a vender más fidelizando a los clientes que te dejan los datos de contacto.</p>
                    
                    <h5 id="mark-sorteos" class="sub-titulo"><i class="bi bi-gift text-primary"></i> Crear un Sorteo Transparente</h5>
                    <div class="ejemplo-didactico">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Es el mes del Día del Niño. Pones un cartel en tu local: "Comprando este mes participas por una Bicicleta". Al terminar el mes, usas el sistema para elegir un ganador que no sea inventado por ti, sino extraído matemáticamente de tu base de clientes reales que te dejaron dinero en esos 30 días.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Hacer el sorteo automático</h6>
                        <ol>
                            <li>Ve al módulo "Sorteos" en el menú principal.</li>
                            <li>Haz clic en "Generar Nuevo Sorteo".</li>
                            <li>Ponle un título (Ej: Sorteo Día del Niño). Busca en la lupa qué producto físico del local vas a regalar (La Bicicleta) para que el sistema descuente 1 unidad de ese producto del stock automáticamente, sin cobrarla en caja.</li>
                            <li>Filtra las fechas (Ej: "Todos los clientes que compraron entre el 1 y el 30 de agosto").</li>
                            <li>Haz clic en "Sortear". La pantalla mezclará los nombres y lanzará un papel picado virtual (confeti) mostrando el Nombre, Teléfono y Correo del ganador legítimo.</li>
                        </ol>
                    </div>

                    <h5 id="mark-cupones" class="sub-titulo"><i class="bi bi-ticket-perforated text-warning"></i> Cupones de Descuento (Códigos Promocionales)</h5>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Fabricar un cupón</h6>
                        <ol>
                            <li>Ve al menú "Cupones" y crea uno nuevo.</li>
                            <li>Escribe el código en mayúsculas (Ej: <code>PRIMAVERA20</code>).</li>
                            <li>Dile al sistema cuánto va a descontar (Ej: 20% de descuento en el total de la compra).</li>
                            <li>Configura reglas estrictas: "Solo sirve si compran más de $15.000", "Se vence el domingo a las 23:59", "Solo se puede usar 1 vez por cliente".</li>
                            <li>Imprime el cupón y pégalo en tu Instagram o vidriera. Cuando el cliente vaya al mostrador (o a la tienda web) y le dicte la palabra mágica al cajero, el descuento se aplicará solo si respeta todas tus reglas de seguridad.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo del sistema de Sorteos o Cupones ]
                    </div>
                </section>

                <div class="my-5 border-top border-3 border-danger pt-4 text-center">
                    <span class="badge bg-danger fs-6 px-4 py-2 rounded-pill"><i class="bi bi-shield-lock-fill"></i> SECCIÓN EXCLUSIVA GERENCIAL <i class="bi bi-shield-lock-fill"></i></span>
                </div>

                <section id="mod-reportes" class="seccion-manual">
                    <h3 class="seccion-titulo text-danger">9. Reportes y Cierres Financieros</h3>
                    <p>La regla de oro de los negocios es: <strong>Lo que hay en la caja no es toda tu plata.</strong> La caja cuenta los billetes para saber si el empleado te robó o se equivocó. El Reporte Financiero cuenta la salud de tu empresa para saber si eres rico o vas a la quiebra.</p>
                    
                    <h5 id="rep-financiero" class="sub-titulo"><i class="bi bi-graph-up text-success"></i> El Reporte Financiero Neto (La Verdad)</h5>
                    <div class="ejemplo-didactico border-danger text-danger bg-white">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Este mes vendiste $10.000.000 en el local. ¡Genial, te sientes millonario! Pero al abrir el Reporte Financiero, el sistema hace esta cuenta cruel y realista: <em>"Vendiste 10 Millones (Ventas Brutas). Pero le pagaste a los proveedores 6 Millones por esos productos (Costo de Mercadería). Y anotaste 3 Millones de luz, alquiler y sueldos (Gastos Operativos). Tu Ganancia Neta Pura, el billete limpio que te puedes llevar al bolsillo para irte de vacaciones, es de 1 Millón."</em>
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Cómo saber si tu negocio gana dinero real</h6>
                        <ol>
                            <li>Ve al módulo de "Reportes" -> "Reporte Financiero".</li>
                            <li>Usa el filtro de fechas (Ej: "Este Mes" o pon del 1 al 31).</li>
                            <li>Observa las 3 tarjetas de colores gigantes:
                                <ul>
                                    <li><strong>Ventas Brutas:</strong> La suma matemática de todos los tickets emitidos.</li>
                                    <li><strong>Costos:</strong> Cuánto te costó de tu bolsillo comprar esa mercadería a los distribuidores.</li>
                                    <li><strong>Ganancia Neta:</strong> El dinero real que produjo tu negocio después de todos los descuentos y gastos de luz.</li>
                                </ul>
                            </li>
                            <li>En la parte inferior verás un gráfico de barras que te muestra los días de la semana donde más ganancia generas, para que sepas cuándo es inteligente contratar más personal.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo del Reporte Financiero y Gráficos ]
                    </div>
                </section>

                <section id="mod-auditoria" class="seccion-manual">
                    <h3 class="seccion-titulo text-danger">10. Auditoría y Seguridad</h3>
                    <p>Vanguard POS tiene un módulo de "Gran Hermano" invencible. Fue diseñado para proteger el bolsillo del dueño del negocio frente a errores, descuidos o manipulaciones intencionadas de los empleados (como robos hormiga en el mostrador).</p>
                    
                    <h5 id="auditoria-uso" class="sub-titulo"><i class="bi bi-shield-check text-dark"></i> Cómo leer el panel de Auditoría</h5>
                    <div class="ejemplo-didactico border-danger text-danger bg-white">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Sospechas que te falta dinero. Vas a "Auditoría". Filtras por el día de ayer. El sistema te dirá textualmente: <em>"15:30hs - El cajero Juan Pérez escaneó un Whisky Blue Label de $300.000. 15:31hs - El cajero Juan Pérez eliminó el Whisky de la pantalla sin cobrarlo"</em>. Si el cliente no se llevó el producto, debe estar en la góndola. Si no está en la góndola, ya sabes quién lo pasó por el escáner y decidió no cobrarlo en el sistema. 
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Revisión de eventos críticos</h6>
                        <ol>
                            <li>Ingresa al menú "Auditoría" (Solo tú como Administrador o Dueño tienes acceso, los cajeros ni siquiera ven el botón en su pantalla).</li>
                            <li>Verás una tabla larga que anota hasta el más mínimo suspiro de la computadora.</li>
                            <li><strong>Bolas Rojas o Naranjas:</strong> Son banderas de peligro. El sistema pinta de colores llamativos si un empleado: 
                                <ul>
                                    <li>Abrió el cajón de dinero sin hacer una venta (Botón físico).</li>
                                    <li>Aplicó un descuento manual muy grande a un producto (Ej: "Cobró una gaseosa al 50%").</li>
                                    <li>Buscó una venta de la semana pasada y la anuló de la base de datos (Posible devolución fraudulenta para robar dinero de la caja).</li>
                                </ul>
                            </li>
                            <li>Esta tabla de auditoría <strong>NO se puede borrar ni modificar desde el sistema</strong>, garantizando que el historial de verdades sea absoluto.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo del listado de alertas en Auditoría ]
                    </div>
                </section>

                <section id="mod-importador" class="seccion-manual">
                    <h3 class="seccion-titulo text-danger">11. Importador y Actualización de Precios</h3>
                    <p>En países con inflación, cambiar precios uno por uno es imposible y te llevaría la vida entera. Estas dos herramientas te permiten mantener cientos de miles de productos con el precio correcto en tan solo un par de clics.</p>
                    
                    <h5 id="imp-excel" class="sub-titulo"><i class="bi bi-file-earmark-excel text-success"></i> Carga Masiva por Excel (El Importador Mágico)</h5>
                    <div class="ejemplo-didactico border-danger text-danger bg-white">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Te llega un PDF o un Excel de la distribuidora Arcor con 5.000 golosinas nuevas y nuevos precios. En lugar de sentarte a teclearlos durante 3 semanas, preparas un Excel con columnas claras y lo subes al sistema. ¡Pum! En 10 segundos todo tu local tiene los precios actualizados.
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Subir lista de precios del proveedor</h6>
                        <ol>
                            <li>Ve al menú "Configuración" -> "Importador Maestro".</li>
                            <li>Haz clic en "Descargar Plantilla Base". Se descargará un Excel vacío pero con los nombres de las columnas perfectos que la computadora entiende (Cod_Barras, Nombre, Precio_Costo, Precio_Venta, Stock, Categoria).</li>
                            <li>Copia los datos del Excel de tu proveedor y pégalos adentro de la plantilla que te acabas de descargar. Guarda el archivo en tu computadora.</li>
                            <li>Vuelve al sistema, arrastra tu archivo y dale al botón azul "Importar".</li>
                            <li><strong>Inteligencia del sistema:</strong> Si el sistema lee que un código de barras ya existía en tu base de datos, no lo duplica, solo le pisa el precio nuevo. Si el código de barras no existía, crea el producto desde cero.</li>
                        </ol>
                    </div>

                    <h5 id="imp-inflacion" class="sub-titulo"><i class="bi bi-percent text-danger"></i> Aumentos por Inflación (Por Categorías o Proveedores)</h5>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Aumentar un 10% a toda la fiambrería de un golpe</h6>
                        <ol>
                            <li>Ve al módulo "Precios Masivos" o "Aumentos".</li>
                            <li>Elige en el primer filtro si quieres afectar a "Toda la tienda", a "Una Categoría" (Ej: Fiambrería) o a "Un Proveedor" (Ej: Coca-Cola).</li>
                            <li>Selecciona si el aumento será en <strong>Porcentaje %</strong> o en <strong>Monto Fijo $</strong>.</li>
                            <li>Escribe "10" (para aplicar un 10%).</li>
                            <li>Presiona "Aplicar Aumento". El sistema entrará a la base de datos de forma invisible, tomará el costo y el precio de venta de todos los quesos y jamones uno por uno, les sumará el 10% exacto y los volverá a guardar de forma automática en milisegundos.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo del Excel de Importación o la pantalla de Inflación ]
                    </div>
                </section>

                <section id="mod-config" class="seccion-manual">
                    <h3 class="seccion-titulo text-danger">12. Usuarios y Configuración del Sistema</h3>
                    
                    <h5 id="conf-usuarios" class="sub-titulo"><i class="bi bi-people text-info"></i> Gestión de Empleados y Permisos</h5>
                    <p>No todos los empleados de tu local pueden tener el poder de ver la plata que ganas o de borrar tickets. Debes crearles "Candados Informáticos" para que solo vean la pantalla de cobrar.</p>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Crear el candado de seguridad a un empleado</h6>
                        <ol>
                            <li>Como dueño, entra a "Usuarios y Permisos".</li>
                            <li>Añade a tu empleado nuevo (Ej: "Cajero Martín") y ponle una contraseña.</li>
                            <li>Abre su panel de "Roles". Destilda todas las casillas peligrosas: quítale el tilde a "Ver Reporte Financiero", "Hacer Descuentos", "Anular Ventas" y "Modificar Stock".</li>
                            <li>Cuando Martín inicie sesión en la computadora con su usuario, el sistema ocultará automáticamente esos botones. Si Martín intenta escribir la ruta del reporte en el navegador, el sistema lo rebotará con un cartel de acceso denegado.</li>
                        </ol>
                    </div>

                    <h5 id="conf-backup" class="sub-titulo"><i class="bi bi-database-down text-dark"></i> Generar Copias de Seguridad (Backups)</h5>
                    <div class="ejemplo-didactico border-danger text-danger bg-white">
                        <i class="bi bi-lightbulb-fill text-warning"></i> <strong>🤓 Ejemplo Práctico:</strong> Un día hay una tormenta. Tu computadora hace un cortocircuito y explota. Se quema el disco duro entero. Si no tienes un backup, acabas de perder los 5.000 productos con sus precios, toda la deuda de los clientes y todo el registro de la AFIP. ¡Tu negocio tendría que empezar de cero absoluto!
                    </div>
                    <div class="paso-a-paso">
                        <h6>Guía de Operación: Salvar tu negocio del desastre</h6>
                        <ol>
                            <li>Por lo menos una vez a la semana, ve al panel de "Configuración".</li>
                            <li>Busca el botón negro grande que dice <strong>"Descargar Copia de Seguridad"</strong>.</li>
                            <li>El sistema generará un archivito diminuto (un archivo terminado en <code>.SQL</code>) con millones de letras adentro. Esa es toda tu base de datos congelada en el tiempo y comprimida.</li>
                            <li>Sube ese pequeño archivo a un pendrive que guardes en tu casa o a tu Google Drive. Si mañana compras una compu nueva en Garbarino, le pasas ese archivo al programador y en 5 minutos tu sistema vuelve a estar exactamente como el día en que lo descargaste.</li>
                        </ol>
                    </div>

                    <div class="captura-placeholder">
                        [ Pegar aquí captura de pantalla o GIF demostrativo del panel de Roles o el botón de Backup SQL ]
                    </div>
                </section>

            </div>
        </div>
    </div>
</div>

<script>
    // Configuración para el Menú Lateral Scrollspy (Seguimiento de lectura sin cortes)
    document.addEventListener('DOMContentLoaded', function() {
        const scrollSpy = new bootstrap.ScrollSpy(document.body, {
            target: '#indice-manual',
            offset: 150
        });

        document.querySelectorAll('.nav-manual .nav-link').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                // Previene el salto brusco
                e.preventDefault();
                const targetId = this.getAttribute('href');
                const targetElem = document.querySelector(targetId);
                
                if (targetElem) {
                    window.scrollTo({
                        top: targetElem.offsetTop - 100, // Margen superior para que no quede tapado por el header
                        behavior: 'smooth'
                    });
                }

                // Cierra el menú en celulares al seleccionar una opción para ver el texto
                const navbarCollapse = document.getElementById('indice-manual');
                if (window.innerWidth < 992 && navbarCollapse.classList.contains('show')) {
                    bootstrap.Collapse.getInstance(navbarCollapse).hide();
                }
            });
        });
    });
</script>

<?php require_once 'includes/layout_footer.php'; ?>