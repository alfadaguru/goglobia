<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <!-- Main Content Area -->
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
<!-- Dashboard Content -->
<div class="bg-white rounded-lg p-6 shadow-sm">
   <h1 class="text-2xl font-bold text-gray-900 mb-4">Hotels Components</h1>
   <p class="text-gray-600 mb-6">Comprehensive hotels system with headers, footers, colors, and interactive styles</p>
   <!-- Content -->
   <div class="space-y-8">
<!-- Basic Card with Icon Header -->
<div class="border-l-4 border-blue-500 pl-4">
<div class="bg-[#0b0c0b] rounded-2xl p-4 sm:p-6 w-full max-w-[360px] border border-gray-700 shadow-xl mb-6">
    <!-- Image -->
    <div class="relative rounded-xl overflow-hidden mb-4">
        <img
            src="https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&h=300&fit=crop"
            alt="Fairmont Heritage Place"
            class="w-full h-40 sm:h-48 object-cover"
        />
    </div>
    <!-- Star Rating -->
    <div class="relative">
        <div
            class="absolute bottom-[3px] left-20 bg-black/75 py-1 rounded-t-lg flex justify-center gap-1 mb-3 text-[#ff9819]"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"
                />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"
                />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"
                />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"
                />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="white"
                    class="text-white"
                    d="m8.85 16.825l3.15-1.9l3.15 1.925l-.825-3.6l2.775-2.4l-3.65-.325l-1.45-3.4l-1.45 3.375l-3.65.325l2.775 2.425zm-1.525 2.098l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102zM12 12.25"
                />
            </svg>
        </div>
    </div>
    <!-- Hotel Name -->
    <h2 class="text-white mt-4 text-xs sm:text-xs font-medium">Fairmont Heritage Place</h2>
    <!-- Location -->
    <div class="flex items-start gap-2 text-sm mb-4 mt-3">
        <span class="-mt-1.5 text-white">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="M11.5 7A2.5 2.5 0 0 1 14 9.5a2.5 2.5 0 0 1-2.5 2.5A2.5 2.5 0 0 1 9 9.5A2.5 2.5 0 0 1 11.5 7m0 1A1.5 1.5 0 0 0 10 9.5a1.5 1.5 0 0 0 1.5 1.5A1.5 1.5 0 0 0 13 9.5A1.5 1.5 0 0 0 11.5 8m-4.7 4.36l4.7 7.73l4.7-7.73c.51-.86.8-1.81.8-2.86A5.5 5.5 0 0 0 11.5 4A5.5 5.5 0 0 0 6 9.5c0 1.05.29 2 .8 2.86m10.25.52L11.5 22l-5.55-9.12C5.35 11.89 5 10.74 5 9.5A6.5 6.5 0 0 1 11.5 3A6.5 6.5 0 0 1 18 9.5c0 1.24-.35 2.39-.95 3.38"
                />
            </svg>
        </span>
        <span class="text-white/70 font-medium text-[11px] leading-tight"> 900 N St, San Francisco </span>
    </div>
    <!-- Pricing Box -->
    <div class="flex border rounded-lg border-gray-700 overflow-hidden">
        <!-- Retail -->
        <div class="flex justify-between sm:gap-8 gap-4 border-r border-gray-700 px-4 py-2 w-1/2">
            <p class="text-gray-300 font-medium text-[10px] leading-tight">Retail<br />Price</p>
            <p class="text-white line-through decoration-red-400 decoration-2 font-medium text-[13px] mt-[3px]">
                $580.14
            </p>
        </div>
        <!-- Traveller -->
        <div class="flex justify-between gap-3 px-4 py-2 w-1/2">
            <p class="text-gray-300 font-medium text-[10px] leading-tight">Price to<br />Traveller</p>
            <p class="text-white text-[13px] font-medium mt-[3px]">$440.14</p>
        </div>
    </div>
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
<div class="hidden transition-all duration-300 mb-6" id="codeBoxOne">
<pre class="line-numbers language-markup"><code class="language-html"><div class="bg-[#0b0c0b] rounded-2xl p-4 sm:p-6 w-full max-w-[360px] border border-gray-700 shadow-xl mb-6">
    <!-- Image -->
    <div class="relative rounded-xl overflow-hidden mb-4">
        <img
            src="https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&h=300&fit=crop"
            alt="Fairmont Heritage Place"
            class="w-full h-40 sm:h-48 object-cover"
        />
    </div>
    <!-- Star Rating -->
    <div class="relative">
        <div
            class="absolute bottom-[3px] left-20 bg-black/75 py-1 rounded-t-lg flex justify-center gap-1 mb-3 text-[#ff9819]"
        >
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"
                />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"
                />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"
                />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"
                />
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24">
                <path
                    fill="white"
                    class="text-white"
                    d="m8.85 16.825l3.15-1.9l3.15 1.925l-.825-3.6l2.775-2.4l-3.65-.325l-1.45-3.4l-1.45 3.375l-3.65.325l2.775 2.425zm-1.525 2.098l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102zM12 12.25"
                />
            </svg>
        </div>
    </div>
    <!-- Hotel Name -->
    <h2 class="text-white mt-4 text-xs sm:text-xs font-medium">Fairmont Heritage Place</h2>
    <!-- Location -->
    <div class="flex items-start gap-2 text-sm mb-4 mt-3">
        <span class="-mt-1.5 text-white">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                <path
                    fill="currentColor"
                    d="M11.5 7A2.5 2.5 0 0 1 14 9.5a2.5 2.5 0 0 1-2.5 2.5A2.5 2.5 0 0 1 9 9.5A2.5 2.5 0 0 1 11.5 7m0 1A1.5 1.5 0 0 0 10 9.5a1.5 1.5 0 0 0 1.5 1.5A1.5 1.5 0 0 0 13 9.5A1.5 1.5 0 0 0 11.5 8m-4.7 4.36l4.7 7.73l4.7-7.73c.51-.86.8-1.81.8-2.86A5.5 5.5 0 0 0 11.5 4A5.5 5.5 0 0 0 6 9.5c0 1.05.29 2 .8 2.86m10.25.52L11.5 22l-5.55-9.12C5.35 11.89 5 10.74 5 9.5A6.5 6.5 0 0 1 11.5 3A6.5 6.5 0 0 1 18 9.5c0 1.24-.35 2.39-.95 3.38"
                />
            </svg>
        </span>
        <span class="text-white/70 font-medium text-[11px] leading-tight"> 900 N St, San Francisco </span>
    </div>
    <!-- Pricing Box -->
    <div class="flex border rounded-lg border-gray-700 overflow-hidden">
        <!-- Retail -->
        <div class="flex justify-between sm:gap-8 gap-4 border-r border-gray-700 px-4 py-2 w-1/2">
            <p class="text-gray-300 font-medium text-[10px] leading-tight">Retail<br />Price</p>
            <p class="text-white line-through decoration-red-400 decoration-2 font-medium text-[13px] mt-[3px]">
                $580.14
            </p>
        </div>
        <!-- Traveller -->
        <div class="flex justify-between gap-3 px-4 py-2 w-1/2">
            <p class="text-gray-300 font-medium text-[10px] leading-tight">Price to<br />Traveller</p>
            <p class="text-white text-[13px] font-medium mt-[3px]">$440.14</p>
        </div>
    </div>
</div></code></pre>
   </div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
<div class="bg-white flex rounded-lg shadow-lg sm:max-w-[34rem] w-sm mx-8 sm:mx-0 my-6">
<!-- Image Section -->
<div class="w-[13rem]">
<img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=400&h=300&fit=crop" alt="Hotel Room" class="w-full h-full object-cover rounded-tl-lg rounded-bl-lg"/>
</div>
<!-- Content Section -->
<div class="w-full py-3 px-6 flex flex-col justify-between">
<!-- Top Section -->
<div>
<!-- Star Rating -->
<div class="flex text-[#ffb319] ">
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
</div>
<!-- Hotel Name -->
<h2 class="text-[15px] font-semibold text-[#1b1b1b] mb-1">
The Park Avenue
</h2>
<!-- Location -->
<p class="text-[10px] text-gray-600 mb-4">
444 Park Avenue South - New York City
</p>
</div>
<div class="flex flex-col sm:flex-row gap-2 sm:justify-between mt-4 mb-2">
<!-- Exclusive Deal Badge -->
<div class="flex gap-1  w-32 bg-[#f2ebff] text-[#7358ab] px-3 py-0.5 rounded-sm text-[11px] font-semibold">
<span class="mr- mb-0.5">
   <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
      <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
   </svg>
</span>
Exclusive deal
</div>
<!-- Bottom Section - Price -->
<div class="flex gap-1">
<p class="text-[10px] text-gray-500 mt-2">4 nights from</p>
<p class="text-base font-semibold text-gray-800">US$ 1,524.12</p>
</div>
</div>
</div>
</div>
<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwo', 'toggleBtnTwo')" class="btn" id="toggleBtnTwo">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTwo">
<pre class="line-numbers language-markup"><code class="language-html"><div class="bg-white flex rounded-lg shadow-lg sm:max-w-[34rem] w-sm mx-8 sm:mx-0 my-6">
<!-- Image Section -->
<div class="w-[13rem]">
<img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=400&h=300&fit=crop" alt="Hotel Room" class="w-full h-full object-cover rounded-tl-lg rounded-bl-lg"/>
</div>
<!-- Content Section -->
<div class="w-full py-3 px-6 flex flex-col justify-between">
<!-- Top Section -->
<div>
<!-- Star Rating -->
<div class="flex text-[#ffb319] ">
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24">
   <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
</svg>
</div>
<!-- Hotel Name -->
<h2 class="text-[15px] font-semibold text-[#1b1b1b] mb-1">
The Park Avenue
</h2>
<!-- Location -->
<p class="text-[10px] text-gray-600 mb-4">
444 Park Avenue South - New York City
</p>
</div>
<div class="flex flex-col sm:flex-row gap-2 sm:justify-between mt-4 mb-2">
<!-- Exclusive Deal Badge -->
<div class="flex gap-1  w-32 bg-[#f2ebff] text-[#7358ab] px-3 py-0.5 rounded-sm text-[11px] font-semibold">
<span class="mr- mb-0.5">
   <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
      <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
   </svg>
</span>
Exclusive deal
</div>
<!-- Bottom Section - Price -->
<div class="flex gap-1">
<p class="text-[10px] text-gray-500 mt-2">4 nights from</p>
<p class="text-base font-semibold text-gray-800">US$ 1,524.12</p>
</div>
</div>
</div>
</div></code></pre>
    </div>
 </div> 

 <div class="border-l-4 border-blue-500 pl-4">
    <div class="bg-white px-4 sm:px-6 py-8 rounded-xl border border-gray-200 max-w-5xl mx-auto my-10 ">
       <!-- Breadcrumb -->
       <div class="flex gap-1 items-center  text-sm text-gray-500 mb-5">
          <span class="text-[#7f759e] text-[13px] font-medium">Stays</span>
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
          <span class="text-[#7f759e] text-[13px] font-medium">New booking</span>
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
          <span class="text-[#7f759e] text-[13px] font-medium">Results</span>
       </div>
       <!-- Search Box -->
<div class="bg-white p-4 sm:py-6 sm:pl-8 sm:pr-4 rounded-2xl border border-gray-200 mb-6 max-w-5xl mx-auto">
<div class="grid md:grid-cols-6 gap-4">
   <!-- Destination -->
   <div class="col-span-2">
      <label class="block text-[15px] font-medium mb-1">Destination</label>
      <input type="text" value="New York" class="input"/>
   </div>
<div class="col-span-2">
   <div class="flex gap-0">
      <!-- Check In -->
      <div>
         <label class="block text-[15px] font-medium mb-1">Check-in</label>
         <input type="text" value="20 / 10 /2023" class=" HotelCheckin border border-gray-200 hover:border-[#629bf8] focus:outline focus:outline-[#5f98f5] rounded-l-md px-3 py-[9px] w-full text-sm"/>
      </div>
      <!-- Check Out -->
      <div>
         <label class="block text-[15px] font-medium mb-1">Check-out</label>
         <input type="text" value="24 / 10 /2023" class="HotelCheckout border border-gray-200 hover:border-[#629bf8]  focus:outline focus:outline-[#5f98f5] rounded-r-md px-3 py-[9px] w-full text-sm"/>
      </div>
   </div>
</div>
<div class="col-span-2">
<div class="grid grid-cols-7">
<div x-data="guestPicker()" class="relative w-full col-span-5">
      <!-- Label -->
      <label class="block text-[15px] font-medium mb-1">Guests</label>
      <!-- Button -->
      <button @click="open = !open" class="w-full relative flex gap-2 items-center justify-center px-3 py-[9px] text-xs border border-gray-200 hover:border-[#629bf8] focus:ring-1 focus:ring-[#5f98f5] rounded-md text-gray-900 transition">
      <!-- Live Updating Text -->
      <span class="font-medium text-[13px]">
      <span x-text="totalGuests()"></span> Adults, 
      <span x-text="rooms"></span> Rooms
      </span>
      <!-- Arrow -->
      <svg class="w-5 h-5 text-gray-600 transition" :class="open ? 'rotate-180' : ''" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 14.975q-.2 0-.375-.062T11.3 14.7l-4.6-4.6q-.275-.275-.275-.7t.275-.7t.7-.275t.7.275l3.9 3.9l3.9-3.9q.275-.275.7-.275t.7.275t.275.7t-.275.7l-4.6 4.6q-.15.15-.325.213t-.375.062"/></svg>
      </button>
      <!-- DROPDOWN -->
      <div x-show="open" x-transition @click.away="open = false" class="absolute z-20 w-full bg-white border border-gray-200 rounded-xl shadow-lg mt-2 p-4 space-y-4">
         <!-- Rooms -->
         <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-black">Rooms</span>
            <div class="flex items-center gap-3">
               <button @click="rooms = Math.max(1, rooms - 1)" class="w-5 h-5 flex items-center justify-center border rounded-full">-</button>
               <span class="text-black font-medium" x-text="rooms"></span>
               <button @click="rooms++" class="w-5 h-5 flex items-center justify-center border rounded-full">+</button>
            </div>
         </div>
         <!-- Adults -->
         <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-black">Adults</span>
            <div class="flex items-center gap-3">
               <button @click="adults = Math.max(1, adults - 1)" class="w-5 h-5 flex items-center justify-center border rounded-full p-1">-</button>
               <span class="text-black font-medium" x-text="adults"></span>
               <button @click="adults++" class="w-5 h-5 flex items-center justify-center border rounded-full p-1">+</button>
            </div>
         </div>
      </div>
   </div>
   <button class="col-span-2 ml-4 bg-black hover:bg-blue-600 text-white mt-[26px] flex items-center justify-center py-1 rounded-md w-10 h-[39px]">
   <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
      <path fill="currentColor" d="m19.6 21l-6.3-6.3q-.75.6-1.725.95T9.5 16q-2.725 0-4.612-1.888T3 9.5t1.888-4.612T9.5 3t4.613 1.888T16 9.5q0 1.1-.35 2.075T14.7 13.3l6.3 6.3zM9.5 14q1.875 0 3.188-1.312T14 9.5t-1.312-3.187T9.5 5T6.313 6.313T5 9.5t1.313 3.188T9.5 14"/>
   </svg>
   </button>
      </div>
   </div>
</div>
</div>
      <!-- Results List -->
<div class="space-y-5 max-w-5xl mx-auto">
   <!-- Hotel Card 1 -->
   <div x-data="hotelCards()" class="space-y-6">
<template x-for="(hotel, index) in hotels" :key="index">
   
<div class="bg-white border border-gray-200 rounded-xl flex flex-col md:flex-row gap-4">
   <img :src="hotel.image" class="w-full md:w-44 h-44 md:rounded-l-md object-cover">

<div class="flex flex-col gap-3 justify-between py-4 pr-4 pl-2">
      <!-- content -->
