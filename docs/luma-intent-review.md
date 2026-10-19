# Luma: review of the 23 original default intents (Stage 21i)

Luma's original built-in answers (the 23 rows that `chatbot:sync-defaults` inserted before Stage 21i) were written before the Other Services rules were agreed. Nothing here was changed in the database: `sync-defaults` is insert-only, so **these rows still say what they said**. Edit them in **Admin > Chatbot** using the suggestions below, or leave them: the new `luma_os_*` answers win whenever a message is clearly about a repair, a ticket, an invoice or a refund request, and a bare "refund", "cancel", "payment", "gcash" or "status" is now asked about once instead of being answered with a furniture-only reply.

How the answers were checked against the agreed facts: Other Services takes as long as the job needs (never a fixed time), work at home or at the shop with any visit or pickup fee confirmed by the team on the invoice, pay online (GCash or card) or cash recorded by the admin, full or half-and-half from the minimum total, **no pay, no work**, minimum online payment, refunds only before work starts, refund by GCash or cash within 7 days after the team confirms, and hours/contact from Contact Settings.

Tokens such as `{{contact}}` and `{{hours}}` are filled by Luma, so keep them.

## Conflict or outdated: please edit

### 1. `luma_payment_methods` (conflicts)
*Now:* "You can pay by card (Stripe), by GCash (through PayMongo), or in cash when you pick up or when it is delivered. You choose at checkout…" This is the furniture checkout only. A customer asking "gcash" or "can I pay cash" about a repair gets it if they pick "Custom furniture order", and it never mentions Other Services.
*Suggested English:* "For furniture orders you can pay by card (Stripe), by GCash (through PayMongo), or in cash when you pick up or when it is delivered. You choose at checkout. An online payment is confirmed as soon as it goes through. Paying for a repair or labor-only job (Other Services)? Ask me 'how do I pay my invoice'."
*Suggested Tagalog:* "Sa order ng furniture, maaari po kayong magbayad sa card (Stripe), sa GCash (sa pamamagitan ng PayMongo), o cash kapag kukunin o ide-deliver na ang order. Pipili po kayo sa checkout. Nakukumpirma po ang online na bayad sa sandaling matagumpay itong maiproseso. Nagbabayad po ba kayo para sa repair o labor lang (Other Services)? Itanong po sa akin kung paano bayaran ang invoice."
*Keywords:* leave as they are. Luma now asks "Other Services or furniture?" for the bare words, so this row is only shown after the customer picks furniture.

### 2. `luma_service_invoice_payment` (outdated, overlaps `luma_os_pay_how`)
*Now:* says some invoices can be half now and half later, without the 3,000-peso rule, the minimum online payment, or "no pay, no work". It also overlaps with the new `luma_os_pay_how`, `luma_os_pay_full_half` and `luma_os_no_pay_no_work` answers, which Luma prefers when both match.
*Suggested:* either disable this row in Admin > Chatbot (the new ones cover it), or replace the English with: "For Other Services (repairs and labor-only work) we send you an invoice, which you can see in your account under Service Request. Pay it online with GCash or card, or pay cash, which our team records. You can pay in full, or, for invoices of {{half_min}} or more where our team allows it, half now and the other half when the service is done. Online payments must be at least {{min_online}}. Work starts after your payment (the full amount, or the first half). {{contact}}" and the Tagalog: "Sa Other Services (pagpapaayos at labor lang), nagpapadala po kami ng invoice na makikita sa inyong account sa Service Request. Bayaran po ito online sa GCash o card, o magbayad ng cash na ire-record ng aming team. Maaaring buo, o para sa invoice na {{half_min}} pataas kung pinapayagan ng team, kalahati ngayon at kalahati kapag tapos na ang serbisyo. Hindi bababa sa {{min_online}} ang online na bayad. Magsisimula ang trabaho pagkatapos ng bayad (buo, o ang unang kalahati). {{contact}}"

### 3. `luma_cancel_refund` (furniture only, needs a pointer)
*Now:* "Our cancellation and refund rules are in the Terms and Conditions… contact us as soon as you can." Correct for furniture orders, and untouched on purpose. It does not tell a repair customer where to go.
*Suggested English (add one sentence):* "…{{contact}} If this is about a repair or labor-only job (Other Services), tell me 'cancel my ticket' and I'll help you send the request."
*Suggested Tagalog:* "…{{contact}} Kung tungkol po ito sa repair o labor lang (Other Services), sabihin lang po sa akin ang 'kanselahin ang ticket ko' at tutulungan ko kayong magpadala ng request."

### 4. `luma_how_long` (conflicts: a fixed 15 to 30 days)
*Now:* "Finishing a piece usually takes roughly {{days}} days, for any furniture type." A customer asking "how long" about a repair would be told 15 to 30 days, but **Other Services never has a fixed time**.
*Suggested English (add):* "Repairs and labor-only jobs (Other Services) take as long as the job needs: our team sets the schedule for each ticket and tells you."
*Suggested Tagalog (add):* "Ang repair at labor lang (Other Services) ay tumatagal ayon sa kailangan ng trabaho: ang aming team ang nagtatakda ng schedule sa bawat ticket at sasabihin nila sa inyo."

