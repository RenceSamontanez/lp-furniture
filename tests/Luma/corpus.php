<?php

/**
 * Luma's labelled customer messages (Stage 21i). Each row: [message, expected, group, who].
 *
 *   expected  a node / intent name (luma_os_..., luma_...)    the intent that must answer
 *             ask:refund | ask:cancel | ask:payment | ask:status   the one disambiguation question
 *             ref        a reference number answered with the owner's real status
 *             refnone    a reference number answered with the neutral "I couldn't find that"
 *             flow       the guided refund flow opened a form card
 *             unmatched  the fallback (gibberish must not be answered)
 *   group     en | tl | mix | typo | short | long | angry | elder | ref | notos | refund | cancel | payment | unpaid
 *             refund, cancel, payment and unpaid are the SAFETY-CRITICAL groups (the test needs 100% on them)
 *   who       guest (default) | owner (signed in, with the fixture ticket SI-2026-0001 / INV-2026-0001 / REF-2026-0001) | other (a different customer)
 *
 * tests/Feature/LumaCorpusTest.php runs every row through the real endpoint. tests/Luma/corpus_stats.php prints the counts.
 */

$rows = [];
$add = function (string $message, string $expected, string $group, string $who = 'guest') use (&$rows) {
    $rows[] = [$message, $expected, $group, $who];
};

/* ───── one word and very short (60+) ───── */
foreach ([
    ['refund', 'ask:refund', 'refund'], ['cancel', 'ask:cancel', 'cancel'], ['refund po', 'ask:refund', 'refund'], ['kanselahin', 'ask:cancel', 'cancel'],
    ['refund?', 'ask:refund', 'refund'], ['Cancel', 'ask:cancel', 'cancel'], ['REFUND', 'ask:refund', 'refund'], ['magrefund', 'ask:refund', 'refund'],
    ['gcash', 'ask:payment', 'payment'], ['cash', 'ask:payment', 'payment'], ['payment', 'ask:payment', 'payment'], ['bayad', 'ask:payment', 'payment'],
    ['gcash po', 'ask:payment', 'payment'], ['card', 'ask:payment', 'payment'], ['pay', 'ask:payment', 'payment'], ['bayad po', 'ask:payment', 'payment'],
    ['status', 'ask:status', 'short'], ['track', 'ask:status', 'short'], ['status po', 'ask:status', 'short'],
    ['other service', 'luma_os_what_is', 'short'], ['other services', 'luma_os_what_is', 'short'], ['Other Services po', 'luma_os_what_is', 'short'], ['otherservices', 'luma_os_what_is', 'short'],
    ['ticket', 'luma_os_make_ticket', 'short'], ['tiket', 'luma_os_make_ticket', 'short'], ['create ticket', 'luma_os_make_ticket', 'short'],
    ['invoice', 'luma_os_invoice_what', 'short'], ['invoys', 'luma_os_invoice_what', 'typo'], ['balance', 'luma_os_pay_status', 'short'], ['balanse', 'luma_os_pay_status', 'typo'],
    ['half', 'luma_os_pay_full_half', 'short'], ['kalahati', 'luma_os_pay_full_half', 'short'], ['second half', 'luma_os_second_half', 'short'], ['service done', 'luma_os_second_half', 'short'],
    ['refund status', 'luma_os_refund_status', 'refund'], ['status ng refund', 'luma_os_refund_status', 'refund'], ['where is my refund', 'luma_os_refund_status', 'refund'],
    ['hi', 'luma_greeting', 'short'], ['hello po', 'luma_greeting', 'short'], ['kumusta', 'luma_greeting', 'short'], ['contact', 'luma_contact_shop', 'short'], ['shop hours', 'luma_shop_open', 'short'],
    ['price', 'luma_products_cost', 'short'], ['presyo', 'luma_products_cost', 'short'], ['delivery', 'luma_delivery_fee', 'short'], ['warranty', 'luma_warranty', 'short'],
    ['deposit', 'luma_deposit_rules', 'short'], ['installment', 'luma_deposit_rules', 'short'], ['hulugan', 'luma_deposit_rules', 'short'],
    ['wood', 'luma_best_wood', 'short'], ['kahoy', 'luma_best_wood', 'short'], ['labor only', 'luma_labor_only', 'short'], ['labor lang', 'luma_labor_only', 'short'],
    ['track order', 'luma_track_order', 'short'], ['my orders', 'luma_track_order', 'short'],
    ['vague', 'luma_os_vague_ticket', 'short'], ['unpaid', 'luma_os_paid_still_unpaid', 'unpaid'], ['expired', 'luma_os_checkout_expired', 'short'],
    ['minimum', 'luma_os_min_payment', 'short'], ['receipt', 'luma_os_invoice_print', 'short'], ['resibo', 'luma_os_invoice_print', 'short'],
    ['notification', 'luma_os_notification_meaning', 'short'], ['complaint', 'luma_os_complaint_service', 'short'], ['reklamo', 'luma_os_complaint_service', 'short'],
    ['validation', 'luma_os_id_validation', 'short'], ['reschedule', 'luma_os_change_schedule_address', 'short'], ['onsite', 'luma_os_where_work', 'short'],
    ['xyzzy', 'unmatched', 'short'], ['asdf qwer', 'unmatched', 'short'],
] as [$m, $e, $g]) {
    $add($m, $e, $g);
}