<div class="flex flex-col gap-1">
   <!-- Star Rating -->
   <div class="flex text-[#ffb319]">
      <template x-for="i in hotel.stars">
         <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
           <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
         </svg>
      </template>
   </div>
   <h2 class="text-lg font-semibold" x-text="hotel.name"></h2>
   <p class="text-xs text-gray-500" x-text="hotel.address"></p> 
   </div>              
    <!-- Bottom -->
     <div class="flex flex-col md:flex-row gap-20 justify-between ">
      <!-- Badges -->
      <div class="flex flex-col sm:flex-row gap-2 items-center">
            <!-- Commission Badge -->
            <div class="flex gap-1 px-1.5 py-[2px] items-center border border-green-300 bg-green-100 text-green-700 rounded-md">
               <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                  <path fill="currentColor" d="M15 16h3c.55 0 1-.45 1-1V9c0-.55-.45-1-1-1h-3c-.55 0-1 .45-1 1v6c0 .55.45 1 1 1m1-6h1v4h-1zm-7 6h3c.55 0 1-.45 1-1V9c0-.55-.45-1-1-1H9c-.55 0-1 .45-1 1v6c0 .55.45 1 1 1m1-6h1v4h-1zM6 8c-.55 0-1 .45-1 1v6c0 .55.45 1 1 1s1-.45 1-1V9c0-.55-.45-1-1-1M2 6v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2m17 12H5c-.55 0-1-.45-1-1V7c0-.55.45-1 1-1h14c.55 0 1 .45 1 1v10c0 .55-.45 1-1 1"/>
               </svg>
               <span class="text-xs" x-text="'Up to $' + hotel.commission + ' commission'"></span>
            </div>

            <!-- Exclusive Badge -->
            <div class="flex gap-0.5 px-1 bg-purple-100 items-center border border-purple-300 text-purple-700 rounded-md">
               <svg xmlns="http://www.w3.org/2000/svg" width="18" height="20" viewBox="0 0 24 24">
                  <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
               </svg>
               <span class="text-xs">Exclusive deal</span>
            </div>
         </div>
         <div class="flex text-end items-end ml-20 md:mt-0">
            <p class="text-xl font-semibold" x-text="'US$ ' + hotel.price"></p>
         </div>
      </div>
    </div>
 </div>
</template>
</div>
 </div>
 
 </div>
<script>
function hotelCards() {
return {
hotels: [
{
name: "Union Square Hotel New York",
address: "134 Fourth Avenue — New York City",
image: "https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop",
stars: 3,
commission: 390,
price: "2,600.78"
},
{
name: "Royal Palm Dubai",
address: "Palm Jumeirah, Dubai — UAE",
image: "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop",
stars: 4,
commission: 420,
price: "3,100.50"
},
{
name: "Hotel California",
address: "Sunset Boulevard — Los Angeles",
image: "https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop",
stars: 5,
commission: 500,
price: "4,890.00"
},
{
name: "Hotel Paris France",
address: "Eiffel Tower Street — Paris",
image: "https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop",
stars: 4,
commission: 350,
price: "3,240.12"
},
{
name: "Tokyo Grand Hotel",
address: "Shinjuku — Tokyo Japan",
image: "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop",
stars: 5,
commission: 600,
price: "5,450.20"
}
      ]
      };
                       }
function guestPicker() {
return {
open: false,
rooms: 1,
adults: 2,
children: 0,

totalGuests() {
return this.adults + this.children;
      }
     }
        }
</script>

<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxThree', 'toggleBtnThree')" class="btn" id="toggleBtnThree">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxThree">
<pre class="line-numbers language-markup"><code class="language-html"> <div class="bg-white px-4 sm:px-6 py-8 rounded-xl border border-gray-200 max-w-5xl mx-auto my-10 ">
       <!-- Breadcrumb -->
       <div class="flex gap-1 items-center  text-sm text-gray-500 mb-5">
          <span class="text-[#7f759e] text-[13px] font-medium">Stays</span>
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
          <span class="text-[#7f759e] text-[13px] font-medium">New booking</span>
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
          <span class="text-[#7f759e] text-[13px] font-medium">Results</span>
       </div>
       <!-- Search Box -->
<div class="bg-white p-4 sm:py-6 sm:pl-8 sm:pr-4 rounded-2xl border border-gray-200 mb-6 max-w-5xl mx-auto">
<div class="grid md:grid-cols-6 gap-4">
   <!-- Destination -->
   <div class="col-span-2">
      <label class="block text-[15px] font-medium mb-1">Destination</label>
      <input type="text" value="New York" class="input"/>
   </div>
<div class="col-span-2">
   <div class="flex gap-0">
      <!-- Check In -->
      <div>
         <label class="block text-[15px] font-medium mb-1">Check-in</label>
         <input type="text" value="20 / 10 /2023" class=" HotelCheckin border border-gray-200 hover:border-[#629bf8] focus:outline focus:outline-[#5f98f5] rounded-l-md px-3 py-[9px] w-full text-sm"/>
      </div>
      <!-- Check Out -->
      <div>
         <label class="block text-[15px] font-medium mb-1">Check-out</label>
         <input type="text" value="24 / 10 /2023" class="HotelCheckout border border-gray-200 hover:border-[#629bf8]  focus:outline focus:outline-[#5f98f5] rounded-r-md px-3 py-[9px] w-full text-sm"/>
      </div>
   </div>
</div>
<div class="col-span-2">
<div class="grid grid-cols-7">
<div x-data="guestPicker()" class="relative w-full col-span-5">
      <!-- Label -->
      <label class="block text-[15px] font-medium mb-1">Guests</label>
      <!-- Button -->
      <button @click="open = !open" class="w-full relative flex gap-2 items-center justify-center px-3 py-[9px] text-xs border border-gray-200 hover:border-[#629bf8] focus:ring-1 focus:ring-[#5f98f5] rounded-md text-gray-900 transition">
      <!-- Live Updating Text -->
      <span class="font-medium text-[13px]">
      <span x-text="totalGuests()"></span> Adults, 
      <span x-text="rooms"></span> Rooms
      </span>
      <!-- Arrow -->
      <svg class="w-5 h-5 text-gray-600 transition" :class="open ? 'rotate-180' : ''" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 14.975q-.2 0-.375-.062T11.3 14.7l-4.6-4.6q-.275-.275-.275-.7t.275-.7t.7-.275t.7.275l3.9 3.9l3.9-3.9q.275-.275.7-.275t.7.275t.275.7t-.275.7l-4.6 4.6q-.15.15-.325.213t-.375.062"/></svg>
      </button>
      <!-- DROPDOWN -->
      <div x-show="open" x-transition @click.away="open = false" class="absolute z-20 w-full bg-white border border-gray-200 rounded-xl shadow-lg mt-2 p-4 space-y-4">
         <!-- Rooms -->
         <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-black">Rooms</span>
            <div class="flex items-center gap-3">
               <button @click="rooms = Math.max(1, rooms - 1)" class="w-5 h-5 flex items-center justify-center border rounded-full">-</button>
               <span class="text-black font-medium" x-text="rooms"></span>
               <button @click="rooms++" class="w-5 h-5 flex items-center justify-center border rounded-full">+</button>
            </div>
         </div>
         <!-- Adults -->
         <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-black">Adults</span>
            <div class="flex items-center gap-3">
               <button @click="adults = Math.max(1, adults - 1)" class="w-5 h-5 flex items-center justify-center border rounded-full p-1">-</button>
               <span class="text-black font-medium" x-text="adults"></span>
               <button @click="adults++" class="w-5 h-5 flex items-center justify-center border rounded-full p-1">+</button>
            </div>
         </div>
      </div>
   </div>
   <button class="col-span-2 ml-4 bg-black hover:bg-blue-600 text-white mt-[26px] flex items-center justify-center py-1 rounded-md w-10 h-[39px]">
   <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
      <path fill="currentColor" d="m19.6 21l-6.3-6.3q-.75.6-1.725.95T9.5 16q-2.725 0-4.612-1.888T3 9.5t1.888-4.612T9.5 3t4.613 1.888T16 9.5q0 1.1-.35 2.075T14.7 13.3l6.3 6.3zM9.5 14q1.875 0 3.188-1.312T14 9.5t-1.312-3.187T9.5 5T6.313 6.313T5 9.5t1.313 3.188T9.5 14"/>
   </svg>
   </button>
      </div>
   </div>
</div>
</div>
      <!-- Results List -->
<div class="space-y-5 max-w-5xl mx-auto">
   <!-- Hotel Card 1 -->
   <div x-data="hotelCards()" class="space-y-6">
<template x-for="(hotel, index) in hotels" :key="index">
   
<div class="bg-white border border-gray-200 rounded-xl flex flex-col md:flex-row gap-4">
   <img :src="hotel.image" class="w-full md:w-44 h-44 md:rounded-l-md object-cover">

<div class="flex flex-col gap-3 justify-between py-4 pr-4 pl-2">
      <!-- content -->
<div class="flex flex-col gap-1">
   <!-- Star Rating -->
   <div class="flex text-[#ffb319]">
      <template x-for="i in hotel.stars">
         <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
           <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
         </svg>
      </template>
   </div>
   <h2 class="text-lg font-semibold" x-text="hotel.name"></h2>
   <p class="text-xs text-gray-500" x-text="hotel.address"></p> 
   </div>              
    <!-- Bottom -->
     <div class="flex flex-col md:flex-row gap-20 justify-between ">
      <!-- Badges -->
      <div class="flex flex-col sm:flex-row gap-2 items-center">
            <!-- Commission Badge -->
            <div class="flex gap-1 px-1.5 py-[2px] items-center border border-green-300 bg-green-100 text-green-700 rounded-md">
               <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                  <path fill="currentColor" d="M15 16h3c.55 0 1-.45 1-1V9c0-.55-.45-1-1-1h-3c-.55 0-1 .45-1 1v6c0 .55.45 1 1 1m1-6h1v4h-1zm-7 6h3c.55 0 1-.45 1-1V9c0-.55-.45-1-1-1H9c-.55 0-1 .45-1 1v6c0 .55.45 1 1 1m1-6h1v4h-1zM6 8c-.55 0-1 .45-1 1v6c0 .55.45 1 1 1s1-.45 1-1V9c0-.55-.45-1-1-1M2 6v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2m17 12H5c-.55 0-1-.45-1-1V7c0-.55.45-1 1-1h14c.55 0 1 .45 1 1v10c0 .55-.45 1-1 1"/>
               </svg>
               <span class="text-xs" x-text="'Up to $' + hotel.commission + ' commission'"></span>
            </div>

            <!-- Exclusive Badge -->
            <div class="flex gap-0.5 px-1 bg-purple-100 items-center border border-purple-300 text-purple-700 rounded-md">
               <svg xmlns="http://www.w3.org/2000/svg" width="18" height="20" viewBox="0 0 24 24">
                  <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
               </svg>
               <span class="text-xs">Exclusive deal</span>
            </div>
         </div>
         <div class="flex text-end items-end ml-20 md:mt-0">
            <p class="text-xl font-semibold" x-text="'US$ ' + hotel.price"></p>
         </div>
      </div>
    </div>
 </div>
</template>
</div>
 </div>
 
 </div>
<script>
function hotelCards() {
return {
hotels: [
{
name: "Union Square Hotel New York",
address: "134 Fourth Avenue — New York City",
image: "https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop",
stars: 3,
commission: 390,
price: "2,600.78"
},
{
name: "Royal Palm Dubai",
address: "Palm Jumeirah, Dubai — UAE",
image: "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop",
stars: 4,
commission: 420,
price: "3,100.50"
},
{
name: "Hotel California",
address: "Sunset Boulevard — Los Angeles",
image: "https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop",
stars: 5,
commission: 500,
price: "4,890.00"
},
{
name: "Hotel Paris France",
address: "Eiffel Tower Street — Paris",
image: "https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop",
stars: 4,
commission: 350,
price: "3,240.12"
},
{
name: "Tokyo Grand Hotel",
address: "Shinjuku — Tokyo Japan",
image: "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop",
stars: 5,
commission: 600,
price: "5,450.20"
}
      ]
      };
                       }
function guestPicker() {
return {
open: false,
rooms: 1,
adults: 2,
children: 0,

totalGuests() {
return this.adults + this.children;
      }
     }
        }
