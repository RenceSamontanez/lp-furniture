# Landing hero: contrast report

Measured in a real (headless Chrome) browser by `tests/UiLinks/browser-hero.cjs`: all text is made transparent, the page is screenshotted, and each text box is compared with the worst painted pixel behind it (lightest 1% for light text, darkest 1% for dark text). Hero rows are the worst of the 3 rotating background photos (each forced in turn). Required: 4.5 (text).

| Text | Where | Worst ratio |
|---|---|---|
| Elegance | hero photos | 5.86 |
| Premium custom wood furnitur | hero photos | 6.45 |
| Handcrafted Since 2020 · San | hero photos | 6.49 |
| Best sellers | hero photos | 8.11 |
| AI Showcase | hero photos | 8.17 |
| Register | hero photos | 8.27 |
| Shop Now | hero photos | 8.27 |
| New | hero photos | 8.27 |
| Design yours | hero photos | 8.39 |
| Describe a piece and our AI  | hero photos | 8.40 |
| Hot · New | hero photos | 8.48 |
| Crafting Comfort | hero photos | 9.30 |
| Samples made with AI | hero photos | 9.53 |
| LP | nav over light pages | 9.61 |
| for Every Home | hero photos | 9.72 |
| ₱13,250.00 | hero photos | 11.15 |
| ₱15,000.00 | hero photos | 11.42 |
| AI sample | hero photos | 11.78 |
| ₱16,750.00 | hero photos | 11.90 |
| and | hero photos | 12.40 |
| ₱6,250.00 | hero photos | 12.81 |
| LP | hero photos | 13.02 |
| Imagine it. We build it. | hero photos | 13.74 |
| Home | nav over light pages | 13.95 |
| Furniture | nav over light pages | 13.95 |
| Catalog | nav over light pages | 14.11 |
| Customize | nav over light pages | 14.11 |
| Reviews | nav over light pages | 14.11 |
| My Account | nav over light pages | 14.17 |
| Contact Us | hero photos | 15.91 |
| Sofa Frame | hero photos | 16.38 |
| Study Table | hero photos | 16.58 |
| Customize Furniture | hero photos | 17.02 |
| Narra Table (back view) | hero photos | 17.63 |
| Home | hero photos | 18.31 |
| Catalog | hero photos | 18.31 |
| Furniture | hero photos | 18.81 |
| Login | hero photos | 19.22 |

## Button states (tests/ButtonContrast/measure.js, home page, 1440 and 390)

| Button | Classes | Worst state ratio |
|---|---|---|
| Login | lp-btn lp-btn--glass-outline sn-cta | 16.5 |
| Register | lp-btn lp-btn--glass-primary sn-cta | 6.49 |
| Shop Now | lp-btn lp-btn--glass-primary lp-hero__cta | 6.49 |
| Customize Furniture | lp-btn lp-btn--glass-outline lp-hero__cta | 8.29 |
| Contact Us | lp-btn lp-btn--glass-quiet lp-hero__cta | 5.85 |
