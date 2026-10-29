# Admin typography: before / after proof

Generated from `docs/admin-type-screenshots/{before,after}/report.json` by `tests/UiLinks/browser-admin-type.cjs` (before = commit 13c54f6 served from a worktree on :8012, after = this tree on :8011, same scratch database, headless Chrome at 1440, 1001 and 390 px, 15 pages).

## Overflow, clipping, arrows, contrast (15 pages x 3 widths)

| Measure | Before | After |
|---|---|---|
| Pages with page-level horizontal overflow | 0 | 0 |
| Clipped text boxes (overflow hidden / ellipsis) | 2 | 2 |
| Off-screen elements (not inside a scroller) | 65 | 0 |
| Arrows on buttons and links (text or icon) | 21 | 0 |
| Text below 4.5:1 (3:1 for large text) | 516 | 0 |

The two clipped boxes after are the top-bar page title at 390 px (`Order LP-2026-00001`, `Cancellations & Refunds`), cut by the existing `max-width: 40vw` ellipsis; they are clipped in the before run too and were left alone.

## Computed styles (1440 px)

| Page | Element | Font | Size | Weight | Numerals |
|---|---|---|---|---|---|
| orders | pageTitle ("Orders") | Cormorant Garamond | 19px | 600 | lining-nums |
| orders | tableHeader ("Order #") | Montserrat | 11px | 600 | lining-nums tabular-nums |
| orders | tableCell ("LP-UI21J-001") | Montserrat | 13.5px | 500 | lining-nums tabular-nums |
| orders | number ("₱1,600.00") | Montserrat | 13.5px | 500 | lining-nums tabular-nums |
| orders | button ("View") | Montserrat | 14px | 600 | lining-nums tabular-nums |
| orders | sidebarItem ("Dashboard") | Montserrat | 13px | 600 | lining-nums |
| order-view | pageTitle ("Order LP-2026-00001") | Cormorant Garamond | 19px | 600 | lining-nums |
| order-view | button ("Add a Photo") | Montserrat | 14px | 600 | normal |
| order-view | formLabel ("Message to the Customer (Optio") | Montserrat | 14px | 500 | lining-nums |
| order-view | sidebarItem ("Dashboard") | Montserrat | 13px | 600 | lining-nums |
| add-product | pageTitle ("Add Product") | Cormorant Garamond | 19px | 600 | lining-nums |
| add-product | button ("Back to Products") | Montserrat | 14px | 600 | lining-nums |
| add-product | formLabel ("Product Name") | Montserrat | 13px | 500 | lining-nums |
| add-product | sidebarItem ("Dashboard") | Montserrat | 13px | 600 | lining-nums |
| add-product | panelTitle ("Product Details") | Montserrat | 16px | 600 | lining-nums |

## Customer pages (HTML compared before / after)

```
/              before=200 after=200 bytes=40481/40490  IDENTICAL
/catalog       before=200 after=200 bytes=19030/19039  IDENTICAL
/product/1     before=200 after=200 bytes=19729/19738  IDENTICAL
/login         before=200 after=200 bytes=7051/7055  IDENTICAL
/cart          before=200 after=200 bytes=22392/22401  IDENTICAL
/checkout      before=200 after=200 bytes=73344/73353  IDENTICAL
/orders        before=200 after=200 bytes=22898/22907  IDENTICAL
/orders/1      before=200 after=200 bytes=24613/24622  IDENTICAL
/profile       before=200 after=200 bytes=47442/47451  IDENTICAL
/notifications before=200 after=200 bytes=42513/42522  IDENTICAL
ALL CUSTOMER PAGES IDENTICAL
```
