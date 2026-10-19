<?php
// Dev tool: php tests/Luma/holdout.php -- messages written AFTER the keywords were tuned and never used to tune them
// (matcher only, no database). Reports an honest pass rate; "ask:" = a context-free word that must be asked about.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\ChatbotIntent; use App\Support\Luma\{DefaultIntents, IntentMatcher};
$intents = collect(); $id = 1;
foreach (DefaultIntents::all() as $name => $def) { $i = new ChatbotIntent(['category' => $name, 'keywords' => $def['keywords'], 'is_active' => true]); $i->id = $id++; $intents->push($i); }
$cases = [
 ['how can i request a repair for my cabinet door', 'luma_os_make_ticket'], ['saan ko ilalagay ang detalye ng sira sa ticket', 'luma_os_describe_problem'],
 ['do i get a call after i submit', 'luma_os_after_ticket'], ['pwede po bang kunin nyo na lang sa bahay namin yung upuan', 'luma_os_where_work'],
 ['may dagdag ba kapag pupuntahan nyo ako', 'luma_os_visit_pickup_fee'], ['ilang araw po bago matapos ang pagpapaayos ng aparador', 'luma_os_duration'],
 ['hindi ko makita ang invoice sa account ko', 'luma_os_invoice_find'], ['can i get a printed copy of the invoice', 'luma_os_invoice_print'],
 ['pwede ba akong magbayad ng gcash para sa repair', 'luma_os_pay_how'], ['gusto ko sana kalahati muna ang bayaran', 'luma_os_pay_full_half'],
 ['nagbayad ako kanina pero walang nagbago sa invoice, unpaid pa rin', 'luma_os_paid_still_unpaid'], ['nabawasan dalawang beses ang gcash ko', 'luma_os_paid_twice'],
 ['ayaw gumana ng gcash payment page', 'luma_os_gcash_stuck'], ['bakit ayaw ng card ko', 'luma_os_card_declined'],
 ['pwede po bang sa shop na lang ako magbabayad ng cash', 'luma_os_pay_at_shop'], ['bakit hindi pa kayo nagsisimula, nagbayad na naman ako', 'luma_os_no_pay_no_work'],
 ['what happens to my money if i cancel the repair', 'luma_os_refund_rule'], ['paano kung nasimulan na ang trabaho tapos magkansela ako', 'luma_os_refund_after_start'],
 ['how many days before the refund arrives', 'luma_os_refund_timeline'], ['wala pa rin akong natatanggap na refund', 'luma_os_refund_status'],
 ['maling number ang naibigay ko sa gcash refund', 'luma_os_wrong_gcash'], ['ayoko na ituloy ang pagpapagawa', 'luma_os_refund_how'],
 ['the carpenter never showed up', 'luma_os_no_show'], ['sobrang bagal ng repair ko', 'luma_os_taking_too_long'],
 ['napasama pa lalo ang upuan ko pagkatapos ng repair', 'luma_os_complaint_service'], ['mas mataas ang singil sa invoice kaysa sa sinabi nyo sa phone', 'luma_os_complaint_price'],
 ['paano ko babaguhin ang email sa ticket ko', 'luma_os_change_contact'], ['pwede ba akong mag-upload ng litrato ng sira kasunod', 'luma_os_add_photos'],
 ['nakalimutan ko ang password ko', 'luma_os_login_problem'], ['bakit hindi pa validated ang id ko', 'luma_os_id_validation'],
 ['saan makikita ang mga ticket ko', 'luma_os_find_ticket'], ['ano ang ibig sabihin ng message na dumating sa akin tungkol sa request', 'luma_os_notification_meaning'],
 ['do you make new beds', 'luma_ready_made'], ['how much is a mahogany table', 'luma_products_cost'], ['saan kayo nagde-deliver', 'luma_free_delivery'],
 ['kailan bukas ang shop', 'luma_shop_open'], ['what is the best wood for outdoor chairs', 'luma_best_wood'], ['paano ko malalaman ang sukat ng pinto', 'luma_dimensions'],
];
$ok = 0; foreach ($cases as [$m, $e]) { $h = IntentMatcher::best($m, $intents); $got = $h['intent']->category ?? '(none)'; if ($got === $e) $ok++; else printf("MISS %-70s want %-34s got %s\n", '"'.$m.'"', $e, $got); }
printf("%d of %d (%.1f%%)\n", $ok, count($cases), 100 * $ok / count($cases));
