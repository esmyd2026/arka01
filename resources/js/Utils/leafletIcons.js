// Arregla un problema conocido de Leaflet con bundlers como Vite: por defecto
// arma la URL de los íconos del marcador a partir de la ubicación del propio
// JS, y no encuentra las imágenes. Acá se las indicamos explícitamente.
import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

let fixed = false;

export function fixLeafletIcons() {
    if (fixed) return;

    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
        iconRetinaUrl: markerIcon2x,
        iconUrl: markerIcon,
        shadowUrl: markerShadow,
    });

    fixed = true;
}
