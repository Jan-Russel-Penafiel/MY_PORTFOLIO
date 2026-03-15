# Copilot Instructions – Canva Resume Template UI for `index.html`

## Project Overview

Redesign the desktop view of `index.html` (single-file, no new files) to look and feel exactly like a
**Canva modern resume template** — a two-column professional layout with a colored sidebar and clean main
content area — while keeping every real content element from the existing portfolio intact.

**Owner:** Jan Russel E. Peñafiel
**Page title (keep unchanged):** `Jan Russel E. Peñafiel - Freelance Student Web Developer`
**Avatar / profile photo:** `./RUSSEL.png`
**Cover / hero accent:** CSS gradient `linear-gradient(135deg, #0f4c75, #1b6ca8)` (deep navy-to-blue)
**Favicon:** `RUSSEL.png` (keep unchanged)

Current stack (keep all):
- Bootstrap 5.3.0 · Font Awesome 6.4.0
- Google Fonts: Orbitron + Poppins
- Highlight.js 11.9.0
- Dark / Light mode via `body.light-mode` class + `localStorage`

---

## Target Layout (Desktop ≥ 1024px)

```
┌──────────────────────────────────────────────────────────────────────────┐
│  TOP NAV  │ [logo wordmark "Jan Russel"]  │ section links │ [🌙] [avatar] │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌─────────────────── RESUME WRAPPER (max-width 1100px) ──────────────┐  │
│  │                                                                    │  │
│  │  ┌── LEFT SIDEBAR (300px) ─────┐  ┌── MAIN CONTENT (flex: 1) ───┐ │  │
│  │  │                             │  │                              │ │  │
│  │  │  [Profile Photo 130px]      │  │  ── HEADER BANNER ────────  │ │  │
│  │  │  Jan Russel E. Peñafiel     │  │  Name · Title · Tagline     │ │  │
│  │  │  Freelance Web Developer    │  │                              │ │  │
│  │  │  ─────────────────────────  │  │  ── ABOUT ME ─────────────  │ │  │
│  │  │  CONTACT                    │  │  ── EXPERIENCE ───────────  │ │  │
│  │  │  EDUCATION                  │  │  ── PROJECTS (8 cards) ───  │ │  │
│  │  │  SKILLS                     │  │  ── ROADMAP ──────────────  │ │  │
│  │  │  LINKS                      │  │  ── TECH STACK ───────────  │ │  │
│  │  │                             │  │  ── GALLERY ──────────────  │ │  │
│  │  └─────────────────────────────┘  └──────────────────────────────┘ │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Color Tokens

```css
:root {
  /* Canva "Navy & Teal" palette — dark mode (default) */
  --cv-bg:           #111827;          /* page background */
  --cv-sidebar:      #0f2744;          /* sidebar dark navy */
  --cv-sidebar-text: #e2e8f0;          /* sidebar text */
  --cv-sidebar-muted:#94a3b8;          /* sidebar muted */
  --cv-accent:       #38bdf8;          /* sky-blue accent */
  --cv-accent-2:     #0ea5e9;          /* darker accent */
  --cv-main-bg:      #1e293b;          /* main panel bg */
  --cv-surface:      #1e293b;
  --cv-surface-2:    #263348;
  --cv-border:       #334155;
  --cv-text-primary: #f1f5f9;
  --cv-text-muted:   #94a3b8;
  --cv-shadow:       0 4px 24px rgba(0,0,0,0.4);
  --cv-tag-bg:       #0f3460;
  --cv-tag-text:     #38bdf8;
  --cv-divider:      #1e3a5f;
}

