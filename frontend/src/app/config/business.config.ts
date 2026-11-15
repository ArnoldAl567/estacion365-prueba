import { BUSINESS_CONFIG as RESTAURANT_CONFIG } from '../data/menu.data';
import { LOCAL_OPERATIONS_ENABLED } from './local-operations.config';

// Contacto de INTEGRATECH para las pruebas en esta computadora.
const LOCAL_TEST_CONTACT = {
  whatsapp: '51902586908',
  displayWhatsapp: '+51 902 586 908',
};

export const BUSINESS_CONFIG = {
  ...RESTAURANT_CONFIG,
  ...(LOCAL_OPERATIONS_ENABLED ? LOCAL_TEST_CONTACT : {}),
};
