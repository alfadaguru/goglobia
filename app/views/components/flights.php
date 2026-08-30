<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <!-- Main Content Area -->
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
            <!-- Dashboard Content -->
            <div class="bg-white rounded-lg p-6 shadow-sm">
               <h1 class="text-2xl font-bold text-gray-900 mb-4">Flights Components</h1>
               <p class="text-gray-600 mb-6">Comprehensive flights system with headers, footers, colors, and interactive styles</p>
               <!-- Content -->
               <div class="space-y-8">
                  <!-- Basic Card with Icon Header -->
<div class="border-l-4 border-blue-500 pl-4">
<div class="bg-[#050708] w-full max-w-[410px] rounded-3xl shadow-xl border border-white/10 mb-5">
  <!-- Top Row -->
  <div class="flex flex-row justify-between items-center border-b border-white/20 px-4 sm:px-5 py-3 gap-3 sm:gap-0">
      <div class="flex flex-row items-center gap-3 w-full sm:w-auto">
        <div class="max-w-xl h-10">
            <img src="https://pics.avs.io/200/200/SV@2x.png" alt="logo" class="w-16 sm:w-20 h-12 sm:h-15 object-cover relative bottom-2 sm:bottom-1">
        </div>
        <div class="">
            <h1 class="text-[13px] font-semibold text-gray-300 mb-0.5">
              Delta Air Lines
            </h1>
            <p class="text-gray-400 text-[11px] font-semibold">DL 0668</p>
        </div>
      </div>
      <div class="border border-white/10 px-4 py-1.5 rounded-lg text-right mt-2 sm:mt-0 w-auto">
        <p class="font-semibold text-[12.5px] text-gray-300">$220.50</p>
      </div>
  </div>
  <!-- Flight Time Section -->
  <div class="flex flex-row justify-between items-center px-4 sm:px-5 py-[9px] gap-4 sm:gap-0">
      <div class="text-right flex flex-col justify-between sm:justify-start gap-2 w-full sm:w-auto">
        <p class="text-[11.5px] font-semibold text-gray-300">09:25</p>
        <p class="text-white/50 font-medium text-[11.5px]">12 Feb 25</p>
        <p class="text-white/40 text-[11px]">LGA</p>
      </div>
      <div class="text-center flex flex-col items-center">
        <div class="flex flex-row text-white/65 items-center justify-center mx-1 gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="rotate-90 w-6 h-6 sm:w-8 sm:h-8" viewBox="0 0 24 24" fill="currentColor">
              <path d="M21 16v-2l-8-5V3.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5V9l-8 5v2l8-1.5V20l-2 1v1h6v-1l-2-1v-5.5L21 16z"></path>
            </svg>
            <div class="flex flex-col gap-3">
              <p class="text-[11px] font-semibold text-white/70 ">Non Stop</p>
              <!-- dashed line -->
              <div class="flex gap-[5px]">
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25 hidden sm:block"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25 hidden sm:block"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25 hidden sm:block"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25 hidden sm:block"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
              </div>
              <p class="text-white/60 font-semibold text-[11px]">2h:5m</p>
            </div>
            <div class="rounded-full bg-white/40 w-2.5 h-2.5"></div>
        </div>
      </div>
      <div class="text-left flex flex-col gap-2 w-full sm:w-auto">
        <p class="text-[11.5px] text-gray-300 font-semibold">11:30</p>
        <p class="text-white/50 font-medium text-[11.5px]">12 Feb 25</p>
        <p class="text-white/40 text-[11px]">LAX</p>
      </div>
  </div>
  <!-- Bottom Row -->
  <div class="flex flex-col sm:flex-row gap-2 sm:gap-3.5 px-4 sm:px-6 py-3.5 border-t border-white/10">
      <div class="text-center border border-white/10 rounded-lg px-8 py-3 w-full sm:w-auto">
        <p class="text-white/65 font-medium text-[11.5px]">Eco. Light</p>
      </div>
      <div class="text-center border border-white/10 rounded-lg px-6 py-3 w-full sm:w-auto">
        <p class="text-white/65 font-medium text-[11.5px]">708 mi</p>
      </div>
      <div class="flex gap-3 w-full sm:w-auto justify-between">
        <!-- First SVG -->
        <div class="flex flex-col justify-center items-center text-sm border border-white/10 rounded-lg px-2 py-1.5 text-gray-400 w-full sm:w-auto">
            <svg xmlns="http://www.w3.org/2000/svg" width="27" height="27" viewBox="0 0 24 24">
              <path fill="currentColor" d="M3.385 19.385v-1h17.23v1zm3.23-3q-.69 0-1.152-.463T5 14.769V8.616q0-.691.463-1.153T6.616 7h3q0-.98.701-1.683q.702-.702 1.683-.702t1.683.702T14.385 7h3q.69 0 1.153.463T19 8.616v6.153q0 .69-.462 1.153t-1.153.463zm10.077-1h.693q.269 0 .442-.174q.173-.173.173-.442V8.616q0-.27-.173-.443T17.385 8h-.693zM10.5 7h3q0-.65-.425-1.075T12 5.5t-1.075.425T10.5 7m-3.192 8.385V8h-.692q-.27 0-.443.173T6 8.616v6.153q0 .27.173.443t.443.173zM8.192 8v7.385h7.616V8zm-.884 7.385h.884zm9.384 0h-.884zm-9.384 0H6zm.884 0h7.616zm8.5 0H18z"></path>
            </svg>
        </div>
        <!-- Second SVG -->
        <div class="flex flex-col justify-center items-center text-sm border border-white/10 rounded-lg px-2.5 py-1.5 text-gray-500 w-full sm:w-auto">
            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24">
              <path fill="currentColor" d="M11 6h2V4h-2zm1 6q-1.9 0-3.625-.788T5 9.45V8q0-.825.588-1.412T7 6h2V3q0-.425.288-.712T10 2h4q.425 0 .713.288T15 3v3h2q.825 0 1.413.588T19 8v1.45q-1.65.975-3.375 1.763T12 12m-5 9q-.825 0-1.412-.587T5 19v-7.3q1.4.85 2.888 1.45t3.112.8V14q0 .425.288.713T12 15t.713-.288T13 14v-.05q1.625-.2 3.113-.8T19 11.7V19q0 .825-.587 1.413T17 21q0 .425-.288.713T16 22q-.4 0-.562-.363T15 21H9q0 .425-.288.713T8 22q-.4 0-.562-.363T7 21"></path>
            </svg>
        </div>
      </div>
  </div>
</div>

<!-- Code toggle button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <button  onclick="toggleCode('codeBoxOne', 'toggleBtnOne')" class="btn" id="toggleBtnOne">
    Show Code
  </button>
 </div>

<div id="codeBoxOne" class="hidden transition-all duration-300">
   <pre class="line-numbers language-markup"><code class="language-html"><div class="bg-[#050708] w-full max-w-[410px] rounded-3xl shadow-xl border border-white/10">
   <!-- Top Row -->
   <div class="flex flex-row justify-between items-center border-b border-white/20 px-4 sm:px-5 py-3 gap-3 sm:gap-0">
      <div class="flex flex-row items-center gap-3 w-full sm:w-auto">
         <div class="max-w-xl h-10">
            <img src="https://pics.avs.io/200/200/SV@2x.png" alt="logo" class="w-16 sm:w-20 h-12 sm:h-15 object-cover relative bottom-2 sm:bottom-1">
         </div>
         <div class="">
            <h1 class="text-[13px] font-semibold text-gray-300 mb-0.5">
               Delta Air Lines
            </h1>
            <p class="text-gray-400 text-[11px] font-semibold">DL 0668</p>
         </div>
      </div>
      <div class="border border-white/10 px-4 py-1.5 rounded-lg text-right mt-2 sm:mt-0 w-auto">
         <p class="font-semibold text-[12.5px] text-gray-300">$220.50</p>
      </div>
   </div>
   <!-- Flight Time Section -->
   <div class="flex flex-row justify-between items-center px-4 sm:px-5 py-[9px] gap-4 sm:gap-0">
      <div class="text-right flex flex-col justify-between sm:justify-start gap-2 w-full sm:w-auto">
         <p class="text-[11.5px] font-semibold text-gray-300">09:25</p>
         <p class="text-white/50 font-medium text-[11.5px]">12 Feb 25</p>
         <p class="text-white/40 text-[11px]">LGA</p>
      </div>
      <div class="text-center flex flex-col items-center">
         <div class="flex flex-row text-white/65 items-center justify-center mx-1 gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="rotate-90 w-6 h-6 sm:w-8 sm:h-8" viewBox="0 0 24 24" fill="currentColor">
               <path d="M21 16v-2l-8-5V3.5a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5V9l-8 5v2l8-1.5V20l-2 1v1h6v-1l-2-1v-5.5L21 16z"></path>
            </svg>
            <div class="flex flex-col gap-3">
               <p class="text-[11px] font-semibold text-white/70 ">Non Stop</p>
               <!-- dashed line -->
               <div class="flex gap-[5px]">
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25 hidden sm:block"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25 hidden sm:block"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25 hidden sm:block"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25 hidden sm:block"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
                  <div class="w-[7px] h-[1.5px] bg-white/25"></div>
               </div>
               <p class="text-white/60 font-semibold text-[11px]">2h:5m</p>
            </div>
            <div class="rounded-full bg-white/40 w-2.5 h-2.5"></div>
         </div>
      </div>
      <div class="text-left flex flex-col gap-2 w-full sm:w-auto">
         <p class="text-[11.5px] text-gray-300 font-semibold">11:30</p>
         <p class="text-white/50 font-medium text-[11.5px]">12 Feb 25</p>
         <p class="text-white/40 text-[11px]">LAX</p>
      </div>
   </div>
   <!-- Bottom Row -->
   <div class="flex flex-col sm:flex-row gap-2 sm:gap-3.5 px-4 sm:px-6 py-3.5 border-t border-white/10">
      <div class="text-center border border-white/10 rounded-lg px-8 py-3 w-full sm:w-auto">
         <p class="text-white/65 font-medium text-[11.5px]">Eco. Light</p>
      </div>
      <div class="text-center border border-white/10 rounded-lg px-6 py-3 w-full sm:w-auto">
         <p class="text-white/65 font-medium text-[11.5px]">708 mi</p>
      </div>
      <div class="flex gap-3 w-full sm:w-auto justify-between">
         <!-- First SVG -->
         <div class="flex flex-col justify-center items-center text-sm border border-white/10 rounded-lg px-2 py-1.5 text-gray-400 w-full sm:w-auto">
            <svg xmlns="http://www.w3.org/2000/svg" width="27" height="27" viewBox="0 0 24 24">
               <path fill="currentColor" d="M3.385 19.385v-1h17.23v1zm3.23-3q-.69 0-1.152-.463T5 14.769V8.616q0-.691.463-1.153T6.616 7h3q0-.98.701-1.683q.702-.702 1.683-.702t1.683.702T14.385 7h3q.69 0 1.153.463T19 8.616v6.153q0 .69-.462 1.153t-1.153.463zm10.077-1h.693q.269 0 .442-.174q.173-.173.173-.442V8.616q0-.27-.173-.443T17.385 8h-.693zM10.5 7h3q0-.65-.425-1.075T12 5.5t-1.075.425T10.5 7m-3.192 8.385V8h-.692q-.27 0-.443.173T6 8.616v6.153q0 .27.173.443t.443.173zM8.192 8v7.385h7.616V8zm-.884 7.385h.884zm9.384 0h-.884zm-9.384 0H6zm.884 0h7.616zm8.5 0H18z"></path>
            </svg>
         </div>
         <!-- Second SVG -->
         <div class="flex flex-col justify-center items-center text-sm border border-white/10 rounded-lg px-2.5 py-1.5 text-gray-500 w-full sm:w-auto">
            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24">
               <path fill="currentColor" d="M11 6h2V4h-2zm1 6q-1.9 0-3.625-.788T5 9.45V8q0-.825.588-1.412T7 6h2V3q0-.425.288-.712T10 2h4q.425 0 .713.288T15 3v3h2q.825 0 1.413.588T19 8v1.45q-1.65.975-3.375 1.763T12 12m-5 9q-.825 0-1.412-.587T5 19v-7.3q1.4.85 2.888 1.45t3.112.8V14q0 .425.288.713T12 15t.713-.288T13 14v-.05q1.625-.2 3.113-.8T19 11.7V19q0 .825-.587 1.413T17 21q0 .425-.288.713T16 22q-.4 0-.562-.363T15 21H9q0 .425-.288.713T8 22q-.4 0-.562-.363T7 21"></path>
            </svg>
         </div>
      </div>
   </div>
</div></code></pre>
</div>
 </div>

<div class="border-l-4 border-green-500 pl-4">
<div class="max-w-[380px] w-full mb-5">
  <div class="bg-white/95 backdrop-blur-sm rounded-2xl ring-1 ring-gray-200 px-4 py-4">
      <form class="space-y-3.5">
        <div class="radio-group flex gap-4">
            <div class="radio-item">
              <div class="radio-container mt-2.5">
                  <input type="radio" id="basic1" name="basic" class="radio-input " value="option1">
                  <div class="radio-custom">
                    <div class="radio-dot"></div>
                  </div>
              </div>
              <label for="basic1" class="cursor-pointer mt-3">One way</label>
            </div>
            <div class="radio-item">
              <div class="radio-container">
                  <input type="radio" id="basic2" name="basic" class="radio-input" value="option2" checked="">
                  <div class="radio-custom">
                    <div class="radio-dot"></div>
                  </div>
              </div>
              <label for="basic2" class="cursor-pointer">Return</label>
            </div>
            <div class="radio-item">
              <div class="radio-container">
                  <input type="radio" id="basic3" name="basic" class="radio-input" value="option3" checked="">
                  <div class="radio-custom">
                    <div class="radio-dot"></div>
                  </div>
              </div>
              <label for="basic3" class="cursor-pointer">Multi-city</label>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-[11.5px] font-medium text-[#19191a]"
                  >Origin</label
                  >
              <input
                  type="text"
                  value="SFO"
                  class="input"
                  />
            </div>
            <div>
              <label class="text-[11.5px] font-medium text-[#19191a]"
                  >Destination</label
                  >
              <input
                  type="text"
                  value="LHR"
                  class="input"
                  />
            </div>
        </div>
        <div>
            <label class="text-[11.5px] text-[#19191a] font-medium"
              >Departure date</label
              >
            <input type="text" class="input dp" placeholder="Select date" readonly="">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="form-control">
              <label class="text-[11.5px] text-[#19191a] font-medium">Passengers</label>
              <select class="select">
                  <option value="">Adult</option>
                  <option value="option1">Adult 1</option>
                  <option value="option2">Adult 2</option>
                  <option value="option3">Adult 3</option>
              </select>
            </div>
            <div class="form-control">
              <label class="text-[11.5px] text-[#19191a] font-medium">Cabin class</label
                  >
              <select class="select">
                  <option value="option1" selected>Economy</option>
                  <option value="option2">Premium Economy</option>
                  <option value="option3" >Business</option>
                  <option value="option4">First</option>
              </select>
            </div>
        </div>
        <div>
            <button class="btn slate w-full">Search flights</button>
        </div>
      </form>
  </div>
</div>
<div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwo', 'toggleBtnTwo')" class="btn" id="toggleBtnTwo">
    Show Code
  </button>
</div>
<div class="hidden transition-all duration-300" id="codeBoxTwo">
  <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-[380px] w-full">
    <div class="bg-white/95 backdrop-blur-sm rounded-2xl ring-1 ring-gray-200 px-4 py-4">
<form class="space-y-3.5">
    <!-- Radio Buttons -->
    <div class="radio-group flex gap-4">  
        <!-- One Way -->
        <div class="radio-item">
            <div class="radio-container mt-2">
                <input type="radio" id="basic1" name="basic" class="radio-input" value="one-way">
                <div class="radio-custom">
                    <div class="radio-dot"></div>
                </div>
            </div>
            <label for="basic1" class="cursor-pointer">One way</label>
        </div>

        <!-- Return -->
        <div class="radio-item">
            <div class="radio-container mt-2">
                <input type="radio" id="basic2" name="basic" class="radio-input" value="return">
                <div class="radio-custom">
                    <div class="radio-dot"></div>
                </div>
            </div>
            <label for="basic2" class="cursor-pointer">Return</label>
        </div>

        <!-- Multi-city -->
        <div class="radio-item">
            <div class="radio-container mt-2">
                <input type="radio" id="basic3" name="basic" class="radio-input" value="multi-city">
                <div class="radio-custom">
                    <div class="radio-dot"></div>
                </div>
            </div>
            <label for="basic3" class="cursor-pointer">Multi-city</label>
        </div>

    </div>

    <!-- Origin / Destination -->
    <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-[11.5px] font-medium text-[#19191a]">Origin</label>
          <input type="text" value="SFO" class="mt-1 block w-full text-sm rounded-md border border-gray-200 px-3 py-1.5 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
        </div>

        <div>
          <label class="text-[11.5px] font-medium text-[#19191a]">Destination
          <label>
          <input type="text" value="LHR" class="mt-1 block w-full text-sm rounded-md border border-gray-200 px-3 py-1.5 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
        </div>
    </div>
    <!-- Departure Date -->
    <div>
        <label class="text-[11.5px] text-[#19191a] font-medium">Departure date</label>
        <input type="text" class="input dp" placeholder="Select date" readonly>
    </div>

    <!-- Select Boxes -->
    <div class="grid grid-cols-2 gap-3">
        
        <div class="form-control">
            <label class="text-[11.5px] text-[#19191a] font-medium">Passengers</label>
            <select class="select">
                <option value="">Adult</option>
                <option value="1">Adult 1</option>
                <option value="2">Adult 2</option>
                <option value="3">Adult 3</option>
            </select>
        </div>

        <div class="form-control">
            <label class="text-[11.5px] text-[#19191a] font-medium">Cabin class</label>
            <select class="select">
                <option value="economy" selected>Economy</option>
                <option value="premium">Premium Economy</option>
                <option value="business">Business</option>
                <option value="first">First</option>
            </select>
        </div>
    </div>

    <!-- Submit Button -->
    <div>
        <button class="btn slate w-full">Search flights</button>
    </div>

</form>
</div>
</div></code></pre>
  </div>
</div>
<div class="border-l-4 border-purple-500 pl-4">
<div class="max-w-lg space-y-1 mb-6">
<!-- Flight Card 1 -->
<div class="bg-white px-4 py-3 flex items-center gap-4 flight-card">
    <!-- Airline Logo -->
    <div class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center flex-shrink-0">
      <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
          <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
      </svg>
    </div>
    <!-- Flight Info -->
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2 mb-1">
          <span class="font-semibold text-[13px] text-gray-800">DJSUEH</span>
          <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-600 text-[9px] font-normal rounded-full">Confirmed</span>
      </div>
      <div class="flex items-center gap-2 font-medium text-[11.5px] text-black/90">
          <span class="">LHR</span>
          <span>⇄</span>
          <span class="">JFK</span>
          <span class="text-gray-200">|</span>
          <span class="">14 Mar 2022, 22:00</span>
          <span class="text-gray-200">|</span>
          <span class="">£234.12</span>
      </div>
      <p class="text-[11.5px] text-gray-500 mt-1">Pepper Potts</p>
    </div>
</div>
<!-- Flight Card 2 -->
<div class="bg-white px-4 py-3 flex items-center gap-4 flight-card">
    <!-- Airline Logo -->
    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-900 to-blue-700 flex items-center justify-center flex-shrink-0">
      <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
          <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
      </svg>
    </div>
    <!-- Flight Info -->
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2 mb-1">
          <span class="font-semibold text-[13px] text-gray-800">HFGFTR</span>
          <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-600 text-[10px] font-normal rounded-full">Confirmed</span>
      </div>
      <div class="flex items-center gap-2 font-medium text-[11.5px] text-black/90">
          <span class="">SEA</span>
          <span>→</span>
          <span class="">JFK</span>
          <span class="text-gray-500">+3 more</span>
          <span class="text-gray-200">|</span>
          <span>14 Mar 2022, 22:00</span>
          <span class="text-gray-200">|</span>
          <span class="">£1,230.45</span>
      </div>
      <p class="text-[11.5px] text-gray-500 mt-1">James Wair</p>
    </div>
</div>
<!-- Flight Card 3 -->
<div class="bg-white px-4 py-3 flex items-center gap-4 flight-card">
    <!-- Airline Logo -->
    <div class="w-10 h-10 rounded-full overflow-hidden flex-shrink-0 bg-gray-100 flex items-center justify-center">
      <div class="w-full h-full flex flex-col">
          <div class="h-1/3 bg-blue-800"></div>
          <div class="h-1/3 bg-yellow-400"></div>
          <div class="h-1/3 bg-blue-800"></div>
      </div>
    </div>
    <!-- Flight Info -->
    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2 mb-1">
          <span class="font-semibold text-[13px] text-gray-800">EEURHG</span>
          <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-600 text-[10px] font-normal rounded-full">Confirmed</span>
      </div>
      <div class="flex items-center gap-2 font-medium text-[11.5px] text-black/90">
          <span class="">JFK</span>
          <span>→</span>
          <span class="">SEA</span>
          <span class="text-gray-200">|</span>
          <span>14 Mar 2022, 22:00</span>
          <span class="text-gray-200">|</span>
          <span class="">£123.67</span>
      </div>
      <p class="text-[11.5px] text-gray-500 mt-1">Steve Domin</p>
    </div>
</div>
</div>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const cards = document.querySelectorAll(".flight-card");

  cards.forEach(card => {
    card.addEventListener("click", () => {

      // Sab cards se active Tailwind classes hatao
      cards.forEach(c => {
        c.classList.remove("bg-blue-50");
      });

      // Clicked card par active Tailwind classes add karo
      card.classList.add("bg-blue-50");
    });
  });
});
</script>

