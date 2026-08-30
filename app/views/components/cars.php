<div class="container mx-auto">
   <div class="flex min-h-screen">
      <?php require_once "app/views/components/sidebar.php"; ?>
      <!-- Main Content Area -->
      <div class="flex-1 min-w-0 bg-gray-50">
         <div class="p-6 max-w-full overflow-x-hidden">
<!-- Dashboard Content -->
<div class="bg-white rounded-lg p-6 shadow-sm">
   <h1 class="text-2xl font-bold text-gray-900 mb-4">Cars Components</h1>
   <p class="text-gray-600 mb-6">Comprehensive cars system with headers, footers, colors, and interactive styles</p>
   <!-- Content -->
   <div class="space-y-8">
<!-- Basic Card with Icon Header -->
<div class="border-l-4 border-blue-500 pl-4">
<div class="max-w-4xl mx-auto my-6 bg-white border rounded-2xl shadow-sm p-4 md:p-6">
  <!-- Top row: compare + badge -->
  <div class="flex items-center space-x-[95px] mb-4">
    <div class="inline-flex items-center gap-2 text-sm text-gray-600">
     <div class="checkbox-group">
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="basic1" class="checkbox-input">
                <div class="checkbox-custom">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
            </div>
            <label for="basic1" class="cursor-pointer">Compare</label>
        </div>
    </div>
    </div>

    <span class="inline-flex items-center rounded-lg bg-[#003b95] px-3 py-1 text-xs font-semibold text-white">
      Top Pick
    </span>
  </div>

  <!-- Main content -->
  <div class="grid gap-4 md:grid-cols-[160px,1fr,180px] items-center">
    <!-- Car image -->
    <div class="flex justify-center">
      <img
        src="https://cdn2.rcstatic.com/images/car_images_b/new_images/kia/picanto_5_door_lrg.jpg"
        alt="Kia Picanto"
        class="w-full max-w-[220px] h-auto object-contain"
      >
    </div>

    <!-- Car details -->
    <div class="space-y-2">
      <h2 class="text-lg md:text-xl font-semibold text-gray-900">
        Kia Picanto <span class="text-sm font-normal text-blue-600">or similar small car</span>
      </h2>

      <!-- Features row 1 -->
      <div class="w-64 flex gap-2 items-center justify-between text-sm text-gray-700 mt-2">
        <div class="flex items-center gap-1">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 11.385q-1.237 0-2.119-.882T9 8.385t.881-2.12T12 5.386t2.119.88t.881 2.12t-.881 2.118t-2.119.882m-7 7.23V16.97q0-.619.36-1.158q.361-.54.97-.838q1.416-.679 2.834-1.018q1.417-.34 2.836-.34t2.837.34t2.832 1.018q.61.298.97.838q.361.539.361 1.158v1.646z"/></svg></span>
          <span>4 seats</span>
        </div>
        <div class="flex items-center gap-1">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M4.252 20q-1.04 0-1.771-.73q-.731-.728-.731-1.77q0-.898.572-1.586t1.428-.854V8.94q-.856-.165-1.428-.853Q1.75 7.398 1.75 6.5q0-1.042.729-1.77Q3.207 4 4.248 4t1.771.73t.731 1.77q0 .898-.572 1.587q-.572.688-1.428.854V11.5h6.75V8.94q-.856-.165-1.428-.853Q9.5 7.398 9.5 6.5q0-1.042.729-1.77q.728-.73 1.769-.73t1.771.73t.731 1.77q0 .898-.572 1.587q-.572.688-1.428.854V11.5h6.77V8.94q-.856-.165-1.429-.853q-.572-.689-.572-1.587q0-1.042.729-1.77q.728-.73 1.769-.73t1.772.73t.73 1.77q0 .898-.572 1.587q-.572.688-1.428.854V12.5H12.5v2.56q.856.165 1.428.853q.572.689.572 1.587q0 1.042-.728 1.77q-.729.73-1.77.73t-1.771-.73T9.5 17.5q0-.898.572-1.586t1.428-.854V12.5H4.75v2.56q.856.165 1.428.853q.572.689.572 1.587q0 1.042-.728 1.77q-.729.73-1.77.73m-.002-1q.617 0 1.059-.441q.441-.442.441-1.059t-.441-1.059Q4.867 16 4.25 16t-1.059.441q-.441.442-.441 1.059t.441 1.059Q3.633 19 4.25 19m0-11q.617 0 1.059-.441q.441-.442.441-1.059t-.441-1.059Q4.867 5 4.25 5t-1.059.441Q2.75 5.883 2.75 6.5t.441 1.059Q3.633 8 4.25 8M12 19q.617 0 1.059-.441q.441-.442.441-1.059t-.441-1.059T12 16t-1.059.441T10.5 17.5t.441 1.059Q11.383 19 12 19m0-11q.617 0 1.059-.441q.441-.442.441-1.059t-.441-1.059Q12.617 5 12 5t-1.059.441T10.5 6.5t.441 1.059Q11.383 8 12 8m7.77 0q.617 0 1.058-.441q.441-.442.441-1.059t-.441-1.059Q20.387 5 19.769 5t-1.058.441q-.442.442-.442 1.059t.442 1.059Q19.152 8 19.769 8m0-1.5"/></svg></span>
          <span>Automatic</span>
        </div>
      </div>

      <!-- Features row 2 -->
      <div class="w-64 flex gap-2 items-center justify-between text-sm text-gray-700 mt-2">
        <div class="flex items-center gap-1">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M3.385 19.385v-1h17.23v1zm3.23-3q-.69 0-1.152-.463T5 14.769V8.616q0-.691.463-1.153T6.616 7h3q0-.98.701-1.683q.702-.702 1.683-.702t1.683.702T14.385 7h3q.69 0 1.153.463T19 8.616v6.153q0 .69-.462 1.153t-1.153.463zm10.077-1h.693q.269 0 .442-.174q.173-.173.173-.442V8.616q0-.27-.173-.443T17.385 8h-.693zM10.5 7h3q0-.65-.425-1.075T12 5.5t-1.075.425T10.5 7m-3.192 8.385V8h-.692q-.27 0-.443.173T6 8.616v6.153q0 .27.173.443t.443.173zM8.192 8v7.385h7.616V8zm-.884 7.385h.884zm9.384 0h-.884zm-9.384 0H6zm.884 0h7.616zm8.5 0H18z"/></svg></span>
          <span>1 Large bag</span>
        </div>
        <div class="flex items-center gap-1">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M7.616 21v-1q-.667 0-1.141-.475T6 18.386v-9.77q0-.666.475-1.14T7.615 7h2.289V4.577q0-.31.23-.54t.539-.23h2.654q.31 0 .54.23t.23.54V7h2.288q.666 0 1.14.475T18 8.615v9.77q0 .666-.475 1.14t-1.14.475v1h-1v-1h-6.77v1zm3.288-14h2.192V4.808h-2.192zM12 12.385q1.325 0 2.588-.338T17 11.035v-2.42q0-.269-.173-.442T16.385 8h-8.77q-.269 0-.442.173T7 8.616v2.419q1.15.675 2.413 1.012t2.587.338m-.5 2v-1.012q-1.184-.086-2.31-.375T7 12.169v6.216q0 .269.173.442t.443.173h8.769q.269 0 .442-.173t.173-.442v-6.216q-1.065.54-2.19.829t-2.31.375v1.012zm.5-2.216"/></svg></span>
          <span>1 Small bag</span>
        </div>
    </div>
    <div class="flex flex-wrap gap-2 text-sm text-gray-700">
        <div class="flex items-center gap-1">
        <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="15px"><path d="M9.75 15.292v-.285a2.25 2.25 0 0 1 4.5 0v.285a.75.75 0 0 0 1.5 0v-.285a3.75 3.75 0 1 0-7.5 0v.285a.75.75 0 0 0 1.5 0M13.54 5.02l-2.25 6.75a.75.75 0 0 0 1.424.474l2.25-6.75a.75.75 0 1 0-1.424-.474M6.377 6.757a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5m12.75 3.75a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5m-1.496-3.75a1.125 1.125 0 1 0 1.119 1.131v-.006c0-.621-.504-1.125-1.125-1.125a.75.75 0 0 0 0 1.5.375.375 0 0 1-.375-.375V7.88a.375.375 0 1 1 .373.377.75.75 0 1 0 .008-1.5m-8.254-3a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5M21.88 17.541a16.5 16.5 0 0 0-19.76 0 .75.75 0 1 0 .898 1.202 15 15 0 0 1 17.964 0 .75.75 0 1 0 .898-1.202m.62-5.534c0 5.799-4.701 10.5-10.5 10.5s-10.5-4.701-10.5-10.5 4.701-10.5 10.5-10.5 10.5 4.701 10.5 10.5m1.5 0c0-6.627-5.373-12-12-12s-12 5.373-12 12 5.373 12 12 12 12-5.373 12-12m-19.123-1.5a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5"></path></svg></span>
        <span>Unlimited mileage</span>
        </div>
    </div>

      <!-- Location info -->
      <div class="mt-3 space-y-1 text-sm text-gray-700">
        <div>
          <p class="font-medium">Dubai International Airport</p>
          <p class="text-gray-500 text-xs">In Terminal</p>
        </div>
        <div>
          <p class="font-medium">Dubai Emirates Tower</p>
          <p class="text-gray-500 text-xs">Downtown</p>
        </div>
      </div>
    </div>

    <!-- Price and actions -->
    <div class="flex flex-col items-end gap-2 md:gap-3 mt-[90px]">
      <div class="text-right">
        <p class="text-xs text-gray-500">Price for 3 days:</p>
        <p class="text-2xl font-bold text-gray-900">PKR 21,313</p>
        <p class="text-xs font-semibold text-green-600 mt-1">Free cancellation</p>
      </div>

      <button
        class="btn px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 transition"
      >
        View deal
      </button>
    </div>
  </div>

  <!-- Bottom row: supplier and links -->
  <div class="mt-4 pt-4 border-t flex flex-wrap items-center justify-between gap-3 text-sm">
    <div class="flex items-center gap-3">
      <span class="w-20 h-8 items-center">
        <img src="https://cdn2.rcstatic.com/sp/images/suppliers/102_logo_200.png" alt="" class="rounded-sm">
      </span>
      <div class="mt-2 flex gap-1">
        <span class="bg-blue-600 p-2 rounded-t-md rounded-br-md text-lg font-bold text-white">7.7</span>
        <div class="flex flex-col gap-">
            <span class="text-lg font-semibold text-gray-950">Good</span>
            <span class="text-xs font-semibold text-gray-600">1000 + reviews</span>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-4 text-xs text-blue-600">
      <button class="inline-flex items-center gap-1 hover:underline">
        <span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2m0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8"/></svg></span>
        <span class="text-[16px] font-semibold">Important info</span>
      </button>
      <button class="inline-flex items-center gap-1 hover:underline">
        <span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M22 6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2zm-2 0l-8 5l-8-5zm0 12H4V8l8 5l8-5z"/></svg></span>
        <span class="text-[16px] font-semibold">Email quote</span>
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
<pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-4xl mx-auto bg-white border rounded-2xl shadow-sm p-4 md:p-6">
  <!-- Top row: compare + badge -->
  <div class="flex items-center space-x-[95px] mb-4">
    <div class="inline-flex items-center gap-2 text-sm text-gray-600">
     <div class="checkbox-group">
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="basic1" class="checkbox-input">
                <div class="checkbox-custom">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
            </div>
            <label for="basic1" class="cursor-pointer">Compare</label>
        </div>
    </div>
    </div>

    <span class="inline-flex items-center rounded-lg bg-[#003b95] px-3 py-1 text-xs font-semibold text-white">
      Top Pick
    </span>
  </div>

  <!-- Main content -->
  <div class="grid gap-4 md:grid-cols-[160px,1fr,180px] items-center">
    <!-- Car image -->
    <div class="flex justify-center">
      <img
        src="https://cdn2.rcstatic.com/images/car_images_b/new_images/kia/picanto_5_door_lrg.jpg"
        alt="Kia Picanto"
        class="w-full max-w-[220px] h-auto object-contain"
      >
    </div>

    <!-- Car details -->
    <div class="space-y-2">
      <h2 class="text-lg md:text-xl font-semibold text-gray-900">
        Kia Picanto <span class="text-sm font-normal text-blue-600">or similar small car</span>
      </h2>

      <!-- Features row 1 -->
      <div class="w-64 flex gap-2 items-center justify-between text-sm text-gray-700 mt-2">
        <div class="flex items-center gap-1">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 11.385q-1.237 0-2.119-.882T9 8.385t.881-2.12T12 5.386t2.119.88t.881 2.12t-.881 2.118t-2.119.882m-7 7.23V16.97q0-.619.36-1.158q.361-.54.97-.838q1.416-.679 2.834-1.018q1.417-.34 2.836-.34t2.837.34t2.832 1.018q.61.298.97.838q.361.539.361 1.158v1.646z"/></svg></span>
          <span>4 seats</span>
        </div>
        <div class="flex items-center gap-1">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M4.252 20q-1.04 0-1.771-.73q-.731-.728-.731-1.77q0-.898.572-1.586t1.428-.854V8.94q-.856-.165-1.428-.853Q1.75 7.398 1.75 6.5q0-1.042.729-1.77Q3.207 4 4.248 4t1.771.73t.731 1.77q0 .898-.572 1.587q-.572.688-1.428.854V11.5h6.75V8.94q-.856-.165-1.428-.853Q9.5 7.398 9.5 6.5q0-1.042.729-1.77q.728-.73 1.769-.73t1.771.73t.731 1.77q0 .898-.572 1.587q-.572.688-1.428.854V11.5h6.77V8.94q-.856-.165-1.429-.853q-.572-.689-.572-1.587q0-1.042.729-1.77q.728-.73 1.769-.73t1.772.73t.73 1.77q0 .898-.572 1.587q-.572.688-1.428.854V12.5H12.5v2.56q.856.165 1.428.853q.572.689.572 1.587q0 1.042-.728 1.77q-.729.73-1.77.73t-1.771-.73T9.5 17.5q0-.898.572-1.586t1.428-.854V12.5H4.75v2.56q.856.165 1.428.853q.572.689.572 1.587q0 1.042-.728 1.77q-.729.73-1.77.73m-.002-1q.617 0 1.059-.441q.441-.442.441-1.059t-.441-1.059Q4.867 16 4.25 16t-1.059.441q-.441.442-.441 1.059t.441 1.059Q3.633 19 4.25 19m0-11q.617 0 1.059-.441q.441-.442.441-1.059t-.441-1.059Q4.867 5 4.25 5t-1.059.441Q2.75 5.883 2.75 6.5t.441 1.059Q3.633 8 4.25 8M12 19q.617 0 1.059-.441q.441-.442.441-1.059t-.441-1.059T12 16t-1.059.441T10.5 17.5t.441 1.059Q11.383 19 12 19m0-11q.617 0 1.059-.441q.441-.442.441-1.059t-.441-1.059Q12.617 5 12 5t-1.059.441T10.5 6.5t.441 1.059Q11.383 8 12 8m7.77 0q.617 0 1.058-.441q.441-.442.441-1.059t-.441-1.059Q20.387 5 19.769 5t-1.058.441q-.442.442-.442 1.059t.442 1.059Q19.152 8 19.769 8m0-1.5"/></svg></span>
          <span>Automatic</span>
        </div>
      </div>

      <!-- Features row 2 -->
      <div class="w-64 flex gap-2 items-center justify-between text-sm text-gray-700 mt-2">
        <div class="flex items-center gap-1">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M3.385 19.385v-1h17.23v1zm3.23-3q-.69 0-1.152-.463T5 14.769V8.616q0-.691.463-1.153T6.616 7h3q0-.98.701-1.683q.702-.702 1.683-.702t1.683.702T14.385 7h3q.69 0 1.153.463T19 8.616v6.153q0 .69-.462 1.153t-1.153.463zm10.077-1h.693q.269 0 .442-.174q.173-.173.173-.442V8.616q0-.27-.173-.443T17.385 8h-.693zM10.5 7h3q0-.65-.425-1.075T12 5.5t-1.075.425T10.5 7m-3.192 8.385V8h-.692q-.27 0-.443.173T6 8.616v6.153q0 .27.173.443t.443.173zM8.192 8v7.385h7.616V8zm-.884 7.385h.884zm9.384 0h-.884zm-9.384 0H6zm.884 0h7.616zm8.5 0H18z"/></svg></span>
          <span>1 Large bag</span>
        </div>
        <div class="flex items-center gap-1">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M7.616 21v-1q-.667 0-1.141-.475T6 18.386v-9.77q0-.666.475-1.14T7.615 7h2.289V4.577q0-.31.23-.54t.539-.23h2.654q.31 0 .54.23t.23.54V7h2.288q.666 0 1.14.475T18 8.615v9.77q0 .666-.475 1.14t-1.14.475v1h-1v-1h-6.77v1zm3.288-14h2.192V4.808h-2.192zM12 12.385q1.325 0 2.588-.338T17 11.035v-2.42q0-.269-.173-.442T16.385 8h-8.77q-.269 0-.442.173T7 8.616v2.419q1.15.675 2.413 1.012t2.587.338m-.5 2v-1.012q-1.184-.086-2.31-.375T7 12.169v6.216q0 .269.173.442t.443.173h8.769q.269 0 .442-.173t.173-.442v-6.216q-1.065.54-2.19.829t-2.31.375v1.012zm.5-2.216"/></svg></span>
          <span>1 Small bag</span>
        </div>
    </div>
    <div class="flex flex-wrap gap-2 text-sm text-gray-700">
        <div class="flex items-center gap-1">
        <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="15px"><path d="M9.75 15.292v-.285a2.25 2.25 0 0 1 4.5 0v.285a.75.75 0 0 0 1.5 0v-.285a3.75 3.75 0 1 0-7.5 0v.285a.75.75 0 0 0 1.5 0M13.54 5.02l-2.25 6.75a.75.75 0 0 0 1.424.474l2.25-6.75a.75.75 0 1 0-1.424-.474M6.377 6.757a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5m12.75 3.75a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5m-1.496-3.75a1.125 1.125 0 1 0 1.119 1.131v-.006c0-.621-.504-1.125-1.125-1.125a.75.75 0 0 0 0 1.5.375.375 0 0 1-.375-.375V7.88a.375.375 0 1 1 .373.377.75.75 0 1 0 .008-1.5m-8.254-3a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5M21.88 17.541a16.5 16.5 0 0 0-19.76 0 .75.75 0 1 0 .898 1.202 15 15 0 0 1 17.964 0 .75.75 0 1 0 .898-1.202m.62-5.534c0 5.799-4.701 10.5-10.5 10.5s-10.5-4.701-10.5-10.5 4.701-10.5 10.5-10.5 10.5 4.701 10.5 10.5m1.5 0c0-6.627-5.373-12-12-12s-12 5.373-12 12 5.373 12 12 12 12-5.373 12-12m-19.123-1.5a1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5"></path></svg></span>
        <span>Unlimited mileage</span>
        </div>
    </div>

      <!-- Location info -->
      <div class="mt-3 space-y-1 text-sm text-gray-700">
        <div>
          <p class="font-medium">Dubai International Airport</p>
          <p class="text-gray-500 text-xs">In Terminal</p>
        </div>
        <div>
          <p class="font-medium">Dubai Emirates Tower</p>
          <p class="text-gray-500 text-xs">Downtown</p>
        </div>
      </div>
    </div>

    <!-- Price and actions -->
    <div class="flex flex-col items-end gap-2 md:gap-3 mt-[90px]">
      <div class="text-right">
        <p class="text-xs text-gray-500">Price for 3 days:</p>
        <p class="text-2xl font-bold text-gray-900">PKR 21,313</p>
        <p class="text-xs font-semibold text-green-600 mt-1">Free cancellation</p>
      </div>

      <button
        class="btn px-5 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 transition"
      >
        View deal
      </button>
    </div>
  </div>

  <!-- Bottom row: supplier and links -->
  <div class="mt-4 pt-4 border-t flex flex-wrap items-center justify-between gap-3 text-sm">
    <div class="flex items-center gap-3">
      <span class="w-20 h-8 items-center">
        <img src="https://cdn2.rcstatic.com/sp/images/suppliers/102_logo_200.png" alt="" class="rounded-sm">
      </span>
      <div class="mt-2 flex gap-1">
        <span class="bg-blue-600 p-2 rounded-t-md rounded-br-md text-lg font-bold text-white">7.7</span>
        <div class="flex flex-col gap-">
            <span class="text-lg font-semibold text-gray-950">Good</span>
            <span class="text-xs font-semibold text-gray-600">1000 + reviews</span>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-4 text-xs text-blue-600">
      <button class="inline-flex items-center gap-1 hover:underline">
        <span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2m0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8"/></svg></span>
        <span class="text-[16px] font-semibold">Important info</span>
      </button>
      <button class="inline-flex items-center gap-1 hover:underline">
        <span><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M22 6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2zm-2 0l-8 5l-8-5zm0 12H4V8l8 5l8-5z"/></svg></span>
        <span class="text-[16px] font-semibold">Email quote</span>
      </button>
    </div>
  </div>
