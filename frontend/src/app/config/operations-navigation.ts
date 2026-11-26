export const OPERATIONS_NAV = [
  { label: 'Salón', icon: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18', pages: [
    { id: 'salon', label: 'Salón', path: 'salon', view: 'salon' },
  ] },
  { label: 'Cocina', icon: 'M4 16h16M5 16a7 7 0 0 1 14 0M12 5V3M9 3h6M3 20h18', pages: [
    { id: 'kitchen', label: 'Cocina', path: 'cocina', view: 'kitchen' },
  ] },
  { label: 'Historial', icon: 'M3 12a9 9 0 1 0 3-7M3 3v6h6M12 7v5l3 2', pages: [
    { id: 'history', label: 'Historial', path: 'pedidos/historial', view: 'history' },
  ] },
  { label: 'Caja', icon: 'M3 6h18v14H3zM3 11h18M8 15h3m5 0h2', pages: [
    { id: 'cash', label: 'Turno actual', path: 'caja/turno', view: 'cash' },
    { id: 'cash-templates', label: 'Configuración de turnos', path: 'caja/turnos', view: 'cash' },
    { id: 'cash-orders', label: 'Cobros y mesas', path: 'caja/cobros', view: 'cash' },
    { id: 'cash-payments', label: 'Historial de cobros', path: 'caja/historial-cobros', view: 'cash' },
    { id: 'cash-history', label: 'Historial de turnos', path: 'caja/historial', view: 'cash' },
  ] },
  { label: 'Menú', icon: 'M3 4h7l2 2 2-2h7v16h-7l-2 2-2-2H3V4m9 2v16M6 8h3M15 8h3', pages: [
    { id: 'catalog', label: 'Platos y bebidas', path: 'menu/platos', view: 'catalog' },
    { id: 'categories', label: 'Categorías', path: 'menu/categorias', view: 'categories' },
    { id: 'modifiers', label: 'Modificadores', path: 'menu/modificadores', view: 'modifiers' },
  ] },
  { label: 'Inventario', icon: 'M3 7l9-4 9 4v10l-9 4-9-4V7m0 0 9 4 9-4m-9 4v10', pages: [
    { id: 'inventory', label: 'Insumos', path: 'inventario/insumos', view: 'inventory' },
    { id: 'inventory-categories', label: 'Categorías de insumos', path: 'inventario/insumos/categorias', view: 'inventory' },
    { id: 'inventory-units', label: 'Unidades de medida', path: 'inventario/insumos/unidades', view: 'inventory' },
    { id: 'inventory-suppliers', label: 'Proveedores', path: 'inventario/proveedores', view: 'inventory' },
    { id: 'inventory-purchases', label: 'Compras', path: 'inventario/compras', view: 'inventory' },
    { id: 'inventory-movements', label: 'Movimientos', path: 'inventario/movimientos', view: 'inventory' },
    { id: 'inventory-incidents', label: 'Desvíos y mermas', path: 'inventario/desvios-mermas', view: 'inventory' },
    { id: 'inventory-production', label: 'Recetas y lotes', path: 'inventario/produccion', view: 'inventory' },
    { id: 'inventory-orders', label: 'Consumo por pedido', path: 'inventario/consumo', view: 'inventory' },
  ] },
  { label: 'Reportes', icon: 'M4 4v16h16M8 15v2M12 11v6M16 7v10', pages: [
    { id: 'reports', label: 'Resumen general', path: 'reportes', view: 'analytics' },
    { id: 'report-articles', label: 'Ventas por artículo', path: 'reportes/ventas/articulos', view: 'analytics' },
    { id: 'report-categories', label: 'Ventas por categoría', path: 'reportes/ventas/categorias', view: 'analytics' },
    { id: 'report-payments', label: 'Cobros por medio de pago', path: 'reportes/pagos', view: 'reports' },
    { id: 'report-expenses', label: 'Gastos', path: 'reportes/gastos', view: 'reports' },
    { id: 'report-orders', label: 'Pedidos', path: 'reportes/pedidos', view: 'reports' },
    { id: 'report-tickets', label: 'Comandas', path: 'reportes/comandas', view: 'reports' },
    { id: 'report-demand', label: 'Demanda de platos', path: 'reportes/demanda', view: 'reports' },
    { id: 'report-consumption', label: 'Consumo de insumos', path: 'reportes/consumo', view: 'reports' },
    { id: 'report-production', label: 'Producción', path: 'reportes/produccion', view: 'reports' },
    { id: 'report-food', label: 'Food Cost', path: 'reportes/food-cost', view: 'reports' },
    { id: 'report-audit', label: 'Auditoría', path: 'reportes/auditoria', view: 'reports' },
  ] },
  { label: 'Configuración', icon: 'M4 6h16M4 12h16M4 18h16M8 4v4m8 2v4m-6 2v4', pages: [
    { id: 'settings-tables', label: 'Salones y mesas', path: 'configuracion/mesas', view: 'salon' },
    { id: 'settings', label: 'Cocina', path: 'configuracion/centros', view: 'settings' },
    { id: 'settings-print', label: 'Impresión', path: 'configuracion/impresion', view: 'settings' },
    { id: 'settings-methods', label: 'Medios de pago', path: 'configuracion/pagos', view: 'settings' },
    { id: 'users', label: 'Equipo y permisos', path: 'configuracion/equipo', view: 'users' },
  ] },
] as const;

export type OperationsPage = typeof OPERATIONS_NAV[number]['pages'][number];
export type OperationsPageId = OperationsPage['id'];
export type OperationsView = OperationsPage['view'];
export const OPERATIONS_PAGES: OperationsPage[] = OPERATIONS_NAV.flatMap<OperationsPage>((group) => group.pages);

export function operationsPageUrl(id: OperationsPageId): string {
  return '/administrar/' + OPERATIONS_PAGES.find((page) => page.id === id)!.path;
}

const legacyPages: Record<string, OperationsPageId> = {
  '#salon': 'salon', '#pedidos': 'kitchen', '#cocina': 'kitchen', '#historial': 'history',
  '#equipo': 'users', '#carta': 'catalog', '#reportes': 'reports', '#caja': 'cash',
  '#inventario': 'inventory', '#configuracion': 'settings',
};
const modulePages: Record<string, OperationsPageId> = {
  '/administrar/pedidos': 'kitchen',
  '/administrar/caja': 'cash',
  '/administrar/caja/gastos': 'cash',
  '/administrar/menu': 'catalog',
  '/administrar/inventario': 'inventory',
  '/administrar/inventario/categorias': 'inventory-categories',
  '/administrar/inventario/disponibilidad': 'inventory-production',
  '/administrar/configuracion': 'settings',
  '/administrar/reportes/ventas': 'report-articles',
};

export function resolveOperationsPage(path: string, hash: string): OperationsPage | undefined {
  const normalized = path.replace(/\/+$/, '');
  if (normalized === '/panel-administrativo') return OPERATIONS_PAGES.find((page) => page.id === 'catalog');
  if (normalized === '/administrar') return OPERATIONS_PAGES.find((page) => page.id === legacyPages[hash]);
  return OPERATIONS_PAGES.find((page) => normalized === '/administrar/' + page.path || page.id === modulePages[normalized]);
}
