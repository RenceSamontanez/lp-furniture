<?php

/**
 * New wording for the 20 hand-seeded intents that have no Category (the rows Luma's first version was trained with).
 * The key is the row's exact keyword list; a row is rewritten only when its stored answer is still exactly the
 * original (database/data/luma-previous-defaults.json, "__legacy"), so an admin's own edit is never overwritten.
 *
 * Four answers also stop repeating facts that are no longer true in the app: the GCash receipt upload (GCash now goes
 * through PayMongo), the 500 peso flat delivery fee and Mon-Sat hours (the shop sets the fee; hours come from config),
 * the installment periods (the plan now follows InstallmentScheduler) and the wood list (Gmelina, Mahogany, Acacia or a
 * special request). Those use the same {{tokens}} as the built-in answers.
 */
return [
    'hello, hi, hey, good morning, good afternoon, kumusta' =>
        "Hi, I'm Luma, LP Furniture's shop assistant.\n\nI can help with ordering, custom designs, payments, delivery and tracking your order. What do you need help with?",

    'how to order, how do i order, place an order, ordering process, paano mag order, how to buy' =>
        "Here is how ordering works:\n\n1. **Pick a piece** from our Catalog.\n2. **Add it to your cart** or choose Order Now.\n3. **Verify your identity** if it is your first order. This is a one-time step.\n4. **Go to Checkout** and choose pickup or delivery.\n5. **Choose your payment method** and confirm.\n\nYou get a notification at every step.",

    'verify, verification, id, identity, why verify, kailangan ba mag verify, valid id' =>
        "Identity verification is a one-time step before your first order.\n\nWe ask for a valid government ID and a selfie holding that ID. This protects you and us from fraud, especially for cash and installment orders.\n\nOur team usually reviews it in less than 24 hours. You can still browse and add items to your cart while you wait.",

    'customize, customization, custom design, custom order, can i request, specific size, change design, sariling design, ai design' =>
        "Yes, we make custom pieces. You have two options:\n\n1. **Customization Note.** On any product page, use this field to ask for a size, wood or finish change before you order.\n2. **Customize tool.** For a piece built from scratch, describe what you want and the tool generates pictures for you to review before we start production.",

    'payment, how to pay, payment method, paano magbayad, gcash, stripe, credit card, cash, bayad' =>
        "You can pay in three ways:\n\n- **Card** through Stripe.\n- **GCash** through PayMongo.\n- **Cash** when you pick up or when it is delivered.\n\nYou choose at checkout. An online payment is confirmed as soon as it goes through.",

    'installment, hulugan, monthly payment, split payment, pay later, layaway' =>
        "Yes. For Catalog orders you can pay in full or in installments.\n\n- **Down payment:** the first payment is {{catalog_down}}% of the total.\n- **The rest:** split over {{months}} months, every {{frequency}} days.\n\nFor custom orders, the deposit is written in your quote and is at least {{deposit_min}}% of the total.",

    'delivery, shipping, deliver, magkano delivery, delivery fee, delivery area, how long delivery, saan available' =>
        "Yes, we deliver. You can also pick up at the shop. You choose at checkout.\n\n- **Free delivery** for locations close to San Juan, Batangas ({{free_area}}).\n- **Delivery beyond that area:** {{fee_note}}\n- **Pickup** at our shop in San Juan, Batangas.\n\n> You get a notification when your order is ready and when it is out for delivery.",

    'track, tracking, order status, where is my order, my order, check order, order update' =>
        "You can follow your order anytime under My Orders in your account.\n\nThe status moves from Pending to Confirmed, In production, Ready, Out for delivery (delivery orders only) and Completed. You get a notification each time it changes.",

    'register, sign up, create account, how to register, gumawa ng account, new account' =>
        "Creating an account is free.\n\nSelect Register in the top menu and enter your name, email, phone number and a password, or sign up with Google. You verify your identity once before your first order.",

    'forgot password, reset password, lost password, cant login, di makapasok, wrong password' =>
        "To reset your password, go to the Login page and select Forgot password. Enter your email and we will send you a reset link.\n\nIf you signed up with Google, you do not need a password. Select Continue with Google to log in.",

    'material, wood, wood type, narra, mahogany, acacia, lawaan, gemelina, ano gamit na kahoy' =>
        "We build with Gmelina, Mahogany and Acacia, or you can make a special request.\n\n- **Gmelina:** the budget-friendly choice. It takes paint or stain well.\n- **Mahogany:** a classic reddish-brown. A good choice for a finer, longer-lasting piece.\n- **Acacia:** hard and durable. Good for tables, chairs and anything used every day.\n\nFor another kind of wood, choose Special request. The team confirms what is possible and the price when we call you.",

    'price, how much, budget, presyo, magkano, cheap, affordable' =>
        "Prices depend on the piece, the material and the size. Smaller items usually start at a few thousand pesos, and large custom furniture like dining sets costs more.\n\nTell me your budget and I'll point you to pieces that fit. You can also filter the Catalog by price range.",

    'cancel order, refund, return, pwede ba icancel, change my order, i made a mistake' =>
        "Most of our furniture is made to order, so an order can be cancelled only before production begins.\n\nIf your order still shows Pending or Confirmed, contact us right away and we will see what we can do. Once it is In production, it usually cannot be cancelled, because the materials have already been cut for your piece.",

    'contact, talk to human, real person, customer service, phone, email, number, facebook, messenger, reach, call, tumawag, kausapin' =>
        "You can reach our team here:\n\n[[contact]]\n\nOr keep chatting with me. I can help with most questions.",

    'location, address, where are you, store hours, open, saan kayo, oras' =>
        "Our shop is in San Juan, Batangas. We are open {{hours}}.\n\nYou are welcome to visit for pickup orders or to see our work in person.",

    'catalog, products, what do you sell, furniture types, categories, ano meron' =>
        "We make handcrafted furniture: dining tables, chairs, cabinets, bed frames, sofas, doors, study tables and full sala sets.\n\nYou can browse everything in our Catalog and filter by category, or tell me which room you are furnishing and I'll suggest where to start.",

    'cart, add to cart, my cart, remove from cart, checkout help' =>
        "Select Add to Cart on any product page. You can view your cart, change quantities or remove items anytime from the Cart page.\n\nWhen you are ready, select Proceed to Checkout to place your order.",

    'ai design, generate design, dalle, design tool, how does ai design work' =>
        "The Customize tool lets you describe furniture you want built from scratch: type, material, style and size. It generates pictures for you to review.\n\nOnce you pick your favorite, our team reaches out to discuss pricing and production. It is a good option if you do not see what you want in our Catalog.",

    'google, sign in with google, google login, google account' =>
        "Yes. You can sign up or log in with your Google account, and you do not need a separate password.\n\nSelect Continue with Google on the Login or Register page.",

    'help, i am lost, confused, i dont know, di ko alam, saan ba ako' =>
        "I can help you with these:\n\n- Browse and order furniture.\n- Request a custom design.\n- Track an existing order.\n- Answer questions about payment, delivery or verification.\n\nWhat would you like to start with?",
];