<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxThree', 'toggleBtnThree')" class="btn" id="toggleBtnThree">
    Show Code
  </button>
</div>

  <div class="hidden transition-all duration-300" id="codeBoxThree">
 <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-lg space-y-1">
    
    <!-- Flight Card 1 -->
    <div class="bg-white px-4 py-3 flex items-center gap-4 flight-card">
      <!-- Airline Logo -->
      <div class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
          <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
        </svg>
      </div>
      
      <!-- Flight Info -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <span class="font-semibold text-[13px] text-gray-800">DJSUEH</span>
          <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-600 text-[9px] font-normal rounded-full">Confirmed</span>
        </div>
        <div class="flex items-center gap-2 font-medium text-[11.5px] text-black/90">
          <span class="">LHR</span>
          <span>⇄</span>
          <span class="">JFK</span>
          <span class="text-gray-200">|</span>
          <span class="">14 Mar 2022, 22:00</span>
          <span class="text-gray-200">|</span>
          <span class="">£234.12</span>
        </div>
        <p class="text-[11.5px] text-gray-500 mt-1">Pepper Potts</p>
      </div>
    </div>

    <!-- Flight Card 2 -->
    <div class="bg-white px-4 py-3 flex items-center gap-4 flight-card">
      <!-- Airline Logo -->
      <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-900 to-blue-700 flex items-center justify-center flex-shrink-0">
        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
          <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
        </svg>
      </div>
      
      <!-- Flight Info -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <span class="font-semibold text-[13px] text-gray-800">HFGFTR</span>
          <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-600 text-[10px] font-normal rounded-full">Confirmed</span>
        </div>
        <div class="flex items-center gap-2 font-medium text-[11.5px] text-black/90">
          <span class="">SEA</span>
          <span>→</span>
          <span class="">JFK</span>
          <span class="text-gray-500">+3 more</span>
          <span class="text-gray-200">|</span>
          <span>14 Mar 2022, 22:00</span>
          <span class="text-gray-200">|</span>
          <span class="">£1,230.45</span>
        </div>
        <p class="text-[11.5px] text-gray-500 mt-1">James Wair</p>
      </div>
    </div>

    <!-- Flight Card 3 -->
    <div class="bg-white px-4 py-3 flex items-center gap-4 flight-card">
      <!-- Airline Logo -->
      <div class="w-10 h-10 rounded-full overflow-hidden flex-shrink-0 bg-gray-100 flex items-center justify-center">
        <div class="w-full h-full flex flex-col">
          <div class="h-1/3 bg-blue-800"></div>
          <div class="h-1/3 bg-yellow-400"></div>
          <div class="h-1/3 bg-blue-800"></div>
        </div>
      </div>
      
      <!-- Flight Info -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <span class="font-semibold text-[13px] text-gray-800">EEURHG</span>
          <span class="px-1.5 py-0.5 bg-emerald-100 text-emerald-600 text-[10px] font-normal rounded-full">Confirmed</span>
        </div>
        <div class="flex items-center gap-2 font-medium text-[11.5px] text-black/90">
          <span class="">JFK</span>
          <span>→</span>
          <span class="">SEA</span>
          <span class="text-gray-200">|</span>
          <span>14 Mar 2022, 22:00</span>
          <span class="text-gray-200">|</span>
          <span class="">£123.67</span>
        </div>
        <p class="text-[11.5px] text-gray-500 mt-1">Steve Domin</p>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", () => {
      const cards = document.querySelectorAll(".flight-card");
      cards.forEach(card => {
        card.addEventListener("click", () => {
          cards.forEach(c => {
            c.classList.remove("bg-blue-50");
          });
          card.classList.add("bg-blue-50");
        });
      });
    });
</script>
</code></pre>
    </div>
 </div>
               
<div class="border-l-4 border-blue-500 pl-4">
<div class="bg-white rounded-3xl shadow-2xl max-w-[335px] w-full my-8">
    <div class="relative h-56 p-4">
      <div class="w-3xs">
          <iframe
            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d212.65664039831708!2d74.3964007525602!3d31.48276592885318!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391905fd0546b151%3A0x982569c6469c0f42!2sOld%20Book%20Bank!5e0!3m2!1sen!2s!4v1763986332940!5m2!1sen!2s"
            width="305"
            height="220"
            class="rounded-lg"
            allowfullscreen=""
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            ></iframe>
      </div>
      <div
          class="absolute top-8 right-7 w-8 h-8 bg-white rounded-full shadow-md flex items-center justify-center text-gray-300"
          >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            >
            <path
                fill="currentColor"
                d="M17 3H7c-1.1 0-2 .9-2 2v16l7-3l7 3V5c0-1.1-.9-2-2-2"
                />
          </svg>
      </div>
    </div>
    <div class="p-6">
      <h2 class="text-lg font-semibold text-[#090a0b] mb-2">
          Product Team Meet-up
      </h2>
      <div class="flex items-center text-gray-400 text-sm mb-6">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="20"
            viewBox="0 0 24 24"
            >
            <path
                fill="currentColor"
                d="M12 11.5A2.5 2.5 0 0 1 9.5 9A2.5 2.5 0 0 1 12 6.5A2.5 2.5 0 0 1 14.5 9a2.5 2.5 0 0 1-2.5 2.5M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7"
                />
          </svg>
          <span>New York, USA</span>
          <div class="ml-auto flex items-center gap-2">
            <div class="flex -space-x-2">
                <div
                  class="w-6 h-6 rounded-full bg-purple-100 text-purple-300 border-2 border-purple-200"
                  >
                  <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="18"
                      height="18"
                      viewBox="0 0 24 24"
                      class="mt-[1px] ml-[1.5px]"
                      >
                      <path
                        fill="currentColor"
                        d="M12 4a4 4 0 0 1 4 4a4 4 0 0 1-4 4a4 4 0 0 1-4-4a4 4 0 0 1 4-4m0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4"
                        />
                  </svg>
                </div>
                <div
                  class="w-6 h-6 rounded-full bg-green-100 text-green-300 border-2 border-green-200"
                  >
                  <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="18"
                      height="18"
                      viewBox="0 0 24 24"
                      class="mt-[1px] ml-[2px]"
                      >
                      <path
                        fill="currentColor"
                        d="M12 4a4 4 0 0 1 4 4a4 4 0 0 1-4 4a4 4 0 0 1-4-4a4 4 0 0 1 4-4m0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4"
                        />
                  </svg>
                </div>
                <div  class="w-6 h-6 rounded-full bg-blue-100 border-2 border-green-300">
                  <span class="mt-1 ml-[1.5px] text-voilet-300 text-xs">+2</span>
                </div>
            </div>
          </div>
      </div>
      <div class="border border-gray-100 rounded-md p-2 mb-4">
          <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-gray-500 mb-1">8:00am</div>
                <div class="text-xl font-medium text-gray-900">SFO</div>
                <div class="text-xs text-gray-400">San Francisco</div>
            </div>
            <div class="flex-1 px-10 flex items-center justify-center">
                <div class="relative w-full text-gray-300">
                  <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24">
                      <path fill="currentColor" d="M4 11v2h12l-5.5 5.5l1.42 1.42L19.84 12l-7.92-7.92L10.5 5.5L16 11z"/>
                  </svg>
                </div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500 mb-1">10:00am</div>
                <div class="text-xl font-medium text-gray-900">JFK</div>
                <div class="text-xs text-gray-400">New York</div>
            </div>
          </div>
      </div>
      <div class="flex items-center justify-between border border-gray-100 rounded-md p-2">
          <div>
            <div class="text-sm font-semibold text-gray-900">Belmont Hotel</div>
            <div class="text-xs text-gray-400">Chelsea</div>
          </div>
          <div>
            <div class="text-sm font-semibold text-gray-900 text-right">$320.00</div>
            <div class="bg-gray-200 rounded-full w-12 h-2 ml-8"></div>
          </div>
      </div>
    </div>
</div>
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
 <pre class="line-numbers language-markup"><code class="language-html"><div class="bg-white rounded-3xl shadow-2xl max-w-[335px] w-full">
        <div class="relative h-56 p-4">
          <div class="w-3xs">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d212.65664039831708!2d74.3964007525602!3d31.48276592885318!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x391905fd0546b151%3A0x982569c6469c0f42!2sOld%20Book%20Bank!5e0!3m2!1sen!2s!4v1763986332940!5m2!1sen!2s"
            width="305" height="220" class="rounded-lg" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
          </div>

          <div class="absolute top-8 right-7 w-8 h-8 bg-white rounded-full shadow-md flex items-center justify-center text-gray-300">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
              <path fill="currentColor" d="M17 3H7c-1.1 0-2 .9-2 2v16l7-3l7 3V5c0-1.1-.9-2-2-2"/>
            </svg>
          </div>
        </div>
        <div class="p-6">
          <h2 class="text-lg font-semibold text-[#090a0b] mb-2">
            Product Team Meet-up
          </h2>
          <div class="flex items-center text-gray-400 text-sm mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="20" viewBox="0 0 24 24">
              <path fill="currentColor" d="M12 11.5A2.5 2.5 0 0 1 9.5 9A2.5 2.5 0 0 1 12 6.5A2.5 2.5 0 0 1 14.5 9a2.5 2.5 0 0 1-2.5 2.5M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7"/>
            </svg>
            <span>New York, USA</span>
            <div class="ml-auto flex items-center gap-2">
              <div class="flex -space-x-2">
                <div class="w-6 h-6 rounded-full bg-purple-100 text-purple-300 border-2 border-purple-200">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                    viewBox="0 0 24 24" class="mt-[1px] ml-[1.5px]">
                    <path fill="currentColor" d="M12 4a4 4 0 0 1 4 4a4 4 0 0 1-4 4a4 4 0 0 1-4-4a4 4 0 0 1 4-4m0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4"/>
                  </svg>
                </div>
                <div class="w-6 h-6 rounded-full bg-green-100 text-green-300 border-2 border-green-200">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" class="mt-[1px] ml-[2px]">
                    <path fill="currentColor" d="M12 4a4 4 0 0 1 4 4a4 4 0 0 1-4 4a4 4 0 0 1-4-4a4 4 0 0 1 4-4m0 10c4.42 0 8 1.79 8 4v2H4v-2c0-2.21 3.58-4 8-4"/>
                  </svg>
                </div>
                <div  class="w-6 h-6 rounded-full bg-blue-100 border-2 border-green-300">
                   <span class="mt-1 ml-[1.5px] text-voilet-300 text-xs">+2</span>
                </div>
              </div>
            </div>
          </div>
          <div class="border border-gray-100 rounded-md p-2 mb-4">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-xs text-gray-500 mb-1">8:00am</div>
                <div class="text-xl font-medium text-gray-900">SFO</div>
                <div class="text-xs text-gray-400">San Francisco</div>
              </div>

              <div class="flex-1 px-10 flex items-center justify-center">
                <div class="relative w-full text-gray-300">
                  <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"><path fill="currentColor" d="M4 11v2h12l-5.5 5.5l1.42 1.42L19.84 12l-7.92-7.92L10.5 5.5L16 11z"/></svg>
                </div>
              </div>

              <div class="text-right">
                <div class="text-xs text-gray-500 mb-1">10:00am</div>
                <div class="text-xl font-medium text-gray-900">JFK</div>
                <div class="text-xs text-gray-400">New York</div>
              </div>
            </div>
          </div>

          <div class="flex items-center justify-between border border-gray-100 rounded-md p-2">
            <div>
              <div class="text-sm font-semibold text-gray-900">Belmont Hotel</div>
              <div class="text-xs text-gray-400">Chelsea</div>
            </div>
            <div>
                <div class="text-sm font-semibold text-gray-900 text-right">$320.00</div>
                <div class="bg-gray-200 rounded-full w-12 h-2 ml-8"></div>
            </div>
          </div>
        </div>
      </div></code></pre>
     </div>
     </div>
            
