<?php
$uploadMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['f1'])) {
    set_time_limit(600);
    NCMRegistry::load('includes/ncm.json'); // Adjust path if needed, or absolute
    // Actually, NCMRegistry is in includes/ncm.php, but it loads a JSON file. 
    // We need to make sure ncm.json is accessible. It's in the root.
    NCMRegistry::load('ncm.json');
    
    $zE = new ZipArchive;
    if ($zE->open($_FILES['f1']['tmp_name']) === TRUE) {
        $count = 0;
        $pdo->beginTransaction();
        
        try {
            for ($i=0; $i<$zE->numFiles; $i++) {
                $raw = $zE->getFromIndex($i);
                if(!$raw) continue;
                $xml = simplexml_load_string($raw);
                if ($xml->getName() == 'procEventoNFe') {
                    $ev = $xml->evento;
                    
                    // Determine where cStat is located
                    $cStat = '';
                    if (isset($xml->retEvento)) {
                        $cStat = (string)$xml->retEvento->infEvento->cStat;
                    } elseif (isset($xml->nfeResultMsg->retEnvEvento->retEvento)) {
                        $cStat = (string)$xml->nfeResultMsg->retEnvEvento->retEvento->infEvento->cStat;
                    } elseif (isset($xml->nfeResultMsg->retEnvEvento)) {
                         // Sometimes it might be here directly? Unlikely but safe to check if structure varies
                         $cStat = (string)$xml->nfeResultMsg->retEnvEvento->cStat;
                    }

                    if ((string)$ev->infEvento->tpEvento == '110111' && $cStat == '135') {
                        // Cancellation Approved
                        $ch = (string)$ev->infEvento->chNFe;
                        $stmt = $pdo->prepare("UPDATE invoices SET status = 'Cancelada' WHERE access_key = ?");
                        $stmt->execute([$ch]);
                        if ($stmt->rowCount() > 0) $count++;
                    }
                    continue;
                }

                if (!$xml || $xml->getName() !== 'nfeProc') continue;
                
                $inf = $xml->NFe->infNFe;
                $ch = (string)$xml->protNFe->infProt->chNFe;
                
                // 1. Company
                $emitCNPJ = (string)$inf->emit->CNPJ;
                $emitName = (string)$inf->emit->xNome;
                
                $stmt = $pdo->prepare("SELECT id FROM companies WHERE cnpj = ?");
                $stmt->execute([$emitCNPJ]);
                $companyId = $stmt->fetchColumn();
                
                if (!$companyId) {
                    $stmt = $pdo->prepare("INSERT INTO companies (cnpj, name) VALUES (?, ?)");
                    $stmt->execute([$emitCNPJ, $emitName]);
                    $companyId = $pdo->lastInsertId();
                }

                // 2. Customer
                $destDoc = (string)($inf->dest->CNPJ ?? $inf->dest->CPF ?? 'N/A');
                $destName = (string)$inf->dest->xNome;
                $destCity = (string)$inf->dest->enderDest->xMun; // Name of city
                $destUF = (string)$inf->dest->enderDest->UF;

                $stmt = $pdo->prepare("SELECT id FROM customers WHERE doc = ?");
                $stmt->execute([$destDoc]);
                $customerId = $stmt->fetchColumn();

                if (!$customerId) {
                    $stmt = $pdo->prepare("INSERT INTO customers (doc, name, city, uf, first_buy, last_buy) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$destDoc, $destName, $destCity, $destUF, substr((string)$inf->ide->dhEmi, 0, 10), substr((string)$inf->ide->dhEmi, 0, 10)]);
                    $customerId = $pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare("UPDATE customers SET last_buy = ? WHERE id = ? AND last_buy < ?");
                    $date = substr((string)$inf->ide->dhEmi, 0, 10);
                    $stmt->execute([$date, $customerId, $date]);
                }

                // 3. Invoice
                $numNF = (string)$inf->ide->nNF;
                $issueDate = substr((string)$inf->ide->dhEmi, 0, 10);
                $valNF = (float)$inf->total->ICMSTot->vNF;

                $stmt = $pdo->prepare("INSERT IGNORE INTO invoices (company_id, customer_id, access_key, number, issue_date, total_value) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$companyId, $customerId, $ch, $numNF, $issueDate, $valNF]);
                $invoiceId = $pdo->lastInsertId();

                if ($invoiceId) { 
                    $count++;
                    // 4. Items
                    foreach ($inf->det as $d) {
                        $ncm = (string)$d->prod->NCM;
                        $desc = (string)$d->prod->xProd;
                        $qty = (float)$d->prod->qCom;
                        $vUn = (float)$d->prod->vUnCom;
                        $vTot = (float)$d->prod->vProd;

                        $stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, ncm, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$invoiceId, $ncm, $desc, $qty, $vUn, $vTot]);
                    }
                }
            }
            $pdo->commit();
            $uploadMessage = "Sucesso! $count notas processadas.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $uploadMessage = "Erro: " . $e->getMessage();
        }
        $zE->close();
    }
}
?>

<div class="glass-card">
    <?php if($uploadMessage): ?>
        <div style="padding: 15px; background: rgba(52, 199, 89, 0.1); color: var(--success); border-radius: 12px; margin-bottom: 20px;">
            <?= $uploadMessage ?>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <div class="upload-area" onclick="document.getElementById('f1').click()">
            <i class="fa-solid fa-file-zipper" style="font-size: 3rem; color: var(--accent); margin-bottom: 20px;"></i>
            <h3>Clique para selecionar o ZIP de Emissões</h3>
            <p style="color: var(--text-secondary);">O sistema detectará automaticamente a empresa e os clientes.</p>
            <input type="file" name="f1" id="f1" style="display: none" onchange="this.form.submit()" required>
        </div>
    </form>
</div>
