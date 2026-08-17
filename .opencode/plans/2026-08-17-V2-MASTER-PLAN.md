# T.N.S.V.T — Plan Maestro V2 Definitiva

> **Fecha:** 2026-08-17
> **Estado:** Plan completo — Fase 0 en ejecución
> **Objetivo:** Llevar TNSVT a una V2 profesional, completa, con identidad visual coherente y todas las funcionalidades de V1 preservadas.

---

## 🔍 Diagnóstico Inicial

### Backend ✅ Casi completo
- 60+ Entities Doctrine (User, Trade, Journal, Calendar, Chat, Clan, Tournament, Wallet, etc.)
- 65+ Controllers API (Auth, Journal, Macro, Oracle, Chat, Feed, Tournament, Wallet, etc.)
- Toda la lógica de V1 migrada a Symfony 8.1

### Frontend ⚠️ Incompleto
- 40 templates Twig, ~50% básicos sin diseño Stitch
- `webgl-background.js` ya hace estrellas procedurales (WebGL) — **pero solo se incluye en `home.html.twig`**, no en `shell.html.twig`
- `gateway-3d.js` hace icosaedro dorado — idem, solo en gateway
- Sidebar/topbar mejorados pero no replicados en todas las vistas
- Design system (tokens.css, components.css) existe pero no se aplica uniformemente

### Lo que el usuario pidió
1. **Fondo estrellado** ← el código existe, hay que moverlo al shell
2. **Reorganización** completa de V2
3. **Calendario Académico** (nuevo)
4. **Sistema de clases 1:1** (nuevo)
5. No perder nada de V1
6. V2 profesional, completa

### V1 (referencia visual)
Imágenes enviadas muestran:
- Fondo cósmico con estrellas doradas cayendo
- Header "EL CRISTO ÍNTEGRO" tipo lettering dorado
- Dashboard con Equity Curve + Calendario + P&L Mensual
- Asentar Operación (form complejo)
- Chat con FAB, panel lateral
- Calendario mensual con P&L por día
- Macro: Próximo Crítico + Ventana No-Operar + Pares Afectados
- Diario Personal (locked/unlocked/empty/editor)
- Social (Solicitudes/Conexiones/Privacidad)
- Admin Música (playlist management)

---

## 📐 Plan por Fases

### Fase 0 — Fundamentos Visibles (1 semana)
**Objetivo:** TODAS las páginas del Sanctum tienen fondo estrellado + lettering dorado.

**0.1 — Global Shell Background**
- Mover `webgl-background.js` y `gateway-3d.js` de `home.html.twig` a `shell.html.twig`
- Agregar bloque `{% block gateway_emblem %}` para que solo el gateway tenga el icosaedro
- Ajustar opacidad del fondo para legibilidad (25-40% en shell, 100% en gateway)

**0.2 — Cinzel / Letras doradas**
- Reutilizar `Cinzel` font ya cargada
- Crear utility classes `.text-cinzel-gold`, `.text-cinzel-violet`
- Aplicar a headers principales de cada módulo

**0.3 — Variables de fondo reutilizables**
- CSS custom properties: `--bg-stars-opacity`, `--bg-stars-color`, `--bg-stars-density`
- Cada módulo puede override

**Entregable:** Todas las páginas del Sanctum tienen fondo estrellado + lettering dorado.

---

### Fase 1 — Dashboard Maestro (2 semanas)
**1.1 — Dashboard Layout v2**
- Top: hero "EL CRISTO ÍNTEGRO" + subtitle "Edición Completa — Trading Neuro-Spiritual Value Theory (2026)"
- Tab navigation: `DASHBOARD` / `REGISTRAR` / `TRADE LOG` / `ESTADÍSTICAS`
- Fila 1: Equity Curve (full-width, todo/30d/7d tabs, +38.47%)
- Fila 2: Calendario de Operaciones (month grid P&L) + P&L Mensual (bar chart)
- Fila 3: Profit Factor (2.34) + Expectativa ($26.16) + Win Rate
- Fila 4: Recent Signals + Task Sovereignty + Educational Mastery
- Fila 5: Guardian widget

