
                <!-- Print-only: Cover Page -->
                <div class="print-cover">
                    <img class="cover-logo" src="<?php echo e($logoUrl); ?>" alt="Logo">
                    <div class="cover-rule"></div>
                    <h1>Documentación de la Plataforma</h1>
                    <div class="cover-edition">Gobernanza, Riesgo &amp; Cumplimiento &bull; Gestión de Riesgos de Terceros</div>
                    <div class="cover-version">Versión 2.6.2</div>
                    <div class="cover-rule-bottom"></div>
                    <div class="cover-meta">
                        <strong>Fecha:</strong> <?php echo date('F j, Y'); ?><br>
                        <strong>Clasificación:</strong> Solo para uso interno<br>
                        <strong>Preparado por:</strong> Equipo de Administración GRC
                    </div>
                </div>

                <!-- Print-only: Introduction & Purpose -->
                <div class="print-intro">
                    <h2>Introducción</h2>

                    <h3>Propósito</h3>
                    <p>Este documento proporciona documentación completa para la Plataforma Fair TPRM &amp; GRC. Sirve tanto como guía de usuario como manual de referencia para todo el personal involucrado en la gestión de riesgos de terceros, gobernanza, evaluación de riesgos y operaciones de cumplimiento.</p>
                    <p>El público destinatario incluye analistas de GRC, responsables de cumplimiento, auditores, personal de seguridad de TI, equipos de compras y administradores de sistemas. Ya sea que esté realizando su primera evaluación de cumplimiento o gestionando un programa de auditoría continuo, esta guía proporciona las instrucciones paso a paso que necesita.</p>

                    <h3>Alcance</h3>
                    <p>Esta documentación cubre los siguientes módulos y capacidades de la plataforma:</p>
                    <ul>
                        <li><strong>Módulo GRC</strong> &mdash; Evaluaciones de cumplimiento unificadas en múltiples marcos (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls, NIST 800-171), gestión de controles internos, recopilación de evidencias, gestión del ciclo de vida de políticas, gestión de auditorías, registro de riesgos, monitoreo continuo y puntuación de madurez</li>
                        <li><strong>Módulo TPRM</strong> &mdash; Incorporación de proveedores externos, clasificación de riesgos, evaluaciones de seguridad, análisis cuantitativo de riesgos (FAIR), puntuación de seguridad externa, seguimiento de riesgos de cuarto nivel y descubrimiento de Shadow SaaS</li>
                        <li><strong>Portal de Administración</strong> &mdash; Configuración del sistema, gestión de usuarios y grupos, identidad de marca, configuración de correo electrónico, integración SSO/SAML y configuración de plataforma de IA</li>
                    </ul>

                    <h3>Cómo usar esta guía</h3>
                    <p>Esta guía está organizada en cuatro partes. <strong>Parte 1 (Primeros Pasos)</strong> cubre la navegación de la plataforma, los roles de usuario y su primer inicio de sesión. <strong>Parte 2 (Módulo GRC)</strong> proporciona un recorrido detallado del proceso de evaluación de cumplimiento, comenzando por crear su primera evaluación y avanzando a través de la recopilación de evidencias, puntuación y generación de informes. <strong>Parte 3 (Módulo TPRM)</strong> cubre la gestión de riesgos de proveedores. <strong>Parte 4 (Portal de Administración)</strong> cubre la administración del sistema.</p>
                    <p>Si es nuevo en la plataforma, comience por la sección <em>Primeros Pasos</em> y luego siga la Guía de Inicio Rápido de GRC de cinco pasos. Cada paso incluye instrucciones exactas, clic a clic.</p>

                    <h3>Convenciones del documento</h3>
                    <p>A lo largo de este documento se utilizan las siguientes convenciones:</p>
                    <ul>
                        <li><strong>Texto en negrita</strong> indica conceptos importantes o énfasis</li>
                        <li><code>Formato de código</code> indica valores que usted escribe o referencias generadas por el sistema</li>
                        <li>Las listas de pasos numerados indican procedimientos secuenciales que se deben seguir en orden</li>
                        <li>Los cuadros de llamada proporcionan sugerencias, advertencias e información importante de contexto</li>
                    </ul>
                </div>

                <!-- Print-only: Table of Contents (populated by JS) -->
                <div class="print-toc">
                    <h2>Tabla de Contenidos</h2>
                    <div id="tocBody"></div>
                </div>

                <!-- Main content body -->
                <div class="doc-body">

                <h1>Documentación de la Plataforma</h1>
                <p class="doc-subtitle">Fair TPRM &amp; GRC Platform &mdash; Version 2.6.2 &mdash; Última actualización: <?php echo date('F j, Y'); ?></p>

                <div style="margin-bottom:20px;">
                    <button class="btn-doc" id="printBtn">Descargar PDF</button>
                </div>

<!-- old doc-toc removed — print-toc above now serves as the on-screen TOC -->


<!-- ================================================================
     GETTING STARTED
     ================================================================ -->
<div class="doc-section" id="overview">
    <h2>Descripción General de la Plataforma</h2>
    <p>Esta plataforma proporciona dos módulos integrados para gestionar la postura de seguridad de su organización:</p>
    <ul>
        <li><strong>TPRM (Gestión de Riesgos de Terceros)</strong> &mdash; Rastree, evalúe y puntúe a sus proveedores y suministradores. Comprenda el riesgo de seguridad que cada proveedor representa para su organización.</li>
        <li><strong>GRC (Gobernanza, Riesgo &amp; Cumplimiento)</strong> &mdash; Gestione marcos de cumplimiento (SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls y más), responda un único cuestionario unificado que cubre todos los marcos simultáneamente, realice seguimiento de controles internos, cargue evidencias, gestione políticas y ejecute auditorías.</li>
    </ul>
    <p>Los administradores también tienen acceso al <strong>Portal de Administración</strong> para la configuración del sistema, gestión de usuarios, integraciones y mantenimiento.</p>

    <div class="callout callout-success">
        <strong>Concepto Clave &mdash; Una Evaluación, Muchos Marcos:</strong> El módulo GRC utiliza un <em>cuestionario de evaluación unificado</em> con 146 preguntas en 14 dominios de seguridad. Cuando responde estas preguntas una vez, la plataforma calcula automáticamente su porcentaje de cumplimiento frente a cada marco compatible (SOC 2, ISO 27001, PCI DSS, etc.) &mdash; sin trabajo duplicado.
    </div>
</div>

<div class="doc-section" id="navigation">
    <h2>Navegación por la Barra Lateral</h2>
    <p>La barra lateral izquierda es su herramienta de navegación principal. Está organizada en módulos y secciones contraíbles:</p>
    <ol class="steps">
        <li>En la parte superior de la barra lateral verá el logotipo y el texto de marca de su empresa.</li>
        <li>Debajo hay dos encabezados de módulo contraíbles: <span class="menu-label">Módulo TPRM</span> y <span class="menu-label">Módulo GRC</span>. Haga clic en cualquiera de los encabezados para expandirlo o contraerlo. Su navegador recuerda qué módulos están abiertos.</li>
        <li>Dentro de cada módulo hay <strong>secciones</strong> contraíbles (p. ej., "Cumplimiento", "Evidencia y Monitoreo", "Evaluación y Auditoría"). Haga clic en el título de una sección para expandirla y ver los enlaces de navegación dentro.</li>
        <li>En la parte inferior de la barra lateral encontrará enlaces de utilidad: <span class="menu-label">Panel</span>, <span class="menu-label">Perfil</span>, <span class="menu-label">Documentación</span> (esta página) y <span class="menu-label">Administración</span> (solo administradores).</li>
    </ol>

    <h3>Estructura de la Barra Lateral del Módulo GRC</h3>
    <p>Cuando expanda <span class="menu-label">Módulo GRC</span>, verá estas secciones:</p>
    <table class="doc-table">
        <tr><th>Sección</th><th>Páginas Incluidas</th><th>Qué Contiene</th></tr>
        <tr><td><strong>Cumplimiento</strong></td><td>Panel GRC, Marcos, Controles Internos, Mapeo Cruzado de Marcos</td><td>Resumen de su postura de cumplimiento, gestión de marcos, biblioteca de controles y mapeo entre marcos</td></tr>
        <tr><td><strong>Evidencia y Monitoreo</strong></td><td>Biblioteca de Evidencias, Monitores Continuos</td><td>Cargue y gestione evidencias de cumplimiento; configure verificaciones automáticas de cumplimiento</td></tr>
        <tr><td><strong>Gestión de Políticas</strong></td><td>Políticas</td><td>Cree, versione, apruebe y publique políticas organizacionales</td></tr>
        <tr><td><strong>Evaluación y Auditoría</strong></td><td>Puntuación de Madurez CSF, Cuestionario de Evaluación, Bandeja de Entrada de Tareas, Auditorías, Hallazgos, Registro de Riesgos</td><td>El cuestionario de evaluación unificado, paneles de madurez, auditorías y seguimiento de riesgos</td></tr>
    </table>
</div>

<div class="doc-section" id="roles">
    <h2>Roles y Permisos de Usuario</h2>
    <p>Los usuarios son asignados a uno o más <strong>Grupos ACL</strong> que determinan qué pueden ver y hacer. Un administrador asigna grupos a través de <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Usuarios</span> &rarr; botón <span class="btn-label">Grupos</span>.</p>
    <table class="doc-table">
        <tr><th>Grupo</th><th>Qué Puede Hacer</th></tr>
        <tr><td><strong>Administrador</strong></td><td>Acceso completo a todo &mdash; todos los módulos, configuración de administración, gestión de usuarios y configuración del sistema</td></tr>
        <tr><td><strong>Cyber TPRM</strong></td><td>Acceso completo al módulo TPRM &mdash; crear/editar/eliminar proveedores, ejecutar evaluaciones, análisis FAIR, puntuación</td></tr>
        <tr><td><strong>Cyber GRC</strong></td><td>Acceso completo al módulo GRC &mdash; gestionar marcos, ejecutar evaluaciones, cargar evidencias, gestionar políticas, ejecutar auditorías, gestionar riesgos</td></tr>
        <tr><td><strong>GRC Contributors</strong></td><td>Acceso limitado a GRC &mdash; completar tareas asignadas, proporcionar evidencias, responder preguntas de evaluación asignadas</td></tr>
        <tr><td><strong>Auditor</strong></td><td><strong>Acceso de solo lectura</strong> a los módulos TPRM y GRC &mdash; puede ver todo, descargar evidencias y generar informes, pero no puede crear, editar ni eliminar</td></tr>
        <tr><td><strong>Procurement</strong></td><td>Crear y gestionar solicitudes de incorporación de proveedores, cargar documentos de proveedores</td></tr>
        <tr><td><strong>Stakeholder</strong></td><td>Ver sus propias solicitudes de proveedores y responder a tareas que les hayan sido asignadas</td></tr>
    </table>

    <div class="callout callout-info">
        <strong>Para ver el módulo GRC en la barra lateral:</strong> Debe pertenecer al grupo <strong>Administrador</strong>, <strong>Cyber GRC</strong> o <strong>Auditor</strong>. Si no ve el Módulo GRC en la barra lateral, pida a su administrador que lo agregue a uno de estos grupos.
    </div>
</div>

