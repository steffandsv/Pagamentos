const fs = require('fs');
const { XMLParser } = require('fast-xml-parser');

const parser = new XMLParser({
  ignoreAttributes: false,
  attributeNamePrefix: '@_',
  removeNSPrefix: true,
  parseTagValue: false,
  isArray: (name) => name === 'det'
});

let raw = fs.readFileSync('exemplonfe.xml', 'utf-8');
const xml = parser.parse(raw);
console.log(xml.nfeProc.protNFe.infProt.chNFe);