**1.2 — Equity Curve Widget**
- API `/api/journal/equity-curve`
- SVG path animado con gradient fill
- Hover: tooltip con fecha + P&L

**1.3 — Calendario Mensual**
- API `/api/journal/calendar-monthly`
- CSS grid 7x6, días con border verde (W) o rojo (L)
- Click → modal con detalle del día

**1.4 — P&L Mensual Bar Chart**
- 6 barras (ENE FEB MAR ABR MAY JUN) verdes/rojas
- Línea 0 punteada

**1.5 — Tarjetas de Stats**
- Profit Factor, Expectativa, Win Rate, Avg Win, Avg Loss, R Multiple

---

### Fase 2 — Registrador de Operaciones (1 semana)
**2.1 — Página `/sanctum/journal/new`**
- Sticky header con título "Asentar Operación Cerrada"
- Sección Fecha/Hora (datepicker + time picker)
- Sección Activo (chips: XAUUSD, NAS100, EURUSD, BTCUSDT, GBPUSD, USDJPY, etc.)
- Sección Dirección (BUY/SELL verde-rojo, large)
- Sección Resultado (WIN/LOSS/BE)
- Sección Precios (Entry/TP/SL con TP arriba/SL abajo)
- Sección Risk:Reward (Risk, R:R TP1, R:R TP2)
- Sección P&L Final + R Múltiple
- Sección Capturas (drag-drop, max 3)
- Sección Tips del Creador (chips con prompts)
- Footer: Cancelar + Registrar Trade

**2.2 — API `/api/journal/entries` POST**
- Validar campos
- Guardar imagen si la hay
- Calcular P&L, R, Expectativa
- Devolver entry creada

---

### Fase 3 — Calendario Económico (3 días)
**3.1 — Bento Grid Mejorado**
- Top: Filtros (SEM/MES/LIST) + count "12 eventos · 3 críticos esta semana"
- Próximo Crítico card: nombre, hora, país, ACTUAL/PREDICCIÓN/ANTERIOR
- Ventana No-Operar card: time window, volatilidad, pares afectados
- Pares Más Afectados (XAUUSD, EURUSD, NAS100, BTCUSDT) con % de confianza
- "Recordarme 15' antes" action

**3.2 — Recordings Format**
- Layout V1: lista vertical con bordes neón
- Filter "Hoy / Esta Semana / Próximos"

---

### Fase 4 — Diario Personal (1 semana)
**4.1 — Estados del Diario**
- **Bloqueado** (rotated icon + password input + "Desbloquear" + "Huella" button)
- **Lista de entradas** (grid 2x2 con cards: fecha, día semana, título, preview, "Cifrada · 247 palabras")
- **Empty state** (scroll icon + "El Cuaderno está vacío" + botón "Escribir la primera")
- **Editor** (split view: editor izq + preview der, prompts chips abajo)

**4.2 — Estados del Editor**
- Editor mode (izq) / Lectura mode (der)
- Auto-save cada 30s
- "Cifrado de extremo a extremo" badge
- Prompts rotativos: ¿Qué emoción dominó? / ¿Qué hice bien? / ¿Qué patrón volvió a aparecer?

**4.3 — Crypto Layer**
- Ya existe `JournalSetting.php` entity
- Contraseña hasheada, validación server-side
- Sesión cifrada en reposo

---

### Fase 5 — Salón del Cónclave (Feed) (1 semana)
**5.1 — Forum Layout**
- Hero "El Salón del Cónclave" + "La palabra escrita queda. La palabra dicha se la lleva el viento."
- Tab navigation: `TODOS` / `GENERAL` / `SEÑALES` / `RESULTADOS` / `PROYECCIÓN` / `PREGUNTAS`
- Composer (sticky bottom, text + chips de tipo: General / Señal / Resultado / Proyección / Pregunta)
- Feed de posts (avatar + nombre + tiempo + badges tipo)
- Reactions (corazones, comentarios con count)
- Panel lateral: Mensajes (en vivo, with search)