</script></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
<div class="bg-white px-4 sm:px-6 py-8 rounded-xl border border-gray-200 max-w-5xl mx-auto my-10">
   <!-- Breadcrumb -->
   <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
      <span class="text-[#7f759e] text-[13px] font-medium">Stays</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
      <span class="text-[#7f759e] text-[13px] font-medium">New booking</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
      <span class="text-[#7f759e] text-[13px] font-medium">Results</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
      <span class="text-[#7f759e] text-[13px] font-medium">The Park Avenue</span>
   </div>

   <div class="flex flex-col gap-4">
      <!-- Content -->
      <div class="flex-1">
         <!-- Star Rating -->
         <div class="flex text-[#ffb319] mb-1.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
         </div>
         <h2 class="text-2xl font-semibold mb-1.5">The Park Avenue</h2>
         <p class="text-[13px] text-gray-500">444 Park Avenue South — New York City</p>
      </div>

      <!-- Images -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
         <div class="col-span-1 md:col-span-3">
            <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" class="w-full md:max-w-6xl h-96 rounded-l-lg object-cover" />
         </div>
         <div class="col-span-1">
            <div class="flex flex-col gap-2 relative group">
               <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop" class="w-full md:w-48 h-[184px] rounded-tr-lg object-cover" />
               <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" class="w-full md:w-48 h-48 rounded-br-lg object-cover" />
               <button class="absolute bottom-2.5 right-5 text-sm rounded-md py-1.5 px-4 font-semibold text-gray-500 text-center bg-white flex items-end justify-end">
                  View photos
               </button>
            </div>
         </div>
      </div>
   </div>

   <!-- Description -->
   <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-5">
      <div class="col-span-1 md:col-span-3 pr-14 flex flex-col gap-6">
         <h1 class="text-[18px] font-semibold text-black/90">About this stay</h1>
         <div class="flex flex-col">
            <p class="text-[14.5px] font-normal text-gray-600">The Park Avenue is located in New York, 1804 feet from Empire State Building in the NoMad district. Guests can enjoy the on-site Meditterranean restaurant.</p>
            <p class="text-[14.5px] font-normal text-gray-600">Rooms include a smart flat-screen TV. Some units include a seating area where you can relax. Every room is fitted with a private marble bathroom...</p>
         </div>
      </div>
      <div class="col-span-1">
         <div class="flex flex-col gap-6">
            <h1 class="text-[18px] font-semibold text-black/90">Key amenities</h1>
            <div class="flex flex-col">
               <div class="flex gap-1 items-center">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="18" viewBox="0 0 24 24">
                     <path fill="currentColor" d="M7 20V4h6q2.058 0 3.529 1.471T18 9t-1.471 3.529T13 14H9v6zm2-8h4.046q1.238 0 2.119-.881T16.046 9t-.881-2.119T13.046 6H9z"/>
                  </svg>
                  <span class="text-[14px] font-normal text-gray-600">Parking</span>
               </div>
               <div class="flex gap-1 items-center">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="18" viewBox="0 0 24 24">
                     <path fill="currentColor" d="M3.187 10.475L1.773 9.062q2.089-1.956 4.721-3.009T12 5t5.506 1.053t4.721 3.009l-1.413 1.413q-1.798-1.684-4.073-2.58T12 7t-4.74.895t-4.073 2.58M7.2 14.45l-1.408-1.408q1.264-1.22 2.861-1.872q1.597-.65 3.347-.65q1.77 0 3.386.66t2.88 1.9l-1.447 1.39q-.973-.95-2.212-1.45T12 12.52t-2.597.5T7.2 14.45m4.8 4.858l-2.361-2.362q.459-.442 1.065-.694T12 16t1.296.252t1.066.694z"/>
                  </svg>
                  <span class="text-[14px] font-normal text-gray-600">Wifi</span>
               </div>
               <div class="flex gap-1.5 items-center">
                  <svg width="14" height="18" class="" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                     <rect x="1" y="7" width="4" height="10"></rect>
                     <rect x="19" y="7" width="4" height="10"></rect>
                     <line x1="5" y1="12" x2="19" y2="12"></line>
                  </svg>
                  <span class="text-[14px] font-normal text-gray-600">Gym</span>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Room options -->
   <div class="max-w-5xl bg-white rounded-lg py-8">
      <!-- King Room -->
      <div class="flex justify-between items-start mb-8">
         <div>
            <h1 class="text-xl font-semibold text-gray-900 mb-3">King Room</h1>
            <div class="flex gap-1 items-center text-gray-600 text-sm">
               <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                  <path fill="currentColor" d="M3 18v-5q0-.444.256-.946T4 11.3V9q0-.846.577-1.423T6 7h4.5q.517 0 .883.213q.365.212.617.587q.252-.375.617-.587Q12.983 7 13.5 7H18q.846 0 1.423.577T20 9v2.3q.489.252.744.754q.256.502.256.946v5h-1v-2H4v2zm9.5-7H19V9q0-.425-.288-.712T18 8h-4.5q-.425 0-.712.288T12.5 9zM5 11h6.5V9q0-.425-.288-.712T10.5 8H6q-.425 0-.712.288T5 9z"/>
               </svg>
               <span>1 king size bed</span>
            </div>
         </div>
         <!-- Image Gallery -->
         <div class="flex gap-2">
            <div class="w-24 h-24 bg-gray-200 rounded-l overflow-hidden">
               <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" alt="Room view 1" class="w-full h-full object-cover">
            </div>
            <div class="w-24 h-24 bg-gray-200 overflow-hidden">
               <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop" alt="Room view 2" class="w-full h-full object-cover">
            </div>
            <div class="w-24 h-24 bg-gray-200 rounded-r overflow-hidden relative group cursor-pointer">
               <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" alt="Room view 3" class="w-full h-full object-cover">
               <div class="absolute bottom-2 right-2 rounded w-6 h-6 p-1 bg-white flex items-end justify-end">
                  <svg class="w-7 h-4.5 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                  </svg>
               </div>
            </div>
         </div>
      </div>

      <!-- Rates Section -->
      <div>
         <h2 class="text-[19px] font-semibold text-gray-900 mb-4">Rates</h2>
         <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <!-- Fully Refundable -->
            <label class="room-card block cursor-pointer col-span-2" x-data="{ selected: false }" @click="selected = !selected">
               <div class="card-div bg-transparent rounded-lg p-6 transition-all border border-gray-200 duration-300" :class="selected ? 'border-blue-600' : ''">
                  <h3 class="text-[17px] font-semibold text-black mb-4">Fully refundable</h3>
                  <div class="space-y-3 mb-6">
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Room only, no meals</span>
                     </div>
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Free cancellation until Oct 19, 2023</span>
                     </div>
                  </div>
                  <div class="border-t border-gray-200 pt-4">
                     <p class="text-sm text-gray-600 mb-1">US$ 1,524.12 for 4 nights</p>
                     <div class="flex items-end justify-between">
                        <div>
                           <span class="text-xl font-bold text-gray-900">US$ 381.00</span>
                           <span class="text-gray-600 text-sm">/night</span>
                        </div>
                        <span class="circle w-7 h-7 flex items-center justify-center rounded-full border-2 border-gray-300 transition-all duration-200" :class="selected ? 'bg-blue-600 border-blue-600' : ''">
                           <svg class="tick w-6 h-6 text-white transition" :class="selected ? 'opacity-100' : 'opacity-0'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                           </svg>
                        </span>
                     </div>
                  </div>
               </div>
            </label>

            <!-- Fully Refundable with Breakfast -->
            <label class="room-card block cursor-pointer col-span-2" x-data="{ selected: false }" @click="selected = !selected">
               <div class="card-div bg-transparent rounded-lg p-6 transition-all border border-gray-200 duration-300" :class="selected ? 'border-blue-600' : ''">
                  <h3 class="text-[17px] font-semibold text-black mb-4">Fully refundable with Breakfast</h3>
                  <div class="space-y-3 mb-6">
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Room only, no meals</span>
                     </div>
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Free cancellation until Oct 19, 2023</span>
                     </div>
                  </div>
                  <div class="border-t border-gray-200 pt-4">
                     <p class="text-sm text-gray-600 mb-1">US$ 1,524.12 for 4 nights</p>
                     <div class="flex items-end justify-between">
                        <div>
                           <span class="text-xl font-bold text-gray-900">US$ 381.00</span>
                           <span class="text-gray-600 text-sm">/night</span>
                        </div>
                        <span class="circle w-7 h-7 flex items-center justify-center rounded-full border-2 border-gray-300 transition-all duration-200" :class="selected ? 'bg-blue-600 border-blue-600' : ''">
                           <svg class="tick w-6 h-6 text-white transition" :class="selected ? 'opacity-100' : 'opacity-0'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                           </svg>
                        </span>
                     </div>
                  </div>
               </div>
            </label>
         </div>
      </div>

      <!-- Suite Rooms Section -->
      <div class="pt-8">
         <div class="flex justify-between items-start mb-8">
            <div>
               <h1 class="text-xl font-semibold text-gray-900 mb-3">Suite</h1>
               <div class="flex gap-1 items-center text-gray-600 text-sm">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                     <path fill="currentColor" d="M3 18v-5q0-.444.256-.946T4 11.3V9q0-.846.577-1.423T6 7h4.5q.517 0 .883.213q.365.212.617.587q.252-.375.617-.587Q12.983 7 13.5 7H18q.846 0 1.423.577T20 9v2.3q.489.252.744.754q.256.502.256.946v5h-1v-2H4v2zm9.5-7H19V9q0-.425-.288-.712T18 8h-4.5q-.425 0-.712.288T12.5 9zM5 11h6.5V9q0-.425-.288-.712T10.5 8H6q-.425 0-.712.288T5 9z"/>
                  </svg>
                  <span>1 king size bed</span>
               </div>
            </div>
            <!-- Image Gallery -->
            <div class="flex gap-2">
               <div class="w-24 h-24 bg-gray-200 rounded-l overflow-hidden">
                  <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" alt="Room view 1" class="w-full h-full object-cover">
               </div>
               <div class="w-24 h-24 bg-gray-200 overflow-hidden">
                  <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop" alt="Room view 2" class="w-full h-full object-cover">
               </div>
               <div class="w-24 h-24 bg-gray-200 rounded-r overflow-hidden relative group cursor-pointer">
                  <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" alt="Room view 3" class="w-full h-full object-cover">
                  <div class="absolute bottom-2 right-2 rounded w-6 h-6 p-1 bg-white flex items-end justify-end">
                     <svg class="w-7 h-4.5 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                     </svg>
                  </div>
               </div>
            </div>
         </div>

         <!-- Suite Rates -->
         <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <!-- Fully Refundable Suite -->
            <label class="room-card block cursor-pointer col-span-2" x-data="{ selected: false }" @click="selected = !selected">
               <div class="card-div bg-transparent rounded-lg p-6 transition-all border border-gray-200 duration-300" :class="selected ? 'border-blue-600' : ''">
                  <h3 class="text-[17px] font-semibold text-black mb-4">Fully refundable</h3>
                  <div class="space-y-3 mb-6">
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Room only, no meals</span>
                     </div>
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Free cancellation until Oct 19, 2023</span>
                     </div>
                  </div>
                  <div class="border-t border-gray-200 pt-4">
                     <p class="text-sm text-gray-600 mb-1">US$ 1,524.12 for 4 nights</p>
                     <div class="flex items-end justify-between">
                        <div>
                           <span class="text-xl font-bold text-gray-900">US$ 381.00</span>
                           <span class="text-gray-600 text-sm">/night</span>
                        </div>
                        <span class="circle w-7 h-7 flex items-center justify-center rounded-full border-2 border-gray-300 transition-all duration-200" :class="selected ? 'bg-blue-600 border-blue-600' : ''">
                           <svg class="tick w-6 h-6 text-white transition" :class="selected ? 'opacity-100' : 'opacity-0'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                           </svg>
                        </span>
                     </div>
                  </div>
               </div>
            </label>

            <!-- Fully Refundable with Breakfast Suite -->
            <label class="room-card block cursor-pointer col-span-2" x-data="{ selected: false }" @click="selected = !selected">
               <div class="card-div bg-transparent rounded-lg p-6 transition-all border border-gray-200 duration-300" :class="selected ? 'border-blue-600' : ''">
                  <h3 class="text-[17px] font-semibold text-black mb-4">Fully refundable with Breakfast</h3>
                  <div class="space-y-3 mb-6">
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Room only, no meals</span>
                     </div>
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Free cancellation until Oct 19, 2023</span>
                     </div>
                  </div>
                  <div class="border-t border-gray-200 pt-4">
                     <p class="text-sm text-gray-600 mb-1">US$ 1,524.12 for 4 nights</p>
                     <div class="flex items-end justify-between">
                        <div>
                           <span class="text-xl font-bold text-gray-900">US$ 381.00</span>
                           <span class="text-gray-600 text-sm">/night</span>
                        </div>
                        <span class="circle w-7 h-7 flex items-center justify-center rounded-full border-2 border-gray-300 transition-all duration-200" :class="selected ? 'bg-blue-600 border-blue-600' : ''">
                           <svg class="tick w-6 h-6 text-white transition" :class="selected ? 'opacity-100' : 'opacity-0'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                           </svg>
                        </span>
                     </div>
                  </div>
               </div>
            </label>
         </div>
      </div>

      <!-- Go to Checkout -->
      <div class="justify-end flex mt-8 pr-4 border-t pt-8">
         <button class="btn text-[17px] px-8 py-6 bg-black">Go to Checkout</button>
      </div>
   </div>
</div>

               
<script>
   document.querySelectorAll(".room-card").forEach((card) => {
         card.addEventListener("click", () => {
            let cardBox = card.querySelector(".card-div");
            let circle = card.querySelector(".circle");
            let tick = card.querySelector(".tick");
            let isSelected = cardBox.classList.contains("border-blue-600");
   
            if (isSelected) {
               cardBox.classList.remove("border-blue-600");
   
               circle.classList.remove("bg-blue-600", "border-blue-600");
   
               tick.classList.remove("opacity-100");
               tick.classList.add("opacity-0");
   
            } else {
               cardBox.classList.add("border-blue-600");
   
               circle.classList.add("bg-blue-600", "border-blue-600");
   
               tick.classList.remove("opacity-0");
               tick.classList.add("opacity-100");
            }
         });
   });
</script>
    <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxFour', 'toggleBtnFour')" class="btn" id="toggleBtnFour">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxFour">