### 5. `luma_made_to_order` (can mislead for repairs)
*Now:* "Everything is made to order… production starts once your order is placed and confirmed, which is once the deposit is received." Not true for a repair, where work starts after the invoice payment.
*Suggested (add to both languages):* "For repairs, work starts after you pay the invoice (the full amount, or the first half)." / "Sa pagpapaayos, magsisimula ang trabaho pagkatapos ninyong bayaran ang invoice (buo, o ang unang kalahati)."

### 6. `luma_warranty` (keyword conflict)
*Now:* keywords include `repair`, `ipaayos`, `sira`, `defective`, `damaged`. So "how do I get a table repaired?" can land on the warranty answer.
*Suggested:* remove the keywords `repair` and `ipaayos` and `sira` from this row, and add to the answer: "Need something repaired or restored? That is our Other Services: tell me 'other service' to start." / "Kailangan po bang ipaayos o i-restore ang furniture? Iyon po ang aming Other Services: sabihin lang po ang 'other service' para magsimula."

### 7. `luma_labor_only` (incomplete)
*Now:* "Yes, we can build with wood you provide (labor only). Labor-only prices vary by furniture type, so the owner discusses them with you by phone. Please send a request or contact us:" Does not say that the team sends an invoice, that work can be at home or at the shop, or that work starts after payment.
*Suggested English:* "Yes, we can build with wood you provide (labor only). Send a request (an account is needed); our team reviews it, may call you, and sends an invoice. The work can be done at your home or at our shop, and any visit or pickup fee is shown on the invoice. Work starts after you pay."
*Suggested Tagalog:* "Opo, maaari po kaming gumawa gamit ang kahoy na ibibigay ninyo (labor lang). Magpadala po ng request (kailangan ng account); susuriin ito ng aming team, maaaring tawagan kayo, at magpapadala ng invoice. Maaaring gawin ang trabaho sa bahay ninyo o sa shop, at nakasulat sa invoice ang anumang bayad sa pagpunta o pagkuha. Magsisimula ang trabaho pagkatapos ninyong magbayad."

### 8. `luma_products_cost` (keyword conflict)
*Now:* keywords include `price of your services`, `services price`, `magkano ang serbisyo`. A customer asking about the cost of a repair is shown product cards.
*Suggested:* remove those three keywords (the new `luma_os_visit_pickup_fee`, `luma_os_complaint_price` and `luma_os_invoice_what` answers explain that the team sets the price on the invoice), or add to the answer: "Repair and labor prices are set by our team after they review your ticket, and shown on your invoice."

### 9. `luma_greeting` and `luma_about_business` (do not mention repairs)
*Suggested English (greeting):* "…I can help with prices, ready-made designs, custom designs, repairs (Other Services), wood choices, delivery, payment and your orders and requests…" *(about):* "…We make furniture to order, and we also repair and restore furniture (Other Services)…"
*Suggested Tagalog (greeting):* "…presyo, ready-made na disenyo, custom na disenyo, pagpapaayos (Other Services), mga kahoy, delivery, bayad at inyong mga order at request…" *(about):* "…Gumagawa po kami ng furniture kapag may order, at nag-aayos at nagre-restore din po kami ng furniture (Other Services)…"

### 10. `luma_delivery_fee` (keyword `pickup`)
*Now:* "pickup", "pick up" are keywords, and the answer is about furniture delivery and pickup at checkout.
*Suggested (add):* "For repairs (Other Services), any visit or pickup fee is confirmed by our team and shown on your invoice." / "Sa pagpapaayos (Other Services), kinukumpirma ng aming team ang anumang bayad sa pagpunta o pagkuha at nakasulat ito sa invoice."

### 11. `luma_deposit_rules` (clarify)
*Now:* "For Catalog orders… installments… For custom orders, the deposit…" Correct, but a repair customer who asks about "hulugan" is given furniture installments.
*Suggested (add):* "Repairs (Other Services) have no installments beyond half and half." / "Sa pagpapaayos (Other Services), wala nang ibang hulugan maliban sa half and half."

### 12. `luma_track_order` and `luma_change_contact` (add a pointer)
*track_order, add:* "For a repair or labor-only request, ask me 'where is my ticket'." / "Para sa repair o labor lang, itanong po sa akin ang 'nasaan ang ticket ko'."
*change_contact, add:* "To correct the phone number or email on a service request you already sent, contact us with your ticket number. {{contact}}" / "Para itama ang numero o email sa service request na naipadala na, kontakin po kami dala ang ticket number. {{contact}}"

## No change needed

`luma_ready_made`, `luma_shop_open`, `luma_price_list`, `luma_price_range`, `luma_free_delivery`, `luma_ai_design_steps`, `luma_dimensions`, `luma_best_wood`, `luma_contact_shop`: furniture-only facts that do not conflict with the Other Services rules. (`luma_contact_shop` is also the answer to "talk to a person".)

## Old, non-default rows in the live table

The 20 hand-written rows from before Stage 20 (ids 11 to 30, kept in `tests/Fixtures/LegacyIntents.php`) are not part of the 23 defaults, but one is **outdated**: **id 15** says "GCash QR payment — scan and upload your receipt". The manual GCash receipt flow was removed when PayMongo became the only GCash path. Suggested English: "We accept card payment through Stripe (Visa, Mastercard), GCash through PayMongo, and cash on pickup or delivery." Suggested Tagalog: "Tumatanggap po kami ng card sa pamamagitan ng Stripe (Visa, Mastercard), GCash sa pamamagitan ng PayMongo, at cash kapag kukunin o ide-deliver ang order." Please check the other 19 for the same kind of old wording.