body.light-mode {
  --cv-bg:           #f1f5f9;
  --cv-sidebar:      #0f4c75;
  --cv-sidebar-text: #ffffff;
  --cv-sidebar-muted:#bfdbfe;
  --cv-accent:       #0ea5e9;
  --cv-accent-2:     #0284c7;
  --cv-main-bg:      #ffffff;
  --cv-surface:      #ffffff;
  --cv-surface-2:    #f8fafc;
  --cv-border:       #e2e8f0;
  --cv-text-primary: #0f172a;
  --cv-text-muted:   #64748b;
  --cv-shadow:       0 4px 24px rgba(0,0,0,0.08);
  --cv-tag-bg:       #e0f2fe;
  --cv-tag-text:     #0369a1;
  --cv-divider:      #bae6fd;
}
```

---

## 1. Top Navbar (`#cv-navbar`)

`position: sticky; top: 0; z-index: 1000; height: 52px; background: var(--cv-sidebar); border-bottom: 2px solid var(--cv-accent)`

**Left zone**
- Icon: `<i class="fas fa-file-alt" style="color: var(--cv-accent)"></i>`
- Wordmark: **"Jan Russel"** – `font-family: Orbitron; color: var(--cv-sidebar-text); font-size: 15px; font-weight: 700`

**Center zone — section links**
```html
<div id="cv-nav-links">
  <a href="#about"      class="cv-nav-link">About</a>
  <a href="#experience" class="cv-nav-link">Experience</a>
  <a href="#projects"   class="cv-nav-link">Projects</a>
  <a href="#roadmap"    class="cv-nav-link">Roadmap</a>
  <a href="#tech"       class="cv-nav-link">Tech</a>
  <a href="#gallery"    class="cv-nav-link">Gallery</a>
</div>
```
Each link: `font-size: 14px; font-weight: 600; color: var(--cv-sidebar-muted); padding: 0 12px; height: 52px; display: flex; align-items: center; border-bottom: 2px solid transparent`
Active / hover: `color: var(--cv-accent); border-bottom: 2px solid var(--cv-accent)`

**Right zone**
- Avatar circle `36px`: `<img src="./RUSSEL.png">`
- Dark/light toggle button (`id="themeToggleBtn"`) — keep existing

---

## 2. Resume Wrapper (`#cv-wrapper`)

```css
#cv-wrapper {
  max-width: 1100px;
  margin: 32px auto;
  display: grid;
  grid-template-columns: 300px 1fr;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: var(--cv-shadow);
}
```

---

## 3. Left Sidebar (`#cv-sidebar`)

`background: var(--cv-sidebar); color: var(--cv-sidebar-text); padding: 40px 28px; display: flex; flex-direction: column; gap: 32px`

### 3a. Profile Block

```html
<div id="cv-profile-block" class="text-center">
  <div class="cv-photo-wrap">
    <img src="./RUSSEL.png" id="cv-profile-photo" alt="Jan Russel">
    <!-- keep orbital ring animation if present -->
  </div>
  <h1 class="cv-name">Jan Russel E. Peñafiel</h1>
  <p class="cv-title">Freelance Web Developer</p>
  <span class="cv-badge">Open for Commissions</span>
</div>
```

`#cv-profile-photo`: `width: 130px; height: 130px; border-radius: 50%; border: 4px solid var(--cv-accent); object-fit: cover; display: block; margin: 0 auto 16px`
`.cv-name`: `font-size: 20px; font-weight: 800; font-family: Poppins; line-height: 1.2; margin-bottom: 4px`
`.cv-title`: `font-size: 13px; color: var(--cv-sidebar-muted); margin-bottom: 10px; font-weight: 500`
`.cv-badge`: `background: var(--cv-accent); color: #fff; border-radius: 50px; padding: 3px 12px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase`

---

### Sidebar Section Heading

```css
.cv-sidebar-heading {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--cv-accent);
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--cv-divider);
}
```

---

### 3b. Contact Section