<pre class="line-numbers language-markup"><code class="language-html"><div class="bg-white px-4 sm:px-6 py-8 rounded-xl border border-gray-200 max-w-5xl mx-auto my-10">
   <!-- Breadcrumb -->
   <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
      <span class="text-[#7f759e] text-[13px] font-medium">Stays</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
      <span class="text-[#7f759e] text-[13px] font-medium">New booking</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
      <span class="text-[#7f759e] text-[13px] font-medium">Results</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
      <span class="text-[#7f759e] text-[13px] font-medium">The Park Avenue</span>
   </div>

   <div class="flex flex-col gap-4">
      <!-- Content -->
      <div class="flex-1">
         <!-- Star Rating -->
         <div class="flex text-[#ffb319] mb-1.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
               <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
            </svg>
         </div>
         <h2 class="text-2xl font-semibold mb-1.5">The Park Avenue</h2>
         <p class="text-[13px] text-gray-500">444 Park Avenue South — New York City</p>
      </div>

      <!-- Images -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
         <div class="col-span-1 md:col-span-3">
            <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" class="w-full md:max-w-6xl h-96 rounded-l-lg object-cover" />
         </div>
         <div class="col-span-1">
            <div class="flex flex-col gap-2 relative group">
               <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop" class="w-full md:w-48 h-[184px] rounded-tr-lg object-cover" />
               <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" class="w-full md:w-48 h-48 rounded-br-lg object-cover" />
               <button class="absolute bottom-2.5 right-5 text-sm rounded-md py-1.5 px-4 font-semibold text-gray-500 text-center bg-white flex items-end justify-end">
                  View photos
               </button>
            </div>
         </div>
      </div>
   </div>

   <!-- Description -->
   <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-5">
      <div class="col-span-1 md:col-span-3 pr-14 flex flex-col gap-6">
         <h1 class="text-[18px] font-semibold text-black/90">About this stay</h1>
         <div class="flex flex-col">
            <p class="text-[14.5px] font-normal text-gray-600">The Park Avenue is located in New York, 1804 feet from Empire State Building in the NoMad district. Guests can enjoy the on-site Meditterranean restaurant.</p>
            <p class="text-[14.5px] font-normal text-gray-600">Rooms include a smart flat-screen TV. Some units include a seating area where you can relax. Every room is fitted with a private marble bathroom...</p>
         </div>
      </div>
      <div class="col-span-1">
         <div class="flex flex-col gap-6">
            <h1 class="text-[18px] font-semibold text-black/90">Key amenities</h1>
            <div class="flex flex-col">
               <div class="flex gap-1 items-center">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="18" viewBox="0 0 24 24">
                     <path fill="currentColor" d="M7 20V4h6q2.058 0 3.529 1.471T18 9t-1.471 3.529T13 14H9v6zm2-8h4.046q1.238 0 2.119-.881T16.046 9t-.881-2.119T13.046 6H9z"/>
                  </svg>
                  <span class="text-[14px] font-normal text-gray-600">Parking</span>
               </div>
               <div class="flex gap-1 items-center">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="18" viewBox="0 0 24 24">
                     <path fill="currentColor" d="M3.187 10.475L1.773 9.062q2.089-1.956 4.721-3.009T12 5t5.506 1.053t4.721 3.009l-1.413 1.413q-1.798-1.684-4.073-2.58T12 7t-4.74.895t-4.073 2.58M7.2 14.45l-1.408-1.408q1.264-1.22 2.861-1.872q1.597-.65 3.347-.65q1.77 0 3.386.66t2.88 1.9l-1.447 1.39q-.973-.95-2.212-1.45T12 12.52t-2.597.5T7.2 14.45m4.8 4.858l-2.361-2.362q.459-.442 1.065-.694T12 16t1.296.252t1.066.694z"/>
                  </svg>
                  <span class="text-[14px] font-normal text-gray-600">Wifi</span>
               </div>
               <div class="flex gap-1.5 items-center">
                  <svg width="14" height="18" class="" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                     <rect x="1" y="7" width="4" height="10"></rect>
                     <rect x="19" y="7" width="4" height="10"></rect>
                     <line x1="5" y1="12" x2="19" y2="12"></line>
                  </svg>
                  <span class="text-[14px] font-normal text-gray-600">Gym</span>
               </div>
            </div>
         </div>
      </div>
   </div>

   <!-- Room options -->
   <div class="max-w-5xl bg-white rounded-lg py-8">
      <!-- King Room -->
      <div class="flex justify-between items-start mb-8">
         <div>
            <h1 class="text-xl font-semibold text-gray-900 mb-3">King Room</h1>
            <div class="flex gap-1 items-center text-gray-600 text-sm">
               <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                  <path fill="currentColor" d="M3 18v-5q0-.444.256-.946T4 11.3V9q0-.846.577-1.423T6 7h4.5q.517 0 .883.213q.365.212.617.587q.252-.375.617-.587Q12.983 7 13.5 7H18q.846 0 1.423.577T20 9v2.3q.489.252.744.754q.256.502.256.946v5h-1v-2H4v2zm9.5-7H19V9q0-.425-.288-.712T18 8h-4.5q-.425 0-.712.288T12.5 9zM5 11h6.5V9q0-.425-.288-.712T10.5 8H6q-.425 0-.712.288T5 9z"/>
               </svg>
               <span>1 king size bed</span>
            </div>
         </div>
         <!-- Image Gallery -->
         <div class="flex gap-2">
            <div class="w-24 h-24 bg-gray-200 rounded-l overflow-hidden">
               <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" alt="Room view 1" class="w-full h-full object-cover">
            </div>
            <div class="w-24 h-24 bg-gray-200 overflow-hidden">
               <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop" alt="Room view 2" class="w-full h-full object-cover">
            </div>
            <div class="w-24 h-24 bg-gray-200 rounded-r overflow-hidden relative group cursor-pointer">
               <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" alt="Room view 3" class="w-full h-full object-cover">
               <div class="absolute bottom-2 right-2 rounded w-6 h-6 p-1 bg-white flex items-end justify-end">
                  <svg class="w-7 h-4.5 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                     <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                  </svg>
               </div>
            </div>
         </div>
      </div>

      <!-- Rates Section -->
      <div>
         <h2 class="text-[19px] font-semibold text-gray-900 mb-4">Rates</h2>
         <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <!-- Fully Refundable -->
            <label class="room-card block cursor-pointer col-span-2" x-data="{ selected: false }" @click="selected = !selected">
               <div class="card-div bg-transparent rounded-lg p-6 transition-all border border-gray-200 duration-300" :class="selected ? 'border-blue-600' : ''">
                  <h3 class="text-[17px] font-semibold text-black mb-4">Fully refundable</h3>
                  <div class="space-y-3 mb-6">
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Room only, no meals</span>
                     </div>
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Free cancellation until Oct 19, 2023</span>
                     </div>
                  </div>
                  <div class="border-t border-gray-200 pt-4">
                     <p class="text-sm text-gray-600 mb-1">US$ 1,524.12 for 4 nights</p>
                     <div class="flex items-end justify-between">
                        <div>
                           <span class="text-xl font-bold text-gray-900">US$ 381.00</span>
                           <span class="text-gray-600 text-sm">/night</span>
                        </div>
                        <span class="circle w-7 h-7 flex items-center justify-center rounded-full border-2 border-gray-300 transition-all duration-200" :class="selected ? 'bg-blue-600 border-blue-600' : ''">
                           <svg class="tick w-6 h-6 text-white transition" :class="selected ? 'opacity-100' : 'opacity-0'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                           </svg>
                        </span>
                     </div>
                  </div>
               </div>
            </label>

            <!-- Fully Refundable with Breakfast -->
            <label class="room-card block cursor-pointer col-span-2" x-data="{ selected: false }" @click="selected = !selected">
               <div class="card-div bg-transparent rounded-lg p-6 transition-all border border-gray-200 duration-300" :class="selected ? 'border-blue-600' : ''">
                  <h3 class="text-[17px] font-semibold text-black mb-4">Fully refundable with Breakfast</h3>
                  <div class="space-y-3 mb-6">
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Room only, no meals</span>
                     </div>
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Free cancellation until Oct 19, 2023</span>
                     </div>
                  </div>
                  <div class="border-t border-gray-200 pt-4">
                     <p class="text-sm text-gray-600 mb-1">US$ 1,524.12 for 4 nights</p>
                     <div class="flex items-end justify-between">
                        <div>
                           <span class="text-xl font-bold text-gray-900">US$ 381.00</span>
                           <span class="text-gray-600 text-sm">/night</span>
                        </div>
                        <span class="circle w-7 h-7 flex items-center justify-center rounded-full border-2 border-gray-300 transition-all duration-200" :class="selected ? 'bg-blue-600 border-blue-600' : ''">
                           <svg class="tick w-6 h-6 text-white transition" :class="selected ? 'opacity-100' : 'opacity-0'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                           </svg>
                        </span>
                     </div>
                  </div>
               </div>
            </label>
         </div>
      </div>

      <!-- Suite Rooms Section -->
      <div class="pt-8">
         <div class="flex justify-between items-start mb-8">
            <div>
               <h1 class="text-xl font-semibold text-gray-900 mb-3">Suite</h1>
               <div class="flex gap-1 items-center text-gray-600 text-sm">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                     <path fill="currentColor" d="M3 18v-5q0-.444.256-.946T4 11.3V9q0-.846.577-1.423T6 7h4.5q.517 0 .883.213q.365.212.617.587q.252-.375.617-.587Q12.983 7 13.5 7H18q.846 0 1.423.577T20 9v2.3q.489.252.744.754q.256.502.256.946v5h-1v-2H4v2zm9.5-7H19V9q0-.425-.288-.712T18 8h-4.5q-.425 0-.712.288T12.5 9zM5 11h6.5V9q0-.425-.288-.712T10.5 8H6q-.425 0-.712.288T5 9z"/>
                  </svg>
                  <span>1 king size bed</span>
               </div>
            </div>
            <!-- Image Gallery -->
            <div class="flex gap-2">
               <div class="w-24 h-24 bg-gray-200 rounded-l overflow-hidden">
                  <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" alt="Room view 1" class="w-full h-full object-cover">
               </div>
               <div class="w-24 h-24 bg-gray-200 overflow-hidden">
                  <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop" alt="Room view 2" class="w-full h-full object-cover">
               </div>
               <div class="w-24 h-24 bg-gray-200 rounded-r overflow-hidden relative group cursor-pointer">
                  <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" alt="Room view 3" class="w-full h-full object-cover">
                  <div class="absolute bottom-2 right-2 rounded w-6 h-6 p-1 bg-white flex items-end justify-end">
                     <svg class="w-7 h-4.5 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                     </svg>
                  </div>
               </div>
            </div>
         </div>

         <!-- Suite Rates -->
         <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <!-- Fully Refundable Suite -->
            <label class="room-card block cursor-pointer col-span-2" x-data="{ selected: false }" @click="selected = !selected">
               <div class="card-div bg-transparent rounded-lg p-6 transition-all border border-gray-200 duration-300" :class="selected ? 'border-blue-600' : ''">
                  <h3 class="text-[17px] font-semibold text-black mb-4">Fully refundable</h3>
                  <div class="space-y-3 mb-6">
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Room only, no meals</span>
                     </div>
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Free cancellation until Oct 19, 2023</span>
                     </div>
                  </div>
                  <div class="border-t border-gray-200 pt-4">
                     <p class="text-sm text-gray-600 mb-1">US$ 1,524.12 for 4 nights</p>
                     <div class="flex items-end justify-between">
                        <div>
                           <span class="text-xl font-bold text-gray-900">US$ 381.00</span>
                           <span class="text-gray-600 text-sm">/night</span>
                        </div>
                        <span class="circle w-7 h-7 flex items-center justify-center rounded-full border-2 border-gray-300 transition-all duration-200" :class="selected ? 'bg-blue-600 border-blue-600' : ''">
                           <svg class="tick w-6 h-6 text-white transition" :class="selected ? 'opacity-100' : 'opacity-0'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                           </svg>
                        </span>
                     </div>
                  </div>
               </div>
            </label>

            <!-- Fully Refundable with Breakfast Suite -->
            <label class="room-card block cursor-pointer col-span-2" x-data="{ selected: false }" @click="selected = !selected">
               <div class="card-div bg-transparent rounded-lg p-6 transition-all border border-gray-200 duration-300" :class="selected ? 'border-blue-600' : ''">
                  <h3 class="text-[17px] font-semibold text-black mb-4">Fully refundable with Breakfast</h3>
                  <div class="space-y-3 mb-6">
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span>Room only, no meals</span>
                     </div>
                     <div class="flex items-start text-gray-600 text-sm">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Free cancellation until Oct 19, 2023</span>
                     </div>
                  </div>
                  <div class="border-t border-gray-200 pt-4">
                     <p class="text-sm text-gray-600 mb-1">US$ 1,524.12 for 4 nights</p>
                     <div class="flex items-end justify-between">
                        <div>
                           <span class="text-xl font-bold text-gray-900">US$ 381.00</span>
                           <span class="text-gray-600 text-sm">/night</span>
                        </div>
                        <span class="circle w-7 h-7 flex items-center justify-center rounded-full border-2 border-gray-300 transition-all duration-200" :class="selected ? 'bg-blue-600 border-blue-600' : ''">
                           <svg class="tick w-6 h-6 text-white transition" :class="selected ? 'opacity-100' : 'opacity-0'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                           </svg>
                        </span>
                     </div>
                  </div>
               </div>
            </label>
         </div>
      </div>

      <!-- Go to Checkout -->
      <div class="justify-end flex mt-8 pr-4 border-t pt-8">
         <button class="btn text-[17px] px-8 py-6 bg-black">Go to Checkout</button>
      </div>
   </div>
</div>

<script>
document.querySelectorAll(".room-card").forEach((card) => {
card.addEventListener("click", () => {
let cardBox = card.querySelector(".card-div");
let circle = card.querySelector(".circle");
let tick = card.querySelector(".tick");
let isSelected = cardBox.classList.contains("border-blue-600");

if (isSelected) {
cardBox.classList.remove("border-blue-600");

circle.classList.remove("bg-blue-600", "border-blue-600");

tick.classList.remove("opacity-100");
tick.classList.add("opacity-0");

} else {
cardBox.classList.add("border-blue-600");

circle.classList.add("bg-blue-600", "border-blue-600");

tick.classList.remove("opacity-0");
tick.classList.add("opacity-100");
}
});
});
</script></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
<div class="bg-white px-4 sm:px-6 py-8 rounded-xl border border-gray-200 max-w-5xl mx-auto my-10">
   <!-- Breadcrumb -->
   <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
      <span class="text-[#7f759e] text-[13px] font-medium">Stays</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
         <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
      </svg>
      <span class="text-[#7f759e] text-[13px] font-medium">New booking</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
         <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
      </svg>
      <span class="text-[#7f759e] text-[13px] font-medium">Checkout</span>
   </div>

   <div>
      <h1 class="text-[22px] font-semibold text-black">Checkout</h1>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
         <div class="col-span-2 flex flex-col gap-6">
            <!-- Booking details -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Booking details</h1>
               <div class="flex flex-col gap-8 border border-gray-200 rounded-lg pt-4 pb-8 px-5 mt-4">
                  <!-- Hotel information -->
                  <div class="flex gap-7">
                     <!-- Image -->
                     <div class="w-20 h-20 overflow-hidden">
                        <img src="https://picsum.photos/200/160?random=5" class="w-full h-full object-cover rounded" alt="Hotel image" />
                     </div>
                     <!-- Hotel Name & Rating -->
                     <div class="flex flex-col gap-1 mt-2">
                        <!-- Star Rating -->
                        <div class="flex text-[#ffb319]">
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                        </div>
                        <span class="text-[19px] font-semibold text-black">The Park Avenue</span>
                     </div>
                  </div>

                  <div class="flex gap-2">
                     <span class="bg-gray-200 px-2 py-[5px] text-center rounded-sm text-[13px]">1x</span>
                     <h2 class="text-lg font-medium text-black/90">Suite</h2>
                  </div>

                  <!-- Check-in & Check-out -->
                  <div class="flex">
                     <div class="flex flex-col gap-1 w-72">
                        <span class="text-[13px] font-normal text-gray-600">Check-in</span>
                        <span class="text-base font-medium text-black">Fri 20 October</span>
                        <span class="text-[13px] font-normal text-gray-600">From 15:00</span>
                     </div>
                     <div class="flex flex-col gap-1 w-72 border-l border-gray-300 pl-5">
                        <span class="text-[13px] font-normal text-gray-600">Check-out</span>
                        <span class="text-base font-medium text-black">Tue 24 October</span>
                        <span class="text-[13px] font-normal text-gray-600">Until 11:00 AM</span>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Guests Details -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Guest details</h1>
               <div class="flex flex-col gap-4 mt-4">
                  <h2 class="px-2 py-1 text-[13px] border border-gray-100 bg-gray-200 w-[65px] rounded-md text-gray-700 font-medium">Guest 1</h2>
                  <div class="grid grid-cols-5">
                     <div class="col-span-5 flex gap-5">
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">First name</label>
                           <input type="text" class="input text-sm" placeholder="Jhon" />
                        </div>
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">Last name</label>
                           <input type="text" class="input text-sm" placeholder="Smith" />
                        </div>
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">Date of birth</label>
                           <input type="text" class="input text-sm dp" placeholder="DD/MM/YYYY" />
                        </div>
                     </div>
                  </div>
               </div>
               <div class="flex flex-col gap-4 mt-4">
                  <h2 class="px-2 py-1 text-[13px] border border-gray-100 bg-gray-200 w-[70px] rounded-md text-gray-700 font-medium">Guest 2</h2>
                  <div class="grid grid-cols-5">
                     <div class="col-span-5 flex gap-5">
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">First name</label>
                           <input type="text" class="input text-sm" placeholder="Jane" />
                        </div>
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">Last name</label>
                           <input type="text" class="input text-sm" placeholder="Smith" />
                        </div>
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">Date of birth</label>
                           <input type="text" class="input text-sm dp" placeholder="DD/MM/YYYY" />
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Additional information -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Additional information</h1>
               <div class="grid grid-cols-5">
                  <div class="col-span-5 flex flex-col gap-2 mt-4">
                     <label class="text-sm text-black">Special request</label>
                     <textarea class="textarea resize-none overflow-hidden text-[17px]" placeholder="Special repuest" x-data="" x-init="$el.style.height = $el.scrollHeight + 'px'" @input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'" rows="2" style="height: 78px;"></textarea>
                  </div>
               </div>
            </div>

            <!-- Contact details -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Contact details</h1>
               <div class="grid grid-cols-6 gap-6">
                  <div class="col-span-3 flex flex-col gap-2 mt-4">
                     <label class="text-sm text-black">Email address</label>
                     <input type="email" class="input" placeholder="jhon.smith@gmail.com" />
                  </div>
                  <div class="col-span-3 flex flex-col gap-2 mt-4">
                     <label class="text-sm text-black">Phone number</label>
                     <input type="number" class="input" placeholder="+1 2345678901" />
                  </div>
               </div>
            </div>

            <!-- Cancelation policy -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Cancelation policy</h1>
               <div class="grid grid-cols-5">
                  <div class="space-y-2 col-span-5 border border-gray-200 rounded-t-lg py-4 px-2 mt-4">
                     <!-- Full Refund -->
                     <div class="flex gap-4 p-4 bg-white">
                        <div class="flex-shrink-0 mt-1">
                           <svg class="text-green-500" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m10 14.312l6.246-6.266q.139-.14.353-.14q.215 0 .355.139t.14.354t-.14.355l-6.389 6.369q-.242.243-.565.243t-.565-.243l-2.389-2.37q-.14-.138-.14-.352t.139-.355t.354-.14t.355.14z" />
                           </svg>
                        </div>
                        <p class="text-gray-600 text-[15px] leading-relaxed block">
                           <strong class="font-semibold text-gray-900">Full refund</strong>
                           <span class="inline-block align-middle mx-1" style="width:3%; vertical-align:middle;">
                              <svg class="text-gray-400" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="1" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                           You may cancel for free before the 19 October 2023 at 4.00am (BST). You will be refunded the full amount.
                        </p>
                     </div>

                     <!-- Partial Refund -->
                     <div class="flex gap-4 p-4 bg-white">
                        <div class="flex-shrink-0 mt-1">
                           <svg class="text-yellow-500" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m10 14.312l6.246-6.266q.139-.14.353-.14q.215 0 .355.139t.14.354t-.14.355l-6.389 6.369q-.242.243-.565.243t-.565-.243l-2.389-2.37q-.14-.138-.14-.352t.139-.355t.354-.14t.355.14z" />
                           </svg>
                        </div>
                        <p class="text-gray-600 text-[15px] leading-relaxed block">
                           <strong class="font-semibold text-gray-900">Partial refund</strong>
                           <span class="inline-block align-middle mx-1" style="width:3%; vertical-align:middle;">
                              <svg class="text-gray-400" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="1" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                           After that date, and before the 19 October 2023 at 4.00am (BST), you will be charged a fee to cancel the booking. Therefore, you will be refunded the remaining amount of US$ 1,678.12.
                        </p>
                     </div>

                     <!-- No Refund -->
                     <div class="flex gap-4 p-4 bg-white">
                        <div class="flex-shrink-0 mt-1 text-red-500">
                           <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m8.4 16.308l3.6-3.6l3.6 3.6l.708-.708l-3.6-3.6l3.6-3.6l-.708-.708l-3.6 3.6l-3.6-3.6l-.708.708l3.6 3.6l-3.6 3.6z" />
                           </svg>
                        </div>
                        <p class="text-gray-600 text-[15px] leading-relaxed block">
                           <strong class="font-semibold text-gray-900">No refund</strong>
                           <span class="inline-block align-middle mx-1" style="width:3%; vertical-align:middle;">
                              <svg class="text-gray-400" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="1" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                           From the 19 October 2023 at 4.00am (BST) onwards, you won't be able to get any refund for cancelling this booking.
                        </p>
                     </div>
                  </div>

                  <!-- Timeline Container -->
                  <div class="col-span-5 border-b border-l border-r border-gray-200 rounded-b-lg py-8 px-4">
                     <div class="relative">
                        <!-- Timeline Line -->
                        <div class="absolute top-12 left-0 right-0 h-0.5 flex">
                           <span class="inline-block align-middle mx-1" style="width:8%; vertical-align:middle;">
                              <svg class="text-gray-200" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="2" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                           <div class="flex-1 bg-[#378271]"></div>
                           <div class="flex-1 bg-[#ffb018]"></div>
                           <div class="flex-1 bg-[#db2b4a]"></div>
                           <span class="inline-block align-middle mx-1" style="width:8%; vertical-align:middle;">
                              <svg class="text-gray-200" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="2" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                        </div>

                        <!-- Timeline Points and Labels -->
                        <div class="relative flex justify-between items-start">
                           <!-- Today Point -->
                           <div class="flex flex-col items-center" style="width: 15%;">
                              <div class="relative left-5 top-[17px]">
                                 <div class="text-xs font-semibold text-gray-700 mb-2">Today</div>
                                 <div class="w-4 h-4 bg-gray-800 rounded-full border-4 border-white z-10"></div>
                              </div>
                              <div class="mt-4 text-center relative left-20 top-3">
                                 <div class="font-semibold text-gray-900 text-xs mb-1">Full refund</div>
                                 <div class="text-xs text-gray-600">US$ 2,678.12</div>
                              </div>
                           </div>

                           <!-- First October Point -->
                           <div class="flex flex-col items-center" style="width: 25%;">
                              <div class="relative left-[40px]">
                                 <div class="text-xs font-semibold text-gray-700 mb-1 text-center">19 October 2023</div>
                                 <div class="text-xs text-gray-500 mb-[5px] text-center">4.00am (BST)</div>
                              </div>
                              <div class="relative left-[40px]">
                                 <div class="w-4 h-4 bg-yellow-500 rounded-full border-4 border-white z-10"></div>
                              </div>
                              <div class="text-center relative left-28 top-3">
                                 <div class="font-semibold text-gray-900 text-xs mb-1">Partial refund</div>
                                 <div class="text-xs text-gray-600">US$ 1,678.12</div>
                              </div>
                           </div>

                           <!-- Second October Point -->
                           <div class="flex flex-col items-center" style="width: 25%;">
                              <div class="relative left-[37px]">
                                 <div class="text-xs font-semibold text-gray-700 mb-1 text-center">19 October 2023</div>
                                 <div class="text-xs text-gray-500 mb-[5px] text-center">4.00am (BST)</div>
                              </div>
                              <div class="relative left-[37px]">
                                 <div class="w-4 h-4 bg-yellow-500 rounded-full border-4 border-white z-10"></div>
                              </div>
                           </div>

                           <!-- Final Point -->
                           <div class="flex flex-col items-center ml-3 relative left-2" style="width: 25%;">
                              <div class="text-xs font-semibold text-gray-700 mb-1 text-center">20 October 2023</div>
                              <div class="text-xs text-gray-500 mb-[5px]">3.00pm (PDT)</div>
                              <div class="w-4 h-4 bg-gray-300 rounded-full border-4 border-white z-10"></div>
                              <div class="mt-4 text-center">
                                 <div class="font-semibold text-gray-900 text-xs mb-1">Check-in</div>
                                 <div class="text-xs text-gray-600">at the hotel</div>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>

         <!-- Billing summary -->
         <div class="col-span-1">
            <h1 class="text-xl font-semibold text-black/90">Billing summary</h1>
            <div class="flex flex-col gap-3 border border-gray-200 rounded-lg py-8 px-5 mt-4">
               <!-- Header -->
               <div class="flex justify-between border-b pb-2 border-gray-100">
                  <span class="text-sm font-normal text-black">Description</span>
                  <span class="text-sm font-normal text-black">Price (USD)</span>
               </div>
               <!-- Room -->
               <div class="flex justify-between pb-2">
                  <span class="text-sm font-normal text-gray-600">Room</span>
                  <span class="text-sm font-normal text-gray-600">2,509.00</span>
               </div>
               <!-- Fees -->
               <div class="flex justify-between pb-2">
                  <span class="text-sm font-normal text-gray-600">Fees</span>
                  <span class="text-sm font-normal text-gray-600">30.10</span>
               </div>
               <!-- Taxes -->
               <div class="flex justify-between border-b pb-2 border-gray-100">
                  <span class="text-sm font-normal text-gray-600">Taxes</span>
                  <span class="text-sm font-normal text-gray-600">113.44</span>
               </div>
               <!-- Total -->
               <div class="flex justify-between pt-2">
                  <span class="text-[15.5px] font-semibold text-black">Total due now</span>
                  <span class="text-[15.5px] font-semibold text-black">US$ 2,652.54</span>
               </div>
            </div>
         </div>
      </div>

      <div class="justify-end flex mt-8 pr-4 border-t pt-8">
         <button class="btn text-[17px] px-8 py-6 bg-black">Checkout</button>
      </div>
   </div>