</div></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
<div class="max-w-sm my-6 bg-white rounded-2xl shadow-sm border overflow-hidden">
  <!-- Image -->
  <div class="relative bg-gray-50">
    <img
      src="https://cdn2.rcstatic.com/images/car_images_b/new_images/kia/picanto_5_door_lrg.jpg"
      alt="Full size car"
      class="w-full h-48 object-contain"
    >

    <!-- Supplier badge -->
       <span class="absolute bottom-3 right-3 w-[60px] h-8 items-center">
        <img src="https://cdn2.rcstatic.com/sp/images/suppliers/102_logo_200.png" alt="" class="rounded-md">
      </span>
  </div>

  <!-- Bottom content -->
  <div class="border-t px-5 py-4 flex items-end justify-between gap-4">
    <!-- Left info -->
    <div class="space-y-1">
      <div>
        <p class="text-lg font-semibold text-gray-900">Full-size</p>
        <p class="text-xs text-gray-500">4 door</p>
      </div>

      <!-- Icons row -->
      <div class="flex items-center gap-4 text-xs text-gray-600 mt-2">
        <div class="flex items-center gap-1 p-1 bg-blue-50 rounded-md">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M18.39 14.56C16.71 13.7 14.53 13 12 13s-4.71.7-6.39 1.56A2.97 2.97 0 0 0 4 17.22V20h16v-2.78c0-1.12-.61-2.15-1.61-2.66M9.78 12h4.44c1.21 0 2.14-1.06 1.98-2.26l-.32-2.45C15.57 5.39 13.92 4 12 4S8.43 5.39 8.12 7.29L7.8 9.74c-.16 1.2.77 2.26 1.98 2.26"/></svg></span>
          <span class="text-sm font-semibold">5</span>
        </div>
        <div class="flex items-center gap-1 p-1 bg-blue-50 rounded-md">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M7 21q-.825 0-1.412-.587T5 19V8q0-.825.588-1.412T7 6h2V4q0-.825.588-1.412T11 2h2q.825 0 1.413.588T15 4v2h2q.825 0 1.413.588T19 8v11q0 .825-.587 1.413T17 21q0 .425-.288.713T16 22t-.712-.288T15 21H9q0 .425-.288.713T8 22t-.712-.288T7 21m2-3h2V9H9zm4 0h2V9h-2zM11 6h2V4h-2z"/></svg></span>
          <span class="text-sm font-semibold">4</span>
        </div>
      </div>
    </div>

    <!-- Right price block -->
    <div class="text-right">
      <p class="text-sm text-gray-500">From</p>
      <p class="text-2xl font-bold text-gray-900">Rs25,514</p>
      <p class="text-sm text-gray-500">per day</p>
    </div>
  </div>
