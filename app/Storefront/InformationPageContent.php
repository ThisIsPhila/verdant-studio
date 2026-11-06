<?php

declare(strict_types=1);

namespace Verdant\Storefront;

/** Editable copy and fixed presentation roles for registered information pages. */
final class InformationPageContent
{
    /** @return list<array<string, int|string|null>> */
    public static function defaults(): array
    {
        $defaults = [];
        $positions = [];
        $add = static function (string $page, string $key, string $type, string $heading, string $body = '', ?string $media = null, string $alt = '', string $label = '', ?string $path = null) use (&$defaults, &$positions): void {
            $positions[$page] = ($positions[$page] ?? 0) + 10;
            // Older sample copy uses literal backslash-n separators in single-quoted strings.
            $body = str_replace('\n', "\n", $body);
            $defaults[] = [
                'page_key' => $page,
                'section_key' => $key,
                'section_type' => $type,
                'position' => $positions[$page],
                'heading' => $heading,
                'body' => $body,
                'media_path' => $media,
                'media_alt' => $alt,
                'link_label' => $label,
                'link_path' => $path,
            ];
        };

        $add('about', 'hero', 'info_hero', 'Nature, curated by hand.', 'Plants, flowers, pots and thoughtful botanical styling for homes, events and workspaces.', '/assets/vision/images/homes.jpg', 'Plants in a sunlit living room', 'Explore our story', '#our-story');
        $add('about', 'hero-script', 'info_text', 'Plants make life feel a little brighter.');
        $add('about', 'story', 'info_text', 'Plants for the way you live.', "Verdant brings plants, pots and arrangements together in one place. Choose individual pieces, build your own pairing or explore a collection that suits your space.\n\nSome pieces are for keeping. Others can bring a moment of greenery to an event or a changing workspace. Buying and renting are different ways to make a space your own.", '/assets/vision/images/hero-configure.jpg', 'A collection of plants paired with textured pots');
        $add('about', 'principle-curated', 'info_card', 'Curated with care', 'Consider the light, the scale of the space and the way you live. Start with a plant that feels at home.');
        $add('about', 'principle-space', 'info_card', 'Made for your space', 'From a single green corner to a layered arrangement, pair plants and pots with intention.');
        $add('about', 'principle-help', 'info_card', 'Here to help', 'Explore practical care guidance and find a starting point for your next green space.');
        $add('about', 'shop-plants', 'info_card', 'Bring nature home.', 'Explore individual plants and pots to keep, care for and make part of your everyday space.', '/assets/vision/images/homes.jpg', 'A living space with plants', 'Shop plants & pots', '/catalogue?kind=plant');
        $add('about', 'rentals', 'info_card', 'Greenery for a moment.', 'Find plants and arrangements for a chosen period, with rental dates selected around your plans.', '/assets/vision/images/r-cluster.jpg', 'A cluster of plants for a shared space', 'Explore plant rentals', '/catalogue?mode=rent');
        $add('about', 'pairing', 'info_card', 'The perfect pair.', 'Choose a plant, explore compatible pots and see the combination come together.', '/assets/vision/images/hero-configure.jpg', 'Plants and pots arranged together', 'Start your pairing', '/configure');
        $add('about', 'collections', 'info_text', 'Explore the collection.', 'Home plants, pots and arrangements.', '/assets/vision/images/homes.jpg', 'An indoor space with plants and natural textures', 'Browse collections', '/collections/homes');

        $add('sustainability', 'hero', 'info_hero', 'A greener way to grow.', 'Thoughtful plants, longer lives and considered choices for the spaces we share.', '/assets/vision/images/hero-configure.jpg', 'Plants and pots in a softly lit room', 'Our approach', '#our-approach');
        $add('sustainability', 'hero-script', 'info_text', 'Plants for a brighter tomorrow.');
        $add('sustainability', 'approach', 'info_text', 'Beautiful spaces, considered choices.', 'Looking after living things starts with small, practical decisions: choosing the right plant, caring for it well and finding another use for pieces you already have.');
        $add('sustainability', 'care', 'info_card', 'Care for what grows.', 'Understand light, water and seasonal changes so each plant has a better chance to thrive.', '/assets/vision/images/homes.jpg', 'Plants in a home', 'Plant care & guidance', '/plant-care');
        $add('sustainability', 'lasting-pieces', 'info_card', 'Choose pieces that last.', 'Consider the size, finish and suitability of a pot, along with the needs of the plant inside it.', '/assets/vision/images/r-olive.jpg', 'Olive-toned plants and pots', 'Explore pairings', '/configure');
        $add('sustainability', 'rent', 'info_card', 'Rent for changing spaces.', 'Explore rental options when you need plants for an occasion or a limited period.', '/assets/vision/images/r-cluster.jpg', 'Plants grouped for an event or changing space', 'Plant & pot rentals', '/catalogue?mode=rent');
        $add('sustainability', 'reuse', 'info_text', 'Longer life, brighter spaces.', 'A different position, fresh growing medium or a well-chosen pot can give a plant a new chapter. Check what each species needs before making a change.', '/assets/vision/images/hero-configure.jpg', 'Different pots and foliage arranged together', 'Care for your plants', '/plant-care');
        $add('sustainability', 'ambition', 'info_text', 'A more thoughtful tomorrow.', 'Our ambition is to make plant care, reuse and considered purchasing easier to understand. These principles guide the experience we are building; they are not claims of certified sourcing or measured environmental savings.', '/assets/vision/images/homes.jpg', 'An indoor space with plants and natural textures', 'Read our story', '/about');

        $add('plant-care', 'hero', 'info_hero', 'A little care. A lot of growth.', 'Simple, practical guidance for light, watering, soil and seasonal care. Start with your plant and the conditions in your space.');
        $add('plant-care', 'topic-light', 'info_text', 'Light', "Find the right place for healthy, steady growth.\n\nFollow the light through the day. Many houseplants prefer bright indirect light, while direct sun and deep shade affect each species differently.\n\nIntroduce a plant to a brighter position gradually and watch for leaf scorch. Rotate it gently if growth leans towards one side.");
        $add('plant-care', 'topic-water', 'info_text', 'Water', "Read the soil and learn when your plant needs a drink.\n\nCheck the growing medium before watering rather than following a fixed calendar. Let the needs of your species guide how dry it should become.\n\nWater in a way that allows excess to drain, then avoid leaving roots standing in collected water. Change the routine as conditions change.");
        $add('plant-care', 'topic-soil', 'info_text', 'Soil', "Understand drainage, growing medium and root health.\n\nUse a growing medium suited to the species. Drainage and airflow around the roots matter as much as the look of the pot.\n\nWhen repotting, inspect the roots and choose an appropriate next pot. A much larger container can stay wet longer than expected.");
        $add('plant-care', 'topic-seasonal', 'info_text', 'Seasonal care', "Adjust care as the temperature and daylight change.\n\nPlants may use less water when growth slows and light levels fall. Check the medium and new growth before adjusting feeding or watering.\n\nKeep plants away from harsh draughts and heat sources. Seasonal advice varies with the plant and your local conditions.");
        $add('plant-care', 'care-note', 'info_text', 'Guidance for growing plants.', 'Care needs vary by species, season and the conditions in your space. Read the care information supplied with each plant.');
        $add('plant-care', 'browse-plants', 'info_text', 'Care starts with the plant.', 'Explore plants and their individual care information.', null, '', 'View all plants', '/catalogue?kind=plant');

        $add('journal', 'hero', 'info_hero', 'Notes from the studio.', 'Plant care, styling ideas and seasonal inspiration. Thoughtful guides for the spaces we live in.', '/assets/vision/images/homes.jpg', 'Plants in a sunlit living room', 'Explore all articles', '#articles');
        $add('journal', 'hero-script', 'info_text', 'Plants make people feel at home.');
        $add('journal', 'collection-heading', 'info_hero', 'Stories to grow with.');
        $add('journal', 'article-finding-your-green-corner', 'info_card', 'Finding your green corner.', 'A practical guide to choosing a spot, following the light and bringing plants and pots together.', '/assets/vision/images/homes.jpg', 'Plants in a sunlit living room', 'Read story', '/journal/article?slug=finding-your-green-corner');
        $add('journal', 'article-happier-houseplants', 'info_card', 'A simple guide to happier houseplants.', 'Start with light, watering and observation, rather than a rigid weekly routine.', '/assets/vision/images/p-monstera.jpg', 'A Monstera plant in a home', 'Read story', '/journal/article?slug=happier-houseplants');
        $add('journal', 'article-choosing-the-right-pot', 'info_card', 'Choosing the right pot.', 'Consider shape, finish and drainage alongside the needs of the plant.', '/assets/vision/images/hero-configure.jpg', 'Pots and plants arranged together', 'Read story', '/journal/article?slug=choosing-the-right-pot');

        $add('journal-article', 'hero', 'info_hero', 'Finding your green corner.', 'A practical guide to choosing a spot, following the light and bringing plants and pots together.', '/assets/vision/images/homes.jpg', 'Plants and natural textures in an indoor space');
        $add('journal-article', 'choose-a-corner', 'info_text', 'Choose a corner', "Start with a space you use every day. A chair by a window, an open shelf or a quiet entrance can become the starting point for a greener room.\n\nLeave enough space around the plant for its leaves and for watering. A narrow walkway is rarely the best place for a broad canopy.");
        $add('journal-article', 'follow-the-light', 'info_text', 'Follow the light', "Watch how daylight moves through the room. Bright indirect light suits many indoor plants, but the right position depends on the species.\n\nA plant described as tolerant of lower light still needs some daylight. Avoid assuming that a dark corner will suit every plant.");
        $add('journal-article', 'combine-heights', 'info_text', 'Combine heights', "Build interest with a mix of upright, spreading and trailing shapes. Choose a focal plant first, then add smaller pieces if the space needs them.\n\nKeep pots stable and away from edges. Check that shelves and stands can carry the weight of the planted pot.");
        $add('journal-article', 'pick-your-pots', 'info_text', 'Pick your pots', "Choose a finish that complements the room and a pot that fits the plant. Consider drainage and protect surfaces from trapped water.\n\nA compatible pot is a practical choice as well as a visual one. The configurator is a useful place to explore plant and pot pairings.", null, '', 'Explore plant and pot pairings', '/configure');
        $add('journal-article', 'related-houseplants', 'info_card', 'A simple guide to happier houseplants.', 'Start with light, watering and observation, rather than a rigid weekly routine.', '/assets/vision/images/p-monstera.jpg', 'A Monstera plant in a home', 'Read story', '/journal/article?slug=happier-houseplants');
        $add('journal-article', 'related-pots', 'info_card', 'Choosing the right pot.', 'Consider shape, finish and drainage alongside the needs of the plant.', '/assets/vision/images/hero-configure.jpg', 'Pots and plants arranged together', 'Read story', '/journal/article?slug=choosing-the-right-pot');

        $add('journal-houseplants', 'hero', 'info_hero', 'A simple guide to happier houseplants.', 'Start with light, watering and observation, rather than a rigid weekly routine.', '/assets/vision/images/p-monstera.jpg', 'A Monstera plant in a home');
        $add('journal-houseplants', 'observe', 'info_text', 'Get to know your plant', "Read the care information for your species. Check new leaves, the soil and the light around your plant regularly.");
        $add('journal-houseplants', 'water', 'info_text', 'Water with attention', 'Check the growing medium before watering. How quickly it dries depends on the species, pot, temperature and light.');
        $add('journal-houseplants', 'drain', 'info_text', 'Let water drain', 'Use appropriate drainage and avoid leaving roots standing in water. Empty collected water where it can safely be removed.');
        $add('journal-houseplants', 'related-corner', 'info_card', 'Finding your green corner.', 'A practical guide to choosing a spot, following the light and bringing plants and pots together.', '/assets/vision/images/homes.jpg', 'Plants in a sunlit living room', 'Read story', '/journal/article?slug=finding-your-green-corner');
        $add('journal-houseplants', 'related-pots', 'info_card', 'Choosing the right pot.', 'Consider shape, finish and drainage alongside the needs of the plant.', '/assets/vision/images/hero-configure.jpg', 'Pots and plants arranged together', 'Read story', '/journal/article?slug=choosing-the-right-pot');

        $add('journal-pots', 'hero', 'info_hero', 'Choosing the right pot.', 'Consider shape, finish and drainage alongside the needs of the plant.', '/assets/vision/images/hero-configure.jpg', 'Pots and plants arranged together');
        $add('journal-pots', 'fit', 'info_text', 'Begin with the fit', 'Check the plant and pot dimensions. Avoid choosing a much larger pot simply because there is more room on a shelf.');
        $add('journal-pots', 'finish', 'info_text', 'Find a finish', 'Stone, terracotta and other finishes change how a grouping feels. Use a few related textures for a calm, cohesive space.');
        $add('journal-pots', 'care', 'info_text', 'Think about care', 'Check drainage, the weight of the planted pot and how easily you can move it for watering. Protect the surface beneath it.');
        $add('journal-pots', 'related-corner', 'info_card', 'Finding your green corner.', 'A practical guide to choosing a spot, following the light and bringing plants and pots together.', '/assets/vision/images/homes.jpg', 'Plants in a sunlit living room', 'Read story', '/journal/article?slug=finding-your-green-corner');
        $add('journal-pots', 'related-houseplants', 'info_card', 'A simple guide to happier houseplants.', 'Start with light, watering and observation, rather than a rigid weekly routine.', '/assets/vision/images/p-monstera.jpg', 'A Monstera plant in a home', 'Read story', '/journal/article?slug=happier-houseplants');

        $add('contact', 'hero', 'info_hero', 'Let’s talk plants.', 'Questions about plants, an order, delivery or a return? Get in touch using the contact form and include your order number when it relates to a purchase or rental.');
        $add('contact', 'customer', 'info_card', 'Customer support', 'Plants, orders and general help\n\nAsk us about plant care, an order, a product, delivery or a return. Tell us what you need help with and include your order reference when you have one.\n\nWe can help with\n- Plant care and suitability\n- Orders and delivery\n- Product information\n- Returns and account support');
        $add('contact', 'corporate', 'info_card', 'Corporate and spaces', 'Green spaces for people\n\nPlants, styling and rental services for workplaces, hospitality spaces and events are coming soon.\n\nPlanned services\n- Office and workplace plants\n- Plant rental and care\n- Space planning and styling\n- Hospitality and event projects');
        $add('contact', 'trade', 'info_card', 'Trade enquiries', 'For plant professionals\n\nSupply and collaboration services for designers, landscapers and other plant professionals are coming soon.\n\nPlanned services\n- Trade accounts\n- Bulk plant and pot supply\n- Project availability\n- Ongoing supply and collaboration');

        $add('delivery', 'hero', 'info_hero', 'Getting greenery to your door.', 'Check the delivery or collection arrangements shown with your order. They can vary with the items and service selected.');
        $add('delivery', 'step-choose', 'info_card', 'Choose your pieces', 'Find plants, pots or arrangements that suit your space.');
        $add('delivery', 'step-confirm', 'info_card', 'Confirm arrangements', 'Check the delivery or collection details shown with your order.');
        $add('delivery', 'step-receive', 'info_card', 'Receive with care', 'Choose a suitable spot and follow the care guidance for each plant.');
        $add('delivery', 'confirm-callout', 'info_text', 'Check your order details', 'Your order shows the delivery or collection arrangements that apply to the items you selected. If you have a question, contact us with your order number.');
        $add('delivery', 'delivery', 'info_text', 'Delivery', 'Available delivery options, charges and timing are shown with the relevant order. Check those details for your items and destination. If an arrangement is unclear or you need help with an order, contact us and include the order number.');
        $add('delivery', 'collection', 'info_text', 'Collection', 'Where collection is offered for an order, the location and instructions are shown with that order. Check those details before travelling. Contact us if you need help with a collection arrangement.');
        $add('delivery', 'preparing-your-space', 'info_text', 'Make room for your plants', 'Measure the space and check its light before choosing a plant or arrangement. Make sure each pot has a stable surface and enough room around it.\n\nRead the care guidance supplied with each plant. General guidance may not suit every species or room.');
        $add('delivery', 'rental-delivery-return', 'info_text', 'Rental delivery and return', 'Your rental details show the agreed dates and delivery, collection and return arrangements. Follow the care information provided for the rental. Contact us if you have a question about those arrangements.');
        $add('delivery', 'order-questions', 'info_text', 'Questions about an order', 'Use the contact form for help with an order. Include the order number and a short description of your question.', null, '', 'Contact customer support', '/contact#customer');

        $add('returns', 'hero', 'info_hero', 'Here to help.', 'Find answers about purchases, rentals and plant care.');
        $add('returns', 'purchase-returns', 'info_text', 'Purchase returns', 'For help with a return, contact us through the contact form and include your order number. Eligibility and next steps depend on the item and circumstances. Your rights under applicable consumer law continue to apply.');
        $add('returns', 'damaged-or-incorrect-items', 'info_text', 'Damaged or incorrect items', 'Contact us through the contact form with your order number and a description of the issue. Photos of the item and packaging can help us understand what happened. We will respond about the next steps.');
        $add('returns', 'rental-returns', 'info_text', 'Rental returns', 'Check the rental details for its end date, care requirements and agreed return or collection arrangements. Contact us through the contact form if you need help with a rental return.');
        $add('returns', 'faq-start-a-return', 'info_faq', 'How do I start a return?', 'Use the contact form and include your order number, the item and the reason for your request. We will consider the details and respond about the next steps.');
        $add('returns', 'faq-eligible-items', 'info_faq', 'Which items can be returned?', 'Return options depend on the item and circumstances. Contact us with your order details so we can help. This information does not limit rights available under applicable consumer law.');
        $add('returns', 'faq-refund-timing', 'info_faq', 'How long does a refund take?', 'If a refund is due, its timing depends on the order and payment process. Contact us with your order number if you need an update.');
        $add('returns', 'faq-damaged-plant', 'info_faq', 'What should I do if an item arrives damaged?', 'Contact us through the form with your order number and a description of the issue. Photos of the plant, item and packaging can help explain what happened.');
        $add('returns', 'faq-incorrect-item', 'info_faq', 'What if I receive the wrong item?', 'Contact us through the form with your order number and tell us which item you expected and what arrived. We will respond about the next steps.');
        $add('returns', 'faq-cancel-purchase', 'info_faq', 'Can I change or cancel an order?', 'Contact us as soon as you can with your order number and request. Whether an order can be changed or cancelled depends on its status and applicable terms. Your legal rights are unaffected.');
        $add('returns', 'faq-rental-return', 'info_faq', 'How do I return a rental?', 'Follow the return or collection arrangements shown in your rental details, including the end date and care instructions. Contact us through the form if anything is unclear.');
        $add('returns', 'faq-rental-extension', 'info_faq', 'Can I extend a plant rental?', 'An extension depends on availability and the terms of your rental. Contact us with your rental details to ask about an extension.');
        $add('returns', 'faq-rental-damage', 'info_faq', 'What if a rental plant or pot is damaged?', 'Contact us through the form with your rental details and a description of the damage. The agreed rental terms explain responsibilities for the plant and pot.');
        $add('returns', 'faq-light', 'info_faq', 'How do I choose the right light for a plant?', 'Start with the plant’s care information and observe the light through the day. Bright indirect light means a well-lit place without strong direct sun on the leaves. Species, season and room conditions can change what works.');
        $add('returns', 'faq-watering', 'info_faq', 'How often should I water?', 'There is no single schedule for every plant. Check the growing medium before watering and follow the care guidance for that species. Pot size, drainage, light, temperature and season all affect how quickly it dries.');
        $add('returns', 'faq-new-plant', 'info_faq', 'What should I do when a new plant arrives?', 'Place it in a suitable spot, check the plant’s care guidance and let it adjust before making major changes. Inspect the growing medium before watering, and avoid moving it repeatedly while it settles.');

        $add('privacy', 'hero', 'info_hero', 'Privacy & POPIA', 'This notice explains how Verdant’s website uses personal information when you browse, create an account, place an order, arrange a rental or contact us.');
        $add('privacy', 'draft-status', 'info_text', 'Who handles your information', 'The operator of the Verdant store handles personal information collected through this website to provide the store and respond to your requests. Use the contact form for a privacy question or request.');
        $add('privacy', 'information-collected', 'info_text', 'Information we handle', 'When you use the store, information associated with your account and activity can include your name and contact details, account credentials, order and rental details, selected compositions, and messages you send through the contact form. These records are stored in the application database.\n\nThe site uses session information to keep the relevant parts of your visit and account working.');
        $add('privacy', 'purposes-and-choice', 'info_text', 'How we use information', 'We use account and contact details to provide account access, respond to support and privacy requests, and manage orders, rentals and compositions. Order and rental records help us provide the service you requested and keep the related transaction information.\n\nWe do not use the contact form as a newsletter subscription. Please include only information needed to handle your request.');
        $add('privacy', 'sharing-and-transfers', 'info_text', 'Service providers and transfers', 'The application sends payment requests to Paystack. Payment card details are not stored in the Verdant application database.\n\nInformation may also be processed by the providers that host or operate the website and its database. Their identities, processing locations and any access from outside South Africa are not set out here.');
        $add('privacy', 'cookies-and-technical-data', 'info_text', 'Sessions and technical information', 'The website uses session data to support account and store functions during your visit. Standard server and security records may also be created when the site is operated. This notice does not describe advertising or analytics tracking.');
        $add('privacy', 'security-and-retention', 'info_text', 'Access, storage and account records', 'Account, order, rental, composition and contact messages are stored in the application database. Administrator functions are restricted to role-authorised accounts.\n\nArchiving an account is a reversible account-management action. It does not erase personal information or transaction records. We retain records as needed to operate the service and address related obligations; use the contact form to request access, correction or deletion so the request can be assessed.');
        $add('privacy', 'your-rights', 'info_text', 'Your rights under POPIA', 'Subject to POPIA and its conditions, you may ask whether we hold personal information about you and request access to it. You may ask us to correct information that is inaccurate or incomplete, request deletion where applicable, object to certain processing, or withdraw consent where processing is based on your consent.\n\nUse the contact form to make a privacy request. We may need to verify your identity and consider legal or transaction-record obligations before responding.');
        $add('privacy', 'questions-and-complaints', 'info_text', 'Questions and complaints', 'Use the contact form for a privacy question, access request, correction request or deletion request. Include enough detail for us to understand the request, without sending unnecessary sensitive information.\n\nYou may also raise a complaint with the South African Information Regulator.');

        $add('terms', 'hero', 'info_hero', 'Terms of Use & Sale', 'These terms explain use of the Verdant website and the main information relevant to purchases and plant rentals. The details shown with your order or rental apply to its arrangements.');
        $add('terms', 'buy-summary', 'info_card', 'Buy plants & pots', 'Choose pieces to keep and care for in your space.');
        $add('terms', 'rent-summary', 'info_card', 'Rent plants for spaces', 'Check dates, availability and responsibilities for your rental.');
        $add('terms', 'using-the-website', 'info_text', 'Using the website', 'Use the website and your account lawfully and keep your sign-in details secure. Do not attempt to disrupt the site, access another person’s account or misuse its content or services. Information submitted through an account or contact form should be accurate and relevant to your request.');
        $add('terms', 'products-and-availability', 'info_text', 'Products and availability', 'Plants are living items and can vary in shape and appearance. Product descriptions and images help you choose, while the item details and availability shown for your order apply to your selection. If an item or detail is unclear, contact us before ordering.');
        $add('terms', 'orders-and-payment', 'info_text', 'Orders and payment', 'Payment is confirmed after verification with the payment provider. An incomplete payment does not confirm an order. Check the items, quantities, prices and other details shown in your order before completing it. Your account provides access to your order information. If you see an issue or need help, use the contact form and include your order number.');
        $add('terms', 'delivery-and-collection', 'info_text', 'Delivery and collection', 'Delivery or collection options, charges and timing are shown with the relevant order. Check those details for your items and destination. If an arrangement changes or needs clarification, contact us through the contact form.');
        $add('terms', 'rentals', 'info_text', 'Rentals', 'Rental dates, prices, availability, care responsibilities and delivery or return arrangements are shown in the rental details. Check them before proceeding and follow the care and return information provided for the rental. Contact us if you have a question about an arrangement.');
        $add('terms', 'returns-and-cancellations', 'info_text', 'Returns and cancellations', 'For help with a purchase return, damaged or incorrect item, order change or rental return, use the contact form and include your order or rental details. Eligibility and next steps depend on the circumstances and applicable terms. Nothing here limits rights available under applicable consumer law.');
        $add('terms', 'personal-information', 'info_text', 'Personal information', 'The Privacy & POPIA page explains how the store handles account, order, rental, composition and contact information, and how to make a privacy request.');
        $add('terms', 'questions-and-disputes', 'info_text', 'Questions, disputes and statutory rights', 'Use the contact form if you have a question or concern about the website, an order or a rental. These terms do not remove rights available under applicable law. The Returns & FAQs page provides further information about purchase and rental issues.', null, '', 'Read the Returns & FAQs page', '/returns');

        $add('journal', 'feature', 'info_text', 'The beauty in the details.', 'From a pot’s texture to the shape of a leaf, small decisions can bring a room together. Start with pieces that fit the way you live.', '/assets/vision/images/hero-configure.jpg', 'A thoughtful grouping of pots and plants', 'Read the feature', '/journal/article?slug=choosing-the-right-pot');

        foreach (['journal-article', 'journal-houseplants', 'journal-pots'] as $articleKey) {
            $add($articleKey, 'article-note', 'info_text', 'Start small, grow thoughtfully.', 'A green corner can be a daily reminder to slow down and notice the space around you.');
        }

        return $defaults;
    }

