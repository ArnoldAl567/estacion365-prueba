declare module 'qz-tray' {
  interface PrintConfig { getPrinter(): unknown }
  const client: {
    websocket: { isActive(): boolean; connect(): Promise<void> };
    configs: { create(printer: string, options: { units: 'mm'; size: { width: number } }): PrintConfig };
    print(config: PrintConfig, jobs: { type: 'pixel'; format: 'html'; flavor: 'plain'; data: string }[]): Promise<void>;
  };
  export default client;
}
