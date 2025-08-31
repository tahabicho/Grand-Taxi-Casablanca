<div class="search-section mb-4">
    <h3 class="h5 mb-3">
        <i class="fas fa-search me-2 text-primary"></i>
        Rechercher un trajet
    </h3>

    <div class="mb-3 position-relative">
        <div class="input-group">
            <span class="input-group-text"><i class="fas fa-location-dot"></i></span>
            <input type="text" id="startPoint" class="form-control" placeholder="Point de départ...">
        </div>
        <div id="startSuggestions" class="suggestions glass-card"></div>
    </div>

    <div class="mb-3 position-relative">
        <div class="input-group">
            <span class="input-group-text"><i class="fas fa-flag-checkered"></i></span>
            <input type="text" id="endPoint" class="form-control" placeholder="Destination...">
        </div>
        <div id="endSuggestions" class="suggestions glass-card"></div>
    </div>

    <div class="d-grid gap-2 mb-3">
        <button class="btn btn-primary" onclick="searchRouteFromInputs()">
            <i class="fas fa-route me-2"></i>Rechercher
        </button>
        <button class="btn btn-outline-secondary" onclick="useCurrentLocation()">
            <i class="fas fa-location-crosshairs me-2"></i>Ma position
        </button>
    </div>

    <div class="mb-3">
        <div class="input-group">
            <span class="input-group-text"><i class="fas fa-filter"></i></span>
            <input type="text" id="departureFilter" class="form-control" placeholder="Filtrer par point de départ...">
        </div>
    </div>
</div>

<div class="route-list">
    <h3 class="h5 mb-3">
        <i class="fas fa-map-location-dot me-2 text-primary"></i>
        Trajets par point de départ
    </h3>

    <div id="groupedRoutes">
        <?php if (empty($groupedLines)): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-info-circle me-2"></i>
                Aucune ligne enregistrée pour le moment.
            </div>
        <?php else: ?>
            <?php foreach ($groupedLines as $group): ?>
                <div class="departure-group mb-3 rounded glass-card" data-departure="<?= htmlspecialchars($group['departure_name']) ?>">
                    <div class="group-header p-3 rounded-top cursor-pointer d-flex justify-content-between align-items-center" onclick="toggleGroup(this)">
                        <div>
                            <div class="group-title fw-bold">
                                <i class="fas fa-location-pin me-2 text-primary"></i><?= htmlspecialchars($group['departure_name']) ?>
                            </div>
                            <div class="group-subtitle text-muted small mt-1">
                                <?= count($group['lines']) ?> ligne<?= count($group['lines']) > 1 ? 's' : '' ?> disponible<?= count($group['lines']) > 1 ? 's' : '' ?>
                            </div>
                        </div>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                    <div class="group-content">
                        <?php foreach ($group['lines'] as $line): ?>
                            <div class="route-item p-3 border-start border-4 rounded-0 rounded-bottom cursor-pointer"
                                onclick="zoomToLine(<?= $line['id'] ?>)"
                                style="border-left-color: <?= htmlspecialchars($line['color']) ?> !important"
                                data-line-id="<?= $line['id'] ?>">
                                <div class="route-name fw-medium"><?= htmlspecialchars($line['name']) ?></div>
                                <div class="route-destination text-muted small mt-1">
                                    <i class="fas fa-arrow-right me-1"></i><?= htmlspecialchars($line['destination']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>