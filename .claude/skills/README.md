# Habilidades de finanzas (contenido de terceros)

Esta carpeta contiene **habilidades (skills) de finanzas de un proyecto externo**, copiadas a propósito para tenerlas a mano como material de consulta.

## Origen y licencia

| Datos | Valor |
|---|---|
| Repositorio | https://github.com/alirezarezvani/claude-skills |
| Ruta original | `finance/skills/` |
| Autor | Alireza Rezvani |
| Licencia | **MIT** (ver `LICENSE-claude-skills.txt`) |
| Versión del índice | 2.9.0 |

La licencia MIT permite copiar, modificar y redistribuir este material **siempre que se conserve el aviso de copyright y la licencia**, que es justo lo que hace el archivo `LICENSE-claude-skills.txt` incluido aquí.

## Qué se copió y qué no

Del paquete original `finance/skills/` (4 habilidades) **solo se trajeron 2**:

| Habilidad | ¿Se copió? | Motivo |
|---|---|---|
| `finance-skills` | ✅ | Es el índice que enruta hacia las demás |
| `financial-analyst` | ✅ | Análisis de ratios, variaciones de presupuesto y pronósticos: aplicable a reportes de negocio |
| `saas-metrics-coach` | ❌ | Métricas de empresas de software (ARR, churn, CAC). No aplica a una ferretería de insumos mineros |
| `stock-analysis` | ❌ | *"Stock"* aquí significa **acciones de la bolsa**, no inventario. Son ~2,4 MB de análisis bursátil |

> Nota: el `SKILL.md` del índice sigue mencionando `saas-metrics-coach`, que **no está presente** porque no se copió. Es una referencia rota conocida y sin consecuencias: el índice no es código.

## Cómo usarlo

- Es **documentación de referencia** para inspiración de formato (plantillas de reporte, guía de ratios) y no forma parte del funcionamiento de MINERVOX.
- MINERVOX calcula su propia contabilidad con su base de datos MariaDB. **Nada de lo que hay aquí se ejecuta dentro de la aplicación.**
- Los archivos `scripts/*.py` son **código de terceros sin auditar**. Trátalos como lectura, no como herramientas a correr sin revisarlas antes.

## Estructura

```
.claude/skills/
├── README.md                        (este archivo)
├── LICENSE-claude-skills.txt        (licencia MIT original)
├── finance-skills/
│   └── SKILL.md                     (índice/enrutador)
└── financial-analyst/
    ├── SKILL.md
    ├── assets/                      (plantillas de reportes y datos de ejemplo)
    ├── references/                  (guías de ratios, pronósticos, valoración)
    └── scripts/                     (calculadoras en Python — solo consulta)
```
