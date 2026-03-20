<?php
require_once 'includes/intelligence.php';

$elasticityData = Intelligence::getElasticityData($pdo, $whereGlobal);
?>

<div class="grid">
    <div class="glass-card col-12">
        <div class="kpi-label">Inteligência de Preços (Top 50 NCMs)</div>
        <p style="color: var(--text-secondary); margin-bottom: 20px;">
            Analise a variação de preços dos seus produtos. 
            <span class="badge badge-C">Alta Variação (>50%)</span> indica inconsistência ou oportunidade de padronização.
        </p>
        <table id="tableIntelligence" class="display">
            <thead>
                <tr>
                    <th>NCM</th>
                    <th>Descrição</th>
                    <th>Preço Mín</th>
                    <th>Preço Méd</th>
                    <th>Preço Máx</th>
                    <th>Variação (CV)</th>
                    <th>Amplitude</th>
                    <th>Receita Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($elasticityData as $row): ?>
                <tr>
                    <td><?= $row['code'] ?></td>
                    <td><?= $row['name'] ?></td>
                    <td><?= formatMoney($row['min_price']) ?></td>
                    <td><?= formatMoney($row['avg_price']) ?></td>
                    <td><?= formatMoney($row['max_price']) ?></td>
                    <td>
                        <?php 
                            $cv = $row['variation'];
                            $badge = $cv < 20 ? 'A' : ($cv < 50 ? 'B' : 'C');
                        ?>
                        <span class="badge badge-<?= $badge ?>"><?= number_format($cv, 1) ?>%</span>
                    </td>
                    <td><?= number_format($row['elasticity_proxy'], 1) ?>%</td>
                    <td><?= formatMoney($row['total_revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid">
    <div class="glass-card col-12">
        <div class="kpi-label">Radar de Volatilidade de Preço (Top 10)</div>
        <canvas id="radarElasticity" height="100"></canvas>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('#tableIntelligence').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/pt-BR.json' },
            pageLength: 15,
            order: [[ 7, "desc" ]]
        });
    });

    // Radar Chart
    const top10 = <?= json_encode(array_slice($elasticityData, 0, 10)) ?>;
    const ctxR = document.getElementById('radarElasticity').getContext('2d');
    new Chart(ctxR, {
        type: 'bar',
        data: {
            labels: top10.map(i => i.name.substring(0, 15)+'...'),
            datasets: [{
                label: 'Variação de Preço (%)',
                data: top10.map(i => i.variation),
                backgroundColor: 'rgba(56, 189, 248, 0.5)',
                borderColor: '#38bdf8',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true } }
        }
    });
</script>