<div class="border-l-4 border-blue-500 pl-4">
<div class="max-w-6xl px-2 py-4 bg-white shadow-md rounded-2xl my-6 h-26 sm:h-18">
<div class="flex flex-wrap sm:flex-row gap-3">
    <!-- Logo / Brand -->
    <div class="flex gap-2 items-center border-r pl-3 pr-4  my-2">
      <div class="p-1 rounded-full bg-[#5b92e3] text-white">
 <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24">
  <path fill="currentColor" stroke="white" stroke-width="2" d="m9.55 15.15l8.475-8.475q.3-.3.7-.3t.7.3t.3.713t-.3.712l-9.175 9.2q-.3.3-.7.3t-.7-.3L4.55 13q-.3-.3-.288-.712t.313-.713t.713-.3t.712.3z" />
 </svg>
  </div>
        <span class="text-lg font-semibold text-gray-800">One way</span>
      </div>
      <div class="flex gap-4 items-center">
      <!-- Dropdown 1: Class -->
  <select class="select">
      <option value="">Class</option>
      <option value="option1">Option 1</option>
      <option value="option2">Option 2</option>
      <option value="option3">Option 3</option>
  </select>
      <!-- Dropdown 2: Price -->
  <select class="select">
      <option value="">Price</option>
      <option value="option1">Option 1</option>
      <option value="option2">Option 2</option>
      <option value="option3">Option 3</option>
  </select>
      <!-- Dropdown 3: Stops -->
  <select class="select">
      <option value="">Stops</option>
      <option value="option1">Option 1</option>
      <option value="option2">Option 2</option>
      <option value="option3">Option 3</option>
  </select>
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
<div class="hidden transition-all duration-300" id="codeBoxFive">                  <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-6xl px-2 py-4 bg-white shadow-md rounded-2xl my-6 h-26 sm:h-18">
<div class="flex flex-wrap sm:flex-row gap-3">
    <!-- Logo / Brand -->
    <div class="flex gap-2 items-center border-r pl-3 pr-4  my-2">
      <div class="p-1 rounded-full bg-[#5b92e3] text-white">
 <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24">
  <path fill="currentColor" stroke="white" stroke-width="2" d="m9.55 15.15l8.475-8.475q.3-.3.7-.3t.7.3t.3.713t-.3.712l-9.175 9.2q-.3.3-.7.3t-.7-.3L4.55 13q-.3-.3-.288-.712t.313-.713t.713-.3t.712.3z" />
 </svg>
  </div>
        <span class="text-lg font-semibold text-gray-800">One way</span>
      </div>
      <div class="flex gap-4 items-center">
      <!-- Dropdown 1: Class -->
  <select class="select">
      <option value="">Class</option>
      <option value="option1">Option 1</option>
      <option value="option2">Option 2</option>
      <option value="option3">Option 3</option>
  </select>
      <!-- Dropdown 2: Price -->
  <select class="select">
      <option value="">Price</option>
      <option value="option1">Option 1</option>
      <option value="option2">Option 2</option>
      <option value="option3">Option 3</option>
  </select>
      <!-- Dropdown 3: Stops -->
  <select class="select">
      <option value="">Stops</option>
      <option value="option1">Option 1</option>
      <option value="option2">Option 2</option>
      <option value="option3">Option 3</option>
  </select>
  </div>
  </div>
</div>

<script>
    function toggleDropdown(id) {
      const allDropdowns = document.querySelectorAll(".dropdown-panel");
      const current = document.getElementById(id);
    
      allDropdowns.forEach((dd) => {
        if (dd.id !== id) {
          dd.classList.add("hidden");
        }
      });
    
      current.classList.toggle("hidden");
    }
</script></code></pre>
  </div>
</div>
         
<div class="border-l-4 border-blue-500 pl-4">
<div class="max-w-5xl px-2 py-2 bg-white shadow-md rounded-2xl h-38 sm:h-auto my-6 mx-6">
<div class="flex flex-col sm:flex-row sm:gap-3 ">
<!-- Logo / Brand -->
<div class="flex gap-4 items-center h-[50px] sm:border-r pl-3 pr-8 sm:my-2">
  <div class="w-28 h-28">
    <img src="https://pics.avs.io/200/200/SV@2x.png" alt="" />
  </div>
  <div class="flex gap-2">
    <span class="text-2xl font-semibold text-black">LHR</span>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="20"
        viewBox="0 0 24 24"
        class="mt-1.5"
        >
        <path
          fill="currentColor"
          d="M17.073 12.5H5.5q-.213 0-.357-.143T5 12t.143-.357t.357-.143h11.573l-3.735-3.734q-.146-.147-.152-.345t.152-.363q.166-.166.357-.168t.357.162l4.383 4.383q.13.13.183.267t.053.298t-.053.298t-.183.268l-4.383 4.382q-.146.146-.347.153t-.367-.159q-.16-.165-.162-.354t.162-.354z"
          />
    </svg>
    <span class="text-2xl font-semibold text-black">SFO</span>
  </div>
</div>
<div class="flex">
  <div class="flex items-center space-x-4 mr-2 pl-5 pr-7 border-r my-2">
    <div
        class="p-1 items-center border border-gray-400 rounded-full text-gray-400"
        >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="26"
          height="26"
          viewBox="0 0 24 24"
          >
          <path
              fill="currentColor"
              d="M8.5 6q-.825 0-1.412-.587T6.5 4t.588-1.412T8.5 2t1.413.588T10.5 4t-.587 1.413T8.5 6M13 20H7.55q-.825 0-1.512-.587T5.175 18l-1.95-9.8q-.1-.475.2-.837t.8-.363q.35 0 .625.225t.35.575L7.25 18H13q.425 0 .713.288T14 19t-.288.713T13 20m6 1.125L16.6 17H9.65q-.725 0-1.263-.437T7.7 15.4l-1.1-5.35q-.275-1.2.563-2.125T9.2 7q.875 0 1.588.525T11.7 8.95L12.8 14h3.25q.525 0 .975.275t.725.725l3 5.125q.2.35.088.763t-.463.612t-.763.088t-.612-.463"
              />
        </svg>
    </div>
    <div
        class="p-1 items-center border border-gray-400 rounded-full text-gray-400"
        >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="24"
          height="24"
          viewBox="0 0 24 24"
          >
          <path
              fill="currentColor"
              d="M11 6h2V4h-2zm1 6q-1.9 0-3.625-.788T5 9.45V8q0-.825.588-1.412T7 6h2V3q0-.425.288-.712T10 2h4q.425 0 .713.288T15 3v3h2q.825 0 1.413.588T19 8v1.45q-1.65.975-3.375 1.763T12 12m-5 9q-.825 0-1.412-.587T5 19v-7.3q1.4.85 2.888 1.45t3.112.8V14q0 .425.288.713T12 15t.713-.288T13 14v-.05q1.625-.2 3.113-.8T19 11.7V19q0 .825-.587 1.413T17 21q0 .425-.288.713T16 22q-.4 0-.562-.363T15 21H9q0 .425-.288.713T8 22q-.4 0-.562-.363T7 21"
              />
        </svg>
    </div>
  </div>
  <div
    class="flex gap-3 w-36 bg-[#e5f8f4] px-2 h-10 my-3 rounded-md text-[#388275] font-medium items-center"
    >
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        >
        <path
          fill="currentColor"
          d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2m2 15l-3-5.2V7h1.5v4.4l2.8 4.9z"
          />
    </svg>
    Hold space
  </div>
   </div>
   </div>
</div>
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
  <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-5xl px-2 py-2 bg-white shadow-md rounded-2xl h-38 sm:h-auto my-6">
<div class="flex flex-col sm:flex-row sm:gap-3 ">
  <!-- Logo / Brand -->
  <div class="flex gap-4 items-center h-[50px] sm:border-r pl-3 pr-8 sm:my-2">
    <div class="w-28 h-28">
      <img src="https://pics.avs.io/200/200/SV@2x.png" alt="" />
    </div>
    <div class="flex gap-2">
      <span class="text-2xl font-semibold text-black">LHR</span>
      <svg
          xmlns="http://www.w3.org/2000/svg"
          width="24"
          height="20"
          viewBox="0 0 24 24"
          class="mt-1.5"
          >
        <path
          fill="currentColor"
          d="M17.073 12.5H5.5q-.213 0-.357-.143T5 12t.143-.357t.357-.143h11.573l-3.735-3.734q-.146-.147-.152-.345t.152-.363q.166-.166.357-.168t.357.162l4.383 4.383q.13.13.183.267t.053.298t-.053.298t-.183.268l-4.383 4.382q-.146.146-.347.153t-.367-.159q-.16-.165-.162-.354t.162-.354z"
          />
      </svg>
      <span class="text-2xl font-semibold text-black">SFO</span>
    </div>
  </div>
  <div class="flex">
  <div class="flex items-center space-x-4 mr-2 pl-5 pr-7 border-r my-2">
    <div
          class="p-1 items-center border border-gray-400 rounded-full text-gray-400"
          >
      <svg
          xmlns="http://www.w3.org/2000/svg"
          width="26"
          height="26"
          viewBox="0 0 24 24"
          >
        <path
          fill="currentColor"
          d="M8.5 6q-.825 0-1.412-.587T6.5 4t.588-1.412T8.5 2t1.413.588T10.5 4t-.587 1.413T8.5 6M13 20H7.55q-.825 0-1.512-.587T5.175 18l-1.95-9.8q-.1-.475.2-.837t.8-.363q.35 0 .625.225t.35.575L7.25 18H13q.425 0 .713.288T14 19t-.288.713T13 20m6 1.125L16.6 17H9.65q-.725 0-1.263-.437T7.7 15.4l-1.1-5.35q-.275-1.2.563-2.125T9.2 7q.875 0 1.588.525T11.7 8.95L12.8 14h3.25q.525 0 .975.275t.725.725l3 5.125q.2.35.088.763t-.463.612t-.763.088t-.612-.463"
          />
      </svg>
    </div>
    <div
          class="p-1 items-center border border-gray-400 rounded-full text-gray-400"
          >
      <svg
          xmlns="http://www.w3.org/2000/svg"
          width="24"
          height="24"
          viewBox="0 0 24 24"
          >
        <path
          fill="currentColor"
          d="M11 6h2V4h-2zm1 6q-1.9 0-3.625-.788T5 9.45V8q0-.825.588-1.412T7 6h2V3q0-.425.288-.712T10 2h4q.425 0 .713.288T15 3v3h2q.825 0 1.413.588T19 8v1.45q-1.65.975-3.375 1.763T12 12m-5 9q-.825 0-1.412-.587T5 19v-7.3q1.4.85 2.888 1.45t3.112.8V14q0 .425.288.713T12 15t.713-.288T13 14v-.05q1.625-.2 3.113-.8T19 11.7V19q0 .825-.587 1.413T17 21q0 .425-.288.713T16 22q-.4 0-.562-.363T15 21H9q0 .425-.288.713T8 22q-.4 0-.562-.363T7 21"
          />
      </svg>
    </div>
  </div>

  <div class="flex gap-3 w-36 bg-[#e5f8f4] px-2 h-10 my-3 rounded-md text-[#388275] font-medium items-center">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
      <path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2m2 15l-3-5.2V7h1.5v4.4l2.8 4.9z"/>
    </svg>
    Hold space
  </div>
  </div>
</div>
</div></code></pre>
  </div>
  </div>

  <div class="border-l-4 border-blue-500 pl-4">
  <div class="max-w-[200px] px-4 pb-2 bg-white shadow-md rounded-2xl h-38 sm:h-20 my-6">
    <div class="flex gap-2">
      <div class="w-16">
        <img
          src="https://www.iata.org/contentassets/3e83770142a040d688e269bb2f709b7b/iata-logo-header.svg?height=127&rmode=crop&v=20240116100112"
          alt=""
        />
      </div>
      <div class="border-l-2 my-6 mx-4"></div>
      <div
        class="p-1 h-6 w-6 rounded-full bg-[#5b92e3] text-white flex items-center justify-center my-7 ml-2"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="17"
          height="17"
          viewBox="0 0 24 24"
        >
          <path
            fill="currentColor"
            stroke="white"
            stroke-width="2"
            d="m9.55 15.15l8.475-8.475q.3-.3.7-.3t.7.3t.3.713t-.3.712l-9.175 9.2q-.3.3-.7.3t-.7-.3L4.55 13q-.3-.3-.288-.712t.313-.713t.713-.3t.712.3z"
          />
        </svg>
      </div>
    </div>
  </div>
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
  <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-[200px] px-4 pb-2 bg-white shadow-md rounded-2xl h-38 sm:h-20 my-6">
  <div class="flex gap-2">
    <div class="w-16">
      <img
        src="https://www.iata.org/contentassets/3e83770142a040d688e269bb2f709b7b/iata-logo-header.svg?height=127&rmode=crop&v=20240116100112"
        alt=""
      />
    </div>
    <div class="border-l-2 my-6 mx-4"></div>
    <div
      class="p-1 h-6 w-6 rounded-full bg-[#5b92e3] text-white flex items-center justify-center my-7 ml-2"
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        width="17"
        height="17"
        viewBox="0 0 24 24"
      >
        <path
          fill="currentColor"
          stroke="white"
          stroke-width="2"
          d="m9.55 15.15l8.475-8.475q.3-.3.7-.3t.7.3t.3.713t-.3.712l-9.175 9.2q-.3.3-.7.3t-.7-.3L4.55 13q-.3-.3-.288-.712t.313-.713t.713-.3t.712.3z"
        />
      </svg>
    </div>
  </div>
</div></code></pre>
 </div>
</div>

 <div class="border-l-4 border-blue-500 pl-4">
  <div class="max-w-md px-2 py-2 bg-white shadow-md rounded-2xl h-40 sm:h-auto my-6">
   <div class="flex flex-col sm:flex-row sm:gap-3">
          <!-- Logo / Brand -->
   <div class="flex flex-col sm:flex-row sm:gap-4 items-center h-[100px] sm:h-[50px] sm:border-r pl-3 pr-8 sm:my-2">
    <div class="w-20">
     <img src="https://pics.avs.io/300/300/SV@2x.png" alt="" class="" />
    </div>
    <div class="flex flex-col gap-2">
      <h1 class="text-md font-medium text-black/80">Customer Support Ticket: 371</h1>
      <div class="flex justify-between">
        <span class="py-1 px-2 inline-block text-[#936f27] bg-[#fff3dc] text-[10px] font-medium rounded-sm">Ref: LX564</span>
        <span class="bg-[#E9EBEF] h-2 px-9 mt-3 rounded-full"></span>
      </div>
    </div>
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
 <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-md px-2 py-2 bg-white shadow-md rounded-2xl h-40 sm:h-auto my-6">
<div class="flex flex-col sm:flex-row sm:gap-3">
  <!-- Logo / Brand -->
  <div class="flex flex-col sm:flex-row sm:gap-4 items-center h-[100px] sm:h-[50px] sm:border-r pl-3 pr-8 sm:my-2">
    <div class="w-20 ">
      <img src="https://pics.avs.io/300/300/SV@2x.png" alt="" class="" />
    </div>
    <div class="flex flex-col gap-2">
      <h1 class="text-md font-medium text-black/80">Customer Support Ticket: 371</h1>
      <div class="flex justify-between">
        <span class="py-1 px-2 inline-block text-[#936f27] bg-[#fff3dc] text-[10px] font-medium rounded-sm">Ref: LX564</span>
        <span class="bg-[#E9EBEF] h-2 px-9 mt-3 rounded-full"></span>
      </div>
      </div>
</div>
</div>
</div></code></pre>
 </div>
 </div>
      
  <div class="border-l-4 border-blue-500 pl-4">
   <div class="max-w-2xl px-4 py-2 bg-white shadow-md rounded-2xl h-32 sm:h-auto my-6">
  <div class="flex flex-col sm:flex-row gap-2 sm:justify-between ">
  <!-- Exclusive Deal Badge -->
  <div class="flex gap-2 w-62 h-10 bg-[#f2ebff] text-[#7358ab] px-4 py-2 rounded-md text-md font-semibold">
    <span class=" "> 
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
      <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
    </svg>
  </span>
    Exclusive Duffel Rate
  </div>
  <span class="border-l my-2"></span>
  <div class="flex gap-2 py-2">
    <span class="py-1 px-2 rounded-md text-sm font-normal bg-gray-100 text-gray-800">1x</span>
    <span class="text-lg font-medium">Suite</span>
  </div>
    <span class="border-l my-2"></span>
  <!-- Bottom Section - Price -->
  <div class="flex gap-1 mt-3">
    <p class="text-[10px] text-gray-500 mt-2">4 nights from</p>
    <p class="text-lg font-semibold text-gray-800">US$ 1,524.12</p>
  </div>
  </div>
  </div>
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
  <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-2xl px-4 py-4 bg-white shadow-md rounded-2xl h-38 sm:h-auto" >
<div class="flex flex-col sm:flex-row gap-2 sm:justify-between ">
  <!-- Exclusive Deal Badge -->
  <div class="flex gap-2 w-62 bg-[#f2ebff] text-[#7358ab] px-4 py-2 rounded-md text-md font-semibold">
    <span class=" "> 
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
      <path fill="currentColor" d="m7.325 18.923l1.24-5.313l-4.123-3.572l5.431-.47L12 4.557l2.127 5.01l5.43.47l-4.123 3.572l1.241 5.313L12 16.102z"/>
    </svg>
  </span>
    Exclusive Duffel Rate
  </div>
  <span class="border-l my-2"></span>
  <div class="flex gap-2 py-2">
    <span class="py-1 px-2 rounded-md text-sm font-normal bg-gray-100 text-gray-800">1x</span>
    <span class="text-lg font-medium">Suite</span>
  </div>
    <span class="border-l my-2"></span>
  <!-- Bottom Section - Price -->
  <div class="flex gap-1 mt-3">
    <p class="text-[10px] text-gray-500 mt-2">4 nights from</p>
    <p class="text-lg font-semibold text-gray-800">US$ 1,524.12</p>
  </div>
</div>
</div></code></pre>
   </div>
 </div>

 <div class="border-l-4 border-blue-500 pl-4">
