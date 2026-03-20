/**
 * Intelligence - migração 1:1 de includes/intelligence.php
 */
const NCMRegistry = require('./ncm');

class Intelligence {
  static async getElasticityData(pool, whereGlobal = '') {
    const sql = `SELECT 
                    SUBSTRING(ii.ncm, 1, 4) as code,
                    MIN(ii.unit_price) as min_price,
                    MAX(ii.unit_price) as max_price,
                    AVG(ii.unit_price) as avg_price,
                    STDDEV(ii.unit_price) as std_dev,
                    SUM(ii.total_price) as total_revenue,
                    COUNT(*) as transactions
                 FROM invoice_items ii
                 JOIN invoices i ON ii.invoice_id = i.id
                 WHERE ii.unit_price > 0 ${whereGlobal} AND i.status != 'Cancelada'
                 GROUP BY code
                 HAVING transactions > 5
                 ORDER BY total_revenue DESC
                 LIMIT 50`;

    const [data] = await pool.query(sql);

    for (const row of data) {
      const info = NCMRegistry.getInfo(row.code);
      row.name = info.desc;
      // Coefficient of Variation (CV) = StdDev / Avg
      row.variation = row.avg_price > 0 ? (row.std_dev / row.avg_price) * 100 : 0;
      // Elasticity Proxy: (Max - Min) / Min
      row.elasticity_proxy = row.min_price > 0 ? ((row.max_price - row.min_price) / row.min_price) * 100 : 0;
    }
    return data;
  }

  static async getChurnRisk(pool, whereGlobal = '', daysThreshold = 60) {
    const thresholdDate = new Date();
    thresholdDate.setDate(thresholdDate.getDate() - daysThreshold);
    const thresholdStr = thresholdDate.toISOString().split('T')[0];

    const sql = `SELECT 
                    c.id, c.name, c.doc, c.city, c.uf,
                    MAX(i.issue_date) as last_buy,
                    SUM(i.total_value) as lifetime_value,
                    COUNT(i.id) as total_invoices
                 FROM invoices i
                 JOIN customers c ON i.customer_id = c.id
                 WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada'
                 GROUP BY c.id
                 HAVING last_buy < ?
                 ORDER BY lifetime_value DESC
                 LIMIT 50`;

    const [data] = await pool.query(sql, [thresholdStr]);
    return data;
  }
}

module.exports = Intelligence;