</div>
                  
 <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxFive', 'toggleBtnFive')" class="btn" id="toggleBtnFive">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxFive">
<pre class="line-numbers language-markup"><code class="language-html"><div class="bg-white px-4 sm:px-6 py-8 rounded-xl border border-gray-200 max-w-5xl mx-auto my-10">
   <!-- Breadcrumb -->
   <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
      <span class="text-[#7f759e] text-[13px] font-medium">Stays</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
         <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
      </svg>
      <span class="text-[#7f759e] text-[13px] font-medium">New booking</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
         <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
      </svg>
      <span class="text-[#7f759e] text-[13px] font-medium">Checkout</span>
   </div>

   <div>
      <h1 class="text-[22px] font-semibold text-black">Checkout</h1>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
         <div class="col-span-2 flex flex-col gap-6">
            <!-- Booking details -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Booking details</h1>
               <div class="flex flex-col gap-8 border border-gray-200 rounded-lg pt-4 pb-8 px-5 mt-4">
                  <!-- Hotel information -->
                  <div class="flex gap-7">
                     <!-- Image -->
                     <div class="w-20 h-20 overflow-hidden">
                        <img src="https://picsum.photos/200/160?random=5" class="w-full h-full object-cover rounded" alt="Hotel image" />
                     </div>
                     <!-- Hotel Name & Rating -->
                     <div class="flex flex-col gap-1 mt-2">
                        <!-- Star Rating -->
                        <div class="flex text-[#ffb319]">
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                           <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z" />
                           </svg>
                        </div>
                        <span class="text-[19px] font-semibold text-black">The Park Avenue</span>
                     </div>
                  </div>

                  <div class="flex gap-2">
                     <span class="bg-gray-200 px-2 py-[5px] text-center rounded-sm text-[13px]">1x</span>
                     <h2 class="text-lg font-medium text-black/90">Suite</h2>
                  </div>

                  <!-- Check-in & Check-out -->
                  <div class="flex">
                     <div class="flex flex-col gap-1 w-72">
                        <span class="text-[13px] font-normal text-gray-600">Check-in</span>
                        <span class="text-base font-medium text-black">Fri 20 October</span>
                        <span class="text-[13px] font-normal text-gray-600">From 15:00</span>
                     </div>
                     <div class="flex flex-col gap-1 w-72 border-l border-gray-300 pl-5">
                        <span class="text-[13px] font-normal text-gray-600">Check-out</span>
                        <span class="text-base font-medium text-black">Tue 24 October</span>
                        <span class="text-[13px] font-normal text-gray-600">Until 11:00 AM</span>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Guests Details -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Guest details</h1>
               <div class="flex flex-col gap-4 mt-4">
                  <h2 class="px-2 py-1 text-[13px] border border-gray-100 bg-gray-200 w-[65px] rounded-md text-gray-700 font-medium">Guest 1</h2>
                  <div class="grid grid-cols-5">
                     <div class="col-span-5 flex gap-5">
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">First name</label>
                           <input type="text" class="input text-sm" placeholder="Jhon" />
                        </div>
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">Last name</label>
                           <input type="text" class="input text-sm" placeholder="Smith" />
                        </div>
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">Date of birth</label>
                           <input type="text" class="input text-sm dp" placeholder="DD/MM/YYYY" />
                        </div>
                     </div>
                  </div>
               </div>
               <div class="flex flex-col gap-4 mt-4">
                  <h2 class="px-2 py-1 text-[13px] border border-gray-100 bg-gray-200 w-[70px] rounded-md text-gray-700 font-medium">Guest 2</h2>
                  <div class="grid grid-cols-5">
                     <div class="col-span-5 flex gap-5">
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">First name</label>
                           <input type="text" class="input text-sm" placeholder="Jane" />
                        </div>
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">Last name</label>
                           <input type="text" class="input text-sm" placeholder="Smith" />
                        </div>
                        <div class="flex flex-col gap-1 w-72">
                           <label class="text-[13px] font-normal text-gray-600">Date of birth</label>
                           <input type="text" class="input text-sm dp" placeholder="DD/MM/YYYY" />
                        </div>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Additional information -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Additional information</h1>
               <div class="grid grid-cols-5">
                  <div class="col-span-5 flex flex-col gap-2 mt-4">
                     <label class="text-sm text-black">Special request</label>
                     <textarea class="textarea resize-none overflow-hidden text-[17px]" placeholder="Special repuest" x-data="" x-init="$el.style.height = $el.scrollHeight + 'px'" @input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'" rows="2" style="height: 78px;"></textarea>
                  </div>
               </div>
            </div>

            <!-- Contact details -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Contact details</h1>
               <div class="grid grid-cols-6 gap-6">
                  <div class="col-span-3 flex flex-col gap-2 mt-4">
                     <label class="text-sm text-black">Email address</label>
                     <input type="email" class="input" placeholder="jhon.smith@gmail.com" />
                  </div>
                  <div class="col-span-3 flex flex-col gap-2 mt-4">
                     <label class="text-sm text-black">Phone number</label>
                     <input type="number" class="input" placeholder="+1 2345678901" />
                  </div>
               </div>
            </div>

            <!-- Cancelation policy -->
            <div>
               <h1 class="text-xl font-semibold text-black/90">Cancelation policy</h1>
               <div class="grid grid-cols-5">
                  <div class="space-y-2 col-span-5 border border-gray-200 rounded-t-lg py-4 px-2 mt-4">
                     <!-- Full Refund -->
                     <div class="flex gap-4 p-4 bg-white">
                        <div class="flex-shrink-0 mt-1">
                           <svg class="text-green-500" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m10 14.312l6.246-6.266q.139-.14.353-.14q.215 0 .355.139t.14.354t-.14.355l-6.389 6.369q-.242.243-.565.243t-.565-.243l-2.389-2.37q-.14-.138-.14-.352t.139-.355t.354-.14t.355.14z" />
                           </svg>
                        </div>
                        <p class="text-gray-600 text-[15px] leading-relaxed block">
                           <strong class="font-semibold text-gray-900">Full refund</strong>
                           <span class="inline-block align-middle mx-1" style="width:3%; vertical-align:middle;">
                              <svg class="text-gray-400" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="1" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                           You may cancel for free before the 19 October 2023 at 4.00am (BST). You will be refunded the full amount.
                        </p>
                     </div>

                     <!-- Partial Refund -->
                     <div class="flex gap-4 p-4 bg-white">
                        <div class="flex-shrink-0 mt-1">
                           <svg class="text-yellow-500" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m10 14.312l6.246-6.266q.139-.14.353-.14q.215 0 .355.139t.14.354t-.14.355l-6.389 6.369q-.242.243-.565.243t-.565-.243l-2.389-2.37q-.14-.138-.14-.352t.139-.355t.354-.14t.355.14z" />
                           </svg>
                        </div>
                        <p class="text-gray-600 text-[15px] leading-relaxed block">
                           <strong class="font-semibold text-gray-900">Partial refund</strong>
                           <span class="inline-block align-middle mx-1" style="width:3%; vertical-align:middle;">
                              <svg class="text-gray-400" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="1" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                           After that date, and before the 19 October 2023 at 4.00am (BST), you will be charged a fee to cancel the booking. Therefore, you will be refunded the remaining amount of US$ 1,678.12.
                        </p>
                     </div>

                     <!-- No Refund -->
                     <div class="flex gap-4 p-4 bg-white">
                        <div class="flex-shrink-0 mt-1 text-red-500">
                           <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                              <path fill="currentColor" d="m8.4 16.308l3.6-3.6l3.6 3.6l.708-.708l-3.6-3.6l3.6-3.6l-.708-.708l-3.6 3.6l-3.6-3.6l-.708.708l3.6 3.6l-3.6 3.6z" />
                           </svg>
                        </div>
                        <p class="text-gray-600 text-[15px] leading-relaxed block">
                           <strong class="font-semibold text-gray-900">No refund</strong>
                           <span class="inline-block align-middle mx-1" style="width:3%; vertical-align:middle;">
                              <svg class="text-gray-400" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="1" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                           From the 19 October 2023 at 4.00am (BST) onwards, you won't be able to get any refund for cancelling this booking.
                        </p>
                     </div>
                  </div>

                  <!-- Timeline Container -->
                  <div class="col-span-5 border-b border-l border-r border-gray-200 rounded-b-lg py-8 px-4">
                     <div class="relative">
                        <!-- Timeline Line -->
                        <div class="absolute top-12 left-0 right-0 h-0.5 flex">
                           <span class="inline-block align-middle mx-1" style="width:8%; vertical-align:middle;">
                              <svg class="text-gray-200" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="2" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                           <div class="flex-1 bg-[#378271]"></div>
                           <div class="flex-1 bg-[#ffb018]"></div>
                           <div class="flex-1 bg-[#db2b4a]"></div>
                           <span class="inline-block align-middle mx-1" style="width:8%; vertical-align:middle;">
                              <svg class="text-gray-200" viewBox="0 0 100 1" preserveAspectRatio="none" width="100%" height="2" xmlns="http://www.w3.org/2000/svg">
                                 <line x1="0" y1="0.5" x2="100" y2="0.5" stroke="currentColor" stroke-width="1" />
                              </svg>
                           </span>
                        </div>

                        <!-- Timeline Points and Labels -->
                        <div class="relative flex justify-between items-start">
                           <!-- Today Point -->
                           <div class="flex flex-col items-center" style="width: 15%;">
                              <div class="relative left-5 top-[17px]">
                                 <div class="text-xs font-semibold text-gray-700 mb-2">Today</div>
                                 <div class="w-4 h-4 bg-gray-800 rounded-full border-4 border-white z-10"></div>
                              </div>
                              <div class="mt-4 text-center relative left-20 top-3">
                                 <div class="font-semibold text-gray-900 text-xs mb-1">Full refund</div>
                                 <div class="text-xs text-gray-600">US$ 2,678.12</div>
                              </div>
                           </div>

                           <!-- First October Point -->
                           <div class="flex flex-col items-center" style="width: 25%;">
                              <div class="relative left-[40px]">
                                 <div class="text-xs font-semibold text-gray-700 mb-1 text-center">19 October 2023</div>
                                 <div class="text-xs text-gray-500 mb-[5px] text-center">4.00am (BST)</div>
                              </div>
                              <div class="relative left-[40px]">
                                 <div class="w-4 h-4 bg-yellow-500 rounded-full border-4 border-white z-10"></div>
                              </div>
                              <div class="text-center relative left-28 top-3">
                                 <div class="font-semibold text-gray-900 text-xs mb-1">Partial refund</div>
                                 <div class="text-xs text-gray-600">US$ 1,678.12</div>
                              </div>
                           </div>

                           <!-- Second October Point -->
                           <div class="flex flex-col items-center" style="width: 25%;">
                              <div class="relative left-[37px]">
                                 <div class="text-xs font-semibold text-gray-700 mb-1 text-center">19 October 2023</div>
                                 <div class="text-xs text-gray-500 mb-[5px] text-center">4.00am (BST)</div>
                              </div>
                              <div class="relative left-[37px]">
                                 <div class="w-4 h-4 bg-yellow-500 rounded-full border-4 border-white z-10"></div>
                              </div>
                           </div>

                           <!-- Final Point -->
                           <div class="flex flex-col items-center ml-3 relative left-2" style="width: 25%;">
                              <div class="text-xs font-semibold text-gray-700 mb-1 text-center">20 October 2023</div>
                              <div class="text-xs text-gray-500 mb-[5px]">3.00pm (PDT)</div>
                              <div class="w-4 h-4 bg-gray-300 rounded-full border-4 border-white z-10"></div>
                              <div class="mt-4 text-center">
                                 <div class="font-semibold text-gray-900 text-xs mb-1">Check-in</div>
                                 <div class="text-xs text-gray-600">at the hotel</div>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>

         <!-- Billing summary -->
         <div class="col-span-1">
            <h1 class="text-xl font-semibold text-black/90">Billing summary</h1>
            <div class="flex flex-col gap-3 border border-gray-200 rounded-lg py-8 px-5 mt-4">
               <!-- Header -->
               <div class="flex justify-between border-b pb-2 border-gray-100">
                  <span class="text-sm font-normal text-black">Description</span>
                  <span class="text-sm font-normal text-black">Price (USD)</span>
               </div>
               <!-- Room -->
               <div class="flex justify-between pb-2">
                  <span class="text-sm font-normal text-gray-600">Room</span>
                  <span class="text-sm font-normal text-gray-600">2,509.00</span>
               </div>
               <!-- Fees -->
               <div class="flex justify-between pb-2">
                  <span class="text-sm font-normal text-gray-600">Fees</span>
                  <span class="text-sm font-normal text-gray-600">30.10</span>
               </div>
               <!-- Taxes -->
               <div class="flex justify-between border-b pb-2 border-gray-100">
                  <span class="text-sm font-normal text-gray-600">Taxes</span>
                  <span class="text-sm font-normal text-gray-600">113.44</span>
               </div>
               <!-- Total -->
               <div class="flex justify-between pt-2">
                  <span class="text-[15.5px] font-semibold text-black">Total due now</span>
                  <span class="text-[15.5px] font-semibold text-black">US$ 2,652.54</span>
               </div>
            </div>
         </div>
      </div>

      <div class="justify-end flex mt-8 pr-4 border-t pt-8">
         <button class="btn text-[17px] px-8 py-6 bg-black">Checkout</button>
      </div>
   </div>