    /** Fixed layout roles only; page copy remains in persisted section fields. */
    public static function presentation(string $pageKey): array
    {
        $common = ['roles' => [], 'categories' => [], 'section_eyebrows' => []];
        $roleMap = static function (array $roles, array $categories = [], array $extra = []) use ($common): array {
            return array_replace_recursive($common, $extra, ['roles' => $roles, 'categories' => $categories]);
        };

        return match ($pageKey) {
            'about' => $roleMap([
                'hero' => 'hero', 'hero-script' => 'script-line', 'story' => 'split', 'principle-curated' => 'principle', 'principle-space' => 'principle',
                'principle-help' => 'principle', 'shop-plants' => 'card', 'rentals' => 'card', 'pairing' => 'card', 'collections' => 'soft',
            ], [], ['eyebrow' => 'Our story', 'hero_image' => '/assets/vision/images/homes.jpg', 'hero_alt' => 'Plants in a sunlit living room', 'groups' => ['principle-curated' => 'principles', 'principle-space' => 'principles', 'principle-help' => 'principles', 'shop-plants' => 'offerings', 'rentals' => 'offerings', 'pairing' => 'offerings'], 'icons' => ['principle-curated' => '◌', 'principle-space' => '✿', 'principle-help' => '♡'], 'section_eyebrows' => ['story' => 'Our story', 'principle-curated' => 'Our principles', 'collections' => 'Home plants, pots & arrangements'], 'image_side' => ['story' => 'left'], 'anchors' => ['story' => 'our-story']]),
            'sustainability' => $roleMap([
                'hero' => 'hero', 'hero-script' => 'script-line', 'approach' => 'soft', 'care' => 'card', 'lasting-pieces' => 'card', 'rent' => 'card', 'reuse' => 'split', 'ambition' => 'split',
            ], [], ['eyebrow' => 'Our sustainability', 'hero_image' => '/assets/vision/images/hero-configure.jpg', 'hero_alt' => 'Plants and pots in a softly lit room', 'groups' => ['care' => 'practices', 'lasting-pieces' => 'practices', 'rent' => 'practices'], 'section_eyebrows' => ['approach' => 'Our approach', 'reuse' => 'Reuse, refresh, reimagine', 'ambition' => 'What we are working towards'], 'image_side' => ['reuse' => 'left', 'ambition' => 'right'], 'anchors' => ['approach' => 'our-approach']]),
            'plant-care' => $roleMap([
                'hero' => 'hero', 'topic-light' => 'care-topic', 'topic-water' => 'care-topic', 'topic-soil' => 'care-topic', 'topic-seasonal' => 'care-topic',
                'care-note' => 'section-heading', 'browse-plants' => 'reading',
            ], ['topic-light' => 'light', 'topic-water' => 'water', 'topic-soil' => 'soil', 'topic-seasonal' => 'seasonal'], ['eyebrow' => 'Plant care', 'care_feature_image' => '/assets/vision/images/hero-configure.jpg', 'care_feature_alt' => 'Plants and pots in a softly lit room']),
            'journal' => $roleMap([
                'hero' => 'hero', 'hero-script' => 'script-line', 'feature' => 'feature', 'collection-heading' => 'section-heading', 'article-finding-your-green-corner' => 'article-card', 'article-happier-houseplants' => 'article-card', 'article-choosing-the-right-pot' => 'article-card',
            ], ['article-finding-your-green-corner' => 'Spaces', 'article-happier-houseplants' => 'Plant care', 'article-choosing-the-right-pot' => 'Spaces'], ['eyebrow' => 'The Verdant journal', 'hero_image' => '/assets/vision/images/homes.jpg', 'hero_alt' => 'Plants in a sunlit living room']),
            'journal-article' => $roleMap([
                'hero' => 'hero', 'article-note' => 'article-callout', 'choose-a-corner' => 'article-body', 'follow-the-light' => 'article-body', 'combine-heights' => 'article-body', 'pick-your-pots' => 'article-body', 'related-houseplants' => 'related-card', 'related-pots' => 'related-card',
            ], [], ['eyebrow' => 'The Verdant journal', 'article_category' => 'Spaces', 'article_slug' => 'finding-your-green-corner', 'hero_image' => '/assets/vision/images/homes.jpg', 'hero_alt' => 'Plants and natural textures in an indoor space']),
            'journal-houseplants' => $roleMap([
                'hero' => 'hero', 'article-note' => 'article-callout', 'observe' => 'article-body', 'water' => 'article-body', 'drain' => 'article-body', 'related-corner' => 'related-card', 'related-pots' => 'related-card',
            ], [], ['eyebrow' => 'The Verdant journal', 'article_category' => 'Plant care', 'article_slug' => 'happier-houseplants', 'hero_image' => '/assets/vision/images/p-monstera.jpg', 'hero_alt' => 'A Monstera plant in a home']),
            'journal-pots' => $roleMap([
                'hero' => 'hero', 'article-note' => 'article-callout', 'fit' => 'article-body', 'finish' => 'article-body', 'care' => 'article-body', 'related-corner' => 'related-card', 'related-houseplants' => 'related-card',
            ], [], ['eyebrow' => 'The Verdant journal', 'article_category' => 'Spaces', 'article_slug' => 'choosing-the-right-pot', 'hero_image' => '/assets/vision/images/hero-configure.jpg', 'hero_alt' => 'Pots and plants arranged together']),
            'contact' => $roleMap(['hero' => 'hero', 'customer' => 'contact-card', 'corporate' => 'contact-card', 'trade' => 'contact-card'], [], [
                'eyebrow' => 'Contact & enquiries',
                'icons' => ['customer' => '◌', 'corporate' => '⌂', 'trade' => '✿'],
            ]),
            'delivery' => $roleMap([
                'hero' => 'hero', 'step-choose' => 'step', 'step-confirm' => 'step', 'step-receive' => 'step',
                'delivery' => 'reading', 'collection' => 'reading', 'preparing-your-space' => 'reading', 'rental-delivery-return' => 'reading', 'order-questions' => 'reading', 'confirm-callout' => 'callout',
            ], [], ['eyebrow' => 'Delivery & collection']),
            'returns' => $roleMap([
                'hero' => 'hero', 'purchase-returns' => 'reading', 'damaged-or-incorrect-items' => 'reading', 'rental-returns' => 'reading',
                'faq-start-a-return' => 'faq', 'faq-eligible-items' => 'faq', 'faq-refund-timing' => 'faq', 'faq-damaged-plant' => 'faq',
                'faq-incorrect-item' => 'faq', 'faq-cancel-purchase' => 'faq', 'faq-rental-return' => 'faq', 'faq-rental-extension' => 'faq',
                'faq-rental-damage' => 'faq', 'faq-light' => 'faq', 'faq-watering' => 'faq', 'faq-new-plant' => 'faq',
            ], [
                'faq-start-a-return' => 'purchases', 'faq-eligible-items' => 'purchases', 'faq-refund-timing' => 'purchases', 'faq-damaged-plant' => 'purchases',
                'faq-incorrect-item' => 'purchases', 'faq-cancel-purchase' => 'purchases', 'faq-rental-return' => 'rentals', 'faq-rental-extension' => 'rentals',
                'faq-rental-damage' => 'rentals', 'faq-light' => 'care', 'faq-watering' => 'care', 'faq-new-plant' => 'care',
            ], ['eyebrow' => 'Returns & FAQs', 'topic_labels' => ['purchases' => 'Purchases', 'rentals' => 'Rentals', 'care' => 'Plant care']]),
            'privacy' => $roleMap([
                'hero' => 'hero', 'draft-status' => 'policy-section', 'information-collected' => 'policy-section', 'purposes-and-choice' => 'policy-section',
                'sharing-and-transfers' => 'policy-section', 'cookies-and-technical-data' => 'policy-section', 'security-and-retention' => 'policy-section',
                'your-rights' => 'policy-section', 'questions-and-complaints' => 'policy-section',
            ], [], ['eyebrow' => 'Privacy & POPIA']),
            'terms' => $roleMap([
                'hero' => 'hero', 'using-the-website' => 'policy-section', 'products-and-availability' => 'policy-section', 'orders-and-payment' => 'policy-section',
                'delivery-and-collection' => 'policy-section', 'rentals' => 'policy-section', 'returns-and-cancellations' => 'policy-section',
                'personal-information' => 'policy-section', 'questions-and-disputes' => 'policy-section', 'buy-summary' => 'terms-summary', 'rent-summary' => 'terms-summary',
            ], [], ['eyebrow' => 'Terms of use & sale']),
            default => $common,
        };
    }
}