/* ───── refund and cancel, every way (safety critical) ───── */
foreach ([
    // other services: explicit
    ['I want a refund for my repair', 'luma_os_refund_how', 'refund'], ['can I get a refund for the service', 'luma_os_refund_rule', 'refund'],
    ['refund po sa repair ko', 'luma_os_refund_how', 'refund'], ['gusto ko pong magpa-refund ng bayad ko sa serbisyo', 'luma_os_refund_how', 'refund'],
    ['pa refund po ng repair payment', 'luma_os_refund_how', 'refund'], ['please refund my invoice', 'luma_os_refund_how', 'refund'],
    ['cancel my ticket', 'luma_os_refund_how', 'cancel'], ['cancel ticket po', 'luma_os_refund_how', 'cancel'], ['gusto kong kanselahin ang service request ko', 'luma_os_refund_how', 'cancel'],
    ['kanselahin ang ticket ko', 'luma_os_refund_how', 'cancel'], ['I would like to cancel my invoice', 'luma_os_refund_how', 'cancel'], ['cancel my repair request', 'luma_os_refund_how', 'cancel'],
    ['ayoko na ituloy ang repair', 'luma_os_refund_how', 'cancel'], ['I changed my mind about the repair', 'luma_os_change_of_mind', 'cancel'], ['nagbago ang isip ko po', 'luma_os_change_of_mind', 'cancel'],
    ['i do not want the repair anymore', 'luma_os_change_of_mind', 'cancel'], ['may iba na akong nahanap', 'luma_os_change_of_mind', 'cancel'],
    ['what is your refund policy for repairs', 'luma_os_refund_rule', 'refund'], ['ano ang patakaran ninyo sa refund ng repair', 'luma_os_refund_rule', 'refund'], ['refund rules ng other services', 'luma_os_refund_rule', 'refund'],
    ['can I cancel after paying', 'luma_os_refund_rule', 'cancel'], ['pwede mag cancel after payment', 'luma_os_refund_rule', 'cancel'], ['when can i cancel my service request', 'luma_os_refund_rule', 'cancel'],
    ['when will I get my refund', 'luma_os_refund_timeline', 'refund'], ['ilang araw ang refund', 'luma_os_refund_timeline', 'refund'], ['kailan darating ang refund ko', 'luma_os_refund_timeline', 'refund'],
    ['how long does a refund take', 'luma_os_refund_timeline', 'refund'], ['how will my refund be sent', 'luma_os_refund_timeline', 'refund'],
    ['paano ko ma-track ang refund', 'luma_os_refund_status', 'refund'], ['track my refund', 'luma_os_refund_status', 'refund'], ['has my refund been sent', 'luma_os_refund_status', 'refund'],
    ['asan na refund ko po', 'luma_os_refund_status', 'refund'], ['nasaan na ang refund ko', 'luma_os_refund_status', 'refund'],
    ['work already started can I still cancel', 'luma_os_refund_after_start', 'refund'], ['nagsimula na ang trabaho pwede pa bang magkansela', 'luma_os_refund_after_start', 'refund'],
    ['is it non refundable once started', 'luma_os_refund_after_start', 'refund'], ['refund after work started po', 'luma_os_refund_after_start', 'refund'],
    ['what does work started mean', 'luma_os_work_started_meaning', 'refund'], ['started na ba ang work ko', 'luma_os_work_started_meaning', 'refund'],
    ['I have not paid yet can I cancel', 'luma_os_cancel_unpaid', 'cancel'], ['hindi pa ako nagbabayad pwede bang magkansela', 'luma_os_cancel_unpaid', 'cancel'], ['can I cancel an unpaid invoice', 'luma_os_cancel_unpaid', 'cancel'],
    ['I paid half can I cancel', 'luma_os_half_paid_cancel', 'cancel'], ['cancel after paying the first half', 'luma_os_half_paid_cancel', 'cancel'], ['refund my first half', 'luma_os_half_paid_cancel', 'refund'],
    ['can I get a partial refund', 'luma_os_partial_refund', 'refund'], ['may partial refund po ba', 'luma_os_partial_refund', 'refund'],
    ['my refund request was declined', 'luma_os_refund_declined', 'refund'], ['bakit declined ang cancellation', 'luma_os_refund_declined', 'refund'], ['refund denied po bakit', 'luma_os_refund_declined', 'refund'],
    ['I entered the wrong gcash number', 'luma_os_wrong_gcash', 'refund'], ['mali yung gcash number ko', 'luma_os_wrong_gcash', 'refund'], ['change gcash number paano', 'luma_os_wrong_gcash', 'refund'],
    ['I sent two cancellation requests', 'luma_os_duplicate_request', 'cancel'], ['can I send another refund request', 'luma_os_duplicate_request', 'refund'], ['duplicate request po ang nasend ko', 'luma_os_duplicate_request', 'cancel'],
    ['I got a cancellation notice', 'luma_os_cancel_notice', 'cancel'], ['bakit na cancel ang request', 'luma_os_cancel_notice', 'cancel'], ['my invoice got cancelled', 'luma_os_cancel_notice', 'cancel'],
    // furniture side stays untouched
    ['can I cancel my order', 'luma_cancel_refund', 'cancel'], ['do you give refunds on orders', 'luma_cancel_refund', 'refund'], ['cancel my custom order', 'luma_ai_design_steps', 'cancel'],
    ['paano mag cancel ng order', 'luma_cancel_refund', 'cancel'], ['pwede ba icancel ang order ko', 'luma_cancel_refund', 'cancel'], ['refund policy for my order', 'luma_cancel_refund', 'refund'],
    // stressed and angry
    ['WHY IS MY REFUND NOT HERE YET!!!', 'luma_os_refund_status', 'angry'], ['I WANT MY MONEY BACK FOR THE REPAIR!!', 'luma_os_refund_how', 'angry'],
    ['grabe ang tagal ng refund ko!!', 'luma_os_refund_status', 'angry'],
] as [$m, $e, $g]) {
    $add($m, $e, $g);
}

