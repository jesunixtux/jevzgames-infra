<?php
declare(strict_types=1);

function app_supported_locales(): array
{
    return [
        'en' => 'English',
        'es' => 'Español',
    ];
}

function current_locale(): string
{
    $default = 'en';
    $enabled = ['en', 'es'];

    if (function_exists('is_installed') && is_installed()) {
        try {
            $settings = \App\Models\PlatformSettings::languageSettings();
            $default = (string) ($settings['default_locale'] ?? $default);
            $enabled = is_array($settings['enabled_locales'] ?? null) ? $settings['enabled_locales'] : $enabled;
        } catch (\Throwable) {
        }
    }

    $requested = '';
    if (isset($_GET['lang'])) {
        $requested = (string) $_GET['lang'];
    } elseif (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['locale'])) {
        $requested = (string) $_SESSION['locale'];
    }

    $requested = strtolower(substr(trim(str_replace('_', '-', $requested)), 0, 2));
    if ($requested !== '' && in_array($requested, $enabled, true)) {
        if (isset($_GET['lang']) && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['locale'] = $requested;
        }

        return $requested;
    }

    return in_array($default, $enabled, true) ? $default : 'en';
}

function available_locales(): array
{
    $supported = app_supported_locales();
    $enabled = ['en', 'es'];

    if (function_exists('is_installed') && is_installed()) {
        try {
            $settings = \App\Models\PlatformSettings::languageSettings();
            $enabled = is_array($settings['enabled_locales'] ?? null) ? $settings['enabled_locales'] : $enabled;
            $supported = is_array($settings['supported_locales'] ?? null) ? $settings['supported_locales'] : $supported;
        } catch (\Throwable) {
        }
    }

    return array_intersect_key($supported, array_flip($enabled));
}

function locale_url(string $locale): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    $queryString = parse_url($uri, PHP_URL_QUERY);
    $path = is_string($path) && $path !== '' ? $path : '/';
    $base = public_base_path();

    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
        $path = $path === '' ? '/' : $path;
    }

    $query = [];
    if (is_string($queryString) && $queryString !== '') {
        parse_str($queryString, $query);
    }

    $query['lang'] = strtolower(substr(trim($locale), 0, 2));
    $suffix = '?' . http_build_query($query);

    return url($path . $suffix);
}

