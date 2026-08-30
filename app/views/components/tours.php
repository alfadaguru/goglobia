<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <!-- Main Content Area -->
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
<!-- Dashboard Content -->
<div class="bg-white rounded-lg p-6 shadow-sm">
   <h1 class="text-2xl font-bold text-gray-900 mb-4">Tours Components</h1>
   <p class="text-gray-600 mb-6">Comprehensive tours system with headers, footers, colors, and interactive styles</p>
   <!-- Content -->
   <div class="space-y-8">
<!-- Basic Card with Icon Header -->
<div class="border-l-4 border-blue-500 pl-4">
 <div class="max-w-xl mx-auto bg-white rounded-2xl overflow-hidden border shadow-sm">
    <!-- Image section -->
    <div class="relative h-24 sm:h-28">
      <img src="https://images.unsplash.com/photo-1483729558449-99ef09a8c325?auto=format&fit=crop&w=1200&q=70"alt="Polar" class="w-full h-full object-cover"/>

      <!-- Overlay -->
      <div class="absolute inset-0 bg-black/35"></div>

      <!-- Content over image -->
      <div class="absolute inset-0 flex items-center justify-between px-4">
        <div class="text-white text-lg sm:text-xl font-semibold">
          Polar
        </div>

        <div class="flex gap-2">
          <button class="px-4 h-9 rounded-full border border-white/70 text-white text-sm bg-white/10 backdrop-blur hover:bg-white/20 transition">
            All Adventures
          </button>
          <button class="px-4 h-9 rounded-full border border-white/70 text-white text-sm bg-white/10 backdrop-blur hover:bg-white/20 transition">
            Deals
          </button>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="px-4 py-4">
      <div class="flex gap-10 text-sm">
        <button class="relative font-semibold text-gray-500 hover:text-gray-800">
          Antarctica
        </button>

        <button class="font-medium text-gray-500 hover:text-gray-800 transition">
          The Arctic
        </button>
      </div>
    </div>
  </div>

<div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxOne', 'toggleBtnOne')" class="btn" id="toggleBtnOne">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxOne">
<pre class="line-numbers language-markup"><code class="language-html"> <div class="max-w-xl mx-auto bg-white rounded-2xl overflow-hidden border shadow-sm">
    <!-- Image section -->
    <div class="relative h-24 sm:h-28">
      <img src="https://images.unsplash.com/photo-1483729558449-99ef09a8c325?auto=format&fit=crop&w=1200&q=70"alt="Polar" class="w-full h-full object-cover"/>

      <!-- Overlay -->
      <div class="absolute inset-0 bg-black/35"></div>

      <!-- Content over image -->
      <div class="absolute inset-0 flex items-center justify-between px-4">
        <div class="text-white text-lg sm:text-xl font-semibold">
          Polar
        </div>

        <div class="flex gap-2">
          <button class="px-4 h-9 rounded-full border border-white/70 text-white text-sm bg-white/10 backdrop-blur hover:bg-white/20 transition">
            All Adventures
          </button>
          <button class="px-4 h-9 rounded-full border border-white/70 text-white text-sm bg-white/10 backdrop-blur hover:bg-white/20 transition">
            Deals
          </button>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="px-4 py-4">
      <div class="flex gap-10 text-sm">
        <button class="relative font-semibold text-gray-500 hover:text-gray-800">
          Antarctica
        </button>

        <button class="font-medium text-gray-500 hover:text-gray-800 transition">
          The Arctic
        </button>
      </div>
    </div>
  </div></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Title -->
    <h2 class="text-xl sm:text-xl font-semibold text-gray-900">
      Travel With The Best Tour Operators
    </h2>

    <!-- Grid -->
    <div class="mt-7 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      <!-- Card -->
      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/G_Adventures-bde9.png" alt="G Adventures">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">G Adventures</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.7</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/>
              </svg>
              <span class="text-gray-500 text-xs">14,222 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/Contiki-5d79.png" alt="Contiki">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Contiki</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.7</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">6,216 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/Globus-2607.png" alt="Globus">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Globus</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">5.0</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">37,874 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/trafalgar-1264.png" alt="Trafalgar">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Trafalgar</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.5</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">2,133 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/collette-vacations-4e29.png" alt="Collette">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Collette</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.6</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">7,568 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/intrepid-premium-cdf1.png" alt="Intrepid Premium">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Intrepid Premium</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.4</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">300 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/Cosmos-0f3d.png" alt="Cosmos">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Cosmos</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.9</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">10,474 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/topdeck-c07a.png" alt="Topdeck">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Topdeck</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.6</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">4,683 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto rounded-xl" src="https://cdn.tourradar.com/s3/op/206x150/Expat_Explore_Travel-abcd.png" alt="Expat Explore Travel">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Expat Explore Travel</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.5</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">5,733 reviews</span>
            </div>
          </div>
        </div>
      </a>
    </div>
  </section>

