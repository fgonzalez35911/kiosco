<?php
session_start();
$rutas_db=[__DIR__.'/db.php',__DIR__.'/includes/db.php','db.php','includes/db.php'];
foreach($rutas_db as $ruta){if(file_exists($ruta)){require_once $ruta;break;}}
if(!isset($_SESSION['usuario_id'])){header("Location: index.php");exit;}
$id_usuario=$_SESSION['usuario_id'];$mensaje='';

if($_SERVER['REQUEST_METHOD']=='POST'&&isset($_POST['btn_datos'])){
    $n=trim($_POST['nombre']);$e=$_POST['email']??'';$w=$_POST['whatsapp']??'';
    $dni=$_POST['dni']??'';$cuil=$_POST['cuil']??'';$fn=$_POST['fecha_nacimiento']??null;
    $dom=$_POST['domicilio']??'';$cp=$_POST['codigo_postal']??'';$ec=$_POST['estado_civil']??'';
    $gs=$_POST['grupo_sanguineo']??'';$em=$_POST['contacto_emergencia']??'';
    $al=$_POST['alergias']??'';$tu=$_POST['talla_uniforme']??'';
    $f_sql="";$params=[$n,$e,$w,$dni,$cuil,$fn,$dom,$cp,$ec,$gs,$em,$al,$tu];
    if(isset($_FILES['foto_perfil'])&&$_FILES['foto_perfil']['error']==0){
        $ext=pathinfo($_FILES['foto_perfil']['name'],PATHINFO_EXTENSION);
        $nom_f="user_".$id_usuario."_".time().".".$ext;
        if(move_uploaded_file($_FILES['foto_perfil']['tmp_name'],"uploads/".$nom_f)){
            $f_sql=",foto_perfil=?";$params[]=$nom_f;$_SESSION['foto_perfil']=$nom_f;
        }
    }
    $params[]=$id_usuario;
    $sql="UPDATE usuarios SET nombre_completo=?,email=?,whatsapp=?,dni=?,cuil=?,fecha_nacimiento=?,domicilio=?,codigo_postal=?,estado_civil=?,grupo_sanguineo=?,contacto_emergencia=?,alergias=?,talla_uniforme=? $f_sql WHERE id=?";
    $conexion->prepare($sql)->execute($params);$_SESSION['nombre']=$n;
    header("Location: perfil.php?msg=datos_ok");exit;
}

if(isset($_POST['btn_pass'])){
    $act=$_POST['pass_actual'];$nue=$_POST['pass_nueva'];
    $stmt=$conexion->prepare("SELECT password FROM usuarios WHERE id=?");$stmt->execute([$id_usuario]);
    $hash=$stmt->fetchColumn();
    if(password_verify($act,$hash)){
        $conexion->prepare("UPDATE usuarios SET password=? WHERE id=?")->execute([password_hash($nue,PASSWORD_DEFAULT),$id_usuario]);
        header("Location: perfil.php?msg=pass_ok");exit;
    }else{$mensaje='error_pass';}
}

$stmt=$conexion->prepare("SELECT u.*,r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol=r.id WHERE u.id=?");
$stmt->execute([$id_usuario]);$u=$stmt->fetch(PDO::FETCH_ASSOC);
$foto_url=(!empty($u['foto_perfil'])&&file_exists("uploads/".$u['foto_perfil']))?"uploads/".$u['foto_perfil']:"img/no-image.png";
$ruta_f="img/firmas/usuario_{$id_usuario}.png";$tiene_f=file_exists($ruta_f);
$firma_img=$tiene_f?$ruta_f."?v=".time():"";
$mis_v=$conexion->query("SELECT SUM(total) FROM ventas WHERE id_usuario=$id_usuario AND MONTH(fecha)=MONTH(CURRENT_DATE()) AND estado='completada'")->fetchColumn()?:0;

