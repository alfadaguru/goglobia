<?php
// ============================================================================
// AGENT SIGNUP LANDING PAGE - B2B TRAVEL AGENT REGISTRATION
// ============================================================================
// PURPOSE: Professional landing page for travel agent registration
// SEO OPTIMIZED: Agent signup, travel agent registration, B2B travel platform
// FEATURES:
// - Hero section with value proposition
// - Feature highlights for agents
// - How it works step-by-step guide
// - CTA to signup page
// ============================================================================
?>

<!-- Hero Section -->
<section class="py-16 lg:py-24 bg-gradient-to-br from-white to-gray-50">
   <div class="container mx-auto px-4">
      <div class="grid lg:grid-cols-2 gap-12 items-center">
         <!-- Left Content -->
         <div class="space-y-4" data-aos="fade-right">
            <h1 class="text-4xl lg:text-5xl xl:text-2xl font-bold text-gray-900 leading-tight">
               <?= T::join ?? 'Join' ?> <span class="text-primary"><?= $business_name ?? 'Our Platform' ?></span>
               <?= T::as_travel_agent ?? 'as a Travel Agent Today!' ?>
            </h1>
            <p class="text-lg lg:text-xl text-gray-600 leading-relaxed">
               <?= T::agent_signup_subtitle ?? 'Access exclusive wholesale rates and premium inventory to grow your travel agency business. Get the best pricing in the market and build better partnerships with our official network.' ?>
            </p>
            <div class="flex flex-wrap gap-2 sm:gap-4 pt-4">
               <a href="#signup-section" class="btn btn-lg inline-flex items-center gap-2 px-5 sm:px-8 py-4">
                  <span class="material-symbols-outlined">person_add</span>
                  <?= T::get_started ?? 'Get Started' ?>
               </a>
               <a href="#how-it-works" class="btn light btn-lg inline-flex items-center gap-2 px-5 sm:px-8 py-4">
                  <span class="material-symbols-outlined">info</span>
                  <?= T::learn_more ?? 'Learn More' ?>
               </a>
            </div>

            <!-- Quick Stats -->
            <div class="grid grid-cols-3 gap-6 pt-8 border-t border-gray-200">
               <div>
                  <div class="text-3xl font-bold text-primary">500K+</div>
                  <div class="text-sm text-gray-600"><?= T::properties_worldwide ?? 'Properties Worldwide' ?></div>
               </div>
               <div>
                  <div class="text-3xl font-bold text-primary">40%</div>
                  <div class="text-sm text-gray-600"><?= T::discount_rates ?? 'Discount on Rates' ?></div>
               </div>
               <div>
                  <div class="text-3xl font-bold text-primary">24/7</div>
                  <div class="text-sm text-gray-600"><?= T::agent_support ?? 'Agent Support' ?></div>
               </div>
            </div>
         </div>

         <!-- Right Image -->
         <div class="relative" data-aos="fade-left">
            <div class="rounded-2xl overflow-hidden shadow-2xl border border-gray-200">
               <img src="<?= root ?>assets/img/agent.jpg"
                    alt="Travel Agent Registration - Join <?= $business_name ?? 'Our Platform' ?>"
                    class="w-full h-full object-cover"
                    loading="lazy">
            </div>
            <!-- Floating Card -->
            <div class="absolute -bottom-6 -left-6 bg-white rounded-xl shadow-xl p-6 border border-gray-200 hidden lg:block">
               <div class="flex items-center gap-3">
                  <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                     <span class="material-symbols-outlined text-green-600">verified</span>
                  </div>
                  <div>
                     <div class="text-sm font-semibold text-gray-900"><?= T::verified_platform ?? 'Verified Platform' ?></div>
                     <div class="text-xs text-gray-600"><?= T::trusted_agents ?? 'Trusted by Agents Worldwide' ?></div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- Features Section -->
