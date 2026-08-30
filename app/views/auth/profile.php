<?php
// themes/default/auth/profile.php
@$SECURE or die('Access Denied!'); ?>

<div class="bg" x-data="{
    profileLoaded: true,
    sidebarCollapsed: window.innerWidth < 1024,
    isLoading: false,
    showPassword: false,
    showConfirmPassword: false
}">
   <div class="flex gap-5 container mb-8">

      <?php include views."auth/sidebar.php"; ?>

      <div class="flex-1 min-w-0 pt-6 bg-white rounded-[8px] ml-0">

         <!-- Profile Header -->
         <div class="flex items-center justify-between mb-6">
            <div>
               <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-3">
                     <a href="<?=root?>dashboard" class="hover:text-gray-700"><?= T::dashboard ?></a>
                     <span class="material-symbols-outlined !text-sm">chevron_right</span>
                     <span class="text-gray-900"><?= T::profile ?></span>
               </nav>
         <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
               <!-- Mobile Menu Toggle -->
               <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-blue-50 rounded-lg text-blue-600 hover:bg-blue-100 transition-colors">
                  <span class="material-symbols-outlined text-[28px]">menu</span>
               </button>

               <!-- Profile Icon -->
               <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-blue-100 rounded-lg">
                  <span class="material-symbols-outlined text-[28px] text-blue-600">person</span>
               </div>
               <div>
                  <h1 class="text-2xl font-bold text-gray-900"><?= T::profile ?></h1>
                  <p class="text-gray-600 text-sm"><?= T::manage_your_profile ?></p>
               </div>
            </div>
         </div>
            </div>
         </div>

         <!-- Error/Success Messages -->
         <?php if (isset($_SESSION['profile_error'])): ?>
         <div class="alert-error mb-6">
            <span class="material-icon material-symbols-outlined">error</span>
            <p><?= T::error_occurred ?>: <?= $_SESSION['profile_error'] ?></p>
         </div>
         <?php unset($_SESSION['profile_error']); endif; ?>

         <?php if (isset($_SESSION['profile_success'])): ?>
         <div class="alert-success mb-6">
            <span class="material-icon material-symbols-outlined">check_circle</span>
            <p><?= $_SESSION['profile_success'] ?></p>
         </div>
         <?php unset($_SESSION['profile_success']); endif; ?>

         <!-- Main Content Grid -->
         <div class="">

            <!-- Profile Form -->
            <div class="lg:col-span-2">
               <div class="card p-0">
                  <div class="card-header">
                     <h2 class="font-semibold text-gray-900"><?= T::personal_information ?></h2>
                  </div>

                  <form method="POST" class="p-5" action="<?=root?>profile" x-data="{ isSubmitting: false }" @submit="isSubmitting = true">
                     <input type="hidden" name="csrf_token" value="<?= CSRF::getToken() ?>">

                     <div class="card-content">
                        <div class="mb-4">
                           <label for="title" class="block text-sm font-medium text-gray-700 mb-1"><?= T::title ?? 'Title' ?></label>
                           <select id="title" name="title" class="select">
                              <option value="" <?= empty($user['title']) ? 'selected' : '' ?>><?= T::select_title ?? 'Select Title' ?></option>
                              <option value="Mr" <?= ($user['title'] ?? '') === 'Mr' ? 'selected' : '' ?>>Mr</option>
                              <option value="Ms" <?= ($user['title'] ?? '') === 'Ms' ? 'selected' : '' ?>>Ms</option>
                              <option value="Mrs" <?= ($user['title'] ?? '') === 'Mrs' ? 'selected' : '' ?>>Mrs</option>
                              <option value="Dr" <?= ($user['title'] ?? '') === 'Dr' ? 'selected' : '' ?>>Dr</option>
                              <option value="Prof" <?= ($user['title'] ?? '') === 'Prof' ? 'selected' : '' ?>>Prof</option>
                           </select>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                           <div>
                              <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1"><?= T::first_name ?> *</label>
                              <input type="text" id="first_name" name="first_name" required
                                     value="<?= htmlspecialchars($user['first_name'] ?? '') ?>"
                                     class="input">
                           </div>
                           <div>
                              <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1"><?= T::last_name ?> *</label>
                              <input type="text" id="last_name" name="last_name" required
                                     value="<?= htmlspecialchars($user['last_name'] ?? '') ?>"
                                     class="input">
                           </div>
                        </div>

                        <div class="mb-4">
                           <label for="email" class="block text-sm font-medium text-gray-700 mb-1"><?= T::email_address ?> *</label>
                           <input type="email" id="email" name="email" required readonly
                                  value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                                  class="input">
                        </div>

                        <div class="mb-4">
                           <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-1"><?= T::phone_number ?></label>
                           <div class="grid grid-cols-12 gap-2">
                              <div class="col-span-4">
                                 <select id="phone_country_code" name="phone_country_code" class="select">
                                    <option value="">Code</option>
                                    <?php foreach ($countries as $country): ?>
                                       <option value="<?= $country['iso'] ?>" <?= ($user['phone_country_code'] ?? '') === $country['iso'] ? 'selected' : '' ?>>
                                          <?= htmlspecialchars($country['iso']) ?> +<?= htmlspecialchars($country['phonecode']) ?>
                                       </option>
                                    <?php endforeach; ?>
                                 </select>
                              </div>
                              <div class="col-span-8">
                                 <input type="tel" id="phone" name="phone"
                                        value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                        class="input"
                                        placeholder="<?= T::phone_placeholder ?? 'Enter phone without spaces or leading 0' ?>"
                                        x-data="{
                                           cleanPhone() {
                                              let value = $el.value.replace(/\D/g, '');
                                              if (value.startsWith('0')) {
                                                 value = value.substring(1);
                                              }
                                              $el.value = value;
                                           }
                                        }"
                                        @input="cleanPhone()"
                                        @paste="setTimeout(() => cleanPhone(), 0)">
                              </div>
                           </div>
                        </div>

                        <div class="mb-4">
                           <label for="country" class="block text-sm font-medium text-gray-700 mb-1"><?= T::country ?></label>
                           <select id="country" name="country" class="select">
                              <option value=""><?= T::select_country ?? 'Select Country' ?></option>
                              <?php foreach ($countries as $country): ?>
                                 <option value="<?= $country['iso'] ?>" <?= ($user['country'] ?? '') === $country['iso'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($country['nicename']) ?>
                                 </option>
                              <?php endforeach; ?>
                           </select>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                           <div>
                              <label for="state" class="block text-sm font-medium text-gray-700 mb-1"><?= T::state ?? 'State/Province' ?></label>
                              <input type="text" id="state" name="state"
                                     value="<?= htmlspecialchars($user['state'] ?? '') ?>"
                                     class="input">
                           </div>
                           <div>
                              <label for="po_box" class="block text-sm font-medium text-gray-700 mb-1"><?= T::po_box ?? 'P.O. Box' ?></label>
                              <input type="text" id="po_box" name="po_box"
                                     value="<?= htmlspecialchars($user['po_box'] ?? '') ?>"
                                     class="input">
                           </div>
                        </div>

                        <div class="mb-6">
                           <label for="address" class="block text-sm font-medium text-gray-700 mb-1"><?= T::address ?></label>
                           <textarea id="address" name="address" rows="3" class="input"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-full" :disabled="isSubmitting">
                           <span x-show="!isSubmitting"><?= T::update_profile ?></span>
                           <span x-show="isSubmitting" class="flex items-center gap-2">
                              <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                              </svg>
                              <?= T::updating ?>...
                           </span>
                        </button>
                     </div>
                  </form>
               </div>

               <!-- Change Password Section -->
               <div id="change-password" class="card mt-6 p-0 scroll-mt-24">
                  <div class="card-header">
                     <h2 class="text-lg font-semibold text-gray-900"><?= T::change_password ?></h2>
                  </div>

                  <form method="POST" class="p-5" action="<?=root?>profile" x-data="{ isSubmitting: false }" @submit="isSubmitting = true">
                     <input type="hidden" name="csrf_token" value="<?= CSRF::getToken() ?>">
                     <input type="hidden" name="action" value="change_password">

                     <div class="card-content">
                        <div class="mb-4">
                           <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1"><?= T::current_password ?> *</label>
                           <div class="input-group" x-data="{ showPassword: false }">
                              <input :type="showPassword ? 'text' : 'password'" id="current_password" name="current_password" required
                                     class="input-with-icon-right">
                              <span @click="showPassword = !showPassword" class="input-icon-right material-symbols-outlined cursor-pointer"
                                    x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
                           </div>
                        </div>

                        <div class="mb-4">
                           <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1"><?= T::new_password ?> *</label>
                           <div class="input-group" x-data="{ showPassword: false }">
                              <input :type="showPassword ? 'text' : 'password'" id="new_password" name="new_password" required
                                     class="input-with-icon-right">
                              <span @click="showPassword = !showPassword" class="input-icon-right material-symbols-outlined cursor-pointer"
                                    x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
                           </div>
                        </div>

                        <div class="mb-6">
                           <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1"><?= T::confirm_password ?> *</label>
                           <div class="input-group" x-data="{ showPassword: false }">
                              <input :type="showPassword ? 'text' : 'password'" id="confirm_password" name="confirm_password" required
                                     class="input-with-icon-right">
                              <span @click="showPassword = !showPassword" class="input-icon-right material-symbols-outlined cursor-pointer"
                                    x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
                           </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-full" :disabled="isSubmitting">
                           <span x-show="!isSubmitting"><?= T::update_password ?></span>
                           <span x-show="isSubmitting" class="flex items-center gap-2">
                              <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                              </svg>
                              <?= T::updating ?>...
                           </span>
                        </button>
                     </div>
                  </form>
               </div>
            </div>

            <!-- Account Info Sidebar -->
            <div class="space-y-6">

               <!-- Finance -->





            </div>
         </div>
      </div>
   </div>
</div>