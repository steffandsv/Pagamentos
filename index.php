<?php
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/ncm.php';
require_once 'includes/geo.php';

// --- AJAX HANDLERS ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'toggle_paid' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE invoices SET is_paid = NOT is_paid WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        $stmt = $pdo->prepare("SELECT is_paid FROM invoices WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true, 'is_paid' => $stmt->fetchColumn()]);
        exit;
    }

    if ($_GET['action'] === 'update_status' && isset($_POST['id']) && isset($_POST['status'])) {
        $stmt = $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?");
        $stmt->execute([$_POST['status'], $_POST['id']]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// --- GLOBAL DATA ---
// Companies List (for dropdown)
$companies = $pdo->query("SELECT * FROM companies")->fetchAll();
$selectedCompany = $_GET['company'] ?? 'all';
$selectedStatus = $_GET['status'] ?? 'all';

// "Apenas autorizadas" filter logic
// Default to true if first visit (no execution of filter) OR if checkbox is checked
$isFilterSubmitted = isset($_GET['filter_submitted']);
$authorizedOnly = false;
if (!$isFilterSubmitted) {
    $authorizedOnly = true; // Default On
} else {
    $authorizedOnly = isset($_GET['authorized_only']);
}

$whereGlobal = "";
if ($selectedCompany !== 'all') {
    $whereGlobal .= " AND i.company_id = " . $pdo->quote($selectedCompany);
}
if ($selectedStatus === 'pending') {
    $whereGlobal .= " AND i.is_paid = 0";
}
if ($authorizedOnly) {
    $whereGlobal .= " AND i.status != 'Cancelada'";
}

// Backwards compatibility for views using $whereCompany (now $whereGlobal)
$whereCompany = $whereGlobal; 

// --- ROUTING ---
$page = $_GET['page'] ?? 'dashboard';
// Allow alphanumeric and underscores only for security
$page = preg_replace('/[^a-z0-9_]/', '', $page);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genius Financial Vision Pro</title>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="sidebar">
    <div class="logo"><i class="fa-solid fa-bolt"></i> <span>Genius</span></div>
    <a href="?page=dashboard" class="nav-item <?= $page=='dashboard' ? 'active' : '' ?>">
        <i class="fa-solid fa-chart-pie"></i> <span>Dashboard</span>
    </a>
    <a href="?page=sales" class="nav-item <?= $page=='sales' ? 'active' : '' ?>">
        <i class="fa-solid fa-table-list"></i> <span>Vendas</span>
    </a>
    <a href="?page=customers" class="nav-item <?= $page=='customers' ? 'active' : '' ?>">
        <i class="fa-solid fa-users"></i> <span>Clientes & Churn</span>
    </a>
    <a href="?page=geo_opps" class="nav-item <?= $page=='geo_opps' ? 'active' : '' ?>">
        <i class="fa-solid fa-map-location-dot"></i> <span>Mapa de Clientes</span>
    </a>
    <a href="?page=products" class="nav-item <?= $page=='products' ? 'active' : '' ?>">
        <i class="fa-solid fa-boxes-stacked"></i> <span>Produtos (NCM)</span>
    </a>
    <a href="?page=intelligence" class="nav-item <?= $page=='intelligence' ? 'active' : '' ?>">
        <i class="fa-solid fa-brain"></i> <span>Inteligência de Preço</span>
    </a>
    <a href="?page=upload" class="nav-item <?= $page=='upload' ? 'active' : '' ?>">
        <i class="fa-solid fa-cloud-arrow-up"></i> <span>Importar XML</span>
    </a>
</div>

<div class="main">
    
    <!-- Header Global -->
    <div class="top-bar">
        <div class="page-title">
            <?php 
                if($page == 'dashboard') echo 'Visão Geral';
                elseif($page == 'sales') echo 'Todas as Vendas';
                elseif($page == 'customers') echo 'Análise de Clientes & Churn';
                elseif($page == 'products') echo 'Produtos';
                elseif($page == 'intelligence') echo 'Inteligência de Preços';
                elseif($page == 'geo_opps') echo 'Oportunidades Geográficas';
                elseif($page == 'upload') echo 'Importação de Dados';
                else echo ucfirst($page);
            ?>
        </div>
        <form method="get" style="display: flex; gap: 10px; align-items: center;">
            <input type="hidden" name="page" value="<?= $page ?>">
            <input type="hidden" name="filter_submitted" value="1">
            
            <label style="display: flex; align-items: center; gap: 5px; font-size: 0.9rem; margin-right: 10px; cursor: pointer;">
                <input type="checkbox" name="authorized_only" value="1" <?= $authorizedOnly ? 'checked' : '' ?> onchange="this.form.submit()">
                Apenas autorizadas
            </label>
            
            <select name="status" class="company-select" onchange="this.form.submit()">
                <option value="all" <?= $selectedStatus == 'all' ? 'selected' : '' ?>>Todos os Status</option>
                <option value="pending" <?= $selectedStatus == 'pending' ? 'selected' : '' ?>>Apenas Pendentes (A Receber)</option>
            </select>

            <select name="company" class="company-select" onchange="this.form.submit()">
                <option value="all">Todas as Empresas</option>
                <?php foreach($companies as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $selectedCompany == $c['id'] ? 'selected' : '' ?>>
                    <?= $c['name'] ?> (<?= $c['cnpj'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Dynamic Content -->
    <?php 
        $viewPath = "views/$page.php";
        if (file_exists($viewPath)) {
            include $viewPath;
        } else {
            echo "<div class='glass-card'>Página não encontrada.</div>";
        }
    ?>

</div>

<script>
    // Global Scripts
    function togglePaid(id, btn) {
        $.post('?action=toggle_paid', {id: id}, function(res) {
            if(res.success) {
                if(res.is_paid) $(btn).addClass('paid');
                else $(btn).removeClass('paid');
            }
        });
    }

    function updateStatus(id, newStatus) {
        $.post('?action=update_status', {id: id, status: newStatus}, function(res) {
            if(res.success) {
                // Optional: visual feedback
                console.log('Status updated');
            } else {
                alert('Erro ao atualizar status');
            }
        });
    }
</script>
</body>
</html>