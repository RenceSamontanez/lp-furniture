<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiDesign;
use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Support\CustomOrderPipeline;
use App\Models\Notification;
use App\Models\User;
use App\Rules\FurnitureTopic;
use App\Rules\NoLinkOrEmail;
use App\Rules\PhilippineMobile;
use App\Models\UnansweredIdea;
use App\Support\StyleChoices;
use App\Support\TextSanitizer;
use App\Support\DimensionsFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomDesignController extends Controller
{
    // Counted against CustomDesignRequest rows created today, not a
    // separate tracking table — every submit() call creates exactly
    // one row and dispatches exactly one generation job, so the rows
    // themselves are already an accurate, already-existing record of
    // "generations used today." Resets at midnight in the app's
    // configured timezone (Asia/Manila) — server time, per-visitor
    // browser timezone isn't tracked anywhere in this schema and
    // would be disproportionate to add just for this.
    //
    // A failed attempt doesn't cost one of the 3 real generations —
    // only requests that didn't end up 'rejected' count toward that.
    // DAILY_ATTEMPT_LIMIT is a separate, higher hard ceiling on TOTAL
    // rows created today regardless of outcome, so repeated failures
    // (or someone intentionally burning attempts) can't turn an
    // unlimited number of tries into an unlimited number of provider
    // calls on our end.
    private const DAILY_GENERATION_LIMIT = 3;
    private const DAILY_ATTEMPT_LIMIT = 6;

    private function generationsUsedToday(): int
    {
        return CustomDesignRequest::where('user_id', Auth::id())
            ->whereDate('created_at', today())
            ->where('status', '!=', 'rejected')
            ->count();
    }

    private function attemptsUsedToday(): int
    {
        return CustomDesignRequest::where('user_id', Auth::id())
            ->whereDate('created_at', today())
            ->count();
    }

    public function index()
    {
        // Lazy staleness check — see PART 2 of the reliability fix.
        // Runs here (and in gallery()/show()) so stuck 'pending' rows
        // get cleaned up even with no scheduler wired up.
        CustomDesignRequest::failStale();
        app(CustomOrderPipeline::class)->expireStaleQuotes();
        app(CustomOrderPipeline::class)->expireUnpaidAgreements();

        $myRequests =CustomDesignRequest::where('user_id', Auth::id())
            ->with('suggestions')
            ->latest()
            ->get();

        // Most recent request with an image that's actually loadable
        // — shown as the hero preview. Not just "has suggestion rows":
        // see AiDesignSuggestion::isLoadable().
        $latestWithImages = $myRequests->first(fn($r) => $r->hasLoadableSuggestion());
        $latestImage = null;
        if ($latestWithImages) {
            $loadable = $latestWithImages->loadableSuggestions();
            $latestImage = $loadable->firstWhere('is_selected', true) ?? $loadable->first();
        }

        // Small history strip — up to 6 most recent OTHER requests with loadable images
        $historyThumbs = $myRequests
            ->filter(fn($r) => $r->hasLoadableSuggestion() && (!$latestWithImages || $r->id !== $latestWithImages->id))
            ->take(6)
            ->map(function ($r) {
                $loadable = $r->loadableSuggestions();
                $img = $loadable->firstWhere('is_selected', true) ?? $loadable->first();
                return ['request' => $r, 'image' => $img];
            });

        $totalWithImages = $myRequests->filter(fn($r) => $r->hasLoadableSuggestion())->count();

        $dailyUsed  = $this->generationsUsedToday();
        $dailyLimit = self::DAILY_GENERATION_LIMIT;

        $recentReady = CustomDesignRequest::recentReadyFor((int) Auth::id(), 3);

        // "Try again" on a failed history row links here with the
        // original prompt as a query string, prefilling the form
        // instead of resubmitting the dead request.
        $prefillPrompt = request()->query('prompt');

        return view('design.index', compact(
            'myRequests', 'latestWithImages', 'latestImage', 'historyThumbs', 'totalWithImages',
            'dailyUsed', 'dailyLimit', 'prefillPrompt', 'recentReady'
        ) + ['extraTypes' => \App\Models\ExtraFurnitureType::chips()]);
    }

    public function submit(Request $request)
    {
        // Hard ceiling first — this one counts failures too, so it's
        // checked regardless of how many of today's attempts actually
        // succeeded.
        if ($this->attemptsUsedToday() >= self::DAILY_ATTEMPT_LIMIT) {
            return back()->withInput()->withErrors([
                'prompt' => "You've reached today's limit of " . self::DAILY_ATTEMPT_LIMIT . " design attempts. Your limit resets at midnight — come back tomorrow.",
            ]);
        }

        // Daily generation cap — checked before validation/dispatch
        // so an over-limit request never reaches the queue, same
        // reasoning as the furniture-topic check. Failed attempts
        // don't count against this one.
        if ($this->generationsUsedToday() >= self::DAILY_GENERATION_LIMIT) {
            return back()->withInput()->withErrors([
                'prompt' => "You've used all " . self::DAILY_GENERATION_LIMIT . " design generations for today. Your limit resets at midnight — come back tomorrow for more!",
            ]);
        }

        // Free text is cleaned first (tags out, spaces tidied) so the rules below judge what will really be stored.
        $request->merge([
            'prompt'               => TextSanitizer::block($request->input('prompt')),
            'additional_info'      => TextSanitizer::block($request->input('additional_info')),
            'furniture_type_other' => TextSanitizer::line($request->input('furniture_type_other')),
            'wood_special_details' => TextSanitizer::line($request->input('wood_special_details')),
        ]);

        $request->validate([
            'prompt'             => ['required', 'string', 'min:15', 'max:1000', $this->atLeastWords(3), new NoLinkOrEmail(), new FurnitureTopic()],
            'furniture_type'       => ['required', 'string', \Illuminate\Validation\Rule::in(\App\Support\FurnitureTypes::LIST)],
            'furniture_type_other' => ['nullable', 'required_if:furniture_type,' . \App\Support\FurnitureTypes::OTHER, 'string', 'min:2', 'max:' . \App\Support\FurnitureTypes::OTHER_MAX, new NoLinkOrEmail()],
            'preferred_material'   => ['required', 'string', \Illuminate\Validation\Rule::in(\App\Support\WoodChoices::LIST)],
            'wood_special_details' => ['nullable', 'required_if:preferred_material,' . \App\Support\WoodChoices::SPECIAL, 'string', 'min:2', 'max:' . \App\Support\WoodChoices::DETAILS_MAX, new NoLinkOrEmail()],
            'wood_supply'          => ['nullable', 'required_if:preferred_material,' . \App\Support\WoodChoices::SPECIAL, \Illuminate\Validation\Rule::in(array_keys(\App\Support\WoodChoices::SUPPLY))],
            'style_preference'   => ['nullable', 'string', \Illuminate\Validation\Rule::in(StyleChoices::LIST)],
            'dimension_length'   => ['nullable'],
            'dimension_width'    => ['nullable'],
            'dimension_height'   => ['nullable'],
            'dimension_unit'     => ['nullable', 'string', 'max:4'],
            'additional_info'    => ['nullable', 'string', 'max:1000', new NoLinkOrEmail()],
            // content-sniffed type (not the file name), JPEG or PNG only, 5 MB at most
            'reference_image'    => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'],
        ], [
            'reference_image.image'     => 'The reference photo must be a JPG or PNG picture.',
            'reference_image.mimes'     => 'The reference photo must be a JPG or PNG picture.',
            'reference_image.mimetypes' => 'The reference photo must be a JPG or PNG picture.',
            'reference_image.max'       => 'The reference photo is over 5 MB. Choose a smaller one.',
            'reference_image.uploaded'  => 'The reference photo could not be uploaded. It may be over the size limit. Try a smaller JPG or PNG.',
            'prompt.min'                => 'Add a little more detail so we can draw it (at least 15 characters).',
            'furniture_type_other.min'  => 'Tell us what you want built (at least 2 letters).',
            'wood_special_details.min'  => 'Type the wood you want (at least 2 letters).',
        ]);

        // Optional Length x Width x Height. Converted to centimetres here, never trusted from the page.
        $size = \App\Support\Dimensions::fromInput(
            $request->input('dimension_length'), $request->input('dimension_width'),
            $request->input('dimension_height'), $request->input('dimension_unit')
        );
        if ($size['errors'] !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($size['errors']);
        }

        $description = trim($request->prompt);
        if ($request->filled('additional_info')) {
            $description .= "\n\nAdditional details: " . trim($request->additional_info);
        }

        $refImagePath = null;
        if ($request->hasFile('reference_image')) {
            $refImagePath = \App\Support\ReferencePhoto::store($request->file('reference_image'), (int) Auth::id());
        }

        // Same optional 3-field Width/Depth/Height (cm) pattern as the
        // admin product form, combined via the same shared helper —
        // unlike a product listing, a customer describing a custom
        // piece may not know exact dimensions yet, so all three stay
        // nullable and simply produce no `dimensions` value if unset.
        // The old text column keeps "L x W x H cm" so older screens and the AI prompt read it as before.
        $dimensions = $size['values']
            ? DimensionsFormatter::combine($size['values']['length_cm'], $size['values']['width_cm'], $size['values']['height_cm'])
            : null;

        $designRequest = CustomDesignRequest::create([
            'user_id'            => Auth::id(),
            'title'              => \App\Support\CustomOrderName::for(Auth::user()),
            'furniture_type'     => $request->furniture_type,
            'furniture_type_other' => \App\Support\FurnitureTypes::needsText($request->furniture_type) ? trim((string) $request->furniture_type_other) : null,
            'description'        => $description,
            'preferred_material' => $request->preferred_material,
            'wood_special_details' => \App\Support\WoodChoices::isSpecial($request->preferred_material) ? trim((string) $request->wood_special_details) : null,
            'wood_supply'        => \App\Support\WoodChoices::isSpecial($request->preferred_material) ? $request->wood_supply : null,
            'dimensions'         => $dimensions,
            'dim_length_cm'      => $size['values']['length_cm'] ?? null,
            'dim_width_cm'       => $size['values']['width_cm'] ?? null,
            'dim_height_cm'      => $size['values']['height_cm'] ?? null,
            'dimension_unit'     => $size['values']['unit'] ?? null,
            'style_preference'   => $request->filled('style_preference') ? $request->style_preference : null,
            'reference_image'    => $refImagePath,
            'status'             => 'pending',
        ]);

        $this->noteUnansweredIdeas($designRequest, $request->prompt);

        GenerateAiDesign::dispatch($designRequest->id);

        return redirect()->route('design.show', $designRequest->id)
            ->with('success', 'Your design request has been submitted!');
    }

    private function atLeastWords(int $min): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($min) {
            if (is_string($value) && TextSanitizer::wordCount($value) < $min) {
                $fail("Describe it in at least {$min} words so we can draw it.");
            }
        };
    }

    /**
     * Keeps what the customer typed that we had no answer for (an "Other" type, an "Other wood", odd description phrases)
     * for the team to review. Never blocks a submission.
     */
    private function noteUnansweredIdeas(CustomDesignRequest $designRequest, string $description): void
    {
        try {
            if (\App\Support\FurnitureTypes::needsText($designRequest->furniture_type) && $designRequest->furniture_type_other) {
                UnansweredIdea::record(UnansweredIdea::KIND_TYPE, $designRequest->furniture_type_other);
            }
            if (\App\Support\WoodChoices::isSpecial($designRequest->preferred_material) && $designRequest->wood_special_details) {
                UnansweredIdea::record(UnansweredIdea::KIND_WOOD, $designRequest->wood_special_details);
            }
            if (config('customize.capture_idea_phrases')) {
                foreach (\App\Support\IdeaClauses::unmatched($description) as $phrase) {
                    UnansweredIdea::record(UnansweredIdea::KIND_IDEA, $phrase);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * What the page shows under "Something else", "Other wood" and the description: the team's written answer,
     * if there is one. Read only; nothing is saved here (the request is counted when it is submitted).
     */
    public function ideaAnswer(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', \Illuminate\Validation\Rule::in(UnansweredIdea::KINDS)],
            'text' => ['required', 'string', 'max:1000'],
        ]);

        $text = TextSanitizer::line($data['text']);
        $row = $data['kind'] === UnansweredIdea::KIND_IDEA
            ? UnansweredIdea::knownWithin($text)
            : UnansweredIdea::knownFor($data['kind'], $text);

        return response()->json([
            'known'  => $row !== null,
            'answer' => $row?->answer,
            'matched' => $row?->text,
        ])->header('Cache-Control', 'no-store');
    }

    /** The customer's own reference photo, owner-checked. Never a public URL. */
    public function referencePhoto($id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        return \App\Support\ReferencePhotoResponse::for($designRequest, false);
    }

    public function show($id)
    {
        CustomDesignRequest::failStale();
        app(CustomOrderPipeline::class)->expireStaleQuotes();
        app(CustomOrderPipeline::class)->expireUnpaidAgreements();

        $designRequest = CustomDesignRequest::where('user_id', Auth::id())
            ->with('suggestions')
            ->findOrFail($id);

        // Read-only quote for the customer: the live sent quote, or the
        // one that just expired. Drafts are never visible to them.
        $quote = null;
        if (in_array($designRequest->pipeline_status, ['quoted', 'expired'], true)) {
            $quote = CustomQuote::where('request_id', $designRequest->id)
                ->whereIn('status', ['sent', 'expired'])
                ->orderByDesc('version')
                ->first();
        }

        // The agreement record, once the customer has accepted. Owner-scoped
        // through the request lookup above.
        $agreement = in_array($designRequest->pipeline_status, ['accepted', 'ordered'], true)
            ? $designRequest->agreements()->orderByDesc('id')->first()
            : null;

        $timeline = $designRequest->pipeline_status !== null
            ? app(\App\Support\CustomOrderTimeline::class)->forCustomer($designRequest)
            : [];

        return view('design.show', compact('designRequest', 'quote', 'agreement', 'timeline'));
    }

    /* ════════════════════════════════
       PICK A CONCEPT — no longer sends anything by itself. It only
       checks the concept belongs to this request and opens the
       "Send to our team" form (sendForm/sendToTeam below).
    ════════════════════════════════ */
    public function selectDesign(Request $request, $id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        if ($designRequest->isSentToTeam()) {
            return $this->alreadySent($designRequest);
        }

        $request->validate(['suggestion_id' => 'required|integer']);
        $suggestion = $this->sendableSuggestion($designRequest, (int) $request->suggestion_id);
        abort_unless($suggestion, 404);

        return redirect()->route('design.sendForm', [$designRequest->id, 'suggestion' => $suggestion->id]);
    }

    /* ════════════════════════════════
       SEND TO TEAM WITHOUT AN IMAGE — PART 3 of the provider-chain
       rescue. Only available on a request generation genuinely
       failed for (status 'rejected'). Opens the same form as
       selectDesign(), just with no concept attached. Keeps the
       prompt/furniture details exactly as submitted.
    ════════════════════════════════ */
    public function sendWithoutImage($id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        if ($designRequest->isSentToTeam()) {
            return $this->alreadySent($designRequest);
        }
        abort_unless($designRequest->status === 'rejected', 404);

        return redirect()->route('design.sendForm', $designRequest->id);
    }

    /* ════════════════════════════════
       "SEND TO OUR TEAM" FORM — contact details, call window and
       build mode. With ?suggestion= it sends that concept; without
       it, it is the no-image path (failed generation only).
    ════════════════════════════════ */
    public function sendForm(Request $request, $id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        if ($designRequest->isSentToTeam()) {
            return $this->alreadySent($designRequest);
        }

        $suggestion = null;
        if ($request->filled('suggestion')) {
            $suggestion = $this->sendableSuggestion($designRequest, (int) $request->query('suggestion'));
            abort_unless($suggestion, 404);
        } elseif ($designRequest->isLegacyUnsent()) {
            // old one-click request: use the concept it already had selected, if any
            $suggestion = $this->legacySuggestion($designRequest);
        } else {
            abort_unless($designRequest->status === 'rejected', 404);
        }

        return view('design.send', $this->formData($designRequest, $suggestion, false));
    }

    // The same form, prefilled, while no quote has been sent yet.
    public function editForm($id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->with('selectedSuggestion')->findOrFail($id);

        if (!$designRequest->isEditableByCustomer()) {
            return redirect()->route('design.show', $designRequest->id)
                ->with('info', 'This request can no longer be edited because a quote has been sent. Please contact us for changes.');
        }

        return view('design.send', $this->formData($designRequest, $designRequest->selectedSuggestion, true));
    }

    public function updateRequest(Request $request, $id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        $data = $request->validate($this->contactRules(), $this->contactMessages());
        $user = Auth::user();

        $result = DB::transaction(function () use ($designRequest, $data, $user, $request) {
            $locked = CustomDesignRequest::where('user_id', $user->id)->lockForUpdate()->findOrFail($designRequest->id);

            if (!$locked->isEditableByCustomer()) {
                return false;
            }

            $locked->forceFill([
                'contact_phone'         => PhilippineMobile::normalize($data['contact_phone']),
                'contact_email'         => $data['contact_email'],
                'preferred_call_window' => $data['preferred_call_window'],
                'request_mode'          => $data['request_mode'],
                'modification_notes'    => $this->cleanNotes($data),
                'lock_version'          => (int) $locked->lock_version + 1,
            ])->save();

            $this->rememberContact($user, $request, $locked);

            CustomOrderEvent::create([
                'request_id'  => $locked->id,
                'type'        => 'request_updated',
                'actor_type'  => CustomOrderEvent::ACTOR_CUSTOMER,
                'actor_id'    => $user->id,
                'data'        => [
                    'request_mode' => $locked->request_mode,
                    'preferred_call_window' => $locked->preferred_call_window,
                    'status' => $locked->pipeline_status,
                ],
                'occurred_at' => now(),
            ]);

            $name = \App\Support\NameFormat::proper(trim($user->first_name . ' ' . $user->last_name));
            foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
                $updated = \App\Support\NotificationTemplates::render('admin_request_updated', [
                    'order' => $locked->order_name, 'customer' => $name,
                ]);
                Notification::create([
                    'user_id' => $adminId,
                    'title'   => $updated['title'],
                    'message' => $updated['message'],
                    'type'    => 'custom_order',
                    'url'     => route('admin.custom-orders.show', $locked->id),
                    'is_read' => false,
                ]);
            }

            return true;
        });

        if (!$result) {
            return redirect()->route('design.show', $designRequest->id)
                ->with('info', 'This request can no longer be edited because a quote has been sent.');
        }

        return redirect()->route('design.show', $designRequest->id)->with('success', 'Your request was updated.');
    }

    /* ── shared by the send and edit forms ── */

    private function contactRules(): array
    {
        return [
            'suggestion_id'         => 'nullable|integer',
            'contact_phone'         => ['required', 'string', 'max:20', new PhilippineMobile()],
            'contact_email'         => 'required|email:rfc|max:150',
            'preferred_call_window' => 'required|in:' . implode(',', array_keys(CustomDesignRequest::CALL_WINDOWS)),
            'request_mode'          => 'required|in:' . implode(',', array_keys(CustomDesignRequest::REQUEST_MODES)),
            'modification_notes'    => 'required_if:request_mode,modified|nullable|string|max:2000',
            'save_contact'          => 'nullable|boolean',
            'use_different'         => 'nullable|boolean',
            'update_saved'          => 'nullable|boolean',
        ];
    }

    private function contactMessages(): array
    {
        return [
            'contact_phone.required'         => 'Please enter a mobile number we can call.',
            'contact_email.required'         => 'Please enter your email address.',
            'contact_email.email'            => 'Please enter a valid email address.',
            'modification_notes.required_if' => 'Please describe the changes you would like.',
            'preferred_call_window.required' => 'Please choose when we should call you.',
            'request_mode.required'          => 'Please choose how you would like it built.',
        ];
    }

    private function cleanNotes(array $data): ?string
    {
        $notes = isset($data['modification_notes']) ? trim((string) $data['modification_notes']) : '';

        return $notes !== '' ? $notes : null;
    }

    // Convenience copy only: every request keeps the contact it was sent with.
    //
    // The contact that was submitted is always validated and used for the
    // request. The users columns are written only when saving was asked for,
    // and the toggle state alone is not enough:
    //  - no saved contact yet: "save_contact" saves it;
    //  - a saved contact exists: it is replaced only when "use_different" AND
    //    "update_saved" are both on and the submitted contact really differs.
    private function rememberContact(User $user, Request $request, CustomDesignRequest $designRequest): void
    {
        $hadSaved = filled($user->saved_contact_phone) && filled($user->saved_contact_email);

        if ($hadSaved) {
            $differs = $designRequest->contact_phone !== $user->saved_contact_phone
                || strcasecmp((string) $designRequest->contact_email, (string) $user->saved_contact_email) !== 0;

            if (!($request->boolean('use_different') && $request->boolean('update_saved') && $differs)) {
                return;
            }
        } elseif (!$request->boolean('save_contact')) {
            return;
        }

        $user->forceFill([
            'saved_contact_phone' => $designRequest->contact_phone,
            'saved_contact_email' => $designRequest->contact_email,
            'contact_saved_at'    => now(),
        ])->save();
    }

    private function legacySuggestion(CustomDesignRequest $designRequest): ?AiDesignSuggestion
    {
        $all = AiDesignSuggestion::where('design_id', $designRequest->id)->get()->filter(fn ($s) => $s->isLoadable());

        return $all->firstWhere('is_selected', true) ?? $all->first();
    }

    private function formData(CustomDesignRequest $designRequest, ?AiDesignSuggestion $suggestion, bool $editing): array
    {
        $user = Auth::user();
        $savedPhone = $user->saved_contact_phone;
        $savedEmail = $user->saved_contact_email;
        $hasSaved = $savedPhone && $savedEmail;

        $phone = $editing
            ? ($designRequest->contact_phone ?: ($savedPhone ?: PhilippineMobile::normalize($user->phone_number)))
            : ($hasSaved ? $savedPhone : (PhilippineMobile::normalize($user->phone_number) ?? $user->phone_number));
        $email = $editing
            ? ($designRequest->contact_email ?: ($savedEmail ?: $user->email))
            : ($hasSaved ? $savedEmail : $user->email);

        return [
            'designRequest' => $designRequest,
            'suggestion'    => $suggestion,
            'editing'       => $editing,
            'hasSaved'      => $hasSaved,
            'savedPhone'    => $savedPhone,
            'savedEmail'    => $savedEmail,
            'defaultPhone'  => $phone,
            'defaultEmail'  => $email,
            'values'        => $editing ? [
                'preferred_call_window' => $designRequest->preferred_call_window,
                'request_mode'          => $designRequest->request_mode,
                'modification_notes'    => $designRequest->modification_notes,
            ] : [],
        ];
    }

    public function sendToTeam(Request $request, $id)
    {
        // Ownership first: someone else's id is a 404, whatever the payload.
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        if ($designRequest->isSentToTeam()) {
            return $this->alreadySent($designRequest);
        }

        $data = $request->validate($this->contactRules(), $this->contactMessages());

        $user = Auth::user();

        $sent = DB::transaction(function () use ($designRequest, $data, $user, $request) {
            // Lock the row so two submits (double click, two tabs) can't
            // both get past the "already sent" check.
            $locked = CustomDesignRequest::where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($designRequest->id);

            if ($locked->isSentToTeam()) {
                return false;
            }

            $suggestion = null;
            if (!empty($data['suggestion_id'])) {
                $suggestion = $this->sendableSuggestion($locked, (int) $data['suggestion_id']);
                abort_unless($suggestion, 404);
            } elseif ($locked->isLegacyUnsent()) {
                $suggestion = $this->legacySuggestion($locked);
            } else {
                abort_unless($locked->status === 'rejected', 404);
            }

            if ($suggestion) {
                AiDesignSuggestion::where('design_id', $locked->id)->update(['is_selected' => false]);
                AiDesignSuggestion::where('id', $suggestion->id)->where('design_id', $locked->id)->update(['is_selected' => true]);
            }

            $locked->update([
                'status'                 => 'approved', // existing meaning: sent to team
                'pipeline_status'        => 'submitted',
                'contact_phone'          => PhilippineMobile::normalize($data['contact_phone']),
                'contact_email'          => $data['contact_email'],
                'preferred_call_window'  => $data['preferred_call_window'],
                'request_mode'           => $data['request_mode'],
                'modification_notes'     => $this->cleanNotes($data),
                'selected_suggestion_id' => $suggestion?->id,
                'submitted_at'           => now(),
            ]);

            $this->rememberContact($user, $request, $locked);

            CustomOrderEvent::create([
                'request_id'  => $locked->id,
                'type'        => 'submitted',
                'actor_type'  => CustomOrderEvent::ACTOR_CUSTOMER,
                'actor_id'    => $user->id,
                'data'        => [
                    'request_mode'          => $locked->request_mode,
                    'preferred_call_window' => $locked->preferred_call_window,
                    'selected_suggestion_id' => $locked->selected_suggestion_id,
                ],
                'occurred_at' => now(),
            ]);

            $design = $locked->order_name;
            $window = $locked->callWindowLabel();

            $received = \App\Support\NotificationTemplates::render('request_received', [
                'order' => $design, 'phone' => $locked->contact_phone, 'window' => $window,
            ]);
            Notification::create([
                'user_id' => $user->id,
                'title'   => $received['title'],
                'message' => $received['message'],
                'type'    => 'custom_order',
                'url'     => route('design.show', $locked->id),
                'is_read' => false,
            ]);

            $customerName = \App\Support\NameFormat::proper(trim($user->first_name . ' ' . $user->last_name));
            foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
                $adminMsg = \App\Support\NotificationTemplates::render('admin_new_request', [
                    'order' => $design, 'customer' => $customerName, 'window' => $window, 'phone' => $locked->contact_phone,
                ]);
                Notification::create([
                    'user_id' => $adminId,
                    'title'   => $adminMsg['title'],
                    'message' => $adminMsg['message'],
                    'type'    => 'custom_order',
                    'url'     => route('admin.design-requests.show', $locked->id),
                    'is_read' => false,
                ]);
            }

            return true;
        });

        if (!$sent) {
            return $this->alreadySent($designRequest);
        }

        return redirect()->route('design.show', $designRequest->id)
            ->with('success', 'Your request was sent to our team.');
    }

    // A concept of THIS request that the customer can still send.
    private function sendableSuggestion(CustomDesignRequest $designRequest, int $suggestionId): ?AiDesignSuggestion
    {
        if (!in_array($designRequest->status, ['ai_generated', 'under_review'], true) && !$designRequest->isLegacyUnsent()) {
            return null;
        }

        $suggestion = AiDesignSuggestion::where('design_id', $designRequest->id)->find($suggestionId);

        return $suggestion && $suggestion->isLoadable() ? $suggestion : null;
    }

    private function alreadySent(CustomDesignRequest $designRequest)
    {
        return redirect()->route('design.show', $designRequest->id)
            ->with('info', 'This request has already been sent to our team.');
    }

    public function regenerate($id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        // Once sent, the concepts the team is reviewing must stay put.
        if ($designRequest->isSentToTeam() || $designRequest->isLegacyUnsent()) {
            return redirect()->route('design.show', $designRequest->id)
                ->with('info', 'This request has already been sent to our team, so its designs can no longer be regenerated. Start a new request for a different design.');
        }

        AiDesignSuggestion::where('design_id', $id)->delete();
        $designRequest->update(['status' => 'pending']);

        GenerateAiDesign::dispatch($designRequest->id);

        return redirect()->route('design.show', $designRequest->id)
            ->with('success', 'Generating new design concepts...');
    }

    /* ════════════════════════════════
       FAVORITE TOGGLE — AJAX
    ════════════════════════════════ */
    public function toggleFavorite(Request $request, $suggestionId)
    {
        $suggestion = AiDesignSuggestion::whereHas('request', function ($q) {
            $q->where('user_id', Auth::id());
        })->findOrFail($suggestionId);

        $suggestion->update(['is_favorited' => !$suggestion->is_favorited]);

        return response()->json(['favorited' => (bool) $suggestion->is_favorited]);
    }

    /* ════════════════════════════════
       MY DESIGNS — History, Gallery and Favorites tabs
    ════════════════════════════════ */
    public function gallery(Request $request)
    {
        CustomDesignRequest::failStale();

        $tab = in_array($request->get('tab'), ['history', 'gallery', 'favorites'], true) ? $request->get('tab') : 'history';
        $filter = in_array($request->get('filter'), ['ready', 'failed'], true) ? $request->get('filter') : 'all';

        $requests = CustomDesignRequest::where('user_id', Auth::id())
            ->with('suggestions')
            ->latest()
            ->get();

        $ready = $requests->filter(fn ($r) => $r->designState() === 'ready')->values();

        $favorites = $ready
            ->flatMap(fn ($r) => $r->loadableSuggestions()->where('is_favorited', true)->map(fn ($s) => ['request' => $r, 'suggestion' => $s]))
            ->sortByDesc(fn ($e) => $e['suggestion']->generated_at)
            ->values();

        $counts = ['history' => $requests->count(), 'gallery' => $ready->count(), 'favorites' => $favorites->count()];

        $grouped = collect();
        $paginator = null;
        $galleryItems = collect();

        if ($tab === 'history') {
            $rows = $requests->filter(fn ($r) => $filter === 'all' || ($filter === 'ready' ? $r->designState() === 'ready' : $r->designState() === 'failed'))->values();
            $perPage = 15;
            $page = max(1, (int) $request->get('page', 1));
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
            $grouped = $paginator->getCollection()->groupBy(fn ($r) => $r->historyGroup());
        } elseif ($tab === 'gallery') {
            $galleryItems = $ready->map(fn ($r) => ['request' => $r, 'suggestion' => $r->representativeSuggestion()]);
        } else {
            $galleryItems = $favorites;
        }

        return view('design.gallery', compact('tab', 'filter', 'counts', 'grouped', 'paginator', 'galleryItems'));
    }
}
