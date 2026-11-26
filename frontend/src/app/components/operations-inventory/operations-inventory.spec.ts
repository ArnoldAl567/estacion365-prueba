import { fakeAsync, flushMicrotasks, TestBed, tick } from '@angular/core/testing';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { OperationsInventory } from './operations-inventory';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';

describe('Inventory record dialogs', () => {
  it('separates ingredient lists, categories and units into linked sections', async () => {
    setup();
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    const links = root.querySelectorAll<HTMLAnchorElement>('.ingredient-tabs a');
    expect(links.length).toBe(3);
    expect(links[1].getAttribute('href')).toBe('/administrar/inventario/insumos/categorias');
    expect(links[2].getAttribute('href')).toBe('/administrar/inventario/insumos/unidades');
    expect(root.textContent).not.toContain('Unidades registradas');
    const requested = jasmine.createSpy('pageRequested'); fixture.componentInstance.pageRequested.subscribe(requested);
    const click = new MouseEvent('click', {bubbles: true, cancelable: true});
    links[2].addEventListener('click', (event) => event.preventDefault()); links[2].dispatchEvent(click);
    expect(requested).toHaveBeenCalledWith({event: click, id: 'inventory-units'});
    fixture.componentRef.setInput('page', 'inventory-units'); fixture.detectChanges();
    expect(root.textContent).toContain('Unidades registradas');
    expect(root.textContent).not.toContain('Insumos registrados');
    expect(root.querySelector('[aria-current="page"]')?.textContent).toContain('Unidades de medida');
    fixture.destroy();
  });
  it('creates inventory categories in a modal and offers them when editing an ingredient', async () => {
    const data = {categories: [{id: 1, name: 'Despensa', item_count: 0}], units: [], items: [], suppliers: [], products: [], recipes: [], purchases: [], orders: {data: []}, movements: {data: []}, batches: {data: []}};
    const workflow = jasmine.createSpy('workflow').and.callFake(async (_path: string, body?: object) => body ? {id: 2} : data);
    TestBed.configureTestingModule({providers: [{provide: LocalOperationsApi, useValue: {workflow}}]});
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.componentRef.setInput('page', 'inventory-categories');
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.textContent).toContain('Despensa');
    expect(root.querySelector('form')).toBeNull();
    fixture.componentInstance.create('categories'); fixture.detectChanges(); await fixture.whenStable();
    const form = root.querySelector<HTMLFormElement>('dialog form')!;
    const input = form.querySelector<HTMLInputElement>('input')!;
    input.value = 'Verduras'; input.dispatchEvent(new Event('input')); fixture.detectChanges(); await fixture.whenStable();
    form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true})); await fixture.whenStable(); fixture.detectChanges();
    expect(workflow).toHaveBeenCalledWith('/inventory/categories', jasmine.objectContaining({name: 'Verduras'}));
    fixture.componentRef.setInput('page', 'inventory'); fixture.componentInstance.create('items'); fixture.detectChanges(); await fixture.whenStable();
    expect(root.querySelector('dialog select[name="category"]')?.textContent).toContain('Despensa');
    fixture.destroy();
  });
  it('keeps a twenty-ingredient recipe compact and shows every ingredient in its detail modal', async () => {
    const items = Array.from({length: 20}, (_, i) => ({id: i + 1, name: 'Insumo ' + (i + 1), symbol: 'kg'}));
    const recipe = {id: 7, name: 'Receta completa', product_key: 'carta:prueba', mode: 'direct', batch_yield: 20,
      components: items.map((item) => ({item_id: item.id, quantity_milli: 250}))};
    const workflow = jasmine.createSpy('workflow').and.resolveTo({units: [], items, suppliers: [], products: [], recipes: [recipe], purchases: [], orders: {data: []}, movements: {data: []}, batches: {data: []}});
    TestBed.configureTestingModule({providers: [{provide: LocalOperationsApi, useValue: {workflow}}]});
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.componentRef.setInput('page', 'inventory-production');
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelectorAll('.recipe-ingredient-tag').length).toBe(3);
    expect(root.querySelector('.recipe-ingredients-summary')?.textContent).toContain('20 insumos');
    expect(root.querySelector('.recipe-ingredients-more')?.textContent).toContain('+17 más');
    root.querySelector<HTMLButtonElement>('[aria-label="Ver detalle de receta de Receta completa"]')!.click();
    fixture.detectChanges();
    const dialog = root.querySelector('dialog[open]')!;
    expect(dialog.querySelectorAll('tbody tr').length).toBe(20);
    expect(dialog.textContent).toContain('Insumo 20');
    expect(workflow.calls.count()).toBe(1);
    fixture.destroy();
  });
  it('saves ingredients per dish and dishes per batch without registering production', async () => {
    const data = {units: [], items: [{id: 1, name: 'Arroz', symbol: 'kg'}], suppliers: [], products: [{item_key: 'carta:arroz', name: 'Arroz preparado', kind: 'carta'}], recipes: [], purchases: [], orders: {data: []}, movements: {data: []}, batches: {data: [{id: 1}]}};
    const workflow = jasmine.createSpy('workflow').and.callFake(async (_path: string, body?: object) => body ? {} : data);
    TestBed.configureTestingModule({providers: [{provide: LocalOperationsApi, useValue: {workflow}}]});
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.componentRef.setInput('page', 'inventory-production');
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.textContent).not.toContain('Registrar producción');
    expect(root.textContent).not.toContain('Lotes de producción');
    fixture.componentInstance.create('recipes'); fixture.detectChanges(); await fixture.whenStable();
    const form = root.querySelector<HTMLFormElement>('dialog form')!;
    expect(form.querySelector('select[name="mode"]')).toBeNull();
    const product = form.querySelector<HTMLSelectElement>('select[name="product"]')!;
    product.selectedIndex = 1; product.dispatchEvent(new Event('change')); fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const ingredient = form.querySelector<HTMLSelectElement>('.workflow-line select')!;
    ingredient.selectedIndex = 1; ingredient.dispatchEvent(new Event('change'));
    for (const [name, value] of [['line-quantity-0', '0.25'], ['yield', '20']]) {
      const field = form.querySelector<HTMLInputElement>(name === 'yield' ? 'input[name="yield"]' : '.workflow-line input')!;
      field.value = value; field.dispatchEvent(new Event('input'));
    }
    fixture.detectChanges(); await fixture.whenStable();
    form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true})); await fixture.whenStable();
    expect(workflow).toHaveBeenCalledWith('/inventory/recipes', jasmine.objectContaining({product_key: 'carta:arroz', batch_yield: 20, components: [{item_id: 1, quantity_milli: 250}]}));
    expect(workflow.calls.allArgs().some((args) => args[0] === '/inventory/batches')).toBeFalse();
    fixture.destroy();
  });
  it('collects a purchase cancellation reason in a modal and requires confirmation before reversing it', async () => {
    const purchase = {id: 4, supplier: 'Proveedor', reference: 'Prueba', total_cents: 3000, created_at: '2026-11-24 20:00:00', cancelled_at: null as string | null};
    const data = {units: [], items: [], suppliers: [], products: [], recipes: [], purchases: [purchase], orders: {data: []}, movements: {data: []}, batches: {data: []}};
    const ask = jasmine.createSpy('ask').and.resolveTo(false);
    const workflow = jasmine.createSpy('workflow').and.callFake(async (path: string) => {
      if (path === '/inventory/purchases/4/cancel') { purchase.cancelled_at = '2026-11-24 21:00:00'; return {cancelled: true}; }
      return data;
    });
    TestBed.configureTestingModule({providers: [{provide: LocalOperationsApi, useValue: {workflow}}, {provide: RemovalConfirmation, useValue: {ask}}]});
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.componentRef.setInput('page', 'inventory-purchases');
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    root.querySelector<HTMLButtonElement>('[aria-label="Anular compra #4"]')!.click();
    fixture.detectChanges(); await fixture.whenStable();
    const form = root.querySelector<HTMLFormElement>('dialog form')!;
    const reason = form.querySelector<HTMLTextAreaElement>('textarea')!;
    reason.value = 'Compra duplicada'; reason.dispatchEvent(new Event('input'));
    fixture.detectChanges(); await fixture.whenStable();
    form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    await fixture.whenStable();
    expect(workflow.calls.allArgs().some((args) => args[0] === '/inventory/purchases/4/cancel')).toBeFalse();
    ask.and.resolveTo(true);
    form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    await fixture.whenStable(); fixture.detectChanges();
    expect(workflow).toHaveBeenCalledWith('/inventory/purchases/4/cancel', {reason: 'Compra duplicada'});
    expect(root.textContent).toContain('Anulada');
    expect(root.querySelector('[aria-label="Anular compra #4"]')).toBeNull();
    expect(root.querySelector('dialog')).toBeNull();
    fixture.destroy();
  });
  it('requires confirmation before deleting a unit and removes it from the refreshed list', async () => {
    const data = { units: [{id: 9, name: 'Bolsa', symbol: 'bl'}], items: [], suppliers: [], products: [], recipes: [], purchases: [], orders: {data: []}, movements: {data: []}, batches: {data: []} };
    const ask = jasmine.createSpy('ask').and.resolveTo(false);
    const workflow = jasmine.createSpy('workflow').and.callFake(async (path: string) => {
      if (path === '/inventory/units/9/delete') { data.units = []; return {deleted: true}; }
      return data;
    });
    TestBed.configureTestingModule({providers: [{provide: LocalOperationsApi, useValue: {workflow}}, {provide: RemovalConfirmation, useValue: {ask}}]});
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.componentRef.setInput('page', 'inventory-units');
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    root.querySelector<HTMLButtonElement>('[aria-label="Eliminar unidad Bolsa"]')!.click();
    await fixture.whenStable();
    expect(workflow.calls.count()).toBe(1);
    expect(ask.calls.mostRecent().args[1]).toContain('Bolsa');
    ask.and.resolveTo(true);
    root.querySelector<HTMLButtonElement>('[aria-label="Eliminar unidad Bolsa"]')!.click();
    await fixture.whenStable(); fixture.detectChanges();
    expect(workflow).toHaveBeenCalledWith('/inventory/units/9/delete', {});
    expect(root.querySelector('[aria-label="Eliminar unidad Bolsa"]')).toBeNull();
    expect(root.textContent).toContain('Unidad eliminada.');
    fixture.destroy();
  });
  it('shows saved recipe ingredients in a read-only modal without additional requests or stock changes', async () => {
    const ingredients = [
      { id: 1, name: 'Arroz', symbol: 'kg', stock_milli: 10000 },
      { id: 2, name: 'Aceite', symbol: 'l', stock_milli: 2000 },
    ];
    const recipe = { id: 7, product_key: 'carta-arroz', name: 'Plato de prueba', mode: 'direct', batch_yield: 10,
      components: [{ item_id: 1, quantity_milli: 250 }, { item_id: 2, quantity_milli: 25 }] };
    const workflow = jasmine.createSpy('workflow').and.resolveTo({ units: [], items: ingredients, suppliers: [], products: [],
      recipes: [recipe], purchases: [], orders: { data: [] }, movements: { data: [] }, batches: { data: [] } });
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.componentRef.setInput('page', 'inventory-production');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.textContent).not.toContain('Disponibilidad diaria');
    root.querySelector<HTMLButtonElement>('[aria-label="Ver detalle de receta de Plato de prueba"]')!.click();
    fixture.detectChanges();
    const dialog = root.querySelector<HTMLDialogElement>('dialog[open]')!;
    expect(dialog.textContent).toContain('Plato de prueba');
    expect(dialog.textContent).toContain('Platos por lote');
    const rows = dialog.querySelectorAll('tbody tr');
    expect(rows.length).toBe(2);
    expect(rows[0].textContent).toContain('Arroz');
    expect(rows[0].textContent).toMatch(/0[,.]25/);
    expect(rows[0].querySelectorAll('td')[1].textContent).toMatch(/2[,.]5/);
    expect(rows[0].textContent).toContain('kg');
    expect(rows[1].textContent).toContain('Aceite');
    expect(rows[1].textContent).toMatch(/0[,.]025/);
    expect(dialog.querySelector('input, select, textarea, form')).toBeNull();
    expect(workflow.calls.count()).toBe(1);
    expect(ingredients.map((item) => item.stock_milli)).toEqual([10000, 2000]);
    dialog.querySelector<HTMLButtonElement>('header button')!.click();
    fixture.detectChanges();
    expect(root.querySelector('dialog')).toBeNull();
    root.querySelector<HTMLButtonElement>('[aria-label="Editar receta de Plato de prueba"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(root.querySelector('dialog form')).not.toBeNull();
    fixture.destroy();
  });

  it('opens a purchase detail modal and lets the user retry a failed request', async () => {
    const data = { units: [], items: [], suppliers: [], products: [], recipes: [],
      purchases: [{ id: 1, supplier: 'Mercado', reference: 'Compra semanal', total_cents: 25000, created_at: '2026-11-20 21:24:00' }],
      orders: { data: [] }, movements: { data: [] }, batches: { data: [] } };
    const workflow = jasmine.createSpy('workflow').and.resolveTo(data);
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.componentRef.setInput('page', 'inventory-purchases');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    workflow.and.rejectWith(new Error('Conexión interrumpida.'));
    fixture.nativeElement.querySelector('[aria-label="Ver detalle de compra #1"]').click();
    await fixture.whenStable();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('dialog [role="alert"]')?.textContent).toContain('Conexión interrumpida.');
    workflow.and.resolveTo({ ...data.purchases[0], lines: [{ id: 1, name: 'Arroz', symbol: 'kg', quantity_milli: 20000, cost_cents: 25000 }] });
    Array.from(root.querySelectorAll<HTMLButtonElement>('dialog button')).find((button) => button.textContent?.trim() === 'Reintentar')!.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(workflow).toHaveBeenCalledWith('/inventory/purchases/1');
    expect(root.querySelector('dialog')?.textContent).toContain('20/11/2026 16:24');
    expect(root.querySelector('dialog tbody')?.textContent).toContain('Arroz');
    expect(root.querySelector('.purchase-total')?.textContent).toContain('S/ 250.00');
    root.querySelector<HTMLButtonElement>('dialog header button')!.click();
    fixture.detectChanges();
    expect(root.querySelector('dialog')).toBeNull();
    fixture.destroy();
  });

  it('does not reopen purchase details when a response arrives after closing the modal', async () => {
    const { workflow } = setup();
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.detectChanges();
    await fixture.whenStable();
    let resolve!: (value: unknown) => void;
    workflow.and.returnValue(new Promise((done) => { resolve = done; }));
    const request = fixture.componentInstance.openPurchaseDetail(1);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('dialog [role="status"]')).not.toBeNull();
    fixture.componentInstance.closePurchaseDetail();
    resolve({ id: 1, lines: [] });
    await request;
    fixture.detectChanges();
    expect(fixture.componentInstance.purchaseDetail()).toBeNull();
    expect(fixture.nativeElement.querySelector('dialog')).toBeNull();
    fixture.destroy();
  });

  function stockFormFixture() {
    const workflow = jasmine.createSpy('workflow').and.resolveTo({
      units: [], suppliers: [{ id: 1, name: 'Proveedor de prueba' }],
      items: [{ id: 1, name: 'Arroz', symbol: 'kg' }, { id: 2, name: 'Aceite', symbol: 'l' }],
      products: [{ item_key: 'carta-arroz', name: 'Plato de prueba', kind: 'carta' }],
      recipes: [], purchases: [], orders: { data: [] }, movements: { data: [] }, batches: { data: [] },
    });
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    return { fixture: TestBed.createComponent(OperationsInventory), workflow };
  }

  it('explains duplicate purchase items and clears the warning when the selection is corrected', async () => {
    const { fixture, workflow } = stockFormFixture();
    fixture.detectChanges();
    await fixture.whenStable();
    const inventory = fixture.componentInstance;
    inventory.purchase = { supplier_id: 1, reference: 'Compra de prueba', lines: [
      { item_id: 1, quantity: 10, cost: 10 }, { item_id: 1, quantity: 20, cost: 20 },
    ] };
    inventory.openEditor('purchases');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('#stock-duplicate-1')?.textContent).toContain('El insumo «Arroz» está repetido.');
    expect(root.querySelectorAll('select[aria-invalid="true"]').length).toBe(2);
    const submit = root.querySelector<HTMLButtonElement>('dialog form > button.ops-primary')!;
    expect(submit.disabled).toBeTrue();
    root.querySelector('dialog form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable();
    expect(workflow.calls.count()).toBe(1);

    const second = root.querySelectorAll<HTMLSelectElement>('select[aria-invalid="true"]')[1];
    second.value = Array.from(second.options).find((option) => option.textContent?.includes('Aceite'))!.value;
    second.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    fixture.detectChanges();
    expect(root.querySelector('#stock-duplicate-1')).toBeNull();
    expect(root.querySelector('select[aria-invalid="true"]')).toBeNull();
    expect(submit.disabled).toBeFalse();
    expect(inventory.purchase.lines.map((line) => line.cost)).toEqual([10, 20]);
    fixture.destroy();
  });

  it('shows the same named warning for duplicate recipe ingredients until the extra row is removed', async () => {
    const { fixture } = stockFormFixture();
    fixture.detectChanges();
    await fixture.whenStable();
    const inventory = fixture.componentInstance;
    inventory.recipe = { product_key: 'carta-arroz', mode: 'direct', batch_yield: 1, lines: [
      { item_id: 1, quantity: 1, cost: null }, { item_id: 1, quantity: 2, cost: null },
    ] };
    inventory.openEditor('recipes');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('#stock-duplicate-1')?.textContent).toContain('Arroz');
    expect(root.querySelector<HTMLButtonElement>('dialog form > button.ops-primary')!.disabled).toBeTrue();
    inventory.recipe.lines.splice(1, 1);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(root.querySelector('#stock-duplicate-1')).toBeNull();
    expect(root.querySelector<HTMLButtonElement>('dialog form > button.ops-primary')!.disabled).toBeFalse();
    fixture.destroy();
  });

  it('removes the supplier success banner after four seconds without removing the saved record', fakeAsync(() => {
    const savedSupplier = { id: 1, name: 'Proveedor de prueba', contact: '' };
    const workflow = jasmine.createSpy('workflow').and.resolveTo({
      units: [], items: [], suppliers: [savedSupplier], products: [], recipes: [], purchases: [],
      orders: { data: [] }, movements: { data: [] }, batches: { data: [] },
    });
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.componentRef.setInput('page', 'inventory-suppliers');
    fixture.detectChanges();
    flushMicrotasks();
    fixture.componentInstance.supplier = { id: undefined, name: savedSupplier.name, contact: '' };
    void fixture.componentInstance.saveMaster('suppliers');
    flushMicrotasks();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('.workflow-notice')?.textContent).toContain('Operación registrada.');
    tick(4000);
    fixture.detectChanges();
    expect(root.querySelector('.workflow-notice')).toBeNull();
    expect(root.querySelector('tbody')?.textContent).toContain(savedSupplier.name);
    fixture.destroy();
  }));

  it('displays the saved timestamps in purchases, movements and batches without date entry fields', async () => {
    const created_at = '2026-11-19 02:30:00';
    const workflow = jasmine.createSpy('workflow').and.resolveTo({
      units: [], items: [], suppliers: [], products: [], recipes: [],
      purchases: [{ id: 1, supplier: 'Proveedor de prueba', reference: null, total_cents: 1500, created_at }],
      movements: { data: [{ id: 1, type: 'compra', name: 'Arroz', quantity_milli: 1000, cost_cents: 1500, reason: null, created_at }], current_page: 1 },
      batches: { data: [{ id: 1, name: 'Preparación de prueba', portions: 10, remaining: 8, cost_cents: 1500, created_at }], current_page: 1 },
      orders: { data: [] },
    });
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.detectChanges();
    await fixture.whenStable();
    for (const page of ['inventory-purchases', 'inventory-movements']) {
      fixture.componentRef.setInput('page', page);
      fixture.detectChanges();
      const root: HTMLElement = fixture.nativeElement;
      expect(root.textContent).toContain('18/11/2026 21:30');
      expect(root.textContent).toContain('Hora de Lima');
      expect(root.querySelector('form input[type="date"], input[type="datetime-local"]')).toBeNull();
    }
    fixture.destroy();
  });

  function setup() {
    const data = { units: [{ id: 1, name: 'Kilogramo', symbol: 'kg' }], items: [], suppliers: [],
      products: [], recipes: [], purchases: [], orders: { data: [] }, movements: { data: [] }, batches: { data: [] } };
    const workflow = jasmine.createSpy('workflow').and.resolveTo(data);
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    return { workflow };
  }

  it('opens creation and editing from the list without inline registration forms', async () => {
    setup();
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('form')).toBeNull();
    const create = Array.from(root.querySelectorAll<HTMLButtonElement>('button'))
      .find((button) => button.textContent?.includes('Nuevo insumo'))!;
    create.click();
    fixture.detectChanges();
    expect(root.querySelector('dialog[open] input[name="name"]')).not.toBeNull();
    root.querySelector<HTMLButtonElement>('dialog header button')!.click();
    fixture.detectChanges();
    expect(root.querySelector('dialog')).toBeNull();
    fixture.componentRef.setInput('page', 'inventory-units'); fixture.detectChanges();
    root.querySelector<HTMLButtonElement>('[aria-label="Editar unidad Kilogramo"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(root.querySelector<HTMLInputElement>('dialog input[name="name"]')?.value).toBe('Kilogramo');
    fixture.destroy();
  });

  it('retains the modal and its input when saving fails', async () => {
    const { workflow } = setup();
    const fixture = TestBed.createComponent(OperationsInventory);
    fixture.detectChanges();
    await fixture.whenStable();
    const inventory = fixture.componentInstance;
    inventory.create('units');
    fixture.detectChanges();
    inventory.unit.name = 'Porción';
    inventory.unit.symbol = 'por';
    workflow.and.rejectWith(new Error('La unidad ya existe.'));
    await inventory.saveMaster('units');
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('dialog[open]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('dialog [role="alert"]').textContent).toContain('La unidad ya existe.');
    expect(inventory.unit.name).toBe('Porción');
    fixture.destroy();
  });
});
