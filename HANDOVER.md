# Nextcloud Plugins Project — Handover Document

> Volledige briefing voor een Claude sessie zonder context.
> Lees het hele document voordat je iets doet. De gebruiker werkt aan dit
> project over meerdere sessies heen; jij hebt geen geheugen, dit document
> is je geheugen.

---

## 1. De missie

De gebruiker draait Nextcloud op `nextcloud.guu.st`, gehost op een eigen
Intel Mac Mini (`mini.local`). Hij wil **twee aparte Nextcloud apps** ontwikkelen
en die later **delen via GitHub** als open source projecten.

### Plugin 1 — `codeedit`
Een code editor met syntax highlighting. Initiële talen: **markdown, swift,
bash, php, python**. Architectuur moet zo zijn dat extra talen toevoegen
triviaal is (één regel per taal in de mapping).

### Plugin 2 — `mdexport`
Markdown → PDF / Word export, waarbij de gebruiker een eigen `.css` bestand
kan kiezen als stijl. Vijf default themes worden meegeleverd; user kan eigen
CSS uploaden via de app settings.

App namen zijn voorlopig — gebruiker mag later hernoemen.

---

## 2. Setup en constraints

### 2.1 Infrastructuur
- **Productie en dev**: dezelfde machine, een **Intel Mac Mini** bereikbaar
  via `ssh bas@mini.local`
- **Domein**: `nextcloud.guu.st` wijst naar mini.local
- **Nextcloud**: draait al in Docker op mini.local (Hub 26 Winter, versie 33.x)
- **SSH toegang**: ja, volledig — externe binaries installeren mag
- **Werkstation gebruiker**: lokaal via Claude CLI op de server, of via Claude
  chat vanaf MacBook Pro / M4 Mac Mini, SSH't naar mini.local
- **Backup**: er is één backup van de Nextcloud setup; voldoende voor rollback
  als iets stuk gaat. Geen extra rollback mechanisme nodig.

### 2.2 Distributie
- Beide apps gaan naar **GitHub** als publieke repos met **AGPL-3.0** license
- Pure PHP heeft voorkeur voor Plugin 2 zodat anderen de app zonder server
  admin werk kunnen installeren; wkhtmltopdf is opt-in via auto-detect

### 2.3 Gebruiker preferenties
- **Communicatie**: Nederlands, beknopt
- **App UI taal**: Engels (publicatie op GitHub); Nederlandse vertaling
  optioneel via `l10n/nl.js`
- **Geen emojis** tenzij gevraagd
- **Code commentaar**: altijd

---

## 3. Tech stack — vastgestelde keuzes

### 3.1 Stack overzicht

| Onderdeel | Keuze | Reden |
|---|---|---|
| Server doel | Nextcloud 33 (Hub 26 Winter) | Wat de gebruiker draait |
| PHP versie | 8.3 | Vereist door NC 33 |
| Frontend framework | Vue 3 + TypeScript | Standaard voor NC 33+ |
| Build tool | Vite via `@nextcloud/vite-config` | Officiële NC tooling |
| UI components | `@nextcloud/vue@^9` | v9 = Vue 3, target NC 31+ (geverifieerd) |
| Files API client | `@nextcloud/files@^4` | Nieuwe v4 API in NC 33 (Node API i.p.v. legacy FileInfo) |
| Editor library | CodeMirror 6 | Modulair, lazy-loadable language packs |
| Markdown parsing (P2) | `league/commonmark` | CommonMark + GFM, mature |
| HTML → PDF (P2) | `mpdf/mpdf` ^8.2 | Pure PHP, full CSS support |
| HTML → DOCX (P2) | `phpoffice/phpword` | Pure PHP, HTML reader met CSS mapping |
| Code highlighting in export | `scrivo/highlight.php` | Server-side highlight in code blocks |

### 3.2 Project setup — from scratch (geen forum-boilerplate)

