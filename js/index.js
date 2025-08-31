// js/index.js - Pour le site public
let map;
let polylines = {};
let departureMarker, arrivalMarker, currentRoute;

document.addEventListener("DOMContentLoaded", () => {
    initializeMap();
    setupEventListeners();
});

function initializeMap() {
    // Initialiser la carte Leaflet
    map = L.map("map").setView([33.5731, -7.5898], 12);

    // Ajouter le fond de carte OpenStreetMap
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    // Créer les polylignes pour chaque ligne de taxi récupérée depuis PHP
    taxiLines.forEach(line => {
        if (line.points && line.points.length > 1) {
            const polyline = L.polyline(line.points, {
                color: line.color,
                weight: 5,
                opacity: 0.7,
            }).addTo(map);

            // Ajouter un popup avec le nom de la ligne
            polyline.bindPopup(`<b>${line.name}</b>`);

            // Stocker la référence de la polyline
            polylines[line.id] = polyline;

            // Ajouter un événement clic pour mettre en évidence la ligne
            polyline.on('click', function (e) {
                highlightRoute(line.id);
            });
        }
    });
}

function setupEventListeners() {
    // Gestion des groupes dans la sidebar
    document.querySelectorAll('.group-header').forEach(header => {
        header.addEventListener('click', function () {
            toggleGroup(this);
        });
    });

    // Filtre par point de départ
    const filterInput = document.getElementById('departureFilter');
    if (filterInput) {
        filterInput.addEventListener('input', filterDepartures);
    }
}

function toggleGroup(header) {
    const content = header.nextElementSibling;
    const icon = header.querySelector('.toggle-icon');

    content.classList.toggle('collapsed');
    header.classList.toggle('collapsed');

    if (content.classList.contains('collapsed')) {
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
    } else {
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    }
}

function filterDepartures(e) {
    const query = e.target.value.toLowerCase().trim();
    const groups = document.querySelectorAll('.departure-group');

    groups.forEach(group => {
        const departureName = group.getAttribute('data-departure').toLowerCase();
        if (departureName.includes(query)) {
            group.style.display = 'block';
        } else {
            group.style.display = 'none';
        }
    });

    // Gestion du message "Aucun résultat"
    const container = document.getElementById('groupedRoutes');
    let noResults = container.querySelector('.no-results');
    const visibleGroups = document.querySelectorAll('.departure-group:not([style*="display: none"])');

    if (visibleGroups.length === 0 && query) {
        if (!noResults) {
            noResults = document.createElement('div');
            noResults.className = 'no-results text-center py-3 text-muted';
            noResults.innerHTML = '<i class="fas fa-search me-2"></i>Aucun point de départ trouvé';
            container.appendChild(noResults);
        }
    } else if (noResults) {
        noResults.remove();
    }
}

function highlightRoute(lineId) {
    const lineData = taxiLines.find(l => l.id == lineId);
    if (!lineData || !polylines[lineId]) return;

    // Réinitialiser les styles de toutes les lignes
    Object.values(polylines).forEach(polyline => {
        const originalLine = taxiLines.find(l => polylines[l.id] === polyline);
        if (originalLine) {
            polyline.setStyle({ opacity: 0.4, weight: 3 });
        }
    });

    // Mettre en évidence la ligne sélectionnée
    const selectedPolyline = polylines[lineId];
    selectedPolyline.setStyle({ opacity: 1, weight: 7, color: lineData.color });

    // Centrer la carte sur la ligne
    map.fitBounds(selectedPolyline.getBounds(), { padding: [50, 50] });

    // Afficher les marqueurs de départ et d'arrivée
    if (departureMarker) map.removeLayer(departureMarker);
    if (arrivalMarker) map.removeLayer(arrivalMarker);

    const points = lineData.points;
    if (points.length > 0) {
        const start = points[0];
        const end = points[points.length - 1];

        departureMarker = L.marker(start, {
            icon: L.divIcon({
                html: '<div style="background: #10b981; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">D</div>',
                iconSize: [30, 30],
                className: 'custom-div-icon'
            })
        }).addTo(map).bindPopup(`<b>Départ:</b> ${lineData.name.split(' - ')[0]}`);

        arrivalMarker = L.marker(end, {
            icon: L.divIcon({
                html: '<div style="background: #ef4444; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">A</div>',
                iconSize: [30, 30],
                className: 'custom-div-icon'
            })
        }).addTo(map).bindPopup(`<b>Arrivée:</b> ${lineData.name.split(' - ')[1] || 'Destination'}`);
    }

    // Mettre à jour l'affichage du nom de la route
    const displayNameElement = document.getElementById("selectedRouteName");
    const routeNameTextElement = document.getElementById("routeNameText");
    if (displayNameElement && routeNameTextElement) {
        routeNameTextElement.textContent = lineData.name;
        displayNameElement.classList.remove('d-none');
    }

    // Mettre à jour l'état actif dans la liste
    document.querySelectorAll('.route-item').forEach(item => item.classList.remove('active'));
    const activeItem = document.querySelector(`[data-line-id="${lineId}"]`);
    if (activeItem) activeItem.classList.add('active');
}

