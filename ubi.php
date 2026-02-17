<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Croquis Real - Mi Kiosco</title>
    <script src='https://unpkg.com/maplibre-gl@3.3.1/dist/maplibre-gl.js'></script>
    <link href='https://unpkg.com/maplibre-gl@3.3.1/dist/maplibre-gl.css' rel='stylesheet' />
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; background: #000; color: white; }
        #map { position: absolute; top: 0; bottom: 0; width: 100%; }
        .ui-panel { position: absolute; top: 20px; left: 20px; z-index: 10; background: rgba(0,0,0,0.8); padding: 20px; border-radius: 12px; border-left: 5px solid red; width: 250px; }
        .info-tag { position: absolute; bottom: 50px; left: 50%; transform: translateX(-50%); background: red; padding: 15px 30px; border-radius: 50px; font-weight: bold; font-size: 1.2rem; display: none; z-index: 100; box-shadow: 0 0 20px rgba(0,0,0,0.5); border: 2px solid white; }
        button { width: 100%; padding: 15px; background: red; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 16px; }
        @media print { .ui-panel, .info-tag { display: none !important; } }
    </style>
</head>
<body>

<div class="ui-panel" id="control">
    <h2 style="margin:0 0 10px 0;">Guía de Llegada</h2>
    <p style="font-size:13px; color:#ccc;">Recorrido real: Estadio, Acceso Sur, Boliches y Kiosco.</p>
    <button onclick="iniciarTour()">INICIAR GUÍA 🚗</button>
</div>

<div class="info-tag" id="label"></div>
<div id="map"></div>

<script>
    // RUTA MANUAL (Sin GPS automático, dibujada a mano por las calles reales)
    const paradas = [
        { t: "Estadio 23 de Agosto (El Lobo)", c: [-65.2890, -24.2013], b: 0 },
        { t: "Bajando por Acceso Sur", c: [-65.2820, -24.2085], b: 45 },
        { t: "Ibiza / Astros (Boliches)", c: [-65.2754, -24.2173], b: 120 },
        { t: "Kolor Show (Refinor al frente)", c: [-65.2743, -24.2186], b: 140 },
        { t: "Entrada por Av. Forestal", c: [-65.2681, -24.2238], b: 90 },
        { t: "Cancha del Suri", c: [-65.2635, -24.2305], b: 90 },
        { t: "Calle Palpalá", c: [-65.2590, -24.2335], b: 45 },
        { t: "¡Llegaste al Kiosco!", c: [-65.255016, -24.235805], b: 0 }
    ];

    const map = new maplibregl.Map({
        container: 'map',
        style: 'https://tiles.openfreemap.org/styles/liberty', 
        center: paradas[0].c,
        zoom: 17, pitch: 75, antialias: true
    });

    map.on('load', () => {
        map.addSource('camino', { 'type': 'geojson', 'data': { 'type': 'Feature', 'geometry': { 'type': 'LineString', 'coordinates': [] } } });
        map.addLayer({ 'id': 'ruta', 'type': 'line', 'source': 'camino', 'paint': { 'line-color': 'red', 'line-width': 7 } });
        
        // Marcador final en el Kiosco
        new maplibregl.Marker({color: 'red'}).setLngLat(paradas[paradas.length-1].c).addTo(map);

        // Edificios 3D
        map.addLayer({
            'id': '3d-buildings', 'source': 'openmaptiles', 'source-layer': 'building',
            'type': 'fill-extrusion', 'minzoom': 14,
            'paint': { 'fill-extrusion-color': '#555', 'fill-extrusion-height': ['get', 'render_height'], 'fill-extrusion-opacity': 0.8 }
        });
    });

    let i = 0;
    let path = [];

    function iniciarTour() {
        document.getElementById('control').style.display = 'none';
        pasoAPaso();
    }

    function pasoAPaso() {
        if (i < paradas.length) {
            const p = paradas[i];
            path.push(p.c);
            
            map.getSource('camino').setData({ 'type': 'Feature', 'geometry': { 'type': 'LineString', 'coordinates': path } });

            map.flyTo({
                center: p.c, zoom: 17.5, pitch: 75, bearing: p.b,
                duration: 5000, // 5 segundos de movimiento lento
                essential: true
            });

            const tag = document.getElementById('label');
            tag.innerText = p.t;
            tag.style.display = 'block';

            setTimeout(() => {
                tag.style.display = 'none';
                i++;
                pasoAPaso();
            }, 6000); // Se queda 6 segundos para que se distinga todo bien
        } else {
            document.getElementById('control').style.display = 'block';
        }
    }
</script>
</body>
</html>