<section class="py-16 lg:py-20 bg-gray-50">
   <div class="container mx-auto px-4">
      <div class="text-center mb-12" data-aos="fade-up">
         <h2 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-4">
            <?= T::why_join_us ?? 'Why Join Us?' ?>
         </h2>
         <p class="text-lg text-gray-600 max-w-3xl mx-auto">
            <?= T::agent_benefits_subtitle ?? 'Access wholesale rates and premium inventory to boost your travel agency revenue and provide exceptional service to your clients.' ?>
         </p>
      </div>

      <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
         <!-- Feature 1 -->
         <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 hover:shadow-md transition-shadow" data-aos="fade-up" data-aos-delay="100">
            <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mb-4">
               <span class="material-symbols-outlined text-3xl text-blue-600">payments</span>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-3"><?= T::wholesale_rates ?? 'Wholesale Rates' ?></h3>
            <p class="text-gray-600 leading-relaxed">
               <?= T::wholesale_rates_desc ?? 'Access exclusive B2B rates up to 40% below retail prices across 500,000+ properties worldwide to maximize your profit margins.' ?>
            </p>
         </div>

         <!-- Feature 2 -->
         <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 hover:shadow-md transition-shadow" data-aos="fade-up" data-aos-delay="200">
            <div class="w-16 h-16 rounded-full bg-purple-100 flex items-center justify-center mb-4">
               <span class="material-symbols-outlined text-3xl text-purple-600">hotel</span>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-3"><?= T::premium_inventory ?? 'Premium Inventory' ?></h3>
            <p class="text-gray-600 leading-relaxed">
               <?= T::premium_inventory_desc ?? 'Book luxury hotels, resorts, and unique properties with guaranteed availability and real-time confirmation for your clients.' ?>
            </p>
         </div>

         <!-- Feature 3 -->
         <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 hover:shadow-md transition-shadow" data-aos="fade-up" data-aos-delay="300">
            <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mb-4">
               <span class="material-symbols-outlined text-3xl text-green-600">dashboard</span>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-3"><?= T::agent_dashboard ?? 'Agent Dashboard' ?></h3>
            <p class="text-gray-600 leading-relaxed">
               <?= T::agent_dashboard_desc ?? 'Manage bookings, access reports, and track commissions through our intuitive platform designed specifically for travel agents.' ?>
            </p>
         </div>

         <!-- Feature 4 -->
         <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 hover:shadow-md transition-shadow" data-aos="fade-up" data-aos-delay="400">
            <div class="w-16 h-16 rounded-full bg-orange-100 flex items-center justify-center mb-4">
               <span class="material-symbols-outlined text-3xl text-orange-600">support_agent</span>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-3"><?= T::dedicated_support ?? 'Dedicated Support' ?></h3>
            <p class="text-gray-600 leading-relaxed">
               <?= T::dedicated_support_desc ?? 'Get priority access to our 24/7 travel agent support team for quick resolution of booking modifications and queries.' ?>
            </p>
         </div>
      </div>
   </div>
</section>

<!-- How It Works Section -->
<section id="how-it-works" class="py-16 lg:py-20 bg-white">
   <div class="container mx-auto px-4">
      <div class="text-center mb-12" data-aos="fade-up">
         <h2 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-4">
            <?= T::how_it_works ?? 'How Does It Work?' ?>
         </h2>
         <p class="text-lg text-gray-600 max-w-2xl mx-auto">
            <?= T::how_it_works_subtitle ?? 'Start booking with wholesale rates today in just four simple steps' ?>
         </p>
      </div>

      <div class="grid lg:grid-cols-2 gap-12 items-center">
         <!-- Steps -->
         <div class="space-y-6" data-aos="fade-right">
            <!-- Step 1 -->
            <div class="flex gap-4 items-start">
               <div class="flex-shrink-0 w-12 h-12 rounded-full bg-primary text-white flex items-center justify-center text-xl font-bold">
                  1
               </div>
               <div class="flex-1">
                  <h3 class="text-xl font-bold text-gray-900 mb-2"><?= T::register_as_agent ?? 'Register as an Agent' ?></h3>
                  <p class="text-gray-600 leading-relaxed">
                     <?= T::register_as_agent_desc ?? 'Complete our simple verification process to confirm your agency credentials and get instant access to the platform.' ?>
                  </p>
               </div>
            </div>

            <!-- Step 2 -->
            <div class="flex gap-4 items-start">
               <div class="flex-shrink-0 w-12 h-12 rounded-full bg-primary text-white flex items-center justify-center text-xl font-bold">
                  2
               </div>
               <div class="flex-1">
                  <h3 class="text-xl font-bold text-gray-900 mb-2"><?= T::access_dashboard ?? 'Access Your Dashboard' ?></h3>
                  <p class="text-gray-600 leading-relaxed">
                     <?= T::access_dashboard_desc ?? 'Get instant access to wholesale rates and premium inventory through your dedicated agent portal with advanced search tools.' ?>
                  </p>
               </div>
            </div>

            <!-- Step 3 -->
            <div class="flex gap-4 items-start">
               <div class="flex-shrink-0 w-12 h-12 rounded-full bg-primary text-white flex items-center justify-center text-xl font-bold">
                  3
               </div>
               <div class="flex-1">
                  <h3 class="text-xl font-bold text-gray-900 mb-2"><?= T::make_bookings ?? 'Make Bookings' ?></h3>
                  <p class="text-gray-600 leading-relaxed">
                     <?= T::make_bookings_desc ?? 'Search, filter, and book with exclusive B2B rates with instant confirmation and secure payment processing.' ?>
                  </p>
               </div>
            </div>

            <!-- Step 4 -->
            <div class="flex gap-4 items-start">
               <div class="flex-shrink-0 w-12 h-12 rounded-full bg-primary text-white flex items-center justify-center text-xl font-bold">
                  4
               </div>
               <div class="flex-1">
                  <h3 class="text-xl font-bold text-gray-900 mb-2"><?= T::grow_business ?? 'Grow Your Business' ?></h3>
                  <p class="text-gray-600 leading-relaxed">
                     <?= T::grow_business_desc ?? 'Track your bookings, manage client requests, monitor commissions, and expand your travel business with our comprehensive tools.' ?>
                  </p>
               </div>
            </div>
         </div>

         <!-- Image -->
         <div class="relative" data-aos="fade-left">
            <div class="rounded-2xl overflow-hidden shadow-xl border border-gray-200">
               <img src="<?= root ?>assets/img/agent2.jpg"
                    alt="How to register as travel agent on <?= $business_name ?? 'our platform' ?>"
                    class="w-full h-full object-cover"
                    loading="lazy">
            </div>
         </div>
      </div>
   </div>
