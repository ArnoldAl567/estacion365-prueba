import { Component, ViewEncapsulation } from '@angular/core';

@Component({
  selector: 'app-operations-theme',
  template: '<ng-content />',
  styleUrls: ['./operations-theme.scss', './operations-appearance.scss', './operations-workspaces.scss'],
  encapsulation: ViewEncapsulation.None,
})
export class OperationsTheme {}
