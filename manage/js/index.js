// manage/js/index.js - Pour l'administration
const map = L.map('map', {
    zoomControl: true,
    attributionControl: true
}).setView([33.5731, -7.5898], 12);

// Ajouter le fond de carte OpenStreetMap
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 18
}).addTo(map);

// États de l'application
let depMarker = null;
let arrMarker = null;
let routeLine = null;
let waypointMarkers = [];
let isRouteGenerating = false;

// Références aux éléments du DOM
const coordsFld = document.getElementById('coords');
const genBtn = document.getElementById('gen');
const colorPicker = document.querySelector('input[name="color"]');

// Initialisation de la carte
document.addEventListener('DOMContentLoaded', function () {
    try {
        // Debug: Afficher les points reçus
        // console.log("existingPoints reçus:", existingPoints);

        // Si nous sommes en mode édition et que des points existent, les reconstruire
        if (Array.isArray(existingPoints) && existingPoints.length > 0) {
            reconstructRoute();
        }

        // Ajouter l'échelle
        L.control.scale({ imperial: false }).addTo(map);

        console.log('Map system initialized successfully');
    } catch (error) {
        console.error('Map initialization failed:', error);
        alert('Erreur lors de l\'initialisation de la carte');
    }
});

// Reconstituer un trajet existant pour l'édition
function reconstructRoute() {
    if (!Array.isArray(existingPoints) || existingPoints.length < 2) {
        console.warn('Invalid existingPoints data for reconstruction');
        return;
    }

    try {
        // existingPoints est un tableau de [latitude, longitude]
        const startLatLng = L.latLng(existingPoints[0][0], existingPoints[0][1]); // L.latLng(lat, lng)
        const endLatLng = L.latLng(existingPoints[existingPoints.length - 1][0], existingPoints[existingPoints.length - 1][1]);
        const intermediatePoints = existingPoints.slice(1, -1); // [[lat, lng], ...]

        // Créer le marqueur de départ
        depMarker = L.marker(startLatLng, {
            draggable: true,
            title: 'Point de départ - Glisser pour déplacer'
        }).addTo(map);
        depMarker.on('dragend', handleMarkerDragEnd);

        // Créer le marqueur d'arrivée
        arrMarker = L.marker(endLatLng, {
            draggable: true,
            title: 'Point d\'arrivée - Glisser pour déplacer'
        }).addTo(map);
        arrMarker.on('dragend', handleMarkerDragEnd);

        // Créer les marqueurs intermédiaires
        waypointMarkers = [];
        intermediatePoints.forEach((point, index) => {
            // point est [lat, lng]
            const marker = L.marker(L.latLng(point[0], point[1]), { // L.latLng(lat, lng)
                draggable: true,
                title: `Point intermédiaire ${index + 1}`,
                icon: L.divIcon({
                    className: 'waypoint-icon',
                    html: `<span>${index + 1}</span>`,
                    iconSize: [24, 24]
                })
            }).addTo(map);

            marker.on('dragend', updateRouteFromMarkers);
            marker.on('click', function () {
                removeWaypoint(marker);
            });

            waypointMarkers.push(marker);
        });

        // Mettre à jour la ligne de route initiale
        updateRouteFromMarkers();

        // Centrer la carte sur le trajet
        const allPoints = [startLatLng, ...intermediatePoints.map(p => L.latLng(p[0], p[1])), endLatLng];
        map.fitBounds(allPoints, { padding: [50, 50] });

    } catch (error) {
        console.error('Route reconstruction failed:', error);
        alert('Erreur lors du chargement du trajet existant');
    }
}

// Gestionnaire d'événement pour le clic sur la carte
map.on('click', function (e) {
    if (isRouteGenerating) return;

    try {
        if (!depMarker) {
            // Créer le marqueur de départ
            depMarker = L.marker(e.latlng, {
                draggable: true,
                title: 'Point de départ'
            }).addTo(map);
            depMarker.on('dragend', handleMarkerDragEnd);

        } else if (!arrMarker) {
            // Créer le marqueur d'arrivée
            arrMarker = L.marker(e.latlng, {
                draggable: true,
                title: 'Point d\'arrivée'
            }).addTo(map);
            arrMarker.on('dragend', handleMarkerDragEnd);

        } else {
            // Ajouter un point intermédiaire
            const marker = L.marker(e.latlng, {
                draggable: true,
                title: `Point intermédiaire ${waypointMarkers.length + 1}`,
                icon: L.divIcon({
                    className: 'waypoint-icon',
                    html: `<span>${waypointMarkers.length + 1}</span>`,
                    iconSize: [24, 24]
                })
            }).addTo(map);

            marker.on('dragend', updateRouteFromMarkers);
            marker.on('click', function () {
                removeWaypoint(marker);
            });

            waypointMarkers.push(marker);
            updateRouteFromMarkers();
        }
    } catch (error) {
        console.error('Marker creation failed:', error);
        alert('Erreur lors de l\'ajout du point');
    }
});

// Gestionnaire pour la fin du déplacement d'un marqueur
function handleMarkerDragEnd(e) {
    updateRouteFromMarkers();
}

// Supprimer un point intermédiaire
function removeWaypoint(marker) {
    try {
        map.removeLayer(marker);
        waypointMarkers = waypointMarkers.filter(m => m !== marker);

        // Réindexer les numéros des marqueurs restants
        waypointMarkers.forEach((m, index) => {
            m.setIcon(L.divIcon({
                className: 'waypoint-icon',
                html: `<span>${index + 1}</span>`,
                iconSize: [24, 24]
            }));
        });

        updateRouteFromMarkers();
    } catch (error) {
        console.error('Waypoint removal failed:', error);
        alert('Erreur lors de la suppression du point');
    }
}