</section>

<!-- Benefits Section -->
<section class="py-16 lg:py-20 bg-gradient-to-br from-primary/5 to-primary/10">
   <div class="container mx-auto px-4">
      <div class="max-w-4xl mx-auto">
         <div class="text-center mb-12" data-aos="fade-up">
            <h2 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-4">
               <?= T::additional_benefits ?? 'Additional Benefits' ?>
            </h2>
            <p class="text-lg text-gray-600">
               <?= T::additional_benefits_subtitle ?? 'More reasons to partner with us' ?>
            </p>
         </div>

         <div class="grid md:grid-cols-2 gap-6">
            <div class="flex items-start gap-4 bg-white rounded-xl p-6 shadow-sm border border-gray-200" data-aos="fade-up" data-aos-delay="100">
               <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
               <div>
                  <h4 class="font-bold text-gray-900 mb-1"><?= T::instant_confirmation ?? 'Instant Confirmation' ?></h4>
                  <p class="text-gray-600 text-sm"><?= T::instant_confirmation_desc ?? 'Real-time booking confirmation for seamless client experience' ?></p>
               </div>
            </div>

            <div class="flex items-start gap-4 bg-white rounded-xl p-6 shadow-sm border border-gray-200" data-aos="fade-up" data-aos-delay="200">
               <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
               <div>
                  <h4 class="font-bold text-gray-900 mb-1"><?= T::flexible_payment ?? 'Flexible Payment Options' ?></h4>
                  <p class="text-gray-600 text-sm"><?= T::flexible_payment_desc ?? 'Multiple payment methods and credit facilities available' ?></p>
               </div>
            </div>

            <div class="flex items-start gap-4 bg-white rounded-xl p-6 shadow-sm border border-gray-200" data-aos="fade-up" data-aos-delay="300">
               <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
               <div>
                  <h4 class="font-bold text-gray-900 mb-1"><?= T::training_support ?? 'Training & Resources' ?></h4>
                  <p class="text-gray-600 text-sm"><?= T::training_support_desc ?? 'Access training materials and marketing resources' ?></p>
               </div>
            </div>

            <div class="flex items-start gap-4 bg-white rounded-xl p-6 shadow-sm border border-gray-200" data-aos="fade-up" data-aos-delay="400">
               <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
               <div>
                  <h4 class="font-bold text-gray-900 mb-1"><?= T::commission_tracking ?? 'Commission Tracking' ?></h4>
                  <p class="text-gray-600 text-sm"><?= T::commission_tracking_desc ?? 'Transparent commission structure with detailed reporting' ?></p>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- CTA Section -->
