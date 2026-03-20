<?php
// Fetch Invoices
$invQuery = "SELECT i.*, c.name as customer_name, c.doc, comp.name as company_name 
             FROM invoices i 
             JOIN customers c ON i.customer_id = c.id 
             JOIN companies comp ON i.company_id = comp.id
             WHERE 1=1 $whereCompany 
             ORDER BY i.issue_date DESC LIMIT 1000";
$stmt = $pdo->prepare($invQuery);
// if($selectedCompany !== 'all') $stmt->bindValue(':comp', $selectedCompany);
$stmt->execute();
$invoices = $stmt->fetchAll();
?>

<div class="glass-card">
    <table id="tableSales" class="display">
        <thead>
            <tr>
                <th>Pago?</th>
                <th>Data</th>
                <th>Empresa</th>
                <th>Cliente</th>
                <th>Valor</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($invoices as $inv): ?>
            <tr>
                <td style="text-align: center;">
                    <button class="btn-check <?= $inv['is_paid'] ? 'paid' : '' ?>" onclick="togglePaid(<?= $inv['id'] ?>, this)">
                        <i class="fa-solid fa-check"></i>
                    </button>
                </td>
                <td><?= formatDate($inv['issue_date']) ?></td>
                <td><?= substr($inv['company_name'], 0, 15) ?>...</td>
                <td>
                    <div style="font-weight: 600;"><?= substr($inv['customer_name'], 0, 30) ?></div>
                    <div style="font-size: 0.8rem; color: #999;"><?= $inv['doc'] ?></div>
                </td>
                <td style="font-weight: 700;"><?= formatMoney($inv['total_value']) ?></td>
                <td>
                    <span class="status-dot <?= $inv['status'] === 'Autorizada' ? 'dot-green' : 'dot-red' ?>"></span>
                    <select class="status-select" onchange="updateStatus(<?= $inv['id'] ?>, this.value)">
                        <option value="Autorizada" <?= $inv['status'] === 'Autorizada' ? 'selected' : '' ?>>Autorizada</option>
                        <option value="Cancelada" <?= $inv['status'] === 'Cancelada' ? 'selected' : '' ?>>Cancelada</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    $(document).ready(function() {
        $('#tableSales').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/pt-BR.json' },
            pageLength: 15,
            order: [[ 1, "desc" ]]
        });
    });
</script>
