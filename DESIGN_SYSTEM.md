# Design System — Meyrin FC Apps

> Référence de design pour tous les micro-SaaS du dossier Meyrin FC.
> À consulter avant toute création ou modification d'interface dans ce dossier.

---

## Typographie

| Rôle | Police | Taille | Line-height |
|---|---|---|---|
| Corps / interface | `"Inter", -apple-system, system-ui, sans-serif` | `14px` | `1.5` |
| Titres (h1–h4), grands labels | `"Sora", "Inter", sans-serif` | variable | — |
| Inputs / selects | Inter | `14px` | — |

**Google Fonts à inclure dans chaque fichier HTML :**
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
```

**Règle CSS obligatoire :**
```css
body { font-family: "Inter", -apple-system, system-ui, sans-serif; font-size: 14px; line-height: 1.5; }
h1, h2, h3, h4 { font-family: "Sora", "Inter", sans-serif; }
input, select { font-family: inherit; font-size: 14px; }
```

---

## Palette de couleurs

### Couleurs principales (CSS variables)
```css
:root {
  --jaune:       #ffdd00;   /* Couleur brand principale */
  --jaune-fonce: #c5a900;   /* Hover, focus, accents */
  --jaune-soft:  #FFF3C4;   /* Fond jaune très léger */
  --jaune-pale:  #FFF9D9;   /* Fond actif sidebar (si nécessaire) */

  --noir:        #15140F;   /* Texte principal, fond sidebar */
  --noir-2:      #211F18;   /* Hover sidebar, fond inputs sombres */
  --noir-3:      #2C2A21;   /* Variante sombre */

  --encre:       #1A1915;   /* Texte alternatif (légèrement plus doux) */
  --gris:        #6E6C61;   /* Texte secondaire */
  --gris-2:      #9A988C;   /* Texte tertiaire, placeholders */

  --ligne:       #E9E6DC;   /* Bordures claires (interface principale) */
  --ligne-2:     #F0EDE4;   /* Bordures très légères */
  --ligne-dark:  #28261E;   /* Bordures dans la sidebar sombre */

  --bg:          #F6F4ED;   /* Fond général (beige chaud) */
  --carte:       #FFFFFF;   /* Fond des cards/panels */

  /* Statuts */
  --vert:        #1F9D5B;   --vert-bg:   #E4F4EA;
  --rouge:       #D8463A;   --rouge-bg:  #FBE7E4;
  --orange:      #E8902A;   --orange-bg: #FCEEDB;
  --bleu:        #3B6FE0;   --bleu-bg:   #E6EDFB;
  --violet:      #7B59D8;   --violet-bg: #EEE8FB;

  --ombre-s: 0 1px 2px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.06);
  --ombre:   0 4px 6px rgba(0,0,0,.07), 0 12px 24px rgba(0,0,0,.10);
  --radius:  14px;
  --radius-s: 10px;
}
```

### Valeurs Tailwind (si l'app utilise Tailwind CDN)
```js
tailwind.config = {
  theme: { extend: {
    fontFamily: { sans: ['Inter','system-ui','sans-serif'], display: ['Sora','Inter','sans-serif'] },
    colors: {
      ink:    { DEFAULT: '#15140F', soft: '#6E6C61' },
      mute:   '#9A988C',
      line:   '#E9E6DC',
      canvas: '#F6F4ED',
      brand:  { 50:'#FFF9D9', 100:'#FFF3C4', 500:'#ffdd00', 600:'#c5a900', 700:'#a88d00', 800:'#8a7300' },
      amber2: { 50:'#FFF8E6', 600:'#B45309' },
      rose2:  { 50:'#FEF1F2', 600:'#DC2626' },
      blue2:  { 50:'#EFF4FF', 600:'#2563EB' },
      violet2:{ 50:'#F4F1FE', 600:'#7C3AED' },
    },
    boxShadow: {
      card: '0 1px 2px rgba(12,18,32,.04), 0 1px 3px rgba(12,18,32,.06)',
      pop:  '0 4px 6px -1px rgba(12,18,32,.07), 0 12px 24px -8px rgba(12,18,32,.12)',
      ring: '0 0 0 4px rgba(255,221,0,.25)',
    },
    borderRadius: { xl2: '14px' },
  }}
}
```

---

## Composants clés

### Sidebar (navigation latérale)
La sidebar est **sombre** sur toutes les apps.

```
Fond :           #15140F
Bordure droite : #28261E
Séparateurs :    #28261E