```html
<div class="cv-sidebar-section">
  <h2 class="cv-sidebar-heading">Contact</h2>
  <div class="cv-contact-row"><i class="fas fa-map-marker-alt"></i> Isulan, Sultan Kudarat</div>
  <div class="cv-contact-row"><i class="fas fa-university"></i> SKSU – Isulan Campus</div>
  <div class="cv-contact-row"><i class="fab fa-github"></i>
    <a href="https://github.com/Jan-Russel-Penafiel" target="_blank">GitHub</a>
  </div>
  <div class="cv-contact-row"><i class="fab fa-linkedin"></i>
    <a href="https://www.linkedin.com/in/jan-russel-peñafiel-a1799036b" target="_blank">LinkedIn</a>
  </div>
  <div class="cv-contact-row"><i class="fab fa-facebook"></i>
    <a href="https://www.facebook.com/russelicioush" target="_blank">Facebook</a>
  </div>
</div>
```

`.cv-contact-row`: `display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--cv-sidebar-text); padding: 5px 0`
Icon: `width: 16px; color: var(--cv-accent); flex-shrink: 0`
Links: `color: var(--cv-sidebar-text); text-decoration: none`
Links hover: `color: var(--cv-accent); text-decoration: underline`

---

### 3c. Education Section

```html
<div class="cv-sidebar-section">
  <h2 class="cv-sidebar-heading">Education</h2>
  <div class="cv-edu-block">
    <p class="cv-edu-degree">BSIT – Bachelor of Science in Information Technology</p>
    <p class="cv-edu-school">Sultan Kudarat State University</p>
    <p class="cv-edu-year">2023 – Present</p>
  </div>
</div>
```

`.cv-edu-degree`: `font-size: 13px; font-weight: 700; margin-bottom: 2px`
`.cv-edu-school`: `font-size: 12px; color: var(--cv-sidebar-muted)`
`.cv-edu-year`: `font-size: 11px; color: var(--cv-accent); margin-top: 2px`

---

### 3d. Skills Section

```html
<div class="cv-sidebar-section">
  <h2 class="cv-sidebar-heading">Core Skills</h2>
  <div class="cv-skills-list">
    <span class="cv-skill-tag">PHP</span>
    <span class="cv-skill-tag">CodeIgniter 4</span>
    <span class="cv-skill-tag">JavaScript</span>
    <span class="cv-skill-tag">Tailwind CSS</span>
    <span class="cv-skill-tag">Bootstrap 5</span>
    <span class="cv-skill-tag">MySQL</span>
    <span class="cv-skill-tag">Git / GitHub</span>
    <span class="cv-skill-tag">Full-Stack Dev</span>
    <span class="cv-skill-tag">REST API</span>
  </div>
</div>
```

`.cv-skills-list`: `display: flex; flex-wrap: wrap; gap: 6px`
`.cv-skill-tag`: `background: rgba(56,189,248,0.15); color: var(--cv-accent); border: 1px solid var(--cv-accent); border-radius: 50px; padding: 3px 10px; font-size: 11px; font-weight: 600`

---

### 3e. Quick Links Section

```html
<div class="cv-sidebar-section">
  <h2 class="cv-sidebar-heading">Quick Links</h2>
  <a href="https://calendly.com/janrusselpenafiel01172005" class="cv-quick-link" target="_blank">
    <i class="fas fa-calendar-check"></i> Book Appointment
  </a>
  <a href="https://www.facebook.com/share/1FMnnSXYZV/" class="cv-quick-link" target="_blank">
    <i class="fas fa-users"></i> Team AsyncX
  </a>
  <a href="./resume.pdf" class="cv-quick-link" download>
    <i class="fas fa-file-pdf"></i> Download Resume
  </a>
</div>
```

`.cv-quick-link`: `display: flex; align-items: center; gap: 8px; padding: 8px 0; font-size: 13px; color: var(--cv-sidebar-text); text-decoration: none; border-bottom: 1px solid var(--cv-divider)`
Last child: `border-bottom: none`
Hover: `color: var(--cv-accent)`
Icon: `width: 16px; color: var(--cv-accent)`

