# La Estación 365 · Sistema local

Laravel 13 con PostgreSQL, integrado con la carta Angular en `/administrar`. Se ejecuta en esta computadora y no modifica Neon ni el despliegue público.

## Iniciar

Requiere Node.js, Composer, PHP 8.3 o superior con `pdo_pgsql` y PostgreSQL 17. Los ejecutables `php`, `composer` y `psql` deben estar en PATH. En esta computadora ya están instalados.

Desde la raíz, inicia el sistema con un solo comando. Este prepara PostgreSQL, inicia Laravel si hace falta y abre Angular:

```powershell
npm start
```

- Carta en <http://127.0.0.1:4201/>
- Gestión y cocina en <http://127.0.0.1:4201/administrar>
- API en `127.0.0.1:8001/local-api`, accesible desde Angular mediante `frontend/proxy.conf.json`.

El primer comando prepara una instancia independiente de PostgreSQL en `backend/.local-postgres`, escuchando únicamente en `127.0.0.1:55436`. Crea `estacion_operaciones` y `estacion_operaciones_test`, aplica migraciones e inserta los productos faltantes. No borra pedidos ni reemplaza productos existentes. La contraseña aleatoria queda en `backend/.env`, excluido de Git. No copies ese archivo al frontend.

Para preparar sin iniciar Laravel: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/local-api.ps1 -SetupOnly`.

Si PostgreSQL ya está activo, puedes iniciar solo Laravel desde esta carpeta con `php artisan serve --host=127.0.0.1 --port=8001`. `SERVER_PORT=8001` en `.env` establece el mismo puerto al usar `php artisan serve` sin opciones. Un servidor en el puerto 8000 no recibe las peticiones del frontend: eso provoca `ECONNREFUSED 127.0.0.1:8001`. Ante ese error, utiliza los dos comandos de inicio anteriores y conserva ambas terminales abiertas.

## Primer acceso y equipo

Al entrar por primera vez en `/administrar`, crea el administrador con nombre, usuario y una contraseña de al menos 10 caracteres. No hay contraseña predeterminada. La configuración inicial se cierra después de crear la primera cuenta.

El administrador puede crear cuentas de **Administrador** o **Cocina**, editar sus datos y desactivar accesos. Desactivar una cuenta o cambiar su contraseña revoca sus sesiones. Nadie puede quitarse su propio permiso de administrador. Las contraseñas se guardan con hash; Laravel valida las sesiones y protege los cambios con CSRF.

Cocina puede ver pedidos, comandas e historial y avanzar estados. No puede gestionar usuarios ni editar la carta. En local, `/panel-administrativo` abre el mismo sistema y lleva al editor de carta después del acceso. En el dominio publicado, esa ruta sigue correspondiendo al catálogo Neon.

## Flujo de atención

1. El cliente arma el carrito local. Cada ración del menú conserva su tipo y su entrada.
2. Completa contacto, modalidad y medio de pago. **Confirmar pedido** lo guarda en PostgreSQL y muestra su número y seguimiento.
3. En `/administrar`, aparece como **Pendiente**. Cocina muestra platos, entradas, cantidades e indicaciones.
4. El personal avanza: **Pendiente → Preparando → Listo → Entregado**. El historial conserva fecha, total y registro de cambios con su responsable.
5. El cliente ve los cambios en su comprobante en el mismo navegador. El último seguimiento se conserva al recargar; no es una cuenta de cliente ni un historial multidispositivo.

Las pantallas consultan novedades cada tres segundos mientras están visibles. **Activar sonido** habilita la campanilla y mantiene la consulta de pedidos al cambiar de pestaña. Mantén el panel abierto con la sesión iniciada. Si el navegador suspende la pestaña, los avisos pueden demorarse.

El servidor calcula precios y disponibilidad desde PostgreSQL. Los reintentos conservan un identificador para evitar duplicados. Si cambian los precios, se pide revisar el total. El pago (Yape o efectivo) es declarado por el cliente: no hay cobro ni verificación automática de Yape.

El sistema acepta pedidos **las 24 horas**, de lunes a domingo. El táper cuesta S/ 1 por plato para retiro y domicilio, sin cargo para bebidas o mesa. Laravel valida las cantidades. Para recuperar el horario anterior, consulta la [configuración del horario de pedidos](../README.md#horario-de-pedidos).

## Catálogo y datos

`database/data/catalog.json` contiene una copia inicial de los 156 registros del catálogo existente al preparar el sistema, incluidos activos y agotados. `LocalCatalogSeeder` inserta solo registros faltantes; no sincroniza cambios desde Neon. Algunas fotografías usan sus URL públicas originales: requieren conexión, pero pedidos y usuarios se guardan localmente.

En localhost, `MenuService` lee Laravel. En el dominio publicado sigue usando Neon y WhatsApp. La edición del catálogo publicado no actualiza automáticamente esta copia local.

En `/administrar`, entra a **Carta digital** para agregar y editar platos a la carta, fondos del día, entradas, entradas adicionales y guarniciones. La cuenta administradora del sistema sirve también para este editor, sin iniciar una segunda sesión. Puedes modificar nombres, fotografías, precios, categorías y disponibilidad, así como los importes globales de los menús Económico y Ejecutivo. Los cambios se guardan en PostgreSQL local y la carta abierta en otra pestaña del mismo navegador se actualiza automáticamente. También comprueba cambios al recuperar el foco.

El límite es **250 productos en total**, incluidos los agotados. Se comprueba en el panel, en Laravel y en PostgreSQL; editar o agotar platos sigue permitido con la carta llena. Los pedidos existentes conservan los datos y los precios que se confirmaron originalmente.

Las fotografías nuevas se optimizan a WebP (máximo 1200 píxeles por lado y 180 KB) y se guardan en `backend/storage/app/public/catalog`, sin enviarse a Neon ni a Vercel. La base de datos guarda solo su dirección local. Incluye esa carpeta en los respaldos junto con PostgreSQL. No se borran las fotografías anteriores al reemplazarlas.

No borres `.local-postgres` ni `.env`: contienen los datos y el acceso de esta instalación. Respalda con `pg_dump` antes de cambiar de equipo o reinstalar. `.local-postgres`, `.env`, `vendor` y los registros están excluidos de Git.

Detén Angular y Laravel con `Ctrl+C`. Para detener también PostgreSQL, desde la raíz:

```powershell
pg_ctl -D backend/.local-postgres -m fast stop
```

Esta entrega usa bucle local: todavía no recibe pedidos desde otras computadoras ni está preparada para publicarse sin configurar servidor, HTTPS y respaldos.

Para las pruebas, los enlaces de WhatsApp locales usan **INTEGRATECH: +51 902 586 908**, configurado en `frontend/src/app/config/business.config.ts`. El dominio publicado conserva el contacto del restaurante. Confirmar un pedido local lo registra en Laravel, sin enviarlo automáticamente por WhatsApp.

## Verificaciones

Desde la raíz:

```powershell
npm run build
npm test -- --watch=false --browsers=ChromeHeadless
```

Desde `backend`:

```powershell
php -d xdebug.mode=off artisan test
```

Las pruebas Laravel usan exclusivamente `estacion_operaciones_test`; una comprobación rechaza migraciones de prueba sobre otra base. Cubren precios, tápers, entradas por ración, reintentos, horarios, roles, estados concurrentes, historial y revocación de acceso.