Header (logo + nom app) :
  - Nom app : font-display, 15px, font-bold, text-white
  - Sous-titre : 10.5px, color #8C897C

Labels de sections (PILOTAGE / CROISSANCE / etc.) :
  - 10.5px, uppercase, letter-spacing, color #75736A

Items de navigation (repos) :
  - Texte : #C9C7BD
  - Icône : #8E8C82
  - Hover fond : #211F18
  - Hover texte/icône : #ffffff

Item actif :
  - Fond : #ffdd00 (jaune plein)
  - Texte : #15140F (noir)
  - Icône : #15140F (noir)
  - Font-weight : 600

Sélecteur saison (si présent) :
  - Fond : #211F18
  - Bordure : #28261E
  - Texte : #C9C7BD

Footer sidebar :
  - Bordure top : #28261E
  - Fond carte info : #211F18
```

### Boutons principaux (CTA)
```css
/* Bouton primaire — TOUJOURS texte sombre sur fond jaune */
background: #ffdd00 (ou var(--jaune))
color:      #15140F  ← IMPORTANT : jamais text-white sur fond jaune
hover:      background #c5a900

/* Classes Tailwind */
bg-brand-600 text-[#15140F] hover:bg-brand-700

/* Bouton sombre */
background: #15140F
color: #ffffff
```

### Cards / Panels
```css
background:    #ffffff
border:        1px solid #E9E6DC
border-radius: 14px
box-shadow:    0 1px 2px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.06)
```

### Inputs / Selects
```css
border:        1.5px solid #E9E6DC
border-radius: 10px
background:    #ffffff
color:         #15140F
font-size:     14px (ou 13px pour les petits champs)

/* Focus */
border-color:  #ffdd00
box-shadow:    0 0 0 4px rgba(255,221,0,.25)

/* Focus-visible (accessibilité) */
outline:       2px solid #c5a900
```

### Avatar utilisateur
```css
background: linear-gradient(135deg, #ffdd00, #c5a900)
color:      #15140F
border-radius: 9px (ou 50% si rond)
font-weight: 700
```

### Badges de statut
Utiliser les paires couleur/fond définies dans la palette :
- Succès : `color #1F9D5B, bg #E4F4EA`
- Erreur : `color #D8463A, bg #FBE7E4`
- Avertissement : `color #E8902A, bg #FCEEDB`
- Info : `color #3B6FE0, bg #E6EDFB`
- Violet : `color #7B59D8, bg #EEE8FB`

---

## Layout général

```
Structure standard : sidebar 248–256px (fixe) + zone principale flexible
Fond général :       #F6F4ED (beige chaud)
Topbar :             fond blanc, bordure bottom #E9E6DC, hauteur 62–66px
Contenu principal :  padding 22–26px, max-width selon app
```

---

## Apps du dossier et leur stack CSS

| App | Stack | Fichier principal |
|---|---|---|
| `erp.meyrinfc.ch/arbitrage` | CSS custom (variables) | index.html (sert via index.php) |
| `erp.meyrinfc.ch/caisse` | CSS custom (variables) | index.html (sert via index.php) |
| `erp.meyrinfc.ch/events` | CSS custom (variables) | index.html (sert via index.php) |
| `erp.meyrinfc.ch/sponsors` | Tailwind CDN + CSS custom | index.html (sert via index.php) |
| `erp.meyrinfc.ch/commandes` | Tailwind CDN + CSS custom | index.html (sert via index.php) |
| `erp.meyrinfc.ch/commandes/shop` | Tailwind CDN | index.php (boutique publique, sans session) |
| `erp.meyrinfc.ch` | CSS custom (variables) | index.php |
| `erp.meyrinfc.ch/rh` | CSS custom (variables) | index.html (SPA, sert via index.php) |
| `erp.meyrinfc.ch/compta` | CSS custom (variables) | index.html (SPA, sert via index.php) |

> Pour les apps en **CSS custom**, utiliser les variables CSS ci-dessus.
> Pour **Sponsors** (Tailwind), utiliser la config Tailwind définie dans ce fichier + classes Tailwind avec valeurs hardcodées (`text-[#15140F]`, `bg-[#15140F]`, etc.) pour les cas non couverts.