---

## 4. Main Content (`#cv-main`)

`background: var(--cv-main-bg); padding: 48px 40px; display: flex; flex-direction: column; gap: 40px`

---

### Main Section Heading

```css
.cv-section-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--cv-accent);
  margin-bottom: 20px;
}

.cv-section-title::after {
  content: '';
  flex: 1;
  height: 2px;
  background: linear-gradient(to right, var(--cv-accent), transparent);
  border-radius: 2px;
}
```

---

### 4a. Header Banner (`#cv-header-banner`)

```html
<div id="cv-header-banner">
  <h1 id="cv-main-name">Jan Russel E. Peñafiel</h1>
  <p id="cv-main-title">Freelance Web Developer · BSIT Student</p>
  <p id="cv-main-tagline">
    Building clean, functional, user-focused web applications.<br>
    Open for Commissions — Full-Stack PHP · CodeIgniter 4 · MySQL
  </p>
  <div id="cv-main-actions">
    <a href="https://github.com/Jan-Russel-Penafiel" class="cv-action-btn cv-btn-primary" target="_blank">
      <i class="fab fa-github"></i> GitHub
    </a>
    <a href="https://www.linkedin.com/in/jan-russel-peñafiel-a1799036b" class="cv-action-btn cv-btn-outline" target="_blank">
      <i class="fab fa-linkedin"></i> LinkedIn
    </a>
    <a href="./resume.pdf" class="cv-action-btn cv-btn-outline" download>
      <i class="fas fa-file-pdf"></i> Resume
    </a>
    <a href="https://calendly.com/janrusselpenafiel01172005" class="cv-action-btn cv-btn-outline" target="_blank">
      <i class="fas fa-calendar-check"></i> Book a Call
    </a>
  </div>
</div>
```

`#cv-main-name`: `font-size: 36px; font-weight: 800; color: var(--cv-text-primary); font-family: Poppins; margin-bottom: 4px`
`#cv-main-title`: `font-size: 16px; color: var(--cv-accent); font-weight: 600; margin-bottom: 10px`
`#cv-main-tagline`: `font-size: 14px; color: var(--cv-text-muted); line-height: 1.7; margin-bottom: 20px`

```css
.cv-action-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 18px; border-radius: 6px;
  font-size: 13px; font-weight: 700; text-decoration: none;
  transition: all 0.2s;
}

.cv-btn-primary {
  background: var(--cv-accent); color: #fff;
}
.cv-btn-primary:hover { background: var(--cv-accent-2); }

.cv-btn-outline {
  border: 1.5px solid var(--cv-accent); color: var(--cv-accent);
  background: transparent;
}
.cv-btn-outline:hover { background: var(--cv-accent); color: #fff; }

#cv-main-actions { display: flex; flex-wrap: wrap; gap: 10px; }
```

---

### 4b. About Me (`#about`)

```html
<section id="about" class="cv-section">
  <h2 class="cv-section-title"><i class="fas fa-user-circle"></i> About Me</h2>
  <div class="cv-about-body">
    <p>Hey, I'm <strong>Jan Russel</strong> — a BSIT student at Sultan Kudarat State University – Isulan Campus
    and a Freelance Web Developer specializing in full-stack development.</p>
    <ul class="cv-bullet-list">
      <li>Specializing in web technologies &amp; full-stack development</li>
      <li>Builds clean, functional, user-focused web applications</li>
      <li>Covers government systems, POS, healthcare &amp; custom solutions</li>
      <li>Turns ideas into production-ready systems fast &amp; efficiently</li>
      <li>Uses AI-assisted development to accelerate without cutting standards</li>
      <li>Solves problems methodically with maintainable code under pressure</li>
      <li>Collaborative, delivers on time, and adapts quickly to new tools</li>
    </ul>
  </div>
</section>
```

