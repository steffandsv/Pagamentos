<?php
class Intelligence {
    
    public static function getElasticityData($pdo, $whereGlobal = "") {
        // $whereGlobal already contains "AND ..."
        
        // Calculate Min, Max, Avg, and StdDev for unit prices per NCM (Grouped by 4 digits)
        // We filter out items with 0 price or very low quantity to avoid noise
        $sql = "SELECT 
                    SUBSTRING(ii.ncm, 1, 4) as code,
                    MIN(ii.unit_price) as min_price,
                    MAX(ii.unit_price) as max_price,
                    AVG(ii.unit_price) as avg_price,
                    STDDEV(ii.unit_price) as std_dev,
                    SUM(ii.total_price) as total_revenue,
                    COUNT(*) as transactions
                FROM invoice_items ii
                JOIN invoices i ON ii.invoice_id = i.id
                WHERE ii.unit_price > 0 $whereGlobal AND i.status != 'Cancelada'
                GROUP BY code
                HAVING transactions > 5
                ORDER BY total_revenue DESC
                LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data = $stmt->fetchAll();

        foreach ($data as &$row) {
            $info = NCMRegistry::getInfo($row['code']);
            $row['name'] = $info['desc'];
            // Variation Coefficient (CV) = StdDev / Avg
            $row['variation'] = $row['avg_price'] > 0 ? ($row['std_dev'] / $row['avg_price']) * 100 : 0;
            // Elasticity Proxy: (Max - Min) / Min
            $row['elasticity_proxy'] = $row['min_price'] > 0 ? (($row['max_price'] - $row['min_price']) / $row['min_price']) * 100 : 0;
        }
        return $data;
    }

    public static function getChurnRisk($pdo, $whereGlobal = "", $daysThreshold = 60) {
        // Find customers who bought in the past but NOT in the last X days
        // We need their last buy date and total lifetime value
        $thresholdDate = date('Y-m-d', strtotime("-$daysThreshold days"));
        
        $sql = "SELECT 
                    c.id, c.name, c.doc, c.city, c.uf,
                    MAX(i.issue_date) as last_buy,
                    SUM(i.total_value) as lifetime_value,
                    COUNT(i.id) as total_invoices
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                WHERE 1=1 $whereGlobal AND i.status != 'Cancelada'
                GROUP BY c.id
                HAVING last_buy < ?
                ORDER BY lifetime_value DESC
                LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$thresholdDate]);
        
        return $stmt->fetchAll();
    }
}
