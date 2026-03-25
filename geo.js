/**
 * GeoIntelligence - migração 1:1 de includes/geo.php
 */
const fs = require('fs');
const path = require('path');

// Mapeamento codigo_uf (IBGE) → sigla UF
const UF_CODE_TO_SIGLA = {
  11: 'RO', 12: 'AC', 13: 'AM', 14: 'RR', 15: 'PA', 16: 'AP', 17: 'TO',
  21: 'MA', 22: 'PI', 23: 'CE', 24: 'RN', 25: 'PB', 26: 'PE', 27: 'AL',
  28: 'SE', 29: 'BA',
  31: 'MG', 32: 'ES', 33: 'RJ', 35: 'SP',
  41: 'PR', 42: 'SC', 43: 'RS',
  50: 'MS', 51: 'MT', 52: 'GO', 53: 'DF'
};

class GeoIntelligence {
  constructor() {
    this.municipalities = {};
    const cacheFile = path.join(__dirname, 'municipios_ibge_cache.json');

    if (fs.existsSync(cacheFile)) {
      let content = fs.readFileSync(cacheFile, 'utf-8');
      // Remove BOM
      if (content.charCodeAt(0) === 0xFEFF) content = content.slice(1);

      let data;
      try {
        data = JSON.parse(content);
      } catch (e) {
        // Fallback: find array start
        const start = content.indexOf('[');
        if (start !== -1) {
          try { data = JSON.parse(content.substring(start)); } catch (e2) { /* ignore */ }
        }
      }

      if (Array.isArray(data)) {
        for (const m of data) {
          if (m.codigo_ibge) {
            const ibge = parseInt(m.codigo_ibge, 10);
            this.municipalities[ibge] = {
              lat: parseFloat(m.latitude),
              lon: parseFloat(m.longitude),
              nome: m.nome,
              uf: UF_CODE_TO_SIGLA[m.codigo_uf] || String(m.codigo_uf)
            };
          }
        }
      }
    }
  }

  getCoordsByIbge(ibgeCode) {
    const code = parseInt(ibgeCode, 10);
    if (this.municipalities[code]) return this.municipalities[code];
    const shortCode = parseInt(String(code).substring(0, 6), 10);
    if (this.municipalities[shortCode]) return this.municipalities[shortCode];
    return null;
  }

  getDistance(lat1, lon1, lat2, lon2) {
    const toRad = (deg) => (deg * Math.PI) / 180;
    const theta = lon1 - lon2;
    let dist = Math.sin(toRad(lat1)) * Math.sin(toRad(lat2)) +
               Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(toRad(theta));
    dist = Math.acos(Math.min(1, Math.max(-1, dist)));
    dist = (dist * 180) / Math.PI;
    return dist * 60 * 1.1515 * 1.609344;
  }

  normalize(str) {
    if (str === null || str === undefined) return '';
    let s = String(str).toLowerCase();
    s = s.replace(/[áàãâä]/g, 'a');
    s = s.replace(/[éèêë]/g, 'e');
    s = s.replace(/[íìîï]/g, 'i');
    s = s.replace(/[óòõôö]/g, 'o');
    s = s.replace(/[úùûü]/g, 'u');
    s = s.replace(/[ç]/g, 'c');
    return s.trim();
  }

  findCoordsByName(name, uf) {
    const normName = this.normalize(name);
    const normUF = this.normalize(uf);

    for (const m of Object.values(this.municipalities)) {
      if (this.normalize(m.nome) === normName && this.normalize(m.uf) === normUF) {
        return m;
      }
    }
    return null;
  }

  async getMapDataFromDB(pool, whereGlobal = '') {
    const sql = `SELECT c.city, c.uf, SUM(i.total_value) as total 
                 FROM invoices i 
                 JOIN customers c ON i.customer_id = c.id 
                 WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada' 
                 GROUP BY c.city, c.uf`;

    const [results] = await pool.query(sql);
    const mapData = {};

    for (const row of results) {
      const coords = this.findCoordsByName(row.city, row.uf);
      if (coords) {
        const key = `${row.city} - ${row.uf}`;
        mapData[key] = {
          val: row.total,
          lat: coords.lat,
          lon: coords.lon,
          city: row.city
        };
      }
    }
    return mapData;
  }

  async getTopCustomersLocations(pool, whereGlobal = '', limit = 50) {
    const sql = `SELECT c.name, c.city, c.uf, SUM(i.total_value) as total 
                 FROM invoices i 
                 JOIN customers c ON i.customer_id = c.id 
                 WHERE 1=1 ${whereGlobal} AND i.status != 'Cancelada' 
                 GROUP BY c.id 
                 ORDER BY total DESC 
                 LIMIT ${parseInt(limit, 10)}`;

    const [results] = await pool.query(sql);
    const markers = [];

    for (const row of results) {
      const coords = this.findCoordsByName(row.city, row.uf);
      if (coords) {
        markers.push({
          name: row.name,
          city: row.city,
          val: row.total,
          lat: coords.lat,
          lon: coords.lon
        });
      }
    }
    return markers;
  }
}

module.exports = GeoIntelligence;