`.cv-about-body p`: `font-size: 14px; line-height: 1.8; color: var(--cv-text-muted); margin-bottom: 12px`
`.cv-bullet-list`: `list-style: none; padding: 0; display: flex; flex-direction: column; gap: 6px`
`.cv-bullet-list li`: `font-size: 14px; color: var(--cv-text-muted); padding-left: 20px; position: relative`
`.cv-bullet-list li::before`: `content: "▸"; color: var(--cv-accent); position: absolute; left: 0`

---

### 4c. Experience (`#experience`)

```html
<section id="experience" class="cv-section">
  <h2 class="cv-section-title"><i class="fas fa-briefcase"></i> Experience</h2>

  <div class="cv-exp-card">
    <div class="cv-exp-header">
      <div>
        <h3 class="cv-exp-role">Freelance Web Developer</h3>
        <p class="cv-exp-place">Self-Employed · Remote</p>
      </div>
      <div class="cv-exp-meta">
        <span class="cv-badge-outline">Currently Active</span>
        <span class="cv-exp-date">2024 – Present · 2 yrs+</span>
      </div>
    </div>
    <ul class="cv-bullet-list">
      <li>Building custom web applications for clients across healthcare, government, education, and SMBs</li>
      <li>Full-stack development using PHP, CodeIgniter 4, Tailwind CSS, MySQL &amp; Bootstrap 5</li>
      <li>Completed multiple capstone commissions and real-world deployed systems</li>
      <li>Handling end-to-end project lifecycle: requirements, design, development, testing, and deployment</li>
    </ul>
  </div>
</section>
```

`.cv-exp-card`: `border-left: 3px solid var(--cv-accent); padding: 0 0 0 20px; margin-left: 6px`
`.cv-exp-header`: `display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; flex-wrap: wrap; gap: 8px`
`.cv-exp-role`: `font-size: 16px; font-weight: 700; color: var(--cv-text-primary); margin: 0`
`.cv-exp-place`: `font-size: 13px; color: var(--cv-text-muted); margin: 2px 0 0`
`.cv-exp-meta`: `display: flex; flex-direction: column; align-items: flex-end; gap: 4px`
`.cv-exp-date`: `font-size: 12px; color: var(--cv-accent)`
`.cv-badge-outline`: `border: 1px solid var(--cv-accent); color: var(--cv-accent); border-radius: 50px; padding: 2px 10px; font-size: 11px; font-weight: 700`

---

### 4d. Projects (`#projects`) — 8 Cards Grid

```html
<section id="projects" class="cv-section">
  <h2 class="cv-section-title"><i class="fas fa-code"></i> Featured Projects</h2>
  <div class="cv-projects-grid">
    <!-- 8 project cards (see content below) -->
  </div>
</section>
```

`.cv-projects-grid`: `display: grid; grid-template-columns: 1fr 1fr; gap: 16px`

**Each project card:**
```html
<div class="cv-project-card">
  <div class="cv-project-header">
    <h3 class="cv-project-title">PROJECT NAME</h3>
    <div class="cv-project-badges">
      <span class="cv-badge-sm cv-badge-blue">Badge 1</span>
      <span class="cv-badge-sm cv-badge-green">Badge 2</span>
    </div>
  </div>
  <ul class="cv-bullet-list cv-project-bullets">
    <li>…</li>
  </ul>
</div>
```

