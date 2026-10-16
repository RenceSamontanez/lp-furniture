<?php

/**
 * A copy of the 20 intents that were in the live chatbot_intents table when Luma's matcher was rewritten (Stage 20),
 * so the tests can prove the old wrong matches are fixed against the real wording.
 * Keys are the live row ids.
 */
return [
    11 => [
        'keywords' => 'hello, hi, hey, good morning, good afternoon, kumusta',
        'response' => 'Hi there! 👋 Welcome to LP Furniture. I can help you with ordering, customization, payments, delivery, or tracking your order. What do you need help with?',
        'suggestions' => 'How to order, Custom design, Payment options, Track my order',
    ],
    12 => [
        'keywords' => 'how to order, how do i order, place an order, ordering process, paano mag order, how to buy',
        'response' => 'Ordering is easy! Here\'s how: 1) Browse our Catalog and pick a piece you like 2) Click "Add to Cart" or "Order Now" 3) If it\'s your first order, you\'ll need to verify your identity (one-time, takes 2 minutes) 4) Go to Checkout, choose pickup or delivery 5) Select your payment method and confirm. That\'s it — you\'ll get updates every step of the way!',
        'suggestions' => 'Why verify identity?, Payment methods, Delivery options',
    ],
    13 => [
        'keywords' => 'verify, verification, id, identity, why verify, kailangan ba mag verify, valid id',
        'response' => 'Identity verification is a one-time requirement before your first order. We ask for a valid government ID and a selfie holding that ID. This protects both you and us from fraud — especially for cash-on-delivery and installment orders. It usually takes less than 24 hours for our team to review. You can still browse and add items to your cart while waiting!',
        'suggestions' => 'How to submit ID, How long does review take, Start verification',
    ],
    14 => [
        'keywords' => 'customize, customization, custom design, custom order, can i request, specific size, change design, sariling design, ai design',
        'response' => 'Yes! We love custom pieces. You have two options: 1) On any product page, use the "Customization Note" field to request size, wood type, or finish changes before ordering. 2) For a completely custom piece built from scratch, use our AI Custom Design tool — describe what you want, and our AI generates visual design options for you to review before we start production.',
        'suggestions' => 'Try AI Design tool, What woods can I choose, Customization pricing',
    ],
    15 => [
        'keywords' => 'payment, how to pay, payment method, paano magbayad, gcash, stripe, credit card, cash, bayad',
        'response' => 'We accept three payment methods: Card payment via Stripe (Visa, Mastercard), GCash QR payment — scan and upload your receipt, and Cash on pickup or delivery. You choose your preferred method during checkout.',
        'suggestions' => 'Installment plan, Is GCash safe, Delivery payment',
    ],
    16 => [
        'keywords' => 'installment, hulugan, monthly payment, split payment, pay later, layaway',
        'response' => 'Yes, we offer installment plans! You can split your payment over 7 days, 15 days, 1, 2, 3, or 6 months. Just choose "Installment Plan" during checkout and pick your preferred period. Installment requests are reviewed and approved by our team within 24 hours.',
        'suggestions' => 'How to choose installment, Full payment instead, Contact for installment',
    ],
    17 => [
        'keywords' => 'delivery, shipping, deliver, magkano delivery, delivery fee, delivery area, how long delivery, saan available',
        'response' => 'We offer two options: Pick-up at our shop in San Juan, Batangas — completely FREE, available Mon to Sat 8AM to 5PM. Door-to-door delivery — flat fee of 500 pesos, available based on your location. You will get a notification the moment your order is ready or out for delivery.',
        'suggestions' => 'Pickup shop location, Track my delivery, Delivery timeline',
    ],
    18 => [
        'keywords' => 'track, tracking, order status, where is my order, my order, check order, order update',
        'response' => 'You can track your order anytime! Go to your Profile then My Orders, or visit the Orders page directly. You will see a live status timeline: Placed then Confirmed then In Production then Ready or Out for Delivery then Completed. You will also get a notification every time your order status changes.',
        'suggestions' => 'Go to my orders, What does In Production mean, Contact about my order',
    ],
    19 => [
        'keywords' => 'register, sign up, create account, how to register, gumawa ng account, new account',
        'response' => 'Creating an account is free and quick! Click "Register" in the top menu, fill in your name, email, phone number, and a secure password — or just sign up instantly with Google. After registering, you will need to verify your identity once before placing your first order.',
        'suggestions' => 'Sign up with Google, Why verify identity, I forgot my password',
    ],
    20 => [
        'keywords' => 'forgot password, reset password, lost password, cant login, di makapasok, wrong password',
        'response' => 'No worries! Go to the Login page and click "Forgot password?" — enter your email and we will send you a secure reset link. If you signed up using Google, you do not need a password at all — just click "Continue with Google" to log in.',
        'suggestions' => 'Go to login, I signed up with Google, Contact support',
    ],
    21 => [
        'keywords' => 'material, wood, wood type, narra, mahogany, acacia, lawaan, gemelina, ano gamit na kahoy',
        'response' => 'We work with premium Philippine hardwood! Our most popular choices: Narra — premium, durable, beautiful grain. Mahogany — classic reddish-brown tone. Acacia — very durable, great for everyday furniture. Lawaan — more affordable option. Gemelina — ideal if you plan to paint the piece. Not sure which fits your needs? Our AI Design tool lets you specify a preference, or leave it to Any and Suggest Best and we will recommend the right wood.',
        'suggestions' => 'Try AI Design tool, Pricing by material, Talk to our team',
    ],
    22 => [
        'keywords' => 'price, how much, budget, presyo, magkano, cheap, affordable',
        'response' => 'Our prices vary by piece, material, and size — typically ranging from a few thousand pesos for smaller items to higher for large custom furniture like dining sets. Tell me your budget and I can help point you toward pieces that fit! You can also browse our Catalog and filter by price range.',
        'suggestions' => 'Browse catalog, Filter by price, Installment options',
    ],
    23 => [
        'keywords' => 'cancel order, refund, return, pwede ba icancel, change my order, i made a mistake',
        'response' => 'Since most of our furniture is made-to-order, cancellations are only possible before production begins. If your order status still shows Pending or Confirmed, contact us right away and we will do our best to help. Once an order reaches In Production, it usually cannot be cancelled since materials have already been cut for your piece.',
        'suggestions' => 'Contact us now, Check my order status, Track my order',
    ],
    24 => [
        'keywords' => 'contact, talk to human, real person, customer service, phone, email, number, facebook, messenger, reach, call, tumawag, kausapin',
        'response' => 'You can reach our team directly: Phone {{phone}}, Email {{email}}, Facebook {{facebook}} — located in San Juan, Batangas, Philippines, open Mon to Sat 8AM to 5PM. Or feel free to keep chatting with me — I am here to help with most questions!',
        'suggestions' => 'Store hours, Store location, Ask another question',
    ],
    25 => [
        'keywords' => 'location, address, where are you, store hours, open, saan kayo, oras',
        'response' => 'We are located in San Juan, Batangas, Philippines. Our shop is open Monday to Saturday, 8AM to 5PM. You are welcome to visit for pickup orders, or to see our craftsmanship in person!',
        'suggestions' => 'Pickup vs delivery, Contact us, Browse catalog',
    ],
    26 => [
        'keywords' => 'catalog, products, what do you sell, furniture types, categories, ano meron',
        'response' => 'We offer a wide range of handcrafted furniture: dining tables, chairs, cabinets, bed frames, sofas, doors, study tables, and full sala sets. You can browse everything in our Catalog and filter by category, or tell me what room you are furnishing and I can point you in the right direction!',
        'suggestions' => 'Browse full catalog, I need a dining table, I need a chair',
    ],
    27 => [
        'keywords' => 'cart, add to cart, my cart, remove from cart, checkout help',
        'response' => 'Adding items is simple — click "Add to Cart" on any product page. You can view, adjust quantities, or remove items anytime from your Cart page. When you are ready, click "Proceed to Checkout" to place your order.',
        'suggestions' => 'Go to my cart, How to checkout, Continue shopping',
    ],
    28 => [
        'keywords' => 'ai design, generate design, dalle, design tool, how does ai design work',
        'response' => 'Our AI Design tool lets you describe furniture you want built from scratch — type, material, style, dimensions — and our AI generates visual design concepts for you to review. Once you pick your favorite, our team reaches out to discuss pricing and production. It is a great option if you do not see exactly what you want in our catalog!',
        'suggestions' => 'Start AI Design request, What details should I include, Talk to our team instead',
    ],
    29 => [
        'keywords' => 'google, sign in with google, google login, google account',
        'response' => 'Yes, you can sign up or log in instantly using your Google account — no need to create a separate password. Just click "Continue with Google" on the Login or Register page.',
        'suggestions' => 'Go to login, Regular email signup instead, I am having trouble signing in',
    ],
    30 => [
        'keywords' => 'help, i am lost, confused, i dont know, di ko alam, saan ba ako',
        'response' => 'No worries, I have got you! Here is a quick overview of what you can do: Browse and order furniture, Request a custom design, Track an existing order, or Ask me about payment, delivery, or verification. What would you like to explore first?',
        'suggestions' => 'How to order, Custom design, Track my order, Payment options',
    ],
];