<div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwo', 'toggleBtnTwo')" class="btn" id="toggleBtnTwo">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTwo">
<pre class="line-numbers language-markup"><code class="language-html"><section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Title -->
    <h2 class="text-xl sm:text-xl font-semibold text-gray-900">
      Travel With The Best Tour Operators
    </h2>

    <!-- Grid -->
    <div class="mt-7 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      <!-- Card -->
      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/G_Adventures-bde9.png" alt="G Adventures">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">G Adventures</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.7</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/>
              </svg>
              <span class="text-gray-500 text-xs">14,222 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/Contiki-5d79.png" alt="Contiki">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Contiki</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.7</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">6,216 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/Globus-2607.png" alt="Globus">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Globus</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">5.0</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">37,874 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/trafalgar-1264.png" alt="Trafalgar">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Trafalgar</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.5</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">2,133 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/collette-vacations-4e29.png" alt="Collette">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Collette</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.6</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">7,568 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/intrepid-premium-cdf1.png" alt="Intrepid Premium">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Intrepid Premium</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.4</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">300 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/Cosmos-0f3d.png" alt="Cosmos">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Cosmos</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.9</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">10,474 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto" src="https://cdn.tourradar.com/s3/op/206x150/topdeck-c07a.png" alt="Topdeck">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Topdeck</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.6</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">4,683 reviews</span>
            </div>
          </div>
        </div>
      </a>

      <a href="#" class="group block rounded-2xl border border-gray-200 bg-white shadow-sm hover:shadow-md transition">
        <div class="flex items-center gap-5 px-2 py-2">
          <div class="w-16 h-10 flex items-center justify-center">
            <img class="max-h-10 w-auto rounded-xl" src="https://cdn.tourradar.com/s3/op/206x150/Expat_Explore_Travel-abcd.png" alt="Expat Explore Travel">
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-900 truncate">Expat Explore Travel</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-gray-600">
              <span class="font-semibold text-gray-900 text-xs">4.5</span>
              <svg class="w-4 h-4 text-gray-900" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.954a1 1 0 00.95.69h4.156c.969 0 1.371 1.24.588 1.81l-3.362 2.443a1 1 0 00-.364 1.118l1.286 3.954c.3.921-.755 1.688-1.54 1.118L10.59 15.1a1 1 0 00-1.176 0l-3.362 2.443c-.784.57-1.838-.197-1.54-1.118l1.286-3.954a1 1 0 00-.364-1.118L2.07 9.38c-.783-.57-.38-1.81.588-1.81h4.156a1 1 0 00.95-.69l1.286-3.954z"/></svg>
              <span class="text-gray-500 text-xs">5,733 reviews</span>
            </div>
          </div>
        </div>
      </a>
    </div>
  </section></code></pre>
</div>
</div> 


