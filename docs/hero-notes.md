# Home page hero (glass nav, AI Showcase, product rails)

Files
* Markup: `resources/views/partials/home-hero.blade.php` (included by `home.blade.php`). The rotating background photos and their script (`.hero-v2-slide`, `main.js`) are unchanged.
* Styles: `public/assets/css/home-hero.css` (hero), `public/assets/css/site-nav.css` (nav), glass buttons in `public/assets/style.css`.
* Scripts: `public/assets/js/home-hero.js` (stops a rail from looping when its list is shorter than the rail), `public/assets/js/site-nav.js` (hamburger).
* Rail data: `app/Support/HeroRails.php`, called from `ProductController::home()`.
* AI Showcase config: `config/hero.php`; images go in `public/images/hero-ai/` (see the README.txt there).

Layout
* Desktop (1100px and up): copy and three buttons on the left, AI Showcase card in the middle, two rails on the right. Below 1100px only the copy and buttons show; the AI card and rails are in a `display:none` wrapper and their images are `loading="lazy"`, so nothing is downloaded for them.
* The nav floats over the top of the hero; the hero is pulled up by `--sn-flow` (76px) so it fills the screen height.
* The rails column keeps a 96px bottom inset so Luma's launcher never covers a card.

How the rails get their data
* **Best sellers** (slides down): the same definition `ProductController::home()` already used: available products ordered by number of order items (`withCount('orderItems')`). The home page falls back to "newest" when nothing was ever ordered; the rail does not, because a card tagged "Best seller" must have sold. So only products with at least one order item appear.
* **Hot · New** (slides up): the newest available products.
* Visibility is the catalog's rule: `Product::available()` (`product_status = 'available'`). The catalog does not look at stock quantity, so neither does the hero.
* Up to 8 per rail, one query per rail (the primary image is eager-loaded, no N+1). A rail with fewer than 3 products is not shown at all; if neither rail has products the AI card still shows. Nothing is ever padded with sample products.
* Loop: the list is rendered twice (the second copy is `aria-hidden` with `tabindex="-1"`); 46 seconds per cycle; paused on hover and on keyboard focus inside the rail; no animation under `prefers-reduced-motion` (the rail becomes a scrollable list).

How to add AI images
1. Make four images of about 800 x 600 px from real designs made on the Customize page.
2. Save them as `public/images/hero-ai/ai-1.jpg` ... `ai-4.jpg` (top left, top right, bottom left, bottom right), or change the names/alt text in `config/hero.php`.
3. Reload the home page. A tile whose file exists shows the picture with an "AI sample" badge; a missing file shows the neutral placeholder tile. All tiles and the "Design yours" link open Customize (the login page for guests, as the old button did).
