# Instalacion

## Requisitos

- PHP 8.1 o superior.
- MySQL o MariaDB.
- `pdo_mysql`.
- Apache o Nginx.
- Permisos de escritura para:

```text
app/config/
storage/logs/
storage/cache/
storage/uploads/
storage/sessions/
```

## Instalacion en XAMPP

1. Deja el proyecto en:

   ```text
   C:\xampp\jevzgames-infra
   ```

2. Abre el panel de XAMPP.
3. Inicia Apache.
4. Inicia MySQL.
5. Configura Apache para servir:

   ```text
   C:\xampp\jevzgames-infra\public
   ```

6. Abre:

   ```text
   http://localhost/install/
   ```

7. Datos comunes en XAMPP:

   ```text
   Host: 127.0.0.1
   Puerto: 3306
   Base: jevzgames_main
   Usuario: root
   Contrasena: vacia
   ```

8. Completa los datos del superroot.
9. Presiona `Instalar`.

## Apache

Ejemplo de VirtualHost local:

```apache
<VirtualHost *:80>
    ServerName jevzgames.local
    DocumentRoot "C:/xampp/jevzgames-infra/public"

    <Directory "C:/xampp/jevzgames-infra/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Agrega en `C:\Windows\System32\drivers\etc\hosts`:

```text
127.0.0.1 jevzgames.local
```

Luego abre:

```text
http://jevzgames.local/install/
```

## Nginx

Ejemplo base:

```nginx
server {
    listen 80;
    server_name jevzgames.local;
    root /var/www/jevzgames-infra/public;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

## Bloqueo del instalador

Cuando la instalacion termina se crean:

```text
app/config/config.php
app/config/installed.lock
```

Si `installed.lock` existe, `public/install/index.php` no vuelve a ejecutar la instalacion.

## Configuracion privada

`app/config/config.php` queda fuera de `public/` y contiene:

- Conexion a la base principal.
- URL base.
- Entorno.
- Servidor.
- Configuracion CDN.
- Configuracion de sesiones.

No se debe subir este archivo a repositorios publicos.

## Correo local en XAMPP

Para pruebas locales no necesitas SMTP. Entra como superroot en:

```text
http://jevzgames.local/superroot/?section=access
```

Activa `Verificacion por correo` y deja `Envio` en `Log local`. Al registrar un usuario, el enlace de verificacion queda en:

```text
storage/logs/app.log
```

Busca el evento `email_verification_link` y abre la URL `verify-email` en el navegador.

## Correo real con PHPMailer

La carpeta externa permitida para correo es:

```text
phpmailer/
```

Entra a:

```text
http://jevzgames.local/superroot/?section=access
```

Selecciona `SMTP con PHPMailer` y configura:

- Host SMTP.
- Puerto.
- Seguridad: `STARTTLS`, `SSL/TLS` o sin cifrado.
- Usuario y password SMTP.
- Remitente y nombre del remitente.

Guarda y luego usa `Enviar prueba SMTP a mi correo`. El password SMTP queda cifrado en `system_settings`.
