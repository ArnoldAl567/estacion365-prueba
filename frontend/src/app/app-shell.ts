import { Component } from '@angular/core';
import { App } from './app';
import { AdminPanel } from './components/admin-panel/admin-panel';
import { OperationsPanel } from './components/operations-panel/operations-panel';
import { LOCAL_OPERATIONS_ENABLED } from './config/local-operations.config';

@Component({
  selector: 'app-root',
  imports: [App, AdminPanel, OperationsPanel],
  template: `
    @if (isOperationsRoute) {
      @defer (on immediate) { <app-operations-panel /> }
      @placeholder { <p style="padding:40px;text-align:center">Cargando el sistema de pedidos…</p> }
    }
    @else if (isAdminRoute) { <app-admin-panel /> }
    @else { <app-menu /> }
  `,
})
export class AppShell {
  readonly isAdminRoute = window.location.pathname.replace(/\/$/, '') === '/panel-administrativo';
  readonly isOperationsRoute = /^\/administrar(?:\/|$)/.test(window.location.pathname)
    || (LOCAL_OPERATIONS_ENABLED && this.isAdminRoute);
}
