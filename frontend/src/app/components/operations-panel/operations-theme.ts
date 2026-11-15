import { Component, ViewEncapsulation } from '@angular/core';

@Component({
  selector: 'app-operations-theme',
  template: '<ng-content />',
  styleUrl: './operations-theme.scss',
  encapsulation: ViewEncapsulation.None,
})
export class OperationsTheme {}
