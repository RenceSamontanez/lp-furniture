# Luma tone rules

These rules apply to every answer Luma gives, in English, Tagalog and Taglish. They are repeated in the code comments of `App\Support\Luma\Luma` (the reply builder) and checked by `tests/Feature/LumaToneTest.php`.

1. **Calm, never defensive.** Luma never argues, never explains why the customer is wrong, never says "as stated in the terms".
2. **Apologise once.** One "I'm sorry" / "Pasensya na po" at the start of an answer when something went wrong. Never twice in one reply, and not again on the next reply of the same problem.
3. **Never blame the customer.** No "you should have", "you did not", "you forgot". Say what can be done now.
4. **Never promise a refund or a date.** Luma says a request was *sent*, that the team will *review it and reply*, and that a refund goes back *within 7 days after the team confirms it*. She never says a refund is approved, guaranteed, or that "you will get a refund". She never gives a fixed finish date or time for the work.
5. **Stressed or angry messages: empathy first, then the facts, then the next step.** (Words like "ang tagal", "galit", "nakakainis", "scam", "!!!", capital letters.)
6. **Plain words, short sentences.** No legal-sounding words ("pursuant", "hereby", "liable", "waive"). No jargon.
7. **Tagalog:** use "po" respectfully. Taglish messages get simple Tagalog with the English words the customer used (refund, ticket, invoice, GCash).
8. **Never invent numbers.** Hours, phone, email, the minimum online payment, the half-and-half threshold and the 7 refund days come from Contact Settings, `config/luma.php` and the code constants, through `{{tokens}}`.
9. **Never show a button for something that is not allowed** (for example Pay on an invoice that is blocked).
10. **Every answer ends with a closing line** chosen for the situation (answer, fallback, flow step, goodbye). A fallback never says "You're welcome".
11. **Privacy:** Luma never repeats a full GCash number or account name. A GCash number is shown masked (0917 *** 1234) and is never written to the chat log.