```css
.cv-project-card {
  background: var(--cv-surface-2);
  border: 1px solid var(--cv-border);
  border-top: 3px solid var(--cv-accent);
  border-radius: 8px;
  padding: 18px;
  transition: box-shadow 0.2s, transform 0.2s;
}
.cv-project-card:hover {
  box-shadow: 0 8px 24px rgba(56,189,248,0.15);
  transform: translateY(-2px);
}
.cv-project-header {
  display: flex; justify-content: space-between;
  align-items: flex-start; gap: 8px; margin-bottom: 10px; flex-wrap: wrap;
}
.cv-project-title { font-size: 14px; font-weight: 700; color: var(--cv-text-primary); margin: 0; }
.cv-project-badges { display: flex; flex-wrap: wrap; gap: 4px; }
.cv-badge-sm { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 50px; }
.cv-badge-blue { background: rgba(56,189,248,0.15); color: var(--cv-accent); }
.cv-badge-green { background: rgba(34,197,94,0.15); color: #22c55e; }
.cv-badge-yellow { background: rgba(250,204,21,0.15); color: #eab308; }
.cv-project-bullets { margin: 0; }
.cv-project-bullets li { font-size: 12px; }
```

**Project content (preserve exactly):**

| # | Title | Badges |
|---|-------|--------|
| 1 | SKSU Scholarship Management System | `My Capstone` · `Ongoing` |
| 2 | Russel's Chicken Delights HR System | `Personal Project` · `Completed` |
| 3 | KES-SMART | `Capstone` · `Completed` |
| 4 | BHC CONNECT | `Capstone` · `Completed` |
| 5 | HealthConnect | `Capstone` · `Completed` |
| 6 | ImmuCare | `Capstone` · `Completed` |
| 7 | AidTrack | `Capstone` · `Completed` |
| 8 | BM-SCaPIS | `Capstone` · `Completed` |

Bullet content for each project is identical to the original portfolio content — do not change.

---

### 4e. Roadmap (`#roadmap`)

```html
<section id="roadmap" class="cv-section">
  <h2 class="cv-section-title"><i class="fas fa-map"></i> Dev Roadmap</h2>
  <div class="cv-timeline">

    <div class="cv-timeline-item">
      <div class="cv-timeline-dot"></div>
      <div class="cv-timeline-content">
        <span class="cv-timeline-period">2023–2025</span>
        <h3 class="cv-timeline-title">The Foundation &amp; First Real Systems</h3>
        <p class="cv-timeline-desc">HTML5, CSS3, JavaScript, PHP, MySQL · Real-world Healthcare and POS Systems · CRUD, Authentication, Responsive Design</p>
      </div>
    </div>

    <div class="cv-timeline-item">
      <div class="cv-timeline-dot"></div>
      <div class="cv-timeline-content">
        <span class="cv-timeline-period">2025–2026</span>
        <h3 class="cv-timeline-title">Frameworks &amp; Full-Stack Mastery</h3>
        <p class="cv-timeline-desc">Bootstrap 5, CodeIgniter 4, MVC Pattern · Government Systems with REST APIs &amp; AJAX · 5 commissioned capstone projects completed</p>
      </div>
    </div>

    <div class="cv-timeline-item cv-timeline-active">
      <div class="cv-timeline-dot"></div>
      <div class="cv-timeline-content">
        <span class="cv-timeline-period">2026 (Now)</span>
        <h3 class="cv-timeline-title">AI-Assisted Development &amp; Beyond</h3>
        <p class="cv-timeline-desc">Gemini API · UI/UX Design polish · Clean Backend Architecture · Open for Freelance Commissions</p>
      </div>
    </div>

  </div>
</section>
```

```css
.cv-timeline { position: relative; padding-left: 28px; }
.cv-timeline::before {
  content: ''; position: absolute; left: 8px; top: 0; bottom: 0;
  width: 2px; background: var(--cv-border);
}
.cv-timeline-item {
  position: relative; margin-bottom: 28px;
}
.cv-timeline-dot {
  position: absolute; left: -24px; top: 4px;
  width: 12px; height: 12px; border-radius: 50%;
  background: var(--cv-border); border: 2px solid var(--cv-surface);
}
.cv-timeline-active .cv-timeline-dot {
  background: var(--cv-accent); box-shadow: 0 0 0 4px rgba(56,189,248,0.2);
}
.cv-timeline-period {
  font-size: 11px; font-weight: 700; color: var(--cv-accent);
  text-transform: uppercase; letter-spacing: 1px;
}
.cv-timeline-title { font-size: 14px; font-weight: 700; color: var(--cv-text-primary); margin: 4px 0; }
.cv-timeline-desc { font-size: 13px; color: var(--cv-text-muted); line-height: 1.6; }
```

