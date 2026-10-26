<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Who receives an order, on which number and at which address. Every way of placing an order (cart, buy now, an accepted
 * custom agreement) goes through here, so the rule is enforced on the server and not only in the page:
 * a phone and a complete structured address are required, and the order keeps a snapshot of what was used.
 *
 * Form fields: recipient_name, phone_country, contact_phone, address_mode (saved|new), addr_province_id, addr_city_id,
 * addr_barangay_id, addr_street, addr_postal_code, addr_landmark, keep_address (yes|no).
 * Anything left out falls back to the customer's account (name, phone, saved address).
 */
final class OrderContact
{
    public const PREFIX = 'addr_';

    /**
     * A pickup order (the customer collects at our shop) needs the recipient and a valid phone but no address, and keeps
     * no address snapshot. A delivery order needs the full structured address.
     *
     * @return array{recipient:string,phone:string,country:string,address:?array,save_address:bool,use_saved:bool,snapshot:?array,delivery:bool}
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, User $user, bool $delivery = true): array
    {
        $in = $request->all();
        $errors = [];

        // recipient
        $recipient = TextSanitizer::line(is_string($in['recipient_name'] ?? null) ? $in['recipient_name'] : '');
        if ($recipient === '') {
            $recipient = $user->display_name;
        }
        $nameCheck = Validator::make(['recipient_name' => $recipient], ['recipient_name' => array_merge(
            DeliveryAddress::textRules(100, true, DeliveryAddress::NAME_PATTERN)
        )], [
            'recipient_name.required' => 'Enter the recipient name.',
            'recipient_name.regex'    => 'Use letters only. No numbers or symbols.',
        ]);
        if ($nameCheck->fails()) {
            $errors['recipient_name'] = $nameCheck->errors()->first('recipient_name');
        }

        // phone
        $phoneInput = isset($in['contact_phone']) && is_string($in['contact_phone']) ? trim($in['contact_phone']) : '';
        if ($phoneInput === '') {
            $e164 = PhoneNumber::forUser($user);
            [$country] = PhoneNumber::split($e164);
            $phone = $e164 ? ['ok' => true, 'e164' => $e164, 'country' => $user->phone_country ?: $country] : ['ok' => false, 'message' => 'Enter your phone number. We call you about your orders.'];
        } else {
            $phone = PhoneNumber::parse($phoneInput, is_string($in['phone_country'] ?? null) ? $in['phone_country'] : PhoneNumber::DEFAULT_COUNTRY);
        }
        if (!$phone['ok']) {
            $errors['contact_phone'] = $phone['message'];
        }

        // address
        $saved = DeliveryAddress::fromUser($user);
        $asked = $in['address_mode'] ?? null;
        $mode = !$delivery ? 'none' : ($asked === 'new' ? 'new' : ($asked === 'saved' ? 'saved' : ($saved ? 'saved' : 'new')));
        $address = null;
        $useSaved = false;
        $save = false;

        if ($mode === 'none') {
            // pickup: nothing about an address is read, saved or stored
        } elseif ($mode === 'saved') {
            if (!$saved) {
                $errors[self::PREFIX . 'province_id'] = 'Add your delivery address to continue.';
            } else {
                $address = $saved;
                $useSaved = true;
            }
        } else {
            try {
                $address = DeliveryAddress::validate($in, self::PREFIX);
                $save = ($in['keep_address'] ?? 'no') === 'yes';
            } catch (ValidationException $e) {
                foreach ($e->errors() as $k => $msgs) {
                    $errors[$k] = $msgs[0];
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'recipient'    => $recipient,
            'phone'        => $phone['e164'],
            'country'      => $phone['country'],
            'address'      => $address,
            'save_address' => $save,
            'use_saved'    => $useSaved,
            'snapshot'     => $delivery ? DeliveryAddress::snapshot($address, $recipient, $phone['e164']) : null,
            'delivery'     => $delivery,
        ];
    }

    /** Contact taken only from the account (for code that places an order without a form). Null when the account is not ready. */
    public static function fromAccount(User $user, bool $delivery = true): ?array
    {
        $e164 = PhoneNumber::forUser($user);
        $address = $delivery ? DeliveryAddress::fromUser($user) : null;
        if (!$e164 || ($delivery && !$address)) {
            return null;
        }
        [$country] = PhoneNumber::split($e164);

        return [
            'recipient'    => $user->display_name,
            'phone'        => $e164,
            'country'      => $user->phone_country ?: $country,
            'address'      => $address,
            'save_address' => false,
            'use_saved'    => true,
            'snapshot'     => $delivery ? DeliveryAddress::snapshot($address, $user->display_name, $e164) : null,
            'delivery'     => $delivery,
        ];
    }

    /** Called inside the order transaction, with the customer row locked: keeps the address and fills in a missing phone. */
    public static function persist(User $user, array $contact): void
    {
        if ($contact['delivery'] && $contact['save_address']) {
            DeliveryAddress::saveFor($user, $contact['address']);
        }
        if (!PhoneNumber::forUser($user)) {
            $parsed = PhoneNumber::parse($contact['phone'], $contact['country']);
            if ($parsed['ok']) {
                $user->forceFill([
                    'phone_e164' => $parsed['e164'], 'phone_country' => $parsed['country'], 'phone_number' => $parsed['legacy'],
                ])->save();
            }
        }
    }
}