/* ───── payment and "I paid but it says unpaid" (safety critical) ───── */
foreach ([
    ['i paid but it still says unpaid', 'luma_os_paid_still_unpaid', 'unpaid'], ['nagbayad na ako pero unpaid pa rin', 'luma_os_paid_still_unpaid', 'unpaid'],
    ['paid na po pero unpaid pa rin ang invoice ko', 'luma_os_paid_still_unpaid', 'unpaid'], ['my payment is not showing', 'luma_os_paid_still_unpaid', 'unpaid'],
    ['payment not reflected', 'luma_os_paid_still_unpaid', 'unpaid'], ['hindi nag reflect ang payment ko', 'luma_os_paid_still_unpaid', 'unpaid'],
    ['bayad na ang invoice ko pero unpaid pa', 'luma_os_paid_still_unpaid', 'unpaid'], ['nag gcash na ako pero unpaid parin', 'luma_os_paid_still_unpaid', 'unpaid'],
    ['i already paid my invoice but it is unpaid', 'luma_os_paid_still_unpaid', 'unpaid'], ['paid already but status not updated', 'luma_os_paid_still_unpaid', 'unpaid'],
    ['I SENT MY GCASH BUT THE INVOICE STILL SAYS UNPAID!!', 'luma_os_paid_still_unpaid', 'unpaid'],
    ['i paid twice', 'luma_os_paid_twice', 'payment'], ['na-charge dalawang beses po', 'luma_os_paid_twice', 'payment'], ['double charge sa gcash ko', 'luma_os_paid_twice', 'payment'],
    ['i was charged twice', 'luma_os_paid_twice', 'payment'], ['dalawang beses akong nagbayad', 'luma_os_paid_twice', 'payment'],
    ['my checkout expired', 'luma_os_checkout_expired', 'payment'], ['expired na yung checkout', 'luma_os_checkout_expired', 'payment'], ['payment link expired na', 'luma_os_checkout_expired', 'payment'],
    ['my gcash payment is stuck', 'luma_os_gcash_stuck', 'payment'], ['pending pa rin ang gcash ko', 'luma_os_gcash_stuck', 'payment'], ['gcash stuck po', 'luma_os_gcash_stuck', 'payment'],
    ['my card was declined', 'luma_os_card_declined', 'payment'], ['card declined po', 'luma_os_card_declined', 'payment'], ['na-decline ang card ko', 'luma_os_card_declined', 'payment'],
    ['how do i pay my invoice', 'luma_os_pay_how', 'payment'], ['paano bayaran ang invoice ko', 'luma_os_pay_how', 'payment'], ['can i pay by gcash for the repair', 'luma_os_pay_how', 'payment'],
    ['pwede bang gcash sa pagpapaayos', 'luma_os_pay_how', 'payment'], ['how to pay for my service', 'luma_os_pay_how', 'payment'],
    ['can i pay half now and half later', 'luma_os_pay_full_half', 'payment'], ['pwede ba hulugan ang service', 'luma_os_pay_full_half', 'payment'], ['half and half payment paano', 'luma_os_pay_full_half', 'payment'],
    ['when can i pay the second half', 'luma_os_second_half', 'payment'], ['kailan ko mababayaran ang natitirang kalahati', 'luma_os_second_half', 'payment'],
    ['what is the minimum payment', 'luma_os_min_payment', 'payment'], ['may minimum ba sa online payment', 'luma_os_min_payment', 'payment'],
    ['can i pay later', 'luma_os_pay_later', 'payment'], ['pwede bang mamaya na ako magbayad', 'luma_os_pay_later', 'payment'],
    ['can i pay at the shop', 'luma_os_pay_at_shop', 'payment'], ['pwede bang cash', 'luma_os_pay_at_shop', 'payment'],
    ['why can you not start without payment', 'luma_os_no_pay_no_work', 'payment'], ['no pay no work po', 'luma_os_no_pay_no_work', 'payment'], ['bakit bayad muna bago gawin', 'luma_os_no_pay_no_work', 'payment'],
    ['how much do i still owe', 'luma_os_pay_status', 'payment'], ['magkano pa ang balanse ko', 'luma_os_pay_status', 'payment'],
    ['what payment methods do you accept', 'luma_payment_methods', 'payment'], ['paano magbayad po', 'luma_payment_methods', 'payment'], ['payment options', 'luma_payment_methods', 'payment'],
] as [$m, $e, $g]) {
    $add($m, $e, $g);
}