---

### 4f. Tech Stack (`#tech`)

```html
<section id="tech" class="cv-section">
  <h2 class="cv-section-title"><i class="fas fa-layer-group"></i> Tech Stack</h2>

  <div class="cv-tech-group">
    <h3 class="cv-tech-category">Languages &amp; Frameworks</h3>
    <div class="cv-tech-tags">
      <span class="cv-skill-tag">PHP</span>
      <span class="cv-skill-tag">CodeIgniter 4</span>
      <span class="cv-skill-tag">JavaScript</span>
      <span class="cv-skill-tag">Tailwind CSS</span>
      <span class="cv-skill-tag">Bootstrap 5</span>
    </div>
  </div>

  <div class="cv-tech-group">
    <h3 class="cv-tech-category">Database &amp; Tools</h3>
    <div class="cv-tech-tags">
      <span class="cv-skill-tag">MySQL</span>
      <span class="cv-skill-tag">Apache</span>
      <span class="cv-skill-tag">Git</span>
      <span class="cv-skill-tag">GitHub</span>
    </div>
  </div>

  <div class="cv-tech-group">
    <h3 class="cv-tech-category">IDEs &amp; AI Tools</h3>
    <div class="cv-tech-tags">
      <span class="cv-skill-tag">VSCode</span>
      <span class="cv-skill-tag">ChatGPT</span>
      <span class="cv-skill-tag">GitHub Copilot</span>
    </div>
  </div>
</section>
```

`.cv-tech-group`: `margin-bottom: 18px`
`.cv-tech-category`: `font-size: 12px; font-weight: 700; color: var(--cv-text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px`
`.cv-tech-tags`: `display: flex; flex-wrap: wrap; gap: 8px`

---

### 4g. Gallery (`#gallery`)

```html
<section id="gallery" class="cv-section">
  <h2 class="cv-section-title"><i class="fas fa-images"></i> Photo Gallery</h2>
  <p class="cv-gallery-subtitle">A collection of my favorite moments and memories.</p>
  <!-- keep existing Bootstrap carousel (#photoGalleryCarousel) here with all its JS -->
</section>
```

`.cv-gallery-subtitle`: `font-size: 13px; color: var(--cv-text-muted); margin-bottom: 16px`

The carousel container: `border-radius: 10px; overflow: hidden; border: 1px solid var(--cv-border)`

---

## 5. Footer

```html
<footer id="cv-footer">
  <p>Date Created: 2024-03-19 · Made with ♥ by Jan Russel</p>
</footer>
```

`#cv-footer`: `text-align: center; padding: 20px; font-size: 12px; color: var(--cv-text-muted); border-top: 1px solid var(--cv-border); background: var(--cv-main-bg)`
Must remain at the bottom of `#cv-main`.

---

## 6. Typography

| Usage | Font | Size | Weight |
|---|---|---|---|
| Body | Poppins | 14px | 400 |
| Nav links | Poppins | 14px | 600 |
| Main name | Poppins | 36px | 800 |
| Section titles | Poppins | 13px | 800 |
| Card title | Poppins | 14px | 700 |
| Muted text | Poppins | 13–14px | 400 |
| Badges / tags | Poppins | 10–11px | 700 |
| Navbar wordmark | Orbitron | 15px | 700 |

---

## 7. Desktop Layout CSS

