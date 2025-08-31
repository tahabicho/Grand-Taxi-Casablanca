<?php
// Connexion à la base de données
$host = 'localhost';
$db = 'taxi_casablanca';
$user = 'root';
$pass = '';

try {
  $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Erreur de connexion : " . $e->getMessage());
}

// Récupérer toutes les lignes avec leurs points de trajet
try {
  $stmt = $pdo->query("SELECT * FROM taxi_line ORDER BY id DESC");
  $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $lines = [];
  // Vous pouvez gérer l'erreur ici si nécessaire
}

$linesWithPoints = [];
foreach ($lines as $line) {
  try {
    $stmtPts = $pdo->prepare("SELECT latitude, longitude FROM trajets WHERE line_id = ? ORDER BY ordre ASC");
    $stmtPts->execute([$line['id']]);
    $points = $stmtPts->fetchAll(PDO::FETCH_ASSOC);

    // Formater les points pour JS : [[lat,lng], [lat,lng], ...]
    $formattedPoints = array_map(fn($pt) => [(float)$pt['latitude'], (float)$pt['longitude']], $points);

    $linesWithPoints[] = [
      'id' => $line['id'],
      'name' => $line['name'],
      'color' => $line['color'],
      'points' => $formattedPoints,
    ];
  } catch (PDOException $e) {
    // En cas d'erreur pour une ligne spécifique, on la passe
    continue;
  }
}

// Regrouper les lignes par point de départ
function groupLines($lines)
{
  $groups = [];

  foreach ($lines as $line) {
    if (empty($line['points'])) continue;

    // --- CHANGEMENT ICI ---
    // Regrouper uniquement par le point de départ extrait
    $departureName = extractDepartureName($line['name']);
    $groupKey = strtolower(trim($departureName)); // Utiliser le départ comme clé

    if (!isset($groups[$groupKey])) {
      $groups[$groupKey] = [
        'label' => ucfirst($departureName), // Afficher le nom avec majuscule
        'departure_name' => $departureName, // Garder le nom original pour le filtre
        'lines' => []
      ];
    }

    // Ajouter la destination à la ligne pour l'affichage
    $line['destination'] = extractDestinationName($line['name']);
    $groups[$groupKey]['lines'][] = $line;
  }

  // Tri alphabétique des groupes par leur clé (nom du départ)
  ksort($groups);

  return $groups;
}

function extractDepartureName($lineName)
{
  // Logique pour extraire le nom du point de départ
  // Exemple: "Derb Sultan - Sidi Bernoussi" -> "Derb Sultan"
  $parts = explode(' - ', $lineName);
  return trim($parts[0]);
}

function extractDestinationName($lineName)
{
  // Logique pour extraire le nom de la destination
  $parts = explode(' - ', $lineName);
  return count($parts) > 1 ? trim($parts[1]) : 'Destination';
}

$groupedLines = groupLines($linesWithPoints);
?>
<!DOCTYPE html>
<html lang="fr" data-bs-theme="dark">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Carte interactive des trajets de grands taxis à Casablanca" />
  <title>Carte des Grands Taxis - Casablanca</title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Font Awesome -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" rel="stylesheet">

  <!-- Leaflet CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />

  <!-- Custom CSS -->
  <link rel="stylesheet" href="css/index.css">

  <!-- Favicon -->
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🚖</text></svg>">
</head>

<body>
  <!-- Header -->
  <header class="header sticky-top">
    <div class="container py-3">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h1 class="h3 mb-0 fw-bold">
            <i class="fas fa-taxi text-primary me-2"></i>
            <span class="d-none d-sm-inline">Carte des Grands Taxis</span>
            <span class="d-sm-none">Grands Taxis</span>
          </h1>
          <p class="mb-0 text-muted d-none d-md-block">Casablanca, Maroc</p>
        </div>
        <div class="d-flex gap-2">
          <a href="manage/" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-lock me-1"></i>
            <span class="d-none d-sm-inline">Admin</span>
          </a>
          <button class="btn btn-sm btn-primary d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar">
            <i class="fas fa-bars"></i>
          </button>
        </div>
      </div>
    </div>
  </header>

  <div class="container-fluid mt-3">
    <div class="row g-3">
      <!-- Sidebar - Desktop -->
      <div class="col-xl-4 col-lg-5 d-none d-md-block">
        <div class="glass-card h-100">
          <div class="offcanvas-body p-3">
            <?php include 'components/sidebar.php'; ?>
          </div>
        </div>
      </div>

      <!-- Map Area -->
      <div class="col-xl-8 col-lg-7">
        <div class="glass-card">
          <div class="p-3">
            <div id="selectedRouteName" class="alert alert-info d-flex align-items-center d-none mb-3">
              <i class="fas fa-route me-2"></i>
              <span id="routeNameText"></span>
              <button type="button" class="btn-close ms-auto" onclick="resetView()"></button>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
              <h2 class="h4 mb-0">
                <i class="fas fa-map-location me-2 text-primary"></i>
                Carte Interactive
              </h2>
              <button onclick="resetView()" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrows-rotate me-1"></i>
                <span class="d-none d-sm-inline">Réinitialiser</span>
              </button>
            </div>

            <div id="map" class="rounded shadow-sm" style="height: 65vh; min-height: 400px;"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Sidebar - Mobile -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebar">
    <div class="offcanvas-header border-bottom">
      <h5 class="offcanvas-title">
        <i class="fas fa-filter me-2"></i>
        Recherche & Filtres
      </h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
      <?php include 'components/sidebar.php'; ?>
    </div>
  </div>

  <!-- Footer -->
  <footer class="footer mt-auto py-3">
    <div class="container text-center text-muted">
      <small>© <?= date('Y') ?> Carte des Grands Taxis - Casablanca</small>
    </div>
  </footer>

  <!-- Scripts -->
  <!-- jQuery (assurez-vous que le chemin est correct) -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

  <!-- Bootstrap Bundle JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Leaflet JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>

  <script>
    // Passer les données PHP aux scripts JS
    const taxiLines = <?= json_encode($linesWithPoints, JSON_UNESCAPED_UNICODE) ?>;
    const groupedLines = <?= json_encode($groupedLines, JSON_UNESCAPED_UNICODE) ?>;
  </script>

  <!-- Script principal de la carte -->
  <script src="js/index.js"></script>
</body>

</html>