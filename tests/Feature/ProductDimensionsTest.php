<?php

namespace Tests\Feature;

use App\Models\ChatbotIntent;
use App\Models\Product;
use App\Support\Dimensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 19: products carry Length x Width x Height too. Entered in a chosen unit, stored as
 * "L x W x H cm" in the same column as before, shown in cm with inches in brackets.
 */
class ProductDimensionsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function admin()
    {
        return $this->makeUser('admin');
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'product_name' => 'Narra Dining Table', 'category' => 'Tables', 'material' => 'Narra', 'finish' => 'Natural',
            'price' => '12000', 'stock_quantity' => '3', 'product_status' => 'available',
        ], $over);
    }

    private function create(array $over = [])
    {
        return $this->actingAs($this->admin(), 'admin')->post(route('admin.products.store'), $this->payload($over));
    }

    private function product(string $dims = '120 x 60 x 75 cm'): Product
    {
        return Product::create([
            'product_name' => 'Old Table', 'category' => 'Tables', 'material' => 'Narra', 'finish' => 'Natural',
            'price' => 5000, 'stock_quantity' => 2, 'product_status' => 'available', 'dimensions' => $dims,
        ]);
    }

    /* ───────────── admin create and edit, each unit ───────────── */

    public function test_create_converts_every_unit_and_stores_centimetres(): void
    {
        $cases = [
            ['cm', ['120', '60', '75'], '120 x 60 x 75 cm'],
            ['mm', ['1200', '600', '750'], '120 x 60 x 75 cm'],
            ['m', ['1.2', '0.6', '0.75'], '120 x 60 x 75 cm'],
            ['in', ['47.5', '24', '30'], '120.65 x 60.96 x 76.2 cm'],
            ['ft', ['4', '2', '2.5'], '121.92 x 60.96 x 76.2 cm'],
        ];

        foreach ($cases as $i => [$unit, [$l, $w, $h], $stored]) {
            $this->create([
                'product_name' => "Table {$i}", 'dimension_length' => $l, 'dimension_width' => $w, 'dimension_height' => $h, 'dimension_unit' => $unit,
            ])->assertSessionHasNoErrors();

            $this->assertSame($stored, Product::where('product_name', "Table {$i}")->value('dimensions'), $unit);
        }
    }

    public function test_the_size_is_optional_and_all_three_or_none(): void
    {
        $this->create(['product_name' => 'No size'])->assertSessionHasNoErrors();
        $this->assertNull(Product::where('product_name', 'No size')->value('dimensions'));

        $this->create(['product_name' => 'Part size', 'dimension_length' => '120', 'dimension_width' => '', 'dimension_height' => ''])
            ->assertSessionHasErrors(['dimension_width', 'dimension_height']);
        $this->assertNull(Product::where('product_name', 'Part size')->first());
    }

    public function test_the_limits_are_two_to_six_hundred_cm_after_conversion(): void
    {
        foreach ([
            ['1.99', 'cm'], ['601', 'cm'], ['0.7', 'in'], ['20', 'ft'], ['6.1', 'm'], ['19', 'mm'], ['0', 'cm'], ['abc', 'cm'], ['-5', 'cm'],
        ] as $i => [$value, $unit]) {
            $this->create(['product_name' => "Bad {$i}", 'dimension_length' => $value, 'dimension_width' => '60', 'dimension_height' => '75', 'dimension_unit' => $unit])
                ->assertSessionHasErrors('dimension_length');
        }
        $this->create(['product_name' => 'Edge low', 'dimension_length' => '2', 'dimension_width' => '3.5', 'dimension_height' => '200'])->assertSessionHasNoErrors();
        $this->create(['product_name' => 'Edge high', 'dimension_length' => '600', 'dimension_width' => '60', 'dimension_height' => '75'])->assertSessionHasNoErrors();

        $this->create(['product_name' => 'Bad unit', 'dimension_length' => '120', 'dimension_width' => '60', 'dimension_height' => '75', 'dimension_unit' => 'yard'])
            ->assertSessionHasErrors('dimension_unit');
        $this->create(['product_name' => 'Msg', 'dimension_length' => '1', 'dimension_width' => '60', 'dimension_height' => '75'])
            ->assertSessionHasErrors(['dimension_length' => 'Length must be between 2 cm and 600 cm.']);
        $this->assertSame(0, Product::where('product_name', 'like', 'Bad%')->count());
    }

    public function test_edit_with_a_unit_replaces_the_size_and_a_cleared_size_is_removed(): void
    {
        $p = $this->product();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->put(route('admin.products.update', $p->id), $this->payload([
            'dimension_length' => '48', 'dimension_width' => '24', 'dimension_height' => '30', 'dimension_unit' => 'in',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('121.92 x 60.96 x 76.2 cm', $p->fresh()->dimensions);

        $this->actingAs($admin, 'admin')->put(route('admin.products.update', $p->id), $this->payload())->assertSessionHasNoErrors();
        $this->assertNull($p->fresh()->dimensions);
    }

    public function test_the_form_has_length_width_height_unit_and_the_measuring_help(): void
    {
        $admin = $this->admin();
        $html = $this->actingAs($admin, 'admin')->get(route('admin.products.create'))->assertOk()->getContent();

        foreach (['dimension_length', 'dimension_width', 'dimension_height', 'dimension_unit'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html);
        }
        $this->assertStringNotContainsString('name="dimensions_length"', $html);
        $this->assertStringContainsString('Length</label>', $html);
        $this->assertStringContainsString('<summary>Need help measuring?</summary>', $html);
        $this->assertStringContainsString('<svg class="gm-svg"', $html);
        $this->assertStringContainsString('href="' . route('guide') . '"', $html);
        $this->assertStringContainsString('assets/css/guide.css', $html);
        foreach (['cm', 'in', 'ft', 'm', 'mm'] as $unit) {
            $this->assertStringContainsString('<option value="' . $unit . '"', $html);
        }
        $this->assertStringContainsString('2 to 600 cm for each side', $html);
    }

    /* ───────────── old products ───────────── */

    public function test_an_old_product_is_read_as_length_width_height_without_changing_it(): void
    {
        $p = $this->product('120 x 60 x 75 cm');   // before: Width x Depth x Height

        $this->assertSame(['length' => 120.0, 'width' => 60.0, 'height' => 75.0], $p->dimensions_cm);
        $this->assertSame('120 x 60 x 75 cm (47.2 x 23.6 x 29.5 in)', $p->dimensions_label);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.products.edit', $p->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="pf-dim-length"[^>]*value="120"/', $html);
        $this->assertMatchesRegularExpression('/id="pf-dim-width"[^>]*value="60"/', $html);
        $this->assertMatchesRegularExpression('/id="pf-dim-height"[^>]*value="75"/', $html);
        $this->assertStringContainsString('<option value="cm" selected', $html);
        $this->assertSame('120 x 60 x 75 cm', $p->fresh()->dimensions, 'viewing changes nothing');

        // saving without touching the size keeps the same text
        $this->actingAs($this->admin(), 'admin')->put(route('admin.products.update', $p->id), $this->payload([
            'dimension_length' => '120', 'dimension_width' => '60', 'dimension_height' => '75', 'dimension_unit' => 'cm',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('120 x 60 x 75 cm', $p->fresh()->dimensions);
    }

    public function test_free_text_sizes_from_long_ago_do_not_break_anything(): void
    {
        $p = $this->product('big and heavy');

        $this->assertNull($p->dimensions_cm);
        $this->assertSame('big and heavy', $p->dimensions_label, 'shown as it was stored');
        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.products.edit', $p->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="pf-dim-length"[^>]*value=""/', $html);
        $this->get(route('product.show', $p->id))->assertOk()->assertSee('big and heavy');
    }

    /* ───────────── customer-facing ───────────── */

    public function test_customers_see_lxwxh_in_cm_with_inches_in_brackets(): void
    {
        $p = $this->product('180 x 90 x 75 cm');

        $this->get(route('product.show', $p->id))->assertOk()
            ->assertSee('Size (L x W x H)')->assertSee('180 x 90 x 75 cm (70.9 x 35.4 x 29.5 in)')
            ->assertDontSee('Dimensions');

        $this->getJson(route('product.quickview', $p->id))->assertOk()
            ->assertJsonPath('dimensions', '180 x 90 x 75 cm (70.9 x 35.4 x 29.5 in)');

        $js = file_get_contents(public_path('assets/main.js'));
        $this->assertStringContainsString('Size (L x W x H)', $js);
        $this->assertStringNotContainsString('qv-spec-label">Dimensions<', $js);

        $c = $this->makeUser();
        DB::table('cart_items')->insert(['user_id' => $c->id, 'product_id' => $p->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->actingAs($c)->get(route('checkout'))->assertOk()->assertSee('180 x 90 x 75 cm (70.9 x 35.4 x 29.5 in)');
    }

    public function test_the_cm_with_inches_formatter(): void
    {
        $this->assertSame('120 x 60 x 75 cm (47.2 x 23.6 x 29.5 in)', Dimensions::cmWithInches(120, 60, 75));
        $this->assertSame('30.5 x 30.5 x 30.5 cm (12 x 12 x 12 in)', Dimensions::cmWithInches(30.5, 30.5, 30.5));
        $this->assertSame('—', Dimensions::cmWithInches(null, 60, 75));
    }

    /* ───────────── chatbot ───────────── */

    public function test_the_chatbot_quotes_a_size_in_the_new_order_and_wording(): void
    {
        $this->product('180 x 90 x 75 cm')->forceFill(['product_name' => 'Walnut Table'])->save();

        $text = $this->postJson(route('chatbot.respond'), ['message' => 'What is the size of the Walnut Table?'])->assertOk()->json('response');

        // Luma ends every answer with her closing line
        $this->assertStringStartsWith('The Walnut Table measures 180 x 90 x 75 cm (70.9 x 35.4 x 29.5 in), as length x width x height.', $text);
        $this->assertStringNotContainsStringIgnoringCase('depth', $text);

        // a trained answer still wins over the size answer
        ChatbotIntent::create(['keywords' => 'walnut', 'response' => 'Ask in store.', 'suggestions' => '', 'category' => 'general', 'is_active' => true]);
        $this->assertStringStartsWith('Ask in store.', $this->postJson(route('chatbot.respond'), ['message' => 'size of the walnut table'])->json('response'));
    }

    /* ───────────── nothing says "Depth", nothing old changes ───────────── */

    public function test_no_template_or_guide_text_says_depth(): void
    {
        $offenders = [];
        $dirs = [resource_path('views'), app_path('Support')];

        foreach ($dirs as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if (!$file->isFile() || !preg_match('/\.php$/', $file->getFilename())) {
                    continue;
                }
                // the word on its own; depth_cm style names are internal column names, not text
                if (preg_match('/\bdepth\b/i', file_get_contents($file->getPathname()))) {
                    $offenders[] = $file->getPathname();
                }
            }
        }
        // the explanatory comments about the old name live in Dimensions and Models, not in templates or the guide
        $offenders = array_values(array_filter($offenders, fn ($f) => !str_ends_with($f, 'Dimensions.php')));

        $this->assertSame([], $offenders);
        $this->assertDoesNotMatchRegularExpression('/\bdepth\b/i', file_get_contents(public_path('assets/js/validation.js')) . file_get_contents(resource_path('views/admin/products/form.blade.php')));
        $this->assertStringNotContainsStringIgnoringCase('depth', $this->get('/guide')->assertOk()->getContent());
        $this->assertStringNotContainsStringIgnoringCase('depth', $this->actingAs($this->admin(), 'admin')->get(route('admin.products.create'))->getContent());
    }

    public function test_existing_order_items_and_agreement_snapshots_are_unchanged_by_editing_a_product(): void
    {
        $admin = $this->admin();
        $customer = $this->makeUser();
        $p = $this->product('120 x 60 x 75 cm');

        $order = $this->makeOrder($customer, ['total_amount' => 5000]);
        $item = \App\Models\OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'product_name' => 'Old Table', 'product_image' => null,
            'quantity' => 1, 'unit_price' => 5000, 'subtotal' => 5000,
        ]);
        $request = $this->makeRequest($customer);
        $agreement = $this->makeAgreement($this->makeQuote($request, ['status' => 'accepted']), $customer, $admin);
        $before = [$item->fresh()->getAttributes(), $agreement->terms_snapshot, $agreement->snapshot_sha256];

        $this->actingAs($admin, 'admin')->put(route('admin.products.update', $p->id), $this->payload([
            'dimension_length' => '200', 'dimension_width' => '100', 'dimension_height' => '80', 'dimension_unit' => 'cm',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('200 x 100 x 80 cm', $p->fresh()->dimensions);
        $this->assertSame($before[0], $item->fresh()->getAttributes());
        $this->assertSame($before[1], $agreement->fresh()->terms_snapshot);
        $this->assertSame($before[2], $agreement->fresh()->snapshot_sha256);
        $this->assertTrue($agreement->fresh()->snapshotIsIntact());
    }
}
