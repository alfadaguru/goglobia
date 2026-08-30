<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Datepicker Components</h1>
                    <p class="text-gray-600 mb-6">Interactive date picker components with single and range selection options.</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Single Date Picker -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>
                                    <i class="material-symbols-outlined">calendar_today</i> Single Date Picker
                                </label>
                                <input type="text" class="input dp" placeholder="Select date" readonly>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>
        <i class="material-symbols-outlined">calendar_today</i> Single Date Picker
    </label>
    <input type="text" class="input dp" placeholder="Select date" readonly>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Check-in Date -->


                        <!-- Check-out Date -->

                        <!-- Date Range Picker -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div class="form-control">
                                    <label>
                                        <i class="material-symbols-outlined">calendar_today</i> From Date
                                    </label>
                                    <input type="text" class="input HotelCheckin" placeholder="Start date" readonly>
                                </div>
                                <div class="form-control">
                                    <label>
                                        <i class="material-symbols-outlined">calendar_today</i> To Date
                                    </label>
                                    <input type="text" class="input HotelCheckout" placeholder="End date" readonly>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="form-control">
        <label>
            <i class="material-symbols-outlined">calendar_today</i> From Date
        </label>
        <input type="text" class="input HotelCheckin" placeholder="Start date" readonly>
    </div>
    <div class="form-control">
        <label>
            <i class="material-symbols-outlined">calendar_today</i> To Date
        </label>
        <input type="text" class="input HotelCheckout" placeholder="End date" readonly>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
[x-cloak] { display: none !important; }
</style>