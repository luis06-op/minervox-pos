# Configuración OpenCode curada

Origen: <https://github.com/anomalyco/opencode/tree/dev/.opencode>
Licencia: MIT (Copyright (c) 2025 opencode)
Exportado: 2026-10-21

## Qué incluye

| Archivo | Para qué sirve |
|---|---|
| `command/commit.md` | Crea commit y push con convención `type(scope): summary`. |
| `command/learn.md` | Extrae aprendizajes no obvios de la sesión y los guarda en `AGENTS.md`. |
| `command/rmslop.md` | Quita código generado por IA (comentarios de más, `try/catch` innecesarios, `any`, emojis). |
| `command/spellcheck.md` | Revisa ortografía y gramática en los `.md` modificados. |
| `command/translate.md` | Traduce documentación y textos de UI a otros idiomas. |
| `glossary/` | Guía de traducción por idioma. `es.md` cubre español. |
| `skills/sample-skill/` | Plantilla mínima de un `SKILL.md` (solo en la configuración global). |
| `skills/rtl-aware-development/` | Buenas prácticas RTL/LTR para interfaces web (solo en la configuración global). |

## Qué se descartó y por qué

- `agent/triage.md` y `tool/github-*.ts`: dependen de los equipos y del repositorio `anomalyco/opencode`.
- `command/issues.md`, `changelog.md`, `ai-deps.md`: específicos de ese monorepo (`gh`, releases, AI SDK en `package.json`).
- `skills/effect`, `unit-tests`, `tui-tests`, `app-e2e-tests`, `component-tests`, `opencode-dev`: atadas a Bun, Playwright, Effect y a la arquitectura interna de opencode.
- `themes/` y `plugins/smoke-theme.json`: variantes del tema Nord, sin relación con la marca de estos proyectos.
- `opencode.jsonc`, `tui.json`, `env.d.ts`: configuración y tipos del monorepo original.

## Adaptaciones aplicadas

- Se eliminaron los `model:` fijos de `commit.md` y `translate.md` para usar el modelo configurado en cada entorno.
- `rmslop.md` compara contra `origin/HEAD` en lugar de `origin/dev`.
- `glossary/README.md` ya no referencia `agent/translator.md`, que no existe en estos proyectos.