/* ───── English, Tagalog, Taglish, typos, long, elderly: the rest of Other Services ───── */
foreach ([
    // what it is / ticket
    ['do you repair furniture', 'luma_os_what_is', 'en'], ['nag-aayos po ba kayo ng furniture', 'luma_os_what_is', 'tl'], ['what do you do for repairs', 'luma_os_what_is', 'en'],
    ['How do I create ticket', 'luma_os_make_ticket', 'en'], ['paano gumawa ng ticket', 'luma_os_make_ticket', 'tl'], ['pano mag create ng tiket', 'luma_os_make_ticket', 'typo'],
    ['how to make a ticket', 'luma_os_make_ticket', 'en'], ['how do i send a service request', 'luma_os_make_ticket', 'en'],
    ['how do i describe the problem', 'luma_os_describe_problem', 'en'], ['paano ilarawan ang problema', 'luma_os_describe_problem', 'tl'],
    ['what happens after i send a ticket', 'luma_os_after_ticket', 'en'], ['ano po ang susunod pagkatapos ng ticket', 'luma_os_after_ticket', 'tl'],
    ['do i need an account to send a request', 'luma_os_need_account', 'en'], ['pwede ba gumawa ng ticket nang hindi naka-log in', 'luma_os_need_account', 'tl'],
    ['is a custom order the same as other services', 'luma_os_not_do', 'en'],
    // where / schedule / fees
    ['do you come to my house', 'luma_os_where_work', 'en'], ['pupunta ba kayo sa bahay namin', 'luma_os_where_work', 'tl'], ['dadalhin ko ba ang furniture sa shop', 'luma_os_where_work', 'tl'],
    ['is there a fee for home visits', 'luma_os_visit_pickup_fee', 'en'], ['magkano ang bayad sa pagkuha ng furniture', 'luma_os_visit_pickup_fee', 'tl'], ['is pickup free', 'luma_os_visit_pickup_fee', 'en'],
    ['how long does a repair take', 'luma_os_duration', 'en'], ['gaano katagal ang pagpapaayos', 'luma_os_duration', 'tl'], ['kailan maaayos ang furniture ko', 'luma_os_duration', 'tl'],
    // invoice
    ['where is my invoice', 'luma_os_invoice_find', 'en'], ['nasaan ang invoice ko', 'luma_os_invoice_find', 'tl'], ['what does paid mean', 'luma_os_invoice_status_words', 'en'],
    ['can i print my invoice', 'luma_os_invoice_print', 'en'], ['official receipt ba ang invoice', 'luma_os_invoice_print', 'tl'], ['what is an invoice', 'luma_os_invoice_what', 'en'],
    // late / complaints
    ['it is taking too long', 'luma_os_taking_too_long', 'angry'], ['ang tagal na po ng repair ko', 'luma_os_taking_too_long', 'angry'], ['hindi pa nagagawa ang trabaho', 'luma_os_taking_too_long', 'tl'],
    ['why is it taking so long', 'luma_os_taking_too_long', 'en'], ['TAGAL NA NG REPAIR KO!!! ILANG LINGGO NA', 'luma_os_taking_too_long', 'angry'], ['nobody came to my house', 'luma_os_no_show', 'en'],
    ['hindi sumipot ang laborer', 'luma_os_no_show', 'tl'], ['the repair was done badly', 'luma_os_complaint_service', 'en'], ['bastos ang staff ninyo', 'luma_os_complaint_service', 'tl'],
    ['the price on my invoice is wrong', 'luma_os_complaint_price', 'en'], ['you charged me extra', 'luma_os_complaint_price', 'en'], ['bakit mas mahal ang invoice kesa usapan', 'luma_os_complaint_price', 'tl'],
    // change / account / notification
    ['i put the wrong phone number in my ticket', 'luma_os_change_contact', 'en'], ['how do i add photos to my ticket', 'luma_os_add_photos', 'en'], ['reschedule my repair', 'luma_os_change_schedule_address', 'en'],
    ['i picked the wrong service type', 'luma_os_wrong_service_type', 'en'], ['i cannot log in', 'luma_os_login_problem', 'en'], ['nakalimutan ko password ko', 'luma_os_login_problem', 'tl'],
    ['why do you need my id', 'luma_os_id_validation', 'en'], ['i cannot find my ticket', 'luma_os_find_ticket', 'en'], ['what does this notification mean', 'luma_os_notification_meaning', 'en'],
    ['ano ang ibig sabihin ng notification na ito', 'luma_os_notification_meaning', 'tl'],
    // elderly-style phrasing, typos, long messages
    ['Hello po, magandang umaga po. Gusto ko po sanang itanong kung paano po magpa-ayos ng lamesa namin na sira na po ang paa. Salamat po.', 'luma_os_make_ticket', 'elder'],
    ['kumusta po, tanong ko lang po kung pwede po bang bayaran ang invoice sa gcash po', 'luma_os_pay_how', 'elder'],
    ['Good afternoon po. Nagbayad na po ako kahapon sa gcash pero hindi pa rin po nagbabago ang invoice, unpaid pa rin po. Ano po ang gagawin ko?', 'luma_os_paid_still_unpaid', 'elder'],
    ['magandang araw po. pwede po bang ikansela ko na lang po ang pagpapagawa kasi nagbago po ang isip ng asawa ko', 'luma_os_change_of_mind', 'elder'],
    ['Sir, maam, good morning. I would like to ask how long the repair of my chair will take because my grandchildren are visiting next month. Thank you very much.', 'luma_os_duration', 'elder'],
    ['hi po, paano po ba mag-send ng request sa repair? wala po akong alam sa computer', 'luma_os_make_ticket', 'elder'],
    ['i want to know if i can still get my money back because the carpenter has not come to my house for two weeks already and i am very disappointed with this service', 'luma_os_no_show', 'long'],
    ['gcsh po ba tinatanggap sa invoice', 'luma_os_pay_how', 'typo'], ['paano bayaran ang invoys ko', 'luma_os_pay_how', 'typo'], ['refnd po pls', 'ask:refund', 'typo'], ['kansel ko na po', 'ask:cancel', 'typo'],
    ['helo po san po ang invoce ko', 'luma_os_invoice_find', 'typo'], ['ano po ang status ng tiket ko', 'luma_os_check_status', 'typo'],
    ['my tikcet number', 'luma_os_check_status', 'typo'],
] as [$m, $e, $g]) {
    $add($m, $e, $g);
}

