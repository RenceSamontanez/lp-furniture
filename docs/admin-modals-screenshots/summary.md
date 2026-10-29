# Admin modal pages: proof

From `browser-admin-modals.cjs` (proof mode): 12 states x 3 widths (1440, 1001, 390), the page scrolled before each modal opens.

| State @ width | Overflow px | Clipped | Low contrast | Modal visible | Above sidebar + top bar | Above toasts | Footer buttons visible | Bottom sheet |
|---|---|---|---|---|---|---|---|---|
| chatbot@1440 | -15 | 0 | 0 | - | - | - | - | - |
| chatbot-add@1440 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot-edit@1440 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot-error@1440 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot-delete@1440 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot-unanswered@1440 | 0 | 0 | 0 | - | - | - | - | - |
| contact@1440 | 0 | 0 | 0 | - | - | - | - | - |
| contact-business@1440 | 0 | 0 | 0 | true | true | true | true | - |
| contact-shop@1440 | 0 | 0 | 0 | true | true | true | true | - |
| contact-error@1440 | 0 | 0 | 0 | true | true | true | true | - |
| health@1440 | 0 | 0 | 0 | - | - | - | - | - |
| health-clear-cache@1440 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot@1001 | -15 | 0 | 0 | - | - | - | - | - |
| chatbot-add@1001 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot-edit@1001 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot-error@1001 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot-delete@1001 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot-unanswered@1001 | 0 | 0 | 0 | - | - | - | - | - |
| contact@1001 | -15 | 0 | 0 | - | - | - | - | - |
| contact-business@1001 | 0 | 0 | 0 | true | true | true | true | - |
| contact-shop@1001 | 0 | 0 | 0 | true | true | true | true | - |
| contact-error@1001 | 0 | 0 | 0 | true | true | true | true | - |
| health@1001 | -15 | 0 | 0 | - | - | - | - | - |
| health-clear-cache@1001 | 0 | 0 | 0 | true | true | true | true | - |
| chatbot@390 | 0 | 0 | 0 | - | - | - | - | - |
| chatbot-add@390 | 0 | 0 | 0 | true | true | true | true | true |
| chatbot-edit@390 | 0 | 0 | 0 | true | true | true | true | true |
| chatbot-error@390 | 0 | 0 | 0 | true | true | true | true | true |
| chatbot-delete@390 | 0 | 0 | 0 | true | true | true | true | true |
| chatbot-unanswered@390 | 0 | 0 | 0 | - | - | - | - | - |
| contact@390 | 0 | 0 | 0 | - | - | - | - | - |
| contact-business@390 | 0 | 0 | 0 | true | true | true | true | true |
| contact-shop@390 | 0 | 0 | 0 | true | true | true | true | true |
| contact-error@390 | 0 | 0 | 0 | true | true | true | true | true |
| health@390 | 0 | 0 | 0 | - | - | - | - | - |
| health-clear-cache@390 | 0 | 0 | 0 | true | true | true | true | true |

## Computed styles (1440 px)

| State | Element | Font | Size | Weight |
|---|---|---|---|---|
| chatbot@1440 | pageTitle ("Chatbot Training") | Cormorant Garamond | 19px | 600 |
| chatbot@1440 | tableHeader ("Category") | Montserrat | 11px | 600 |
| chatbot@1440 | tableCell ("—") | Montserrat | 13.5px | 500 |
| chatbot@1440 | button ("Search") | Montserrat | 14px | 600 |
| chatbot@1440 | label ("Keywords (Comma-Separated)") | Montserrat | 13px | 500 |
| chatbot@1440 | sidebar ("Dashboard") | Montserrat | 13px | 600 |
| chatbot-add@1440 | pageTitle ("Chatbot Training") | Cormorant Garamond | 19px | 600 |
| chatbot-add@1440 | tableHeader ("Category") | Montserrat | 11px | 600 |
| chatbot-add@1440 | tableCell ("—") | Montserrat | 13.5px | 500 |
| chatbot-add@1440 | button ("Search") | Montserrat | 14px | 600 |
| chatbot-add@1440 | label ("Keywords (Comma-Separated)") | Montserrat | 13px | 500 |
| chatbot-add@1440 | modalTitle ("Add Intent") | Montserrat | 21px | 600 |
| chatbot-add@1440 | modalInput ("") | Montserrat | 14px | 400 |
| chatbot-add@1440 | sidebar ("Dashboard") | Montserrat | 13px | 600 |
| chatbot-unanswered@1440 | pageTitle ("Chatbot Training") | Cormorant Garamond | 19px | 600 |
| chatbot-unanswered@1440 | button ("Dismiss") | Montserrat | 14px | 600 |
| chatbot-unanswered@1440 | sidebar ("Dashboard") | Montserrat | 13px | 600 |
| contact@1440 | pageTitle ("Contact Settings") | Cormorant Garamond | 19px | 600 |
| contact@1440 | button ("Edit") | Montserrat | 14px | 600 |
| contact@1440 | label ("Phone") | Montserrat | 16px | 500 |
| contact@1440 | sidebar ("Dashboard") | Montserrat | 13px | 600 |
| contact-business@1440 | pageTitle ("Contact Settings") | Cormorant Garamond | 19px | 600 |
| contact-business@1440 | button ("Edit") | Montserrat | 14px | 600 |
| contact-business@1440 | label ("Phone") | Montserrat | 16px | 500 |
| contact-business@1440 | modalTitle ("Edit Business Contact") | Montserrat | 21px | 600 |
| contact-business@1440 | modalInput ("") | Montserrat | 14px | 400 |
| contact-business@1440 | sidebar ("Dashboard") | Montserrat | 13px | 600 |
| health@1440 | pageTitle ("System Health") | Cormorant Garamond | 19px | 600 |
| health@1440 | tableHeader ("Folder") | Montserrat | 11px | 600 |
| health@1440 | tableCell ("Customer reference photos (p") | Montserrat | 13.5px | 500 |
| health@1440 | button ("Clear Cache") | Montserrat | 14px | 600 |
| health@1440 | sidebar ("Dashboard") | Montserrat | 13px | 600 |