<div class="doc-section" id="first-login">
    <h2>Su Primer Inicio de Sesión</h2>
    <ol class="steps">
        <li>Abra su navegador web y navegue a la URL de su plataforma (p. ej., <code>https://tprm.yourcompany.com</code>).</li>
        <li>Ingrese su <span class="field-label">Nombre de usuario</span> y <span class="field-label">Contraseña</span> proporcionados por su administrador.</li>
        <li>Si la autenticación de dos factores (TOTP) está habilitada para su cuenta, abra su aplicación de autenticación (Google Authenticator, Microsoft Authenticator, etc.) e ingrese el código de 6 dígitos cuando se le solicite.</li>
        <li>Llegará al <strong>Panel</strong>. La barra superior muestra "Bienvenido, [Su Nombre]" con enlaces a Admin (si es administrador), Perfil y Cerrar sesión.</li>
        <li>Observe la barra lateral izquierda. Si pertenece al grupo <strong>Cyber GRC</strong> o <strong>Administrador</strong>, verá <span class="menu-label">Módulo GRC</span> en la barra lateral. Haga clic para expandir la navegación GRC.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/dashboard.png" alt="The platform dashboard after logging in" loading="lazy">
        <figcaption><strong>El Panel.</strong> Después de iniciar sesión llegará aquí. La barra superior (parte superior derecha) tiene <strong>Admin</strong>, <strong>Perfil</strong> y <strong>Cerrar sesión</strong>. La barra lateral izquierda es su menú principal.</figcaption>
    </figure>
</div>


<!-- ================================================================
     WHAT'S NEW IN 2.6.2
     ================================================================ -->
<div class="doc-section" id="whats-new">
    <h2>Novedades en la Versión 2.6.2</h2>
    <p>La versión 2.6.2 agrega varias funciones centradas en la <strong>incorporación de proveedores, colaboración en compras, soporte multilingüe y descubrimiento de Shadow SaaS</strong>. Si ha usado una versión anterior, esto es lo que hay de nuevo. Cada elemento enlaza con su recorrido completo más adelante en esta guía.</p>
    <table class="doc-table">
        <tr><th>Nueva Función</th><th>Qué Hace</th><th>Para Quién Es</th></tr>
        <tr><td><strong><a href="#language">Configuración de idioma</a></strong></td><td>Use la plataforma en 8 idiomas. Cada persona elige su propio idioma; los administradores deciden qué idiomas están disponibles.</td><td>Todos</td></tr>
        <tr><td><strong><a href="#onboarding-workflow">Incorporación de Compras y ID de Proveedor</a></strong></td><td>Un proveedor debe ser incorporado a través de compras y tener un ID de Proveedor (VID) válido antes de poder enviarse a revisión de ciberseguridad.</td><td>Compras, Partes Interesadas</td></tr>
        <tr><td><strong><a href="#ai-review">Revisión de IA para proveedores</a></strong></td><td>Un estado de revisión dedicado para proveedores cuyos servicios usan IA, además de una acción "Forzar Revisión de IA".</td><td>Cyber TPRM, Administradores</td></tr>
        <tr><td><strong><a href="#procurement-cyber-status">Estado Cibernético de Compras</a></strong></td><td>Una página en vivo que muestra proveedores en revisión, con un historial de actualizaciones que el equipo de ciberseguridad comparte con compras, más un resumen semanal por correo electrónico.</td><td>Compras, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#shadow-saas-grip">Integración Grip Shadow SaaS</a></strong></td><td>Descubra automáticamente aplicaciones SaaS utilizadas en toda su organización e impórtelas a la lista de Shadow SaaS.</td><td>Administradores</td></tr>
        <tr><td><strong><a href="#shadow-saas-hero">Integración Hero Shadow SaaS</a></strong></td><td>Un proveedor alternativo de Shadow SaaS: descubra proveedores y problemas de seguridad desde HERO Security e impórtelos a la misma lista de Shadow SaaS. Grip y Hero son mutuamente excluyentes &mdash; use uno u otro.</td><td>Administradores</td></tr>
        <tr><td><strong><a href="#zscaler">Bloqueo con Zscaler</a></strong></td><td>Bloquee el dominio web de una aplicación no autorizada directamente en Zscaler con un clic.</td><td>Administradores</td></tr>
        <tr><td><strong><a href="#admin-updates">Actualizaciones dentro de la aplicación</a></strong></td><td>Verifique si hay una versión más nueva en su registro y actualice desde el Portal de Administración.</td><td>Administradores</td></tr>
        <tr><td><strong><a href="#question-types">Tipos de pregunta de teléfono y VAT</a></strong></td><td>Nuevos tipos de campo de evaluación/incorporación: un número de teléfono con selector de código de país y bandera (con formato automático), y un número de IVA de la UE con doble entrada y validación en vivo contra el servicio oficial EU VIES.</td><td>Todos</td></tr>
        <tr><td><strong><a href="#question-types">Datos de proveedor y mejoras en la búsqueda</a></strong></td><td>Almacene un número de IVA en cada proveedor (mostrado en la página del proveedor con un acceso directo "Agregar IVA"), encuentre proveedores por número de IVA en la búsqueda rápida y un banner de puntuación de Incorporación de Compras más claro.</td><td>Compras, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#admin-backup">Copias de seguridad de bases de datos grandes</a></strong></td><td>Las copias de seguridad y restauración ahora admiten bases de datos de varios gigabytes y registros muy grandes sin tiempos de espera.</td><td>Administradores</td></tr>
        <tr><td><strong><a href="#assessment-forms">Formularios de evaluación y Autorrelleno con IA</a></strong></td><td>Descargue una evaluación como PDF rellenable o libro de Excel, vuelva a importar un archivo completado y &mdash; con un proveedor de IA &mdash; rellene automáticamente las respuestas a partir de los certificados actuales del proveedor.</td><td>Cyber TPRM, Administradores</td></tr>
        <tr><td><strong><a href="#tprm-action-plan">Plan de Acción del Proveedor</a></strong></td><td>Programe acciones de seguimiento para un proveedor (contactar, enviar evaluación, forzar revisión anual) con fechas de vencimiento, responsables, alertas por correo electrónico y notas de estado.</td><td>Cyber TPRM, Administradores</td></tr>
        <tr><td><strong><a href="#custom-onboarding">Campos de incorporación personalizados y Datos Personalizados</a></strong></td><td>Capture campos adicionales específicos de la organización en un proveedor con visibilidad por rol, edítelos en la pestaña Datos Personalizados y léalos en la exportación CSV y la API.</td><td>Administradores, Cyber TPRM</td></tr>
        <tr><td><strong><a href="#breach-alerts">Alertas de Brechas / Ciberseguridad</a></strong></td><td>Un feed de brechas de la cadena de suministro (incluidos los incidentes de Grip) con un desglose de usuarios afectados y confirmación / falso positivo / eliminación en bloque.</td><td>Cyber TPRM, Administradores</td></tr>
        <tr><td><strong><a href="#admin-acl-groups">Grupos de control de acceso personalizados</a></strong></td><td>Cree sus propios grupos ACL, clone permisos de un grupo existente y establezca Lectura o Lectura/Escritura por módulo. Los grupos incluidos están protegidos.</td><td>Administradores</td></tr>
        <tr><td><strong><a href="#admin-templates">Constructor de Plantillas de Evaluación</a></strong></td><td>Nuevos tipos de pregunta (selección múltiple, teléfono, VAT), instrucciones de certificado basadas en plantilla, restricción de campos por rol y plantillas desactivadas ocultas por defecto.</td><td>Administradores, Cyber TPRM</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>¿Cómo sé qué versión tengo?</strong> Los administradores pueden ir a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Versión</span> para ver la versión instalada. Esta guía describe <strong>v2.6.2</strong>. Consulte <a href="#admin-updates">Actualización de la Plataforma</a>.
    </div>
</div>

<div class="doc-section" id="language">
    <h2>Cambio de Idioma <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>La interfaz de la plataforma puede mostrarse en <strong>8 idiomas</strong>. Cada persona elige su propio idioma &mdash; cambiarlo solo afecta <em>su</em> pantalla, no la de los demás. Su elección se recuerda cada vez que inicia sesión.</p>

    <h3>Idiomas disponibles</h3>
    <table class="doc-table">
        <tr><th>Idioma</th><th>Aparece en el menú como</th></tr>
        <tr><td>Inglés</td><td>English</td></tr>
        <tr><td>Español</td><td>Espa&ntilde;ol</td></tr>
        <tr><td>Italiano</td><td>Italiano</td></tr>
        <tr><td>Ucraniano</td><td>&#1059;&#1082;&#1088;&#1072;&#1111;&#1085;&#1089;&#1100;&#1082;&#1072;</td></tr>
        <tr><td>Chino (Simplificado)</td><td>&#20013;&#25991;&#65288;&#31616;&#20307;&#65289;</td></tr>
        <tr><td>Hindi</td><td>&#2361;&#2367;&#2344;&#2381;&#2342;&#2368;</td></tr>
        <tr><td>Francés</td><td>Fran&ccedil;ais</td></tr>
        <tr><td>Portugués</td><td>Portugu&ecirc;s</td></tr>
    </table>
    <p class="callout callout-info" style="margin-top:0;"><strong>Solo aparecerán en su lista los idiomas que su administrador haya activado.</strong> El inglés siempre está disponible y no se puede desactivar.</p>

    <h3>Cómo cambiar su idioma (paso a paso)</h3>
    <ol class="steps">
        <li>Haga clic en <span class="menu-label">Perfil</span> en la esquina superior derecha de cualquier página.</li>
        <li>En la página de Perfil, desplácese hacia abajo hasta la tarjeta <span class="field-label">Preferencia de Idioma</span>.</li>
        <li>Haga clic en el menú desplegable <span class="field-label">Idioma</span> y elija su idioma. Para volver al idioma que su administrador ha configurado para todos, elija <strong>Sistema predeterminado</strong>.</li>
        <li>Haga clic en <span class="btn-label">Actualizar Idioma</span>. La página se recarga y los menús, botones y etiquetas ahora aparecen en el idioma elegido.</li>
    </ol>
    <figure class="doc-figure narrow">
        <img src="app/docs/profile-language-card.png" alt="Language Preference card on the Profile page" loading="lazy">
        <figcaption><strong>Perfil &rarr; Preferencia de Idioma.</strong> Elija un idioma y haga clic en <strong>Actualizar Idioma</strong>. Elegir <em>Sistema predeterminado</em> elimina su preferencia personal.</figcaption>
    </figure>

    <h3>Para administradores: elegir qué idiomas están disponibles</h3>
    <p>Los administradores deciden el <strong>idioma predeterminado</strong> (usado para nuevos usuarios y para la página de inicio de sesión antes de que alguien inicie sesión) y qué idiomas puede elegir cada persona.</p>
    <ol class="steps">
        <li>Vaya a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">General</span>.</li>
        <li>Busque el menú desplegable <span class="field-label">Idioma Predeterminado</span> y elija el idioma predeterminado para toda la organización.</li>
        <li>En <span class="field-label">Idiomas Habilitados</span>, marque los idiomas que desea poner a disposición. (El inglés siempre está marcado y no se puede deshabilitar.)</li>
        <li>Haga clic en <span class="btn-label">Guardar Configuración</span>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-general-language.png" alt="Default Language and Enabled Languages settings in Admin General" loading="lazy">
        <figcaption><strong>Admin &rarr; General.</strong> Establezca el <strong>Idioma Predeterminado</strong> y marque los <strong>Idiomas Habilitados</strong> entre los que pueden elegir los usuarios.</figcaption>
    </figure>

    <div class="callout callout-warning">
        <strong>Información útil:</strong> La interfaz está traducida siempre que exista una traducción para su idioma; una cadena que aún no se ha traducido recurre al inglés, por lo que es posible que siga viendo alguna etiqueta ocasional en inglés. El contenido que usted o sus proveedores escriben (nombres de proveedores, notas, nombres de archivos cargados, respuestas de texto libre) siempre se muestra exactamente como se ingresó. Las <em>preguntas</em> de la evaluación de proveedores pueden traducirse automáticamente para su visualización cuando se configura un proveedor de IA (consulte <a href="#admin-ai">Integración de IA</a>); sin uno, permanecen en el idioma en que fueron escritas. Los valores de respuesta almacenados siempre permanecen en inglés para que la puntuación y los informes sean coherentes en todos los idiomas.
    </div>
</div>


<!-- ================================================================
     PHONE & VAT QUESTION TYPES + VENDOR DATA IMPROVEMENTS
     ================================================================ -->
<div class="doc-section" id="question-types">
    <h2>Tipos de Pregunta de Teléfono y VAT <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>El <strong>Constructor de Plantillas</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Plantillas de Evaluación</span>) incorpora dos nuevos tipos de pregunta que capturan datos de contacto e información fiscal en un formato limpio y coherente. Pueden usarse en cualquier plantilla de evaluación o incorporación y, como otras preguntas, pueden asignarse a un campo de proveedor para que la respuesta fluya al registro del proveedor.</p>

    <h3>Teléfono</h3>
    <p>El tipo <strong>Teléfono</strong> muestra un selector de país con una bandera y el código de marcación junto al cuadro de número. Estados Unidos aparece primero; todos los demás países siguen en orden alfabético. Independientemente del formato que escriba la persona &mdash; <code>314-444-5544</code>, <code>(314)&nbsp;444-5544</code> o <code>3144445544</code> &mdash; el número se almacena en un formato internacional uniforme (por ejemplo, seleccionar la bandera de EE.&nbsp;UU. y escribir <code>3144445544</code> almacena <code>+13144445544</code>). El formulario predeterminado de Solicitud de Incorporación de Proveedor ahora usa este tipo para el número de teléfono del contacto principal, y el campo de teléfono de <strong>certificación</strong> de la evaluación también lo utiliza.</p>

    <h3>VAT (número de IVA de la UE)</h3>
    <p>El tipo <strong>VAT</strong> es para números de IVA europeos. Para evitar errores tipográficos, debe <strong>ingresarse dos veces</strong> y las dos entradas deben coincidir antes de guardarse. El número se almacena en una forma coherente (mayúsculas, sin espacios ni signos de puntuación; por ejemplo, <code>DE123456789</code>).</p>
    <ul>
        <li><strong>Validación en vivo gratuita.</strong> Cuando termina de escribir, la plataforma verifica el número contra el servicio oficial <strong>EU VIES</strong> (el Sistema de Intercambio de Información sobre el IVA de la Comisión Europea). VIES es gratuito, no requiere cuenta y refleja el registro en vivo de cada estado miembro.</li>
        <li><strong>Informativo, nunca bloqueante.</strong> Si VIES no puede confirmar el número, igualmente se guarda &mdash; un aviso simplemente le pide que lo verifique. Si VIES está momentáneamente lento o el registro de un país no está disponible temporalmente, el número se guarda y se le indica que lo verifique más tarde.</li>
        <li><strong>Detalles a demanda.</strong> Cuando VIES confirma un número, aparece un botón de información (&#9432;) a su lado. Al hacer clic se abre un panel que muestra el nombre y la dirección de la empresa registrada devueltos por VIES.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Asignación del VAT al registro del proveedor.</strong> Hay disponible un campo dedicado <code>vat_number</code>, por lo que una pregunta de VAT asignada a él almacena el valor en el proveedor. Cuando elige el tipo de pregunta VAT en el Constructor de Plantillas, esta asignación se selecciona automáticamente.
    </div>

    <h3>VAT en la página del proveedor</h3>
    <p>El número de IVA del proveedor se muestra en la tarjeta <strong>Información del Proveedor</strong> en la página de incorporación del proveedor. Si no hay ningún IVA registrado, aparece un botón <strong>&ldquo;+ Agregar IVA&rdquo;</strong> que accede directamente al modo de edición con el campo IVA enfocado.</p>

    <h3>Búsqueda de proveedores por número de IVA</h3>
    <p>El cuadro de <strong>búsqueda rápida</strong> en la parte superior derecha de la plataforma ahora también busca coincidencias por número de IVA, junto con el nombre del proveedor, dominio y parte interesada. Las coincidencias directas con el nombre propio del proveedor, dominio o número de IVA siempre se muestran primero.</p>

    <h2>Banner de puntuación de Incorporación de Compras <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>Cuando el estado de <strong>Incorporación de Compras</strong> de un proveedor se establece en <strong>No</strong>, ahora aparece un banner que aclara que <em>la puntuación automática del proveedor está deshabilitada hasta que el proveedor complete la Incorporación de Compras</em>. Aparece tanto en la página de incorporación del proveedor como debajo de la pregunta de evaluación correspondiente, y se actualiza inmediatamente cuando cambia la respuesta.</p>

    <h2>Envío de una evaluación: campos obligatorios verificados primero <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>Cuando un proveedor hace clic en <strong>Enviar</strong> en una evaluación, la plataforma ahora verifica que todas las preguntas obligatorias estén respondidas <em>antes</em> de solicitar los datos de certificación del remitente. Anteriormente, una respuesta faltante solo se informaba después de completar la certificación, lo que obligaba a volver a ingresarla.</p>
</div>

<!-- ================================================================
     LARGE DATABASE BACKUPS
     ================================================================ -->
<div class="doc-section" id="admin-backup">
    <h2>Copias de Seguridad y Restauraciones de Bases de Datos Grandes <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>Las copias de seguridad y restauraciones (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Copia de Seguridad</span>) ahora manejan <strong>bases de datos de varios gigabytes</strong> y registros individuales que se aproximan a <strong>1&nbsp;GB</strong> sin que la operación se interrumpa por un tiempo de espera o falta de memoria. En segundo plano, el límite de paquetes de la base de datos, los tiempos de espera de la red, el tamaño de carga y los límites de tiempo de solicitud se incrementaron para admitir datos muy grandes.</p>
    <div class="callout callout-info">
        <strong>Para bases de datos muy grandes:</strong> Una copia de seguridad o restauración de un archivo de varios gigabytes puede tardar un tiempo &mdash; deje la página abierta hasta que finalice. Los conjuntos de datos extremadamente grandes (decenas de gigabytes) se restauran mejor desde la línea de comandos del servidor.
    </div>
</div>


<!-- ================================================================
     GRC MODULE - COMPREHENSIVE DOCUMENTATION
     ================================================================ -->
<div class="doc-section" id="grc-overview">
    <h2>Módulo GRC: ¿Qué es Gobernanza, Riesgo y Cumplimiento?</h2>
    <p><strong>GRC</strong> son las siglas de <strong>Gobernanza, Riesgo y Cumplimiento</strong>. Es la práctica de garantizar que su organización cumpla con los requisitos normativos, siga las mejores prácticas de seguridad, gestione los riesgos y pueda demostrar el cumplimiento a auditores y reguladores.</p>

    <p>El módulo GRC le ayuda a:</p>
    <ul>
        <li><strong>Evaluar su madurez en seguridad</strong> mediante un único cuestionario unificado que se asigna a múltiples marcos de cumplimiento simultáneamente</li>
        <li><strong>Rastrear el cumplimiento</strong> frente a SOC 2, ISO 27001, PCI DSS, NIST CSF, CMMC, HIPAA, CIS Controls y más</li>
        <li><strong>Gestionar controles internos</strong> &mdash; documentar las medidas de seguridad que ha implementado su organización</li>
        <li><strong>Recopilar y almacenar evidencias</strong> &mdash; cargar capturas de pantalla, exportaciones de configuración, documentos de políticas y certificados que demuestren el cumplimiento</li>
        <li><strong>Gestionar políticas</strong> &mdash; crear, versionar, aprobar y publicar políticas de seguridad organizacionales</li>
        <li><strong>Ejecutar auditorías</strong> &mdash; planificar auditorías, registrar hallazgos, asignar medidas correctivas y hacer seguimiento del cierre</li>
        <li><strong>Rastrear riesgos</strong> &mdash; mantener un registro de riesgos con puntuación de probabilidad/impacto y planes de tratamiento</li>
        <li><strong>Monitorear continuamente</strong> &mdash; configurar verificaciones automáticas que validan los controles de cumplimiento según un calendario</li>
    </ul>

    <div class="callout callout-warning">
        <strong>Concepto Importante &mdash; Preguntas Unificadas:</strong> La plataforma contiene <strong>146 preguntas de seguridad unificadas</strong> organizadas en <strong>14 dominios de seguridad</strong> (Gobernanza, Gestión de Identidad y Acceso, Seguridad de Datos, Seguridad de Red, etc.). Cada pregunta está pre-asignada a requisitos específicos en múltiples marcos de cumplimiento. Cuando responde una pregunta una vez, la respuesta se aplica automáticamente a cada marco al que está asignada. Esto elimina la necesidad de responder la misma pregunta por separado para SOC 2, ISO 27001 y PCI DSS.
    </div>
</div>


<div class="doc-section" id="grc-getting-started">
    <h2>Primeros Pasos con GRC &mdash; Guía de Inicio Rápido</h2>
    <p>Si es completamente nuevo en el módulo GRC, siga estos pasos en orden. Al finalizar, tendrá una evaluación de cumplimiento completada con puntuaciones en todos los marcos.</p>

    <div class="callout callout-info">
        <strong>Requisitos previos:</strong><br>
        &bull; Debe haber iniciado sesión como usuario del grupo <strong>Administrador</strong> o <strong>Cyber GRC</strong><br>
        &bull; Debe poder ver <span class="menu-label">Módulo GRC</span> en la barra lateral izquierda<br>
        &bull; Si no lo ve, pida a su administrador que lo asigne al grupo Cyber GRC (Admin &rarr; Usuarios &rarr; haga clic en el botón Grupos junto a su nombre &rarr; marque "Cyber GRC" &rarr; Guardar)
    </div>

    <p>El flujo de trabajo recomendado es:</p>
    <ol>
        <li><strong>Crear una Evaluación</strong> &mdash; Define el alcance y el propósito de su revisión de cumplimiento</li>
        <li><strong>Responder las Preguntas</strong> &mdash; Trabaje a través de las 146 preguntas unificadas, calificando su nivel de madurez para cada una</li>
        <li><strong>Cargar Evidencias</strong> &mdash; Adjunte documentos, capturas de pantalla y archivos que demuestren sus respuestas</li>
        <li><strong>Ver Sus Puntuaciones</strong> &mdash; Revise sus porcentajes de cumplimiento en la página de Marcos</li>
        <li><strong>Generar Informes</strong> &mdash; Cree informes detallados de cumplimiento por marco para auditores</li>
    </ol>
    <p>Cada paso se explica en detalle a continuación.</p>
</div>


<div class="doc-section" id="grc-step1">
    <h2>Paso 1: Crear Su Primera Evaluación</h2>
    <p>Una <strong>Evaluación</strong> es una revisión de cumplimiento de su organización. Representa una evaluación en un momento específico donde responde preguntas de seguridad, registra calificaciones de madurez y recopila evidencias. Piense en ella como una "instantánea de cumplimiento".</p>

    <h3>Cómo crear una nueva evaluación</h3>
    <ol class="steps">
        <li>En la barra lateral izquierda, haga clic en <span class="menu-label">Módulo GRC</span> para expandirlo.</li>
        <li>Haga clic en la sección <span class="menu-label">Evaluación y Auditoría</span> para expandirla.</li>
        <li>Haga clic en <span class="menu-label">Cuestionario de Evaluación</span>. Esto abre la página principal de evaluación.</li>
        <li>En la parte superior de la página, verá un botón <span class="btn-label">+ Nueva Evaluación</span>. Haga clic en él.</li>
        <li>Aparecerá un formulario. Complete los siguientes campos:
            <ul>
                <li><span class="field-label">Título</span> &mdash; Dé a su evaluación un nombre descriptivo. Ejemplo: <code>2026 Annual Security Assessment - ACME Corp</code></li>
                <li><span class="field-label">Tipo de Evaluación</span> &mdash; Seleccione el tipo de evaluación:
                    <ul>
                        <li><strong>Inicial</strong> &mdash; Su primera evaluación (recomendado para nuevos usuarios)</li>
                        <li><strong>Periódica</strong> &mdash; Una evaluación recurrente regular (p. ej., revisión anual)</li>
                        <li><strong>Dirigida</strong> &mdash; Una evaluación enfocada en un área específica</li>
                        <li><strong>Pre-Auditoría</strong> &mdash; Preparación antes de una auditoría formal</li>
                        <li><strong>Certificación</strong> &mdash; Evaluación con fines de certificación (p. ej., SOC 2 Tipo II)</li>
                    </ul>
                </li>
                <li><span class="field-label">Alcance</span> &mdash; Seleccione o describa el alcance organizacional. Define qué parte de su organización está siendo evaluada (p. ej., "Todos los sistemas de TI" o "Infraestructura en la nube").</li>
                <li><span class="field-label">Auditor Líder</span> &mdash; Seleccione a la persona que lidera esta evaluación. El menú desplegable solo muestra usuarios de los grupos Administrador o Cyber GRC.</li>
                <li><span class="field-label">Fecha de Inicio Planificada</span> &mdash; Cuándo planea comenzar la evaluación.</li>
                <li><span class="field-label">Fecha de Finalización Planificada</span> &mdash; Su fecha objetivo de finalización.</li>
            </ul>
        </li>
        <li>Haga clic en <span class="btn-label">Crear Evaluación</span>.</li>
        <li>Su nueva evaluación se crea con estado <span class="status-label">Borrador</span>. Ahora puede comenzar a responder preguntas.</li>
    </ol>

    <div class="example-box">
        <strong>Ejemplo:</strong> Está realizando la primera revisión anual de seguridad de su organización.<br><br>
        &bull; Título: <code>2026 Annual Security Assessment</code><br>
        &bull; Tipo: <code>Initial</code><br>
        &bull; Alcance: <code>All Corporate IT Systems</code><br>
        &bull; Auditor Líder: <code>Jane Smith</code><br>
        &bull; Fecha de Inicio: <code>March 1, 2026</code><br>
        &bull; Fecha de Finalización: <code>April 30, 2026</code>
    </div>

    <h3>Estados de la Evaluación</h3>
    <table class="doc-table">
        <tr><th>Estado</th><th>Significado</th></tr>
        <tr><td><span class="status-label">Borrador</span></td><td>La evaluación ha sido creada pero el trabajo aún no ha comenzado. Se pueden responder preguntas.</td></tr>
        <tr><td><span class="status-label">En Progreso</span></td><td>Evaluación activa &mdash; los miembros del equipo están respondiendo preguntas y cargando evidencias.</td></tr>
        <tr><td><span class="status-label">En Revisión</span></td><td>Todas las preguntas respondidas &mdash; un auditor líder o validador está revisando las respuestas.</td></tr>
        <tr><td><span class="status-label">Completada</span></td><td>La evaluación ha concluido y se ha finalizado. Las respuestas están bloqueadas.</td></tr>
        <tr><td><span class="status-label">Archivada</span></td><td>Evaluación histórica conservada para los registros. Ya no está activa.</td></tr>
    </table>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment.png" alt="Assessment list on the Assessment Questionnaire page" loading="lazy">
        <figcaption><strong>Módulo GRC &rarr; Cuestionario de Evaluación.</strong> Cada evaluación aparece con su referencia, título, tipo, estado, auditor líder, puntuación CSF actual y % de cumplimiento, y fecha planificada. Use <span class="btn-label">+ Nueva Evaluación</span> para iniciar una, o <span class="btn-label">Abrir</span> para continuar respondiendo una existente. Las pestañas de estado en la parte superior filtran la lista.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step2">
    <h2>Paso 2: Responder las Preguntas de Evaluación</h2>
    <p>Una vez que haya creado una evaluación, debe responder las 146 preguntas de seguridad unificadas. Cada pregunta pertenece a uno de los 14 dominios de seguridad.</p>

    <h3>Los 14 Dominios de Seguridad</h3>
    <table class="doc-table">
        <tr><th>Código</th><th>Nombre del Dominio</th><th>Preguntas</th><th>Qué Cubre</th></tr>
        <tr><td><code>GOV</code></td><td>Gobernanza y Liderazgo</td><td>12</td><td>Liderazgo del programa de seguridad, estrategia, presupuesto, informes a la junta</td></tr>
        <tr><td><code>IAM</code></td><td>Gestión de Identidad y Acceso</td><td>14</td><td>Cuentas de usuario, autenticación, controles de acceso, acceso privilegiado</td></tr>
        <tr><td><code>DSP</code></td><td>Seguridad de Datos y Privacidad</td><td>12</td><td>Clasificación de datos, cifrado, privacidad, prevención de pérdida de datos</td></tr>
        <tr><td><code>EPS</code></td><td>Seguridad de Endpoints y Plataformas</td><td>10</td><td>Portátiles, servidores, dispositivos móviles, parches, EDR</td></tr>
        <tr><td><code>NET</code></td><td>Seguridad de Red</td><td>11</td><td>Cortafuegos, segmentación, VPN, seguridad DNS, Wi-Fi</td></tr>
        <tr><td><code>APS</code></td><td>Seguridad de Aplicaciones</td><td>10</td><td>Desarrollo seguro, revisiones de código, seguridad de API, WAF</td></tr>
        <tr><td><code>OPS</code></td><td>Operaciones de Seguridad</td><td>12</td><td>SIEM, registros, monitoreo, análisis de vulnerabilidades, SOC</td></tr>
        <tr><td><code>INC</code></td><td>Gestión de Incidentes</td><td>10</td><td>Planes de respuesta a incidentes, ejercicios de simulación, notificación de brechas</td></tr>
        <tr><td><code>SCM</code></td><td>Cadena de Suministro y Terceros</td><td>10</td><td>Gestión de proveedores, riesgo en la cadena de suministro, contratos</td></tr>
        <tr><td><code>PHY</code></td><td>Seguridad Física y Ambiental</td><td>8</td><td>Centros de datos, control de acceso con tarjeta, CCTV, controles ambientales</td></tr>
        <tr><td><code>HRS</code></td><td>Seguridad de Recursos Humanos</td><td>10</td><td>Verificación de antecedentes, formación en seguridad, procedimientos de baja</td></tr>
        <tr><td><code>BCP</code></td><td>Continuidad del Negocio</td><td>10</td><td>Copia de seguridad, recuperación ante desastres, pruebas de BCP, RTO/RPO</td></tr>
        <tr><td><code>CRY</code></td><td>Criptografía y Gestión de Claves</td><td>8</td><td>Estándares de cifrado, rotación de claves, gestión de certificados</td></tr>
        <tr><td><code>CMP</code></td><td>Cumplimiento y Aseguramiento</td><td>9</td><td>Cumplimiento normativo, auditoría interna, preparación para auditoría externa</td></tr>
    </table>

    <h3>Cómo responder las preguntas</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evaluación y Auditoría</span> &rarr; <span class="menu-label">Cuestionario de Evaluación</span>.</li>
        <li>Si tiene varias evaluaciones, seleccione la correcta en el menú desplegable en la parte superior de la página.</li>
        <li>Verá los 14 dominios de seguridad enumerados. Haga clic en el nombre de un dominio (p. ej., <strong>GOV - Gobernanza y Liderazgo</strong>) para expandirlo y ver sus preguntas.</li>
        <li>Para cada pregunta debe proporcionar dos datos:
            <ul>
                <li><span class="field-label">Calificación de Madurez</span> (1-4) &mdash; ¿Qué tan madura es la implementación de este control en su organización?
                    <ul>
                        <li><strong>1 &mdash; Inicial/Ad Hoc:</strong> Sin proceso formal. Se realiza de forma inconsistente o no se realiza en absoluto.</li>
                        <li><strong>2 &mdash; En Desarrollo:</strong> Existen algunos procesos pero no se siguen de manera consistente. Parcialmente documentado.</li>
                        <li><strong>3 &mdash; Definido:</strong> Los procesos formales y documentados están establecidos y se siguen de manera consistente.</li>
                        <li><strong>4 &mdash; Gestionado/Optimizado:</strong> Los procesos se miden, monitorean y mejoran continuamente.</li>
                    </ul>
                </li>
                <li><span class="field-label">Estado de Conformidad</span> &mdash; Su estado de cumplimiento para esta pregunta:
                    <ul>
                        <li><strong>Conforme</strong> &mdash; Totalmente implementado y cumple el requisito</li>
                        <li><strong>Parcial</strong> &mdash; Parcialmente implementado; persisten algunas brechas</li>
                        <li><strong>No Conforme</strong> &mdash; No implementado o no cumple el requisito</li>
                        <li><strong>No Aplicable</strong> &mdash; Esta pregunta no aplica a su organización</li>
                    </ul>
                </li>
            </ul>
        </li>
        <li>Opcionalmente, agregue <span class="field-label">Notas</span> para explicar su respuesta. Esto es muy recomendable &mdash; los auditores querrán ver su razonamiento.</li>
        <li>Sus respuestas se <strong>guardan automáticamente</strong> mientras trabaja. No necesita hacer clic en un botón de guardar.</li>
        <li>Continúe respondiendo preguntas en los 14 dominios. No necesita completarlo todo en una sesión &mdash; vuelva cuando quiera para retomar.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Consejo &mdash; La madurez impulsa la conformidad:</strong> Cuando establece una calificación de madurez, el sistema puede derivar automáticamente el estado de conformidad: Madurez 3-4 = Conforme, Madurez 2 = Parcial, Madurez 1 = No Conforme. Puede modificar esto si lo necesita.
    </div>

    <div class="callout callout-warning">
        <strong>Importante:</strong> Cada pregunta que responde se asigna a requisitos de múltiples marcos. Por ejemplo, responder una pregunta sobre "Autenticación Multifactor" (en el dominio IAM) actualiza simultáneamente sus puntuaciones de cumplimiento para SOC 2, ISO 27001, PCI DSS, NIST CSF y CMMC. Nunca necesita responder el mismo concepto dos veces.
    </div>
    <figure class="doc-figure">
        <img src="app/docs/grc-assessment-questions.png" alt="Answering questions in the assessment questionnaire" loading="lazy">
        <figcaption><strong>Respondiendo el cuestionario.</strong> El encabezado muestra el <em>Progreso</em>, la <em>Madurez CSF</em> en tiempo real y el <em>Cumplimiento</em> mientras trabaja. Las pestañas de dominio (GOV, IAM, DSP, &hellip;) muestran la puntuación actual de ese dominio; haga clic en una para saltar a sus preguntas. Para cada pregunta establezca una calificación de <strong>Madurez</strong> (1&ndash;4 o N/A) y un estado de <strong>Conformidad</strong> &mdash; las respuestas se guardan automáticamente. Use <span class="btn-label">Mostrar Preguntas Sin Responder</span> para encontrar las pendientes.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-step3">
    <h2>Paso 3: Cargar Evidencias</h2>
    <p>Las evidencias demuestran que sus respuestas son precisas. Los auditores esperarán ver evidencias para cada afirmación de cumplimiento. Las evidencias pueden incluir capturas de pantalla, exportaciones de configuración, documentos de políticas, registros de auditoría, certificados y más.</p>

    <h3>Cómo cargar evidencias durante una evaluación</h3>
    <ol class="steps">
        <li>Mientras responde una pregunta en el <span class="menu-label">Cuestionario de Evaluación</span>, busque la sección de <strong>Evidencias</strong> debajo del área de respuesta de la pregunta.</li>
        <li>Haga clic en <span class="btn-label">Cargar Evidencia</span> o en el ícono de adjunto.</li>
        <li>Seleccione un archivo de su computadora. Los tipos admitidos incluyen PDF, imágenes (PNG, JPG), documentos Word, hojas de cálculo Excel y archivos de texto.</li>
        <li>Dé a la evidencia un <span class="field-label">Título</span> descriptivo (p. ej., "Captura de pantalla de configuración MFA - Consola Admin Okta").</li>
        <li>La evidencia se vincula automáticamente a la pregunta de evaluación actual.</li>
        <li>Puede cargar múltiples archivos de evidencia por pregunta.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Seguridad:</strong> Todos los archivos de evidencia cargados se cifran (AES-256-CBC) antes de almacenarse en la base de datos. Cuando descarga una evidencia, se descifra al instante. Esto garantiza que los documentos sensibles de cumplimiento estén protegidos en reposo.
    </div>

    <h3>Biblioteca de Evidencias</h3>
    <p>También puede gestionar evidencias por separado a través de <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evidencia y Monitoreo</span> &rarr; <span class="menu-label">Biblioteca de Evidencias</span>. Esta página muestra todas las evidencias de todas las evaluaciones y controles, con filtrado por tipo, estado y fecha de vencimiento.</p>
</div>


<div class="doc-section" id="grc-step4">
    <h2>Paso 4: Ver Sus Puntuaciones de Cumplimiento</h2>
    <p>A medida que responde preguntas, la plataforma calcula su porcentaje de cumplimiento para cada marco en tiempo real.</p>

    <h3>Ver puntuaciones en la página de Marcos</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Cumplimiento</span> &rarr; <span class="menu-label">Marcos</span>.</li>
        <li>En la parte superior de la página verá un menú desplegable <span class="field-label">Evaluación</span>. Seleccione la evaluación para la que desea ver las puntuaciones. Por defecto se selecciona la evaluación más reciente.</li>
        <li>Debajo del menú desplegable verá tarjetas de marcos &mdash; una por cada marco de cumplimiento que tiene preguntas asignadas. Cada tarjeta muestra:
            <ul>
                <li>Un <strong>gráfico de dona</strong> con el porcentaje general de cumplimiento (p. ej., 75%)</li>
                <li>El <strong>código y nombre del marco</strong> (p. ej., "SOC2 &mdash; SOC 2 Tipo II")</li>
                <li>Puntuación de <strong>Madurez Promedio</strong> (si existen datos de madurez, mostrada como p. ej., "3.50 / 4.00")</li>
                <li>Conteo de métricas: <strong>Conforme</strong>, <strong>Parcial</strong>, <strong>No Conforme</strong> y <strong>Total Asignado</strong></li>
            </ul>
        </li>
        <li>Haga clic en cualquier tarjeta de marco para abrir el <strong>Informe de Cumplimiento</strong> detallado de ese marco.</li>
    </ol>

    <h3>Cálculo del Porcentaje de Cumplimiento</h3>
    <p>El porcentaje de cumplimiento se calcula como:</p>
    <div class="example-box">
        <strong>Fórmula:</strong> <code>(Conformes + Parciales &times; 0.5) &divide; Requisitos Aplicables &times; 100</code><br><br>
        &bull; Los requisitos <strong>Conformes</strong> cuentan como 100% completados<br>
        &bull; Los requisitos <strong>Parciales</strong> cuentan como 50% completados<br>
        &bull; Los requisitos <strong>No Aplicables</strong> se excluyen del cálculo<br>
        &bull; Los requisitos <strong>No Conformes</strong> y <strong>No Evaluados</strong> cuentan como 0%
    </div>

    <h3>Marcos Actualmente Compatibles</h3>
    <table class="doc-table">
        <tr><th>Marco</th><th>Versión</th><th>Preguntas Asignadas</th></tr>
        <tr><td>NIST Cybersecurity Framework (CSF)</td><td>2.0</td><td>146</td></tr>
        <tr><td>ISO/IEC 27001</td><td>2022</td><td>146</td></tr>
        <tr><td>SOC 2 Type II</td><td>2017</td><td>146</td></tr>
        <tr><td>PCI DSS</td><td>4.0</td><td>132</td></tr>
        <tr><td>CMMC / NIST 800-171</td><td>v2.0</td><td>97</td></tr>
        <tr><td>CIS Controls</td><td>v8</td><td>95</td></tr>
        <tr><td>NIST SP 800-171</td><td>Rev 2</td><td>90</td></tr>
        <tr><td>HIPAA Security Rule</td><td>2013</td><td>61</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-step5">
    <h2>Paso 5: Generar un Informe de Cumplimiento por Marco</h2>
    <p>Una vez que haya respondido preguntas, puede generar un informe de cumplimiento detallado para cualquier marco. Este informe es adecuado para compartir con auditores, reguladores o la dirección.</p>

    <h3>Cómo generar un informe</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Cumplimiento</span> &rarr; <span class="menu-label">Marcos</span>.</li>
        <li>Seleccione su evaluación en el menú desplegable <span class="field-label">Evaluación</span> en la parte superior.</li>
        <li>Haga clic en la tarjeta del marco sobre el que desea informar (p. ej., "SOC2 &mdash; SOC 2 Tipo II").</li>
        <li>Se abre la página de <strong>Informe de Cumplimiento por Marco</strong>, que muestra:
            <ul>
                <li><strong>Encabezado del informe</strong> &mdash; Nombre del marco, título de evaluación, tipo, estado, alcance, auditor líder, fechas y porcentaje general de cumplimiento</li>
                <li><strong>Estadísticas de resumen</strong> &mdash; Tarjetas clicables que muestran el total de requisitos, conformes, parciales, no conformes, no evaluados y conteos de N/A</li>
                <li><strong>Tarjetas de requisitos</strong> &mdash; Una tarjeta por cada requisito del marco, que muestra la referencia del requisito, el título, la insignia de estado y todas las preguntas asignadas con sus respuestas</li>
            </ul>
        </li>
        <li>Para <strong>filtrar requisitos por estado</strong>, haga clic en cualquiera de las tarjetas de estadísticas en la parte superior. Por ejemplo, haga clic en <strong>No Conforme</strong> para mostrar solo los requisitos no conformes. Haga clic nuevamente (o en "Total de Requisitos") para mostrar todos.</li>
        <li>Para <strong>imprimir el informe</strong>, haga clic en el botón <span class="btn-label">Imprimir Informe</span> en la parte superior. Se abrirá el diálogo de impresión del navegador. Puede imprimir en papel o seleccionar "Guardar como PDF" para crear un archivo PDF.</li>
    </ol>

    <h3>Qué muestra cada tarjeta de requisito</h3>
    <p>Para cada requisito del informe verá:</p>
    <ul>
        <li><strong>Referencia del Requisito</strong> &mdash; El número de referencia oficial (p. ej., "CC6.1" para SOC 2)</li>
        <li><strong>Título del Requisito</strong> &mdash; Lo que dice el requisito</li>
        <li><strong>Insignia de Estado</strong> &mdash; Con código de colores: verde (Conforme), ámbar (Parcial), rojo (No Conforme), gris (No Evaluado / N/A)</li>
        <li><strong>Preguntas Asignadas</strong> &mdash; Cada pregunta asignada a este requisito, que muestra:
            <ul>
                <li>Referencia y texto de la pregunta</li>
                <li>Calificación de madurez (1-4) con una barra visual</li>
                <li>Estado de conformidad</li>
                <li>Estado de validación (Pendiente, Validado, Rechazado, Necesita Revisión)</li>
                <li>Nombre del evaluador y fecha</li>
                <li>Intensidad del mapeo (Exacto, Sólido, Parcial, Relacionado)</li>
                <li>Notas del evaluador</li>
                <li>Notas de validación</li>
                <li>Archivos de evidencia adjuntos (con enlaces de descarga)</li>
            </ul>
        </li>
    </ul>
</div>


<div class="doc-section" id="grc-fairscore">
    <h2>Panel de Puntuación de Madurez CSF</h2>
    <p>La página de <strong>Puntuación de Madurez CSF</strong> proporciona un panel visual que muestra la madurez de su organización en los 14 dominios de seguridad, alineada con el Marco de Ciberseguridad NIST.</p>

    <h3>Cómo acceder</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evaluación y Auditoría</span> &rarr; <span class="menu-label">Puntuación de Madurez CSF</span>.</li>
        <li>Si tiene varias evaluaciones, seleccione la deseada en el menú desplegable.</li>
        <li>La página muestra:
            <ul>
                <li><strong>Puntuación FAIR General</strong> &mdash; Una puntuación de madurez promedio ponderada en todos los dominios</li>
                <li><strong>Gráfico de Radar</strong> &mdash; Un gráfico visual de araña/radar que representa sus puntuaciones en los 14 dominios</li>
                <li><strong>Tarjetas de Puntuación por Dominio</strong> &mdash; Tarjetas individuales para cada dominio que muestran la madurez promedio, preguntas respondidas y desglose de conformidad</li>
                <li><strong>Barras de Cumplimiento por Marco</strong> &mdash; Barras horizontales que muestran los porcentajes de cumplimiento por marco</li>
                <li><strong>Resumen de Análisis de Brechas</strong> &mdash; Dominios donde las puntuaciones están por debajo del objetivo</li>
            </ul>
        </li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grc-fairscore.png" alt="CSF Maturity Score dashboard with radar chart" loading="lazy">
        <figcaption><strong>Módulo GRC &rarr; Puntuación de Madurez CSF.</strong> Los cuatro indicadores principales &mdash; <em>Puntuación de Madurez CSF</em> (escala 1&ndash;4), <em>Tasa de Cumplimiento</em>, <em>Preguntas Respondidas</em> y <em>Brechas Encontradas</em> &mdash; resumen su postura de un vistazo. El <strong>Radar de Madurez de Dominio de Seguridad</strong> representa los 14 dominios, y la lista de la derecha muestra la puntuación promedio exacta de cada dominio. Elija la evaluación deseada en el menú desplegable de la parte superior.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-gaps">
    <h2>Análisis de Brechas</h2>
    <p>La página de <strong>Análisis de Brechas</strong> reúne todas las debilidades detectadas durante una evaluación &mdash; cada pregunta respondida como <strong>No Conforme</strong> o <strong>Parcial</strong> &mdash; en una lista de trabajo priorizada. Responde a la pregunta "¿dónde estamos fallando y qué afecta cada deficiencia?"</p>

    <h3>Cómo acceder</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evaluación y Auditoría</span> &rarr; <span class="menu-label">Brechas</span>.</li>
        <li>Seleccione la evaluación que desea analizar en el menú desplegable <span class="field-label">Evaluación</span>.</li>
    </ol>

    <h3>Qué muestra la página</h3>
    <p>Cuatro indicadores de resumen en la parte superior cuentan el <strong>Total de Brechas</strong>, <strong>No Conformes</strong>, <strong>Parciales</strong> y brechas <strong>Con Riesgo Vinculado</strong>. Debajo de ellos, cada brecha aparece como una fila con:</p>
    <ul>
        <li><strong>Gravedad</strong> &mdash; una insignia: <em>No Conforme</em> (rojo) o <em>Parcial</em> (ámbar).</li>
        <li><strong>Dominio</strong> y <strong>Ref</strong> &mdash; el dominio de seguridad y la referencia exacta de la pregunta (p. ej., <code>GOV-08</code>).</li>
        <li><strong>Hallazgo</strong> &mdash; el texto de la pregunta que describe lo que falta.</li>
        <li><strong>Impacto en el Marco</strong> &mdash; insignias para cada requisito de marco al que afecta esta brecha, para que pueda ver de un vistazo si una sola corrección mejora SOC 2, ISO 27001, PCI DSS y más a la vez.</li>
        <li><strong>Riesgo</strong> &mdash; si se ha registrado un riesgo para esta brecha, y una acción <span class="btn-label">Ver</span> para abrir el detalle completo.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/grc-gaps.png" alt="Gap Analysis page listing non-conforming and partial findings" loading="lazy">
        <figcaption><strong>Módulo GRC &rarr; Brechas.</strong> Cada respuesta no conforme o parcial se convierte en una brecha. La columna <strong>Impacto en el Marco</strong> muestra qué requisitos de cada marco toca la brecha &mdash; cerrar una brecha puede mejorar varios marcos a la vez.</figcaption>
    </figure>
</div>


<div class="doc-section" id="grc-frameworks">
    <h2>Página de Marcos</h2>
    <p>La página de <strong>Marcos</strong> es su centro principal para ver el estado de cumplimiento en todos los marcos compatibles. Muestra datos de cumplimiento basados en la evaluación.</p>

    <h3>Cómo usar</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Cumplimiento</span> &rarr; <span class="menu-label">Marcos</span>.</li>
        <li>Seleccione una evaluación en el menú desplegable <span class="field-label">Evaluación</span>. La página usa por defecto su evaluación más reciente.</li>
        <li>La página muestra tarjetas de marcos en una cuadrícula. Solo aparecen los marcos con preguntas asignadas. Cada tarjeta muestra el porcentaje de cumplimiento, la puntuación de madurez y los recuentos de métricas.</li>
        <li>Haga clic en una tarjeta de marco para abrir el informe de cumplimiento detallado.</li>
    </ol>

    <figure class="doc-figure">
        <img src="app/docs/grc-frameworks.png" alt="Compliance Frameworks page with per-framework compliance cards" loading="lazy">
        <figcaption><strong>Módulo GRC &rarr; Marcos.</strong> Los indicadores superiores cuentan sus marcos, la preparación promedio, el total de requisitos y cuántos <em>necesitan atención</em>. Cada tarjeta muestra la dona de cumplimiento de un marco, su madurez promedio y el desglose Conforme / Parcial / No Conforme / Total Asignado. Haga clic en cualquier tarjeta para abrir el informe completo de cumplimiento de ese marco.</figcaption>
    </figure>

    <h3>Árbol de Requisitos del Marco</h3>
    <p>Si navega a esta página <em>sin</em> seleccionar una evaluación (o haciendo clic en un enlace de marco desde otro lugar), verá la vista de <strong>Árbol de Requisitos</strong>. Esta muestra la estructura jerárquica de todos los requisitos dentro de un marco, junto con los controles asignados y el estado de implementación. Los administradores y usuarios de Cyber GRC pueden agregar, editar y eliminar requisitos personalizados aquí.</p>
</div>


<div class="doc-section" id="grc-controls">
    <h2>Controles Internos</h2>
    <p>Los <strong>Controles Internos</strong> son las medidas de seguridad específicas que su organización ha implementado. Ejemplos: "Autenticación Multifactor en todos los sistemas", "Copias de seguridad diarias cifradas", "Pruebas de penetración anuales".</p>

    <h3>Cómo crear un control</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Cumplimiento</span> &rarr; <span class="menu-label">Controles Internos</span>.</li>
        <li>Haga clic en <span class="btn-label">+ Nuevo Control</span>.</li>
        <li>Complete los campos:
            <ul>
                <li><span class="field-label">Título del Control</span> &mdash; Un nombre corto (p. ej., "MFA para todas las cuentas de usuario")</li>
                <li><span class="field-label">Descripción</span> &mdash; Descripción detallada de lo que hace este control</li>
                <li><span class="field-label">Tipo de Control</span> &mdash; Preventivo, Detectivo, Correctivo o Directivo</li>
                <li><span class="field-label">Categoría</span> &mdash; Técnico, Administrativo o Físico</li>
                <li><span class="field-label">Estado de Implementación</span> &mdash; Planificado, En Progreso, Implementado o No Aplicable</li>
                <li><span class="field-label">Efectividad</span> &mdash; No Probado, Ineficaz, Parcialmente Efectivo o Efectivo</li>
                <li><span class="field-label">Nivel de Riesgo</span> &mdash; Bajo, Medio, Alto o Crítico</li>
                <li><span class="field-label">Responsable</span> &mdash; La persona responsable (limitado a miembros de los grupos Administrador y Cyber GRC)</li>
                <li><span class="field-label">Frecuencia de Prueba</span> &mdash; Con qué frecuencia se prueba este control (Diario, Semanal, Mensual, etc.)</li>
            </ul>
        </li>
        <li>En <strong>Mapeo de Marcos</strong>, seleccione qué requisitos de marco satisface este control. Puede asignar un control a requisitos de múltiples marcos.</li>
        <li>Haga clic en <span class="btn-label">Guardar</span>.</li>
    </ol>

    <div class="callout callout-success">
        <strong>Beneficio Clave &mdash; Mapeo entre Marcos:</strong> Un único control como "MFA" puede satisfacer requisitos en SOC 2 (CC6.1), ISO 27001 (A.8.5), PCI DSS (8.4.2) y NIST CSF (PR.AC-7) simultáneamente. Asígnelo una vez y cubre todos los marcos.
    </div>
</div>


<div class="doc-section" id="grc-crosswalk">
    <h2>Mapeo Cruzado de Marcos</h2>
    <p>El <strong>Mapeo Cruzado de Marcos</strong> muestra cómo el cumplimiento de un marco proporciona automáticamente cobertura para otro. Por ejemplo, si cumple con SOC 2, ¿cuánto de ISO 27001 ya tiene cubierto?</p>

    <h3>Cómo usar</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Cumplimiento</span> &rarr; <span class="menu-label">Mapeo Cruzado de Marcos</span>.</li>
        <li>Seleccione un <span class="field-label">Marco de Origen</span> (el marco que ya ha completado, p. ej., "SOC 2").</li>
        <li>Seleccione un <span class="field-label">Marco de Destino</span> (el marco con el que desea comparar, p. ej., "ISO 27001").</li>
        <li>La tabla de mapeo cruzado muestra qué requisitos del marco de destino están cubiertos por sus controles de origen, y cuáles tienen brechas.</li>
    </ol>
</div>


<div class="doc-section" id="grc-evidence">
    <h2>Biblioteca de Evidencias</h2>
    <p>La <strong>Biblioteca de Evidencias</strong> es un repositorio centralizado para todas las evidencias de cumplimiento de su organización.</p>

    <h3>Cómo cargar evidencias</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evidencia y Monitoreo</span> &rarr; <span class="menu-label">Biblioteca de Evidencias</span>.</li>
        <li>Haga clic en <span class="btn-label">+ Cargar Evidencia</span>.</li>
        <li>Complete: <span class="field-label">Título</span>, <span class="field-label">Tipo de Evidencia</span> (captura de pantalla, documento, certificado, configuración, informe, etc.), <span class="field-label">Descripción</span> y opcionalmente una <span class="field-label">Fecha de Vencimiento</span>.</li>
        <li>Seleccione el archivo a cargar.</li>
        <li>Haga clic en <span class="btn-label">Cargar</span>. El archivo se cifra y se almacena de forma segura.</li>
        <li>Luego puede vincular esta evidencia a controles específicos o respuestas de evaluación.</li>
    </ol>

    <h3>Estados de las Evidencias</h3>
    <table class="doc-table">
        <tr><th>Estado</th><th>Significado</th></tr>
        <tr><td><strong>Vigente</strong></td><td>Evidencia activa y válida</td></tr>
        <tr><td><strong>Vencida</strong></td><td>Pasó su fecha de vencimiento &mdash; necesita actualizarse</td></tr>
        <tr><td><strong>Reemplazada</strong></td><td>Sustituida por evidencia más reciente</td></tr>
        <tr><td><strong>Borrador</strong></td><td>Cargada pero aún no revisada ni finalizada</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-policies">
    <h2>Gestión de Políticas</h2>
    <p>La página de <strong>Políticas</strong> proporciona un ciclo de vida completo de políticas &mdash; desde la redacción hasta la aprobación, publicación y revisión periódica.</p>

    <h3>Cómo crear una política</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Gestión de Políticas</span> &rarr; <span class="menu-label">Políticas</span>.</li>
        <li>Haga clic en <span class="btn-label">+ Nueva Política</span>.</li>
        <li>Complete: <span class="field-label">Título</span>, <span class="field-label">Categoría</span> (Seguridad, Privacidad, Cumplimiento, Operacional, RRHH, TI, etc.), <span class="field-label">Frecuencia de Revisión</span> (con qué frecuencia debe revisarse la política).</li>
        <li>Escriba el contenido de la política usando el editor de texto enriquecido.</li>
        <li>Haga clic en <span class="btn-label">Guardar</span>. La política se crea con estado <span class="status-label">Borrador</span>.</li>
        <li>Cuando esté listo, envíe para <strong>Revisión</strong> &rarr; <strong>Aprobación</strong> &rarr; <strong>Publicación</strong>.</li>
    </ol>

    <h3>Ciclo de Vida de la Política</h3>
    <p><span class="status-label">Borrador</span> &rarr; <span class="status-label">Revisión</span> &rarr; <span class="status-label">Aprobada</span> &rarr; <span class="status-label">Publicada</span> &rarr; (Revisión Periódica o <span class="status-label">Retirada</span>)</p>
</div>


<div class="doc-section" id="grc-audits">
    <h2>Auditorías y Hallazgos</h2>
    <p>La página de <strong>Auditorías</strong> gestiona el ciclo de vida completo de la auditoría &mdash; desde la planificación hasta el trabajo de campo, hallazgos, medidas correctivas y cierre.</p>

    <h3>Cómo crear una auditoría</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evaluación y Auditoría</span> &rarr; <span class="menu-label">Auditorías</span>.</li>
        <li>Haga clic en <span class="btn-label">+ Nueva Auditoría</span>.</li>
        <li>Complete: <span class="field-label">Título</span>, <span class="field-label">Tipo de Auditoría</span> (Interna, Externa, Certificación, Vigilancia, Preparación), <span class="field-label">Marco</span>, <span class="field-label">Auditor Líder</span>, <span class="field-label">Fechas de Inicio/Fin Planificadas</span>.</li>
        <li>Haga clic en <span class="btn-label">Crear</span>.</li>
    </ol>

    <h3>Registro de Hallazgos</h3>
    <ol class="steps">
        <li>Abra una auditoría y haga clic en <span class="btn-label">+ Agregar Hallazgo</span>.</li>
        <li>Complete: <span class="field-label">Título</span>, <span class="field-label">Gravedad</span> (Informativo, Bajo, Medio, Alto, Crítico), <span class="field-label">Tipo de Hallazgo</span> (No Conformidad, Observación, Oportunidad, Fortaleza) y <span class="field-label">Descripción</span>.</li>
        <li>Asigne el hallazgo a requisitos o controles específicos del marco.</li>
        <li>Asigne la medida correctiva a un miembro del equipo con una fecha límite.</li>
        <li>Realice seguimiento del progreso de la medida correctiva hasta alcanzar el estado <strong>Cierre Verificado</strong>.</li>
    </ol>

    <h3>Estados de la Auditoría</h3>
    <table class="doc-table">
        <tr><th>Estado</th><th>Significado</th></tr>
        <tr><td><strong>Planificación</strong></td><td>Definición del alcance, objetivos y calendario</td></tr>
        <tr><td><strong>Trabajo de Campo</strong></td><td>Pruebas activas, revisión de evidencias y entrevistas</td></tr>
        <tr><td><strong>Elaboración de Informes</strong></td><td>Redacción del informe de auditoría y documentación de hallazgos</td></tr>
        <tr><td><strong>Medidas Correctivas</strong></td><td>Los hallazgos han sido informados; el equipo está resolviendo los problemas</td></tr>
        <tr><td><strong>Cerrada</strong></td><td>Todos los hallazgos resueltos y auditoría completada</td></tr>
    </table>
</div>


<div class="doc-section" id="grc-risks">
    <h2>Registro de Riesgos</h2>
    <p>El <strong>Registro de Riesgos</strong> realiza un seguimiento de los riesgos organizacionales con puntuación de probabilidad/impacto, planes de tratamiento y vínculos a controles.</p>

    <h3>Cómo agregar un riesgo</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evaluación y Auditoría</span> &rarr; <span class="menu-label">Registro de Riesgos</span>.</li>
        <li>Haga clic en <span class="btn-label">+ Nuevo Riesgo</span>.</li>
        <li>Complete: <span class="field-label">Título</span>, <span class="field-label">Descripción</span>, <span class="field-label">Categoría</span> (Estratégico, Operacional, Financiero, Cumplimiento, Reputacional, Tecnológico, Terceros).</li>
        <li>Establezca la <span class="field-label">Probabilidad</span> (Raro, Improbable, Posible, Probable, Casi Cierto) y el <span class="field-label">Impacto</span> (Insignificante, Menor, Moderado, Mayor, Catastrófico).</li>
        <li>El sistema calcula la <strong>Puntuación de Riesgo Inherente</strong> (Probabilidad &times; Impacto, en una escala de 1-25).</li>
        <li>Seleccione una <span class="field-label">Estrategia de Tratamiento</span>: Aceptar, Mitigar, Transferir o Evitar.</li>
        <li>Vincule los controles internos relevantes para mostrar cómo se está mitigando el riesgo. El sistema calcula la <strong>Puntuación de Riesgo Residual</strong> después de los controles.</li>
    </ol>
</div>


<div class="doc-section" id="grc-monitors">
    <h2>Monitores Continuos</h2>
    <p>Los <strong>Monitores Continuos</strong> son verificaciones automatizadas que comprueban sus controles de seguridad según un calendario (cada hora, diariamente, semanalmente o mensualmente).</p>

    <h3>Cómo crear un monitor</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evidencia y Monitoreo</span> &rarr; <span class="menu-label">Monitores Continuos</span>.</li>
        <li>Haga clic en <span class="btn-label">+ Nuevo Monitor</span>.</li>
        <li>Complete: <span class="field-label">Título</span>, <span class="field-label">Tipo de Verificación</span>, <span class="field-label">Frecuencia</span> (Cada hora, Diaria, Semanal, Mensual) y la <span class="field-label">Configuración del Recopilador</span> (configuración JSON para la verificación).</li>
        <li>Vincule el monitor a un control interno.</li>
        <li>Active el monitor. Se ejecutará automáticamente según el calendario configurado.</li>
        <li>Vea los resultados (Aprobado, Fallido, Error, Advertencia) y el historial de ejecuciones en la página de detalles del monitor.</li>
    </ol>
</div>


<div class="doc-section" id="grc-tasks">
    <h2>Bandeja de Entrada de Tareas</h2>
    <p>La <strong>Bandeja de Entrada de Tareas</strong> muestra todas las tareas de GRC asignadas a usted en todas las evaluaciones. Las tareas se crean durante las evaluaciones para delegar trabajo como recopilación de evidencias, medidas correctivas, revisiones o documentación.</p>

    <h3>Cómo usar</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Evaluación y Auditoría</span> &rarr; <span class="menu-label">Bandeja de Entrada de Tareas</span>.</li>
        <li>Verá una lista de tareas asignadas a usted. Cada tarea muestra: título, tipo (Solicitud de Evidencia, Medida Correctiva, Revisión, Documentación, Implementación), prioridad, fecha límite y estado.</li>
        <li>Haga clic en una tarea para ver los detalles y actualizar su estado.</li>
        <li>Marque las tareas como <span class="status-label">En Progreso</span> cuando comience a trabajar y como <span class="status-label">Completada</span> cuando terminen.</li>
    </ol>
</div>


<div class="doc-section" id="grc-dashboard">
    <h2>Panel de GRC</h2>
    <p>El <strong>Panel de GRC</strong> es su centro de mando de cumplimiento &mdash; una vista general de página única de toda su postura GRC.</p>

    <h3>Qué muestra el Panel</h3>
    <ul>
        <li><strong>Mapa de Calor de Cumplimiento por Marco</strong> &mdash; Porcentajes de cumplimiento con código de colores para cada marco</li>
        <li><strong>Progreso de Implementación de Controles</strong> &mdash; Cuántos controles están implementados frente a los planificados</li>
        <li><strong>Vigencia de Evidencias</strong> &mdash; Cuántos elementos de evidencia están vigentes, por vencer o vencidos</li>
        <li><strong>Hallazgos Abiertos</strong> &mdash; Recuento y desglose por gravedad de los hallazgos de auditoría sin resolver</li>
        <li><strong>Estado de Revisión de Políticas</strong> &mdash; Políticas pendientes de revisión</li>
        <li><strong>Estado del Monitor</strong> &mdash; Estado de aprobado/fallido de los monitores continuos</li>
        <li><strong>Resumen del Registro de Riesgos</strong> &mdash; Riesgos abiertos por gravedad</li>
    </ul>

    <h3>Cómo acceder</h3>
    <p>Navegue a <span class="menu-label">Módulo GRC</span> &rarr; <span class="menu-label">Cumplimiento</span> &rarr; <span class="menu-label">Panel de GRC</span>.</p>
</div>


<!-- ================================================================
     TPRM MODULE
     ================================================================ -->
<div class="doc-section" id="tprm-overview">
    <h2>Módulo TPRM: ¿Qué es la Gestión de Riesgos de Terceros?</h2>
    <p>Todas las empresas dependen de proveedores externos &mdash; proveedores de nube, empresas de nómina, plataformas de marketing, consultores de TI. Cada proveedor puede tener acceso a sus datos o sistemas. <strong>TPRM</strong> le ayuda a responder: "¿Qué riesgo representa cada proveedor y están protegiendo nuestros datos?"</p>
    <ul>
        <li>Agregue y realice seguimiento de todos sus proveedores en un solo lugar</li>
        <li>Asigne un nivel de riesgo (Nivel 1 = mayor riesgo, Nivel 3 = menor)</li>
        <li>Envíe cuestionarios de seguridad (evaluaciones) a los proveedores</li>
        <li>Puntúe automáticamente a los proveedores usando servicios externos de calificación de seguridad</li>
        <li>Realice análisis cuantitativo de riesgos (FAIR) para estimar posibles pérdidas financieras</li>
        <li>Realice seguimiento del riesgo de cuarto nivel (los proveedores de sus proveedores)</li>
        <li>Descubra aplicaciones SaaS no gestionadas (Shadow SaaS)</li>
    </ul>
</div>

<div class="doc-section" id="tprm-add-vendor">
    <h2>Agregar un Nuevo Proveedor</h2>
    <ol class="steps">
        <li>En la barra lateral izquierda, expanda <span class="menu-label">Módulo TPRM</span>, luego expanda la sección <span class="menu-label">Partes Interesadas</span>.</li>
        <li>Haga clic en <span class="menu-label">Nueva Solicitud</span>. Esto abre el formulario de incorporación de proveedor.</li>
        <li>Complete los campos obligatorios:
            <ul>
                <li><span class="field-label">Nombre del Proveedor</span> &mdash; El nombre legal de la empresa (p. ej., "Acme Cloud Services")</li>
                <li><span class="field-label">Dominio del Proveedor</span> &mdash; El dominio de su sitio web sin https:// (p. ej., "acmecloud.com"). Utilizado por los motores de puntuación de seguridad para analizar al proveedor.</li>
            </ul>
        </li>
        <li>Complete los campos opcionales recomendados:
            <ul>
                <li><span class="field-label">Tipo de Proveedor</span> &mdash; Tecnología, Servicios Profesionales, Servicios Financieros, RRHH/Beneficios, etc.</li>
                <li><span class="field-label">Nivel del Proveedor</span> &mdash; 1 (Crítico), 2 (Importante) o 3 (Estándar)</li>
                <li><span class="field-label">Nombre del Contacto Principal</span>, <span class="field-label">Correo electrónico</span>, <span class="field-label">Teléfono</span></li>
                <li><span class="field-label">Recuento de Registros PII</span> &mdash; Cuántos registros personales accede este proveedor</li>
                <li><span class="field-label">Recuento de Registros SPII</span> &mdash; Cuántos registros personales sensibles (SSN, datos de salud)</li>
            </ul>
        </li>
        <li>Haga clic en <span class="btn-label">Guardar</span>. El proveedor se crea con estado <strong>Borrador</strong>.</li>
    </ol>

    <div class="callout callout-info">
        <strong>Explicación de los Niveles de Proveedor:</strong><br>
        &bull; <strong>Nivel 1 (Crítico)</strong> &mdash; Proveedores con acceso a datos sensibles o sistemas críticos. Requieren evaluación completa.<br>
        &bull; <strong>Nivel 2 (Importante)</strong> &mdash; Proveedores con acceso moderado. Requieren evaluación estándar.<br>
        &bull; <strong>Nivel 3 (Estándar)</strong> &mdash; Proveedores de bajo riesgo. Puede que solo requieran una revisión básica.
    </div>
</div>

<div class="doc-section" id="tprm-lifecycle">
    <h2>Ciclo de Vida del Proveedor</h2>
    <p>Los proveedores avanzan a través de un ciclo de vida definido:</p>
    <p><span class="status-label">Borrador</span> &rarr; <span class="status-label">Pendiente de Revisión</span> &rarr; <span class="status-label">En Revisión</span> &rarr; <span class="status-label">Aprobado</span> (o <span class="status-label">Rechazado</span>) &rarr; <span class="status-label">Activo</span> &rarr; <span class="status-label">Revisión Anual</span> &rarr; <span class="status-label">Dado de Baja</span></p>
    <p>Cada etapa desencadena los flujos de trabajo, notificaciones y acciones requeridas correspondientes.</p>
</div>

<div class="doc-section" id="tprm-assessments">
    <h2>Evaluaciones de Proveedores</h2>
    <p>Las evaluaciones de proveedores son cuestionarios de seguridad enviados a los proveedores para evaluar su postura de seguridad. Navegue a <span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Evaluaciones de Proveedores</span> para gestionarlas.</p>
    <ol class="steps">
        <li>Abra la página de detalles de un proveedor.</li>
        <li>Haga clic en <span class="btn-label">Enviar Evaluación</span>.</li>
        <li>Seleccione la plantilla de evaluación adecuada para el nivel del proveedor.</li>
        <li>El proveedor recibe un correo electrónico con un enlace para completar el cuestionario.</li>
        <li>Una vez enviado, revise las respuestas del proveedor y puntúelas.</li>
    </ol>
</div>

<div class="doc-section" id="assessment-forms">
    <h2>Formularios de Evaluación: Descargar, Rellenar, Importar y Autorrelleno con IA <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>No todos los proveedores quieren responder un cuestionario en el navegador. Desde la página de una evaluación individual (<span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Evaluaciones de Proveedores</span> &rarr; abra una evaluación) puede entregar al proveedor una copia sin conexión, recuperar un archivo completado o dejar que un proveedor de IA rellene previamente las respuestas a partir de los propios certificados del proveedor. Los botones están en una fila cerca de la parte superior de la evaluación.</p>

    <h3>Descargar la evaluación como archivo rellenable</h3>
    <ul>
        <li><span class="btn-label">Descargar PDF</span> &mdash; un formulario PDF rellenable. Cada pregunta se convierte en un campo de formulario real, para que el proveedor pueda escribir y marcar casillas directamente en el archivo.</li>
        <li><span class="btn-label">Descargar Excel</span> &mdash; un libro <code>.xlsx</code> real que puede completarse en Excel, Google Sheets o LibreOffice. Las preguntas de opción única incluyen menús desplegables en celda, y las preguntas condicionales se atenúan automáticamente cuando no aplican.</li>
    </ul>
    <div class="callout callout-info">
        <strong>El PDF rellenable ahora funciona en cualquier navegador, no solo en Adobe.</strong> Las casillas de verificación llevan apariencias integradas para que se muestren y alternen en Chrome, Edge y otros visores de PDF integrados (anteriormente solo funcionaban en Adobe Acrobat/Reader). Un campo de nombre escrito etiquetado como <strong>&ldquo;SIGNATURE (TYPE FULL NAME)&rdquo;</strong> permite que cualquiera firme en cualquier visor; los campos de firma digital y de fecha de firma exclusivos de Adobe permanecen ocultos salvo en Acrobat/Reader, que sí pueden usarlos.
    </div>

    <h3>Importar una evaluación completada (PDF, Excel o CSV)</h3>
    <p>Cuando el proveedor devuelve el archivo terminado, haga clic en <span class="btn-label">Importar Evaluación Completada</span> y cárguelo. La plataforma detecta el formato automáticamente &mdash; un <strong>PDF</strong>, <strong>Excel (.xlsx)</strong> o <strong>CSV</strong> completado &mdash; y fusiona las respuestas con las respuestas existentes de la evaluación.</p>
    <div class="callout callout-warning">
        <strong>La Referencia del archivo debe coincidir.</strong> Cada archivo descargado lleva una <strong>Referencia</strong> oculta (el ID de la evaluación). Si la Referencia falta o pertenece a una evaluación diferente, la importación se rechaza y no se escribe nada &mdash; por lo que las respuestas nunca pueden acabar en la evaluación equivocada.
    </div>

    <h3>¿Tiene un certificado en su lugar? <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>A un proveedor que completa una evaluación se le puede ofrecer un atajo: si posee una certificación relevante, puede cargarla en lugar de responder cada pregunta. El mensaje <strong>&ldquo;¿Tiene un Certificado?&rdquo;</strong> ahora muestra las <strong>Instrucciones de Carga de Certificado</strong> que haya escrito el autor de la plantilla, por lo que ya no se limita a ISO 27001 &mdash; una plantilla puede invitar a un SOC 2 Tipo 2, ISO 27001 o cualquier otro certificado. (Los autores de plantillas establecen este texto en el Constructor de Plantillas; consulte <a href="#admin-templates">Constructor de Plantillas de Evaluación</a>.)</p>

    <h3>Autorrelleno con IA a partir de Certificaciones <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>Si su administrador ha configurado un <a href="#admin-ai">proveedor de IA</a>, un revisor autorizado puede dejar que la IA lea los documentos de certificación cargados por el proveedor y rellene previamente el cuestionario. Haga clic en <span class="btn-label">&#9889; Autorrellenar a partir de Certificaciones</span> en la página de evaluación.</p>
    <figure class="doc-figure">
        <img src="app/docs/assessment-ai-autofill.png" alt="The Auto-Fill from Certifications button on a vendor assessment" loading="lazy">
        <figcaption><strong>Autorrellenar a partir de Certificaciones.</strong> Con un proveedor de IA configurado, el botón aparece junto a <strong>Descargar PDF</strong>, <strong>Descargar Excel</strong> e <strong>Importar Evaluación Completada</strong>. Lee los documentos de certificación actuales del proveedor y rellena las preguntas que esos documentos responden.</figcaption>
    </figure>
    <p>Cuando hace clic, se le recuerda: <em>&ldquo;Esto analizará los documentos de certificación del proveedor y rellenará previamente las preguntas sin responder. Las respuestas existentes no se modificarán.&rdquo;</em> La IA luego revisa los certificados del proveedor e informa, por ejemplo, <em>&ldquo;Rellenadas 12 de 30 preguntas sin responder.&rdquo;</em> Algunas cosas que conviene saber:</p>
    <ul>
        <li><strong>Solo se usan certificados actuales.</strong> Lee los documentos cargados por el proveedor cuyo tipo es <em>Certificación</em> y que están <strong>activos y no vencidos</strong> (documentos PDF, CSV y Excel; los más recientes). Un certificado vencido o reemplazado se ignora.</li>
        <li><strong>Solo rellena espacios en blanco.</strong> Las preguntas que ya ha respondido se dejan intactas, y nunca sobrescribe una respuesta existente.</li>
        <li><strong>Responde únicamente a partir de lo que los documentos realmente dicen.</strong> La IA tiene instrucciones de no adivinar; cualquier cosa que no pueda respaldar con confianza a partir de los documentos se deja sin responder para que una persona la complete.</li>
        <li><strong>Usted mantiene el control.</strong> Las respuestas rellenadas se guardan y la página se recarga mostrándolas, para que pueda revisar y cambiar cualquier respuesta antes de que se envíe la evaluación.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Quién puede usarlo y cuándo aparece.</strong> El botón se muestra solo a usuarios <strong>Administradores</strong> y <strong>Cyber TPRM</strong>, solo cuando hay un proveedor de IA habilitado, y solo cuando el proveedor tiene al menos un documento de certificación actual en archivo. Se oculta en evaluaciones completadas.
    </div>
</div>

<div class="doc-section" id="tprm-action-plan">
    <h2>Plan de Acción del Proveedor <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>La pestaña <strong>Plan de Acción</strong> en la página de un proveedor permite al equipo de ciberseguridad programar trabajo de seguimiento para ese proveedor &mdash; contactar al proveedor, enviar otra evaluación, forzar una revisión anual &mdash; con una fecha de vencimiento, responsables y un conjunto continuo de notas de estado. Un trabajo diario dispara cada acción cuando llega su fecha y la convierte en una tarea pendiente con seguimiento.</p>
    <p>Abra un proveedor desde <span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Incorporación de Proveedores</span> &rarr; <span class="menu-label">Mis Proveedores</span>, luego haga clic en la pestaña <strong>Plan de Acción</strong>. La pestaña está disponible para usuarios <strong>Administradores</strong> y <strong>Cyber TPRM</strong>.</p>

    <h3>Programar una acción</h3>
    <ol class="steps">
        <li>En la pestaña <strong>Plan de Acción</strong>, haga clic en <span class="btn-label">+ Crear Acción</span>.</li>
        <li>Elija la <span class="field-label">Acción</span>: <strong>Contactar Proveedor</strong>, <strong>Contactar Parte Interesada</strong>, <strong>Enviar Evaluación</strong> o <strong>Forzar Revisión Anual</strong>. (Si elige <strong>Enviar Evaluación</strong>, aparece un selector de <span class="field-label">Evaluación de Proveedor</span> para que pueda elegir qué plantilla enviar.)</li>
        <li>Establezca la <span class="field-label">Fecha de Vencimiento</span> &mdash; el día en que debe dispararse la acción.</li>
        <li>En <span class="field-label">Asignar a (Cyber TPRM)</span>, marque uno o más responsables de Cyber TPRM. (Si no hay ninguno, la acción recae en la parte interesada del proveedor.)</li>
        <li>Opcionalmente marque <span class="field-label">Enviar correo electrónico a las personas asignadas cuando se dispare esta acción</span>, y use <span class="field-label">Direcciones de correo electrónico de notificación</span> para enviar a direcciones específicas en su lugar &mdash; separadas por comas. Déjelo en blanco para usar los correos electrónicos de cuenta de los propios asignados.</li>
        <li>Escriba una <span class="field-label">Descripción</span> (se traslada a la tarea pendiente que se crea), luego haga clic en <span class="btn-label">Crear Acción</span>.</li>
    </ol>

    <h3>Qué sucede cuando se dispara una acción</h3>
    <p>Cada acción se dispara una vez, en su fecha de vencimiento o después. El disparo crea una <strong>Tarea Pendiente de Ciberseguridad</strong> vinculada que enlaza directamente de vuelta a esta pestaña Plan de Acción, ejecuta la acción (para <strong>Enviar Evaluación</strong> envía por correo electrónico el cuestionario al proveedor; para <strong>Forzar Revisión Anual</strong> marca la revisión anual como vencida) y &mdash; si lo habilitó &mdash; envía correos electrónicos a los responsables o a las direcciones que indicó.</p>

    <h3>Estados de las acciones</h3>
    <p>Una acción avanza por estos estados:</p>
    <p><span class="status-label">Pendiente</span> &rarr; <span class="status-label">En Progreso</span> (establecido automáticamente cuando se dispara) &rarr; <span class="status-label">Completada</span>, o <span class="status-label">Problema</span> si algo salió mal al dispararse, o <span class="status-label">Cancelada</span> si la cancela antes de que se dispare. Puede cambiar el estado usted mismo en cualquier momento; el trabajo diario nunca sobrescribe un estado que usted haya establecido.</p>

    <h3>Notas de estado</h3>
    <p>Abra una acción para agregar <strong>Notas de Estado</strong> con fecha a medida que avanza el trabajo. Escriba una nota y haga clic en <span class="btn-label">Agregar Nota</span>. Puede editar o eliminar sus propias notas; los administradores pueden editar o eliminar las de cualquiera. Cada creación, edición y eliminación queda registrada en la auditoría.</p>

    <div class="callout callout-info">
        <strong>El trabajo &ldquo;Vendor Remediation Schedule&rdquo;.</strong> El trabajo diario que dispara las acciones vencidas se llama <strong>Vendor Remediation Schedule</strong> y se ejecuta todos los días a las <strong>7:00 AM</strong> por defecto. Los administradores pueden habilitarlo, deshabilitarlo o reprogramarlo en la página <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Programador</span>. Si ha estado apagado, se pone al día la próxima vez que se ejecuta, disparando todo lo que venció mientras tanto.
    </div>
</div>

<div class="doc-section" id="tprm-srs">
    <h2>Tarjeta de Puntuación de Riesgo de Seguridad (SRS)</h2>
    <p>La SRS proporciona una puntuación de seguridad externa automatizada para cada proveedor basada en la configuración DNS, SSL/TLS, seguridad del correo electrónico (SPF, DKIM, DMARC), puertos abiertos y otros indicadores técnicos.</p>
    <p>Navegue a <span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Módulos</span> &rarr; <span class="menu-label">Tarjeta de Puntuación de Riesgo de Seguridad</span>.</p>
</div>

<div class="doc-section" id="tprm-fair">
    <h2>Análisis FAIR</h2>
    <p><strong>FAIR</strong> (Factor Analysis of Information Risk) es un modelo cuantitativo de riesgos que estima la pérdida financiera probable a raíz de un incidente de seguridad con un proveedor.</p>
    <p>Navegue a <span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Módulos</span> &rarr; <span class="menu-label">Análisis FAIR</span> para crear y ver análisis.</p>
</div>

<div class="doc-section" id="tprm-fourth-party">
    <h2>Riesgo de Cuarto Nivel</h2>
    <p>Realice seguimiento de los proveedores de los que <em>sus proveedores</em> dependen. Si su proveedor de nube utiliza un subcontratista para el almacenamiento de datos, eso es un riesgo de cuarto nivel. Desde la barra lateral puede abrir <span class="menu-label">Riesgo de Cuarto Nivel</span> (concentración tecnológica), <span class="menu-label">Búsqueda de CVE</span> y <span class="menu-label">Subprocesadores</span>. Está disponible para administradores y usuarios de Cyber TPRM; los auditores pueden ver pero no actuar.</p>

    <h3>Concentración de subprocesadores <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>Abra <span class="menu-label">Subprocesadores</span> para ver la vista de <strong>Concentración de Subprocesadores</strong>: cada subprocesador que sus proveedores han declarado, y cuántos de sus proveedores usan cada uno. Un subprocesador compartido por varios proveedores se resalta &mdash; esa dependencia compartida es un riesgo de concentración en la cadena de suministro. (Los subprocesadores se agregan a un proveedor desde la página de detalles de ese proveedor.)</p>

    <h3>Enviar una evaluación a todos los que usan un subprocesador <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>Cuando un subprocesador concentra el riesgo, puede encuestar en una sola acción a los proveedores que dependen de él:</p>
    <ol class="steps">
        <li>En la lista de Subprocesadores, haga clic en <span class="btn-label">Enviar Evaluación</span> en la fila de ese subprocesador.</li>
        <li>En el selector de proveedores, elija cuáles de los proveedores que usan ese subprocesador deben recibir la evaluación (o <span class="field-label">Seleccionar Todos los Visibles</span>), luego continúe.</li>
        <li>Elija una <span class="field-label">Plantilla de Evaluación</span> y una ventana de <span class="field-label">Vence En</span> (14, 30, 60 o 90 días), luego haga clic en <span class="btn-label">Asignar Evaluación</span>.</li>
    </ol>
    <p>A cada proveedor seleccionado se le envía por correo electrónico el cuestionario (una solicitud de información), y se realiza un seguimiento de un recordatorio para que los seguimientos se envíen automáticamente. Los proveedores sin correo electrónico en archivo se omiten, y cualquier envío que falle es reintentado por el trabajo de recordatorio. El mismo flujo de <strong>Asignar Evaluación</strong> está disponible desde las vistas de concentración tecnológica y de CVE.</p>
</div>

<div class="doc-section" id="tprm-shadow-saas">
    <h2>Descubrimiento de Shadow SaaS</h2>
    <p>Descubra aplicaciones SaaS que se usan en su organización y que puede que no hayan sido aprobadas o evaluadas formalmente. Navegue a <span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Módulos</span> &rarr; <span class="menu-label">Shadow SaaS</span>. En v2.6.2 esta lista puede completarse automáticamente con la integración de Shadow SaaS de <a href="#shadow-saas-grip">Grip</a> o <a href="#shadow-saas-hero">Hero</a>, y las aplicaciones no autorizadas pueden bloquearse en <a href="#zscaler">Zscaler</a>.</p>
</div>


<!-- ================================================================
     VENDOR ONBOARDING & PROCUREMENT (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="onboarding-workflow">
    <h2>Incorporación de Proveedores y Compras <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>Una <strong>solicitud de incorporación de proveedor</strong> es la forma en que un nuevo proveedor entra en la plataforma. Avanza a través de una serie de <strong>estados</strong> desde el primer borrador hasta la decisión final. Antes de que el equipo de ciberseguridad revise a un proveedor, este debe ser <strong>incorporado a través de su proceso de compras</strong> y tener un <strong>ID de Proveedor (VID)</strong> válido. Esta sección explica por qué y cómo funciona exactamente.</p>

    <h3>El proceso de incorporación (estados)</h3>
    <table class="doc-table">
        <tr><th>Estado</th><th>Qué significa</th></tr>
        <tr><td><span class="status-label">Borrador</span></td><td>La solicitud está siendo completada. Aún no ha sido enviada para revisión.</td></tr>
        <tr><td><span class="status-label">Enviada</span></td><td>La solicitud superó las verificaciones de envío y ha sido enviada al equipo de ciberseguridad.</td></tr>
        <tr><td><span class="status-label">En Revisión</span></td><td>El equipo de ciberseguridad está revisando al proveedor.</td></tr>
        <tr><td><span class="status-label">Revisión de IA</span></td><td>Los servicios del proveedor usan IA y se encuentra en la etapa de revisión de IA dedicada (consulte <a href="#ai-review">Revisión de IA</a>).</td></tr>
        <tr><td><span class="status-label">Evaluación</span></td><td>El proveedor está siendo probado o evaluado.</td></tr>
        <tr><td><span class="status-label">Aprobado</span></td><td>El proveedor ha sido aprobado e incorporado.</td></tr>
        <tr><td><span class="status-label">Rechazado</span></td><td>El proveedor no fue aprobado.</td></tr>
        <tr><td><span class="status-label">Inactivo</span></td><td>El proveedor ya no está activo.</td></tr>
    </table>

    <h3>Cómo encontrar sus solicitudes de proveedor</h3>
    <p>Vaya a <span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Partes Interesadas</span> &rarr; <span class="menu-label">Incorporación de Proveedores</span>. Verá una lista de proveedores con búsqueda, con su estado, nivel, puntuación de seguridad (SRS) y acciones rápidas (Ver, Editar). Use los filtros en la parte superior (por ejemplo <strong>Todos</strong>, <strong>Aprobados</strong>, <strong>Revisión</strong>) para reducir la lista. Use <span class="btn-label">+ Nueva Solicitud</span> para iniciar un nuevo proveedor.</p>
    <figure class="doc-figure">
        <img src="app/docs/vendor-onboarding-list.png" alt="Vendor Onboarding Requests list" loading="lazy">
        <figcaption><strong>Lista de Incorporación de Proveedores.</strong> Búsqueda, filtros y acciones por proveedor. El filtro <strong>Revisión</strong> es una vista única que combina proveedores <em>En Revisión</em> y en <em>Revisión de IA</em>.</figcaption>
    </figure>

    <h3 id="procurement-onboarding">Las dos cosas que todo proveedor necesita antes de la revisión</h3>
    <p>Abra un proveedor y observe la tarjeta de <strong>Información del Proveedor</strong>. Dos campos controlan si el proveedor puede enviarse a revisión de ciberseguridad:</p>
    <ul>
        <li><strong>Incorporación de Compras</strong> &mdash; un campo Sí/No que responde a la pregunta <em>"¿Ha completado este proveedor la Incorporación de Compras?"</em> Debe estar configurado en <strong>Sí</strong>.</li>
        <li><strong>ID de Proveedor (VID)</strong> &mdash; el identificador de 4&ndash;8 dígitos asignado al proveedor por su sistema de compras. Debe ser un número válido de 4&ndash;8 dígitos.</li>
    </ul>
    <figure class="doc-figure">
        <img src="app/docs/vendor-info-card.png" alt="Vendor Information card showing Procurement Onboarding and Vendor ID fields" loading="lazy">
        <figcaption><strong>Tarjeta de Información del Proveedor.</strong> El <strong>ID de Proveedor (VID)</strong> y el campo de incorporación de compras deben estar completados antes de que el proveedor pueda enviarse. <em>Nota:</em> en instancias actualizadas desde una versión anterior, este campo puede seguir mostrando <strong>"VSU Onboarded"</strong>; en v2.6.2 se llama <strong>"Procurement Onboarding"</strong> &mdash; es el mismo campo.</figcaption>
    </figure>

    <h3>Envío de un proveedor para revisión</h3>
    <ol class="steps">
        <li>Abra el proveedor desde la lista de <span class="menu-label">Incorporación de Proveedores</span> (la solicitud debe estar en estado <strong>Borrador</strong>).</li>
        <li>En la tarjeta de <strong>Información del Proveedor</strong>, establezca <span class="field-label">Incorporación de Compras</span> en <strong>Sí</strong> e ingrese un <span class="field-label">ID de Proveedor (VID)</span> válido (4&ndash;8 dígitos). Guarde sus cambios.</li>
        <li>Haga clic en <span class="btn-label">Enviar para Revisión</span>. Se le pedirá que confirme: <em>"¿Enviar este proveedor para revisión? El proveedor debe tener un VID válido y estar incorporado en VSU."</em></li>
        <li>Si ambas verificaciones pasan, el estado cambia a <strong>Enviado</strong> y se notifica al equipo de ciberseguridad.</li>
    </ol>
    <div class="callout callout-danger">
        <strong>Si el envío está bloqueado,</strong> verá uno de estos mensajes:
        <ul style="margin:8px 0 0;">
            <li>"Cannot submit: Vendor must be onboarded at VSU before submission. Please complete the onboarding assessment with VSU details." &rarr; establezca <strong>Incorporación de Compras</strong> en <strong>Sí</strong>.</li>
            <li>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits). Please complete the onboarding assessment with the VSU Vendor ID." &rarr; ingrese un <strong>ID de Proveedor</strong> válido de 4&ndash;8 dígitos.</li>
        </ul>
        Consulte <a href="#troubleshooting">Solución de Problemas</a> para saber por qué existe esta regla.
    </div>
</div>

<div class="doc-section" id="custom-onboarding">
    <h2>Campos de Incorporación Personalizados y la Pestaña de Datos Personalizados <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>Los campos estándar de proveedor (nombre, dominio, nivel, VAT, etc.) cubren la mayoría de las necesidades, pero cada organización registra algo adicional. En v2.6.2 una plantilla de incorporación puede definir <strong>campos personalizados</strong> que no tienen una columna estándar de proveedor. Sus valores se capturan por proveedor y se muestran en la pestaña <strong>Datos Personalizados</strong> del proveedor.</p>

    <h3>Dónde viven los valores personalizados: la pestaña Datos Personalizados</h3>
    <p>Abra un proveedor (<span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Incorporación de Proveedores</span> &rarr; <span class="menu-label">Mis Proveedores</span> &rarr; abra un proveedor). Si la plantilla de incorporación del proveedor define campos personalizados, aparece una pestaña <strong>Datos Personalizados</strong> junto a las demás pestañas del proveedor, con un recuento de cuántos valores personalizados hay en archivo. La pestaña es de solo lectura hasta que hace clic en <span class="btn-label">Editar</span>; realice sus cambios y haga clic en <span class="btn-label">Guardar Datos Personalizados</span>. Los campos se agrupan por su sección de plantilla. Los usuarios que tienen permitido ver un campo pero no editarlo lo ven marcado como <em>(solo lectura)</em>.</p>

    <h3>Definir un campo personalizado (administradores)</h3>
    <p>Un campo personalizado es simplemente una pregunta en una plantilla de categoría <strong>Incorporación</strong> cuyo <span class="field-label">Nombre de Campo</span> <em>no</em> es una columna integrada de proveedor. Hay dos pasos, ambos en el Portal de Administración:</p>
    <ol class="steps">
        <li><strong>Registre el nombre de campo.</strong> Vaya a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Referencia de Campos</span>, haga clic en <span class="btn-label">+ Agregar Campo</span> y agregue su campo personalizado (letras minúsculas, números y guiones bajos; p. ej. <code>data_residency_region</code>). Elija un tipo de columna (texto, número, fecha, etc.) y la categoría <span class="field-label">Incorporación</span>.</li>
        <li><strong>Agregue una pregunta que se asigne a él.</strong> En <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Constructor de Plantillas</span>, abra su plantilla de incorporación, agregue una pregunta y establezca su <span class="field-label">Nombre de Campo</span> al campo que acaba de registrar. Consulte <a href="#admin-templates">Constructor de Plantillas de Evaluación</a>.</li>
    </ol>

    <h3>Nuevos tipos de campo para respuestas más ricas <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>Además de los tipos existentes de texto, número, fecha, desplegable y opción única, las preguntas (personalizadas o estándar) ahora pueden usar:</p>
    <table class="doc-table">
        <tr><th>Tipo</th><th>Qué ve el proveedor</th></tr>
        <tr><td><strong>Casillas de Verificación</strong></td><td>Una lista de selección múltiple &mdash; marque cada opción que aplique.</td></tr>
        <tr><td><strong>Grupo de Botones (Múltiple)</strong></td><td>La misma selección múltiple, mostrada como una fila de botones de alternancia.</td></tr>
        <tr><td><strong>Teléfono</strong></td><td>Un número de teléfono con selector de código de país y bandera (consulte <a href="#question-types">Tipos de Pregunta de Teléfono y VAT</a>).</td></tr>
        <tr><td><strong>Número de VAT</strong></td><td>Un número de IVA de la UE con doble entrada y validación en vivo de VIES (consulte <a href="#question-types">Tipos de Pregunta de Teléfono y VAT</a>).</td></tr>
    </table>
    <p>Los equivalentes de selección única (<strong>Desplegable</strong>, <strong>Botones de Opción</strong>, <strong>Grupo de Botones</strong>) siguen disponibles. Los tipos de selección múltiple necesitan una lista de <span class="field-label">Opciones</span> (una por línea).</p>

    <h3>Controlar quién puede ver y editar un campo (restricción por rol) <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>En las plantillas de incorporación, cada sección y pregunta <strong>personalizada</strong> lleva dos controles de rol, para que pueda mantener los campos sensibles alejados de personas que no deberían verlos:</p>
    <ul>
        <li><span class="field-label">Visible para Roles</span> &mdash; qué roles pueden <em>ver</em> el campo.</li>
        <li><span class="field-label">Roles Visibles y Editables</span> &mdash; qué roles pueden <em>editarlo</em>.</li>
    </ul>
    <div class="callout callout-info">
        <strong>Los campos personalizados son privados por defecto.</strong> A diferencia de una pregunta estándar, un campo personalizado está oculto hasta que otorga un rol. Hasta que se otorgue un rol, solo los superadministradores pueden verlo o editarlo. Un campo que un visor no tiene permitido ver se omite de la pestaña Datos Personalizados, la página del proveedor, la exportación CSV y la API para esa persona. (Este valor predeterminado de solo por concesión aplica a los campos personalizados; las preguntas de incorporación estándar nunca se restringen de esta manera.)
    </div>
    <p>Tanto la concesión de una sección como la de una pregunta deben permitir a una persona antes de que vea esa pregunta, para que pueda ocultar una sección completa o solo campos individuales dentro de ella.</p>

    <h3>Valores personalizados en exportaciones y la API</h3>
    <ul>
        <li><strong>Exportación CSV.</strong> En la lista de <span class="menu-label">Incorporación de Proveedores</span>, <span class="btn-label">Exportar CSV</span> (administradores y Cyber TPRM) ahora agrega una columna por cada campo personalizado, con el nombre <code>custom:&lt;field_name&gt;</code>, junto a las columnas estándar.</li>
        <li><strong>API REST.</strong> La respuesta de un solo proveedor (<code>GET /vendors/{id}</code>) incluye un arreglo <code>custom_onboarding_data</code>; cada entrada tiene <code>field_name</code>, <code>label</code>, <code>value</code>, <code>type</code>, <code>section</code> y <code>template_name</code>.</li>
    </ul>
    <p>Ambos leen del mismo lugar que la pestaña Datos Personalizados y respetan la misma visibilidad basada en roles &mdash; un campo que el solicitante (o el propietario de la clave API) no puede ver se deja en blanco o se omite.</p>
</div>

<div class="doc-section" id="ai-review">
    <h2>Revisión de IA para Proveedores <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>Algunos proveedores ofrecen servicios que utilizan inteligencia artificial. Estos proveedores pueden conllevar diferentes riesgos, por lo que v2.6.2 añade un estado dedicado de <strong>Revisión de IA</strong> para hacerles seguimiento por separado durante el proceso de revisión.</p>

    <h3>Cómo un proveedor entra en Revisión de IA</h3>
    <p>En la tarjeta de <strong>Información del Proveedor</strong> hay un campo <span class="field-label">Los Servicios Usan IA</span>. Cuando está configurado en <strong>Sí</strong>, un revisor autorizado (un usuario de <strong>Cyber TPRM</strong> o <strong>Administrador</strong>, mientras edita el proveedor) ve un enlace <span class="btn-label">Forzar Revisión de IA</span> directamente debajo de ese campo.</p>
    <ol class="steps">
        <li>Abra el proveedor y confirme que <span class="field-label">Los Servicios Usan IA</span> esté configurado en <strong>Sí</strong>.</li>
        <li>Haga clic en <span class="btn-label">Forzar Revisión de IA</span>. Confirme el mensaje: <em>"¿Forzar a este proveedor a Revisión de IA?"</em></li>
        <li>El estado del proveedor cambia a <strong>Revisión de IA</strong>.</li>
    </ol>
    <div class="callout callout-info">
        <strong>¿Por qué puede que no aparezca el enlace?</strong> El enlace <strong>Forzar Revisión de IA</strong> solo se muestra cuando (1) tiene permiso para aprobar, (2) está en modo de edición, (3) <strong>Los Servicios Usan IA</strong> está en <strong>Sí</strong> y (4) el proveedor no está ya en Revisión de IA. Si <strong>Los Servicios Usan IA</strong> es "No", verá el mensaje <em>"La Revisión de IA solo puede forzarse para proveedores cuyos servicios usen IA."</em></p>
    </div>
    <p>En la página de <a href="#procurement-cyber-status">Estado Cibernético de Compras</a> y en el filtro <strong>Revisión</strong> de la lista de proveedores, los proveedores en <strong>En Revisión</strong> y <strong>Revisión de IA</strong> se muestran juntos &mdash; por lo que nada en revisión queda oculto por estar siendo revisado con IA.</p>
</div>

<div class="doc-section" id="procurement-cyber-status">
    <h2>Estado Cibernético de Compras <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>La página de <strong>Estado Cibernético</strong> ofrece al <strong>equipo de compras</strong> una vista simple y siempre actualizada de qué proveedores está revisando el equipo de ciberseguridad y cuál es la última novedad sobre cada uno &mdash; sin necesidad de acceder a las herramientas de seguridad completas. El equipo de ciberseguridad publica actualizaciones breves con fecha; compras las lee aquí (y en un correo electrónico semanal).</p>
    <p>Ábrala desde <span class="menu-label">Módulo TPRM</span> &rarr; <span class="menu-label">Compras</span> &rarr; <span class="menu-label">Estado Cibernético</span>. Está disponible para usuarios de <strong>Procurement</strong>, <strong>Cyber TPRM</strong> y <strong>Administrador</strong>.</p>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status.png" alt="Procurement Cyber Status page" loading="lazy">
        <figcaption><strong>Compras &rarr; Estado Cibernético.</strong> Lista todos los proveedores cuyo estado es <em>En Revisión</em> o <em>Revisión de IA</em>, con el número de actualizaciones y la fecha de la última actualización. Cuando no hay proveedores en revisión, la tabla es reemplazada por un mensaje "No hay proveedores en revisión".</figcaption>
    </figure>

    <h3>Lectura del historial de actualizaciones de un proveedor</h3>
    <ol class="steps">
        <li>Haga clic en el nombre de un proveedor en la tabla de <strong>Proveedores en Revisión</strong>.</li>
        <li>Se abre el panel de <strong>Historial de Actualizaciones de Compras</strong>, que muestra cada actualización de la más reciente a la más antigua: la fecha y hora, quién la escribió, el estado del proveedor en ese momento y la nota en sí.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/procurement-cyber-status-updates.png" alt="Procurement Update History for a vendor in review" loading="lazy">
        <figcaption><strong>Historial de actualizaciones de un proveedor.</strong> Hacer clic en el nombre de un proveedor abre su <strong>Historial de Actualizaciones de Compras</strong>. Cada entrada muestra la fecha y hora, el autor, una insignia del estado del proveedor cuando se escribió la nota y la nota del equipo de ciberseguridad &mdash; para que compras pueda ver exactamente en qué punto se encuentra cada revisión. Los historiales largos se paginan con el control <em>Mostrar&nbsp;por&nbsp;página</em>.</figcaption>
    </figure>

    <h3>Para revisores de ciberseguridad: publicar una actualización para compras</h3>
    <p>Los usuarios de Cyber TPRM y los administradores pueden publicar una actualización para uno o más proveedores a la vez:</p>
    <ol class="steps">
        <li>En la página de <strong>Estado Cibernético</strong>, marque la casilla junto a cada proveedor que desee actualizar.</li>
        <li>Haga clic en <span class="btn-label">Proporcionar Actualización a Compras</span>.</li>
        <li>En la ventana <strong>Proporcionar Actualización a Compras</strong>, escriba su nota en el cuadro <span class="field-label">Actualización</span>.</li>
        <li>Opcionalmente use <span class="field-label">Cambiar estado</span> para avanzar el/los proveedor(es) (por ejemplo a <strong>Evaluación</strong>, <strong>Aprobado</strong> o <strong>Rechazado</strong>). Déjelo en <em>Mantener estado actual</em> para solo agregar una nota.</li>
        <li>Haga clic en <span class="btn-label">Guardar Actualización</span>. La actualización queda registrada en cada proveedor seleccionado.</li>
    </ol>

    <h3>El correo electrónico de resumen semanal de compras</h3>
    <p>Para mantener informado a compras sin que nadie necesite iniciar sesión, la plataforma puede enviar por correo electrónico un <strong>resumen semanal</strong> con todos los proveedores en revisión junto con su actualización más reciente. Por defecto esto se envía <strong>todos los lunes a las 7:00 AM</strong>.</p>
    <ol class="steps">
        <li>Un administrador va a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Configuración de Correo Electrónico</span> y busca las opciones de <strong>Resumen de Actualizaciones de Compras</strong>.</li>
        <li>Active el resumen y escriba una o más direcciones de correo electrónico de destinatarios (separadas por comas).</li>
        <li>Guarde. También puede enviarlo inmediatamente con <span class="btn-label">Enviar resumen ahora</span> para probarlo.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-scheduler.png" alt="Admin Scheduler showing the Procurement Update Digest job" loading="lazy">
        <figcaption><strong>Admin &rarr; Programador.</strong> El trabajo de <strong>Resumen de Actualizaciones de Compras</strong> (al final de la lista) se ejecuta semanalmente. El Programador es donde los administradores habilitan, deshabilitan y programan todos los trabajos automatizados.</figcaption>
    </figure>
</div>

<!-- ================================================================
     INTEGRATIONS: GRIP + ZSCALER (NEW IN 2.6.2)
     ================================================================ -->
<div class="doc-section" id="shadow-saas-grip">
    <h2>Integración Grip Shadow SaaS <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>"Shadow SaaS" son las aplicaciones en la nube que los empleados usan y que nunca fueron aprobadas formalmente. <strong>Grip Security</strong> es un servicio que descubre estas aplicaciones. En v2.6.2 puede conectar su cuenta de Grip para que la plataforma importe automáticamente las aplicaciones que Grip encuentra &mdash; junto con cuántas personas usa cada una, una puntuación de riesgo y alertas de seguridad &mdash; y las muestre en su página de <a href="#tprm-shadow-saas">Shadow SaaS</a>.</p>
    <p>Lo configura un administrador en <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> en la pestaña <strong>Grip</strong>. Grip es uno de dos proveedores de Shadow SaaS (el otro es <a href="#shadow-saas-hero">Hero</a>); solo uno puede estar habilitado a la vez.</p>

    <h3>Conexión de Grip (paso a paso)</h3>
    <ol class="steps">
        <li>En Grip, cree un <strong>token de API</strong> y anote la URL base de su tenant (termina en <code>/public/saas</code>, por ejemplo <code>https://tenant.dep.grip.security/public/saas</code>).</li>
        <li>En la plataforma, vaya a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> y busque la tarjeta de <strong>Conexión de Grip Security</strong>.</li>
        <li>Marque <span class="field-label">Habilitar integración de Grip Security</span>.</li>
        <li>Pegue la URL de su tenant en <span class="field-label">Servidor (URL Base del Tenant)</span> y su token en <span class="field-label">Token de API</span>.</li>
        <li>Haga clic en <span class="btn-label">Guardar Configuración</span>, luego haga clic en <span class="btn-label">Probar Conexión</span> para confirmar. Un mensaje de éxito tiene el aspecto <em>"Conectado a Grip — muestra devuelta de 1 registro(s)"</em>.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/grip-card.png" alt="Grip Security Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Conexión de Grip Security.</strong> Ingrese su URL de tenant y token de API, guarde y luego pruebe.</figcaption>
    </figure>

    <h3>Mantenerlo actualizado automáticamente</h3>
    <p>Use la tarjeta compartida de <strong>Rehidratación Programada</strong> (debajo de las pestañas del proveedor) para actualizar los datos del proveedor habilitado según un calendario. Marque <span class="field-label">Habilitar rehidratación programada</span> e ingrese una <span class="field-label">Programación (expresión cron)</span> &mdash; por ejemplo <code>0 2 * * *</code> para diariamente a las 2 AM; la tarjeta muestra un resumen en texto claro de lo que escribió. El trabajo se instala automáticamente en el programador del sistema (sin pasos manuales en el servidor) y sobrevive a los reinicios. También puede hacer clic en <span class="btn-label">Ejecutar Ahora</span> para actualizar inmediatamente. La misma programación sirve para el proveedor que esté actualmente habilitado (Grip o Hero).</p>

    <h3>Qué verá después</h3>
    <p>Las aplicaciones descubiertas aparecen en la página de <span class="menu-label">Shadow SaaS</span> como entradas <strong>Pendientes</strong> con una puntuación de riesgo (mostrada en una escala de 1&ndash;5), categoría y número de usuarios. Desde allí puede <strong>Permitir</strong> una aplicación (lo que inicia su incorporación como proveedor), <strong>Denegar</strong> (marcarla como no autorizada y, opcionalmente, bloquearla en Zscaler) o <strong>Desestimar</strong>.</p>
    <figure class="doc-figure">
        <img src="app/docs/shadow-saas.png" alt="Shadow SaaS list page" loading="lazy">
        <figcaption><strong>La página de Shadow SaaS.</strong> Aplicaciones descubiertas e importadas con su riesgo y acciones. Las aplicaciones incorporadas como proveedores se omiten en sincronizaciones futuras, y las que desestima permanecen desestimadas.</figcaption>
    </figure>

    <h3>Fuente de datos En Vivo vs. Local (en caché) <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>En la tarjeta de Conexión de Grip Security, <span class="field-label">Fuente de datos</span> controla de dónde leen las páginas de Grip:</p>
    <ul>
        <li><strong>En Vivo</strong> &mdash; llama a la API de Grip en cada página. Siempre actual, pero más exigente con la API.</li>
        <li><strong>Local (Hidratada/en caché)</strong> &mdash; sirve desde la copia de los datos de Grip almacenada en la base de datos de la plataforma. Más ligera para la API. En modo Local, cada sincronización <strong>actualiza por completo</strong> esa copia; entre sincronizaciones las páginas sirven desde la instantánea en lugar de llamar a Grip.</li>
    </ul>

    <h3>Observar y controlar una sincronización <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>Mientras se ejecuta una sincronización, la tarjeta de <strong>Última Sincronización</strong> muestra una lectura de progreso en vivo &mdash; <em>&ldquo;Hidratando listas por aplicación &mdash; NN% (D / T aplicaciones)&rdquo;</em> &mdash; encima de un botón <span class="btn-label">Detener Sincronización</span> que cancela la ejecución de forma cooperativa. Para borrar por completo los datos de Grip servidos localmente, use <span class="btn-label">Truncar Datos</span> en la misma tarjeta: limpia las tablas espejo de Grip, las filas de Grip en la lista de Shadow SaaS y la telemetría de Grip estampada en los registros de proveedor (la pestaña Datos SaaS). Su historial de sincronización se conserva, y la siguiente sincronización rehidrata todo desde Grip.</p>

    <h3>Calificación de SecurityScorecard (SSC) <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>Cuando Grip está conectado, una columna <strong>SSC</strong> muestra la calificación por letra de <strong>SecurityScorecard</strong> (A&ndash;F) de cada aplicación o proveedor en la lista de Shadow SaaS y en la lista SRS de proveedores, y en la pestaña Datos SaaS del proveedor. Aparece solo mientras Grip está habilitado.</p>

    <h3>La pestaña &ldquo;Datos SaaS&rdquo; del proveedor <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>Cuando un proveedor coincide con una aplicación descubierta por Grip, aparece una pestaña de solo lectura <strong>Datos SaaS</strong> en la página de ese proveedor, que expone la telemetría de Grip recopilada durante la sincronización sin salir del proveedor: <strong>Primer Descubrimiento</strong>, <strong>Cuentas Activas</strong> (un enlace a la lista de usuarios afectados), <strong>Último Uso Conocido</strong>, clasificación de la aplicación, la calificación de <strong>Security Scorecard</strong>, categoría, profundidad de IA, señales de cumplimiento y soporte de SAML/MFA.</p>

    <h3>Alertas de brechas de Grip <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>Grip también puede alimentar incidentes de seguridad en la plataforma. Marque <span class="field-label">Enviar información de brechas de Grip a Alertas de Brechas / Ciberseguridad</span> en la tarjeta de conexión y las alertas de Grip &ldquo;Security Incident Detected&rdquo; se escriben en su lista de <a href="#breach-alerts">Alertas de Brechas / Ciberseguridad</a> en cada sincronización. (Esto también requiere que la función de Alertas de Brechas / Ciberseguridad esté habilitada en <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Configuración de Correo Electrónico</span>.)</p>
    <div class="callout callout-info">
        <strong>Los datos personales están cifrados en reposo.</strong> Los nombres, direcciones de correo electrónico y otros detalles personales de los datos de Grip en caché están cifrados en la base de datos y se descifran solo cuando se muestran en la aplicación o los devuelve la API. Esto es automático y no requiere configuración.
    </div>
</div>

<div class="doc-section" id="shadow-saas-hero">
    <h2>Integración Hero Shadow SaaS <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p><strong>HERO Security</strong> es un proveedor alternativo de Shadow SaaS. En lugar de Grip, puede conectar una cuenta de HERO y la plataforma importa los proveedores que HERO descubre &mdash; con su estado, una puntuación de riesgo, el contacto más activo y un recuento de usuarios &mdash; a la misma lista de <a href="#tprm-shadow-saas">Shadow SaaS</a>. Grip y Hero son <strong>mutuamente excluyentes</strong>: habilitar Hero deshabilita automáticamente Grip (y viceversa), por lo que la lista siempre es alimentada por exactamente un proveedor.</p>
    <p>Lo configura un administrador en <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> en la pestaña <strong>Hero</strong>.</p>

    <h3>Conexión de Hero (paso a paso)</h3>
    <ol class="steps">
        <li>En el panel de administración de HERO, cree un <strong>cliente de API</strong> y copie su <strong>ID de Cliente</strong> y <strong>Secreto de Cliente</strong> (el secreto solo se muestra una vez).</li>
        <li>En la plataforma, vaya a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span> y abra la pestaña <strong>Hero</strong> para encontrar la tarjeta de <strong>Conexión de HERO Security</strong>.</li>
        <li>Marque <span class="field-label">Habilitar integración de HERO Security</span> (esto deshabilita Grip).</li>
        <li>Deje <span class="field-label">Servidor (URL Base)</span> como <code>https://api.herosecurity.ai/stable</code> salvo que se le indique lo contrario, y pegue su <span class="field-label">ID de Cliente</span> y <span class="field-label">Secreto de Cliente</span>.</li>
        <li>Haga clic en <span class="btn-label">Guardar Configuración</span>, luego en <span class="btn-label">Probar Conexión</span>. Un mensaje de éxito tiene el aspecto <em>"Conectado a HERO — muestra devuelta de 1 registro(s)"</em>.</li>
    </ol>

    <h3>Qué verá después</h3>
    <p>Los proveedores de HERO aparecen en la página de <span class="menu-label">Shadow SaaS</span> de la misma manera que las aplicaciones de Grip &mdash; como entradas <strong>Pendientes</strong> que puede Permitir, Denegar o Desestimar. Para cada proveedor la plataforma registra:</p>
    <ul>
        <li><strong>Puntuación de Riesgo (1&ndash;5)</strong> &mdash; derivada del problema de seguridad abierto más grave que HERO tiene para ese proveedor (crítico&nbsp;=&nbsp;5 hasta bajo&nbsp;=&nbsp;2; los proveedores sin problemas abiertos no reciben puntuación). Esta es la misma escala de 1&ndash;5 que usa Grip.</li>
        <li><strong>Gestor de Relaciones</strong> &mdash; el contacto observado más activo del proveedor (el usuario con mayor actividad de correo electrónico).</li>
        <li><strong>Número de Usuarios</strong> &mdash; cuántos usuarios se observó interactuando con el proveedor.</li>
        <li><strong>Tipo de Riesgo</strong> &mdash; un resumen de las señales de participación de HERO (autorización, actividad, participación comercial) y el recuento de problemas abiertos.</li>
    </ul>
    <p>Algunas columnas que otras fuentes proporcionan (categoría de aplicación, soporte de MFA, historial de brechas, volúmenes de tráfico, uso compartido de archivos) no forman parte de la API de HERO, por lo que permanecen en blanco para las filas de Hero.</p>

    <div class="callout callout-info">
        <strong>Nota sobre el tiempo de sincronización.</strong> HERO devuelve sus datos por proveedor y limita la tasa de solicitudes, por lo que una actualización completa de un tenant grande tarda varios minutos en segundo plano. El trabajo programado y "Ejecutar Ahora" se adaptan automáticamente para mantenerse dentro de los límites de HERO.
    </div>
</div>

<div class="doc-section" id="zscaler">
    <h2>Integración de Bloqueo Zscaler <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p><strong>Zscaler</strong> es un servicio de seguridad web que puede bloquear el acceso a sitios web. Con esta integración, cuando <strong>Deniega</strong> una aplicación no autorizada en la página de Shadow SaaS, la plataforma puede agregar automáticamente el dominio web de esa aplicación a una lista de bloqueo en su cuenta de Zscaler &mdash; para que las personas ya no puedan acceder a ella. Hacer clic en <strong>Permitir</strong> más tarde elimina el bloqueo.</p>
    <p>Lo configura un administrador en <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Shadow SaaS</span>, en la tarjeta de <strong>Conexión de Zscaler</strong>.</p>

    <h3>Conexión de Zscaler (paso a paso)</h3>
    <ol class="steps">
        <li>En Zscaler (ZIdentity), cree un <strong>Cliente de API</strong> y copie su <strong>ID de Cliente</strong> y <strong>Secreto de Cliente</strong>. Anote su <strong>dominio vanity</strong> (la parte antes de <code>.zslogin.net</code>).</li>
        <li>En ZIA, cree (o seleccione) una <strong>Categoría de URL personalizada</strong> a la que se agregarán los dominios bloqueados y anote su nombre exacto.</li>
        <li>En la tarjeta de <strong>Conexión de Zscaler</strong> de la plataforma, marque <span class="field-label">Habilitar bloqueo de Categoría de URL de Zscaler al Denegar</span>.</li>
        <li>Complete <span class="field-label">URL de API</span> (por defecto <code>https://api.zsapi.net</code>), <span class="field-label">Dominio Vanity de ZIdentity</span>, <span class="field-label">ID de Cliente</span>, <span class="field-label">Secreto de Cliente</span> y el nombre de la <span class="field-label">Categoría de URL</span>.</li>
        <li>Haga clic en <span class="btn-label">Guardar Configuración</span>, luego en <span class="btn-label">Probar Conexión</span> para confirmar que las credenciales funcionan.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/zscaler-card.png" alt="Zscaler Connection settings" loading="lazy">
        <figcaption><strong>Admin &rarr; Shadow SaaS &rarr; Conexión de Zscaler.</strong> Cuando está habilitado, el botón <strong>Denegar</strong> en una aplicación de Shadow SaaS agrega su dominio a la Categoría de URL que indique aquí. La categoría ya debe existir en Zscaler.</figcaption>
    </figure>
    <div class="callout callout-info">
        <strong>Si el bloqueo está desactivado,</strong> denegar una aplicación solo la marca como no autorizada en la plataforma; nada se envía a Zscaler. Verá el mensaje <em>"Marcada como no autorizada. La integración de Zscaler no está habilitada; el dominio no se agregó a la Categoría de URL."</em>
    </div>
</div>


<div class="doc-section" id="breach-alerts">
    <h2>Alertas de Brechas / Ciberseguridad <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>La página de <strong>Alertas de Brechas / Ciberseguridad</strong> reúne en un solo lugar las señales de brechas e inteligencia de amenazas de su cadena de suministro de proveedores. Ábrala desde la barra lateral en <span class="menu-label">Alertas de Brechas / Ciberseguridad</span> &rarr; <span class="menu-label">Alertas de Brechas</span>; una insignia roja muestra el número de alertas nuevas.</p>

    <h3>De dónde provienen las alertas</h3>
    <p>Las alertas incluyen brechas detectadas por los escáneres de IA de Brechas y OSINT (consulte <a href="#admin-ai">Integración de IA</a>) y, cuando está habilitado, incidentes de seguridad de <a href="#shadow-saas-grip">Grip</a>. Cada alerta muestra la entidad afectada, los usuarios potencialmente impactados, la tecnología y cuándo se detectó. Un incidente en una aplicación SaaS que <strong>no</strong> ha incorporado como proveedor se etiqueta como <strong>&ldquo;Shadow SaaS&rdquo;</strong> con el número de usuarios potencialmente impactados; si esa aplicación se incorpora más tarde, los incidentes futuros se adjuntan al proveedor en su lugar.</p>

    <h3>Quién fue afectado</h3>
    <p>Para un incidente procedente de Grip, el recuento de usuarios impactados enlaza a una lista de <strong>usuarios afectados</strong> de esa aplicación. La lista está paginada y es filtrable (por ejemplo, por método de autenticación), y tiene un cuadro de <strong>Buscar por nombre o correo electrónico</strong> para encontrar a una persona específica. Debido a que la lista se almacena cifrada, la búsqueda se ejecuta sobre los datos descifrados en la aplicación, por lo que funciona igual que la ordenación y la paginación.</p>

    <h3>Trabajar con las alertas en bloque</h3>
    <p>Los administradores y los usuarios de Cyber TPRM obtienen una barra de herramientas de selección múltiple en la lista. Marque las alertas que desee (o use <strong>Marcar todas</strong>) y aplique una acción a todas a la vez:</p>
    <ul>
        <li><span class="btn-label">Confirmar</span> &mdash; marque las alertas como vistas.</li>
        <li><span class="btn-label">Falso Positivo</span> &mdash; márquelas como no un problema real.</li>
        <li><span class="btn-label">Eliminar</span> &mdash; elimínelas. <strong>Solo administradores</strong>, y confirmado antes de ejecutarse.</li>
    </ul>
</div>


<!-- ================================================================
     ADMIN PORTAL
     ================================================================ -->
<div class="doc-section" id="admin-general">
    <h2>Portal de Administración: Configuración General</h2>
    <p>El Portal de Administración es accesible a través de <span class="menu-label">Administración</span> en la barra lateral (solo usuarios administradores) o el enlace <span class="btn-label">Admin</span> en la barra superior.</p>
    <p>La Configuración General incluye: nombre de la aplicación, nombre de la empresa, correo electrónico de soporte y opciones de configuración de todo el sistema.</p>
</div>

<div class="doc-section" id="admin-branding">
    <h2>Imagen de Marca y Tema</h2>
    <p>Personalice el aspecto de la plataforma: cargue el logotipo de su empresa, establezca colores de la barra lateral, colores de encabezado, colores de botones y ancho de navegación. Navegue a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Imagen de Marca</span>.</p>
</div>

<div class="doc-section" id="admin-users">
    <h2>Gestión de Usuarios</h2>
    <p>Gestione cuentas de usuario y asignaciones de grupos. Navegue a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Usuarios</span>.</p>

    <h3>Asignación de Usuarios a Grupos ACL</h3>
    <ol class="steps">
        <li>Navegue a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Usuarios</span>.</li>
        <li>Busque el usuario en la lista.</li>
        <li>Haga clic en el botón <span class="btn-label">Grupos</span> junto al nombre del usuario.</li>
        <li>Aparecerá un modal con todos los grupos disponibles con casillas de verificación. Marque los grupos que desea asignar (p. ej., <strong>Cyber GRC</strong>, <strong>Administrador</strong>).</li>
        <li>Haga clic en <span class="btn-label">Guardar Cambios</span>.</li>
    </ol>
    <p>Además de asignar los grupos incluidos, los superadministradores pueden crear sus propios grupos con un conjunto de permisos personalizado &mdash; consulte <a href="#admin-acl-groups">Grupos ACL y Control de Acceso Personalizado</a>.</p>
</div>

<div class="doc-section" id="admin-acl-groups">
    <h2>Grupos ACL y Control de Acceso Personalizado <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>La plataforma incluye siete grupos integrados (administrator, cyber_tprm, procurement, stakeholder, auditor, cyber_grc, grc_contributors). En v2.6.2, los <strong>superadministradores</strong> también pueden crear sus propios grupos y ajustar exactamente lo que cada uno puede hacer. Abra <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Control de Acceso</span> &rarr; <span class="menu-label">Grupos ACL</span>. Cualquier administrador puede ver esta página; solo los superadministradores ven los controles de creación, edición y permisos.</p>

    <h3>Los grupos incluidos están protegidos</h3>
    <p>Los siete grupos integrados están marcados como <strong>Sistema</strong>. No pueden eliminarse ni renombrarse, y sus permisos son de solo lectura &mdash; puede abrir <span class="btn-label">Ver Permisos</span> para ver exactamente qué otorgan, pero no cambiarlos. Esto mantiene estables los valores predeterminados en los que todos confían.</p>

    <h3>Crear un grupo personalizado</h3>
    <ol class="steps">
        <li>Haga clic en <span class="btn-label">+ Crear Grupo</span>.</li>
        <li>Ingrese un <span class="field-label">Nombre de Grupo (máquina)</span> (letras minúsculas, números, guiones bajos &mdash; esto es fijo una vez creado), un <span class="field-label">Nombre para Mostrar</span> descriptivo y una <span class="field-label">Descripción</span>.</li>
        <li>Opcionalmente use <span class="field-label">Copiar permisos de</span> para <strong>clonar</strong> un grupo existente (incluido un grupo de Sistema) como punto de partida &mdash; y luego refínelo. Déjelo en <em>&mdash; Empezar sin permisos &mdash;</em> para construir desde cero.</li>
        <li>Haga clic en <span class="btn-label">Crear Grupo</span>.</li>
    </ol>

    <h3>Ajustar la matriz de permisos</h3>
    <p>Abra los <span class="btn-label">Permisos</span> de un grupo personalizado. Los permisos se agrupan por módulo (Incorporación de Proveedores, Análisis FAIR, Evaluaciones, Calificación de Seguridad (SRS), Revisiones Anuales, GRC y Otros). Cada permiso se etiqueta como <strong>Lectura</strong> o <strong>Lectura/Escritura</strong>, y cada módulo tiene tres preajustes de un clic:</p>
    <ul>
        <li><span class="btn-label">Lectura</span> &mdash; otorga solo los permisos de ver/listar/exportar de ese módulo.</li>
        <li><span class="btn-label">Lectura y Escritura</span> &mdash; otorga todo (ver <em>y</em> cambiar).</li>
        <li><span class="btn-label">Ninguno</span> &mdash; limpia el módulo.</li>
    </ul>
    <p>Haga clic en <span class="btn-label">Guardar Permisos</span> cuando termine. Otorgar <strong>Lectura</strong> nunca implica acceso de escritura &mdash; la capacidad de cambiar algo es siempre una concesión separada y explícita. Todos los cambios de grupo quedan registrados en la auditoría.</p>
</div>

<div class="doc-section" id="admin-templates">
    <h2>Constructor de Plantillas de Evaluación <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>El <strong>Constructor de Plantillas</strong> (<span class="menu-label">Admin</span> &rarr; <span class="menu-label">Constructor de Plantillas</span>) es donde los administradores y los usuarios de Cyber TPRM diseñan cuestionarios de evaluación e incorporación. Use <span class="btn-label">+ Agregar Sección</span> y <span class="btn-label">+ Agregar Pregunta</span> para construir una plantilla. Vale la pena destacar algunas incorporaciones de v2.6.2.</p>

    <h3>Tipos de pregunta y asignación de campos</h3>
    <p>El <span class="field-label">Tipo de Pregunta</span> de una pregunta ahora incluye <strong>Teléfono</strong>, <strong>Número de VAT</strong>, <strong>Casillas de Verificación</strong> y <strong>Grupo de Botones (Múltiple)</strong> además de los conocidos tipos de texto, desplegable y opción única (consulte <a href="#custom-onboarding">Campos de Incorporación Personalizados</a> para saber qué captura cada uno). El <span class="field-label">Nombre de Campo</span> de una pregunta asigna su respuesta a un campo de proveedor; elija un campo integrado o uno personalizado que haya registrado en <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Referencia de Campos</span>.</p>

    <h3>Instrucciones de Carga de Certificado</h3>
    <p>En una plantilla puede completar las <span class="field-label">Instrucciones de Carga de Certificado</span> &mdash; el texto que se muestra a un proveedor en el mensaje <em>&ldquo;¿Tiene un Certificado?&rdquo;</em>. Esto permite que una plantilla invite a cualquier certificado (SOC 2 Tipo 2, ISO 27001, etc.), no solo ISO 27001. Si lo deja en blanco, se muestra un mensaje genérico.</p>

    <h3>Visibilidad basada en roles en plantillas de incorporación</h3>
    <p>Para las plantillas de <strong>incorporación</strong>, las secciones y preguntas personalizadas llevan controles de <span class="field-label">Visible para Roles</span> y <span class="field-label">Roles Visibles y Editables</span>, para que decida quién puede ver y editar cada campo personalizado. Consulte <a href="#custom-onboarding">Campos de Incorporación Personalizados y la Pestaña de Datos Personalizados</a>.</p>

    <h3>Las plantillas desactivadas están ocultas por defecto</h3>
    <p>La lista de plantillas muestra solo las plantillas <strong>activas</strong>. Si alguna ha sido desactivada, un botón <span class="btn-label">Mostrar Desactivadas (N)</span> las revela (y alterna de vuelta a <span class="btn-label">Ocultar Desactivadas (N)</span>), manteniendo la lista de un tenant de larga duración centrada en las plantillas realmente en uso sin perder acceso a las retiradas.</p>
</div>

<div class="doc-section" id="admin-email">
    <h2>Configuración de Correo Electrónico</h2>
    <p>Configure los ajustes SMTP para enviar notificaciones por correo electrónico. Navegue a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Correo Electrónico</span>. Los ajustes incluyen host SMTP, puerto, nombre de usuario, contraseña, método de cifrado (TLS/SSL) y dirección del remitente.</p>
</div>

<div class="doc-section" id="admin-saml">
    <h2>SAML / SSO</h2>
    <p>Configure el Inicio de Sesión Único utilizando SAML 2.0. Navegue a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span>. Esto permite a los usuarios iniciar sesión utilizando el proveedor de identidad de su organización (Okta, Azure AD, etc.).</p>
    <ol class="steps">
        <li>Marque <span class="field-label">Habilitar SAML 2.0</span>.</li>
        <li>Complete todos los campos obligatorios del Proveedor de Identidad (<span class="field-label">ID de Entidad del IdP</span>, <span class="field-label">URL de Inicio de Sesión Único del IdP</span>, <span class="field-label">Certificado X.509 del IdP</span>) y del Proveedor de Servicios (<span class="field-label">ID de Entidad del SP</span>, <span class="field-label">URL ACS del SP</span>). El SSO solo se activa cuando <strong>todos</strong> estos campos están completados &mdash; un formulario parcialmente completado permanece deshabilitado.</li>
        <li>Haga clic en <span class="btn-label">Guardar</span>.</li>
    </ol>

    <h3>Ejecutar inicio de sesión local y SSO juntos</h3>
    <p>De forma predeterminada, habilitar SSO <strong>no</strong> desactiva el formulario local de usuario/contraseña &mdash; la página de inicio de sesión muestra un botón de <strong>Iniciar sesión con SSO</strong> <em>y</em> una opción de inicio de sesión local, por lo que ambos funcionan en paralelo. El comportamiento está controlado por un único interruptor de inicio de sesión local:</p>
    <table>
        <tr><th>Modo</th><th>Qué ven los usuarios</th></tr>
        <tr><td><strong>Inicio de sesión local habilitado</strong> (predeterminado)</td><td>Botón de SSO <em>y</em> el formulario de usuario/contraseña. Use esto para ejecutar ambos a la vez.</td></tr>
        <tr><td><strong>Inicio de sesión local deshabilitado</strong> (solo SSO)</td><td>SSO es la única vía para los usuarios normales. La cuenta de <strong>administrador de emergencia</strong> designada puede seguir iniciando sesión localmente, para que un proveedor de identidad defectuoso nunca deje a todos sin acceso.</td></tr>
    </table>
    <p>Si SAML no está configurado, el interruptor se ignora y el inicio de sesión local siempre permanece disponible (red de seguridad anti-bloqueo).</p>

    <h3>Emergencia: permitir SAML e inicio de sesión local juntos (archivo de configuración) <span class="new-badge">Nuevo en 2.6.2</span></h3>
    <p>El interruptor de inicio de sesión local puede configurarse de dos maneras. La configuración en el archivo de configuración, cuando está presente, <strong>tiene prioridad sobre el valor de la base de datos</strong> &mdash; un control de emergencia que no necesita acceso a la base de datos, para que siempre pueda restaurar el inicio de sesión local incluso si SSO está fallando.</p>
    <table>
        <tr><th>Dónde</th><th>Cómo</th></tr>
        <tr><td>Página Admin &rarr; SAML</td><td>En la tarjeta de <strong>Configuración de Conexión</strong>, marque o desmarque <span class="field-label">Permitir inicio de sesión local con usuario/contraseña (además de SSO)</span> y haga clic en <span class="btn-label">Guardar Configuración SAML</span>. Esto escribe el ajuste <code>local_login_enabled</code> (habilitado por defecto) &mdash; no se necesita SQL.</td></tr>
        <tr><td>Archivo de configuración (prevalece si está definido)</td><td>En <code>config/config.php</code>, bajo el bloque <code>auth</code>, establezca <code>'local_login_enabled' =&gt; true</code> para mantener el inicio de sesión local siempre disponible (tanto local como SSO), o <code>false</code> para solo SSO. Esto <strong>anula</strong> el interruptor anterior; mientras esté definido, la casilla de verificación en la página SAML se muestra como de solo lectura. Elimine la línea para gestionarlo desde la interfaz de nuevo. Reinicie el contenedor después de editar <code>config.php</code>.</td></tr>
    </table>
    <p>Para ejecutar <strong>tanto SAML como inicio de sesión local</strong> sin cambios en la base de datos, configure SAML como se indicó anteriormente y agregue esto al bloque <code>auth</code> de <code>config/config.php</code>, luego reinicie el contenedor:</p>
    <pre><code>'auth' =&gt; [
    // ...
    // Break-glass: true = local login always available alongside SSO;
    // false = SSO-only (break-glass admin can still log in locally).
    'local_login_enabled' =&gt; true,
],</code></pre>
</div>

<div class="doc-section" id="admin-ai">
    <h2>Integración de IA</h2>
    <p>Habilite funciones impulsadas por IA que incluyen refinamiento de notas de evaluación, sugerencias de controles, comentarios de proveedores, análisis de riesgo FAIR asistido por IA y asistencia en el idioma de los informes. Navegue a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Plataforma de IA</span> para elegir un proveedor e ingresar su clave API. Solo una plataforma está activa a la vez.</p>
    <p>Plataformas de IA compatibles:</p>
    <ul>
        <li><strong>Anthropic (Claude)</strong> <span class="new-badge">Nuevo en 2.6.2</span> &mdash; se conecta directamente a la API nativa de Claude (p. ej., <code>claude-opus-4-8</code>). Pegue su clave API de Anthropic; el endpoint utiliza por defecto la URL estándar de Messages. Admite <strong>búsqueda web</strong> en vivo, por lo que las Alertas de Brechas y los análisis OSINT se basan en fuentes actuales y citadas.</li>
        <li><strong>OpenAI (ChatGPT)</strong> <span class="new-badge">Nuevo en 2.6.2</span> &mdash; se conecta directamente a OpenAI (p. ej., <code>gpt-4o</code>). Pegue su clave API de OpenAI. Para los análisis de Brechas y OSINT utiliza un modelo con capacidad de búsqueda web (por defecto <code>gpt-4o-search-preview</code>) para que esos análisis se basen en fuentes en tiempo real.</li>
        <li><strong>OpenWebUI</strong> &mdash; token JWT Bearer contra un endpoint compatible con OpenAI.</li>
        <li><strong>LibreChat</strong> &mdash; autenticación por clave de API, basado en agentes; el agente gestiona su propio modelo y muestreo.</li>
        <li><strong>Personalizado</strong> &mdash; pegue una plantilla de encabezados y cuerpo estilo curl para cualquier otro endpoint compatible con OpenAI (u orquestador).</li>
    </ul>
    <p><strong>Elegir y cargar un modelo:</strong> después de ingresar y <strong>guardar</strong> una clave, haga clic en <span class="btn-label">Cargar Modelos</span> en la tarjeta de esa plataforma para obtener su lista de modelos disponibles (OpenWebUI / LibreChat / OpenAI). Para Anthropic, escriba el nombre del modelo directamente (p. ej., <code>claude-opus-4-8</code>).</p>
    <p><strong>Fundamentación en Alertas de Brechas:</strong> los escáneres de Brechas y OSINT necesitan un proveedor que pueda buscar en la web. <strong>Anthropic (Claude)</strong> y <strong>OpenAI (ChatGPT)</strong> realizan la fundamentación de forma nativa; OpenWebUI / LibreChat solo lo hacen si el agente subyacente tiene navegación; la plataforma Personalizada solo cuando se configura una URL de Búsqueda Web.</p>
    <p>El proveedor de IA activo también impulsa la <strong>traducción automática de preguntas de evaluación</strong> (consulte <a href="#language">Cambio de Idioma</a>).</p>
</div>

<div class="doc-section" id="admin-updates">
    <h2>Actualización de la Plataforma <span class="new-badge">Nuevo en 2.6.2</span></h2>
    <p>Los administradores pueden buscar y aplicar nuevas versiones desde dentro de la plataforma. Navegue a <span class="menu-label">Admin</span> &rarr; <span class="menu-label">Versión</span>.</p>
    <ol class="steps">
        <li>La tarjeta de <strong>Estado Actual</strong> muestra su <span class="field-label">Versión Instalada</span> y si hay una más reciente disponible.</li>
        <li>Confirme que el <span class="field-label">Nombre de Host del Registro</span> sea correcto (su registro de imágenes), luego haga clic en <span class="btn-label">Buscar Actualizaciones</span>.</li>
        <li>Si aparece una versión más reciente, siga la acción de <strong>Actualización</strong> en pantalla para aplicarla.</li>
    </ol>
    <figure class="doc-figure">
        <img src="app/docs/admin-version.png?v=20260711" alt="Version Management page showing installed version 2.6.2" loading="lazy">
        <figcaption><strong>Admin &rarr; Versión.</strong> Aquí la versión instalada es <strong>v2.6.2</strong> y la plataforma informa que está actualizada. También es aquí donde confirma qué versión describe esta guía.</figcaption>
    </figure>
</div>


<!-- ================================================================
     FAQ (SEARCHABLE)
     ================================================================ -->
<div class="doc-section" id="faq">
    <h2>Preguntas Frecuentes</h2>
    <p>Escriba una palabra clave a continuación para filtrar instantáneamente las preguntas &mdash; por ejemplo <em>idioma</em>, <em>VID</em>, <em>incorporación</em>, <em>Grip</em> o <em>contraseña</em>.</p>
    <div class="faq-search-wrap">
        <input type="text" id="faqSearch" class="faq-search" placeholder="Buscar en las FAQ&hellip;" aria-label="Search frequently asked questions" autocomplete="off">
    </div>
    <p class="faq-count" id="faqCount"></p>
    <div id="faqList">

        <details class="faq-item"><summary>¿Pueden los usuarios iniciar sesión con SSO y con contraseña local al mismo tiempo?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>Sí. Habilitar SAML/SSO <strong>no</strong> desactiva el inicio de sesión local por defecto &mdash; la página de inicio de sesión muestra un botón de <strong>Iniciar sesión con SSO</strong> y una opción de usuario/contraseña local juntos. Puede controlar esto con la casilla <strong>Permitir inicio de sesión local con usuario/contraseña</strong> en la página <span class="menu-label">Admin</span> &rarr; <span class="menu-label">SAML</span> (déjela marcada para ejecutar ambos; desmárquela para solo SSO, donde el administrador de emergencia aún puede iniciar sesión localmente). Para un control de emergencia sin base de datos, la misma configuración puede forzarse en <code>config/config.php</code> mediante <code>'local_login_enabled' =&gt; true</code>, que anula la casilla. Consulte <a href="#admin-saml">SAML / SSO</a>.</p></div></details>

        <details class="faq-item"><summary>El SSO está mal configurado y nadie puede iniciar sesión. ¿Cómo recupero el acceso?<span class="faq-tag">SSO</span></summary>
            <div class="faq-body"><p>Use el control de emergencia: en <code>config/config.php</code>, bajo el bloque <code>auth</code>, establezca <code>'local_login_enabled' =&gt; true</code> y reinicie el contenedor. Esto reactiva el formulario de usuario/contraseña local independientemente del ajuste de la base de datos, para que pueda iniciar sesión y corregir la configuración SAML. Consulte <a href="#admin-saml">SAML / SSO</a>.</p></div></details>

        <details class="faq-item"><summary>¿Cómo cambio el idioma de la plataforma?<span class="faq-tag">Idioma</span></summary>
            <div class="faq-body"><p>Haga clic en <strong>Perfil</strong> (parte superior derecha), abra la tarjeta de <strong>Preferencia de Idioma</strong>, elija su idioma y haga clic en <strong>Actualizar Idioma</strong>. Esto solo cambia su propia pantalla. Consulte <a href="#language">Cambio de Idioma</a>.</p></div></details>

        <details class="faq-item"><summary>¿Qué idiomas están disponibles?<span class="faq-tag">Idioma</span></summary>
            <div class="faq-body"><p>Inglés, Español, Italiano, Ucraniano, Chino (Simplificado), Hindi, Francés y Portugués. Su administrador decide cuáles de estos aparecen en su lista; el inglés siempre está disponible.</p></div></details>

        <details class="faq-item"><summary>Cambié mi idioma pero parte del texto sigue en inglés. ¿Por qué?<span class="faq-tag">Idioma</span></summary>
            <div class="faq-body"><p>Varias cosas distintas pueden permanecer en inglés incluso después de cambiar de idioma:</p>
            <ul>
                <li><strong>Texto de interfaz que aún no se ha traducido.</strong> Los menús, botones y etiquetas están traducidos siempre que exista una traducción para su idioma. Si una cadena en particular aún no se ha traducido a su idioma, recurre al inglés en lugar de mostrar un espacio en blanco &mdash; por lo que es posible que vea alguna etiqueta ocasional en inglés.</li>
                <li><strong>Cualquier cosa que se haya escrito.</strong> El contenido que usted o sus proveedores ingresan &mdash; nombres de proveedores, notas, nombres de documentos cargados, respuestas de texto libre &mdash; se muestra exactamente como se escribió, en el idioma que fuera.</li>
                <li><strong>Preguntas de evaluación sin un proveedor de IA.</strong> El texto de las <em>preguntas</em> de evaluación de proveedores se traduce automáticamente solo cuando su administrador ha configurado un proveedor de IA; sin uno, las preguntas permanecen en el idioma en que fueron escritas. Los valores de respuesta almacenados siempre permanecen en inglés para que la puntuación sea coherente.</li>
                <li><strong>Los correos electrónicos y algunos componentes de terceros</strong> no están controlados por su configuración de idioma.</li>
            </ul>
            <p>Si ve una etiqueta de interfaz que debería estar traducida pero no lo está, avise a su administrador para que se pueda agregar el texto faltante.</p></div></details>

        <details class="faq-item"><summary>¿Por qué no puedo enviar mi proveedor para revisión?<span class="faq-tag">Incorporación</span></summary>
            <div class="faq-body"><p>Un proveedor solo puede enviarse una vez que ha completado la <strong>Incorporación de Compras</strong> (establecida en <strong>Sí</strong>) y tiene un <strong>ID de Proveedor (VID)</strong> válido de 4&ndash;8 dígitos. Abra el proveedor, complete ambos en la tarjeta de <strong>Información del Proveedor</strong>, guarde y luego haga clic en <strong>Enviar para Revisión</strong>. Consulte <a href="#onboarding-workflow">Incorporación de Proveedores</a> y <a href="#troubleshooting">Solución de Problemas</a>.</p></div></details>

        <details class="faq-item"><summary>¿Qué es un ID de Proveedor (VID) y dónde lo consigo?<span class="faq-tag">Incorporación</span></summary>
            <div class="faq-body"><p>El VID es un número de 4&ndash;8 dígitos asignado al proveedor por su sistema de compras cuando el proveedor es incorporado. Vincula al proveedor aquí con sus registros de compras y finanzas. Si no tiene uno, el proveedor aún no ha completado la incorporación de compras.</p></div></details>

        <details class="faq-item"><summary>El campo en mi proveedor dice "VSU Onboarded", pero la guía dice "Procurement Onboarding". ¿Cuál es?<span class="faq-tag">Incorporación</span></summary>
            <div class="faq-body"><p>Es el mismo campo. Fue renombrado al más claro <strong>"Procurement Onboarding"</strong> en v2.6.2. Si su pantalla aún muestra <strong>"VSU Onboarded"</strong>, su instancia aún no ha sido actualizada a la imagen más reciente de v2.6.2 &mdash; el comportamiento es idéntico.</p></div></details>

        <details class="faq-item"><summary>¿Qué significa "Revisión de IA" para un proveedor?<span class="faq-tag">Revisión de IA</span></summary>
            <div class="faq-body"><p>Es un estado de revisión separado para proveedores cuyos servicios usan IA, para que puedan ser rastreados aparte de las revisiones ordinarias. Un usuario de Cyber TPRM o administrador mueve un proveedor a este estado con el enlace <strong>Forzar Revisión de IA</strong>. Consulte <a href="#ai-review">Revisión de IA para Proveedores</a>.</p></div></details>

        <details class="faq-item"><summary>No veo el enlace "Forzar Revisión de IA". ¿Por qué?<span class="faq-tag">Revisión de IA</span></summary>
            <div class="faq-body"><p>Solo aparece cuando está editando el proveedor con permiso de aprobación, el campo <strong>Los Servicios Usan IA</strong> del proveedor está en <strong>Sí</strong> y el proveedor no está ya en Revisión de IA.</p></div></details>

        <details class="faq-item"><summary>¿Qué es la página de Estado Cibernético de Compras?<span class="faq-tag">Compras</span></summary>
            <div class="faq-body"><p>Una página en lenguaje sencillo (<strong>TPRM &rarr; Compras &rarr; Estado Cibernético</strong>) donde compras puede ver qué proveedores está revisando el equipo de ciberseguridad y leer actualizaciones con fecha que el equipo de ciberseguridad publica. Consulte <a href="#procurement-cyber-status">Estado Cibernético de Compras</a>.</p></div></details>

        <details class="faq-item"><summary>¿Cómo recibe compras los correos electrónicos de actualización?<span class="faq-tag">Compras</span></summary>
            <div class="faq-body"><p>Un administrador activa el <strong>Resumen de Actualizaciones de Compras</strong> en <strong>Admin &rarr; Configuración de Correo Electrónico</strong> y agrega direcciones de destinatarios. Se envía semanalmente (por defecto los lunes a las 7:00 AM) y también puede enviarse bajo demanda.</p></div></details>

        <details class="faq-item"><summary>¿Qué es Grip y qué hace aquí?<span class="faq-tag">Integraciones</span></summary>
            <div class="faq-body"><p>Grip Security descubre aplicaciones SaaS utilizadas en su organización. Cuando está conectado (<strong>Admin &rarr; Shadow SaaS</strong>), la plataforma importa automáticamente esas aplicaciones, sus recuentos de usuarios, puntuaciones de riesgo y alertas a su lista de Shadow SaaS. Consulte <a href="#shadow-saas-grip">Integración Grip Shadow SaaS</a>.</p></div></details>

        <details class="faq-item"><summary>¿Cuál es la diferencia entre las integraciones de Grip y Hero?<span class="faq-tag">Integraciones</span></summary>
            <div class="faq-body"><p>Ambos alimentan la misma lista de Shadow SaaS desde un servicio de descubrimiento de terceros &mdash; Grip Security o HERO Security &mdash; y ambos comparten el bloqueo de Zscaler y el trabajo de Rehidratación Programada. Son <strong>mutuamente excluyentes</strong>: habilitar uno deshabilita el otro, por lo que ejecuta el proveedor que utiliza su organización. Consulte <a href="#shadow-saas-hero">Integración Hero Shadow SaaS</a>.</p></div></details>

        <details class="faq-item"><summary>Mi "Probar Conexión" de Grip falló. ¿Qué debo verificar?<span class="faq-tag">Integraciones</span></summary>
            <div class="faq-body"><p>Confirme que el <strong>Servidor (URL Base del Tenant)</strong> termina en <code>/public/saas</code>, que el <strong>Token de API</strong> está vigente y que su servidor puede acceder al endpoint de Grip. Un error de token informa <em>"No autorizado — token rechazado"</em>; un error de URL informa <em>"Endpoint no encontrado — verifique la URL base"</em>.</p></div></details>

        <details class="faq-item"><summary>¿Qué hace la integración de Zscaler?<span class="faq-tag">Integraciones</span></summary>
            <div class="faq-body"><p>Cuando <strong>Deniega</strong> una aplicación no autorizada, la plataforma puede agregar su dominio web a una Categoría de URL de bloqueo en su cuenta de Zscaler para que las personas no puedan acceder a ella. Hacer clic en <strong>Permitir</strong> más tarde elimina el bloqueo. Consulte <a href="#zscaler">Integración de Bloqueo Zscaler</a>.</p></div></details>

        <details class="faq-item"><summary>¿Cuál es la diferencia entre Permitir, Denegar y Desestimar en una aplicación de Shadow SaaS?<span class="faq-tag">Integraciones</span></summary>
            <div class="faq-body"><p><strong>Permitir</strong> inicia la incorporación de la aplicación como proveedor; <strong>Denegar</strong> la marca como no autorizada (y puede bloquearla en Zscaler); <strong>Desestimar</strong> la oculta de la lista. Las aplicaciones desestimadas permanecen desestimadas incluso después de sincronizaciones futuras.</p></div></details>

        <details class="faq-item"><summary>¿Quién puede ver el módulo GRC?<span class="faq-tag">Acceso</span></summary>
            <div class="faq-body"><p>Los usuarios de los grupos <strong>Administrador</strong>, <strong>Cyber GRC</strong> o <strong>Auditor</strong>. Si no lo ve, pida a su administrador que lo agregue a uno de estos grupos. Consulte <a href="#roles">Roles y Permisos de Usuario</a>.</p></div></details>

        <details class="faq-item"><summary>¿Cómo activo la autenticación de dos factores (2FA)?<span class="faq-tag">Cuenta</span></summary>
            <div class="faq-body"><p>Abra <strong>Perfil</strong> y use la tarjeta de <strong>Autenticación de Dos Factores (TOTP)</strong> para habilitarla con una aplicación de autenticación como Google Authenticator o Microsoft Authenticator.</p></div></details>

        <details class="faq-item"><summary>¿Puedo guardar o imprimir esta documentación?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>Sí. Haga clic en <strong>Descargar PDF</strong> en la parte superior de esta página. Genera un documento con formato que incluye una portada, tabla de contenidos y números de página.</p></div></details>

        <details class="faq-item"><summary>¿Cómo sé qué versión estoy ejecutando?<span class="faq-tag">General</span></summary>
            <div class="faq-body"><p>Los administradores pueden consultar <strong>Admin &rarr; Versión</strong>. Esta guía describe <strong>v2.6.2</strong>. Consulte <a href="#admin-updates">Actualización de la Plataforma</a>.</p></div></details>

    </div>
    <p class="faq-no-results" id="faqNoResults">Ninguna pregunta coincide con su búsqueda. Pruebe con otra palabra clave.</p>
</div>


<!-- ================================================================
     TROUBLESHOOTING
     ================================================================ -->
<div class="doc-section" id="troubleshooting">
    <h2>Solución de Problemas</h2>

    <h3>Por qué es importante la incorporación a través de compras (la regla de VID y Procurement Onboarding)</h3>
    <p>Esta es la situación más común que impide que un proveedor avance, por lo que vale la pena entenderla. La plataforma <strong>no permitirá que un proveedor sea enviado para revisión de ciberseguridad</strong> hasta que se registren dos datos de compras en el proveedor:</p>
    <ul>
        <li><strong>Procurement Onboarding = Sí</strong> &mdash; confirmación de que el proveedor ha sido configurado y verificado a través del proceso de compras de su organización.</li>
        <li>Un <strong>ID de Proveedor (VID)</strong> válido &mdash; el número de 4&ndash;8 dígitos que compras asigna al proveedor.</li>
    </ul>
    <p>¿Por qué se aplica esto? Porque el VID es la clave compartida que vincula a este proveedor con los registros de compras, finanzas y contratos. Si el equipo de ciberseguridad revisara y aprobara un proveedor que compras nunca había incorporado, acabaría con proveedores duplicados o "fantasma", trabajo de seguridad que no puede vincularse a una orden de compra real e informes que no cuadran. Exigir la incorporación de compras <em>primero</em> mantiene la revisión de seguridad y el registro de compras apuntando al mismo proveedor real.</p>
    <div class="callout callout-warning">
        <strong>Cómo solucionarlo:</strong> Abra el proveedor y, en la tarjeta de <strong>Información del Proveedor</strong>, establezca <strong>Procurement Onboarding</strong> en <strong>Sí</strong> e ingrese el <strong>ID de Proveedor (VID)</strong> de 4&ndash;8 dígitos de su sistema de compras. Guarde y luego haga clic en <strong>Enviar para Revisión</strong> de nuevo. Si aún no tiene un VID, el proveedor no ha completado la incorporación de compras &mdash; comience por ahí.
    </div>

    <h3>Problemas comunes y cómo resolverlos</h3>
    <table class="doc-table">
        <tr><th>Síntoma</th><th>Causa probable y solución</th></tr>
        <tr><td>"Cannot submit: Vendor must be onboarded at VSU before submission&hellip;"</td><td>El campo <strong>Procurement Onboarding</strong> no está configurado en <strong>Sí</strong>. Establézcalo en Sí en la tarjeta de Información del Proveedor y guarde.</td></tr>
        <tr><td>"Cannot submit: A valid Vendor ID (VID) is required (4-8 digits)&hellip;"</td><td>El <strong>ID de Proveedor</strong> falta o no tiene 4&ndash;8 dígitos. Ingrese un VID válido de compras.</td></tr>
        <tr><td>"Only draft requests can be submitted for review."</td><td>El proveedor ya está más allá del estado Borrador. Solo puede enviar una solicitud que aún esté en estado <strong>Borrador</strong>.</td></tr>
        <tr><td>El botón <strong>Enviar para Revisión</strong> no es visible</td><td>Solo aparece para proveedores en estado <strong>Borrador</strong> cuando tiene permiso de edición.</td></tr>
        <tr><td>"AI Review can only be forced for vendors whose services use AI."</td><td>Establezca <strong>Los Servicios Usan IA</strong> en <strong>Sí</strong> en el proveedor antes de forzar la Revisión de IA.</td></tr>
        <tr><td>No puedo ver el módulo GRC en la barra lateral</td><td>Debe pertenecer al grupo <strong>Administrador</strong>, <strong>Cyber GRC</strong> o <strong>Auditor</strong>. Contacte a un administrador.</td></tr>
        <tr><td>Mi cambio de idioma no se conservó</td><td>Asegúrese de haber hecho clic en <strong>Actualizar Idioma</strong> (no solo cambiar el menú desplegable) y de que el idioma esté habilitado por su administrador.</td></tr>
        <tr><td>"Probar Conexión" de Grip falla</td><td>Verifique que la URL base termina en <code>/public/saas</code> y que el token de API sea válido y esté vigente.</td></tr>
        <tr><td>Denegar una aplicación de Shadow SaaS no la bloqueó en Zscaler</td><td>El bloqueo de Zscaler debe estar habilitado y configurado, y la <strong>Categoría de URL</strong> indicada ya debe existir en Zscaler.</td></tr>
        <tr><td>Compras no recibió el correo electrónico de resumen</td><td>Confirme que el resumen está habilitado con destinatarios en <strong>Admin &rarr; Configuración de Correo Electrónico</strong> y que la configuración SMTP en <strong>Admin &rarr; Correo Electrónico</strong> es correcta.</td></tr>
        <tr><td>El botón de Actualización dice "No se pudo obtener el manifiesto"</td><td>Un problema de registro/red al acceder a su registro de imágenes. Verifique el <strong>Nombre de Host del Registro</strong> en <strong>Admin &rarr; Versión</strong> y que el host pueda acceder a él.</td></tr>
    </table>
    <div class="callout callout-info">
        <strong>¿Sigue atascado?</strong> Anote el mensaje exacto en pantalla y en qué página estaba, luego contacte a su administrador de la plataforma. Los administradores pueden revisar <strong>Admin &rarr; Registro de Actividad</strong> para obtener detalles.
    </div>
</div>


<!-- ================================================================
     GLOSSARY
     ================================================================ -->
<div class="doc-section" id="glossary">
    <h2>Glosario</h2>
    <table class="doc-table">
        <tr><th>Término</th><th>Definición</th></tr>
        <tr><td><strong>ACL</strong></td><td>Lista de Control de Acceso &mdash; define qué acciones pueden realizar los usuarios de un grupo</td></tr>
        <tr><td><strong>Plan de Acción</strong></td><td>Una pestaña por proveedor para programar acciones de seguimiento (contactar, enviar evaluación, forzar revisión anual) con fechas de vencimiento, responsables y notas de estado; disparada diariamente por el trabajo Vendor Remediation Schedule</td></tr>
        <tr><td><strong>AI Review</strong></td><td>Un estado de incorporación de proveedor para proveedores cuyos servicios usan IA, rastreado por separado durante la revisión</td></tr>
        <tr><td><strong>Evaluación</strong></td><td>Una evaluación de cumplimiento en un momento específico utilizando el cuestionario unificado</td></tr>
        <tr><td><strong>CIS Controls</strong></td><td>Controles del Centro de Seguridad en Internet &mdash; un conjunto priorizado de mejores prácticas de seguridad</td></tr>
        <tr><td><strong>CMMC</strong></td><td>Certificación del Modelo de Madurez de Ciberseguridad &mdash; requerida para contratistas del Departamento de Defensa de EE.&nbsp;UU.</td></tr>
        <tr><td><strong>Estado de Conformidad</strong></td><td>Si un requisito es Conforme, Parcial, No Conforme, No Aplicable o No Evaluado</td></tr>
        <tr><td><strong>Control</strong></td><td>Una medida de seguridad específica implementada para cumplir con los requisitos de cumplimiento</td></tr>
        <tr><td><strong>Mapeo Cruzado</strong></td><td>Una correspondencia entre dos marcos que muestra qué requisitos se superponen</td></tr>
        <tr><td><strong>CSF</strong></td><td>Marco de Ciberseguridad NIST &mdash; un marco de gestión de riesgos de ciberseguridad ampliamente utilizado</td></tr>
        <tr><td><strong>Campo Personalizado / Datos Personalizados</strong></td><td>Un campo de incorporación específico de la organización sin columna estándar de proveedor; capturado por proveedor y mostrado en la pestaña Datos Personalizados del proveedor, con visibilidad por rol (consulte <a href="#custom-onboarding">Campos de Incorporación Personalizados</a>)</td></tr>
        <tr><td><strong>Dominio</strong></td><td>Una categoría de preguntas de seguridad (p. ej., Gobernanza, Gestión de Identidad y Acceso)</td></tr>
        <tr><td><strong>Evidencia</strong></td><td>Documentos, capturas de pantalla o archivos que prueban una afirmación de cumplimiento</td></tr>
        <tr><td><strong>FAIR</strong></td><td>Factor Analysis of Information Risk &mdash; una metodología de análisis cuantitativo de riesgos</td></tr>
        <tr><td><strong>FairScore</strong></td><td>La puntuación de madurez general de la plataforma calculada a partir de las respuestas de evaluación</td></tr>
        <tr><td><strong>Hallazgo</strong></td><td>Un problema descubierto durante una auditoría (no conformidad, observación, oportunidad o fortaleza)</td></tr>
        <tr><td><strong>Marco</strong></td><td>Un estándar de cumplimiento como SOC 2, ISO 27001, PCI DSS, etc.</td></tr>
        <tr><td><strong>GRC</strong></td><td>Gobernanza, Riesgo y Cumplimiento</td></tr>
        <tr><td><strong>Grip</strong></td><td>Grip Security &mdash; un servicio que descubre aplicaciones SaaS en uso; puede alimentar la lista de Shadow SaaS (consulte <a href="#shadow-saas-grip">Integración Grip Shadow SaaS</a>)</td></tr>
        <tr><td><strong>Hero</strong></td><td>HERO Security &mdash; un servicio alternativo de descubrimiento de Shadow SaaS que puede alimentar la lista de Shadow SaaS (mutuamente excluyente con Grip; consulte <a href="#shadow-saas-hero">Integración Hero Shadow SaaS</a>)</td></tr>
        <tr><td><strong>HIPAA</strong></td><td>Ley de Portabilidad y Responsabilidad de Seguros de Salud &mdash; ley de protección de datos de salud de EE.&nbsp;UU.</td></tr>
        <tr><td><strong>ISO 27001</strong></td><td>Estándar internacional para sistemas de gestión de seguridad de la información</td></tr>
        <tr><td><strong>Calificación de Madurez</strong></td><td>Una puntuación de 1-4 que indica qué tan madura es una práctica de seguridad (1=Ad Hoc, 4=Optimizado)</td></tr>
        <tr><td><strong>NIST 800-171</strong></td><td>Directrices NIST para proteger Información No Clasificada Controlada (CUI)</td></tr>
        <tr><td><strong>PCI DSS</strong></td><td>Estándar de Seguridad de Datos de la Industria de Tarjetas de Pago</td></tr>
        <tr><td><strong>PII</strong></td><td>Información de Identificación Personal (nombres, correos electrónicos, direcciones, etc.)</td></tr>
        <tr><td><strong>Procurement Onboarding</strong></td><td>Confirmación (Sí/No) de que un proveedor ha sido configurado a través de su proceso de compras; requerida, junto con un VID válido, antes de que un proveedor pueda enviarse para revisión. (Etiquetada como "VSU Onboarded" en instancias actualizadas desde versiones anteriores.)</td></tr>
        <tr><td><strong>Requisito</strong></td><td>Una cláusula específica u objetivo de control dentro de un marco de cumplimiento</td></tr>
        <tr><td><strong>SaaS</strong></td><td>Software como Servicio &mdash; aplicaciones en la nube a las que se accede a través de la web</td></tr>
        <tr><td><strong>Shadow SaaS</strong></td><td>Aplicaciones SaaS utilizadas en la organización que nunca fueron aprobadas o evaluadas formalmente</td></tr>
        <tr><td><strong>SOC 2</strong></td><td>Control de Organización de Servicios Tipo 2 &mdash; criterios de servicios de confianza para organizaciones de servicios</td></tr>
        <tr><td><strong>SPII</strong></td><td>PII Sensible (SSN, datos financieros, registros de salud)</td></tr>
        <tr><td><strong>SRS</strong></td><td>Tarjeta de Puntuación de Riesgo de Seguridad &mdash; la calificación/puntuación de seguridad externa de la plataforma para un proveedor</td></tr>
        <tr><td><strong>SSC (SecurityScorecard)</strong></td><td>Una calificación de seguridad por letra de terceros (A&ndash;F) mostrada para las aplicaciones y proveedores descubiertos por Grip cuando Grip está conectado</td></tr>
        <tr><td><strong>Subprocesador</strong></td><td>El proveedor descendente de un proveedor; el mismo subprocesador compartido por varios de sus proveedores indica concentración en la cadena de suministro (consulte <a href="#tprm-fourth-party">Riesgo de Cuarto Nivel</a>)</td></tr>
        <tr><td><strong>TPRM</strong></td><td>Gestión de Riesgos de Terceros</td></tr>
        <tr><td><strong>Pregunta Unificada</strong></td><td>Una sola pregunta de seguridad que se asigna a requisitos de múltiples marcos</td></tr>
        <tr><td><strong>VID</strong></td><td>ID de Proveedor &mdash; un identificador de 4&ndash;8 dígitos asignado a un proveedor por su sistema de compras</td></tr>
        <tr><td><strong>VSU</strong></td><td>La función de compras/configuración de proveedores; "incorporado en VSU" significa que el proveedor ha completado la Incorporación de Compras</td></tr>
        <tr><td><strong>Zscaler</strong></td><td>Un servicio de seguridad web que puede bloquear dominios de sitios web; integrado para que las aplicaciones no autorizadas puedan bloquearse al Denegar (consulte <a href="#zscaler">Bloqueo Zscaler</a>)</td></tr>
    </table>
</div>


                </div><!-- /.doc-body -->

                <!-- Sticky right-side TOC (screen only) -->
                <nav class="doc-sidebar-toc" id="sidebarToc">
                    <h4>Primeros Pasos</h4>
                    <a href="#overview">Descripción General de la Plataforma</a>
                    <a href="#navigation">Navegación por la Barra Lateral</a>
                    <a href="#roles">Roles y Permisos de Usuario</a>
                    <a href="#first-login">Su Primer Inicio de Sesión</a>
                    <a href="#whats-new">Novedades en 2.6.2</a>
                    <a href="#language">Cambio de Idioma</a>
                    <a href="#question-types">Tipos de Pregunta de Teléfono y VAT</a>

                    <h4>GRC — Inicio Rápido</h4>
                    <a href="#grc-overview">¿Qué es GRC?</a>
                    <a href="#grc-getting-started">Primeros Pasos</a>
                    <a href="#grc-step1">Paso 1: Crear Evaluación</a>
                    <a href="#grc-step2">Paso 2: Responder Preguntas</a>
                    <a href="#grc-step3">Paso 3: Cargar Evidencias</a>
                    <a href="#grc-step4">Paso 4: Ver Puntuaciones</a>
                    <a href="#grc-step5">Paso 5: Generar Informe</a>

                    <h4>GRC — Funciones</h4>
                    <a href="#grc-fairscore">Puntuación de Madurez CSF</a>
                    <a href="#grc-gaps">Análisis de Brechas</a>
                    <a href="#grc-frameworks">Marcos</a>
                    <a href="#grc-controls">Controles Internos</a>
                    <a href="#grc-crosswalk">Mapeo Cruzado de Marcos</a>
                    <a href="#grc-evidence">Biblioteca de Evidencias</a>
                    <a href="#grc-policies">Gestión de Políticas</a>
                    <a href="#grc-audits">Auditorías y Hallazgos</a>
                    <a href="#grc-risks">Registro de Riesgos</a>
                    <a href="#grc-monitors">Monitores Continuos</a>
                    <a href="#grc-tasks">Bandeja de Entrada de Tareas</a>
                    <a href="#grc-dashboard">Panel de GRC</a>

                    <h4>Módulo TPRM</h4>
                    <a href="#tprm-overview">¿Qué es TPRM?</a>
                    <a href="#tprm-add-vendor">Agregar un Proveedor</a>
                    <a href="#tprm-lifecycle">Ciclo de Vida del Proveedor</a>
                    <a href="#tprm-assessments">Evaluaciones de Proveedores</a>
                    <a href="#assessment-forms">Formularios de Evaluación e IA</a>
                    <a href="#tprm-action-plan">Plan de Acción del Proveedor</a>
                    <a href="#tprm-srs">Tarjeta de Puntuación de Riesgo de Seguridad</a>
                    <a href="#tprm-fair">Análisis FAIR</a>
                    <a href="#tprm-fourth-party">Riesgo de Cuarto Nivel</a>
                    <a href="#tprm-shadow-saas">Shadow SaaS</a>

                    <h4>Incorporación y Compras</h4>
                    <a href="#onboarding-workflow">Incorporación de Proveedores</a>
                    <a href="#custom-onboarding">Campos de Incorporación Personalizados</a>
                    <a href="#ai-review">Revisión de IA</a>
                    <a href="#procurement-cyber-status">Estado Cibernético de Compras</a>
                    <a href="#shadow-saas-grip">Integración Grip Shadow SaaS</a>
                    <a href="#shadow-saas-hero">Integración Hero Shadow SaaS</a>
                    <a href="#zscaler">Bloqueo Zscaler</a>
                    <a href="#breach-alerts">Alertas de Brechas / Ciberseguridad</a>

                    <h4>Portal de Administración</h4>
                    <a href="#admin-general">Configuración General</a>
                    <a href="#admin-branding">Imagen de Marca y Tema</a>
                    <a href="#admin-users">Gestión de Usuarios</a>
                    <a href="#admin-acl-groups">Grupos ACL</a>
                    <a href="#admin-templates">Plantillas de Evaluación</a>
                    <a href="#admin-email">Configuración de Correo Electrónico</a>
                    <a href="#admin-saml">SAML / SSO</a>
                    <a href="#admin-ai">Integración de IA</a>
                    <a href="#admin-backup">Copias de Seguridad de Bases de Datos Grandes</a>
                    <a href="#admin-updates">Actualización de la Plataforma</a>

                    <h4>Ayuda y Referencia</h4>
                    <a href="#faq">Preguntas Frecuentes</a>
                    <a href="#troubleshooting">Solución de Problemas</a>
                    <a href="#glossary">Glosario</a>
                </nav>