<div class="border-l-4 border-blue-500 pl-4">
  <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10"
    x-data="chipsUI()"
    x-init="init()"
  >
    <template x-for="(row, r) in rows" :key="r">
      <div class="mb-10">
        <!-- Title -->
        <div class="flex items-center gap-3 text-gray-900">
          <span class="w-9 h-9 rounded-xl bg-gray-50 border border-gray-200 grid place-items-center">
            <span x-html="row.icon"></span>
          </span>
          <h3 class="text-lg font-semibold" x-text="row.title"></h3>
        </div>

        <!-- Scroll rail -->
        <div class="mt-4 relative">
          <div class="flex gap-4 overflow-x-auto no-scrollbar pb-3 px-1 scroll-smooth snap-x snap-mandatory"
               :id="`rail-${r}`"
               @scroll="sync(r)">
            <template x-for="(it, i) in row.items" :key="i">
              <a href="#"
                 class="snap-start min-w-[180px] sm:min-w-[220px] bg-white border border-gray-200 rounded-2xl shadow-sm hover:shadow-md transition flex items-center gap-4 px-4 py-4">
                <img class="w-12 h-12 rounded-xl object-cover" :src="it.img" :alt="it.name">
                <span class="text-gray-900 font-medium" x-text="it.name"></span>
              </a>
            </template>
          </div>

          <!-- Left -->
          <button type="button"
            class="absolute left-0 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-white border border-gray-200 shadow-sm hover:shadow-md transition grid place-items-center"
            :class="pos[r]?.atStart ? 'opacity-0 pointer-events-none' : 'opacity-100'"
            @click="move(r,-1)"
            aria-label="Previous">
            <svg class="w-5 h-5 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M15 18l-6-6 6-6" />
            </svg>
          </button>

          <!-- Right -->
          <button type="button"
            class="absolute right-0 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-white border border-gray-200 shadow-sm hover:shadow-md transition grid place-items-center"
            :class="pos[r]?.atEnd ? 'opacity-0 pointer-events-none' : 'opacity-100'"
            @click="move(r,1)"
            aria-label="Next">
            <svg class="w-5 h-5 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 18l6-6-6-6" />
            </svg>
          </button>

          <!-- edge fade -->
          <div class="pointer-events-none absolute inset-y-0 left-0 w-10 bg-gradient-to-r from-white to-white/0"></div>
          <div class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-white to-white/0"></div>
        </div>
      </div>
    </template>
  </section>

  <script>
    function chipsUI() {
      return {
        step: 320,
        pos: {},
        rows: [
          {
            title: 'Hiking & Trekking',
            icon: `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 21l3-9 3 9"/><path d="M4 11l5-5 3 3 4-4 4 4"/></svg>`,
            items: [
              { name: 'Nepal', img: 'https://cdn.tourradar.com/s3/content-pages/391/120x120/hQ2YMG.jpg' },
              { name: 'Everest Base Camp', img: 'https://images.unsplash.com/photo-1501785888041-af3ef285b470?auto=format&fit=crop&w=200&q=70' },
              { name: 'Machu Picchu', img: 'https://images.unsplash.com/photo-1526392060635-9d6019884377?auto=format&fit=crop&w=200&q=70' },
              { name: 'Kilimanjaro', img: 'https://images.unsplash.com/photo-1526778548025-fa2f459cd5c1?auto=format&fit=crop&w=200&q=70' },
              { name: 'Spain', img: 'https://images.unsplash.com/photo-1502602898657-3e91760cbb34?auto=format&fit=crop&w=200&q=70' }
            ]
          },
          {
            title: 'River Cruises',
            icon: `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 18c3 2 6 2 9 0s6-2 9 0"/><path d="M7 11h10l-2-4H9l-2 4Z"/></svg>`,
            items: [
              { name: 'Nile', img: 'https://images.unsplash.com/photo-1524492412937-b28074a5d7da?auto=format&fit=crop&w=200&q=70' },
              { name: 'Danube', img: 'https://images.unsplash.com/photo-1505761671935-60b3a7427bad?auto=format&fit=crop&w=200&q=70' },
              { name: 'Rhine', img: 'https://images.unsplash.com/photo-1467269204594-9661b134dd2b?auto=format&fit=crop&w=200&q=70' },
              { name: 'Main', img: 'https://images.unsplash.com/photo-1446776811953-b23d57bd21aa?auto=format&fit=crop&w=200&q=70' },
              { name: 'Mekong', img: 'https://images.unsplash.com/photo-1512453979798-5ea266f8880c?auto=format&fit=crop&w=200&q=70' }
            ]
          },
          {
            title: 'Safari',
            icon: `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3c4 0 7 3 7 7 0 5-4 9-7 11-3-2-7-6-7-11 0-4 3-7 7-7Z"/></svg>`,
            items: [
              { name: 'Africa', img: 'https://images.unsplash.com/photo-1516426122078-c23e76319801?auto=format&fit=crop&w=200&q=70' },
              { name: 'Tanzania', img: 'https://images.unsplash.com/photo-1523805009345-7448845a9e53?auto=format&fit=crop&w=200&q=70' },
              { name: 'Kenya', img: 'https://images.unsplash.com/photo-1489515217757-5fd1be406fef?auto=format&fit=crop&w=200&q=70' },
              { name: 'South Africa', img: 'https://images.unsplash.com/photo-1508672019048-805c876b67e2?auto=format&fit=crop&w=200&q=70' },
              { name: 'Botswana', img: 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=200&q=70' }
            ]
          },
          {
            title: 'Explore Europe',
            icon: `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M7 20V6a3 3 0 016 0v14"/><path d="M5 20h14"/></svg>`,
            items: [
              { name: 'Train & Rail', img: 'https://images.unsplash.com/photo-1469474968028-56623f02e42e?auto=format&fit=crop&w=200&q=70' },
              { name: 'River Cruise', img: 'https://cdn.tourradar.com/s3/content-pages/391/120x120/OzA5Os.jpg' },
              { name: 'Bicycle', img: 'https://cdn.tourradar.com/s3/content-pages/391/120x120/d27mqF.jpg' },
              { name: 'Budget', img: 'https://images.unsplash.com/photo-1500375592092-40eb2168fd21?auto=format&fit=crop&w=200&q=70' },
              { name: 'Family', img: 'https://images.unsplash.com/photo-1529156069898-49953e39b3ac?auto=format&fit=crop&w=200&q=70' }
            ]
          }
        ],
        init() {
          this.rows.forEach((_, r) => this.$nextTick(() => this.sync(r)));
          window.addEventListener('resize', () => this.rows.forEach((_, r) => this.sync(r)));
        },
        rail(r) { return document.getElementById(`rail-${r}`); },
        move(r, dir) {
          const el = this.rail(r);
          el.scrollBy({ left: dir * this.step, behavior: 'smooth' });
          setTimeout(() => this.sync(r), 220);
        },
        sync(r) {
          const el = this.rail(r);
          const atStart = el.scrollLeft <= 2;
          const atEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 2;
          this.pos[r] = { atStart, atEnd };
        }
      }
    }
  </script>

<div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxThree', 'toggleBtnThree')" class="btn" id="toggleBtnThree">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxThree">
<pre class="line-numbers language-markup"><code class="language-html"></code></pre>
</div>
</div> 


<!-- <div class="border-l-4 border-blue-500 pl-4">


<div class="mb-3 flex items-end justify-end text-left">
  Toggle Button
  <button 
   onclick="toggleCode('codeBoxOne', 'toggleBtnOne')" class="btn" id="toggleBtnOne">
    Show Code
  </button>
</div>

Collapsible Code Block
<div class="hidden transition-all duration-300" id="codeBoxOne">
<pre class="line-numbers language-markup"><code class="language-html"></code></pre>
</div>
</div> -->

<!-- end  -->
 <!-- JS for code toogle button -->
<script>
  function toggleCode(boxId, btnId) {
    const box = document.getElementById(boxId);
    const btn = document.getElementById(btnId);
    box.classList.toggle("hidden");
    if (box.classList.contains("hidden")) {
      btn.innerText = "Show Code";
    } else {
      btn.innerText = "Hide Code";
    }
  }
</script>
      </div>
   </div>
</div>
</div>
</div>
</div>
<style>
   [x-cloak] { display: none !important; }
   /* optional: hide scrollbar */
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>