</div>

<div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxTwo', 'toggleBtnTwo')" class="btn" id="toggleBtnTwo">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxTwo">
<pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-sm my-6 bg-white rounded-2xl shadow-sm border overflow-hidden">
  <!-- Image -->
  <div class="relative bg-gray-50">
    <img
      src="https://cdn2.rcstatic.com/images/car_images_b/new_images/kia/picanto_5_door_lrg.jpg"
      alt="Full size car"
      class="w-full h-48 object-contain"
    >

    <!-- Supplier badge -->
       <span class="absolute bottom-3 right-3 w-[60px] h-8 items-center">
        <img src="https://cdn2.rcstatic.com/sp/images/suppliers/102_logo_200.png" alt="" class="rounded-md">
      </span>
  </div>

  <!-- Bottom content -->
  <div class="border-t px-5 py-4 flex items-end justify-between gap-4">
    <!-- Left info -->
    <div class="space-y-1">
      <div>
        <p class="text-lg font-semibold text-gray-900">Full-size</p>
        <p class="text-xs text-gray-500">4 door</p>
      </div>

      <!-- Icons row -->
      <div class="flex items-center gap-4 text-xs text-gray-600 mt-2">
        <div class="flex items-center gap-1 p-1 bg-blue-50 rounded-md">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M18.39 14.56C16.71 13.7 14.53 13 12 13s-4.71.7-6.39 1.56A2.97 2.97 0 0 0 4 17.22V20h16v-2.78c0-1.12-.61-2.15-1.61-2.66M9.78 12h4.44c1.21 0 2.14-1.06 1.98-2.26l-.32-2.45C15.57 5.39 13.92 4 12 4S8.43 5.39 8.12 7.29L7.8 9.74c-.16 1.2.77 2.26 1.98 2.26"/></svg></span>
          <span class="text-sm font-semibold">5</span>
        </div>
        <div class="flex items-center gap-1 p-1 bg-blue-50 rounded-md">
          <span class="text-lg"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M7 21q-.825 0-1.412-.587T5 19V8q0-.825.588-1.412T7 6h2V4q0-.825.588-1.412T11 2h2q.825 0 1.413.588T15 4v2h2q.825 0 1.413.588T19 8v11q0 .825-.587 1.413T17 21q0 .425-.288.713T16 22t-.712-.288T15 21H9q0 .425-.288.713T8 22t-.712-.288T7 21m2-3h2V9H9zm4 0h2V9h-2zM11 6h2V4h-2z"/></svg></span>
          <span class="text-sm font-semibold">4</span>
        </div>
      </div>
    </div>

    <!-- Right price block -->
    <div class="text-right">
      <p class="text-sm text-gray-500">From</p>
      <p class="text-2xl font-bold text-gray-900">Rs25,514</p>
      <p class="text-sm text-gray-500">per day</p>
    </div>
  </div>
