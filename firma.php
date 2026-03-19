<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor Visual de Documentos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #2c3e50; color: white; padding: 15px 0; font-family: Arial, sans-serif; }
        
        .toolbar { background: #34495e; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        
        /* CONTENEDOR BLOQUEADO AL TAMAÑO EXACTO A4 - ESTO EVITA LAS 2 PÁGINAS */
        .wrapper-hoja { width: 100%; overflow-x: auto; padding-bottom: 50px; text-align: center; }
        #zona-trabajo {
            position: relative;
            display: inline-block;
            background: white;
            width: 794px;  /* Ancho estricto A4 */
            height: 1123px; /* Alto estricto A4 */
            box-shadow: 0 0 15px rgba(0,0,0,0.8);
            overflow: hidden; 
            text-align: left;
        }
        
        /* La imagen se ajusta sin deformarse dentro de la hoja A4 */
        #img-fondo { 
            width: 100%; 
            height: 100%; 
            object-fit: contain; 
            pointer-events: none; 
        }

        .elemento-draggable {
            position: absolute;
            top: 50px;
            left: 50px;
            cursor: grab;
            border: 2px dashed #0d6efd;
            background: rgba(255, 255, 255, 0.6);
            padding: 2px 5px;
            font-family: Arial, sans-serif;
            font-size: 18px;
            color: black;
            font-weight: bold;
            user-select: none;
            touch-action: none;
            white-space: nowrap;
        }
        .elemento-draggable:active { cursor: grabbing; border-color: #dc3545; background: rgba(255, 255, 255, 0.9); }
        
        #pizarra { border: 2px dashed #000; background: #fff; width: 100%; height: 250px; touch-action: none; }
    </style>
</head>
<body>

<div class="container text-center">
    
    <div class="toolbar no-print">
        <h5 class="fw-bold text-warning mb-3">1. Cargar imagen del PDF original:</h5>
        <input type="file" id="inputFondo" accept="image/*" class="form-control mb-4">

        <h5 class="fw-bold text-info mb-2">2. Agregar elementos:</h5>
        <div class="d-flex justify-content-center gap-2 mb-3 flex-wrap">
            <button class="btn btn-light fw-bold" onclick="agregarTexto()">+ AGREGAR TEXTO</button>
            <button class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#modalFirma">+ AGREGAR FIRMA</button>
        </div>

        <div class="mb-4 bg-light p-2 rounded text-dark text-center mx-auto" id="control-tamano" style="display:none; max-width: 400px;">
            <label class="fw-bold small text-primary mb-1">Ajustar tamaño (Mínimo 5px):</label>
            <input type="range" class="form-range" id="rangoTamano" min="5" max="150" oninput="cambiarTamano()">
        </div>

        <button class="btn btn-success w-100 fw-bold py-3 fs-5" onclick="exportarPDF()">
            3. DESCARGAR DOCUMENTO FINAL
        </button>
    </div>

    <div class="wrapper-hoja">
        <div id="zona-trabajo">
            <img id="img-fondo" src="" alt="Cargá la imagen acá">
        </div>
    </div>

</div>

<div class="modal fade" id="modalFirma" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-dark">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title fw-bold">Dibujá tu firma</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <canvas id="pizarra"></canvas>
        <div class="d-flex justify-content-between mt-3">
            <button class="btn btn-outline-danger fw-bold" onclick="limpiarFirma()">BORRAR</button>
            <button class="btn btn-success fw-bold px-4" onclick="guardarFirmaYAgregar()">INSERTAR FIRMA</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
    let elementoActivo = null;

    // 1. CARGAR IMAGEN
    document.getElementById('inputFondo').addEventListener('change', function(e) {
        let reader = new FileReader();
        reader.onload = function(event) {
            document.getElementById('img-fondo').src = event.target.result;
        }
        reader.readAsDataURL(e.target.files[0]);
    });

    // 2. FIRMA
    var canvas = document.getElementById('pizarra');
    var signaturePad = new SignaturePad(canvas, { penColor: 'rgb(0, 0, 150)' });

    document.getElementById('modalFirma').addEventListener('shown.bs.modal', function () {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext("2d").scale(ratio, ratio);
        signaturePad.clear();
    });

    function limpiarFirma() { signaturePad.clear(); }

    function guardarFirmaYAgregar() {
        if (signaturePad.isEmpty()) return alert("Dibujá tu firma primero.");
        
        let img = document.createElement('img');
        img.src = signaturePad.toDataURL("image/png");
        img.className = 'elemento-draggable';
        img.style.height = '80px';
        
        document.getElementById('zona-trabajo').appendChild(img);
        hacerArrastrable(img);
        
        bootstrap.Modal.getInstance(document.getElementById('modalFirma')).hide();
    }

    // 3. TEXTOS
    function agregarTexto() {
        let texto = prompt("¿Qué texto querés agregar?");
        if (!texto) return;

        let div = document.createElement('div');
        div.innerText = texto;
        div.className = 'elemento-draggable';
        div.ondblclick = function() { this.remove(); document.getElementById('control-tamano').style.display = 'none'; };
        
        document.getElementById('zona-trabajo').appendChild(div);
        hacerArrastrable(div);
    }

    // 4. CAMBIAR TAMAÑO
    function cambiarTamano() {
        if (!elementoActivo) return;
        let valor = document.getElementById('rangoTamano').value;
        if (elementoActivo.tagName === 'IMG') {
            elementoActivo.style.height = valor + 'px';
        } else {
            elementoActivo.style.fontSize = valor + 'px';
        }
    }

    // 5. MOTOR DE ARRASTRE Y SELECCIÓN
    function hacerArrastrable(elmnt) {
        var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
        
        elmnt.onmousedown = iniciarArrastre;
        elmnt.ontouchstart = iniciarArrastre;

        function iniciarArrastre(e) {
            e.preventDefault();
            
            // Selección para redimensionar
            elementoActivo = elmnt;
            document.getElementById('control-tamano').style.display = 'block';
            let valorAct = elmnt.tagName === 'IMG' ? parseInt(elmnt.style.height) : parseInt(window.getComputedStyle(elmnt).fontSize);
            document.getElementById('rangoTamano').value = valorAct || 50;
            
            document.querySelectorAll('.elemento-draggable').forEach(el => el.style.borderColor = 'transparent');
            elmnt.style.borderColor = '#dc3545';
            elmnt.style.borderStyle = 'dashed';

            if (e.type === 'touchstart') {
                pos3 = e.touches[0].clientX;
                pos4 = e.touches[0].clientY;
            } else {
                pos3 = e.clientX;
                pos4 = e.clientY;
            }
            document.onmouseup = detenerArrastre;
            document.onmousemove = arrastrarElemento;
            document.ontouchend = detenerArrastre;
            document.ontouchmove = arrastrarElemento;
        }

        function arrastrarElemento(e) {
            e.preventDefault();
            if (e.type === 'touchmove') {
                pos1 = pos3 - e.touches[0].clientX;
                pos2 = pos4 - e.touches[0].clientY;
                pos3 = e.touches[0].clientX;
                pos4 = e.touches[0].clientY;
            } else {
                pos1 = pos3 - e.clientX;
                pos2 = pos4 - e.clientY;
                pos3 = e.clientX;
                pos4 = e.clientY;
            }
            elmnt.style.top = (elmnt.offsetTop - pos2) + "px";
            elmnt.style.left = (elmnt.offsetLeft - pos1) + "px";
        }

        function detenerArrastre() {
            document.onmouseup = null;
            document.onmousemove = null;
            document.ontouchend = null;
            document.ontouchmove = null;
        }
    }

    // 6. EXPORTAR PDF 100% BLINDADO A 1 PÁGINA
    function exportarPDF() {
        if(!document.getElementById('img-fondo').src.startsWith('data')) {
            return alert("Tenés que cargar la imagen original primero.");
        }

        // Esconder bordes e inputs para que salga limpio
        document.getElementById('control-tamano').style.display = 'none';
        document.querySelectorAll('.elemento-draggable').forEach(el => {
            el.style.borderColor = 'transparent';
            el.style.background = 'transparent';
        });

        let elemento = document.getElementById('zona-trabajo');
        
        // CONFIGURACIÓN ESTRICTA: 794x1123 px (1 sola hoja A4 exacta)
        var opt = {
            margin:       0,
            filename:     'Renuncia_Firmada.pdf',
            image:        { type: 'jpeg', quality: 1 },
            html2canvas:  { scale: 2, useCORS: true, scrollY: 0 },
            jsPDF:        { unit: 'px', format: [794, 1123], orientation: 'portrait' }
        };

        // Generar
        html2pdf().set(opt).from(elemento).save().then(() => {
            // Restaurar bordes para seguir editando
            document.querySelectorAll('.elemento-draggable').forEach(el => {
                el.style.borderColor = '#0d6efd';
                el.style.background = 'rgba(255, 255, 255, 0.6)';
            });
        });
    }
</script>

</body>
</html>