</div></code></pre>
</div>
</div>


<div class="border-l-4 border-blue-500 pl-4">
<div class="bg-white w-full max-w-4xl shadow-sm rounded-xl p-8 mb-6">
   <!-- Breadcrumbs -->
   <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
      <span class="text-[#7f759e] text-[13px] font-medium">Stays</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
         <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
      </svg>
      <span class="text-[#7f759e] text-[13px] font-medium">New booking</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
         <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
      </svg>
      <span class="text-[#7f759e] text-[13px] font-medium">Confirmation</span>
   </div>

   <!-- Page Title -->
   <h1 class="text-2xl font-semibold text-gray-900 mb-6">
      Booking confirmed
   </h1>

   <!-- Card Section -->
   <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
         <!-- Left -->
         <div>
            <h2 class="text-gray-500 text-[15px]">Booking reference</h2>
            <!-- right pill -->
            <div class="flex items-center gap-1 py-1">
               <div id="refPill" role="status" aria-live="polite">
                  <span class="text-[17px] font-bold text-black/80 tracking-wide" id="refText">AFE33SE2</span>
               </div>
               <!-- copy button -->
               <button id="copyBtn" class="text-gray-400" title="Copy reference">
                  <!-- clipboard icon -->
                  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
                     <path fill="currentColor" d="M9.116 17q-.691 0-1.153-.462T7.5 15.385V4.615q0-.69.463-1.153T9.116 3h7.769q.69 0 1.153.462t.462 1.153v10.77q0 .69-.462 1.152T16.884 17zm0-1h7.769q.23 0 .423-.192t.192-.423V4.615q0-.23-.192-.423T16.884 4H9.116q-.231 0-.424.192t-.192.423v10.77q0 .23.192.423t.423.192m-3 4q-.69 0-1.153-.462T4.5 18.385V6.615h1v11.77q0 .23.192.423t.423.192h8.77v1zM8.5 16V4z" />
                  </svg>
               </button>
            </div>

            <div class="text-black text-[13px] text-center flex gap-1.5">
               <span>1 room</span>
               <svg xmlns="http://www.w3.org/2000/svg" class="mt-[9px]" width="2" height="2" viewBox="0 0 24 24">
                  <path fill="currentColor" d="M12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22" />
               </svg>
               <span>4 nights</span>
            </div>
         </div>

         <!-- Right -->
         <div>
            <h2 class="text-gray-500 text-[15px]">Guests</h2>
            <p class="text-gray-900 font-normal text-[15px]">John Smith</p>
            <p class="text-gray-900 font-normal text-[15px]">Sarah Smith</p>
         </div>
      </div>

      <p class="text-gray-900 text-sm mt-12">
         You'll need these details to manage your booking.
      </p>
   </div>

   <!-- tiny success toast -->
   <div id="toast" class="fixed bottom-6 left-[50%] bg-gray-900 text-white text-sm px-4 py-2 rounded-md shadow-lg opacity-0 pointer-events-none transition-opacity duration-300">
      Copied!
   </div>
</div>

<script>
const copyBtn = document.getElementById('copyBtn');
const refText = document.getElementById('refText').innerText;
const toast = document.getElementById('toast');

copyBtn.addEventListener('click', async () => {
try {
await navigator.clipboard.writeText(refText);
// show tiny toast
toast.classList.remove('opacity-0', 'pointer-events-none');
setTimeout(() => {
toast.classList.add('opacity-0', 'pointer-events-none');
}, 1400);
} catch (e) {
// fallback
const ta = document.createElement('textarea');
ta.value = refText;
document.body.appendChild(ta);
ta.select();
try { document.execCommand('copy'); }
catch(e) {}
document.body.removeChild(ta);
toast.classList.remove('opacity-0', 'pointer-events-none');
setTimeout(() => {
toast.classList.add('opacity-0', 'pointer-events-none');
}, 1400);
}
});
</script>
<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxSix', 'toggleBtnSix')" class="btn" id="toggleBtnSix">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxSix">
<pre class="line-numbers language-markup"><code class="language-html"><div class="bg-white w-full max-w-4xl shadow-sm rounded-xl p-8 mb-6">
   <!-- Breadcrumbs -->
   <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
      <span class="text-[#7f759e] text-[13px] font-medium">Stays</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
         <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
      </svg>
      <span class="text-[#7f759e] text-[13px] font-medium">New booking</span>
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
         <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z" />
      </svg>
      <span class="text-[#7f759e] text-[13px] font-medium">Confirmation</span>
   </div>

   <!-- Page Title -->
   <h1 class="text-2xl font-semibold text-gray-900 mb-6">
      Booking confirmed
   </h1>

   <!-- Card Section -->
   <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
         <!-- Left -->
         <div>
            <h2 class="text-gray-500 text-[15px]">Booking reference</h2>
            <!-- right pill -->
            <div class="flex items-center gap-1 py-1">
               <div id="refPill" role="status" aria-live="polite">
                  <span class="text-[17px] font-bold text-black/80 tracking-wide" id="refText">AFE33SE2</span>
               </div>
               <!-- copy button -->
               <button id="copyBtn" class="text-gray-400" title="Copy reference">
                  <!-- clipboard icon -->
                  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
                     <path fill="currentColor" d="M9.116 17q-.691 0-1.153-.462T7.5 15.385V4.615q0-.69.463-1.153T9.116 3h7.769q.69 0 1.153.462t.462 1.153v10.77q0 .69-.462 1.152T16.884 17zm0-1h7.769q.23 0 .423-.192t.192-.423V4.615q0-.23-.192-.423T16.884 4H9.116q-.231 0-.424.192t-.192.423v10.77q0 .23.192.423t.423.192m-3 4q-.69 0-1.153-.462T4.5 18.385V6.615h1v11.77q0 .23.192.423t.423.192h8.77v1zM8.5 16V4z" />
                  </svg>
               </button>
            </div>

            <div class="text-black text-[13px] text-center flex gap-1.5">
               <span>1 room</span>
               <svg xmlns="http://www.w3.org/2000/svg" class="mt-[9px]" width="2" height="2" viewBox="0 0 24 24">
                  <path fill="currentColor" d="M12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22" />
               </svg>
               <span>4 nights</span>
            </div>
         </div>

         <!-- Right -->
         <div>
            <h2 class="text-gray-500 text-[15px]">Guests</h2>
            <p class="text-gray-900 font-normal text-[15px]">John Smith</p>
            <p class="text-gray-900 font-normal text-[15px]">Sarah Smith</p>
         </div>
      </div>

      <p class="text-gray-900 text-sm mt-12">
         You'll need these details to manage your booking.
      </p>
   </div>

   <!-- tiny success toast -->
   <div id="toast" class="fixed bottom-6 left-[50%] bg-gray-900 text-white text-sm px-4 py-2 rounded-md shadow-lg opacity-0 pointer-events-none transition-opacity duration-300">
      Copied!
   </div>
</div>

   <script>
    const copyBtn = document.getElementById('copyBtn');
    const refText = document.getElementById('refText').innerText;
    const toast = document.getElementById('toast');

    copyBtn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(refText);
        // show tiny toast
        toast.classList.remove('opacity-0', 'pointer-events-none');
        setTimeout(() => {
          toast.classList.add('opacity-0', 'pointer-events-none');
        }, 1400);
      } catch (e) {
        // fallback
        const ta = document.createElement('textarea');
        ta.value = refText;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); }
        catch(e) {}
        document.body.removeChild(ta);
        toast.classList.remove('opacity-0', 'pointer-events-none');
        setTimeout(() => {
          toast.classList.add('opacity-0', 'pointer-events-none');
        }, 1400);
      }
    });
  </script></code></pre>               
</div>
</div>
    <div class="border-l-4 border-blue-500 pl-4">
  <div class="max-w-[240px] w-full">
        <!-- Slider Container -->
        <div class="relative bg-white rounded-xl shadow-xl overflow-hidden">
            <!-- Images Container -->
            <div class="relative h-52 overflow-hidden">
                <div id="slider" class="flex transition-transform duration-500 ease-in-out h-full">
                    <!-- Image 1 -->
                    <div class="w-60 h-full flex-shrink-0">
                        <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" 
                             alt="Mountain Landscape" 
                             class="w-full h-full object-cover">
                    </div>
                    
                    <!-- Image 2 -->
                    <div class="w-60 h-full flex-shrink-0">
                        <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop" 
                             alt="Beach Sunset" 
                             class="w-full h-full object-cover">
                    </div>
                    
                    <!-- Image 3 -->
                    <div class="w-60 h-full flex-shrink-0">
                        <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" 
                             alt="Forest Path" 
                             class="w-full h-full object-cover">
                    </div>
                    
                    <!-- Image 4 -->
                    <div class="w-60 h-full flex-shrink-0">
                        <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" 
                             alt="Lake View" 
                             class="w-full h-full object-cover">
                    </div>
                    
                    <!-- Image 5 -->
                    <div class="w-60 h-full flex-shrink-0">
                        <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" 
                             alt="Northern Lights" 
                             class="w-full h-full object-cover">
                    </div>
                </div>
            </div>

            <!-- Left Arrow Button -->
            <button id="prevBtn" 
                    class="absolute left-4 top-1/2 -translate-y-1/2 bg-white/90 hover:bg-white text-gray-800 w-6 h-6 rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-110">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </button>

            <!-- Right Arrow Button -->
            <button id="nextBtn" 
                    class="absolute right-4 top-1/2 -translate-y-1/2 bg-white/90 hover:bg-white text-gray-800 w-6 h-6 rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-110">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </button>

            <!-- Dots Indicator -->
            <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2">
                <button class="dot w-1.5 h-1.5 rounded-full bg-white transition-all" data-index="0"></button>
                <button class="dot w-1.5 h-1.5 rounded-full bg-white/50 transition-all" data-index="1"></button>
                <button class="dot w-1.5 h-1.5 rounded-full bg-white/50 transition-all" data-index="2"></button>
                <button class="dot w-1.5 h-1.5 rounded-full bg-white/50 transition-all" data-index="3"></button>
                <button class="dot w-1.5 h-1.5 rounded-full bg-white/50 transition-all" data-index="4"></button>
            </div>

            <!-- Image Counter -->
            <!-- <div class="absolute top-4 right-4 bg-black/50 text-white px-3 py-1 rounded-full text-sm hidden">
                <span id="currentSlide">1</span> / <span id="totalSlides">5</span>
            </div> -->
        </div>
    </div>

    <script>
        const slider = document.getElementById('slider');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const dots = document.querySelectorAll('.dot');
        const currentSlideSpan = document.getElementById('currentSlide');
        const totalSlides = 5;
        let currentIndex = 0;

        function updateSlider() {
            slider.style.transform = `translateX(-${currentIndex * 100}%)`;
            currentSlideSpan.textContent = currentIndex + 1;
            
            // Update dots
            dots.forEach((dot, index) => {
                if (index === currentIndex) {
                    dot.classList.remove('bg-white/50');
                    dot.classList.add('bg-white');
                } else {
                    dot.classList.remove('bg-white');
                    dot.classList.add('bg-white/50');
                }
            });
        }

        nextBtn.addEventListener('click', () => {
            currentIndex = (currentIndex + 1) % totalSlides;
            updateSlider();
        });

        prevBtn.addEventListener('click', () => {
            currentIndex = (currentIndex - 1 + totalSlides) % totalSlides;
            updateSlider();
        });

        // Dot navigation
        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                currentIndex = index;
                updateSlider();
            });
        });

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                prevBtn.click();
            } else if (e.key === 'ArrowRight') {
                nextBtn.click();
            }
        });

        // Auto-play (optional - remove if not needed)
        let autoplayInterval = setInterval(() => {
            nextBtn.click();
        }, 5000);

        // Pause autoplay on hover
        slider.parentElement.addEventListener('mouseenter', () => {
            clearInterval(autoplayInterval);
        });

        slider.parentElement.addEventListener('mouseleave', () => {
            autoplayInterval = setInterval(() => {
                nextBtn.click();
            }, 5000);
        });
    </script>

   <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxSeven', 'toggleBtnSeven')" class="btn" id="toggleBtnSeven">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxSeven">
<pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-[240px] w-full mx-auto">
   <div class="relative bg-white rounded-xl shadow-xl overflow-hidden">
      <div class="relative h-52 overflow-hidden">
         <div id="slider" class="flex transition-transform duration-500 ease-in-out h-full">
            <!-- Image 1 -->
            <div class="w-60 h-full flex-shrink-0">
               <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" alt="Mountain Landscape" class="w-full h-full object-cover">
            </div>
            
            <!-- Image 2 -->
            <div class="w-60 h-full flex-shrink-0">
               <img src="https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&h=200&fit=crop" alt="Beach Sunset" class="w-full h-full object-cover">
            </div>
            
            <!-- Image 3 -->
            <div class="w-60 h-full flex-shrink-0">
               <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" alt="Forest Path" class="w-full h-full object-cover">
            </div>
            
            <!-- Image 4 -->
            <div class="w-60 h-full flex-shrink-0">
               <img src="https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=300&h=200&fit=crop" alt="Lake View" class="w-full h-full object-cover">
            </div>
            
            <!-- Image 5 -->
            <div class="w-60 h-full flex-shrink-0">
               <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&h=200&fit=crop" alt="Northern Lights" class="w-full h-full object-cover">
            </div>
         </div>
   </div>

   <!-- Left Arrow Button -->
   <button id="prevBtn" 
            class="absolute left-4 top-1/2 -translate-y-1/2 bg-white/90 hover:bg-white text-gray-800 w-6 h-6 rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-110">
         <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
         </svg>
   </button>

   <!-- Right Arrow Button -->
   <button id="nextBtn" 
            class="absolute right-4 top-1/2 -translate-y-1/2 bg-white/90 hover:bg-white text-gray-800 w-6 h-6 rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-110">
         <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
         </svg>
   </button>

   <!-- Dots Indicator -->
   <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2">
         <button class="dot w-1.5 h-1.5 rounded-full bg-white transition-all" data-index="0"></button>
         <button class="dot w-1.5 h-1.5 rounded-full bg-white/50 transition-all" data-index="1"></button>
         <button class="dot w-1.5 h-1.5 rounded-full bg-white/50 transition-all" data-index="2"></button>
         <button class="dot w-1.5 h-1.5 rounded-full bg-white/50 transition-all" data-index="3"></button>
         <button class="dot w-1.5 h-1.5 rounded-full bg-white/50 transition-all" data-index="4"></button>
   </div>
        </div>
    </div>

    <script>
        const slider = document.getElementById('slider');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const dots = document.querySelectorAll('.dot');
        const currentSlideSpan = document.getElementById('currentSlide');
        const totalSlides = 5;
        let currentIndex = 0;

        function updateSlider() {
            slider.style.transform = `translateX(-${currentIndex * 100}%)`;
            currentSlideSpan.textContent = currentIndex + 1;
            
            dots.forEach((dot, index) => {
                if (index === currentIndex) {
                    dot.classList.remove('bg-white/50');
                    dot.classList.add('bg-white');
                } else {
                    dot.classList.remove('bg-white');
                    dot.classList.add('bg-white/50');
                }
            });
        }

        nextBtn.addEventListener('click', () => {
            currentIndex = (currentIndex + 1) % totalSlides;
            updateSlider();
        });

        prevBtn.addEventListener('click', () => {
            currentIndex = (currentIndex - 1 + totalSlides) % totalSlides;
            updateSlider();
        });

        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                currentIndex = index;
                updateSlider();
            });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                prevBtn.click();
            } else if (e.key === 'ArrowRight') {
                nextBtn.click();
            }
        });

        let autoplayInterval = setInterval(() => {
            nextBtn.click();
        }, 5000);

        slider.parentElement.addEventListener('mouseenter', () => {
            clearInterval(autoplayInterval);
        });

        slider.parentElement.addEventListener('mouseleave', () => {
            autoplayInterval = setInterval(() => {
                nextBtn.click();
            }, 5000);
        });
    </script></code></pre>
</div>
</div>

 <div class="border-l-4 border-blue-500 pl-4">
  <div class="bg-white p-6 rounded-xl shadow-sm w-full max-w-[290px]">
    <h2 class="text-sm font-semibold text-slate-900 mb-2">
      Amenities
    </h2>

    <div class="grid grid-cols-2 gap-3 text-sm text-slate-800">

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M6 19q-1.25 0-2.125-.875T3 16q-.825 0-1.412-.587T1 14V7q0-.825.588-1.412T3 5h13.175q.4 0 .763.15t.637.425l4.85 4.85q.275.275.425.638t.15.762V14q0 .825-.587 1.413T21 16q0 1.25-.875 2.125T18 19t-2.125-.875T15 16H9q0 1.25-.875 2.125T6 19m9-9h4l-3-3h-1zm-6 0h4V7H9zm-6 0h4V7H3zm3 7.25q.525 0 .888-.363T7.25 16t-.363-.888T6 14.75t-.888.363T4.75 16t.363.888t.887.362m12 0q.525 0 .888-.363T19.25 16t-.363-.888T18 14.75t-.888.363t-.362.887t.363.888t.887.362M8.2 14h7.6q.425-.45.975-.725T18 13t1.225.275t.975.725h.8v-2H3v2h.8q.425-.45.975-.725T6 13t1.225.275T8.2 14M21 12H3z"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Airport shuttle included
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 22q-1.825-.225-3.625-.987t-3.212-2.188t-2.288-3.6T2 10V9h1q1.275 0 2.625.325t2.525.975q.3-2.15 1.363-4.413T12 2q1.425 1.625 2.488 3.888T15.85 10.3q1.175-.65 2.525-.975T21 9h1v1q0 3.05-.875 5.225t-2.287 3.6t-3.2 2.188T12 22m-.05-2.05q-.275-4.15-2.463-6.275T4.05 11.05q.275 4.275 2.538 6.375t5.362 2.525M12 13.6q.375-.55.913-1.137t1.037-1.013q-.05-1.425-.562-2.975T12 5.45q-.875 1.475-1.388 3.025t-.562 2.975q.5.425 1.05 1.013T12 13.6m1.95 5.9q.925-.3 1.925-.875t1.863-1.562t1.475-2.463t.737-3.55q-2.35.35-4.125 1.563T13.1 15.7q.3.8.513 1.75t.337 2.05M12 22"/></svg>
        <span class=" text-[10px] text-center font-bold text-black leading-snug">
          Spa
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M2.725 10.725L.6 8.6q2.3-2.225 5.25-3.412T12 4t6.15 1.188T23.4 8.6l-2.125 2.125Q19.4 8.925 17 7.963T12 7t-5 .963t-4.275 2.762M6.95 14.95l-2.1-2.1q1.475-1.375 3.313-2.112T12 10t3.838.738t3.312 2.112l-2.1 2.1Q16 14 14.713 13.5T12 13t-2.713.5t-2.337 1.45M12 20q-.825 0-1.412-.587T10 18t.588-1.412T12 16t1.413.588T14 18t-.587 1.413T12 20"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Wifi included
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M7 8q-.825 0-1.412-.587T5 6t.588-1.412T7 4t1.413.588T9 6t-.587 1.413T7 8M4 22q-.825 0-1.412-.587T2 20v-8h3v-.75q0-.95.65-1.6T7.25 9q.5 0 .925.2t.775.55l1.4 1.55q.175.2.375.375t.425.325H22v8q0 .825-.587 1.413T20 22zm14-12l.1-.6q.125-.625-.088-1.213T17.35 7.15q-.725-.725-1.075-1.687T16.05 3.45L16.1 3H18l-.1.6q-.1.6.088 1.188T18.6 5.8q.75.75 1.113 1.725t.237 2.025l-.05.45zm-4 0l.1-.6q.125-.625-.088-1.213T13.35 7.15q-.725-.725-1.075-1.687T12.05 3.45L12.1 3H14l-.1.6q-.125.6.075 1.188T14.6 5.8q.75.75 1.113 1.725t.237 2.025l-.05.45zm3 10h2v-6h-2zm-4 0h2v-6h-2zm-4 0h2v-6H9zm-4 0h2v-6H5z"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Hot tub
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M2 21v-2q.95 0 1.425-.5T5.3 18t1.925.5t1.425.5t1.425-.5T12 18t1.925.5t1.425.5t1.425-.5T18.7 18t1.875.5T22 19v2q-1.475 0-1.937-.5T18.7 20t-1.425.5t-1.925.5t-1.925-.5T12 20t-1.425.5t-1.925.5t-1.925-.5T5.3 20t-1.363.5T2 21m0-4.5v-2q.95 0 1.425-.5t1.875-.5t1.938.5t1.412.5q.9 0 1.425-.5T12 13.5t1.925.5t1.425.5t1.425-.5t1.925-.5t1.875.5t1.425.5v2q-1.475 0-1.937-.5t-1.363-.5t-1.388.5t-1.962.5q-1.425 0-1.937-.5T12 15.5q-.95 0-1.412.5t-1.938.5t-1.963-.5t-1.387-.5t-1.362.5T2 16.5m4.9-5.1l3.325-3.325l-1-1q-.825-.825-1.75-1.2T5.2 5.5V3q1.875 0 3.1.413T10.7 5l6.4 6.4q-.425.275-.825.438T15.35 12q-.9 0-1.425-.5T12 11t-1.925.5t-1.425.5q-.525 0-.925-.162T6.9 11.4M16.7 3q1.05 0 1.775.738T19.2 5.5q0 1.05-.725 1.775T16.7 8t-1.775-.725T14.2 5.5q0-1.025.725-1.763T16.7 3"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Pool
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M7.5 21.5v-9.034q-1.16-.177-1.965-1.064q-.804-.886-.804-2.171V2.5h1v6.73H7.5V2.5h1v6.73h1.77V2.5h1v6.73q0 1.286-.805 2.172q-.806.887-1.965 1.064V21.5zm9.23 0v-8h-2.46V7q0-1.671.942-2.96q.944-1.29 2.519-1.501V21.5z"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Restaurant
        </span>
      </div>

    </div>
  </div>

  <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxEight', 'toggleBtnEight')" class="btn" id="toggleBtnEight">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxEight">
<pre class="line-numbers language-markup"><code class="language-html"><div class="bg-white p-6 rounded-xl shadow-sm w-full max-w-[290px]">
    <h2 class="text-sm font-semibold text-slate-900 mb-2">
      Amenities
    </h2>

    <div class="grid grid-cols-2 gap-3 text-sm text-slate-800">

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M6 19q-1.25 0-2.125-.875T3 16q-.825 0-1.412-.587T1 14V7q0-.825.588-1.412T3 5h13.175q.4 0 .763.15t.637.425l4.85 4.85q.275.275.425.638t.15.762V14q0 .825-.587 1.413T21 16q0 1.25-.875 2.125T18 19t-2.125-.875T15 16H9q0 1.25-.875 2.125T6 19m9-9h4l-3-3h-1zm-6 0h4V7H9zm-6 0h4V7H3zm3 7.25q.525 0 .888-.363T7.25 16t-.363-.888T6 14.75t-.888.363T4.75 16t.363.888t.887.362m12 0q.525 0 .888-.363T19.25 16t-.363-.888T18 14.75t-.888.363t-.362.887t.363.888t.887.362M8.2 14h7.6q.425-.45.975-.725T18 13t1.225.275t.975.725h.8v-2H3v2h.8q.425-.45.975-.725T6 13t1.225.275T8.2 14M21 12H3z"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Airport shuttle included
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 22q-1.825-.225-3.625-.987t-3.212-2.188t-2.288-3.6T2 10V9h1q1.275 0 2.625.325t2.525.975q.3-2.15 1.363-4.413T12 2q1.425 1.625 2.488 3.888T15.85 10.3q1.175-.65 2.525-.975T21 9h1v1q0 3.05-.875 5.225t-2.287 3.6t-3.2 2.188T12 22m-.05-2.05q-.275-4.15-2.463-6.275T4.05 11.05q.275 4.275 2.538 6.375t5.362 2.525M12 13.6q.375-.55.913-1.137t1.037-1.013q-.05-1.425-.562-2.975T12 5.45q-.875 1.475-1.388 3.025t-.562 2.975q.5.425 1.05 1.013T12 13.6m1.95 5.9q.925-.3 1.925-.875t1.863-1.562t1.475-2.463t.737-3.55q-2.35.35-4.125 1.563T13.1 15.7q.3.8.513 1.75t.337 2.05M12 22"/></svg>
        <span class=" text-[10px] text-center font-bold text-black leading-snug">
          Spa
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M2.725 10.725L.6 8.6q2.3-2.225 5.25-3.412T12 4t6.15 1.188T23.4 8.6l-2.125 2.125Q19.4 8.925 17 7.963T12 7t-5 .963t-4.275 2.762M6.95 14.95l-2.1-2.1q1.475-1.375 3.313-2.112T12 10t3.838.738t3.312 2.112l-2.1 2.1Q16 14 14.713 13.5T12 13t-2.713.5t-2.337 1.45M12 20q-.825 0-1.412-.587T10 18t.588-1.412T12 16t1.413.588T14 18t-.587 1.413T12 20"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Wifi included
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M7 8q-.825 0-1.412-.587T5 6t.588-1.412T7 4t1.413.588T9 6t-.587 1.413T7 8M4 22q-.825 0-1.412-.587T2 20v-8h3v-.75q0-.95.65-1.6T7.25 9q.5 0 .925.2t.775.55l1.4 1.55q.175.2.375.375t.425.325H22v8q0 .825-.587 1.413T20 22zm14-12l.1-.6q.125-.625-.088-1.213T17.35 7.15q-.725-.725-1.075-1.687T16.05 3.45L16.1 3H18l-.1.6q-.1.6.088 1.188T18.6 5.8q.75.75 1.113 1.725t.237 2.025l-.05.45zm-4 0l.1-.6q.125-.625-.088-1.213T13.35 7.15q-.725-.725-1.075-1.687T12.05 3.45L12.1 3H14l-.1.6q-.125.6.075 1.188T14.6 5.8q.75.75 1.113 1.725t.237 2.025l-.05.45zm3 10h2v-6h-2zm-4 0h2v-6h-2zm-4 0h2v-6H9zm-4 0h2v-6H5z"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Hot tub
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M2 21v-2q.95 0 1.425-.5T5.3 18t1.925.5t1.425.5t1.425-.5T12 18t1.925.5t1.425.5t1.425-.5T18.7 18t1.875.5T22 19v2q-1.475 0-1.937-.5T18.7 20t-1.425.5t-1.925.5t-1.925-.5T12 20t-1.425.5t-1.925.5t-1.925-.5T5.3 20t-1.363.5T2 21m0-4.5v-2q.95 0 1.425-.5t1.875-.5t1.938.5t1.412.5q.9 0 1.425-.5T12 13.5t1.925.5t1.425.5t1.425-.5t1.925-.5t1.875.5t1.425.5v2q-1.475 0-1.937-.5t-1.363-.5t-1.388.5t-1.962.5q-1.425 0-1.937-.5T12 15.5q-.95 0-1.412.5t-1.938.5t-1.963-.5t-1.387-.5t-1.362.5T2 16.5m4.9-5.1l3.325-3.325l-1-1q-.825-.825-1.75-1.2T5.2 5.5V3q1.875 0 3.1.413T10.7 5l6.4 6.4q-.425.275-.825.438T15.35 12q-.9 0-1.425-.5T12 11t-1.925.5t-1.425.5q-.525 0-.925-.162T6.9 11.4M16.7 3q1.05 0 1.775.738T19.2 5.5q0 1.05-.725 1.775T16.7 8t-1.775-.725T14.2 5.5q0-1.025.725-1.763T16.7 3"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Pool
        </span>
      </div>

      <div class="flex flex-col gap-1 items-center justify-center border border-slate-500 rounded-lg py-1 px-1 hover:border-blue-500 hover:bg-blue-50 cursor-pointer transition">
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M7.5 21.5v-9.034q-1.16-.177-1.965-1.064q-.804-.886-.804-2.171V2.5h1v6.73H7.5V2.5h1v6.73h1.77V2.5h1v6.73q0 1.286-.805 2.172q-.806.887-1.965 1.064V21.5zm9.23 0v-8h-2.46V7q0-1.671.942-2.96q.944-1.29 2.519-1.501V21.5z"/></svg>
        <span class="text-[10px] text-center font-bold text-black leading-snug">
          Restaurant
        </span>
      </div>

    </div>
  </div></code></pre>
      </div>
      </div>