/* ───── not Other Services at all (40+): furniture, prices, delivery must still route correctly ───── */
foreach ([
    ['hi there', 'luma_greeting', 'notos'], ['who are you?', 'luma_greeting', 'notos'], ['tell me about your business', 'luma_about_business', 'notos'],
    ['are you still active?', 'luma_shop_open', 'notos'], ['what are your store hours', 'luma_shop_open', 'notos'], ['can I see your price list', 'luma_price_list', 'notos'],
    ['what is the price range of doors', 'luma_price_range', 'notos'], ['what is your cheapest table', 'luma_price_range', 'notos'], ['how much do your products cost', 'luma_products_cost', 'notos'],
    ['do you have ready made doors?', 'luma_ready_made', 'notos'], ['do you have ordinary designs of tables', 'luma_ready_made', 'notos'], ['how long to finish a cabinet', 'luma_how_long', 'notos'],
    ['is it ready made or made to order', 'luma_made_to_order', 'notos'], ['when do you start making it', 'luma_made_to_order', 'notos'], ['labor only please', 'luma_labor_only', 'notos'],
    ['I will provide the wood', 'luma_labor_only', 'notos'], ['which locations get free delivery', 'luma_free_delivery', 'notos'], ['how much is the delivery fee', 'luma_delivery_fee', 'notos'],
    ['how does the custom design work step by step', 'luma_ai_design_steps', 'notos'], ['how do I know the dimensions', 'luma_dimensions', 'notos'], ['which wood is best for a bed', 'luma_best_wood', 'notos'],
    ['how much is the deposit', 'luma_deposit_rules', 'notos'], ['where is my order', 'luma_track_order', 'notos'], ['how do I change my phone number', 'luma_change_contact', 'notos'],
    ['is there a warranty', 'luma_warranty', 'notos'], ['how can I contact the shop', 'luma_contact_shop', 'notos'], ['talk to a person', 'luma_contact_shop', 'notos'],
    ['kumusta po', 'luma_greeting', 'notos'], ['sino ka po?', 'luma_greeting', 'notos'], ['bukas pa ba kayo?', 'luma_shop_open', 'notos'], ['gaano katagal gawin ang mesa?', 'luma_how_long', 'notos'],
    ['kailan sisimulan ang paggawa', 'luma_made_to_order', 'notos'], ['magkano po ang pinto?', 'luma_products_cost', 'notos'], ['meron ba kayong pinto', 'luma_ready_made', 'notos'],
    ['libre ba ang delivery?', 'luma_free_delivery', 'notos'], ['anong kahoy ang maganda po', 'luma_best_wood', 'notos'], ['pwede po ba labor lang, ako na sa kahoy', 'luma_labor_only', 'notos'],
    ['saan na po ang order ko', 'luma_track_order', 'notos'], ['paano sukatin ang mesa', 'luma_dimensions', 'notos'], ['paano ang custom design po', 'luma_ai_design_steps', 'notos'],
    ['makausap ang shop', 'luma_contact_shop', 'notos'], ['can I pay in installments for my table order', 'luma_deposit_rules', 'notos'], ['how do I customize a bed', 'luma_ai_design_steps', 'notos'],
    ['what size is the dining table', 'luma_dimensions', 'notos'],
] as [$m, $e, $g]) {
    $add($m, $e, $g);
}

