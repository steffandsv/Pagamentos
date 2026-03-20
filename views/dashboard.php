<?php
// 1. KPIs
$kpiQuery = "SELECT 
    SUM(total_value) as total,
    COUNT(*) as count,
    AVG(total_value) as ticket,
    SUM(CASE WHEN is_paid = 0 THEN total_value ELSE 0 END) as pending,
    SUM(CASE WHEN is_paid = 1 THEN total_value ELSE 0 END) as paid
    FROM invoices i WHERE 1=1 $whereCompany AND i.status != 'Cancelada'";
$stmt = $pdo->prepare($kpiQuery);
// if($selectedCompany !== 'all') $stmt->bindValue(':comp', $selectedCompany);
$stmt->execute();
$kpi = $stmt->fetch();

// 2. Timeline
$timeQuery = "SELECT issue_date, SUM(total_value) as val FROM invoices i WHERE 1=1 $whereCompany AND i.status != 'Cancelada' GROUP BY issue_date ORDER BY issue_date";
$stmt = $pdo->prepare($timeQuery);
// if($selectedCompany !== 'all') $stmt->bindValue(':comp', $selectedCompany);
$stmt->execute();
$timeline = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Top Customers
$custQuery = "SELECT c.name, SUM(i.total_value) as total 
              FROM invoices i JOIN customers c ON i.customer_id = c.id 
              WHERE 1=1 $whereCompany AND i.status != 'Cancelada' 
              GROUP BY c.id 
              ORDER BY total DESC LIMIT 5";
$stmt = $pdo->prepare($custQuery);
// if($selectedCompany !== 'all') $stmt->bindValue(':comp', $selectedCompany);
$stmt->execute();
$topCustomers = $stmt->fetchAll();

// 4. Map Data
$geo = new GeoIntelligence();
$mapData = $geo->getMapDataFromDB($pdo, $whereGlobal);
?>

<div class="grid">
    <div class="glass-card col-3">
        <div class="kpi-label">Receita Total</div>
        <div class="kpi-val"><?= formatMoney($kpi['total']) ?></div>
        <div class="kpi-trend"><?= $kpi['count'] ?> notas emitidas</div>
    </div>
    <div class="glass-card col-3">
        <div class="kpi-label">A Receber (Pendente)</div>
        <div class="kpi-val" style="color: var(--danger);"><?= formatMoney($kpi['pending']) ?></div>
        <div class="kpi-trend trend-down">Falta receber</div>
    </div>
    <div class="glass-card col-3">
        <div class="kpi-label">Em Caixa (Pago)</div>
        <div class="kpi-val" style="color: var(--success);"><?= formatMoney($kpi['paid']) ?></div>
        <div class="kpi-trend trend-up">Confirmado</div>
    </div>
    <div class="glass-card col-3">
        <div class="kpi-label">Ticket Médio</div>
        <div class="kpi-val"><?= formatMoney($kpi['ticket']) ?></div>
    </div>
</div>

<div class="grid">
    <div class="glass-card col-8">
        <div class="kpi-label">Fluxo de Caixa (Emissões)</div>
        <canvas id="chartTimeline" height="100"></canvas>
    </div>
    <div class="glass-card col-4">
        <div class="kpi-label">Top 5 Clientes</div>
        <canvas id="chartTopCustomers" height="200"></canvas>
    </div>
</div>

<div class="grid">
    <div class="glass-card col-12">
        <div class="kpi-label">Mapa de Calor Geográfico</div>
        <div id="map"></div>
    </div>
</div>

<script>
    // Timeline Chart
    const ctx = document.getElementById('chartTimeline').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_keys($timeline)) ?>,
            datasets: [{
                label: 'Faturamento',
                data: <?= json_encode(array_values($timeline)) ?>,
                borderColor: '#007aff',
                backgroundColor: 'rgba(0, 122, 255, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { grid: { borderDash: [5, 5] } }
            }
        }
    });

    // Top Customers Chart
    const topCust = <?= json_encode($topCustomers) ?>;
    new Chart(document.getElementById('chartTopCustomers'), {
        type: 'doughnut',
        data: {
            labels: topCust.map(c => c.name.substring(0, 15)+'...'),
            datasets: [{
                data: topCust.map(c => c.total),
                backgroundColor: ['#38bdf8', '#818cf8', '#c084fc', '#f472b6', '#fb7185'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'right', labels: { boxWidth: 10 } } },
            cutout: '70%'
        }
    });

    // Map
    try {
        var map = L.map('map').setView([-15, -55], 4);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap &copy; CARTO',
            subdomains: 'abcd',
            maxZoom: 19
        }).addTo(map);

        const mapData = <?= json_encode($mapData) ?>;
        const markers = [];
        
        Object.values(mapData).forEach(city => {
            if(city.lat && city.lon) {
                L.circleMarker([city.lat, city.lon], {
                    radius: Math.min(Math.max(5, Math.sqrt(city.val)/100), 25),
                    fillColor: "#007aff",
                    color: "#fff",
                    weight: 1,
                    opacity: 1,
                    fillOpacity: 0.7
                }).addTo(map).bindPopup(`<b>${city.city}</b><br>R$ ${parseFloat(city.val).toLocaleString('pt-BR', {minimumFractionDigits: 2})}`);
                markers.push([city.lat, city.lon]);
            }
        });
        
        if(markers.length > 0) map.fitBounds(markers);
    } catch(e) { console.error("Map Error", e); }
</script>
