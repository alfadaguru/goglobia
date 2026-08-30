<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Radio Components</h1>
                    <p class="text-gray-600 mb-6">Radio button components with custom styling, grouping, and interactive functionality.</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Basic Radio Group -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Basic Radio Group</label>
                                <div class="radio-group">
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="basic1" name="basic" class="radio-input" value="option1">
                                            <div class="radio-custom">
                                                <div class="radio-dot"></div>
                                            </div>
                                        </div>
                                        <label for="basic1" class="cursor-pointer">Option 1</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="basic2" name="basic" class="radio-input" value="option2" checked>
                                            <div class="radio-custom">
                                                <div class="radio-dot"></div>
                                            </div>
                                        </div>
                                        <label for="basic2" class="cursor-pointer">Option 2</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="basic3" name="basic" class="radio-input" value="option3">
                                            <div class="radio-custom">
                                                <div class="radio-dot"></div>
                                            </div>
                                        </div>
                                        <label for="basic3" class="cursor-pointer">Option 3</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Basic Radio Group</label>
    <div class="radio-group">
        <div class="radio-item">
            <div class="radio-container">
                <input type="radio" id="basic1" name="basic" class="radio-input" value="option1">
                <div class="radio-custom">
                    <div class="radio-dot"></div>
                </div>
            </div>
            <label for="basic1" class="cursor-pointer">Option 1</label>
        </div>
        <div class="radio-item">
            <div class="radio-container">
                <input type="radio" id="basic2" name="basic" class="radio-input" value="option2" checked>
                <div class="radio-custom">
                    <div class="radio-dot"></div>
                </div>
            </div>
            <label for="basic2" class="cursor-pointer">Option 2</label>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Colored Radio Buttons -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Colored Radio Buttons</label>
                                <div class="radio-group">
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="color1" name="colors" class="radio-input" value="green" checked>
                                            <div class="radio-custom border-green-500">
                                                <div class="radio-dot !bg-green-500"></div>
                                            </div>
                                        </div>
                                        <label for="color1" class="cursor-pointer">Green</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="color2" name="colors" class="radio-input" value="red">
                                            <div class="radio-custom border-red-500">
                                                <div class="radio-dot !bg-red-500"></div>
                                            </div>
                                        </div>
                                        <label for="color2" class="cursor-pointer">Red</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="color3" name="colors" class="radio-input" value="purple">
                                            <div class="radio-custom border-purple-500">
                                                <div class="radio-dot !bg-purple-500"></div>
                                            </div>
                                        </div>
                                        <label for="color3" class="cursor-pointer">Purple</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="color4" name="colors" class="radio-input" value="orange">
                                            <div class="radio-custom border-orange-500">
                                                <div class="radio-dot !bg-orange-500"></div>
                                            </div>
                                        </div>
                                        <label for="color4" class="cursor-pointer">Orange</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Colored Radio Buttons</label>
    <div class="radio-group">
        <div class="radio-item">
            <div class="radio-container">
                <input type="radio" id="color1" name="colors" class="radio-input" value="green" checked>
                <div class="radio-custom border-green-500">
                    <div class="radio-dot !bg-green-500"></div>
                </div>
            </div>
            <label for="color1" class="cursor-pointer">Green</label>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Radio Sizes -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Radio Button Sizes</label>
                                <div class="radio-group">
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="size1" name="sizes" class="radio-input" value="small" checked>
                                            <div class="radio-custom !w-3 !h-3">
                                                <div class="radio-dot !w-1 !h-1"></div>
                                            </div>
                                        </div>
                                        <label for="size1" class="cursor-pointer text-sm">Small radio</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="size2" name="sizes" class="radio-input" value="default">
                                            <div class="radio-custom">
                                                <div class="radio-dot"></div>
                                            </div>
                                        </div>
                                        <label for="size2" class="cursor-pointer">Default radio</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="size3" name="sizes" class="radio-input" value="large">
                                            <div class="radio-custom !w-5 !h-5">
                                                <div class="radio-dot !w-3 !h-3"></div>
                                            </div>
                                        </div>
                                        <label for="size3" class="cursor-pointer text-lg">Large radio</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Radio Button Sizes</label>
    <div class="radio-group">
        <!-- Small -->
        <div class="radio-item">
            <div class="radio-container">
                <input type="radio" id="size1" name="sizes" class="radio-input" value="small" checked>
                <div class="radio-custom !w-3 !h-3">
                    <div class="radio-dot !w-1 !h-1"></div>
                </div>
            </div>
            <label for="size1" class="cursor-pointer text-sm">Small radio</label>
        </div>
        <!-- Large -->
        <div class="radio-item">
            <div class="radio-container">
                <input type="radio" id="size3" name="sizes" class="radio-input" value="large">
                <div class="radio-custom !w-5 !h-5">
                    <div class="radio-dot !w-3 !h-3"></div>
                </div>
            </div>
            <label for="size3" class="cursor-pointer text-lg">Large radio</label>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Interactive Payment Method Selection -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Payment Method Selection</label>
                                <div x-data="{ selectedPayment: 'card' }">
                                    <div class="space-y-3">
                                        <!-- Credit/Debit Card -->
                                        <div class="radio-item">
                                            <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                                                 :class="selectedPayment === 'card' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                                                 @click="selectedPayment = 'card'">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center">
                                                        <div class="radio-container mr-4">
                                                            <input type="radio" id="payment1" name="payment" class="radio-input" value="card" x-model="selectedPayment">
                                                            <div class="radio-custom" :class="selectedPayment === 'card' ? 'border-blue-500' : 'border-gray-300'">
                                                                <div class="radio-dot" :class="selectedPayment === 'card' ? '!bg-blue-500' : ''"></div>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <label for="payment1" class="cursor-pointer font-semibold text-gray-900 text-base">Credit/Debit Card</label>
                                                            <p class="text-sm text-gray-600 mt-1">Pay securely with your card</p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <span class="material-symbols-outlined text-2xl text-gray-400">credit_card</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- PayPal -->
                                        <div class="radio-item">
                                            <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                                                 :class="selectedPayment === 'paypal' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                                                 @click="selectedPayment = 'paypal'">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center">
                                                        <div class="radio-container mr-4">
                                                            <input type="radio" id="payment2" name="payment" class="radio-input" value="paypal" x-model="selectedPayment">
                                                            <div class="radio-custom" :class="selectedPayment === 'paypal' ? 'border-blue-500' : 'border-gray-300'">
                                                                <div class="radio-dot" :class="selectedPayment === 'paypal' ? '!bg-blue-500' : ''"></div>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <label for="payment2" class="cursor-pointer font-semibold text-gray-900 text-base">PayPal</label>
                                                            <p class="text-sm text-gray-600 mt-1">Pay with your PayPal account</p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <span class="material-symbols-outlined text-2xl text-blue-600">account_balance_wallet</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Bank Transfer -->
                                        <div class="radio-item">
                                            <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                                                 :class="selectedPayment === 'bank' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                                                 @click="selectedPayment = 'bank'">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center">
                                                        <div class="radio-container mr-4">
                                                            <input type="radio" id="payment3" name="payment" class="radio-input" value="bank" x-model="selectedPayment">
                                                            <div class="radio-custom" :class="selectedPayment === 'bank' ? 'border-blue-500' : 'border-gray-300'">
                                                                <div class="radio-dot" :class="selectedPayment === 'bank' ? '!bg-blue-500' : ''"></div>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <label for="payment3" class="cursor-pointer font-semibold text-gray-900 text-base">Bank Transfer</label>
                                                            <p class="text-sm text-gray-600 mt-1">Direct bank account transfer</p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <span class="material-symbols-outlined text-2xl text-green-600">account_balance</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Crypto Payment -->
                                        <div class="radio-item">
                                            <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                                                 :class="selectedPayment === 'crypto' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                                                 @click="selectedPayment = 'crypto'">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center">
                                                        <div class="radio-container mr-4">
                                                            <input type="radio" id="payment4" name="payment" class="radio-input" value="crypto" x-model="selectedPayment">
                                                            <div class="radio-custom" :class="selectedPayment === 'crypto' ? 'border-blue-500' : 'border-gray-300'">
                                                                <div class="radio-dot" :class="selectedPayment === 'crypto' ? '!bg-blue-500' : ''"></div>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <label for="payment4" class="cursor-pointer font-semibold text-gray-900 text-base">Cryptocurrency</label>
                                                            <p class="text-sm text-gray-600 mt-1">Pay with Bitcoin, Ethereum, or other crypto</p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <span class="material-symbols-outlined text-2xl text-orange-500">currency_bitcoin</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-6 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <div class="text-sm font-medium text-blue-900">Selected Payment Method:</div>
                                                <div class="text-lg font-semibold text-blue-800" x-text="selectedPayment === 'card' ? 'Credit/Debit Card' : selectedPayment === 'paypal' ? 'PayPal' : selectedPayment === 'bank' ? 'Bank Transfer' : 'Cryptocurrency'"></div>
                                            </div>
                                            <div class="flex items-center">
                                                <span class="material-symbols-outlined text-blue-600 mr-2">check_circle</span>
                                                <span class="text-sm text-blue-700">Ready to proceed</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Payment Method Selection</label>
    <div x-data="{ selectedPayment: 'card' }">
        <div class="space-y-3">
            <div class="radio-item">
                <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                     :class="selectedPayment === 'card' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                     @click="selectedPayment = 'card'">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="radio-container mr-4">
                                <input type="radio" id="payment1" name="payment" class="radio-input" value="card" x-model="selectedPayment">
                                <div class="radio-custom" :class="selectedPayment === 'card' ? 'border-blue-500' : 'border-gray-300'">
                                    <div class="radio-dot" :class="selectedPayment === 'card' ? '!bg-blue-500' : ''"></div>
                                </div>
                            </div>
                            <div>
                                <label for="payment1" class="cursor-pointer font-semibold text-gray-900 text-base">Credit/Debit Card</label>
                                <p class="text-sm text-gray-600 mt-1">Pay securely with your card</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="material-symbols-outlined text-2xl text-gray-400">credit_card</span>
                            <span class="material-symbols-outlined text-xl text-gray-400">security</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Alternative Payment Selection (Icons on Left) -->
                        <div class="border-l-4 border-indigo-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Payment Method Selection - Variant 2 (Icons Left)</label>
                                <div x-data="{ selectedPayment2: 'visa' }">
                                    <div class="space-y-3">
                                        <!-- Visa -->
                                        <div class="radio-item">
                                            <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                                                 :class="selectedPayment2 === 'visa' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                                                 @click="selectedPayment2 = 'visa'">
                                                <div class="flex items-center">
                                                    <div class="flex items-center space-x-2 mr-4">
                                                        <span class="material-symbols-outlined text-2xl text-blue-600">credit_card</span>
                                                    </div>
                                                    <div class="flex-1">
                                                        <div class="flex items-center">
                                                            <div class="radio-container mr-4">
                                                                <input type="radio" id="payment2_1" name="payment2" class="radio-input" value="visa" x-model="selectedPayment2">
                                                                <div class="radio-custom" :class="selectedPayment2 === 'visa' ? 'border-blue-500' : 'border-gray-300'">
                                                                    <div class="radio-dot" :class="selectedPayment2 === 'visa' ? '!bg-blue-500' : ''"></div>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label for="payment2_1" class="cursor-pointer font-semibold text-gray-900 text-base">Visa Card</label>
                                                                <p class="text-sm text-gray-600 mt-1">Worldwide accepted credit card</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Mastercard -->
                                        <div class="radio-item">
                                            <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                                                 :class="selectedPayment2 === 'mastercard' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                                                 @click="selectedPayment2 = 'mastercard'">
                                                <div class="flex items-center">
                                                    <div class="flex items-center space-x-2 mr-4">
                                                        <span class="material-symbols-outlined text-2xl text-red-600">credit_card</span>
                                                    </div>
                                                    <div class="flex-1">
                                                        <div class="flex items-center">
                                                            <div class="radio-container mr-4">
                                                                <input type="radio" id="payment2_2" name="payment2" class="radio-input" value="mastercard" x-model="selectedPayment2">
                                                                <div class="radio-custom" :class="selectedPayment2 === 'mastercard' ? 'border-blue-500' : 'border-gray-300'">
                                                                    <div class="radio-dot" :class="selectedPayment2 === 'mastercard' ? '!bg-blue-500' : ''"></div>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label for="payment2_2" class="cursor-pointer font-semibold text-gray-900 text-base">Mastercard</label>
                                                                <p class="text-sm text-gray-600 mt-1">Secure and reliable payment method</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- American Express -->
                                        <div class="radio-item">
                                            <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                                                 :class="selectedPayment2 === 'amex' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                                                 @click="selectedPayment2 = 'amex'">
                                                <div class="flex items-center">
                                                    <div class="flex items-center space-x-2 mr-4">
                                                        <span class="material-symbols-outlined text-2xl text-green-600">credit_card</span>
                                                        <span class="material-symbols-outlined text-xl text-green-600">star</span>
                                                    </div>
                                                    <div class="flex-1">
                                                        <div class="flex items-center">
                                                            <div class="radio-container mr-4">
                                                                <input type="radio" id="payment2_3" name="payment2" class="radio-input" value="amex" x-model="selectedPayment2">
                                                                <div class="radio-custom" :class="selectedPayment2 === 'amex' ? 'border-blue-500' : 'border-gray-300'">
                                                                    <div class="radio-dot" :class="selectedPayment2 === 'amex' ? '!bg-blue-500' : ''"></div>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label for="payment2_3" class="cursor-pointer font-semibold text-gray-900 text-base">American Express</label>
                                                                <p class="text-sm text-gray-600 mt-1">Premium card with extra benefits</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-6 p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-lg border border-indigo-200">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <div class="text-sm font-medium text-indigo-900">Selected Card Type:</div>
                                                <div class="text-lg font-semibold text-indigo-800" x-text="selectedPayment2 === 'visa' ? 'Visa Card' : selectedPayment2 === 'mastercard' ? 'Mastercard' : 'American Express'"></div>
                                            </div>
                                            <div class="flex items-center">
                                                <span class="material-symbols-outlined text-indigo-600 mr-2">payment</span>
                                                <span class="text-sm text-indigo-700">Secure checkout</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Payment Method Selection - Variant 2 (Icons Left)</label>
    <div x-data="{ selectedPayment2: 'visa' }">
        <div class="space-y-3">
            <div class="radio-item">
                <div class="w-full border rounded-lg p-4 transition-all duration-200 cursor-pointer hover:shadow-md" 
                     :class="selectedPayment2 === 'visa' ? 'border-blue-500 bg-blue-50 shadow-sm ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300 bg-white'"
                     @click="selectedPayment2 = 'visa'">
                    <div class="flex items-center">
                        <div class="flex items-center space-x-2 mr-4">
                            <span class="material-symbols-outlined text-2xl text-blue-600">credit_card</span>
                            <span class="material-symbols-outlined text-xl text-blue-600">verified</span>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center">
                                <div class="radio-container mr-4">
                                    <input type="radio" id="payment2_1" name="payment2" class="radio-input" value="visa" x-model="selectedPayment2">
                                    <div class="radio-custom" :class="selectedPayment2 === 'visa' ? 'border-blue-500' : 'border-gray-300'">
                                        <div class="radio-dot" :class="selectedPayment2 === 'visa' ? '!bg-blue-500' : ''"></div>
                                    </div>
                                </div>
                                <div>
                                    <label for="payment2_1" class="cursor-pointer font-semibold text-gray-900 text-base">Visa Card</label>
                                    <p class="text-sm text-gray-600 mt-1">Worldwide accepted credit card</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Rating/Survey Radio -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Rating Survey</label>
                                <div x-data="{ rating: '4' }">
                                    <div class="space-y-4">
                                        <div>
                                            <h3 class="font-medium text-gray-900 mb-3">How satisfied are you with our service?</h3>
                                            <div class="flex justify-between">
                                                <div class="radio-item flex-col items-center">
                                                    <div class="radio-container mb-2">
                                                        <input type="radio" id="rating1" name="rating" class="radio-input" value="1" x-model="rating">
                                                        <div class="radio-custom border-red-500">
                                                            <div class="radio-dot !bg-red-500"></div>
                                                        </div>
                                                    </div>
                                                    <label for="rating1" class="cursor-pointer text-sm text-center">
                                                        <div class="text-2xl mb-1">😞</div>
                                                        <div>Very Poor</div>
                                                    </label>
                                                </div>
                                                
                                                <div class="radio-item flex-col items-center">
                                                    <div class="radio-container mb-2">
                                                        <input type="radio" id="rating2" name="rating" class="radio-input" value="2" x-model="rating">
                                                        <div class="radio-custom border-orange-500">
                                                            <div class="radio-dot !bg-orange-500"></div>
                                                        </div>
                                                    </div>
                                                    <label for="rating2" class="cursor-pointer text-sm text-center">
                                                        <div class="text-2xl mb-1">😐</div>
                                                        <div>Poor</div>
                                                    </label>
                                                </div>
                                                
                                                <div class="radio-item flex-col items-center">
                                                    <div class="radio-container mb-2">
                                                        <input type="radio" id="rating3" name="rating" class="radio-input" value="3" x-model="rating">
                                                        <div class="radio-custom border-yellow-500">
                                                            <div class="radio-dot !bg-yellow-500"></div>
                                                        </div>
                                                    </div>
                                                    <label for="rating3" class="cursor-pointer text-sm text-center">
                                                        <div class="text-2xl mb-1">🙂</div>
                                                        <div>Fair</div>
                                                    </label>
                                                </div>
                                                
                                                <div class="radio-item flex-col items-center">
                                                    <div class="radio-container mb-2">
                                                        <input type="radio" id="rating4" name="rating" class="radio-input" value="4" x-model="rating">
                                                        <div class="radio-custom border-green-500">
                                                            <div class="radio-dot !bg-green-500"></div>
                                                        </div>
                                                    </div>
                                                    <label for="rating4" class="cursor-pointer text-sm text-center">
                                                        <div class="text-2xl mb-1">😊</div>
                                                        <div>Good</div>
                                                    </label>
                                                </div>
                                                
                                                <div class="radio-item flex-col items-center">
                                                    <div class="radio-container mb-2">
                                                        <input type="radio" id="rating5" name="rating" class="radio-input" value="5" x-model="rating">
                                                        <div class="radio-custom border-blue-500">
                                                            <div class="radio-dot !bg-blue-500"></div>
                                                        </div>
                                                    </div>
                                                    <label for="rating5" class="cursor-pointer text-sm text-center">
                                                        <div class="text-2xl mb-1">🤩</div>
                                                        <div>Excellent</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center p-3 bg-gray-50 rounded-lg">
                                            <span class="text-sm font-medium">Your rating: </span>
                                            <span class="text-sm" x-text="rating + '/5'"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Rating Survey</label>
    <div x-data="{ rating: '4' }">
        <h3 class="font-medium text-gray-900 mb-3">How satisfied are you with our service?</h3>
        <div class="flex justify-between">
            <div class="radio-item flex-col items-center">
                <div class="radio-container mb-2">
                    <input type="radio" id="rating1" name="rating" class="radio-input" value="1" x-model="rating">
                    <div class="radio-custom border-red-500">
                        <div class="radio-dot !bg-red-500"></div>
                    </div>
                </div>
                <label for="rating1" class="cursor-pointer text-sm text-center">
                    <div class="text-2xl mb-1">😞</div>
                    <div>Very Poor</div>
                </label>
            </div>
            <!-- More rating options... -->
        </div>
        <div class="text-center p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium">Your rating: </span>
            <span class="text-sm" x-text="rating + '/5'"></span>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Disabled State -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Disabled State</label>
                                <div class="radio-group">
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="disabled1" name="disabled" class="radio-input" value="option1" disabled>
                                            <div class="radio-custom">
                                                <div class="radio-dot"></div>
                                            </div>
                                        </div>
                                        <label for="disabled1" class="cursor-not-allowed opacity-50">Disabled unselected</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="disabled2" name="disabled" class="radio-input" value="option2" checked disabled>
                                            <div class="radio-custom">
                                                <div class="radio-dot"></div>
                                            </div>
                                        </div>
                                        <label for="disabled2" class="cursor-not-allowed opacity-50">Disabled selected</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Disabled State</label>
    <div class="radio-group">
        <div class="radio-item">
            <div class="radio-container">
                <input type="radio" id="disabled1" name="disabled" class="radio-input" value="option1" disabled>
                <div class="radio-custom">
                    <div class="radio-dot"></div>
                </div>
            </div>
            <label for="disabled1" class="cursor-not-allowed opacity-50">Disabled unselected</label>
        </div>
        <div class="radio-item">
            <div class="radio-container">
                <input type="radio" id="disabled2" name="disabled" class="radio-input" value="option2" checked disabled>
                <div class="radio-custom">
                    <div class="radio-dot"></div>
                </div>
            </div>
            <label for="disabled2" class="cursor-not-allowed opacity-50">Disabled selected</label>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Error State -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Error State</label>
                                <div class="radio-group">
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="error1" name="error" class="radio-input" value="option1">
                                            <div class="radio-custom !border-red-300">
                                                <div class="radio-dot"></div>
                                            </div>
                                        </div>
                                        <label for="error1" class="cursor-pointer">Option 1</label>
                                    </div>
                                    <div class="radio-item">
                                        <div class="radio-container">
                                            <input type="radio" id="error2" name="error" class="radio-input" value="option2">
                                            <div class="radio-custom !border-red-300">
                                                <div class="radio-dot"></div>
                                            </div>
                                        </div>
                                        <label for="error2" class="cursor-pointer">Option 2</label>
                                    </div>
                                </div>
                                <div class="text-red-600 text-xs mt-1">Please select an option to continue</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Error State</label>
    <div class="radio-group">
        <div class="radio-item">
            <div class="radio-container">
                <input type="radio" id="error1" name="error" class="radio-input" value="option1">
                <div class="radio-custom !border-red-300">
                    <div class="radio-dot"></div>
                </div>
            </div>
            <label for="error1" class="cursor-pointer">Option 1</label>
        </div>
    </div>
    <div class="text-red-600 text-xs mt-1">Please select an option to continue</div>
</div></code></pre>
                            </div>
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