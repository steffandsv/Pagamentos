/**
 * NCMRegistry - migração 1:1 de includes/ncm.php
 */
const fs = require('fs');

class NCMRegistry {
  constructor() {
    this.descriptions = {};
    this.loaded = false;
  }

  load(file) {
    if (this.loaded) return;
    if (fs.existsSync(file)) {
      let content = fs.readFileSync(file, 'utf-8');
      // Remove BOM if present
      if (content.charCodeAt(0) === 0xFEFF) content = content.slice(1);
      try {
        const json = JSON.parse(content);
        if (json.Nomenclaturas) {
          for (const item of json.Nomenclaturas) {
            const cleanCode = item.Codigo.replace(/\./g, '');
            this.descriptions[cleanCode] = item.Descricao;
          }
        }
      } catch (e) {
        console.error('NCM JSON parse error:', e.message);
      }
    }
    this.loaded = true;
  }

  getInfo(fullCode) {
    const candidates = [
      fullCode.substring(0, 8),
      fullCode.substring(0, 6),
      fullCode.substring(0, 4),
      fullCode.substring(0, 2)
    ];
    for (const code of candidates) {
      if (this.descriptions[code]) {
        return { code, desc: this.descriptions[code] };
      }
    }
    return { code: fullCode.substring(0, 4), desc: 'Categoria Desconhecida' };
  }
}

// Singleton
const registry = new NCMRegistry();
module.exports = registry;
