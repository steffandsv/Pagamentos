<?php
// Fetch Customers for Pareto
$custQuery = "SELECT c.name, c.doc, SUM(i.total_value) as total, MAX(i.issue_date) as last_buy
              FROM invoices i JOIN customers c ON i.customer_id = c.id 
              WHERE 1=1 $whereCompany AND i.status != 'Cancelada' 
              GROUP BY c.id 
              ORDER BY total DESC";
$stmt = $pdo->prepare($custQuery);
// if($selectedCompany !== 'all') $stmt->bindValue(':comp', $selectedCompany);
$stmt->execute();
$customers = $stmt->fetchAll();

// Calculate Pareto
$totalRev = array_sum(array_column($customers, 'total')) ?: 1;
$accum = 0;
foreach ($customers as &$c) {
    $accum += $c['total'];
    $share = ($c['total'] / $totalRev) * 100;
    $accumShare = ($accum / $totalRev) * 100;
    
    $c['share'] = $share;
    $c['accumulated_share'] = $accumShare;
    $c['class'] = $accumShare <= 80 ? 'A' : ($accumShare <= 95 ? 'B' : 'C');
}
?>

<div class="grid">
    <div class="glass-card col-12">
        <div class="kpi-label">Análise de Pareto (Curva ABC)</div>
        <p style="color: var(--text-secondary); margin-bottom: 20px;">
            <span class="badge badge-A">Classe A</span> 80% do faturamento.
            <span class="badge badge-B">Classe B</span> Próximos 15%.
            <span class="badge badge-C">Classe C</span> Últimos 5%.
        </p>
        <table id="tableCustomers" class="display">
            <thead>
                <tr>
                    <th>Class</th>
                    <th>Cliente</th>
                    <th>Documento</th>
                    <th>Total Comprado</th>
                    <th>% Share</th>
                    <th>% Acumulado</th>
                    <th>Última Compra</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($customers as $c): ?>
                <tr>
                    <td><span class="badge badge-<?= $c['class'] ?>"><?= $c['class'] ?></span></td>
                    <td><?= $c['name'] ?></td>
                    <td><?= $c['doc'] ?></td>
                    <td><?= formatMoney($c['total']) ?></td>
                    <td><?= number_format($c['share'], 2) ?>%</td>
                    <td><?= number_format($c['accumulated_share'], 2) ?>%</td>
                    <td><?= formatDate($c['last_buy']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('#tableCustomers').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/pt-BR.json' },
            pageLength: 10,
            order: [[ 3, "desc" ]]
        });
    });
</script>

<?php
require_once 'includes/intelligence.php';
$churnData = Intelligence::getChurnRisk($pdo, $whereGlobal, 90); // 90 days threshold
?>

<div class="grid" style="margin-top: 40px;">
    <div class="glass-card col-12">
        <div class="kpi-label" style="color: var(--danger);">Risco de Churn (Clientes Inativos > 90 dias)</div>
        <p style="color: var(--text-secondary); margin-bottom: 20px;">
            Clientes valiosos que não compram há mais de 3 meses. Ação recomendada: Contato imediato.
        </p>
        <table id="tableChurn" class="display">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Cidade/UF</th>
                    <th>Última Compra</th>
                    <th>Dias Inativo</th>
                    <th>Valor Total (LTV)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($churnData as $c): 
                    $days = floor((time() - strtotime($c['last_buy'])) / (60 * 60 * 24));
                ?>
                <tr>
                    <td style="font-weight: 600;"><?= $c['name'] ?></td>
                    <td><?= $c['city'] ?>/<?= $c['uf'] ?></td>
                    <td><?= formatDate($c['last_buy']) ?></td>
                    <td><span class="badge badge-C"><?= $days ?> dias</span></td>
                    <td><?= formatMoney($c['lifetime_value']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('#tableChurn').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/pt-BR.json' },
            pageLength: 5,
            order: [[ 4, "desc" ]] // Order by LTV
        });
    });
</script>
