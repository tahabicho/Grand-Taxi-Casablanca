<?php
session_start();

// Connexion à la base de données
try {
  $pdo = new PDO("mysql:host=localhost;dbname=taxi_casablanca;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (PDOException $e) {
  die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// Vérifier si l'utilisateur est connecté
$login_required = !isset($_SESSION['user']);
$message = "";
$message_type = "info";
$editId = $_GET['edit'] ?? null;
$deleteId = $_GET['delete'] ?? null;

// 🔐 LOGIN
if (isset($_POST['login_email']) && isset($_POST['login_password'])) {
  $email = filter_var($_POST['login_email'], FILTER_SANITIZE_EMAIL);
  $password = $_POST['login_password'];

  try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
      if ($user['status'] === 'validé') {
        $_SESSION['user'] = $user['email'];
        header("Location: index.php"); // Redirige vers la même page après login
        exit;
      } else {
        $message = "Compte non validé. Veuillez contacter l'administrateur.";
        $message_type = "warning";
      }
    } else {
      $message = "Identifiants incorrects. Veuillez réessayer.";
      $message_type = "danger";
    }
  } catch (Exception $e) {
    $message = "Erreur de connexion : " . $e->getMessage();
    $message_type = "danger";
  }
}

// 📝 REGISTER - Optionnel dans l'admin, mais conservé si nécessaire
if (isset($_POST['register_email']) && isset($_POST['register_password'])) {
  $email = filter_var($_POST['register_email'], FILTER_SANITIZE_EMAIL);
  $password = password_hash($_POST['register_password'], PASSWORD_DEFAULT);

  try {
    $pdo->prepare("INSERT INTO users (email, password) VALUES (?, ?)")->execute([$email, $password]);
    $message = "Inscription réussie. En attente de validation par l'administrateur.";
    $message_type = "success";
  } catch (PDOException $e) {
    if ($e->getCode() == 23000) { // Code d'erreur pour duplication
      $message = "Cet email est déjà utilisé.";
    } else {
      $message = "Erreur d'inscription : " . $e->getMessage();
    }
    $message_type = "danger";
  }
}

// 🗑️ SUPPRESSION D'UNE LIGNE
if ($deleteId) {
  try {
    // Utiliser une transaction pour s'assurer que tout est supprimé correctement
    $pdo->beginTransaction();

    // Supprimer d'abord les points de trajet associés
    $pdo->prepare("DELETE FROM trajets WHERE line_id = ?")->execute([$deleteId]);

    // Ensuite, supprimer la ligne elle-même
    $pdo->prepare("DELETE FROM taxi_line WHERE id = ?")->execute([$deleteId]);

    $pdo->commit();

    $message = "Ligne supprimée avec succès.";
    $message_type = "success";
  } catch (Exception $e) {
    // En cas d'erreur, annuler les modifications
    $pdo->rollBack();
    $message = "Erreur lors de la suppression : " . $e->getMessage();
    $message_type = "danger";
  }
  // Rediriger avec le message
  header("Location: index.php?message=" . urlencode($message) . "&type=" . $message_type);
  exit;
}