<div class="border-l-4 border-blue-500 pl-4">
<div x-data="priceRange()" class="w-[240px]">

  <!-- Top labels -->
  <div class="flex items-center justify-between mb-3 text-sm text-slate-700">
    <span>Price range</span>
    <span class="font-semibold text-blue-600">
      $<span x-text="from"></span> to $<span x-text="to"></span>
    </span>
  </div>

  <!-- Slider area -->
  <div class="relative h-24 select-none">
    <!-- Inner track with same padding for everything -->
    <div class="absolute inset-x-0 bottom-5 px-4">
      <div class="relative h-12">

        <!-- Bars background -->
        <div class="absolute inset-x-0 bottom-2 flex items-end gap-1">
          <template x-for="(h, i) in bars" :key="i">
            <div
              class="w-2 rounded-t-full transition-colors duration-150"
              :style="`height:${h}px`"
              :class="i >= activeStart && i <= activeEnd ? 'bg-blue-500' : 'bg-slate-300'"
            ></div>
          </template>
        </div>

        <div class="absolute left-0 right-0 bottom-1 h-[2px] bg-slate-200">
          <div class="absolute top-1/2 -translate-y-1/2 h-[2px] bg-blue-500"
               :style="`left:${fromPercent}%; width:${toPercent - fromPercent}%;`"></div>
        </div>

        <div class="absolute bottom-1 z-10"
             :style="`left: calc(${fromPercent}% - 8px)`">
          <div class="w-4 h-4 rounded-full bg-blue-600 shadow"></div>
        </div>

        <div class="absolute bottom-1 z-10"
             :style="`left: calc(${toPercent}% - 8px)`">
          <div class="w-4 h-4 rounded-full bg-blue-600 shadow"></div>
        </div>

        <input type="range"
               x-model="from"
               :min="min"
               :max="max"
               step="10"
               class="absolute bottom-0 left-0 h-6 w-full opacity-0 cursor-pointer z-30"
               :style="`width:${toPercent}%;`"
               @input="if (Number(from) > Number(to)) from = to" />

        <input type="range"
               x-model="to"
               :min="min"
               :max="max"
               step="10"
               class="absolute bottom-0 right-0 h-6 w-full opacity-0 cursor-pointer z-20"
               :style="`width:${100 - fromPercent}%;`"
               @input="if (Number(to) < Number(from)) to = from" />
      </div>
    </div>
  </div>
</div>

<script>
function priceRange() {
  return {
    min: 0,
    max: 1000,
    from: 150,
    to: 650,

    // bar heights
    bars: [8,12,16,24,30,36,28,22,18,14,20,18,16,14,10,8],

    get fromPercent() {
      return ((this.from - this.min) / (this.max - this.min)) * 100;
    },
    get toPercent() {
      return ((this.to - this.min) / (this.max - this.min)) * 100;
    },

    // active bars exactly under handles
    get activeStart() {
      const last = this.bars.length - 1;
      return Math.round((this.fromPercent / 100) * last);
    },
    get activeEnd() {
      const last = this.bars.length - 1;
      return Math.round((this.toPercent / 100) * last);
    }
  };
}
</script>

        <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxNine', 'toggleBtnNine')" class="btn" id="toggleBtnNine">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxNine">
<pre class="line-numbers language-markup"><code class="language-html"><div x-data="priceRange()" class="w-[240px]">

  <!-- Top labels -->
  <div class="flex items-center justify-between mb-3 text-sm text-slate-700">
    <span>Price range</span>
    <span class="font-semibold text-blue-600">
      $<span x-text="from"></span> to $<span x-text="to"></span>
    </span>
  </div>

  <!-- Slider area -->
  <div class="relative h-24 select-none">
    <!-- Inner track with same padding for everything -->
    <div class="absolute inset-x-0 bottom-5 px-4">
      <div class="relative h-12">

        <!-- Bars background -->
        <div class="absolute inset-x-0 bottom-2 flex items-end gap-1">
          <template x-for="(h, i) in bars" :key="i">
            <div
              class="w-2 rounded-t-full transition-colors duration-150"
              :style="`height:${h}px`"
              :class="i >= activeStart && i <= activeEnd ? 'bg-blue-500' : 'bg-slate-300'"
            ></div>
          </template>
        </div>

        <div class="absolute left-0 right-0 bottom-1 h-[2px] bg-slate-200">
          <div class="absolute top-1/2 -translate-y-1/2 h-[2px] bg-blue-500"
               :style="`left:${fromPercent}%; width:${toPercent - fromPercent}%;`"></div>
        </div>

        <div class="absolute bottom-1 z-10"
             :style="`left: calc(${fromPercent}% - 8px)`">
          <div class="w-4 h-4 rounded-full bg-blue-600 shadow"></div>
        </div>

        <div class="absolute bottom-1 z-10"
             :style="`left: calc(${toPercent}% - 8px)`">
          <div class="w-4 h-4 rounded-full bg-blue-600 shadow"></div>
        </div>

        <input type="range"
               x-model="from"
               :min="min"
               :max="max"
               step="10"
               class="absolute bottom-0 left-0 h-6 w-full opacity-0 cursor-pointer z-30"
               :style="`width:${toPercent}%;`"
               @input="if (Number(from) > Number(to)) from = to" />

        <input type="range"
               x-model="to"
               :min="min"
               :max="max"
               step="10"
               class="absolute bottom-0 right-0 h-6 w-full opacity-0 cursor-pointer z-20"
               :style="`width:${100 - fromPercent}%;`"
               @input="if (Number(to) < Number(from)) to = from" />
      </div>
    </div>
  </div>
</div>

<script>
function priceRange() {
  return {
    min: 0,
    max: 1000,
    from: 150,
    to: 650,

    // bar heights
    bars: [8,12,16,24,30,36,28,22,18,14,20,18,16,14,10,8],

    get fromPercent() {
      return ((this.from - this.min) / (this.max - this.min)) * 100;
    },
    get toPercent() {
      return ((this.to - this.min) / (this.max - this.min)) * 100;
    },

    
    get activeStart() {
      const last = this.bars.length - 1;
      return Math.round((this.fromPercent / 100) * last);
    },
    get activeEnd() {
      const last = this.bars.length - 1;
      return Math.round((this.toPercent / 100) * last);
    }
  };
}
</script></code></pre>
      </div>
      </div>

 <div class="border-l-4 border-blue-500 pl-4">
<div x-data="{ open: false }" class="">
  <div class="relative inline-block cursor-pointer group" @click="open = true">
    <!-- Thumbnail image -->
    <img src="https://images.pexels.com/photos/208901/pexels-photo-208901.jpeg" alt="Video thumbnail" class="w-40 h-32 rounded-xl shadow-md group-hover:shadow-lg group-hover:scale-[1.02] transition">
    <!-- Play button overlay -->
    <div class="absolute inset-0 flex items-center justify-center">
      <div class="w-11 h-11 rounded-full bg-black/60 flex items-center justify-center">
        <div class="ml-1 w-0 h-0 border-t-[10px] border-b-[10px] border-l-[16px] border-t-transparent border-b-transparent border-l-white"></div>
      </div>
    </div>
  </div>
  <!-- Popup / modal -->
  <div x-show="open" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @keydown.escape.window="open = false" @click.self="open = false">
    <div x-show="open" x-transition.scale class="bg-black rounded-2xl shadow-xl max-w-3xl w-[90%] aspect-video relative">
      <!-- Close button -->
      <button class="absolute -top-10 right-0 text-white text-sm px-3 py-1 rounded-full bg-black/70 hover:bg-black" @click="open = false">
        Close
      </button>
      <!-- Video player -->
      <video src="https://www.pexels.com/download/video/5396971/" controls autoplay class="w-full h-full rounded-2xl object-cover"></video>
    </div>
  </div>
</div>
<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTen', 'toggleBtnTen')" class="btn" id="toggleBtnTen">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTen">
<pre class="line-numbers language-markup"><code class="language-html"><div x-data="{ open: false }" class="text-center">
  <div class="relative inline-block cursor-pointer group" @click="open = true">
    <!-- Thumbnail image -->
    <img src="https://images.pexels.com/photos/208901/pexels-photo-208901.jpeg" alt="Video thumbnail" class="w-38 h-26 rounded-xl shadow-md group-hover:shadow-lg group-hover:scale-[1.02] transition">
    <!-- Play button overlay -->
    <div class="absolute inset-0 flex items-center justify-center">
      <div class="w-11 h-11 rounded-full bg-black/60 flex items-center justify-center">
        <div class="ml-1 w-0 h-0 border-t-[10px] border-b-[10px] border-l-[16px] border-t-transparent border-b-transparent border-l-white"></div>
      </div>
    </div>
  </div>
  <!-- Popup / modal -->
  <div x-show="open" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @keydown.escape.window="open = false" @click.self="open = false">
    <div x-show="open" x-transition.scale class="bg-black rounded-2xl shadow-xl max-w-3xl w-[90%] aspect-video relative">
      <!-- Close button -->
      <button class="absolute -top-10 right-0 text-white text-sm px-3 py-1 rounded-full bg-black/70 hover:bg-black" @click="open = false">
        Close
      </button>
      <!-- Video player -->
      <video src="https://www.pexels.com/download/video/5396971/" controls autoplay class="w-full h-full rounded-2xl object-cover"></video>
    </div>
  </div>
</div></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
 <div class="w-full max-w-xl rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <!-- Map area -->
      <div class="">
        <div class="relative rounded-2xl overflow-hidden border border-slate-200 bg-slate-100 h-64">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d300.7418964757572!2d74.39625828144445!3d31.482768574538483!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391905fd0546b151%3A0x982569c6469c0f42!2sOld%20Book%20Bank!5e0!3m2!1sen!2s!4v1765459410430!5m2!1sen!2s" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
      </div>
    </div>

<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxEleven', 'toggleBtnEleven')" class="btn" id="toggleBtnEleven">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxEleven">
<pre class="line-numbers language-markup"><code class="language-html"> <div class="w-full max-w-xl rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
      <!-- Map area -->
      <div class="">
        <div class="relative rounded-2xl overflow-hidden border border-slate-200 bg-slate-100 h-64">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d300.7418964757572!2d74.39625828144445!3d31.482768574538483!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391905fd0546b151%3A0x982569c6469c0f42!2sOld%20Book%20Bank!5e0!3m2!1sen!2s!4v1765459410430!5m2!1sen!2s" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
      </div>
    </div></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
   <div class="w-full max-w-5xl px-4 mb-10 rounded-xl">
      <div class="relative h-[150px] w-[500px]">
        <img src="https://q-xx.bstatic.com/xdata/images/xphoto/1080x308/591152843.jpeg?k=468bcc25489a1e4b2648eba9720840b9eb7d1c7243c3c2aae97a84fd7da6378c&o=" alt="Late escape deals" class="w-full h-full object-cover rounded-xl">
        <!-- Dark overlay -->
        <div class="relative inset-0 bg-black/45"></div>

        <!-- Content -->
        <div class="absolute inset-0 px-6 py-3 flex flex-col text-white">
          <p class="text-[10px] font-medium uppercase tracking-wide text-slate-100/80">
            Late Escape Deals
          </p>
          <h3 class="mt-1 text-xl font-semibold">
            Go for a good time, not a long time
          </h3>
          <p class="mt-1 text-sm text-slate-100/90 max-w-md">
            Squeeze out the last bit of sun with at least 15% off.
          </p>
          <button
            class="inline-flex items-center justify-center mt-3 px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 w-max"
          >
            Find deals
          </button>
        </div>
      </div>
</div>

 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwelve', 'toggleBtnTwelve')" class="btn" id="toggleBtnTwelve">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTwelve">
<pre class="line-numbers language-markup"><code class="language-html"> <div class="w-full max-w-5xl px-4 mb-10 rounded-xl">
      <div class="relative h-[150px] w-[500px]">
        <img src="https://q-xx.bstatic.com/xdata/images/xphoto/1080x308/591152843.jpeg?k=468bcc25489a1e4b2648eba9720840b9eb7d1c7243c3c2aae97a84fd7da6378c&o=" alt="Late escape deals" class="w-full h-full object-cover rounded-xl">
        <!-- Dark overlay -->
        <div class="relative inset-0 bg-black/45"></div>

        <!-- Content -->
        <div class="absolute inset-0 px-6 py-3 flex flex-col text-white">
          <p class="text-[10px] font-medium uppercase tracking-wide text-slate-100/80">
            Late Escape Deals
          </p>
          <h3 class="mt-1 text-xl font-semibold">
            Go for a good time, not a long time
          </h3>
          <p class="mt-1 text-sm text-slate-100/90 max-w-md">
            Squeeze out the last bit of sun with at least 15% off.
          </p>
          <button
            class="inline-flex items-center justify-center mt-3 px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 w-max"
          >
            Find deals
          </button>
        </div>
      </div>
</div></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
   <div class="max-w-5xl px-4 mb-10 rounded-xl">
    <div class="max-w-xl bg-white rounded-lg border border-slate-200 shadow-sm px-4 py-4 flex items-center justify-between gap-4">
        <div class="space-y-2">
          <p class="text-[11px] font-normal text-slate-600 uppercase tracking-wide">
            Early 2026 Deals
          </p>
          <h3 class="text-xl font-bold text-slate-900">
            At least 15% off
          </h3>
          <p class="text-sm text-slate-600 max-w-md">
            Save on your next stay with Early 2026 Deals. Book now, stay until 1 April 2026.
          </p>
          <button
            class="inline-flex items-center justify-center mt-2 px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700"
          >
            Explore deals
          </button>
        </div>

        <div class="hidden sm:block">
          <img
            src="https://images.pexels.com/photos/271639/pexels-photo-271639.jpeg?auto=compress&cs=tinysrgb&w=400"
            alt="Early deals"
            class="w-28 h-28 rounded-lg object-cover"
          >
        </div>
      </div>
</div>

 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxThirteen', 'toggleBtnThirteen')" class="btn" id="toggleBtnThirteen">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxThirteen">
<pre class="line-numbers language-markup"><code class="language-html"> <div class="max-w-5xl px-4 mb-10 rounded-xl">
    <div class="max-w-xl bg-white rounded-lg border border-slate-200 shadow-sm px-4 py-4 flex items-center justify-between gap-4">
        <div class="space-y-2">
          <p class="text-[11px] font-normal text-slate-600 uppercase tracking-wide">
            Early 2026 Deals
          </p>
          <h3 class="text-xl font-bold text-slate-900">
            At least 15% off
          </h3>
          <p class="text-sm text-slate-600 max-w-md">
            Save on your next stay with Early 2026 Deals. Book now, stay until 1 April 2026.
          </p>
          <button
            class="inline-flex items-center justify-center mt-2 px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700"
          >
            Explore deals
          </button>
        </div>

        <div class="hidden sm:block">
          <img
            src="https://images.pexels.com/photos/271639/pexels-photo-271639.jpeg?auto=compress&cs=tinysrgb&w=400"
            alt="Early deals"
            class="w-28 h-28 rounded-lg object-cover"
          >
        </div>
      </div>
</div></code></pre>
</div>
</div>


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
</style>