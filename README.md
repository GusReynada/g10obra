# G10 Obra — Sitio Web & Panel Administrativo

Sitio web minimalista para estudio de arquitectura y constructora desarrollado en **HTML5, CSS3, JavaScript, PHP y MySQL**, con un diseño de interfaz en modo oscuro de alto impacto (estética moderna monocromática con tarjetas y paneles estilo SaaS).

---

## 📁 Estructura del Proyecto

```text
g10obra/
│
├── config/
│   └── db.php                  # Conexión centralizada a MySQL con PDO (singleton & status)
│
├── database/
│   └── schema.sql              # Script SQL con tablas (admins, projects, contact_messages)
│
├── admin/
│   ├── auth.php                # Guard de autenticación y sesiones
│   ├── login.php               # Login administrativo con diseño dark
│   ├── logout.php              # Cierre seguro de sesión
│   ├── dashboard.php           # Panel principal con métricas, gráficos y accesos
│   ├── projects.php            # CRUD de obras (crear, editar, subir fotos, listar)
│   └── messages.php            # Bandeja de consultas recibidas desde la web
│
├── assets/
│   ├── css/
│   │   └── style.css           # Estilos OKLCH, diseño oscuro/claro, tarjetas y tipografía
│   └── js/
│       └── main.js             # Lógica de cambio de tema y navegación móvil
│
├── includes/
│   ├── header.php              # Cabecera reutilizable, metadatos y navegación
│   └── footer.php              # Pie de página unificado
│
├── uploads/
│   └── projects/               # Carpeta donde se guardan las fotografías de obras
│
├── .htaccess                   # Configuración de seguridad y caché para Hostinger Apache
├── index.php                   # Portada principal pública con catálogo y formulario
└── README.md                   # Esta guía
```

---

## 🚀 Guía de Instalación y Subida a Hostinger

### Paso 1: Crear la Base de Datos en Hostinger
1. Inicia sesión en tu **hPanel** de Hostinger.
2. Ve a **Bases de datos** &rarr; **Gestión de bases de datos MySQL**.
3. Crea una nueva base de datos (anota el **Nombre de la base de datos**, el **Usuario** y la **Contraseña**).
4. Haz clic en **phpMyAdmin** junto a tu base de datos recién creada.
5. Haz clic en la pestaña **Importar** y selecciona el archivo [`database/schema.sql`](file:///c:/Users/PC%20%2001/Desktop/g10obra/database/schema.sql).
6. Presiona **Continuar** para ejecutar la creación de tablas y datos iniciales.

### Paso 2: Configurar las Credenciales en PHP
Abre el archivo [`config/db.php`](file:///c:/Users/PC%20%2001/Desktop/g10obra/config/db.php) y reemplaza los valores de conexión:

```php
define('DB_HOST', 'localhost'); // En Hostinger suele ser localhost
define('DB_NAME', 'u123456789_g10obra'); // Nombre completo de la BD en Hostinger
define('DB_USER', 'u123456789_admin');   // Usuario de la BD en Hostinger
define('DB_PASS', 'TuClaveSegura123!');  // Contraseña de la BD
```

### Paso 3: Subir los Archivos a Hostinger
1. En Hostinger ve a **Administrador de Archivos** (File Manager).
2. Abre la carpeta `public_html/`.
3. Sube todos los archivos y carpetas de este proyecto directamente dentro de `public_html/`.
4. Asegúrate de que los permisos de la carpeta `uploads/projects/` permitan escritura (`755` o `775`).

---

## 🔐 Acceso al Panel de Administración

- **Ruta de acceso:** `tudominio.com/admin/login.php`
- **Usuario inicial:** `admin`
- **Contraseña inicial:** `admin123`

*(Puedes cambiar estos datos en cualquier momento desde la tabla `admins` en phpMyAdmin con `password_hash`)*.

---

## ✨ Características Incluidas
- **Estética de Referencia:** Tarjetas con bordes sutiles en modo oscuro, tipografía *Inter*, gráficos de barras, barras de progreso y botones blancos de alto contraste.
- **Detector en Tiempo Real de MySQL:** La portada te avisa en pantalla si la base de datos se conectó correctamente o qué datos faltan configurar.
- **Formulario de Contacto Funcional:** Las consultas de clientes se almacenan en MySQL y se gestionan desde la bandeja de entrada del panel admin.
- **Gestión de Obras:** Carga y edición de proyectos con imágenes, superficie, ubicación y año.
