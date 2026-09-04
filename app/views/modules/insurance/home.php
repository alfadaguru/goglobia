<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Flight-compensation-claim form (AirHelp). Free to the customer.
$csrf = class_exists('CSRF') ? CSRF::getToken() : '';
$countries = $GLOBALS['countries'] ?? [];
?>
<section class="max-w-3xl mx-auto px-4 py-10">
  <div class="text-center mb-8">
    <span class="material-symbols-outlined text-5xl text-green-600">health_and_safety</span>
    <h1 class="text-3xl font-bold mt-2">Flight Compensation Claim</h1>
    <p class="text-gray-600 mt-2">
      Delayed, cancelled or overbooked flight? You may be entitled to up to
      <strong>€600</strong> in compensation. Checking is <strong>free</strong> — submit your flight
      and we'll register your claim with AirHelp.
    </p>
  </div>

  <form id="claimForm" class="bg-white rounded-xl shadow p-6 space-y-6 border border-gray-100">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div>
      <h2 class="text-lg font-semibold mb-3 flex items-center gap-2">
        <span class="material-symbols-outlined text-green-600">flight</span> Flight details
      </h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Airline code (IATA) *</label>
          <input name="airline_code" maxlength="3" required placeholder="e.g. EK"
                 class="mt-1 w-full border rounded-lg px-3 py-2 uppercase">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Flight number *</label>
          <input name="flight_number" required placeholder="e.g. EK623"
                 class="mt-1 w-full border rounded-lg px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Departure airport (IATA) *</label>
          <input name="departure_airport" maxlength="3" required placeholder="e.g. LHE"
                 class="mt-1 w-full border rounded-lg px-3 py-2 uppercase">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Arrival airport (IATA) *</label>
          <input name="arrival_airport" maxlength="3" required placeholder="e.g. DXB"
                 class="mt-1 w-full border rounded-lg px-3 py-2 uppercase">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Departure date &amp; time *</label>
          <input name="departure_datetime" type="datetime-local" required
                 class="mt-1 w-full border rounded-lg px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Arrival date &amp; time</label>
          <input name="arrival_datetime" type="datetime-local"
                 class="mt-1 w-full border rounded-lg px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Booking reference / PNR</label>
          <input name="pnr" placeholder="e.g. ABC123"
                 class="mt-1 w-full border rounded-lg px-3 py-2 uppercase">
        </div>
      </div>
    </div>

    <div>
      <h2 class="text-lg font-semibold mb-3 flex items-center gap-2">
        <span class="material-symbols-outlined text-green-600">person</span> Your details
      </h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">First name *</label>
          <input name="first_name" required class="mt-1 w-full border rounded-lg px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Last name *</label>
          <input name="last_name" required class="mt-1 w-full border rounded-lg px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Email *</label>
          <input name="email" type="email" required class="mt-1 w-full border rounded-lg px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Phone</label>
          <input name="phone" class="mt-1 w-full border rounded-lg px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Country (ISO code)</label>
          <input name="country" maxlength="2" placeholder="e.g. GB"
                 class="mt-1 w-full border rounded-lg px-3 py-2 uppercase">
        </div>
      </div>
    </div>

    <div id="claimMsg" class="hidden rounded-lg px-4 py-3 text-sm"></div>

    <button type="submit" id="claimSubmit"
            class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 rounded-lg transition">
      Submit my claim — it's free
    </button>
    <p class="text-xs text-gray-400 text-center">
      No win, no fee. We register your claim with AirHelp, which pursues the airline on your behalf.
    </p>
  </form>
</section>

<script>
(function () {
  var form = document.getElementById('claimForm');
  var btn  = document.getElementById('claimSubmit');
  var msg  = document.getElementById('claimMsg');
  if (!form) return;

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    btn.disabled = true; btn.textContent = 'Submitting…';
    msg.className = 'hidden';

    var data = {};
    new FormData(form).forEach(function (v, k) { data[k] = v; });

    fetch('<?= root ?>insurance/claim/submit', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': data.csrf_token || '' },
      body: JSON.stringify(data)
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (res.status) {
        window.location.href = res.redirect;
      } else {
        msg.className = 'rounded-lg px-4 py-3 text-sm bg-red-50 text-red-700';
        msg.textContent = res.message || 'Something went wrong. Please try again.';
        btn.disabled = false; btn.textContent = "Submit my claim — it's free";
      }
    })
    .catch(function () {
      msg.className = 'rounded-lg px-4 py-3 text-sm bg-red-50 text-red-700';
      msg.textContent = 'Network error. Please try again.';
      btn.disabled = false; btn.textContent = "Submit my claim — it's free";
    });
  });
})();
</script>
