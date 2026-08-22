<div align="center">

# ⛏️ MINERVOX

### Gestión & Suministros Mineros

**Sistema POS + Inventario para comercializadoras de insumos y reactivos químicos de minería**

![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-MariaDB-005C84?style=for-the-badge&logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-ES6+-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![XAMPP](https://img.shields.io/badge/XAMPP-FB7A24?style=for-the-badge&logo=xampp&logoColor=white)
![PWA](https://img.shields.io/badge/PWA-Ready-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white)
![License](https://img.shields.io/badge/Licencia-MIT-yellow?style=for-the-badge)

</div>

---

## 📌 Tabla de Contenidos

- [Acerca del Proyecto](#-acerca-del-proyecto)
- [Módulos Principales](#-módulos-principales)
- [Stack Tecnológico](#️-stack-tecnológico)
- [Vista Previa](#-vista-previa)
- [Configuración y Entorno Local](#️-configuración-y-entorno-local)
- [Estructura del Proyecto](#-estructura-del-proyecto)
- [Roadmap](#-roadmap)
- [Contribuir](#-contribuir)
- [Autor](#-autor)
- [Licencia](#-licencia)

---

## 📖 Acerca del Proyecto

> **MINERVOX** es un sistema web progresivo (**PWA**) de **Punto de Venta (POS)**, diseñado a la medida para la comercialización de insumos y reactivos químicos mineros en **Puerto Claver, El Bagre**.

El aplicativo centraliza la gestión de ventas de contado, el control de inventario de **sustancias controladas** y la administración de cartera (ventas a crédito o *"fiaos"*), automatizando la creación de pagarés en PDF y la comunicación directa con el cliente.

| 🎯 Objetivo | Descripción |
|---|---|
| 📦 **Sustancias controladas** | Inventario en tiempo real de insumos y reactivos químicos mineros |
| 🧾 **Pagarés automatizados** | Generación instantánea de vales de crédito en PDF |
| 💰 **Gestión de cartera** | Seguimiento de ventas a crédito ("fiaos") con alertas de vencimiento |
| 📱 **Mobile-first** | Interfaz ágil optimizada para ventas en mostrador desde cualquier dispositivo |

---

## ✨ Módulos Principales

<table>
<tr>
<td width="50%">

### 🛒 Facturación Dual
Interfaz POS que alterna instantáneamente entre tarifas de **Contado** y **Crédito**.

</td>
<td width="50%">

### 🔍 Gestión de Clientes en Caliente
Formulario inteligente que busca por **Cédula/NIT** y autocompleta los datos, permitiendo editar o registrar clientes nuevos sin abandonar el flujo de venta.

</td>
</tr>
<tr>
<td width="50%">

### 🚦 Control de Cartera (Semáforo)
Panel financiero que monitorea los plazos otorgados (**8 a 15 días**), registra abonos y genera enlaces dinámicos para enviar cobros y comprobantes vía **WhatsApp**.

</td>
<td width="50%">

### 📦 Inventario Inteligente
Seguimiento detallado del stock de productos base con alertas preventivas de descapitalización al alcanzar el mínimo de productos.

</td>
</tr>
</table>

---

## 🛠️ Stack Tecnológico

<div align="center">

| Capa | Tecnologías |
|:---:|:---|
| 🎨 **Frontend** | ![HTML5](https://img.shields.io/badge/HTML5-E34F26?logo=html5&logoColor=white) ![CSS3](https://img.shields.io/badge/CSS3-1572B6?logo=css3&logoColor=white) ![Tailwind](https://img.shields.io/badge/Tailwind_CSS-38B2AC?logo=tailwind-css&logoColor=white) ![JS](https://img.shields.io/badge/JavaScript-F7DF1E?logo=javascript&logoColor=black) — Tailwind vía CDN, diseño **Mobile-First**, interactividad con **JS ES6+** |
| ⚙️ **Backend** | ![PHP](https://img.shields.io/badge/PHP_8+-777BB4?logo=php&logoColor=white) `POO` + `PDO` — conexiones seguras y consultas eficientes |
| 🗄️ **Base de Datos** | ![MySQL](https://img.shields.io/badge/MySQL-005C84?logo=mysql&logoColor=white) ![MariaDB](https://img.shields.io/badge/MariaDB-003545?logo=mariadb&logoColor=white) — arquitectura relacional |
| 📚 **Librerías** | `jsPDF` · `FPDF` — renderizado y generación instantánea de vales de crédito |

</div>

---

## 🖼️ Vista Previa

<div align="center">

| 🛒 Punto de Venta | 🚦 Semáforo de Cartera | 📄 Vale de Pago |
|:---:|:---:|:---:|
| _Captura pendiente_ | _Captura pendiente_ | _Captura pendiente_ |

</div>


---

## ⚙️ Configuración y Entorno Local

MINERVOX está optimizado para un despliegue ágil en entornos locales mediante **XAMPP**, sin necesidad de dependencias ni compiladores externos (**Node.js / npm**).

```bash
# 1️⃣ Clona (o descarga) el repositorio dentro del directorio htdocs de XAMPP
cd /ruta/a/xampp/htdocs
git clone https://github.com/luis06-op/minervox-pos.git
```

**2️⃣ Inicia Apache y MySQL** desde el Panel de Control de XAMPP.

**3️⃣ Importa la base de datos:**
Abre `phpMyAdmin` (`http://localhost/phpmyadmin`), crea una base de datos nueva e importa el script `database.sql` incluido en el repositorio — el modelo de datos queda listo sin pasos adicionales.

**4️⃣ Accede a la aplicación** desde el navegador:
```
http://localhost/minervox-pos/
```

> ⚠️ Requisitos: **XAMPP** (Apache + PHP 8+ + MySQL/MariaDB), extensión `PDO` habilitada.

---

## 📂 Estructura del Proyecto

```
minervox-pos/
├── 📁 api/              # Endpoints PHP (PDO)
├── 📁 assets/           # CSS, JS, íconos, imágenes
├── 📄 database.sql      # Esquema de la base de datos
├── 📄 index.html        # Punto de entrada PWA
└── 📄 README.md
```

---

## 🗺️ Roadmap

- [x] Módulo de facturación (Contado/Crédito)
- [x] Generador de pagarés en PDF
- [x] Buscador de clientes por Cédula/NIT
- [ ] Dashboard de reportes y estadísticas de ventas
- [ ] Notificaciones automáticas por WhatsApp API
- [ ] Modo offline completo (Service Workers)
- [ ] Roles y permisos multiusuario

---

## 🤝 Contribuir

¡Las contribuciones son bienvenidas! 🎉

1. Haz un **Fork** del proyecto
2. Crea tu rama (`git checkout -b feature/nueva-funcionalidad`)
3. Haz **commit** de tus cambios (`git commit -m 'Agrega nueva funcionalidad'`)
4. Sube tu rama (`git push origin feature/nueva-funcionalidad`)
5. Abre un **Pull Request**

---

## 👤 Autor

<div align="center">

**Luis Muñoz**

[![GitHub](https://img.shields.io/badge/GitHub-100000?style=for-the-badge&logo=github&logoColor=white)](https://github.com/luis06-op)

</div>

---

## 📄 Licencia

Este proyecto está bajo la Licencia **MIT** — consulta el archivo [`LICENSE`](LICENSE) para más detalles.

<div align="center">

### ⛏️ Hecho con dedicación para optimizar la gestión minera ⛏️

</div>