function t(string $key): string
{
    $locale = current_locale();
    $dictionary = [
        'en' => [
            'nav.games' => 'Games',
            'nav.community' => 'Community',
            'nav.publish' => 'Publish',
            'nav.workshop' => 'Workshop',
            'nav.client' => 'Client',
            'nav.dark_mode' => 'Dark mode',
            'nav.light_mode' => 'Light mode',
            'nav.install' => 'Install',
            'nav.profile' => 'Profile',
            'nav.library' => 'Library',
            'nav.achievements' => 'Achievements',
            'nav.inventory' => 'Inventory',
            'nav.friends' => 'Friends',
            'nav.messages' => 'Messages',
            'nav.notifications' => 'Notifications',
            'nav.support' => 'Support',
            'nav.admin' => 'Admin',
            'nav.support_panel' => 'Support panel',
            'nav.superroot' => 'Superroot',
            'nav.logout' => 'Logout',
            'nav.login' => 'Login',
            'nav.register' => 'Register',
            'nav.eula' => 'EULA',
            'presence.online' => 'Online',
            'presence.offline' => 'Offline',
            'presence.playing' => 'Playing {game}',
            'achievements.hidden_title' => 'Hidden achievement',
            'achievements.hidden_description' => 'This achievement is secret until you unlock it.',
            'oauth.authorize_title' => 'Authorize game',
            'oauth.enter_code' => 'Enter the code shown by the game to link it to your account.',
            'oauth.code' => 'Code',
            'oauth.continue' => 'Continue',
            'oauth.no_request' => 'No active request exists for that code.',
            'oauth.try_again' => 'Search again',
            'oauth.game' => 'Game',
            'oauth.status' => 'Status',
            'oauth.expires' => 'Expires',
            'oauth.requires_access' => 'This game requires access to your account to continue.',
            'oauth.approve' => 'Approve link',
            'oauth.deny' => 'Deny',
            'oauth.approved' => 'Request approved. Return to the game to continue.',
            'oauth.denied' => 'Request denied.',
            'oauth.expired' => 'Request expired. Start the link again from the compatible app.',
            'friends.title' => 'Friends',
            'friends.subtitle' => 'Your friend list, activity and quick actions.',
            'friends.total' => 'friends',
            'friends.online' => 'online',
            'friends.pending' => 'pending',
            'friends.list' => 'Friend list',
            'friends.empty' => 'You do not have friends added yet.',
            'friends.find' => 'Find people',
            'friends.send_message' => 'Send message',
            'friends.send_message_help' => 'Open a private conversation.',
            'friends.view_profile' => 'View profile',
            'friends.view_profile_help' => 'Open this public profile.',
            'friends.manage' => 'Manage friend',
            'friends.remove' => 'Remove friend',
            'friends.mute' => 'Mute',
            'friends.block' => 'Block',
            'friends.no_selection' => 'No friend selected',
            'friends.no_selection_help' => 'Add friends from community or public profiles.',
            'friends.requests' => 'Friend requests',
            'friends.requests_help' => 'Accept or reject incoming friend requests.',
            'friends.no_requests' => 'There are no pending requests.',
            'friends.accept' => 'Accept',
            'friends.reject' => 'Reject',
        ],
        'es' => [
            'nav.games' => 'Juegos',
            'nav.community' => 'Comunidad',
            'nav.publish' => 'Publicar',
            'nav.workshop' => 'Workshop',
            'nav.client' => 'Cliente',
            'nav.dark_mode' => 'Modo oscuro',
            'nav.light_mode' => 'Modo claro',
            'nav.install' => 'Instalar',
            'nav.profile' => 'Perfil',
            'nav.library' => 'Biblioteca',
            'nav.achievements' => 'Logros',
            'nav.inventory' => 'Inventario',
            'nav.friends' => 'Amigos',
            'nav.messages' => 'Mensajes',
            'nav.notifications' => 'Notificaciones',
            'nav.support' => 'Soporte',
            'nav.admin' => 'Admin',
            'nav.support_panel' => 'Panel soporte',
            'nav.superroot' => 'Superroot',
            'nav.logout' => 'Salir',
            'nav.login' => 'Login',
            'nav.register' => 'Registro',
            'nav.eula' => 'EULA',
            'presence.online' => 'Conectado',
            'presence.offline' => 'Desconectado',
            'presence.playing' => 'Jugando {game}',
            'achievements.hidden_title' => 'Logro oculto',
            'achievements.hidden_description' => 'Este logro es secreto hasta desbloquearlo.',
            'oauth.authorize_title' => 'Autorizar juego',
            'oauth.enter_code' => 'Ingresa el codigo que muestra el juego para vincularlo a tu cuenta.',
            'oauth.code' => 'Codigo',
            'oauth.continue' => 'Continuar',
            'oauth.no_request' => 'No existe una solicitud activa con ese codigo.',
            'oauth.try_again' => 'Buscar otra vez',
            'oauth.game' => 'Juego',
            'oauth.status' => 'Estado',
            'oauth.expires' => 'Expira',
            'oauth.requires_access' => 'Este juego requiere acceso a tu cuenta para continuar.',
            'oauth.approve' => 'Aprobar vinculo',
            'oauth.deny' => 'Rechazar',
            'oauth.approved' => 'Solicitud aprobada. Vuelve al juego para continuar.',
            'oauth.denied' => 'Solicitud rechazada.',
            'oauth.expired' => 'Solicitud expirada. Inicia el vinculo otra vez desde la app compatible.',
            'friends.title' => 'Amigos',
            'friends.subtitle' => 'Tu lista de amigos, actividad y acciones rapidas.',
            'friends.total' => 'amigos',
            'friends.online' => 'conectados',
            'friends.pending' => 'pendientes',
            'friends.list' => 'Lista de amigos',
            'friends.empty' => 'Aun no tienes amigos agregados.',
            'friends.find' => 'Buscar personas',
            'friends.send_message' => 'Enviar mensaje',
            'friends.send_message_help' => 'Abre una conversacion privada.',
            'friends.view_profile' => 'Ver perfil',
            'friends.view_profile_help' => 'Abre este perfil publico.',
            'friends.manage' => 'Gestionar amigo',
            'friends.remove' => 'Quitar amigo',
            'friends.mute' => 'Silenciar',
            'friends.block' => 'Bloquear',
            'friends.no_selection' => 'No hay amigo seleccionado',
            'friends.no_selection_help' => 'Agrega amigos desde comunidad o perfiles publicos.',
            'friends.requests' => 'Solicitudes de amistad',
            'friends.requests_help' => 'Acepta o rechaza solicitudes de amistad recibidas.',
            'friends.no_requests' => 'No hay solicitudes pendientes.',
            'friends.accept' => 'Aceptar',
            'friends.reject' => 'Rechazar',
        ],
    ];

    return $dictionary[$locale][$key] ?? $dictionary['en'][$key] ?? $key;
}

