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
