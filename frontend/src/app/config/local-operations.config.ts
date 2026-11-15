// Las operaciones se conectan únicamente al servidor local durante esta etapa.
export const LOCAL_OPERATIONS_ENABLED = ['localhost', '127.0.0.1', '[::1]'].includes(
  window.location.hostname,
);