</div></code></pre>
</div>
</div>

<div class="border-l-4 border-blue-500 pl-4">
<div class="max-w-3xl my-6 bg-white border rounded-xl px-4 py-5">
  <div class="grid grid-cols-3 sm:grid-cols-6 gap-6 text-center">

    <!-- Item -->
    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path d="M18 17a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0M6 17a.75.75 0 1 1-1.5 0A.75.75 0 0 1 6 17m1.5 0A2.25 2.25 0 1 0 3 17a2.25 2.25 0 0 0 4.5 0m8.25-.75h-9a.75.75 0 0 0 0 1.5h9a.75.75 0 0 0 0-1.5m-12 0h-1.5a.75.75 0 0 1-.75-.75V14a2.25 2.25 0 0 1 2.25-2.25.75.75 0 0 0 .67-.415l1.836-3.67a.75.75 0 0 1 .67-.415h7.901a.75.75 0 0 1 .671.415l1.83 3.67a.75.75 0 0 0 .672.415h2.25A2.25 2.25 0 0 1 22.5 14v1.5a.75.75 0 0 1-.75.75h-3a.75.75 0 0 0 0 1.5h3A2.25 2.25 0 0 0 24 15.5V14a3.75 3.75 0 0 0-3.75-3.75H18l.671.415-1.83-3.67a2.25 2.25 0 0 0-2.014-1.245h-7.9a2.25 2.25 0 0 0-2.013 1.244l-1.835 3.67.671-.414A3.75 3.75 0 0 0 0 14v1.5a2.25 2.25 0 0 0 2.25 2.25h1.5a.75.75 0 0 0 0-1.5m14.25-6H3.75a.75.75 0 0 0 0 1.5H18a.75.75 0 0 0 0-1.5"></path></svg></span>
      <span class="text-[13px] text-gray-700">Medium car</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path fill-rule="evenodd" d="M5.02 7.03a.72.72 0 0 1 .57-.28h7.25c.21 0 .41.09.55.24l2.89 3.13H2.76c.18-.27.38-.57.6-.88.58-.82 1.23-1.67 1.66-2.22Zm-2.62 4.6h18.07c.7 0 1.27.57 1.27 1.27 0 .51-.31.98-.78 1.18l-2.64 1.1c-.41-.56-1.07-.93-1.82-.93-.98 0-1.82.63-2.13 1.51-.04 0-.08-.01-.12-.01H8.12c-.31-.87-1.14-1.5-2.12-1.5-.66 0-1.25.28-1.66.73a7.05 7.05 0 0 1-1.94-3.36Zm11.97 5.61s-.08.01-.12.01H8.12c-.31.87-1.14 1.5-2.12 1.5-1.24 0-2.25-1.01-2.25-2.25v-.03l-.07-.06a8.6 8.6 0 0 1-2.93-5.44c-.02-.12 0-.24.04-.35.1-.3.31-.66.53-1.02.23-.38.52-.8.82-1.22.6-.85 1.27-1.72 1.7-2.27s1.08-.85 1.76-.85h7.25c.63 0 1.23.26 1.65.72l3.83 4.15h2.15a2.77 2.77 0 0 1 1.06 5.33l-2.8 1.16a2.256 2.256 0 0 1-4.38.61ZM6 17.25c.41 0 .75-.34.75-.75s-.34-.75-.75-.75-.75.34-.75.75.34.75.75.75m11.25-.75c0 .41-.34.75-.75.75s-.75-.34-.75-.75.34-.75.75-.75.75.34.75.75"></path></svg></span>
      <span class="text-[13px] text-gray-700">Small car</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path d="M19.468 21.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0m-15 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0m12.75.75h1.532A2.25 2.25 0 0 0 24 20.25 5.28 5.28 0 0 0 18.717 15l.713.513c-.08-.24-.238-.638-.482-1.143a13.6 13.6 0 0 0-1.513-2.455c-1.771-2.278-4.083-3.665-6.967-3.665C4.554 8.25.358 12.258.002 20.182A2.22 2.22 0 0 0 2.217 22.5h1.501a.75.75 0 0 0 0-1.5h-1.53a.72.72 0 0 1-.688-.751C1.82 13.127 5.356 9.75 10.468 9.75c2.366 0 4.273 1.144 5.783 3.085a12.2 12.2 0 0 1 1.755 3.152.75.75 0 0 0 .713.513 3.777 3.777 0 0 1 3.781 3.755.75.75 0 0 1-.75.745h-1.532a.75.75 0 0 0 0 1.5m-3-1.5h-10.5a.75.75 0 0 0 0 1.5h10.5a.75.75 0 0 0 0-1.5m1.5-6H1.484a.75.75 0 0 0 0 1.5h17.234a.75.75 0 0 0 0-1.5m-8.218.75V9.025a.75.75 0 0 0-1.5 0v6.725a.75.75 0 0 0 1.5 0m-5.25-9V5.5a.25.25 0 0 1 .25-.25H14a.25.25 0 0 1 .25.25V8a.25.25 0 0 1-.25.25H5.5A.25.25 0 0 1 5.25 8zm-1.5 0V8c0 .966.784 1.75 1.75 1.75H14A1.75 1.75 0 0 0 15.75 8V5.5A1.75 1.75 0 0 0 14 3.75H5.5A1.75 1.75 0 0 0 3.75 5.5zm3-4.125V1.75A.25.25 0 0 1 7 1.5h5.5a.25.25 0 0 1 .25.25V3.5a.25.25 0 0 1-.25.25H7a.25.25 0 0 1-.25-.25zm-1.5 0V3.5c0 .966.784 1.75 1.75 1.75h5.5a1.75 1.75 0 0 0 1.75-1.75V1.75A1.75 1.75 0 0 0 12.5 0H7a1.75 1.75 0 0 0-1.75 1.75zm6 1.875V9a.75.75 0 0 0 1.5 0V4.5a.75.75 0 0 0-1.5 0m-4.5 0V9a.75.75 0 0 0 1.5 0V4.5a.75.75 0 0 0-1.5 0"></path></svg></span>
      <span class="text-[13px] text-gray-700">Large car</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path d="M8.25 18a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m1.5 0a3 3 0 1 0-6 0 3 3 0 0 0 6 0m10.5 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m1.5 0a3 3 0 1 0-6 0 3 3 0 0 0 6 0M3.056 15.429a4.5 4.5 0 0 1 7.388 0 .75.75 0 0 0 .615.321h3.382a.75.75 0 0 0 .615-.321 4.5 4.5 0 0 1 7.388 0 .75.75 0 0 0 1.23-.858 6 6 0 0 0-9.848 0l.615-.321h-3.382V15l.616-.429a6 6 0 0 0-9.85 0 .75.75 0 1 0 1.231.858M22.5 13.69v-2.44A2.25 2.25 0 0 0 20.25 9H3.75a.75.75 0 0 0-.75.75v3.942a.75.75 0 0 0 1.5 0V9.75l-.75.75h16.5a.75.75 0 0 1 .75.75v2.441a.75.75 0 0 0 1.5 0zm-4.529-4.147-1.189-4.162A2.25 2.25 0 0 0 14.62 3.75h-7a2.25 2.25 0 0 0-1.951 1.134l-2.57 4.494a.75.75 0 1 0 1.303.744l2.57-4.494a.75.75 0 0 1 .65-.378h6.998a.75.75 0 0 1 .72.544l1.19 4.162a.75.75 0 1 0 1.442-.412zM0 7.5v5.25a.75.75 0 0 0 1.5 0V7.5a.75.75 0 0 0-1.5 0m9-3v5.25a.75.75 0 0 0 1.5 0V4.5a.75.75 0 0 0-1.5 0"></path></svg></span>
      <span class="text-[13px] text-gray-700">SUVs</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path fill-rule="evenodd" d="M0 6.75C0 4.68 1.68 3 3.75 3H15.1c1.37 0 2.63.74 3.29 1.94l3.03 5.5a.3.3 0 0 0 .21.15c1.37.23 2.38 1.41 2.38 2.8v1.6c0 1.98-1.53 3.59-3.47 3.74a3.004 3.004 0 0 1-5.82.01H7.78c-.33 1.29-1.51 2.25-2.91 2.25s-2.68-1.04-2.95-2.43A3.06 3.06 0 0 1 0 15.71zM16.12 18c0 .83.67 1.5 1.5 1.5s1.5-.67 1.5-1.5-.67-1.5-1.5-1.5-1.5.67-1.5 1.5m4.4-.77c-.34-1.29-1.51-2.23-2.9-2.23s-2.57.96-2.91 2.25H7.77A3.006 3.006 0 0 0 4.86 15c-1.28 0-2.37.8-2.8 1.93a1.58 1.58 0 0 1-.57-1.22V12h19.58c.1.03.2.06.3.08.65.11 1.12.67 1.12 1.33v1.6c0 1.15-.86 2.1-1.97 2.23ZM4.87 16.5c-.82 0-1.49.66-1.5 1.49v.02c0 .82.68 1.49 1.5 1.49s1.5-.67 1.5-1.5-.67-1.5-1.5-1.5m9.75-6h5.11l-1.65-3h-3.46zm-1.5-3v3H1.5v-3zm3.95-1.83.18.33H1.63c.31-.87 1.14-1.5 2.12-1.5H15.1c.82 0 1.58.45 1.97 1.17"></path></svg></span>
      <span class="text-[13px] text-gray-700">People carrier</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path d="M19.5 15.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0m-13.5 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0m8.25-.75h-9a.75.75 0 0 0 0 1.5h9a.75.75 0 0 0 0-1.5m-12 0h-3a.75.75 0 0 1-.75-.75v-1.5a.75.75 0 0 1 .75-.75H3c1.184 0 1.792-.398 4.312-2.414C9.168 8.1 10.22 7.5 11.25 7.5H15c.635 0 1.216.359 1.5.927l1.58 3.158a.75.75 0 0 0 .67.415h1.5a2.25 2.25 0 0 1 2.25 2.25.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 0 0 1.5h1.5A2.25 2.25 0 0 0 24 14.25a3.75 3.75 0 0 0-3.75-3.75h-1.5l.67.415-1.578-3.158A3.18 3.18 0 0 0 15 6h-3.75c-1.501 0-2.746.712-4.875 2.414C4.255 10.11 3.66 10.5 3 10.5h-.75A2.25 2.25 0 0 0 0 12.75v1.5a2.25 2.25 0 0 0 2.25 2.25h3a.75.75 0 0 0 0-1.5M3 11.25V9a.75.75 0 0 0-.415-.67l-1.5-.75a.75.75 0 0 0-.67 1.34l1.5.75L1.5 9v2.25a.75.75 0 0 0 1.5 0m15.75-.75H3A.75.75 0 0 0 3 12h15.75a.75.75 0 0 0 0-1.5"></path></svg></span>
      <span class="text-[13px] text-gray-700">Premium car</span>
    </div>

  </div>