function i18n_text(string $es, string $en): string
{
    return current_locale() === 'es' ? $es : $en;
}

function i18n_translate_rendered_html(string $html): string
{
    if (current_locale() === 'es' || $html === '') {
        return $html;
    }

    $blocks = [];
    $html = preg_replace_callback(
        '#<(script|style|textarea|pre|code)\b[^>]*>.*?</\1>#is',
        static function (array $match) use (&$blocks): string {
            $key = '%%I18N_BLOCK_' . count($blocks) . '%%';
            $blocks[$key] = $match[0];
            return $key;
        },
        $html
    ) ?? $html;

    $map = i18n_html_dictionary();
    $html = preg_replace_callback(
        '#>([^<]+)<#',
        static fn (array $match): string => '>' . strtr($match[1], $map) . '<',
        $html
    ) ?? $html;

    return strtr($html, $blocks);
}

function i18n_html_dictionary(): array
{
    return [
        'JevzGames Infraestructura modular en PHP puro.' => 'JevzGames modular infrastructure in plain PHP.',
        'Configuracion' => 'Configuration',
        'Configuración' => 'Configuration',
        'Configuracion global' => 'Global configuration',
        'Configuracion publica' => 'Public configuration',
        'Configuracion JSON' => 'JSON configuration',
        'Contenido editable' => 'Editable content',
        'Idioma por defecto' => 'Default language',
        'Idiomas disponibles' => 'Available languages',
        'Idiomas activos' => 'Enabled languages',
        'Textos por idioma' => 'Texts by language',
        'Titulo de inicio' => 'Home title',
        'Texto de inicio' => 'Home text',
        'Texto del catalogo' => 'Catalog text',
        'Texto de biblioteca' => 'Library text',
        'Estructura lista para juegos con configuracion propia y APIs HTTP/JSON.' => 'Structure ready for games with their own configuration and HTTP/JSON APIs.',
        'Estructura lista para juegos con configuraciÃ³n propia y APIs HTTP/JSON.' => 'Structure ready for games with their own configuration and HTTP/JSON APIs.',
        'Los juegos archivados no se muestran publicamente.' => 'Archived games are not shown publicly.',
        'Los juegos archivados no se muestran pÃºblicamente.' => 'Archived games are not shown publicly.',
        'Guardar contenido' => 'Save content',
        'Ver inicio' => 'View home',
        'Ver catalogo' => 'View catalog',
        'Ver catálogo' => 'View catalog',
        'Guardar configuracion' => 'Save configuration',
        'Guardar funciones' => 'Save features',
        'Guardar acceso legal' => 'Save legal access',
        'Guardar mantenimiento' => 'Save maintenance',
        'Guardar perfil' => 'Save profile',
        'Guardar privacidad' => 'Save privacy',
        'Guardar cambios' => 'Save changes',
        'Guardar' => 'Save',
        'Crear' => 'Create',
        'Crear cuenta' => 'Create account',
        'Crear ticket' => 'Create ticket',
        'Crear codigo' => 'Create code',
        'Crear logro' => 'Create achievement',
        'Crear juego' => 'Create game',
        'Crear config' => 'Create config',
        'Cancelar edicion' => 'Cancel edit',
        'Cancelar edición' => 'Cancel edit',
        'Cancelar solicitud' => 'Cancel request',
        'Editar' => 'Edit',
        'Editar perfil' => 'Edit profile',
        'Editar juego' => 'Edit game',
        'Editar logro' => 'Edit achievement',
        'Editar cloud save' => 'Edit cloud save',
        'Nuevo juego' => 'New game',
        'Nuevo logro' => 'New achievement',
        'Nuevo mensaje' => 'New message',
        'Buscar personas' => 'Find people',
        'Tu lista de amigos, actividad y acciones rapidas.' => 'Your friend list, activity and quick actions.',
        'Tu lista de amigos, actividad y acciones rápidas.' => 'Your friend list, activity and quick actions.',
        'Lista de amigos' => 'Friend list',
        'Aun no tienes amigos agregados.' => 'You do not have friends added yet.',
        'Aún no tienes amigos agregados.' => 'You do not have friends added yet.',
        'Gestionar amigo' => 'Manage friend',
        'No hay amigo seleccionado' => 'No friend selected',
        'Agrega amigos desde comunidad o perfiles publicos.' => 'Add friends from community or public profiles.',
        'Agrega amigos desde comunidad o perfiles públicos.' => 'Add friends from community or public profiles.',
        'Solicitudes de amistad' => 'Friend requests',
        'Acepta o rechaza solicitudes de amistad recibidas.' => 'Accept or reject incoming friend requests.',
        'Tu lista de amigos, actividad y acciones rapidas' => 'Your friend list, activity and quick actions',
        'Abre una conversacion privada.' => 'Open a private conversation.',
        'Abre una conversación privada.' => 'Open a private conversation.',
        'Abre este perfil publico.' => 'Open this public profile.',
        'Abre este perfil público.' => 'Open this public profile.',
        'Enviar mensaje' => 'Send message',
        'Nueva integracion' => 'New integration',
        'Nueva config cloud' => 'New cloud config',
        'Volver al inicio' => 'Back to home',
        'Volver al catalogo' => 'Back to catalog',
        'Volver al login' => 'Back to login',
        'Volver' => 'Back',
        'Ver perfil' => 'View profile',
        'Ver perfil publico' => 'View public profile',
        'Ver perfil público' => 'View public profile',
        'Ver juego' => 'View game',
        'Ver juegos' => 'View games',
        'Ver EULA publico' => 'View public EULA',
        'Ver EULA público' => 'View public EULA',
        'Verificar correo' => 'Verify email',
        'Ver inventario' => 'View inventory',
        'Ver logros' => 'View achievements',
        'Juegos' => 'Games',
        'Juego' => 'Game',
        'Usuarios' => 'Users',
        'Usuario' => 'User',
        'Logros' => 'Achievements',
        'Logro' => 'Achievement',
        'Biblioteca' => 'Library',
        'Inventario' => 'Inventory',
        'Mensajes' => 'Messages',
        'Mensaje' => 'Message',
        'Notificaciones' => 'Notifications',
        'Soporte' => 'Support',
        'Perfil' => 'Profile',
        'Perfil publico' => 'Public profile',
        'Perfil público' => 'Public profile',
        'Comunidad' => 'Community',
        'Publicar' => 'Publish',
        'Publicar juego' => 'Publish game',
        'Publicar item' => 'Publish item',
        'Mantenimiento' => 'Maintenance',
        'Modo mantenimiento' => 'Maintenance mode',
        'Estado' => 'Status',
        'Estado actual' => 'Current status',
        'Acciones' => 'Actions',
        'Resumen' => 'Overview',
        'Funciones' => 'Features',
        'Contenido' => 'Content',
        'Acceso legal' => 'Legal access',
        'Integraciones' => 'Integrations',
        'Usuarios y roles' => 'Users and roles',
        'Correo y EULA' => 'Email and EULA',
        'Verificacion por correo' => 'Email verification',
        'Verificación por correo' => 'Email verification',
        'Correo' => 'Email',
        'Email' => 'Email',
        'Password' => 'Password',
        'Contrasena' => 'Password',
        'Contraseña' => 'Password',
        'Confirmar contrasena' => 'Confirm password',
        'Confirmar contraseña' => 'Confirm password',
        'Email o usuario' => 'Email or username',
        'Mantener sesion iniciada' => 'Keep me signed in',
        'Mantener sesiÃ³n iniciada' => 'Keep me signed in',
        'Entrar' => 'Sign in',
        'Debes iniciar sesion para continuar.' => 'You must sign in to continue.',
        'Debes iniciar sesiÃ³n para continuar.' => 'You must sign in to continue.',
        'Credenciales invalidas o cuenta bloqueada.' => 'Invalid credentials or blocked account.',
        'Credenciales invÃ¡lidas o cuenta bloqueada.' => 'Invalid credentials or blocked account.',
        'No se pudo iniciar sesion en este momento.' => 'Could not sign in right now.',
        'No se pudo iniciar sesiÃ³n en este momento.' => 'Could not sign in right now.',
        'Reenviar verificacion' => 'Resend verification',
        'Reenviar verificaciÃ³n' => 'Resend verification',
        'Entrar con' => 'Sign in with',
        'Registro' => 'Register',
        'Login' => 'Login',
        'Salir' => 'Logout',
        'Cuenta' => 'Account',
        'Cuenta suspendida' => 'Suspended account',
        'Estado de usuario actualizado.' => 'User status updated.',
        'Juego guardado.' => 'Game saved.',
        'Estado de juego actualizado.' => 'Game status updated.',
        'Logro guardado.' => 'Achievement saved.',
        'Estado de logro actualizado.' => 'Achievement status updated.',
        'Configuracion actualizada.' => 'Configuration updated.',
        'Configuración actualizada.' => 'Configuration updated.',
        'Contenido publico actualizado.' => 'Public content updated.',
        'Contenido público actualizado.' => 'Public content updated.',
        'EULA aceptado.' => 'EULA accepted.',
        'No hay EULA activo.' => 'There is no active EULA.',
        'Aceptar y continuar' => 'Accept and continue',
        'Acepto esta version del EULA' => 'I accept this EULA version',
        'Version' => 'Version',
        'Versión' => 'Version',
        'Version actual' => 'Current version',
        'Version publica' => 'Public version',
        'Version minima' => 'Minimum version',
        'Sin version' => 'No version',
        'Sin descripcion publica.' => 'No public description.',
        'Sin descripción pública.' => 'No public description.',
        'Descripcion' => 'Description',
        'Descripción' => 'Description',
        'Titulo' => 'Title',
        'Título' => 'Title',
        'Codigo' => 'Code',
        'Código' => 'Code',
        'Codigos' => 'Codes',
        'Códigos' => 'Codes',
        'Puntos' => 'Points',
        'Meta' => 'Goal',
        'Desbloqueados' => 'Unlocked',
        'Pendiente' => 'Pending',
        'Pendientes' => 'Pending',
        'Desbloqueado' => 'Unlocked',
        'Logros desbloqueados' => 'Unlocked achievements',
        'Logros configurados por juego' => 'Achievements configured by game',
        'No hay logros configurados.' => 'No achievements configured.',
        'No hay logros para el filtro seleccionado.' => 'No achievements for the selected filter.',
        'Este juego no tiene logros configurados.' => 'This game has no configured achievements.',
        'No tienes juegos vinculados' => 'You have no linked games',
        'Progreso y logros desbloqueados por juego vinculado.' => 'Progress and unlocked achievements by linked game.',
        'Filtro' => 'Filter',
        'Todos' => 'All',
        'Todas' => 'All',
        'Buscar' => 'Search',
        'Responder' => 'Reply',
        'Enviar' => 'Send',
        'Enviar mensaje' => 'Send message',
        'Mensaje enviado.' => 'Message sent.',
        'Conversaciones privadas entre usuarios.' => 'Private conversations between users.',
        'Enviar a usuario' => 'Send to user',
        'Componer' => 'Compose',
        'No hay conversaciones.' => 'No conversations.',
        'No hay mensajes en esta conversacion.' => 'No messages in this conversation.',
        'No hay mensajes en esta conversación.' => 'No messages in this conversation.',
        'Solicitud enviada.' => 'Request sent.',
        'Solicitud aceptada.' => 'Request accepted.',
        'Solicitud rechazada.' => 'Request rejected.',
        'Amigo eliminado.' => 'Friend removed.',
        'Usuario silenciado.' => 'User muted.',
        'Usuario bloqueado.' => 'User blocked.',
        'Aceptar solicitud' => 'Accept request',
        'Rechazar' => 'Reject',
        'Aceptar' => 'Accept',
        'Agregar amigo' => 'Add friend',
        'Quitar amigo' => 'Remove friend',
        'Bloquear' => 'Block',
        'Desbloquear' => 'Unblock',
        'Silenciar' => 'Mute',
        'Quitar silencio' => 'Unmute',
        'Solicitudes cerradas' => 'Requests closed',
        'Perfil privado.' => 'Private profile.',
        'Usuario no encontrado' => 'User not found',
        'Usuario no encontrado.' => 'User not found.',
        'No hay juegos vinculados visibles.' => 'No linked games visible.',
        'No hay logros desbloqueados visibles.' => 'No unlocked achievements visible.',
        'No hay amigos visibles.' => 'No visible friends.',
        'Amigos' => 'Friends',
        'Solicitudes' => 'Requests',
        'Privacidad y contacto' => 'Privacy and contact',
        'Foto de perfil' => 'Profile picture',
        'Nombre publico' => 'Public name',
        'Nombre público' => 'Public name',
        'Visibilidad' => 'Visibility',
        'Publico' => 'Public',
        'Público' => 'Public',
        'Privado' => 'Private',
        'Subir foto' => 'Upload picture',
        'Imagen' => 'Image',
        'Imagen desbloqueada' => 'Unlocked image',
        'Imagen bloqueada' => 'Locked image',
        'Cloud saves' => 'Cloud saves',
        'Partidas guardadas por API.' => 'Save files stored by API.',
        'Recompensa agregada a tu inventario.' => 'Reward added to your inventory.',
        'Codigo canjeado.' => 'Code redeemed.',
        'Canjear' => 'Redeem',
        'Canje' => 'Redeem',
        'Activar' => 'Enable',
        'Desactivar' => 'Disable',
        'Activo' => 'Active',
        'Activa' => 'Active',
        'Inactiva' => 'Inactive',
        'Deshabilitado' => 'Disabled',
        'Publicado' => 'Published',
        'Archivado' => 'Archived',
        'Desarrollo' => 'Development',
        'Creado' => 'Created',
        'Actualizado' => 'Updated',
        'Ultimo login' => 'Last login',
        'Ultima build' => 'Latest build',
        'Última build' => 'Latest build',
        'Administracion operativa de usuarios, juegos, codigos, soporte y logs.' => 'Operational administration for users, games, codes, support and logs.',
        'Gestionar usuarios' => 'Manage users',
        'Gestionar juegos' => 'Manage games',
        'Admin puede bloquear, desbloquear o marcar recuperacion de usuarios normales. Admin y superroot se gestionan desde Superroot.' => 'Admin can block, unblock or mark recovery for regular users. Admin and superroot accounts are managed from Superroot.',
        'Bloqueada' => 'Blocked',
        'Recuperacion' => 'Recovery',
        'No hay juegos registrados.' => 'No registered games.',
        'Sube un .zip por juego. El cliente lo descarga, extrae y ejecuta la ruta indicada dentro del zip.' => 'Upload one .zip per game. The client downloads it, extracts it and runs the configured path inside the zip.',
        'API keys de juegos' => 'Game API keys',
        'La app compatible usa la public key para pedir un device code. La secret key se muestra solo al crearla.' => 'The compatible app uses the public key to request a device code. The secret key is only shown when it is created.',
        'Sin acciones' => 'No actions',
        'Aprobar crea un juego en estado' => 'Approval creates a game with status',
        'No hay solicitudes para este filtro.' => 'No requests for this filter.',
        'Cada bloque muestra los logros asociados a un juego.' => 'Each block shows the achievements associated with a game.',
        'Cada juego puede tener una o varias keys de guardado. Las integraciones usan' => 'Each game can have one or more save keys. Integrations use',
        'El codigo se muestra una sola vez al crearlo. En base de datos se guarda hash y preview.' => 'The code is shown only once when created. The database stores a hash and preview.',
        'Para canjear juegos usa' => 'To redeem games use',
        'Configurar workshop por juego' => 'Configure workshop per game',
        'Progreso y logros desbloqueados por juego vinculado.' => 'Progress and unlocked achievements by linked game.',
        'Suma de logros desbloqueados.' => 'Sum of unlocked achievements.',
        'Puedes ver todos tus logros o solo los de un juego.' => 'You can view all your achievements or only those from one game.',
        'Los logros apareceran cuando el juego se vincule e informe progreso por API.' => 'Achievements will appear when the game is linked and reports progress through the API.',
        'Publicaciones y conversaciones de usuarios de JevzGames.' => 'Posts and conversations from JevzGames users.',
        'El juego solicitado no existe o no esta visible.' => 'The requested game does not exist or is not visible.',
        'Obtener juego' => 'Get game',
        'El juego se vincula automaticamente cuando inicias sesion desde una app o cliente compatible.' => 'The game links automatically when you sign in from a compatible app or client.',
        'Este juego aun no tiene configuracion publica registrada.' => 'This game does not have public configuration yet.',
        'No hay juegos visibles' => 'No visible games',
        'Crea un juego desde Admin y dejalo en desarrollo, playtest, beta o publicado.' => 'Create a game from Admin and leave it in development, playtest, beta or published.',
        'Mis juegos vinculados' => 'My linked games',
        'Todavia no tienes juegos vinculados a tu cuenta.' => 'You do not have games linked to your account yet.',
        'Mis juegos' => 'My games',
        'Cuando inicies sesion desde una app compatible u obtengas un juego, aparecera aqui automaticamente.' => 'When you sign in from a compatible app or obtain a game, it will appear here automatically.',
        'El sistema todavia no esta instalado. Ejecuta el instalador inicial para crear la configuracion privada y el usuario superroot.' => 'The system is not installed yet. Run the initial installer to create the private configuration and superroot user.',
        'La plataforma esta instalada. Puedes iniciar sesion o crear una cuenta normal.' => 'The platform is installed. You can sign in or create a regular account.',
        'No encontre el usuario' => 'I could not find user',
        'Envio de juegos de la comunidad para revision.' => 'Community game submissions for review.',
        'Superroot debe activar esta funcion en el panel de funciones antes de recibir juegos externos.' => 'Superroot must enable this feature in the features panel before receiving external games.',
        'Necesitas una cuenta para enviar un juego.' => 'You need an account to submit a game.',
        'Enviar juego' => 'Submit game',
        'Nombre del juego' => 'Game name',
        'Slug publico' => 'Public slug',
        'Sitio del juego' => 'Game website',
        'Mis solicitudes' => 'My requests',
        'Todavia no has enviado juegos.' => 'You have not submitted games yet.',
        'Si tu perfil esta privado, puedes permitir que tus amigos vean estas partes.' => 'If your profile is private, you can allow your friends to see these sections.',
        'No tienes usuarios bloqueados ni silenciados.' => 'You do not have blocked or muted users.',
        'Desbloqueados en tus juegos vinculados.' => 'Unlocked in your linked games.',
        'Ganados por logros desbloqueados.' => 'Earned from unlocked achievements.',
        'solicitudes pendientes.' => 'pending requests.',
        'Todavia no tienes logros desbloqueados.' => 'You do not have unlocked achievements yet.',
        'No hay partidas cloud guardadas todavia.' => 'There are no cloud saves yet.',
        'Tu cuenta ya acepto la version vigente o no es obligatorio aceptarla.' => 'Your account already accepted the current version or acceptance is not required.',
        'Ingresa un codigo activo. Puede entregar items, licencias de juegos o recompensas configuradas.' => 'Enter an active code. It can grant items, game licenses or configured rewards.',
        'Configuracion sensible, integraciones, roles y mantenimiento de la infraestructura.' => 'Sensitive configuration, integrations, roles and infrastructure maintenance.',
        'Textos principales e idiomas que Superroot puede cambiar sin editar archivos.' => 'Main texts and languages Superroot can change without editing files.',
        'Formato por linea:' => 'Format per line:',
        'Despues de guardar aparecen campos para cada idioma.' => 'After saving, fields for each language appear.',
        'Controla verificacion de correo y el EULA vigente sin editar codigo.' => 'Control email verification and the active EULA without editing code.',
        'Enviar prueba SMTP a mi correo' => 'Send SMTP test to my email',
        'Mensaje publico' => 'Public message',
        'La verificacion por correo esta deshabilitada.' => 'Email verification is disabled.',
        'Mods, mapas, skins y contenido publicado por usuarios.' => 'Mods, maps, skins and content published by users.',
        'Superroot debe activar Workshop y Admin debe habilitarlo por juego.' => 'Superroot must enable Workshop and Admin must enable it per game.',
        'Inicia sesion para publicar contenido en juegos que acepten workshop.' => 'Sign in to publish content for games that accept workshop.',
        'No hay notificaciones.' => 'No notifications.',
        'Gestion de tickets, conversacion simple con polling, cierre y resultado.' => 'Ticket management, simple polling conversation, closure and result.',
        'Vista usuario' => 'User view',
        'Selecciona un ticket de la lista para responder o cerrar la solicitud.' => 'Select a ticket from the list to reply or close the request.',
        'Crea una solicitud y conversa con soporte mientras el chat este abierto.' => 'Create a request and talk with support while the chat is open.',
        'Crea o selecciona un ticket para ver la conversacion.' => 'Create or select a ticket to view the conversation.',
        'Token CSRF invalido. Recarga la pagina e intenta de nuevo.' => 'Invalid CSRF token. Reload the page and try again.',
        'Instalacion completada. Ahora puedes iniciar sesion con el superroot.' => 'Installation complete. You can now sign in with the superroot account.',
        'Debes iniciar sesion para vincular juegos.' => 'You must sign in to link games.',
        'Juego desvinculado. Se borraron tus datos de ese juego en la plataforma.' => 'Game unlinked. Your data for that game was deleted from the platform.',
        'Correo verificado. Ya puedes iniciar sesion.' => 'Email verified. You can now sign in.',
        'Ingresa un correo valido.' => 'Enter a valid email.',
        'Si existe una cuenta pendiente para ese correo, se genero un nuevo enlace de verificacion.' => 'If a pending account exists for that email, a new verification link was generated.',
        'Perfil publico actualizado.' => 'Public profile updated.',
        'Foto de perfil actualizada.' => 'Profile picture updated.',
        'Control de usuario actualizado.' => 'User control updated.',
        'La publicacion abierta de juegos esta deshabilitada.' => 'Open game publishing is disabled.',
        'Solicitud enviada. Un admin revisara el juego antes de publicarlo.' => 'Request sent. An admin will review the game before publishing it.',
        'Codigo canjeado. Recompensa agregada a tu inventario.' => 'Code redeemed. Reward added to your inventory.',
        'Acceso, correo y EULA actualizados.' => 'Access, email and EULA updated.',
        'Modo mantenimiento actualizado.' => 'Maintenance mode updated.',
        'Nuevo mensaje' => 'New message',
        'mensajes nuevos.' => 'new messages.',
        'acepto tu solicitud.' => 'accepted your request.',
        'Nunca' => 'Never',
        'Protegido' => 'Protected',
        'Rol' => 'Role',
        'Roles' => 'Roles',
        'Fecha' => 'Date',
        'Expira' => 'Expires',
        'Cualquiera' => 'Anyone',
        'No recibir' => 'Do not receive',
        'Solo amigos' => 'Friends only',
        'Solo amigos en comun' => 'Mutual friends only',
        'Solo amigos en común' => 'Mutual friends only',
        'Amigos o amigos en comun' => 'Friends or mutual friends',
        'Amigos o amigos en común' => 'Friends or mutual friends',
        'Verificado' => 'Verified',
        'Pendiente' => 'Pending',
        'Reenviar' => 'Resend',
    ];
}
