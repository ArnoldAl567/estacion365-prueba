const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const cache = new Map();

function load(file) {
  const fullPath = path.resolve(file);
  if (cache.has(fullPath)) return cache.get(fullPath).exports;
  const module = { exports: {} };
  cache.set(fullPath, module);
  const source = ts.transpileModule(fs.readFileSync(fullPath, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const localRequire = specifier => {
    if (!specifier.startsWith('.')) throw new Error(`Unexpected dependency: ${specifier}`);
    return load(path.resolve(path.dirname(fullPath), `${specifier}.ts`));
  };
  new Function('require', 'module', 'exports', source)(localRequire, module, module.exports);
  return module.exports;
}

const target = path.join(__dirname, '../backend/database/data/catalog.json');
if (fs.existsSync(target) && !process.argv.includes('--replace')) {
  console.log('Se conserva la copia local del catálogo.');
  process.exit(0);
}
fs.mkdirSync(path.dirname(target), { recursive: true });
const catalog = load(path.join(__dirname, '../frontend/src/app/data/catalog-seed.ts')).INITIAL_CATALOG;
fs.writeFileSync(target, JSON.stringify({ items: catalog, prices: { economico: 12, ejecutivo: 14 } }, null, 2));
console.log(`Catálogo local preparado con ${catalog.length} productos.`);
