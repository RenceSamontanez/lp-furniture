<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row table holding the business contact info shown on the
 * checkout "Need Help" panel, the design request page, and in chatbot
 * replies (via {{phone}} / {{email}} / {{facebook}} placeholders).
 * Edited from Admin → Contact Settings; see App\Models\BusinessSetting.
 */
return new class extends Migration {
    // Contact intent as it existed before placeholders, kept so down() can restore it
    private const OLD_KEYWORDS = 'contact, talk to human, real person, customer service, phone number, email, tumawag, kausapin';
    private const OLD_RESPONSE = 'You can reach our team directly: Phone 0917-456-7890, Email lpfurnitureph@gmail.com, located in San Juan, Batangas, Philippines, open Mon to Sat 8AM to 5PM. Or feel free to keep chatting with me — I am here to help with most questions!';

    private const NEW_KEYWORDS = 'contact, talk to human, real person, customer service, phone, email, number, facebook, messenger, reach, call, tumawag, kausapin';
    private const NEW_RESPONSE = 'You can reach our team directly: Phone {{phone}}, Email {{email}}, Facebook {{facebook}} — located in San Juan, Batangas, Philippines, open Mon to Sat 8AM to 5PM. Or feel free to keep chatting with me — I am here to help with most questions!';

    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 30);
            $table->string('email', 191);
            $table->string('facebook_url', 255);
            $table->foreignId('updated_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamps();
        });

        // Placeholders — an admin replaces these from the settings page
        DB::table('business_settings')->insert([
            'phone'        => '0917-000-0000',
            'email'        => 'contact@example.com',
            'facebook_url' => 'https://facebook.com/your-page',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Point the existing contact intent at the placeholders instead of
        // hardcoded values; create it if it was deleted.
        $contactIntent = DB::table('chatbot_intents')
            ->where('keywords', 'like', '%talk to human%')
            ->first();

        if ($contactIntent) {
            DB::table('chatbot_intents')->where('id', $contactIntent->id)->update([
                'keywords'   => self::NEW_KEYWORDS,
                'response'   => self::NEW_RESPONSE,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('chatbot_intents')->insert([
                'keywords'   => self::NEW_KEYWORDS,
                'response'   => self::NEW_RESPONSE,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('chatbot_intents')
            ->where('keywords', self::NEW_KEYWORDS)
            ->update([
                'keywords'   => self::OLD_KEYWORDS,
                'response'   => self::OLD_RESPONSE,
                'updated_at' => now(),
            ]);

        Schema::dropIfExists('business_settings');
    }
};
