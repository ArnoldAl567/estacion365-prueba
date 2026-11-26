import { CATEGORIES, DAILY_ADDON_CATEGORIES } from '../data/menu.data';

export const MENU_CATEGORY_LABELS: Partial<Record<string, string>> = {
  ...Object.fromEntries([...CATEGORIES, ...DAILY_ADDON_CATEGORIES].map((category) => [category.id, category.name])),
  'menu-dia': 'Menú del día',
  'sin-categoria': 'Sin categoría',
};
