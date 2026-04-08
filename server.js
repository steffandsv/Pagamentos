/**
 * Genius Financial Vision Pro - Node.js/Express Server
 * Migração completa de index.php + views PHP
 */
require('dotenv').config();
const express = require('express');
const mysql = require('mysql2/promise');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const AdmZip = require('adm-zip');
const { XMLParser } = require('fast-xml-parser');
const { formatMoney, formatDate } = require('./helpers');
const GeoIntelligence = require('./geo');
const Intelligence = require('./intelligence');
const NCMRegistry = require('./ncm');

const app = express();
const PORT = process.env.APP_PORT || 4105;

// --- Database Pool ---
const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'financial_vision',
  waitForConnections: true,
  connectionLimit: 10,
  charset: 'utf8mb4'
});

// --- Middleware ---
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));
app.use(express.static(path.join(__dirname, 'assets')));
app.use(express.urlencoded({ extended: true }));
app.use(express.json());

// Upload storage (temp)
const upload = multer({ dest: '/tmp/uploads/' });

// Make helpers available in all EJS templates
app.use((req, res, next) => {
  res.locals.formatMoney = formatMoney;
  res.locals.formatDate = formatDate;
  next();
});

// --- Load NCM Registry ---
NCMRegistry.load(path.join(__dirname, 'ncm.json'));

// --- Geo Intelligence Singleton ---
const geo = new GeoIntelligence();

// --- Helper: Build WHERE clause from query params ---
function buildWhereGlobal(query) {
  let where = '';
  const selectedCompany = query.company || 'all';
  const selectedStatus = query.status || 'all';

  const isFilterSubmitted = !!query.filter_submitted;
  let authorizedOnly = false;
  if (!isFilterSubmitted) {
    authorizedOnly = true; // Default on
  } else {
    authorizedOnly = !!query.authorized_only;
  }

  if (selectedCompany !== 'all') {
    where += ` AND i.company_id = ${pool.escape(selectedCompany)}`;
  }
  if (selectedStatus === 'pending') {
    where += ` AND i.is_paid = 0`;
  }
  if (authorizedOnly) {
    where += ` AND i.status != 'Cancelada'`;
  }

  return { where, selectedCompany, selectedStatus, authorizedOnly };
}

// ============================
// AJAX API ROUTES
// ============================

// Toggle Paid
app.post('/', async (req, res) => {
  const action = req.query.action;

  if (action === 'toggle_paid' && req.body.id) {
    try {
      await pool.query('UPDATE invoices SET is_paid = NOT is_paid WHERE id = ?', [req.body.id]);
      const [rows] = await pool.query('SELECT is_paid FROM invoices WHERE id = ?', [req.body.id]);
      return res.json({ success: true, is_paid: rows[0]?.is_paid });
    } catch (e) {
      return res.json({ success: false, error: e.message });
    }
  }

  if (action === 'update_status' && req.body.id && req.body.status) {
    try {
      await pool.query('UPDATE invoices SET status = ? WHERE id = ?', [req.body.status, req.body.id]);
      return res.json({ success: true });
    } catch (e) {
      return res.json({ success: false, error: e.message });
    }
  }

  return res.status(400).json({ success: false, error: 'Unknown action' });
});

