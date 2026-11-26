import {TestBed} from '@angular/core/testing';
import {InventoryIncidents} from './inventory-incidents';
import {LocalOperationsApi} from '../../services/local-operations-api.service';
import {RemovalConfirmation} from '../../services/removal-confirmation.service';
describe('Inventory incidents',()=>{
  it('saves a loss as pending and asks for confirmation before applying it',async()=>{
    const item={id:1,name:'Arroz',stock_milli:10000,stock_value_cents:5000,symbol:'kg',category_id:null};
    const data={items:[item],categories:[],records:{data:[],current_page:1,next_page_url:null},summary:{pending:0,loss_cents:0,surplus_cents:0}};
    const workflow=jasmine.createSpy('workflow').and.callFake(async(_path:string,body?:object)=>body?{id:1}:data);
    const ask=jasmine.createSpy('ask').and.resolveTo(false);
    TestBed.configureTestingModule({providers:[{provide:LocalOperationsApi,useValue:{workflow,user:()=>({id:2,role:'admin'})}},{provide:RemovalConfirmation,useValue:{ask}}]});
    const fixture=TestBed.createComponent(InventoryIncidents);fixture.detectChanges();await fixture.whenStable();fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('dialog')).toBeNull();
    await fixture.componentInstance.open();fixture.detectChanges();expect(fixture.nativeElement.querySelector('dialog[open]')).not.toBeNull();
    fixture.componentInstance.form={item_id:1,quantity:1,counted:null,incomingCost:null,reason:'Derrame',notes:''};fixture.componentInstance.selectItem();
    await fixture.componentInstance.save();fixture.detectChanges();
    expect(workflow).toHaveBeenCalledWith('/inventory/incidents',jasmine.objectContaining({kind:'merma',quantity_milli:1000,expected_stock_milli:10000}));
    expect(item.stock_milli).toBe(10000);
    const record={id:1,name:'Arroz'} as Parameters<InventoryIncidents['apply']>[0];
    await fixture.componentInstance.apply(record);expect(workflow.calls.allArgs().some(a=>a[0].endsWith('/apply'))).toBeFalse();
    ask.and.resolveTo(true);await fixture.componentInstance.apply(record);expect(workflow).toHaveBeenCalledWith('/inventory/incidents/1/apply',{});
    fixture.destroy();
  });
});
