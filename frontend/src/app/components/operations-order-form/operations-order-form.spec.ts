import { TestBed } from '@angular/core/testing';
import { INITIAL_CATALOG } from '../../data/catalog-seed';
import { DEFAULT_DAILY_PRICES } from '../../models/catalog.models';
import { StaffOrder } from '../../models/operations.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { OperationsOrderForm } from './operations-order-form';

describe('Order editor dialog', () => {
  const product = { ...INITIAL_CATALOG[0], active: true };
  const existing: StaffOrder = {
    id: 12, number: '0012', method: 'table', tableLabel: '4',
    customerName: '', customerPhone: '', payment: null, address: null, reference: null, items: [],
    trackingId: 'test-order', status: 'pending', subtotalCents: 0, packagingCents: 0, totalCents: 0,
    createdAt: '2026-11-14T15:00:00Z', updatedAt: '2026-11-14T15:00:00Z',
  };
  let saveStaffOrder: jasmine.Spy;

  beforeEach(() => {
    saveStaffOrder = jasmine.createSpy().and.resolveTo({ order: existing });
    TestBed.configureTestingModule({
      imports: [OperationsOrderForm],
      providers: [{
        provide: LocalOperationsApi,
        useValue: {
          catalog: () => Promise.resolve({ items: [product], prices: DEFAULT_DAILY_PRICES }),
          orderModifiers: () => Promise.resolve({ modifiers: [] }),
          saveStaffOrder,
        },
      }],
    });
  });

  async function open(order: StaffOrder | null = null) {
    const fixture = TestBed.createComponent(OperationsOrderForm);
    fixture.componentRef.setInput('order', order);
    const closed = jasmine.createSpy();
    fixture.componentInstance.closed.subscribe(closed);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const dialog = (fixture.nativeElement as HTMLElement).querySelector('dialog')!;
    const close = dialog.querySelector<HTMLButtonElement>('header button')!;
    return { fixture, dialog, close, closed };
  }

  function changeTable(dialog: HTMLDialogElement, value: string): void {
    const input = dialog.querySelector<HTMLInputElement>('[name="table"]')!;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  it('opens an existing order in a modal and closes it without a prompt if unchanged', async () => {
    const { dialog, close, closed } = await open(existing);
    expect(dialog.matches(':modal')).toBeTrue();
    expect(dialog.querySelector('h2')?.textContent).toContain('#0012');
    expect(document.activeElement).toBe(dialog.querySelector('h2'));
    close.click();
    expect(dialog.open).toBeFalse();
    expect(closed).toHaveBeenCalledTimes(1);
  });

  it('protects an unsaved order on Escape and lets staff continue or discard', async () => {
    const { fixture, dialog, close, closed } = await open();
    changeTable(dialog, 'Terraza');
    const escape = new Event('cancel', { cancelable: true });
    dialog.dispatchEvent(escape);
    fixture.detectChanges();
    expect(escape.defaultPrevented).toBeTrue();
    expect(dialog.open).toBeTrue();
    expect(closed).not.toHaveBeenCalled();
    const keepEditing = dialog.querySelector<HTMLButtonElement>('.discard-actions button')!;
    keepEditing.click();
    fixture.detectChanges();
    expect(dialog.querySelector<HTMLInputElement>('[name="table"]')?.value).toBe('Terraza');
    close.click();
    fixture.detectChanges();
    dialog.querySelector<HTMLButtonElement>('.discard')!.click();
    expect(dialog.open).toBeFalse();
    expect(closed).toHaveBeenCalledTimes(1);
  });

  it('submits the order using the button in the fixed footer', async () => {
    const { fixture, dialog } = await open();
    changeTable(dialog, '4');
    const editor = fixture.componentInstance;
    editor.productKey = product.item_key;
    editor.addLine();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const register = dialog.querySelector<HTMLButtonElement>('footer button[type="submit"]')!;
    expect(register.disabled).toBeFalse();
    register.click();
    await fixture.whenStable();
    expect(saveStaffOrder).toHaveBeenCalledWith(
      jasmine.objectContaining({
        customer: jasmine.objectContaining({ tableLabel: '4' }),
        items: [jasmine.objectContaining({ productId: product.source_id, quantity: 1 })],
      }),
      null,
      jasmine.any(String),
    );
    expect(dialog.open).toBeFalse();
  });
});
