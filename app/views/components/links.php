<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <!-- Main Content Area -->
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
<!-- Dashboard Content -->
<div class="bg-white rounded-lg p-6 shadow-sm">
   <h1 class="text-2xl font-bold text-gray-900 mb-4">Links Components</h1>
   <p class="text-gray-600 mb-6">Comprehensive links system with headers, footers, colors, and interactive styles</p>
   <!-- Content -->
   <div class="space-y-8">
<!-- Basic Card with Icon Header -->
<div class="border-l-4 border-blue-500 pl-4">
<div class="w-full max-w-sm bg-white shadow-xl rounded-2xl p-6 space-y-6">

    <!-- Add Your Brand -->
    <div class="space-y-3">
      <h2 class="text-lg font-semibold text-gray-800">Add your brand</h2>

      <div class="space-y-1">
        <label class="text-sm text-gray-600">Logo URL</label>
        <input type="text"
          placeholder="https://yourlogourl.com"
          class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-400 focus:outline-none">
      </div>

      <div class="grid grid-cols-2 gap-4">
        <!-- Primary Colour -->
        <div class="space-y-1">
          <label class="text-sm text-gray-600">Primary colour</label>
          <div class="flex items-center gap-2 border border-gray-200 rounded-lg px-3 py-2">
            <input type="text"
              value="#353654"
              class="w-full text-sm outline-none">
            <div class="w-8 h-6 rounded-md" style="background:#353654;"></div>
          </div>
        </div>

        <!-- Secondary Colour -->
        <div class="space-y-1">
          <label class="text-sm text-gray-600">Secondary colour</label>
          <div class="flex items-center gap-2 border border-gray-200 rounded-lg px-3 py-2">
            <input type="text"
              value="#BBD4FB"
              class="w-full text-sm outline-none">
            <div class="w-6 h-6 rounded-md" style="background:#BBD4FB;"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Markup -->
    <div>
      <h2 class="text-lg font-semibold text-gray-800 mb-3">Add markup</h2>

      <div class="grid grid-cols-3 gap-3">

        <!-- Currency -->
        <div class="col-span-1">
          <label class="text-sm text-gray-600">Currency</label>
          <select class="select w-full ">
            <option class="option1">USD</option>
            <option class="option2">PKR</option>
            <option class="option3">EUR</option>
          </select>
        </div>

        <!-- Markup # -->
        <div class="col-span-1">
          <label class="text-sm text-gray-600">Markup #</label>
          <input type="text" value="$ 5.00"
            class="w-full input">
        </div>

        <!-- Markup % -->
        <div class="col-span-1">
          <label class="text-sm text-gray-600">Markup %</label>
          <input type="text" value="10 %"
            class="w-full input">
        </div>

      </div>
    </div>

    <!-- Button -->
    <button class="w-full btn bg-[#100024] text-sm font-semibold transition">
      Create link
    </button>

  </div>

<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxOne', 'toggleBtnOne')" class="btn" id="toggleBtnOne">
    Show Code
  </button>
</div>
<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxOne">
<pre class="line-numbers language-markup"><code class="language-html"><div class="w-full max-w-sm bg-white shadow-xl rounded-2xl p-6 space-y-6">

    <!-- Add Your Brand -->
    <div class="space-y-3">
      <h2 class="text-lg font-semibold text-gray-800">Add your brand</h2>

      <div class="space-y-1">
        <label class="text-sm text-gray-600">Logo URL</label>
        <input type="text"
          placeholder="https://yourlogourl.com"
          class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-400 focus:outline-none">
      </div>

      <div class="grid grid-cols-2 gap-4">
        <!-- Primary Colour -->
        <div class="space-y-1">
          <label class="text-sm text-gray-600">Primary colour</label>
          <div class="flex items-center gap-2 border border-gray-200 rounded-lg px-3 py-2">
            <input type="text"
              value="#353654"
              class="w-full text-sm outline-none">
            <div class="w-8 h-6 rounded-md" style="background:#353654;"></div>
          </div>
        </div>

        <!-- Secondary Colour -->
        <div class="space-y-1">
          <label class="text-sm text-gray-600">Secondary colour</label>
          <div class="flex items-center gap-2 border border-gray-200 rounded-lg px-3 py-2">
            <input type="text"
              value="#BBD4FB"
              class="w-full text-sm outline-none">
            <div class="w-6 h-6 rounded-md" style="background:#BBD4FB;"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Markup -->
    <div>
      <h2 class="text-lg font-semibold text-gray-800 mb-3">Add markup</h2>

      <div class="grid grid-cols-3 gap-3">

        <!-- Currency -->
        <div class="col-span-1">
          <label class="text-sm text-gray-600">Currency</label>
          <select class="select w-full ">
            <option class="option1">USD</option>
            <option class="option2">PKR</option>
            <option class="option3">EUR</option>
          </select>
        </div>

        <!-- Markup # -->
        <div class="col-span-1">
          <label class="text-sm text-gray-600">Markup #</label>
          <input type="text" value="$ 5.00"
            class="w-full input">
        </div>

        <!-- Markup % -->
        <div class="col-span-1">
          <label class="text-sm text-gray-600">Markup %</label>
          <input type="text" value="10 %"
            class="w-full input">
        </div>

      </div>
    </div>

    <!-- Button -->
    <button class="w-full btn bg-[#100024] text-sm font-semibold transition">
      Create link
    </button>

  </div></code></pre>
</div>
</div>




<!-- JS for code toggle button  -->
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
</style>