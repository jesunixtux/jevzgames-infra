INSERT INTO roles (slug, name, description, is_system)
VALUES
('user', 'Usuario', 'Usuario normal de la plataforma.', 1),
('developer', 'Desarrollador', 'Gestiona juegos propios y configuracion publica.', 1),
('developer-extern', 'Desarrollador externo', 'Publica y configura juegos de terceros.', 1),
('admin', 'Administrador', 'Administra usuarios, juegos, logs y moderacion.', 1),
('supporter', 'Soporte', 'Atiende solicitudes y conversaciones de soporte.', 1),
('superroot', 'Superroot', 'Control total de la infraestructura.', 1)
ON DUPLICATE KEY UPDATE
name = VALUES(name),
description = VALUES(description),
is_system = VALUES(is_system);

INSERT INTO permissions (slug, name, description)
VALUES
('profile.view', 'Ver perfil', 'Permite ver el perfil propio.'),
('codes.redeem', 'Canjear codigos', 'Permite canjear codigos habilitados.'),
('games.manage', 'Gestionar juegos', 'Permite crear y gestionar juegos propios.'),
('api.keys.manage', 'Gestionar API keys', 'Permite crear, revocar y regenerar API keys de juegos.'),
('support.manage', 'Gestionar soporte', 'Permite atender solicitudes de soporte.'),
('admin.users.manage', 'Gestionar usuarios', 'Permite administrar usuarios.'),
('admin.logs.view', 'Ver logs', 'Permite revisar registros de actividad.'),
('superroot.settings.manage', 'Gestionar configuracion sensible', 'Permite modificar configuracion global sensible.')
ON DUPLICATE KEY UPDATE
name = VALUES(name),
description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.slug IN ('profile.view', 'codes.redeem')
WHERE r.slug = 'user';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.slug IN ('profile.view', 'codes.redeem', 'games.manage', 'api.keys.manage')
WHERE r.slug = 'developer';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.slug IN ('profile.view', 'codes.redeem', 'games.manage', 'api.keys.manage')
WHERE r.slug = 'developer-extern';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.slug IN ('profile.view', 'codes.redeem', 'games.manage', 'api.keys.manage', 'support.manage', 'admin.users.manage', 'admin.logs.view')
WHERE r.slug = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.slug IN ('profile.view', 'support.manage')
WHERE r.slug = 'supporter';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug = 'superroot';

INSERT INTO cdn_settings (name, mode, base_url, is_active, config_json)
VALUES ('Local', 'local', NULL, 1, NULL)
ON DUPLICATE KEY UPDATE updated_at = NOW();
