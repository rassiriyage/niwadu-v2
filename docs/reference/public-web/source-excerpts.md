# Source provenance

Retrieved 2026-09-23; excerpts are reference data, not runtime dependencies.

Stylesheet: https://niwadu.com/build/assets/styles.css?v=67c8e50b-acfb-4c6c-908e-46e57ab10354
SHA-256: `f241718aefd038aa6f2ba56b77157caff3011079425e8a35afe2de3436b787f1` (minified, line 1).

```css
.niwadu-site-header__menu{flex:1;display:flex;gap:14px;align-items:center;justify-content:center}
@media (max-width: 1023.98px){.niwadu-site-header__menu{display:none}
body:has(.layout-without-expanded-header) .niwadu-site-header .niwadu-site-header__menu{transform:translateY(-70px)}
.niwadu-container{margin-inline:auto;padding-inline:1rem}
@media (min-width: 576px) and (max-width: 1023.98px){.niwadu-container{padding-inline:1.5rem}
@media (min-width: 1024px){.niwadu-container{padding-inline:2.5rem}
.niwadu-mobile-main-nav{display:none}
@media (max-width: 1023.98px){.niwadu-mobile-main-nav{display:flex;position:fixed;z-index:1000;bottom:16px;left:16px;right:16px;height:44px;background-color:#00000014;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);border-radius:40px;--border-width: clamp(1px, .0625em, 4px);box-shadow:1px 2.5px .5px -1.5px #fff6 inset,-1px -2.5px .5px -1.5px #fff6 inset,2.5px 2.5px .42px -2.92px #fff inset}
```

Carousel: https://niwadu.com/js/niwadu.js?v=67c8e50b-acfb-4c6c-908e-46e57ab10354
SHA-256: `6251164608c725ed0026525b16e736ea6bc142a9f5f3bd625d479dd8a22e3aa5`; lines 302–324:

```js
niwaduListingGlide.forEach((niwaduglide) => {
    const slideItems = parseInt(niwaduglide.dataset.slideItems);
    let glide = new Glide(niwaduglide, {
        perView: slideItems,
        gap: 16,
        rewind: false,
        bound: true,
        breakpoints: {
            1024: {
                perView: 3,
            },
            768: {
                perView: 2,
            },
            576: {
                perView: 1,
                peek: {
                    before: 0,
                    after: 40,
                },
            },
        },
    });
```

Font CSS from https://fonts.googleapis.com/css2?family=Inter+Tight:ital,wght@0,100..900;1,100..900&display=swap ; selected normal weights only, self-hosted with OFL license.

```json
[
  {
    "weight": 400,
    "url": "https://fonts.gstatic.com/s/intertight/v9/NGSnv5HMAFg6IuGlBNMjxJEL2VmU3NS7Z2mjDw-qXA.ttf",
    "file": "fonts/inter-tight-400.ttf"
  },
  {
    "weight": 500,
    "url": "https://fonts.gstatic.com/s/intertight/v9/NGSnv5HMAFg6IuGlBNMjxJEL2VmU3NS7Z2mjPQ-qXA.ttf",
    "file": "fonts/inter-tight-500.ttf"
  },
  {
    "weight": 600,
    "url": "https://fonts.gstatic.com/s/intertight/v9/NGSnv5HMAFg6IuGlBNMjxJEL2VmU3NS7Z2mj0QiqXA.ttf",
    "file": "fonts/inter-tight-600.ttf"
  }
]
```

Dock SVG paths are the four inline Lucide SVGs from the homepage `niwadu-mobile-main-nav` region, map-pin/hotel/trees/user. Decorative stroke resolves to white in the saved copies. MIT/ISC licenses preserved alongside files. No Lucide runtime dependency.