**5.2 — Estado vacío + Cards**
- Empty: "No hay posts todavía. Sé el primero en escribir."
- Cada post: header con avatar, body, footer con reactions

**5.3 — Chat Sub-panel**
- Tabs: `TODOS` / `NO LEÍDOS` / `GRUPOS`
- Search bar
- Lista de conversaciones con avatar, nombre, último mensaje, timestamp
- FAB grande en esquina inferior derecha

**5.4 — Chat Full Page**
- 2 columnas: lista izquierda + conversación derecha
- Empty: "No hay conversaciones aún"
- Burbujas con timestamps, estados (sent/delivered/read)

---

### Fase 6 — Social (Conexiones) (3 días)
**6.1 — Layout**
- Hero "El Cónclave de Ejecutores"
- Tabs: `SOLICITUDES` / `CONEXIONES` / `PRIVACIDAD`
- Buscar por nombre
- Lista de usuarios con: avatar, nombre, badge de rol, botón "Conectar" / "Conectado" / "Pendiente"

**6.2 — Acciones**
- Enviar solicitud
- Aceptar/rechazar
- Bloquear

---

### Fase 7 — Macro Calendario (refinar, 2 días)
**7.1 — Bento grid**
- 3 columns: Próximo Crítico / Ventana No-Operar / Pares Afectados
- Reglas de Disciplina (15' antes / EVENTO / 15' después)

**7.2 — Charts**
- Faith vs Logic gauge
- Emotional Bias scatter
- Session Performance bars

---

### Fase 8 — Calendario Académico (NUEVO) (2 semanas)
**8.1 — Entidades nuevas**
```php
class CalendarEvent {
    id, userId, title, description, type (class/group/1on1/mentoring/event/task),
    startsAt, endsAt, mentorId, location, meetingUrl, status, color, recurring;
}
class MentorAvailability {
    id, mentorId, dayOfWeek, startTime, endTime, status;
}
class ClassBooking {
    id, studentId, mentorId, eventId, requestedAt, status (pending/accepted/declined/proposed),
    proposedTimes, notes;
}
```

**8.2 — Vista `/calendar` mejorada**
- 3 vistas: mes / semana / lista
- Filtros: Tipo de evento, Mentor, Estado
- Sidebar: hoy, próximos, mis reservas

**8.3 — Botón "Solicitar Clase 1:1"**
- Modal: selecciona mentor, fecha, duración, tema, notas
- Backend: crea ClassBooking + notifica mentor
- Mentor ve en `/sanctum/admin/bookings`: aceptar / rechazar / proponer otro horario

**8.4 — Notificaciones**
- Cuando mentor acepta, el alumno recibe in-app notification
- Cuando se acerca la clase, reminder 15' antes

---

### Fase 9 — Honor / Leaderboard / Tournaments (1 semana)
**9.1 — Honor Board**
- Top 10 traders (avatar, tier, P&L, win rate)
- Tu posición destacada con marco dorado
- Tab semanal/mensual/total

**9.2 — Tournaments**
- Cards de torneos activos (inscripción abierta, en progreso, finalizados)
- Modal de inscripción
- Bracket view para torneos en progreso

**9.3 — Duels**
- Lista de duelos activos
- Crear duelo (invitar usuario, definir parámetros)
- Resultado

---

### Fase 10 — Admin / Monitoring / Audit (2 días)
**10.1 — Monitoring**
- Server, DB, PHP, Opcache, Security & Business metrics
- Mejorar visual: gauges, timeline, alerts

**10.2 — Users Admin**
- Lista de usuarios
- Tier management
- Ban/unban
- Bulk actions

**10.3 — Audit**
- Log de acciones
- Filtros por usuario, acción, fecha
- Export CSV

---

### Fase 11 — Shop / Wallet / Frecuencias (1 semana)
**11.1 — Wallet**
- Balance (ARS, USD, USDC)
- Transactions list
- Buy credits (MercadoPago, BinancePay)
- Convert

**11.2 — Shop**
- Catálogo de items (TNSVT Market)
- Filtros
- Carrito + checkout