We gebruiken alleen officiële Nextcloud packages. Skeleton wordt zelf opgebouwd.
Per app:

```bash
# Initiele structuur
mkdir codeedit && cd codeedit
git init
mkdir -p appinfo lib/AppInfo lib/Controller src/languages css l10n tests

# Frontend dependencies
npm init -y
npm install --save-dev \
  vite \
  @nextcloud/vite-config \
  @nextcloud/eslint-config \
  typescript \
  @types/node \
  vitest

npm install --save \
  vue@^3 \
  @nextcloud/vue@^9 \
  @nextcloud/files@^4 \
  @nextcloud/axios \
  @nextcloud/router \
  @nextcloud/l10n

# CodeMirror voor codeedit specifiek
npm install --save \
  @codemirror/state \
  @codemirror/view \
  @codemirror/lang-markdown \
  @codemirror/lang-php \
  @codemirror/lang-python \
  @codemirror/legacy-modes

# PHP dependencies (voor mdexport)
composer init
composer require league/commonmark mpdf/mpdf phpoffice/phpword scrivo/highlight.php
composer require --dev phpunit/phpunit
```

### 3.3 CodeMirror 6 language packs (Plugin 1 v0.1.0)

| Taal | Import |
|---|---|
| Markdown | `import { markdown } from '@codemirror/lang-markdown'` |
| Swift | `import { swift } from '@codemirror/legacy-modes/mode/swift'` |
| Bash / Shell | `import { shell } from '@codemirror/legacy-modes/mode/shell'` |
| PHP | `import { php } from '@codemirror/lang-php'` |
| Python | `import { python } from '@codemirror/lang-python'` |

### 3.4 Trade-offs die al zijn afgewogen

- **Pure PHP default voor Plugin 2**: zonder server admin werk te installeren
- **mPDF beperkingen**: complexe CSS minder krachtig dan wkhtmltopdf, maar
  prima voor 95% van markdown documenten
- **PhpWord DOCX is BEPERKT**: headings, fonts en kleuren werken; complexe
  layout (multi-column, custom margins, page breaks) werkt slecht. PDF wordt
  veel mooier dan DOCX. **Documenteer dit duidelijk in de README**.
- **Geen collaborative editing in v1**: te complex (OT/CRDT), kan later
- **Geen mime type registratie in v1**: file action matching gebeurt op
  extensie, dat is voldoende. Custom mime types alleen als blijkt dat NC
  een extensie niet herkent en het problemen geeft (zie 4.2)

---

## 4. Architectuur — Plugin 1 (`codeedit`)

### 4.1 Repository layout
```
codeedit/                              # GitHub: <user>/nextcloud-codeedit
├── README.md                          # Install instructies, screenshots
├── LICENSE                            # AGPL-3.0
├── CHANGELOG.md
├── .gitignore                         # node_modules, vendor, build/
├── appinfo/
│   ├── info.xml                       # Manifest (zie 4.5 voor template)
│   └── routes.php                     # API endpoints
├── lib/
│   ├── AppInfo/Application.php        # Bootstrap + listener registratie
│   ├── Controller/FileController.php  # GET/PUT file content via OCP\Files
│   └── Listener/LoadEditorListener.php
├── src/
│   ├── filesplugin.ts                 # Registreert file action (per extensie)
│   ├── Editor.vue                     # Hoofdcomponent met CodeMirror
│   ├── languages/
│   │   ├── index.ts                   # Extensie → dynamic import mapping
│   │   ├── markdown.ts
│   │   ├── swift.ts
│   │   ├── bash.ts
│   │   ├── php.ts
│   │   └── python.ts
│   └── main.ts                        # Vite entry point
├── l10n/                              # i18n vertalingen
├── tests/
│   ├── php/                           # PHPUnit tests
│   └── js/                            # Vitest tests
├── css/editor.scss
├── package.json
├── composer.json
├── vite.config.ts
└── Makefile                           # make build / make appstore
```