// ============================
// MAIN PAGE ROUTE
// ============================
app.get('/', async (req, res) => {
  try {
    let page = req.query.page || 'dashboard';
    // Security: allow only alphanumeric and underscores
    page = page.replace(/[^a-z0-9_]/g, '');

    const validPages = ['dashboard', 'sales', 'customers', 'geo_opps', 'products', 'intelligence', 'cobranca', 'upload'];
    if (!validPages.includes(page)) {
      page = 'notfound';
    }

    // Global data
    const [companies] = await pool.query('SELECT * FROM companies');
    const { where: whereGlobal, selectedCompany, selectedStatus, authorizedOnly } = buildWhereGlobal(req.query);

    // Page-specific data
    let pageData = {};

    if (page === 'dashboard') {
      pageData = await getDashboardData(whereGlobal);
    } else if (page === 'sales') {
      pageData = await getSalesData(whereGlobal);
    } else if (page === 'customers') {
      pageData = await getCustomersData(whereGlobal);
    } else if (page === 'geo_opps') {
      pageData = await getGeoOppsData(whereGlobal);
    } else if (page === 'products') {
      pageData = await getProductsData(whereGlobal);
    } else if (page === 'intelligence') {
      pageData = await getIntelligenceData(whereGlobal);
    } else if (page === 'cobranca') {
      pageData = await getCobrancaData(whereGlobal);
    }

    res.render('layout', {
      page,
      companies,
      selectedCompany,
      selectedStatus,
      authorizedOnly,
      pageData,
      uploadMessage: ''
    });
  } catch (err) {
    console.error('Route error:', err);
    res.status(500).send('Erro interno do servidor: ' + err.message);
  }
});

// ============================
// UPLOAD ROUTE (POST)
// ============================
app.post('/upload', upload.single('f1'), async (req, res) => {
  let uploadMessage = '';

  try {
    if (!req.file) {
      uploadMessage = 'Nenhum arquivo enviado.';
    } else {
      NCMRegistry.load(path.join(__dirname, 'ncm.json'));
      const zip = new AdmZip(req.file.path);
      const zipEntries = zip.getEntries();
      let count = 0;
      const conn = await pool.getConnection();

      try {
        await conn.beginTransaction();

        const parser = new XMLParser({
          ignoreAttributes: false,
          attributeNamePrefix: '@_',
          isArray: (name) => {
            // det (items) must always be an array
            return name === 'det';
          }
        });

        for (const entry of zipEntries) {
          // Antiestrangulamento do event loop (evita ETIMEDOUT em outras requisições)
          await new Promise(resolve => setImmediate(resolve));

          if (entry.isDirectory) continue;
          const raw = entry.getData().toString('utf-8');
          if (!raw.trim()) continue;

          let xml;
          try {
            xml = parser.parse(raw);
          } catch (e) {
            continue; // Skip unparseable files
          }

          // --- Handle Cancellation Events ---
          if (xml.procEventoNFe) {
            const ev = xml.procEventoNFe.evento;
            if (!ev) continue;

            let cStat = '';
            if (xml.procEventoNFe.retEvento) {
              cStat = String(xml.procEventoNFe.retEvento.infEvento?.cStat || '');
            }

            const infEvento = ev.infEvento || {};
            if (String(infEvento.tpEvento) === '110111' && cStat === '135') {
              const ch = String(infEvento.chNFe || '');
              if (ch) {
                const [result] = await conn.query("UPDATE invoices SET status = 'Cancelada' WHERE access_key = ?", [ch]);
                if (result.affectedRows > 0) count++;
              }
            }
            continue;
          }

          // --- Handle NF-e ---
          const nfeNode = xml.nfeProc?.NFe || xml.NFe;
          if (!nfeNode) continue;

          const inf = nfeNode.infNFe;
          if (!inf) continue;

          // Extrair chave de acesso (do protNFe ou do Id da infNFe)
          let ch = String(xml.nfeProc?.protNFe?.infProt?.chNFe || '');
          if (!ch && inf['@_Id']) {
            ch = inf['@_Id'].replace(/^NFe/, '');
          }
          if (!ch) continue;

          // 1. Company
          const emitCNPJ = String(inf.emit?.CNPJ || '');
          const emitName = String(inf.emit?.xNome || '');

          const [compRows] = await conn.query('SELECT id FROM companies WHERE cnpj = ?', [emitCNPJ]);
          let companyId;

          if (compRows.length > 0) {
            companyId = compRows[0].id;
          } else {
            const [insertResult] = await conn.query('INSERT INTO companies (cnpj, name) VALUES (?, ?)', [emitCNPJ, emitName]);
            companyId = insertResult.insertId;
          }

          // 2. Customer
          const destDoc = String(inf.dest?.CNPJ || inf.dest?.CPF || 'N/A');
          const destName = String(inf.dest?.xNome || '');
          const destCity = String(inf.dest?.enderDest?.xMun || '');
          const destUF = String(inf.dest?.enderDest?.UF || '');
          const issueStr = String(inf.ide?.dhEmi || '').substring(0, 10);

          const [custRows] = await conn.query('SELECT id FROM customers WHERE doc = ?', [destDoc]);
          let customerId;

          if (custRows.length > 0) {
            customerId = custRows[0].id;
            await conn.query('UPDATE customers SET last_buy = ? WHERE id = ? AND last_buy < ?', [issueStr, customerId, issueStr]);
          } else {
            const [insertResult] = await conn.query(
              'INSERT INTO customers (doc, name, city, uf, first_buy, last_buy) VALUES (?, ?, ?, ?, ?, ?)',
              [destDoc, destName, destCity, destUF, issueStr, issueStr]
            );
            customerId = insertResult.insertId;
          }

          // 3. Invoice
          const numNF = String(inf.ide?.nNF || '');
          const valNF = parseFloat(inf.total?.ICMSTot?.vNF) || 0;

          const [invResult] = await conn.query(
            'INSERT IGNORE INTO invoices (company_id, customer_id, access_key, number, issue_date, total_value) VALUES (?, ?, ?, ?, ?, ?)',
            [companyId, customerId, ch, numNF, issueStr, valNF]
          );
          const invoiceId = invResult.insertId;

          if (invoiceId) {
            count++;
            // 4. Items
            const items = inf.det || [];
            if (items.length > 0) {
              const insertData = items.map(d => {
                const ncm = String(d.prod?.NCM || '');
                const desc = String(d.prod?.xProd || '');
                const qty = parseFloat(d.prod?.qCom) || 0;
                const vUn = parseFloat(d.prod?.vUnCom) || 0;
                const vTot = parseFloat(d.prod?.vProd) || 0;
                return [invoiceId, ncm, desc, qty, vUn, vTot];
              });

              await conn.query(
                'INSERT INTO invoice_items (invoice_id, ncm, description, quantity, unit_price, total_price) VALUES ?',
                [insertData]
              );
            }
          }
        }

        await conn.commit();
        uploadMessage = `Sucesso! ${count} notas processadas.`;
      } catch (e) {
        await conn.rollback();
        uploadMessage = 'Erro: ' + e.message;
      } finally {
        conn.release();
      }

      // Cleanup temp file
      try { fs.unlinkSync(req.file.path); } catch (e) { /* ignore */ }
    }
  } catch (e) {
    uploadMessage = 'Erro: ' + e.message;
  }

  // Re-render the page with upload message
  try {
    const [companies] = await pool.query('SELECT * FROM companies');
    const { where: whereGlobal, selectedCompany, selectedStatus, authorizedOnly } = buildWhereGlobal(req.query);

    res.render('layout', {
      page: 'upload',
      companies,
      selectedCompany,
      selectedStatus,
      authorizedOnly,
      pageData: {},
      uploadMessage
    });
  } catch (err) {
    res.status(500).send('Erro: ' + err.message);
  }
});

