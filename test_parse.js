const fs = require('fs');
const { XMLParser } = require('fast-xml-parser');

const parser = new XMLParser({
  ignoreAttributes: false,
  attributeNamePrefix: '@_',
  removeNSPrefix: true,
  isArray: (name) => name === 'det'
});

let raw = fs.readFileSync('exemplonfe.xml', 'utf-8');
raw = raw.replace(/^\uFEFF/, '');
const xml = parser.parse(raw);
console.log(JSON.stringify(xml).substring(0, 500));

const nfeNode = xml.nfeProc?.NFe || xml.NFe;
if (!nfeNode) {
  console.log('nfeNode is undefined');
} else {
  const inf = nfeNode.infNFe;
  let ch = String(xml.nfeProc?.protNFe?.infProt?.chNFe || '');
  if (!ch && inf['@_Id']) {
    ch = inf['@_Id'].replace(/^NFe/, '');
  }
  console.log('Access key:', ch);
  const issueStr = String(inf.ide?.dhEmi || '').substring(0, 10);
  console.log('Issue date:', issueStr);
  console.log('CNPJ Emit:', inf.emit?.CNPJ);
  console.log('CNPJ Dest:', inf.dest?.CNPJ || inf.dest?.CPF);
}