### 4.2 File action — match op extensie, niet mime

NC's nieuwe `@nextcloud/files` v4 file action registratie kan filteren op
file basename / extensie. Voorbeeld:

```typescript
// src/filesplugin.ts — registreert file action voor onze extensies
// Geen mime type registratie nodig, want we matchen op extensie
import { registerFileAction, FileAction } from '@nextcloud/files'

const SUPPORTED_EXTENSIONS = ['md', 'swift', 'sh', 'bash', 'php', 'py']

registerFileAction(new FileAction({
    id: 'codeedit-open',
    displayName: () => t('codeedit', 'Open in code editor'),
    iconSvgInline: () => editorIcon,
    // Enabled wanneer file extensie in onze lijst staat
    enabled: (nodes) => {
        if (nodes.length !== 1) return false
        const ext = nodes[0].basename.split('.').pop()?.toLowerCase()
        return SUPPORTED_EXTENSIONS.includes(ext ?? '')
    },
    async exec(node) {
        // Open editor modal met file
        openEditorFor(node)
        return null  // null = action handled, geen navigatie
    },
}))
```

### 4.3 Conflict met de Text app

Voor `.md` files staat de **Text app** standaard ingeschakeld in NC. Dat
betekent dat dubbelklikken op een `.md` bestand de Text app opent, niet
codeedit. Onze action staat wel in het rechter-klik menu.

**Opties voor de gebruiker (documenteer in README):**
- Text app helemaal uitschakelen: `occ app:disable text`
- Text app houden, codeedit gebruiken via rechtermuis → "Open in code editor"
- (Toekomstig v0.2.0) Onze action als default registreren via `setDefault`,
  user kan kiezen via een setting

Voor `.swift`, `.sh`, `.bash`, `.py`, `.php` is er geen conflict — codeedit
is daar de enige editor.

### 4.4 Belangrijke details
- **Save**: debounced autosave (2s) + Cmd+S. ETag check via
  `If-Match` header tegen overschrijven bij gelijktijdige edits.
- **Theme**: volgt NC light/dark mode automatisch via CSS variables van NC
- **UX**: opent in modal of full-page route — beslissing in fase 1 op basis
  van wat de Files Viewer API in NC 33 ondersteunt
- **Taal toevoegen later**: één regel in `src/languages/index.ts`

```typescript
// Lazy-loaded language packs voor minimale initial bundle
const languageLoaders = {
  md:    () => import('./markdown'),
  swift: () => import('./swift'),
  sh:    () => import('./bash'),
  bash:  () => import('./bash'),
  php:   () => import('./php'),
  py:    () => import('./python'),
  // Nieuwe taal hier toevoegen, bijv:
  // js: () => import('./javascript'),
}
```

### 4.5 Volledig info.xml template (minimaal werkend)

```xml
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
    <id>codeedit</id>
    <name>Code Editor</name>
    <summary>Code editor with syntax highlighting for markdown, swift, bash, php, and python files</summary>
    <description><![CDATA[
A modern code editor for Nextcloud, powered by CodeMirror 6.

Initial language support:
- Markdown
- Swift
- Bash / Shell
- PHP
- Python

Open files via the Files app context menu.
    ]]></description>
    <version>0.1.0</version>
    <licence>agpl</licence>
    <author mail="user@example.com">Bas</author>
    <namespace>CodeEdit</namespace>
    <category>files</category>
    <bugs>https://github.com/USER/nextcloud-codeedit/issues</bugs>
    <repository>https://github.com/USER/nextcloud-codeedit</repository>
    <dependencies>
        <php min-version="8.2" max-version="8.5"/>
        <nextcloud min-version="33" max-version="33"/>
    </dependencies>
</info>
```

### 4.6 Test strategie
- **PHPUnit** voor `FileController` (mock `OCP\Files\IRootFolder`)
- **Vitest** voor `Editor.vue` en de language loader resolution
- **Coverage doel v0.1.0**: 70% PHP, smoke tests JS