// ============================
// PAGE DATA FETCHERS
// ============================

async function getDashboardData(whereGlobal) {
  // 1. KPIs
  const [kpiRows] = await pool.query(`
    SELECT 
      SUM(total_value) as total,
      COUNT(*) as count,
      AVG(total_value) as ticket,
      SUM(CASE WHEN is_paid = 0 THEN total_value ELSE 0 END) as pending,
      SUM(CASE WHEN is_paid = 1 THEN total_value ELSE 0 END) as paid
    FROM invoices i WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada'
  `);
  const kpi = kpiRows[0];

  // 2. Timeline
  const [timeRows] = await pool.query(`
    SELECT issue_date, SUM(total_value) as val 
    FROM invoices i WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada' 
    GROUP BY issue_date ORDER BY issue_date
  `);
  const timeline = {};
  for (const r of timeRows) {
    const key = r.issue_date instanceof Date
      ? r.issue_date.toISOString().split('T')[0]
      : String(r.issue_date);
    timeline[key] = r.val;
  }

  // 3. Top Customers
  const [topCustomers] = await pool.query(`
    SELECT c.name, SUM(i.total_value) as total 
    FROM invoices i JOIN customers c ON i.customer_id = c.id 
    WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada' 
    GROUP BY c.id 
    ORDER BY total DESC LIMIT 5
  `);

  // 4. Map Data
  const mapData = await geo.getMapDataFromDB(pool, whereGlobal);

  // 5. Aging KPIs (Net 30)
  const [agingRows] = await pool.query(`
    SELECT 
      SUM(CASE WHEN i.is_paid = 0 AND DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) > 30 THEN 1 ELSE 0 END) as critico_count,
      SUM(CASE WHEN i.is_paid = 0 AND DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) > 30 THEN i.total_value ELSE 0 END) as critico_val,
      SUM(CASE WHEN i.is_paid = 0 AND DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) BETWEEN 1 AND 30 THEN 1 ELSE 0 END) as alerta_count,
      SUM(CASE WHEN i.is_paid = 0 AND DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) BETWEEN 1 AND 30 THEN i.total_value ELSE 0 END) as alerta_val,
      SUM(CASE WHEN i.is_paid = 0 AND DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) <= 0 THEN 1 ELSE 0 END) as noprazo_count,
      SUM(CASE WHEN i.is_paid = 0 AND DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) <= 0 THEN i.total_value ELSE 0 END) as noprazo_val
    FROM invoices i WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada'
  `);
  const aging = agingRows[0];

  // 6. Priority Chart - debtors grouped by aging category (top 20)
  const [priorityRows] = await pool.query(`
    SELECT 
      c.name as customer_name,
      SUM(CASE WHEN DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) > 30 THEN i.total_value ELSE 0 END) as critico,
      SUM(CASE WHEN DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) BETWEEN 1 AND 30 THEN i.total_value ELSE 0 END) as alerta,
      SUM(CASE WHEN DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) <= 0 THEN i.total_value ELSE 0 END) as noprazo,
      SUM(i.total_value) as total
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada' AND i.is_paid = 0
    GROUP BY c.id
    ORDER BY total DESC
    LIMIT 20
  `);

  return { kpi, timeline, topCustomers, mapData, aging, priorityChart: priorityRows };
}