**11.3 — Frecuencias**
- Listado de presets
- Session player
- 432Hz library

---

### Fase 12 — Música (Admin) (3 días)
**12.1 — `/sanctum/music`**
- 4 tabs: Alumnos / Tareas Operativas / Música / Monitoring
- Reproductor actual (track, file, Activar, borrar)
- Subir archivo (drag-drop, .mp3/.ogg/.m4a, max 200MB)
- O pegar URL externa
- Save track

---

### Fase 13 — Campus / Academia (1 semana)
**13.1 — Campus**
- Catálogo de cursos
- Inscripción
- Lecciones, materiales, assignments
- Progreso

**13.2 — Academia**
- Versión diferente del campus (conceptos fundamentales de trading)
- Lecciones adicionales

---

### Fase 14 — Profile / Settings / Notifications (3 días)
**14.1 — Profile**
- Avatar, nombre, tier, disciplina
- Streak (días consecutivos)
- Logros
- Historial de actividad

**14.2 — Settings**
- Personal (idioma, timezone, notificaciones)
- Privacidad (quién ve tu perfil)
- Seguridad (2FA, password reset)

**14.3 — Notifications**
- Centro de notificaciones
- Mark as read
- Filtros

---

### Fase 15 — Polishing (1 semana)
- Loading states (skeleton shimmer)
- Empty states
- Error states (404, 500, network)
- Toast notifications
- Optimistic UI
- Accessibility (keyboard nav, ARIA, screen reader)
- Performance (lazy load, code splitting, image optimization)
- PWA (offline, install)
- SEO meta tags

---

## 📊 Resumen de Esfuerzo

| Fase | Scope | Esfuerzo | Estado |
|------|-------|----------|--------|
| 0 | Global shell + estrellas | 1 semana | **EN EJECUCIÓN** |
| 1 | Dashboard maestro | 2 semanas | Plan |
| 2 | Registrador de Operaciones | 1 semana | Plan |
| 3 | Calendario Económico | 3 días | Plan |
| 4 | Diario Personal | 1 semana | Plan |
| 5 | Salón del Cónclave + Chat | 1 semana | Plan |
| 6 | Social | 3 días | Plan |
| 7 | Macro refinar | 2 días | Plan |
| 8 | **Calendario Académico** | 2 semanas | Plan |
| 9 | Honor/LB/Torneos | 1 semana | Plan |
| 10 | Admin/Monitoring | 2 días | Plan |
| 11 | Shop/Wallet/Frec | 1 semana | Plan |
| 12 | Música admin | 3 días | Plan |
| 13 | Campus/Academia | 1 semana | Plan |
| 14 | Profile/Settings | 3 días | Plan |
| 15 | Polishing | 1 semana | Plan |
| **Total** | **V2 definitiva** | **~14 semanas** | |

---

## 🛠️ Stack Técnico

- **Backend:** Symfony 8.1, PHP 8.4, Doctrine ORM, Lexik JWT
- **Frontend:** Twig, TailwindCSS, Vanilla JS (ES modules)
- **Asset Mapper:** Symfony 8.1 native (no Webpack)
- **Deployment:** Hostinger (Hostinger Panel)
- **SSH:** `tnsvt-deploy` key, port 65002
- **Server:** LiteSpeed + PHP-FPM

---

## 📁 Convenciones

- **Production path:** `domains/tnsvt.com/public_html/`
- **Deploy steps:** Upload via SCP → `asset-map:compile --env=prod` → `cache:clear --env=prod`
- **Config files:** `config/packages/`, `config/routes/`
- **Templates:** `templates/`
- **Entities:** `src/Entity/`
- **Controllers:** `src/Controller/`
- **API:** `src/Controller/Api/`
- **CSS:** `assets/styles/`
- **JS:** `assets/js/modules/`

---

## 🔐 No perder nada de V1

**Cross-reference (V1 → V2):**