<div class="max-w-[350px] px-4 py-2 bg-white shadow-md rounded-lg h-38 sm:h-auto my-6" >
<div class="flex flex-col sm:flex-row gap-2 ">
    <!-- Room Type -->
  <div class="flex gap-3 w-62 px-3 py-2" >
      <span class="text-lg font-semibold text-black/80 flex items-center">King Room</span>
      <span class="text-gray-600 flex items-center">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
          <path fill="currentColor" d="M2 19v-6q0-.675.275-1.225T3 10.8V8q0-1.25.875-2.125T6 5h4q.575 0 1.075.213T12 5.8q.425-.375.925-.587T14 5h4q1.25 0 2.125.875T21 8v2.8q.45.425.725.975T22 13v6h-2v-2H4v2zm11-9h6V8q0-.425-.288-.712T18 7h-4q-.425 0-.712.288T13 8zm-8 0h6V8q0-.425-.288-.712T10 7H6q-.425 0-.712.288T5 8z"></svg>
        </span>
      <span class="text-xs font-medium text-gray-500 flex items-center ">1 king size bed</span>
    </div>
    <!-- Image Section -->
  <div class="sm:w-[3.5rem] w-[17rem]">
    <img
      src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=400&h=300&fit=crop"
      alt="Hotel Room"
      class="w-full h-full object-cover rounded-lg"
    />
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
  <div  class="hidden transition-all duration-300" id="codeBoxTen">
  <pre class="line-numbers language-markup">
    <code class="language-html">
  <div class="max-w-sm px-4 py-2 bg-white shadow-md rounded-2xl h-38 sm:h-auto">
  <div class="flex flex-col sm:flex-row gap-2 ">
    <div class="flex gap-3 w-62 px-3 py-2">
      <span class="text-lg font-semibold text-black/80 flex items-center">King Room</span>
      <span class="text-gray-600 flex items-center">
        ... icon svg ...
      </span>
      <span class="text-xs font-medium text-gray-500 flex items-center">1 king size bed</span>
    </div>
    <div class="sm:w-[3.5rem] w-[17rem]">
      <img src="https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=400&h=300&fit=crop"
           alt="Hotel Room"
           class="w-full h-full object-cover rounded-lg"/>
    </div>
  </div>
  </div></code></pre>
  </div>
  </div>

<div class="border-l-4 border-blue-500 pl-4">
 <div class="max-w-sm w-full my-6">
    <!-- card -->
    <div class="bg-white rounded-lg shadow-md px-4 py-2">
      <div class="flex items-center justify-between gap-4">
        <!-- left label -->
        <div class="text-md text-gray-500 font-medium">
          Booking reference
        </div>

        <!-- right pill -->
        <div class="flex items-center gap-3 border border-gray-300 rounded-xl bg-gray-200 px-3 py-1">
          <div
            id="refPill"
            class=""
           
            role="status"
            aria-live="polite"
          >
            <span class="text-lg font-bold text-black/80 tracking-wide" id="refText">AFE33SE2</span>
          </div>

          <!-- copy button -->
          <button
            id="copyBtn"
            class=""
            title="Copy reference"
          >
            <!-- clipboard icon -->
           <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M9.116 17q-.691 0-1.153-.462T7.5 15.385V4.615q0-.69.463-1.153T9.116 3h7.769q.69 0 1.153.462t.462 1.153v10.77q0 .69-.462 1.152T16.884 17zm0-1h7.769q.23 0 .423-.192t.192-.423V4.615q0-.23-.192-.423T16.884 4H9.116q-.231 0-.424.192t-.192.423v10.77q0 .23.192.423t.423.192m-3 4q-.69 0-1.153-.462T4.5 18.385V6.615h1v11.77q0 .23.192.423t.423.192h8.77v1zM8.5 16V4z"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- tiny success toast -->
    <div id="toast" class="fixed bottom-6 left-[50%] bg-gray-900 text-white text-sm px-4 py-2 rounded-md shadow-lg opacity-0 pointer-events-none transition-opacity duration-300">
      Copied!
    </div>
  </div>

  <script>
    // Copy to clipboard behavior
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
   onclick="toggleCode('codeBoxEleven', 'toggleBtnEleven')" class="btn" id="toggleBtnEleven">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxEleven">
<pre class="line-numbers language-markup"><code class="language-html"> <div class="max-w-sm w-full my-6">
    <!-- card -->
    <div class="bg-white rounded-lg shadow-md p-4">
      <div class="flex items-center justify-between gap-4">
        <!-- left label -->
        <div class="text-md text-gray-500 font-medium">
          Booking reference
        </div>

        <!-- right pill -->
        <div class="flex items-center gap-3 border border-gray-300 rounded-xl bg-gray-200 px-3 py-1">
          <div
            id="refPill"
            class=""
           
            role="status"
            aria-live="polite"
          >
            <span class="text-lg font-bold text-black/80 tracking-wide" id="refText">AFE33SE2</span>
          </div>

          <!-- copy button -->
          <button
            id="copyBtn"
            class=""
            title="Copy reference"
          >
            <!-- clipboard icon -->
           <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M9.116 17q-.691 0-1.153-.462T7.5 15.385V4.615q0-.69.463-1.153T9.116 3h7.769q.69 0 1.153.462t.462 1.153v10.77q0 .69-.462 1.152T16.884 17zm0-1h7.769q.23 0 .423-.192t.192-.423V4.615q0-.23-.192-.423T16.884 4H9.116q-.231 0-.424.192t-.192.423v10.77q0 .23.192.423t.423.192m-3 4q-.69 0-1.153-.462T4.5 18.385V6.615h1v11.77q0 .23.192.423t.423.192h8.77v1zM8.5 16V4z"/></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- tiny success toast -->
    <div id="toast" class="fixed bottom-6 right-6 bg-gray-900 text-white text-sm px-4 py-2 rounded-md shadow-lg opacity-0 pointer-events-none transition-opacity duration-300">
      Copied!
    </div>
  </div>

  <script>
    // Copy to clipboard behavior
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
    <div class="w-full max-w-sm shadow-lg my-6">
        <!-- Message Bubble -->
        <div class="bg-white rounded-lg shadow-sm p-4 flex items-start gap-3">
            <!-- Checkmark Icon -->
            <div class="flex-shrink-0 mt-1.5">
                <svg class="w-5 h-5 text-[#59978c] mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            
            <!-- Message Text -->
            <div class="flex-1 bg-[#e5f8f4] px-2 py-1 border border-[#b0e7dd] rounded-md">
                <span class="text-gray-800 text-sm">Can we please</span> <span class="text-black/90 text-sm font-semibold">check-in at 10pm?</span>
            </div>
        </div>
    </div>
           <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwelve', 'toggleBtnTwelve')" class="btn" id="toggleBtnTwelve">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTwelve">
     <pre class="line-numbers language-markup"><code class="language-html"><div class="w-full max-w-md shadow-lg">
        <!-- Message Bubble -->
        <div class="bg-white rounded-lg shadow-sm p-4 flex items-start gap-3">
            <!-- Checkmark Icon -->
            <div class="flex-shrink-0">
                <svg class="w-5 h-5 text-green-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            
            <!-- Message Text -->
            <div class="flex-1">
                <p class="text-gray-700 text-sm">Can we please check-in at 10pm?</p>
            </div>
        </div>
    </div></code></pre>
  </div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
 <div class="max-w-2xl bg-white border border-gray-200 rounded-lg mb-6">
        <!-- Header -->
        <div class="grid grid-cols-4 gap-4 px-6 py-6 mx-6 border-b text-xs font-normal text-gray-400 uppercase">
            <div>Airline</div>
            <div>Reference</div>
            <div>Status</div>
            <div class="text-right">Amount</div>
        </div>

        <!-- Booking Items -->
        <div class="mx-4">
            <!-- Item 1 - AA -->
            <div class="grid grid-cols-4 gap-4 px-6 py-4 mx-3 items-center">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <img src="https://static.dezeen.com/uploads/2013/01/dezeen_American-Airlines-logo-and-livery_4a-300x300.jpg" alt="">
                    </div>
                    <span class="font-medium text-gray-900">AA</span>
                </div>
                <div>
                    <div class="text-[#373737] font-normal text-[15px]">XQIEH4</div>
                </div>
                <div>
                    <span class="inline-block px-3 py-1 text-xs font-medium text-green-700 bg-green-100 rounded-full">
                        Confirmed
                    </span>
                </div>
                <div class="text-right font-normal text-gray-900 text-[15px]">
                    $3261.09
                </div>
               </div>
               <div class="flex justify-between pl-20 pr-6 pt-2 pb-4 mx-3 items-center border-b border-gray-200">
                  <div class="flex gap-6 items-center">
                   <a href="#" class="text-sm text-gray-600">See updates</a>
                   <span class="bg-gray-200 rounded-full py-1 px-20"></span>
                   </div>
                   <span class="bg-gray-200 rounded-full py-1 px-6"></span>
                </div>

            <!-- Item 2 - BA -->
            <div class="grid grid-cols-4 gap-4 px-6 py-4 mx-3 border-b border-gray-200 items-center">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 flex items-center justify-center">
                        
                    </div>
                    <span class="font-medium text-gray-900">BA</span>
                </div>
                <div>
                    <div class="text-[#373737] font-normal text-[15px]">DF83NF</div>
                </div>
                <div>
                    <span class="inline-block px-3 py-1 text-xs font-medium text-green-700 bg-green-100 rounded-full">
                        Confirmed
                    </span>
                </div>
                <div class="text-right font-normal text-gray-900 text-[15px]">
                    $231.48
                </div>
            </div>

            <!-- Item 3 - EK -->
            <div class="grid grid-cols-4 gap-4 border-b mx-3 border-gray-200 px-6 py-4 items-center">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 flex items-center justify-center">
                       <img src="https://pics.avs.io/200/200/EK@2x.png" alt="">
                    </div>
                    <span class="font-medium text-gray-900">EK</span>
                </div>
                <div>
                    <div class="text-[#373737] font-normal text-[15px]">F94KPH</div>
                </div>
                <div>
                    <span class="inline-block px-3 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded-full">
                        Past
                    </span>
                </div>
                <div class="text-right font-normal text-gray-900 text-[15px]">
                    $3261.12
                </div>
            </div>

            <!-- Item 4 - SQ -->
            <div class="grid grid-cols-4 gap-4 px-6 py-4 mx-3 border-b border-gray-200 items-center">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 flex items-center justify-center">
                        
                    </div>
                    <span class="font-medium text-gray-900">SQ</span>
                </div>
                <div>
                    <div class="text-[#373737] font-normal text-[15px]">D7JLPP</div>
                </div>
                <div>
                    <span class="inline-block px-3 py-1 text-xs font-medium text-red-700 bg-red-100 rounded-full">
                        Cancelled
                    </span>
                </div>
                <div class="text-right font-normal text-gray-900 text-[15px]">
                    $361.67
                </div>
            </div>
        </div>
    </div>

    <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <button onclick="toggleCode('codeBoxThirteen', 'toggleBtnThirteen')" class="btn" id="toggleBtnThirteen">
    Show Code
  </button>
</div>

<div id="codeBoxThirteen" class="hidden transition-all duration-300">
<pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-2xl bg-white border border-gray-200 rounded-lg mb-6">
<!-- Header -->
<div class="grid grid-cols-4 gap-4 px-6 py-6 mx-6 border-b text-xs font-normal text-gray-400 uppercase">
    <div>Airline</div>
    <div>Reference</div>
    <div>Status</div>
    <div class="text-right">Amount</div>
</div>

<!-- Booking Items -->
<div class="mx-4">
    <!-- Item 1 - AA -->
    <div class="grid grid-cols-4 gap-4 px-6 py-4 mx-3 items-center">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img src="https://static.dezeen.com/uploads/2013/01/dezeen_American-Airlines-logo-and-livery_4a-300x300.jpg" alt="">
            </div>
            <span class="font-medium text-gray-900">AA</span>
        </div>
        <div>
            <div class="text-[#373737] font-normal text-[15px]">XQIEH4</div>
        </div>
        <div>
            <span class="inline-block px-3 py-1 text-xs font-medium text-green-700 bg-green-100 rounded-full">
                Confirmed
            </span>
        </div>
        <div class="text-right font-normal text-gray-900 text-[15px]">
            $3261.09
        </div>
        </div>
        <div class="flex justify-between pl-20 pr-6 pt-2 pb-4 mx-3 items-center border-b border-gray-200">
          <div class="flex gap-6 items-center">
            <a href="#" class="text-sm text-gray-600">See updates</a>
            <span class="bg-gray-200 rounded-full py-1 px-20"></span>
            </div>
            <span class="bg-gray-200 rounded-full py-1 px-6"></span>
        </div>

    <!-- Item 2 - BA -->
    <div class="grid grid-cols-4 gap-4 px-6 py-4 mx-3 border-b border-gray-200 items-center">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                
            </div>
            <span class="font-medium text-gray-900">BA</span>
        </div>
        <div>
            <div class="text-[#373737] font-normal text-[15px]">DF83NF</div>
        </div>
        <div>
            <span class="inline-block px-3 py-1 text-xs font-medium text-green-700 bg-green-100 rounded-full">
                Confirmed
            </span>
        </div>
        <div class="text-right font-normal text-gray-900 text-[15px]">
            $231.48
        </div>
    </div>

    <!-- Item 3 - EK -->
    <div class="grid grid-cols-4 gap-4 border-b mx-3 border-gray-200 px-6 py-4 items-center">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img src="https://pics.avs.io/200/200/EK@2x.png" alt="">
            </div>
            <span class="font-medium text-gray-900">EK</span>
        </div>
        <div>
            <div class="text-[#373737] font-normal text-[15px]">F94KPH</div>
        </div>
        <div>
            <span class="inline-block px-3 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded-full">
                Past
            </span>
        </div>
        <div class="text-right font-normal text-gray-900 text-[15px]">
            $3261.12
        </div>
    </div>

    <!-- Item 4 - SQ -->
    <div class="grid grid-cols-4 gap-4 px-6 py-4 mx-3 border-b border-gray-200 items-center">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                
            </div>
            <span class="font-medium text-gray-900">SQ</span>
        </div>
        <div>
            <div class="text-[#373737] font-normal text-[15px]">D7JLPP</div>
        </div>
        <div>
            <span class="inline-block px-3 py-1 text-xs font-medium text-red-700 bg-red-100 rounded-full">
                Cancelled
            </span>
        </div>
        <div class="text-right font-normal text-gray-900 text-[15px]">
            $361.67
        </div>
    </div>
</div>
</div></code></pre>
</div>
</div>

      <div class="border-l-4 border-blue-500 pl-4">
    <div
      class="max-w-lg bg-white rounded-xl shadow-sm mb-4 border border-gray-200 flight-card"
      id="flight1"
    >
      <!-- Header -->
      <div
        class="flex justify-between items-center py-4 border-b border-gray-300 mx-5"
      >
        <div class="flex items-center space-x-6">
          <img
            class="w-8 h-8"
            src="https://assets.duffel.com/img/airlines/for-light-background/full-color-logo/BA.svg?v=1"
            alt=""
          />
          <div class="flex flex-col gap-1">
            <span class="text-sm text-black font-medium">13:45 – 19:15</span>
            <span class="bg-gray-200 rounded-full py-1 w-10"></span>
          </div>
          <div class="flex flex-col gap-1">
            <span class="px-2 py-0.5 text-sm font-medium rounded-full"
              >Direct</span
            >
            <span class="bg-gray-200 rounded-full py-1 w-7 ml-2"></span>
          </div>
        </div>
        <button
          onclick="toggleCard('flight1')"
          class="text-gray-500 hover:text-gray-700"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="text-gray-300"
            width="28"
            height="24"
            viewBox="0 0 24 24"
          >
            <path
              fill="currentColor"
              d="M12 14.975q-.2 0-.375-.062T11.3 14.7l-4.6-4.6q-.275-.275-.275-.7t.275-.7t.7-.275t.7.275l3.9 3.9l3.9-3.9q.275-.275.7-.275t.7.275t.275.7t-.275.7l-4.6 4.6q-.15.15-.325.213t-.375.062"
            />
          </svg>
        </button>
      </div>

      <div class="flex justify-between items-center my-3 mx-6">
        <div class="flex items-center space-x-2">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="text-[#4c73c3]"
            width="24"
            height="24"
            viewBox="0 0 24 24"
          >
            <path
              fill="currentColor"
              d="m8.85 15.65l8.9-2.35q.375-.1.563-.462t.087-.738t-.437-.562t-.713-.088l-2.45.65l-4-3.75l-1.4.35l2.4 4.2l-2.4.6l-1.25-.95l-.95.25zM20 20H4q-.825 0-1.412-.587T2 18v-4q.825 0 1.413-.587T4 12t-.587-1.412T2 10V6q0-.825.588-1.412T4 4h16q.825 0 1.413.588T22 6v12q0 .825-.587 1.413T20 20"
            />
          </svg>
          <span class="text-[15px] font-normal text-[#4c73c3]"
            >Flexible ticket</span
          >
        </div>
        <span class="text-xl font-normal text-black">$369.66</span>
      </div>

      <!-- Collapsible Content -->
      <div id="flight1-content" class="hidden px-4 pt-4 pb-1">
        <div class="flex gap-3">
          <div class="flex flex-col items-center justify-center mb-3">
            <span class="bg-gray-200 rounded-full h-3 w-3 ml-2"></span>
            <span class="bg-gray-200 rounded-full h-3 w-3 ml-2"></span>
          </div>
          <div class="">
            <!-- Departure -->
            <div class="flex items-start space-x-3 mb-3 mx-4">
              <div class="text-right">
                <div class="font-medium text-[15px] text-center">13:50</div>
                <div class="text-xs text-gray-700 text-center">Tue, 31 May</div>
              </div>
              <div>
                <div class="font-medium text-[15px] text-center">
                  London (LHR)
                </div>
                <div class="text-xs text-gray-700 text-center">
                  Heathrow Airport
                </div>
              </div>
            </div>

            <!-- Arrival -->
            <div class="flex items-start space-x-3 mb-3 mx-4">
              <div class="text-right">
                <div class="font-medium text-[15px] text-center">15:55</div>
                <div class="text-xs text-gray-700 text-center">Tue, 31 May</div>
              </div>
              <div>
                <div class="font-medium text-[15px] text-center">
                  Barcelona (BCN)
                </div>
                <div class="text-xs text-gray-700 text-center">
                  Barcelona International Airport
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="flex justify-between items-center">
          <span class="bg-gray-200 rounded-full h-2 w-44 ml-2"></span>

          <!-- CO2 -->
          <div class="flex gap-1 text-right items-center text-xs text-gray-500">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="text-right items-end justify-end"
              width="24"
              height="24"
              viewBox="0 0 24 24"
            >
              <path
                fill="currentColor"
                d="M6.5 19q-1.871 0-3.185-1.306Q2 16.39 2 14.517q0-1.719 1.175-3.051t2.921-1.431q.337-2.185 2.01-3.61T12 5q2.502 0 4.251 1.749T18 11v1h.616q1.436.046 2.41 1.055T22 15.5q0 1.471-1.014 2.486Q19.97 19 18.5 19zm0-1h12q1.05 0 1.775-.725T21 15.5t-.725-1.775T18.5 13H17v-2q0-2.075-1.463-3.538T12 6T8.463 7.463T7 11h-.5q-1.45 0-2.475 1.025T3 14.5t1.025 2.475T6.5 18m5.5-6"
              />
            </svg>
            <span class=""> 127kg CO₂ </span>
          </div>
        </div>
      </div>
    </div>

    <!-- JavaScript for Toggle -->
    <script>
      function toggleCard(id) {
        const content = document.getElementById(id + "-content");
        const icon = content.previousElementSibling.querySelector("svg");

        if (content.classList.contains("hidden")) {
          // Close other cards
          document
            .querySelectorAll('.flight-card > div[id$="-content"]')
            .forEach((el) => {
              if (el !== content) el.classList.add("hidden");
            });

          content.classList.remove("hidden");
          icon.style.transform = "rotate(180deg)";
        } else {
          content.classList.add("hidden");
          icon.style.transform = "rotate(0deg)";
        }
      }
    </script>
           <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxFourteen', 'toggleBtnFourteen')" class="btn" id="toggleBtnFourteen">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxFourteen">
<pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-lg mx-auto bg-white rounded-xl shadow-sm mb-4 border border-gray-200 flight-card" id="flight1">
<!-- Header -->
<div class="flex justify-between items-center py-4 border-b border-gray-300 mx-5">
  <div class="flex items-center space-x-6">
    <img
      class="w-8 h-8"
      src="https://assets.duffel.com/img/airlines/for-light-background/full-color-logo/BA.svg?v=1"
      alt=""
    />
    <div class="flex flex-col gap-1">
      <span class="text-sm text-black font-medium">13:45 – 19:15</span>
      <span class="bg-gray-200 rounded-full py-1 w-10"></span>
    </div>
    <div class="flex flex-col gap-1">
      <span class="px-2 py-0.5 text-sm font-medium rounded-full"
        >Direct</span
      >
      <span class="bg-gray-200 rounded-full py-1 w-7 ml-2"></span>
    </div>
  </div>
  <button
    onclick="toggleCard('flight1')"
    class="text-gray-500 hover:text-gray-700"
  >
    <svg
      xmlns="http://www.w3.org/2000/svg"
      class="text-gray-300"
      width="28"
      height="24"
      viewBox="0 0 24 24"
    >
      <path
        fill="currentColor"
        d="M12 14.975q-.2 0-.375-.062T11.3 14.7l-4.6-4.6q-.275-.275-.275-.7t.275-.7t.7-.275t.7.275l3.9 3.9l3.9-3.9q.275-.275.7-.275t.7.275t.275.7t-.275.7l-4.6 4.6q-.15.15-.325.213t-.375.062"
      />
    </svg>
  </button>
</div>

<div class="flex justify-between items-center my-3 mx-6">
  <div class="flex items-center space-x-2">
    <svg
      xmlns="http://www.w3.org/2000/svg"
      class="text-[#4c73c3]" width="24" height="24" viewBox="0 0 24 24">
      <path fill="currentColor" d="m8.85 15.65l8.9-2.35q.375-.1.563-.462t.087-.738t-.437-.562t-.713-.088l-2.45.65l-4-3.75l-1.4.35l2.4 4.2l-2.4.6l-1.25-.95l-.95.25zM20 20H4q-.825 0-1.412-.587T2 18v-4q.825 0 1.413-.587T4 12t-.587-1.412T2 10V6q0-.825.588-1.412T4 4h16q.825 0 1.413.588T22 6v12q0 .825-.587 1.413T20 20"/>
    </svg>
    <span class="text-[15px] font-normal text-[#4c73c3]"
      >Flexible ticket</span
    >
  </div>
  <span class="text-xl font-normal text-black">$369.66</span>
</div>

  <!-- Collapsible Content -->
  <div id="flight1-content" class="hidden px-4 pt-4 pb-1">
    <div class="flex gap-3">
      <div class="flex flex-col items-center justify-center mb-3">
        <span class="bg-gray-200 rounded-full h-3 w-3 ml-2"></span>
        <span class="bg-gray-200 rounded-full h-3 w-3 ml-2"></span>
      </div>
      <div class="">
        <!-- Departure -->
        <div class="flex items-start space-x-3 mb-3 mx-4">
          <div class="text-right">
            <div class="font-medium text-[15px] text-center">13:50</div>
            <div class="text-xs text-gray-700 text-center">Tue, 31 May</div>
          </div>
          <div>
            <div class="font-medium text-[15px] text-center">
              London (LHR)
            </div>
            <div class="text-xs text-gray-700 text-center">
              Heathrow Airport
            </div>
          </div>
        </div>

        <!-- Arrival -->
        <div class="flex items-start space-x-3 mb-3 mx-4">
          <div class="text-right">
            <div class="font-medium text-[15px] text-center">15:55</div>
            <div class="text-xs text-gray-700 text-center">Tue, 31 May</div>
          </div>
          <div>
            <div class="font-medium text-[15px] text-center">
              Barcelona (BCN)
            </div>
            <div class="text-xs text-gray-700 text-center">
              Barcelona International Airport
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="flex justify-between items-center">
      <span class="bg-gray-200 rounded-full h-2 w-44 ml-2"></span>

      <!-- CO2 -->
      <div class="flex gap-1 text-right items-center text-xs text-gray-500">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="text-right items-end justify-end"
          width="24"
          height="24"
          viewBox="0 0 24 24"
        >
          <path
            fill="currentColor"
            d="M6.5 19q-1.871 0-3.185-1.306Q2 16.39 2 14.517q0-1.719 1.175-3.051t2.921-1.431q.337-2.185 2.01-3.61T12 5q2.502 0 4.251 1.749T18 11v1h.616q1.436.046 2.41 1.055T22 15.5q0 1.471-1.014 2.486Q19.97 19 18.5 19zm0-1h12q1.05 0 1.775-.725T21 15.5t-.725-1.775T18.5 13H17v-2q0-2.075-1.463-3.538T12 6T8.463 7.463T7 11h-.5q-1.45 0-2.475 1.025T3 14.5t1.025 2.475T6.5 18m5.5-6"
          />
        </svg>
        <span class=""> 127kg CO₂ </span>
      </div>
    </div>
  </div>
</div>

<!-- JavaScript for Toggle -->
<script>
  function toggleCard(id) {
    const content = document.getElementById(id + "-content");
    const icon = content.previousElementSibling.querySelector("svg");

    if (content.classList.contains("hidden")) {
      // Close other cards
      document
        .querySelectorAll('.flight-card > div[id$="-content"]')
        .forEach((el) => {
          if (el !== content) el.classList.add("hidden");
        });

      content.classList.remove("hidden");
      icon.style.transform = "rotate(180deg)";
    } else {
      content.classList.add("hidden");
      icon.style.transform = "rotate(0deg)";
    }
  }
</script></code></pre>
  </div>
</div>

<div class="border-l-4 border-blue-500 pl-4">

<div class="w-full max-w-md space-y-4 mb-6">
<!-- Flight card -->
<div class="bg-white rounded-xl shadow p-4 sm:p-5">
  <div class="flex items-center bg-gray-50 py-2 px-5 space-x-12">
    <div class="flex items-center gap-3">
      <img
        class="w-8 h-8"
        src="https://assets.duffel.com/img/airlines/for-light-background/full-color-logo/BA.svg?v=1"
        alt=""
      />
      <div class="flex gap-2">
        <p class="font-normal text-sm">Tuesday 31st May,</p>
        <span class="text-sm text-black font-normal"
          >13:45 – 19:15</span
        >
      </div>
    </div>
    <div class="flex flex-col gap-1">
      <span class="px-2 py-0.5 text-sm font-normal rounded-full"
        >Direct</span
      >
    </div>
  </div>

  <!-- Checked bag row -->
  <div
    class="mt-5 flex items-center justify-between bg-gray-50 rounded-lg py-3 px-4"
  >
    <div class="flex items-center gap-3">
      <div class="flex items-center justify-center">
        <!-- bag icon -->
        <svg
          xmlns="http://www.w3.org/2000/svg"
          class="text-[#5aaa9c]"
          width="24"
          height="24"
          viewBox="0 0 24 24"
        >
          <path
            fill="currentColor"
            d="M11 6h2V4h-2zm1 6q-1.9 0-3.625-.788T5 9.45V8q0-.825.588-1.412T7 6h2V3q0-.425.288-.712T10 2h4q.425 0 .713.288T15 3v3h2q.825 0 1.413.588T19 8v1.45q-1.65.975-3.375 1.763T12 12m-5 9q-.825 0-1.412-.587T5 19v-7.3q1.4.85 2.888 1.45t3.112.8V14q0 .425.288.713T12 15t.713-.288T13 14v-.05q1.625-.2 3.113-.8T19 11.7V19q0 .825-.587 1.413T17 21q0 .425-.288.713T16 22q-.4 0-.562-.363T15 21H9q0 .425-.288.713T8 22q-.4 0-.562-.363T7 21"
          />
        </svg>
      </div>
      <div class="flex gap-10">
        <p class="text-sm font-normal">Checked Bag</p>
        <p class="text-sm text-black font-normal">$52.00</p>
      </div>
    </div>

    <!-- controls -->
    <div class="flex items-center gap-2">
      <button
        id="decBtn"
        class="h-6 w-6 grid place-items-center rounded-md text-[#5aaa9c] bg-[#ccefe9] hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
        aria-label="Decrease bags"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="18"
          height="24"
          viewBox="0 0 24 24"
        >
          <path
            fill="currentColor"
            d="M18 12.998H6a1 1 0 0 1 0-2h12a1 1 0 0 1 0 2"
          />
        </svg>
      </button>
      <span id="bagQty" class="w-6 text-sm text-center font-medium">1</span>
      <button
        id="incBtn"
        class="h-6 w-6 grid place-items-center rounded-md bg-emerald-600 text-white hover:bg-emerald-700"
        aria-label="Increase bags"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="18"
          height="18"
          viewBox="0 0 24 24"
        >
          <path
            fill="currentColor"
            d="M19 12.998h-6v6h-2v-6H5v-2h6v-6h2v6h6z"
          />
        </svg>
      </button>
    </div>
  </div>
</div>

<!-- Payment card -->
<div class="bg-white rounded-xl shadow py-4 px-6">
  <h3 class="text-md font-medium mb-2">Payment</h3>
  <div class="divide-y px-2">
    <div class="flex items-center justify-between py-2">
      <span class="text-gray-700 font-medium text-xs">Description</span>
      <span class="text-gray-700 font-medium text-xs">Price</span>
    </div>
    <div class="flex items-center justify-between py-2">
      <span class="text-black text-sm">Fare</span>
      <span id="fareAmt" class="text-black font-medium text-sm">$301.43</span>
    </div>
    <div class="flex items-center justify-between py-2">
      <span class="text-black text-sm">Fare tax</span>
      <span id="taxAmt" class="text-black font-medium text-sm">$68.23</span>
    </div>
    <div class="flex items-center justify-between py-2">
      <span class="text-black text-sm">Baggage</span>
      <span id="bagAmt" class="text-black font-medium text-sm">$52.00</span>
    </div>
    <div class="flex items-center justify-between pt-3">
      <span class="text-gray-800 text-lg font-normal">Total</span>
      <span id="totalAmt" class="text-lg font-normal">$421.66</span>
    </div>
  </div>
</div>
</div>
<script>
  // Constants
  const BAG_PRICE = 52.0;
  const FARE = 301.43;
  const TAX = 68.23;

  // Elements
  const decBtn = document.getElementById("decBtn");
  const incBtn = document.getElementById("incBtn");
  const qtySpan = document.getElementById("bagQty");
  const bagAmt = document.getElementById("bagAmt");
  const totalAmt = document.getElementById("totalAmt");
  const fareAmt = document.getElementById("fareAmt");
  const taxAmt = document.getElementById("taxAmt");

  // Initialize UI from constants
  fareAmt.textContent = usd(FARE);
  taxAmt.textContent = usd(TAX);

  function usd(n) {
    return `$${n.toFixed(2)}`;
  }

  function computeAndRender(qty) {
    // Safety: min 0, max 9 (you can change)
    qty = Math.max(0, Math.min(9, qty));
    qtySpan.textContent = qty;

    const baggageCost = qty * BAG_PRICE;
    bagAmt.textContent = usd(baggageCost);

    const total = FARE + TAX + baggageCost;
    totalAmt.textContent = usd(total);

    // Disable minus at 0
    decBtn.disabled = qty === 0;
  }

  // Default quantity: 1 bag
  let qty = 1;
  computeAndRender(qty);

  incBtn.addEventListener("click", () => {
    qty++;
    computeAndRender(qty);
  });

  decBtn.addEventListener("click", () => {
    qty--;
    computeAndRender(qty);
  });
</script>
<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxFifteen', 'toggleBtnFifteen')" class="btn" id="toggleBtnFifteen">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxFifteen">
<pre class="line-numbers language-markup"><code class="language-html"><div class="w-full max-w-md space-y-4">
  <!-- Flight card -->
  <div class="bg-white rounded-xl shadow p-4 sm:p-5">
    <div class="flex items-center bg-gray-50 py-2 px-5 space-x-12">
      <div class="flex items-center gap-3">
        <img
          class="w-8 h-8"
          src="https://assets.duffel.com/img/airlines/for-light-background/full-color-logo/BA.svg?v=1"
          alt=""
        />
        <div class="flex gap-2">
          <p class="font-normal text-sm">Tuesday 31st May,</p>
          <span class="text-sm text-black font-normal"
            >13:45 – 19:15</span
          >
        </div>
      </div>
      <div class="flex flex-col gap-1">
        <span class="px-2 py-0.5 text-sm font-normal rounded-full"
          >Direct</span
        >
      </div>
    </div>

    <!-- Checked bag row -->
    <div
      class="mt-5 flex items-center justify-between bg-gray-50 rounded-lg py-3 px-4"
    >
      <div class="flex items-center gap-3">
        <div class="flex items-center justify-center">
          <!-- bag icon -->
          <svg
            xmlns="http://www.w3.org/2000/svg"
            class="text-[#5aaa9c]"
            width="24"
            height="24"
            viewBox="0 0 24 24"
          >
            <path
              fill="currentColor"
              d="M11 6h2V4h-2zm1 6q-1.9 0-3.625-.788T5 9.45V8q0-.825.588-1.412T7 6h2V3q0-.425.288-.712T10 2h4q.425 0 .713.288T15 3v3h2q.825 0 1.413.588T19 8v1.45q-1.65.975-3.375 1.763T12 12m-5 9q-.825 0-1.412-.587T5 19v-7.3q1.4.85 2.888 1.45t3.112.8V14q0 .425.288.713T12 15t.713-.288T13 14v-.05q1.625-.2 3.113-.8T19 11.7V19q0 .825-.587 1.413T17 21q0 .425-.288.713T16 22q-.4 0-.562-.363T15 21H9q0 .425-.288.713T8 22q-.4 0-.562-.363T7 21"
            />
          </svg>
        </div>
        <div class="flex gap-10">
          <p class="text-sm font-normal">Checked Bag</p>
          <p class="text-sm text-black font-normal">$52.00</p>
        </div>
      </div>

      <!-- controls -->
      <div class="flex items-center gap-2">
        <button
          id="decBtn"
          class="h-6 w-6 grid place-items-center rounded-md text-[#5aaa9c] bg-[#ccefe9] hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"
          aria-label="Decrease bags"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="18"
            height="24"
            viewBox="0 0 24 24"
          >
            <path
              fill="currentColor"
              d="M18 12.998H6a1 1 0 0 1 0-2h12a1 1 0 0 1 0 2"
            />
          </svg>
        </button>
        <span id="bagQty" class="w-6 text-sm text-center font-medium">1</span>
        <button
          id="incBtn"
          class="h-6 w-6 grid place-items-center rounded-md bg-emerald-600 text-white hover:bg-emerald-700"
          aria-label="Increase bags"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="18"
            height="18"
            viewBox="0 0 24 24"
          >
            <path
              fill="currentColor"
              d="M19 12.998h-6v6h-2v-6H5v-2h6v-6h2v6h6z"
            />
          </svg>
        </button>
      </div>
    </div>
  </div>

  <!-- Payment card -->
  <div class="bg-white rounded-xl shadow py-4 px-6">
    <h3 class="text-md font-medium mb-2">Payment</h3>
    <div class="divide-y px-2">
      <div class="flex items-center justify-between py-2">
        <span class="text-gray-700 font-medium text-xs">Description</span>
        <span class="text-gray-700 font-medium text-xs">Price</span>
      </div>
      <div class="flex items-center justify-between py-2">
        <span class="text-black text-sm">Fare</span>
        <span id="fareAmt" class="text-black font-medium text-sm">$301.43</span>
      </div>
      <div class="flex items-center justify-between py-2">
        <span class="text-black text-sm">Fare tax</span>
        <span id="taxAmt" class="text-black font-medium text-sm">$68.23</span>
      </div>
      <div class="flex items-center justify-between py-2">
        <span class="text-black text-sm">Baggage</span>
        <span id="bagAmt" class="text-black font-medium text-sm">$52.00</span>
      </div>
      <div class="flex items-center justify-between pt-3">
        <span class="text-gray-800 text-lg font-normal">Total</span>
        <span id="totalAmt" class="text-lg font-normal">$421.66</span>
      </div>
    </div>
  </div>