---

## 5. Architectuur — Plugin 2 (`mdexport`)

### 5.1 Repository layout
```
mdexport/                              # GitHub: <user>/nextcloud-mdexport
├── README.md                          # Install + DOCX limitaties documenteren
├── LICENSE                            # AGPL-3.0
├── CHANGELOG.md
├── .gitignore
├── appinfo/
│   ├── info.xml
│   └── routes.php
├── lib/
│   ├── AppInfo/Application.php
│   ├── Controller/
│   │   ├── ExportController.php       # POST /api/v1/export
│   │   ├── ThemeController.php        # GET /api/v1/themes  (lijst defaults + custom)
│   │   └── SettingsController.php     # Custom CSS upload/delete
│   ├── Service/
│   │   ├── HtmlRenderer.php           # MD → HTML (commonmark)
│   │   ├── PdfConverter.php           # HTML → PDF (mPDF default, wkhtmltopdf optioneel)
│   │   ├── DocxConverter.php          # HTML → DOCX (PhpWord)
│   │   ├── ThemeRepository.php        # Default + custom themes ophalen
│   │   └── PathValidator.php          # Validatie tegen path traversal
│   └── Settings/PersonalSection.php
├── src/
│   ├── filesplugin.ts                 # Voegt "Export as..." menu items toe
│   ├── ExportDialog.vue               # Modal: format + theme picker + output folder
│   └── PersonalSettings.vue           # Settings: custom CSS upload, theme preview
├── themes/                            # Read-only, meegeleverd in build
│   ├── minimal.css
│   ├── academic.css
│   ├── modern.css
│   ├── print.css
│   └── dark.css
├── l10n/
├── tests/
├── composer.json
├── package.json
└── Makefile
```

### 5.2 CSS themes — workflow (vereenvoudigd)

**Default themes (read-only):**
- 5 default themes leven in `apps/mdexport/themes/` — onderdeel van de app
- User kan deze NIET wijzigen; bij app update worden ze ververst
- Files staan niet in user's Files folder (geen ruis)

**Custom themes (per user):**
- User upload eigen `.css` via app Settings page (`PersonalSettings.vue`)
- Files worden opgeslagen in app data: `data/<user>/files_external/mdexport_themes/`
  via `OCP\Files\IAppData` — niet zichtbaar in Files UI
- User kan ze in de Settings page bekijken, hernoemen, verwijderen
- Optionele simpele inline editor (kleine CodeMirror) in v0.2.0; voor v0.1.0
  alleen upload/delete

**Bij export:**
- Dropdown toont: alle defaults + alle custom CSS van deze user
- Default selectie: `default.css` als die bestaat, anders eerste in lijst

### 5.3 Export flow
```
Files app
  → rechtsklik op .md
  → menu: "Export as PDF" / "Export as DOCX"
  → ExportDialog: format + theme + output folder
  → POST /apps/mdexport/api/v1/export { fileId, format, themeId, outputPath }
  → Backend:
      1. PathValidator: fileId hoort bij user, outputPath is binnen user folder
      2. Read MD via OCP\Files\IRootFolder::getById($fileId)
      3. CommonMark → HTML
      4. Read theme CSS (default uit app folder, custom uit IAppData)
      5. mPDF (default) of wkhtmltopdf (auto-detect) → PDF
      6. of: PhpWord → DOCX
      7. Write resultaat naar outputPath
  → Notification: "document.pdf opgeslagen in /Exports"
```

### 5.4 Edge cases en security
- **Path traversal**: nooit raw paths uit user input. Altijd via
  `IRootFolder::getById($fileId)` met scope check naar user folder.
  `PathValidator` service hiervoor.
- **CSS source restrictie**: alleen themes uit eigen `ThemeRepository`,
  geen externe URLs.