// 🚖 ENREGISTREMENT / MODIFICATION DE LIGNE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
  $name = trim($_POST['name'] ?? '');
  $color = trim($_POST['color'] ?? '#3b82f6'); // Couleur par défaut
  $coordsJson = $_POST['coords'] ?? '[]';
  $coords = json_decode($coordsJson, true);

  // Debug: Afficher les coordonnées reçues
  // error_log("Coords reçues: " . print_r($coords, true));

  // Validation des données
  if (!$name) {
    $message = "Veuillez remplir le nom de la ligne.";
    $message_type = "warning";
  } else if (!is_array($coords) || count($coords) < 2) {
    $message = "Veuillez générer un itinéraire avec au moins 2 points.";
    $message_type = "warning";
  } else {
    try {
      $pdo->beginTransaction();

      if ($editId) {
        // Mode Modification
        $pdo->prepare("UPDATE taxi_line SET name = ?, color = ? WHERE id = ?")->execute([$name, $color, $editId]);

        // Supprimer les anciens points de trajet
        $pdo->prepare("DELETE FROM trajets WHERE line_id = ?")->execute([$editId]);

        $line_id = $editId;
        $message = "Ligne modifiée avec succès.";
      } else {
        // Mode Création
        $pdo->prepare("INSERT INTO taxi_line (name, color) VALUES (?, ?)")->execute([$name, $color]);
        $line_id = $pdo->lastInsertId();
        $message = "Nouvelle ligne enregistrée avec succès.";
      }

      // Insérer les nouveaux points de trajet
      // IMPORTANT: $coords est un tableau de [latitude, longitude]
      $stmt = $pdo->prepare("INSERT INTO trajets (line_id, latitude, longitude, ordre) VALUES (?, ?, ?, ?)");
      foreach ($coords as $i => $pt) {
        // $pt[0] est la latitude, $pt[1] est la longitude
        if (isset($pt[0]) && isset($pt[1])) {
          $stmt->execute([$line_id, $pt[0], $pt[1], $i]);
        } else {
          error_log("Point invalide ignoré: " . print_r($pt, true));
        }
      }

      $pdo->commit();
      $message_type = "success";

      // Rediriger après succès
      header("Location: index.php?message=" . urlencode($message) . "&type=" . $message_type);
      exit;
    } catch (Exception $e) {
      $pdo->rollBack();
      error_log("Erreur DB: " . $e->getMessage()); // Log l'erreur
      $message = "Erreur lors de l'enregistrement : " . $e->getMessage();
      $message_type = "danger";
    }
  }
}

// 🔄 RÉCUPÉRATION DES LIGNES EXISTANTES POUR L'AFFICHAGE
try {
  $lines = $pdo->query("SELECT * FROM taxi_line ORDER BY id DESC")->fetchAll();
  // S'assurer que $lines est toujours un tableau
  if (!is_array($lines)) {
    $lines = [];
  }
} catch (Exception $e) {
  $lines = [];
  $message = "Erreur lors du chargement des lignes : " . $e->getMessage();
  $message_type = "danger";
}

// 🔄 RÉCUPÉRATION DES DONNÉES POUR L'ÉDITION
$current = null;
$points = []; // Pour passer les points existants au JS [[lat, lng], ...]

if ($editId) {
  try {
    // Récupérer les données de la ligne à modifier
    $stmt = $pdo->prepare("SELECT * FROM taxi_line WHERE id = ?");
    $stmt->execute([$editId]);
    $current = $stmt->fetch();

    if ($current) {
      // Récupérer les points de trajet associés
      $stmt = $pdo->prepare("SELECT latitude, longitude FROM trajets WHERE line_id = ? ORDER BY ordre ASC");
      $stmt->execute([$editId]);
      // Récupérer sous forme de tableau numéroté [lat, lng]
      $points = $stmt->fetchAll(PDO::FETCH_NUM); // [[lat1, lng1], [lat2, lng2], ...]

      // Debug: Afficher les points récupérés
      // error_log("Points récupérés pour édition: " . print_r($points, true));

      // S'assurer que $points est toujours un tableau
      if (!is_array($points)) {
        $points = [];
      }
    } else {
      // Si la ligne n'existe pas, annuler le mode édition
      $editId = null;
    }
  } catch (Exception $e) {
    $message = "Erreur lors du chargement de la ligne : " . $e->getMessage();
    $message_type = "danger";
  }
}