// Mettre à jour la ligne de route en se basant sur les marqueurs
async function updateRouteFromMarkers() {
    if (!depMarker || !arrMarker) {
        // Si pas assez de points, supprimer la ligne existante
        if (routeLine) {
            map.removeLayer(routeLine);
            routeLine = null;
        }
        updateCoordsField();
        return;
    }

    try {
        const start = depMarker.getLatLng(); // L.latLng(lat, lng)
        const end = arrMarker.getLatLng();
        const waypoints = waypointMarkers.map(m => m.getLatLng());
        const allPoints = [start, ...waypoints, end];

        // Construire la chaîne de coordonnées pour OSRM
        // OSRM attend: lng,lat;lng,lat;...
        const coordString = allPoints.map(p => `${p.lng},${p.lat}`).join(';');
        const url = `https://router.project-osrm.org/route/v1/driving/${coordString}?overview=full&geometries=geojson`;

        const response = await fetch(url);

        if (!response.ok) {
            throw new Error(`OSRM API error: ${response.status}`);
        }

        const data = await response.json();

        if (data.code !== 'Ok' || !data.routes || data.routes.length === 0) {
            // En cas d'échec d'OSRM, tracer une ligne droite
            updateFallbackRoute(allPoints);
            return;
        }

        const route = data.routes[0];
        // OSRM renvoie coordinates: [[lng, lat], ...]
        // Nous voulons les stocker comme [[lat, lng], ...] dans la base
        const coords = route.geometry.coordinates.map(c => [c[1], c[0]]); // Convertir [lng,lat] en [lat,lng]

        // Mettre à jour ou créer la ligne de route
        if (routeLine) {
            // setLatLngs attend [[lat, lng], ...] ou [L.latLng(lat, lng), ...]
            routeLine.setLatLngs(coords);
        } else {
            const color = colorPicker ? colorPicker.value : '#3b82f6';
            routeLine = L.polyline(coords, {
                color: color,
                weight: 6,
                opacity: 0.8
            }).addTo(map);
        }

        updateCoordsField(coords);

    } catch (error) {
        console.error('Route update failed:', error);
        // Tracer une ligne droite en cas d'erreur
        const start = depMarker.getLatLng();
        const end = arrMarker.getLatLng();
        const waypoints = waypointMarkers.map(m => m.getLatLng());
        const allPoints = [start, ...waypoints, end];
        updateFallbackRoute(allPoints);
    }
}

// Tracer une ligne droite (fallback)
function updateFallbackRoute(points) {
    // points est un tableau de L.latLng
    // Nous voulons les stocker comme [[lat, lng], ...]
    const coords = points.map(p => [p.lat, p.lng]);

    if (routeLine) {
        routeLine.setLatLngs(coords);
        routeLine.setStyle({ dashArray: '10, 10', opacity: 0.6 });
    } else {
        const color = colorPicker ? colorPicker.value : '#3b82f6';
        routeLine = L.polyline(coords, {
            color: color,
            weight: 4,
            opacity: 0.6,
            dashArray: '10, 10'
        }).addTo(map);
    }

    updateCoordsField(coords);
}

// Mettre à jour le champ caché des coordonnées
// coordinates est un tableau de [latitude, longitude]
function updateCoordsField(coordinates = null) {
    if (!coordsFld) return;

    if (coordinates) {
        // Si des coordonnées sont fournies, les utiliser
        // coordinates est déjà [[lat, lng], ...]
        coordsFld.value = JSON.stringify(coordinates);
    } else if (routeLine) {
        // Sinon, utiliser les coordonnées de la ligne actuelle
        const latlngs = routeLine.getLatLngs(); // [L.latLng(lat, lng), ...] ou [[lat, lng], ...]
        // S'assurer que c'est un tableau de [lat, lng]
        const coords = latlngs.map(latlng =>
            Array.isArray(latlng) ? [latlng[0], latlng[1]] : [latlng.lat, latlng.lng]
        );
        coordsFld.value = JSON.stringify(coords);
    } else {
        // Sinon, tableau vide
        coordsFld.value = '[]';
    }
}

// Gestionnaire du bouton "Générer"
genBtn.addEventListener('click', async function () {
    if (isRouteGenerating) return;

    if (!depMarker || !arrMarker) {
        alert('Veuillez sélectionner un point de départ et un point d\'arrivée sur la carte.');
        return;
    }

    try {
        isRouteGenerating = true;
        genBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><span class="d-none d-sm-inline">Génération...</span>';
        genBtn.disabled = true;

        await updateRouteFromMarkers();

    } catch (error) {
        console.error('Route generation failed:', error);
        alert('Erreur lors de la génération du trajet');
    } finally {
        isRouteGenerating = false;
        genBtn.innerHTML = '<i class="fas fa-magic me-1"></i><span class="d-none d-sm-inline">Générer</span>';
        genBtn.disabled = false;
    }
});

// Gestionnaire de changement de couleur
if (colorPicker) {
    colorPicker.addEventListener('change', function () {
        if (routeLine) {
            routeLine.setStyle({ color: this.value });
        }
    });
}

// Nettoyer la carte (optionnel, peut être appelé si nécessaire)
function cleanup() {
    try {
        if (depMarker) map.removeLayer(depMarker);
        if (arrMarker) map.removeLayer(arrMarker);
        waypointMarkers.forEach(marker => map.removeLayer(marker));
        if (routeLine) map.removeLayer(routeLine);

        depMarker = null;
        arrMarker = null;
        routeLine = null;
        waypointMarkers = [];

        if (coordsFld) coordsFld.value = '[]';

    } catch (error) {
        console.error('Cleanup failed:', error);
    }
}