async function getSalesData(whereGlobal) {
  const [invoices] = await pool.query(`
    SELECT i.*, c.name as customer_name, c.doc, comp.name as company_name,
      DATE_ADD(i.issue_date, INTERVAL 30 DAY) as due_date,
      DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) as days_overdue
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN companies comp ON i.company_id = comp.id
    WHERE 1=1 ${whereGlobal} 
    ORDER BY i.issue_date DESC LIMIT 1000
  `);
  return { invoices };
}

async function getCustomersData(whereGlobal) {
  // Pareto
  const [customers] = await pool.query(`
    SELECT c.name, c.doc, SUM(i.total_value) as total, MAX(i.issue_date) as last_buy
    FROM invoices i JOIN customers c ON i.customer_id = c.id 
    WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada' 
    GROUP BY c.id 
    ORDER BY total DESC
  `);

  const totalRev = customers.reduce((sum, c) => sum + parseFloat(c.total || 0), 0) || 1;
  let accum = 0;
  for (const c of customers) {
    accum += parseFloat(c.total || 0);
    c.share = (parseFloat(c.total) / totalRev) * 100;
    c.accumulated_share = (accum / totalRev) * 100;
    c.class = c.accumulated_share <= 80 ? 'A' : (c.accumulated_share <= 95 ? 'B' : 'C');
  }

  // Churn Risk
  const churnData = await Intelligence.getChurnRisk(pool, whereGlobal, 90);

  return { customers, churnData };
}

async function getGeoOppsData(whereGlobal) {
  const topClients = await geo.getTopCustomersLocations(pool, whereGlobal, 100);
  return { topClients };
}