</div>

<div class="mb-3 flex items-end justify-end text-left">
  <!-- Toggle Button -->
  <button 
   onclick="toggleCode('codeBoxThree', 'toggleBtnThree')" class="btn" id="toggleBtnThree">
    Show Code
  </button>
</div>

<!-- Collapsible Code Block -->
<div class="hidden transition-all duration-300" id="codeBoxThree">
<pre class="line-numbers language-markup"><code class="language-html"><div class="max-w-3xl my-6 bg-white border rounded-xl px-4 py-5">
  <div class="grid grid-cols-3 sm:grid-cols-6 gap-6 text-center">

    <!-- Item -->
    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path d="M18 17a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0M6 17a.75.75 0 1 1-1.5 0A.75.75 0 0 1 6 17m1.5 0A2.25 2.25 0 1 0 3 17a2.25 2.25 0 0 0 4.5 0m8.25-.75h-9a.75.75 0 0 0 0 1.5h9a.75.75 0 0 0 0-1.5m-12 0h-1.5a.75.75 0 0 1-.75-.75V14a2.25 2.25 0 0 1 2.25-2.25.75.75 0 0 0 .67-.415l1.836-3.67a.75.75 0 0 1 .67-.415h7.901a.75.75 0 0 1 .671.415l1.83 3.67a.75.75 0 0 0 .672.415h2.25A2.25 2.25 0 0 1 22.5 14v1.5a.75.75 0 0 1-.75.75h-3a.75.75 0 0 0 0 1.5h3A2.25 2.25 0 0 0 24 15.5V14a3.75 3.75 0 0 0-3.75-3.75H18l.671.415-1.83-3.67a2.25 2.25 0 0 0-2.014-1.245h-7.9a2.25 2.25 0 0 0-2.013 1.244l-1.835 3.67.671-.414A3.75 3.75 0 0 0 0 14v1.5a2.25 2.25 0 0 0 2.25 2.25h1.5a.75.75 0 0 0 0-1.5m14.25-6H3.75a.75.75 0 0 0 0 1.5H18a.75.75 0 0 0 0-1.5"></path></svg></span>
      <span class="text-[13px] text-gray-700">Medium car</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path fill-rule="evenodd" d="M5.02 7.03a.72.72 0 0 1 .57-.28h7.25c.21 0 .41.09.55.24l2.89 3.13H2.76c.18-.27.38-.57.6-.88.58-.82 1.23-1.67 1.66-2.22Zm-2.62 4.6h18.07c.7 0 1.27.57 1.27 1.27 0 .51-.31.98-.78 1.18l-2.64 1.1c-.41-.56-1.07-.93-1.82-.93-.98 0-1.82.63-2.13 1.51-.04 0-.08-.01-.12-.01H8.12c-.31-.87-1.14-1.5-2.12-1.5-.66 0-1.25.28-1.66.73a7.05 7.05 0 0 1-1.94-3.36Zm11.97 5.61s-.08.01-.12.01H8.12c-.31.87-1.14 1.5-2.12 1.5-1.24 0-2.25-1.01-2.25-2.25v-.03l-.07-.06a8.6 8.6 0 0 1-2.93-5.44c-.02-.12 0-.24.04-.35.1-.3.31-.66.53-1.02.23-.38.52-.8.82-1.22.6-.85 1.27-1.72 1.7-2.27s1.08-.85 1.76-.85h7.25c.63 0 1.23.26 1.65.72l3.83 4.15h2.15a2.77 2.77 0 0 1 1.06 5.33l-2.8 1.16a2.256 2.256 0 0 1-4.38.61ZM6 17.25c.41 0 .75-.34.75-.75s-.34-.75-.75-.75-.75.34-.75.75.34.75.75.75m11.25-.75c0 .41-.34.75-.75.75s-.75-.34-.75-.75.34-.75.75-.75.75.34.75.75"></path></svg></span>
      <span class="text-[13px] text-gray-700">Small car</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path d="M19.468 21.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0m-15 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0m12.75.75h1.532A2.25 2.25 0 0 0 24 20.25 5.28 5.28 0 0 0 18.717 15l.713.513c-.08-.24-.238-.638-.482-1.143a13.6 13.6 0 0 0-1.513-2.455c-1.771-2.278-4.083-3.665-6.967-3.665C4.554 8.25.358 12.258.002 20.182A2.22 2.22 0 0 0 2.217 22.5h1.501a.75.75 0 0 0 0-1.5h-1.53a.72.72 0 0 1-.688-.751C1.82 13.127 5.356 9.75 10.468 9.75c2.366 0 4.273 1.144 5.783 3.085a12.2 12.2 0 0 1 1.755 3.152.75.75 0 0 0 .713.513 3.777 3.777 0 0 1 3.781 3.755.75.75 0 0 1-.75.745h-1.532a.75.75 0 0 0 0 1.5m-3-1.5h-10.5a.75.75 0 0 0 0 1.5h10.5a.75.75 0 0 0 0-1.5m1.5-6H1.484a.75.75 0 0 0 0 1.5h17.234a.75.75 0 0 0 0-1.5m-8.218.75V9.025a.75.75 0 0 0-1.5 0v6.725a.75.75 0 0 0 1.5 0m-5.25-9V5.5a.25.25 0 0 1 .25-.25H14a.25.25 0 0 1 .25.25V8a.25.25 0 0 1-.25.25H5.5A.25.25 0 0 1 5.25 8zm-1.5 0V8c0 .966.784 1.75 1.75 1.75H14A1.75 1.75 0 0 0 15.75 8V5.5A1.75 1.75 0 0 0 14 3.75H5.5A1.75 1.75 0 0 0 3.75 5.5zm3-4.125V1.75A.25.25 0 0 1 7 1.5h5.5a.25.25 0 0 1 .25.25V3.5a.25.25 0 0 1-.25.25H7a.25.25 0 0 1-.25-.25zm-1.5 0V3.5c0 .966.784 1.75 1.75 1.75h5.5a1.75 1.75 0 0 0 1.75-1.75V1.75A1.75 1.75 0 0 0 12.5 0H7a1.75 1.75 0 0 0-1.75 1.75zm6 1.875V9a.75.75 0 0 0 1.5 0V4.5a.75.75 0 0 0-1.5 0m-4.5 0V9a.75.75 0 0 0 1.5 0V4.5a.75.75 0 0 0-1.5 0"></path></svg></span>
      <span class="text-[13px] text-gray-700">Large car</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path d="M8.25 18a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m1.5 0a3 3 0 1 0-6 0 3 3 0 0 0 6 0m10.5 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0m1.5 0a3 3 0 1 0-6 0 3 3 0 0 0 6 0M3.056 15.429a4.5 4.5 0 0 1 7.388 0 .75.75 0 0 0 .615.321h3.382a.75.75 0 0 0 .615-.321 4.5 4.5 0 0 1 7.388 0 .75.75 0 0 0 1.23-.858 6 6 0 0 0-9.848 0l.615-.321h-3.382V15l.616-.429a6 6 0 0 0-9.85 0 .75.75 0 1 0 1.231.858M22.5 13.69v-2.44A2.25 2.25 0 0 0 20.25 9H3.75a.75.75 0 0 0-.75.75v3.942a.75.75 0 0 0 1.5 0V9.75l-.75.75h16.5a.75.75 0 0 1 .75.75v2.441a.75.75 0 0 0 1.5 0zm-4.529-4.147-1.189-4.162A2.25 2.25 0 0 0 14.62 3.75h-7a2.25 2.25 0 0 0-1.951 1.134l-2.57 4.494a.75.75 0 1 0 1.303.744l2.57-4.494a.75.75 0 0 1 .65-.378h6.998a.75.75 0 0 1 .72.544l1.19 4.162a.75.75 0 1 0 1.442-.412zM0 7.5v5.25a.75.75 0 0 0 1.5 0V7.5a.75.75 0 0 0-1.5 0m9-3v5.25a.75.75 0 0 0 1.5 0V4.5a.75.75 0 0 0-1.5 0"></path></svg></span>
      <span class="text-[13px] text-gray-700">SUVs</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path fill-rule="evenodd" d="M0 6.75C0 4.68 1.68 3 3.75 3H15.1c1.37 0 2.63.74 3.29 1.94l3.03 5.5a.3.3 0 0 0 .21.15c1.37.23 2.38 1.41 2.38 2.8v1.6c0 1.98-1.53 3.59-3.47 3.74a3.004 3.004 0 0 1-5.82.01H7.78c-.33 1.29-1.51 2.25-2.91 2.25s-2.68-1.04-2.95-2.43A3.06 3.06 0 0 1 0 15.71zM16.12 18c0 .83.67 1.5 1.5 1.5s1.5-.67 1.5-1.5-.67-1.5-1.5-1.5-1.5.67-1.5 1.5m4.4-.77c-.34-1.29-1.51-2.23-2.9-2.23s-2.57.96-2.91 2.25H7.77A3.006 3.006 0 0 0 4.86 15c-1.28 0-2.37.8-2.8 1.93a1.58 1.58 0 0 1-.57-1.22V12h19.58c.1.03.2.06.3.08.65.11 1.12.67 1.12 1.33v1.6c0 1.15-.86 2.1-1.97 2.23ZM4.87 16.5c-.82 0-1.49.66-1.5 1.49v.02c0 .82.68 1.49 1.5 1.49s1.5-.67 1.5-1.5-.67-1.5-1.5-1.5m9.75-6h5.11l-1.65-3h-3.46zm-1.5-3v3H1.5v-3zm3.95-1.83.18.33H1.63c.31-.87 1.14-1.5 2.12-1.5H15.1c.82 0 1.58.45 1.97 1.17"></path></svg></span>
      <span class="text-[13px] text-gray-700">People carrier</span>
    </div>

    <div class="flex flex-col items-center gap-2 cursor-pointer hover:text-blue-600 transition">
      <span class="text-2xl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="25px"><path d="M19.5 15.75a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0m-13.5 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0m1.5 0a2.25 2.25 0 1 0-4.5 0 2.25 2.25 0 0 0 4.5 0m8.25-.75h-9a.75.75 0 0 0 0 1.5h9a.75.75 0 0 0 0-1.5m-12 0h-3a.75.75 0 0 1-.75-.75v-1.5a.75.75 0 0 1 .75-.75H3c1.184 0 1.792-.398 4.312-2.414C9.168 8.1 10.22 7.5 11.25 7.5H15c.635 0 1.216.359 1.5.927l1.58 3.158a.75.75 0 0 0 .67.415h1.5a2.25 2.25 0 0 1 2.25 2.25.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 0 0 1.5h1.5A2.25 2.25 0 0 0 24 14.25a3.75 3.75 0 0 0-3.75-3.75h-1.5l.67.415-1.578-3.158A3.18 3.18 0 0 0 15 6h-3.75c-1.501 0-2.746.712-4.875 2.414C4.255 10.11 3.66 10.5 3 10.5h-.75A2.25 2.25 0 0 0 0 12.75v1.5a2.25 2.25 0 0 0 2.25 2.25h3a.75.75 0 0 0 0-1.5M3 11.25V9a.75.75 0 0 0-.415-.67l-1.5-.75a.75.75 0 0 0-.67 1.34l1.5.75L1.5 9v2.25a.75.75 0 0 0 1.5 0m15.75-.75H3A.75.75 0 0 0 3 12h15.75a.75.75 0 0 0 0-1.5"></path></svg></span>
      <span class="text-[13px] text-gray-700">Premium car</span>
    </div>

  </div>
</div></code></pre>
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
</style>