</div>
    <script>
    // Constants
    const BAG_PRICE = 52.0;
    const FARE = 301.43;
    const TAX = 68.23;

    // Elements
    const decBtn = document.getElementById("decBtn");
    const incBtn = document.getElementById("incBtn");
    const qtySpan = document.getElementById("bagQty");
    const bagAmt = document.getElementById("bagAmt");
    const totalAmt = document.getElementById("totalAmt");
    const fareAmt = document.getElementById("fareAmt");
    const taxAmt = document.getElementById("taxAmt");

    // Initialize UI from constants
    fareAmt.textContent = usd(FARE);
    taxAmt.textContent = usd(TAX);

    function usd(n) {
      return `$${n.toFixed(2)}`;
    }

    function computeAndRender(qty) {
      // Safety: min 0, max 9 (you can change)
      qty = Math.max(0, Math.min(9, qty));
      qtySpan.textContent = qty;

      const baggageCost = qty * BAG_PRICE;
      bagAmt.textContent = usd(baggageCost);

      const total = FARE + TAX + baggageCost;
      totalAmt.textContent = usd(total);

      // Disable minus at 0
      decBtn.disabled = qty === 0;
    }

    // Default quantity: 1 bag
    let qty = 1;
    computeAndRender(qty);

    incBtn.addEventListener("click", () => {
      qty++;
      computeAndRender(qty);
    });

    decBtn.addEventListener("click", () => {
      qty--;
      computeAndRender(qty);
    });
  </script></code></pre>
</div>
  </div>

  <div class="border-l-4 border-blue-500 pl-4">
<div class="max-w-md">
<div class="bg-white mb-6 p-4 rounded-lg shadow">
<!-- Header -->
<h1 class="text-[17px] font-medium text-gray-900 mb-4">Update your flight</h1>

<!-- Alert Banner -->
<div id="alertBanner" class="bg-[#fff3dc] rounded-lg px-4 py-3 flex items-start gap-3">
<div class="flex-shrink-0">
    <svg class="w-5 h-5 text-yellow-600" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
    </svg>
</div>
<p class="text-sm font-medium text-gray-800">Do you want to update your flight?</p>
</div>

<!-- Success Message (Hidden by default) -->
<div id="successMessage" class="bg-teal-50 rounded-lg px-4 py-3 items-center justify-between hidden">
<div class="flex items-center gap-3">
    <div class="flex-shrink-0">
        <svg class="w-5 h-5 text-teal-600" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
        </svg>
    </div>
    <p class="text-sm font-medium text-gray-800 flex-1">Flight booking updated!</p>
</div>
<span class="text-sm font-semibold text-gray-700">SA548173</span>
</div>
</div>
<div class="bg-white rounded-lg p-4 shadow">
<!-- Flight Details Card -->
<div class="bg-white border border-gray-100 rounded-lg p-4 mb-6">

<!-- Date Changed Badge -->
<div class="flex items-center justify-between mb-4">
    <span class="inline-block px-2 py-0.5 text-[10.5px] font-semibold text-teal-700 bg-teal-50 rounded-2xl">
        Date Changed
    </span>
    <div class="h-2.5 w-20 mx-3 bg-gray-200 rounded"></div>
</div>

<!-- Flight Route -->
<div class="relative">
    <!-- Timeline Line -->
    <div class="absolute left-[7px] top-1.5 bottom-11 w-0.5 bg-gray-200"></div>
    
    <!-- Departure -->
    <div class="relative flex gap-8 mb-2">
    <div class="flex flex-col items-center">
    <div class="w-4 h-4 rounded-full bg-gray-200 border-2 border-white z-10"></div>
    </div>
    <div class="flex gap-4">
    <div class="flex flex-col  items-baseline gap-1">
        <span class="text-sm font-medium text-gray-900">13:50</span>
        <div class="text-xs font-medium text-gray-500" >Tue, 31 May</div>
    </div>
    <div class=" flex flex-col items-baseline gap-1 mb-1">
        <span class="text-sm font-medium text-gray-900">London (LHR)</span>
        <div class="text-xs font-medium text-gray-500">London, Heathrow</div>
    </div>
 </div>
</div>

<!-- Arrival -->
<div class="relative flex gap-8 mb-6">
<div class="flex flex-col items-center">
    <div class="w-4 h-4 rounded-full bg-gray-200 border-2 border-white z-10"></div>
</div>
<div class="flex gap-4">
    <div class="flex flex-col  items-baseline gap-1">
        <span class="text-sm font-medium text-gray-900">15:55</span>
        <div class="text-xs font-medium text-gray-500" >Tue, 31 May</div>
    </div>
    <div class=" flex flex-col items-baseline gap-1 mb-1">
        <span class="text-sm font-medium text-gray-900">Barcelona (BCN)</span>
        <div class="text-xs font-medium text-gray-500"> Barcelona Intl Airport</div>
    </div>
</div>
    </div>
</div>

<!-- CO2 Emissions -->
<div class="mt-6 pt-4 border-t border-gray-200 flex items-center justify-between">
<div class="h-2.5 w-20 mx-3 bg-gray-200 rounded"></div>
<div class="flex items-center justify-end gap-1 text-sm text-gray-600 font-medium">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"></path>
    </svg>
    <span>127kg CO<sub>2</sub></span>
</div>
</div>
</div>

  <!-- Confirm Button -->
<div class="justify-center flex pr-4">
    <button onclick="confirmChanges()" class="btn text-[15px] font-bold px-28 py-3 bg-black">Confirm changes</button>
</div>

</div>
</div>
<script>
function confirmChanges() {
    // Hide alert banner
    document.getElementById('alertBanner').classList.add('hidden');
    
    // Show success message
    const successMessage = document.getElementById('successMessage');
    successMessage.classList.remove('hidden');
    successMessage.classList.add('flex');
}
</script>
<!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxSixteen', 'toggleBtnSixteen')" class="btn" id="toggleBtnSixteen">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxSixteen">
 <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-md">
<div class="bg-white mb-6 p-4 rounded-lg shadow">
<!-- Header -->
<h1 class="text-[17px] font-medium text-gray-900 mb-4">Update your flight</h1>

<!-- Alert Banner -->
<div id="alertBanner" class="bg-[#fff3dc] rounded-lg px-4 py-3 flex items-start gap-3">
<div class="flex-shrink-0">
<svg class="w-5 h-5 text-yellow-600" fill="currentColor" viewBox="0 0 20 20">
  <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
</svg>
</div>
  <p class="text-sm font-medium text-gray-800">Do you want to update your flight?</p>
</div>

<!-- Success Message (Hidden by default) -->
<div id="successMessage" class="bg-teal-50 rounded-lg px-4 py-3 items-center justify-between hidden">
<div class="flex items-center gap-3">
<div class="flex-shrink-0">
<svg class="w-5 h-5 text-teal-600" fill="currentColor" viewBox="0 0 20 20">
<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
</svg>
</div>
<p class="text-sm font-medium text-gray-800 flex-1">Flight booking updated!</p>
</div>
<span class="text-sm font-semibold text-gray-700">SA548173</span>
</div>
</div>
<div class="bg-white rounded-lg p-4 shadow">
<!-- Flight Details Card -->
<div class="bg-white border border-gray-100 rounded-lg p-4 mb-6">

<!-- Date Changed Badge -->
<div class="flex items-center justify-between mb-4">
<span class="inline-block px-2 py-0.5 text-[10.5px] font-semibold text-teal-700 bg-teal-50 rounded-2xl">
Date Changed
</span>
<div class="h-2.5 w-20 mx-3 bg-gray-200 rounded"></div>
</div>

<!-- Flight Route -->
<div class="relative">
<!-- Timeline Line -->
<div class="absolute left-[7px] top-1.5 bottom-11 w-0.5 bg-gray-200"></div>

<!-- Departure -->
<div class="relative flex gap-8 mb-2">
<div class="flex flex-col items-center">
<div class="w-4 h-4 rounded-full bg-gray-200 border-2 border-white z-10"></div>
</div>
<div class="flex gap-4">
<div class="flex flex-col  items-baseline gap-1">
  <span class="text-sm font-medium text-gray-900">13:50</span>
  <div class="text-xs font-medium text-gray-500" >Tue, 31 May</div>
</div>
<div class=" flex flex-col items-baseline gap-1 mb-1">
  <span class="text-sm font-medium text-gray-900">London (LHR)</span>
  <div class="text-xs font-medium text-gray-500">London, Heathrow</div>
</div>
</div>
</div>

<!-- Arrival -->
<div class="relative flex gap-8 mb-6">
<div class="flex flex-col items-center">
<div class="w-4 h-4 rounded-full bg-gray-200 border-2 border-white z-10"></div>
</div>
<div class="flex gap-4">
<div class="flex flex-col  items-baseline gap-1">
  <span class="text-sm font-medium text-gray-900">15:55</span>
  <div class="text-xs font-medium text-gray-500" >Tue, 31 May</div>
</div>
<div class=" flex flex-col items-baseline gap-1 mb-1">
  <span class="text-sm font-medium text-gray-900">Barcelona (BCN)</span>
  <div class="text-xs font-medium text-gray-500"> Barcelona Intl Airport</div>
</div>
</div>
</div>
</div>

<!-- CO2 Emissions -->
<div class="mt-6 pt-4 border-t border-gray-200 flex items-center justify-between">
<div class="h-2.5 w-20 mx-3 bg-gray-200 rounded"></div>
<div class="flex items-center justify-end gap-1 text-sm text-gray-600 font-medium">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"></path>
</svg>
<span>127kg CO<sub>2</sub></span>
</div>
</div>
</div>

<!-- Confirm Button -->
<div class="justify-center flex pr-4">
<button onclick="confirmChanges()" class="btn text-[15px] font-bold px-28 py-3 bg-black">Confirm changes</button>
</div>

</div>
</div>

<script>
function confirmChanges() {
    // Hide alert banner
    document.getElementById('alertBanner').classList.add('hidden');
    
    // Show success message
    const successMessage = document.getElementById('successMessage');
    successMessage.classList.remove('hidden');
    successMessage.classList.add('flex');
}
</script>
</code></pre>
</div>
</div>

 <div class="border-l-4 border-blue-500 pl-4">
<div class="max-w-2xl p-6">
    <!-- Card -->
    <div class="bg-white shadow-md rounded-2xl p-6 border border-gray-200">
        
        <!-- Heading + Description -->
        <div class="grid grid-cols-5 gap-5 justify-between items-start">
            <h2 class="col-span-2 text-xl font-semibold text-gray-900">
                US Product Team Visit
            </h2>

            <p class="col-span-3 text-sm text-gray-500 max-w-sm">
                3-day and 2-night trip to SF to meet with R&D team and plan for Q4.
            </p>
        </div>

        <!-- Divider -->
        <div class="border-t my-5"></div>

        <!-- Date + Budget -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <!-- Date box -->
            <div class="border px-4 py-2 rounded-xl flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-gray-400">Date</p>
                    <input type="text" class="dp text-sm">
                </div>
               <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M3 22V4h3V2h2v2h8V2h2v2h3v18zm2-2h14V10H5z"/></svg>
            </div>

            <!-- Budget box -->
            <div class="border px-4 py-2 rounded-xl">
                <p class="text-[11px] font-semibold text-gray-400">Budget</p>
                <p class="text-gray-900 text-sm mt-1">US$ 6,000.00</p>
            </div>

        </div>
    </div>
</div>
 <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxSeventeen', 'toggleBtnSeventeen')" class="btn" id="toggleBtnSeventeen">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxSeventeen">
   <pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-3xl mx-auto p-6">
    <!-- Card -->
    <div class="bg-white shadow-md rounded-2xl p-6 border border-gray-200">
        
        <!-- Heading + Description -->
        <div class="flex justify-between items-start">
            <h2 class="text-xl font-semibold text-gray-900">
                US Product Team Visit
            </h2>

            <p class="text-sm text-gray-500 max-w-sm">
                3-day and 2-night trip to SF to meet with R&D team and plan for Q4.
            </p>
        </div>

        <!-- Divider -->
        <div class="border-t my-5"></div>

        <!-- Date + Budget -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <!-- Date box -->
            <div class="border p-4 rounded-xl flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-400">Date</p>
                    <input type="text" class="dp">
                </div>
               <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M3 22V4h3V2h2v2h8V2h2v2h3v18zm2-2h14V10H5z"/></svg>
            </div>

            <!-- Budget box -->
            <div class="border p-4 rounded-xl">
                <p class="text-xs text-gray-400">Budget</p>
                <p class="text-gray-900 font-semibold">US$ 6,000.00</p>
            </div>

        </div>
    </div>
</div></code></pre>
 </div>