/* ───── reference numbers (20+): the owner is signed in with SI-2026-0001, INV-2026-0001, REF-2026-0001 ───── */
foreach ([
    ['REF-2026-0001', 'ref', 'ref', 'owner'], ['where is REF-2026-0001', 'ref', 'ref', 'owner'], ['ref-2026-0001', 'ref', 'ref', 'owner'], ['REF20260001', 'ref', 'ref', 'owner'],
    ['ref 2026 1', 'ref', 'ref', 'owner'], ['Ref 2026-0001 po', 'ref', 'ref', 'owner'], ['asan na po ang refund REF-2026-0001', 'ref', 'ref', 'owner'], ['status ng REF-2026-0001 please', 'ref', 'ref', 'owner'],
    ['INV-2026-0001', 'ref', 'ref', 'owner'], ['inv2026-1', 'ref', 'ref', 'owner'], ['how much is left on INV-2026-0001', 'ref', 'ref', 'owner'], ['INV 2026 0001', 'ref', 'ref', 'owner'],
    ['SI-2026-0001', 'ref', 'ref', 'owner'], ['si 2026 1', 'ref', 'ref', 'owner'], ['what is the status of SI-2026-0001', 'ref', 'ref', 'owner'], ['kumusta na ang SI-2026-0001', 'ref', 'ref', 'owner'],
    ['REF-2026-0001', 'refnone', 'ref', 'guest'], ['INV-2026-0001', 'refnone', 'ref', 'guest'], ['SI-2026-0001', 'refnone', 'ref', 'guest'],
    ['REF-2026-0001', 'refnone', 'ref', 'other'], ['INV-2026-0001', 'refnone', 'ref', 'other'], ['SI-2026-0001', 'refnone', 'ref', 'other'], ['where is my refund REF-2026-0001', 'refnone', 'ref', 'other'],
    ['REF-2026-9999', 'refnone', 'ref', 'owner'], ['INV-2026-9999', 'refnone', 'ref', 'owner'], ['SI-2026-9999', 'refnone', 'ref', 'owner'],
    ['cancel SI-2026-0001', 'flow', 'ref', 'owner'], ['asan na po ang refund REF-2026-0001', 'ref', 'ref', 'owner'],
] as [$m, $e, $g, $who]) {
    $add($m, $e, $g, $who);
}

return $rows;