<section id="signup-section" class="py-16 lg:py-24 bg-white">
   <div class="container mx-auto px-4">
      <div class="max-w-4xl mx-auto text-center" data-aos="zoom-in">
         <div class="bg-gradient-to-br from-primary to-primary/80 rounded-2xl p-8 lg:p-12 text-white shadow-2xl">
            <h2 class="text-3xl lg:text-4xl font-bold mb-4">
               <?= T::ready_to_join ?? 'Ready to Join Our Network?' ?>
            </h2>
            <p class="text-xl mb-8 text-white/90">
               <?= T::ready_to_join_subtitle ?? 'Start accessing wholesale rates and grow your travel business today' ?>
            </p>

            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
               <a href="<?= root ?>signup?type=agent" class="btn btn-lg bg-white text-primary hover:bg-gray-100 inline-flex items-center gap-2 px-8 py-4 text-lg font-semibold shadow-lg">
                  <span class="material-symbols-outlined">person_add</span>
                  <?= T::agent_signup ?? 'Agent Signup' ?>
               </a>

               <?php if (!empty($contact_email)): ?>
               <a href="mailto:<?= $contact_email ?>" class="btn btn-lg light border-2 border-white text-white hover:bg-white/10 inline-flex items-center gap-2 px-8 py-4 text-lg">
                  <span class="material-symbols-outlined">email</span>
                  <?= T::contact_us ?? 'Contact Us' ?>
               </a>
               <?php endif; ?>
            </div>

            <div class="mt-8 pt-8 border-t border-white/20">
               <p class="text-white/80 text-sm">
                  <?= T::existing_agent ?? 'Already a registered agent?' ?>
                  <a href="<?= root ?>login" class="text-white font-semibold underline hover:no-underline ml-1">
                     <?= T::signin ?? 'Sign In' ?>
                  </a>
               </p>
            </div>
         </div>
      </div>
   </div>
</section>

<!-- FAQ Section -->
<section class="py-16 lg:py-20 bg-gray-50">
   <div class="container mx-auto px-4">
      <div class="max-w-3xl mx-auto">
         <div class="text-center mb-12" data-aos="fade-up">
            <h2 class="text-3xl lg:text-4xl font-bold text-gray-900 mb-4">
               <?= T::frequently_asked ?? 'Frequently Asked Questions' ?>
            </h2>
            <p class="text-lg text-gray-600">
               <?= T::faq_subtitle ?? 'Get answers to common questions about agent registration' ?>
            </p>
         </div>

         <div class="space-y-4" data-aos="fade-up">
            <details class="bg-white rounded-lg border border-gray-200 p-6 cursor-pointer group">
               <summary class="font-bold text-gray-900 flex justify-between items-center">
                  <?= T::faq_eligibility ?? 'Who can register as an agent?' ?>
                  <span class="material-symbols-outlined group-open:rotate-180 transition-transform">expand_more</span>
               </summary>
               <p class="mt-4 text-gray-600 leading-relaxed">
                  <?= T::faq_eligibility_answer ?? 'Any licensed travel agency or registered travel professional can apply. You need valid business credentials and contact information to complete the registration.' ?>
               </p>
            </details>

            <details class="bg-white rounded-lg border border-gray-200 p-6 cursor-pointer group">
               <summary class="font-bold text-gray-900 flex justify-between items-center">
                  <?= T::faq_fees ?? 'Are there any registration fees?' ?>
                  <span class="material-symbols-outlined group-open:rotate-180 transition-transform">expand_more</span>
               </summary>
               <p class="mt-4 text-gray-600 leading-relaxed">
                  <?= T::faq_fees_answer ?? 'Registration is completely free. You only pay when you make bookings through the platform at discounted wholesale rates.' ?>
               </p>
            </details>

            <details class="bg-white rounded-lg border border-gray-200 p-6 cursor-pointer group">
               <summary class="font-bold text-gray-900 flex justify-between items-center">
                  <?= T::faq_approval ?? 'How long does approval take?' ?>
                  <span class="material-symbols-outlined group-open:rotate-180 transition-transform">expand_more</span>
               </summary>
               <p class="mt-4 text-gray-600 leading-relaxed">
                  <?= T::faq_approval_answer ?? 'Most applications are reviewed within 24-48 hours. Once approved, you get immediate access to the agent portal and wholesale rates.' ?>
               </p>
            </details>

            
         </div>
      </div>
   </div>
</section>

<!-- Trust Badges -->
<section class="py-12 bg-white border-t border-gray-200">
   <div class="container mx-auto px-4">
      <div class="flex flex-wrap justify-center items-center gap-8 opacity-60">
         <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600">security</span>
            <span class="text-sm font-semibold text-gray-600"><?= T::secure_platform ?? 'Secure Platform' ?></span>
         </div>
         <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600">verified</span>
            <span class="text-sm font-semibold text-gray-600"><?= T::verified_suppliers ?? 'Verified Suppliers' ?></span>
         </div>
         <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600">support</span>
            <span class="text-sm font-semibold text-gray-600"><?= T::support_247 ?? '24/7 Support' ?></span>
         </div>
         <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600">payments</span>
            <span class="text-sm font-semibold text-gray-600"><?= T::secure_payments ?? 'Secure Payments' ?></span>
         </div>
      </div>
   </div>
</section>