| V1 Module | V2 Status | Notes |
|-----------|-----------|-------|
| Gateway (home) | ✅ Migrado | Mejorado con shader |
| Dashboard | ✅ Migrado | Stub básico, pendiente Fase 1 |
| Tasks | ✅ Migrado | Functional |
| Users | ✅ Migrado | Functional |
| Audit | ✅ Migrado | Functional |
| Settings | ✅ Migrado | Functional |
| Monitoring | ✅ Migrado | Functional |
| Oracle | ✅ Migrado | Functional |
| Macro | ✅ Migrado | Mejorado |
| Frequencies | ✅ Migrado | Functional |
| Journal | ✅ Migrado | Stub básico, pendiente Fase 2 |
| Chat | ✅ Migrado | Stub básico, pendiente Fase 5 |
| Feed | ✅ Migrado | Stub básico, pendiente Fase 5 |
| Calendar | ✅ Migrado | Stub básico, pendiente Fase 8 |
| Diario | ✅ Migrado | Stub básico, pendiente Fase 4 |
| Trading | ⏳ Legacy redirect | `/trading` |
| Campus | ✅ Migrado | Pendiente Fase 13 |
| Academia | ✅ Migrado | Pendiente Fase 13 |
| Social | ✅ Migrado | Stub básico, pendiente Fase 6 |
| Tournaments | ✅ Migrado | Stub básico, pendiente Fase 9 |
| Duels | ✅ Migrado | Stub básico, pendiente Fase 9 |
| Honor | ✅ Migrado | Stub básico, pendiente Fase 9 |
| Leaderboard | ✅ Migrado | Stub básico, pendiente Fase 9 |
| Wallet | ✅ Migrado | Stub básico, pendiente Fase 11 |
| Shop | ✅ Migrado | Stub básico, pendiente Fase 11 |
| Game | ✅ Migrado | Stub básico, pendiente Fase 9 |
| Clan | ✅ Migrado | Stub básico, pendiente Fase 7 |
| Frequencies | ✅ Migrado | Pendiente Fase 11 |

---

## 🎯 Tareas de Fase 0 (✅ COMPLETADAS 2026-08-17)

- [x] F0.1 — Mover `webgl-background.js` a `shell.html.twig` ✅
- [x] F0.2 — Definir bloque `gateway_emblem` en `shell.html.twig` ✅
- [x] F0.3 — Crear utility classes `.text-cinzel-*` (gold, violet) ✅
- [x] F0.4 — Aplicar `text-cinzel-display` al topbar_title ✅
- [x] F0.5 — Mover `gateway-3d.js` al bloque `gateway_emblem` (solo home) ✅
- [x] F0.6 — Custom properties `--bg-stars-*` en tokens.css ✅
- [x] F0.7 — Test local + verificar 404s ✅
- [x] F0.8 — Deploy + verificar fondo estrellado en producción ✅

**Resultado Fase 0:**
- 18/18 assets retornando HTTP 200
- Home (gateway): opacity 1.0, density 45 (full stars)
- Login/macro/etc: opacity 0.6, density 30 (subtle stars)
- `text-cinzel-display` aplicado al topbar del Sanctum
- `gateway-emblem` body class distingue gateway del resto
- Fondo estrellado ahora en TODAS las páginas

---

## 📝 Notas de Sesión

- **2026-08-17:** Plan maestro creado. Fase 0 aprobada para ejecución inmediata.
- **Asset 404 fix:** LiteSpeed cache no detecta archivos nuevos. Patch: manifest.json + importmap.json apuntan a symlinks no-hashed. `stimulus_bootstrap.js` reemplazado con shim (sin import de `@symfony/stimulus-bundle`).
- **Vehicular deploy:** SCP archivos → `asset-map:compile --env=prod` → `cache:clear --env=prod`. Manual, no script.
- **Plan vivo:** Se actualiza con cada fase completada.

---

## 🚀 Próximos Pasos

1. **Fase 0** (- 1 semana): fondo estrellado + lettering dorado
2. **Fase 1** (siguiente): Dashboard maestro con tabs
3. **Fase 8** (paralelo prioritario): Calendario Académico + clases 1:1
4. **Fases 2-15** en orden según feedback del usuario
