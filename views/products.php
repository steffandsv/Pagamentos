<?php
NCMRegistry::load('ncm.json');

$prodQuery = "SELECT 
    i.company_id,
    ii.ncm as code,
    SUM(ii.total_price) as total
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    WHERE 1=1 $whereGlobal AND i.status != 'Cancelada'
    GROUP BY ii.ncm
    ORDER BY total DESC";

$stmt = $pdo->prepare($prodQuery);
$stmt->execute();
$products = $stmt->fetchAll();

$totalProdRev = array_sum(array_column($products, 'total')) ?: 1;

$accum = 0;
foreach ($products as &$p) {
    $accum += $p['total'];
    $p['share'] = ($p['total'] / $totalProdRev) * 100;
    $p['accumulated_share'] = ($accum / $totalProdRev) * 100;
    $p['class'] = $p['accumulated_share'] <= 80 ? 'A' : ($p['accumulated_share'] <= 95 ? 'B' : 'C');
    
    $info = NCMRegistry::getInfo($p['code']);
    $p['name'] = $info['desc'];
}
?>

<div class="glass-card">
    <div class="kpi-label">Performance por Grupo NCM</div>
    <table id="tableProducts" class="display">
        <thead>
            <tr>
                <th>Class</th>
                <th>Código NCM</th>
                <th>Descrição</th>
                <th>Receita Total</th>
                <th>% Share</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($products as $p): ?>
            <tr>
                <td><span class="badge badge-<?= $p['class'] ?>"><?= $p['class'] ?></span></td>
                <td><?= $p['code'] ?></td>
                <td><?= $p['name'] ?></td>
                <td><?= formatMoney($p['total']) ?></td>
                <td><?= number_format($p['share'], 2) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    $(document).ready(function() {
        $('#tableProducts').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/pt-BR.json' },
            pageLength: 15,
            order: [[ 3, "desc" ]]
        });
    });
</script>