async function getProductsData(whereGlobal) {
  const [products] = await pool.query(`
    SELECT 
      ii.ncm as code,
      SUM(ii.total_price) as total
    FROM invoice_items ii
    JOIN invoices i ON ii.invoice_id = i.id
    WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada'
    GROUP BY ii.ncm
    ORDER BY total DESC
  `);

  const totalProdRev = products.reduce((sum, p) => sum + parseFloat(p.total || 0), 0) || 1;
  let accum = 0;
  for (const p of products) {
    accum += parseFloat(p.total || 0);
    p.share = (parseFloat(p.total) / totalProdRev) * 100;
    p.accumulated_share = (accum / totalProdRev) * 100;
    p.class = p.accumulated_share <= 80 ? 'A' : (p.accumulated_share <= 95 ? 'B' : 'C');
    const info = NCMRegistry.getInfo(p.code);
    p.name = info.desc;
  }

  return { products };
}

async function getIntelligenceData(whereGlobal) {
  const elasticityData = await Intelligence.getElasticityData(pool, whereGlobal);
  return { elasticityData };
}

async function getCobrancaData(whereGlobal) {
  // 1. Critical debtors (>30 days overdue, unpaid) grouped by customer
  const [criticalRows] = await pool.query(`
    SELECT 
      c.id as customer_id, c.name, c.doc, c.city, c.uf,
      i.id as invoice_id, i.number, i.issue_date, i.total_value,
      DATE_ADD(i.issue_date, INTERVAL 30 DAY) as due_date,
      DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) as days_overdue
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE 1=1 ${whereGlobal}
      AND i.status != 'Cancelada'
      AND i.is_paid = 0
      AND DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) > 30
    ORDER BY days_overdue DESC, i.total_value DESC
  `);

  // Group by customer
  const criticalMap = {};
  for (const row of criticalRows) {
    if (!criticalMap[row.customer_id]) {
      criticalMap[row.customer_id] = {
        name: row.name,
        doc: row.doc,
        city: row.city,
        uf: row.uf,
        total: 0,
        invoices: []
      };
    }
    criticalMap[row.customer_id].total += parseFloat(row.total_value);
    criticalMap[row.customer_id].invoices.push({
      id: row.invoice_id,
      number: row.number || 'S/N',
      issue_date: row.issue_date,
      due_date: row.due_date,
      total_value: row.total_value,
      days_overdue: row.days_overdue
    });
  }

  const criticalDebtors = Object.values(criticalMap)
    .sort((a, b) => b.total - a.total);

  // 2. PDCA - Curva A (>R$5000, unpaid)
  const [curvaARows] = await pool.query(`
    SELECT c.name, c.doc, i.number, i.total_value, i.issue_date,
      DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) as days_overdue
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE 1=1 ${whereGlobal}
      AND i.status != 'Cancelada' AND i.is_paid = 0
      AND i.total_value >= 5000
    ORDER BY i.total_value DESC LIMIT 30
  `);

  // 3. PDCA - Crônicos (>90 days overdue)
  const [cronicoRows] = await pool.query(`
    SELECT c.name, c.doc, c.city, c.uf, i.number, i.total_value, i.issue_date,
      DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) as days_overdue
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    WHERE 1=1 ${whereGlobal}
      AND i.status != 'Cancelada' AND i.is_paid = 0
      AND DATEDIFF(CURDATE(), DATE_ADD(i.issue_date, INTERVAL 30 DAY)) > 90
    ORDER BY days_overdue DESC LIMIT 30
  `);

  // 4. Summary KPIs for the cobrança page
  const totalCriticoVal = criticalDebtors.reduce((s, d) => s + d.total, 0);
  const totalCriticoCount = criticalRows.length;

  return {
    criticalDebtors,
    curvaA: curvaARows,
    cronicos: cronicoRows,
    totalCriticoVal,
    totalCriticoCount
  };
}

// ============================
// START SERVER
// ============================
app.listen(PORT, '0.0.0.0', () => {
  console.log(`Genius Financial Vision Pro running on port ${PORT}`);
});
