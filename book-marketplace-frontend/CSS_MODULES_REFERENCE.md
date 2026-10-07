# CSS Modules File Reference

## Quick Reference Guide

### File Statistics

| File | Purpose | Approx. Lines | Key Components |
|------|---------|---------------|-----------------|
| `style.css` | Main index (imports) | 20 | All @import statements |
| `variables.css` | Variables & reset | 58 | Colors, fonts, spacing, reset |
| `header.css` | Header & nav | 142 | Header, navigation, auth status |
| `layout.css` | Page structure | 43 | Main, section, grid |
| `footer.css` | Footer | 31 | Footer styling |
| `forms.css` | Form elements | 177 | Input, select, validation |
| `buttons.css` | Buttons | 145 | All button variants |
| `tables.css` | Tables | 57 | Table styling |
| `auth.css` | Auth pages | 340 | Login, register, auth components |
| `home.css` | Home page | 100 | Hero section |
| `dashboard.css` | Dashboard | 95 | Customer dashboard |
| `bookshelf.css` | Library UI | 780 | Bookshelf, catalogs, modals |
| `landing-page.css` | Landing page | 840 | Hero, discovery, showcase, footer |
| `utilities.css` | Utilities | 95 | Loaders, status, helpers |
| **Total** | **All files** | **~2,880** | **Complete stylesheet** |

## Import Dependencies

```
style.css (main)
├── variables.css (defines all CSS variables)
├── header.css (uses variables)
├── layout.css (uses variables)
├── footer.css (uses variables)
├── forms.css (uses variables)
├── buttons.css (uses variables)
├── tables.css (uses variables)
├── auth.css (uses variables + components)
├── home.css (uses variables + components)
├── dashboard.css (uses variables + components)
├── bookshelf.css (uses variables + components)
├── landing-page.css (uses variables + components)
└── utilities.css (uses variables)
```

## Finding Styles by Feature

### Colors & Branding
- **File**: `variables.css`
- **Variables**: `--ink`, `--paper`, `--spine`, `--tab`, etc.

### Header & Navigation
- **File**: `header.css`
- **Classes**: `.header`, `.nav`, `.auth-status`, `.active-nav`

### Form Input & Validation
- **File**: `forms.css`
- **Classes**: `.form-group`, `.field-error`, `.checkbox-option`, `.price-input-wrapper`

### Buttons
- **File**: `buttons.css`
- **Classes**: `.btn-primary`, `.btn-clear`, `.btn-danger`, `.text-link`

### Data Tables
- **File**: `tables.css`
- **Classes**: `table`, `thead`, `td`, `.detail-table`

### Authentication (Login/Register)
- **File**: `auth.css`
- **Classes**: `.auth-card`, `.auth-tab-btn`, `.password-input-wrapper`, `.auth-message`

### Bookshelf & Catalog
- **File**: `bookshelf.css`
- **Classes**: `.bookshelf`, `.shelf-book`, `.book-cover`, `.catalog-card`, `.book-dialog`

### Landing Page
- **File**: `landing-page.css`
- **Classes**: `.landing-hero`, `.hero-title`, `.showcase-card`, `.landing-footer`

### Dashboard
- **File**: `dashboard.css`
- **Classes**: `.dashboard-card`, `.dashboard-card-value`, `.welcome-art`

### Loading & Status
- **File**: `utilities.css`
- **Classes**: `.inline-spinner`, `.tx-status`, `.stat-loading`

## Responsive Breakpoints

Breakpoints are defined throughout the files:
- **960px**: Desktop to tablet (header reflow, hero grid)
- **720px**: Tablet to mobile (form layout, font sizes)
- **600px**: Small mobile (bookshelf grid, bulk bar)

All responsive styles are located in `@media` queries within each relevant module file.

## Adding New Styles

### For a new component:
1. Create a new CSS file (e.g., `components/card.css`)
2. Add `@import url("components/card.css");` to `style.css`
3. Use CSS variables from `variables.css`
4. Include responsive breakpoints

### For a new page:
1. Create a new CSS file (e.g., `pages/my-page.css`)
2. Add to `style.css` in the "Pages" section
3. Import after components but before landing page
4. Follow existing naming conventions

### For global utilities:
1. Add to `utilities.css` or create new utility file
2. Keep utilities independent and reusable
3. Avoid page-specific styles in utilities

## Common CSS Custom Properties

```css
/* Colors */
--ink: #4e3b30;                 /* Primary text color */
--paper: #f4eadd;               /* Background */
--spine: #9a7458;               /* Accent/Action */
--tab: #e2b66c;                 /* Yellow active state */

/* Typography */
--font-head: "Fraunces", serif;
--font-ui: "Nunito Sans", sans-serif;
--font-type: "Courier Prime", monospace;

/* Spacing */
--r-lg: 20px;                   /* Large radius */
--r-md: 12px;                   /* Medium radius */
--r-pill: 999px;                /* Pill-shaped radius */
```

## Cozy Theme Overrides

The cozy theme (main default theme) overrides are applied within each module file with comments marked "COZY THEME". Key changes include:
- Pastel brown/gradient backgrounds
- Rounded pill-shaped buttons
- Softer box shadows
- Paper card aesthetic
- Wood texture elements (in bookshelf.css)

## Browser Compatibility

All CSS follows:
- CSS Grid (modern browsers)
- Flexbox (modern browsers)
- CSS Custom Properties (all modern browsers)
- Fallbacks for older browsers where needed

No vendor prefixes are needed for the main CSS, but the build process may add them.
