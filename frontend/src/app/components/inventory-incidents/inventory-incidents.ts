import {Component, inject, signal, OnInit} from '@angular/core';
import {FormsModule} from '@angular/forms';
import {DecimalPipe} from '@angular/common';
import {LocalOperationsApi} from '../../services/local-operations-api.service';
import {RemovalConfirmation} from '../../services/removal-confirmation.service';
import {createTransientNotice} from '../../services/transient-notice';
import {RecordDialog} from '../record-dialog/record-dialog';
import {OperationTimestampPipe} from '../../pipes/operation-timestamp.pipe';
interface Item {id:number;name:string;stock_milli:number;stock_value_cents:number;symbol:string;category_id:number|null}
interface Incident {id:number;kind:string;name:string;symbol:string;system_milli:number|null;counted_milli:number|null;delta_milli:number;cost_cents:number;state:string;reason:string;notes:string|null;created_at:string;author:string;created_by:number;applied_at:string|null;approver:string|null;cancelled_at:string|null;canceller:string|null;cancellation_reason:string|null}
interface View {items:Item[];categories:{id:number;name:string}[];records:{data:Incident[];current_page:number;next_page_url:string|null};summary:{pending:number;loss_cents:number;surplus_cents:number}}
@Component({selector:'app-inventory-incidents',imports:[FormsModule,DecimalPipe,RecordDialog,OperationTimestampPipe],templateUrl:'./inventory-incidents.html'})
export class InventoryIncidents implements OnInit {
  readonly api=inject(LocalOperationsApi); private readonly confirmation=inject(RemovalConfirmation);
  readonly data=signal<View|null>(null); readonly busy=signal(false); readonly error=signal('');
  private readonly success=createTransientNotice(); readonly notice=this.success.value;
  readonly editor=signal<'new'|'cancel'|null>(null); readonly detail=signal<Incident|null>(null);
  kind:'merma'|'desvio'='merma'; filters={from:'',to:'',state:'',item_id:'',category_id:''}; page=1;
  form={item_id:null as number|null,quantity:null as number|null,counted:null as number|null,incomingCost:null as number|null,reason:'',notes:''};
  snapshot:Item|null=null; selectedId=0; cancellationReason=''; private request={payload:'',id:''};
  money=(cents:number)=>'S/ '+(cents/100).toFixed(2);
  stateName=(state:string)=>({pending:'Pendiente',applied:'Aplicada',cancelled:'Anulada'}[state]??state);
  ngOnInit():void{void this.load();}
  async load():Promise<void>{if(this.busy())return;this.busy.set(true);this.error.set('');try{await this.read();}catch(e){this.error.set(e instanceof Error?e.message:'No se pudo cargar el módulo.');}finally{this.busy.set(false);}}
  private async read():Promise<void>{const search=new URLSearchParams({kind:this.kind,page:String(this.page)});for(const[k,v]of Object.entries(this.filters))if(v)search.set(k,v);this.data.set(await this.api.workflow<View>('/inventory/incidents?'+search));}
  changeKind(kind:'merma'|'desvio'):void{if(this.busy())return;this.kind=kind;this.page=1;void this.load();}
  filter():void{this.page=1;void this.load();}
  paginate(step:number):void{this.page=Math.max(1,this.page+step);void this.load();}
  async open():Promise<void>{if(this.busy())return;await this.load();if(this.error())return;this.form={item_id:null,quantity:null,counted:null,incomingCost:null,reason:'',notes:''};this.snapshot=null;this.request={payload:'',id:''};this.error.set('');this.editor.set('new');}
  selectItem():void{this.snapshot=this.data()?.items.find(i=>i.id===Number(this.form.item_id))??null;}
  difference():number{return this.kind==='merma'?-(this.form.quantity??0):(this.form.counted??0)-(this.snapshot?.stock_milli??0)/1000;}
  async save():Promise<void>{
    if(!this.snapshot||this.form.reason.trim().length<3)return;
    const body={kind:this.kind,item_id:this.snapshot.id,quantity_milli:this.kind==='merma'?Math.round((this.form.quantity??0)*1000):null,counted_milli:this.kind==='desvio'?Math.round((this.form.counted??0)*1000):null,
      incoming_cost_cents:this.form.incomingCost===null?null:Math.round(this.form.incomingCost*100),expected_stock_milli:this.snapshot.stock_milli,expected_stock_value_cents:this.snapshot.stock_value_cents,reason:this.form.reason.trim(),notes:this.form.notes.trim()};
    const json=JSON.stringify(body);if(json!==this.request.payload)this.request={payload:json,id:crypto.randomUUID()};
    await this.write('/inventory/incidents',{...body,request_id:this.request.id},'Registro pendiente guardado. El stock todavía no cambió.');
  }
  async apply(record:Incident):Promise<void>{if(this.busy()||!(await this.confirmation.ask('Aplicar ajuste','Se actualizará el stock de «'+record.name+'» una sola vez. Revisa la cantidad y el motivo.','Aplicar ajuste')))return;await this.write('/inventory/incidents/'+record.id+'/apply',{},'Ajuste aplicado y registrado en Movimientos.');}
  openCancellation(record:Incident):void{this.selectedId=record.id;this.cancellationReason='';this.error.set('');this.editor.set('cancel');}
  async cancel():Promise<void>{if(this.cancellationReason.trim().length<3||this.busy()||!(await this.confirmation.ask('Anular registro','Si el ajuste ya fue aplicado, se revertirá solo si no hubo movimientos posteriores. Se conservará todo el historial.','Anular registro')))return;await this.write('/inventory/incidents/'+this.selectedId+'/cancel',{reason:this.cancellationReason.trim()},'Registro anulado.');}
  private async write(path:string,body:object,message:string):Promise<void>{if(this.busy())return;this.busy.set(true);this.error.set('');try{await this.api.workflow(path,body);this.editor.set(null);this.success.show(message);await this.read();}catch(e){this.error.set(e instanceof Error?e.message:'No se pudo completar la operación.');}finally{this.busy.set(false);}}
}