// Message depuis les paramètres URL (après redirection)
if (isset($_GET['message'])) {
  $message = $_GET['message'];
  $message_type = $_GET['type'] ?? 'info';
}
?>
<!DOCTYPE html>
<html lang="fr" data-bs-theme="dark">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Gestion des lignes de taxi - Administration">
  <title>Gestion des Lignes Taxi - Dashboard</title>

  <!-- Bootstrap CSS - CORRIGE: espaces supprimés -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Font Awesome - CORRIGE: espaces supprimés -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" rel="stylesheet">

  <!-- Leaflet CSS - CORRIGE: espaces supprimés -->
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
            <span class="d-none d-sm-inline">Gestion des Lignes Taxi</span>
            <span class="d-sm-none">Gestion Taxi</span>
          </h1>
          <p class="mb-0 text-muted d-none d-md-block">Administration des trajets</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <?php if (!isset($_SESSION['user'])): ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#authModal">
              <i class="fas fa-user me-1"></i>
              <span class="d-none d-sm-inline">Connexion</span>
            </button>
          <?php else: ?>
            <div class="d-flex align-items-center bg-dark rounded-pill px-3 py-2">
              <i class="fas fa-user-check text-success me-2"></i>
              <span class="small d-none d-md-inline"><?= htmlspecialchars($_SESSION['user']) ?></span>
            </div>
            <a href="../logout.php" class="btn btn-sm btn-outline-danger">
              <i class="fas fa-sign-out-alt me-1"></i>
              <span class="d-none d-sm-inline">Déconnexion</span>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <div class="container mt-4">
    <!-- Messages d'alerte -->
    <?php if ($message): ?>
      <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?php
                          switch ($message_type) {
                            case 'success':
                              echo 'check-circle';
                              break;
                            case 'warning':
                              echo 'exclamation-triangle';
                              break;
                            case 'danger':
                              echo 'exclamation-circle';
                              break;
                            default:
                              echo 'info-circle';
                          }
                          ?> me-2"></i>
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Fenêtre modale d'authentification -->
    <div class="modal fade" id="authModal" tabindex="-1" aria-labelledby="authModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title w-100 text-center" id="authModalLabel">
              <i class="fas fa-lock me-2"></i>
              Authentification
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <ul class="nav nav-tabs nav-justified" id="authTab" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button" role="tab">Connexion</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button" role="tab">Inscription</button>
              </li>
            </ul>
            <div class="tab-content mt-4" id="authTabContent">
              <!-- Onglet Login -->
              <div class="tab-pane fade show active" id="login" role="tabpanel" aria-labelledby="login-tab">
                <form method="POST">
                  <div class="mb-3">
                    <div class="input-group">
                      <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                      <input name="login_email" class="form-control" type="email" placeholder="Adresse email" required>
                    </div>
                  </div>
                  <div class="mb-3">
                    <div class="input-group">
                      <span class="input-group-text"><i class="fas fa-lock"></i></span>
                      <input name="login_password" class="form-control" type="password" placeholder="Mot de passe" required>
                    </div>
                  </div>
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-sign-in-alt me-2"></i>Se connecter
                  </button>
                </form>
              </div>
              <!-- Onglet Register -->
              <div class="tab-pane fade" id="register" role="tabpanel" aria-labelledby="register-tab">
                <form method="POST">
                  <div class="mb-3">
                    <div class="input-group">
                      <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                      <input name="register_email" class="form-control" type="email" placeholder="Adresse email" required>
                    </div>
                  </div>
                  <div class="mb-3">
                    <div class="input-group">
                      <span class="input-group-text"><i class="fas fa-lock"></i></span>
                      <input name="register_password" class="form-control" type="password" placeholder="Mot de passe" required>
                    </div>
                  </div>
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-user-plus me-2"></i>Créer le compte
                  </button>
                  <div class="mt-3">
                    <small class="text-warning">
                      <i class="fas fa-exclamation-triangle me-1"></i>
                      Accès accordé uniquement après validation par l'administrateur
                    </small>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Contenu principal -->
    <?php if (!isset($_SESSION['user'])): ?>
      <!-- Page d'accueil si non connecté -->
      <div class="glass-card text-center py-5">
        <i class="fas fa-lock fa-3x text-primary mb-3"></i>
        <h3 class="mb-3">Accès restreint</h3>
        <p class="text-muted mb-4">Veuillez vous connecter pour accéder à la gestion des lignes taxi</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#authModal">
          <i class="fas fa-sign-in-alt me-2"></i>Se connecter
        </button>
      </div>
    <?php else: ?>
      <!-- Formulaire d'ajout/modification de ligne -->
      <div class="glass-card mb-4">
        <div class="card-body p-4">
          <h3 class="mb-4">
            <i class="fas fa-route me-2 text-primary"></i>
            <?= $editId ? "Modifier la ligne" : "Ajouter une nouvelle ligne" ?>
          </h3>

          <form method="POST">
            <div class="row g-3 mb-4">
              <div class="col-md-7">
                <label class="form-label">Nom de la ligne</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-tag"></i></span>
                  <input name="name" class="form-control" required value="<?= htmlspecialchars($current['name'] ?? '') ?>" placeholder="Ex: Centre ville - Aéroport">
                </div>
              </div>
              <div class="col-md-3">
                <label class="form-label">Couleur</label>
                <div class="input-group input-group-color">
                  <input name="color" type="color" class="form-control-color" value="<?= $current['color'] ?? '#3b82f6' ?>" title="Choisir une couleur">
                </div>
              </div>
              <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="button" id="gen" class="btn btn-success w-100">
                  <i class="fas fa-magic me-1"></i>
                  <span class="d-none d-sm-inline">Générer</span>
                </button>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label">Carte d'itinéraire</label>
              <div id="map" class="rounded shadow-sm" style="height: 500px;"></div>
            </div>

            <!-- Champs cachés pour les données du formulaire -->
            <input type="hidden" name="coords" id="coords">

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-2"></i>
                <?= $editId ? "Enregistrer les modifications" : "Ajouter la ligne" ?>
              </button>
              <?php if ($editId): ?>
                <a href="index.php" class="btn btn-outline-secondary">
                  <i class="fas fa-times me-2"></i>Annuler
                </a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <!-- Liste des lignes existantes -->
      <div class="glass-card">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="mb-0">
              <i class="fas fa-list me-2 text-primary"></i>
              Lignes existantes
            </h3>
            <span class="badge bg-primary"><?= is_array($lines) ? count($lines) : 0 ?> ligne<?= (is_array($lines) ? count($lines) : 0) != 1 ? 's' : '' ?></span>
          </div>

          <?php if (empty($lines) || !is_array($lines)): ?>
            <div class="text-center py-5">
              <i class="fas fa-route fa-2x text-muted mb-3"></i>
              <p class="text-muted">Aucune ligne enregistrée pour le moment</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th>Nom</th>
                    <th>Couleur</th>
                    <th>Points</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($lines as $line): ?>
                    <tr>
                      <td>
                        <strong><?= htmlspecialchars($line['name']) ?></strong>
                      </td>
                      <td>
                        <span class="badge" style="background-color: <?= $line['color'] ?>;">
                          <?= $line['color'] ?>
                        </span>
                      </td>
                      <td>
                        <?php
                        try {
                          $stmt = $pdo->prepare("SELECT COUNT(*) FROM trajets WHERE line_id = ?");
                          $stmt->execute([$line['id']]);
                          $pointCount = $stmt->fetchColumn();
                          echo $pointCount . " point" . ($pointCount > 1 ? 's' : '');
                        } catch (Exception $e) {
                          echo "Erreur";
                        }
                        ?>
                      </td>
                      <td class="text-end">
                        <div class="btn-group" role="group">
                          <a href="index.php?edit=<?= $line['id'] ?>" class="btn btn-sm btn-warning">
                            <i class="fas fa-edit"></i>
                            <span class="d-none d-md-inline">Modifier</span>
                          </a>
                          <a href="index.php?delete=<?= $line['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette ligne ? Cette action est irréversible.')">
                            <i class="fas fa-trash"></i>
                            <span class="d-none d-md-inline">Supprimer</span>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Footer -->
  <footer class="footer mt-auto py-3">
    <div class="container text-center text-muted">
      <small>© <?= date('Y') ?> Gestion des Lignes Taxi - Casablanca</small>
    </div>
  </footer>

  <!-- Scripts -->
  <!-- jQuery - CORRIGE: espaces supprimés -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

  <!-- Bootstrap Bundle JS - CORRIGE: espaces supprimés -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Leaflet JS - CORRIGE: espaces supprimés -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>

  <script>
    // Passer les points existants au script JS pour l'édition
    // Format: [[latitude, longitude], ...]
    const existingPoints = <?= json_encode($points ?? []) ?>;
    // Debug: Afficher les points passés à JS
    // console.log("existingPoints passés à JS:", existingPoints);
  </script>

  <!-- Script principal de gestion de la carte -->
  <script src="js/index.js"></script>
</body>

</html>