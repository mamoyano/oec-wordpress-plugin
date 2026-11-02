# Verificación de la app de Google — "Conectar con Google"

Proyecto de Google Cloud: **OEC Plugin Wordpress** (`oec-plugin-wordpress`), organización onlineeducation.center.
Permisos que pide la app: `openid`, `email` (no sensibles) y `https://www.googleapis.com/auth/gmail.send` (sensible).

Hasta que Google la apruebe: solo pueden conectarse las cuentas cargadas como usuarios de prueba, ven el
aviso de "app no verificada" y el permiso vence a los 7 días.

---

## Paso 1 — Lo que tiene que existir antes de pedir la verificación

1. **Dominio verificado** en [Google Search Console](https://search.google.com/search-console) con una cuenta
   que sea propietaria o editora del proyecto: `onlineeducation.center`.
2. **Página principal de la app**, en ese dominio, que explique para qué sirve y enlace a la política de
   privacidad. Sugerencia: `https://onlineeducation.center/plugin-wordpress/` (texto en el Paso 3).
3. **Política de privacidad** publicada en ese dominio (texto en el Paso 2). Sugerencia:
   `https://onlineeducation.center/privacidad-plugin-wordpress/` (o una sección nueva de la política actual,
   con un ancla propia).
4. **Logo** cuadrado de 120×120 px (PNG o JPG), el mismo que se usa en el sitio. Ojo: subir un logo hace que
   Google revise también la marca; si se quiere ir más rápido, se puede dejar sin logo y agregarlo después.

## Paso 2 — Política de privacidad (texto para publicar)

> Google pide que la política esté en el dominio verificado, que nombre la app tal como aparece en la
> pantalla de consentimiento y que diga qué datos de Google se usan, para qué, cómo se guardan, con quién
> se comparten y cómo se borran. La frase de "Limited Use" tiene que estar textual (en inglés está bien).

**Plugin de WordPress de Online Education Center — "Conectar con Google"**

*Última actualización: [fecha de publicación]*

Online Education Center ("OEC") ofrece a sus socios educativos un plugin de WordPress que muestra los
contactos interesados en sus formaciones y les permite escribirles. La función "Conectar con Google" permite
que esos emails salgan desde la cuenta de Gmail o Google Workspace del propio socio.

**Qué datos de Google usamos**
- **El email de tu cuenta de Google**, para mostrar en el plugin desde qué cuenta salen los emails y usarlo
  como remitente.
- **El permiso para enviar emails en tu nombre** (`gmail.send`). Lo usamos solo para enviar los emails que
  tú redactas y envías desde la pantalla de Estadísticas del plugin, a los contactos que tú eliges.

**Lo que no hacemos**
- No leemos, no buscamos, no modificamos ni borramos los emails de tu cuenta. El permiso que pedimos no lo
  permite.
- No guardamos el contenido de tus emails en servidores de OEC.
- No vendemos, no alquilamos ni compartimos tus datos de Google con terceros, ni los usamos para publicidad,
  para entrenar modelos de inteligencia artificial ni para ningún fin distinto de enviar los emails que tú
  decides enviar.

**Dónde se guardan**
- El permiso de Google (token de actualización) se guarda **cifrado en la base de datos de tu propio sitio
  de WordPress**, no en los servidores de OEC.
- Para obtener ese permiso y renovarlo, tu sitio se comunica con un servicio de OEC
  (`onlineeducation.center/connections/google/`), que hace el intercambio con Google. Ese servicio no guarda
  tus tokens: el resultado de la conexión se borra apenas tu sitio lo retira (como máximo a los 5 minutos).
- Cada envío usa un token de acceso temporal, que vence en una hora.
- Los emails enviados quedan en la carpeta "Enviados" de tu Gmail, como cualquier email que envías tú.

**Cómo revocar el acceso y borrar los datos**
- En tu sitio: OEC → Configuración → "Desconectar". El plugin revoca el permiso en Google y borra el token
  guardado.
- En cualquier momento, desde tu cuenta de Google: https://myaccount.google.com/permissions → "Online
  Education Center" → "Quitar acceso".
- Si desinstalas el plugin, puedes revocar el acceso desde tu cuenta de Google como se indica arriba.

**Uso limitado (Limited Use)**
Online Education Center's use and transfer to any other app of information received from Google APIs will
adhere to the [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy),
including the Limited Use requirements.

**Contacto**
Para consultas sobre esta política o sobre tus datos: [email de contacto de OEC].

## Paso 3 — Página principal de la app (texto corto)

**Plugin de WordPress de Online Education Center**

El plugin de Online Education Center permite a nuestros socios educativos mostrar sus formaciones en su
propio sitio de WordPress y hacer seguimiento de las personas interesadas: ver los contactos por etapa,
escribirles por WhatsApp o por email y registrar cada contacto.

Con **"Conectar con Google"**, los emails que el socio envía a sus contactos salen desde su propia cuenta de
Gmail o Google Workspace: con su nombre, quedan en su carpeta "Enviados" y las respuestas le llegan ahí. El
plugin solo pide permiso para **enviar** emails; no puede leer el correo.

[Política de privacidad del plugin](https://onlineeducation.center/privacidad-plugin-wordpress/)

## Paso 4 — Google Auth Platform: qué completar

**Desarrollo de la marca (Branding)**
| Campo | Valor |
|---|---|
| Nombre de la app | Online Education Center |
| Correo de asistencia | (un email de OEC que se lea) |
| Logotipo | 120×120 px (opcional, ver Paso 1) |
| Página principal | https://onlineeducation.center/plugin-wordpress/ |
| Política de privacidad | https://onlineeducation.center/privacidad-plugin-wordpress/ |
| Condiciones del servicio | (las condiciones generales de OEC, si existen) |
| Dominios autorizados | onlineeducation.center |
| Contacto del desarrollador | tu email |

**Acceso a los datos — justificación del permiso `gmail.send`** (en inglés, que es lo que lee el revisor):

> Online Education Center provides a WordPress plugin to its education partners (course organizers). In the
> plugin's Statistics screen, a partner sees the people who showed interest in their courses and can write
> them a follow-up email. With "Connect with Google", the email the partner composes is sent from the
> partner's own Gmail / Google Workspace account through the Gmail API, so it comes from their real address,
> stays in their Sent folder and replies reach them directly.
>
> We only need to send messages: the app never reads, searches, modifies or deletes the user's mailbox, so
> `gmail.send` is the narrowest scope that allows this feature. Broader scopes (gmail.compose, gmail.modify,
> mail.google.com) are not requested. Emails are only sent when the partner explicitly clicks "Send" for
> recipients they selected; each automated email includes an unsubscribe link. The refresh token is stored
> encrypted in the partner's own WordPress database, and the user can disconnect at any time from the plugin
> (which revokes the token) or from their Google Account permissions page.

**Cómo usa los permisos** (si el formulario pide un texto aparte):
> The scope is used exclusively to call `users.messages.send` with the message the user wrote, addressed to
> the contacts the user selected, at the moment the user clicks "Send from your Gmail".

## Paso 5 — Guion del video (1 a 2 minutos)

Google pide un video (YouTube, "No listado") que muestre el flujo completo de OAuth y el uso del permiso.
Requisitos que suelen hacer rechazar el pedido si faltan:
- Que se vea la **pantalla de consentimiento** de Google con el nombre "Online Education Center" y el permiso.
- Que se vea la **URL del navegador** en la pantalla de Google, con el `client_id` del proyecto.
- Que se vea **para qué se usa** el permiso (el envío) y el resultado (el email en "Enviados").
- Conviene grabarlo **con la cuenta de Google en inglés** (myaccount.google.com → Datos personales →
  Idioma), porque el revisor lee en inglés. La narración o los subtítulos también en inglés.

**Escenas**
1. *(0:00)* wp-admin de un sitio de prueba → OEC → Configuración → caja "Enviar emails desde tu Gmail".
   Texto/voz: "This is the Online Education Center WordPress plugin, used by our education partners."
2. *(0:10)* Clic en **"Conectar con Google"**. Se abre Google: elegir la cuenta. **Mostrar la barra de
   direcciones** (que se lea `accounts.google.com/...client_id=...`).
3. *(0:25)* Pantalla de consentimiento: detenerse 3–4 segundos en "Online Education Center" y en el permiso
   "Send email on your behalf". Clic en **Continuar/Allow**.
4. *(0:35)* De vuelta en el plugin: "Conectado: los emails salen desde …". Voz: "The plugin only stores an
   encrypted token in the partner's own site."
5. *(0:45)* Clic en **"Enviarme un email de prueba"** → mensaje de éxito.
6. *(0:55)* Ir a **Estadísticas** → abrir una etapa (por ejemplo "Prospectos calientes") → elegir 1 o 2
   contactos de prueba (direcciones propias) → "Enviar email" → redactar o usar la plantilla → mostrar la
   vista previa → **"Enviar desde tu Gmail"** → barra de progreso → "¡Listo!".
7. *(1:20)* Abrir Gmail → carpeta **Enviados** → el email enviado. Abrir la bandeja del destinatario de
   prueba y mostrar que llegó.
8. *(1:35)* Volver al plugin → **"Desconectar"**. Voz: "Users can disconnect at any time; the token is
   revoked and deleted."

**Importante para grabar**: usar contactos de prueba propios (no personas reales de Zoho), o una formación de
prueba. En Estadísticas los contactos son personas reales.

## Paso 6 — Enviar a verificación

La justificación del permiso NO se escribe en "Acceso a los datos" (ahí solo se declaran los permisos):
se pega en el formulario de verificación.

1. **Público → "Publicar app"**: pasa de "En prueba" a "En producción". Con la app en prueba, Google no
   pide (ni deja enviar) la verificación.
2. **Centro de verificación** → aparece que la app necesita verificación → "Preparar para la
   verificación" (o "Iniciar verificación"). El asistente repasa la marca y los permisos y pide, para
   `gmail.send`, la justificación (Paso 4) y el link del video (Paso 5) → enviar.

Google responde por email al contacto del desarrollador; suele pedir aclaraciones: responder en el mismo
hilo.

Mientras dura la revisión, la app "En producción" pero sin verificar muestra el aviso de "app no
verificada" y tiene un tope de 100 usuarios; no vence a los 7 días.

---

# Segunda ronda (respuesta de Google del 2026-11-02)

Google pidió: (1) que la política de privacidad diga cómo se protegen los datos, (2) un video nuevo y más
completo, y (3) un sitio y credenciales de prueba con instrucciones paso a paso. Se responde **por email, en
el mismo hilo**.

## A. Agregar a la política de privacidad — "Cómo protegemos tus datos"

Agregar esta sección (antes de "Cómo revocar el acceso"). Va también en inglés porque el revisor lee en
inglés: se puede publicar la versión en español y, debajo, la inglesa.

**Cómo protegemos tus datos**
- **Cifrado en tránsito.** Toda comunicación con Google, con el servicio de conexión de OEC y con tu sitio se
  hace por HTTPS (TLS). El plugin no acepta volver de Google a una dirección sin HTTPS.
- **Cifrado en reposo.** El permiso de Google (token de actualización) y el token de acceso temporal se
  guardan cifrados con AES-256-GCM, con una clave derivada de las claves secretas propias de tu instalación
  de WordPress. Nunca se guardan en texto plano.
- **Sin copias en servidores de OEC.** El servicio de conexión de OEC no guarda tus tokens: el resultado de
  la conexión se guarda un máximo de 5 minutos, bajo un código de un solo uso, en una carpeta sin acceso
  desde la web y con permisos restringidos al sistema, y se borra en cuanto tu sitio lo retira.
- **Credenciales de la app protegidas.** El secreto de cliente de Google de la app vive solo en el servidor
  de conexión de OEC; no se distribuye con el plugin ni llega a los sitios de los socios.
- **Protección contra solicitudes falsas.** Cada conexión usa un parámetro de estado firmado
  criptográficamente (HMAC-SHA256) que vence a los 15 minutos, y solo se vuelve a la pantalla de
  administración de WordPress que la inició.
- **Control de acceso.** Solo los administradores de tu sitio de WordPress pueden conectar la cuenta,
  enviar emails o ver los contactos; cada acción se verifica con la sesión y un código anti-falsificación
  (nonce) de WordPress.
- **Minimización.** Solo pedimos permiso para enviar emails y para conocer la dirección de la cuenta. No
  leemos tu correo. El historial de envíos (destinatario, fecha, estado y el texto de cada campaña) queda
  solo en la base de datos de tu sitio, para mostrarte a quién ya le escribiste.
- **Sin registros de credenciales.** Los tokens nunca se escriben en registros (logs) ni se muestran en
  pantalla.
- **Revocación inmediata.** Al tocar "Desconectar", el plugin revoca el permiso en Google y borra los
  tokens guardados.
- **Incidentes.** Si detectamos un acceso no autorizado que afecte datos de Google, revocaremos los
  permisos afectados y avisaremos a los usuarios afectados sin demoras indebidas.

**How we protect your data**
- **Encryption in transit.** All communication with Google, with OEC's connection service and with your
  site uses HTTPS (TLS). The plugin refuses to return from Google to a non-HTTPS address.
- **Encryption at rest.** The Google refresh token and the short-lived access token are stored encrypted
  with AES-256-GCM, using a key derived from your WordPress installation's own secret keys. They are never
  stored in plain text.
- **No copies on OEC servers.** OEC's connection service does not keep your tokens: the result of a
  connection is held for at most 5 minutes under a single-use code, in a folder with no web access and
  system-restricted permissions, and is deleted as soon as your site retrieves it.
- **App credentials protected.** The app's Google client secret exists only on OEC's connection server;
  it is not distributed with the plugin and never reaches partner sites.
- **Request forgery protection.** Each connection uses a cryptographically signed state parameter
  (HMAC-SHA256) that expires after 15 minutes, and only returns to the WordPress admin screen that started it.
- **Access control.** Only administrators of your WordPress site can connect the account, send emails or
  see contacts; every action is checked against the WordPress session and an anti-forgery token (nonce).
- **Data minimization.** We only request permission to send email and to know the account's address. We
  do not read your mail. The sending history (recipient, date, status and each campaign's text) is kept
  only in your site's database, to show you whom you have already contacted.
- **No credential logging.** Tokens are never written to logs or shown on screen.
- **Immediate revocation.** "Disconnect" revokes the permission at Google and deletes the stored tokens.
- **Incidents.** If we detect unauthorized access affecting Google user data, we will revoke the affected
  permissions and notify affected users without undue delay.

## B. Que los permisos coincidan ("Scope Matching")

La app pide `openid`, `email` y `gmail.send`, pero en **Acceso a los datos** solo está declarado
`gmail.send`. Antes de grabar: Acceso a los datos → "Agregar o quitar permisos" → tildar `openid` y
`.../auth/userinfo.email` → Actualizar → Save. Quedan 2 no sensibles + 1 sensible, iguales a los que pide
la app.

## C. Sitio de prueba para el revisor — biokinetics.site

1. Plugin actualizado (≥ 1.5.7) y con su token de OEC (ya lo tiene).
2. Subir `server/google/oec-review-demo.php` a `wp-content/mu-plugins/` (crear la carpeta si no existe).
   **Solo actúa para el usuario `google-review`**: para él, Estadísticas muestra 6 personas ficticias cuyos
   emails son `mamoyano+ana@onlineeducation.center`, `mamoyano+bruno@…` (te llegan a vos), sin llamar a
   Zoho, y aparece un aviso de "Sitio de demostración". Los demás administradores siguen viendo lo real.
   Cambiar las casillas: `define('OEC_REVIEW_INBOX', '…')` en `wp-config.php`.
3. Usuarios → Añadir nuevo: `google-review`, rol **Administrador**, contraseña fuerte, sin 2FA, captcha ni
   bloqueo por país en el login (revisar plugins de seguridad tipo Wordfence / reCAPTCHA).
4. La conexión con Google es UNA por sitio: cuando el revisor conecte su cuenta, reemplaza la que haya, y
   lo que se envíe mientras tanto sale desde la suya. Si BioKinetics usa "Enviar desde tu Gmail", avisar o
   reconectar después de la aprobación.
5. Cuando Google apruebe: borrar el usuario `google-review` y el mu-plugin.

## D. Video nuevo (en inglés, 2–3 minutos, YouTube "No listado")

Grabar en el sitio de prueba, con la cuenta de Google en inglés. Lo que pidió Google, explícitamente:
- **Pantalla de consentimiento completa**: si aparece "Show all"/"Ver todo", tocarlo; desplegar cada permiso
  (tocar sobre él) para que se lea su descripción. Detenerse 3–4 segundos.
- **Impacto en la cuenta de Google**: mostrar en Gmail → **Enviados** cada email que mandó la app.
- **Máximo uso de la función**: email de prueba + envío a varios contactos.

Escenas:
1. URL del sitio de prueba, wp-admin → OEC → Configuración → "Send emails from your Gmail". Voz: what the
   plugin is and that partners use it to follow up with people interested in their courses.
2. "Connect with Google" → elegir cuenta → **mostrar la barra de direcciones** con `client_id=…` y
   `scope=openid email https://www.googleapis.com/auth/gmail.send`.
3. Pantalla de consentimiento: "Show all", desplegar "Send email on your behalf", tildar/aceptar. Voz: "This
   is the only Gmail permission we request; there is no narrower scope that allows sending email, and the
   app never reads, modifies or deletes mail."
4. De vuelta: "Connected: emails are sent from …". "Send me a test email" → éxito.
5. Abrir Gmail en otra pestaña → **Sent** → el email de prueba.
6. Estadísticas → una formación → "Hot prospects" (3) → los 3 elegidos → "Send email" → plantilla, variables,
   vista previa de cada persona → "Send from your Gmail (3)" → barra de progreso → "Done".
7. Gmail → **Sent**: los 3 emails, abrir uno (destinatario, texto personalizado, link de baja al pie). Abrir
   la casilla de destino y mostrar que llegaron.
8. Volver al plugin: la columna de envíos muestra la fecha. Voz: "Only emails the administrator explicitly
   sends are delivered; nothing is sent automatically."
9. "Disconnect" → abrir https://myaccount.google.com/permissions y mostrar que "Online Education Center" ya
   no tiene acceso. Voz: "Disconnecting revokes the token at Google and deletes it from the site."

## E. Respuesta a Google (pegar en el mismo hilo, completando los datos)

> Hello,
>
> Thank you for the review. We have addressed each item:
>
> **1. Privacy policy – data protection.** Our privacy policy now includes a "How we protect your data"
> section (encryption in transit and at rest, no token storage on our servers, protection of app
> credentials, signed OAuth state, access control, data minimization, no credential logging, revocation and
> incident handling): [URL de la política]
>
> **2. Scopes.** The scopes configured in Cloud Console now exactly match those requested by the app:
> `openid`, `https://www.googleapis.com/auth/userinfo.email` and `https://www.googleapis.com/auth/gmail.send`.
>
> **3. New demo video:** [link de YouTube]. It shows the full OAuth flow with the consent screen and all
> scopes expanded, every feature that uses gmail.send (test email and sending to selected contacts), the
> resulting messages in the Gmail "Sent" folder of the connected account, and disconnection (token revoked,
> app removed from the Google Account permissions page). gmail.send is the narrowest scope that allows
> sending email; the app has no feature that reads, modifies or deletes mail.
>
> **4. Test environment.** The app is a WordPress plugin; we set up a test site for you:
> - URL: https://biokinetics.site/wp-admin/
> - Username: google-review  Password: [contraseña]
> - No phone verification, 2FA or payment is required.
>
> Steps:
> 1. Log in at the URL above.
> 2. Go to **OEC → Configuración** (Settings) and click **"Conectar con Google"** (Connect with Google). Sign
>    in with any Google account and accept the permission.
> 3. Click **"Enviarme un email de prueba"** (Send me a test email): a message is sent to the connected
>    account; check its Gmail "Sent" folder.
> 4. Go to **OEC → Estadísticas** (Statistics). In any course row, click the number under **"Prospectos
>    calientes"** (Hot prospects). A window lists 3 fictitious contacts (their addresses are our own test
>    inboxes, so it is safe to send).
> 5. Click **"Enviar email"** (Send email), review the message and the preview, and click **"Enviar desde tu
>    Gmail (3)"** (Send from your Gmail). The progress bar shows the sending; the 3 messages appear in the
>    connected account's "Sent" folder.
> 6. Back in **OEC → Configuración**, click **"Desconectar"** (Disconnect) to revoke access.
>
> Best regards,
> [nombre] — Online Education Center