</div>

      <div class="border-l-4 border-blue-500 pl-4">
  <div
   x-data="seatBooking()"
      class="max-w-lg w-full bg-white rounded-2xl shadow-2xl overflow-hidden mb-6"
    >
      <div class="p-6 space-y-8">
        <!-- SEAT SECTION -->
        <div class="space-y-6">
          <!-- BUSINESS ROW -->
          <div class="flex justify-center gap-6">
            <template x-for="seat in businessSeats">
              <div :class="seatClass(seat)" @click="toggleSeat(seat)">
                <span x-text="seat.label"></span>
              </div>
            </template>
          </div>
          <!-- ECONOMY ROW -->
          <div class="flex justify-center gap-6">
            <template x-for="seat in economySeats">
              <div :class="seatClass(seat)" @click="toggleSeat(seat)">
                <span x-text="seat.label"></span>
              </div>
            </template>
          </div>
          <!-- ECONOMY ROW -->
          <div class="flex justify-center gap-6">
            <template x-for="seat in economySeat">
              <div :class="seatClass(seat)" @click="toggleSeat(seat)">
                <span x-text="seat.label"></span>
              </div>
            </template>
          </div>
        </div>

        <!-- SUMMARY -->
        <div
          class="rounded-lg px-6 py-3 border space-y-2"
        >
          <template x-for="(p, index) in selected">
            <div
              class="flex justify-between items-center"
            >
              <div class="flex items-center gap-2">
                <span class="text-sm font-normal text-gray-700">
                  Passenger <span x-text="index + 1"></span>
                </span>

                <span
                  class="px-3 py-1 rounded-full text-xs font-semibold"
                  x-show="p.seat"
                  :class="p.seat?.class === 'business'
              ? 'bg-blue-100 text-blue-700'
              : 'bg-purple-100 text-purple-700'"
                >
                  <span
                    x-text="p.seat?.class === 'business' ? 'Business' : 'Economy'"
                  ></span>
                </span>

                <span class="text-[10px] font-normal text-gray-600 bg-gray-100 py-0.5 px-2 rounded-xl" x-show="!p.seat"
                  >Unselected</span
                >
              </div>

              <span
                class="font-normal text-gray-800"
                x-text="'$' + p.price.toFixed(2)"
              >
              </span>
            </div>
          </template>

          <div class="border-t pt-4 flex justify-between items-center">
            <span class="text-[19px] font-normal text-gray-700">Total</span>
            <span
              class="text-lg font-normal bg-clip-text text-gray-700"
              x-text="'$' + total.toFixed(2)"
            >
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- ALPINE JS LOGIC -->
    <script>
      function seatBooking() {
        return {
          // 2 fixed passengers (always visible)
          selected: [
            { seat: null, price: 0 },
            { seat: null, price: 0 },
          ],

          businessSeats: [
            {
              id: "A1",
              label: "A",
              class: "business",
              price: 50,
              disabled: false,
            },
            {
              id: "B1",
              label: "B",
              class: "business",
              price: 50,
              disabled: false,
            },
            {
              id: "C1",
              label: "C",
              class: "business",
              price: 50,
              disabled: false,
            },
            {
              id: "D1",
              label: "D",
              class: "business",
              price: 50,
              disabled: false,
            },
            { id: "X1", label: "✕", disabled: true },
            { id: "X2", label: "✕", disabled: true },
          ],

          economySeats: [
            {
              id: "A2",
              label: "A",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "B2",
              label: "B",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "C2",
              label: "C",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "D2",
              label: "D",
              class: "economy",
              price: 27,
              disabled: false,
            },
            { id: "X3", label: "✕", disabled: true },
            { id: "X4", label: "✕", disabled: true },
          ],
          economySeat: [
            {
              id: "A3",
              label: "A",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "B3",
              label: "B",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "C3",
              label: "C",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "D3",
              label: "D",
              class: "economy",
              price: 27,
              disabled: false,
            },
            { id: "X3", label: "✕", disabled: true },
            { id: "X4", label: "✕", disabled: true },
          ],

          get total() {
            return this.selected.reduce((sum, p) => sum + p.price, 0);
          },

          seatClass(seat) {
            if (seat.disabled)
              return "w-12 h-12 flex items-center justify-center rounded-lg text-gray-300 bg-gray-100 cursor-not-allowed";

            const found = this.selected.find((p) => p.seat?.id === seat.id);

            if (found) {
              return seat.class === "business"
                ? "w-12 h-12 flex items-center justify-center rounded-md bg-blue-600 text-white shadow-lg ring-4 ring-blue-300 border-2 border-blue-600"
                : "w-12 h-12 flex items-center justify-center rounded-md bg-purple-600 text-white shadow-lg ring-4 ring-purple-300 border-2 border-purple-600";
            }

            return seat.class === "business"
              ? "w-12 h-12 flex items-center justify-center rounded-md bg-blue-100 text-blue-600 border-2 border-blue-200 hover:border-blue-400 cursor-pointer"
              : "w-12 h-12 flex items-center justify-center rounded-md bg-purple-100 text-purple-600 border-2 border-purple-200 hover:border-purple-400 cursor-pointer";
          },

          toggleSeat(seat) {
            // If selected → unselect
            const indexFound = this.selected.findIndex(
              (p) => p.seat?.id === seat.id
            );
            if (indexFound !== -1) {
              this.selected[indexFound].seat = null;
              this.selected[indexFound].price = 0;
              return;
            }

            // If all seats occupied
            const emptySlot = this.selected.find((p) => p.seat === null);
            if (!emptySlot) {
              alert("Only 2 seats can be selected.");
              return;
            }

            // Assign seat
            emptySlot.seat = seat;
            emptySlot.price = seat.price;
          },
        };
      }
    </script>
  <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxEighteen', 'toggleBtnEighteen')" class="btn" id="toggleBtnEighteen">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxEighteen">
   <pre class="line-numbers language-markup"><code class="language-html">  <div
      class="max-w-lg w-full bg-white rounded-2xl shadow-2xl overflow-hidden"
    >
      <div class="p-6 space-y-8">
        <!-- SEAT SECTION -->
        <div class="space-y-6">
          <!-- BUSINESS ROW -->
          <div class="flex justify-center gap-6">
            <template x-for="seat in businessSeats">
              <div :class="seatClass(seat)" @click="toggleSeat(seat)">
                <span x-text="seat.label"></span>
              </div>
            </template>
          </div>
          <!-- ECONOMY ROW -->
          <div class="flex justify-center gap-6">
            <template x-for="seat in economySeats">
              <div :class="seatClass(seat)" @click="toggleSeat(seat)">
                <span x-text="seat.label"></span>
              </div>
            </template>
          </div>
          <!-- ECONOMY ROW -->
          <div class="flex justify-center gap-6">
            <template x-for="seat in economySeat">
              <div :class="seatClass(seat)" @click="toggleSeat(seat)">
                <span x-text="seat.label"></span>
              </div>
            </template>
          </div>
        </div>

        <!-- SUMMARY -->
        <div
          class="rounded-lg px-6 py-3 border space-y-2"
        >
          <template x-for="(p, index) in selected">
            <div
              class="flex justify-between items-center"
            >
              <div class="flex items-center gap-2">
                <span class="text-sm font-normal text-gray-700">
                  Passenger <span x-text="index + 1"></span>
                </span>

                <span
                  class="px-3 py-1 rounded-full text-xs font-semibold"
                  x-show="p.seat"
                  :class="p.seat?.class === 'business'
              ? 'bg-blue-100 text-blue-700'
              : 'bg-purple-100 text-purple-700'"
                >
                  <span
                    x-text="p.seat?.class === 'business' ? 'Business' : 'Economy'"
                  ></span>
                </span>

                <span class="text-[10px] font-normal text-gray-600 bg-gray-100 py-0.5 px-2 rounded-xl" x-show="!p.seat"
                  >Unselected</span
                >
              </div>

              <span
                class="font-normal text-gray-800"
                x-text="'$' + p.price.toFixed(2)"
              >
              </span>
            </div>
          </template>

          <div class="border-t pt-4 flex justify-between items-center">
            <span class="text-[19px] font-normal text-gray-700">Total</span>
            <span
              class="text-lg font-normal bg-clip-text text-gray-700"
              x-text="'$' + total.toFixed(2)"
            >
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- ALPINE JS LOGIC -->
    <script>
      function seatBooking() {
        return {
          // 2 fixed passengers (always visible)
          selected: [
            { seat: null, price: 0 },
            { seat: null, price: 0 },
          ],

          businessSeats: [
            {
              id: "A1",
              label: "A",
              class: "business",
              price: 50,
              disabled: false,
            },
            {
              id: "B1",
              label: "B",
              class: "business",
              price: 50,
              disabled: false,
            },
            {
              id: "C1",
              label: "C",
              class: "business",
              price: 50,
              disabled: false,
            },
            {
              id: "D1",
              label: "D",
              class: "business",
              price: 50,
              disabled: false,
            },
            { id: "X1", label: "✕", disabled: true },
            { id: "X2", label: "✕", disabled: true },
          ],

          economySeats: [
            {
              id: "A2",
              label: "A",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "B2",
              label: "B",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "C2",
              label: "C",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "D2",
              label: "D",
              class: "economy",
              price: 27,
              disabled: false,
            },
            { id: "X3", label: "✕", disabled: true },
            { id: "X4", label: "✕", disabled: true },
          ],
          economySeat: [
            {
              id: "A3",
              label: "A",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "B3",
              label: "B",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "C3",
              label: "C",
              class: "economy",
              price: 27,
              disabled: false,
            },
            {
              id: "D3",
              label: "D",
              class: "economy",
              price: 27,
              disabled: false,
            },
            { id: "X3", label: "✕", disabled: true },
            { id: "X4", label: "✕", disabled: true },
          ],

          get total() {
            return this.selected.reduce((sum, p) => sum + p.price, 0);
          },

          seatClass(seat) {
            if (seat.disabled)
              return "w-12 h-12 flex items-center justify-center rounded-lg text-gray-300 bg-gray-100 cursor-not-allowed";

            const found = this.selected.find((p) => p.seat?.id === seat.id);

            if (found) {
              return seat.class === "business"
                ? "w-12 h-12 flex items-center justify-center rounded-md bg-blue-600 text-white shadow-lg ring-4 ring-blue-300 border-2 border-blue-600"
                : "w-12 h-12 flex items-center justify-center rounded-md bg-purple-600 text-white shadow-lg ring-4 ring-purple-300 border-2 border-purple-600";
            }

            return seat.class === "business"
              ? "w-12 h-12 flex items-center justify-center rounded-md bg-blue-100 text-blue-600 border-2 border-blue-200 hover:border-blue-400 cursor-pointer"
              : "w-12 h-12 flex items-center justify-center rounded-md bg-purple-100 text-purple-600 border-2 border-purple-200 hover:border-purple-400 cursor-pointer";
          },

          toggleSeat(seat) {
            // If selected → unselect
            const indexFound = this.selected.findIndex(
              (p) => p.seat?.id === seat.id
            );
            if (indexFound !== -1) {
              this.selected[indexFound].seat = null;
              this.selected[indexFound].price = 0;
              return;
            }

            // If all seats occupied
            const emptySlot = this.selected.find((p) => p.seat === null);
            if (!emptySlot) {
              alert("Only 2 seats can be selected.");
              return;
            }

            // Assign seat
            emptySlot.seat = seat;
            emptySlot.price = seat.price;
          },
        };
      }
    </script></code></pre>
 </div>
</div>


        <div class="border-l-4 border-blue-500 pl-4">
 <div class="max-w-md bg-white rounded-lg shadow-sm border border-gray-200" x-data="{
    airlines: {
        american: true,
        british: true,
        lufthansa: true,
        swiss: true,
        singapore: true
    }
}">
    <!-- American Airlines -->
    <div class="flex items-center justify-between px-4 pt-4 pb-2 cursor-pointer"
         @click="airlines.american = !airlines.american">

        <div class="flex items-center gap-3">
            <div class="w-7 h-7 flex items-center justify-center">
                <img src="https://static.dezeen.com/uploads/2013/01/dezeen_American-Airlines-logo-and-livery_4a-300x300.jpg" alt="">
            </div>
            <span class="text-gray-800 font-medium">American Airlines</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.american ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.american">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>

    <!-- British Airlines -->
    <div class="flex items-center justify-between px-4 py-2 cursor-pointer"
         @click="airlines.british = !airlines.british">

        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img class="w-8 h-8" src="https://assets.duffel.com/img/airlines/for-light-background/full-color-logo/BA.svg?v=1" alt="">
            </div>
            <span class="text-gray-800 font-medium">British Airlines</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.british ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.british">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>

    <!-- Lufthansa -->
    <div class="flex items-center justify-between px-4 py-2 cursor-pointer"
         @click="airlines.lufthansa = !airlines.lufthansa">

        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img src="https://encrypted-tbn1.gstatic.com/images?q=tbn:ANd9GcTi257wo9zOQEwKNE0OV9-u7G5Zoham74R6FkKOAjwlI25ED5E-" alt="">
            </div>
            <span class="text-gray-800 font-medium">Lufthansa</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.lufthansa ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.lufthansa">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>

    <!-- Swiss Air -->
    <div class="flex items-center justify-between px-4 py-2 cursor-pointer"
         @click="airlines.swiss = !airlines.swiss">

        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img src="https://encrypted-tbn1.gstatic.com/images?q=tbn:ANd9GcTOmbtSVaYwf_4aK7VwiFSNi_S8emb_ov5VNyhfJcOIlmVC0dgv" alt="">
            </div>
            <span class="text-gray-800 font-medium">Swiss Air</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.swiss ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.swiss">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>

    <!-- Singapore Airlines -->
    <div class="flex items-center justify-between px-4 pb-4 pt-2 cursor-pointer"
         @click="airlines.singapore = !airlines.singapore">

        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQzBHwqmO3IrrsA3MzZMHME6coMeNOSnNGyprMPFysjZGkLMLCu" alt="">
            </div>
            <span class="text-gray-800 font-medium">Singapore Airlines</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.singapore ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.singapore">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>
</div>


        <!-- Code Toggle Button -->
 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxNineteen', 'toggleBtnNineteen')" class="btn" id="toggleBtnNineteen">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxNineteen">
<pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-md bg-white rounded-lg shadow-sm border border-gray-200" x-data="{
    airlines: {
        american: true,
        british: true,
        lufthansa: true,
        swiss: true,
        singapore: true
    }
}">
    <!-- American Airlines -->
    <div class="flex items-center justify-between px-4 pt-4 pb-2 cursor-pointer"
         @click="airlines.american = !airlines.american">

        <div class="flex items-center gap-3">
            <div class="w-7 h-7 flex items-center justify-center">
                <img src="https://static.dezeen.com/uploads/2013/01/dezeen_American-Airlines-logo-and-livery_4a-300x300.jpg" alt="">
            </div>
            <span class="text-gray-800 font-medium">American Airlines</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.american ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.american">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>

    <!-- British Airlines -->
    <div class="flex items-center justify-between px-4 py-2 cursor-pointer"
         @click="airlines.british = !airlines.british">

        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img class="w-8 h-8" src="https://assets.duffel.com/img/airlines/for-light-background/full-color-logo/BA.svg?v=1" alt="">
            </div>
            <span class="text-gray-800 font-medium">British Airlines</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.british ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.british">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>

    <!-- Lufthansa -->
    <div class="flex items-center justify-between px-4 py-2 cursor-pointer"
         @click="airlines.lufthansa = !airlines.lufthansa">

        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img src="https://encrypted-tbn1.gstatic.com/images?q=tbn:ANd9GcTi257wo9zOQEwKNE0OV9-u7G5Zoham74R6FkKOAjwlI25ED5E-" alt="">
            </div>
            <span class="text-gray-800 font-medium">Lufthansa</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.lufthansa ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.lufthansa">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>

    <!-- Swiss Air -->
    <div class="flex items-center justify-between px-4 py-2 cursor-pointer"
         @click="airlines.swiss = !airlines.swiss">

        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img src="https://encrypted-tbn1.gstatic.com/images?q=tbn:ANd9GcTOmbtSVaYwf_4aK7VwiFSNi_S8emb_ov5VNyhfJcOIlmVC0dgv" alt="">
            </div>
            <span class="text-gray-800 font-medium">Swiss Air</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.swiss ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.swiss">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>

    <!-- Singapore Airlines -->
    <div class="flex items-center justify-between px-4 pb-4 pt-2 cursor-pointer"
         @click="airlines.singapore = !airlines.singapore">

        <div class="flex items-center gap-3">
            <div class="w-8 h-8 flex items-center justify-center">
                <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQzBHwqmO3IrrsA3MzZMHME6coMeNOSnNGyprMPFysjZGkLMLCu" alt="">
            </div>
            <span class="text-gray-800 font-medium">Singapore Airlines</span>
        </div>

        <div class="w-8 h-8 rounded-md flex items-center justify-center"
             :class="airlines.singapore ? 'bg-indigo-950' : 'bg-white border-2 border-gray-300'">
            <template x-if="airlines.singapore">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </template>
        </div>
    </div>
</div></code></pre>
</div>
</div>

 <div class="border-l-4 border-blue-500 pl-4">
 <div class="max-w-lg">
        <div class="bg-white rounded-lg shadow-xl p-3">
            <div class="flex items-center gap-4 ">
             <div class="flex items-center gap- border border-gray-300 rounded-lg px-4 py-1.5">
                <!-- Card Logo (Mastercard) -->
                <div class="flex-shrink-0">
                    <div class="flex items-center gap-[-8px]">
                        <div class="w-3 h-3 rounded-full bg-red-500 opacity-100"></div>
                        <div class="w-3 h-3 rounded-full bg-yellow-500 opacity-100 -ml-5"></div>
                    </div>
                </div>

                <!-- Card Number Input -->
                <div class="flex-1 ml-4">
                    <input 
                        type="password" 
                        placeholder="•••• •••• •••• 8356" 
                        maxlength="19"
                        class="w-32 text-black text-xs font-medium outline-none bg-transparent placeholder-gray-400"
                    />
                </div>

                <!-- MM/YY Input -->
                <div class="flex-shrink-0 border-l border-gray-300 pl-2">
                    <input 
                        type="text" 
                        placeholder="MM / YY" 
                        maxlength="7"
                        class="w-12 text-gray-400 text-xs outline-none bg-transparent placeholder-gray-400 text-center"
                    />
                </div>

                <!-- CVC Input -->
                <div class="flex-shrink-0 ml-2">
                    <input 
                        type="text" 
                        placeholder="CVC" 
                        maxlength="3"
                        class="w-10 text-gray-400 text-xs outline-none bg-transparent placeholder-gray-400 text-center"
                    />
                </div>
              </div>

                <!-- Proceed Button -->
                <div class="flex-shrink-0">
                    <button class="bg-indigo-950 text-white text-xs py-[1px] btn flex gap-2">
                        Proceed to checkout
                        <div class="text-black bg-white p-0.5 items-center rounded-full">
                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-format card number with spaces
        const cardInput = document.querySelector('input[placeholder*="8356"]');
        cardInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\s/g, '');
            let formattedValue = value.match(/.{1,4}/g)?.join(' ') || value;
            e.target.value = formattedValue;
        });

        // Auto-format MM/YY
        const dateInput = document.querySelector('input[placeholder="MM / YY"]');
        dateInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 2) {
                value = value.slice(0, 2) + ' / ' + value.slice(2, 4);
            }
            e.target.value = value;
        });

        // Only allow numbers in CVC
        const cvcInput = document.querySelector('input[placeholder="CVC"]');
        cvcInput.addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '');
        });
    </script>

 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwenty', 'toggleBtnTwenty')" class="btn" id="toggleBtnTwenty">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTwenty">
<pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-lg">
        <div class="bg-white rounded-lg shadow-xl p-3">
            <div class="flex items-center gap-4 ">
             <div class="flex items-center gap- border border-gray-300 rounded-lg px-4 py-1.5">
                <!-- Card Logo (Mastercard) -->
                <div class="flex-shrink-0">
                    <div class="flex items-center gap-[-8px]">
                        <div class="w-3 h-3 rounded-full bg-red-500 opacity-100"></div>
                        <div class="w-3 h-3 rounded-full bg-yellow-500 opacity-100 -ml-5"></div>
                    </div>
                </div>

                <!-- Card Number Input -->
                <div class="flex-1 ml-4">
                    <input 
                        type="password" 
                        placeholder="•••• •••• •••• 8356" 
                        maxlength="19"
                        class="w-32 text-black text-xs font-medium outline-none bg-transparent placeholder-gray-400"
                    />
                </div>

                <!-- MM/YY Input -->
                <div class="flex-shrink-0 border-l border-gray-300 pl-2">
                    <input 
                        type="text" 
                        placeholder="MM / YY" 
                        maxlength="7"
                        class="w-12 text-gray-400 text-xs outline-none bg-transparent placeholder-gray-400 text-center"
                    />
                </div>

                <!-- CVC Input -->
                <div class="flex-shrink-0 ml-2">
                    <input 
                        type="text" 
                        placeholder="CVC" 
                        maxlength="3"
                        class="w-10 text-gray-400 text-xs outline-none bg-transparent placeholder-gray-400 text-center"
                    />
                </div>
              </div>

                <!-- Proceed Button -->
                <div class="flex-shrink-0">
                    <button class="bg-indigo-950 text-white text-xs py-[1px] btn flex gap-2">
                        Proceed to checkout
                        <div class="text-black bg-white p-0.5 items-center rounded-full">
                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-format card number with spaces
        const cardInput = document.querySelector('input[placeholder*="8356"]');
        cardInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\s/g, '');
            let formattedValue = value.match(/.{1,4}/g)?.join(' ') || value;
            e.target.value = formattedValue;
        });

        // Auto-format MM/YY
        const dateInput = document.querySelector('input[placeholder="MM / YY"]');
        dateInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 2) {
                value = value.slice(0, 2) + ' / ' + value.slice(2, 4);
            }
            e.target.value = value;
        });

        // Only allow numbers in CVC
        const cvcInput = document.querySelector('input[placeholder="CVC"]');
        cvcInput.addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '');
        });
    </script>
 </code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