include 'includes/layout_header.php';
$titulo="Mi Perfil Profesional";$subtitulo="Gestión de ficha y seguridad.";$icono_bg="bi-person-badge";$botones=[];
$widgets=[
    ['label'=>'Rango','valor'=>strtoupper($u['nombre_rol']),'icono'=>'bi-shield-check','icon_bg'=>'bg-white bg-opacity-10'],
    ['label'=>'Ventas Mes','valor'=>'$'.number_format($mis_v,0,',','.'),'icono'=>'bi-cash-stack','border'=>'border-success','icon_bg'=>'bg-success bg-opacity-20'],
    ['label'=>'Firma','valor'=>$tiene_f?'ACTIVA':'PENDIENTE','icono'=>'bi-pen','border'=>$tiene_f?'border-primary':'border-warning','icon_bg'=>'bg-primary bg-opacity-20']
];
include 'includes/componente_banner.php';
?>

<style>
.avatar-preview{width:140px;height:140px;object-fit:cover;border-radius:50%;border:4px solid #fff;box-shadow:0 5px 15px rgba(0,0,0,0.1);cursor:pointer;}
.card-title-bg{background:#f8f9fa;border-bottom:1px solid #eee;padding:12px 20px;font-weight:bold;color:#102A57;border-radius:12px 12px 0 0;text-transform:uppercase;font-size:0.8rem;}

/* Clases para manejar la pantalla completa con Flexbox en SweetAlert2 */
.swal-firma-popup { transition: width 0.3s ease, height 0.3s ease; }
.swal-fullscreen {
    width: 100vw !important;
    max-width: 100% !important;
    height: 100dvh !important; /* dvh evita problemas con la barra de navegación del móvil */
    margin: 0 !important;
    border-radius: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    padding-bottom: 1rem !important;
}
.swal-fullscreen .swal2-html-container {
    flex-grow: 1 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}
.swal-fullscreen #canvasWrapper {
    height: 100% !important;
    flex-grow: 1 !important;
}
.swal-fullscreen .swal2-actions {
    margin-top: 0 !important;
}
</style>

<div class="container-fluid container-md pb-5 pt-3 px-2">
    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" enctype="multipart/form-data">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-title-bg"><i class="bi bi-person-vcard me-2"></i> Identidad</div>
                    <div class="card-body p-4">
                        <div class="row align-items-center mb-4">
                            <div class="col-md-3 text-center mb-3">
                                <img src="<?php echo $foto_url;?>" id="imgPreview" class="avatar-preview" onclick="document.getElementById('inputFoto').click()">
                                <input type="file" name="foto_perfil" id="inputFoto" class="d-none" onchange="previewImage(event)">
                            </div>
                            <div class="col-md-9">
                                <div class="row g-3">
                                    <div class="col-12"><label class="small fw-bold text-muted">Nombre Completo</label><input type="text" name="nombre" class="form-control" value="<?php echo $u['nombre_completo'];?>" required></div>
                                    <div class="col-6"><label class="small fw-bold text-muted">DNI</label><input type="text" name="dni" class="form-control" value="<?php echo $u['dni'];?>"></div>
                                    <div class="col-6"><label class="small fw-bold text-muted">CUIL</label><input type="text" name="cuil" class="form-control" value="<?php echo $u['cuil'];?>"></div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6"><label class="small fw-bold text-muted">Nacimiento</label><input type="date" name="fecha_nacimiento" class="form-control" value="<?php echo $u['fecha_nacimiento'];?>"></div>
                            <div class="col-6"><label class="small fw-bold text-muted">Estado Civil</label>
                                <select name="estado_civil" class="form-select"><option value="">Seleccionar...</option><option value="Soltero/a" <?php echo ($u['estado_civil']=='Soltero/a')?'selected':'';?>>Soltero/a</option><option value="Casado/a" <?php echo ($u['estado_civil']=='Casado/a')?'selected':'';?>>Casado/a</option></select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-title-bg"><i class="bi bi-geo-alt me-2"></i> Contacto</div>
                    <div class="card-body p-4"><div class="row g-3">
                        <div class="col-6"><label class="small fw-bold text-muted">WhatsApp</label><input type="text" name="whatsapp" class="form-control" value="<?php echo $u['whatsapp'];?>"></div>
                        <div class="col-6"><label class="small fw-bold text-muted">Email</label><input type="email" name="email" class="form-control" value="<?php echo $u['email'];?>"></div>
                        <div class="col-8"><label class="small fw-bold text-muted">Domicilio</label><input type="text" name="domicilio" class="form-control" value="<?php echo $u['domicilio'];?>"></div>
                        <div class="col-4"><label class="small fw-bold text-muted">CP</label><input type="text" name="codigo_postal" class="form-control" value="<?php echo $u['codigo_postal'];?>"></div>
                    </div></div>
                </div>
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-title-bg text-danger"><i class="bi bi-heart-pulse me-2"></i> Salud</div>
                    <div class="card-body p-4"><div class="row g-3">
                        <div class="col-4"><label class="small fw-bold text-muted">Sangre</label><select name="grupo_sanguineo" class="form-select"><option value=""><?php echo $u['grupo_sanguineo']?:'S/D';?></option><option value="A+">A+</option><option value="O+">O+</option></select></div>
                        <div class="col-8"><label class="small fw-bold text-muted">Emergencia</label><input type="text" name="contacto_emergencia" class="form-control" value="<?php echo $u['contacto_emergencia'];?>"></div>
                        <div class="col-12"><label class="small fw-bold text-muted">Alergias</label><textarea name="alergias" class="form-control"><?php echo $u['alergias'];?></textarea></div>
                    </div></div>
                </div>
                <button type="submit" name="btn_datos" class="btn btn-primary w-100 fw-bold py-3 rounded-pill shadow">GUARDAR PERFIL</button>
            </form>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-title-bg d-flex justify-content-between"><span>Firma Digital</span><span class="badge bg-<?php echo $tiene_f?'success':'warning';?> rounded-pill"><?php echo $tiene_f?'OK':'Falta';?></span></div>
                <div class="card-body text-center p-4">
                    <div class="mb-3 border rounded bg-light d-flex align-items-center justify-content-center" style="min-height:100px;">
                        <?php if($tiene_f):?><img src="<?php echo $firma_img;?>" style="max-height:80px;"><?php else:?><small class="text-muted">Sin firma</small><?php endif;?>
                    </div>
                    <button type="button" class="btn btn-danger w-100 fw-bold rounded-pill" onclick="abrirFirma()">ACTUALIZAR FIRMA</button>
                </div>
            </div>
            <div class="card border-0 shadow-sm rounded-4 border-top border-4 border-danger">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-danger mb-3">Seguridad</h6>
                    <?php if($mensaje=='error_pass')echo "<div class='alert alert-danger py-1 small'>Error clave actual</div>";?>
                    <form method="POST">
                        <input type="password" name="pass_actual" class="form-control mb-2" placeholder="Clave actual" required>
                        <input type="password" name="pass_nueva" class="form-control mb-3" placeholder="Nueva clave" required>
                        <button type="submit" name="btn_pass" class="btn btn-danger w-100 fw-bold rounded-pill">CAMBIAR CLAVE</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
<script>
function previewImage(e){
    var r=new FileReader();
    r.onload=function(){document.getElementById('imgPreview').src=r.result;};
    r.readAsDataURL(e.target.files[0]);
}

let isFullScreen = false;

function abrirFirma() {
    isFullScreen = false;
    Swal.fire({
        title: 'Firma Digital Requerida',
        html: `
            <div id="canvasWrapper" style="position:relative; width:100%; height:250px; border:2px dashed #a0aec0; border-radius:8px; background-color:#f8fafc; overflow:hidden; touch-action:none; transition: height 0.3s ease;">
                
                <button type="button" id="btnFullScreen" class="btn btn-sm btn-light border shadow-sm" style="position:absolute; top:10px; right:10px; z-index:10; border-radius:8px;">
                    <i id="fsIcon" class="bi bi-arrows-fullscreen text-secondary"></i>
                </button>

                <div style="position:absolute; bottom:35%; left:10%; right:10%; border-bottom:2px solid #94a3b8; pointer-events:none;"></div>
                <span style="position:absolute; bottom:calc(35% - 25px); left:0; width:100%; text-align:center; color:#64748b; font-size:14px; font-weight:bold; text-transform:uppercase; pointer-events:none;">Firme sobre la línea</span>
                
                <canvas id="swalCanvas" style="width:100%; height:100%; position:absolute; top:0; left:0; touch-action:none;"></canvas>
            </div>
        `,
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: '<i class="bi bi-check-circle me-1"></i> GUARDAR',
        denyButtonText: '<i class="bi bi-eraser me-1"></i> LIMPIAR',
        cancelButtonText: 'CANCELAR',
        confirmButtonColor: '#198754',
        denyButtonColor: '#6c757d',
        customClass: {
            popup: 'swal-firma-popup rounded-4 shadow-lg',
            title: 'fs-5 text-dark fw-bold mb-0',
            actions: 'w-100 d-flex justify-content-center gap-2 flex-wrap mt-3'
        },
        didOpen: () => {
            ajustarCanvas();
            
            const canvas = document.getElementById('swalCanvas');
            window.sp = new SignaturePad(canvas, {
                penColor: 'rgb(16, 42, 87)',
                backgroundColor: 'rgba(0,0,0,0)'
            });

            document.getElementById('btnFullScreen').addEventListener('click', toggleFullScreen);
        },
        preDeny: () => {
            // Esta función intercepta el botón LIMPIAR para que no cierre el SweetAlert
            window.sp.clear();
            return false;
        },
        preConfirm: () => {
            if (window.sp.isEmpty()) {
                Swal.showValidationMessage('La firma no puede estar vacía. Por favor, firme sobre la línea indicadora.');
                return false;
            }
            return window.sp.toDataURL();
        }
    }).then((result) => {
        if (result.isConfirmed) {
            enviarFirma(result.value);
        }
    });
}

function ajustarCanvas() {
    const canvas = document.getElementById('swalCanvas');
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    canvas.width = canvas.offsetWidth * ratio;
    canvas.height = canvas.offsetHeight * ratio;
    canvas.getContext("2d").scale(ratio, ratio);
}

function toggleFullScreen() {
    const popup = Swal.getPopup();
    const icon = document.getElementById('fsIcon');
    const trazosGuardados = window.sp.toData();

    if (!isFullScreen) {
        popup.classList.add('swal-fullscreen');
        icon.classList.replace('bi-arrows-fullscreen', 'bi-fullscreen-exit');
        isFullScreen = true;
    } else {
        popup.classList.remove('swal-fullscreen');
        icon.classList.replace('bi-fullscreen-exit', 'bi-arrows-fullscreen');
        isFullScreen = false;
    }

    // Esperar a que la transición CSS termine y recalcular
    setTimeout(() => {
        ajustarCanvas();
        if (trazosGuardados.length > 0) {
            window.sp.fromData(trazosGuardados);
        }
    }, 310);
}

function enviarFirma(base64Data) {
    Swal.fire({
        title: 'Guardando...',
        text: 'Procesando firma digital',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    fetch('guardar_firma.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'imgBase64=' + encodeURIComponent(base64Data)
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') location.reload();
        else throw new Error();
    })
    .catch(() => {
        fetch('acciones/guardar_firma.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'imgBase64=' + encodeURIComponent(base64Data)
        }).then(() => location.reload());
    });
}

const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('msg') === 'datos_ok') Swal.fire('Éxito', 'Tus datos se actualizaron correctamente.', 'success');
if (urlParams.get('msg') === 'pass_ok') Swal.fire('Éxito', 'Tu clave de seguridad ha sido cambiada.', 'success');
</script>
<?php include 'includes/layout_footer.php'; ?>
