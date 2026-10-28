<?php

/*
 * Exceptions for App\Support\TitleCase (and its JS twin public/assets/js/title-case.js, which reads this file through
 * window.LP_TITLECASE). The helper only changes display text; nothing stored is touched.
 */
return [
    // lowercase unless first or last word
    'small_words' => ['a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'as', 'at', 'by', 'in', 'of', 'on', 'to', 'up', 'with', 'from', 'via', 'vs', 'vs.'],

    // written exactly like this, whatever the input (units, brands, words with inner capitals already survive on their own)
    'keep' => ['cm', 'mm', 'kg', 'm', 'ft', 'in.', 'sq.ft', 'pcs', 'x', 'stripe'],
    'keep_as' => ['gcash' => 'GCash', 'paymongo' => 'PayMongo', 'lp' => 'LP', 'id' => 'ID', 'pdf' => 'PDF', 'faq' => 'FAQ', 'faqs' => 'FAQs', 'ph' => 'PH', 'otp' => 'OTP', 'qr' => 'QR', 'kyc' => 'KYC'],
];