```css
@media (min-width: 1024px) {

  /* hide old navbar on desktop */
  nav.navbar.fixed-top { display: none !important; }

  body {
    background: var(--cv-bg);
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    color: var(--cv-text-primary);
    min-height: 100vh;
  }

  #cv-wrapper {
    max-width: 1100px;
    margin: 32px auto;
    display: grid;
    grid-template-columns: 300px 1fr;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--cv-shadow);
  }

  #cv-sidebar {
    background: var(--cv-sidebar);
    color: var(--cv-sidebar-text);
    padding: 40px 28px;
    display: flex;
    flex-direction: column;
    gap: 32px;
    min-height: 100%;
  }

  #cv-main {
    background: var(--cv-main-bg);
    padding: 48px 40px;
    display: flex;
    flex-direction: column;
    gap: 40px;
    overflow: hidden;
  }

  .cv-section { scroll-margin-top: 80px; }
}

/* Mobile */
@media (max-width: 1023px) {
  #cv-wrapper {
    display: flex;
    flex-direction: column;
    margin: 0;
    border-radius: 0;
  }
  #cv-sidebar { padding: 28px 20px; }
  #cv-main { padding: 28px 16px; }
  .cv-projects-grid { grid-template-columns: 1fr; }
  #cv-nav-links { display: none; }
}
```

---

## 8. HTML Skeleton

```html
<!-- inside <body>, after modals/overlays -->
<div id="cv-root">

  <!-- NAVBAR -->
  <nav id="cv-navbar"> ... </nav>

  <!-- RESUME WRAPPER -->
  <div id="cv-wrapper">

    <!-- LEFT SIDEBAR -->
    <aside id="cv-sidebar">
      <div id="cv-profile-block"> ... </div>
      <div class="cv-sidebar-section"> <!-- Contact --> </div>
      <div class="cv-sidebar-section"> <!-- Education --> </div>
      <div class="cv-sidebar-section"> <!-- Skills --> </div>
      <div class="cv-sidebar-section"> <!-- Quick Links --> </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main id="cv-main">
      <div id="cv-header-banner"> ... </div>
      <section id="about"> ... </section>
      <section id="experience"> ... </section>
      <section id="projects"> ... </section>
      <section id="roadmap"> ... </section>
      <section id="tech"> ... </section>
      <section id="gallery"> ... </section>
      <footer id="cv-footer"> ... </footer>
    </main>

  </div><!-- end #cv-wrapper -->

</div><!-- end #cv-root -->
```

---

## 9. Implementation Rules for Copilot

1. **Never break existing JS** — dark mode toggle, highlight.js, chatbot (`chat.php`), viewer counter (`counter.php`), portfolio manager (Ctrl+Alt+E), carousel zoom, in-app browser redirect.
2. **Mobile-only CSS is untouched** — all layout changes go inside `@media (min-width: 1024px)`.
3. `body.light-mode` toggle (existing JS) must still work — all new tokens auto-respond via CSS vars.
4. Move `id="themeToggleBtn"` into `#cv-navbar` right zone; keep `id="mobileThemeToggle"` for mobile only.
5. Keep `id="viewerCounter"` fixed overlay as-is (z-index above everything).
6. Keep `id="chatbotToggle"` and `id="chatbotWindow"` as-is (fixed positioned).
7. Keep `id="mainMenuModal"` (Portfolio Manager) as-is.
8. All sections keep their original `id` attributes for scroll targeting.
9. Do **not** create separate `.css` or `.js` files — single-file project (`index.html` only).
10. Use **semantic HTML**: `<nav>`, `<aside>`, `<main>`, `<article>`, `<section>`, `<header>`.
11. All interactive elements need `:hover` and `:focus-visible` states.
12. Footer must remain at the bottom of `#cv-main`.
13. The profile picture must keep the orbital ring animation from `#heroProfilePicture` if it doesn't break layout.
14. Active nav link updates on scroll — use `IntersectionObserver` on each `section[id]` to toggle `.active` class on the corresponding `.cv-nav-link`.
15. Prefix all new CSS classes with `cv-` to avoid conflicts with existing Bootstrap or legacy styles.
