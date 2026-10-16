<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Terms and Conditions, version 2 (draft for owner and lawyer review).
 *
 * Insert-only and idempotent: it adds agreement_policies version 2 when that
 * row is missing and touches nothing else. Version 1 and every existing
 * agreement, snapshot and hash are never read or changed here. New quotes
 * pick the highest version, so they use this text; agreements that already
 * exist keep the version and the text snapshot they were accepted with.
 *
 * Format of the text (read by App\Support\PolicyText): "## Heading" starts a
 * section, a blank line separates paragraphs, lines starting with "- " are a
 * list. {contact_phone} and {contact_email} are filled from Contact Settings
 * when the text is shown. [TO CONFIRM] marks a rule the owner must decide.
 * The text is frozen here: once on a live database it is never edited, a
 * wording change is a new version.
 */
return new class extends Migration {
    private const VERSION = 2;

    public function up(): void
    {
        if (DB::table('agreement_policies')->where('version', self::VERSION)->exists()) {
            return;
        }

        DB::table('agreement_policies')->insert([
            'version'    => self::VERSION,
            'title'      => 'Terms and Conditions',
            'body'       => $this->body(),
            'created_at' => now(),
        ]);
    }

    /**
     * Removes only version 2, and only while no agreement points at it. Version 1
     * is never touched. If an agreement already uses version 2 the row stays,
     * because deleting it would break that agreement's record.
     */
    public function down(): void
    {
        $used = DB::table('design_agreements')->where('policy_version', self::VERSION)->exists();

        if (!$used) {
            DB::table('agreement_policies')->where('version', self::VERSION)->delete();
        }
    }

    private function body(): string
    {
        return <<<'TEXT'
## About these terms

These terms apply to every made-to-order furniture agreement between you and LP Furniture. They sit together with the agreement you accept, which lists your design, size, materials, finish, price and payment schedule. If something in your agreement is different from these terms, the agreement is the one that applies.

## What we will do

- Build your furniture to the design, dimensions, materials and finish written in your agreement.
- Give you an expected ready date. It is an estimate counted in working days (Monday to Saturday) from the date your deposit is received.
- Keep you updated here in your account when your order is confirmed, goes into production, is ready, and is out for delivery.
- Tell you as early as we can if something may delay your order.

## What we will not do

- We do not accept changes to the design, size, materials or finish once production has started. See "Changes to your order" below.
- We do not guarantee that the finished piece matches an AI-generated image exactly. The image is a reference for the look and style. The size, materials and finish in your agreement are what we build to, and natural wood varies in grain and colour.
- We do not accept returns or exchanges of made-to-order furniture because you changed your mind, because each piece is made specifically for you.

## When your order becomes binding

Your order is confirmed once you have accepted the agreement and we have received your deposit. From that point we order materials and plan production for you, so the order cannot be cancelled freely. The cancellation rules below explain what happens if you or we need to cancel.

You have until the payment date shown in your agreement to pay the deposit. If the deposit is not paid by then, the agreement expires and nothing is owed.

## Deposit and balance payments

- The deposit is at least 50 percent of the total price, including any delivery fee. Your agreement shows the exact amount.
- The balance is paid on the dates in your payment schedule, and in any case before we deliver or release the furniture.
- You can pay by card (Stripe), by GCash, or in person where we accept it. We record every payment against your order.
- We do not release or deliver the furniture until the full balance is paid. [TO CONFIRM: whether a small remaining balance may be paid on delivery.]

## Cancellation and refunds

Before production starts: you may cancel and we will refund your deposit, less a cancellation fee of [TO CONFIRM: percentage or amount] to cover materials already ordered and the work we have already done. Refunds are returned by the same method you paid with where possible, within [TO CONFIRM: number] working days.

After production starts: we cannot refund the deposit, because the materials are cut and the work has begun. [TO CONFIRM: whether any part of the deposit may be credited or refunded, and the percentage.]

If your furniture arrives damaged or with a defect: tell us within [TO CONFIRM: number] days of delivery or pickup, with photos. We will repair or replace the piece at no cost to you. If we cannot fix it, we will refund the amount you paid for that piece.

If we are late: if we miss the expected ready date by more than [TO CONFIRM: number] working days for reasons within our control, you may choose to keep waiting or to cancel with a full refund of what you paid. [TO CONFIRM: any compensation for delay, such as a discount, and its amount.]

## Changes to your order

An agreement can only be changed in writing through our team, and only before production starts. Any change may change the price and the expected ready date, and we will tell you both before you decide. A change is final only when you accept the updated agreement. Requests made by phone or message alone are not changes until our team has recorded them.

## Delivery and pickup

- If you chose delivery, we deliver to the address in your agreement. The delivery fee is listed there. [TO CONFIRM: delivery areas and what happens if the address is outside them.]
- If you chose pickup, we will contact you when your furniture is ready and arrange a pickup time at our shop.
- Please inspect the furniture when you receive it and tell us about any damage right away.
- If you are unable to receive or pick up your furniture within [TO CONFIRM: number] days of the ready date, we may charge a storage fee of [TO CONFIRM: amount].

## Defects and warranty

We repair defects in workmanship and materials for [TO CONFIRM: warranty length, for example 6 months or 1 year] from the date of delivery or pickup. The warranty does not cover normal wear, damage from misuse, moisture, direct heat, moving the furniture, repairs by someone else, or natural changes in wood such as small changes in colour and grain.

## Your responsibilities

- Give us accurate contact details and keep your phone reachable, so we can call you about your order.
- Check that the measurements and details in your agreement are correct before you accept it. We build to what the agreement says.
- Make sure the delivery address is correct and that someone can receive the furniture.
- Pay on the dates in your schedule.

## Your data and privacy

We collect your name, email, phone number, delivery address, the design you request and your payment records. We use them to prepare your quote and agreement, build and deliver your order, contact you about it, and keep records of what was agreed. Card payments are handled by Stripe and GCash payments by PayMongo; we do not see or store your card or GCash credentials.

We keep your agreement and order records for as long as we need them for warranty, accounting and legal reasons, and we do not sell your information. We handle your personal data in line with the Data Privacy Act of 2012. You may ask to see or correct your data, or ask us to delete what we are not required to keep, by contacting us below. [TO CONFIRM: retention period and the name of the person responsible for data privacy.]

## Contact us

If you have a question about these terms or your order, call us at {contact_phone} or email {contact_email}.
TEXT;
    }
};