function zoomToLine(lineId) {
    highlightRoute(lineId);
}

function resetView() {
    // Réinitialiser les styles de toutes les lignes
    Object.values(polylines).forEach(polyline => {
        const originalLine = taxiLines.find(l => polylines[l.id] === polyline);
        if (originalLine) {
            polyline.setStyle({ opacity: 0.7, weight: 5, color: originalLine.color });
        }
    });

    // Supprimer les marqueurs
    if (departureMarker) map.removeLayer(departureMarker);
    if (arrivalMarker) map.removeLayer(arrivalMarker);
    if (currentRoute) map.removeLayer(currentRoute);

    // Réinitialiser l'affichage du nom de la route
    const displayNameElement = document.getElementById("selectedRouteName");
    if (displayNameElement) {
        displayNameElement.classList.add('d-none');
    }

    // Réinitialiser l'état actif dans la liste
    document.querySelectorAll('.route-item').forEach(item => item.classList.remove('active'));

    // Réinitialiser la vue de la carte
    map.setView([33.5731, -7.5898], 12);
}

// --- Fonctions de recherche (inchangées) ---
let typingTimers = {};
let cache = {};

function setupAutocomplete(inputId, suggestionsId) {
    const input = document.getElementById(inputId);
    const box = document.getElementById(suggestionsId);

    input.addEventListener("input", () => {
        const query = input.value.trim();
        clearTimeout(typingTimers[inputId]);

        if (query.length < 2) {
            box.style.display = "none";
            return;
        }

        box.innerHTML = "<div class='suggestion-item'><i class='fas fa-spinner fa-spin me-2'></i>Recherche...</div>";
        box.style.display = "block";

        typingTimers[inputId] = setTimeout(async () => {
            if (cache[query]) {
                showSuggestions(cache[query], input, box);
                return;
            }

            // Note: Vous devez obtenir un token Mapbox valide
            const MAPBOX_TOKEN = "YOUR_MAPBOX_ACCESS_TOKEN";
            const proximity = "-7.5898,33.5731";
            const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json?access_token=${MAPBOX_TOKEN}&limit=5&language=fr&proximity=${proximity}`;

            try {
                const res = await fetch(url);
                const data = await res.json();
                cache[query] = data.features;
                showSuggestions(data.features, input, box);
            } catch (error) {
                console.error("Erreur Mapbox:", error);
                box.innerHTML = "<div class='suggestion-item'><i class='fas fa-exclamation-triangle me-2'></i>Erreur de recherche</div>";
            }
        }, 300);
    });

    input.addEventListener("blur", () => {
        setTimeout(() => {
            if (box) box.style.display = "none";
        }, 150);
    });
}

function showSuggestions(features, input, box) {
    if (!box) return;

    box.innerHTML = "";
    const filtered = features.filter(f =>
        f.context?.some(c => c.text.toLowerCase().includes("casablanca")) ||
        f.text.toLowerCase().includes("casablanca")
    );

    if (!filtered.length) {
        box.innerHTML = "<div class='suggestion-item'><i class='fas fa-info-circle me-2'></i>Aucun résultat</div>";
        return;
    }

    filtered.forEach(place => {
        const div = document.createElement("div");
        div.className = "suggestion-item";
        div.innerHTML = `<i class='fas fa-map-marker-alt me-2'></i> ${place.place_name}`;
        div.onclick = () => {
            input.value = place.place_name;
            box.style.display = "none";
        };
        box.appendChild(div);
    });
    box.style.display = "block";
}

async function searchRouteFromInputs() {
    const start = document.getElementById("startPoint").value;
    const end = document.getElementById("endPoint").value;

    if (start && end) {
        await showRouteByName(start, end);
    } else {
        alert("Veuillez entrer un point de départ et une destination.");
    }
}

async function showRouteByName(startName, endName) {
    // Note: Vous devez obtenir un token Mapbox valide
    const MAPBOX_TOKEN = "YOUR_MAPBOX_ACCESS_TOKEN";

    try {
        const [startRes, endRes] = await Promise.all([
            fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(startName)}.json?access_token=${MAPBOX_TOKEN}&limit=1&language=fr`),
            fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(endName)}.json?access_token=${MAPBOX_TOKEN}&limit=1&language=fr`)
        ]);

        const startData = await startRes.json();
        const endData = await endRes.json();

        if (!startData.features.length || !endData.features.length) {
            alert("Coordonnées introuvables.");
            return;
        }

        const start = startData.features[0].center; // [lng, lat]
        const end = endData.features[0].center;     // [lng, lat]

        const routeRes = await fetch(`https://router.project-osrm.org/route/v1/driving/${start[0]},${start[1]};${end[0]},${end[1]}?overview=full&geometries=geojson`);
        const routeJson = await routeRes.json();

        if (!routeJson.routes || !routeJson.routes.length) {
            alert("Impossible de calculer l'itinéraire.");
            return;
        }

        const coords = routeJson.routes[0].geometry.coordinates.map(([lon, lat]) => [lat, lon]);

        if (currentRoute) map.removeLayer(currentRoute);
        currentRoute = L.polyline(coords, {
            color: "#f59e0b", // Couleur orange pour les trajets calculés
            weight: 6,
            opacity: 0.9,
        }).addTo(map);

        map.fitBounds(currentRoute.getBounds(), { padding: [50, 50] });

        // Afficher les informations de l'itinéraire
        const displayNameElement = document.getElementById("selectedRouteName");
        const routeNameTextElement = document.getElementById("routeNameText");
        if (displayNameElement && routeNameTextElement) {
            routeNameTextElement.textContent = `Itinéraire: ${startName} → ${endName}`;
            displayNameElement.classList.remove('d-none');
        }

    } catch (error) {
        console.error("Erreur itinéraire:", error);
        alert("Impossible de tracer le trajet. Vérifiez votre connexion internet.");
    }
}

function useCurrentLocation() {
    if (!navigator.geolocation) {
        alert("Géolocalisation non supportée par votre navigateur");
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            const lat = pos.coords.latitude;
            const lon = pos.coords.longitude;

            const marker = L.marker([lat, lon], {
                icon: L.divIcon({
                    html: '<div style="background: #3b82f6; color: white; border-radius: 50%; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-location-dot"></i></div>',
                    iconSize: [35, 35],
                    className: 'custom-div-icon'
                })
            }).addTo(map).bindPopup("Votre position actuelle").openPopup();

            map.setView([lat, lon], 15);
            document.getElementById("startPoint").value = `Position actuelle (${lat.toFixed(4)}, ${lon.toFixed(4)})`;
        },
        (error) => {
            console.error("Erreur géolocalisation:", error);
            let message = "Impossible d'obtenir votre position. ";
            switch (error.code) {
                case error.PERMISSION_DENIED:
                    message += "Permission refusée.";
                    break;
                case error.POSITION_UNAVAILABLE:
                    message += "Position indisponible.";
                    break;
                case error.TIMEOUT:
                    message += "Délai dépassé.";
                    break;
                default:
                    message += "Erreur inconnue.";
                    break;
            }
            alert(message);
        }
    );
}

// Initialiser les autocomplétions après le chargement du DOM
document.addEventListener("DOMContentLoaded", () => {
    if (document.getElementById("startPoint")) {
        setupAutocomplete("startPoint", "startSuggestions");
    }
    if (document.getElementById("endPoint")) {
        setupAutocomplete("endPoint", "endSuggestions");
    }
});