- **mPDF CVE history**: pin op `^8.2` of recenter, `composer audit` in CI.
- **Images in markdown**: relative paths resolven via NC's file API of
  base64 embedden; externe URLs (https://) optioneel via setting
  (default OFF — privacy: server doet dan geen externe requests).
- **Grote output (>10MB)**: background job via `IJobList` i.p.v. synchrone HTTP.
- **wkhtmltopdf detectie**: `which wkhtmltopdf` via `Symfony\Component\Process`,
  cache resultaat 1 uur.
- **DOCX expectation management**: in ExportDialog UI subtiele hint tonen
  als user DOCX kiest ("PDF biedt betere stijl-ondersteuning").

### 5.5 Test strategie
- **PHPUnit** voor `HtmlRenderer`, `PdfConverter`, `DocxConverter`,
  `PathValidator`. Fixture markdown files in `tests/fixtures/`.
- **Vitest** voor `ExportDialog.vue` en `PersonalSettings.vue`
- **Snapshot tests** van rendered HTML uit fixtures — voorkomt regressies

---

## 6. Werken op mini.local

### 6.1 Verbinding maken
```bash
ssh bas@mini.local
```

### 6.2 Bestaande Docker setup inspecteren
```bash
docker ps                                          # Welke containers draaien
docker ps --format '{{.Names}}' | grep -i next     # Vind NC container naam
docker inspect CONTAINER_NAME | grep -A 20 Mounts  # Volume mappings
sudo find / -name 'docker-compose*.yml' 2>/dev/null
```

### 6.3 OCC (Nextcloud admin CLI)
```bash
# Vervang CONTAINER met werkelijke naam
alias occ="docker exec --user www-data -it CONTAINER php /var/www/html/occ"

occ status
occ app:list
occ app:enable codeedit
occ app:disable codeedit
occ maintenance:mode --on
```

### 6.4 Apps directory voor development

Check huidige config:
```bash
occ config:system:get apps_paths
```

Als er nog geen `apps-extra/` bind mount is, voeg toe aan docker-compose.yml:
```yaml
volumes:
  - ~/projects/nextcloud-apps/apps-extra:/var/www/html/apps-extra
```

Restart container, dan registreren in Nextcloud:
```bash
occ config:system:set apps_paths 1 path --value=/var/www/html/apps-extra
occ config:system:set apps_paths 1 url --value=/apps-extra
occ config:system:set apps_paths 1 writable --value=true --type=boolean
```

### 6.5 Hot reload workflow

**Voor JavaScript/Vue changes:**
```bash
cd ~/projects/nextcloud-apps/apps-extra/codeedit
npm run dev    # Vite watch mode, herbouwt dist/ bij elke save
# In browser: Cmd+Shift+R voor hard refresh
```

**Voor PHP changes:**
- Meestal voldoende: hard refresh in browser
- Bij class loading issues:
  ```bash
  occ app:disable codeedit && occ app:enable codeedit
  ```
- Bij grote refactors:
  ```bash
  occ maintenance:repair
  ```

**Na wijzigen van `info.xml`:**
```bash
occ app:disable codeedit && occ app:enable codeedit
```

**Bij file scan issues (zelden nodig):**
```bash
occ files:scan --all
```

### 6.6 Build dependencies op mini.local
```bash
node --version || brew install node@20
composer --version || brew install composer
brew install --cask wkhtmltopdf   # Optioneel, voor betere PDF
```

---

## 7. GitHub workflow

### 7.1 Twee aparte repos
- `github.com/<user>/nextcloud-codeedit`
- `github.com/<user>/nextcloud-mdexport`

### 7.2 Branch strategie
- `main`: stable, alleen merges van releases
- `develop`: actieve development
- `feature/*`: per feature branch

### 7.3 Release flow (handmatig, geen CI nodig)
```bash
# Op mini.local in de repo
git checkout main
git merge develop
make appstore                          # Bouwt tarball met alle dependencies
git tag v0.1.0
git push origin main --tags
# Tarball handmatig uploaden naar GitHub release
```

`make appstore` doel:
- `composer install --no-dev --optimize-autoloader`
- `npm run build`
- Tarball maken die alle benodigde files bevat (geen node_modules, wel dist/)

### 7.4 Installatie door derden
1. Download tarball van GitHub release
2. Upload via Nextcloud admin → Apps → "Upload App"

---

## 8. Huidige status

**Op het moment van schrijven (bijgewerkt 2026-04-28):**

### Fase 0 — KLAAR ✓
- Node 20.20.2 via nvm (`~/.nvm`)
- Composer via Docker image (`docker run --rm composer:2`)
- `gh` CLI op `~/.local/bin/gh`, ingelogd als `bglnelissen`
- `apps-extra/` bind mount actief in Docker én geregistreerd bij NC (`occ config:system:set apps_paths 2 ...`)
- GitHub repos aangemaakt: `github.com/bglnelissen/nextcloud-codeedit` en `github.com/bglnelissen/nextcloud-mdexport`

### Fase 1 — IN UITVOERING (stappen 6–11 gedaan, stap 12+ open)

**Wat gebouwd is:**
- Volledige mappenstructuur `~/projects/nextcloud-apps/apps-extra/codeedit/`
- `appinfo/info.xml`, `appinfo/routes.php`
- `lib/AppInfo/Application.php` — luistert naar `OCA\Files\Event\LoadAdditionalScriptsEvent`
- `lib/Listener/LoadEditorListener.php` — laadt script met `Util::addScript(..., 'files')`
- `lib/Controller/FileController.php` — GET/PUT file content via OCP\Files, ETag check
- `src/languages/` — markdown, php, python, bash, swift (lazy-loaded)
- `src/filesplugin.ts` — registreert `FileAction` via `@nextcloud/files`
- `src/Editor.vue` — CodeMirror 6 in NcDialog, autosave, Cmd+S, dark/light mode
- `src/main.ts` — Vite entry point
- `vite.config.ts` — `createAppConfig({ main: 'src/main.ts' })`
- Build werkt: `js/codeedit-main.mjs` (1.4MB), taal-chunks gesplitst

**Kritiek openstaand probleem — fix klaar maar nog niet gebouwd:**

NC 33 gebruikt `@nextcloud/files` **v4** voor het file-action register.
v3 (wat we eerder gebruikten) schrijft naar `window._nc_fileactions` — NC 33 leest daar niet meer.
v4 gebruikt `scopedGlobals.fileActions` via het gedeelde chunk `folder-29HuacU_.mjs`
dat NC 33 al serveert in `/var/www/html/dist/`. Daarmee is het register reactief en gedeeld.

**Fix:**
- `package.json` staat al op `@nextcloud/files: "^4.0.0"` (v4.0.0 geïnstalleerd)
- **Volgende actie**: controleer of v4 API breaking changes heeft vs v3 voor `FileAction`, `enabled()`, `exec()`, rebuild, test

**Debug-logs nog in code (verwijderen na fix):**
- `filesplugin.ts` — `console.log('[codeedit] filesplugin loaded')`, `'[codeedit] registering file action'`, `'[codeedit] enabled() called ...'`

**Bekende gotchas gevonden tijdens deze sessie:**
- `@nextcloud/vite-config` vereist `"type": "module"` in package.json (ESM-only)
- `@nextcloud/vite-config` vereist vite v7 (niet v5)
- Entry point in `createAppConfig` moet `main` heten, niet `codeedit-main` (anders dubbel prefix in output)
- `Util::addScript(APP_ID, 'codeedit-main', 'files')` — derde parameter `'files'` is vereist (laadvolgorde)
- Listener moet `LoadAdditionalScriptsEvent` gebruiken, niet `BeforeTemplateRenderedEvent`
- Syntaxhighlighting vereist `syntaxHighlighting(defaultHighlightStyle, { fallback: true })` expliciet

**Volgende concrete stappen (in volgorde):**

### Fase 0 — Voorbereiding (~30 min) — KLAAR ✓
1. ~~SSH naar mini.local~~ — we zitten al op de server
2. ~~Inspecteer huidige Docker NC setup~~ — gedaan
3. ~~Configureer apps-extra/ bind mount~~ — gedaan
4. ~~Verifieer Node 20+ en Composer~~ — gedaan
5. ~~Maak GitHub repos aan (twee, AGPL-3.0)~~ — gedaan

### Fase 1 — Plugin 1 v0.1.0 (~3-4 sessies)
6. Scratch setup: `npm init` + dependencies (sectie 3.2)
7. info.xml schrijven (template uit 4.5)
8. Application.php + FileController.php basis
9. filesplugin.ts: file action voor onze extensies (sectie 4.2)
10. Editor.vue met CodeMirror voor markdown
11. Voeg swift, bash, php, python language packs toe
12. Save logic + ETag conflict handling
13. NC light/dark theme integratie
14. PHPUnit + Vitest skeleton tests
15. Test, debug, eerste release tag v0.1.0

### Fase 2 — Plugin 2 v0.1.0 (~3-4 sessies)
16. Scratch setup met composer dependencies
17. info.xml + Application.php
18. ExportController + HtmlRenderer + PathValidator
19. PdfConverter via mPDF (default)
20. DocxConverter via PhpWord
21. ThemeRepository (defaults uit app, custom uit IAppData)
22. ExportDialog.vue + PersonalSettings.vue
23. wkhtmltopdf auto-detect en optionele backend
24. Tests + DOCX limitaties documenteren in README
25. Test, debug, eerste release tag v0.1.0

### Fase 3 — Polish en publicatie
26. Screenshots, README's, demo video
27. Optioneel: submitten naar Nextcloud App Store

---

## 9. Briefing voor toekomstige Claude

Als je dit leest in een nieuwe sessie zonder context:

1. **Begin met sectie 8** — vraag de gebruiker waar hij staat in de
   stappenlijst voordat je iets doet.
2. **Heroverweeg de tech keuzes uit sectie 3 NIET** tenzij de gebruiker
   expliciet vraagt om iets te veranderen.
3. **Lees sectie 12 (risico's)** voor je begint met bouwen.
4. **De gebruiker heeft SSH** naar `bas@mini.local` (Intel Mac Mini) waar
   Nextcloud Docker draait. Productie = development = mini.local.
5. **Beide apps gaan naar GitHub** met AGPL-3.0.
6. **Pure PHP voor Plugin 2 is default**, wkhtmltopdf is opt-in.
7. **Nederlands voor communicatie**, beknopt, geen emojis. Code commentaar
   altijd. App UI is Engels.
8. **Geen mime type registratie** in v0.1.0 — file actions matchen op
   extensie. Alleen toevoegen als blijkt dat het echt nodig is.
9. **Plugin 1 talen**: markdown, swift, bash, php, python.
10. **Plugin 2 themes**: 5 defaults read-only in app folder, custom upload
    via app settings (niet via Files UI).
11. **Conflict met Text app** voor `.md`: in README documenteren hoe user
    kan kiezen.

---

## 10. Handige commando's cheat sheet

```bash
# === Docker (op mini.local) ===
docker ps
docker compose up -d
docker compose down
docker compose logs -f nextcloud
docker compose restart nextcloud

# === OCC ===
alias occ="docker exec --user www-data -it CONTAINER php /var/www/html/occ"
occ app:list
occ app:enable codeedit
occ app:disable codeedit
occ app:disable text                  # Optie: Text app uit voor .md default
occ maintenance:mode --on
occ maintenance:repair
occ files:scan --all

# === App development ===
cd ~/projects/nextcloud-apps/apps-extra/codeedit
npm install
npm run dev                            # Vite watch mode
npm run build                          # Production build
npm run test                           # Vitest
make appstore                          # Tarball voor release

# === Composer ===
composer install
composer install --no-dev --optimize-autoloader
composer test                          # PHPUnit
composer audit                         # Security check

# === Git ===
git checkout -b feature/swift-support
git push -u origin feature/swift-support
git tag v0.1.0 && git push --tags
```

---

## 11. Referenties

- Nextcloud Developer Manual: https://docs.nextcloud.com/server/latest/developer_manual/
- Upgrade to NC 33: https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/app_upgrade_guide/upgrade_to_33.html
- info.xml schema: https://apps.nextcloud.com/schema/apps/info.xsd
- info.xml docs: https://docs.nextcloud.com/server/latest/developer_manual/app_development/info.html
- @nextcloud/vue (v9, Vue 3): https://github.com/nextcloud-libraries/nextcloud-vue
- @nextcloud/vue components: https://nextcloud-vue-components.netlify.app/
- @nextcloud/vite-config: https://github.com/nextcloud-libraries/nextcloud-vite-config
- @nextcloud/files (v4): https://github.com/nextcloud/nextcloud-files
- CodeMirror 6 docs: https://codemirror.net/docs/
- league/commonmark: https://commonmark.thephpleague.com/
- mPDF: https://mpdf.github.io/
- PhpWord: https://phpoffice.github.io/PHPWord/
- highlight.php: https://github.com/scrivo/highlight.php

---

## 12. Bekende risico's en aandachtspunten

### 12.1 NC 33 Files API breaking changes
NC 33 heeft Files API v4.0.0:
- `OCA.Files.Sidebar` is verwijderd, vervangen door API in `@nextcloud/files`
- File action handlers gebruiken destructured parameters i.p.v. positional
  array arguments
- Files context object is uitgebreid met current folder en file list

**Mitigatie**: we gebruiken `@nextcloud/files@^4` direct vanaf het begin,
niet de oude `OCA.Files.*` globals. info.xml zet `max-version="33"` zodat
we niet automatisch breken op NC 34.

### 12.2 PhpWord DOCX kwaliteit
DOCX output via PhpWord is significant minder mooi dan PDF via mPDF.
Headings, fonts en kleuren werken; complexe layout niet.

**Mitigatie**: README sectie "DOCX limitations" met voorbeelden van wat
wel/niet werkt. ExportDialog UI hint tonen bij DOCX keuze.

### 12.3 Security — path traversal en CSS source
Het export endpoint accepteert user input voor file IDs en theme IDs.

**Mitigatie**:
- Path traversal: `PathValidator` service, alleen via `IRootFolder::getById()`
- CSS source: alleen via `ThemeRepository` (defaults + user's IAppData),
  geen externe URLs of arbitrary file IDs
- mPDF CVEs: pin `^8.2`, `composer audit` in CI

### 12.4 Composer dependencies omvang
mPDF + PhpWord + commonmark + highlight.php = ~30-40MB vendor folder.
Release tarball wordt navenant groot.

**Mitigatie**: `composer install --no-dev --optimize-autoloader
--classmap-authoritative` in build.

### 12.5 Text app conflict voor `.md`
Default opent de Text app op `.md` files. Codeedit zit dan in het
context menu maar is niet de default click action.

**Mitigatie**: in README documenteren. v0.2.0 kan optioneel `setDefault`
gebruiken met user setting.

### 12.6 Test coverage realistisch
Volledige coverage is niet haalbaar voor v0.1.0.
- v0.1.0: 70% PHP, smoke tests JS
- v0.2.0: 85% PHP, full component testing
- E2E (Playwright): pas vanaf v0.3.0

---

*Document versie: 3.0 — major rewrite: scratch setup met officiële
@nextcloud/* packages (forum boilerplate eruit), volledige info.xml template,
mime registratie geschrapt (file action matcht op extensie), themes folder
eruit (defaults read-only in app, custom via Settings), hot reload workflow
expliciet, Text app conflict gedocumenteerd.*