<div class="w-full max-w-5xl my-6 px-4" x-data="destinationSlider()">

  <!-- Heading -->
  <h2 class="text-2xl font-semibold text-slate-900 mb-4">
    Popular flight destinations
  </h2>

  <div class="relative">
    <!-- Left arrow -->
    <button @click="prev()" x-show="!atStart" class="hidden md:flex absolute left-0 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-white shadow border border-slate-200 items-center justify-center text-slate-700 hover:bg-slate-50">
      <span class="material-symbols-outlined text-base">chevron_left</span>
    </button>
    <!-- Slider track -->
    <div x-ref="track" @scroll="onScroll()" class="no-scrollbar flex gap-4 overflow-x-auto scroll-smooth pb-2 md:px-8">
      <template x-for="(destination, index) in destinations" :key="index">
        <div class="relative flex-shrink-0 w-[220px] h-[280px] rounded-3xl overflow-hidden cursor-pointer group">
          <!-- Background image -->
          <div class="absolute inset-0 bg-center bg-cover" :style="`background-image:url('${destination.image}')`"></div>
          <!-- Dark gradient overlay -->
          <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/40 to-transparent"></div>
          <!-- Hover overlay light -->
          <div class="absolute inset-0 bg-black/10 opacity-0 group-hover:opacity-100 transition"></div>
          <!-- Text -->
          <div class="absolute inset-0 flex items-end justify-center pb-6 px-4">
            <span class="text-white font-semibold text-sm text-center drop-shadow">
              <span x-text="destination.label"></span> Flights
            </span>
          </div>
        </div>
      </template>
    </div>

    <!-- Right arrow -->
    <button @click="next()" x-show="!atEnd" class="hidden md:flex absolute right-0 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-white shadow border border-slate-200 items-center justify-center text-slate-700 hover:bg-slate-50">
      <span class="material-symbols-outlined text-base">chevron_right</span>
    </button>
  </div>
</div>

<script>
function destinationSlider() {
  return {
    cardWidth: 240, 
    atStart: true,
    atEnd: false,
    destinations: [
      {
        label: 'Los Angeles',
        image: 'https://images.pexels.com/photos/248797/pexels-photo-248797.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Las Vegas',
        image: 'https://images.pexels.com/photos/4459837/pexels-photo-4459837.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Cancun',
        image: 'https://images.pexels.com/photos/240526/pexels-photo-240526.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Miami',
        image: 'https://images.pexels.com/photos/208702/pexels-photo-208702.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Honolulu',
        image: 'https://images.pexels.com/photos/462146/pexels-photo-462146.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Orlando',
        image: 'https://images.pexels.com/photos/462149/pexels-photo-462149.jpeg?auto=compress&cs=tinysrgb&w=800'
      }
    ],

    next() {
      this.$refs.track.scrollBy({ left: this.cardWidth, behavior: 'smooth' });
      this.onScroll();
    },
    prev() {
      this.$refs.track.scrollBy({ left: -this.cardWidth, behavior: 'smooth' });
      this.onScroll();
    },

    onScroll() {
      const el = this.$refs.track;
      this.atStart = el.scrollLeft <= 5;
      this.atEnd = Math.ceil(el.scrollLeft + el.clientWidth) >= el.scrollWidth - 5;
    }
  };
}
</script>

 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwentyone', 'toggleBtnTwentyone')" class="btn" id="toggleBtnTwentyone">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTwentyone">
<pre class="line-numbers language-markup"><code class="language-html"><div class="w-full max-w-5xl my-6 px-4" x-data="destinationSlider()">

  <!-- Heading -->
  <h2 class="text-2xl font-semibold text-slate-900 mb-4">
    Popular flight destinations
  </h2>

  <div class="relative">
    <!-- Left arrow -->
    <button @click="prev()" x-show="!atStart" class="hidden md:flex absolute left-0 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-white shadow border border-slate-200 items-center justify-center text-slate-700 hover:bg-slate-50">
      <span class="material-symbols-outlined text-base">chevron_left</span>
    </button>
    <!-- Slider track -->
    <div x-ref="track" @scroll="onScroll()" class="no-scrollbar flex gap-4 overflow-x-auto scroll-smooth pb-2 md:px-8">
      <template x-for="(destination, index) in destinations" :key="index">
        <div class="relative flex-shrink-0 w-[220px] h-[280px] rounded-3xl overflow-hidden cursor-pointer group">
          <!-- Background image -->
          <div class="absolute inset-0 bg-center bg-cover" :style="`background-image:url('${destination.image}')`"></div>
          <!-- Dark gradient overlay -->
          <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/40 to-transparent"></div>
          <!-- Hover overlay light -->
          <div class="absolute inset-0 bg-black/10 opacity-0 group-hover:opacity-100 transition"></div>
          <!-- Text -->
          <div class="absolute inset-0 flex items-end justify-center pb-6 px-4">
            <span class="text-white font-semibold text-sm text-center drop-shadow">
              <span x-text="destination.label"></span> Flights
            </span>
          </div>
        </div>
      </template>
    </div>

    <!-- Right arrow -->
    <button @click="next()" x-show="!atEnd" class="hidden md:flex absolute right-0 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-white shadow border border-slate-200 items-center justify-center text-slate-700 hover:bg-slate-50">
      <span class="material-symbols-outlined text-base">chevron_right</span>
    </button>
  </div>
</div>

<script>
function destinationSlider() {
  return {
    cardWidth: 240, 
    atStart: true,
    atEnd: false,
    destinations: [
      {
        label: 'Los Angeles',
        image: 'https://images.pexels.com/photos/248797/pexels-photo-248797.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Las Vegas',
        image: 'https://images.pexels.com/photos/4459837/pexels-photo-4459837.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Cancun',
        image: 'https://images.pexels.com/photos/240526/pexels-photo-240526.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Miami',
        image: 'https://images.pexels.com/photos/208702/pexels-photo-208702.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Honolulu',
        image: 'https://images.pexels.com/photos/462146/pexels-photo-462146.jpeg?auto=compress&cs=tinysrgb&w=800'
      },
      {
        label: 'Orlando',
        image: 'https://images.pexels.com/photos/462149/pexels-photo-462149.jpeg?auto=compress&cs=tinysrgb&w=800'
      }
    ],

    next() {
      this.$refs.track.scrollBy({ left: this.cardWidth, behavior: 'smooth' });
      this.onScroll();
    },
    prev() {
      this.$refs.track.scrollBy({ left: -this.cardWidth, behavior: 'smooth' });
      this.onScroll();
    },

    onScroll() {
      const el = this.$refs.track;
      this.atStart = el.scrollLeft <= 5;
      this.atEnd = Math.ceil(el.scrollLeft + el.clientWidth) >= el.scrollWidth - 5;
    }
  };
}
</script></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
 <div class="w-full max-w-2xl my-20 rounded-2xl border border-red-700 bg-[#d71f27] text-white shadow-lg overflow-hidden">

    <!-- Top header -->
    <div class="flex items-start justify-between px-5 pt-4 pb-3">
      <div class="flex items-center gap-3">
        <div class="text-lg font-semibold w-[110px] h-[45px]">
          <img src="https://pics.avs.io/200/200/EK@2x.png" class="w-full h-full object-cover" alt="">
        </div>
        <div>
          <p class="text-md font-semibold">Fly to Dubai with Emirates</p>
          <p class="text-xs text-red-100 mt-0.5">
            Better comfort and better dining with Emirates
          </p>
        </div>
      </div>

      <div class="text-right text-[10px] leading-tight">
        <p class="flex items-center justify-end gap-1 text-sm font-medium text-white mt-3">
          Sponsored
          <span class="inline-flex items-center justify-center w-3 h-3 rounded-full border border-red-100 text-[8px]">i</span>
        </p>
      </div>
    </div>

    <!-- Content area -->
    <div class="grid grid-cols-3 bg-white rounded-xl text-slate-900">

      <!-- Flights section -->
      <div class="col-span-2 px-5 py-4">
        <!-- Row 1 -->
        <div class="flex items-center gap-4 pb-3">
          <div class=" w-[60px] h-[25px]">
            <img src="https://pics.avs.io/200/200/EK@2x.png" class="w-full h-full object-cover" alt="">
          </div>

          <div class="flex-1 grid grid-cols-[auto_auto_auto] items-center text-sm">
            <!-- From -->
            <div>
              <p class="font-semibold text-base text-right">9:00 AM</p>
              <p class="text-[16px] text-slate-500 text-right">ISB</p>
            </div>

            <!-- Duration -->
            <div class="flex flex-col items-center text-center text-xs text-slate-500">
                <span class="text-[11px]">3h 35</span>
                <div class="flex gap-1 items-center">
                  <span class="h-[2px] w-20 bg-slate-300"></span>
                  <svg xmlns="http://www.w3.org/2000/svg" class="rotate-90 items-center text-center" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M7.712 21v-1.538l2.846-2.004v-4.331L3 16.173v-1.961l7.558-5.331V4.442q0-.594.424-1.018T12 3t1.018.424t.424 1.018v4.439L21 14.21v1.962l-7.558-3.046v4.33l2.827 2.005V21L12 19.692z"/></svg>
                </div>
              <span class="text-[12px] text-emerald-600 font-normal">Direct</span>
            </div>

            <!-- To -->
            <div class="text-right">
              <p class="font-semibold text-base text-left">11:35 AM</p>
              <p class="text-[16px] text-slate-500 text-left">DXB</p>
            </div>
          </div>
        </div>

        <!-- Row 2 -->
        <div class="flex items-center gap-4">
          <div class=" w-[60px] h-[25px]">
            <img src="https://pics.avs.io/200/200/EK@2x.png" class="w-full h-full object-cover" alt="">
          </div>

          <div class="flex-1 grid grid-cols-[auto_auto_auto] items-center text-sm">
            <!-- From -->
            <div>
              <p class="font-semibold text-base text-right">9:00 AM</p>
              <p class="text-[16px] text-slate-500 text-right">ISB</p>
            </div>

            <!-- Duration -->
            <div class="flex flex-col items-center text-center text-xs text-slate-500">
                <span class="text-[11px]">3h</span>
              <div class="flex gap-1 items-center">
                  <span class="h-[2px] w-20 bg-slate-300"></span>
                  <svg xmlns="http://www.w3.org/2000/svg" class="rotate-90 items-center text-center" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M7.712 21v-1.538l2.846-2.004v-4.331L3 16.173v-1.961l7.558-5.331V4.442q0-.594.424-1.018T12 3t1.018.424t.424 1.018v4.439L21 14.21v1.962l-7.558-3.046v4.33l2.827 2.005V21L12 19.692z"/></svg>
                </div>
              <span class="text-[12px] text-emerald-600 font-normal">Direct</span>
            </div>

            <!-- To -->
            <div class="text-right">
              <p class="font-semibold text-base text-left">01:30 AM</p>
              <p class="text-[16px] text-slate-500 text-left">DXB</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Price section -->
      <div class="col-span-1 border-l border-slate-200 py-4 flex flex-col justify-center items-center">
        <p class="text-xs text-slate-500">Book with Emirates from</p>
        <p class="text-xl font-bold text-slate-900">Rs 206,165</p>
        <button class="btn mt-2 inline-flex gap-1 items-center justify-center rounded-xl bg-slate-900 text-white text-sm font-semibold px-5 py-2">
          <span>Select</span> <svg xmlns="http://www.w3.org/2000/svg" width="18" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="m14 18l-1.4-1.45L16.15 13H4v-2h12.15L12.6 7.45L14 6l6 6z"/></svg>
        </button>
      </div>

    </div>
  </div>

 <div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwentytwo', 'toggleBtnTwentytwo')" class="btn" id="toggleBtnTwentytwo">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTwentytwo">
<pre class="line-numbers language-markup"><code class="language-html"> <div class="w-full max-w-2xl my-20 rounded-2xl border border-red-700 bg-[#d71f27] text-white shadow-lg overflow-hidden">

    <!-- Top header -->
    <div class="flex items-start justify-between px-5 pt-4 pb-3">
      <div class="flex items-center gap-3">
        <div class="text-lg font-semibold w-[110px] h-[45px]">
          <img src="https://pics.avs.io/200/200/EK@2x.png" class="w-full h-full object-cover" alt="">
        </div>
        <div>
          <p class="text-md font-semibold">Fly to Dubai with Emirates</p>
          <p class="text-xs text-red-100 mt-0.5">
            Better comfort and better dining with Emirates
          </p>
        </div>
      </div>

      <div class="text-right text-[10px] leading-tight">
        <p class="flex items-center justify-end gap-1 text-sm font-medium text-white mt-3">
          Sponsored
          <span class="inline-flex items-center justify-center w-3 h-3 rounded-full border border-red-100 text-[8px]">i</span>
        </p>
      </div>
    </div>

    <!-- Content area -->
    <div class="grid grid-cols-3 bg-white rounded-xl text-slate-900">

      <!-- Flights section -->
      <div class="col-span-2 px-5 py-4">
        <!-- Row 1 -->
        <div class="flex items-center gap-4 pb-3">
          <div class=" w-[60px] h-[25px]">
            <img src="https://pics.avs.io/200/200/EK@2x.png" class="w-full h-full object-cover" alt="">
          </div>

          <div class="flex-1 grid grid-cols-[auto_auto_auto] items-center text-sm">
            <!-- From -->
            <div>
              <p class="font-semibold text-base text-right">9:00 AM</p>
              <p class="text-[16px] text-slate-500 text-right">ISB</p>
            </div>

            <!-- Duration -->
            <div class="flex flex-col items-center text-center text-xs text-slate-500">
                <span class="text-[11px]">3h 35</span>
                <div class="flex gap-1 items-center">
                  <span class="h-[2px] w-20 bg-slate-300"></span>
                  <svg xmlns="http://www.w3.org/2000/svg" class="rotate-90 items-center text-center" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M7.712 21v-1.538l2.846-2.004v-4.331L3 16.173v-1.961l7.558-5.331V4.442q0-.594.424-1.018T12 3t1.018.424t.424 1.018v4.439L21 14.21v1.962l-7.558-3.046v4.33l2.827 2.005V21L12 19.692z"/></svg>
                </div>
              <span class="text-[12px] text-emerald-600 font-normal">Direct</span>
            </div>

            <!-- To -->
            <div class="text-right">
              <p class="font-semibold text-base text-left">11:35 AM</p>
              <p class="text-[16px] text-slate-500 text-left">DXB</p>
            </div>
          </div>
        </div>

        <!-- Row 2 -->
        <div class="flex items-center gap-4">
          <div class=" w-[60px] h-[25px]">
            <img src="https://pics.avs.io/200/200/EK@2x.png" class="w-full h-full object-cover" alt="">
          </div>

          <div class="flex-1 grid grid-cols-[auto_auto_auto] items-center text-sm">
            <!-- From -->
            <div>
              <p class="font-semibold text-base text-right">9:00 AM</p>
              <p class="text-[16px] text-slate-500 text-right">ISB</p>
            </div>

            <!-- Duration -->
            <div class="flex flex-col items-center text-center text-xs text-slate-500">
                <span class="text-[11px]">3h</span>
              <div class="flex gap-1 items-center">
                  <span class="h-[2px] w-20 bg-slate-300"></span>
                  <svg xmlns="http://www.w3.org/2000/svg" class="rotate-90 items-center text-center" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M7.712 21v-1.538l2.846-2.004v-4.331L3 16.173v-1.961l7.558-5.331V4.442q0-.594.424-1.018T12 3t1.018.424t.424 1.018v4.439L21 14.21v1.962l-7.558-3.046v4.33l2.827 2.005V21L12 19.692z"/></svg>
                </div>
              <span class="text-[12px] text-emerald-600 font-normal">Direct</span>
            </div>

            <!-- To -->
            <div class="text-right">
              <p class="font-semibold text-base text-left">01:30 AM</p>
              <p class="text-[16px] text-slate-500 text-left">DXB</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Price section -->
      <div class="col-span-1 border-l border-slate-200 py-4 flex flex-col justify-center items-center">
        <p class="text-xs text-slate-500">Book with Emirates from</p>
        <p class="text-xl font-bold text-slate-900">Rs 206,165</p>
        <button class="btn mt-2 inline-flex gap-1 items-center justify-center rounded-xl bg-slate-900 text-white text-sm font-semibold px-5 py-2">
          <span>Select</span> <svg xmlns="http://www.w3.org/2000/svg" width="18" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="m14 18l-1.4-1.45L16.15 13H4v-2h12.15L12.6 7.45L14 6l6 6z"/></svg>
        </button>
      </div>

    </div>
  </div></code></pre>
</div>
</div>





<!-- <div class="border-l-4 border-blue-500 pl-4">

 <div class="mb-3 flex items-end justify-end text-left">
  Toggle Button
  <button 
   onclick="toggleCode('codeBoxTwenty', 'toggleBtnTwenty')" class="btn" id="toggleBtnTwenty">
    Show Code
  </button>
</div>

Collapsible Code Block
<div class="hidden transition-all duration-300" id="codeBoxTwenty">
<pre class="line-numbers language-markup"><code class="language-html"></code></pre>
</div>
</div> -->
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