<div align="center">

# ⛏️ MINERVOX

### Gestión & Suministros Mineros

**Sistema POS + Inventario para comercializadoras de insumos y reactivos químicos de minería**

![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-MariaDB-005C84?style=for-the-badge&logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white)
![Vue.js](https://img.shields.io/badge/Vue.js-4FC08D?style=for-the-badge&logo=vue.js&logoColor=white)
![PWA](https://img.shields.io/badge/PWA-Ready-5A0FC8?style=for-the-badge&logo=pwa&logoColor=white)
![License](https://img.shields.io/badge/Licencia-MIT-yellow?style=for-the-badge)

</div>

---

## 📌 Tabla de Contenidos

- [Acerca del Proyecto](#-acerca-del-proyecto)
- [Características Principales](#-características-principales)
- [Stack Tecnológico](#️-stack-tecnológico)
- [Vista Previa](#-vista-previa)
- [Instalación](#-instalación)
- [Estructura del Proyecto](#-estructura-del-proyecto)
- [Roadmap](#-roadmap)
- [Contribuir](#-contribuir)
- [Autor](#-autor)
- [Licencia](#-licencia)

---

## 📖 Acerca del Proyecto

> **MINERVOX** es una aplicación web progresiva (**PWA**) de **Punto de Venta (POS)** y **gestión de inventario**, diseñada específicamente para comercializadoras de insumos y reactivos químicos de minería.

El sistema optimiza:

| 🎯 Objetivo | Descripción |
|---|---|
| 📦 **Control de stock** | Inventario en tiempo real con alertas de reabastecimiento |
| 🧾 **Comprobantes en PDF** | Facturas y vales de crédito generados automáticamente |
| 💰 **Gestión de cartera** | Seguimiento ágil de créditos y "fiaos" |
| 📱 **Mobile-first** | Adaptado completamente a dispositivos móviles |

---

## ✨ Características Principales

<table>
<tr>
<td width="50%">

### 🛒 Punto de Venta Dinámico
Facturación rápida con cálculo automático de precios diferenciados (**Contado** vs. **Crédito**).

</td>
<td width="50%">

### 🚦 Gestión de Cartera Inteligente
Control de cuentas por cobrar con alertas visuales de vencimiento — *Semáforo de deudas*.

</td>
</tr>
<tr>
<td width="50%">

### 📄 Generador de Pagarés
Emisión automática de vales de entrega en PDF, listos para imprimir o compartir por **WhatsApp**.

</td>
<td width="50%">

### 🔍 Buscador Autocompletable
Búsqueda en tiempo real de clientes por **Cédula/NIT** para agilizar las ventas en mostrador.

</td>
</tr>
<tr>
<td width="50%" colspan="2">

### 📦 Control de Inventario
Alertas automáticas de reabastecimiento para productos críticos, evitando desabastos.

</td>
</tr>
</table>

---

## 🛠️ Stack Tecnológico

<div align="center">

| Capa | Tecnologías |
|:---:|:---|
| 🎨 **Frontend** | ![HTML5](https://img.shields.io/badge/HTML5-E34F26?logo=html5&logoColor=white) ![CSS3](https://img.shields.io/badge/CSS3-1572B6?logo=css3&logoColor=white) ![Tailwind](https://img.shields.io/badge/Tailwind_CSS-38B2AC?logo=tailwind-css&logoColor=white) ![JS](https://img.shields.io/badge/JavaScript-F7DF1E?logo=javascript&logoColor=black) ![Vue](https://img.shields.io/badge/Vue.js-4FC08D?logo=vue.js&logoColor=white) |
| ⚙️ **Backend** | ![PHP](https://img.shields.io/badge/PHP_8+-777BB4?logo=php&logoColor=white) `Arquitectura PDO` |
| 🗄️ **Base de Datos** | ![MySQL](https://img.shields.io/badge/MySQL-005C84?logo=mysql&logoColor=white) ![MariaDB](https://img.shields.io/badge/MariaDB-003545?logo=mariadb&logoColor=white) |
| 📚 **Librerías** | `jsPDF` · `FPDF` · Generación de PDFs |

</div>

---

## 🖼️ Vista Previa

<div align="center">

| 🛒 Punto de Venta | 🚦 Semáforo de Cartera | 📄 Vale de Pago |
|:---:|:---:|:---:|
| _Captura pendiente_ | _Captura pendiente_ | _Captura pendiente_ |

</div>

---

## ⚙️ Instalación

```bash
# 1️⃣ Clona el repositorio
git clone https://github.com/luis06-op/minervox-pos.git
cd minervox-pos

# 2️⃣ Configura la base de datos
mysql -u root -p < database.sql

# 3️⃣ Configura las credenciales
cp config.example.php config.php
# Edita config.php con tus datos de conexión (host, usuario, password, DB)

# 4️⃣ Levanta un servidor local (ej. con PHP)
php -S localhost:8000

# 5️⃣ Abre en tu navegador
# http://localhost:8000
```

> ⚠️ Requisitos: **PHP 8+**, **MySQL/MariaDB**, extensión `PDO` habilitada.

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

**luis06-op**

[![GitHub](https://img.shields.io/badge/GitHub-100000?style=for-the-badge&logo=github&logoColor=white)](https://github.com/luis06-op)

</div>

---

## 📄 Licencia

Este proyecto está bajo la Licencia **MIT** — consulta el archivo [`LICENSE`](LICENSE) para más detalles.

<div align="center">

### ⛏️ Hecho con dedicación para optimizar la gestión minera ⛏️

</div>
